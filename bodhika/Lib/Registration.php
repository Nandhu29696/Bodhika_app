<?php
/**
 * Lib/Registration.php — Student self-registration for the mobile API.
 *
 * Clean-room re-implementation of auth/register.php's STUDENT branch only
 * (same validation rules, same logininfo/userinfo insert + 3-tier schema
 * fallback for InstituteId / BloodGroup / WillingToDonateBlood). Teacher
 * self-registration is intentionally NOT exposed on the mobile API for v1 —
 * it requires a mandatory passport photo upload and lands in a pending-
 * approval queue reviewed from the desktop admin console, so there is no
 * urgency to support it from a phone. Admins can still add teachers from
 * the desktop app as today.
 *
 * No CAPTCHA here: the web form's math CAPTCHA defends a public HTML form
 * against bots; a mobile app talking to a token-protected JSON API is a
 * different threat model. The IP registration-rate-limit is kept (and kept
 * in sync with auth/register.php's cap — see ipUnderRateLimit() below).
 *
 * migration_v60: new students land Active='N' + RegistrationStatus='Pending'
 * here too, same as the web flow — Auth::verifyCredentials() (shared by both
 * ApiAuth::login() and the web session login) already refuses sign-in for
 * any non-'Y' account, so a mobile self-registration is blocked from login
 * until an admin approves it via Admin/StudentApprovals.php, exactly like a
 * web one.
 *
 * Requires Config.php, Database.php to already be loaded.
 */
