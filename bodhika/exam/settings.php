<?php
/**
 * exam/settings.php — Student Settings
 *
 * Tabs:
 *   • Edit Profile     — FstName, LstName, EMail, Mobile, InstituteId
 *   • Change Password  — current password → new password (bcrypt)
 *
 * CSRF protected. Admin users are also allowed (they can update their own profile).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Phone.php';
Auth::requireLogin('../auth/login.php');
Auth::validateCsrf();

$myUserId   = Auth::currentUserId();
$myLoginId  = Auth::currentLoginId();
$isAdmin    = Auth::isAdmin();

/* ── Load current user data ────────────────────────────────────────────── */
function _loadSettingsUser(int $userId): ?array {
    // BloodGroup/WillingToDonateBlood may not exist yet (migration_v39)
    try {
        return Database::fetchOne(
            "SELECT u.UserInfoId, u.FstName, u.LstName, u.EMail, u.Mobile, u.InstituteId,
                    u.LoginName, u.BloodGroup, u.WillingToDonateBlood,
                    COALESCE(inst.InstituteName,'') AS InstituteName
               FROM userinfo u
               LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
              WHERE u.UserInfoId = ? LIMIT 1",
            [$userId]
        );
    } catch (\Throwable $e) {
        return Database::fetchOne(
            "SELECT u.UserInfoId, u.FstName, u.LstName, u.EMail, u.Mobile, u.InstituteId,
                    u.LoginName,
                    COALESCE(inst.InstituteName,'') AS InstituteName
               FROM userinfo u
               LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
              WHERE u.UserInfoId = ? LIMIT 1",
            [$userId]
        );
    }
}

$user = _loadSettingsUser($myUserId);
if (!$user) {
    die('User record not found. Please contact the administrator.');
}

/* ── Institute list (for dropdown) ────────────────────────────────────── */
$institutes = Database::fetchAll(
    "SELECT InstituteId, InstituteName FROM institutes ORDER BY InstituteName", []);

/* ── Active tab ───────────────────────────────────────────────────────── */
$tab = in_array($_GET['tab'] ?? '', ['password','profile']) ? $_GET['tab'] : 'profile';

/* ── Messages ─────────────────────────────────────────────────────────── */
$success = '';
$errors  = [];

