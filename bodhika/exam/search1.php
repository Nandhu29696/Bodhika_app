<?php
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Enrollment.php';
Auth::requireLogin('../auth/login.php');

$pageTitle  = 'Exam Search';
$isAdmin    = Auth::isAdmin();
$myUid      = (int)Auth::currentUserId();

$grades     = Database::fetchAll("SELECT GradeInfoId, GradeName   FROM gradeinfo   ORDER BY GradeName");
$subjects   = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo ORDER BY SubjectName");
$gradeMap   = array_column($grades,   'GradeName',   'GradeInfoId');
$subjectMap = array_column($subjects, 'SubjectName', 'SubjectInfoId');

/* ── Parse search filters ────────────────────────────────────────────────── */
$filterGrade   = isset($_POST['Search']) ? (int)($_POST['txtGrade']   ?? 0) : 0;
$filterSubject = isset($_POST['Search']) ? (int)($_POST['txtSubject'] ?? 0) : 0;

/* ── Load exams — admins see all; students see assigned + self-enrolled ──── */
if ($isAdmin) {
    $where = []; $params = [];
    if ($filterGrade   > 0) { $where[] = 'GradeInfoId = ?';   $params[] = $filterGrade; }
    if ($filterSubject > 0) { $where[] = 'SubjectInfoId = ?'; $params[] = $filterSubject; }
    $exams = Database::fetchAll(
        'SELECT * FROM examinfo' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ExamInfoId DESC',
        $params);
} else {
    /* ── 1. Admin-assigned exams ──────────────────────────────────────────── */
    $assignedExams = [];
    $assignedIds   = [];
    $where  = ['ea.UserInfoId = ?'];
    $params = [$myUid];
    if ($filterGrade   > 0) { $where[] = 'e.GradeInfoId = ?';   $params[] = $filterGrade; }
    if ($filterSubject > 0) { $where[] = 'e.SubjectInfoId = ?'; $params[] = $filterSubject; }
    try {
        $assignedExams = Database::fetchAll(
            "SELECT e.*, ea.AssignmentId, ea.Status AS AssignStatus,
                    ea.DueDate, ea.StudentExamId AS AsgStudentExamId
               FROM examinfo e
               JOIN exam_assignments ea ON ea.ExamInfoId = e.ExamInfoId
              WHERE " . implode(' AND ', $where) . "
              ORDER BY ea.Status ASC, ea.AssignedAt DESC",
            $params);
        $assignedIds = array_column($assignedExams, 'ExamInfoId');
    } catch (Exception $e) {}

    /* ── 2. Self-enrolled exams (paid enrollment, not already assigned) ──── */
    $selfEnrolled = [];
    $today        = date('Y-m-d');
    try {
        $epWhere  = ['ep.UserInfoId = ?',
                     "ep.PaymentStatus IN ('Paid','Waived','Free')",
                     '(ep.EndDate IS NULL OR ep.EndDate >= ?)'];
        $epParams = [$myUid, $today];
        if ($filterGrade   > 0) { $epWhere[] = 'e.GradeInfoId = ?';   $epParams[] = $filterGrade; }
        if ($filterSubject > 0) { $epWhere[] = 'e.SubjectInfoId = ?'; $epParams[] = $filterSubject; }
        $selfEnrolled = Database::fetchAll(
            "SELECT e.*,
                    NULL  AS AssignmentId,
                    'SelfEnrolled' AS AssignStatus,
                    NULL  AS DueDate,
                    NULL  AS AsgStudentExamId
               FROM examinfo e
               JOIN enrollment_payments ep ON ep.SubjectInfoId = e.SubjectInfoId
              WHERE " . implode(' AND ', $epWhere) . "
              ORDER BY e.ExamInfoId DESC",
            $epParams);
        /* Exclude already-assigned exams */
        if (!empty($assignedIds)) {
            $selfEnrolled = array_filter($selfEnrolled,
                fn($ex) => !in_array($ex['ExamInfoId'], $assignedIds));
        }
    } catch (Exception $e) {}

    $exams = array_merge($assignedExams, array_values($selfEnrolled));
}

