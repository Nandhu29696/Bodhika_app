<?php
/**
 * Auth.php — Authentication, session management & security.
 *
 * Security features:
 *  • Single-session enforcement (session_token in DB)
 *  • 15-minute inactivity auto-logout
 *  • Session ID regeneration every 5 minutes (prevents fixation during active use)
 *  • Login rate-limiting: 5 failures → 15-minute account lockout
 *  • CSRF token generation & validation
 *  • bcrypt password hashing (auto-upgrades MD5 / plain-text on login)
 *
 * Session keys stored (no plaintext credentials):
 *   user_id       — UserInfoId  (int)
 *   login_id      — LoginInfoId (int)
 *   role          — Role string ('Admin','PRCIPAL','STDNT'…)
 *   is_admin      — bool (cached)
 *   full_name     — display name from userinfo
 *   sess_token    — single-session enforcement token
 *   last_activity — Unix timestamp of last verified request
 *   last_regen    — Unix timestamp of last session_regenerate_id()
 *   csrf_token    — CSRF secret for this session
 */
class Auth
{
    // ── Login ──────────────────────────────────────────────────────────────────

    /**
     * Attempt to authenticate a user.
     * Enforces account lockout and resets failure counter on success.
     */
    public static function login(string $loginName, string $plainPwd, string &$errorMsg): bool
    {
        $cred = self::verifyCredentials($loginName, $plainPwd);
        if (!$cred['ok']) {
            $errorMsg = $cred['error'];
            return false;
        }
        $row       = $cred['row'];
        $loginName = trim($loginName);

        // ── Single-session token ──────────────────────────────────────────────
        $token = bin2hex(random_bytes(32));
        Database::execute(
            "UPDATE logininfo SET session_token = ?, last_login = NOW() WHERE LoginInfoId = ?",
            [$token, (int)$row['LoginInfoId']]
        );

        // Prevent session-fixation: new ID on privilege change
        session_regenerate_id(true);

        // ── Resolve display info ──────────────────────────────────────────────
        $role             = $row['Role'];
        $roleFlags        = self::resolveRoleFlags($role);
        $isAdmin          = $roleFlags['isAdmin'];
        $isTeacher        = $roleFlags['isTeacher'];
        $isInstituteAdmin = $roleFlags['isInstituteAdmin'];
        $mustChangePwd    = !empty($row['MustChangePassword']);

        $displayName = trim($row['FstName'] . ' ' . $row['LstName']);
        if ($displayName === '') $displayName = $loginName;

        // ── OTP check: if any OTP channel is enabled, pause and request code ───
        //    AppSettings + Otp are auto-loaded when available; if tables don't
        //    exist yet the code silently skips 2FA (graceful degradation).
        try {
            if (class_exists('AppSettings') && class_exists('Otp') &&
                (AppSettings::isEnabled('otp_email_enabled') ||
                 AppSettings::isEnabled('otp_sms_enabled'))) {

                $email  = (string)($row['EMail']  ?? '');
                $mobile = (string)($row['Mobile'] ?? '');
                $result = Otp::dispatch((int)$row['LoginInfoId'], $loginName, $email, $mobile);

                if ($result['sent']) {
                    /* Stash everything needed to complete the login after OTP verify */
                    $_SESSION['otp_pending'] = [
                        'user_id'            => (int)$row['UserInfoId'],
                        'login_id'           => (int)$row['LoginInfoId'],
                        'login_name'         => $loginName,
                        'role'               => $role,
                        'display_name'       => $displayName,
                        'is_admin'           => $isAdmin,
                        'is_teacher'         => $isTeacher,
                        'is_institute_admin' => $isInstituteAdmin,
                        'must_change_pwd'    => $mustChangePwd,
                        'token'              => $token,
                        'channels'           => $result['channels'],
                        'email_masked'       => Otp::maskEmail($email),
                        'phone_masked'       => Otp::maskPhone($mobile),
                        'expires'            => time() + 660,  // 11 min (OTP is 10 min)
                    ];
                    /* DO NOT set main session keys yet — user must verify OTP */
                    return true;
                }
                /* OTP dispatch failed (bad config?) — fall through to full login
                   so the user isn't locked out due to a gateway misconfiguration. */
                error_log("Auth: OTP dispatch failed for $loginName — logging in without 2FA");
            }
        } catch (Exception $_otpEx) {
            /* Migration not run or class not loaded — proceed without OTP */
            error_log('Auth: OTP check skipped (' . $_otpEx->getMessage() . ')');
        }

        // ── Complete login — no OTP required (or OTP dispatch failed) ────────
        self::finalizeSession($token, $row, $isAdmin, $isTeacher, $isInstituteAdmin, $mustChangePwd, $displayName, $loginName);
        self::recordLoginEvent((int)$row['UserInfoId'], (int)$row['LoginInfoId'], $loginName);
        return true;
    }

