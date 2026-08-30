<?php
/**
 * exam/exam-directory.php — ExamPath Directory: "All Exams & Colleges"
 *
 * Read-only informational list of real-world external exams/colleges
 * (JEE, NEET, CLAT, NID, NDA, ...) curated by admins in
 * Admin/ExamDirectoryList.php. Completely separate from this app's own
 * testable exams (examinfo). Students can mark their personal status
 * (Registered / Done / Skipping) against each entry — stored server-side
 * in exam_directory_progress, not just in the browser.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$userId = Auth::currentUserId();

/* ── Handle status update (progressive-enhancement: auto-submits on change) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    Auth::validateCsrf();
    $edId   = (int)($_POST['ExamDirectoryId'] ?? 0);
    $status = $_POST['Status'] ?? 'not_started';
    if ($edId > 0 && in_array($status, ['not_started','registered','done','skip'], true)) {
        Database::execute(
            "INSERT INTO exam_directory_progress (UserInfoId, ExamDirectoryId, Status)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE Status = VALUES(Status)",
            [$userId, $edId, $status]);
    }
    $backUrl = $_SERVER['HTTP_REFERER'] ?? 'exam-directory.php';
    header('Location: ' . $backUrl);
    exit;
}

/* ── Filters ──────────────────────────────────────────────────────────────── */
$filterTrack = trim($_GET['track'] ?? '');
$filterBlr   = trim($_GET['blr']   ?? '');
$search      = trim($_GET['q']     ?? '');

$where  = ["d.Active = 'Y'"];
$params = [];
if ($filterTrack !== '') { $where[] = 't.TrackCode = ?'; $params[] = $filterTrack; }
if ($filterBlr === 'Y')  { $where[] = "d.IsBangalore = 'Y'"; }
if ($search !== '') {
    $where[]  = '(d.ExamName LIKE ? OR d.ShortName LIKE ? OR d.Outcome LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like]);
}

$sql = "SELECT d.*, t.TrackCode, t.Label AS TrackLabel, t.ColorHex, t.BgColorHex,
               p.Status AS MyStatus
        FROM exam_directory d
        LEFT JOIN exam_directory_track t ON t.TrackId = d.TrackId
        LEFT JOIN exam_directory_progress p
               ON p.ExamDirectoryId = d.ExamDirectoryId AND p.UserInfoId = ?
        WHERE " . implode(' AND ', $where) . "
        ORDER BY (d.RegDeadlineDate IS NULL), d.RegDeadlineDate ASC, d.SortOrder ASC";

$rows   = Database::fetchAll($sql, array_merge([$userId], $params));
$tracks = Database::fetchAll("SELECT TrackCode, Label FROM exam_directory_track WHERE Active='Y' ORDER BY SortOrder");

/* Stat cards computed off the *unfiltered* active set so they stay stable
   while the student plays with filters/search. */
