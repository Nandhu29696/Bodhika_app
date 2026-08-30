<?php
/**
 * auth/register.php — Self-registration for students AND teachers.
 *
 * Account types:
 *   Student  → Role='STDNT', Active='Y'  (instant access)
 *   Teacher  → Role='TEACH', Active='N'  (pending admin approval)
 *              Creates teacher_profiles row with OffersOnline=0.
 *              Admin activates via ManageTeachers.php.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

// Institute.php is optional — only needed if migration_v16 has been run
$_instituteLibLoaded = false;
if (file_exists(__DIR__ . '/../Lib/Institute.php')) {
    require_once __DIR__ . '/../Lib/Institute.php';
    $_instituteLibLoaded = true;
}

if (Auth::isLoggedIn()) { header('Location: ../exam/search.php'); exit; }

/* ── IP helpers ─────────────────────────────────────────────────────────────── */
function getClientIp(): string {
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Returns true if this IP is under the hourly limit.
 * Max 10 successful registrations per IP per rolling hour.
 * Silently allows if the table doesn't exist yet (migration_v23 not run).
 */
function ipUnderRateLimit(string $ip, int $max = 10): bool {
    try {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM registration_rate_limit
              WHERE ip_address = ? AND registered_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$ip]
        );
        return (int)($row['cnt'] ?? 0) < $max;
    } catch (Exception $e) {
        return true; // table not yet created — fail open
    }
}

function logIpRegistration(string $ip): void {
    try {
        Database::execute(
            "INSERT INTO registration_rate_limit (ip_address) VALUES (?)", [$ip]
        );
    } catch (Exception $e) { /* migration_v23 not yet run — skip */ }
}

/* ── Math CAPTCHA helpers ────────────────────────────────────────────────────── */
function generateCaptcha(): void {
    $_SESSION['captcha_a']      = random_int(2, 9);
    $_SESSION['captcha_b']      = random_int(1, 9);
    $_SESSION['captcha_answer'] = $_SESSION['captcha_a'] + $_SESSION['captcha_b'];
}

// Generate on first visit (or after a failed attempt — regenerated there)
if (empty($_SESSION['captcha_answer'])) {
    generateCaptcha();
}

$msg = ''; $isErr = false;
$institutes = $_instituteLibLoaded ? Institute::listAll() : [];
$_clientIp  = getClientIp();

