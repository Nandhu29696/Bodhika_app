<?php
/**
 * Admin/BulkAssignInstitute.php — link many existing students to an
 * institute in one action, instead of opening EditUser.php per student.
 *
 * Flow (mirrors GenerateCertificates.php's two-step pattern):
 *   1. "Currently assigned to" filter (GET, auto-reloads) narrows the
 *      roster below — defaults to Unassigned Only, since that's the
 *      normal reason to reach for this tool.
 *   2. Pick students (search + multi-select) and the target institute,
 *      then POST applies userinfo.InstituteId to every selected student
 *      in a single query.
 *
 * Any pending 'InstituteId' change request for an affected student is
 * auto-approved, same as EditUser.php's single-user save action — an
 * admin setting the institute directly should resolve that request
 * rather than leave it stranded as pending.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/index.php'); exit; }

// Results depend entirely on the query string — never serve a stale cached page.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$adminId = Auth::currentUserId();
$flash   = '';

/* ── Filter: which students to show below ─────────────────────────────────
   'unassigned' (default) | 'all' | a positive InstituteId (reassign from) */
$showRaw = $_REQUEST['show'] ?? 'unassigned';
$show    = ($showRaw === 'all' || $showRaw === 'unassigned') ? $showRaw : (string)(int)$showRaw;
if ($show !== 'all' && $show !== 'unassigned' && (int)$show <= 0) $show = 'unassigned';

/* ── Pagination helpers (mirrors AdminUsers.php / StudentGroupMembers.php) ── */
const PAGE_SIZE = 25;
function currentPage(string $key): int {
    return max(1, (int)($_GET[$key] ?? 1));
}
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

$search = trim($_GET['q'] ?? '');
$page   = currentPage('p');

/* ── Handle POST — bulk-assign ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();

    $targetInstituteId = (int)($_POST['TargetInstituteId'] ?? 0);
    $userIds = array_values(array_unique(array_filter(
        array_map('intval', $_POST['user_ids'] ?? []),
        fn($id) => $id > 0
    )));

    $target = $targetInstituteId > 0
        ? Database::fetchOne("SELECT InstituteId, InstituteName FROM institutes WHERE InstituteId = ? LIMIT 1", [$targetInstituteId])
        : null;

    if (!$target) {
        $flash = 'error|Please choose an institute to assign.';
    } elseif (!$userIds) {
        $flash = 'error|Please select at least one student.';
    } else {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        Database::execute(
            "UPDATE userinfo SET InstituteId = ? WHERE UserInfoId IN ($placeholders)",
            array_merge([$targetInstituteId], $userIds)
        );

        // Auto-approve any pending InstituteId change request for these students —
        // an admin direct-assign should settle it rather than leave it stranded.
        Database::execute(
            "UPDATE user_change_requests
                SET Status='approved', ReviewedBy=?, ReviewedAt=NOW(),
                    AdminNote='Auto-approved by bulk institute assignment'
              WHERE FieldName='InstituteId' AND Status='pending' AND UserId IN ($placeholders)",
            array_merge([$adminId], $userIds)
        );

        $flash = 'success|Linked ' . count($userIds) . ' student(s) to "' . $target['InstituteName'] . '".';
    }

    $backPage = max(1, (int)($_POST['p'] ?? 1));
    header('Location: BulkAssignInstitute.php?show=' . urlencode($show)
        . '&q=' . urlencode($_POST['q'] ?? '') . '&p=' . $backPage
        . '&flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = urldecode($_GET['flash']);
[$flashType, $flashMsg] = $flash ? explode('|', $flash, 2) : ['', ''];

/* ── Dropdown data ───────────────────────────────────────────────────────── */
$institutes = Database::fetchAll("SELECT InstituteId, InstituteName FROM institutes WHERE Active='Y' ORDER BY InstituteName");

/* ── Student roster, filtered by $show + search, server-side paginated ──────
   (Was a single unbounded fetchAll rendered into the DOM and filtered with
   JS on every keystroke — with 1600+ students that meant shipping the whole
   roster on every page load. Now it's a real WHERE + LIMIT.) */
$where  = ["l.Role = 'STDNT'"];
$params = [];
if ($show === 'unassigned') {
    $where[] = 'u.InstituteId IS NULL';
} elseif ($show !== 'all') {
    $where[]  = 'u.InstituteId = ?';
    $params[] = (int)$show;
}
if ($search !== '') {
    $where[]  = '(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)';
    $like     = "%{$search}%";
    array_push($params, $like, $like, $like);
}
$whereSQL = implode(' AND ', $where);
$baseSQL  = "FROM userinfo  u
        LEFT JOIN logininfo  l    ON l.LoginName   = u.LoginName
        LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
       WHERE {$whereSQL}";

