<?php
/**
 * Admin/ManageTeachers.php
 *
 * Manage teacher profiles, online teaching indicator, and their offered courses.
 *
 * Tabs:
 *   list        — all teachers with online indicator
 *   profile     — edit a teacher's profile (?id=N)
 *   courses     — manage a teacher's courses/subjects (?id=N)
 *   students    — students enrolled in a teacher's courses (?id=N)
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$tab      = $_GET['tab'] ?? 'list';
$teacherId= filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$msg      = ''; $msgType = 'success';

/* ── Pagination helpers (mirrors AdminUsers.php / StudentGroupMembers.php) ── */
const PAGE_SIZE = 25;
function currentPage(string $key): int {
    return max(1, (int)($_GET[$key] ?? 1));
}
function paginator(int $total, int $current, int $pageSize, array $qs, string $pageKey): string {
    if ($total <= $pageSize) return '';
    $pages = (int)ceil($total / $pageSize);
    $html  = '<div class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $q    = array_merge($qs, [$pageKey => $i]);
        $url  = '?' . http_build_query($q);
        $cls  = $i === $current ? 'pager-active' : 'pager-link';
        $html .= "<a href=\"{$url}\" class=\"{$cls}\">{$i}</a> ";
    }
    return $html . '</div>';
}

/* ── Helpers ─────────────────────────────────────────────────────────────── */
function getTeacher(int $id): ?array {
    return Database::fetchOne(
        "SELECT tp.*, u.FstName, u.LstName, u.EMail, u.Mobile
           FROM teacher_profiles tp
           JOIN userinfo u ON u.UserInfoId = tp.UserInfoId
          WHERE tp.TeacherId = ? LIMIT 1", [$id]) ?: null;
}

/* ── POST handlers ──────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['post_action'] ?? '';

    /* Approve a pending teacher registration */
    if ($action === 'approve_teacher') {
        $loginName = trim($_POST['LoginName'] ?? '');
        if ($loginName !== '') {
            Database::execute("UPDATE logininfo SET Active='Y' WHERE LoginName=? AND Role='TEACH'", [$loginName]);
            Database::execute("UPDATE teacher_profiles SET Active='Y' WHERE LoginName=?", [$loginName]);
        }
        header('Location: ManageTeachers.php?tab=pending&msg=approved'); exit;
    }

    /* Reject / delete a pending teacher */
    if ($action === 'reject_teacher') {
        $loginName = trim($_POST['LoginName'] ?? '');
        if ($loginName !== '') {
            /* Soft-delete: set Active='N' on both tables */
            Database::execute("UPDATE logininfo SET Active='N' WHERE LoginName=? AND Role='TEACH'", [$loginName]);
        }
        header('Location: ManageTeachers.php?tab=pending&msg=rejected'); exit;
    }

    /* Toggle OffersOnline */
    if ($action === 'toggle_online') {
        $tid = (int)($_POST['TeacherId'] ?? 0);
        $cur = Database::fetchOne("SELECT OffersOnline FROM teacher_profiles WHERE TeacherId=? LIMIT 1", [$tid]);
        if ($cur) {
            Database::execute("UPDATE teacher_profiles SET OffersOnline=? WHERE TeacherId=?",
                [($cur['OffersOnline'] ? 0 : 1), $tid]);
        }
        header('Location: ManageTeachers.php?tab=list&msg=toggled'); exit;
    }

    /* Save teacher profile */
    if ($action === 'save_profile') {
        $tid   = (int)($_POST['TeacherId'] ?? 0);
        $bio   = trim($_POST['Bio']           ?? '');
        $qual  = trim($_POST['Qualification'] ?? '');
        $exp   = trim($_POST['Experience']    ?? '');
        $online= isset($_POST['OffersOnline']) ? 1 : 0;
        $active= ($_POST['Active'] ?? 'Y') === 'Y' ? 'Y' : 'N';
        Database::execute(
            "UPDATE teacher_profiles SET Bio=?,Qualification=?,Experience=?,OffersOnline=?,Active=?
              WHERE TeacherId=?",
            [$bio, $qual, $exp, $online, $active, $tid]);
        $msg = 'Profile saved.';
        $tab = 'profile';
    }

    /* Save course */
    if ($action === 'save_course') {
        $tid   = (int)($_POST['TeacherId']       ?? 0);
        $csid  = (int)($_POST['TeacherSubjectId'] ?? 0);
        $subjId= (int)($_POST['SubjectInfoId']   ?? 0) ?: null;
        $name  = trim($_POST['CourseName']       ?? '');
        $desc  = trim($_POST['Description']      ?? '');
        $fee   = max(0.0, (float)($_POST['CourseFee'] ?? 0));
        $free  = isset($_POST['IsFree']) ? 1 : 0;
        $max   = (int)($_POST['MaxStudents']     ?? 0) ?: null;
        $active= ($_POST['Active'] ?? 'Y') === 'Y' ? 'Y' : 'N';

        if ($name === '') { $msg = 'Course name is required.'; $msgType = 'danger'; }
        else {
            if ($csid > 0) {
                Database::execute(
                    "UPDATE teacher_subjects SET SubjectInfoId=?,CourseName=?,Description=?,
                      CourseFee=?,IsFree=?,MaxStudents=?,Active=?
                     WHERE TeacherSubjectId=? AND TeacherId=?",
                    [$subjId,$name,$desc,$fee,$free,$max,$active,$csid,$tid]);
                $msg = 'Course updated.';
            } else {
                Database::execute(
                    "INSERT INTO teacher_subjects
                      (TeacherId,SubjectInfoId,CourseName,Description,CourseFee,IsFree,MaxStudents,Active)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [$tid,$subjId,$name,$desc,$fee,$free,$max,$active]);
                $msg = 'Course added.';
            }
        }
        $tab = 'courses';
    }

    /* Delete / toggle course */
    if ($action === 'toggle_course') {
        $csid = (int)($_POST['TeacherSubjectId'] ?? 0);
        $cur  = Database::fetchOne("SELECT Active FROM teacher_subjects WHERE TeacherSubjectId=? LIMIT 1", [$csid]);
        if ($cur) {
            Database::execute("UPDATE teacher_subjects SET Active=? WHERE TeacherSubjectId=?",
                [$cur['Active']==='Y'?'N':'Y', $csid]);
        }
        $msg = 'Course status updated.'; $tab = 'courses';
    }

    /* Waive enrollment (admin override) */
    if ($action === 'waive') {
        $eid = (int)($_POST['EnrollmentId'] ?? 0);
        Database::execute(
            "UPDATE teacher_enrollments SET PaymentStatus='Waived', PaidAt=NOW() WHERE EnrollmentId=?", [$eid]);
        $msg = 'Enrollment waived.'; $tab = 'students';
    }
}

