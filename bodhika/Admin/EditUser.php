<?php
/**
 * Admin/EditUser.php — Admin edit any user's profile.
 *
 * GET  ?id=UserInfoId         → show form
 * POST action=save            → update userinfo + logininfo.Active
 * POST action=clear_request   → dismiss a pending change request
 *
 * Admin edits are applied immediately (no approval needed).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Phone.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$adminId = Auth::currentUserId();
$userId  = (int)($_REQUEST['id'] ?? 0);
if (!$userId) { header('Location: AdminUsers.php'); exit; }

$success = '';
$errors  = [];

/* ── Load dropdowns ─────────────────────────────────────────────────── */
$institutes = Database::fetchAll(
    "SELECT InstituteId, InstituteName FROM institutes ORDER BY InstituteName", []);

/* ── Handle POST ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── Save profile ─────────────────────────────────────────────── */
    if ($action === 'save') {
        $fstName  = trim($_POST['FstName']  ?? '');
        $lstName  = trim($_POST['LstName']  ?? '');
        $email    = trim($_POST['EMail']    ?? '');
        // Same country-code + local-digits handling as exam/settings.php and
        // auth/register.php (via Lib/Phone.php) — previously this page took
        // a single free-text "Mobile" field with no country-code split and
        // no length validation, so an admin could save a value like
        // "919876543210" (country code baked into the digits, no "+").
        // Every other page's Phone::split() then displays that as a bogus
        // 12-digit number under "+91", and the "must be 10 digits" error
        // never goes away for the student no matter how they edit it.
        $countryCode  = trim($_POST['CountryCode'] ?? Phone::DEFAULT_CC);
        $mobileDigits = preg_replace('/\D/', '', $_POST['MobileNum'] ?? '');
        $mobile       = Phone::combine($countryCode, $mobileDigits);
        $instId   = filter_input(INPUT_POST, 'InstituteId', FILTER_VALIDATE_INT) ?: null;
        $active   = ($_POST['Active'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $bloodGroup = trim($_POST['BloodGroup'] ?? '');
        $willDonate = (($_POST['WillingToDonateBlood'] ?? '') === 'Y') ? 'Y' : 'N';

        if ($fstName === '') $errors[] = 'First name is required.';
        if ($lstName === '') $errors[] = 'Last name is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors[] = 'Invalid email address.';
        $mobileErr = Phone::validate($countryCode, $mobileDigits);
        if ($mobileErr !== null) $errors[] = $mobileErr;
        if ($bloodGroup !== '' && !in_array($bloodGroup, ['A+','A-','B+','B-','AB+','AB-','O+','O-'], true))
            $errors[] = 'Invalid blood group selected.';

        if (!empty($errors)) {
            // Redisplay what the admin just typed instead of the stale
            // pre-edit DB row (same fix applied to exam/settings.php).
            // $user isn't loaded from the DB until after this whole POST
            // block runs (see below), so stash the overlay and merge it in
            // once $user exists rather than writing to it here.
            $formOverride = [
                'FstName'  => $fstName,
                'LstName'  => $lstName,
                'EMail'    => $email,
                'Mobile'   => $mobile,
                'BloodGroup' => $bloodGroup,
                'WillingToDonateBlood' => $willDonate,
                'Active'   => $active,
            ];
        }

        if (empty($errors)) {
            $bloodGroupParam = $bloodGroup !== '' ? $bloodGroup : null;
            try {
                Database::execute(
                    "UPDATE userinfo SET FstName=?, LstName=?, EMail=?, Mobile=?, InstituteId=?,
                            BloodGroup=?, WillingToDonateBlood=?
                      WHERE UserInfoId=?",
                    [$fstName, $lstName, $email, $mobile, $instId, $bloodGroupParam, $willDonate, $userId]
                );
            } catch (\Throwable $e) {
                // migration_v39 not yet run — save the rest, skip the new fields
                Database::execute(
                    "UPDATE userinfo SET FstName=?, LstName=?, EMail=?, Mobile=?, InstituteId=?
                      WHERE UserInfoId=?",
                    [$fstName, $lstName, $email, $mobile, $instId, $userId]
                );
            }
            /* Sync Active flag on logininfo */
            Database::execute(
                "UPDATE logininfo SET Active=?
                  WHERE LoginName = (SELECT LoginName FROM userinfo WHERE UserInfoId=? LIMIT 1)",
                [$active, $userId]
            );

            /* Auto-approve any pending institute change request if admin just set the institute */
            if ($instId !== null) {
                Database::execute(
                    "UPDATE user_change_requests
                        SET Status='approved', ReviewedBy=?, ReviewedAt=NOW(),
                            AdminNote='Auto-approved by direct admin edit'
                      WHERE UserId=? AND FieldName='InstituteId' AND Status='pending'",
                    [$adminId, $userId]
                );
            }

            $success = 'User profile updated successfully.';
        }
    }

    /* ── Dismiss / reject a pending change request ────────────────── */
    if ($action === 'reject_request') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $note  = trim($_POST['admin_note'] ?? 'Rejected by admin.');
        if ($reqId) {
            Database::execute(
                "UPDATE user_change_requests
                    SET Status='rejected', ReviewedBy=?, ReviewedAt=NOW(), AdminNote=?
                  WHERE RequestId=? AND UserId=?",
                [$adminId, $note, $reqId, $userId]
            );
            $success = 'Change request rejected.';
        }
    }

    /* ── Reset password to the default, forced change on next login ──── */
    // Uses the same Auth::resetPasswordToDefault() helper as
    // Admin/ResetStudentPassword.php (the dedicated Admin/Institute-Admin
    // reset page) so the two entry points can never drift apart.
    if ($action === 'reset_password') {
        $resetResult = Auth::resetPasswordToDefault($userId);
        if ($resetResult['ok']) {
            $success = 'Password reset to the default ("' . $resetResult['password'] . '"). '
                     . 'They will be required to set a new password the next time they log in.';
        } else {
            $errors[] = $resetResult['error'] ?: 'Could not reset password.';
        }
    }

    /* ── Unlock account (clear failed-login lockout) ──────────────────── */
    if ($action === 'unlock_account') {
        try {
            Database::execute(
                "UPDATE logininfo SET failed_attempts=0, locked_until=NULL
                  WHERE LoginName = (SELECT LoginName FROM userinfo WHERE UserInfoId=? LIMIT 1)",
                [$userId]
            );
            $success = 'Account unlocked. The user can log in immediately.';
        } catch (\Throwable $e) {
            $errors[] = 'Could not unlock account — lockout columns are not present yet.';
        }
    }

    /* ── Approve a pending change request ─────────────────────────── */
    if ($action === 'approve_request') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        if ($reqId) {
            $req = Database::fetchOne(
                "SELECT * FROM user_change_requests WHERE RequestId=? AND UserId=? AND Status='pending' LIMIT 1",
                [$reqId, $userId]
            );
            if ($req) {
                $col   = $req['FieldName'];
                $val   = $req['NewValue'];
                // Whitelist columns that can be approved
                $allowed = ['InstituteId','EMail','Mobile','FstName','LstName'];
                if (in_array($col, $allowed, true)) {
                    Database::execute(
                        "UPDATE userinfo SET `$col`=? WHERE UserInfoId=?",
                        [$val ?: null, $userId]
                    );
                }
                Database::execute(
                    "UPDATE user_change_requests
                        SET Status='approved', ReviewedBy=?, ReviewedAt=NOW()
                      WHERE RequestId=?",
                    [$adminId, $reqId]
                );
                $success = 'Change request approved and applied.';
            }
        }
    }
}

