<?php
/**
 * Admin/marks.php — Student marks report (per-student, per-term).
 * URL params: ?StudentDtlId=X  (required)
 *             &TermId=Y         (optional; shows all terms if omitted)
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) {
    header('Location: ../auth/login.php'); exit;
}

$studentDtlId = (int)($_GET['StudentDtlId'] ?? 0);
$termId       = (int)($_GET['TermId']       ?? 0);

/* ── Dropdown data (gracefully handle missing legacy tables) ─────────── */
try {
    $terms = Database::fetchAll(
        "SELECT TermId, TermDesc FROM terminfo ORDER BY TermId", []);
} catch (\Throwable $e) {
    $terms = [];   // terminfo table may not exist in all installations
}

/* ── Student info ────────────────────────────────────────────────────── */
$student    = null;
$dbMissing  = false;   // set true if legacy tables don't exist

if ($studentDtlId > 0) {
    try {
        $student = Database::fetchOne(
            "SELECT sd.StudentDtlId, sd.GradeInfoId,
                    si.StudentFstNm, si.StudentLstNm,
                    g.GradeName
               FROM studentdetails sd
               LEFT JOIN studentinfo si ON si.StudentInfoId = sd.StudentInfoId
               LEFT JOIN gradeinfo   g  ON g.GradeInfoId   = sd.GradeInfoId
              WHERE sd.StudentDtlId = ? LIMIT 1",
            [$studentDtlId]);
    } catch (\Throwable $e) {
        $dbMissing = true;
    }
}

/* ── Marks data ──────────────────────────────────────────────────────── */
$markRows  = [];
$chartData = [];   // termLabel → [subjectName → marks]

if ($student && !$dbMissing) {
    $where  = ['srd.StudentDtlId = ?'];
    $params = [$studentDtlId];
    if ($termId > 0) { $where[] = 'srd.TermId = ?'; $params[] = $termId; }
    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    // JOIN terminfo only when it exists; fall back to "Term N" label otherwise
    $termJoin  = $terms ? "LEFT JOIN terminfo ti ON ti.TermId = srd.TermId" : "";
    $termLabel = $terms ? "ti.TermDesc" : "CONCAT('Term ', srd.TermId)";

    try {
        $markRows = Database::fetchAll(
            "SELECT srd.TermId, srd.SubjectInfoId, srd.MarksObtained,
                    sub.SubjectName,
                    $termLabel AS TermDesc
               FROM studentresultdetail srd
               LEFT JOIN subjectinfo sub ON sub.SubjectInfoId = srd.SubjectInfoId
               $termJoin
              $whereSQL
              ORDER BY srd.TermId ASC, sub.SubjectName ASC",
            $params);
    } catch (\Throwable $e) {
        $dbMissing = true;
    }

    foreach ($markRows as $r) {
        $tDesc = $r['TermDesc'] ?? 'Term ' . $r['TermId'];
        $chartData[$tDesc][$r['SubjectName']] = (float)$r['MarksObtained'];
    }
}

/* ── Totals per term ─────────────────────────────────────────────────── */
$termGroups = [];   // [ termDesc => [ rows ] ]
foreach ($markRows as $r) {
    $termGroups[$r['TermDesc']][] = $r;
}

// All unique subject names (for chart x-axis)
$allSubjects = [];
foreach ($markRows as $r) { $allSubjects[$r['SubjectName']] = true; }
$allSubjects = array_keys($allSubjects);

$pageTitle = 'Student Marks Report';
include __DIR__ . '/../includes/header.php';
?>

<style>
.marks-card   { background:#fff; border:1px solid var(--clr-border); border-radius:10px;
                margin-bottom:20px; overflow:hidden; }
.marks-card-h { padding:14px 18px; border-bottom:1px solid var(--clr-border);
                font-weight:700; font-size:1rem; color:var(--clr-primary); }
.marks-total  { background:var(--clr-primary-light); font-weight:700; }
.marks-total td { padding:9px 14px; border-top:2px solid var(--clr-border); }
.chart-wrap   { padding:18px; }
</style>

