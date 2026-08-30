<?php
/**
 * Admin/ExamHistoryList.php — Admin view of all student exam attempts.
 * Filters: Grade, Subject, Exam, Student name/login, Result, Date range.
 * Links to result.php for per-attempt detail.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) {
    header('Location: ../auth/login.php'); exit;
}

/* ── Filters ─────────────────────────────────────────────────────────── */
$fGrade   = (int)   ($_GET['grade']    ?? 0);
$fSubject = (int)   ($_GET['subject']  ?? 0);
$fExam    = (int)   ($_GET['exam']     ?? 0);
$fStudent = trim    ($_GET['student']  ?? '');
$fResult  = trim    ($_GET['result']   ?? '');   // Pass | Fail | ''
$fFrom    = trim    ($_GET['from']     ?? '');
$fTo      = trim    ($_GET['to']       ?? '');

/* ── Pagination ──────────────────────────────────────────────────────── */
$perPage = 50;
$page    = max(1, (int)($_GET['pg'] ?? 1));
$offset  = ($page - 1) * $perPage;

/* ── Dropdown data ───────────────────────────────────────────────────── */
$grades   = Database::fetchAll("SELECT GradeInfoId, GradeName   FROM gradeinfo   ORDER BY GradeName");
$subjects = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo ORDER BY SubjectName");

$examWhere  = [];
$examParams = [];
if ($fGrade)   { $examWhere[] = 'e.GradeInfoId   = ?'; $examParams[] = $fGrade; }
if ($fSubject) { $examWhere[] = 'e.SubjectInfoId  = ?'; $examParams[] = $fSubject; }
$examFilter  = $examWhere ? 'WHERE ' . implode(' AND ', $examWhere) : '';
$exams = Database::fetchAll(
    "SELECT e.ExamInfoId, e.ExamName
       FROM examinfo e $examFilter
      ORDER BY e.ExamName",
    $examParams);

/* ── Main query ──────────────────────────────────────────────────────── */
$where  = [];
$params = [];

if ($fGrade)   { $where[] = 'e.GradeInfoId   = ?'; $params[] = $fGrade; }
if ($fSubject) { $where[] = 'e.SubjectInfoId  = ?'; $params[] = $fSubject; }
if ($fExam)    { $where[] = 'se.ExamInfoId    = ?'; $params[] = $fExam; }
if ($fResult)  { $where[] = 'se.Description   = ?'; $params[] = $fResult; }
if ($fFrom)    { $where[] = 'DATE(se.CreateDate) >= ?'; $params[] = $fFrom; }
if ($fTo)      { $where[] = 'DATE(se.CreateDate) <= ?'; $params[] = $fTo; }
if ($fStudent !== '') {
    $like = '%' . $fStudent . '%';
    $where[] = "(u.FstName LIKE ? OR u.LstName LIKE ?
                 OR CONCAT(u.FstName,' ',u.LstName) LIKE ?
                 OR u.LoginName LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}

$joins = "LEFT JOIN examinfo    e ON e.ExamInfoId   = se.ExamInfoId
          LEFT JOIN gradeinfo   g ON g.GradeInfoId  = e.GradeInfoId
          LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
          LEFT JOIN userinfo    u ON u.UserInfoId    = se.UserInfoId";

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)(Database::fetchOne(
    "SELECT COUNT(*) FROM studentexam se $joins $whereSQL",
    $params)['COUNT(*)'] ?? 0);

$rows = Database::fetchAll(
    "SELECT se.StudentExamId, se.ExamInfoId, se.UserInfoId,
            se.Score, se.MarksOutOf, se.TimeTaken, se.Description,
            se.CreateDate,
            e.ExamName, e.NumOfQuestions, e.MinPassing,
            g.GradeName, s.SubjectName,
            u.FstName, u.LstName, u.LoginName AS StudentLogin
       FROM studentexam se $joins
      $whereSQL
      ORDER BY se.CreateDate DESC, se.StudentExamId DESC
      LIMIT $perPage OFFSET $offset",
    $params);

/* ── Normalise rows ──────────────────────────────────────────────────── */
foreach ($rows as &$r) {
    $score      = (float)($r['Score']      ?? 0);
    $marksOutOf = (float)($r['MarksOutOf'] ?? $r['NumOfQuestions'] ?? 0);
    $r['_pct']  = $marksOutOf > 0 ? round($score / $marksOutOf * 100, 1) : null;
    $mins = (int)(($r['TimeTaken'] ?? 0) / 60);
    $secs = (int)(($r['TimeTaken'] ?? 0) % 60);
    $r['_time'] = $mins > 0 ? "{$mins}m {$secs}s" : "{$secs}s";
    $r['_name'] = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
    if ($r['_name'] === '') $r['_name'] = $r['StudentLogin'] ?? '—';
}
unset($r);