/* ── Load user ───────────────────────────────────────────────────────── */
// BloodGroup/WillingToDonateBlood may not exist yet (migration_v39)
try {
    $user = Database::fetchOne(
        "SELECT u.UserInfoId, u.FstName, u.LstName, u.EMail, u.Mobile,
                u.LoginName, u.InstituteId, u.BloodGroup, u.WillingToDonateBlood,
                COALESCE(inst.InstituteName,'') AS InstituteName,
                l.Active, l.Role
           FROM userinfo u
           LEFT JOIN logininfo  l    ON l.LoginName   = u.LoginName
           LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
          WHERE u.UserInfoId = ? LIMIT 1",
        [$userId]
    );
} catch (\Throwable $e) {
    $user = Database::fetchOne(
        "SELECT u.UserInfoId, u.FstName, u.LstName, u.EMail, u.Mobile,
                u.LoginName, u.InstituteId,
                COALESCE(inst.InstituteName,'') AS InstituteName,
                l.Active, l.Role
           FROM userinfo u
           LEFT JOIN logininfo  l    ON l.LoginName   = u.LoginName
           LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
          WHERE u.UserInfoId = ? LIMIT 1",
        [$userId]
    );
}
if (!$user) { header('Location: AdminUsers.php'); exit; }

/* Validation failed above — overlay what the admin actually submitted so
   the form shows their in-progress edit instead of the just-fetched DB
   row (which still has whatever was there before, e.g. a malformed
   legacy mobile number). */