<div class="page-wrap">

  <!-- Page header -->
  <h1 style="font-size:1.35rem;font-weight:800;color:var(--clr-primary);margin-bottom:20px;">
    📊 Student Marks Report
  </h1>

  <!-- Filter card -->
  <div class="card" style="margin-bottom:22px;">
    <form method="get" class="card-body">
      <div class="filter-bar" style="flex-wrap:wrap;gap:12px 16px;">

        <div class="form-group" style="margin:0;">
          <label>Student Detail ID</label>
          <input type="number" name="StudentDtlId" class="form-control form-control-sm"
                 value="<?php echo $studentDtlId ?: ''; ?>"
                 placeholder="StudentDtlId…" style="width:160px;">
        </div>

        <div class="form-group" style="margin:0;">
          <label>Term</label>
          <select name="TermId" class="form-control form-control-sm">
            <option value="">— All Terms —</option>
            <?php foreach ($terms as $t): ?>
              <option value="<?php echo $t['TermId']; ?>"
                <?php echo $termId === (int)$t['TermId'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($t['TermDesc']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filter-actions" style="margin:0;align-self:flex-end;">
          <button type="submit" class="btn btn-sm">View Marks</button>
        </div>
      </div>
    </form>
  </div>

  <?php if ($dbMissing): ?>
    <div class="alert" style="background:#fef9c3;border-color:#fde68a;color:#92400e;">
      <strong>Legacy tables not found.</strong>
      The marks system tables (<code>studentresultdetail</code>, <code>studentdetails</code>)
      do not exist in this database. This page requires the legacy marks schema to be present.
    </div>

  <?php elseif (!$studentDtlId): ?>
    <div class="card">
      <div class="card-body" style="text-align:center;padding:48px;color:var(--clr-text-muted);">
        Enter a Student Detail ID above to view marks.
      </div>
    </div>

  <?php elseif (!$student): ?>
    <div class="alert alert-danger">Student with ID <strong><?php echo $studentDtlId; ?></strong> not found.</div>

  <?php elseif (empty($markRows)): ?>
    <!-- Student info -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-body">
        <strong><?php echo htmlspecialchars($student['StudentFstNm'] . ' ' . $student['StudentLstNm']); ?></strong>
        &nbsp;·&nbsp; <?php echo htmlspecialchars($student['GradeName'] ?? ''); ?>
      </div>
    </div>
    <div class="alert" style="background:#fef9c3;border-color:#fde68a;color:#92400e;">
      No marks records found<?php echo $termId ? ' for the selected term' : ''; ?>.
    </div>

  <?php else: ?>

    <!-- Student info strip -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-body" style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
        <div>
          <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;
                      letter-spacing:.06em;color:var(--clr-primary);margin-bottom:2px;">Student</div>
          <div style="font-weight:700;font-size:1.05rem;">
            <?php echo htmlspecialchars($student['StudentFstNm'] . ' ' . $student['StudentLstNm']); ?>
          </div>
        </div>
        <div>
          <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;
                      letter-spacing:.06em;color:var(--clr-primary);margin-bottom:2px;">Grade</div>
          <div style="font-weight:600;"><?php echo htmlspecialchars($student['GradeName'] ?? '—'); ?></div>
        </div>
        <div>
          <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;
                      letter-spacing:.06em;color:var(--clr-primary);margin-bottom:2px;">Student ID</div>
          <div style="font-weight:600;"><?php echo $studentDtlId; ?></div>
        </div>
      </div>
    </div>

    <!-- Per-term marks tables -->
    <?php foreach ($termGroups as $termDesc => $rows):
      $total = array_sum(array_column($rows, 'MarksObtained'));
    ?>
    <div class="marks-card">
      <div class="marks-card-h"><?php echo htmlspecialchars($termDesc); ?> Marks</div>
      <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th style="width:60%;">Subject</th>
              <th style="text-align:center;">Marks Obtained</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars($r['SubjectName'] ?? '—'); ?></td>
              <td style="text-align:center;font-weight:600;">
                <?php echo htmlspecialchars($r['MarksObtained'] ?? '—'); ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="marks-total">
              <td style="padding:9px 14px;">Total</td>
              <td style="text-align:center;padding:9px 14px;"><?php echo $total; ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Chart -->
    <?php if (!empty($allSubjects) && count($chartData) > 0): ?>
    <div class="marks-card">
      <div class="marks-card-h">📈 Marks by Subject</div>
      <div class="chart-wrap">
        <canvas id="marksChart" style="max-height:340px;"></canvas>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    (function () {
      const subjects = <?php echo json_encode($allSubjects); ?>;
      const termData = <?php echo json_encode($chartData); ?>;
      const palette  = ['#6366f1','#22c55e','#f59e0b','#ef4444','#0ea5e9','#a855f7'];

      const datasets = Object.entries(termData).map(([term, subMap], i) => ({
        label: term,
        data:  subjects.map(s => subMap[s] ?? 0),
        backgroundColor: palette[i % palette.length] + 'cc',
        borderColor:     palette[i % palette.length],
        borderWidth: 1,
        borderRadius: 4,
      }));

      new Chart(document.getElementById('marksChart'), {
        type: 'bar',
        data: { labels: subjects, datasets },
        options: {
          responsive: true,
          plugins: { legend: { position: 'top' } },
          scales: {
            y: { beginAtZero: true, title: { display: true, text: 'Marks' } },
            x: { title: { display: true, text: 'Subject' } },
          },
        },
      });
    })();
    </script>
    <?php endif; ?>

  <?php endif; ?>

</div><!-- /page-wrap -->

<?php include __DIR__ . '/../includes/footer.php'; ?>
