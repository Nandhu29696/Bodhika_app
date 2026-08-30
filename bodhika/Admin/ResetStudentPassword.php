<?php
/**
 * Admin/ResetStudentPassword.php
 * Reset a student's password to the app default (DEFAULT_RESET_PASSWORD) and
 * force them to set a new one at next login (Auth::resetPasswordToDefault(),
 * migration_v59 MustChangePassword flag).
 *
 * Scope:
 *   - Full Admin (Auth::isAdmin())         → can reset any student, any institute.
 *   - Institute-Admin (Auth::isInstituteAdmin()) → can reset only students
 *     belonging to their own institute (Auth::currentInstituteId()) —
 *     enforced fail-safe-closed both in the search results AND, again, on
 *     the POST handler itself (never trust a client-supplied UserInfoId
 *     alone).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isInstituteAdmin() && !Auth::isAdmin()) {
    header('Location: ../exam/search.php');
    exit;
}

$isFullAdmin  = Auth::isAdmin() && !Auth::isInstituteAdmin();
$scopeInstId  = $isFullAdmin ? 0 : (Auth::currentInstituteId() ?? 0);

// A full Admin can optionally scope the search to one institute via ?instId=
// (e.g. arriving from InstituteAdminHome.php's "View Students" support link).
// An Institute-Admin is always locked to their own institute — never trusts
// a client-supplied instId.
$filterInstId = $isFullAdmin ? (filter_input(INPUT_GET, 'instId', FILTER_VALIDATE_INT) ?: 0) : $scopeInstId;

if (!$isFullAdmin && $scopeInstId <= 0) {
    // Institute-Admin with no institute linked yet — nothing they can do here.
    header('Location: InstituteAdminHome.php');
    exit;
}

$msg = ''; $isErr = false; $resetPasswordShown = '';

/* ── Handle reset action ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnReset'])) {
    Auth::validateCsrf();
    $targetId = (int)($_POST['user_id'] ?? 0);

    // Re-verify scope from the DB — never trust that the row shown to this
    // admin in their last page load is still the one they're allowed to
    // touch (fail-safe-closed, same pattern as InstituteAdminStudentDetail.php).
    $target = $targetId > 0 ? Database::fetchOne(
        "SELECT u.UserInfoId, u.FstName, u.LstName, u.InstituteId, l.Role
           FROM userinfo u
           LEFT JOIN logininfo l ON l.LoginName = u.LoginName
          WHERE u.UserInfoId = ? LIMIT 1",
        [$targetId]
    ) : null;

    if (!$target || ($target['Role'] ?? '') !== 'STDNT') {
        $msg = 'Student not found.'; $isErr = true;
    } elseif (!$isFullAdmin && (int)($target['InstituteId'] ?? 0) !== $scopeInstId) {
        $msg = 'You can only reset passwords for students in your own institute.'; $isErr = true;
    } else {
        $result = Auth::resetPasswordToDefault($targetId);
        if ($result['ok']) {
            $resetPasswordShown = $result['password'];
            $msg = 'Password reset for ' . trim($target['FstName'] . ' ' . $target['LstName'])
                 . '. Share the new password below with the student — they will be required to set '
                 . 'their own new password the next time they log in.';
        } else {
            $msg = $result['error'] ?: 'Could not reset password.'; $isErr = true;
        }
    }
}

/* ── Search ──────────────────────────────────────────────────────────── */
function safeStr(?string $v): string { return trim($v ?? ''); }
$search = safeStr($_GET['q'] ?? '');
$prefillId = (int)($_GET['id'] ?? 0);

$where  = ["l.Role = 'STDNT'"];
$params = [];
if ($filterInstId > 0) { $where[] = 'u.InstituteId = ?'; $params[] = $filterInstId; }
if ($search !== '') {
    $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like]);
}
if ($prefillId > 0) { $where[] = 'u.UserInfoId = ?'; $params[] = $prefillId; }
$whereSQL = implode(' AND ', $where);

$results = [];
$searched = $search !== '' || $prefillId > 0 || $filterInstId > 0;
if ($searched) {
    $results = Database::fetchAll(
        "SELECT u.UserInfoId, u.FstName, u.LstName, u.LoginName, u.EMail, u.Mobile,
                COALESCE(i.InstituteName, '—') AS InstituteName, l.Active
           FROM userinfo u
           LEFT JOIN logininfo  l ON l.LoginName   = u.LoginName
           LEFT JOIN institutes i ON i.InstituteId = u.InstituteId
          WHERE {$whereSQL}
          ORDER BY u.FstName, u.LstName
          LIMIT 100",
        $params
    );
}

