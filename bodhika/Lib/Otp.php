<?php
/**
 * Lib/Otp.php — OTP generation, delivery, and verification.
 *
 * Supports two delivery channels: email and SMS.
 * Which channels are active is controlled by app_settings:
 *   otp_email_enabled = '1'
 *   otp_sms_enabled   = '1'
 *
 * Email supports 'mail' driver (PHP mail()) or 'smtp' (requires SMTP settings).
 * SMS supports MSG91, Twilio, or a custom HTTP endpoint.
 *
 * Requires: AppSettings, Mailer, migration_v30.sql.
 */
require_once __DIR__ . '/Mailer.php';

class Otp
{
    /* Maximum wrong-guess attempts before an OTP is invalidated */
    const MAX_ATTEMPTS = 5;

    // ── Token management ───────────────────────────────────────────────────────

    /**
     * Generate a fresh OTP and persist it for the given login + channel.
     * Any previous active token for the same (LoginInfoId, Channel) is replaced.
     *
     * @return string The plain-text code to send to the user.
     */
    public static function generate(int $loginInfoId, string $channel): string
    {
        $length  = max(4, min(8, (int)AppSettings::get('otp_length', '6')));
        $expiry  = max(1, (int)AppSettings::get('otp_expiry_minutes', '10'));
        $code    = str_pad((string)random_int(0, (int)str_repeat('9', $length)), $length, '0', STR_PAD_LEFT);

        Database::execute(
            "INSERT INTO otp_tokens (LoginInfoId, Channel, Token, Attempts, ExpiresAt, Used)
                  VALUES (?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL ? MINUTE), 'N')
             ON DUPLICATE KEY UPDATE
                  Token     = VALUES(Token),
                  Attempts  = 0,
                  ExpiresAt = VALUES(ExpiresAt),
                  Used      = 'N',
                  CreatedAt = NOW()",
            [$loginInfoId, $channel, $code, $expiry]
        );

