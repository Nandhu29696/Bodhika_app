<?php
/**
 * Admin/SearchUser.php
 * Search all users (students, teachers, principals, admins) by name,
 * role, and active status. Admin-only.
 *
 * Was broken: every page load ran mysql_query()/mysql_fetch_array()
 * (the old ext/mysql API, removed in PHP 7+), which fataled the moment
 * the Role dropdown tried to populate — that's why the page rendered
 * the form and then went blank with no Search button visible. The
 * search itself also spliced raw $_POST values into SQL (injectable)
 * and queried a separate student-only table set that duplicated
 * SearchStudent.php.
 *
 * Rewritten on the shared PDO Database layer with prepared statements,
 * GET-based filters (bookmarkable, paginate-friendly, no resubmit
 * warning), and the same header/footer + card/table/badge components
 * already used by Admin/ChangeUserRole.php and Admin/AdminUsers.php.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) {
    header('Location: Index.php');
    exit;
}

const PAGE_SIZE = 15;

$fname    = trim($_GET['fname'] ?? '');
$lname    = trim($_GET['lname'] ?? '');
$active   = $_GET['active'] ?? 'Y';       // '', 'Y', 'N' — defaults to "Yes"
$role     = $_GET['role']   ?? 'STDNT';   // '', or a logininfo.Role code — defaults to "Student"
$searched = isset($_GET['Search']);
$page     = max(1, (int)($_GET['page'] ?? 1));

// Role codes actually understood by Auth::resolveRoleFlags() — this list is
// authoritative and drives the dropdown, rather than the legacy `role` table
// (a holdover from the pre-PDO app, populated by a screen — Admin/RoleInfo.php
// / AddEditRoleInfo.php — that still uses the ext/mysql API removed in PHP 7
// and hasn't run successfully in years). That table's rows are stale,
// duplicated junk ('User', old casing, etc.) with no bearing on how the app
// actually authorizes anyone, which is why old/dead entries were showing up
// in this dropdown.
//
// Each canonical role can be stored under more than one literal spelling
// depending on when the account was created — Auth::resolveRoleFlags()
// already treats these as equivalent (isAdmin: ADMIN/ADMN/PRCIPAL/PRINCIPAL;
// isTeacher: anything containing TEACH). The search filter has to use the
// same equivalence groups, or picking a role from the dropdown silently
// excludes every account stored under an older variant — e.g. an admin
// whose row has Role='ADMN' would never show up when filtering on 'Admin'
// with a plain `=`. That mismatch is why Search returned nothing once a
// Role was selected.
$builtinRoles = [
    ['RoleNm' => 'STDNT',   'RoleDesc' => 'Student'],
    ['RoleNm' => 'TEACH',   'RoleDesc' => 'Teacher'],
    ['RoleNm' => 'PRCIPAL', 'RoleDesc' => 'Principal'],
    ['RoleNm' => 'Admin',   'RoleDesc' => 'Admin'],
];
$roleMatchGroups = [
    'STDNT'   => ['STDNT', 'STUDENT'],
    'PRCIPAL' => ['PRCIPAL', 'PRINCIPAL'],
    'Admin'   => ['Admin', 'ADMIN', 'ADMN'],
];
$roleRows = $builtinRoles;

/** Maps any known historical Role spelling back to its canonical display label. */
function roleLabel(array $roleRows, array $roleMatchGroups, string $code): string
{
    $codeUpper = strtoupper($code);
    if ($codeUpper === '') return '—';
    if (strpos($codeUpper, 'TEACH') !== false) return 'Teacher';
    foreach ($roleMatchGroups as $canonical => $variants) {
        if (in_array($codeUpper, array_map('strtoupper', $variants), true)) {
            foreach ($roleRows as $r) {
                if ($r['RoleNm'] === $canonical) return $r['RoleDesc'];
            }
        }
    }
    foreach ($roleRows as $r) {
        if (strtoupper($r['RoleNm']) === $codeUpper) return $r['RoleDesc'];
    }
    return $code; // unrecognized legacy value — show it as-is rather than hide it
}

$results = [];
$total   = 0;

