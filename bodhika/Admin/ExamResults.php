<?php
/**
 * Admin/ExamResults.php — Exam-level results dashboard.
 *
 * Allows admins to:
 *   - View all student exam attempts in one place
 *   - Filter by exam, subject, grade, pass/fail status, and date range
 *   - Sort by score (%), date, or student name
 *   - See summary stats and a score-distribution chart
 *   - Jump directly to an individual attempt's result page
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

/* ── Filter inputs ───────────────────────────────────────────────────────── */
$filterExam    = filter_input(INPUT_GET, 'exam',    FILTER_VALIDATE_INT) ?: 0;
$filterSubject = filter_input(INPUT_GET, 'subject', FILTER_VALIDATE_INT) ?: 0;
$filterGrade   = filter_input(INPUT_GET, 'grade',   FILTER_VALIDATE_INT) ?: 0;
$filterStatus  = in_array($_GET['status'] ?? '', ['Pass', 'Fail', ''], true) ? ($_GET['status'] ?? '') : '';
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo   = trim($_GET['date_to']   ?? '');
$filterStudent  = trim($_GET['student']   ?? '');
$sortBy         = $_GET['sort'] ?? 'date_desc';
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 50;

$validSorts = ['score_desc', 'score_asc', 'date_desc', 'date_asc', 'name_asc', 'name_desc'];
if (!in_array($sortBy, $validSorts, true)) $sortBy = 'date_desc';

$orderSQL = match ($sortBy) {
    'score_desc' => '(se.Score / NULLIF(se.MarksOutOf, 0)) DESC, AttemptDate DESC',
    'score_asc'  => '(se.Score / NULLIF(se.MarksOutOf, 0)) ASC,  AttemptDate DESC',
    'date_desc'  => 'AttemptDate DESC',
    'date_asc'   => 'AttemptDate ASC',
    'name_asc'   => 'u.FstName ASC,  u.LstName ASC',
    'name_desc'  => 'u.FstName DESC, u.LstName DESC',
    default      => 'AttemptDate DESC',
};

/* ── Build WHERE clause ──────────────────────────────────────────────────── */
$where  = ['(se.Description IS NOT NULL AND se.Description != \'\')'];
$params = [];