final class Registration
{
    private const BLOOD_GROUPS = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];

    private const MOBILE_RULES = [
        '+91'  => [10, 10, '/^[6-9]/'], '+1' => [10, 10, null], '+44' => [10, 10, null],
        '+61'  => [9, 9, null], '+64' => [8, 9, null], '+971' => [9, 9, null],
        '+966' => [9, 9, '/^[5]/'], '+65' => [8, 8, '/^[689]/'], '+60' => [9, 10, '/^[1]/'],
        '+94'  => [9, 9, '/^[7]/'], '+92' => [10, 10, '/^[3]/'], '+880' => [10, 10, '/^[1]/'],
        '+977' => [10, 10, '/^[9]/'], '+81' => [10, 11, null], '+82' => [9, 10, null],
        '+86'  => [11, 11, null], '+852' => [8, 8, null], '+49' => [10, 12, null],
        '+33'  => [9, 9, null], '+39' => [9, 10, null], '+34' => [9, 9, null],
        '+31'  => [9, 9, null], '+46' => [9, 9, null], '+47' => [8, 8, null],
        '+45'  => [8, 8, null], '+41' => [9, 9, null], '+7' => [10, 10, null],
        '+55'  => [10, 11, null], '+52' => [10, 10, null], '+54' => [10, 10, null],
        '+27'  => [9, 9, null], '+234' => [10, 10, '/^[07-9]/'], '+254' => [9, 9, '/^[7]/'],
        '+20'  => [10, 10, null], '+212' => [9, 9, null],
    ];

    /**
     * @param array $f Expected keys: loginName, password, confirmPassword, email,
     *                  firstName, lastName, gender, bloodGroup, willingToDonate (bool),
     *                  countryCode, mobileNumber, instituteId (?int)
     * @return array ['ok'=>true,'loginId'=>int,'userId'=>int] or ['ok'=>false,'errors'=>string[]]
     */
    public static function registerStudent(array $f, string $clientIp): array
    {
        $loginName   = trim($f['loginName'] ?? '');
        $plainPwd    = (string)($f['password'] ?? '');
        $confirm     = (string)($f['confirmPassword'] ?? '');
        $email       = trim($f['email'] ?? '');
        $fstName     = trim($f['firstName'] ?? '');
        $lstName     = trim($f['lastName'] ?? '');
        $gender      = (string)($f['gender'] ?? '');
        $bloodGroup  = trim($f['bloodGroup'] ?? '');
        $bloodGroupParam = $bloodGroup !== '' ? $bloodGroup : null;
        $willDonate  = !empty($f['willingToDonate']) ? 'Y' : 'N';
        $countryCode = trim($f['countryCode'] ?? '+91');
        $mobileRaw   = preg_replace('/\D/', '', trim($f['mobileNumber'] ?? ''));
        $mobile      = $mobileRaw !== '' ? $countryCode . $mobileRaw : '';
        $instituteId = isset($f['instituteId']) && (int)$f['instituteId'] > 0 ? (int)$f['instituteId'] : null;

        $errors = [];
        if (!self::ipUnderRateLimit($clientIp)) {
            return ['ok' => false, 'errors' => ['Too many registrations from this network recently. Please try again later.']];
        }

        if ($loginName === '') $errors[] = 'Username is required.';
        elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $loginName)) $errors[] = 'Username: 3-50 chars, letters/digits/_ . - only.';
        if ($fstName === '') $errors[] = 'First name is required.';
        if ($email === '') $errors[] = 'Email address is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
        if ($plainPwd === '') $errors[] = 'Password is required.';
        elseif (strlen($plainPwd) < 8) $errors[] = 'Password must be at least 8 characters.';
        elseif ($plainPwd !== $confirm) $errors[] = 'Passwords do not match.';

        if ($mobileRaw !== '') {
            $digits = strlen($mobileRaw);
            if (!ctype_digit($mobileRaw)) {
                $errors[] = 'Mobile number must contain digits only.';
            } elseif (isset(self::MOBILE_RULES[$countryCode])) {
                [$min, $max, $leadPat] = self::MOBILE_RULES[$countryCode];
                if ($digits < $min || $digits > $max) {
                    $errors[] = "Mobile number for {$countryCode} must be " . ($min === $max ? "{$min}" : "{$min}-{$max}") . " digits.";
                } elseif ($leadPat && !preg_match($leadPat, $mobileRaw)) {
                    $errors[] = "Mobile number for {$countryCode} appears invalid (check starting digit).";
                }
            } elseif ($digits < 6 || $digits > 15) {
                $errors[] = 'Mobile number must be between 6 and 15 digits.';
            }
        }
        if ($bloodGroup !== '' && !in_array($bloodGroup, self::BLOOD_GROUPS, true)) {
            $errors[] = 'Invalid blood group selected.';
        }
        if ($errors) return ['ok' => false, 'errors' => $errors];

        if (Database::fetchOne("SELECT LoginInfoId FROM logininfo WHERE LoginName = ? LIMIT 1", [$loginName])) {
            return ['ok' => false, 'errors' => ['That username is already taken.']];
        }
        $emailUsed = Database::fetchOne("SELECT LoginInfoId FROM logininfo WHERE Email = ? LIMIT 1", [$email]);
        if (!$emailUsed) {
            try { $emailUsed = Database::fetchOne("SELECT UserInfoId FROM userinfo WHERE EMail = ? LIMIT 1", [$email]); }
            catch (Exception $e) {}
        }
        if ($emailUsed) return ['ok' => false, 'errors' => ['An account with that email already exists.']];

        $hash = password_hash($plainPwd, PASSWORD_DEFAULT);
        $loginInfoId = 0;

        try {
            Database::beginTransaction();

            try {
                Database::execute(
                    "INSERT INTO logininfo (LoginName, Password, Role, Email, Active, InstituteId, RegisteredIp)
                     VALUES (?, ?, 'STDNT', ?, 'N', ?, ?)",
                    [$loginName, $hash, $email, $instituteId, $clientIp]
                );
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Unknown column') === false) throw $e;
                try {
                    Database::execute(
                        "INSERT INTO logininfo (LoginName, Password, Role, Email, Active, InstituteId) VALUES (?, ?, 'STDNT', ?, 'N', ?)",
                        [$loginName, $hash, $email, $instituteId]
                    );
                } catch (Exception $e2) {
                    Database::execute(
                        "INSERT INTO logininfo (LoginName, Password, Role, Email, Active) VALUES (?, ?, 'STDNT', ?, 'N')",
                        [$loginName, $hash, $email]
                    );
                }
            }
            $loginInfoId = (int)Database::lastInsertId();

            // Registration status label (migration_v60) — guarded, best-
            // effort; Active='N' above is what actually blocks login even if
            // this column isn't present yet. Mirrors auth/register.php.
            if (Database::hasColumn('logininfo', 'RegistrationStatus')) {
                try {
                    Database::execute(
                        "UPDATE logininfo SET RegistrationStatus = 'Pending' WHERE LoginInfoId = ?",
                        [$loginInfoId]
                    );
                } catch (Exception $e) {}
            }

            try {
                Database::execute(
                    "INSERT INTO userinfo (LoginName, FstName, MiddleName, LstName, Gender, EMail, Mobile, Address, ImageLoc, Note, InstituteId, BloodGroup, WillingToDonateBlood)
                     VALUES (?, ?, '', ?, ?, ?, ?, '', '', '', ?, ?, ?)",
                    [$loginName, $fstName, $lstName, $gender, $email, $mobile, $instituteId, $bloodGroupParam, $willDonate]
                );
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'BloodGroup') !== false || strpos($msg, 'WillingToDonateBlood') !== false) {
                    try {
                        Database::execute(
                            "INSERT INTO userinfo (LoginName, FstName, MiddleName, LstName, Gender, EMail, Mobile, Address, ImageLoc, Note, InstituteId)
                             VALUES (?, ?, '', ?, ?, ?, ?, '', '', '', ?)",
                            [$loginName, $fstName, $lstName, $gender, $email, $mobile, $instituteId]
                        );
                    } catch (Exception $e2) {
                        Database::execute(
                            "INSERT INTO userinfo (LoginName, FstName, MiddleName, LstName, Gender, EMail, Mobile, Address, ImageLoc, Note)
                             VALUES (?, ?, '', ?, ?, ?, ?, '', '', '')",
                            [$loginName, $fstName, $lstName, $gender, $email, $mobile]
                        );
                    }
                } elseif (strpos($msg, 'InstituteId') !== false || strpos($msg, 'Unknown column') !== false) {
                    try {
                        Database::execute(
                            "INSERT INTO userinfo (LoginName, FstName, MiddleName, LstName, Gender, EMail, Mobile, Address, ImageLoc, Note, BloodGroup, WillingToDonateBlood)
                             VALUES (?, ?, '', ?, ?, ?, ?, '', '', '', ?, ?)",
                            [$loginName, $fstName, $lstName, $gender, $email, $mobile, $bloodGroupParam, $willDonate]
                        );
                    } catch (Exception $e2) {
                        Database::execute(
                            "INSERT INTO userinfo (LoginName, FstName, MiddleName, LstName, Gender, EMail, Mobile, Address, ImageLoc, Note)
                             VALUES (?, ?, '', ?, ?, ?, ?, '', '', '')",
                            [$loginName, $fstName, $lstName, $gender, $email, $mobile]
                        );
                    }
                } else {
                    throw $e;
                }
            }

            Database::commit();
        } catch (Exception $ex) {
            Database::rollBack();
            error_log('Registration::registerStudent failed: ' . $ex->getMessage());
            return ['ok' => false, 'errors' => ['Could not create account. Please try again.']];
        }

        self::logIpRegistration($clientIp);

        $userRow = Database::fetchOne("SELECT UserInfoId FROM userinfo WHERE LoginName = ? LIMIT 1", [$loginName]);
        return ['ok' => true, 'loginId' => $loginInfoId, 'userId' => (int)($userRow['UserInfoId'] ?? 0)];
    }

    /**
     * Max 10 registrations per IP per rolling hour — kept in sync with
     * auth/register.php's cap (same registration_rate_limit table, shared
     * by both channels, so registrations from web and mobile count toward
     * the same per-IP limit).
     *
     * Previously queried a column called `created_at`, which does not exist
     * on this table (migration_v23.sql names it `registered_at`) — every
     * call silently threw, was caught by the catch block below, and "failed
     * open", so this check never actually limited anything from the mobile
     * app. Fixed alongside the web-side cap change.
     */
    private static function ipUnderRateLimit(string $ip, int $max = 10): bool
    {
        try {
            $row = Database::fetchOne(
                "SELECT COUNT(*) AS cnt FROM registration_rate_limit WHERE ip_address = ? AND registered_at >= NOW() - INTERVAL 1 HOUR",
                [$ip]
            );
            return (int)($row['cnt'] ?? 0) < $max;
        } catch (Exception $e) {
            return true; // table not yet created — fail open, same as the web flow
        }
    }

    private static function logIpRegistration(string $ip): void
    {
        try {
            Database::execute("INSERT INTO registration_rate_limit (ip_address) VALUES (?)", [$ip]);
        } catch (Exception $e) {}
    }
}
