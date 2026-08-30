<?php
/**
 * Admin/ChangeUserRole.php
 * Search users and change their role. Admin-only.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) {
    header('Location: Index.php');
    exit;
}

$msg = ''; $isErr = false;

// ── Handle role update ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnChangeRole'])) {
    Auth::validateCsrf();
    $loginInfoId = (int)($_POST['loginInfoId'] ?? 0);
    $newRole     = trim($_POST['newRole'] ?? '');

    if ($loginInfoId <= 0 || $newRole === '') {
        $msg = 'Invalid request.'; $isErr = true;
    } else {
        // Prevent demoting the only Admin
        if ($newRole !== 'Admin') {
            $adminCount = (int)(Database::fetchOne(
                "SELECT COUNT(*) AS cnt FROM logininfo WHERE Role='Admin' AND LoginInfoId != ?",
                [$loginInfoId])['cnt'] ?? 0);
            $isTargetAdmin = (Database::fetchOne(
                "SELECT Role FROM logininfo WHERE LoginInfoId=?", [$loginInfoId])['Role'] ?? '') === 'Admin';
            if ($isTargetAdmin && $adminCount === 0) {
                $msg = 'Cannot remove the only Admin account.'; $isErr = true;
            }
        }
        if (!$isErr) {
            Database::execute(
                "UPDATE logininfo SET Role = ? WHERE LoginInfoId = ?",
                [$newRole, $loginInfoId]);
            $msg = 'Role updated successfully.';

            // Institute Admin without an institute would see nothing (fail-safe-closed
            // scoping) — flag it so the admin knows to set one via Edit User.
            if ($newRole === 'INSTADMIN') {
                $hasInstitute = (bool)(Database::fetchOne(
                    "SELECT u.InstituteId FROM userinfo u
                       JOIN logininfo l ON l.LoginName = u.LoginName
                      WHERE l.LoginInfoId = ? AND u.InstituteId IS NOT NULL",
                    [$loginInfoId])['InstituteId'] ?? null);
                if (!$hasInstitute) {
                    $msg .= ' This account has no Institute set yet, so they won\'t see any students until you set one via Edit User.';
                }
            }
        }
    }
}

// ── Load roles for dropdown ─────────────────────────────────────────────────
// Same authoritative-list approach as Admin/SearchUser.php: the canonical
// roles Auth::resolveRoleFlags() actually understands come first, so this
// dropdown can't be corrupted by stale/duplicate/aliased rows sitting in the
// legacy `role` table. Any genuinely custom role from that table is still
// appended at the end.
$builtinRoles = [
    ['RoleNm' => 'Admin',     'RoleDesc' => 'Admin'],
    ['RoleNm' => 'INSTADMIN', 'RoleDesc' => 'Institute Admin'],
    ['RoleNm' => 'STDNT',     'RoleDesc' => 'Student'],
    ['RoleNm' => 'TEACH',     'RoleDesc' => 'Teacher'],
    ['RoleNm' => 'PRCIPAL',   'RoleDesc' => 'Principal'],
];
$roleAliases = [
    'ADMN'            => 'Admin',
    'PRINCIPAL'       => 'PRCIPAL',
    'STUDENT'         => 'STDNT',
    'INSTITUTE_ADMIN' => 'INSTADMIN',
    'INSTITUTEADMIN'  => 'INSTADMIN',
];

try {
    $legacyRoles = Database::fetchAll("SELECT RoleNm, RoleDesc FROM role ORDER BY RoleDesc");
} catch (Exception $e) {
    $legacyRoles = [];
}
$roles       = $builtinRoles;
$builtinSeen = array_map('strtoupper', array_column($builtinRoles, 'RoleNm'));
foreach ($legacyRoles as $lr) {
    $code = strtoupper($lr['RoleNm'] ?? '');
    if ($code === '' || in_array($code, $builtinSeen, true)) continue;
    if (isset($roleAliases[$code]) && in_array(strtoupper($roleAliases[$code]), $builtinSeen, true)) continue;
    $roles[] = $lr; // genuinely custom role — keep it available
}

// ── Search users ────────────────────────────────────────────────────────────
$searchResults = [];
$searched = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnSearch'])) {
    Auth::validateCsrf();
    $searched = true;
    $fname      = trim($_POST['searchFname'] ?? '');
    $lname      = trim($_POST['searchLname'] ?? '');
    $roleFilter = trim($_POST['searchRole']  ?? '');

    $where  = ['1=1'];
    $params = [];
    if ($fname      !== '') { $where[] = 'u.FstName LIKE ?'; $params[] = "%$fname%"; }
    if ($lname      !== '') { $where[] = 'u.LstName LIKE ?'; $params[] = "%$lname%"; }
    if ($roleFilter !== '') { $where[] = 'l.Role = ?';       $params[] = $roleFilter; }

    $searchResults = Database::fetchAll(
        "SELECT l.LoginInfoId, l.LoginName, l.Role, l.Active,
                u.FstName, u.LstName, u.EMail, u.Mobile
           FROM logininfo l
      LEFT JOIN userinfo u ON u.LoginName = l.LoginName
          WHERE " . implode(' AND ', $where) . "
          ORDER BY u.FstName, l.LoginName
          LIMIT 100",
        $params);
}

$pageTitle = 'Change User Role';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg !== ''): ?>
  <div class="alert <?php echo $isErr ? 'alert-danger' : 'alert-success'; ?>" style="margin-bottom:16px;">
    <?php echo $isErr ? '&#10006;' : '&#10004;'; ?> <?php echo htmlspecialchars($msg); ?>
  </div>
<?php endif; ?>

<!-- ── Search panel ─────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">&#128269; Search Users</div>
  <div class="card-body">
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <div class="form-row cols-3" style="align-items:flex-end;">
        <div class="form-group" style="margin-bottom:0;">
          <label>First Name</label>
          <input type="text" name="searchFname" class="form-control"
                 value="<?php echo htmlspecialchars($_POST['searchFname'] ?? ''); ?>"
                 placeholder="e.g. John">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Last Name</label>
          <input type="text" name="searchLname" class="form-control"
                 value="<?php echo htmlspecialchars($_POST['searchLname'] ?? ''); ?>"
                 placeholder="e.g. Smith">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Current Role</label>
          <select name="searchRole" class="form-control">
            <option value="">— All Roles —</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?php echo htmlspecialchars($r['RoleNm']); ?>"
                <?php echo (($_POST['searchRole'] ?? '') === $r['RoleNm']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($r['RoleDesc']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="span-full" style="margin-top:8px;display:flex;gap:8px;">
          <button type="submit" name="btnSearch" class="btn btn-primary">&#128269; Search</button>
          <a href="ChangeUserRole.php" class="btn btn-secondary">Clear</a>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Results ──────────────────────────────────────────────────────────── -->
<?php if ($searched): ?>
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#128101; Results</span>
    <?php if ($searchResults): ?>
      <span style="font-weight:400;font-size:.85rem;opacity:.85;"><?php echo count($searchResults); ?> found</span>
    <?php endif; ?>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if (!$searchResults): ?>
      <p style="padding:32px;text-align:center;color:var(--clr-text-muted);">
        No users found matching your search.
      </p>
    <?php else: ?>
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <input type="hidden" name="loginInfoId" value="">
      <input type="hidden" name="newRole"     value="">
      <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Name</th>
            <th>Username</th>
            <th>Email / Mobile</th>
            <th>Current Role</th>
            <th>Status</th>
            <th>New Role</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($searchResults as $u):
            $fullName   = trim($u['FstName'] . ' ' . $u['LstName']);
            $roleNm     = $u['Role'] ?? '';
            $roleUpper  = strtoupper($roleNm);
            if (in_array($roleUpper, ['ADMIN','ADMN','PRCIPAL','PRINCIPAL'])) {
                $badgeClass = 'badge-admin';
            } elseif ($roleUpper === 'STDNT') {
                $badgeClass = 'badge-student';
            } else {
                $badgeClass = '';
            }
            $lid = (int)$u['LoginInfoId'];
          ?>
          <tr>
            <td><?php echo $fullName !== '' ? htmlspecialchars($fullName) : '<em style="color:var(--clr-text-faint)">—</em>'; ?></td>
            <td><code style="background:var(--clr-bg);padding:2px 6px;border-radius:4px;font-size:.82rem;"><?php echo htmlspecialchars($u['LoginName']); ?></code></td>
            <td>
              <?php if (!empty($u['EMail'])): ?>
                <div style="font-size:.85rem;"><?php echo htmlspecialchars($u['EMail']); ?></div>
              <?php endif; ?>
              <?php if (!empty($u['Mobile'])): ?>
                <div style="color:var(--clr-text-muted);font-size:.8rem;">&#128241; <?php echo htmlspecialchars($u['Mobile']); ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?php echo $badgeClass; ?>"
                    style="<?php echo $badgeClass === '' ? 'background:var(--clr-bg);color:var(--clr-text-muted);' : ''; ?>">
                <?php echo htmlspecialchars($roleNm); ?>
              </span>
            </td>
            <td>
              <span class="badge <?php echo $u['Active'] === 'Y' ? 'badge-pass' : 'badge-fail'; ?>">
                <?php echo $u['Active'] === 'Y' ? 'Active' : 'Inactive'; ?>
              </span>
            </td>
            <td>
              <select name="newRole_<?php echo $lid; ?>" class="form-control" style="min-height:38px;padding:6px 10px;">
                <?php foreach ($roles as $r): ?>
                  <option value="<?php echo htmlspecialchars($r['RoleNm']); ?>"
                    <?php echo ($r['RoleNm'] === $roleNm) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($r['RoleDesc']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <button type="submit" name="btnChangeRole" value="1"
                      class="btn btn-primary btn-sm"
                      onclick="document.querySelector('[name=loginInfoId]').value='<?php echo $lid; ?>';
                               document.querySelector('[name=newRole]').value=document.querySelector('[name=newRole_<?php echo $lid; ?>]').value;
                               return true;">
                &#10003; Update
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div style="margin-top:8px;">
  <a href="Index.php" class="btn btn-secondary btn-sm">&larr; Back to Admin Home</a>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