/* ── Load enrollment + fee status for students ───────────────────────────── */
$enrollMap      = []; // subjectId → enrollment_payments row
$feeMap         = []; // subjectId → ['fee'=>float, 'disc'=>float]
$scholarshipUser = false;
if (!$isAdmin && !empty($exams)) {
    $subjectIds = array_values(array_unique(array_column($exams, 'SubjectInfoId')));
    $ph = implode(',', array_fill(0, count($subjectIds), '?'));
    try {
        $feeRows = Database::fetchAll(
            "SELECT SubjectInfoId, COALESCE(ExamFee,0) AS ExamFee,
                    COALESCE(DiscountPct,0) AS DiscountPct
               FROM subjectinfo WHERE SubjectInfoId IN ($ph)", $subjectIds);
        foreach ($feeRows as $fr) {
            $feeMap[(int)$fr['SubjectInfoId']] = [
                'fee'  => (float)$fr['ExamFee'],
                'disc' => (float)$fr['DiscountPct'],
            ];
        }
    } catch (Exception $e) {}
    try {
        $epRows = Database::fetchAll(
            "SELECT SubjectInfoId, PaymentStatus, FinalAmount
               FROM enrollment_payments
              WHERE UserInfoId = ? AND SubjectInfoId IN ($ph)",
            array_merge([$myUid], $subjectIds));
        foreach ($epRows as $er) {
            $enrollMap[(int)$er['SubjectInfoId']] = $er;
        }
    } catch (Exception $e) {}
    try {
        $uRow = Database::fetchOne(
            "SELECT ScholarshipFlag FROM userinfo WHERE UserInfoId = ? LIMIT 1", [$myUid]);
        $scholarshipUser = (($uRow['ScholarshipFlag'] ?? 'N') === 'Y');
    } catch (Exception $e) {}
}

/* ── For admin: load per-exam assignment counts ──────────────────────────── */
$assignCounts = [];
if ($isAdmin) {
    try {
        $rows = Database::fetchAll(
            "SELECT ExamInfoId,
                    SUM(Status='Assigned')   AS Pending,
                    SUM(Status='Completed')  AS Done,
                    COUNT(*)                 AS Total
               FROM exam_assignments
              GROUP BY ExamInfoId", []);
        foreach ($rows as $r) $assignCounts[(int)$r['ExamInfoId']] = $r;
    } catch (Exception $e) { /* table not yet created */ }
}

include __DIR__ . '/../includes/header.php';
?>
<style>
  .assign-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:12px;font-size:.78rem;font-weight:700;}
  .assign-pending  {background:#ebf8ff;color:#2b6cb0;}
  .assign-completed{background:#c6efce;color:#276749;}
  .assign-overdue  {background:#fff5f5;color:#c53030;}
</style>

<?php if (isset($_GET['enrolled'])): ?>
<div class="alert alert-success" style="margin-bottom:16px;">
  &#10004; You are now enrolled! You can start the exam below.
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- Admin action bar -->
<div class="card" style="border-left:4px solid #3182ce;margin-bottom:16px;">
  <div class="card-body" style="padding:14px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <strong style="color:#1a365d;margin-right:4px;">&#9881; Admin Actions:</strong>
    <a href="manage.php?InfoId=0"   class="btn btn-success btn-sm">&#10010; Add New Exam</a>
    <a href="questions-hub.php"     class="btn btn-sm" style="background:#6b46c1;color:#fff;">&#10067; Manage Questions</a>
    <a href="log.php"               class="btn btn-secondary btn-sm">&#128203; Exam Log</a>
    <a href="../Admin/ExamResults.php" class="btn btn-sm" style="background:#0891b2;color:#fff;">&#128202; Results</a>
    <span style="color:#718096;font-size:.82rem;margin-left:4px;">
      &#128161; Use <strong>&#128101; Assign</strong> per exam row to assign exams to students.
    </span>
  </div>
</div>
<?php endif; ?>


<!-- Search form -->
<div class="card">
  <div class="card-header">&#128269; Search Exams</div>
  <div class="card-body">
    <form method="post" action="">
      <div class="flex gap-3" style="flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin-bottom:0;min-width:180px;">
          <label>Grade</label>
          <select name="txtGrade" class="form-control">
            <option value="0">-- All Grades --</option>
            <?php foreach ($grades as $g): ?>
              <option value="<?php echo (int)$g['GradeInfoId']; ?>"
                <?php echo ((int)($_POST['txtGrade']??0)===(int)$g['GradeInfoId'])?'selected':''; ?>>
                <?php echo htmlspecialchars($g['GradeName']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:0;min-width:180px;">
          <label>Subject</label>
          <select name="txtSubject" class="form-control">
            <option value="0">-- All Subjects --</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?php echo (int)$s['SubjectInfoId']; ?>"
                <?php echo ((int)($_POST['txtSubject']??0)===(int)$s['SubjectInfoId'])?'selected':''; ?>>
                <?php echo htmlspecialchars($s['SubjectName']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;gap:8px;margin-top:20px;">
          <button type="submit" name="Search" class="btn btn-primary">&#128269; Search</button>
          <a href="search.php" class="btn btn-secondary">Clear</a>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Exam list -->
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#128196; Examination List</span>
    <?php if ($isAdmin): ?>
      <a href="manage.php?InfoId=0" class="btn btn-success btn-sm">&#10010; Add New Exam</a>
    <?php endif; ?>
  </div>
  <div class="card-body" style="padding:0">
    <?php if (empty($exams)): ?>
      <p style="padding:20px;color:#718096;text-align:center;">
        <?php if ($isAdmin): ?>
          No exams found. <a href="manage.php?InfoId=0">Add one</a>.
        <?php else: ?>
          You have no exams assigned to you yet.
          <a href="browse-subjects.php" style="font-weight:700;">Browse &amp; enroll in subjects &rarr;</a>
        <?php endif; ?>
      </p>
    <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th>Exam Name</th>
          <th>Grade</th>
          <th>Subject</th>
          <th style="width:80px;">Questions</th>
          <th style="width:80px;">Pass Mark</th>
          <th style="width:80px;">Time (min)</th>
          <?php if ($isAdmin): ?>
            <th style="width:80px;">Assigned</th>
          <?php else: ?>
            <th style="width:90px;">Status</th>
            <th style="width:100px;">Due Date</th>
            <th style="width:100px;">Access</th>
          <?php endif; ?>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($exams as $i => $exam):
          $eid    = (int)$exam['ExamInfoId'];
          $counts = $assignCounts[$eid] ?? null;
          /* Student assignment metadata is embedded directly in $exam row */
          $assignStatus = $exam['AssignStatus'] ?? null;
          $dueDate      = $exam['DueDate']      ?? null;
          $asgSeId      = (int)($exam['AsgStudentExamId'] ?? 0);
          $isOverdue    = ($assignStatus === 'Assigned' && $dueDate && $dueDate < date('Y-m-d'));

          /* Enrollment / fee check */
          $sid        = (int)$exam['SubjectInfoId'];
          $subjectFee = $feeMap[$sid]['fee'] ?? 0.0;
          $subjectDisc= $feeMap[$sid]['disc'] ?? 0.0;
          $discFee    = $subjectDisc > 0
              ? max(0, $subjectFee - round($subjectFee * $subjectDisc / 100, 2))
              : $subjectFee;
          $enrollRow  = $enrollMap[$sid] ?? null;
          $enrollStatus = $enrollRow['PaymentStatus'] ?? '';
          $isEnrolled = $isAdmin
              || $subjectFee <= 0
              || $scholarshipUser
              || in_array($enrollStatus, ['Paid', 'Waived', 'Free'], true);
          $isPending  = ($enrollStatus === 'Pending');
        ?>
        <tr class="<?php echo ($i%2===0)?'odd':'even'; ?>">
          <td><?php echo htmlspecialchars($exam['ExamName']); ?></td>
          <td><?php echo htmlspecialchars($gradeMap[$exam['GradeInfoId']] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($subjectMap[$exam['SubjectInfoId']] ?? '—'); ?></td>
          <td class="text-center"><?php echo (int)$exam['NumOfQuestions']; ?></td>
          <td class="text-center"><?php echo (int)$exam['MinPassing']; ?>%</td>
          <td class="text-center"><?php echo htmlspecialchars($exam['TimeAlloted'] ?? '—'); ?></td>

          <?php if ($isAdmin): ?>
          <td class="text-center">
            <?php if ($counts && $counts['Total'] > 0): ?>
              <span title="<?php echo (int)$counts['Pending']; ?> pending / <?php echo (int)$counts['Done']; ?> done"
                    style="font-size:.8rem;font-weight:700;color:#2b6cb0;">
                &#128101; <?php echo (int)$counts['Total']; ?>
              </span>
            <?php else: ?>
              <span style="font-size:.78rem;color:#a0aec0;">—</span>
            <?php endif; ?>
          </td>
          <?php else: ?>
          <td class="text-center">
            <?php if ($assignStatus === 'SelfEnrolled'): ?>
              <span class="assign-badge" style="background:#ede9fe;color:#6d28d9;">&#128218; Self</span>
            <?php elseif ($assignStatus === 'Completed'): ?>
              <span class="assign-badge assign-completed">&#10004; Done</span>
            <?php elseif ($isOverdue): ?>
              <span class="assign-badge assign-overdue">&#9888; Overdue</span>
            <?php else: ?>
              <span class="assign-badge assign-pending">&#9679; Pending</span>
            <?php endif; ?>
          </td>
          <td class="text-center" style="font-size:.82rem;color:<?php echo $isOverdue ? '#c53030' : '#718096'; ?>;">
            <?php echo $dueDate ? date('d M Y', strtotime($dueDate)) : '—'; ?>
          </td>
          <td class="text-center">
            <?php if ($subjectFee <= 0 || $scholarshipUser): ?>
              <span class="assign-badge" style="background:#e0f2fe;color:#0369a1;" title="Free access">&#127995; Free</span>
            <?php elseif ($isEnrolled): ?>
              <span class="assign-badge assign-completed" title="Payment confirmed">&#128274; Paid</span>
            <?php elseif ($isPending): ?>
              <span class="assign-badge" style="background:#fef9c3;color:#92400e;" title="Payment pending">&#9203; Pending</span>
            <?php else: ?>
              <span class="assign-badge" style="background:#fee2e2;color:#b91c1c;" title="Enrollment required">&#128274; Locked</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>

          <td>
            <div class="flex gap-2" style="flex-wrap:wrap;">
              <?php if ($isAdmin): ?>
                <a href="write.php?InfoId=<?php echo $eid; ?>"
                   class="btn btn-primary btn-sm">&#9998; Write Exam</a>
                <a href="history.php?InfoId=<?php echo $eid; ?>"
                   class="btn btn-secondary btn-sm">&#128200; History</a>
                <a href="manage.php?InfoId=<?php echo $eid; ?>"
                   class="btn btn-warning btn-sm" title="Edit exam settings">&#9998; Edit</a>
                <a href="questions.php?examId=<?php echo $eid; ?>"
                   class="btn btn-sm" style="background:#6b46c1;color:#fff;font-weight:700;"
                   title="Manage questions">&#10067; Questions</a>
                <a href="question-edit.php?examId=<?php echo $eid; ?>"
                   class="btn btn-success btn-sm" title="Add question">&#10010; Add Q</a>
                <a href="assign.php?examId=<?php echo $eid; ?>"
                   class="btn btn-sm" style="background:#d97706;color:#fff;font-weight:700;"
                   title="Assign this exam to users">&#128101; Assign</a>
              <?php elseif ($assignStatus === 'Completed'): ?>
                <?php if ($asgSeId): ?>
                  <a href="result.php?id=<?php echo $asgSeId; ?>"
                     class="btn btn-secondary btn-sm">&#128200; View Result</a>
                <?php endif; ?>
                <a href="history.php?InfoId=<?php echo $eid; ?>"
                   class="btn btn-secondary btn-sm">&#128200; History</a>
              <?php elseif (!$isEnrolled && $subjectFee > 0): ?>
                <?php if ($isPending): ?>
                  <a href="enroll.php?subjectId=<?php echo $sid; ?>"
                     class="btn btn-sm" style="background:#d97706;color:#fff;font-weight:700;">
                    &#9203; Complete Payment
                  </a>
                <?php else: ?>
                  <a href="enroll.php?subjectId=<?php echo $sid; ?>"
                     class="btn btn-sm" style="background:#dc2626;color:#fff;font-weight:700;">
                    &#128274; Enroll &#8377;<?php echo number_format($discFee, 2); ?>
                  </a>
                <?php endif; ?>
                <a href="history.php?InfoId=<?php echo $eid; ?>"
                   class="btn btn-secondary btn-sm">&#128200; History</a>
              <?php else: ?>
                <a href="write.php?InfoId=<?php echo $eid; ?>"
                   class="btn btn-primary btn-sm" style="font-weight:700;">&#9998; Start Exam</a>
                <a href="history.php?InfoId=<?php echo $eid; ?>"
                   class="btn btn-secondary btn-sm">&#128200; History</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
