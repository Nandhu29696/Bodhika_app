<?php
/**
 * AdminUsers.php
 * Admin-only page: Registered Students + Login Activity
 * with search/filter by name, date range, and subject.
 *
 * Requires: PHP 7.4+, PDO (Database class), Auth class.
 * All queries use prepared statements — no SQL injection risk.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) {
    header('Location: ../exam/index.php');
    exit;
}

// ── Active tab ────────────────────────────────────────────────────────────────
$tab = ($_GET['tab'] ?? 'students') === 'logins' ? 'logins' : 'students';

// ── Shared filter helpers ─────────────────────────────────────────────────────
function safeStr(?string $v): string { return trim($v ?? ''); }
function safeDate(?string $v): string {
    if (!$v) return '';
    $d = DateTime::createFromFormat('Y-m-d', trim($v));
    return $d ? $d->format('Y-m-d') : '';
}

// ── Pagination constant ───────────────────────────────────────────────────────
const PAGE_SIZE = 25;
function currentPage(string $key = 'p'): int {
    return max(1, (int)($_GET[$key] ?? 1));
}

// ═════════════════════════════════════════════════════════════════════════════
// TAB 1 — Registered Students
// ═════════════════════════════════════════════════════════════════════════════
$students     = [];
$studentCount = 0;
$studentPage  = currentPage('sp');

// Load subject list for dropdown
$subjects = Database::fetchAll(
    "SELECT SubjectInfoId, SubjectName FROM subjectinfo WHERE Active='Y' ORDER BY SubjectName"
);

if ($tab === 'students') {
    // Filter inputs
    $sf_name    = safeStr($_GET['sf_name']    ?? '');
    $sf_from    = safeDate($_GET['sf_from']   ?? '');
    $sf_to      = safeDate($_GET['sf_to']     ?? '');
    $sf_subject = (int)($_GET['sf_subject']   ?? 0);

    // Build WHERE clauses + params
    $where  = ["1=1"];
    $params = [];

    if ($sf_name !== '') {
        $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
        $like     = "%{$sf_name}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($sf_from !== '') {
        $where[]  = "EXISTS (SELECT 1 FROM logintrackinfo _lt WHERE _lt.UserId = u.UserInfoId AND DATE(_lt.CreateDtm) >= ?)";
        $params[] = $sf_from;
    }
    if ($sf_to !== '') {
        $where[]  = "EXISTS (SELECT 1 FROM logintrackinfo _lt WHERE _lt.UserId = u.UserInfoId AND DATE(_lt.CreateDtm) <= ?)";
        $params[] = $sf_to;
    }
    if ($sf_subject > 0) {
        $where[]  = "ep.SubjectInfoId = ?";
        $params[] = $sf_subject;
    }

    $whereSQL = implode(' AND ', $where);

    $baseSQL = "FROM userinfo u
                LEFT JOIN logininfo l    ON l.LoginName   = u.LoginName
                LEFT JOIN enrollment_payments ep ON ep.UserInfoId = u.UserInfoId
                LEFT JOIN subjectinfo   s    ON s.SubjectInfoId   = ep.SubjectInfoId
                WHERE l.Role = 'STDNT' AND {$whereSQL}";

    // Count
    $countRow     = Database::fetchOne("SELECT COUNT(DISTINCT u.UserInfoId) AS cnt {$baseSQL}", $params);
    $studentCount = (int)($countRow['cnt'] ?? 0);

    // Fetch page
    $offset  = ($studentPage - 1) * PAGE_SIZE;
    $students = Database::fetchAll(
        "SELECT DISTINCT
                u.UserInfoId,
                u.FstName,
                u.LstName,
                u.LoginName,
                u.Mobile,
                u.EMail,
                l.Active,
                GROUP_CONCAT(DISTINCT s.SubjectName ORDER BY s.SubjectName SEPARATOR ', ') AS Subjects,
                (SELECT MIN(lt2.CreateDtm)
                   FROM logintrackinfo lt2
                  WHERE lt2.UserId = u.UserInfoId) AS RegisteredAt
         {$baseSQL}
         GROUP BY u.UserInfoId
         ORDER BY RegisteredAt DESC, u.UserInfoId DESC
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

// ═════════════════════════════════════════════════════════════════════════════
// TAB 2 — Login Activity
// ═════════════════════════════════════════════════════════════════════════════
$logins     = [];
$loginCount = 0;
$loginPage  = currentPage('lp');

if ($tab === 'logins') {
    // Filter inputs
    $lf_name = safeStr($_GET['lf_name'] ?? '');
    $lf_role = safeStr($_GET['lf_role'] ?? '');
    $lf_from = safeDate($_GET['lf_from'] ?? '');
    $lf_to   = safeDate($_GET['lf_to']   ?? '');

    // No super-admin exclusion here — show all login events including admin.
    $where  = ["1=1"];
    $params = [];

    if ($lf_name !== '') {
        // Search name columns OR the stored LoginName (covers users with no userinfo row)
        $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR lt.LoginName LIKE ?)";
        $like     = "%{$lf_name}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($lf_role !== '') {
        $where[]  = "li.Role LIKE ?";
        $params[] = "%{$lf_role}%";
    }
    if ($lf_from !== '') {
        $where[]  = "DATE(lt.CreateDtm) >= ?";
        $params[] = $lf_from;
    }
    if ($lf_to !== '') {
        $where[]  = "DATE(lt.CreateDtm) <= ?";
        $params[] = $lf_to;
    }

    $whereSQL = implode(' AND ', $where);

    // LEFT JOIN so rows with no matching userinfo (e.g. admin-only accounts) still appear.
    // lt.UserId is positive for normal users (= userinfo.UserInfoId),
    // negative for admin/legacy accounts that have no userinfo row.
    $baseSQL = "FROM logintrackinfo lt
                LEFT JOIN userinfo  u  ON u.UserInfoId = lt.UserId AND lt.UserId > 0
                LEFT JOIN logininfo li ON li.LoginName = lt.LoginName
                WHERE {$whereSQL}";

    $countRow   = Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params);
    $loginCount = (int)($countRow['cnt'] ?? 0);

    $offset = ($loginPage - 1) * PAGE_SIZE;
    $logins = Database::fetchAll(
        "SELECT lt.UserId,
                lt.LoginName AS TrackLogin,
                COALESCE(u.FstName, '')   AS FstName,
                COALESCE(u.LstName, '')   AS LstName,
                COALESCE(u.EMail,   '')   AS EMail,
                COALESCE(li.Role,  '—')  AS RoleDesc,
                lt.CreateDtm AS LoginAt
         {$baseSQL}
         ORDER BY lt.CreateDtm DESC
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

// ── Role list for login filter dropdown (from logininfo.Role string column) ───
$roles = Database::fetchAll(
    "SELECT DISTINCT Role AS RoleDesc FROM logininfo WHERE Role IS NOT NULL AND Role != '' ORDER BY Role");

// ── Pagination helper ─────────────────────────────────────────────────────────
function paginator(int $total, int $current, int $pageSize, array $qs, string $pageKey): string {
    if ($total <= $pageSize) return '';
    $pages = (int)ceil($total / $pageSize);
    $html  = '<div class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $q    = array_merge($qs, [$pageKey => $i]);
        $url  = '?' . http_build_query($q);
        $cls  = $i === $current ? 'pager-active' : 'pager-link';
        $html .= "<a href=\"{$url}\" class=\"{$cls}\">{$i}</a> ";
    }
    return $html . '</div>';
}

// Build current querystring for paginators (preserves filters)
$currentQS_students = array_filter([
    'tab'        => 'students',
    'sf_name'    => $_GET['sf_name']    ?? '',
    'sf_from'    => $_GET['sf_from']    ?? '',
    'sf_to'      => $_GET['sf_to']      ?? '',
    'sf_subject' => $_GET['sf_subject'] ?? '',
]);
$currentQS_logins = array_filter([
    'tab'     => 'logins',
    'lf_name' => $_GET['lf_name'] ?? '',
    'lf_role' => $_GET['lf_role'] ?? '',
    'lf_from' => $_GET['lf_from'] ?? '',
    'lf_to'   => $_GET['lf_to']   ?? '',
]);

$pageTitle = 'Users & Students';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Page wrapper ── */
.au-wrap { max-width:1100px; margin:28px auto; padding:0 16px; }

