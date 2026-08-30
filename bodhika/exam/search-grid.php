<?php
/**
 * exam/search-grid.php — Admin "Exam List" grid view.
 *
 * A card-based alternative to search.php's table view (admin only).
 * Same filter querystring keys as search.php (txtExamName, txtGrade,
 * txtSubject, txtCategory, page) so the "List View" / "Grid View" toggle
 * on each page can swap views without losing the current search.
 *
 * Status-badge heuristic (examinfo has no schedule/start-end date columns,
 * so "Live / Upcoming / Completed" per exam is derived from existing
 * signals rather than a dedicated status column):
 *   - Live      → same definition already used by includes/header.php's
 *                 "Live Now" navbar stat: an exam_events row for this exam
 *                 with LastEventAt in the last 2 hours (i.e. someone is
 *                 actively taking it right now).
 *   - Completed → examinfo.IsActive = 'N' (admin has closed/deactivated it).
 *   - Upcoming  → everything else (IsActive = 'Y' and not currently live).
 * Documented here so the heuristic doesn't drift silently if someone edits
 * this file later.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/ExamType.php';
Auth::requireLogin('../auth/login.php');

// Admin-only view — matches the audience of the "Manage" actions on each card.
if (!Auth::isAdmin()) {
    header('Location: search.php');
    exit;
}

$pageTitle = 'Exam List';

/* ── Soft delete (same POST contract as search.php, so either view can
   delete without duplicating logic) ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_examid'])) {
    Auth::validateCsrf();
    $delId = (int)$_POST['delete_examid'];
    if ($delId > 0) {
        try {
            Database::execute(
                "UPDATE examinfo SET IsDeleted='Y', DeletedAt=NOW(), DeletedBy=? WHERE ExamInfoId=?",
                [Auth::currentUser() ?: 'admin', $delId]
            );
            try {
                $delName = Database::fetchOne("SELECT ExamName FROM examinfo WHERE ExamInfoId=? LIMIT 1", [$delId])['ExamName'] ?? '';
                Database::execute(
                    "INSERT INTO exam_changelog (ExamInfoId,ExamName,Action,ActionBy,Details) VALUES (?,?,?,?,?)",
                    [$delId, $delName, 'DELETE', Auth::currentUser(), 'Soft-deleted from exam grid']
                );
            } catch (Exception $eLog) {}
        } catch (Exception $e) { /* migration_v43 not yet run */ }
    }
    header('Location: search-grid.php' . (isset($_GET['qs']) ? '?' . $_GET['qs'] : ''));
    exit;
}

$grades     = Database::fetchAll("SELECT GradeInfoId, GradeName FROM gradeinfo ORDER BY GradeName");
$subjects   = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo ORDER BY SubjectName");
$gradeMap   = array_column($grades,   'GradeName',   'GradeInfoId');
$subjectMap = array_column($subjects, 'SubjectName', 'SubjectInfoId');

/* Education-level group per grade (Primary/Secondary/UG/PG/...) — same
   source search.php uses for its group tabs/chips. Empty until migration_v44. */
try {
    $groups = Database::fetchAll("SELECT GroupId, GroupName FROM groupinfo WHERE Active='Y' ORDER BY SortOrder, GroupName");
} catch (Exception $e) { $groups = []; }
$gradeGroupMap = []; // GradeInfoId -> GroupName
try {
    $ggRows = Database::fetchAll(
        "SELECT gi.GradeInfoId, gr.GroupName FROM gradeinfo gi LEFT JOIN groupinfo gr ON gr.GroupId = gi.GroupId");
    foreach ($ggRows as $r) { $gradeGroupMap[(int)$r['GradeInfoId']] = $r['GroupName']; }
} catch (Exception $e) {}