        return $code;
    }

    /**
     * Verify a submitted code for the given login.
     * Checks all pending (non-expired, non-used) tokens across channels.
     *
     * @return true on success
     * @return false|string false on token-not-found; 'expired'|'used'|'max_attempts' on specific failures
     */
    public static function verify(int $loginInfoId, string $submitted): bool|string
    {
        /* Fetch any live token for this login */
        $row = Database::fetchOne(
            "SELECT id, Token, ExpiresAt, Used, Attempts
               FROM otp_tokens
              WHERE LoginInfoId = ?
                AND ExpiresAt  > NOW()
                AND Used       = 'N'
              ORDER BY CreatedAt DESC LIMIT 1",
            [$loginInfoId]
        );

        if (!$row) {
            return 'expired';
        }

        /* Guard against brute-force */
        if ((int)$row['Attempts'] >= self::MAX_ATTEMPTS) {
            return 'max_attempts';
        }

        /* Constant-time comparison */
        if (!hash_equals($row['Token'], $submitted)) {
            Database::execute(
                "UPDATE otp_tokens SET Attempts = Attempts + 1 WHERE id = ?",
                [$row['id']]
            );
            return false;
        }

        /* Mark as used */
        Database::execute(
            "UPDATE otp_tokens SET Used = 'Y' WHERE id = ?",
            [$row['id']]
        );

        return true;
    }

    /**
     * Generate + send OTP to all enabled channels the user has data for.
     *
     * @param int    $loginInfoId
     * @param string $loginName   display only (for log messages)
     * @param string $email
     * @param string $phone
     * @return array{sent:bool, channels:string[], error:string}
     */
    public static function dispatch(int $loginInfoId, string $loginName, string $email, string $phone): array
    {
        $emailEnabled = AppSettings::isEnabled('otp_email_enabled');
        $smsEnabled   = AppSettings::isEnabled('otp_sms_enabled');

        $sent     = false;
        $channels = [];
        $errors   = [];

        if ($emailEnabled && $email !== '') {
            $code = self::generate($loginInfoId, 'email');
            if (self::sendEmail($email, $loginName, $code)) {
                $sent       = true;
                $channels[] = 'email';
            } else {
                $errors[] = 'Email delivery failed.';
                error_log("OTP: email send failed for LoginInfoId=$loginInfoId");
            }
        }

        if ($smsEnabled && $phone !== '') {
            $code = self::generate($loginInfoId, 'sms');
            if (self::sendSms($phone, $code)) {
                $sent       = true;
                $channels[] = 'sms';
            } else {
                $errors[] = 'SMS delivery failed.';
                error_log("OTP: SMS send failed for LoginInfoId=$loginInfoId");
            }
        }

        return [
            'sent'     => $sent,
            'channels' => $channels,
            'error'    => implode(' ', $errors),
        ];
    }

    // ── Delivery: Email ────────────────────────────────────────────────────────

    public static function sendEmail(string $to, string $name, string $code): bool
    {
        if ($to === '') return false;

        $expiry  = max(1, (int)AppSettings::get('otp_expiry_minutes', '10'));
        $appName = defined('APP_NAME') ? APP_NAME : 'App';

        $subject  = "[$appName] Your Verification Code: $code";
        $bodyHtml = self::emailHtml($name, $code, $expiry, $appName);
        $bodyText = "Hi $name,\n\nYour $appName verification code is: $code\n\nValid for $expiry minutes. Do not share this code.\n\n— $appName";

        return Mailer::send($to, $subject, $bodyHtml, $bodyText, $name);
    }

    /** Styled HTML email body. */
    private static function emailHtml(string $name, string $code, int $expiry, string $appName): string
    {
        $digits = '';
        foreach (str_split($code) as $d) {
            $digits .= "<span style='display:inline-block;width:44px;height:52px;line-height:52px;"
                     . "text-align:center;font-size:1.6rem;font-weight:900;background:#f1f5f9;"
                     . "border:2px solid #e2e8f0;border-radius:8px;margin:0 4px;color:#312e81;'>"
                     . htmlspecialchars($d) . "</span>";
        }
        return <<<HTML
<!DOCTYPE html><html><body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f8fafc;">
<table align="center" width="520" cellpadding="0" cellspacing="0"
       style="background:#fff;border-radius:12px;margin:32px auto;box-shadow:0 2px 12px rgba(0,0,0,.08);">
  <tr><td style="background:#312e81;border-radius:12px 12px 0 0;padding:24px 32px;text-align:center;">
    <h2 style="color:#fff;margin:0;font-size:1.3rem;">{$appName}</h2>
  </td></tr>
  <tr><td style="padding:32px 40px;text-align:center;">
    <p style="font-size:1rem;color:#374151;margin:0 0 20px;">
      Hi <strong>{$name}</strong>, use the code below to complete your sign-in.
    </p>
    <div style="margin:24px 0;">{$digits}</div>
    <p style="font-size:.85rem;color:#6b7280;margin:20px 0 0;">
      This code expires in <strong>{$expiry} minutes</strong>.<br>
      Never share it with anyone.
    </p>
  </td></tr>
  <tr><td style="background:#f8fafc;border-radius:0 0 12px 12px;padding:16px 40px;text-align:center;">
    <p style="font-size:.75rem;color:#9ca3af;margin:0;">
      If you did not request this, you can safely ignore this email.
    </p>
  </td></tr>
</table>
</body></html>
HTML;
    }

    // ── Delivery: SMS ──────────────────────────────────────────────────────────

    public static function sendSms(string $phone, string $code): bool
    {
        $phone = preg_replace('/[^+\d]/', '', $phone);
        if ($phone === '') return false;

        $appName = defined('APP_NAME') ? APP_NAME : 'App';
        $expiry  = (int)AppSettings::get('otp_expiry_minutes', '10');
        $message = "$appName OTP: $code (valid $expiry min). Do not share.";
        $gateway = AppSettings::get('sms_gateway', 'msg91');

        switch ($gateway) {
            case 'msg91':  return self::sendMsg91($phone, $code, $message);
            case 'twilio': return self::sendTwilio($phone, $message);
            default:       return self::sendCustomSms($phone, $message);
        }
    }

    private static function sendMsg91(string $phone, string $otp, string $message): bool
    {
        $apiKey  = AppSettings::get('sms_api_key');
        $sender  = AppSettings::get('sms_sender_id', 'BODHIK');
        $flowId  = AppSettings::get('sms_msg91_flow_id');

        if ($apiKey === '') {
            error_log('OTP MSG91: sms_api_key not set');
            return false;
        }

        /* Prefer Flow API (template) when flow_id is set; fall back to SMS API */
        if ($flowId !== '') {
            $payload = json_encode([
                'flow_id'   => $flowId,
                'sender'    => $sender,
                'mobiles'   => $phone,
                'OTP'       => $otp,
            ]);
            return self::curlPost(
                'https://api.msg91.com/api/v5/flow/',
                $payload,
                ['Content-Type: application/json', "authkey: $apiKey"]
            );
        }

        /* Plain SMS API */
        $url = 'https://api.msg91.com/api/sendhttp.php?' . http_build_query([
            'authkey'  => $apiKey,
            'mobiles'  => $phone,
            'message'  => $message,
            'sender'   => $sender,
            'route'    => 4,
            'country'  => 91,
        ]);
        return self::curlGet($url);
    }

    private static function sendTwilio(string $phone, string $message): bool
    {
        $sid  = AppSettings::get('sms_twilio_sid');
        $auth = AppSettings::get('sms_api_key');       // Twilio auth token
        $from = AppSettings::get('sms_twilio_from');

        if ($sid === '' || $auth === '' || $from === '') {
            error_log('OTP Twilio: missing credentials (sms_twilio_sid, sms_api_key, sms_twilio_from)');
            return false;
        }

        $url  = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
        return self::curlPost(
            $url,
            http_build_query(['To' => $phone, 'From' => $from, 'Body' => $message]),
            ['Content-Type: application/x-www-form-urlencoded'],
            $sid,
            $auth
        );
    }

    private static function sendCustomSms(string $phone, string $message): bool
    {
        $url  = AppSettings::get('sms_custom_url');
        $body = AppSettings::get('sms_custom_body', '{"phone":"{PHONE}","message":"{MSG}"}');

        if ($url === '') {
            error_log('OTP custom SMS: sms_custom_url not set');
            return false;
        }

        $body = str_replace(['{PHONE}', '{MSG}'], [$phone, $message], $body);
        return self::curlPost($url, $body, ['Content-Type: application/json']);
    }

    // ── HTTP helpers ────────────────────────────────────────────────────────────

    private static function curlPost(
        string $url, string $body, array $headers = [],
        string $user = '', string $pass = ''
    ): bool {
        if (!function_exists('curl_init')) {
            error_log('OTP: cURL extension not available');
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($user !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
        }
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) { error_log("OTP cURL error: $err"); return false; }
        if ($code < 200 || $code >= 300) {
            error_log("OTP HTTP $code: " . substr((string)$resp, 0, 300));
            return false;
        }
        return true;
    }

    private static function curlGet(string $url): bool
    {
        if (!function_exists('curl_init')) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) { error_log("OTP cURL GET error: $err"); return false; }
        return ($code >= 200 && $code < 300);
    }

    // ── UI helpers ──────────────────────────────────────────────────────────────

    /** Mask an email address for display: j***@example.com */
    public static function maskEmail(string $email): string
    {
        if ($email === '') return '';
        $parts = explode('@', $email, 2);
        $local = $parts[0];
        $domain = $parts[1] ?? '';
        $visible = substr($local, 0, min(2, strlen($local)));
        return $visible . str_repeat('*', max(0, strlen($local) - 2)) . '@' . $domain;
    }

    /** Mask a phone number: ******7890 */
    public static function maskPhone(string $phone): string
    {
        if ($phone === '') return '';
        $clean = preg_replace('/[^0-9]/', '', $phone);
        $show  = substr($clean, -4);
        return str_repeat('*', max(0, strlen($clean) - 4)) . $show;
    }
}
