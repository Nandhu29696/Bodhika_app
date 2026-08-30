<?php
/**
 * Lib/Mailer.php — Single point of outbound email delivery for the whole app.
 *
 * Reads driver + SMTP credentials from app_settings (configured via
 * Admin/AppSettings.php → "Email / SMTP" tab). Supports two drivers:
 *   'mail' — PHP's built-in mail() (fine on hosts with a working MTA)
 *   'smtp' — raw-socket SMTP client, no PHPMailer/Composer dependency
 *            (works on locked-down shared hosting like Hostinger)
 *
 * Every part of the app that needs to send an email (OTP codes, welcome
 * emails, password resets, etc.) should go through here instead of calling
 * mail() directly, so there's exactly one place that knows how to deliver
 * mail and exactly one settings panel that controls it.
 *
 * Requires: AppSettings (Lib/AppSettings.php), migration_v30.sql.
 */
class Mailer
{
    /**
     * Send an HTML email, with an auto-derived plain-text alternative.
     *
     * @param string $to       Recipient email address.
     * @param string $subject  Email subject line.
     * @param string $bodyHtml HTML body.
     * @param string $bodyText Plain-text alternative. Auto-derived from
     *                         $bodyHtml when omitted.
     * @param string $toName   Recipient display name (To: header). Defaults
     *                         to the address itself when omitted.
     */
    public static function send(
        string $to, string $subject, string $bodyHtml,
        string $bodyText = '', string $toName = ''
    ): bool {
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

        $settings = AppSettings::getMany([
            'email_driver', 'email_from_address', 'email_from_name',
            'email_smtp_host', 'email_smtp_port', 'email_smtp_encryption',
            'email_smtp_user', 'email_smtp_pass',
        ]);

        $from     = $settings['email_from_address'] ?: (defined('DEFAULT_SENDER') ? DEFAULT_SENDER : 'noreply@localhost');
        $fromName = $settings['email_from_name']    ?: (defined('APP_NAME') ? APP_NAME : 'App');
        $toName   = $toName !== '' ? $toName : $to;

        if ($bodyText === '') {
            $bodyText = self::htmlToText($bodyHtml);
        }

        if (($settings['email_driver'] ?? 'mail') === 'smtp' && ($settings['email_smtp_host'] ?? '') !== '') {
            return self::sendSmtp($to, $toName, $from, $fromName, $subject, $bodyHtml, $bodyText, $settings);
        }

        return self::sendPhpMail($to, $from, $fromName, $subject, $bodyHtml);
    }

    /**
     * Convenience wrapper for legacy call sites that only have a plain-text
     * body string (no HTML template). Wraps it in a minimal HTML shell so
     * SMTP delivery still gets a proper multipart/alternative message.
     */
    public static function sendPlainText(string $to, string $subject, string $bodyText, string $toName = ''): bool
    {
        $bodyHtml = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937;">'
                  . '<div style="white-space:pre-wrap;">' . nl2br(htmlspecialchars($bodyText)) . '</div>'
                  . '</body></html>';
        return self::send($to, $subject, $bodyHtml, $bodyText, $toName);
    }

    /** Strip an HTML body down to a readable plain-text fallback. */
    private static function htmlToText(string $html): string
    {
        $text = preg_replace('/<(br|\/p|\/div|\/tr|\/li)\s*\/?>/i', "\n", $html);
        $text = strip_tags($text ?? $html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private static function sendPhpMail(string $to, string $from, string $fromName, string $subject, string $bodyHtml): bool
    {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . self::mimeEncode($fromName) . " <$from>\r\n";
        $headers .= "Reply-To: $from\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        return @mail($to, $subject, $bodyHtml, $headers);
    }

    /** Send via raw SMTP socket (no PHPMailer dependency). */
    private static function sendSmtp(
        string $to, string $toName, string $from, string $fromName,
        string $subject, string $bodyHtml, string $bodyText,
        array  $settings
    ): bool {
        $host = $settings['email_smtp_host'];
        $port = (int)($settings['email_smtp_port'] ?: 587);
        $enc  = strtolower($settings['email_smtp_encryption'] ?? 'tls');
        $user = $settings['email_smtp_user'];
        $pass = $settings['email_smtp_pass'];

        $prefix = ($enc === 'ssl') ? 'ssl://' : '';

        try {
            $sock = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
            if (!$sock) {
                error_log("Mailer SMTP: connect failed: $errstr ($errno)");
                return false;
            }
            stream_set_timeout($sock, 10);

            $read = function() use ($sock): string {
                $resp = '';
                while ($line = fgets($sock, 512)) {
                    $resp .= $line;
                    if (substr($line, 3, 1) === ' ') break;
                }
                return $resp;
            };
            $cmd  = function(string $c) use ($sock, $read): string {
                fputs($sock, "$c\r\n");
                return $read();
            };

            $read();                          // 220 greeting
            $cmd("EHLO " . gethostname());

            if ($enc === 'tls') {
                $cmd("STARTTLS");
                stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $cmd("EHLO " . gethostname());
            }

            if ($user !== '') {
                $cmd("AUTH LOGIN");
                $cmd(base64_encode($user));
                $r = $cmd(base64_encode($pass));
                if (substr(trim($r), 0, 3) !== '235') {
                    error_log("Mailer SMTP: AUTH failed: $r");
                    fclose($sock);
                    return false;
                }
            }

            $boundary = md5(uniqid());
            $cmd("MAIL FROM:<$from>");
            $cmd("RCPT TO:<$to>");
            $cmd("DATA");

            $msg  = "From: " . self::mimeEncode($fromName) . " <$from>\r\n";
            $msg .= "To: " . self::mimeEncode($toName) . " <$to>\r\n";
            $msg .= "Subject: " . self::mimeEncode($subject) . "\r\n";
            $msg .= "MIME-Version: 1.0\r\n";
            $msg .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
            $msg .= "Date: " . date('r') . "\r\n\r\n";
            $msg .= "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$bodyText\r\n";
            $msg .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$bodyHtml\r\n";
            $msg .= "--$boundary--\r\n";

            fputs($sock, $msg . "\r\n.\r\n");
            $r = $read();
            $cmd("QUIT");
            fclose($sock);

            return substr(trim($r), 0, 3) === '250';
        } catch (Exception $e) {
            error_log("Mailer SMTP exception: " . $e->getMessage());
            return false;
        }
    }

    public static function mimeEncode(string $str): string
    {
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }
}