/* ── Handle POST ──────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── Edit Profile ───────────────────────────────────────────────── */
    if ($action === 'profile') {
        $tab       = 'profile';
        $fstName     = trim($_POST['FstName']    ?? '');
        $lstName     = trim($_POST['LstName']    ?? '');
        $email       = trim($_POST['EMail']      ?? '');
        $countryCode = trim($_POST['CountryCode'] ?? Phone::DEFAULT_CC);
        $mobileDigits = preg_replace('/\D/', '', $_POST['MobileNum'] ?? '');
        $mobile      = Phone::combine($countryCode, $mobileDigits);
        $instId      = filter_input(INPUT_POST, 'InstituteId', FILTER_VALIDATE_INT) ?: null;
        $bloodGroup  = trim($_POST['BloodGroup'] ?? '');
        $willDonate  = (($_POST['WillingToDonateBlood'] ?? '') === 'Y') ? 'Y' : 'N';

        if ($fstName === '')        $errors[] = 'First name is required.';
        if ($lstName === '')        $errors[] = 'Last name is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
                                    $errors[] = 'Enter a valid email address.';
        $mobileErr = Phone::validate($countryCode, $mobileDigits);
        if ($mobileErr !== null)   $errors[] = $mobileErr;
        if ($bloodGroup !== '' && !in_array($bloodGroup, ['A+','A-','B+','B-','AB+','AB-','O+','O-'], true))
                                    $errors[] = 'Invalid blood group selected.';

        if (empty($errors)) {
            $messages = [];

            /* ── Institute: queue for approval if changed (non-admin) ── */
            $instChanged = !$isAdmin && ((int)($user['InstituteId'] ?? 0) !== (int)$instId);
            if ($instChanged) {
                /* Resolve label for new institute */
                $newInstLabel = '';
                if ($instId) {
                    $instRow = Database::fetchOne(
                        "SELECT InstituteName FROM institutes WHERE InstituteId=? LIMIT 1",
                        [$instId]);
                    $newInstLabel = $instRow['InstituteName'] ?? '';
                }
                $oldInstLabel = $user['InstituteName'] ?? '';

                /* Cancel any existing pending institute request for this user */
                try {
                    Database::execute(
                        "UPDATE user_change_requests
                            SET Status='rejected', AdminNote='Superseded by new request'
                          WHERE UserId=? AND FieldName='InstituteId' AND Status='pending'",
                        [$myUserId]
                    );
                    /* Insert new pending request */
                    Database::execute(
                        "INSERT INTO user_change_requests
                               (UserId, FieldName, OldValue, NewValue, OldLabel, NewLabel)
                         VALUES (?, 'InstituteId', ?, ?, ?, ?)",
                        [$myUserId,
                         $user['InstituteId'] ?? '',
                         $instId ?? '',
                         $oldInstLabel,
                         $newInstLabel]
                    );
                    $messages[] = 'Institute change has been submitted for admin approval.';
                } catch (\Throwable $e) {
                    /* user_change_requests table may not exist yet */
                    $messages[] = 'Institute change could not be queued (run migration_v32.sql first).';
                }
                /* Don't apply InstituteId immediately — keep existing value */
                $instId = $user['InstituteId'];   // revert to current
            }

            /* ── Update all other fields immediately ───────────────── */
            $bloodGroupParam = $bloodGroup !== '' ? $bloodGroup : null;
            try {
                Database::execute(
                    "UPDATE userinfo SET FstName=?, LstName=?, EMail=?, Mobile=?, InstituteId=?,
                            BloodGroup=?, WillingToDonateBlood=?
                      WHERE UserInfoId=?",
                    [$fstName, $lstName, $email, $mobile, $instId, $bloodGroupParam, $willDonate, $myUserId]
                );
            } catch (\Throwable $e) {
                // migration_v39 not yet run — save the rest, skip the new fields
                Database::execute(
                    "UPDATE userinfo SET FstName=?, LstName=?, EMail=?, Mobile=?, InstituteId=?
                      WHERE UserInfoId=?",
                    [$fstName, $lstName, $email, $mobile, $instId, $myUserId]
                );
            }
            /* Refresh session display name */
            $_SESSION['full_name'] = trim("$fstName $lstName");
            $_SESSION['Admin']     = $_SESSION['full_name']; // legacy key

            $messages[] = 'Profile updated successfully.';
            $success = implode(' ', $messages);

            /* Re-load user row to reflect changes */
            $user = _loadSettingsUser($myUserId);
        } else {
            /* Validation failed — redisplay exactly what was submitted,
               not the stale pre-edit DB row. Without this, ANY error
               (even one unrelated to the phone number, like a blank
               name) makes the form fall back to $user as loaded before
               this POST, so a user fixing a bad/legacy mobile number
               sees their correction discarded and the old value
               reappear on every failed attempt — it looks like editing
               the phone field "does nothing". InstituteId is deliberately
               left alone here since the pending-approval flow above
               reads $user['InstituteId'] as the "current" value. */
            $user['FstName']    = $fstName;
            $user['LstName']    = $lstName;
            $user['EMail']      = $email;
            $user['Mobile']     = $mobile;
            $user['BloodGroup'] = $bloodGroup;
            $user['WillingToDonateBlood'] = $willDonate;
        }
    }

    /* ── Change Password ─────────────────────────────────────────────── */
    if ($action === 'password') {
        $tab     = 'password';
        $current = $_POST['current_password'] ?? '';
        $new1    = $_POST['new_password']     ?? '';
        $new2    = $_POST['confirm_password'] ?? '';

        if ($current === '') $errors[] = 'Current password is required.';
        if (strlen($new1) < 6) $errors[] = 'New password must be at least 6 characters.';
        if ($new1 !== $new2)   $errors[] = 'New passwords do not match.';

        if (empty($errors)) {
            /* Verify current password */
            $li = Database::fetchOne(
                "SELECT Password FROM logininfo WHERE LoginInfoId = ? LIMIT 1",
                [$myLoginId]
            );
            $storedPwd = $li['Password'] ?? '';
            $valid = password_verify($current, $storedPwd)
                  || ($current === $storedPwd)        // plain-text legacy
                  || (md5($current) === $storedPwd);  // MD5 legacy

            if (!$valid) {
                $errors[] = 'Current password is incorrect.';
            } else {
                $hash = password_hash($new1, PASSWORD_DEFAULT);
                Database::execute(
                    "UPDATE logininfo SET Password = ? WHERE LoginInfoId = ?",
                    [$hash, $myLoginId]
                );
                $success = 'Password changed successfully.';
            }
        }
    }
}

$pageTitle = 'Settings';
include __DIR__ . '/../includes/header.php';
?>