if ($searched) {
    $where  = ['1=1'];
    $params = [];

    if ($fname !== '')  { $where[] = 'u.FstName LIKE ?'; $params[] = "%{$fname}%"; }
    if ($lname !== '')  { $where[] = 'u.LstName LIKE ?'; $params[] = "%{$lname}%"; }
    if ($active !== '') { $where[] = 'l.Active = ?';     $params[] = $active; }
    if ($role !== '') {
        if ($role === 'TEACH') {
            // Mirrors Auth::resolveRoleFlags()'s substring check — teacher
            // accounts have used more than one literal spelling over time.
            $where[] = "UPPER(l.Role) LIKE '%TEACH%'";
        } elseif (isset($roleMatchGroups[$role])) {
            $variants = $roleMatchGroups[$role];
            $ph = implode(',', array_fill(0, count($variants), '?'));
            $where[] = "l.Role IN ($ph)";
            foreach ($variants as $v) { $params[] = $v; }
        } else {
            $where[] = 'l.Role = ?';
            $params[] = $role;
        }
    }

    $whereSQL = implode(' AND ', $where);
    $baseSQL  = "FROM userinfo u JOIN logininfo l ON l.LoginName = u.LoginName WHERE {$whereSQL}";

    $total = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

    $offset  = ($page - 1) * PAGE_SIZE;
    $results = Database::fetchAll(
        "SELECT u.UserInfoId, u.FstName, u.LstName, u.Mobile, u.EMail, l.LoginName, l.Role, l.Active
           {$baseSQL}
          ORDER BY u.FstName, u.LstName
          LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

$pageTitle = 'Search User';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="card-header">&#128269; Search User</div>
  <div class="card-body">
    <form method="get" action="">
      <div class="form-row cols-3" style="align-items:flex-end;">
        <div class="form-group" style="margin-bottom:0;">
          <label>First Name</label>
          <input type="text" name="fname" class="form-control"
                 value="<?php echo htmlspecialchars($fname); ?>" placeholder="e.g. John">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Last Name</label>
          <input type="text" name="lname" class="form-control"
                 value="<?php echo htmlspecialchars($lname); ?>" placeholder="e.g. Smith">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Active</label>
          <select name="active" class="form-control">
            <option value=""  <?php echo $active === ''  ? 'selected' : ''; ?>>&mdash; All &mdash;</option>
            <option value="Y" <?php echo $active === 'Y' ? 'selected' : ''; ?>>Yes</option>
            <option value="N" <?php echo $active === 'N' ? 'selected' : ''; ?>>No</option>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Role</label>
          <select name="role" class="form-control">
            <option value="" <?php echo $role === '' ? 'selected' : ''; ?>>&mdash; All &mdash;</option>
            <?php foreach ($roleRows as $r): ?>
              <option value="<?php echo htmlspecialchars($r['RoleNm']); ?>"
                <?php echo $role === $r['RoleNm'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($r['RoleDesc']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="span-full" style="margin-top:8px;display:flex;gap:8px;">
          <button type="submit" name="Search" value="1" class="btn btn-primary">&#128269; Search</button>
          <a href="SearchUser.php" class="btn btn-secondary">Clear</a>
        </div>
      </div>
    </form>
  </div>
</div>

<?php if ($searched): ?>
<div class="card" style="margin-top:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#128101; Results</span>
    <?php if ($total > 0): ?>
      <span style="font-weight:400;font-size:.85rem;opacity:.85;"><?php echo number_format($total); ?> found</span>
    <?php endif; ?>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if (!$results): ?>
      <p style="padding:32px;text-align:center;color:var(--clr-text-muted);">
        No users found matching your search.
      </p>
    <?php else: ?>
      <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>First Name</th>
            <th>Last Name</th>
            <th>Mobile#</th>
            <th>Email</th>
            <th>Role</th>
            <th>Active</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $r):
              $roleCode   = $r['Role'] ?? '';
              $roleUpper  = strtoupper($roleCode);
              $badgeClass = in_array($roleUpper, ['ADMIN', 'ADMN', 'PRCIPAL', 'PRINCIPAL'], true) ? 'badge-admin'
                          : (in_array($roleUpper, ['STDNT', 'STUDENT'], true) ? 'badge-student' : '');
          ?>
          <tr>
            <td><?php echo htmlspecialchars($r['FstName'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['LstName'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['Mobile'] ?: '—'); ?></td>
            <td><?php echo htmlspecialchars($r['EMail'] ?: '—'); ?></td>
            <td>
              <span class="badge <?php echo $badgeClass; ?>"
                    style="<?php echo $badgeClass === '' ? 'background:var(--clr-bg);color:var(--clr-text-muted);' : ''; ?>">
                <?php echo htmlspecialchars(roleLabel($roleRows, $roleMatchGroups, $roleCode)); ?>
              </span>
            </td>
            <td>
              <span class="badge <?php echo $r['Active'] === 'Y' ? 'badge-pass' : 'badge-fail'; ?>">
                <?php echo $r['Active'] === 'Y' ? 'Yes' : 'No'; ?>
              </span>
            </td>
            <td>
              <a href="EditUser.php?id=<?php echo (int)$r['UserInfoId']; ?>"
                 class="btn btn-secondary btn-sm">&#9999;&#65039; Edit</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php
        $pages = (int)ceil($total / PAGE_SIZE);
        if ($pages > 1):
            $qs = $_GET;
      ?>
        <div style="padding:12px 16px;display:flex;gap:6px;flex-wrap:wrap;">
          <?php for ($i = 1; $i <= $pages; $i++):
              $qs['page'] = $i;
              $url = '?' . http_build_query($qs);
          ?>
            <a href="<?php echo htmlspecialchars($url); ?>"
               class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $i; ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div style="margin-top:8px;">
  <a href="Index.php" class="btn btn-secondary btn-sm">&larr; Back to Admin Home</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
