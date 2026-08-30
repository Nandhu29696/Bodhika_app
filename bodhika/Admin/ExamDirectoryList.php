<?php
/**
 * Admin/ExamDirectoryList.php — Manage the ExamPath Directory
 *
 * Read-only informational content for students (JEE/NEET/CLAT/NID/... —
 * real-world external exams, NOT this app's own testable exams). Admins
 * curate the list here; students browse it via exam/exam-directory.php.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

/* ── Handle quick actions ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['ExamDirectoryId'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        Database::execute("DELETE FROM exam_directory_progress WHERE ExamDirectoryId = ?", [$id]);
        Database::execute("DELETE FROM exam_directory WHERE ExamDirectoryId = ?", [$id]);
        header('Location: ExamDirectoryList.php?msg=deleted');
        exit;
    }
    if ($action === 'toggle' && $id > 0) {
        Database::execute(
            "UPDATE exam_directory SET Active = IF(Active='Y','N','Y') WHERE ExamDirectoryId = ?",
            [$id]);
        header('Location: ExamDirectoryList.php');
        exit;
    }
}

/* ── Filters ──────────────────────────────────────────────────────────────── */
$filterTrack    = trim($_GET['track']    ?? '');
$filterPriority = trim($_GET['priority'] ?? '');
$filterActive   = trim($_GET['active']   ?? '');
$filterBlr      = trim($_GET['blr']      ?? '');
$search         = trim($_GET['q']        ?? '');

$where  = ['1=1'];
$params = [];

if ($filterTrack !== '')    { $where[] = 't.TrackCode = ?'; $params[] = $filterTrack; }
if ($filterPriority !== '') { $where[] = 'd.Priority = ?';  $params[] = $filterPriority; }
if ($filterActive !== '')   { $where[] = 'd.Active = ?';    $params[] = $filterActive; }
if ($filterBlr === 'Y')     { $where[] = "d.IsBangalore = 'Y'"; }
if ($search !== '') {
    $where[]  = '(d.ExamName LIKE ? OR d.ShortName LIKE ? OR d.Outcome LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like]);
}

$sql = "SELECT d.*, t.Label AS TrackLabel, t.ColorHex, t.BgColorHex
        FROM exam_directory d
        LEFT JOIN exam_directory_track t ON t.TrackId = d.TrackId
        WHERE " . implode(' AND ', $where) . "
        ORDER BY (d.RegDeadlineDate IS NULL), d.RegDeadlineDate ASC, d.SortOrder ASC, d.ExamDirectoryId ASC";

$rows   = Database::fetchAll($sql, $params);
$tracks = Database::fetchAll("SELECT TrackId, TrackCode, Label FROM exam_directory_track WHERE Active='Y' ORDER BY SortOrder");

$totalCount    = count($rows);
$closing60Count = 0;
$bangaloreCount = 0;
$today = new DateTime('today');
foreach ($rows as $r) {
    if (!empty($r['RegDeadlineDate'])) {
        $diff = $today->diff(new DateTime($r['RegDeadlineDate']))->days;
        $isFuture = new DateTime($r['RegDeadlineDate']) >= $today;
        if ($isFuture && $diff <= 60) $closing60Count++;
    }
    if ($r['IsBangalore'] === 'Y') $bangaloreCount++;
}

$priorityOptions = ['CRITICAL','HIGH','MEDIUM','OPTIONAL','FUTURE'];