/* ── Page title ── */
.au-page-title { font-size:1.3rem; font-weight:700; color:var(--clr-primary);
                 margin:0 0 16px; display:flex; align-items:center; gap:8px; }

/* ── Tab navigation ── */
.au-tabs       { margin: 0 0 16px; border-bottom: 2px solid var(--clr-primary); display:flex; gap:4px; }
.au-tab        { display:inline-block; padding:8px 22px; cursor:pointer;
                 background:#f1f5f9; border:1px solid #cbd5e1; border-bottom:none;
                 border-radius:6px 6px 0 0;
                 font-weight:600; font-size:13px; text-decoration:none; color:#475569; }
.au-tab.active { background:var(--clr-primary); color:#fff; border-color:var(--clr-primary); }
.au-tab:hover:not(.active) { background:#e2e8f0; color:var(--clr-primary); }

/* ── Search / filter bar ── */
.au-search { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;
             padding:12px 16px; margin-bottom:14px; }
.au-search form { display:flex; flex-wrap:wrap; align-items:center; gap:10px; }
.au-search label { font-size:12px; font-weight:600; color:#64748b; white-space:nowrap; }
.au-search input[type=text],
.au-search input[type=date],
.au-search select {
    height:32px; border:1px solid #cbd5e1; border-radius:5px;
    font-size:12px; padding:0 8px; color:#1e293b; background:#fff; }
.au-search input[type=text]:focus,
.au-search input[type=date]:focus,
.au-search select:focus { outline:none; border-color:var(--clr-primary); box-shadow:0 0 0 2px rgba(79,70,229,.15); }
.au-search .btn-search {
    background:var(--clr-gold); color:#fff; border:none; padding:6px 18px;
    border-radius:5px; cursor:pointer; font-size:13px; font-weight:600; }
.au-search .btn-search:hover { filter:brightness(1.1); }
.au-search a.reset-link { font-size:12px; color:#64748b; text-decoration:none; }
.au-search a.reset-link:hover { color:var(--clr-primary); }

/* ── Results table ── */
.au-table     { width:100%; border-collapse:collapse; margin-top:4px;
                font-size:13px; border-radius:8px; overflow:hidden;
                box-shadow:0 1px 4px rgba(0,0,0,.08); }
.au-table th  { background:var(--clr-primary); color:#fff; padding:9px 12px;
                font-size:12px; text-align:left; white-space:nowrap; }
.au-table td  { padding:8px 12px; border-bottom:1px solid #f1f5f9; color:#1e293b; }
.au-table tr.odd  td { background:#fff; }
.au-table tr.even td { background:#f8fafc; }
.au-table tr:hover td { background:#eff6ff; }

/* ── Badges ── */
.badge        { display:inline-block; padding:2px 9px; border-radius:12px;
                font-size:11px; font-weight:700; }
.badge-y      { background:#dcfce7; color:#15803d; }
.badge-n      { background:#fee2e2; color:#b91c1c; }

/* ── Pager ── */
.pager        { margin:10px 0; font-size:12px; display:flex; flex-wrap:wrap; gap:4px; }
.pager-link   { display:inline-block; padding:3px 10px;
                border:1px solid #cbd5e1; border-radius:4px;
                text-decoration:none; color:#475569; }
.pager-link:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
.pager-active { display:inline-block; padding:3px 10px; border-radius:4px;
                background:var(--clr-primary); color:#fff; border:1px solid var(--clr-primary); }

/* ── Count badge ── */
.result-count { font-size:12px; color:#64748b; margin-bottom:8px; }
</style>

<div class="au-wrap">
<div class="au-page-title">&#x1F465; Users &amp; Students</div>

<!-- ── Tab bar ──────────────────────────────────────────────────────────── -->
<div class="au-tabs">
    <a href="?tab=students" class="au-tab <?= $tab==='students'?'active':'' ?>">
        &#x1F393; Registered Students
    </a>
    <a href="?tab=logins" class="au-tab <?= $tab==='logins'?'active':'' ?>">
        &#x1F511; Login Activity
    </a>
</div>

<?php if ($tab === 'students'): ?>
<!-- ════════════════════════════════════════════════════════════════════════
     TAB 1 — Registered Students
     ════════════════════════════════════════════════════════════════════════ -->

<div class="au-search">
<form method="get" action="">
  <input type="hidden" name="tab" value="students">
  <label>Name / Username</label>
  <input type="text" name="sf_name" value="<?= htmlspecialchars($sf_name) ?>" placeholder="Search…" style="width:160px">
  <label>Activity From</label>
  <input type="date" name="sf_from" value="<?= htmlspecialchars($sf_from) ?>">
  <label>To</label>
  <input type="date" name="sf_to" value="<?= htmlspecialchars($sf_to) ?>">
  <label>Subject</label>
  <select name="sf_subject">
    <option value="0">— All Subjects —</option>
    <?php foreach ($subjects as $s): ?>
      <option value="<?= (int)$s['SubjectInfoId'] ?>" <?= $sf_subject===(int)$s['SubjectInfoId']?'selected':'' ?>>
        <?= htmlspecialchars($s['SubjectName']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn-search">Search</button>
  <a href="?tab=students" class="reset-link">Reset</a>
</form>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
  <div class="result-count"><?= number_format($studentCount) ?> student<?= $studentCount===1?'':'s' ?> found</div>
  <a href="../exam/export-excel.php?type=students&<?= http_build_query(array_filter([
      'sf_name'    => $sf_name,
      'sf_from'    => $sf_from,
      'sf_to'      => $sf_to,
      'sf_subject' => $sf_subject ?: '',
  ])) ?>" style="display:inline-flex;align-items:center;gap:6px;background:#16a34a;color:#fff;
      padding:6px 14px;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;">
    &#x1F4E5; Export to Excel
  </a>
</div>

<?php if ($students): ?>
<?= paginator($studentCount, $studentPage, PAGE_SIZE, $currentQS_students, 'sp') ?>
<table class="au-table">
  <thead>
    <tr>
      <th>#</th>
      <th>Name</th>
      <th>Username</th>
      <th>Email</th>
      <th>Mobile</th>
      <th>Subjects Enrolled</th>
      <th>First Seen</th>
      <th>Active</th>
    </tr>
  </thead>
  <tbody>
    <?php
    $rowNum = ($studentPage - 1) * PAGE_SIZE + 1;
    foreach ($students as $i => $r):
        $rowClass = $i % 2 === 0 ? 'odd' : 'even';
        $name     = htmlspecialchars(trim($r['FstName'] . ' ' . $r['LstName']));
        $uname    = htmlspecialchars($r['LoginName'] ?? '');
        $email    = htmlspecialchars($r['EMail']    ?? '');
        $mobile   = htmlspecialchars($r['Mobile']   ?? '');
        $subjects = htmlspecialchars($r['Subjects'] ?? '—');
        $active   = ($r['Active'] ?? 'N') === 'Y';
        $regDate  = !empty($r['RegisteredAt'])
                    ? date('d M Y H:i', strtotime($r['RegisteredAt'])) : '—';
    ?>
    <tr class="<?= $rowClass ?>">
      <td><?= $rowNum++ ?></td>
      <td><?= $name ?></td>
      <td><?= $uname ?></td>
      <td><?= $email ?></td>
      <td><?= $mobile ?: '—' ?></td>
      <td><?= $subjects ?></td>
      <td style="white-space:nowrap;font-size:11px;"><?= $regDate ?></td>
      <td><span class="badge <?= $active?'badge-y':'badge-n' ?>"><?= $active?'Yes':'No' ?></span></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?= paginator($studentCount, $studentPage, PAGE_SIZE, $currentQS_students, 'sp') ?>

<?php else: ?>
  <p style="color:#888;font-size:13px;">No students match the current filters.</p>
<?php endif; ?>


<?php else: // tab === 'logins' ?>
<!-- ════════════════════════════════════════════════════════════════════════
     TAB 2 — Login Activity
     ════════════════════════════════════════════════════════════════════════ -->

<div class="au-search">
<form method="get" action="">
  <input type="hidden" name="tab" value="logins">
  <label>Name</label>
  <input type="text" name="lf_name" value="<?= htmlspecialchars($lf_name) ?>" placeholder="Search…" style="width:150px">
  <label>Role</label>
  <select name="lf_role">
    <option value="">— All Roles —</option>
    <?php foreach ($roles as $r): ?>
      <option value="<?= htmlspecialchars($r['RoleDesc']) ?>" <?= $lf_role===trim($r['RoleDesc'])?'selected':'' ?>>
        <?= htmlspecialchars($r['RoleDesc']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <label>Login From</label>
  <input type="date" name="lf_from" value="<?= htmlspecialchars($lf_from) ?>">
  <label>To</label>
  <input type="date" name="lf_to" value="<?= htmlspecialchars($lf_to) ?>">
  <button type="submit" class="btn-search">Search</button>
  <a href="?tab=logins" class="reset-link">Reset</a>
</form>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
  <div class="result-count"><?= number_format($loginCount) ?> login event<?= $loginCount===1?'':'s' ?> found</div>
  <a href="../exam/export-excel.php?type=logins&<?= http_build_query(array_filter([
      'lf_name' => $lf_name,
      'lf_role' => $lf_role,
      'lf_from' => $lf_from,
      'lf_to'   => $lf_to,
  ])) ?>" style="display:inline-flex;align-items:center;gap:6px;background:#16a34a;color:#fff;
      padding:6px 14px;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;">
    &#x1F4E5; Export to Excel
  </a>
</div>

<?php if ($logins): ?>
<?= paginator($loginCount, $loginPage, PAGE_SIZE, $currentQS_logins, 'lp') ?>
<table class="au-table">
  <thead>
    <tr>
      <th>#</th>
      <th>Name</th>
      <th>Email</th>
      <th>Role</th>
      <th>Login Date &amp; Time</th>
    </tr>
  </thead>
  <tbody>
    <?php
    $rowNum = ($loginPage - 1) * PAGE_SIZE + 1;
    foreach ($logins as $i => $r):
        $rowClass  = $i % 2 === 0 ? 'odd' : 'even';
        $fullName  = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
        // Fall back to the stored LoginName when no userinfo row exists
        $name      = htmlspecialchars($fullName !== '' ? $fullName : ($r['TrackLogin'] ?? '—'));
        $email     = htmlspecialchars($r['EMail']    ?? '');
        $role      = htmlspecialchars($r['RoleDesc'] ?? '');
        $loginAt   = !empty($r['LoginAt']) ? date('d M Y, H:i', strtotime($r['LoginAt'])) : '—';
    ?>
    <tr class="<?= $rowClass ?>">
      <td><?= $rowNum++ ?></td>
      <td><?= $name ?></td>
      <td><?= $email ?></td>
      <td><?= $role ?></td>
      <td><?= $loginAt ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?= paginator($loginCount, $loginPage, PAGE_SIZE, $currentQS_logins, 'lp') ?>

<?php else: ?>
  <p style="color:#888;font-size:13px;">No login events match the current filters.</p>
<?php endif; ?>

<?php endif; // end tabs ?>

</div><!-- /.au-wrap -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