/* ── Load teacher for profile/courses/students tabs ─────────────────────── */
$teacher = $teacherId ? getTeacher($teacherId) : null;
$subjects = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo WHERE Active='Y' ORDER BY SubjectName");

/* ── Pending teacher registrations (Active='N' in logininfo) ────────────── */
$pendingTeachers = [];
if ($tab === 'pending' || $tab === 'list') {
    $pendingTeachers = Database::fetchAll(
        "SELECT l.LoginName, l.Email,
                u.FstName, u.LstName, u.Mobile,
                tp.Qualification, tp.Experience, tp.Bio, tp.ProfilePhoto
           FROM logininfo l
           JOIN userinfo u ON u.LoginName = l.LoginName
      LEFT JOIN teacher_profiles tp ON tp.LoginName COLLATE utf8mb4_unicode_ci = l.LoginName
          WHERE l.Role = 'TEACH' AND l.Active = 'N'
          ORDER BY l.LoginInfoId DESC");
}

/* ── Teacher list ─────────────────────────────────────────────────────────────
   Was a single unbounded fetchAll (GROUP BY over every teacher, no LIMIT, no
   search) — fine while there were a handful of teachers, but no ceiling on
   growth and no way to jump straight to one teacher by name. Now search +
   real LIMIT/OFFSET pagination (PAGE_SIZE, matching AdminUsers.php's
   convention), with a separate lightweight COUNT query (no LEFT JOINs
   needed for the count since the search only touches teacher/user columns,
   not the course/enrollment aggregates). */
$teachers      = [];
$teacherCount  = 0;
$teacherSearch = trim($_GET['q'] ?? '');
$teacherPage   = currentPage('p');
if ($tab === 'list' || !$teacherId) {
    $twhere  = ['1=1'];
    $tparams = [];
    if ($teacherSearch !== '') {
        $twhere[]  = '(u.FstName LIKE ? OR u.LstName LIKE ? OR u.EMail LIKE ? OR tp.LoginName LIKE ?)';
        $like      = "%{$teacherSearch}%";
        array_push($tparams, $like, $like, $like, $like);
    }
    $twhereSQL = implode(' AND ', $twhere);

    $teacherCount = (int)(Database::fetchOne(
        "SELECT COUNT(*) AS cnt FROM teacher_profiles tp JOIN userinfo u ON u.UserInfoId = tp.UserInfoId WHERE {$twhereSQL}",
        $tparams
    )['cnt'] ?? 0);

    $toffset  = ($teacherPage - 1) * PAGE_SIZE;
    $teachers = Database::fetchAll(
        "SELECT tp.*, u.FstName, u.LstName, u.EMail, u.Mobile,
                COUNT(DISTINCT ts.TeacherSubjectId) AS CourseCount,
                COUNT(DISTINCT te.EnrollmentId)     AS EnrollCount
           FROM teacher_profiles tp
           JOIN userinfo u ON u.UserInfoId = tp.UserInfoId
      LEFT JOIN teacher_subjects ts ON ts.TeacherId = tp.TeacherId AND ts.Active='Y'
      LEFT JOIN teacher_enrollments te ON te.TeacherSubjectId = ts.TeacherSubjectId
                                       AND te.PaymentStatus IN ('Paid','Free','Waived')
          WHERE {$twhereSQL}
          GROUP BY tp.TeacherId
          ORDER BY tp.OffersOnline DESC, u.FstName
          LIMIT {$toffset}, " . PAGE_SIZE,
        $tparams
    );
}
$qsTeachers = array_filter(['tab' => 'list', 'q' => $teacherSearch]);

/* ── Teacher courses ─────────────────────────────────────────────────────── */
$courses = [];
if (($tab === 'courses' || $tab === 'students') && $teacherId) {
    $courses = Database::fetchAll(
        "SELECT ts.*, COALESCE(s.SubjectName,'—') AS SubjectName,
                COUNT(te.EnrollmentId) AS EnrolledCount
           FROM teacher_subjects ts
      LEFT JOIN subjectinfo s ON s.SubjectInfoId = ts.SubjectInfoId
      LEFT JOIN teacher_enrollments te ON te.TeacherSubjectId = ts.TeacherSubjectId
                                       AND te.PaymentStatus IN ('Paid','Free','Waived')
          WHERE ts.TeacherId = ?
          GROUP BY ts.TeacherSubjectId
          ORDER BY ts.Active DESC, ts.CourseName", [$teacherId]);
}

/* ── Enrolled students for a teacher ────────────────────────────────────── */
$enrollments = [];
if ($tab === 'students' && $teacherId) {
    $enrollments = Database::fetchAll(
        "SELECT te.*, u.FstName, u.LstName, u.EMail,
                ts.CourseName, ts.CourseFee, ts.IsFree
           FROM teacher_enrollments te
           JOIN teacher_subjects ts ON ts.TeacherSubjectId = te.TeacherSubjectId
           JOIN userinfo u ON u.UserInfoId = te.UserInfoId
          WHERE ts.TeacherId = ?
          ORDER BY te.PaymentStatus, te.CreatedAt DESC", [$teacherId]);
}

$pageTitle = $teacher
    ? 'Teacher: ' . ($teacher['FstName'] ?? '') . ' ' . ($teacher['LstName'] ?? '')
    : 'Manage Teachers';
include __DIR__ . '/../includes/header.php';
?>
<style>
.tcard{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px 24px;margin-bottom:16px;}
.tbl th{background:#1e3a5f;color:#fff;padding:8px 12px;font-size:.82rem;text-align:left;}
.tbl td{padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:.83rem;vertical-align:middle;}
.tbl tr:hover td{background:#f7faff;}
.online-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:.75rem;font-weight:700;}
.online-yes{background:#d1fae5;color:#065f46;}
.online-no {background:#f1f5f9;color:#64748b;}
.tab-bar{display:flex;gap:4px;border-bottom:2px solid #e2e8f0;margin-bottom:20px;}
.tab-btn{padding:8px 16px;border:none;background:none;cursor:pointer;font-size:.88rem;
         color:#6b7280;border-bottom:3px solid transparent;margin-bottom:-2px;font-family:inherit;}
.tab-btn.active{color:#1e3a5f;border-bottom-color:#1e3a5f;font-weight:700;}
.pager        { margin:10px 0; font-size:12px; display:flex; flex-wrap:wrap; gap:4px; }
.pager-link   { display:inline-block; padding:3px 10px; border:1px solid #cbd5e1; border-radius:4px;
                 text-decoration:none; color:#475569; }
.pager-link:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
.pager-active { display:inline-block; padding:3px 10px; border-radius:4px;
                 background:var(--clr-primary); color:#fff; border:1px solid var(--clr-primary); }
</style>

<?php if ($msg): ?>
<div style="background:<?php echo $msgType==='success'?'#d1fae5;color:#065f46':'#fee2e2;color:#991b1b'; ?>;
            padding:10px 16px;border-radius:6px;margin-bottom:16px;">
  <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php if (!$teacherId): /* ═══════ TEACHER LIST ═══════ */ ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
  <h2 style="margin:0;">Teachers</h2>
</div>

<!-- Top tabs for list view -->
<div class="tab-bar" style="margin-bottom:16px;">
  <a href="?tab=list">
    <button class="tab-btn <?php echo $tab==='list'?'active':''; ?>">
      Active Teachers (<?php echo $teacherCount; ?>)
    </button>
  </a>
  <a href="?tab=pending">
    <button class="tab-btn <?php echo $tab==='pending'?'active':''; ?>">
      Pending Approval
      <?php if (count($pendingTeachers) > 0): ?>
        <span style="background:#ef4444;color:#fff;border-radius:10px;padding:1px 7px;
                     font-size:.72rem;margin-left:4px;"><?php echo count($pendingTeachers); ?></span>
      <?php endif; ?>
    </button>
  </a>
</div>

<?php if ($tab === 'pending'): ?>
<!-- ── PENDING TEACHERS ── -->
<?php if (empty($pendingTeachers)): ?>
  <div class="tcard" style="text-align:center;color:#888;padding:32px;">
    No teacher registrations pending approval.
  </div>
<?php else: ?>
<div class="tcard">
  <h3 style="margin-top:0;color:#991b1b;">&#9888; Pending Teacher Approvals</h3>
  <p style="font-size:.85rem;color:#6b7280;margin-bottom:14px;">
    These teachers registered themselves and are waiting for admin activation.
    Review their details and approve or reject.
  </p>
  <table class="tbl" style="width:100%;border-collapse:collapse;">
    <thead><tr>
      <th>Photo</th><th>Name</th><th>Username</th><th>Email</th><th>Mobile</th>
      <th>Qualification</th><th>Experience</th><th>Bio</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($pendingTeachers as $pt): ?>
    <tr>
      <td style="text-align:center;">
        <?php if (!empty($pt['ProfilePhoto'])): ?>
          <img src="../Admin/<?php echo htmlspecialchars($pt['ProfilePhoto']); ?>"
               alt="Photo" style="width:48px;height:60px;object-fit:cover;border-radius:4px;border:1px solid #d1d5db;">
        <?php else: ?>
          <div style="width:48px;height:60px;border-radius:4px;background:#f1f5f9;border:1px solid #d1d5db;
                      display:flex;align-items:center;justify-content:center;font-size:1.4rem;">👤</div>
        <?php endif; ?>
      </td>
      <td><strong><?php echo htmlspecialchars(trim($pt['FstName'].' '.$pt['LstName'])); ?></strong></td>
      <td><?php echo htmlspecialchars($pt['LoginName']); ?></td>
      <td><?php echo htmlspecialchars($pt['Email'] ?? ''); ?></td>
      <td><?php echo htmlspecialchars($pt['Mobile'] ?? '—'); ?></td>
      <td><?php echo htmlspecialchars($pt['Qualification'] ?? '—'); ?></td>
      <td><?php echo htmlspecialchars($pt['Experience'] ?? '—'); ?></td>
      <td style="max-width:200px;font-size:.8rem;color:#6b7280;">
        <?php echo htmlspecialchars(substr($pt['Bio'] ?? '', 0, 80)); ?>
      </td>
      <td>
        <form method="post" style="display:inline;">
          <input type="hidden" name="csrf_token"  value="<?php echo Auth::csrfToken(); ?>">
          <input type="hidden" name="post_action" value="approve_teacher">
          <input type="hidden" name="LoginName"   value="<?php echo htmlspecialchars($pt['LoginName']); ?>">
          <button type="submit" class="btn btn-sm btn-success"
                  onclick="return confirm('Approve <?php echo addslashes($pt['FstName']); ?> as a teacher?')">
            ✓ Approve
          </button>
        </form>
        <form method="post" style="display:inline;margin-left:4px;">
          <input type="hidden" name="csrf_token"  value="<?php echo Auth::csrfToken(); ?>">
          <input type="hidden" name="post_action" value="reject_teacher">
          <input type="hidden" name="LoginName"   value="<?php echo htmlspecialchars($pt['LoginName']); ?>">
          <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;"
                  onclick="return confirm('Reject this teacher application?')">
            ✗ Reject
          </button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php else: /* tab === list */ ?>

<form method="get" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
  <input type="hidden" name="tab" value="list">
  <input type="text" name="q" value="<?php echo htmlspecialchars($teacherSearch); ?>" class="form-control"
         placeholder="&#128269; Search by name, email, or login…" style="flex:1;min-width:220px;max-width:360px;">
  <button type="submit" class="btn btn-secondary btn-sm">Search</button>
  <?php if ($teacherSearch !== ''): ?>
    <a href="?tab=list" class="btn btn-secondary btn-sm">Clear</a>
  <?php endif; ?>
</form>
<?php if ($teacherSearch !== ''): ?>
  <p style="font-size:.8rem;color:#6b7280;margin:0 0 10px;">Found <?php echo $teacherCount; ?> teacher(s) matching “<?php echo htmlspecialchars($teacherSearch); ?>”.</p>
<?php endif; ?>

<table class="tbl" style="width:100%;border-collapse:collapse;">
  <thead><tr>
    <th>Name</th><th>Email</th><th>Mobile</th>
    <th style="text-align:center;">Online Courses</th>
    <th style="text-align:center;">Courses</th>
    <th style="text-align:center;">Enrolled Students</th>
    <th>Actions</th>
  </tr></thead>
  <tbody>
  <?php if (empty($teachers)): ?>
    <tr><td colspan="7" style="text-align:center;padding:24px;color:#888;">
      <?php echo $teacherSearch !== '' ? 'No teachers match your search.' : 'No teachers found. Teachers are users with a role containing "TEACH".'; ?>
    </td></tr>
  <?php endif; ?>
  <?php foreach ($teachers as $t): ?>
  <tr>
    <td><strong><?php echo htmlspecialchars(trim($t['FstName'].' '.$t['LstName'])); ?></strong>
        <div style="font-size:.75rem;color:#6b7280;"><?php echo htmlspecialchars($t['LoginName']); ?></div></td>
    <td><?php echo htmlspecialchars($t['EMail'] ?? '—'); ?></td>
    <td><?php echo htmlspecialchars($t['Mobile'] ?? '—'); ?></td>
    <td style="text-align:center;">
      <form method="post" style="display:inline;">
        <input type="hidden" name="csrf_token"   value="<?php echo Auth::csrfToken(); ?>">
        <input type="hidden" name="post_action"  value="toggle_online">
        <input type="hidden" name="TeacherId"    value="<?php echo $t['TeacherId']; ?>">
        <button type="submit" class="online-badge <?php echo $t['OffersOnline']?'online-yes':'online-no'; ?>">
          <?php echo $t['OffersOnline'] ? '✓ Online' : '✗ Not Online'; ?>
        </button>
      </form>
    </td>
    <td style="text-align:center;"><?php echo (int)$t['CourseCount']; ?></td>
    <td style="text-align:center;"><?php echo (int)$t['EnrollCount']; ?></td>
    <td>
      <a href="?tab=profile&id=<?php echo $t['TeacherId']; ?>"  class="btn btn-sm btn-secondary">Profile</a>
      <a href="?tab=courses&id=<?php echo $t['TeacherId']; ?>"  class="btn btn-sm btn-primary">Courses</a>
      <a href="?tab=students&id=<?php echo $t['TeacherId']; ?>" class="btn btn-sm"
         style="background:#0891b2;color:#fff;">Students</a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php echo paginator($teacherCount, $teacherPage, PAGE_SIZE, $qsTeachers, 'p'); ?>

<?php endif; /* end list/pending tab */ ?>

<?php else: /* ═══════ TEACHER DETAIL TABS ═══════ */ ?>

<!-- Teacher header -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
  <div>
    <h2 style="margin:0;"><?php echo htmlspecialchars(trim(($teacher['FstName']??'').' '.($teacher['LstName']??''))); ?></h2>
    <div style="color:#6b7280;font-size:.85rem;margin-top:4px;">
      <?php echo htmlspecialchars($teacher['EMail'] ?? ''); ?>
      <?php if ($teacher['OffersOnline']): ?>
        <span class="online-badge online-yes" style="margin-left:8px;">✓ Online Teaching</span>
      <?php else: ?>
        <span class="online-badge online-no"  style="margin-left:8px;">✗ Not offering online courses</span>
      <?php endif; ?>
    </div>
  </div>
  <a href="ManageTeachers.php" class="btn btn-secondary btn-sm">← All Teachers</a>
</div>

<!-- Tab bar -->
<div class="tab-bar">
  <?php foreach (['profile'=>'Profile','courses'=>'Courses','students'=>'Enrolled Students'] as $k=>$label): ?>
    <a href="?tab=<?php echo $k; ?>&id=<?php echo $teacherId; ?>">
      <button class="tab-btn <?php echo $tab===$k?'active':''; ?>"><?php echo $label; ?></button>
    </a>
  <?php endforeach; ?>
</div>

<?php /* ── PROFILE TAB ── */ ?>
<?php if ($tab === 'profile'): ?>
<div class="tcard">
  <form method="post">
    <input type="hidden" name="csrf_token"  value="<?php echo Auth::csrfToken(); ?>">
    <input type="hidden" name="post_action" value="save_profile">
    <input type="hidden" name="TeacherId"   value="<?php echo $teacherId; ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
      <div class="form-group" style="margin:0;">
        <label>Qualification</label>
        <input type="text" name="Qualification" class="form-control"
               placeholder="e.g. M.Tech, MBA"
               value="<?php echo htmlspecialchars($teacher['Qualification'] ?? ''); ?>">
      </div>
      <div class="form-group" style="margin:0;">
        <label>Experience</label>
        <input type="text" name="Experience" class="form-control"
               placeholder="e.g. 5 years"
               value="<?php echo htmlspecialchars($teacher['Experience'] ?? ''); ?>">
      </div>
    </div>

    <div class="form-group">
      <label>Bio / About</label>
      <textarea name="Bio" class="form-control" rows="4"
                placeholder="Short description about the teacher…"><?php
        echo htmlspecialchars($teacher['Bio'] ?? '');
      ?></textarea>
    </div>

    <div style="display:flex;gap:24px;align-items:center;margin-top:10px;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
        <input type="checkbox" name="OffersOnline" value="1"
               <?php echo $teacher['OffersOnline'] ? 'checked' : ''; ?>>
        <span style="color:#065f46;">Offers Online Courses</span>
        <small style="font-weight:400;color:#6b7280;">(shown on student course browser)</small>
      </label>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
        Active:
        <select name="Active" class="form-control" style="width:80px;">
          <option value="Y" <?php echo ($teacher['Active'] ?? 'Y')==='Y'?'selected':''; ?>>Yes</option>
          <option value="N" <?php echo ($teacher['Active'] ?? 'Y')==='N'?'selected':''; ?>>No</option>
        </select>
      </label>
    </div>

    <div style="margin-top:16px;">
      <button type="submit" class="btn btn-primary">Save Profile</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php /* ── COURSES TAB ── */ ?>
<?php if ($tab === 'courses'): ?>

<!-- Existing courses -->
<?php if ($courses): ?>
<div class="tcard">
  <h3 style="margin-top:0;">Current Courses</h3>
  <table class="tbl" style="width:100%;border-collapse:collapse;">
    <thead><tr>
      <th>Course Name</th><th>Subject</th><th>Fee</th>
      <th style="text-align:center;">Enrolled</th>
      <th style="text-align:center;">Status</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($courses as $c): ?>
    <tr>
      <td><strong><?php echo htmlspecialchars($c['CourseName']); ?></strong>
          <?php if ($c['IsFree']): ?>
          <span style="background:#d1fae5;color:#065f46;font-size:.72rem;padding:1px 7px;border-radius:10px;margin-left:6px;">FREE</span>
          <?php endif; ?>
          <?php if ($c['Description']): ?>
          <div style="font-size:.78rem;color:#6b7280;margin-top:2px;"><?php echo htmlspecialchars(substr($c['Description'],0,80)); ?></div>
          <?php endif; ?>
      </td>
      <td><?php echo htmlspecialchars($c['SubjectName']); ?></td>
      <td><?php echo $c['IsFree'] ? '—' : '₹'.number_format((float)$c['CourseFee'],2); ?></td>
      <td style="text-align:center;"><?php echo (int)$c['EnrolledCount']; ?></td>
      <td style="text-align:center;">
        <form method="post" style="display:inline;">
          <input type="hidden" name="csrf_token"         value="<?php echo Auth::csrfToken(); ?>">
          <input type="hidden" name="post_action"        value="toggle_course">
          <input type="hidden" name="TeacherSubjectId"   value="<?php echo $c['TeacherSubjectId']; ?>">
          <input type="hidden" name="tab"                value="courses">
          <input type="hidden" name="id"                 value="<?php echo $teacherId; ?>">
          <button type="submit" style="background:<?php echo $c['Active']==='Y'?'#d1fae5;color:#065f46':'#fee2e2;color:#991b1b'; ?>;
                  border:none;border-radius:12px;padding:2px 10px;cursor:pointer;font-size:.75rem;font-weight:700;">
            <?php echo $c['Active']==='Y'?'Active':'Inactive'; ?>
          </button>
        </form>
      </td>
      <td>
        <a href="?tab=courses&id=<?php echo $teacherId; ?>&edit_course=<?php echo $c['TeacherSubjectId']; ?>"
           class="btn btn-sm btn-primary">Edit</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Add / Edit Course form -->
<?php
$editCsid   = filter_input(INPUT_GET, 'edit_course', FILTER_VALIDATE_INT) ?: 0;
$editCourse = null;
if ($editCsid) {
    $editCourse = Database::fetchOne(
        "SELECT * FROM teacher_subjects WHERE TeacherSubjectId=? AND TeacherId=? LIMIT 1",
        [$editCsid, $teacherId]);
}
?>
<div class="tcard">
  <h3 style="margin-top:0;"><?php echo $editCourse ? 'Edit Course' : 'Add New Course'; ?></h3>
  <form method="post">
    <input type="hidden" name="csrf_token"         value="<?php echo Auth::csrfToken(); ?>">
    <input type="hidden" name="post_action"        value="save_course">
    <input type="hidden" name="TeacherId"          value="<?php echo $teacherId; ?>">
    <input type="hidden" name="TeacherSubjectId"   value="<?php echo $editCourse ? $editCourse['TeacherSubjectId'] : 0; ?>">

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-bottom:12px;">
      <div class="form-group" style="margin:0;">
        <label>Course Name <span style="color:red">*</span></label>
        <input type="text" name="CourseName" class="form-control" required
               value="<?php echo htmlspecialchars($editCourse['CourseName'] ?? ''); ?>">
      </div>
      <div class="form-group" style="margin:0;">
        <label>Linked Subject <small style="color:#6b7280;">(optional)</small></label>
        <select name="SubjectInfoId" class="form-control">
          <option value="">— None / Custom —</option>
          <?php foreach ($subjects as $s): ?>
          <option value="<?php echo $s['SubjectInfoId']; ?>"
            <?php echo ($editCourse['SubjectInfoId'] ?? '') == $s['SubjectInfoId'] ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($s['SubjectName']); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label>Description</label>
      <textarea name="Description" class="form-control" rows="2"><?php echo htmlspecialchars($editCourse['Description'] ?? ''); ?></textarea>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
      <div class="form-group" style="margin:0;">
        <label>Course Fee (₹)</label>
        <input type="number" name="CourseFee" class="form-control" min="0" step="0.01"
               id="feeInput" value="<?php echo $editCourse['CourseFee'] ?? '0'; ?>">
      </div>
      <div class="form-group" style="margin:0;">
        <label>Max Students <small style="color:#6b7280;">(blank = unlimited)</small></label>
        <input type="number" name="MaxStudents" class="form-control" min="0"
               value="<?php echo $editCourse['MaxStudents'] ?? ''; ?>">
      </div>
      <div class="form-group" style="margin:0;">
        <label>Active</label>
        <select name="Active" class="form-control">
          <option value="Y" <?php echo ($editCourse['Active'] ?? 'Y')==='Y'?'selected':''; ?>>Yes</option>
          <option value="N" <?php echo ($editCourse['Active'] ?? 'Y')==='N'?'selected':''; ?>>No</option>
        </select>
      </div>
    </div>

    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;margin-bottom:14px;">
      <input type="checkbox" name="IsFree" value="1" id="chkFree"
             onchange="document.getElementById('feeInput').disabled=this.checked"
             <?php echo ($editCourse['IsFree'] ?? 0) ? 'checked' : ''; ?>>
      <span style="color:#065f46;">Free Course (no payment required)</span>
    </label>

    <div style="display:flex;gap:10px;">
      <button type="submit" class="btn btn-primary"><?php echo $editCourse ? 'Update' : 'Add Course'; ?></button>
      <?php if ($editCourse): ?>
        <a href="?tab=courses&id=<?php echo $teacherId; ?>" class="btn btn-secondary">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<?php /* ── STUDENTS TAB ── */ ?>
<?php if ($tab === 'students'): ?>
<div class="tcard">
  <h3 style="margin-top:0;">Online Teaching — Enrolled Students
    <small style="font-weight:400;color:#6b7280;">(<?php echo count($enrollments); ?> total)</small>
  </h3>
  <?php if (empty($enrollments)): ?>
    <p style="color:#888;">No students enrolled yet.</p>
  <?php else: ?>
  <table class="tbl" style="width:100%;border-collapse:collapse;">
    <thead><tr>
      <th>Student</th><th>Course</th><th>Fee</th>
      <th style="text-align:center;">Status</th><th>Paid At</th><th>Action</th>
    </tr></thead>
    <tbody>
    <?php foreach ($enrollments as $e):
      $status = $e['PaymentStatus'];
      $statusColor = $status==='Paid'||$status==='Free'||$status==='Waived' ? '#065f46' : '#991b1b';
      $statusBg    = $status==='Paid'||$status==='Free'||$status==='Waived' ? '#d1fae5'  : '#fee2e2';
    ?>
    <tr>
      <td>
        <strong><?php echo htmlspecialchars(trim($e['FstName'].' '.$e['LstName'])); ?></strong>
        <div style="font-size:.75rem;color:#6b7280;"><?php echo htmlspecialchars($e['EMail'] ?? ''); ?></div>
      </td>
      <td><?php echo htmlspecialchars($e['CourseName']); ?></td>
      <td><?php echo $e['IsFree'] ? 'Free' : '₹'.number_format((float)$e['CourseFee'],2); ?></td>
      <td style="text-align:center;">
        <span style="background:<?php echo $statusBg; ?>;color:<?php echo $statusColor; ?>;
                     padding:2px 10px;border-radius:12px;font-size:.75rem;font-weight:700;">
          <?php echo htmlspecialchars($status); ?>
        </span>
      </td>
      <td style="font-size:.8rem;"><?php echo $e['PaidAt'] ? date('d M Y', strtotime($e['PaidAt'])) : '—'; ?></td>
      <td>
        <?php if ($status === 'Pending'): ?>
        <form method="post" style="display:inline;" onsubmit="return confirm('Waive payment for this student?')">
          <input type="hidden" name="csrf_token"    value="<?php echo Auth::csrfToken(); ?>">
          <input type="hidden" name="post_action"   value="waive">
          <input type="hidden" name="EnrollmentId"  value="<?php echo $e['EnrollmentId']; ?>">
          <input type="hidden" name="tab"           value="students">
          <input type="hidden" name="id"            value="<?php echo $teacherId; ?>">
          <button type="submit" class="btn btn-sm" style="background:#f59e0b;color:#fff;">Waive</button>
        </form>
        <?php else: echo '—'; endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; /* end teacher detail */ ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
