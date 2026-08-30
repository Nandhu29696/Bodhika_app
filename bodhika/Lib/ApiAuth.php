<?php
/**
 * Lib/ApiAuth.php — Token-based authentication for the Flutter mobile API.
 *
 * Deliberately independent of the web app's session-cookie auth
 * (Lib/Auth.php): logging in from the mobile app issues a bearer token
 * stored in api_tokens (migrations/migration_v40.sql) and never touches
 * logininfo.session_token — so a mobile login can never silently kick out
 * a concurrent web session, and vice versa (Auth::requireLogin() enforces
 * single-session purely against session_token).
 *
 * Credential checking itself (password hash + upgrade, lockout, active
 * flag) is NOT duplicated here — every login path calls
 * Auth::verifyCredentials() so the two entry points can never drift apart.
 *
 * Requires Lib/Config.php + Lib/Auth.php + Lib/SignedToken.php to already be
 * loaded (done by api/_bootstrap.php).
 */
final class ApiAuth
{
    // ── Login (step 1) ───────────────────────────────────────────────────────

    /**
     * @return array One of:
     *   ['status'=>'ok', 'token'=>string, 'user'=>array]
     *   ['status'=>'otp_required', 'pendingToken'=>string, 'channels'=>string[], 'emailMasked'=>?string, 'phoneMasked'=>?string]
     *   ['status'=>'error', 'error'=>string]
     */
    public static function login(string $loginName, string $plainPwd, ?string $deviceInfo): array
    {
        $cred = Auth::verifyCredentials($loginName, $plainPwd);
        if (!$cred['ok']) {
            return ['status' => 'error', 'error' => $cred['error']];
        }
        $row       = $cred['row'];
        $loginName = trim($loginName);

        // ── OTP 2FA — same site-wide gate the web app uses ────────────────────
        try {
            if (class_exists('AppSettings') && class_exists('Otp') &&
                (AppSettings::isEnabled('otp_email_enabled') ||
                 AppSettings::isEnabled('otp_sms_enabled'))) {

                $email  = (string)($row['EMail']  ?? '');
                $mobile = (string)($row['Mobile'] ?? '');
                $result = Otp::dispatch((int)$row['LoginInfoId'], $loginName, $email, $mobile);

                if ($result['sent']) {
                    $pendingToken = self::createPendingToken([
                        'loginId'    => (int)$row['LoginInfoId'],
                        'deviceInfo' => $deviceInfo,
                    ]);
                    return [
                        'status'       => 'otp_required',
                        'pendingToken' => $pendingToken,
                        'channels'     => $result['channels'],
                        'emailMasked'  => $email  !== '' ? Otp::maskEmail($email)  : null,
                        'phoneMasked'  => $mobile !== '' ? Otp::maskPhone($mobile) : null,
                    ];
                }
                error_log("ApiAuth: OTP dispatch failed for $loginName — logging in without 2FA");
            }
        } catch (Exception $e) {
            error_log('ApiAuth: OTP check skipped (' . $e->getMessage() . ')');
        }

        return self::completeLogin($row, $deviceInfo);
    }

    // ── Login (step 2 — only when status was 'otp_required') ────────────────

    public static function verifyOtp(string $pendingToken, string $code): array
    {
        $pending = self::readPendingToken($pendingToken);
        if (!$pending) {
            return ['status' => 'error', 'error' => 'This login attempt has expired. Please log in again.'];
        }
        if (!class_exists('Otp')) {
            return ['status' => 'error', 'error' => 'Verification is not available right now.'];
        }

        $result = Otp::verify((int)$pending['loginId'], $code);
        if ($result !== true) {
            $msg = match ($result) {
                'expired'      => 'Code expired — please log in again to get a new one.',
                'max_attempts' => 'Too many incorrect attempts. Please log in again.',
                default        => 'Incorrect code. Please try again.',
            };
            return ['status' => 'error', 'error' => $msg];
        }

        $row = Database::fetchOne(
            "SELECT l.LoginInfoId, l.LoginName, l.Role,
                    COALESCE(u.UserInfoId, 0) AS UserInfoId,
                    COALESCE(u.FstName, '')   AS FstName,
                    COALESCE(u.LstName, '')   AS LstName,
                    COALESCE(u.EMail,  '')    AS EMail,
                    COALESCE(u.Mobile, '')    AS Mobile
               FROM logininfo l
          LEFT JOIN userinfo  u ON u.LoginName = l.LoginName
              WHERE l.LoginInfoId = ? LIMIT 1",
            [(int)$pending['loginId']]
        );
        if (!$row) {
            return ['status' => 'error', 'error' => 'Account no longer exists.'];
        }

        return self::completeLogin($row, $pending['deviceInfo'] ?? null);
    }