/* ── POST ──────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnRegister'])) {
    Auth::validateCsrf();

    /* ── IP rate-limit check ─────────────────────────────────────────────── */
    if (!ipUnderRateLimit($_clientIp)) {
        $msg = 'Too many accounts registered from your network in the last hour. Please try again later.';
        $isErr = true;
        generateCaptcha(); // refresh CAPTCHA regardless
    }

    $accountType = ($_POST['accountType'] ?? 'student') === 'teacher' ? 'teacher' : 'student';
    $loginName   = trim($_POST['txtLoginID']    ?? '');
    $plainPwd    = $_POST['txtPwd']             ?? '';
    $confirm     = $_POST['txtPwdConfirm']      ?? '';
    $email       = trim($_POST['txtEmail']      ?? '');
    $fstName     = trim($_POST['txtFstName']    ?? '');
    $midName     = trim($_POST['txtMidName']    ?? '');
    $lstName     = trim($_POST['txtLstName']    ?? '');
    $gender      = $_POST['rdoGender']          ?? '';
    $bloodGroup  = trim($_POST['selBloodGroup']  ?? '');
    $bloodGroupParam = $bloodGroup !== '' ? $bloodGroup : null;
    $willDonate  = (($_POST['chkWillingDonate'] ?? '') === 'Y') ? 'Y' : 'N';
    $countryCode = trim($_POST['txtCountryCode'] ?? '+91');
    $mobileRaw   = preg_replace('/\D/', '', trim($_POST['txtMobileNum'] ?? ''));
    $mobile      = ($mobileRaw !== '') ? $countryCode . $mobileRaw : '';
    $instituteId = filter_input(INPUT_POST, 'txtInstituteId', FILTER_VALIDATE_INT) ?: null;
    $instituteStudentId = trim($_POST['txtInstituteStudentId'] ?? '');
    if (mb_strlen($instituteStudentId) > 50) $instituteStudentId = mb_substr($instituteStudentId, 0, 50);

    /* Teacher-only fields */
    $qualification = trim($_POST['txtQualification'] ?? '');
    $experience    = trim($_POST['txtExperience']    ?? '');
    $bio           = trim($_POST['txtBio']           ?? '');

    /* ── Photo upload (teacher only — mandatory) ── */
    $errors    = [];
    $photoPath = '';
    if ($accountType === 'teacher') {
        $photo = $_FILES['teacherPhoto'] ?? null;
        if (!$photo || $photo['error'] === UPLOAD_ERR_NO_FILE || $photo['size'] === 0) {
            $errors[] = 'Passport size photo is required for teacher registration.';
        } elseif ($photo['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Photo upload failed (error ' . $photo['error'] . '). Max size is 2 MB.';
        } else {
            $allowedExt  = ['jpg','jpeg','png','webp'];
            $ext         = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
            $allowedMime = ['image/jpeg','image/png','image/webp'];
            $mime        = mime_content_type($photo['tmp_name']);
            if (!in_array($ext, $allowedExt) || !in_array($mime, $allowedMime)) {
                $errors[] = 'Photo must be a JPG, PNG, or WebP image.';
            } elseif ($photo['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Photo must be smaller than 2 MB.';
            } else {
                $uploadDir = dirname(__DIR__) . '/Admin/images/teachers/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $fileName  = 'teacher_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($photo['tmp_name'], $uploadDir . $fileName)) {
                    $photoPath = 'images/teachers/' . $fileName;
                } else {
                    $errors[] = 'Could not save photo. Check folder permissions.';
                }
            }
        }
    }

    /* ── CAPTCHA check ──────────────────────────────────────────────────── */
    if (!$isErr) {
        $captchaInput  = (int)($_POST['captcha_answer'] ?? -1);
        $captchaExpect = (int)($_SESSION['captcha_answer'] ?? -99);
        if ($captchaInput !== $captchaExpect) {
            $errors[] = 'Incorrect answer to the security question. Please try again.';
        }
        generateCaptcha(); // always regenerate after each attempt
    }

    /* ── Validation ── */
    if ($loginName === '') $errors[] = 'Username is required.';
    elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $loginName))
        $errors[] = 'Username: 3–50 chars, letters/digits/_ . - only.';
    if ($fstName === '') $errors[] = 'First name is required.';
    if ($lstName === '') $errors[] = 'Last name is required.';
    if ($email === '') $errors[] = 'Email address is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if ($plainPwd === '') $errors[] = 'Password is required.';
    elseif (strlen($plainPwd) < 8) $errors[] = 'Password must be at least 8 characters.';
    elseif ($plainPwd !== $confirm) $errors[] = 'Passwords do not match.';
    /* Mobile: validate length based on country code */
    if ($mobileRaw !== '') {
        // Rules: [dialCode => [min, max, optional leading-digit regex or null]]
        $mobileRules = [
            '+91'  => [10, 10, '/^[6-9]/'],   // India
            '+1'   => [10, 10, null],           // USA / Canada
            '+44'  => [10, 10, null],           // UK
            '+61'  => [9,  9,  null],           // Australia
            '+64'  => [8,  9,  null],           // New Zealand
            '+971' => [9,  9,  null],           // UAE
            '+966' => [9,  9,  '/^[5]/'],       // Saudi Arabia
            '+65'  => [8,  8,  '/^[689]/'],     // Singapore
            '+60'  => [9,  10, '/^[1]/'],       // Malaysia
            '+94'  => [9,  9,  '/^[7]/'],       // Sri Lanka
            '+92'  => [10, 10, '/^[3]/'],       // Pakistan
            '+880' => [10, 10, '/^[1]/'],       // Bangladesh
            '+977' => [10, 10, '/^[9]/'],       // Nepal
            '+81'  => [10, 11, null],           // Japan
            '+82'  => [9,  10, null],           // South Korea
            '+86'  => [11, 11, null],           // China
            '+852' => [8,  8,  null],           // Hong Kong
            '+49'  => [10, 12, null],           // Germany
            '+33'  => [9,  9,  null],           // France
            '+39'  => [9,  10, null],           // Italy
            '+34'  => [9,  9,  null],           // Spain
            '+31'  => [9,  9,  null],           // Netherlands
            '+46'  => [9,  9,  null],           // Sweden
            '+47'  => [8,  8,  null],           // Norway
            '+45'  => [8,  8,  null],           // Denmark
            '+41'  => [9,  9,  null],           // Switzerland
            '+7'   => [10, 10, null],           // Russia
            '+55'  => [10, 11, null],           // Brazil
            '+52'  => [10, 10, null],           // Mexico
            '+54'  => [10, 10, null],           // Argentina
            '+27'  => [9,  9,  null],           // South Africa
            '+234' => [10, 10, '/^[07-9]/'],    // Nigeria
            '+254' => [9,  9,  '/^[7]/'],       // Kenya
            '+20'  => [10, 10, null],           // Egypt
            '+212' => [9,  9,  null],           // Morocco
        ];

        $digits = strlen($mobileRaw);
        if (!ctype_digit($mobileRaw)) {
            $errors[] = 'Mobile number must contain digits only (no spaces or dashes).';
        } elseif (isset($mobileRules[$countryCode])) {
            [$min, $max, $leadPat] = $mobileRules[$countryCode];
            if ($digits < $min || $digits > $max) {
                $errors[] = "Mobile number for {$countryCode} must be "
                          . ($min === $max ? "{$min}" : "{$min}–{$max}")
                          . " digits (you entered {$digits}).";
            } elseif ($leadPat && !preg_match($leadPat, $mobileRaw)) {
                $errors[] = "Mobile number for {$countryCode} appears invalid (check starting digit).";
            }
        } else {
            // Unknown country code — basic length check
            if ($digits < 6 || $digits > 15) {
                $errors[] = 'Mobile number must be between 6 and 15 digits.';
            }
        }
    }
    if ($accountType === 'teacher' && $qualification === '')
        $errors[] = 'Qualification is required for teacher registration.';
    if ($bloodGroup !== '' && !in_array($bloodGroup, ['A+','A-','B+','B-','AB+','AB-','O+','O-'], true))
        $errors[] = 'Invalid blood group selected.';

    if ($errors) {
        $msg = implode(' ', $errors); $isErr = true;
    } else {
        /* Check duplicate username / email */
        $existing = Database::fetchOne(
            "SELECT LoginInfoId FROM logininfo WHERE LoginName = ? LIMIT 1", [$loginName]);
        if ($existing) { $msg = 'That username is already taken.'; $isErr = true; }
        else {
            $emailUsed = Database::fetchOne(
                "SELECT LoginInfoId FROM logininfo WHERE Email = ? LIMIT 1", [$email]);
            if (!$emailUsed) {
                try {
                    $emailUsed = Database::fetchOne(
                        "SELECT UserInfoId FROM userinfo WHERE EMail = ? LIMIT 1", [$email]);
                } catch (Exception $e) {}
            }
            if ($emailUsed) { $msg = 'An account with that email already exists.'; $isErr = true; }
        }
    }

    if (!$isErr && !$errors) {
        $hash = password_hash($plainPwd, PASSWORD_DEFAULT);

        try {
            Database::beginTransaction();

            if ($accountType === 'student') {
                /* ── Student: pending admin approval (migration_v60) ──
                   Inserted inactive (Active='N') — Auth::verifyCredentials()
                   already refuses login for any non-'Y' account, so this
                   alone is what blocks sign-in until an admin approves via
                   Admin/StudentApprovals.php. The RegistrationStatus='Pending'
                   flip happens in a separate guarded UPDATE right after this
                   insert cascade succeeds (see below), same layering pattern
                   used for RegisteredIp/InstituteId here — keeps this already
                   tricky 3-tier fallback from needing a 4th variant. */
                // Try with InstituteId (migration_v16+), fall back without it
                try {
                    Database::execute(
                        "INSERT INTO logininfo (LoginName, Password, Role, Email, Active, InstituteId, RegisteredIp)
                         VALUES (?, ?, 'STDNT', ?, 'N', ?, ?)",
                        [$loginName, $hash, $email, $instituteId, $_clientIp]);
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'Unknown column') !== false) {
                        // Fallback: try without RegisteredIp (migration_v23 not run yet)
                        try {
                            Database::execute(
                                "INSERT INTO logininfo (LoginName, Password, Role, Email, Active, InstituteId)
                                 VALUES (?, ?, 'STDNT', ?, 'N', ?)",
                                [$loginName, $hash, $email, $instituteId]);
                        } catch (Exception $e2) {
                            if (strpos($e2->getMessage(), 'InstituteId') !== false || strpos($e2->getMessage(), 'Unknown column') !== false) {
                                Database::execute(
                                    "INSERT INTO logininfo (LoginName, Password, Role, Email, Active)
                                     VALUES (?, ?, 'STDNT', ?, 'N')",
                                    [$loginName, $hash, $email]);
                            } else { throw $e2; }
                        }
                    } else { throw $e; }
                }

                // Registration status label (migration_v60) — guarded, best-
                // effort. If the column doesn't exist yet, the account is
                // still safely blocked from login by Active='N' above; it
                // just won't show up distinctly in Admin/StudentApprovals.php
                // until the migration is run.
                if (Database::hasColumn('logininfo', 'RegistrationStatus')) {
                    try {
                        Database::execute(
                            "UPDATE logininfo SET RegistrationStatus = 'Pending' WHERE LoginName = ?",
                            [$loginName]);
                    } catch (Exception $e) {}
                }

                // Column availability checked once via information_schema (memoized —
                // see Database::hasColumn()) rather than the try/catch-on-"Unknown
                // column" cascade this used to be: same graceful degradation on an
                // install that hasn't run migration_v16/v39/v61 yet, without a new
                // nested branch every time an optional userinfo column is added.
                $hasUiInstituteCol  = Database::hasColumn('userinfo', 'InstituteId');
                $hasUiInstStudentId = Database::hasColumn('userinfo', 'InstituteStudentId');
                $hasUiBloodCols     = Database::hasColumn('userinfo', 'BloodGroup')
                                   && Database::hasColumn('userinfo', 'WillingToDonateBlood');

                $uiCols = "LoginName, FstName, MiddleName, LstName, Gender, EMail, Mobile, Address, ImageLoc, Note";
                $uiPhs  = "?, ?, ?, ?, ?, ?, ?, '', '', ''";
                $uiVals = [$loginName, $fstName, $midName, $lstName, $gender, $email, $mobile];
                if ($hasUiInstituteCol)  { $uiCols .= ", InstituteId";        $uiPhs .= ", ?"; $uiVals[] = $instituteId; }
                if ($hasUiInstStudentId) { $uiCols .= ", InstituteStudentId"; $uiPhs .= ", ?"; $uiVals[] = $instituteStudentId !== '' ? $instituteStudentId : null; }
                if ($hasUiBloodCols)     { $uiCols .= ", BloodGroup, WillingToDonateBlood"; $uiPhs .= ", ?, ?"; $uiVals[] = $bloodGroupParam; $uiVals[] = $willDonate; }

                Database::execute("INSERT INTO userinfo ($uiCols) VALUES ($uiPhs)", $uiVals);

                Database::commit();
                logIpRegistration($_clientIp);
                $_SESSION['reg_success'] = 'Account created! Your registration is pending admin approval — you will be able to sign in once it is approved.';
                header('Location: login.php');
                exit;

            } else {
                /* ── Teacher: inactive until admin approves ── */
                try {
                    Database::execute(
                        "INSERT INTO logininfo (LoginName, Password, Role, Email, Active, RegisteredIp)
                         VALUES (?, ?, 'TEACH', ?, 'N', ?)",
                        [$loginName, $hash, $email, $_clientIp]);
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'Unknown column') !== false) {
                        Database::execute(
                            "INSERT INTO logininfo (LoginName, Password, Role, Email, Active)
                             VALUES (?, ?, 'TEACH', ?, 'N')",
                            [$loginName, $hash, $email]);
                    } else { throw $e; }
                }
                try {
                    Database::execute(
                        "INSERT INTO userinfo (LoginName, FstName, MiddleName, LstName, Gender, EMail, Mobile, Address, ImageLoc, Note, BloodGroup, WillingToDonateBlood)
                         VALUES (?, ?, ?, ?, ?, ?, ?, '', ?, '', ?, ?)",
                        [$loginName, $fstName, $midName, $lstName, $gender, $email, $mobile, $photoPath ?: '', $bloodGroupParam, $willDonate]);
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'Unknown column') !== false) {
                        // migration_v39 not yet run — retry without the new blood-donor fields
                        Database::execute(
                            "INSERT INTO userinfo (LoginName, FstName, MiddleName, LstName, Gender, EMail, Mobile, Address, ImageLoc, Note)
                             VALUES (?, ?, ?, ?, ?, ?, ?, '', ?, '')",
                            [$loginName, $fstName, $midName, $lstName, $gender, $email, $mobile, $photoPath ?: '']);
                    } else {
                        throw $e;
                    }
                }

                /* Create teacher_profiles row (migration_v17+ only) */
                try {
                    $userRow = Database::fetchOne(
                        "SELECT UserInfoId FROM userinfo WHERE LoginName = ? LIMIT 1", [$loginName]);
                    if ($userRow) {
                        Database::execute(
                            "INSERT INTO teacher_profiles
                                (UserInfoId, LoginName, Qualification, Experience, Bio, ProfilePhoto, OffersOnline, Active)
                             VALUES (?, ?, ?, ?, ?, ?, 0, 'N')",
                            [(int)$userRow['UserInfoId'], $loginName, $qualification, $experience, $bio, $photoPath ?: null]);
                    }
                } catch (Exception $e) {
                    /* teacher_profiles table may not exist yet — safe to skip */
                    error_log('register.php teacher_profiles insert: ' . $e->getMessage());
                }

                Database::commit();
                logIpRegistration($_clientIp);
                $_SESSION['reg_success'] = 'Teacher application received! Your account is pending admin approval — you will be notified once activated.';
                header('Location: login.php');
                exit;
            }

        } catch (Exception $e) {
            Database::rollBack();
            error_log('register.php DB error: ' . $e->getMessage());
            $msg   = 'Registration failed due to a database error. Please try again or contact support.';
            $isErr = true;
        }
    }
}

