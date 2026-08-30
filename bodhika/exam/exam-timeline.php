<?php
/**
 * exam/exam-timeline.php — ExamPath Directory: "Timeline & Deadlines"
 *
 * Month-grouped chronological view of registration deadlines from the
 * ExamPath Directory (see exam-directory.php for the main listing page).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$rows = Database::fetchAll(
    "SELECT d.ExamDirectoryId, d.ExamName, d.ShortName, d.RegDeadlineDate, d.RegDeadlineText,
            d.ExamDateText, d.Priority, d.OfficialUrl, t.Label AS TrackLabel, t.ColorHex, t.BgColorHex
       FROM exam_directory d
       LEFT JOIN exam_directory_track t ON t.TrackId = d.TrackId
      WHERE d.Active = 'Y' AND d.RegDeadlineDate IS NOT NULL
      ORDER BY d.RegDeadlineDate ASC");

$today = new DateTime('today');
$months = [];
foreach ($rows as $r) {
    $dl  = new DateTime($r['RegDeadlineDate']);
    $key = $dl->format('Y-m');
    $months[$key]['label'] = $dl->format('F Y');
    $months[$key]['events'][] = $r;
}

$pageTitle = 'ExamPath Directory — Timeline & Deadlines';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .tl-month { margin-bottom:26px; }
  .tl-month-label { font-weight:800; font-size:1.05rem; color:#1e293b; margin-bottom:10px;
                     padding-bottom:6px; border-bottom:2px solid #e2e8f0; }
  .tl-event { display:flex; gap:14px; padding:12px 0; border-bottom:1px solid #f1f5f9; }
  .tl-event:last-child { border-bottom:none; }
  .tl-date { width:54px; flex-shrink:0; text-align:center; }
  .tl-date .d { font-size:1.3rem; font-weight:800; color:#1e293b; line-height:1; }
  .tl-date .dow { font-size:.68rem; color:#94a3b8; text-transform:uppercase; font-weight:700; }
  .tl-body { flex:1; }
  .tl-name { font-weight:700; color:#1e293b; font-size:.92rem; }
  .tl-track { display:inline-block; padding:1px 8px; border-radius:20px; font-size:.68rem; font-weight:700; margin-left:6px; }
  .tl-meta { font-size:.78rem; color:#64748b; margin-top:3px; }
  .tl-pr { display:inline-block; padding:1px 8px; border-radius:20px; font-size:.68rem; font-weight:700; }
  .tl-pr-CRITICAL { background:#fee2e2; color:#991b1b; }
  .tl-pr-HIGH     { background:#ffedd5; color:#9a3412; }
  .tl-pr-MEDIUM   { background:#fef9c3; color:#854d0e; }
  .tl-pr-OPTIONAL { background:#e0e7ff; color:#3730a3; }
  .tl-pr-FUTURE   { background:#f3f4f6; color:#374151; }
  .tl-past { opacity:.5; }
</style>

<div class="card">
  <div class="card-header">&#128197; ExamPath Directory — Timeline &amp; Deadlines</div>
  <div class="card-body">

    <?php if (empty($months)): ?>
      <p style="text-align:center;color:#6b7280;padding:30px 0;">No dated deadlines to show yet.</p>
    <?php else: ?>
      <?php foreach ($months as $key => $m): ?>
        <div class="tl-month">
          <div class="tl-month-label"><?php echo htmlspecialchars($m['label']); ?></div>
          <?php foreach ($m['events'] as $ev):
            $dl = new DateTime($ev['RegDeadlineDate']);
            $isPast = $dl < $today;
            $lines = explode("\n", $ev['ExamName'], 2);
          ?>
            <div class="tl-event <?php echo $isPast ? 'tl-past' : ''; ?>">
              <div class="tl-date">
                <div class="d"><?php echo $dl->format('d'); ?></div>
                <div class="dow"><?php echo $dl->format('D'); ?></div>
              </div>
              <div class="tl-body">
                <div class="tl-name">
                  <?php echo htmlspecialchars($lines[0]); ?>
                  <?php if ($ev['TrackLabel']): ?>
                    <span class="tl-track" style="background:<?php echo htmlspecialchars($ev['BgColorHex']); ?>;color:<?php echo htmlspecialchars($ev['ColorHex']); ?>;">
                      <?php echo htmlspecialchars($ev['TrackLabel']); ?>
                    </span>
                  <?php endif; ?>
                </div>
                <div class="tl-meta">
                  <span class="tl-pr tl-pr-<?php echo htmlspecialchars($ev['Priority']); ?>"><?php echo htmlspecialchars($ev['Priority']); ?></span>
                  Registration deadline: <?php echo htmlspecialchars($ev['RegDeadlineText'] ?: $ev['RegDeadlineDate']); ?>
                  <?php if ($ev['ExamDateText']): ?> · Exam: <?php echo htmlspecialchars($ev['ExamDateText']); ?><?php endif; ?>
                  <?php if ($ev['OfficialUrl']): ?>
                    · <a href="<?php echo htmlspecialchars($ev['OfficialUrl']); ?>" target="_blank" rel="noopener">Official site &#8599;</a>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