$institutesForPicker = [];
if ($isFullAdmin) {
    require_once __DIR__ . '/../Lib/Institute.php';
    $institutesForPicker = Institute::listAll();
}

$pageTitle = 'Reset Student Password';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($msg !== ''): ?>
  <div class="alert <?php echo $isErr ? 'alert-danger' : 'alert-success'; ?>" style="margin-bottom:16px;">
    <?php echo $isErr ? '&#10006;' : '&#10004;'; ?> <?php echo htmlspecialchars($msg); ?>
  </div>
  <?php if ($resetPasswordShown !== ''): ?>
    <div class="card" style="margin-bottom:16px;border:2px solid #16a34a;">
      <div class="card-body" style="text-align:center;padding:20px;">
        <div style="font-size:.8rem;color:#64748b;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em;font-weight:700;">New Temporary Password</div>
        <div style="font-size:1.6rem;font-weight:800;font-family:monospace;color:#15803d;letter-spacing:.05em;">
          <?php echo htmlspecialchars($resetPasswordShown); ?>
        </div>
        <div style="font-size:.8rem;color:#64748b;margin-top:8px;">The student must set their own password immediately after logging in with this.</div>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="card">
  <div class="card-header">&#128269; Find Student</div>
  <div class="card-body">
    <form method="get" action="">
      <div class="form-row cols-3" style="align-items:flex-end;">
        <div class="form-group" style="margin-bottom:0;">
          <label>Name or Username</label>
          <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="e.g. jdoe">
        </div>
        <?php if ($isFullAdmin): ?>
        <div class="form-group" style="margin-bottom:0;">
          <label>Institute</label>
          <select name="instId" class="form-control">
            <option value="0">— All Institutes —</option>
            <?php foreach ($institutesForPicker as $inst): ?>
              <option value="<?php echo (int)$inst['InstituteId']; ?>" <?php echo $filterInstId === (int)$inst['InstituteId'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($inst['InstituteName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="span-full" style="margin-top:8px;display:flex;gap:8px;">
          <button type="submit" class="btn btn-primary">&#128269; Search</button>
          <a href="ResetStudentPassword.php" class="btn btn-secondary">Clear</a>
        </div>
      </div>
    </form>
  </div>
</div>

<?php if ($searched): ?>
<div class="card" style="margin-top:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#128101; Results</span>
    <?php if ($results): ?><span style="font-weight:400;font-size:.85rem;opacity:.85;"><?php echo count($results); ?> found</span><?php endif; ?>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if (!$results): ?>
      <p style="padding:32px;text-align:center;color:var(--clr-text-muted);">No students found.</p>
    <?php else: ?>
    <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Name</th><th>Username</th><th>Email / Mobile</th>
          <?php if ($isFullAdmin): ?><th>Institute</th><?php endif; ?>
          <th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $u):
          $fullName = trim($u['FstName'] . ' ' . $u['LstName']);
          $active   = ($u['Active'] ?? 'N') === 'Y';
        ?>
        <tr>
          <td><?php echo $fullName !== '' ? htmlspecialchars($fullName) : '<em>—</em>'; ?></td>
          <td><code style="background:var(--clr-bg);padding:2px 6px;border-radius:4px;font-size:.82rem;"><?php echo htmlspecialchars($u['LoginName']); ?></code></td>
          <td>
            <?php if (!empty($u['EMail'])): ?><div style="font-size:.85rem;"><?php echo htmlspecialchars($u['EMail']); ?></div><?php endif; ?>
            <?php if (!empty($u['Mobile'])): ?><div style="color:var(--clr-text-muted);font-size:.8rem;"><?php echo htmlspecialchars($u['Mobile']); ?></div><?php endif; ?>
          </td>
          <?php if ($isFullAdmin): ?><td><?php echo htmlspecialchars($u['InstituteName']); ?></td><?php endif; ?>
          <td><span class="badge <?php echo $active ? 'badge-pass' : 'badge-fail'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span></td>
          <td>
            <form method="post" action="" style="margin:0;"
                  onsubmit="return confirm('Reset password for <?php echo htmlspecialchars(addslashes($fullName), ENT_QUOTES); ?>? They will need to set a new password at next login.');">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
              <input type="hidden" name="user_id" value="<?php echo (int)$u['UserInfoId']; ?>">
              <button type="submit" name="btnReset" value="1" class="btn btn-sm" style="background:#dc2626;border-color:#dc2626;">
                &#128274; Reset Password
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div style="margin-top:8px;">
  <a href="<?php echo $isFullAdmin ? 'AdminUsers.php' : 'InstituteAdminHome.php'; ?>" class="btn btn-secondary btn-sm">&larr; Back</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
