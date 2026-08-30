<?php
/**
 * exam/performance.php — My Performance (student-facing)
 *
 * One card per subject the student has completed at least one exam in,
 * each showing a score-trend line chart across their most recent (up to)
 * 10 completed exams in that subject — chronological order, oldest to
 * newest, so the line reads left-to-right the way a trend naturally does.
 *
 * "Completed" mirrors the definition already established in exam/history.php
 * (se.Description IN ('Pass','Fail')) so the two pages never disagree about
 * what counts as a finished attempt.
 *
 * This is deliberately an at-a-glance overview across ALL subjects at once
 * (no subject picker) — exam/history.php already covers the single-subject
 * drill-down with the full attempts table, rank, and attempt-limit details;
 * this page is the complementary "how am I doing, subject by subject" view.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$pageTitle = 'My Performance';
$myUid     = Auth::currentUserId();
const PERF_LIMIT = 10;

/* ── Load every completed attempt for this student, most-recent first ───── */
$rows = [];
try {
    $rows = Database::fetchAll(
        "SELECT se.StudentExamId, se.Score, se.MarksOutOf, se.Description,
                COALESCE(se.ExamDate, se.CreateDate) AS AttemptDate,
                e.ExamName, COALESCE(s.SubjectName, 'Uncategorized') AS SubjectName
           FROM studentexam se
      LEFT JOIN examinfo   e ON e.ExamInfoId    = se.ExamInfoId
      LEFT JOIN subjectinfo s ON s.SubjectInfoId = se.SubjectInfoId
          WHERE se.UserInfoId = ?
            AND se.Description IN ('Pass','Fail')
            AND se.MarksOutOf > 0
          ORDER BY AttemptDate DESC",
        [$myUid]);
} catch (Exception $e) {
    $rows = [];
}

/* ── Group by subject, keep only the most recent PERF_LIMIT each,
      then flip each subject's slice back to chronological (oldest→newest)
      order for a natural left-to-right trend line. ─────────────────────── */
$bySubject = [];
foreach ($rows as $r) {
    $subj = $r['SubjectName'];
    if (count($bySubject[$subj] ?? []) >= PERF_LIMIT) continue; // rows already DESC — first N are the most recent N
    $bySubject[$subj][] = $r;
}
foreach ($bySubject as $subj => $attempts) {
    $bySubject[$subj] = array_reverse($attempts);
}
ksort($bySubject);

$hasAnyData = !empty($bySubject);

/* ── Pre-build Chart.js payload per subject (PHP-side, so the <script>
      block below only has to json_encode ready-made arrays) ─────────────── */
$subjectCharts = [];
foreach ($bySubject as $subj => $attempts) {
    $labels = [];
    $scores = [];
    foreach ($attempts as $a) {
        $dt = $a['AttemptDate'] ? date('d M', strtotime($a['AttemptDate'])) : '';
        $labels[] = $dt !== '' ? $dt : ($a['ExamName'] ?? '');
        $scores[] = (int)round($a['MarksOutOf'] > 0 ? $a['Score'] / $a['MarksOutOf'] * 100 : 0);
    }
    $latest  = end($attempts);
    $best    = $scores ? max($scores) : 0;
    $average = $scores ? round(array_sum($scores) / count($scores), 1) : 0;

    $subjectCharts[] = [
        'subject' => $subj,
        'labels'  => $labels,
        'scores'  => $scores,
        'count'   => count($attempts),
        'latest'  => $scores ? end($scores) : null,
        'best'    => $best,
        'average' => $average,
    ];
}

$pageHead = '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .perf-intro{color:var(--tx-muted,#718096);font-size:.88rem;margin-bottom:1.1rem;}
  .perf-grid{display:flex;flex-wrap:wrap;gap:16px;}
  .perf-card{flex:1;min-width:340px;}
  .perf-card-head{display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap;}
  .perf-card-title{font-weight:700;color:#1a365d;font-size:1rem;}
  .perf-card-note{font-size:.75rem;color:#94a3b8;}
  .perf-mini-row{display:flex;gap:10px;margin:10px 0 4px;flex-wrap:wrap;}
  .perf-mini{flex:1;min-width:80px;text-align:center;border-radius:8px;padding:6px 8px;background:#f8fafc;}
  .perf-mini .pv{font-size:1rem;font-weight:800;line-height:1.1;}
  .perf-mini .pl{font-size:.68rem;color:#64748b;text-transform:uppercase;letter-spacing:.03em;margin-top:1px;}
  .perf-empty{padding:40px 20px;text-align:center;color:#94a3b8;}
</style>

<h1 style="font-size:1.3rem;font-weight:800;color:var(--clr-primary,#1a365d);margin-bottom:6px;">
  &#128200; My Performance
</h1>
<p class="perf-intro">
  Score trend across your most recent completed exams in each subject
  (up to the last <?php echo PERF_LIMIT; ?> per subject).
</p>

<?php if (!$hasAnyData): ?>
  <div class="card">
    <div class="perf-empty">
      No completed exams yet. Once you finish and get a result for an exam, it'll show up here — subject by subject.
      <div style="margin-top:14px;"><a href="search.php" class="btn btn-primary btn-sm">Browse Available Exams</a></div>
    </div>
  </div>
<?php else: ?>

  <div class="perf-grid">
    <?php foreach ($subjectCharts as $i => $sc): ?>
    <div class="card perf-card">
      <div class="card-body">
        <div class="perf-card-head">
          <span class="perf-card-title"><?php echo htmlspecialchars($sc['subject']); ?></span>
          <span class="perf-card-note">
            <?php echo $sc['count']; ?> completed attempt<?php echo $sc['count'] !== 1 ? 's' : ''; ?> shown
          </span>
        </div>

        <div class="perf-mini-row">
          <div class="perf-mini">
            <div class="pv" style="color:#2563eb;"><?php echo $sc['latest'] !== null ? $sc['latest'] . '%' : '—'; ?></div>
            <div class="pl">Most Recent</div>
          </div>
          <div class="perf-mini">
            <div class="pv" style="color:#059669;"><?php echo $sc['best']; ?>%</div>
            <div class="pl">Best</div>
          </div>
          <div class="perf-mini">
            <div class="pv" style="color:#7c3aed;"><?php echo $sc['average']; ?>%</div>
            <div class="pl">Average</div>
          </div>
        </div>

        <?php if ($sc['count'] < 2): ?>
          <p style="padding:18px 0 4px;color:#94a3b8;text-align:center;font-size:.82rem;">
            Complete at least one more exam in this subject to see a trend line.
          </p>
        <?php else: ?>
          <div style="position:relative;height:180px;margin-top:8px;">
            <canvas id="perfChart<?php echo (int)$i; ?>"></canvas>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <script>
  (function() {
    var subjectCharts = <?php echo json_encode($subjectCharts, JSON_UNESCAPED_UNICODE); ?>;
    subjectCharts.forEach(function(sc, i) {
      if (sc.count < 2) return;
      var el = document.getElementById('perfChart' + i);
      if (!el) return;
      new Chart(el, {
        type: 'line',
        data: {
          labels: sc.labels,
          datasets: [{
            label: sc.subject + ' — Score %',
            data: sc.scores,
            borderColor: '#4f46e5',
            backgroundColor: '#4f46e533',
            tension: 0.3,
            fill: true,
            pointRadius: 4,
            pointBackgroundColor: '#4f46e5',
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { beginAtZero: true, max: 100, ticks: { callback: function(v) { return v + '%'; } } },
            x: { ticks: { maxRotation: 0, autoSkip: true } },
          },
        },
      });
    });
  })();
  </script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