    /**
     * Core credential check — username/password, lockout, active-flag, hash
     * verification (+ upgrade) — with NO session side effects at all.
     *
     * Extracted so both the web session login (above) and the token-based
     * mobile API (Lib/ApiAuth.php) share exactly one implementation of "is
     * this username/password valid right now" — including the lockout
     * counter, which must never drift between the two entry points.
     *
     * @return array{ok:bool,error:string,row:?array} row is the same shape
     *         previously fetched inline here: LoginInfoId, LoginName,
     *         Password, Role, Active, failed_attempts, locked_until,
     *         UserInfoId, FstName, LstName, EMail, Mobile.
     */
    public static function verifyCredentials(string $loginName, string $plainPwd): array
    {
        $loginName = trim($loginName);

        if ($loginName === '' || $plainPwd === '') {
            return ['ok' => false, 'error' => 'Username and password are required.', 'row' => null];
        }

        // Fetch login record (join userinfo for display name + contact details for OTP).
        // Try with rate-limit columns first; fall back if migration_v15 hasn't run yet.
        try {
            $row = Database::fetchOne(
                "SELECT l.LoginInfoId, l.LoginName, l.Password, l.Role, l.Active,
                        l.failed_attempts, l.locked_until,
                        COALESCE(u.UserInfoId, 0) AS UserInfoId,
                        COALESCE(u.FstName, '')   AS FstName,
                        COALESCE(u.LstName, '')   AS LstName,
                        COALESCE(u.EMail,  '')    AS EMail,
                        COALESCE(u.Mobile, '')    AS Mobile
                   FROM logininfo l
              LEFT JOIN userinfo  u ON u.LoginName = l.LoginName
                  WHERE l.LoginName = ?
                  LIMIT 1",
                [$loginName]
            );
        } catch (Exception $e) {
            // Columns not yet added — run migration_v15.sql to enable rate-limiting.
            $row = Database::fetchOne(
                "SELECT l.LoginInfoId, l.LoginName, l.Password, l.Role, l.Active,
                        0    AS failed_attempts,
                        NULL AS locked_until,
                        COALESCE(u.UserInfoId, 0) AS UserInfoId,
                        COALESCE(u.FstName, '')   AS FstName,
                        COALESCE(u.LstName, '')   AS LstName,
                        COALESCE(u.EMail,  '')    AS EMail,
                        COALESCE(u.Mobile, '')    AS Mobile
                   FROM logininfo l
              LEFT JOIN userinfo  u ON u.LoginName = l.LoginName
                  WHERE l.LoginName = ?
                  LIMIT 1",
                [$loginName]
            );
        }

        if (!$row) {
            // Uniform message — don't reveal whether the username exists
            return ['ok' => false, 'error' => 'Invalid username or password.', 'row' => null];
        }

        // ── Forced password-change flag (migration_v59) — layered on top so
        //    the two query shapes above never need a third fallback variant. ─
        $row['MustChangePassword'] = 0;
        if (Database::hasColumn('logininfo', 'MustChangePassword')) {
            try {
                $mcp = Database::fetchOne(
                    "SELECT MustChangePassword FROM logininfo WHERE LoginInfoId = ?",
                    [(int)$row['LoginInfoId']]
                );
                $row['MustChangePassword'] = (int)($mcp['MustChangePassword'] ?? 0);
            } catch (Exception $e) { /* column not present — default 0 already set */ }
        }

        // ── Registration status (migration_v60) — same layering pattern as
        //    MustChangePassword above. Lets the "account isn't active" branch
        //    below tell a brand-new self-registration awaiting review apart
        //    from a suspension, without needing a third variant of the two
        //    SELECTs above.
        $row['RegistrationStatus'] = 'Approved';
        if (Database::hasColumn('logininfo', 'RegistrationStatus')) {
            try {
                $rs = Database::fetchOne(
                    "SELECT RegistrationStatus FROM logininfo WHERE LoginInfoId = ?",
                    [(int)$row['LoginInfoId']]
                );
                $row['RegistrationStatus'] = $rs['RegistrationStatus'] ?? 'Approved';
            } catch (Exception $e) { /* column not present — default Approved already set */ }
        }

        // ── Lockout check ────────────────────────────────────────────────────
        if (!empty($row['locked_until']) && strtotime($row['locked_until']) > time()) {
            $mins = (int)ceil((strtotime($row['locked_until']) - time()) / 60);
            return ['ok' => false, 'row' => null, 'error' =>
                "Account temporarily locked due to too many failed attempts. "
                . "Try again in {$mins} minute" . ($mins !== 1 ? 's' : '') . "."];
        }

        if ($row['Active'] !== 'Y') {
            if ($row['RegistrationStatus'] === 'Pending') {
                return ['ok' => false, 'row' => null, 'error' =>
                    'Your registration is awaiting admin approval. You will be able to sign in once it is approved.'];
            }
            if ($row['RegistrationStatus'] === 'Rejected') {
                return ['ok' => false, 'row' => null, 'error' =>
                    'Your registration was not approved. Please contact the administrator.'];
            }
            return ['ok' => false, 'error' => 'Your account is inactive. Please contact the administrator.', 'row' => null];
        }

        // ── Password verification ────────────────────────────────────────────
        if (!self::verifyPassword($plainPwd, $row['Password'], (int)$row['LoginInfoId'])) {
            self::recordFailedAttempt((int)$row['LoginInfoId']);
            return ['ok' => false, 'error' => 'Invalid username or password.', 'row' => null];
        }

        // ── Success: reset failure counter ───────────────────────────────────
        try {
            Database::execute(
                "UPDATE logininfo SET failed_attempts = 0, locked_until = NULL WHERE LoginInfoId = ?",
                [(int)$row['LoginInfoId']]
            );
        } catch (Exception $e) { /* columns not yet added — safe to skip */ }

        return ['ok' => true, 'error' => '', 'row' => $row];
    }