/** Shortens a group name to a compact chip label (Undergraduate -> UG, etc.) */
function groupChip(?string $groupName): string {
    if (!$groupName) return '';
    $known = ['Primary' => 'PRI', 'Secondary' => 'SEC', 'Undergraduate' => 'UG', 'Postgraduate' => 'PG', 'Doctorate' => 'PHD'];
    if (isset($known[$groupName])) return $known[$groupName];
    // Fallback: initials of each word, e.g. "Higher Secondary" -> HS
    $words = preg_split('/\s+/', trim($groupName));
    $initials = strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', $words)));
    return $initials !== '' ? substr($initials, 0, 4) : strtoupper(substr($groupName, 0, 3));
}

/** Deterministic icon + colour pair for an exam card avatar, keyed off the
 *  subject name so the same subject always renders the same way. */
function examCardIcon(string $subjectName): array {
    $s = strtolower($subjectName);
    $rules = [
        'python'    => ['&#128013;', '#0d9488'],
        'java'      => ['&#9749;',   '#b45309'],
        'database'  => ['&#128451;', '#7c3aed'],
        'sql'       => ['&#128451;', '#7c3aed'],
        'data'      => ['&#128202;', '#7c3aed'],
        'web'       => ['&#127760;', '#059669'],
        'cloud'     => ['&#9729;',   '#ea580c'],
        'network'   => ['&#127760;', '#0891b2'],
        'operating' => ['&#128187;', '#dc2626'],
        'os'        => ['&#128187;', '#dc2626'],
        'security'  => ['&#128274;', '#b91c1c'],
        'ai'        => ['&#129302;', '#7c3aed'],
        'machine'   => ['&#129302;', '#7c3aed'],
        'devops'    => ['&#9881;',   '#0891b2'],
        'software'  => ['&#128295;', '#2563eb'],
        'algorithm' => ['&#128200;', '#2563eb'],
        'math'      => ['&#128202;', '#0369a1'],
        'physics'   => ['&#9889;',   '#0369a1'],
        'chemistry' => ['&#129514;', '#059669'],
        'english'   => ['&#128214;', '#b45309'],
        'general knowledge' => ['&#127942;', '#d97706'],
    ];
    foreach ($rules as $kw => $pair) {
        if (str_contains($s, $kw)) return $pair;
    }
    $palette = [
        ['&#128218;', '#2563eb'], ['&#128218;', '#0d9488'], ['&#128218;', '#7c3aed'],
        ['&#128218;', '#dc2626'], ['&#128218;', '#059669'], ['&#128218;', '#ea580c'],
    ];
    return $palette[crc32($subjectName) % count($palette)];
}

/* ── Filters (same querystring keys as search.php) ───────────────────────── */
$filterGrade    = (int)($_GET['txtGrade']   ?? 0);
$filterSubject  = (int)($_GET['txtSubject'] ?? 0);
$filterName     = trim($_GET['txtExamName'] ?? '');
$filterCategory = trim($_GET['txtCategory'] ?? '');
$catColAvailable = Database::hasColumn('examinfo', 'ExamCategory');
$examTypes = $catColAvailable ? ExamType::allValues() : [];

