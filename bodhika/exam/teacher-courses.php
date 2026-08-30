<?php
/**
 * exam/teacher-courses.php
 *
 * Student-facing page — browse all teacher online courses.
 *
 *  • Free courses: instantly accessible (no payment). Students can click
 *    "Access Exams" to see exams in that subject.
 *  • Paid courses: show fee + Enroll button. After payment, student appears
 *    under the teacher's "Online Teaching" student list.
 *  • Search: filter by teacher name or course/subject name.
 *  • Admin view: shows all courses; student view: shows only Online teachers.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$myUid   = Auth::currentUserId();
$isAdmin = Auth::isAdmin();

$search = trim($_GET['q'] ?? '');
$filterFree = isset($_GET['free']) ? (int)$_GET['free'] : -1; // -1=all, 1=free, 0=paid

/* ── Load courses ────────────────────────────────────────────────────────── */
$where  = ["tp.Active='Y'", "ts.Active='Y'", "tp.OffersOnline=1"];
$params = [];

if (!$isAdmin) {
    // Students only see online teachers
}

if ($search !== '') {
    $where[]  = "(CONCAT(u.FstName,' ',u.LstName) LIKE ? OR ts.CourseName LIKE ? OR s.SubjectName LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterFree === 1) { $where[] = 'ts.IsFree = 1'; }
if ($filterFree === 0) { $where[] = 'ts.IsFree = 0'; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$courses = Database::fetchAll(
    "SELECT ts.TeacherSubjectId, ts.CourseName, ts.Description, ts.CourseFee, ts.IsFree,
            ts.MaxStudents,
            tp.TeacherId, tp.Bio, tp.Qualification, tp.Experience,
            u.FstName, u.LstName, u.EMail,
            COALESCE(s.SubjectName,'—') AS SubjectName,
            COUNT(te.EnrollmentId) AS EnrolledCount
       FROM teacher_subjects ts
       JOIN teacher_profiles tp ON tp.TeacherId = ts.TeacherId
       JOIN userinfo u ON u.UserInfoId = tp.UserInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = ts.SubjectInfoId
  LEFT JOIN teacher_enrollments te ON te.TeacherSubjectId = ts.TeacherSubjectId
                                   AND te.PaymentStatus IN ('Paid','Free','Waived')
     $whereSQL
     GROUP BY ts.TeacherSubjectId
     ORDER BY ts.IsFree DESC, tp.TeacherId, ts.CourseName",
    $params);

/* ── For each course: check if current student is already enrolled ──────── */
$enrolledMap = []; // [TeacherSubjectId => PaymentStatus]
if ($myUid && $courses) {
    $csIds = array_column($courses, 'TeacherSubjectId');
    $ph    = implode(',', array_fill(0, count($csIds), '?'));
    $rows  = Database::fetchAll(
        "SELECT TeacherSubjectId, PaymentStatus FROM teacher_enrollments
          WHERE UserInfoId = ? AND TeacherSubjectId IN ($ph)",
        array_merge([$myUid], $csIds));
    foreach ($rows as $r) {
        $enrolledMap[(int)$r['TeacherSubjectId']] = $r['PaymentStatus'];
    }
}

$pageTitle = 'Teacher Online Courses';
include __DIR__ . '/../includes/header.php';
?>
<style>
.course-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px;margin-top:16px;}
.course-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;
             display:flex;flex-direction:column;transition:box-shadow .2s;}
