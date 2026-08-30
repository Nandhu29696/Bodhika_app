<?php
/**
 * Admin/StudentGroupExams.php — bulk assign/unassign exams to one student
 * group. This does NOT grant free access (see Lib/StudentGroup.php's
 * docblock) — it only tags an exam as "Recommended" for the group; every
 * exam is already open for any student to browse and self-enroll in.
 *
 * Layout mirrors StudentGroupMembers.php: "Currently Assigned" and "Assign
 * Exams" are side-by-side tabs instead of stacked cards, both server-side
 * searched + paginated (PAGE_SIZE/currentPage()/paginator() — the same
 * house convention as AdminUsers.php) rather than fetchAll-everything +
 * client-side JS filtering.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/StudentGroup.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$gid = filter_input(INPUT_GET, 'gid', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'gid', FILTER_VALIDATE_INT);
if (!$gid) { header('Location: StudentGroups.php'); exit; }

$group = Database::fetchOne("SELECT * FROM student_groups WHERE StudentGroupId = ? LIMIT 1", [$gid]);
if (!$group) { header('Location: StudentGroups.php'); exit; }

$adminId = Auth::currentUser() ?: 'admin';
$flash   = '';

/* ── Handle bulk assign ──────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign') {
    Auth::validateCsrf();
    $examIds = array_values(array_unique(array_filter(
        array_map('intval', $_POST['exam_ids'] ?? []), fn($id) => $id > 0)));

    if (!$examIds) {
        $flash = 'error|Please select at least one exam.';
    } else {
        foreach ($examIds as $eid) {
            Database::execute(
                "INSERT IGNORE INTO student_group_exam_assignments (StudentGroupId, ExamInfoId, AssignedBy) VALUES (?,?,?)",
                [$gid, $eid, $adminId]);
        }
        // Bridge: give every current member a real exam_assignments row for
        // each newly-recommended exam, so admins don't assign them one by one.
        $createdCount = StudentGroup::syncAssignments($gid, null, Auth::currentLoginId());
        $flash = 'success|Assigned ' . count($examIds) . ' exam(s) to "' . $group['GroupName'] . '".'
            . ($createdCount > 0 ? " {$createdCount} individual assignment(s) auto-created for current members." : '');
    }
    header('Location: StudentGroupExams.php?gid=' . $gid . '&flash=' . urlencode($flash)); exit;
}

/* ── Handle single unassign ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_eid'])) {
    Auth::validateCsrf();
    $eid = (int)$_POST['remove_eid'];
    Database::execute(
        "DELETE FROM student_group_exam_assignments WHERE StudentGroupId=? AND ExamInfoId=?", [$gid, $eid]);

    // Bridge: revoke this exam's individual assignment for group members,
    // but only where never attempted and no other active group still
    // recommends it to them (StudentGroup::pruneOrphanedAssignments).
    $memberIds = array_map('intval', array_column(
        Database::fetchAll("SELECT UserInfoId FROM student_group_members WHERE StudentGroupId = ?", [$gid]),
        'UserInfoId'
    ));
    $revokedCount = $memberIds ? StudentGroup::pruneOrphanedAssignments($memberIds, [$eid]) : 0;

    $backTab  = in_array($_POST['tab'] ?? '', ['assigned', 'add'], true) ? $_POST['tab'] : 'assigned';
    $backPage = max(1, (int)($_POST['mp'] ?? 1));
    $msg = 'Exam unassigned from group.'
        . ($revokedCount > 0 ? " {$revokedCount} un-attempted individual assignment(s) auto-revoked." : '');
    header('Location: StudentGroupExams.php?gid=' . $gid . '&tab=' . $backTab . '&mp=' . $backPage
        . '&flash=' . urlencode('success|' . $msg)); exit;
}

if (isset($_GET['flash'])) $flash = urldecode($_GET['flash']);
[$flashType, $flashMsg] = $flash ? explode('|', $flash, 2) : ['', ''];

/* ── Active tab ───────────────────────────────────────────────────────────── */
$tab = in_array($_GET['tab'] ?? '', ['assigned', 'add'], true) ? $_GET['tab'] : 'assigned';

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

