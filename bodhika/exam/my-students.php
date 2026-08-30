<?php
/**
 * exam/my-students.php — Teacher's own student dashboard.
 *
 * Shows only students enrolled in the logged-in teacher's courses.
 * Accessible to TEACH role only; admins and students are redirected.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

// Only teachers may access this page
if (!Auth::isTeacher()) {
    header('Location: search.php');
    exit;
}

$myUid = Auth::currentUserId();

/* ── Resolve TeacherId for this logged-in user ───────────────────────────── */
$teacherProfile = Database::fetchOne(
    "SELECT tp.TeacherId, tp.Qualification, tp.Experience, tp.Bio, tp.OffersOnline
       FROM teacher_profiles tp
       JOIN userinfo u ON u.UserInfoId = tp.UserInfoId
      WHERE u.UserInfoId = ?
      LIMIT 1",
    [$myUid]
);

if (!$teacherProfile) {
    // Logged in as TEACH role but no teacher_profiles row yet
    $pageTitle = 'My Students';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="card" style="text-align:center;padding:48px;color:#6b7280;">';
    echo '<p style="font-size:1.1rem;">Your teacher profile is not set up yet.</p>';
    echo '<p>Please contact an administrator to complete your profile activation.</p>';
    echo '</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$myTeacherId = (int)$teacherProfile['TeacherId'];

/* ── Filters ─────────────────────────────────────────────────────────────── */
$filterCourse = filter_input(INPUT_GET, 'course', FILTER_VALIDATE_INT) ?: 0;
$filterStatus = trim($_GET['status'] ?? '');   // '' | 'Paid' | 'Free' | 'Pending' | 'Waived'
$search       = trim($_GET['q'] ?? '');

/* ── My courses ──────────────────────────────────────────────────────────── */
$myCourses = Database::fetchAll(
    "SELECT ts.TeacherSubjectId, ts.CourseName, ts.IsFree, ts.CourseFee, ts.Active,
            COALESCE(s.SubjectName,'—') AS SubjectName,
            COUNT(te.EnrollmentId) AS EnrolledCount
       FROM teacher_subjects ts
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = ts.SubjectInfoId
  LEFT JOIN teacher_enrollments te ON te.TeacherSubjectId = ts.TeacherSubjectId
                                   AND te.PaymentStatus IN ('Paid','Free','Waived')
      WHERE ts.TeacherId = ?
      GROUP BY ts.TeacherSubjectId
      ORDER BY ts.Active DESC, ts.CourseName",
    [$myTeacherId]
);

/* ── Enrolled students — scoped to this teacher only ────────────────────── */
$where  = ['ts.TeacherId = ?'];
$params = [$myTeacherId];

if ($filterCourse > 0) {
    $where[]  = 'te.TeacherSubjectId = ?';
    $params[] = $filterCourse;
}
if ($filterStatus !== '') {
    $where[]  = 'te.PaymentStatus = ?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where[]  = "(CONCAT(u.FstName,' ',u.LstName) LIKE ? OR u.EMail LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$enrollments = Database::fetchAll(
    "SELECT te.EnrollmentId, te.PaymentStatus, te.AmountPaid, te.PaidAt, te.CreatedAt,
            u.FstName, u.LstName, u.EMail,
            ts.CourseName, ts.IsFree, ts.CourseFee,
            COALESCE(s.SubjectName,'—') AS SubjectName
       FROM teacher_enrollments te
       JOIN teacher_subjects ts ON ts.TeacherSubjectId = te.TeacherSubjectId
       JOIN userinfo u ON u.UserInfoId = te.UserInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = ts.SubjectInfoId
      WHERE " . implode(' AND ', $where) . "
      ORDER BY te.PaymentStatus ASC, te.CreatedAt DESC",
    $params
);

/* ── Counts by status (unfiltered by search, for summary pills) ─────────── */
$statusCounts = ['Paid' => 0, 'Free' => 0, 'Waived' => 0, 'Pending' => 0];
$allEnrollments = Database::fetchAll(
    "SELECT te.PaymentStatus FROM teacher_enrollments te
       JOIN teacher_subjects ts ON ts.TeacherSubjectId = te.TeacherSubjectId
      WHERE ts.TeacherId = ?",
    [$myTeacherId]
);
foreach ($allEnrollments as $r) {
    if (isset($statusCounts[$r['PaymentStatus']])) {
        $statusCounts[$r['PaymentStatus']]++;
    }
}
$totalActive = $statusCounts['Paid'] + $statusCounts['Free'] + $statusCounts['Waived'];

$pageTitle = 'My Students';
include __DIR__ . '/../includes/header.php';
?>
<style>
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:24px;}
.stat-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;text-align:center;}
.stat-num{font-size:1.8rem;font-weight:700;color:#1e3a5f;}
.stat-lbl{font-size:.78rem;color:#6b7280;margin-top:2px;}
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px;}
.filter-chip{padding:5px 14px;border-radius:20px;border:1px solid #cbd5e0;background:#fff;
             font-size:.82rem;cursor:pointer;text-decoration:none;color:#374151;white-space:nowrap;}
.filter-chip.active{background:#1e3a5f;color:#fff;border-color:#1e3a5f;}
.tbl th{background:#1e3a5f;color:#fff;padding:9px 12px;font-size:.82rem;text-align:left;white-space:nowrap;}
.tbl td{padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:.83rem;vertical-align:middle;}
.tbl tr:hover td{background:#f7faff;}
.status-pill{display:inline-block;padding:2px 10px;border-radius:12px;font-size:.75rem;font-weight:700;}
.pill-paid   {background:#d1fae5;color:#065f46;}
.pill-free   {background:#dbeafe;color:#1e40af;}
.pill-waived {background:#ede9fe;color:#5b21b6;}
.pill-pending{background:#fef3c7;color:#92400e;}
.course-tag{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.72rem;
            background:#f1f5f9;color:#475569;margin-top:2px;}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
  <div>
    <h2 style="margin:0;">&#128101; My Students</h2>
    <div style="font-size:.85rem;color:#6b7280;margin-top:4px;">
      Students enrolled in your courses
      <?php if ($teacherProfile['Qualification']): ?>
        &bull; <?php echo htmlspecialchars($teacherProfile['Qualification']); ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Summary stats -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-num"><?php echo $totalActive; ?></div>
    <div class="stat-lbl">Active Students</div>
  </div>
  <div class="stat-card">
    <div class="stat-num"><?php echo count($myCourses); ?></div>
    <div class="stat-lbl">My Courses</div>
  </div>
  <div class="stat-card">
    <div class="stat-num"><?php echo $statusCounts['Paid']; ?></div>
    <div class="stat-lbl">Paid</div>
  </div>
  <div class="stat-card">
    <div class="stat-num"><?php echo $statusCounts['Free']; ?></div>
    <div class="stat-lbl">Free Enrolled</div>
  </div>
  <div class="stat-card">
    <div class="stat-num" style="color:<?php echo $statusCounts['Pending']>0?'#92400e':'#1e3a5f'; ?>">
      <?php echo $statusCounts['Pending']; ?>
    </div>
    <div class="stat-lbl">Pending</div>
  </div>
</div>

<!-- Filter bar -->
<form method="get" class="filter-bar">
  <!-- Search -->
  <div style="position:relative;flex:1;min-width:180px;max-width:320px;">
    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;">&#128269;</span>
    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
           placeholder="Search name or email…"
           class="form-control" style="padding-left:32px;">
  </div>

  <!-- Course filter -->
  <select name="course" class="form-control" style="max-width:220px;">
    <option value="0">All Courses</option>
    <?php foreach ($myCourses as $c): ?>
      <option value="<?php echo $c['TeacherSubjectId']; ?>"
              <?php echo $filterCourse===(int)$c['TeacherSubjectId']?'selected':''; ?>>
        <?php echo htmlspecialchars($c['CourseName']); ?>
        (<?php echo (int)$c['EnrolledCount']; ?>)
      </option>
    <?php endforeach; ?>
  </select>

  <!-- Status filter -->
  <?php
  $statusLinks = [
    ''        => 'All',
    'Paid'    => 'Paid',
    'Free'    => 'Free',
    'Waived'  => 'Waived',
    'Pending' => 'Pending',
  ];
  $baseQ = http_build_query(array_filter(['q' => $search, 'course' => $filterCourse ?: null]));
  foreach ($statusLinks as $sv => $sl):
    $link = '?' . $baseQ . ($baseQ ? '&' : '') . 'status=' . urlencode($sv);
  ?>
  <a href="<?php echo $link; ?>"
     class="filter-chip <?php echo $filterStatus===$sv?'active':''; ?>">
    <?php echo $sl; ?>
  </a>
  <?php endforeach; ?>

  <button type="submit" class="btn btn-secondary btn-sm">Search</button>
  <?php if ($search || $filterCourse || $filterStatus): ?>
    <a href="my-students.php" class="btn btn-secondary btn-sm">✕ Clear</a>
  <?php endif; ?>
</form>

<?php if (empty($myCourses)): ?>
<!-- No courses at all -->
<div class="card" style="text-align:center;padding:48px;color:#718096;">
  <p style="font-size:1.1rem;">You haven't created any courses yet.</p>
  <p style="font-size:.9rem;">
    Once you create courses and students enroll, they will appear here.
  </p>
</div>

<?php elseif (empty($enrollments)): ?>
<!-- Courses exist but no students match the filter -->
<div class="card" style="text-align:center;padding:48px;color:#718096;">
  <p>
    <?php if ($search || $filterCourse || $filterStatus): ?>
      No students match your current filters.
      <a href="my-students.php">Clear filters</a>
    <?php else: ?>
      No students have enrolled in your courses yet.
    <?php endif; ?>
  </p>
</div>

<?php else: ?>
<!-- Student table -->
<div style="font-size:.85rem;color:#6b7280;margin-bottom:8px;">
  Showing <?php echo count($enrollments); ?> enrollment<?php echo count($enrollments)!==1?'s':''; ?>
</div>

<div style="overflow-x:auto;">
<table class="tbl" style="width:100%;border-collapse:collapse;">
  <thead>
    <tr>
      <th>#</th>
      <th>Student Name</th>
      <th>Email</th>
      <th>Course</th>
      <th>Subject</th>
      <th style="text-align:center;">Fee</th>
      <th style="text-align:center;">Status</th>
      <th>Enrolled On</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($enrollments as $i => $e):
    $statusClass = [
      'Paid'    => 'pill-paid',
      'Free'    => 'pill-free',
      'Waived'  => 'pill-waived',
      'Pending' => 'pill-pending',
    ][$e['PaymentStatus']] ?? 'pill-pending';
  ?>
  <tr>
    <td style="color:#9ca3af;"><?php echo $i + 1; ?></td>
    <td>
      <strong><?php echo htmlspecialchars(trim($e['FstName'] . ' ' . $e['LstName'])); ?></strong>
    </td>
    <td style="font-size:.8rem;color:#6b7280;">
      <?php echo htmlspecialchars($e['EMail'] ?? '—'); ?>
    </td>
    <td><?php echo htmlspecialchars($e['CourseName']); ?></td>
    <td>
      <span class="course-tag"><?php echo htmlspecialchars($e['SubjectName']); ?></span>
    </td>
    <td style="text-align:center;">
      <?php echo $e['IsFree'] ? 'Free' : '₹' . number_format((float)$e['CourseFee'], 2); ?>
    </td>
    <td style="text-align:center;">
      <span class="status-pill <?php echo $statusClass; ?>">
        <?php echo htmlspecialchars($e['PaymentStatus']); ?>
      </span>
    </td>
    <td style="font-size:.8rem;color:#6b7280;">
      <?php echo $e['CreatedAt'] ? date('d M Y', strtotime($e['CreatedAt'])) : '—'; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