$pageTitle = 'ExamPath Directory';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .ed-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:18px; }
  .ed-filters .form-group { margin:0; }
  .ed-filters label { font-size:.72rem; font-weight:800; color:var(--clr-primary); text-transform:uppercase; letter-spacing:.06em; display:block; margin-bottom:3px; white-space:nowrap; }
  .ed-filters select, .ed-filters input[type=text] { font-size:.85rem; padding:5px 8px; height:32px; }
  .ed-filters .btn { height:32px; padding:0 14px; font-size:.85rem; }

  .ed-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:18px; }
  .ed-stat { border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; background:#f8fafc; }
  .ed-stat .n { font-size:1.5rem; font-weight:800; color:#1e293b; }
  .ed-stat .l { font-size:.75rem; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }

  .ed-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.72rem; font-weight:700; letter-spacing:.3px; white-space:nowrap; }
  .ed-pr-CRITICAL { background:#fee2e2; color:#991b1b; }
  .ed-pr-HIGH     { background:#ffedd5; color:#9a3412; }
  .ed-pr-MEDIUM   { background:#fef9c3; color:#854d0e; }
  .ed-pr-OPTIONAL { background:#e0e7ff; color:#3730a3; }
  .ed-pr-FUTURE   { background:#f3f4f6; color:#374151; }

  .act-btn { font-size:.8rem; padding:3px 10px; }
  .tbl th, .tbl td { vertical-align:middle; }
  .inactive-row { opacity:.55; }
</style>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#127942; ExamPath Directory — Exams &amp; Colleges</span>
    <a href="AddEditExamDirectory.php?ExamDirectoryId=0" class="btn btn-primary" style="font-size:.85rem;padding:5px 14px;">
      &#43; Add Exam
    </a>
  </div>
  <div class="card-body">

    <?php if (($_GET['msg'] ?? '') === 'deleted'): ?>
      <div class="alert alert-success">Directory entry deleted.</div>
    <?php endif; ?>

    <div class="ed-stats">
      <div class="ed-stat"><div class="n"><?php echo $totalCount; ?></div><div class="l">Total Exams</div></div>
      <div class="ed-stat"><div class="n"><?php echo $closing60Count; ?></div><div class="l">Closing in 60 Days</div></div>
      <div class="ed-stat"><div class="n"><?php echo $bangaloreCount; ?></div><div class="l">Bangalore-Based</div></div>
      <div class="ed-stat"><div class="n"><?php echo count($tracks); ?></div><div class="l">Tracks</div></div>
    </div>

    <form method="get" action="">
      <div class="ed-filters">
        <div class="form-group">
          <label>Search</label>
          <input type="text" name="q" class="form-control" placeholder="Exam / college name…"
                 value="<?php echo htmlspecialchars($search); ?>" style="min-width:180px;">
        </div>
        <div class="form-group">
          <label>Track</label>
          <select name="track" class="form-control">
            <option value="">All Tracks</option>
            <?php foreach ($tracks as $t): ?>
              <option value="<?php echo htmlspecialchars($t['TrackCode']); ?>" <?php echo $filterTrack===$t['TrackCode']?'selected':''; ?>>
                <?php echo htmlspecialchars($t['Label']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Priority</label>
          <select name="priority" class="form-control">
            <option value="">All</option>
            <?php foreach ($priorityOptions as $p): ?>
              <option value="<?php echo $p; ?>" <?php echo $filterPriority===$p?'selected':''; ?>><?php echo $p; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Status</label>
          <select name="active" class="form-control">
            <option value="">All</option>
            <option value="Y" <?php echo $filterActive==='Y'?'selected':''; ?>>Active</option>
            <option value="N" <?php echo $filterActive==='N'?'selected':''; ?>>Inactive</option>
          </select>
        </div>
        <div class="form-group">
          <label>Bangalore</label>
          <select name="blr" class="form-control">
            <option value="">All</option>
            <option value="Y" <?php echo $filterBlr==='Y'?'selected':''; ?>>Bangalore Only</option>
          </select>
        </div>
        <div class="form-group">
          <label>&nbsp;</label>
          <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-secondary">&#128269; Filter</button>
            <a href="ExamDirectoryList.php" class="btn btn-secondary">&times; Clear</a>
          </div>
        </div>
      </div>
    </form>

    <?php if (empty($rows)): ?>
      <p style="text-align:center;color:#6b7280;padding:30px 0;">No directory entries found. <a href="AddEditExamDirectory.php?ExamDirectoryId=0">Add the first one</a>.</p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:20%">Exam / College</th>
            <th style="width:11%">Track</th>
            <th style="width:9%">Priority</th>
            <th style="width:13%">Reg. Deadline</th>
            <th style="width:9%">Fee</th>
            <th style="width:5%">Blr</th>
            <th style="width:7%">Visible</th>
            <th style="width:26%">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $inactive = ($r['Active'] === 'N');
            $nameFirstLine = strtok($r['ExamName'], "\n");
          ?>
          <tr class="<?php echo $inactive ? 'inactive-row' : ''; ?>">
            <td>
              <strong><?php echo htmlspecialchars($nameFirstLine); ?></strong>
              <div style="font-size:.78rem;color:#6b7280;"><?php echo htmlspecialchars($r['ShortName']); ?></div>
            </td>
            <td>
              <?php if ($r['TrackLabel']): ?>
                <span class="ed-badge" style="background:<?php echo htmlspecialchars($r['BgColorHex']); ?>;color:<?php echo htmlspecialchars($r['ColorHex']); ?>;">
                  <?php echo htmlspecialchars($r['TrackLabel']); ?>
                </span>
              <?php else: ?><em style="color:#9ca3af;">—</em><?php endif; ?>
            </td>
            <td><span class="ed-badge ed-pr-<?php echo htmlspecialchars($r['Priority']); ?>"><?php echo htmlspecialchars($r['Priority']); ?></span></td>
            <td style="font-size:.82rem;">
              <?php echo htmlspecialchars($r['RegDeadlineText'] ?: '—'); ?>
              <?php if ($r['RegDeadlineDate']): ?>
                <div style="color:#9ca3af;"><?php echo htmlspecialchars($r['RegDeadlineDate']); ?></div>
              <?php endif; ?>
            </td>
            <td style="font-size:.82rem;"><?php echo htmlspecialchars($r['FeeText'] ?: '—'); ?></td>
            <td><?php echo $r['IsBangalore'] === 'Y' ? '&#127775;' : ''; ?></td>
            <td>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="ExamDirectoryId" value="<?php echo $r['ExamDirectoryId']; ?>">
                <button type="submit" class="btn act-btn <?php echo $inactive ? 'btn-secondary' : 'btn-success'; ?>" title="Toggle visible / hidden">
                  <?php echo $inactive ? 'Hidden' : 'Visible'; ?>
                </button>
              </form>
            </td>
            <td style="white-space:nowrap;">
              <a href="AddEditExamDirectory.php?ExamDirectoryId=<?php echo $r['ExamDirectoryId']; ?>" class="btn btn-primary act-btn">&#9998; Edit</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete this directory entry?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="ExamDirectoryId" value="<?php echo $r['ExamDirectoryId']; ?>">
                <button type="submit" class="btn btn-danger act-btn">&#128465;</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:10px;font-size:.82rem;color:#6b7280;">
      <?php echo count($rows); ?> entr<?php echo count($rows) !== 1 ? 'ies' : 'y'; ?> found.
    </div>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
