<?php
/**
 * Admin/Charts.php — Performance Graph
 *
 * Replaces the old FusionCharts/Flash function library that used to live at
 * this path (renderChart()/renderChartHTML(), Flash Player was retired by
 * Adobe in Dec 2020 and no browser can run it anymore — the file defined
 * PHP functions only, with no HTML output at all, so navigating here always
 * rendered a blank page). This is a real page now, built on the same
 * PDO + Chart.js 4.4.1 pattern as exam/dashboard.php and Admin/marks.php.
 *
 * Three views, each filterable by Group and date range:
 *   1. Subject-wise Average Marks   — bar chart
 *   2. Pass / Fail + Score Distribution — doughnut + histogram
 *   3. Grade/Group Performance Trend — line chart over time
 *
 * Requires: migration_v44.sql (groupinfo/gradeinfo.GroupId) for the Group
 * filter and trend breakdown — degrades gracefully (single "Overall" series)
 * if that migration hasn't been applied yet.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

$pageTitle = 'Performance Graph';

/* ── Filters ──────────────────────────────────────────────────────────── */
$fGroup    = filter_input(INPUT_GET, 'group', FILTER_VALIDATE_INT) ?: 0;
$fDateFrom = trim($_GET['date_from'] ?? '');
$fDateTo   = trim($_GET['date_to']   ?? '');

$dateExpr = 'COALESCE(se.ExamDate, se.CreateDate)';
$where    = ['se.MarksOutOf > 0'];
$params   = [];

if ($fGroup > 0) {
    $where[]  = 'gr.GroupId = ?';
    $params[] = $fGroup;
}
if ($fDateFrom !== '') {
    $where[]  = "DATE($dateExpr) >= ?";
    $params[] = $fDateFrom;
}
if ($fDateTo !== '') {
    $where[]  = "DATE($dateExpr) <= ?";
    $params[] = $fDateTo;
}
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$baseJoins = "FROM studentexam se
              LEFT JOIN examinfo   e  ON e.ExamInfoId  = se.ExamInfoId
              LEFT JOIN subjectinfo s ON s.SubjectInfoId = se.SubjectInfoId
              LEFT JOIN gradeinfo   g  ON g.GradeInfoId  = e.GradeInfoId
              LEFT JOIN groupinfo   gr ON gr.GroupId     = g.GroupId";

/* ── Groups for the filter dropdown (empty list = migration not applied) ─── */
try {
    $groups = Database::fetchAll(
        "SELECT GroupId, GroupName FROM groupinfo WHERE Active = 'Y' ORDER BY SortOrder, GroupName", []);
} catch (Exception $e) {
    $groups = [];
}