if (isset($formOverride)) {
    $user = array_merge($user, $formOverride);
}

/* ── Lockout status (failed_attempts / locked_until may not exist on
   older installs — fail soft) ─────────────────────────────────────────── */
$lockInfo = ['failed_attempts' => 0, 'locked_until' => null, 'isLocked' => false, 'minsLeft' => 0];
try {
    $lockRow = Database::fetchOne(
        "SELECT l.failed_attempts, l.locked_until
           FROM logininfo l WHERE l.LoginName = ? LIMIT 1",
        [$user['LoginName']]
    );
    if ($lockRow) {
        $lockInfo['failed_attempts'] = (int)($lockRow['failed_attempts'] ?? 0);
        $lockInfo['locked_until']    = $lockRow['locked_until'] ?? null;
        if (!empty($lockRow['locked_until']) && strtotime($lockRow['locked_until']) > time()) {
            $lockInfo['isLocked'] = true;
            $lockInfo['minsLeft'] = (int)ceil((strtotime($lockRow['locked_until']) - time()) / 60);
        }
    }
} catch (\Throwable $e) { /* migration not applied — show no lock info */ }

/* ── Pending change requests for this user ───────────────────────────── */
$pendingRequests = [];
try {
    $pendingRequests = Database::fetchAll(
        "SELECT * FROM user_change_requests
          WHERE UserId=? AND Status='pending'
          ORDER BY RequestedAt DESC",
        [$userId]
    );
} catch (\Throwable $e) { /* table may not exist yet */ }

/* ── Recent closed requests ──────────────────────────────────────────── */
$recentRequests = [];
try {
    $recentRequests = Database::fetchAll(
        "SELECT * FROM user_change_requests
          WHERE UserId=? AND Status != 'pending'
          ORDER BY ReviewedAt DESC LIMIT 5",
        [$userId]
    );
} catch (\Throwable $e) { }

$pageTitle = 'Edit User — ' . htmlspecialchars($user['FstName'] . ' ' . $user['LstName']);
include __DIR__ . '/../includes/header.php';
?>

<style>
.eu-grid   { display:grid; grid-template-columns:1fr 380px; gap:20px; align-items:start; }
.req-card  { border:2px solid #fbbf24; border-radius:10px; background:#fffbeb;
             margin-bottom:12px; padding:14px 16px; }
.req-field { font-size:.72rem; font-weight:800; text-transform:uppercase;
             letter-spacing:.06em; color:var(--clr-primary); margin-bottom:6px; }