/* ── Summary stats for current filter ──────────────────────────────── */
$stats = Database::fetchOne(
    "SELECT COUNT(*)                                              AS total,
            SUM(se.Description = 'Pass')                        AS passed,
            SUM(se.Description = 'Fail')                        AS failed,
            ROUND(AVG(CASE WHEN se.MarksOutOf > 0
                      THEN se.Score/se.MarksOutOf*100 END), 1)  AS avgPct,
            ROUND(AVG(se.TimeTaken)/60, 1)                      AS avgMins
       FROM studentexam se $joins
      $whereSQL",
    $params) ?? [];

$totalPages = max(1, (int)ceil($total / $perPage));

/* ── QS builder (preserves all filters) ─────────────────────────────── */
$qs = function(array $extra = []) use ($fGrade,$fSubject,$fExam,$fStudent,$fResult,$fFrom,$fTo,$page) {
    $base = array_filter([
        'grade'   => $fGrade,
        'subject' => $fSubject,
        'exam'    => $fExam,
        'student' => $fStudent,
        'result'  => $fResult,
        'from'    => $fFrom,
        'to'      => $fTo,
        'pg'      => $page,
    ]);
    return '?' . http_build_query(array_merge($base, $extra));
};

$pageTitle = 'Exam History';
include __DIR__ . '/../includes/header.php';
?>

<style>
.eh-stats { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:22px; }
.eh-stat  { flex:1; min-width:140px; background:#fff; border:1px solid var(--clr-border);
            border-radius:10px; padding:16px 18px; text-align:center; }
.eh-stat .val { font-size:1.9rem; font-weight:800; color:var(--clr-primary); line-height:1; }
.eh-stat .lbl { font-size:.72rem; font-weight:700; text-transform:uppercase;
                letter-spacing:.06em; color:var(--clr-text-muted); margin-top:4px; }