$studentCount = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

$offset   = ($page - 1) * PAGE_SIZE;
$students = Database::fetchAll(
    "SELECT u.UserInfoId, u.FstName, u.LstName, u.LoginName,
            COALESCE(inst.InstituteName, '') AS CurrentInstitute
     {$baseSQL}
     ORDER BY u.FstName, u.LstName
     LIMIT {$offset}, " . PAGE_SIZE,
    $params
);

$qsRoster = array_filter(['show' => $show, 'q' => $search]);

$pageTitle = 'Bulk Assign Institute';
$pageHead  = '<style>
  .bai-student-row{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid #e2e8f0;}
  .bai-student-row:hover{background:#f7fafc;}
  .bai-student-row:last-child{border-bottom:none;}
  .bai-chip{padding:2px 10px;border-radius:10px;font-size:.75rem;font-weight:600;background:#edf2f7;color:#4a5568;white-space:nowrap;}
  .bai-chip-unassigned{background:#fef3c7;color:#92400e;}
  .pager        { margin:10px 16px; font-size:12px; display:flex; flex-wrap:wrap; gap:4px; }
  .pager-link   { display:inline-block; padding:3px 10px; border:1px solid #cbd5e1; border-radius:4px;
                   text-decoration:none; color:#475569; }
  .pager-link:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
  .pager-active { display:inline-block; padding:3px 10px; border-radius:4px;
                   background:var(--clr-primary); color:#fff; border:1px solid var(--clr-primary); }
</style>';
include __DIR__ . '/../includes/header.php';
?>

<nav style="font-size:.85rem;color:#718096;margin-bottom:14px;">
  <a href="AdminUsers.php?tab=students" style="color:#3182ce;text-decoration:none;">&#128101; Users</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <span>Bulk Assign Institute</span>
</nav>

<?php if ($flashMsg): ?>
<div class="alert alert-<?= $flashType==='success' ? 'success' : 'danger' ?>" style="margin-bottom:14px;">
  <?= htmlspecialchars($flashMsg) ?>
</div>
<?php endif; ?>

<!-- ── Step 1: which students to show (GET, auto-reloads) ─────────────────── -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-header">&#128269; 1. Show Students</div>
  <div class="card-body">
    <form method="get" id="showForm" class="form-row cols-3">
      <div class="form-group">
        <label class="form-label">Currently Assigned To</label>
        <select class="form-control" name="show" onchange="this.form.submit()">
          <option value="unassigned" <?= $show==='unassigned'?'selected':'' ?>>&#9888; Unassigned Only</option>
          <option value="all" <?= $show==='all'?'selected':'' ?>>— All Students —</option>
          <?php foreach ($institutes as $inst): ?>
            <option value="<?= (int)$inst['InstituteId'] ?>" <?= $show===(string)(int)$inst['InstituteId']?'selected':'' ?>>
              Currently in: <?= htmlspecialchars($inst['InstituteName']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Search</label>
        <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name or login…">
      </div>
      <div class="form-group" style="align-self:flex-end;">
        <button type="submit" class="btn btn-secondary btn-sm">Search</button>
        <?php if ($search !== ''): ?>
          <a href="?show=<?= urlencode($show) ?>" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
      </div>
    </form>
    <div class="alert alert-warning" style="margin-top:12px;margin-bottom:0;">
      &#9888; "Unassigned Only" shows students with no institute on record yet — the usual starting point when backfilling existing accounts.
    </div>
  </div>
</div>

<!-- ── Step 2: pick students + target institute (POST) ─────────────────────── -->
<form method="post" id="assignForm">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
  <input type="hidden" name="show" value="<?= htmlspecialchars($show) ?>">
  <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
  <input type="hidden" name="p" value="<?= $page ?>">

  <div class="card" style="margin-bottom:16px;">
    <div class="card-header">&#127982; 2. Assign To</div>
    <div class="card-body">
      <div class="form-row cols-3">
        <div class="form-group">
          <label class="form-label">Institute</label>
          <select class="form-control" name="TargetInstituteId" required>
            <option value="">— Select institute —</option>
            <?php foreach ($institutes as $inst): ?>
              <option value="<?= (int)$inst['InstituteId'] ?>"><?= htmlspecialchars($inst['InstituteName']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php if (!$institutes): ?>
        <div class="alert alert-warning" style="margin-top:12px;margin-bottom:0;">
          No institutes yet. <a href="ManageInstitutes.php">Create one first &rarr;</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span>&#128101; 3. Select Students</span>
      <span style="font-size:.85rem;color:#718096;"><span id="selCount">0</span> selected</span>
    </div>

    <div style="padding:10px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#f7fafc;">
      <button type="button" onclick="selectAll(true)"  class="btn btn-secondary btn-sm">Select All (this page)</button>
      <button type="button" onclick="clearAllSelections()" class="btn btn-secondary btn-sm">Clear Selection</button>
    </div>

    <?php if ($search !== ''): ?>
      <div style="padding:8px 16px 0;font-size:.8rem;color:#718096;">Found <?= $studentCount ?> student(s) matching “<?= htmlspecialchars($search) ?>”.</div>
    <?php endif; ?>

    <?php if (!$students): ?>
      <div style="padding:30px;text-align:center;color:#718096;">
        No students match this filter.
      </div>
    <?php else: ?>
    <div id="studentList">
      <?php foreach ($students as $st):
        $fullName = trim($st['FstName'] . ' ' . $st['LstName']);
        $uid = (int)$st['UserInfoId'];
      ?>
      <div class="bai-student-row student-item">
        <input type="checkbox" name="user_ids[]" value="<?= $uid ?>" id="uid<?= $uid ?>" onchange="toggleSelected(this.value, this.checked)"
               style="transform:scale(1.3);accent-color:#3182ce;flex-shrink:0;">
        <label for="uid<?= $uid ?>" style="cursor:pointer;flex:1;display:flex;align-items:center;justify-content:space-between;gap:12px;">
          <div>
            <div class="user-name"><?= htmlspecialchars($fullName) ?></div>
            <div class="user-meta"><?= htmlspecialchars($st['LoginName'] ?? '') ?></div>
          </div>
          <span class="bai-chip<?= $st['CurrentInstitute']===''?' bai-chip-unassigned':'' ?>">
            <?= $st['CurrentInstitute'] !== '' ? htmlspecialchars($st['CurrentInstitute']) : 'Unassigned' ?>
          </span>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
    <?= paginator($studentCount, $page, PAGE_SIZE, $qsRoster, 'p') ?>
    <div style="padding:14px 16px;border-top:1px solid #e2e8f0;background:#f7fafc;">
      <button type="submit" class="btn btn-success" style="font-weight:700;">
        &#128279; Link Selected Students
      </button>
    </div>
    <?php endif; ?>
  </div>
</form>

<script>
// Selections persist across search/pagination (sessionStorage) so paging through
// the roster never silently drops a partial pick — same pattern as
// StudentGroupMembers.php's Add Students tab.
var BAI_KEY = 'bai_selected_students';

function loadSelected() {
  try { return JSON.parse(sessionStorage.getItem(BAI_KEY) || '[]'); } catch (e) { return []; }
}
function saveSelected(ids) { sessionStorage.setItem(BAI_KEY, JSON.stringify(ids)); }

function toggleSelected(uid, checked) {
  uid = String(uid);
  var ids = loadSelected();
  if (checked) {
    if (ids.indexOf(uid) === -1) ids.push(uid);
  } else {
    ids = ids.filter(function (id) { return id !== uid; });
  }
  saveSelected(ids);
  updateCount();
}
function updateCount() {
  var el = document.getElementById('selCount');
  if (el) el.textContent = loadSelected().length;
}
function selectAll(checked) {
  document.querySelectorAll('#studentList input[type=checkbox]').forEach(function (cb) {
    cb.checked = checked;
    toggleSelected(cb.value, checked);
  });
}
function clearAllSelections() {
  saveSelected([]);
  document.querySelectorAll('#studentList input[type=checkbox]').forEach(function (cb) { cb.checked = false; });
  updateCount();
}
function restoreSelections() {
  var ids = loadSelected();
  document.querySelectorAll('#studentList input[type=checkbox]').forEach(function (cb) {
    if (ids.indexOf(cb.value) !== -1) cb.checked = true;
  });
  updateCount();
}

<?php if ($flashType === 'success'): ?>
sessionStorage.removeItem(BAI_KEY);
<?php endif; ?>

document.addEventListener('DOMContentLoaded', restoreSelections);

var assignForm = document.getElementById('assignForm');
if (assignForm) {
  assignForm.addEventListener('submit', function () {
    var ids = loadSelected();
    var visible = new Set(Array.prototype.map.call(
      document.querySelectorAll('#studentList input[type=checkbox]'), function (cb) { return cb.value; }));
    var form = this;
    ids.forEach(function (id) {
      if (!visible.has(id)) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'user_ids[]';
        inp.value = id;
        form.appendChild(inp);
      }
    });
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