    /**
     * Resolve admin/teacher flags from a Role string — single source of
     * truth shared by login(), isAdmin(), isTeacher() and the mobile API
     * (Lib/ApiAuth.php) so role rules can never drift between the web
     * session and the token-based API.
     *
     * @return array{isAdmin:bool,isTeacher:bool}
     */
    public static function resolveRoleFlags(string $role): array
    {
        $roleUpper = strtoupper($role);
        return [
            'isAdmin'          => in_array($roleUpper, ['ADMIN', 'ADMN', 'PRCIPAL', 'PRINCIPAL'], true),
            'isTeacher'        => strpos($roleUpper, 'TEACH') !== false,
            // Institute-Admin: scoped admin who only sees/manages students in
            // their own institute (userinfo.InstituteId). Deliberately NOT
            // included in isAdmin above — it must never inherit full-admin
            // pages, only the dedicated Admin/InstituteAdmin*.php views.
            'isInstituteAdmin' => in_array($roleUpper, ['INSTADMIN', 'INSTITUTE_ADMIN', 'INSTITUTEADMIN'], true),
        ];
    }

    /**
     * Called from auth/otp-verify.php after the user enters a valid code.
     * Reads $_SESSION['otp_pending'] and establishes the full authenticated session.
     */
    public static function completeOtpLogin(): bool
    {
        $pending = $_SESSION['otp_pending'] ?? null;
        if (!$pending || !isset($pending['expires']) || time() > $pending['expires']) {
            unset($_SESSION['otp_pending']);
            return false;
        }

        /* Re-validate single-session token is still current in DB */
        try {
            $dbRow = Database::fetchOne(
                "SELECT session_token FROM logininfo WHERE LoginInfoId = ? LIMIT 1",
                [$pending['login_id']]
            );
            if (!$dbRow || $dbRow['session_token'] !== $pending['token']) {
                unset($_SESSION['otp_pending']);
                return false;
            }
        } catch (Exception $e) { /* DB unreachable — proceed */ }

        self::finalizeSession(
            $pending['token'],
            [
                'UserInfoId' => $pending['user_id'],
                'LoginInfoId'=> $pending['login_id'],
                'Role'       => $pending['role'],
            ],
            $pending['is_admin'],
            $pending['is_teacher'],
            $pending['is_institute_admin'] ?? false,
            $pending['must_change_pwd']    ?? false,
            $pending['display_name'],
            $pending['login_name']
        );

        /* Record the actual login event now */
        self::recordLoginEvent((int)$pending['user_id'], (int)$pending['login_id'], $pending['login_name']);

        unset($_SESSION['otp_pending']);
        return true;
    }