.pct-bar  { height:6px; border-radius:3px; background:#e2e8f0; overflow:hidden; margin-top:4px; }
.pct-fill { height:100%; border-radius:3px; background:var(--clr-primary); transition:width .4s; }
.badge-pass   { background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:99px;
                font-size:.72rem; font-weight:700; }
.badge-fail   { background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:99px;
                font-size:.72rem; font-weight:700; }
.badge-inprog { background:#fef9c3; color:#854d0e; padding:2px 8px; border-radius:99px;
                font-size:.72rem; font-weight:700; }
.eh-pag   { display:flex; align-items:center; gap:6px; justify-content:flex-end; margin-top:16px;
            flex-wrap:wrap; }
.eh-pag a, .eh-pag span {
    padding:5px 11px; border-radius:6px; font-size:.82rem; font-weight:600;
    border:1px solid var(--clr-border); text-decoration:none; color:var(--clr-primary); background:#fff; }
.eh-pag a:hover { background:var(--clr-primary); color:#fff; }
.eh-pag span.current { background:var(--clr-primary); color:#fff; border-color:var(--clr-primary); }
.eh-pag span.disabled { color:#cbd5e1; cursor:default; }
</style>

<div class="page-wrap">

  <!-- Page header -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
    <h1 style="font-size:1.35rem;font-weight:800;color:var(--clr-primary);margin:0;">
      📋 Exam History
    </h1>
    <a href="<?php echo $qs(['export'=>1,'pg'=>'']); ?>&export=1"
       class="btn btn-sm" style="gap:6px;">
      ⬇ Export CSV
    </a>
  </div>

  <!-- Filter card -->
  <div class="card" style="margin-bottom:20px;">
    <form method="get" class="card-body">
      <div class="filter-bar" style="flex-wrap:wrap;gap:12px 16px;">

        <div class="form-group" style="margin:0;">
          <label>Grade</label>
          <select name="grade" class="form-control form-control-sm" onchange="this.form.submit()">
            <option value="">— All Grades —</option>
            <?php foreach ($grades as $g): ?>
              <option value="<?php echo $g['GradeInfoId']; ?>"
                <?php echo $fGrade === (int)$g['GradeInfoId'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($g['GradeName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="margin:0;">
          <label>Subject</label>
          <select name="subject" class="form-control form-control-sm" onchange="this.form.submit()">
            <option value="">— All Subjects —</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?php echo $s['SubjectInfoId']; ?>"
                <?php echo $fSubject === (int)$s['SubjectInfoId'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($s['SubjectName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="margin:0;min-width:200px;">
          <label>Exam</label>
          <select name="exam" class="form-control form-control-sm">
            <option value="">— All Exams —</option>
            <?php foreach ($exams as $e): ?>
              <option value="<?php echo $e['ExamInfoId']; ?>"
                <?php echo $fExam === (int)$e['ExamInfoId'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($e['ExamName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="margin:0;">
          <label>Student</label>
          <input type="text" name="student" class="form-control form-control-sm"
                 placeholder="Name or login…"
                 value="<?php echo htmlspecialchars($fStudent); ?>" style="width:150px;">
        </div>

        <div class="form-group" style="margin:0;">
          <label>Result</label>
          <select name="result" class="form-control form-control-sm">
            <option value="">— Any —</option>
            <option value="Pass" <?php echo $fResult==='Pass'?'selected':''; ?>>Pass</option>
            <option value="Fail" <?php echo $fResult==='Fail'?'selected':''; ?>>Fail</option>
          </select>
        </div>

        <div class="form-group" style="margin:0;">
          <label>From</label>
          <input type="date" name="from" class="form-control form-control-sm"
                 value="<?php echo htmlspecialchars($fFrom); ?>">
        </div>
        <div class="form-group" style="margin:0;">
          <label>To</label>
          <input type="date" name="to" class="form-control form-control-sm"
                 value="<?php echo htmlspecialchars($fTo); ?>">
        </div>

        <div class="filter-actions" style="margin:0;align-self:flex-end;display:flex;gap:6px;">
          <button type="submit" class="btn btn-sm">Search</button>
          <a href="ExamHistoryList.php" class="btn btn-sm btn-outline">Reset</a>
        </div>
      </div>
    </form>
  </div>

  <!-- Stats strip -->
  <div class="eh-stats">
    <div class="eh-stat">
      <div class="val"><?php echo number_format($stats['total'] ?? 0); ?></div>
      <div class="lbl">Total Attempts</div>
    </div>
    <div class="eh-stat">
      <div class="val" style="color:#059669;"><?php echo number_format($stats['passed'] ?? 0); ?></div>
      <div class="lbl">Passed</div>
    </div>
    <div class="eh-stat">
      <div class="val" style="color:#dc2626;"><?php echo number_format($stats['failed'] ?? 0); ?></div>
      <div class="lbl">Failed</div>
    </div>
    <div class="eh-stat">
      <?php $avg = (float)($stats['avgPct'] ?? 0); ?>
      <div class="val" style="color:<?php echo $avg>=60?'#059669':($avg>=40?'#d97706':'#dc2626'); ?>">
        <?php echo $avg > 0 ? $avg . '%' : '—'; ?>
      </div>
      <div class="lbl">Avg Score</div>
    </div>
    <div class="eh-stat">
      <div class="val"><?php echo $stats['avgMins'] > 0 ? $stats['avgMins'] . 'm' : '—'; ?></div>
      <div class="lbl">Avg Time</div>
    </div>
  </div>

  <!-- Results table -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span style="font-weight:700;">
        <?php echo number_format($total); ?> attempt<?php echo $total !== 1 ? 's' : ''; ?>
        <?php if ($total > $perPage): ?>
          <span style="font-weight:400;color:var(--clr-text-muted);font-size:.85rem;">
            — showing <?php echo $offset+1; ?>–<?php echo min($offset+$perPage,$total); ?>
          </span>
        <?php endif; ?>
      </span>
    </div>
    <div class="card-body" style="padding:0;">
      <?php if (empty($rows)): ?>
        <div style="padding:48px;text-align:center;color:var(--clr-text-muted);">
          No exam attempts match your filters.
        </div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>#</th>
              <th>Student</th>
              <th>Exam</th>
              <th>Grade / Subject</th>
              <th style="text-align:center;">Score</th>
              <th style="text-align:center;">Result</th>
              <th style="text-align:center;">Time Taken</th>
              <th>Date</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $i => $r): ?>
            <?php
              $pct    = $r['_pct'];
              $result = $r['Description'] ?? '';
              $isPassed = $result === 'Pass';
              $isFailed = $result === 'Fail';
            ?>
            <tr>
              <td style="color:var(--clr-text-muted);font-size:.82rem;"><?php echo $offset + $i + 1; ?></td>
              <td>
                <div style="font-weight:600;"><?php echo htmlspecialchars($r['_name']); ?></div>
                <div style="font-size:.75rem;color:var(--clr-text-muted);">
                  <?php echo htmlspecialchars($r['StudentLogin'] ?? ''); ?>
                </div>
              </td>
              <td>
                <div style="font-weight:600;max-width:200px;">
                  <?php echo htmlspecialchars($r['ExamName'] ?? '—'); ?>
                </div>
              </td>
              <td style="font-size:.82rem;color:var(--clr-text-muted);">
                <?php echo htmlspecialchars($r['GradeName'] ?? ''); ?>
                <?php if (!empty($r['SubjectName'])): ?>
                  · <?php echo htmlspecialchars($r['SubjectName']); ?>
                <?php endif; ?>
              </td>
              <td style="text-align:center;">
                <?php if ($pct !== null): ?>
                  <div style="font-weight:700;font-size:.95rem;
                       color:<?php echo $pct>=60?'#059669':($pct>=40?'#d97706':'#dc2626'); ?>;">
                    <?php echo $pct; ?>%
                  </div>
                  <div style="font-size:.72rem;color:var(--clr-text-muted);">
                    <?php echo (int)($r['Score']??0); ?> /
                    <?php echo (int)($r['MarksOutOf']??0); ?>
                  </div>
                  <div class="pct-bar" style="width:70px;margin:4px auto 0;">
                    <div class="pct-fill"
                         style="width:<?php echo min(100,$pct); ?>%;
                                background:<?php echo $pct>=60?'#059669':($pct>=40?'#d97706':'#dc2626'); ?>;">
                    </div>
                  </div>
                <?php else: ?>
                  <span style="color:var(--clr-text-muted);">—</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center;">
                <?php if ($isPassed): ?>
                  <span class="badge-pass">Pass</span>
                <?php elseif ($isFailed): ?>
                  <span class="badge-fail">Fail</span>
                <?php elseif ($result !== ''): ?>
                  <span class="badge-inprog"><?php echo htmlspecialchars($result); ?></span>
                <?php else: ?>
                  <span style="color:var(--clr-text-muted);font-size:.8rem;">In progress</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center;font-size:.85rem;">
                <?php echo htmlspecialchars($r['_time']); ?>
              </td>
              <td style="font-size:.82rem;color:var(--clr-text-muted);white-space:nowrap;">
                <?php echo $r['CreateDate']
                  ? date('d M Y, H:i', strtotime($r['CreateDate']))
                  : '—'; ?>
              </td>
              <td>
                <a href="../exam/result.php?id=<?php echo (int)$r['StudentExamId']; ?>"
                   class="btn btn-xs" title="View result">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <div style="padding:12px 16px;">
        <div class="eh-pag">
          <?php if ($page > 1): ?>
            <a href="<?php echo $qs(['pg' => $page - 1]); ?>">‹ Prev</a>
          <?php else: ?>
            <span class="disabled">‹ Prev</span>
          <?php endif; ?>

          <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1) echo '<span style="background:none;border:none;color:var(--clr-text-muted);">…</span>';
            for ($p = $start; $p <= $end; $p++):
          ?>
            <?php if ($p === $page): ?>
              <span class="current"><?php echo $p; ?></span>
            <?php else: ?>
              <a href="<?php echo $qs(['pg' => $p]); ?>"><?php echo $p; ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($end < $totalPages) echo '<span style="background:none;border:none;color:var(--clr-text-muted);">…</span>'; ?>

          <?php if ($page < $totalPages): ?>
            <a href="<?php echo $qs(['pg' => $page + 1]); ?>">Next ›</a>
          <?php else: ?>
            <span class="disabled">Next ›</span>
          <?php endif; ?>

          <span style="color:var(--clr-text-muted);font-size:.8rem;margin-left:6px;">
            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
          </span>
        </div>
      </div>
      <?php endif; ?>

      <?php endif; ?>
    </div><!-- /card-body -->
  </div><!-- /card -->

</div><!-- /page-wrap -->

<?php
/* ── CSV Export ─────────────────────────────────────────────────────── */
// Note: export is handled in export-excel.php?type=history
// The export link above redirects there with same filters.
include __DIR__ . '/../includes/footer.php';
?>