function gridUrl(array $overrides = []): string {
    $qs = array_merge($_GET, $overrides);
    foreach (['txtGrade', 'txtSubject', 'txtExamName', 'txtCategory'] as $k) {
        if (array_key_exists($k, $overrides) && !array_key_exists('page', $overrides)) unset($qs['page']);
    }
    $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return 'search-grid.php' . ($qs ? '?' . http_build_query($qs) : '');
}
// Same filters, but pointed at the table view — keeps the Grid/List toggle in sync.
function listViewUrl(): string {
    $qs = array_filter($_GET, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    unset($qs['page']);
    return 'search.php' . ($qs ? '?' . http_build_query($qs) : '');
}

/* ── Global KPI stats (unfiltered — same as the navbar's admin quick-stats,
   plus the Upcoming/Completed split) ─────────────────────────────────────── */
$statTotal = 0; $statLive = 0; $statUpcoming = 0; $statCompleted = 0;
try {
    $statTotal = (int)(Database::fetchOne(
        "SELECT COUNT(*) AS c FROM examinfo WHERE COALESCE(IsDeleted,'N')='N'")['c'] ?? 0);
} catch (Exception $e) {
    $statTotal = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM examinfo")['c'] ?? 0);
}
try {
    $statCompleted = (int)(Database::fetchOne(
        "SELECT COUNT(*) AS c FROM examinfo WHERE COALESCE(IsDeleted,'N')='N' AND COALESCE(IsActive,'Y')='N'")['c'] ?? 0);
} catch (Exception $e) {}
try {
    $statLive = (int)(Database::fetchOne(
        "SELECT COUNT(DISTINCT ExamInfoId) AS c FROM exam_events
          WHERE LastEventAt >= DATE_SUB(NOW(), INTERVAL 2 HOUR)")['c'] ?? 0);
} catch (Exception $e) {}
$statUpcoming = max(0, $statTotal - $statCompleted - $statLive);

/* Full set of currently-live ExamInfoIds, so each card can flag itself. */
$liveExamIds = [];
try {
    $liveRows = Database::fetchAll(
        "SELECT DISTINCT ExamInfoId FROM exam_events WHERE LastEventAt >= DATE_SUB(NOW(), INTERVAL 2 HOUR)");
    $liveExamIds = array_column($liveRows, 'ExamInfoId');
    $liveExamIds = array_map('intval', $liveExamIds);
} catch (Exception $e) {}

/* ── Filtered exam list (same WHERE-building convention as search.php) ──── */
$where = ["COALESCE(IsDeleted,'N') = 'N'"];
$params = [];
if ($filterName !== '')     { $where[] = 'ExamName LIKE ?';   $params[] = '%' . $filterName . '%'; }
if ($filterGrade > 0)       { $where[] = 'GradeInfoId = ?';   $params[] = $filterGrade; }
if ($filterSubject > 0)     { $where[] = 'SubjectInfoId = ?'; $params[] = $filterSubject; }
if (Database::hasColumn('examinfo', 'IsQuestionBank')) { $where[] = "COALESCE(IsQuestionBank,'N') = 'N'"; }
if ($catColAvailable && $filterCategory !== '') { $where[] = 'ExamCategory = ?'; $params[] = $filterCategory; }

try {
    $exams = Database::fetchAll(
        'SELECT * FROM examinfo WHERE ' . implode(' AND ', $where) . ' ORDER BY ExamInfoId DESC', $params);
} catch (Exception $e) {
    $legacyWhere = array_slice($where, 1);
    $exams = Database::fetchAll(
        'SELECT * FROM examinfo' . ($legacyWhere ? ' WHERE ' . implode(' AND ', $legacyWhere) : '') . ' ORDER BY ExamInfoId DESC', $params);
}

/* Per-exam assigned-student counts (same query as search.php). */
$assignCounts = [];
try {
    $rows = Database::fetchAll(
        "SELECT ExamInfoId, COUNT(*) AS Total FROM exam_assignments GROUP BY ExamInfoId");
    foreach ($rows as $r) { $assignCounts[(int)$r['ExamInfoId']] = (int)$r['Total']; }
} catch (Exception $e) {}

/* ── Pagination (8 per page — matches the 4x2 card grid) ─────────────────── */
const EXAMS_PER_PAGE_GRID = 8;
$totalExams = count($exams);
$totalPages = max(1, (int)ceil($totalExams / EXAMS_PER_PAGE_GRID));
$page       = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$pageExams  = array_slice($exams, ($page - 1) * EXAMS_PER_PAGE_GRID, EXAMS_PER_PAGE_GRID);

$csrfToken = htmlspecialchars(Auth::csrfToken());

include __DIR__ . '/../includes/header.php';
?>
<style>
.eg-wrap { max-width: 1300px; margin: 0 auto; padding: 0 16px; }

.eg-title-row { display:flex; align-items:flex-start; justify-content:space-between;
                flex-wrap:wrap; gap:12px; margin-bottom:18px; }
.eg-title { display:flex; align-items:center; gap:12px; }
.eg-title-icon { width:44px; height:44px; border-radius:12px; background:var(--clr-primary-pale);
                 display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
.eg-title h1 { font-size:1.3rem; margin:0; color:var(--clr-text); }
.eg-title p  { margin:2px 0 0; font-size:.82rem; color:var(--clr-text-muted); }
.eg-title-actions { display:flex; gap:8px; flex-wrap:wrap; }

/* KPI cards */
.eg-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:18px; }
@media (max-width:900px) { .eg-stats { grid-template-columns:repeat(2,1fr); } }
.eg-stat { background:var(--clr-surface); border:1px solid var(--clr-border); border-radius:var(--radius-lg);
           padding:16px; display:flex; align-items:center; gap:12px; box-shadow:var(--shadow-sm); }
.eg-stat-ic { width:46px; height:46px; border-radius:50%; display:flex; align-items:center; justify-content:center;
              font-size:1.2rem; flex-shrink:0; }
.eg-stat-num { font-size:1.4rem; font-weight:800; color:var(--clr-text); line-height:1.1; }
.eg-stat-lbl { font-size:.78rem; color:var(--clr-text-muted); font-weight:600; }

/* Filter bar */
.eg-filterbar { background:var(--clr-surface); border:1px solid var(--clr-border); border-radius:var(--radius-lg);
                padding:14px 16px; margin-bottom:18px; box-shadow:var(--shadow-sm); }
.eg-filterbar form { display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px 16px; }
.eg-field { display:flex; flex-direction:column; gap:5px; }
.eg-field label { font-size:.72rem; font-weight:800; color:var(--clr-primary); text-transform:uppercase; letter-spacing:.05em; }
.eg-field input, .eg-field select { height:36px; border:1px solid var(--clr-border-2); border-radius:6px;
                                     font-size:.85rem; padding:0 10px; min-width:150px; background:#fff; }
.eg-field-name input { width:220px; min-width:180px; }
.eg-actions-inline { display:flex; gap:8px; }

/* Results header + view toggle */
.eg-results-row { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:12px; }
.eg-results-count { display:flex; align-items:center; gap:8px; font-weight:700; color:var(--clr-text); }
.eg-view-toggle { display:flex; border:1px solid var(--clr-border-2); border-radius:8px; overflow:hidden; }
.eg-view-toggle a { padding:7px 14px; font-size:.82rem; font-weight:700; text-decoration:none; color:var(--clr-text-muted); background:#fff; }
.eg-view-toggle a.active { background:var(--clr-btn-accent); color:#fff; }

/* Card grid */
.eg-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:18px; }
@media (max-width:1150px) { .eg-grid { grid-template-columns:repeat(3,1fr); } }
@media (max-width:850px)  { .eg-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width:520px)  { .eg-grid { grid-template-columns:1fr; } }

.eg-card { background:var(--clr-surface); border:1px solid var(--clr-border); border-radius:var(--radius-lg);
           padding:16px; box-shadow:var(--shadow-sm); display:flex; flex-direction:column; gap:10px;
           position:relative; transition:box-shadow var(--tx), transform var(--tx); }
.eg-card:hover { box-shadow:var(--shadow); transform:translateY(-1px); }
.eg-card-top { display:flex; align-items:flex-start; gap:12px; }
.eg-card-avatar { width:48px; height:48px; border-radius:50%; display:flex; align-items:center; justify-content:center;
                   font-size:1.3rem; flex-shrink:0; }
.eg-card-name { font-weight:800; font-size:.95rem; color:var(--clr-text); line-height:1.25;
                display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.eg-card-sub  { font-size:.76rem; color:var(--clr-text-muted); margin-top:2px; }
.eg-card-chips { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.eg-chip { font-size:.68rem; font-weight:800; padding:2px 7px; border-radius:5px; background:var(--clr-primary-pale); color:var(--clr-primary); }
.eg-qcount { font-size:.76rem; color:var(--clr-text-muted); }
.eg-card-meta { display:flex; align-items:center; justify-content:space-between; font-size:.78rem; color:var(--clr-text-muted); }
.eg-status { display:inline-flex; align-items:center; gap:5px; font-size:.72rem; font-weight:800; padding:3px 9px; border-radius:12px; }
.eg-status-live      { background:#dcfce7; color:#15803d; }
.eg-status-upcoming  { background:#fef3c7; color:#b45309; }
.eg-status-completed { background:#dbeafe; color:#1d4ed8; }
.eg-status-dot { width:6px; height:6px; border-radius:50%; background:currentColor; display:inline-block; }

.eg-card-actions { display:flex; gap:6px; align-items:center; margin-top:2px; }
.eg-btn { flex:1; text-align:center; padding:6px 8px; border-radius:6px; font-size:.74rem; font-weight:700;
          text-decoration:none; color:#fff; border:none; cursor:pointer; white-space:nowrap; }
.eg-btn-manage  { background:#334155; }
.eg-btn-results { background:#0891b2; }
.eg-btn-edit    { background:var(--clr-btn-accent); }
.eg-more-wrap { position:relative; }
.eg-more-btn { width:30px; height:30px; border-radius:6px; border:1px solid var(--clr-border-2); background:#fff;
               cursor:pointer; font-weight:900; color:var(--clr-text-muted); flex-shrink:0; }
.eg-more-menu { display:none; position:absolute; right:0; top:36px; background:#fff; border:1px solid var(--clr-border);
                border-radius:8px; box-shadow:var(--shadow-lg); z-index:20; min-width:180px; overflow:hidden; }
.eg-more-menu.open { display:block; }
.eg-more-menu a, .eg-more-menu button { display:block; width:100%; text-align:left; padding:9px 14px; font-size:.8rem;
                font-weight:600; color:var(--clr-text); text-decoration:none; background:none; border:none; cursor:pointer; }
.eg-more-menu a:hover, .eg-more-menu button:hover { background:var(--clr-primary-pale); }
.eg-more-menu form { margin:0; }
.eg-more-menu .danger { color:#dc2626; }

.eg-empty { text-align:center; padding:48px 16px; color:var(--clr-text-muted); }
</style>

<div class="eg-wrap">

  <div class="eg-title-row">
    <div class="eg-title">
      <div class="eg-title-icon">&#128203;</div>
      <div>
        <h1>Exam List</h1>
        <p>Manage and monitor all examinations</p>
      </div>
    </div>
    <div class="eg-title-actions">
      <a href="manage.php?InfoId=0" class="btn btn-success btn-sm">&#10010; Add Exam</a>
      <a href="../Admin/ImportExamTemplate.php" class="btn btn-secondary btn-sm">&#11014; Import</a>
      <a href="../Admin/ExamResults.php" class="btn btn-sm" style="background:#0891b2;color:#fff;">&#128202; Results</a>
    </div>
  </div>

  <div class="eg-stats">
    <div class="eg-stat">
      <div class="eg-stat-ic" style="background:#ede9fe;color:#7c3aed;">&#127891;</div>
      <div><div class="eg-stat-num"><?php echo number_format($statTotal); ?></div><div class="eg-stat-lbl">Total Exams</div></div>
    </div>
    <div class="eg-stat">
      <div class="eg-stat-ic" style="background:#dcfce7;color:#15803d;">&#128101;</div>
      <div><div class="eg-stat-num"><?php echo number_format($statLive); ?></div><div class="eg-stat-lbl">Live Exams</div></div>
    </div>
    <div class="eg-stat">
      <div class="eg-stat-ic" style="background:#fef3c7;color:#b45309;">&#128337;</div>
      <div><div class="eg-stat-num"><?php echo number_format($statUpcoming); ?></div><div class="eg-stat-lbl">Upcoming</div></div>
    </div>
    <div class="eg-stat">
      <div class="eg-stat-ic" style="background:#dbeafe;color:#1d4ed8;">&#10003;</div>
      <div><div class="eg-stat-num"><?php echo number_format($statCompleted); ?></div><div class="eg-stat-lbl">Completed</div></div>
    </div>
  </div>

  <div class="eg-filterbar">
    <form method="get" action="">
      <div class="eg-field eg-field-name">
        <label>Search</label>
        <input type="text" name="txtExamName" placeholder="Search by name…" value="<?php echo htmlspecialchars($filterName); ?>">
      </div>
      <div class="eg-field">
        <label>Grade</label>
        <select name="txtGrade">
          <option value="0">— All Grades —</option>
          <?php foreach ($grades as $g): ?>
            <option value="<?php echo (int)$g['GradeInfoId']; ?>" <?php echo $filterGrade===(int)$g['GradeInfoId']?'selected':''; ?>>
              <?php echo htmlspecialchars($g['GradeName']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="eg-field">
        <label>Subject</label>
        <select name="txtSubject">
          <option value="0">— All Subjects —</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?php echo (int)$s['SubjectInfoId']; ?>" <?php echo $filterSubject===(int)$s['SubjectInfoId']?'selected':''; ?>>
              <?php echo htmlspecialchars($s['SubjectName']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($catColAvailable && !empty($examTypes)): ?>
      <div class="eg-field">
        <label>Type</label>
        <select name="txtCategory">
          <option value="">— All Types —</option>
          <?php foreach ($examTypes as $t): ?>
            <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $filterCategory===$t?'selected':''; ?>>
              <?php echo htmlspecialchars($t); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="eg-actions-inline">
        <button type="submit" class="btn btn-primary btn-sm">&#128269; Search</button>
        <a href="search-grid.php" class="btn btn-secondary btn-sm">&#8635; Reset</a>
      </div>
    </form>
  </div>

  <div class="eg-results-row">
    <div class="eg-results-count">&#128203; <?php echo number_format($totalExams); ?> Exam<?php echo $totalExams===1?'':'s'; ?></div>
    <div class="eg-view-toggle">
      <a href="<?php echo gridUrl(); ?>" class="active">&#9638; Grid View</a>
      <a href="<?php echo listViewUrl(); ?>">&#9776; List View</a>
    </div>
  </div>

  <?php if (empty($pageExams)): ?>
    <div class="eg-empty">No exams found. <a href="manage.php?InfoId=0">Add one now</a>.</div>
  <?php else: ?>
  <div class="eg-grid">
    <?php foreach ($pageExams as $exam):
        $eid       = (int)$exam['ExamInfoId'];
        $gname     = $gradeMap[$exam['GradeInfoId']]     ?? '—';
        $sname     = $subjectMap[$exam['SubjectInfoId']] ?? '—';
        $chip      = groupChip($gradeGroupMap[(int)$exam['GradeInfoId']] ?? null);
        [$icon, $iconColor] = examCardIcon($sname);
        $students  = $assignCounts[$eid] ?? 0;
        $isActive  = ($exam['IsActive'] ?? 'Y') === 'Y';
        if (in_array($eid, $liveExamIds, true)) {
            $status = ['Live', 'eg-status-live'];
        } elseif (!$isActive) {
            $status = ['Completed', 'eg-status-completed'];
        } else {
            $status = ['Upcoming', 'eg-status-upcoming'];
        }
    ?>
    <div class="eg-card">
      <div class="eg-card-top">
        <div class="eg-card-avatar" style="background:<?php echo $iconColor; ?>22;color:<?php echo $iconColor; ?>;">
          <?php echo $icon; ?>
        </div>
        <div style="min-width:0;">
          <div class="eg-card-name" title="<?php echo htmlspecialchars($exam['ExamName']); ?>">
            <?php echo htmlspecialchars($exam['ExamName']); ?>
          </div>
          <div class="eg-card-sub"><?php echo htmlspecialchars($gname); ?> &middot; <?php echo htmlspecialchars($sname); ?></div>
        </div>
      </div>

      <div class="eg-card-chips">
        <?php if ($chip !== ''): ?><span class="eg-chip"><?php echo htmlspecialchars($chip); ?></span><?php endif; ?>
        <span class="eg-qcount"><?php echo (int)($exam['NumOfQuestions'] ?? 0); ?> Questions</span>
      </div>

      <div class="eg-card-meta">
        <span>&#128101; <?php echo number_format($students); ?> Students</span>
        <span class="eg-status <?php echo $status[1]; ?>"><span class="eg-status-dot"></span><?php echo $status[0]; ?></span>
      </div>

      <div class="eg-card-actions">
        <a href="assign.php?examId=<?php echo $eid; ?>" class="eg-btn eg-btn-manage" title="Assign / manage students">Manage</a>
        <a href="../Admin/ExamResults.php?examId=<?php echo $eid; ?>" class="eg-btn eg-btn-results" title="View results">Results</a>
        <a href="manage.php?InfoId=<?php echo $eid; ?>" class="eg-btn eg-btn-edit" title="Edit exam">Edit</a>
        <div class="eg-more-wrap">
          <button type="button" class="eg-more-btn" onclick="egToggleMenu(<?php echo $eid; ?>)" title="More actions">&#8942;</button>
          <div class="eg-more-menu" id="eg-menu-<?php echo $eid; ?>">
            <a href="write.php?InfoId=<?php echo $eid; ?>">&#9998; Preview</a>
            <a href="history.php?InfoId=<?php echo $eid; ?>">&#128200; History</a>
            <a href="questions.php?examId=<?php echo $eid; ?>">&#10067; Questions</a>
            <a href="question-edit.php?examId=<?php echo $eid; ?>">&#10010; Add Question</a>
            <a href="../Admin/BulkUploadQuestions.php?examId=<?php echo $eid; ?>">&#11014; Bulk Upload</a>
            <form method="post" onsubmit="return confirm('Delete exam &quot;<?php echo addslashes(htmlspecialchars($exam['ExamName'])); ?>&quot;?\n\nIt will be hidden everywhere but can be restored from Trash.');">
              <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
              <input type="hidden" name="delete_examid" value="<?php echo $eid; ?>">
              <button type="submit" class="danger">&#128465; Delete</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pager">
    <a class="pg-btn <?php echo $page<=1?'disabled':''; ?>" href="<?php echo gridUrl(['page'=>max(1,$page-1)]); ?>">&laquo; Prev</a>
    <?php
      $start = max(1, $page - 2);
      $end   = min($totalPages, $start + 4);
      $start = max(1, $end - 4);
      for ($i = $start; $i <= $end; $i++):
    ?>
      <a class="pg-btn <?php echo $i===$page?'active':''; ?>" href="<?php echo gridUrl(['page'=>$i]); ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
    <?php if ($end < $totalPages): ?><span style="padding:0 4px;color:var(--clr-text-faint);">&hellip;</span>
      <a class="pg-btn" href="<?php echo gridUrl(['page'=>$totalPages]); ?>"><?php echo $totalPages; ?></a>
    <?php endif; ?>
    <a class="pg-btn <?php echo $page>=$totalPages?'disabled':''; ?>" href="<?php echo gridUrl(['page'=>min($totalPages,$page+1)]); ?>">Next &raquo;</a>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div>

<script>
function egToggleMenu(id) {
  var menu = document.getElementById('eg-menu-' + id);
  var wasOpen = menu.classList.contains('open');
  document.querySelectorAll('.eg-more-menu.open').forEach(function(m) { m.classList.remove('open'); });
  if (!wasOpen) menu.classList.add('open');
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.eg-more-wrap')) {
    document.querySelectorAll('.eg-more-menu.open').forEach(function(m) { m.classList.remove('open'); });
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