/* ── 1. Subject-wise average marks (top 8 subjects by attempts) ─────────── */
$subjectPerf = [];
try {
    $subjectPerf = Database::fetchAll(
        "SELECT s.SubjectName,
                AVG(se.Score / NULLIF(se.MarksOutOf, 0) * 100) AS AvgPct,
                COUNT(*) AS Attempts
           $baseJoins
           $whereSQL
           GROUP BY s.SubjectInfoId
           ORDER BY Attempts DESC
           LIMIT 8", $params);
} catch (Exception $e) {
    $subjectPerf = [];
}

/* ── 2a. Pass / Fail distribution ────────────────────────────────────────── */
$passFail = ['Pass' => 0, 'Fail' => 0, 'Not evaluated' => 0];
try {
    $pfRows = Database::fetchAll(
        "SELECT
             CASE
                 WHEN se.Description = 'Pass' THEN 'Pass'
                 WHEN se.Description = 'Fail' THEN 'Fail'
                 WHEN se.Score IS NULL OR se.MarksOutOf IS NULL OR se.MarksOutOf = 0 THEN 'Not evaluated'
                 WHEN (se.Score / se.MarksOutOf * 100) >= LEAST(100, GREATEST(0, COALESCE(e.MinPassing, 40))) THEN 'Pass'
                 ELSE 'Fail'
             END AS Bucket,
             COUNT(*) AS Cnt
         $baseJoins
         $whereSQL
         GROUP BY Bucket", $params);
    foreach ($pfRows as $r) {
        $passFail[$r['Bucket']] = (int)$r['Cnt'];
    }
} catch (Exception $e) {
    // leave zeros — chart will show the empty state
}

/* ── 2b. Score distribution (5 buckets across 0–100%) ────────────────────── */
$scoreBuckets = ['0–20%' => 0, '21–40%' => 0, '41–60%' => 0, '61–80%' => 0, '81–100%' => 0];
try {
    $sdRows = Database::fetchAll(
        "SELECT (se.Score / NULLIF(se.MarksOutOf, 0) * 100) AS Pct
         $baseJoins
         $whereSQL", $params);
    foreach ($sdRows as $r) {
        $pct = $r['Pct'] === null ? null : (float)$r['Pct'];
        if ($pct === null) continue;
        if     ($pct <= 20)  $scoreBuckets['0–20%']++;
        elseif ($pct <= 40)  $scoreBuckets['21–40%']++;
        elseif ($pct <= 60)  $scoreBuckets['41–60%']++;
        elseif ($pct <= 80)  $scoreBuckets['61–80%']++;
        else                 $scoreBuckets['81–100%']++;
    }
} catch (Exception $e) {
    // leave zeros
}

/* ── 3. Grade/Group performance trend — last 6 months, one line per group ── */
$trendLabels   = [];
$trendDatasets = []; // GroupName => [month => avgPct]
try {
    $trendRows = Database::fetchAll(
        "SELECT DATE_FORMAT($dateExpr, '%Y-%m') AS Ym,
                COALESCE(gr.GroupName, 'Uncategorized') AS GroupName,
                AVG(se.Score / NULLIF(se.MarksOutOf, 0) * 100) AS AvgPct
         $baseJoins
         $whereSQL
           AND $dateExpr >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY Ym, GroupName
         ORDER BY Ym ASC", $params);

    // Build the last 6 calendar months as labels, even if a month has no data.
    for ($i = 5; $i >= 0; $i--) {
        $trendLabels[] = date('Y-m', strtotime("-$i months"));
    }
    $byGroup = [];
    foreach ($trendRows as $r) {
        $byGroup[$r['GroupName']][$r['Ym']] = round((float)$r['AvgPct'], 1);
    }
    foreach ($byGroup as $groupName => $map) {
        $trendDatasets[$groupName] = array_map(fn($ym) => $map[$ym] ?? null, $trendLabels);
    }
} catch (Exception $e) {
    $trendLabels = [];
    $trendDatasets = [];
}

$hasSubjectData = !empty($subjectPerf);
$hasPassFail    = array_sum($passFail) > 0;
$hasScoreData   = array_sum($scoreBuckets) > 0;
$hasTrendData   = !empty($trendDatasets);

// Pre-build the Chart.js dataset objects here (in PHP) so the <script> block
// below can just json_encode a ready-made array — no JS-side reconstruction.
$trendPalette = ['#0369a1','#22c55e','#f59e0b','#ef4444','#a855f7','#0ea5e9','#14b8a6','#f43f5e'];
$trendChartDatasets = [];
$i = 0;
foreach ($trendDatasets as $groupName => $data) {
    $color = $trendPalette[$i % count($trendPalette)];
    $trendChartDatasets[] = [
        'label'           => $groupName,
        'data'            => $data,
        'borderColor'     => $color,
        'backgroundColor' => $color . '33',
        'tension'         => 0.3,
        'spanGaps'        => true,
    ];
    $i++;
}

$pageHead = '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .pg-filter-bar{display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;margin-bottom:1.2rem;}
  .pg-grid{display:flex;gap:16px;flex-wrap:wrap;align-items:stretch;}
  .pg-col{flex:1;min-width:340px;}
  .pg-empty{padding:32px;text-align:center;color:#94a3b8;font-size:.85rem;}
  .pg-card-body{padding:16px;}
</style>

<div class="card" style="margin-bottom:1rem;">
  <div class="card-body">
    <form method="get" action="" class="pg-filter-bar">
      <div class="form-group" style="flex:1 1 180px;min-width:160px;">
        <label class="form-label">Group</label>
        <select class="form-control" name="group">
          <option value="">All groups</option>
          <?php foreach ($groups as $g): ?>
            <option value="<?= (int)$g['GroupId'] ?>" <?= $fGroup === (int)$g['GroupId'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($g['GroupName']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:0 0 auto;">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($fDateFrom) ?>">
      </div>
      <div class="form-group" style="flex:0 0 auto;">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($fDateTo) ?>">
      </div>
      <div style="display:flex;gap:.4rem;">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="Charts.php" class="btn btn-secondary btn-sm">Clear</a>
      </div>
    </form>
  </div>
</div>

<div class="pg-grid">

  <!-- Subject-wise average marks -->
  <div class="card pg-col">
    <div class="card-header">&#128202; Subject-wise Average Marks</div>
    <div class="card-body pg-card-body">
      <?php if (!$hasSubjectData): ?>
        <div class="pg-empty">No evaluated attempts found for the selected filters.</div>
      <?php else: ?>
        <canvas id="subjectChart" height="220"></canvas>
      <?php endif; ?>
    </div>
  </div>

  <!-- Pass / Fail distribution -->
  <div class="card pg-col">
    <div class="card-header">&#9989; Pass / Fail Distribution</div>
    <div class="card-body pg-card-body">
      <?php if (!$hasPassFail): ?>
        <div class="pg-empty">No evaluated attempts found for the selected filters.</div>
      <?php else: ?>
        <canvas id="passFailChart" height="220"></canvas>
      <?php endif; ?>
    </div>
  </div>

</div>

<div class="pg-grid" style="margin-top:16px;">

  <!-- Score distribution -->
  <div class="card pg-col">
    <div class="card-header">&#128200; Score Distribution</div>
    <div class="card-body pg-card-body">
      <?php if (!$hasScoreData): ?>
        <div class="pg-empty">No evaluated attempts found for the selected filters.</div>
      <?php else: ?>
        <canvas id="scoreDistChart" height="220"></canvas>
      <?php endif; ?>
    </div>
  </div>

  <!-- Grade/Group trend -->
  <div class="card pg-col">
    <div class="card-header">&#128201; Grade/Group Performance Trend <span style="font-weight:400;font-size:.75rem;color:var(--tx-muted);">(last 6 months)</span></div>
    <div class="card-body pg-card-body">
      <?php if (!$hasTrendData): ?>
        <div class="pg-empty">No evaluated attempts in the last 6 months for the selected filters.</div>
      <?php else: ?>
        <canvas id="trendChart" height="220"></canvas>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
(function() {
  <?php if ($hasSubjectData): ?>
  new Chart(document.getElementById('subjectChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_column($subjectPerf, 'SubjectName')) ?>,
      datasets: [{
        label: 'Average %',
        data: <?= json_encode(array_map(fn($r) => round((float)$r['AvgPct'], 1), $subjectPerf)) ?>,
        backgroundColor: '#0369a1cc',
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } },
    },
  });
  <?php endif; ?>

  <?php if ($hasPassFail): ?>
  new Chart(document.getElementById('passFailChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_keys($passFail)) ?>,
      datasets: [{
        data: <?= json_encode(array_values($passFail)) ?>,
        backgroundColor: ['#22c55ecc', '#ef4444cc', '#94a3b8cc'],
      }],
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } },
  });
  <?php endif; ?>

  <?php if ($hasScoreData): ?>
  new Chart(document.getElementById('scoreDistChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_keys($scoreBuckets)) ?>,
      datasets: [{
        label: 'Attempts',
        data: <?= json_encode(array_values($scoreBuckets)) ?>,
        backgroundColor: '#a855f7cc',
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    },
  });
  <?php endif; ?>

  <?php if ($hasTrendData): ?>
  new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($trendLabels) ?>,
      datasets: <?= json_encode($trendChartDatasets) ?>,
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'top' } },
      scales: { y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } },
    },
  });
  <?php endif; ?>
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