    /** Issues the bearer token + builds the user payload; shared by both login paths. */
    private static function completeLogin(array $row, ?string $deviceInfo): array
    {
        $loginInfoId = (int)$row['LoginInfoId'];
        $userInfoId  = (int)($row['UserInfoId'] ?? 0);
        $role        = (string)$row['Role'];
        $loginName   = (string)($row['LoginName'] ?? '');

        if ($loginName === '') {
            $li = Database::fetchOne("SELECT LoginName FROM logininfo WHERE LoginInfoId = ? LIMIT 1", [$loginInfoId]);
            $loginName = $li['LoginName'] ?? '';
        }

        $token = bin2hex(random_bytes(32));
        $ttl   = defined('API_TOKEN_DEFAULT_TTL') ? API_TOKEN_DEFAULT_TTL : (60 * 60 * 24 * 30);
        Database::execute(
            "INSERT INTO api_tokens (Token, LoginInfoId, UserInfoId, Role, DeviceInfo, ExpiresAt)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))",
            [$token, $loginInfoId, $userInfoId ?: null, $role, $deviceInfo, $ttl]
        );

        Auth::recordLoginEvent($userInfoId, $loginInfoId, $loginName);

        return [
            'status' => 'ok',
            'token'  => $token,
            'user'   => self::buildUserPayload($loginInfoId, $userInfoId, $role, $loginName, $row),
        ];
    }

    private static function buildUserPayload(int $loginInfoId, int $userInfoId, string $role, string $loginName, array $row): array
    {
        $flags    = Auth::resolveRoleFlags($role);
        $fullName = trim(($row['FstName'] ?? '') . ' ' . ($row['LstName'] ?? ''));
        if ($fullName === '') $fullName = $loginName;

        $extra = [];
        if ($userInfoId > 0) {
            try {
                $extra = Database::fetchOne(
                    "SELECT BloodGroup, WillingToDonateBlood, InstituteId, Mobile, ScholarshipFlag
                       FROM userinfo WHERE UserInfoId = ? LIMIT 1", [$userInfoId]
                ) ?: [];
            } catch (Exception $e) { /* migration_v39 not yet run — degrade gracefully */ }
        }

        // Forced password-change flag (migration_v59) — looked up directly
        // by loginInfoId rather than trusting the caller's $row to carry it,
        // since one caller (verifyOtp's re-fetch) doesn't select it. Mirrors
        // the guarded pattern in Auth::verifyCredentials().
        $mustChangePassword = false;
        if (Database::hasColumn('logininfo', 'MustChangePassword')) {
            try {
                $mcp = Database::fetchOne(
                    "SELECT MustChangePassword FROM logininfo WHERE LoginInfoId = ? LIMIT 1", [$loginInfoId]
                );
                $mustChangePassword = (int)($mcp['MustChangePassword'] ?? 0) === 1;
            } catch (Exception $e) { /* column not present — default false already set */ }
        }

        return [
            'loginId'              => $loginInfoId,
            'userId'               => $userInfoId,
            'loginName'            => $loginName,
            'role'                 => $role,
            'isAdmin'              => $flags['isAdmin'],
            'isTeacher'            => $flags['isTeacher'],
            'isInstituteAdmin'     => $flags['isInstituteAdmin'],
            'mustChangePassword'   => $mustChangePassword,
            'fullName'             => $fullName,
            'email'                => $row['EMail'] ?? '',
            'mobile'               => $extra['Mobile'] ?? ($row['Mobile'] ?? ''),
            'instituteId'          => isset($extra['InstituteId']) ? (int)$extra['InstituteId'] : null,
            'bloodGroup'           => $extra['BloodGroup'] ?? null,
            'willingToDonateBlood' => ($extra['WillingToDonateBlood'] ?? 'N') === 'Y',
            'scholarship'          => ($extra['ScholarshipFlag'] ?? 'N') === 'Y',
        ];
    }