    /**
     * Internal: populate session keys and record the login event.
     * Extracted so both the direct-login and OTP paths share the same code.
     */
    private static function finalizeSession(
        string $token,
        array  $row,
        bool   $isAdmin,
        bool   $isTeacher,
        bool   $isInstituteAdmin,
        bool   $mustChangePassword,
        string $displayName,
        string $loginName
    ): void {
        $role = $row['Role'] ?? '';

        $_SESSION['user_id']            = (int)($row['UserInfoId']  ?? $row['user_id']  ?? 0);
        $_SESSION['login_id']           = (int)($row['LoginInfoId'] ?? $row['login_id'] ?? 0);
        $_SESSION['role']               = $role;
        $_SESSION['is_admin']           = $isAdmin;
        $_SESSION['is_teacher']         = $isTeacher;
        $_SESSION['is_institute_admin'] = $isInstituteAdmin;
        $_SESSION['must_change_password'] = $mustChangePassword;
        $_SESSION['full_name']          = $displayName;
        $_SESSION[SESSION_TOKEN_KEY]    = $token;
        $_SESSION['last_activity']      = time();
        $_SESSION['last_regen']         = time();

        // Legacy keys
        $_SESSION['Admin']       = $displayName;
        $_SESSION['Role']        = $role;
        $_SESSION['LoginInfoId'] = (int)($row['LoginInfoId'] ?? $row['login_id'] ?? 0);
        $_SESSION['UserInfoId']  = (int)($row['UserInfoId']  ?? $row['user_id']  ?? 0);
    }

    // ── Session guard ──────────────────────────────────────────────────────────

    /**
     * Protect a page. Redirects to login if:
     *  • Not logged in / stale session
     *  • 15-minute inactivity timeout exceeded
     *  • Single-session token no longer matches DB (displaced by another login)
     *
     * Also performs periodic session ID regeneration every 5 minutes.
     */
    public static function requireLogin(?string $redirectTo = null): void
    {
        $redirectTo = $redirectTo ?? self::loginUrl();

        // ── All required session keys must exist ──────────────────────────────
        if (!isset(
            $_SESSION['user_id'],
            $_SESSION['login_id'],
            $_SESSION['role'],
            $_SESSION[SESSION_TOKEN_KEY]
        )) {
            self::redirect($redirectTo);
        }

        // ── Inactivity timeout ────────────────────────────────────────────────
        // Normally: logged out after $timeout seconds since the last verified
        // request. But a student mid-exam may go 10+ minutes between full page
        // loads (autosave pings refresh last_activity directly via
        // touchActivity() — see autosave.php — so this rarely engages there).
        // exam_deadline is a belt-and-suspenders safety net: as long as a
        // student is within their allotted exam time (+ grace), don't evict
        // them even if activity-tracking hiccups for any reason.
        $timeout      = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 900;
        $lastActivity = $_SESSION['last_activity'] ?? time();
        $deadline     = $lastActivity + $timeout;

        if (!empty($_SESSION['exam_deadline'])) {
            $deadline = max($deadline, (int)$_SESSION['exam_deadline']);
        }

        if (time() > $deadline) {
            self::logout();
            self::redirect($redirectTo . ((strpos($redirectTo, '?') !== false) ? '&' : '?') . 'timeout=1');
        }

        // ── Single-session token check ────────────────────────────────────────
        // Only kick the user when we can positively confirm a token mismatch.
        // If the DB is unreachable we let the request through rather than
        // incorrectly logging out an active user.
        try {
            $row = Database::fetchOne(
                "SELECT session_token FROM logininfo WHERE LoginInfoId = ? LIMIT 1",
                [$_SESSION['login_id']]
            );
            // $row === false means the login record was deleted — treat as kicked.
            if ($row === false || $row['session_token'] !== $_SESSION[SESSION_TOKEN_KEY]) {
                session_unset();
                session_destroy();
                self::redirect($redirectTo . ((strpos($redirectTo, '?') !== false) ? '&' : '?') . 'kicked=1');
            }
        } catch (Exception $e) {
            // DB error: skip the check rather than incorrectly evicting the user.
            error_log('Auth: single-session check skipped due to DB error: ' . $e->getMessage());
        }

        // ── Periodic session ID regeneration (every 5 min) ───────────────────
        $regenInterval = defined('SESSION_REGEN_INTERVAL') ? SESSION_REGEN_INTERVAL : 300;
        $lastRegen     = $_SESSION['last_regen'] ?? 0;

        if (time() - $lastRegen > $regenInterval) {
            session_regenerate_id(true);
            $_SESSION['last_regen'] = time();
        }

        // ── Forced password-change gate (migration_v59) ───────────────────────
        // Set by Auth::resetPasswordToDefault() (Admin/Institute-Admin "reset
        // student password" feature). Every protected page runs through this
        // check via requireLogin(), so there's no page a user could navigate
        // to instead — only the change-password page itself and logout are
        // exempt, same pattern as the existing single-session/timeout checks.
        if (!empty($_SESSION['must_change_password'])) {
            $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
            if (!in_array($script, ['change-password-forced.php', 'logout.php'], true)) {
                self::redirect(self::rootRelativeUrl('auth/change-password-forced.php'));
            }
        }

        // ── Refresh activity timestamp ────────────────────────────────────────
        $_SESSION['last_activity'] = time();
    }