/* ── Exam-level pricing (migration_v51) may not be applied on every install
   yet — same resilience pattern as exam/browse-subjects.php: prefer the
   per-exam ExamFee once it exists, otherwise fall back to the older
   per-subject fee it superseded, rather than a hard SQL error either way. */
$feeExpr = Database::hasColumn('examinfo', 'ExamFee') ? 'COALESCE(e.ExamFee,0)' : 'COALESCE(s.ExamFee,0)';

/* ── Every currently-assigned ExamInfoId — unfiltered, unpaginated ──────────
   Needed to (a) show the true assigned-count on the tab label regardless of
   search/paging, and (b) exclude assigned exams from the candidate list
   regardless of what page/search the Assigned tab happens to be on. */
$allAssignedIds = array_map('intval', array_column(
    Database::fetchAll("SELECT ExamInfoId FROM student_group_exam_assignments WHERE StudentGroupId = ?", [$gid]),
    'ExamInfoId'
));
$assignedTotalCount = count($allAssignedIds);

/* ── Currently assigned: search + paginate ───────────────────────────────── */
$assignedSearch = trim($_GET['mq'] ?? '');
$assignedPage   = currentPage('mp');
$assigned       = [];
$assignedCount  = 0;

if ($tab === 'assigned') {
    $where  = ['a.StudentGroupId = ?'];
    $params = [$gid];
    if ($assignedSearch !== '') {
        $where[]  = '(e.ExamName LIKE ? OR s.SubjectName LIKE ?)';
        $like     = "%{$assignedSearch}%";
        array_push($params, $like, $like);
    }
    $whereSQL = implode(' AND ', $where);
    $baseSQL  = "FROM student_group_exam_assignments a
                   JOIN examinfo e ON e.ExamInfoId = a.ExamInfoId
              LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
                  WHERE {$whereSQL}";

    $assignedCount = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

    $offset   = ($assignedPage - 1) * PAGE_SIZE;
    $assigned = Database::fetchAll(
        "SELECT e.ExamInfoId, e.ExamName, $feeExpr AS ExamFee, s.SubjectName, a.AssignedAt
         {$baseSQL}
         ORDER BY s.SubjectName, e.ExamName
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

/* ── Assign more exams: candidates = every active exam not assigned yet, search + paginate ── */
$addSearch  = trim($_GET['aq'] ?? '');
$addPage    = currentPage('ap');
$candidates = [];
$candTotal  = 0;

if ($tab === 'add') {
    $where  = ["s.Active = 'Y'", "e.IsActive = 'Y'", "COALESCE(e.IsDeleted,'N') = 'N'"];
    $params = [];
    if ($allAssignedIds) {
        $ph      = implode(',', array_fill(0, count($allAssignedIds), '?'));
        $where[] = "e.ExamInfoId NOT IN ($ph)";
        array_push($params, ...$allAssignedIds);
    }
    if ($addSearch !== '') {
        $where[]  = '(e.ExamName LIKE ? OR s.SubjectName LIKE ?)';
        $like     = "%{$addSearch}%";
        array_push($params, $like, $like);
    }
    $whereSQL = implode(' AND ', $where);
    $baseSQL  = "FROM examinfo e
                  JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
                 WHERE {$whereSQL}";

    $candTotal = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

    $offset     = ($addPage - 1) * PAGE_SIZE;
    $candidates = Database::fetchAll(
        "SELECT e.ExamInfoId, e.ExamName, e.SubjectInfoId, $feeExpr AS ExamFee, s.SubjectName
         {$baseSQL}
         ORDER BY s.SubjectName, e.ExamName
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

$qsAssigned = array_filter(['gid' => $gid, 'tab' => 'assigned', 'mq' => $assignedSearch]);
$qsAdd      = array_filter(['gid' => $gid, 'tab' => 'add',      'aq' => $addSearch]);

$pageTitle = 'Assign Exams — ' . $group['GroupName'];
$pageHead  = '<style>
  .sge-row{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid #e2e8f0;}
  .sge-row:hover{background:#f7fafc;}
  .sge-row:last-child{border-bottom:none;}
  .sge-subject-hdr{background:#eef2ff;color:#312e81;font-weight:700;font-size:.82rem;padding:7px 12px;}

  .sge-tabs      { margin:0 0 16px; border-bottom:2px solid var(--clr-primary); display:flex; gap:4px; }
  .sge-tab       { display:inline-block; padding:8px 22px; cursor:pointer;
                    background:#f1f5f9; border:1px solid #cbd5e1; border-bottom:none;
                    border-radius:6px 6px 0 0;
                    font-weight:600; font-size:13px; text-decoration:none; color:#475569; }
  .sge-tab.active{ background:var(--clr-primary); color:#fff; border-color:var(--clr-primary); }
  .sge-tab:hover:not(.active) { background:#e2e8f0; color:var(--clr-primary); }

  .sge-search    { padding:10px 16px;border-bottom:1px solid #e2e8f0;display:flex;
                    align-items:center;gap:10px;flex-wrap:wrap;background:#f7fafc; }
  .sge-search input[type=text] { flex:1;min-width:220px;max-width:360px;padding:7px 12px;
                    border:1px solid #cbd5e0;border-radius:6px;font-size:.88rem; }
  .sge-result-count { font-size:.8rem; color:#718096; padding:8px 16px 0; }

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
  <span style="margin:0 6px;">›</span>
  <a href="StudentGroups.php" style="color:#3182ce;text-decoration:none;">Student Groups</a>
  <span style="margin:0 6px;">›</span>
  <span><?php echo htmlspecialchars($group['GroupName']); ?> — Assigned Exams</span>
</nav>

<?php if ($flashMsg): ?>
<div class="alert alert-<?php echo $flashType === 'success' ? 'success' : 'danger'; ?>" style="margin-bottom:14px;">
  <?php echo htmlspecialchars($flashMsg); ?>
</div>
<?php endif; ?>

<div style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:10px 16px;border-radius:6px;margin-bottom:16px;font-size:.85rem;">
  &#8505; Assigning an exam here marks it "Recommended" for <strong><?php echo htmlspecialchars($group['GroupName']); ?></strong>
  on the student's Browse &amp; Enroll page. It does not grant free access — members still pay the exam's
  price, with the group's <?php echo rtrim(rtrim(number_format((float)$group['DiscountPct'], 2), '0'), '.'); ?>% discount applied automatically.
</div>

<!-- ── Tab bar ──────────────────────────────────────────────────────────── -->
<div class="sge-tabs">
  <a href="?gid=<?php echo $gid; ?>&tab=assigned" class="sge-tab <?php echo $tab === 'assigned' ? 'active' : ''; ?>">
    &#128220; Currently Assigned (<?php echo $assignedTotalCount; ?>)
  </a>
  <a href="?gid=<?php echo $gid; ?>&tab=add" class="sge-tab <?php echo $tab === 'add' ? 'active' : ''; ?>">
    &#10010; Assign Exams
  </a>
</div>

<?php if ($tab === 'assigned'): ?>
<!-- ── Currently assigned ───────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-header">&#128220; Currently Assigned (<?php echo $assignedTotalCount; ?>)</div>

  <form method="get" class="sge-search">
    <input type="hidden" name="gid" value="<?php echo $gid; ?>">
    <input type="hidden" name="tab" value="assigned">
    <input type="text" name="mq" value="<?php echo htmlspecialchars($assignedSearch); ?>"
           placeholder="&#128269; Search assigned exams by name or subject…">
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php if ($assignedSearch !== ''): ?>
      <a href="?gid=<?php echo $gid; ?>&tab=assigned" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
  </form>

  <?php if ($assignedSearch !== ''): ?>
    <div class="sge-result-count">Showing <?php echo $assignedCount; ?> of <?php echo $assignedTotalCount; ?> assigned exams matching “<?php echo htmlspecialchars($assignedSearch); ?>”.</div>
  <?php endif; ?>

  <?php if (empty($assigned)): ?>
    <div style="padding:24px;text-align:center;color:#718096;">
      <?php echo $assignedSearch !== '' ? 'No assigned exams match your search.' : 'No exams assigned yet — add some from the "Assign Exams" tab.'; ?>
    </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="tbl" style="width:100%;font-size:.85rem;">
      <thead><tr><th>Exam</th><th>Subject</th><th>Fee</th><th>Assigned</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($assigned as $i => $a): ?>
        <tr class="<?php echo ($i % 2 === 0) ? 'odd' : 'even'; ?>">
          <td><?php echo htmlspecialchars($a['ExamName']); ?></td>
          <td><?php echo htmlspecialchars($a['SubjectName'] ?? ''); ?></td>
          <td>&#8377;<?php echo number_format((float)$a['ExamFee'], 2); ?></td>
          <td style="font-size:.78rem;color:#718096;"><?php echo $a['AssignedAt'] ? date('d M Y', strtotime($a['AssignedAt'])) : ''; ?></td>
          <td>
            <form method="post" style="display:inline;" onsubmit="return confirm('Unassign this exam from the group?');">
              <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
              <input type="hidden" name="gid" value="<?php echo $gid; ?>">
              <input type="hidden" name="tab" value="assigned">
              <input type="hidden" name="mp" value="<?php echo $assignedPage; ?>">
              <input type="hidden" name="remove_eid" value="<?php echo (int)$a['ExamInfoId']; ?>">
              <button type="submit" style="background:#c53030;color:#fff;padding:3px 9px;border-radius:4px;font-size:.78rem;font-weight:600;border:none;cursor:pointer;">Unassign</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php echo paginator($assignedCount, $assignedPage, PAGE_SIZE, $qsAssigned, 'mp'); ?>
  <?php endif; ?>
</div>

<?php else: /* $tab === 'add' */ ?>
<!-- ── Assign more exams ────────────────────────────────────────────────── -->
<div class="card" id="assign">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#10010; Assign Exams</span>
    <span style="font-size:.85rem;color:#718096;"><span id="selCount">0</span> selected</span>
  </div>

  <!-- Standalone GET search form — must NOT be nested inside the POST
       "assign exams" form below (nested <form> is invalid HTML; browsers
       drop the inner <form> tag, so its Search button ends up submitting
       the OUTER form instead — POSTing action=assign with no exam_ids[],
       which fails validation and redirects to the default "assigned" tab,
       since that redirect doesn't carry a &tab=add. That was the bug. -->
  <form method="get" class="sge-search" style="border-bottom:none;">
    <input type="hidden" name="gid" value="<?php echo $gid; ?>">
    <input type="hidden" name="tab" value="add">
    <input type="text" name="aq" value="<?php echo htmlspecialchars($addSearch); ?>"
           placeholder="&#128269; Search by exam or subject…">
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php if ($addSearch !== ''): ?>
      <a href="?gid=<?php echo $gid; ?>&tab=add" class="btn btn-secondary btn-sm">Clear search</a>
    <?php endif; ?>
  </form>
  <div class="sge-search">
    <button type="button" onclick="selectAll(true)"  class="btn btn-secondary btn-sm">Select All (this page)</button>
    <button type="button" onclick="clearAllSelections()" class="btn btn-secondary btn-sm">Clear Selection</button>
  </div>

  <?php if ($addSearch !== ''): ?>
    <div class="sge-result-count">Found <?php echo $candTotal; ?> exam(s) matching “<?php echo htmlspecialchars($addSearch); ?>”.</div>
  <?php endif; ?>

  <?php if (empty($candidates)): ?>
    <div style="padding:30px;text-align:center;color:#718096;">
      <?php echo $addSearch !== '' ? 'No exams match your search.' : 'Every active exam is already assigned to this group.'; ?>
    </div>
  <?php else: ?>
  <form method="post" id="assignExamsForm">
    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
    <input type="hidden" name="gid" value="<?php echo $gid; ?>">
    <input type="hidden" name="action" value="assign">
    <div id="examList">
      <?php $lastSubject = null; foreach ($candidates as $ex):
        $eid = (int)$ex['ExamInfoId'];
        if ($ex['SubjectName'] !== $lastSubject):
          $lastSubject = $ex['SubjectName'];
      ?>
        <div class="sge-subject-hdr"><?php echo htmlspecialchars($ex['SubjectName']); ?></div>
      <?php endif; ?>
        <div class="sge-row exam-item">
          <input type="checkbox" name="exam_ids[]" value="<?php echo $eid; ?>" id="eid<?php echo $eid; ?>"
                 onchange="toggleSelected(this.value, this.checked)"
                 style="transform:scale(1.3);accent-color:#3182ce;flex-shrink:0;">
          <label for="eid<?php echo $eid; ?>" style="cursor:pointer;flex:1;display:flex;justify-content:space-between;gap:12px;">
            <span><?php echo htmlspecialchars($ex['ExamName']); ?></span>
            <span style="color:#718096;font-size:.82rem;">&#8377;<?php echo number_format((float)$ex['ExamFee'], 2); ?></span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
    <?php echo paginator($candTotal, $addPage, PAGE_SIZE, $qsAdd, 'ap'); ?>
    <div style="padding:14px 16px;border-top:1px solid #e2e8f0;background:#f7fafc;">
      <button type="submit" class="btn btn-success" style="font-weight:700;">&#128220; Assign Selected Exams</button>
      <a href="StudentGroups.php" class="btn btn-secondary">&#8592; Back to Groups</a>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Selections persist across search/pagination on the Assign Exams tab
// (sessionStorage, scoped per group) — same pattern as
// StudentGroupMembers.php's Add Students tab.
var SGE_KEY = 'sge_selected_<?php echo $gid; ?>';

function loadSelected() {
  try { return JSON.parse(sessionStorage.getItem(SGE_KEY) || '[]'); } catch (e) { return []; }
}
function saveSelected(ids) { sessionStorage.setItem(SGE_KEY, JSON.stringify(ids)); }

function toggleSelected(eid, checked) {
  eid = String(eid);
  var ids = loadSelected();
  if (checked) {
    if (ids.indexOf(eid) === -1) ids.push(eid);
  } else {
    ids = ids.filter(function (id) { return id !== eid; });
  }
  saveSelected(ids);
  updateCount();
}
function updateCount() {
  var el = document.getElementById('selCount');
  if (el) el.textContent = loadSelected().length;
}
function selectAll(checked) {
  document.querySelectorAll('#examList input[type=checkbox]').forEach(function (cb) {
    cb.checked = checked;
    toggleSelected(cb.value, checked);
  });
}
function clearAllSelections() {
  saveSelected([]);
  document.querySelectorAll('#examList input[type=checkbox]').forEach(function (cb) { cb.checked = false; });
  updateCount();
}
function restoreSelections() {
  var ids = loadSelected();
  document.querySelectorAll('#examList input[type=checkbox]').forEach(function (cb) {
    if (ids.indexOf(cb.value) !== -1) cb.checked = true;
  });
  updateCount();
}

<?php if ($flashType === 'success'): ?>
sessionStorage.removeItem(SGE_KEY);
<?php endif; ?>

document.addEventListener('DOMContentLoaded', restoreSelections);

var assignExamsForm = document.getElementById('assignExamsForm');
if (assignExamsForm) {
  assignExamsForm.addEventListener('submit', function () {
    var ids = loadSelected();
    var visible = new Set(Array.prototype.map.call(
      document.querySelectorAll('#examList input[type=checkbox]'), function (cb) { return cb.value; }));
    var form = this;
    ids.forEach(function (id) {
      if (!visible.has(id)) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'exam_ids[]';
        inp.value = id;
        form.appendChild(inp);
      }
    });
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
