<?php
/**
 * exam/dashboard.php — Admin overview dashboard.
 *
 * Every number here is a real query against this app's own schema — no
 * placeholder data. A few concepts from the original UI reference (e.g.
 * "Active Batches") don't exist in this schema, so they're mapped to the
 * closest honest equivalent and labeled accordingly:
 *   - "Active Batches"     -> Active Courses (teacher_subjects.Active='Y')
 *   - "Batch Performance"  -> Avg score % per Subject, broken out by Group
 *     (Primary/Secondary/UG/PG/...) using the exam/groups.php categorization
 *   - "Date Created"       -> exam_changelog's first 'CREATE' entry per exam
 *     (examinfo itself has no creation-timestamp column)
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: search.php'); exit; }

$pageTitle = 'Dashboard';

/* ── Stat cards ───────────────────────────────────────────────────────── */
function dashCount(string $sql, array $params = []): int {
    try { return (int)(Database::fetchOne($sql, $params)['c'] ?? 0); }
    catch (Exception $e) { return 0; }
}

$totalStudents = dashCount(
    "SELECT COUNT(*) AS c FROM userinfo u JOIN logininfo l ON l.LoginName = u.LoginName WHERE l.Role = 'STDNT'");

/* "Active Batches" has no real equivalent — teacher-run courses are the
   closest thing this schema has to an ongoing cohort/batch. */
$activeCourses = dashCount("SELECT COUNT(*) AS c FROM teacher_subjects WHERE Active = 'Y'");

try {
    $testsCreated = (int)(Database::fetchOne(
        "SELECT COUNT(*) AS c FROM examinfo WHERE COALESCE(IsDeleted,'N') = 'N'")['c'] ?? 0);
} catch (Exception $e) {
    $testsCreated = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM examinfo")['c'] ?? 0);
}

try {
    $avgPerformance = (float)(Database::fetchOne(
        "SELECT AVG(Score / NULLIF(MarksOutOf, 0) * 100) AS avgPct FROM studentexam WHERE MarksOutOf > 0")['avgPct'] ?? 0);
} catch (Exception $e) {
    $avgPerformance = 0.0;
}