    // ── Logout ─────────────────────────────────────────────────────────────────

    public static function logout(): void
    {
        if (isset($_SESSION['login_id'])) {
            try {
                Database::execute(
                    "UPDATE logininfo SET session_token = NULL WHERE LoginInfoId = ?",
                    [$_SESSION['login_id']]
                );
            } catch (Exception $e) { /* ignore */ }
        }
        // Wipe all session data and destroy the cookie
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 86400,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Ensure the reset-token columns exist on logininfo. If the database is a
     * few migrations behind, add them at runtime so the feature still works.
     */
    public static function ensurePasswordResetColumns(): void
    {
        if (!Database::tableExists('logininfo')) {
            return;
        }

        if (!Database::hasColumn('logininfo', 'reset_token')) {
            try {
                Database::execute(
                    "ALTER TABLE logininfo ADD COLUMN reset_token VARCHAR(255) NULL AFTER Password"
                );
            } catch (Exception $e) {
                error_log('Auth::ensurePasswordResetColumns reset_token failed: ' . $e->getMessage());
            }
        }

        if (!Database::hasColumn('logininfo', 'reset_expires')) {
            try {
                Database::execute(
                    "ALTER TABLE logininfo ADD COLUMN reset_expires DATETIME NULL AFTER reset_token"
                );
            } catch (Exception $e) {
                error_log('Auth::ensurePasswordResetColumns reset_expires failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Create and email a password-reset token. Returns true even when the user
     * doesn't exist so the UI can show the generic success message.
     */
    public static function requestPasswordReset(string $loginName, string $email, string &$error): bool
    {
        $loginName = trim($loginName);
        $email     = trim($email);

        if ($loginName === '' || $email === '') {
            $error = 'Please enter both your username and email address.';
            return false;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
            return false;
        }

        self::ensurePasswordResetColumns();

        if (!Database::hasColumn('logininfo', 'reset_token') || !Database::hasColumn('logininfo', 'reset_expires')) {
            $error = 'Password reset is not configured for this installation. Please contact the administrator.';
            return false;
        }

        $row = Database::fetchOne(
            "SELECT LoginInfoId, LoginName, EMail
               FROM logininfo
              WHERE LoginName = ? AND (Email = ? OR EMail = ?) AND Active = 'Y'
              LIMIT 1",
            [$loginName, $email, $email]
        );

        if (!$row) {
            return true;
        }

        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);

        Database::execute(
            "UPDATE logininfo SET reset_token = ?, reset_expires = ? WHERE LoginInfoId = ?",
            [$token, $expires, (int)$row['LoginInfoId']]
        );

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $baseDir = rtrim(dirname($uri), '/');
        if ($baseDir === '' || $baseDir === '.') {
            $baseDir = '';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $resetLink = $scheme . '://' . $host . $baseDir . '/reset-password.php?token=' . urlencode($token);

        $body = "Dear " . htmlspecialchars($row['LoginName']) . ",\r\n\r\n"
              . "Click the link below to reset your password (valid 1 hour):\r\n"
              . $resetLink . "\r\n\r\nIf you did not request this, ignore this email.\r\n";

        Mailer::sendPlainText($email, 'Password Reset - ' . APP_NAME, $body, $row['LoginName']);
        return true;
    }

    /**
     * Return a reset record when the token exists and has not expired.
     */
    public static function validatePasswordResetToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        self::ensurePasswordResetColumns();
        if (!Database::hasColumn('logininfo', 'reset_token') || !Database::hasColumn('logininfo', 'reset_expires')) {
            return null;
        }

        $row = Database::fetchOne(
            "SELECT LoginInfoId, LoginName, reset_expires
               FROM logininfo
              WHERE reset_token = ?
              LIMIT 1",
            [$token]
        );

        if (!$row) {
            return null;
        }

        if (empty($row['reset_expires']) || strtotime($row['reset_expires']) < time()) {
            Database::execute(
                "UPDATE logininfo SET reset_token = NULL, reset_expires = NULL WHERE LoginInfoId = ?",
                [(int)$row['LoginInfoId']]
            );
            return null;
        }

        return $row;
    }

    /**
     * Complete a password reset using a valid token.
     */
    public static function completePasswordReset(string $token, string $newPassword, string $confirmPassword, string &$error): bool
    {
        $token = trim($token);
        $newPassword = (string)$newPassword;
        $confirmPassword = (string)$confirmPassword;

        $row = self::validatePasswordResetToken($token);
        if (!$row) {
            $error = 'This password reset link is invalid or has expired.';
            return false;
        }

        if ($newPassword === '') {
            $error = 'Please enter a new password.';
            return false;
        }

        if (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters.';
            return false;
        }

        if ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
            return false;
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        try {
            Database::execute(
                "UPDATE logininfo
                    SET Password = ?, reset_token = NULL, reset_expires = NULL,
                        failed_attempts = 0, locked_until = NULL
                  WHERE LoginInfoId = ?",
                [$hash, (int)$row['LoginInfoId']]
            );

            if (Database::hasColumn('logininfo', 'MustChangePassword')) {
                Database::execute(
                    "UPDATE logininfo SET MustChangePassword = 0 WHERE LoginInfoId = ?",
                    [(int)$row['LoginInfoId']]
                );
            }
        } catch (Exception $e) {
            error_log('Auth::completePasswordReset failed: ' . $e->getMessage());
            $error = 'Could not update your password. Please try again.';
            return false;
        }

        return true;
    }

    // ── CSRF ───────────────────────────────────────────────────────────────────

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate CSRF token on POST. Dies with 403 on failure.
     * Safe to call on GET — no-ops when request method is not POST.
     */
    public static function validateCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return; // GET/HEAD/OPTIONS — nothing to validate
        }
        $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $stored    = $_SESSION['csrf_token'] ?? '';
        if ($stored === '' || !hash_equals($stored, $submitted)) {
            http_response_code(403);
            die('Invalid or expired form token. Please go back and try again.');
        }
    }

    // ── Convenience accessors ──────────────────────────────────────────────────

    public static function isLoggedIn(): bool
    {
        return isset(
            $_SESSION['user_id'],
            $_SESSION['login_id'],
            $_SESSION['role'],
            $_SESSION[SESSION_TOKEN_KEY]
        );
    }

    public static function isAdmin(): bool
    {
        if (isset($_SESSION['is_admin'])) {
            return (bool)$_SESSION['is_admin'];
        }
        $role    = $_SESSION['role'] ?? $_SESSION['Role'] ?? '';
        $isAdmin = self::resolveRoleFlags($role)['isAdmin'];
        $_SESSION['is_admin'] = $isAdmin;
        return $isAdmin;
    }

    /**
     * Returns true for users with a TEACH role.
     * Teachers have their own dashboard and see only their own enrolled students.
     */
    public static function isTeacher(): bool
    {
        if (isset($_SESSION['is_teacher'])) {
            return (bool)$_SESSION['is_teacher'];
        }
        $role      = $_SESSION['role'] ?? $_SESSION['Role'] ?? '';
        $isTeacher = self::resolveRoleFlags($role)['isTeacher'];
        $_SESSION['is_teacher'] = $isTeacher;
        return $isTeacher;
    }

    /**
     * Returns true for users with the INSTADMIN role — a scoped admin who
     * only sees/manages students belonging to their own institute. NOT a
     * subset of isAdmin(): Institute-Admin pages must gate on this (or
     * isAdmin(), for full-admin support access) explicitly, never fall
     * through generic isAdmin()-only checks.
     */
    public static function isInstituteAdmin(): bool
    {
        if (isset($_SESSION['is_institute_admin'])) {
            return (bool)$_SESSION['is_institute_admin'];
        }
        $role = $_SESSION['role'] ?? $_SESSION['Role'] ?? '';
        $isInstituteAdmin = self::resolveRoleFlags($role)['isInstituteAdmin'];
        $_SESSION['is_institute_admin'] = $isInstituteAdmin;
        return $isInstituteAdmin;
    }

    /**
     * InstituteId of the currently logged-in user (via userinfo.InstituteId).
     * Used by Institute-Admin pages to scope every query to their own
     * institute. Returns null if the user has no userinfo row or no
     * institute set (e.g. a full Admin with no institute link).
     */
    public static function currentInstituteId(): ?int
    {
        $userId = self::currentUserId();
        if ($userId <= 0) return null;
        try {
            $row = Database::fetchOne(
                "SELECT InstituteId FROM userinfo WHERE UserInfoId = ? LIMIT 1", [$userId]);
            return $row && $row['InstituteId'] !== null ? (int)$row['InstituteId'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Where should a user land right after finishing login (or a forced
     * password change)? Centralizes the role→landing-page mapping so
     * auth/login.php, auth/otp-verify.php and auth/change-password-forced.php
     * can't drift apart. Returns a URL relative to auth/ — the directory all
     * three callers live in.
     */
    public static function postLoginUrl(): string
    {
        // A full Admin who also happens to hold the INSTADMIN role (shouldn't
        // normally happen, but roles are free-text) still gets the full
        // admin experience — isAdmin() wins.
        if (self::isInstituteAdmin() && !self::isAdmin()) {
            return '../Admin/InstituteAdminHome.php';
        }
        return '../exam/search.php';
    }

    /**
     * Reset a user's password to the app-wide default (DEFAULT_RESET_PASSWORD)
     * and flag their account so Auth::requireLogin() forces them through
     * auth/change-password-forced.php on next login, before they can reach
     * anything else. Used by Admin/ResetStudentPassword.php and
     * Admin/EditUser.php — the single place this logic lives so both entry
     * points (full-admin edit page, and the dedicated Admin/Institute-Admin
     * reset page) can never drift apart.
     *
     * Caller is responsible for authorization (is this admin allowed to
     * reset *this* student's password?) — this method just performs the
     * reset once that's been confirmed.
     *
     * @return array{ok:bool,password:string,error:string}
     */
    public static function resetPasswordToDefault(int $userInfoId): array
    {
        if ($userInfoId <= 0) {
            return ['ok' => false, 'password' => '', 'error' => 'Invalid user.'];
        }

        $loginName = Database::fetchOne(
            "SELECT LoginName FROM userinfo WHERE UserInfoId = ? LIMIT 1", [$userInfoId]
        )['LoginName'] ?? null;

        if (!$loginName) {
            return ['ok' => false, 'password' => '', 'error' => 'User not found.'];
        }

        $newPassword = DEFAULT_RESET_PASSWORD;
        $hash        = password_hash($newPassword, PASSWORD_DEFAULT);

        try {
            if (Database::hasColumn('logininfo', 'MustChangePassword')) {
                Database::execute(
                    "UPDATE logininfo SET Password = ?, MustChangePassword = 1 WHERE LoginName = ?",
                    [$hash, $loginName]
                );
            } else {
                // migration_v59 not yet run — reset the password but skip the
                // forced-change flag (there's no column to store it in yet).
                Database::execute(
                    "UPDATE logininfo SET Password = ? WHERE LoginName = ?",
                    [$hash, $loginName]
                );
            }
        } catch (Exception $e) {
            error_log('Auth::resetPasswordToDefault failed: ' . $e->getMessage());
            return ['ok' => false, 'password' => '', 'error' => 'Could not reset password — please try again.'];
        }

        // Clear any lockout so the new password works immediately.
        try {
            Database::execute(
                "UPDATE logininfo SET failed_attempts = 0, locked_until = NULL WHERE LoginName = ?",
                [$loginName]
            );
        } catch (Exception $e) { /* rate-limit columns not present — safe to skip */ }

        return ['ok' => true, 'password' => $newPassword, 'error' => ''];
    }

    /** Display name (FstName + LstName). NOT the login name. */
    public static function currentUser(): string
    {
        return $_SESSION['full_name'] ?? '';
    }

    public static function currentRole(): string
    {
        return $_SESSION['role'] ?? '';
    }

    public static function currentUserId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public static function currentLoginId(): int
    {
        return (int)($_SESSION['login_id'] ?? 0);
    }

    /**
     * Remaining session seconds before inactivity logout.
     * Useful for a JS countdown warning.
     */
    public static function sessionSecondsRemaining(): int
    {
        $timeout      = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 900;
        $lastActivity = $_SESSION['last_activity'] ?? time();
        $remaining    = $timeout - (int)(time() - $lastActivity);

        if (!empty($_SESSION['exam_deadline'])) {
            $remaining = max($remaining, (int)$_SESSION['exam_deadline'] - time());
        }

        return max(0, $remaining);
    }

    /**
     * Refresh last_activity without running the full requireLogin() flow
     * (single-session DB check, session-ID regen, etc). Intended for
     * lightweight endpoints — like exam autosave pings — that are called
     * frequently and only need to prove the user is still active.
     * No-ops if the caller isn't logged in.
     */
    public static function touchActivity(): void
    {
        if (self::isLoggedIn()) {
            $_SESSION['last_activity'] = time();
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Increment failed_attempts. Lock the account after LOGIN_MAX_ATTEMPTS.
     */
    private static function recordFailedAttempt(int $loginInfoId): void
    {
        $maxAttempts   = defined('LOGIN_MAX_ATTEMPTS')    ? LOGIN_MAX_ATTEMPTS    : 5;
        $lockoutMins   = defined('LOGIN_LOCKOUT_MINUTES') ? LOGIN_LOCKOUT_MINUTES : 15;

        try {
            Database::execute(
                "UPDATE logininfo
                    SET failed_attempts = failed_attempts + 1,
                        locked_until    = IF(
                            failed_attempts + 1 >= ?,
                            DATE_ADD(NOW(), INTERVAL ? MINUTE),
                            locked_until
                        )
                  WHERE LoginInfoId = ?",
                [$maxAttempts, $lockoutMins, $loginInfoId]
            );
        } catch (Exception $e) {
            // Column may not exist yet if migration hasn't been run — silently skip
            error_log('Auth::recordFailedAttempt skipped: ' . $e->getMessage());
        }
    }

    /** Verify password — supports bcrypt, plain-text, MD5. Upgrades on first match. */
    private static function verifyPassword(string $plain, string $stored, int $loginInfoId): bool
    {
        if (password_verify($plain, $stored)) {
            if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                self::updatePasswordHash($loginInfoId, $plain);
            }
            return true;
        }
        if ($plain === $stored) {
            self::updatePasswordHash($loginInfoId, $plain);
            return true;
        }
        if (md5($plain) === $stored) {
            self::updatePasswordHash($loginInfoId, $plain);
            return true;
        }
        return false;
    }

    private static function updatePasswordHash(int $loginInfoId, string $plain): void
    {
        try {
            Database::execute(
                "UPDATE logininfo SET Password = ? WHERE LoginInfoId = ?",
                [password_hash($plain, PASSWORD_DEFAULT), $loginInfoId]
            );
        } catch (Exception $e) {
            error_log('Auth::updatePasswordHash failed: ' . $e->getMessage());
        }
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    private static function loginUrl(): string
    {
        return self::rootRelativeUrl('auth/login.php');
    }

    /**
     * Build a URL to $relPath (relative to the app root, e.g. Exam/) that's
     * correct from wherever the *current* script happens to live. Every
     * known caller sits exactly one level below the app root (exam/, Admin/,
     * auth/) or at the root itself — same assumption includes/header.php
     * documents and relies on for its own $_root prefix. Generalizes the old
     * loginUrl(), which only special-cased '/Admin/' and silently produced a
     * wrong (rootless) path for scripts under exam/ or auth/ that rely on
     * the null-$redirectTo default.
     */
    private static function rootRelativeUrl(string $relPath): string
    {
        $scriptFile = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
        $rootDir    = realpath(__DIR__ . '/..');               // Lib/.. => Exam/
        $scriptDir  = $scriptFile !== '' ? realpath(dirname($scriptFile)) : false;

        if ($rootDir && $scriptDir) {
            $depth = ($scriptDir !== $rootDir) ? 1 : 0;
        } else {
            // Fallback when realpath() is unavailable: best-effort string check.
            $normalized = str_replace('\\', '/', $scriptFile);
            $depth = (strpos($normalized, '/Admin/') !== false
                   || strpos($normalized, '/exam/') !== false
                   || strpos($normalized, '/auth/') !== false) ? 1 : 0;
        }
        return str_repeat('../', $depth) . $relPath;
    }

    /**
     * Record a login event.
     * $userId      — userinfo.UserInfoId (may be 0 if user has no userinfo row)
     * $loginInfoId — logininfo.LoginInfoId (always valid, used as fallback)
     *
     * Stores a negative LoginInfoId when UserInfoId is 0 so the row is never
     * lost to a "UserId = 0" INNER JOIN miss.
     *
     * Public so Lib/ApiAuth.php can log mobile logins into the same
     * logintrackinfo table the web app's "Login Activity" admin tab reads.
     */
    public static function recordLoginEvent(int $userId, int $loginInfoId, string $loginName): void
    {
        $storedUserId = $userId > 0 ? $userId : -$loginInfoId;

        /* Capture client IP — respects common reverse-proxy headers */
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? null;
        if ($ip) {
            // X-Forwarded-For can be a comma-separated list; take the first (client) IP
            $ip = trim(explode(',', $ip)[0]);
            if (strlen($ip) > 45) $ip = substr($ip, 0, 45); // clamp to column width
        }

        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500)
            : null;

        try {
            Database::execute(
                "INSERT INTO logintrackinfo (UserId, CreateDtm, IpAddress, UserAgent)
                 VALUES (?, NOW(), ?, ?)",
                [$storedUserId, $ip, $ua]
            );
        } catch (PDOException $e) {
            /* Columns may not exist yet (pre-migration_v28) — fall back to minimal insert */
            try {
                Database::execute(
                    "INSERT INTO logintrackinfo (UserId, CreateDtm) VALUES (?, NOW())",
                    [$storedUserId]
                );
            } catch (PDOException $e2) {
                error_log('logintrackinfo insert failed: ' . $e2->getMessage());
            }
        }
    }
}