<style>
  .settings-tabs { display:flex;gap:0;border-bottom:2px solid var(--clr-border);margin-bottom:1.4rem; }
  .settings-tab  { padding:9px 20px;font-size:.88rem;font-weight:600;cursor:pointer;color:var(--tx-muted);
                   border-bottom:3px solid transparent;margin-bottom:-2px;text-decoration:none;
                   transition:.15s; }
  .settings-tab:hover { color:var(--clr-primary); }
  .settings-tab.active { color:var(--clr-primary);border-bottom-color:var(--clr-primary); }
  .settings-section { max-width:560px; }
</style>

<div class="card">
  <div class="card-header">
    <h2 style="margin:0;font-size:1.05rem;">⚙️ Account Settings</h2>
  </div>
  <div class="card-body">

    <!-- Tab bar -->
    <div class="settings-tabs">
      <a href="settings.php?tab=profile"
         class="settings-tab <?= $tab === 'profile' ? 'active' : '' ?>">Edit Profile</a>
      <a href="settings.php?tab=password"
         class="settings-tab <?= $tab === 'password' ? 'active' : '' ?>">Change Password</a>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger" style="margin-bottom:1rem;">
        <?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?>
      </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════════
         TAB: Edit Profile
    ════════════════════════════════════════════════════════════════ -->
    <?php if ($tab === 'profile'): ?>
    <div class="settings-section">
      <form method="post" action="settings.php?tab=profile">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
        <input type="hidden" name="action"     value="profile">

        <div class="form-group">
          <label class="form-label">Username (Login Name)</label>
          <input class="form-control" type="text"
                 value="<?= htmlspecialchars($user['LoginName'] ?? '') ?>"
                 disabled title="Username cannot be changed">
          <small style="color:var(--tx-muted);">Username cannot be changed. Contact admin if needed.</small>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label class="form-label">First Name <span style="color:#dc2626;">*</span></label>
            <input class="form-control" type="text" name="FstName"
                   value="<?= htmlspecialchars($user['FstName'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Last Name <span style="color:#dc2626;">*</span></label>
            <input class="form-control" type="text" name="LstName"
                   value="<?= htmlspecialchars($user['LstName'] ?? '') ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input class="form-control" type="email" name="EMail"
                 value="<?= htmlspecialchars($user['EMail'] ?? '') ?>"
                 placeholder="your@email.com">
        </div>

        <div class="form-group">
          <label class="form-label">Mobile Number</label>
          <?php [$curCC, $curDigits] = Phone::split($user['Mobile'] ?? ''); ?>
          <div class="phone-input-wrap" id="phoneInputWrap">
            <select id="txtCountryCode" name="CountryCode" class="phone-cc-select" aria-label="Country code">
              <?php foreach (Phone::ccList() as [$code, $iso, $flag, $label]): ?>
                <option value="<?= htmlspecialchars($code) ?>" <?= $curCC === $code ? 'selected' : '' ?>>
                  <?= htmlspecialchars($flag . ' ' . $code . ' ' . $label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="tel" id="txtMobileNum" name="MobileNum"
                   class="phone-num-input" inputmode="numeric"
                   placeholder="Mobile number" autocomplete="tel-national"
                   value="<?= htmlspecialchars($curDigits) ?>"
                   aria-describedby="mobileHint mobileError">
          </div>
          <small id="mobileHint" class="mobile-hint"></small>
          <small id="mobileError" class="mobile-error" role="alert" aria-live="polite"></small>
        </div>

        <div class="form-group">
          <label class="form-label">Blood Group <small style="color:var(--tx-muted);font-weight:400;">(optional)</small></label>
          <select class="form-control" name="BloodGroup">
            <option value="">— Not Sure / Skip —</option>
            <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
              <option value="<?= $bg ?>" <?= ($user['BloodGroup'] ?? '') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
            <?php endforeach; ?>
          </select>
          <label style="font-weight:normal;display:block;margin-top:8px;font-size:.88rem;">
            <input type="checkbox" name="WillingToDonateBlood" value="Y"
                   <?= ($user['WillingToDonateBlood'] ?? 'N') === 'Y' ? 'checked' : '' ?>>
            I'm willing to donate blood if contacted in an emergency
          </label>
        </div>

        <div class="form-group">
          <label class="form-label">Institute</label>
          <?php
          /* Check for pending institute change request */
          $pendingInst = null;
          if (!$isAdmin) {
              try {
                  $pendingInst = Database::fetchOne(
                      "SELECT * FROM user_change_requests
                        WHERE UserId=? AND FieldName='InstituteId' AND Status='pending'
                        ORDER BY RequestedAt DESC LIMIT 1",
                      [$myUserId]
                  );
              } catch (\Throwable $e) {}
          }
          ?>
          <?php if ($pendingInst): ?>
            <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:7px;
                        padding:8px 12px;margin-bottom:8px;font-size:.83rem;">
              ⏳ <strong>Pending approval:</strong>
              Change to
              <strong><?= htmlspecialchars($pendingInst['NewLabel'] ?: $pendingInst['NewValue'] ?: '(none)') ?></strong>
              is waiting for admin review.
            </div>
          <?php endif; ?>
          <select class="form-control" name="InstituteId">
            <option value="">— Select Institute —</option>
            <?php foreach ($institutes as $inst): ?>
              <option value="<?= (int)$inst['InstituteId'] ?>"
                <?= (int)($user['InstituteId'] ?? 0) === (int)$inst['InstituteId'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($inst['InstituteName']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$isAdmin): ?>
            <small style="color:var(--clr-text-muted);">
              Institute changes require admin approval before taking effect.
            </small>
          <?php endif; ?>
        </div>

        <div style="margin-top:1.2rem;">
          <button type="submit" class="btn btn-primary">Save Profile</button>
          <a href="search.php" class="btn btn-secondary" style="margin-left:.5rem;">Cancel</a>
        </div>
      </form>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         TAB: Change Password
    ════════════════════════════════════════════════════════════════ -->
    <?php else: ?>
    <div class="settings-section">
      <form method="post" action="settings.php?tab=password">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
        <input type="hidden" name="action"     value="password">

        <div class="form-group">
          <label class="form-label">Current Password <span style="color:#dc2626;">*</span></label>
          <input class="form-control" type="password" name="current_password"
                 autocomplete="current-password" required>
        </div>

        <div class="form-group">
          <label class="form-label">New Password <span style="color:#dc2626;">*</span></label>
          <input class="form-control" type="password" name="new_password"
                 id="newPwd" autocomplete="new-password" minlength="6" required
                 oninput="checkStrength(this.value)">
          <!-- Password strength bar -->
          <div style="margin-top:6px;height:5px;border-radius:3px;background:#e2e8f0;overflow:hidden;">
            <div id="strengthBar" style="height:100%;width:0;transition:width .3s,background .3s;border-radius:3px;"></div>
          </div>
          <small id="strengthLabel" style="font-size:.75rem;color:var(--tx-muted);"></small>
        </div>

        <div class="form-group">
          <label class="form-label">Confirm New Password <span style="color:#dc2626;">*</span></label>
          <input class="form-control" type="password" name="confirm_password"
                 autocomplete="new-password" required
                 oninput="checkMatch(this.value)">
          <small id="matchMsg" style="font-size:.75rem;"></small>
        </div>

        <div style="margin-top:1.2rem;">
          <button type="submit" class="btn btn-primary">Change Password</button>
          <a href="search.php" class="btn btn-secondary" style="margin-left:.5rem;">Cancel</a>
        </div>
      </form>
    </div>
    <?php endif; ?>

  </div><!-- /card-body -->
</div><!-- /card -->

<script src="../<?php echo asset_version('assets/phone-input.js'); ?>"></script>
<script>
initPhoneField();

function checkStrength(pwd) {
  var bar   = document.getElementById('strengthBar');
  var label = document.getElementById('strengthLabel');
  if (!bar) return;
  var score = 0;
  if (pwd.length >= 6)  score++;
  if (pwd.length >= 10) score++;
  if (/[A-Z]/.test(pwd) && /[a-z]/.test(pwd)) score++;
  if (/[0-9]/.test(pwd)) score++;
  if (/[^A-Za-z0-9]/.test(pwd)) score++;
  var map = [
    { w: '0%',   bg: '',        txt: '' },
    { w: '20%',  bg: '#dc2626', txt: 'Very weak' },
    { w: '40%',  bg: '#f59e0b', txt: 'Weak' },
    { w: '60%',  bg: '#eab308', txt: 'Fair' },
    { w: '80%',  bg: '#22c55e', txt: 'Good' },
    { w: '100%', bg: '#15803d', txt: 'Strong' },
  ];
  var m = map[Math.min(score, 5)];
  bar.style.width      = m.w;
  bar.style.background = m.bg;
  label.textContent    = m.txt;
  label.style.color    = m.bg;
}

function checkMatch(confirmVal) {
  var msg = document.getElementById('matchMsg');
  var pwd = document.getElementById('newPwd');
  if (!msg || !pwd) return;
  if (confirmVal === '') { msg.textContent = ''; return; }
  if (confirmVal === pwd.value) {
    msg.textContent = '✓ Passwords match';
    msg.style.color = '#059669';
  } else {
    msg.textContent = '✗ Passwords do not match';
    msg.style.color = '#dc2626';
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
