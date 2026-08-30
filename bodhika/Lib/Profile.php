<?php
/**
 * Lib/Profile.php — Student/teacher self-profile read + update for the mobile API.
 *
 * Clean-room re-implementation of exam/settings.php's "Edit Profile" and
 * "Change Password" actions, reusing Lib/Phone.php for country-code handling
 * (no second copy of the mobile-number-validation table). Institute changes
 * are queued into user_change_requests for admin approval — same as the web
 * app — rather than applied immediately, since that's a deliberate anti-abuse
 * control already in production.
 *
 * Requires Config.php, Database.php, Phone.php to already be loaded.
 */
final class Profile
{
    private const BLOOD_GROUPS = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];

    public static function get(int $userId): ?array
    {
        try {
            $user = Database::fetchOne(
                "SELECT u.UserInfoId, u.FstName, u.LstName, u.EMail, u.Mobile, u.InstituteId,
                        u.LoginName, u.BloodGroup, u.WillingToDonateBlood,
                        COALESCE(inst.InstituteName,'') AS InstituteName
                   FROM userinfo u
              LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
                  WHERE u.UserInfoId = ? LIMIT 1",
                [$userId]
            );
        } catch (Exception $e) {
            $user = Database::fetchOne(
                "SELECT u.UserInfoId, u.FstName, u.LstName, u.EMail, u.Mobile, u.InstituteId,
                        u.LoginName, COALESCE(inst.InstituteName,'') AS InstituteName
                   FROM userinfo u
              LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
                  WHERE u.UserInfoId = ? LIMIT 1",
                [$userId]
            );
        }
        if (!$user) return null;

        $pendingInstitute = null;
        try {
            $pendingInstitute = Database::fetchOne(
                "SELECT NewValue, NewLabel FROM user_change_requests
                  WHERE UserId = ? AND FieldName = 'InstituteId' AND Status = 'pending'
                  ORDER BY RequestedAt DESC LIMIT 1",
                [$userId]
            );
        } catch (Exception $e) {}

        [$cc, $digits] = Phone::split($user['Mobile'] ?? '');

        return [
            'userId'               => (int)$user['UserInfoId'],
            'loginName'            => $user['LoginName'] ?? '',
            'firstName'            => $user['FstName'] ?? '',
            'lastName'             => $user['LstName'] ?? '',
            'email'                => $user['EMail'] ?? '',
            'countryCode'          => $cc,
            'mobileNumber'         => $digits,
            'bloodGroup'           => $user['BloodGroup'] ?? null,
            'willingToDonateBlood' => ($user['WillingToDonateBlood'] ?? 'N') === 'Y',
            'instituteId'          => isset($user['InstituteId']) ? (int)$user['InstituteId'] : null,
            'instituteName'        => $user['InstituteName'] ?? '',
            'pendingInstituteChange' => $pendingInstitute ? [
                'newInstituteId'   => $pendingInstitute['NewValue'] ?? '',
                'newInstituteName' => $pendingInstitute['NewLabel'] ?? '',
            ] : null,
        ];
    }

    /**
     * @param array $f firstName, lastName, email, countryCode, mobileNumber, bloodGroup,
     *                 willingToDonateBlood (bool), instituteId (?int)
     * @return array ['ok'=>true,'message'=>string] or ['ok'=>false,'errors'=>string[]]
     */
    public static function update(int $userId, array $f, bool $isAdmin): array
    {
        $current = self::get($userId);
        if (!$current) return ['ok' => false, 'errors' => ['Profile not found.']];

        $fstName  = trim($f['firstName'] ?? '');
        $lstName  = trim($f['lastName'] ?? '');
        $email    = trim($f['email'] ?? '');
        $cc       = trim($f['countryCode'] ?? Phone::DEFAULT_CC);
        $digits   = preg_replace('/\D/', '', (string)($f['mobileNumber'] ?? ''));
        $mobile   = Phone::combine($cc, $digits);
        $bloodGroup = trim($f['bloodGroup'] ?? '');
        $willDonate = !empty($f['willingToDonateBlood']) ? 'Y' : 'N';
        $instId   = isset($f['instituteId']) && (int)$f['instituteId'] > 0 ? (int)$f['instituteId'] : null;

        $errors = [];
        if ($fstName === '') $errors[] = 'First name is required.';
        if ($lstName === '') $errors[] = 'Last name is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        $mobileErr = Phone::validate($cc, $digits);
        if ($mobileErr !== null) $errors[] = $mobileErr;
        if ($bloodGroup !== '' && !in_array($bloodGroup, self::BLOOD_GROUPS, true)) $errors[] = 'Invalid blood group selected.';
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $messages = [];

        // Institute changes go through approval for non-admins, exactly like the web app.
        $instChanged = !$isAdmin && ((int)($current['instituteId'] ?? 0) !== (int)$instId);
        if ($instChanged) {
            $newInstLabel = '';
            if ($instId) {
                $instRow = Database::fetchOne("SELECT InstituteName FROM institutes WHERE InstituteId = ? LIMIT 1", [$instId]);
                $newInstLabel = $instRow['InstituteName'] ?? '';
            }
            try {
                Database::execute(
                    "UPDATE user_change_requests SET Status = 'rejected', AdminNote = 'Superseded by new request'
                      WHERE UserId = ? AND FieldName = 'InstituteId' AND Status = 'pending'",
                    [$userId]
                );
                Database::execute(
                    "INSERT INTO user_change_requests (UserId, FieldName, OldValue, NewValue, OldLabel, NewLabel)
                     VALUES (?, 'InstituteId', ?, ?, ?, ?)",
                    [$userId, $current['instituteId'] ?? '', $instId ?? '', $current['instituteName'] ?? '', $newInstLabel]
                );
                $messages[] = 'Institute change has been submitted for admin approval.';
            } catch (Exception $e) {
                $messages[] = 'Institute change could not be queued right now.';
            }
            $instId = $current['instituteId']; // keep existing value until approved
        }

        $bloodGroupParam = $bloodGroup !== '' ? $bloodGroup : null;
        try {
            Database::execute(
                "UPDATE userinfo SET FstName=?, LstName=?, EMail=?, Mobile=?, InstituteId=?, BloodGroup=?, WillingToDonateBlood=?
                  WHERE UserInfoId=?",
                [$fstName, $lstName, $email, $mobile, $instId, $bloodGroupParam, $willDonate, $userId]
            );
        } catch (Exception $e) {
            Database::execute(
                "UPDATE userinfo SET FstName=?, LstName=?, EMail=?, Mobile=?, InstituteId=? WHERE UserInfoId=?",
                [$fstName, $lstName, $email, $mobile, $instId, $userId]
            );
        }

        $messages[] = 'Profile updated successfully.';
        return ['ok' => true, 'message' => implode(' ', $messages)];
    }

    public static function changePassword(int $loginId, string $current, string $new1, string $new2): array
    {
        if ($current === '') return ['ok' => false, 'errors' => ['Current password is required.']];
        if (strlen($new1) < 8) return ['ok' => false, 'errors' => ['New password must be at least 8 characters.']];
        if ($new1 !== $new2) return ['ok' => false, 'errors' => ['New passwords do not match.']];
        if (defined('DEFAULT_RESET_PASSWORD') && $new1 === DEFAULT_RESET_PASSWORD) {
            return ['ok' => false, 'errors' => ['Please choose a password other than the temporary one you were given.']];
        }

        $li = Database::fetchOne("SELECT Password FROM logininfo WHERE LoginInfoId = ? LIMIT 1", [$loginId]);
        $stored = $li['Password'] ?? '';
        $valid = password_verify($current, $stored) || $current === $stored || md5($current) === $stored;
        if (!$valid) return ['ok' => false, 'errors' => ['Current password is incorrect.']];

        $hash = password_hash($new1, PASSWORD_DEFAULT);

        // Also clears the forced-password-change flag (migration_v59) if set —
        // this is the mobile app's only password-change path, covering both a
        // voluntary change and the admin-reset-triggered forced change (see
        // auth/change-password-forced.php for the web-side equivalent, which
        // this mirrors). Guarded so DBs without migration_v59 still work.
        if (Database::hasColumn('logininfo', 'MustChangePassword')) {
            Database::execute(
                "UPDATE logininfo SET Password = ?, MustChangePassword = 0 WHERE LoginInfoId = ?",
                [$hash, $loginId]
            );
        } else {
            Database::execute("UPDATE logininfo SET Password = ? WHERE LoginInfoId = ?", [$hash, $loginId]);
        }

        return ['ok' => true, 'message' => 'Password changed successfully.'];
    }
}
