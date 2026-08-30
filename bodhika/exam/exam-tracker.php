<?php
/**
 * exam/exam-tracker.php — ExamPath Directory: "My Exam Tracker" / My Progress
 *
 * Per-student status against every ExamPath Directory entry, stored
 * server-side in exam_directory_progress (unlike the original static tool,
 * which only remembered this in browser localStorage) so it follows the
 * student across devices and logins.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$userId = Auth::currentUserId();

/* ── Handle status update ─────────────────────────────────────────────────── */
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
    header('Location: exam-tracker.php');
    exit;
}

$rows = Database::fetchAll(
    "SELECT d.ExamDirectoryId, d.ExamName, d.ShortName, d.RegDeadlineDate, d.RegDeadlineText,
            d.Priority, t.Label AS TrackLabel, t.ColorHex, t.BgColorHex,
            COALESCE(p.Status, 'not_started') AS MyStatus
       FROM exam_directory d
       LEFT JOIN exam_directory_track t ON t.TrackId = d.TrackId
       LEFT JOIN exam_directory_progress p
              ON p.ExamDirectoryId = d.ExamDirectoryId AND p.UserInfoId = ?
      WHERE d.Active = 'Y'
      ORDER BY (d.RegDeadlineDate IS NULL), d.RegDeadlineDate ASC, d.SortOrder ASC",
    [$userId]);

$counts = ['registered'=>0, 'done'=>0, 'skip'=>0, 'not_started'=>0];
foreach ($rows as $r) { $counts[$r['MyStatus']]++; }

$statusLabels = [
    'not_started' => 'Not Started',
    'registered'  => 'Registered',
    'done'        => 'Done',
    'skip'        => 'Skipping',
];

$pageTitle = 'ExamPath Directory — My Exam Tracker';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .ed-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px; }
  .ed-stat { border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; background:#fff; text-align:center; }
  .ed-stat .n { font-size:1.7rem; font-weight:800; }
  .ed-stat .l { font-size:.72rem; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }
  .ed-stat.registered .n { color:#2563eb; }
  .ed-stat.done .n       { color:#16a34a; }
  .ed-stat.skip .n       { color:#dc2626; }
  .ed-stat.remaining .n  { color:#64748b; }

  .tl-track { display:inline-block; padding:1px 8px; border-radius:20px; font-size:.68rem; font-weight:700; }
  .status-select { font-size:.82rem; padding:4px 8px; }
  .status-select.st-registered { background:#dbeafe; color:#1e40af; font-weight:700; }
  .status-select.st-done       { background:#dcfce7; color:#166534; font-weight:700; }
  .status-select.st-skip       { background:#fee2e2; color:#991b1b; font-weight:700; }
  .status-select.st-not_started{ background:#f8fafc; color:#475569; }
</style>

<div class="card">
  <div class="card-header">&#128203; My Exam Tracker</div>
  <div class="card-body">

    <div class="ed-stats">
      <div class="ed-stat registered"><div class="n"><?php echo $counts['registered']; ?></div><div class="l">Registered</div></div>
      <div class="ed-stat done"><div class="n"><?php echo $counts['done']; ?></div><div class="l">Completed</div></div>
      <div class="ed-stat skip"><div class="n"><?php echo $counts['skip']; ?></div><div class="l">Skipping</div></div>
      <div class="ed-stat remaining"><div class="n"><?php echo $counts['not_started']; ?></div><div class="l">Remaining</div></div>
    </div>

    <?php if (empty($rows)): ?>
      <p style="text-align:center;color:#6b7280;padding:30px 0;">No exams in the directory yet.</p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:32%">Exam / College</th>
            <th style="width:14%">Track</th>
            <th style="width:10%">Priority</th>
            <th style="width:22%">Reg. Deadline</th>
            <th style="width:22%">My Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $lines = explode("\n", $r['ExamName'], 2);
          ?>
          <tr>
            <td>
              <strong><?php echo htmlspecialchars($lines[0]); ?></strong>
              <div style="font-size:.78rem;color:#6b7280;"><?php echo htmlspecialchars($r['ShortName']); ?></div>
            </td>
            <td>
              <?php if ($r['TrackLabel']): ?>
                <span class="tl-track" style="background:<?php echo htmlspecialchars($r['BgColorHex']); ?>;color:<?php echo htmlspecialchars($r['ColorHex']); ?>;">
                  <?php echo htmlspecialchars($r['TrackLabel']); ?>
                </span>
              <?php else: ?><em style="color:#9ca3af;">—</em><?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($r['Priority']); ?></td>
            <td style="font-size:.82rem;"><?php echo htmlspecialchars($r['RegDeadlineText'] ?: '—'); ?></td>
            <td>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="ExamDirectoryId" value="<?php echo $r['ExamDirectoryId']; ?>">
                <select name="Status" class="form-control status-select st-<?php echo $r['MyStatus']; ?>" onchange="this.className='form-control status-select st-'+this.value; this.form.submit()">
                  <?php foreach ($statusLabels as $sv => $sl): ?>
                    <option value="<?php echo $sv; ?>" <?php echo $r['MyStatus']===$sv?'selected':''; ?>><?php echo $sl; ?></option>
                  <?php endforeach; ?>
                </select>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
