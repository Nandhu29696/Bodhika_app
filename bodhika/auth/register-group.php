<?php
/**
 * auth/register-group.php — Student self-registration bound to a student
 * group's shareable code (migration_v68, Lib/StudentGroup.php).
 *
 * This is the "additional page" alongside auth/register.php: an admin
 * generates a registration code for a group from Admin/StudentGroupEdit.php
 * and shares this page's URL — auth/register-group.php?code=XXXXXXXX — with
 * students (e.g. printed on a batch handout, posted in a class WhatsApp
 * group). Anyone who completes registration through that link is added to
 * the matching student_groups row automatically after their account is
 * saved (Lib/StudentGroup.php::enrollSelfByCode()), which also fans out any
 * exams already recommended/directly-assigned to that group — exactly like
 * an admin bulk-adding them via Admin/StudentGroupMembers.php would.
 *
 * Deliberately student-only (no teacher toggle): a group-registration link
 * is for onboarding a batch/cohort of students, not for teacher applications
 * — those still go through the general auth/register.php.
 *
 * Everything else (validation rules, CAPTCHA, IP rate-limit, pending-
 * approval account state, optional institute/blood-group fields) mirrors
 * auth/register.php's student branch so the two flows behave identically
 * apart from the group link — see that file for the canonical version of
 * this house style.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/StudentGroup.php';

// Institute.php is optional — only needed if migration_v16 has been run
$_instituteLibLoaded = false;
if (file_exists(__DIR__ . '/../Lib/Institute.php')) {
    require_once __DIR__ . '/../Lib/Institute.php';
    $_instituteLibLoaded = true;
}

if (Auth::isLoggedIn()) { header('Location: ../exam/search.php'); exit; }

/* ── Resolve & validate the group code ─────────────────────────────────────
   Read from GET on first load and carry it through the form as a hidden
   field on POST, so a page refresh or a slow submit still targets the same
   group. Re-checked against the DB on every request (not just trusted from
   the hidden field) — a group could be deactivated between page-load and
   submit, and an invalid/inactive code must never silently register the
   student ungrouped without saying so. */
$code  = trim($_GET['code'] ?? $_POST['code'] ?? '');
$group = StudentGroup::findByCode($code);

if (!$group) {
    // No valid, active group behind this code — show a small standalone
    // notice rather than the full registration form. The account itself
    // isn't the problem here; the link is, so send them to the general
    // registration page (or to sign in) instead of a dead end.
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Invalid Registration Link &mdash; <?php echo APP_NAME; ?></title>
      <link rel="icon" type="image/svg+xml" href="../assets/logo.svg">
      <link rel="stylesheet" href="../<?php echo asset_version('assets/style.css'); ?>">
    </head>
    <body>
    <div class="auth-wrap">
      <div class="auth-header">
        <div class="auth-logo">
          <img src="../assets/logo.png" alt="Logo"
               onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
          <div class="auth-logo-placeholder" style="display:none;">YOUR<br>LOGO</div>
        </div>
        <h1><?php echo APP_NAME; ?></h1>
      </div>
      <div class="auth-body">
        <div class="alert alert-error">
          <?php echo $code === ''
            ? 'This registration link is missing its group code.'
            : 'This registration link is invalid, or the group is no longer accepting new students.'; ?>
        </div>
        <p style="font-size:.88rem;color:#4a5568;">
          Double-check the link you were given, or ask whoever shared it for a fresh one.
          You can still create a regular account below.
        </p>
        <div style="text-align:center;margin-top:16px;display:flex;flex-direction:column;gap:8px;">
          <a href="register.php" class="btn btn-primary">Create a Regular Account</a>
          <a href="login.php" style="font-size:.85rem;">Already have an account? Sign In</a>
        </div>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$gid = (int)$group['StudentGroupId'];

/* ── IP helpers (mirrors auth/register.php — same registration_rate_limit
   table, so registrations from both pages count toward the same per-IP cap) ── */
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
        Database::execute("INSERT INTO registration_rate_limit (ip_address) VALUES (?)", [$ip]);
    } catch (Exception $e) { /* migration_v23 not yet run — skip */ }
}

/* ── Math CAPTCHA helpers ────────────────────────────────────────────────── */
function generateCaptcha(): void {
    $_SESSION['captcha_a']      = random_int(2, 9);
    $_SESSION['captcha_b']      = random_int(1, 9);
    $_SESSION['captcha_answer'] = $_SESSION['captcha_a'] + $_SESSION['captcha_b'];
}
if (empty($_SESSION['captcha_answer'])) {
    generateCaptcha();
}