/* ── Recent Test Papers ──────────────────────────────────────────────────── */
try {
    $recentPapers = Database::fetchAll(
        "SELECT e.ExamInfoId, e.ExamName, s.SubjectName, gr.GroupName,
                (SELECT COUNT(*) FROM exam_questions eq
                   JOIN questions q ON q.QuestionId = eq.QuestionId
                  WHERE eq.ExamInfoId = e.ExamInfoId AND COALESCE(q.IsDeleted,'N') = 'N') AS QCount,
                (SELECT MIN(c.ActionAt) FROM exam_changelog c
                  WHERE c.ExamInfoId = e.ExamInfoId AND c.Action = 'CREATE') AS CreatedAt
           FROM examinfo e
      LEFT JOIN subjectinfo s  ON s.SubjectInfoId = e.SubjectInfoId
      LEFT JOIN gradeinfo   g  ON g.GradeInfoId   = e.GradeInfoId
      LEFT JOIN groupinfo   gr ON gr.GroupId      = g.GroupId
          WHERE COALESCE(e.IsDeleted,'N') = 'N'
          ORDER BY e.ExamInfoId DESC LIMIT 6");
} catch (Exception $e) {
    $recentPapers = Database::fetchAll(
        "SELECT e.ExamInfoId, e.ExamName, s.SubjectName, NULL AS GroupName,
                (SELECT COUNT(*) FROM questions q WHERE q.ExamInfoId = e.ExamInfoId) AS QCount,
                NULL AS CreatedAt
           FROM examinfo e
      LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
          ORDER BY e.ExamInfoId DESC LIMIT 6");
}

/* ── Performance by Subject, broken out by Group ─────────────────────────── */
$perfRows = [];
try {
    $perfRows = Database::fetchAll(
        "SELECT s.SubjectName, COALESCE(gr.GroupName,'Uncategorized') AS GroupName,
                AVG(se.Score / NULLIF(se.MarksOutOf, 0) * 100) AS AvgPct,
                COUNT(*) AS Attempts
           FROM studentexam se
           JOIN subjectinfo s  ON s.SubjectInfoId = se.SubjectInfoId
      LEFT JOIN examinfo  e   ON e.ExamInfoId  = se.ExamInfoId
      LEFT JOIN gradeinfo g   ON g.GradeInfoId = e.GradeInfoId
      LEFT JOIN groupinfo gr  ON gr.GroupId    = g.GroupId
          WHERE se.MarksOutOf > 0
          GROUP BY s.SubjectInfoId, gr.GroupId
          ORDER BY Attempts DESC");
} catch (Exception $e) {
    try {
        $flat = Database::fetchAll(
            "SELECT s.SubjectName,
                    AVG(se.Score / NULLIF(se.MarksOutOf, 0) * 100) AS AvgPct,
                    COUNT(*) AS Attempts
               FROM studentexam se
               JOIN subjectinfo s ON s.SubjectInfoId = se.SubjectInfoId
              WHERE se.MarksOutOf > 0
              GROUP BY s.SubjectInfoId
              ORDER BY Attempts DESC");
        foreach ($flat as $r) { $r['GroupName'] = 'Overall'; $perfRows[] = $r; }
    } catch (Exception $e2) { $perfRows = []; }
}

// Top 6 subjects by total attempts, then pivot into one series per group.
$subjectTotals = [];
foreach ($perfRows as $r) {
    $subjectTotals[$r['SubjectName']] = ($subjectTotals[$r['SubjectName']] ?? 0) + (int)$r['Attempts'];
}
arsort($subjectTotals);
$topSubjects = array_slice(array_keys($subjectTotals), 0, 6);

$seriesMap = []; // GroupName => [SubjectName => pct]
foreach ($perfRows as $r) {
    if (!in_array($r['SubjectName'], $topSubjects, true)) continue;
    $seriesMap[$r['GroupName']][$r['SubjectName']] = round((float)$r['AvgPct'], 1);
}
$chartDatasets = [];
foreach ($seriesMap as $groupName => $subMap) {
    $chartDatasets[$groupName] = array_map(fn($s) => $subMap[$s] ?? null, $topSubjects);
}

/* ── Upcoming Exams (assigned, due today or later) ───────────────────────── */
try {
    $upcoming = Database::fetchAll(
        "SELECT ea.DueDate, e.ExamName, e.ExamInfoId, gr.GroupName, COUNT(*) AS AssignedCount
           FROM exam_assignments ea
           JOIN examinfo e ON e.ExamInfoId = ea.ExamInfoId
      LEFT JOIN gradeinfo g  ON g.GradeInfoId = e.GradeInfoId
      LEFT JOIN groupinfo gr ON gr.GroupId    = g.GroupId
          WHERE ea.Status = 'Assigned' AND ea.DueDate >= CURDATE()
            AND COALESCE(e.IsDeleted,'N') = 'N'
          GROUP BY e.ExamInfoId, ea.DueDate, gr.GroupName
          ORDER BY ea.DueDate ASC LIMIT 6");
} catch (Exception $e) {
    try {
        $upcoming = Database::fetchAll(
            "SELECT ea.DueDate, e.ExamName, e.ExamInfoId, NULL AS GroupName, COUNT(*) AS AssignedCount
               FROM exam_assignments ea
               JOIN examinfo e ON e.ExamInfoId = ea.ExamInfoId
              WHERE ea.Status = 'Assigned' AND ea.DueDate >= CURDATE()
              GROUP BY e.ExamInfoId, ea.DueDate
              ORDER BY ea.DueDate ASC LIMIT 6");
    } catch (Exception $e2) { $upcoming = []; }
}

$pageHead = '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .dash-kpi-row{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;}
  .dash-kpi{flex:1;min-width:180px;background:#fff;border:1px solid var(--clr-border,#bae6fd);
            border-radius:10px;padding:18px 20px;display:flex;align-items:center;gap:14px;}
  .dash-kpi-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;
                 justify-content:center;font-size:1.3rem;flex-shrink:0;}
  .dash-kpi-val{font-size:1.6rem;font-weight:800;color:#0c1a2e;line-height:1.1;}
  .dash-kpi-lbl{font-size:.8rem;color:#64748b;font-weight:600;margin-top:2px;}

  .dash-cols{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;}
  .dash-col-wide{flex:1.6;min-width:320px;}
  .dash-col-med{flex:1.2;min-width:280px;}
  .dash-col-narrow{flex:1;min-width:260px;}

  .dash-tbl{width:100%;border-collapse:collapse;}
  .dash-tbl th{font-size:.72rem;text-transform:uppercase;letter-spacing:.4px;color:#64748b;
               text-align:left;padding:6px 10px;border-bottom:2px solid #eef2f7;}
  .dash-tbl td{padding:9px 10px;font-size:.85rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
  .dash-tbl tr:last-child td{border-bottom:none;}
  .dash-paper-name{font-weight:700;color:#1a365d;}
  .dash-group-tag{display:inline-block;padding:1px 8px;border-radius:9px;font-size:.68rem;
                   font-weight:700;background:#eef2ff;color:#4338ca;margin-top:2px;}

  .dash-upcoming-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9;}
  .dash-upcoming-item:last-child{border-bottom:none;}
  .dash-upcoming-date{flex-shrink:0;width:52px;text-align:center;background:#f0f9ff;
                       border-radius:8px;padding:6px 4px;}
  .dash-upcoming-date .d{font-size:1.1rem;font-weight:800;color:#0369a1;line-height:1;}
  .dash-upcoming-date .m{font-size:.65rem;text-transform:uppercase;color:#64748b;font-weight:700;}
  .dash-upcoming-name{font-weight:700;color:#1a365d;font-size:.88rem;}
  .dash-upcoming-meta{font-size:.75rem;color:#94a3b8;margin-top:2px;}

  .dash-empty{padding:24px;text-align:center;color:#94a3b8;font-size:.85rem;}

  .quick-actions{display:flex;gap:12px;flex-wrap:wrap;}
  .quick-action{flex:1;min-width:150px;display:flex;flex-direction:column;align-items:center;
                gap:8px;padding:18px 12px;border-radius:10px;background:#f8fafc;
                border:1px solid #eef2f7;text-decoration:none;color:#1a365d;
                font-weight:700;font-size:.85rem;text-align:center;transition:.15s;}
  .quick-action:hover{background:#eef2ff;border-color:#c7d2fe;transform:translateY(-2px);text-decoration:none;}
  .quick-action .qa-icon{font-size:1.6rem;}
</style>

<!-- KPI cards -->
<div class="dash-kpi-row">
  <div class="dash-kpi">
    <div class="dash-kpi-icon" style="background:#e0f2fe;">&#128101;</div>
    <div>
      <div class="dash-kpi-val"><?php echo number_format($totalStudents); ?></div>
      <div class="dash-kpi-lbl">Total Students</div>
    </div>
  </div>
  <div class="dash-kpi">
    <div class="dash-kpi-icon" style="background:#dcfce7;">&#127891;</div>
    <div>
      <div class="dash-kpi-val"><?php echo number_format($activeCourses); ?></div>
      <div class="dash-kpi-lbl">Active Courses</div>
    </div>
  </div>
  <div class="dash-kpi">
    <div class="dash-kpi-icon" style="background:#fef3c7;">&#128203;</div>
    <div>
      <div class="dash-kpi-val"><?php echo number_format($testsCreated); ?></div>
      <div class="dash-kpi-lbl">Tests Created</div>
    </div>
  </div>
  <div class="dash-kpi">
    <div class="dash-kpi-icon" style="background:#ede9fe;">&#128200;</div>
    <div>
      <div class="dash-kpi-val"><?php echo $avgPerformance > 0 ? round($avgPerformance) . '%' : '—'; ?></div>
      <div class="dash-kpi-lbl">Avg Performance</div>
    </div>
  </div>
</div>

<div class="dash-cols">

  <!-- Recent Test Papers -->
  <div class="card dash-col-wide">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span>&#128203; Recent Test Papers</span>
      <a href="search.php" class="btn btn-secondary btn-sm">View All</a>
    </div>
    <div class="card-body" style="padding:8px 16px;">
      <?php if (empty($recentPapers)): ?>
        <div class="dash-empty">No exams yet. <a href="manage.php?InfoId=0">Create your first one</a>.</div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="dash-tbl">
          <thead>
            <tr><th>Paper Name</th><th>Subject</th><th class="text-center">Qs</th><th>Created</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentPapers as $p): ?>
            <tr>
              <td>
                <a href="manage.php?InfoId=<?php echo (int)$p['ExamInfoId']; ?>" class="dash-paper-name" style="text-decoration:none;">
                  <?php echo htmlspecialchars($p['ExamName']); ?>
                </a>
                <?php if (!empty($p['GroupName'])): ?>
                  <div><span class="dash-group-tag"><?php echo htmlspecialchars($p['GroupName']); ?></span></div>
                <?php endif; ?>
              </td>
              <td><?php echo htmlspecialchars($p['SubjectName'] ?? '—'); ?></td>
              <td class="text-center"><?php echo (int)$p['QCount']; ?></td>
              <td style="color:#64748b;">
                <?php echo !empty($p['CreatedAt']) ? htmlspecialchars(date('d M Y', strtotime($p['CreatedAt']))) : '—'; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Performance chart -->
  <div class="card dash-col-med">
    <div class="card-header">&#128202; Performance by Subject<?php echo count($seriesMap) > 1 ? ' &amp; Group' : ''; ?></div>
    <div class="card-body">
      <?php if (empty($topSubjects)): ?>
        <div class="dash-empty">No completed attempts yet — this fills in once students start taking exams.</div>
      <?php else: ?>
        <canvas id="perfChart" height="220"></canvas>
      <?php endif; ?>
    </div>
  </div>

  <!-- Upcoming Exams -->
  <div class="card dash-col-narrow">
    <div class="card-header">&#128197; Upcoming Exams</div>
    <div class="card-body">
      <?php if (empty($upcoming)): ?>
        <div class="dash-empty">Nothing due soon. <a href="assign.php">Assign an exam</a> to see it here.</div>
      <?php else: ?>
        <?php foreach ($upcoming as $u): $due = strtotime($u['DueDate']); ?>
        <div class="dash-upcoming-item">
          <div class="dash-upcoming-date">
            <div class="d"><?php echo date('d', $due); ?></div>
            <div class="m"><?php echo date('M', $due); ?></div>
          </div>
          <div>
            <a href="manage.php?InfoId=<?php echo (int)$u['ExamInfoId']; ?>" class="dash-upcoming-name" style="text-decoration:none;">
              <?php echo htmlspecialchars($u['ExamName']); ?>
            </a>
            <div class="dash-upcoming-meta">
              <?php echo (int)$u['AssignedCount']; ?> assigned
              <?php if (!empty($u['GroupName'])): ?> &bull; <?php echo htmlspecialchars($u['GroupName']); ?><?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Quick Actions -->
<div class="card" style="margin-top:16px;">
  <div class="card-header">&#9889; Quick Actions</div>
  <div class="card-body">
    <div class="quick-actions">
      <a href="manage.php?InfoId=0" class="quick-action">
        <span class="qa-icon">&#128221;</span> Create Test Paper
      </a>
      <a href="search.php" class="quick-action">
        <span class="qa-icon">&#128101;</span> Assign / Schedule Exam
      </a>
      <a href="../Admin/AddStudent.php" class="quick-action">
        <span class="qa-icon">&#10133;</span> Add Students
      </a>
      <a href="../Admin/ExamResults.php" class="quick-action">
        <span class="qa-icon">&#128200;</span> View Reports
      </a>
    </div>
  </div>
</div>

<?php if (!empty($topSubjects)): ?>
<script>
(function() {
  const subjects = <?php echo json_encode(array_values($topSubjects)); ?>;
  const series   = <?php echo json_encode($chartDatasets); ?>;
  const palette  = ['#0369a1','#22c55e','#f59e0b','#ef4444','#a855f7','#0ea5e9'];

  const datasets = Object.entries(series).map(([groupName, data], i) => ({
    label: groupName,
    data: data,
    backgroundColor: palette[i % palette.length] + 'cc',
    borderRadius: 4,
  }));

  new Chart(document.getElementById('perfChart'), {
    type: 'bar',
    data: { labels: subjects, datasets },
    options: {
      responsive: true,
      plugins: { legend: { position: 'top' } },
      scales: { y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } },
    },
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