.req-vals  { display:flex; align-items:center; gap:10px; font-size:.88rem; }
.req-old   { color:#64748b; text-decoration:line-through; }
.req-arrow { color:#94a3b8; }
.req-new   { font-weight:700; color:#1e293b; }
@media(max-width:760px){ .eu-grid { grid-template-columns:1fr; } }
</style>

<div class="page-wrap">

  <!-- Breadcrumb -->
  <div style="font-size:.82rem;color:var(--clr-text-muted);margin-bottom:16px;">
    <a href="AdminUsers.php" style="color:var(--clr-primary);text-decoration:none;">Users &amp; Students</a>
    › Edit User
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:16px;"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger" style="margin-bottom:16px;">
      <?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?>
    </div>
  <?php endif; ?>

  <div class="eu-grid">

    <!-- Left: Edit form -->
    <div>
      <div class="card">
        <div class="card-header" style="display:flex;align-items:center;gap:10px;">
          <span style="font-size:1.4rem;">👤</span>
          <div>
            <div style="font-weight:800;font-size:1.05rem;">
              <?= htmlspecialchars($user['FstName'] . ' ' . $user['LstName']) ?>
            </div>
            <div style="font-size:.8rem;color:var(--clr-text-muted);">
              @<?= htmlspecialchars($user['LoginName']) ?>
              &nbsp;·&nbsp; <?= htmlspecialchars($user['Role'] ?? '') ?>
            </div>
          </div>
        </div>
        <div class="card-body">
          <form method="post" action="EditUser.php?id=<?= $userId ?>">
            <input type="hidden" name="action" value="save">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div class="form-group">
                <label class="form-label">First Name <span style="color:#dc2626">*</span></label>
                <input type="text" name="FstName" class="form-control"
                       value="<?= htmlspecialchars($user['FstName']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">Last Name <span style="color:#dc2626">*</span></label>
                <input type="text" name="LstName" class="form-control"
                       value="<?= htmlspecialchars($user['LstName']) ?>" required>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Username (Login Name)</label>
              <input type="text" class="form-control"
                     value="<?= htmlspecialchars($user['LoginName']) ?>" disabled>
              <small style="color:var(--clr-text-muted);">Username cannot be changed here.</small>
            </div>

            <div class="form-group">
              <label class="form-label">Email</label>
              <input type="email" name="EMail" class="form-control"
                     value="<?= htmlspecialchars($user['EMail'] ?? '') ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Mobile</label>
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
              <label class="form-label">Blood Group</label>
              <select name="BloodGroup" class="form-control" style="width:160px;">
                <option value="">— Not Sure —</option>
                <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                  <option value="<?= $bg ?>" <?= ($user['BloodGroup'] ?? '') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                <?php endforeach; ?>
              </select>
              <label style="font-weight:normal;display:block;margin-top:8px;font-size:.85rem;">
                <input type="checkbox" name="WillingToDonateBlood" value="Y"
                       <?= ($user['WillingToDonateBlood'] ?? 'N') === 'Y' ? 'checked' : '' ?>>
                Willing to donate blood
              </label>
            </div>

            <div class="form-group">
              <label class="form-label">Institute</label>
              <select name="InstituteId" class="form-control">
                <option value="">— None —</option>
                <?php foreach ($institutes as $inst): ?>
                  <option value="<?= (int)$inst['InstituteId'] ?>"
                    <?= (int)($user['InstituteId'] ?? 0) === (int)$inst['InstituteId'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($inst['InstituteName']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small style="color:var(--clr-text-muted);">Admin changes are applied immediately.</small>
            </div>

            <div class="form-group">
              <label class="form-label">Account Active</label>
              <select name="Active" class="form-control" style="width:120px;">
                <option value="Y" <?= ($user['Active'] ?? 'Y') === 'Y' ? 'selected' : '' ?>>Yes</option>
                <option value="N" <?= ($user['Active'] ?? 'Y') === 'N' ? 'selected' : '' ?>>No</option>
              </select>
            </div>

            <div style="display:flex;gap:10px;margin-top:4px;">
              <button type="submit" class="btn">Save Changes</button>
              <a href="AdminUsers.php" class="btn btn-outline">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Right: Pending change requests -->
    <div>
      <!-- Reset password -->
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header" style="font-weight:700;">🔑 Security</div>
        <div class="card-body">

          <?php if ($lockInfo['isLocked']): ?>
          <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;
                      padding:10px 12px;margin-bottom:14px;">
            <div style="font-weight:700;color:#dc2626;font-size:.85rem;">
              🔒 Account locked
            </div>
            <div style="font-size:.82rem;color:#7f1d1d;margin-top:2px;">
              Too many failed login attempts (<?= $lockInfo['failed_attempts'] ?>).
              Locked for <?= $lockInfo['minsLeft'] ?> more minute<?= $lockInfo['minsLeft'] !== 1 ? 's' : '' ?>
              (until <?= htmlspecialchars(date('d M Y H:i', strtotime($lockInfo['locked_until']))) ?>).
            </div>
            <form method="post" action="EditUser.php?id=<?= $userId ?>" style="margin:10px 0 0;">
              <input type="hidden" name="action" value="unlock_account">
              <button type="submit" class="btn btn-sm" style="background:#dc2626;border-color:#dc2626;">
                Unlock Account Now
              </button>
            </form>
          </div>
          <?php elseif ($lockInfo['failed_attempts'] > 0): ?>
          <div style="font-size:.8rem;color:var(--clr-text-muted);margin-bottom:14px;">
            <?= $lockInfo['failed_attempts'] ?> recent failed login attempt<?= $lockInfo['failed_attempts'] !== 1 ? 's' : '' ?> (not currently locked).
          </div>
          <?php endif; ?>

          <p style="font-size:.85rem;color:var(--clr-text-muted);margin:0 0 10px;">
            If <?= htmlspecialchars($user['FstName']) ?> forgot their password, reset it to the
            default below. They'll be required to set their own new password the next time they log in.
          </p>
          <form method="post" action="EditUser.php?id=<?= $userId ?>" style="margin:0;"
                onsubmit="return confirm('Reset password for <?= htmlspecialchars(addslashes(trim($user['FstName'].' '.$user['LstName'])), ENT_QUOTES) ?> to the default? They will be required to set a new password at next login.');">
            <input type="hidden" name="action" value="reset_password">
            <button type="submit" class="btn btn-outline" style="border-color:#dc2626;color:#dc2626;">
              Reset Password to Default
            </button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
          <span style="font-weight:700;">⏳ Pending Change Requests</span>
          <?php if (!empty($pendingRequests)): ?>
            <span style="background:#fbbf24;color:#78350f;padding:2px 8px;border-radius:99px;
                         font-size:.72rem;font-weight:800;">
              <?= count($pendingRequests) ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="card-body" style="<?= empty($pendingRequests) ? 'padding:20px;text-align:center;' : 'padding:14px;' ?>">
          <?php if (empty($pendingRequests)): ?>
            <div style="color:var(--clr-text-muted);font-size:.88rem;">No pending requests.</div>
          <?php else: ?>
            <?php foreach ($pendingRequests as $req): ?>
            <div class="req-card">
              <div class="req-field"><?= htmlspecialchars($req['FieldName']) ?></div>
              <div class="req-vals">
                <span class="req-old"><?= htmlspecialchars($req['OldLabel'] ?: $req['OldValue'] ?: '(none)') ?></span>
                <span class="req-arrow">→</span>
                <span class="req-new"><?= htmlspecialchars($req['NewLabel'] ?: $req['NewValue'] ?: '(none)') ?></span>
              </div>
              <div style="font-size:.75rem;color:var(--clr-text-muted);margin:6px 0 10px;">
                Requested <?= date('d M Y H:i', strtotime($req['RequestedAt'])) ?>
              </div>
              <div style="display:flex;gap:8px;">
                <form method="post" action="EditUser.php?id=<?= $userId ?>" style="margin:0;">
                  <input type="hidden" name="action"     value="approve_request">
                  <input type="hidden" name="request_id" value="<?= (int)$req['RequestId'] ?>">
                  <button type="submit" class="btn btn-sm"
                          style="background:#16a34a;border-color:#16a34a;"
                          onclick="return confirm('Approve this change?')">
                    ✓ Approve
                  </button>
                </form>
                <form method="post" action="EditUser.php?id=<?= $userId ?>" style="margin:0;">
                  <input type="hidden" name="action"     value="reject_request">
                  <input type="hidden" name="request_id" value="<?= (int)$req['RequestId'] ?>">
                  <input type="hidden" name="admin_note" value="Rejected by admin.">
                  <button type="submit" class="btn btn-sm btn-outline"
                          style="border-color:#dc2626;color:#dc2626;"
                          onclick="return confirm('Reject this change?')">
                    ✗ Reject
                  </button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Recent resolved requests -->
      <?php if (!empty($recentRequests)): ?>
      <div class="card" style="margin-top:16px;">
        <div class="card-header" style="font-weight:700;font-size:.88rem;">Recent Request History</div>
        <div class="card-body" style="padding:0;">
          <table class="tbl" style="font-size:.8rem;">
            <thead>
              <tr>
                <th>Field</th>
                <th>New Value</th>
                <th>Status</th>
                <th>Reviewed</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentRequests as $req):
                $stColor = $req['Status']==='approved' ? '#059669' : '#dc2626';
              ?>
              <tr>
                <td><?= htmlspecialchars($req['FieldName']) ?></td>
                <td><?= htmlspecialchars($req['NewLabel'] ?: $req['NewValue'] ?: '—') ?></td>
                <td><span style="color:<?= $stColor ?>;font-weight:700;text-transform:capitalize;">
                  <?= htmlspecialchars($req['Status']) ?>
                </span></td>
                <td style="white-space:nowrap;">
                  <?= $req['ReviewedAt'] ? date('d M', strtotime($req['ReviewedAt'])) : '—' ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /eu-grid -->
</div><!-- /page-wrap -->

<script src="../<?php echo asset_version('assets/phone-input.js'); ?>"></script>
<script>initPhoneField();</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