    // ── Bearer-token verification (per request) ──────────────────────────────

    /** Reads "Authorization: Bearer <token>", returns the auth context or null. */
    public static function authenticate(): ?array
    {
        $token = self::bearerToken();
        if ($token === null) return null;

        $row = Database::fetchOne(
            "SELECT t.TokenId, t.LoginInfoId, t.UserInfoId, t.Role, t.ExpiresAt,
                    l.Active, l.LoginName
               FROM api_tokens t
               JOIN logininfo l ON l.LoginInfoId = t.LoginInfoId
              WHERE t.Token = ? LIMIT 1",
            [$token]
        );
        if (!$row) return null;
        if ($row['Active'] !== 'Y') return null;
        if (!empty($row['ExpiresAt']) && strtotime($row['ExpiresAt']) < time()) return null;

        Database::execute("UPDATE api_tokens SET LastUsedAt = NOW() WHERE TokenId = ?", [(int)$row['TokenId']]);

        $flags = Auth::resolveRoleFlags($row['Role']);
        return [
            'token'            => $token,
            'loginId'          => (int)$row['LoginInfoId'],
            'userId'           => (int)($row['UserInfoId'] ?? 0),
            'loginName'        => $row['LoginName'],
            'role'             => $row['Role'],
            'isAdmin'          => $flags['isAdmin'],
            'isTeacher'        => $flags['isTeacher'],
            'isInstituteAdmin' => $flags['isInstituteAdmin'],
        ];
    }

    public static function requireAuth(): array
    {
        $ctx = self::authenticate();
        if (!$ctx) {
            ApiResponse::error('Please log in again.', 401, 'UNAUTHENTICATED');
        }
        return $ctx;
    }

    public static function requireAdmin(): array
    {
        $ctx = self::requireAuth();
        if (!$ctx['isAdmin']) {
            ApiResponse::error('Admin access required.', 403, 'FORBIDDEN');
        }
        return $ctx;
    }

    /** Requires a logged-in account that also has a student profile (userinfo row). */
    public static function requireStudent(): array
    {
        $ctx = self::requireAuth();
        if ($ctx['userId'] <= 0) {
            ApiResponse::error('This account has no student profile.', 403, 'NO_STUDENT_PROFILE');
        }
        return $ctx;
    }

    /** Re-fetches a fresh user payload for an already-authenticated context (used by GET /api/auth/me.php). */
    public static function currentUserPayload(array $ctx): ?array
    {
        $row = Database::fetchOne(
            "SELECT l.EMail AS LoginEmail, u.FstName, u.LstName, u.EMail, u.Mobile
               FROM logininfo l
          LEFT JOIN userinfo u ON u.LoginName = l.LoginName
              WHERE l.LoginInfoId = ? LIMIT 1",
            [$ctx['loginId']]
        );
        if (!$row) return null;
        if (empty($row['EMail'])) $row['EMail'] = $row['LoginEmail'] ?? '';

        return self::buildUserPayload($ctx['loginId'], $ctx['userId'], $ctx['role'], $ctx['loginName'], $row);
    }

    public static function logout(): void
    {
        $token = self::bearerToken();
        if ($token !== null) {
            Database::execute("DELETE FROM api_tokens WHERE Token = ?", [$token]);
        }
    }

    private static function bearerToken(): ?string
    {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($hdr === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
            }
        }
        if (!preg_match('/^Bearer\s+([A-Za-z0-9]+)$/i', trim($hdr), $m)) return null;
        return $m[1];
    }

    // ── Stateless OTP hand-off token ──────────────────────────────────────────
    // The API never assumes two requests from the same device share a PHP
    // session, so the "I'm mid-login, waiting on an OTP code" state is
    // encoded entirely in a SignedToken instead of $_SESSION.

    private static function createPendingToken(array $payload): string
    {
        return SignedToken::encode($payload, defined('API_OTP_PENDING_TTL') ? API_OTP_PENDING_TTL : 600);
    }

    private static function readPendingToken(string $token): ?array
    {
        return SignedToken::decode($token);
    }
}