$allActive = Database::fetchAll(
    "SELECT d.ExamDirectoryId, d.IsBangalore, d.RegDeadlineDate, p.Status
       FROM exam_directory d
       LEFT JOIN exam_directory_progress p
              ON p.ExamDirectoryId = d.ExamDirectoryId AND p.UserInfoId = ?
      WHERE d.Active = 'Y'", [$userId]);

$today = new DateTime('today');
$totalCount = count($allActive);
$closing60  = 0;
$doneOrRegistered = 0;
$bangaloreCount = 0;
foreach ($allActive as $r) {
    if (!empty($r['RegDeadlineDate'])) {
        $dl = new DateTime($r['RegDeadlineDate']);
        if ($dl >= $today && $today->diff($dl)->days <= 60) $closing60++;
    }
    if (in_array($r['Status'] ?? '', ['registered','done'], true)) $doneOrRegistered++;
    if ($r['IsBangalore'] === 'Y') $bangaloreCount++;
}

$statusLabels = [
    'not_started' => 'Not Started',
    'registered'  => 'Registered',
    'done'        => 'Done',
    'skip'        => 'Skipping',
];

$pageTitle = 'ExamPath Directory — All Exams & Colleges';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .ed-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:20px; }
  .ed-stat { border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.04); }
  .ed-stat .n { font-size:1.7rem; font-weight:800; color:#1e293b; }
  .ed-stat .l { font-size:.75rem; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }

  .ed-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
  .ed-chip { display:inline-flex; align-items:center; padding:6px 14px; border-radius:20px; font-size:.82rem;
             font-weight:700; border:2px solid #e2e8f0; background:#fff; color:#475569; text-decoration:none; }
  .ed-chip.active { border-color:#4f46e5; background:#eef2ff; color:#4338ca; }

  .ed-search { margin-bottom:20px; }
  .ed-search input { max-width:340px; }

  .ed-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:16px; }
  .ed-card { border:1px solid #e2e8f0; border-radius:14px; padding:16px; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.05); display:flex; flex-direction:column; gap:8px; }
  .ed-card-top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
  .ed-card-name { font-weight:800; font-size:1rem; color:#1e293b; line-height:1.3; }
  .ed-card-sub { font-size:.78rem; color:#94a3b8; margin-top:2px; }
  .ed-track-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.7rem; font-weight:700; white-space:nowrap; }
  .ed-urgency { width:46px; height:46px; border-radius:50%; display:flex; align-items:center; justify-content:center;
                font-size:.68rem; font-weight:800; color:#fff; flex-shrink:0; text-align:center; line-height:1.1; }
  .ed-details { font-size:.83rem; color:#475569; }
  .ed-meta { font-size:.78rem; color:#64748b; display:flex; flex-direction:column; gap:2px; }
  .ed-pr { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.7rem; font-weight:700; }
  .ed-pr-CRITICAL { background:#fee2e2; color:#991b1b; }
  .ed-pr-HIGH     { background:#ffedd5; color:#9a3412; }
  .ed-pr-MEDIUM   { background:#fef9c3; color:#854d0e; }
  .ed-pr-OPTIONAL { background:#e0e7ff; color:#3730a3; }
  .ed-pr-FUTURE   { background:#f3f4f6; color:#374151; }
  .ed-actions { display:flex; gap:8px; align-items:center; margin-top:auto; padding-top:8px; }
  .ed-actions select { font-size:.8rem; padding:4px 6px; }
  .ed-blr-tag { font-size:.7rem; color:#b45309; font-weight:700; }
</style>

<div class="card">
  <div class="card-header">&#127942; ExamPath Directory — All Exams &amp; Colleges</div>
  <div class="card-body">

    <div class="ed-stats">
      <div class="ed-stat"><div class="n"><?php echo $totalCount; ?></div><div class="l">Total Exams</div></div>
      <div class="ed-stat"><div class="n"><?php echo $closing60; ?></div><div class="l">Closing in 60 Days</div></div>
      <div class="ed-stat"><div class="n"><?php echo $doneOrRegistered; ?></div><div class="l">Registered / Done</div></div>
      <div class="ed-stat"><div class="n"><?php echo $bangaloreCount; ?></div><div class="l">Bangalore Based</div></div>
    </div>

    <div class="ed-chips">
      <a class="ed-chip <?php echo $filterTrack===''?'active':''; ?>" href="?<?php echo http_build_query(array_filter(['q'=>$search,'blr'=>$filterBlr])); ?>">All</a>
      <?php foreach ($tracks as $t):
        $qs = array_filter(['track'=>$t['TrackCode'],'q'=>$search,'blr'=>$filterBlr]); ?>
        <a class="ed-chip <?php echo $filterTrack===$t['TrackCode']?'active':''; ?>" href="?<?php echo http_build_query($qs); ?>">
          <?php echo htmlspecialchars($t['Label']); ?>
        </a>
      <?php endforeach; ?>
      <?php $blrQs = array_filter(['track'=>$filterTrack,'q'=>$search,'blr'=>$filterBlr==='Y'?'':'Y']); ?>
      <a class="ed-chip <?php echo $filterBlr==='Y'?'active':''; ?>" href="?<?php echo http_build_query($blrQs); ?>">
        &#127775; Bangalore Only
      </a>
    </div>

    <form method="get" class="ed-search">
      <input type="hidden" name="track" value="<?php echo htmlspecialchars($filterTrack); ?>">
      <input type="hidden" name="blr" value="<?php echo htmlspecialchars($filterBlr); ?>">
      <input type="text" name="q" class="form-control" placeholder="Search exam or college name…"
             value="<?php echo htmlspecialchars($search); ?>">
    </form>

    <?php if (empty($rows)): ?>
      <p style="text-align:center;color:#6b7280;padding:30px 0;">No exams match these filters.</p>
    <?php else: ?>
    <div class="ed-grid">
      <?php foreach ($rows as $r):
        $lines = explode("\n", $r['ExamName'], 2);
        $days = null;
        $urgColor = '#94a3b8';
        $urgText = '—';
        if (!empty($r['RegDeadlineDate'])) {
            $dl = new DateTime($r['RegDeadlineDate']);
            $days = (int)$today->diff($dl)->format('%r%a');
            if ($days < 0)      { $urgColor = '#94a3b8'; $urgText = 'Closed'; }
            elseif ($days === 0){ $urgColor = '#dc2626'; $urgText = 'Today'; }
            elseif ($days <= 14){ $urgColor = '#dc2626'; $urgText = $days.'d left'; }
            elseif ($days <= 30){ $urgColor = '#ea580c'; $urgText = $days.'d left'; }
            elseif ($days <= 60){ $urgColor = '#ca8a04'; $urgText = $days.'d left'; }
            else                { $urgColor = '#16a34a'; $urgText = $days.'d left'; }
        }
        $myStatus = $r['MyStatus'] ?? 'not_started';
      ?>
      <div class="ed-card">
        <div class="ed-card-top">
          <div>
            <div class="ed-card-name"><?php echo htmlspecialchars($lines[0]); ?></div>
            <?php if (!empty($lines[1])): ?><div class="ed-card-sub"><?php echo htmlspecialchars($lines[1]); ?></div><?php endif; ?>
            <?php if ($r['TrackLabel']): ?>
              <div style="margin-top:6px;">
                <span class="ed-track-badge" style="background:<?php echo htmlspecialchars($r['BgColorHex']); ?>;color:<?php echo htmlspecialchars($r['ColorHex']); ?>;">
                  <?php echo htmlspecialchars($r['TrackLabel']); ?>
                </span>
                <?php if ($r['IsBangalore']==='Y'): ?><span class="ed-blr-tag">&#127775; Bangalore</span><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="ed-urgency" style="background:<?php echo $urgColor; ?>;"><?php echo htmlspecialchars($urgText); ?></div>
        </div>

        <?php if ($r['DetailsText']): ?><div class="ed-details"><?php echo htmlspecialchars($r['DetailsText']); ?></div><?php endif; ?>

        <div class="ed-meta">
          <span><span class="ed-pr ed-pr-<?php echo htmlspecialchars($r['Priority']); ?>"><?php echo htmlspecialchars($r['Priority']); ?></span></span>
          <?php if ($r['ExamDateText']): ?><span>&#128197; Exam: <?php echo htmlspecialchars($r['ExamDateText']); ?></span><?php endif; ?>
          <?php if ($r['RegDeadlineText']): ?><span>&#9203; Reg. Deadline: <?php echo htmlspecialchars($r['RegDeadlineText']); ?></span><?php endif; ?>
          <?php if ($r['FeeText']): ?><span>&#128176; Fee: <?php echo htmlspecialchars($r['FeeText']); ?></span><?php endif; ?>
          <?php if ($r['Outcome']): ?><span>&#127942; <?php echo htmlspecialchars($r['Outcome']); ?></span><?php endif; ?>
        </div>

        <div class="ed-actions">
          <?php if ($r['OfficialUrl']): ?>
            <a href="<?php echo htmlspecialchars($r['OfficialUrl']); ?>" target="_blank" rel="noopener" class="btn btn-primary act-btn" style="font-size:.8rem;padding:4px 12px;">Register &#8599;</a>
          <?php endif; ?>
          <form method="post" style="margin-left:auto;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="ExamDirectoryId" value="<?php echo $r['ExamDirectoryId']; ?>">
            <select name="Status" class="form-control" onchange="this.form.submit()">
              <?php foreach ($statusLabels as $sv => $sl): ?>
                <option value="<?php echo $sv; ?>" <?php echo $myStatus===$sv?'selected':''; ?>><?php echo $sl; ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:14px;font-size:.82rem;color:#6b7280;">
      <?php echo count($rows); ?> exam<?php echo count($rows) !== 1 ? 's' : ''; ?> shown.
    </div>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