.course-card:hover{box-shadow:0 4px 18px rgba(30,58,95,.12);}
.course-card-hdr{background:#1e3a5f;color:#fff;padding:14px 16px;}
.course-card-body{padding:14px 16px;flex:1;display:flex;flex-direction:column;gap:8px;}
.course-card-footer{padding:12px 16px;border-top:1px solid #f1f5f9;background:#f8fafc;}
.teacher-name{font-size:.78rem;opacity:.8;margin-top:2px;}
.free-pill{background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:12px;font-size:.72rem;font-weight:700;}
.paid-pill{background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:12px;font-size:.72rem;font-weight:700;}
.enrolled-pill{background:#7c3aed;color:#fff;padding:3px 10px;border-radius:12px;font-size:.72rem;font-weight:700;}
.search-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px;}
.filter-chip{padding:5px 14px;border-radius:20px;border:1px solid #cbd5e0;background:#fff;
             font-size:.82rem;cursor:pointer;text-decoration:none;color:#374151;}
.filter-chip.active{background:#1e3a5f;color:#fff;border-color:#1e3a5f;}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
  <h2 style="margin:0;">&#127891; Teacher Online Courses</h2>
</div>

<!-- Search & filter bar -->
<form method="get" class="search-bar">
  <div style="position:relative;flex:1;min-width:200px;max-width:360px;">
    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;">&#128269;</span>
    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
           placeholder="Search teacher or course…"
           class="form-control" style="padding-left:32px;">
  </div>
  <a href="?q=<?php echo urlencode($search); ?>"
     class="filter-chip <?php echo $filterFree===-1?'active':''; ?>">All</a>
  <a href="?q=<?php echo urlencode($search); ?>&free=1"
     class="filter-chip <?php echo $filterFree===1?'active':''; ?>">Free only</a>
  <a href="?q=<?php echo urlencode($search); ?>&free=0"
     class="filter-chip <?php echo $filterFree===0?'active':''; ?>">Paid only</a>
  <button type="submit" class="btn btn-secondary btn-sm">Search</button>
  <?php if ($search): ?>
    <a href="teacher-courses.php" class="btn btn-secondary btn-sm">✕ Clear</a>
  <?php endif; ?>
</form>

<?php if (empty($courses)): ?>
<div class="card" style="text-align:center;padding:48px;color:#718096;">
  No online courses found<?php echo $search ? ' matching "'.htmlspecialchars($search).'"' : ''; ?>.
</div>
<?php else: ?>

<div style="font-size:.85rem;color:#6b7280;margin-bottom:4px;">
  <?php echo count($courses); ?> course<?php echo count($courses)!==1?'s':''; ?> found
</div>

<div class="course-grid">
<?php foreach ($courses as $c):
    $csId     = (int)$c['TeacherSubjectId'];
    $status   = $enrolledMap[$csId] ?? null;
    $isActive = ($status === 'Paid' || $status === 'Free' || $status === 'Waived');
    $isPending= ($status === 'Pending');
    $isFull   = ($c['MaxStudents'] && (int)$c['EnrolledCount'] >= (int)$c['MaxStudents']);
    $teacherName = htmlspecialchars(trim($c['FstName'].' '.$c['LstName']));
?>
<div class="course-card">
  <div class="course-card-hdr">
    <div style="font-weight:700;font-size:1rem;"><?php echo htmlspecialchars($c['CourseName']); ?></div>
    <div class="teacher-name">&#128101; <?php echo $teacherName; ?>
      <?php if ($c['Qualification']): ?>
        &nbsp;&bull;&nbsp;<?php echo htmlspecialchars($c['Qualification']); ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="course-card-body">
    <!-- Subject tag -->
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
      <?php if ($c['IsFree']): ?>
        <span class="free-pill">FREE</span>
      <?php else: ?>
        <span class="paid-pill">₹<?php echo number_format((float)$c['CourseFee'],2); ?></span>
      <?php endif; ?>
      <span style="font-size:.78rem;color:#6b7280;">Subject: <?php echo htmlspecialchars($c['SubjectName']); ?></span>
    </div>

    <!-- Description -->
    <?php if ($c['Description']): ?>
    <p style="font-size:.85rem;color:#4a5568;margin:0;line-height:1.5;">
      <?php echo htmlspecialchars(substr($c['Description'], 0, 120)) . (strlen($c['Description'])>120?'…':''); ?>
    </p>
    <?php endif; ?>

    <!-- Teacher info -->
    <?php if ($c['Experience']): ?>
    <div style="font-size:.78rem;color:#6b7280;">&#128336; <?php echo htmlspecialchars($c['Experience']); ?> experience</div>
    <?php endif; ?>

    <!-- Enrollment count -->
    <div style="font-size:.78rem;color:#6b7280;">
      &#128101; <?php echo (int)$c['EnrolledCount']; ?> student<?php echo $c['EnrolledCount']!=1?'s':''; ?> enrolled
      <?php if ($isFull): ?>
        <span style="color:#dc2626;font-weight:700;margin-left:4px;">· FULL</span>
      <?php endif; ?>
    </div>

    <!-- Enrolled badge -->
    <?php if ($isActive): ?>
    <div><span class="enrolled-pill">✓ Enrolled</span></div>
    <?php elseif ($isPending): ?>
    <div style="font-size:.78rem;color:#d97706;">⏳ Payment pending</div>
    <?php endif; ?>
  </div>

  <div class="course-card-footer">
    <?php if ($isActive): ?>
      <!-- Already enrolled — link to exams if subject is linked -->
      <?php if ($c['SubjectInfoId'] ?? null): ?>
      <a href="search.php?subject=<?php echo $c['SubjectInfoId']; ?>"
         class="btn btn-primary btn-sm" style="width:100%;text-align:center;display:block;">
        &#9997; Access Exams
      </a>
      <?php else: ?>
      <span style="color:#065f46;font-size:.85rem;font-weight:600;">✓ You are enrolled</span>
      <?php endif; ?>

    <?php elseif ($isFull): ?>
      <button class="btn btn-sm" disabled
              style="width:100%;background:#f1f5f9;color:#94a3b8;cursor:not-allowed;">
        Course Full
      </button>

    <?php elseif ($c['IsFree']): ?>
      <!-- Free: enroll instantly via GET -->
      <a href="teacher-enroll.php?csid=<?php echo $csId; ?>&free=1"
         class="btn btn-success btn-sm" style="width:100%;text-align:center;display:block;">
        &#10010; Enroll Free
      </a>

    <?php else: ?>
      <!-- Paid: go to enrollment/payment page -->
      <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
        <strong style="color:#1e3a5f;font-size:1.05rem;">
          ₹<?php echo number_format((float)$c['CourseFee'],2); ?>
        </strong>
        <a href="teacher-enroll.php?csid=<?php echo $csId; ?>"
           class="btn btn-primary btn-sm">
          &#128179; Enroll &amp; Pay
        </a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