if ($filterExam    > 0) { $where[] = 'se.ExamInfoId = ?';    $params[] = $filterExam; }
if ($filterSubject > 0) { $where[] = 'e.SubjectInfoId = ?';  $params[] = $filterSubject; }
if ($filterGrade   > 0) { $where[] = 'e.GradeInfoId = ?';    $params[] = $filterGrade; }
if ($filterStatus  !== '') { $where[] = 'se.Description = ?'; $params[] = $filterStatus; }
if ($filterStudent !== '') {
    $where[]  = '(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)';
    $like     = '%' . $filterStudent . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterDateFrom !== '') {
    $where[]  = 'COALESCE(se.ExamDate, se.CreateDate) >= ?';
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
    $where[]  = 'COALESCE(se.ExamDate, se.CreateDate) <= ?';
    $params[] = $filterDateTo . ' 23:59:59';
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

/* ── Core SELECT (shared for count + paged rows) ─────────────────────────── */
$joinSQL = "FROM studentexam se
            LEFT JOIN examinfo    e ON e.ExamInfoId   = se.ExamInfoId
            LEFT JOIN gradeinfo   g ON g.GradeInfoId  = e.GradeInfoId
            LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
            LEFT JOIN userinfo    u ON u.UserInfoId   = se.UserInfoId";

$selectCols = "se.StudentExamId,
               se.ExamInfoId,
               se.UserInfoId,
               COALESCE(se.Score,        0)               AS Score,
               COALESCE(se.MarksOutOf,   e.NumOfQuestions, 0) AS MarksOutOf,
               COALESCE(se.Description, '') AS Description,
               COALESCE(se.TimeTaken,   0)               AS TimeTaken,
               se.CorrectCount,
               se.WrongCount,
               se.SkippedCount,
               COALESCE(se.ExamDate, se.CreateDate)       AS AttemptDate,
               e.ExamName,
               COALESCE(e.MinPassing, 0)                  AS MinPassing,
               g.GradeName,
               s.SubjectName,
               u.FstName, u.LstName, u.LoginName AS StudentLogin";

/* Total count for pagination */
try {
    $totalRow = Database::fetchOne(
        "SELECT COUNT(*) AS cnt $joinSQL $whereSQL", $params);
    $totalCount = (int)($totalRow['cnt'] ?? 0);
} catch (Exception $e) {
    $totalCount = 0;
}

$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

/* Paged rows */
$rows = [];
try {
    $rows = Database::fetchAll(
        "SELECT $selectCols $joinSQL $whereSQL ORDER BY $orderSQL LIMIT $perPage OFFSET $offset",
        $params);
} catch (Exception $e) {
    /* Graceful — table may not have all columns yet */
}

/* ── Normalise Description (recalculate Pass/Fail inline) ─────────────────
   MinPassing stored as 0-100 percentage, Score is on MarksOutOf basis.      */
foreach ($rows as &$r) {
    $score      = (float)($r['Score']      ?? 0);
    $marksOutOf = (float)($r['MarksOutOf'] ?? 0);
    $r['ScorePercent'] = $marksOutOf > 0 ? round($score / $marksOutOf * 100, 1) : 0;
    $minPass = min(100, max(0, (int)($r['MinPassing'] ?? 0)));
    if ($minPass > 0 && $marksOutOf > 0) {
        $r['Description'] = ($r['ScorePercent'] >= $minPass) ? 'Pass' : 'Fail';
    }
}
unset($r);

/* ── Summary stats (across ALL matching rows, not just current page) ──────── */
$stats = ['total' => 0, 'pass' => 0, 'fail' => 0, 'avgPct' => 0, 'maxPct' => 0, 'minPct' => 100];
try {
    $statRow = Database::fetchOne(
        "SELECT COUNT(*)                                                AS total,
                SUM(se.Description = 'Pass')                           AS pass_cnt,
                SUM(se.Description = 'Fail')                           AS fail_cnt,
                AVG(se.Score / NULLIF(se.MarksOutOf, 0) * 100)         AS avg_pct,
                MAX(se.Score / NULLIF(se.MarksOutOf, 0) * 100)         AS max_pct,
                MIN(se.Score / NULLIF(se.MarksOutOf, 0) * 100)         AS min_pct
         $joinSQL $whereSQL", $params);
    if ($statRow) {
        $stats['total']  = (int)$statRow['total'];
        $stats['pass']   = (int)$statRow['pass_cnt'];
        $stats['fail']   = (int)$statRow['fail_cnt'];
        $stats['avgPct'] = round((float)($statRow['avg_pct'] ?? 0), 1);
        $stats['maxPct'] = round((float)($statRow['max_pct'] ?? 0), 1);
        $stats['minPct'] = round((float)($statRow['min_pct'] ?? 0), 1);
    }
} catch (Exception $e) {}

/* ── Score distribution for chart (buckets: 0-9, 10-19, … 90-100) ─────────
   Done from full matching set, not paginated.                                */
$buckets = array_fill(0, 10, 0); // index 0 = 0-9%, …, index 9 = 90-100%
try {
    $distRows = Database::fetchAll(
        "SELECT FLOOR(se.Score / NULLIF(se.MarksOutOf,0) * 100 / 10) AS bucket, COUNT(*) AS cnt
         $joinSQL $whereSQL
         GROUP BY bucket ORDER BY bucket",
        $params);
    foreach ($distRows as $dr) {
        $b = min(9, (int)($dr['bucket'] ?? 0));
        if ($b >= 0) $buckets[$b] += (int)$dr['cnt'];
    }
} catch (Exception $e) {}

/* ── Dropdown data ───────────────────────────────────────────────────────── */
$exams    = Database::fetchAll("SELECT ExamInfoId, ExamName FROM examinfo ORDER BY ExamName");
$subjects = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo ORDER BY SubjectName");
$grades   = Database::fetchAll("SELECT GradeInfoId, GradeName FROM gradeinfo ORDER BY GradeName");

/* ── Helper: build current query string preserving all params except target ─ */
function qsExcept(array $except = [], array $override = []): string {
    $p = $_GET;
    foreach ($except as $k) unset($p[$k]);
    $p = array_merge($p, $override);
    return $p ? '?' . http_build_query($p) : '';
}

$pageTitle = 'Exam Results';
$pageHead  = '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
include __DIR__ . '/../includes/header.php';
?>
<style>
  /* Stat chips */
  .stat-chip { display:inline-flex;flex-direction:column;align-items:center;
               padding:10px 20px;border-radius:10px;font-size:.8rem;min-width:90px; }
  .stat-chip strong { font-size:1.5rem;font-weight:800;line-height:1.2; }

  /* Filter bar */
  .filter-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 12px;align-items:end; }
  .filter-grid .form-group { margin-bottom:0; }

  /* Sort header helper */
  .sortable { cursor:pointer;white-space:nowrap;user-select:none; }
  .sortable:hover { color:#3182ce; }
  .sort-arrow { font-size:.7rem;margin-left:3px;opacity:.6; }

  /* Pass/Fail badges */
  .badge-pass { background:#dcfce7;color:#166534;padding:2px 10px;border-radius:10px;font-size:.75rem;font-weight:700; }
  .badge-fail { background:#fee2e2;color:#991b1b;padding:2px 10px;border-radius:10px;font-size:.75rem;font-weight:700; }

  /* Score bar */
  .score-bar-wrap { display:flex;align-items:center;gap:6px; }
  .score-bar-bg   { flex:1;height:7px;background:#e2e8f0;border-radius:4px;min-width:50px; }
  .score-bar-fill { height:100%;border-radius:4px;transition:width .3s; }

  /* Pagination */
  .pagination { display:flex;gap:4px;flex-wrap:wrap;margin-top:12px; }
  .pg-btn { padding:4px 12px;border:1px solid #d1d5db;border-radius:5px;
            background:#fff;color:#374151;font-size:.82rem;text-decoration:none; }
  .pg-btn:hover { background:#f3f4f6; }
  .pg-btn.active { background:#3b82f6;color:#fff;border-color:#3b82f6;font-weight:700; }
  .pg-btn.disabled { opacity:.4;pointer-events:none; }
</style>

<?php
/* Build export URL carrying all current filters */
$exportParams = array_filter([
    'type'      => 'results',
    'exam'      => $filterExam    ?: null,
    'subject'   => $filterSubject ?: null,
    'grade'     => $filterGrade   ?: null,
    'status'    => $filterStatus  ?: null,
    'date_from' => $filterDateFrom ?: null,
    'date_to'   => $filterDateTo   ?: null,
    'student'   => $filterStudent  ?: null,
    'sort'      => $sortBy,
]);
$exportUrl = '../exam/export-excel.php?' . http_build_query($exportParams);
?>

<!-- ── Filter panel ────────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#128269; Filter Results</span>
    <?php if ($totalCount > 0): ?>
      <a href="<?php echo htmlspecialchars($exportUrl); ?>"
         class="btn btn-sm" style="background:#217346;color:#fff;font-weight:700;">
        &#128190; Export XL (<?php echo number_format($totalCount); ?> records)
      </a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <form method="get" action="ExamResults.php">
      <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
      <div class="filter-grid">

        <div class="form-group">
          <label>Exam</label>
          <select name="exam" class="form-control">
            <option value="">All Exams</option>
            <?php foreach ($exams as $ex): ?>
              <option value="<?php echo (int)$ex['ExamInfoId']; ?>"
                <?php echo $filterExam === (int)$ex['ExamInfoId'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($ex['ExamName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Subject</label>
          <select name="subject" class="form-control">
            <option value="">All Subjects</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?php echo (int)$s['SubjectInfoId']; ?>"
                <?php echo $filterSubject === (int)$s['SubjectInfoId'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($s['SubjectName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Grade</label>
          <select name="grade" class="form-control">
            <option value="">All Grades</option>
            <?php foreach ($grades as $gr): ?>
              <option value="<?php echo (int)$gr['GradeInfoId']; ?>"
                <?php echo $filterGrade === (int)$gr['GradeInfoId'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($gr['GradeName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Result</label>
          <select name="status" class="form-control">
            <option value="">All Results</option>
            <option value="Pass" <?php echo $filterStatus === 'Pass' ? 'selected' : ''; ?>>Pass</option>
            <option value="Fail" <?php echo $filterStatus === 'Fail' ? 'selected' : ''; ?>>Fail</option>
          </select>
        </div>

        <div class="form-group">
          <label>Date From</label>
          <input type="date" name="date_from" class="form-control"
                 value="<?php echo htmlspecialchars($filterDateFrom); ?>">
        </div>

        <div class="form-group">
          <label>Date To</label>
          <input type="date" name="date_to" class="form-control"
                 value="<?php echo htmlspecialchars($filterDateTo); ?>">
        </div>

        <div class="form-group">
          <label>Student Name / Login</label>
          <input type="text" name="student" class="form-control"
                 value="<?php echo htmlspecialchars($filterStudent); ?>"
                 placeholder="Search…">
        </div>

        <div style="display:flex;gap:8px;align-self:flex-end;">
          <button type="submit" class="btn btn-primary">&#128269; Apply</button>
          <a href="ExamResults.php" class="btn btn-secondary">Clear</a>
        </div>

      </div>
    </form>
  </div>
</div>

<!-- ── Summary stats ──────────────────────────────────────────────────────── -->
<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
  <div class="stat-chip" style="background:#ede9fe;color:#4f46e5;">
    <strong><?php echo number_format($stats['total']); ?></strong>
    <span>Attempts</span>
  </div>
  <div class="stat-chip" style="background:#dcfce7;color:#166534;">
    <strong><?php echo number_format($stats['pass']); ?></strong>
    <span>Passed</span>
  </div>
  <div class="stat-chip" style="background:#fee2e2;color:#991b1b;">
    <strong><?php echo number_format($stats['fail']); ?></strong>
    <span>Failed</span>
  </div>
  <div class="stat-chip" style="background:#fef9c3;color:#92400e;">
    <strong><?php echo $stats['total'] > 0 ? round($stats['pass'] / $stats['total'] * 100) : 0; ?>%</strong>
    <span>Pass Rate</span>
  </div>
  <div class="stat-chip" style="background:#e0f2fe;color:#0369a1;">
    <strong><?php echo $stats['avgPct']; ?>%</strong>
    <span>Avg Score</span>
  </div>
  <div class="stat-chip" style="background:#f0fdf4;color:#15803d;">
    <strong><?php echo $stats['maxPct']; ?>%</strong>
    <span>High Score</span>
  </div>
  <div class="stat-chip" style="background:#fff7ed;color:#c2410c;">
    <strong><?php echo $stats['minPct']; ?>%</strong>
    <span>Low Score</span>
  </div>
</div>

<!-- ── Score distribution chart ──────────────────────────────────────────── -->
<?php if ($stats['total'] > 0): ?>
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:16px 20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <span style="font-size:.8rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.05em;">
        &#128202; Score Distribution (all matching attempts)
      </span>
    </div>
    <div style="position:relative;height:220px;">
      <canvas id="distChart"></canvas>
    </div>
  </div>
</div>
<script>
(function(){
  var buckets  = <?php echo json_encode(array_values($buckets)); ?>;
  var labels   = ['0–9%','10–19%','20–29%','30–39%','40–49%',
                  '50–59%','60–69%','70–79%','80–89%','90–100%'];
  var minPass  = <?php
    /* derive typical pass mark from first matching row (best effort) */
    $firstPass = 0;
    if (!empty($rows) && (int)$rows[0]['MinPassing'] > 0) $firstPass = min(100, max(0, (int)$rows[0]['MinPassing']));
    echo json_encode($firstPass);
  ?>;
  /* Color bars: below minPass = red, at/above = green, unknown = blue */
  var colors = buckets.map(function(_, i) {
    var low = i * 10;
    if (minPass <= 0) return 'rgba(99,102,241,.75)';
    return low + 10 <= minPass ? 'rgba(229,62,62,.7)' : 'rgba(56,161,105,.75)';
  });
  new Chart(document.getElementById('distChart').getContext('2d'), {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{ label: 'Students', data: buckets, backgroundColor: colors,
                   borderRadius: 4, borderWidth: 0 }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(c) { return ' ' + c.raw + ' student' + (c.raw !== 1 ? 's' : ''); }
          }
        }
      },
      scales: {
        y: { ticks: { precision: 0, font: { size: 11 } }, grid: { color: '#f0f4f8' } },
        x: { ticks: { font: { size: 11 } }, grid: { display: false } }
      }
    }
  });
})();
</script>
<?php endif; ?>

<!-- ── Sort controls ──────────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;flex-wrap:wrap;">
  <span style="font-size:.82rem;color:#6b7280;font-weight:600;">Sort by:</span>
  <?php
    $sortOptions = [
      'score_desc' => '&#9660; Score (high)',
      'score_asc'  => '&#9650; Score (low)',
      'date_desc'  => '&#9660; Date (newest)',
      'date_asc'   => '&#9650; Date (oldest)',
      'name_asc'   => 'Name A→Z',
      'name_desc'  => 'Name Z→A',
    ];
    foreach ($sortOptions as $val => $label):
      $qs = qsExcept(['sort', 'page'], ['sort' => $val]);
  ?>
    <a href="ExamResults.php<?php echo $qs; ?>"
       class="btn btn-sm <?php echo $sortBy === $val ? 'btn-primary' : 'btn-secondary'; ?>"
       style="font-size:.78rem;padding:4px 12px;">
      <?php echo $label; ?>
    </a>
  <?php endforeach; ?>
  <span style="margin-left:auto;font-size:.8rem;color:#9ca3af;">
    <?php echo number_format($totalCount); ?> record<?php echo $totalCount !== 1 ? 's' : ''; ?>
    <?php if ($totalPages > 1): ?>
      &nbsp;&middot;&nbsp; Page <?php echo $page; ?> of <?php echo $totalPages; ?>
    <?php endif; ?>
  </span>
</div>

<!-- ── Results table ──────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <?php if (empty($rows)): ?>
      <p style="padding:20px;color:#718096;text-align:center;">
        No results match the selected filters.
      </p>
    <?php else: ?>
    <table class="tbl" style="font-size:.82rem;">
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Exam</th>
          <th>Grade / Subject</th>
          <th class="text-center">Score</th>
          <th class="text-center" style="min-width:130px;">Score %</th>
          <th class="text-center">&#10004; / &#10008; / &#8212;</th>
          <th class="text-center">Result</th>
          <th class="text-center">Time</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r):
          $sp      = $r['ScorePercent'];
          $barClr  = (int)$r['MinPassing'] > 0 && $sp >= (int)$r['MinPassing']
                     ? '#16a34a' : (#$sp >= 50 ? '#f59e0b' :
                       ($sp >= 50 ? '#f59e0b' : '#dc2626'));
          /* colour: green if pass, red otherwise */
          $barClr  = ($r['Description'] === 'Pass') ? '#16a34a' : '#dc2626';
          $rowNum  = $offset + $i + 1;
        ?>
        <tr class="<?php echo ($i%2===0)?'odd':'even'; ?>">
          <td style="color:#9ca3af;font-size:.78rem;"><?php echo $rowNum; ?></td>
          <td>
            <?php
              $name = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
              echo htmlspecialchars($name ?: ($r['StudentLogin'] ?? '—'));
            ?>
            <?php if ($r['StudentLogin'] ?? ''): ?>
              <br><span style="font-size:.73rem;color:#9ca3af;">
                <?php echo htmlspecialchars($r['StudentLogin']); ?>
              </span>
            <?php endif; ?>
          </td>
          <td><?php echo htmlspecialchars($r['ExamName'] ?? '—'); ?></td>
          <td>
            <span style="color:#6b7280;"><?php echo htmlspecialchars($r['GradeName'] ?? '—'); ?></span><br>
            <span><?php echo htmlspecialchars($r['SubjectName'] ?? '—'); ?></span>
          </td>
          <td class="text-center">
            <strong><?php echo (int)$r['Score']; ?></strong>
            <span style="color:#9ca3af;">/ <?php echo (int)$r['MarksOutOf']; ?></span>
          </td>
          <td>
            <div class="score-bar-wrap">
              <div class="score-bar-bg">
                <div class="score-bar-fill"
                     style="width:<?php echo min(100, $sp); ?>%;background:<?php echo $barClr; ?>;"></div>
              </div>
              <span style="font-size:.8rem;font-weight:700;color:<?php echo $barClr; ?>;min-width:36px;text-align:right;">
                <?php echo $sp; ?>%
              </span>
            </div>
          </td>
          <td class="text-center" style="white-space:nowrap;">
            <?php if ($r['CorrectCount'] !== null): ?>
              <span style="color:#059669;font-weight:700;"><?php echo (int)$r['CorrectCount']; ?></span>
              &nbsp;/&nbsp;
              <span style="color:#ef4444;font-weight:700;"><?php echo (int)$r['WrongCount']; ?></span>
              &nbsp;/&nbsp;
              <span style="color:#94a3b8;font-weight:700;"><?php echo (int)$r['SkippedCount']; ?></span>
            <?php else: echo '<span style="color:#94a3b8;">—</span>'; endif; ?>
          </td>
          <td class="text-center">
            <?php if ($r['Description'] !== ''): ?>
              <span class="badge-<?php echo strtolower($r['Description']); ?>">
                <?php echo htmlspecialchars($r['Description']); ?>
              </span>
            <?php else: echo '—'; endif; ?>
          </td>
          <td class="text-center" style="white-space:nowrap;">
            <?php $t = (int)$r['TimeTaken'];
              echo $t > 0 ? floor($t/60) . 'm ' . ($t%60) . 's' : '—'; ?>
          </td>
          <td style="white-space:nowrap;color:#6b7280;">
            <?php
              $dt = $r['AttemptDate'] ?? '';
              echo $dt ? date('d M Y', strtotime($dt)) . '<br><span style="font-size:.73rem;">'
                       . date('H:i', strtotime($dt)) . '</span>' : '—';
            ?>
          </td>
          <td>
            <a href="../exam/result.php?id=<?php echo (int)$r['StudentExamId']; ?>"
               class="btn btn-secondary btn-sm" style="white-space:nowrap;">
              &#128196; View
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="padding:12px 16px;">
      <div class="pagination">
        <?php
          $qs = function(int $pg) use ($sortBy): string {
              $p = array_merge($_GET, ['page' => $pg, 'sort' => $sortBy]);
              return '?' . http_build_query($p);
          };
        ?>
        <a href="ExamResults.php<?php echo $qs(max(1, $page-1)); ?>"
           class="pg-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">&#8592; Prev</a>
        <?php
          $start = max(1, $page - 3);
          $end   = min($totalPages, $page + 3);
          if ($start > 1)          echo '<span class="pg-btn disabled">…</span>';
          for ($pg = $start; $pg <= $end; $pg++):
        ?>
          <a href="ExamResults.php<?php echo $qs($pg); ?>"
             class="pg-btn <?php echo $pg === $page ? 'active' : ''; ?>">
            <?php echo $pg; ?>
          </a>
        <?php endfor;
          if ($end < $totalPages) echo '<span class="pg-btn disabled">…</span>'; ?>
        <a href="ExamResults.php<?php echo $qs(min($totalPages, $page+1)); ?>"
           class="pg-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Next &#8594;</a>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