$msg = ''; $isErr = false;
$institutes = $_instituteLibLoaded ? Institute::listAll() : [];
$_clientIp  = getClientIp();

/* ── POST ────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnRegister'])) {
    Auth::validateCsrf();

    // Re-validate the group is still live at submit time (see comment above).
    $group = StudentGroup::findByCode($code);
    if (!$group) {
        $msg = 'This group is no longer accepting new registrations. Please ask for a fresh link.';
        $isErr = true;
    }
    $gid = $group ? (int)$group['StudentGroupId'] : 0;

    if (!$isErr && !ipUnderRateLimit($_clientIp)) {
        $msg = 'Too many accounts registered from your network in the last hour. Please try again later.';
        $isErr = true;
        generateCaptcha();
    }

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

    $errors = [];

    /* ── CAPTCHA check ──────────────────────────────────────────────────── */
    if (!$isErr) {
        $captchaInput  = (int)($_POST['captcha_answer'] ?? -1);
        $captchaExpect = (int)($_SESSION['captcha_answer'] ?? -99);
        if ($captchaInput !== $captchaExpect) {
            $errors[] = 'Incorrect answer to the security question. Please try again.';
        }
        generateCaptcha();
    }

    /* ── Validation (identical rules to auth/register.php's student branch) ── */
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

    if ($mobileRaw !== '') {
        $mobileRules = [
            '+91'  => [10, 10, '/^[6-9]/'],   '+1'   => [10, 10, null],
            '+44'  => [10, 10, null],         '+61'  => [9,  9,  null],
            '+64'  => [8,  9,  null],         '+971' => [9,  9,  null],
            '+966' => [9,  9,  '/^[5]/'],     '+65'  => [8,  8,  '/^[689]/'],
            '+60'  => [9,  10, '/^[1]/'],     '+94'  => [9,  9,  '/^[7]/'],
            '+92'  => [10, 10, '/^[3]/'],     '+880' => [10, 10, '/^[1]/'],
            '+977' => [10, 10, '/^[9]/'],     '+81'  => [10, 11, null],
            '+82'  => [9,  10, null],         '+86'  => [11, 11, null],
            '+852' => [8,  8,  null],         '+49'  => [10, 12, null],
            '+33'  => [9,  9,  null],         '+39'  => [9,  10, null],
            '+34'  => [9,  9,  null],         '+31'  => [9,  9,  null],
            '+46'  => [9,  9,  null],         '+47'  => [8,  8,  null],
            '+45'  => [8,  8,  null],         '+41'  => [9,  9,  null],
            '+7'   => [10, 10, null],         '+55'  => [10, 11, null],
            '+52'  => [10, 10, null],         '+54'  => [10, 10, null],
            '+27'  => [9,  9,  null],         '+234' => [10, 10, '/^[07-9]/'],
            '+254' => [9,  9,  '/^[7]/'],     '+20'  => [10, 10, null],
            '+212' => [9,  9,  null],
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
        } elseif ($digits < 6 || $digits > 15) {
            $errors[] = 'Mobile number must be between 6 and 15 digits.';
        }
    }
    if ($bloodGroup !== '' && !in_array($bloodGroup, ['A+','A-','B+','B-','AB+','AB-','O+','O-'], true)) {
        $errors[] = 'Invalid blood group selected.';
    }

    if ($errors) {
        $msg = implode(' ', $errors); $isErr = true;
    } elseif (!$isErr) {
        $existing = Database::fetchOne("SELECT LoginInfoId FROM logininfo WHERE LoginName = ? LIMIT 1", [$loginName]);
        if ($existing) {
            $msg = 'That username is already taken.'; $isErr = true;
        } else {
            $emailUsed = Database::fetchOne("SELECT LoginInfoId FROM logininfo WHERE Email = ? LIMIT 1", [$email]);
            if (!$emailUsed) {
                try { $emailUsed = Database::fetchOne("SELECT UserInfoId FROM userinfo WHERE EMail = ? LIMIT 1", [$email]); }
                catch (Exception $e) {}
            }
            if ($emailUsed) { $msg = 'An account with that email already exists.'; $isErr = true; }
        }
    }

    if (!$isErr && !$errors) {
        $hash = password_hash($plainPwd, PASSWORD_DEFAULT);

        try {
            Database::beginTransaction();

            // Same 3-tier fallback as auth/register.php: InstituteId
            // (migration_v16) and RegisteredIp (migration_v23) may not
            // exist yet on an older install.
            try {
                Database::execute(
                    "INSERT INTO logininfo (LoginName, Password, Role, Email, Active, InstituteId, RegisteredIp)
                     VALUES (?, ?, 'STDNT', ?, 'N', ?, ?)",
                    [$loginName, $hash, $email, $instituteId, $_clientIp]);
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Unknown column') === false) throw $e;
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
            }

            if (Database::hasColumn('logininfo', 'RegistrationStatus')) {
                try {
                    Database::execute(
                        "UPDATE logininfo SET RegistrationStatus = 'Pending' WHERE LoginName = ?",
                        [$loginName]);
                } catch (Exception $e) {}
            }

            // Column availability checked once via information_schema
            // (memoized — Database::hasColumn()) rather than a try/catch
            // cascade: same graceful degradation on an install that hasn't
            // run migration_v16/v39/v61 yet.
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

            // ── Group join (migration_v68) — deliberately OUTSIDE the
            // account-creation transaction: the account must exist even if
            // group-linking hits an unexpected snag, and enrollSelfByCode()
            // is already internally exception-safe (never throws), so
            // there's nothing here worth rolling back over.
            $userRow    = Database::fetchOne("SELECT UserInfoId FROM userinfo WHERE LoginName = ? LIMIT 1", [$loginName]);
            $newUserId  = (int)($userRow['UserInfoId'] ?? 0);
            $joinResult = StudentGroup::enrollSelfByCode($code, $newUserId);

            $_SESSION['reg_success'] = $joinResult['ok']
                ? 'Account created and you\'ve been added to "' . $group['GroupName'] . '"! Your registration is pending admin approval — you will be able to sign in once it is approved.'
                : 'Account created! Your registration is pending admin approval — you will be able to sign in once it is approved. (Note: we could not confirm the group link — contact your admin if you expected to be added to a group.)';
            header('Location: login.php');
            exit;

        } catch (Exception $e) {
            Database::rollBack();
            error_log('register-group.php DB error: ' . $e->getMessage());
            $msg   = 'Registration failed due to a database error. Please try again or contact support.';
            $isErr = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Join <?php echo htmlspecialchars($group['GroupName']); ?> &mdash; <?php echo APP_NAME; ?></title>
  <link rel="icon" type="image/svg+xml" href="../assets/logo.svg">
  <link rel="icon" type="image/png" href="../assets/logo.png">
  <link rel="apple-touch-icon" href="../assets/logo.png">
  <link rel="stylesheet" href="../<?php echo asset_version('assets/style.css'); ?>">
  <style>
    .group-banner{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;
                  padding:14px 16px;margin-bottom:20px;}
    .group-banner h2{margin:0 0 4px;font-size:1.05rem;color:#065f46;}
    .group-banner p{margin:0;font-size:.83rem;color:#166534;}
    .group-banner .discount-chip{display:inline-block;margin-top:6px;background:#ede9fe;
                  color:#5b21b6;padding:2px 10px;border-radius:10px;font-size:.75rem;font-weight:700;}

    /* ── Phone input combo (mirrors auth/register.php) ─────────────────── */
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
      font-size:.9rem; outline:none; background:transparent; min-width:0;
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
           onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="auth-logo-placeholder" style="display:none;">YOUR<br>LOGO</div>
    </div>
    <h1>Create Account</h1>
    <p>Join <?php echo htmlspecialchars(APP_NAME); ?></p>
  </div>

  <div class="auth-body">
    <div class="group-banner">
      <h2>&#127891; You're joining: <?php echo htmlspecialchars($group['GroupName']); ?></h2>
      <?php if (!empty($group['Description'])): ?>
        <p><?php echo htmlspecialchars($group['Description']); ?></p>
      <?php endif; ?>
      <?php if ((float)($group['DiscountPct'] ?? 0) > 0): ?>
        <span class="discount-chip">
          <?php echo rtrim(rtrim(number_format((float)$group['DiscountPct'], 2), '0'), '.'); ?>% discount on paid exams
        </span>
      <?php endif; ?>
    </div>

    <?php if ($msg !== ''): ?>
      <div class="alert <?php echo $isErr ? 'alert-error' : 'alert-success'; ?>">
        <?php echo htmlspecialchars($msg); ?>
      </div>
    <?php endif; ?>

    <div class="teacher-notice" style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px 14px;font-size:.83rem;color:#92400e;margin-bottom:14px;">
      &#9888; New student accounts require admin approval before you can sign in.
      You will be able to log in once your registration is approved.
    </div>

    <form method="post" action="" id="regForm">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">

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
              ['+91','🇮🇳','India'],           ['+1','🇺🇸','USA / Canada'],
              ['+44','🇬🇧','UK'],               ['+61','🇦🇺','Australia'],
              ['+64','🇳🇿','New Zealand'],      ['+971','🇦🇪','UAE'],
              ['+966','🇸🇦','Saudi Arabia'],    ['+65','🇸🇬','Singapore'],
              ['+60','🇲🇾','Malaysia'],         ['+94','🇱🇰','Sri Lanka'],
              ['+92','🇵🇰','Pakistan'],         ['+880','🇧🇩','Bangladesh'],
              ['+977','🇳🇵','Nepal'],           ['+81','🇯🇵','Japan'],
              ['+82','🇰🇷','South Korea'],      ['+86','🇨🇳','China'],
              ['+852','🇭🇰','Hong Kong'],       ['+49','🇩🇪','Germany'],
              ['+33','🇫🇷','France'],           ['+39','🇮🇹','Italy'],
              ['+34','🇪🇸','Spain'],            ['+31','🇳🇱','Netherlands'],
              ['+46','🇸🇪','Sweden'],           ['+47','🇳🇴','Norway'],
              ['+45','🇩🇰','Denmark'],          ['+41','🇨🇭','Switzerland'],
              ['+7','🇷🇺','Russia'],            ['+55','🇧🇷','Brazil'],
              ['+52','🇲🇽','Mexico'],           ['+54','🇦🇷','Argentina'],
              ['+27','🇿🇦','South Africa'],     ['+234','🇳🇬','Nigeria'],
              ['+254','🇰🇪','Kenya'],           ['+20','🇪🇬','Egypt'],
              ['+212','🇲🇦','Morocco'],
            ];
            foreach ($ccList as [$code_, $flag, $name]):
            ?>
              <option value="<?php echo $code_; ?>" <?php echo $selectedCC===$code_?'selected':''; ?>>
                <?php echo $flag . ' ' . $code_ . ' ' . $name; ?>
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
      </div>
      <div class="form-group">
        <label for="txtInstituteStudentId">Institute Student ID / Roll No.
          <small style="color:#6b7280;font-weight:400;">(optional)</small>
        </label>
        <input type="text" id="txtInstituteStudentId" name="txtInstituteStudentId" class="form-control"
               maxlength="50" placeholder="e.g. your roll number or admission ID"
               value="<?php echo htmlspecialchars($_POST['txtInstituteStudentId'] ?? ''); ?>">
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
        Create Student Account &amp; Join Group
      </button>
    </form>

    <div style="text-align:center;margin-top:14px;font-size:.85rem;">
      Already have an account? <a href="login.php">Sign In</a>
    </div>
  </div>
</div>

<script>
/* Init hint/placeholder from default country */
onCountryChange(document.getElementById('txtCountryCode').value);

/* Form submit: mobile number check */
document.getElementById('regForm').addEventListener('submit', function(e) {
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

/* ── Mobile / Country-code validation (mirrors auth/register.php) ────────── */
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
  hintEl.textContent = rule ? ('Format: ' + rule.hint) : 'Enter 6–15 digit mobile number';
  validateMobile();
  var input = document.getElementById('txtMobileNum');
  if (rule) {
    input.placeholder = rule.min === rule.max ? rule.min + ' digits' : rule.min + '–' + rule.max + ' digits';
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

  if (numEl.value !== digits) numEl.value = digits;

  wrap.classList.remove('ok','error');
  errEl.classList.remove('visible');
  errEl.textContent = '';

  if (digits.length === 0) return;

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
    setMobileError(wrap, errEl, 'Expected ' + expected + ' for ' + cc + ' (entered ' + digits.length + ').');
    return;
  }

  if (rule.lead && !rule.lead.test(digits)) {
    setMobileError(wrap, errEl, 'Number for ' + cc + ' should start with ' + rule.hint.match(/starts with (.+?) \(/)?.[1] + '.');
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