$postType = $_POST['accountType'] ?? 'student';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register &mdash; <?php echo APP_NAME; ?></title>
  <link rel="icon" type="image/svg+xml" href="../assets/logo.svg">
  <link rel="icon" type="image/png" href="../assets/logo.png">
  <link rel="apple-touch-icon" href="../assets/logo.png">
  <link rel="stylesheet" href="../<?php echo asset_version('assets/style.css'); ?>">
  <style>
    .type-toggle{display:flex;gap:0;border:2px solid #0c4a6e;border-radius:8px;overflow:hidden;margin-bottom:20px;}
    .type-btn{flex:1;padding:12px;text-align:center;cursor:pointer;font-weight:700;font-size:.95rem;
              background:#fff;color:#0c4a6e;border:none;transition:background .2s,color .2s;}
    .type-btn.active{background:#0c4a6e;color:#fff;}
    .teacher-fields{display:none;}
    .teacher-notice{background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;
                    padding:10px 14px;font-size:.83rem;color:#92400e;margin-bottom:14px;}

    /* ── Phone input combo ─────────────────────────────────── */
    .phone-input-wrap{display:flex;border:1px solid #cbd5e0;border-radius:6px;overflow:hidden;
                      background:#fff;transition:border-color .2s,box-shadow .2s;}
    .phone-input-wrap:focus-within{border-color:#0369a1;box-shadow:0 0 0 3px rgba(3,105,161,.15);}
    .phone-input-wrap.error{border-color:#e53e3e;box-shadow:0 0 0 3px rgba(229,62,62,.12);}
    .phone-input-wrap.ok   {border-color:#38a169;box-shadow:0 0 0 3px rgba(56,161,105,.12);}

    .phone-cc-select{
      flex-shrink:0; border:none; border-right:1px solid #e2e8f0;
      padding:0 10px; height:42px; font-size:.84rem;
      background:#f8fafc; color:#1e293b; cursor:pointer; outline:none;
      max-width:190px; min-width:130px;
    }
    .phone-cc-select:focus{background:#f0f9ff;}

    .phone-num-input{
      flex:1; border:none; padding:0 12px; height:42px;
      font-size:.9rem; outline:none; background:transparent;
      min-width:0;
    }

    .mobile-hint {display:block;margin-top:4px;font-size:.78rem;color:#718096;}
    .mobile-error{display:none;margin-top:4px;font-size:.78rem;color:#e53e3e;font-weight:600;}
    .mobile-error.visible{display:block;}
  </style>
</head>
<body>
<div class="auth-wrap" style="max-width:540px;">
  <div class="auth-header">
    <div class="auth-logo">
      <img src="../assets/logo.png" alt="Logo"
           onerror="this.style.display:'none';this.nextElementSibling.style.display='flex';">
      <div class="auth-logo-placeholder" style="display:none;">YOUR<br>LOGO</div>
    </div>
    <h1>Create Account</h1>
    <p>Join <?php echo APP_NAME; ?></p>
  </div>

  <div class="auth-body">
    <?php if ($msg !== ''): ?>
      <div class="alert <?php echo $isErr ? 'alert-error' : 'alert-success'; ?>">
        <?php echo htmlspecialchars($msg); ?>
        <?php if (!$isErr && $postType === 'student'): ?>
          <a href="login.php" style="font-weight:700;margin-left:8px;">Sign In &rarr;</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Account type toggle -->
    <div class="type-toggle" id="typeToggle">
      <button type="button" class="type-btn <?php echo $postType!=='teacher'?'active':''; ?>"
              onclick="setType('student')">&#127891; I am a Student</button>
      <button type="button" class="type-btn <?php echo $postType==='teacher'?'active':''; ?>"
              onclick="setType('teacher')">&#128101; I am a Teacher</button>
    </div>

    <form method="post" action="" id="regForm" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token"   value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <input type="hidden" name="accountType"  value="<?php echo $postType==='teacher'?'teacher':'student'; ?>" id="accountTypeField">

      <!-- Common fields -->
      <div class="form-group">
        <label for="txtLoginID">Username <span style="color:#e53e3e">*</span></label>
        <input type="text" id="txtLoginID" name="txtLoginID" class="form-control" required
               pattern="[A-Za-z0-9_.\-]{3,50}" title="3-50 chars: letters, digits, _ . -"
               value="<?php echo htmlspecialchars($_POST['txtLoginID'] ?? ''); ?>"
               oninput="checkUsername(this.value)">
        <small id="unameMsg" style="font-size:.8rem"></small>
      </div>

      <div style="display:flex;gap:12px;">
        <div class="form-group" style="flex:1">
          <label for="txtPwd">Password <span style="color:#e53e3e">*</span></label>
          <input type="password" id="txtPwd" name="txtPwd" class="form-control" required minlength="8"
                 oninput="checkStrength(this.value)">
          <div id="strengthBar" style="height:4px;border-radius:2px;background:#e2e8f0;margin-top:4px;display:none;">
            <div id="strengthFill" style="height:100%;border-radius:2px;width:0;background:#e53e3e;transition:width .3s"></div>
          </div>
          <small id="strengthLabel" style="color:#718096;font-size:.78rem"></small>
        </div>
        <div class="form-group" style="flex:1">
          <label for="txtPwdConfirm">Confirm Password <span style="color:#e53e3e">*</span></label>
          <input type="password" id="txtPwdConfirm" name="txtPwdConfirm" class="form-control" required
                 oninput="checkMatch()">
          <small id="matchLabel" style="color:#e53e3e;font-size:.78rem"></small>
        </div>
      </div>

      <div style="display:flex;gap:12px;">
        <div class="form-group" style="flex:1">
          <label for="txtFstName">First Name <span style="color:#e53e3e">*</span></label>
          <input type="text" id="txtFstName" name="txtFstName" class="form-control" required
                 value="<?php echo htmlspecialchars($_POST['txtFstName'] ?? ''); ?>">
        </div>
        <div class="form-group" style="flex:1">
          <label for="txtMidName">Middle Name
            <small style="color:#6b7280;font-weight:400;">(optional)</small>
          </label>
          <input type="text" id="txtMidName" name="txtMidName" class="form-control"
                 value="<?php echo htmlspecialchars($_POST['txtMidName'] ?? ''); ?>">
        </div>
        <div class="form-group" style="flex:1">
          <label for="txtLstName">Last Name <span style="color:#e53e3e">*</span></label>
          <input type="text" id="txtLstName" name="txtLstName" class="form-control" required
                 value="<?php echo htmlspecialchars($_POST['txtLstName'] ?? ''); ?>">
        </div>
      </div>
      <small style="display:block;margin-top:-10px;margin-bottom:12px;color:#6b7280;font-size:.78rem;">
        Some states/institutes require First, Middle and Last Name together — add a Middle Name here if yours does.
      </small>

      <div class="form-group">
        <label for="txtEmail">Email Address <span style="color:#e53e3e">*</span></label>
        <input type="email" id="txtEmail" name="txtEmail" class="form-control" required
               value="<?php echo htmlspecialchars($_POST['txtEmail'] ?? ''); ?>">
      </div>

      <!-- ── Mobile number with country code ────────────────── -->
      <div class="form-group">
        <label for="txtMobileNum">Mobile Number</label>
        <div class="phone-input-wrap" id="phoneInputWrap">
          <select id="txtCountryCode" name="txtCountryCode" class="phone-cc-select"
                  onchange="onCountryChange(this.value)" aria-label="Country code">
            <?php
            $selectedCC = $_POST['txtCountryCode'] ?? '+91';
            $ccList = [
              ['+91','IN','🇮🇳','India'],           ['+1','US','🇺🇸','USA / Canada'],
              ['+44','GB','🇬🇧','UK'],               ['+61','AU','🇦🇺','Australia'],
              ['+64','NZ','🇳🇿','New Zealand'],      ['+971','AE','🇦🇪','UAE'],
              ['+966','SA','🇸🇦','Saudi Arabia'],    ['+65','SG','🇸🇬','Singapore'],
              ['+60','MY','🇲🇾','Malaysia'],         ['+94','LK','🇱🇰','Sri Lanka'],
              ['+92','PK','🇵🇰','Pakistan'],         ['+880','BD','🇧🇩','Bangladesh'],
              ['+977','NP','🇳🇵','Nepal'],           ['+81','JP','🇯🇵','Japan'],
              ['+82','KR','🇰🇷','South Korea'],      ['+86','CN','🇨🇳','China'],
              ['+852','HK','🇭🇰','Hong Kong'],       ['+49','DE','🇩🇪','Germany'],
              ['+33','FR','🇫🇷','France'],           ['+39','IT','🇮🇹','Italy'],
              ['+34','ES','🇪🇸','Spain'],            ['+31','NL','🇳🇱','Netherlands'],
              ['+46','SE','🇸🇪','Sweden'],           ['+47','NO','🇳🇴','Norway'],
              ['+45','DK','🇩🇰','Denmark'],          ['+41','CH','🇨🇭','Switzerland'],
              ['+7','RU','🇷🇺','Russia'],            ['+55','BR','🇧🇷','Brazil'],
              ['+52','MX','🇲🇽','Mexico'],           ['+54','AR','🇦🇷','Argentina'],
              ['+27','ZA','🇿🇦','South Africa'],     ['+234','NG','🇳🇬','Nigeria'],
              ['+254','KE','🇰🇪','Kenya'],           ['+20','EG','🇪🇬','Egypt'],
              ['+212','MA','🇲🇦','Morocco'],
            ];
            foreach ($ccList as [$code, $iso, $flag, $name]):
            ?>
              <option value="<?php echo $code; ?>" <?php echo $selectedCC===$code?'selected':''; ?>>
                <?php echo $flag . ' ' . $code . ' ' . $name; ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="tel" id="txtMobileNum" name="txtMobileNum"
                 class="phone-num-input" inputmode="numeric"
                 placeholder="Mobile number"
                 autocomplete="tel-national"
                 value="<?php echo htmlspecialchars(preg_replace('/\D/','',$_POST['txtMobileNum'] ?? '')); ?>"
                 oninput="validateMobile()" aria-describedby="mobileHint mobileError">
        </div>
        <small id="mobileHint" class="mobile-hint"></small>
        <small id="mobileError" class="mobile-error" role="alert" aria-live="polite"></small>
      </div>

      <div class="form-group">
        <label>Gender</label>
        <label style="font-weight:normal;margin-right:16px;">
          <input type="radio" name="rdoGender" value="M"
                 <?php echo (($_POST['rdoGender']??'')==='M')?'checked':''; ?>> Male
        </label>
        <label style="font-weight:normal;">
          <input type="radio" name="rdoGender" value="F"
                 <?php echo (($_POST['rdoGender']??'')==='F')?'checked':''; ?>> Female
        </label>
      </div>

      <div class="form-group">
        <label for="selBloodGroup">Blood Group
          <small style="color:#6b7280;font-weight:400;">(optional)</small>
        </label>
        <?php $selBlood = $_POST['selBloodGroup'] ?? ''; ?>
        <select id="selBloodGroup" name="selBloodGroup" class="form-control">
          <option value="">— Not Sure / Skip —</option>
          <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
            <option value="<?php echo $bg; ?>" <?php echo $selBlood===$bg?'selected':''; ?>><?php echo $bg; ?></option>
          <?php endforeach; ?>
        </select>
        <label style="font-weight:normal;display:block;margin-top:8px;">
          <input type="checkbox" name="chkWillingDonate" value="Y"
                 <?php echo (($_POST['chkWillingDonate']??'')==='Y')?'checked':''; ?>>
          I'm willing to donate blood if contacted in an emergency
        </label>
      </div>

      <!-- ── STUDENT-ONLY: Institute ─────────────────────────────────── -->
      <div id="studentFields">
        <div class="teacher-notice" style="display:block;">
          &#9888; New student accounts require admin approval before you can sign in.
          You will be able to log in once your registration is approved.
        </div>
        <div class="form-group">
          <label for="txtInstituteId">Institute / School / College
            <small style="color:#6b7280;font-weight:400;">(optional)</small>
          </label>
          <select id="txtInstituteId" name="txtInstituteId" class="form-control">
            <option value="">— Independent / Not Listed —</option>
            <?php $selInst = (int)($_POST['txtInstituteId'] ?? 0);
            foreach ($institutes as $inst): ?>
              <option value="<?php echo $inst['InstituteId']; ?>"
                <?php echo $selInst===(int)$inst['InstituteId']?'selected':''; ?>>
                <?php echo htmlspecialchars($inst['InstituteName']); ?>
                <?php if (!empty($inst['InstituteType']) || !empty($inst['State'])): ?>
                (<?php echo htmlspecialchars(implode(', ', array_filter([$inst['InstituteType'] ?? '', $inst['State'] ?? '']))); ?>)
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($institutes)): ?>
          <small style="color:#9ca3af;font-size:.78rem;">
            No institutes registered yet — select when available, or leave as Independent.
          </small>
          <?php else: ?>
          <small style="color:#6b7280;font-size:.78rem;">
            Selecting your institute may unlock special exam fees or free access.
          </small>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label for="txtInstituteStudentId">Institute Student ID / Roll No.
            <small style="color:#6b7280;font-weight:400;">(optional)</small>
          </label>
          <input type="text" id="txtInstituteStudentId" name="txtInstituteStudentId" class="form-control"
                 maxlength="50" placeholder="e.g. your roll number or admission ID"
                 value="<?php echo htmlspecialchars($_POST['txtInstituteStudentId'] ?? ''); ?>">
          <small style="color:#6b7280;font-size:.78rem;">
            If your institute assigned you a student/roll number, enter it here so they can match your
            results to their own records. Leave blank if you don't have one.
          </small>
        </div>
      </div>

      <!-- ── TEACHER-ONLY fields ──────────────────────────────────────── -->
      <div id="teacherFields" class="teacher-fields">
        <div class="teacher-notice">
          &#9888; Teacher accounts require admin approval before you can sign in.
          You will be notified once your account is activated.
        </div>

        <div style="display:flex;gap:12px;">
          <div class="form-group" style="flex:1">
            <label for="txtQualification">Qualification <span style="color:#e53e3e">*</span></label>
            <input type="text" id="txtQualification" name="txtQualification" class="form-control"
                   placeholder="e.g. M.Tech, MBA, B.Ed"
                   value="<?php echo htmlspecialchars($_POST['txtQualification'] ?? ''); ?>">
          </div>
          <div class="form-group" style="flex:1">
            <label for="txtExperience">Teaching Experience</label>
            <input type="text" id="txtExperience" name="txtExperience" class="form-control"
                   placeholder="e.g. 5 years"
                   value="<?php echo htmlspecialchars($_POST['txtExperience'] ?? ''); ?>">
          </div>
        </div>

        <div class="form-group">
          <label for="txtBio">About / Bio</label>
          <textarea id="txtBio" name="txtBio" class="form-control" rows="3"
                    placeholder="Brief introduction — subjects you teach, specialisation, etc."><?php
            echo htmlspecialchars($_POST['txtBio'] ?? '');
          ?></textarea>
          <small style="color:#6b7280;font-size:.78rem;">
            This will be shown to students on the Teacher Courses page.
          </small>
        </div>

        <!-- Passport photo — mandatory for teachers -->
        <div class="form-group">
          <label>Passport Size Photo <span style="color:#e53e3e">*</span></label>
          <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">
            <!-- Preview circle -->
            <div id="photoPreviewWrap" style="flex-shrink:0;">
              <div id="photoPlaceholder"
                   style="width:100px;height:120px;border:2px dashed #fdba74;border-radius:8px;
                          background:#fff7ed;display:flex;flex-direction:column;align-items:center;
                          justify-content:center;color:#c2410c;font-size:.75rem;text-align:center;gap:4px;">
                <span style="font-size:2rem;">&#128247;</span>
                Preview
              </div>
              <img id="photoPreview" src="" alt="Photo preview"
                   style="display:none;width:100px;height:120px;object-fit:cover;
                          border-radius:8px;border:2px solid #d97706;">
            </div>
            <!-- Upload area -->
            <div style="flex:1;min-width:200px;">
              <div id="photoDropZone"
                   onclick="document.getElementById('teacherPhoto').click()"
                   style="border:2px dashed #fdba74;border-radius:8px;padding:18px;
                          text-align:center;cursor:pointer;background:#fff7ed;
                          transition:background .2s;">
                <div style="font-size:1.5rem;">&#128443;</div>
                <div style="font-size:.85rem;font-weight:600;color:#c2410c;margin:4px 0;">
                  Click to upload photo
                </div>
                <div style="font-size:.75rem;color:#7c3a20;">
                  JPG, PNG or WebP &nbsp;|&nbsp; Max 2 MB<br>
                  Passport size (white background preferred)
                </div>
              </div>
              <input type="file" id="teacherPhoto" name="teacherPhoto"
                     accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
                     style="display:none;" onchange="previewPhoto(this)">
              <div id="photoError" style="color:#dc2626;font-size:.78rem;margin-top:4px;display:none;"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Security check (CAPTCHA) ──────────────────────────────────── -->
      <div class="form-group" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:12px 14px;margin-top:8px;">
        <label for="captcha_answer" style="font-weight:700;color:#065f46;">
          &#128274; Security Check
        </label>
        <div style="display:flex;align-items:center;gap:12px;margin-top:6px;">
          <span style="font-size:1rem;font-weight:600;color:#1e293b;white-space:nowrap;">
            What is
            <strong><?php echo (int)$_SESSION['captcha_a']; ?></strong>
            +
            <strong><?php echo (int)$_SESSION['captcha_b']; ?></strong>
            ?
          </span>
          <input type="number" id="captcha_answer" name="captcha_answer"
                 class="form-control" required min="0" max="99"
                 inputmode="numeric" autocomplete="off"
                 style="width:90px;text-align:center;font-size:1rem;"
                 placeholder="?">
        </div>
        <small style="color:#6b7280;font-size:.78rem;display:block;margin-top:4px;">
          Answer this simple question to confirm you are not a bot.
        </small>
      </div>

      <button type="submit" name="btnRegister" class="btn btn-success"
              style="width:100%;margin-top:8px;" id="submitBtn">
        Create Student Account
      </button>
    </form>

    <div style="text-align:center;margin-top:14px;font-size:.85rem;">
      Already have an account? <a href="login.php">Sign In</a>
    </div>
  </div>
</div>

<script>
var currentType = '<?php echo $postType==='teacher'?'teacher':'student'; ?>';

function setType(type) {
  currentType = type;
  document.getElementById('accountTypeField').value = type;

  var teacherFields = document.getElementById('teacherFields');
  var studentFields = document.getElementById('studentFields');
  var submitBtn     = document.getElementById('submitBtn');
  var btns          = document.querySelectorAll('.type-btn');

  btns[0].classList.toggle('active', type === 'student');
  btns[1].classList.toggle('active', type === 'teacher');

  if (type === 'teacher') {
    teacherFields.style.display = 'block';
    studentFields.style.display = 'none';
    submitBtn.textContent = 'Register as Teacher';
    submitBtn.style.background = '#0891b2';
  } else {
    teacherFields.style.display = 'none';
    studentFields.style.display = 'block';
    submitBtn.textContent = 'Create Student Account';
    submitBtn.style.background = '';
  }
}

/* Photo preview */
function previewPhoto(input) {
  var errEl  = document.getElementById('photoError');
  var imgEl  = document.getElementById('photoPreview');
  var phEl   = document.getElementById('photoPlaceholder');
  var zone   = document.getElementById('photoDropZone');
  errEl.style.display = 'none';

  if (!input.files || !input.files[0]) return;
  var file = input.files[0];

  /* Client-side size check */
  if (file.size > 2 * 1024 * 1024) {
    errEl.textContent = 'Photo must be smaller than 2 MB.';
    errEl.style.display = 'block';
    input.value = '';
    return;
  }
  /* Client-side type check */
  if (!file.type.match(/^image\/(jpeg|png|webp)$/)) {
    errEl.textContent = 'Only JPG, PNG or WebP images are accepted.';
    errEl.style.display = 'block';
    input.value = '';
    return;
  }

  var reader = new FileReader();
  reader.onload = function(e) {
    imgEl.src = e.target.result;
    imgEl.style.display  = 'block';
    phEl.style.display   = 'none';
    zone.style.background = '#d1fae5';
    zone.style.borderColor = '#059669';
    zone.innerHTML = '<div style="font-size:1.5rem;">&#10004;</div>'
                   + '<div style="font-size:.82rem;font-weight:600;color:#065f46;">'
                   + file.name + '</div>';
  };
  reader.readAsDataURL(file);
}

/* Drag-and-drop on photo zone */
(function() {
  var zone = document.getElementById('photoDropZone');
  if (!zone) return;
  zone.addEventListener('dragover', function(e) {
    e.preventDefault(); zone.style.background = '#fef3c7';
  });
  zone.addEventListener('dragleave', function() {
    zone.style.background = '#fff7ed';
  });
  zone.addEventListener('drop', function(e) {
    e.preventDefault();
    var fi = document.getElementById('teacherPhoto');
    if (e.dataTransfer.files.length) {
      // DataTransfer to input
      try {
        var dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        fi.files = dt.files;
        previewPhoto(fi);
      } catch(err) {}
    }
  });
})();

/* Form submit: validate photo (teacher) + mobile number */
document.getElementById('regForm').addEventListener('submit', function(e) {
  // Teacher photo check
  if (currentType === 'teacher') {
    var fi  = document.getElementById('teacherPhoto');
    var err = document.getElementById('photoError');
    if (!fi.files || fi.files.length === 0) {
      err.textContent   = 'Passport size photo is required.';
      err.style.display = 'block';
      fi.scrollIntoView({ behavior: 'smooth', block: 'center' });
      e.preventDefault();
      return false;
    }
  }

  // Mobile number check (only if filled in)
  var numEl  = document.getElementById('txtMobileNum');
  var ccEl   = document.getElementById('txtCountryCode');
  var errEl  = document.getElementById('mobileError');
  var wrap   = document.getElementById('phoneInputWrap');
  var digits = numEl.value.replace(/\D/g, '');

  if (digits.length > 0) {
    var cc   = ccEl.value;
    var rule = MOBILE_RULES[cc];
    var bad  = false;
    var msg  = '';

    if (rule) {
      if (digits.length < rule.min || digits.length > rule.max) {
        bad = true;
        msg = 'Mobile number for ' + cc + ' must be '
            + (rule.min===rule.max ? rule.min : rule.min+'–'+rule.max)
            + ' digits.';
      } else if (rule.lead && !rule.lead.test(digits)) {
        bad = true;
        msg = 'Mobile number for ' + cc + ' appears invalid (check starting digit).';
      }
    } else if (digits.length < 6 || digits.length > 15) {
      bad = true;
      msg = 'Mobile number must be 6–15 digits.';
    }

    if (bad) {
      setMobileError(wrap, errEl, msg);
      numEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
      e.preventDefault();
      return false;
    }
  }
});

/* ── Mobile / Country-code validation ──────────────────────────── */

// Rules: dialCode → { min, max, lead (regex string | null), hint }
var MOBILE_RULES = {
  '+91' : {min:10,max:10,lead:/^[6-9]/,   hint:'10 digits, starts with 6–9 (India)'},
  '+1'  : {min:10,max:10,lead:null,        hint:'10 digits (USA / Canada)'},
  '+44' : {min:10,max:10,lead:null,        hint:'10 digits (UK)'},
  '+61' : {min:9, max:9, lead:null,        hint:'9 digits (Australia)'},
  '+64' : {min:8, max:9, lead:null,        hint:'8–9 digits (New Zealand)'},
  '+971': {min:9, max:9, lead:null,        hint:'9 digits (UAE)'},
  '+966': {min:9, max:9, lead:/^[5]/,      hint:'9 digits, starts with 5 (Saudi Arabia)'},
  '+65' : {min:8, max:8, lead:/^[689]/,    hint:'8 digits, starts with 6/8/9 (Singapore)'},
  '+60' : {min:9, max:10,lead:/^[1]/,      hint:'9–10 digits, starts with 1 (Malaysia)'},
  '+94' : {min:9, max:9, lead:/^[7]/,      hint:'9 digits, starts with 7 (Sri Lanka)'},
  '+92' : {min:10,max:10,lead:/^[3]/,      hint:'10 digits, starts with 3 (Pakistan)'},
  '+880': {min:10,max:10,lead:/^[1]/,      hint:'10 digits, starts with 1 (Bangladesh)'},
  '+977': {min:10,max:10,lead:/^[9]/,      hint:'10 digits, starts with 9 (Nepal)'},
  '+81' : {min:10,max:11,lead:null,        hint:'10–11 digits (Japan)'},
  '+82' : {min:9, max:10,lead:null,        hint:'9–10 digits (South Korea)'},
  '+86' : {min:11,max:11,lead:null,        hint:'11 digits (China)'},
  '+852': {min:8, max:8, lead:null,        hint:'8 digits (Hong Kong)'},
  '+49' : {min:10,max:12,lead:null,        hint:'10–12 digits (Germany)'},
  '+33' : {min:9, max:9, lead:null,        hint:'9 digits (France)'},
  '+39' : {min:9, max:10,lead:null,        hint:'9–10 digits (Italy)'},
  '+34' : {min:9, max:9, lead:null,        hint:'9 digits (Spain)'},
  '+31' : {min:9, max:9, lead:null,        hint:'9 digits (Netherlands)'},
  '+46' : {min:9, max:9, lead:null,        hint:'9 digits (Sweden)'},
  '+47' : {min:8, max:8, lead:null,        hint:'8 digits (Norway)'},
  '+45' : {min:8, max:8, lead:null,        hint:'8 digits (Denmark)'},
  '+41' : {min:9, max:9, lead:null,        hint:'9 digits (Switzerland)'},
  '+7'  : {min:10,max:10,lead:null,        hint:'10 digits (Russia)'},
  '+55' : {min:10,max:11,lead:null,        hint:'10–11 digits (Brazil)'},
  '+52' : {min:10,max:10,lead:null,        hint:'10 digits (Mexico)'},
  '+54' : {min:10,max:10,lead:null,        hint:'10 digits (Argentina)'},
  '+27' : {min:9, max:9, lead:null,        hint:'9 digits (South Africa)'},
  '+234': {min:10,max:10,lead:/^[07-9]/,   hint:'10 digits (Nigeria)'},
  '+254': {min:9, max:9, lead:/^[7]/,      hint:'9 digits, starts with 7 (Kenya)'},
  '+20' : {min:10,max:10,lead:null,        hint:'10 digits (Egypt)'},
  '+212': {min:9, max:9, lead:null,        hint:'9 digits (Morocco)'}
};

function onCountryChange(cc) {
  var hintEl = document.getElementById('mobileHint');
  var rule   = MOBILE_RULES[cc];
  if (rule) {
    hintEl.textContent = 'Format: ' + rule.hint;
  } else {
    hintEl.textContent = 'Enter 6–15 digit mobile number';
  }
  // Re-validate whatever is already typed
  validateMobile();
  // Update placeholder
  var input = document.getElementById('txtMobileNum');
  if (rule) {
    input.placeholder = rule.min === rule.max
      ? rule.min + ' digits'
      : rule.min + '–' + rule.max + ' digits';
    input.maxLength = rule.max;
  } else {
    input.placeholder = 'Mobile number';
    input.maxLength = 15;
  }
}

function validateMobile() {
  var ccEl    = document.getElementById('txtCountryCode');
  var numEl   = document.getElementById('txtMobileNum');
  var errEl   = document.getElementById('mobileError');
  var wrap    = document.getElementById('phoneInputWrap');
  var cc      = ccEl.value;
  var digits  = numEl.value.replace(/\D/g, '');

  // Strip non-digits from the input as the user types
  if (numEl.value !== digits) numEl.value = digits;

  wrap.classList.remove('ok','error');
  errEl.classList.remove('visible');
  errEl.textContent = '';

  if (digits.length === 0) return; // empty is fine (field is optional)

  var rule = MOBILE_RULES[cc];
  if (!rule) {
    if (digits.length < 6 || digits.length > 15) {
      setMobileError(wrap, errEl, 'Enter 6–15 digits for this country.');
    } else {
      wrap.classList.add('ok');
    }
    return;
  }

  if (digits.length < rule.min || digits.length > rule.max) {
    var expected = rule.min === rule.max ? rule.min + ' digits' : rule.min + '–' + rule.max + ' digits';
    setMobileError(wrap, errEl,
      'Expected ' + expected + ' for ' + cc + ' (entered ' + digits.length + ').');
    return;
  }

  if (rule.lead && !rule.lead.test(digits)) {
    setMobileError(wrap, errEl,
      'Number for ' + cc + ' should start with ' + rule.hint.match(/starts with (.+?) \(/)?.[1] + '.');
    return;
  }

  wrap.classList.add('ok');
  errEl.textContent = '✓ Valid';
  errEl.style.color = '#38a169';
  errEl.classList.add('visible');
}

function setMobileError(wrap, errEl, msg) {
  wrap.classList.add('error');
  errEl.style.color  = '#e53e3e';
  errEl.textContent  = msg;
  errEl.classList.add('visible');
}

/* Init on load */
setType(currentType);
// Set initial hint and placeholder from default country
onCountryChange(document.getElementById('txtCountryCode').value);

/* Username availability check */
var _timer;
function checkUsername(val) {
  var el = document.getElementById('unameMsg');
  clearTimeout(_timer);
  if (val.length < 3) { el.textContent=''; return; }
  el.textContent = 'Checking...'; el.style.color = '#718096';
  _timer = setTimeout(function() {
    fetch('check-username.php?u=' + encodeURIComponent(val))
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.taken) { el.textContent='Username taken'; el.style.color='#e53e3e'; }
        else         { el.textContent='Username available ✓'; el.style.color='#38a169'; }
      }).catch(function(){});
  }, 400);
}

/* Password strength */
function checkStrength(val) {
  var bar  = document.getElementById('strengthBar');
  var fill = document.getElementById('strengthFill');
  var lbl  = document.getElementById('strengthLabel');
  bar.style.display = 'block';
  var score = 0;
  if (val.length >= 8)          score++;
  if (val.length >= 12)         score++;
  if (/[A-Z]/.test(val))        score++;
  if (/[0-9]/.test(val))        score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  var colors = ['#e53e3e','#dd6b20','#d69e2e','#38a169','#276749'];
  var labels = ['Very Weak','Weak','Fair','Strong','Very Strong'];
  fill.style.width      = (score * 20) + '%';
  fill.style.background = colors[score-1] || '#e53e3e';
  lbl.textContent       = labels[score-1] || '';
  lbl.style.color       = colors[score-1] || '#e53e3e';
}

/* Password match */
function checkMatch() {
  var n   = document.getElementById('txtPwd').value;
  var c   = document.getElementById('txtPwdConfirm').value;
  var lbl = document.getElementById('matchLabel');
  lbl.textContent = (c && n !== c) ? 'Passwords do not match' : (n===c && c ? '✓ Match' : '');
  lbl.style.color = (n===c && c) ? '#38a169' : '#e53e3e';
}
</script>
<script src="../<?php echo asset_version('assets/password-toggle.js'); ?>"></script>
</body>
</html>
