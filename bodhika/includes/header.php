<?php
/**
 * includes/header.php — Responsive shared navigation.
 * Config.php + Auth.php must already be loaded by the calling page.
 */
require_once __DIR__ . '/../Lib/Pii.php';
$_navUser       = Auth::currentUser();
$_navRole       = Auth::currentRole();
$_navIsAdmin    = Auth::isAdmin();
$_navIsTeacher  = Auth::isTeacher();
$_navIsInstAdmin = Auth::isInstituteAdmin() && !$_navIsAdmin; // full Admin always wins
$_navCurrent   = basename($_SERVER['PHP_SELF']);
$_navDir       = basename(dirname($_SERVER['PHP_SELF']));   // 'exam' | 'auth' | 'Admin' | ''

// Resolve the web-root prefix (relative URL back to Exam/).
// Uses SCRIPT_FILENAME (filesystem path, always reliable) rather than PHP_SELF
// (which may be rewritten by mod_rewrite / .htaccess).
// header.php lives at Exam/includes/, so __DIR__/.. == Exam/.
// All known callers are exactly one level below Exam/ (exam/, Admin/, auth/).
// The only exception is pages served from Exam/ itself (no prefix needed).
$_examFsDir   = realpath(__DIR__ . '/..');                          // Exam/ filesystem path
$_scriptFsDir = realpath(dirname($_SERVER['SCRIPT_FILENAME']));     // caller's directory

if ($_examFsDir && $_scriptFsDir) {
    // Caller is inside Exam/ but not at its root → one level deep → '../'
    // Caller IS the Exam/ root → ''
    $_root = ($_scriptFsDir !== $_examFsDir) ? '../' : '';
} else {
    // Fallback: use PHP_SELF basename (works when SCRIPT_FILENAME is unavailable)
    $_root = in_array($_navDir, ['exam', 'auth', 'Admin']) ? '../' : '';
}

function _active(string $file, string $dir = ''): string {
    global $_navCurrent, $_navDir;
    return ($_navCurrent === $file && ($dir === '' || $_navDir === $dir)) ? ' active' : '';
}

/**
 * Returns ' active' when the current page belongs to the given section.
 * Pass any number of filenames that belong to this nav group.
 */
function _sectionActive(string ...$files): string {
    global $_navCurrent;
    return in_array($_navCurrent, $files, true) ? ' active' : '';
}

/* ── Admin quick-stats (top-right of the navbar) ─────────────────────────
   Moved here from includes/sidebar.php's old .sb-stats-widget, which sat
   above "+ Add Exam" in the left nav — Add Exam already lives in the Exams
   section of the sidebar, so that standalone button was just removed
   outright rather than relocated.

   NOTE: the original widget queried userinfo.IsActive (no such column —
   Active lives on logininfo) and examinfo.IsLive (never existed anywhere
   in this codebase), so "Students" and "Live Now" always silently failed
   to 0 via the catch blocks below, regardless of real data. Rewritten to
   reuse the same counting conventions already established elsewhere:
     • Students  → Lib/AdminApi.php's 'totalStudents' (Role='STDNT' via the
                   userinfo/logininfo join)
     • Exams     → Lib/AdminApi.php's 'totalExams' (active AND not deleted)
     • Live Now  → Admin/LiveExamCenter.php's "Exams Running" (exams with
                   an exam_events row in the last 2 hours) — this is the
                   only place in the app that actually tracks "live". */
$_navStudents = 0; $_navActiveExams = 0; $_navLiveExams = 0;
if ($_navIsAdmin) {
    try {
        $_navStudents = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM userinfo u
               JOIN logininfo l ON l.LoginName = u.LoginName
              WHERE l.Role = 'STDNT'", [])['c'] ?? 0);
    } catch (\Throwable $e) {}

    try {
        /* Primary: active AND not soft-deleted (migration_v43+) */
        $_navActiveExams = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM examinfo
              WHERE COALESCE(IsActive,'Y') = 'Y' AND COALESCE(IsDeleted,'N') = 'N'", [])['c'] ?? 0);
    } catch (\Throwable $e) {
        try {
            /* IsDeleted column not present yet — active count only */
            $_navActiveExams = (int)(Database::fetchOne(
                "SELECT COUNT(*) AS c FROM examinfo WHERE COALESCE(IsActive,'Y') = 'Y'", [])['c'] ?? 0);
        } catch (\Throwable $e2) {}
    }

    try {
        /* Requires migration_v29 (exam_events) — 0 if not yet run */
        $_navLiveExams = (int)(Database::fetchOne(
            "SELECT COUNT(DISTINCT ExamInfoId) AS c FROM exam_events
              WHERE LastEventAt >= DATE_SUB(NOW(), INTERVAL 2 HOUR)", [])['c'] ?? 0);
    } catch (\Throwable $e) {}
}

/* Pending student self-registrations (migration_v60) — badge next to the
   Student Approvals nav link. Full Admin sees every institute; Institute-
   Admin only their own. 0 (silently) until migration_v60 is run, same
   degrade-gracefully convention as everything else on this page. */
$_navReturnTo = ($_SERVER['REQUEST_URI'] ?? '') ?: ($_root . 'exam/search.php');

$_navPendingStudents = 0;
if ($_navIsAdmin) {
    try {
        $_navPendingStudents = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM logininfo WHERE Role = 'STDNT' AND RegistrationStatus = 'Pending'", [])['c'] ?? 0);
    } catch (\Throwable $e) {}
} elseif ($_navIsInstAdmin) {
    try {
        $_navInstId = Auth::currentInstituteId();
        if ($_navInstId) {
            $_navPendingStudents = (int)(Database::fetchOne(
                "SELECT COUNT(*) AS c FROM logininfo l JOIN userinfo u ON u.LoginName = l.LoginName
                  WHERE l.Role = 'STDNT' AND l.RegistrationStatus = 'Pending' AND u.InstituteId = ?",
                [$_navInstId])['c'] ?? 0);
        }
    } catch (\Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1a1a2e">
  <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle).' &mdash; ' : ''; ?>Bodhika</title>
  <link rel="icon" type="image/svg+xml" href="<?php echo $_root; ?>assets/logo.svg">
  <link rel="icon" type="image/png" href="<?php echo $_root; ?>assets/logo.png">
  <link rel="apple-touch-icon" href="<?php echo $_root; ?>assets/logo.png">
  <link rel="stylesheet" href="<?php echo $_root . asset_version('assets/style.css'); ?>">
  <!-- Adds a show/hide 👁 toggle to every password field on the page
       (Settings → Change Password, Admin → Add User, App Settings SMTP/API
       key fields, etc.) — fully automatic, no per-page markup needed. -->
  <script src="<?php echo $_root . asset_version('assets/password-toggle.js'); ?>" defer></script>
  <?php if (isset($pageHead)) echo $pageHead; ?>
  <?php if (!empty($useMathJax)): ?>
  <!-- MathJax v3 — loaded only on pages that render question content.
       Set $useMathJax = true before including header.php to activate.
       Supports: \( inline \)  and  \[ display block \]  LaTeX notation. -->
  <script>
    window.MathJax = {
      tex: { inlineMath: [['\\(','\\)']], displayMath: [['\\[','\\]']], processEscapes: true },
      options: { skipHtmlTags: ['script','noscript','style','textarea','pre'] },
      startup: { typeset: true }
    };
  </script>
  <script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.min.js" async></script>
  <?php endif; ?>
</head>
<body>

<!-- ── Backdrop (closes mobile nav on click) ─────────── -->
<div class="nav-backdrop" id="navBackdrop" aria-hidden="true"></div>

<!-- ── Navigation Bar ────────────────────────────────── -->
<nav class="navbar" role="navigation" aria-label="Main navigation">
  <div class="navbar-inner">

    <!-- Logo -->
    <div class="navbar-logo" aria-hidden="true">
      <img src="<?php echo $_root; ?>assets/logo.png" alt="Logo"
           onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="navbar-logo-placeholder" style="display:none;">YOUR<br>LOGO</div>
    </div>

    <!-- Brand -->
    <a class="navbar-brand" href="<?php echo $_root; ?>exam/search.php">
      Bodhika
    </a>

    <!-- Desktop links -->
    <ul class="navbar-links" role="list">
      <li>
        <a href="<?php echo $_root; ?>exam/search.php"
           class="<?php echo _active('search.php','exam'); ?>">&#128196; Exams</a>
      </li>

      <?php if (!$_navIsAdmin && !$_navIsInstAdmin): ?>
      <li>
        <a href="<?php echo $_root; ?>exam/history.php"
           class="<?php echo _active('history.php','exam'); ?>">&#128200; My History</a>
      </li>
      <?php endif; ?>

      <?php /* Section-level nav (Exams/Users/References/Results/Certs/Notify/
                Support/Payments/Monitor for admin; Teaching/Dashboard/My Exams/
                Study Resources for student & teacher) now lives in the left
                sidebar — see includes/sidebar.php — instead of top dropdowns. */ ?>

      <?php if (!$_navIsAdmin && !$_navIsInstAdmin): ?>
      <li>
        <a href="<?php echo $_root; ?>exam/tickets.php"
           class="<?php echo _sectionActive('tickets.php','ticket-view.php'); ?>">&#127381; My Tickets</a>
      </li>
      <li>
        <a href="<?php echo $_root; ?>exam/feedback.php"
           class="<?php echo _active('feedback.php','exam'); ?>">&#128172; Feedback</a>
      </li>
      <li>
        <a href="<?php echo $_root; ?>exam/settings.php"
           class="<?php echo _sectionActive('settings.php'); ?>">&#9881; Settings</a>
      </li>
      <?php else: ?>
      <li>
        <a href="<?php echo $_root; ?>exam/settings.php"
           class="<?php echo _sectionActive('settings.php'); ?>">&#9881; My Settings</a>
      </li>
      <?php endif; ?>
    </ul>

    <!-- Desktop user area -->
    <div class="navbar-user" aria-label="User menu">
      <?php if ($_navIsAdmin): ?>
      <div class="navbar-stats" aria-label="Quick stats">
        <span class="navbar-stat"><strong><?php echo $_navStudents; ?></strong> Students</span>
        <span class="navbar-stat"><strong><?php echo $_navActiveExams; ?></strong> Exams</span>
        <span class="navbar-stat navbar-stat-live"><strong><?php echo $_navLiveExams; ?></strong> Live Now</span>
      </div>
      <?php endif; ?>
      <?php if ($_navIsAdmin || $_navIsInstAdmin): ?>
        <?php echo Pii::toggleButton($_navReturnTo); ?>
      <?php endif; ?>
      <span class="user-name">&#128100; <?php echo htmlspecialchars($_navUser); ?></span>
      <span class="badge <?php echo $_navIsAdmin ? 'badge-admin' : ($_navIsInstAdmin ? 'badge-instadmin' : ($_navIsTeacher ? 'badge-teacher' : 'badge-student')); ?>">
        <?php echo htmlspecialchars($_navRole); ?>
      </span>
      <a href="<?php echo $_root; ?>auth/logout.php" class="btn-logout"
         aria-label="Logout">Logout</a>
    </div>

    <!-- Hamburger toggle (mobile only) -->
    <button class="nav-toggle" id="navToggle"
            aria-controls="mobileNav" aria-expanded="false"
            aria-label="Toggle navigation menu" type="button">
      <span></span><span></span><span></span>
    </button>

  </div>
</nav>

<?php /* Student secondary nav bar removed — replaced by the persistent
          left sidebar (includes/sidebar.php), rendered for every role
          inside .app-shell below. */ ?>

<!-- ── Mobile Nav Drawer ──────────────────────────────── -->
<div class="mobile-nav" id="mobileNav" aria-hidden="true" role="dialog" aria-label="Mobile navigation">
  <ul role="list">
    <?php if (!$_navIsInstAdmin): ?>
    <li>
      <a href="<?php echo $_root; ?>exam/search.php"
         class="<?php echo _active('search.php','exam'); ?>">&#128196; Exams</a>
    </li>
    <?php endif; ?>
    <?php if (!$_navIsAdmin && !$_navIsInstAdmin): ?>
    <li>
      <a href="<?php echo $_root; ?>exam/history.php"
         class="<?php echo _active('history.php','exam'); ?>">&#128200; My History</a>
    </li>
    <?php endif; ?>

    <?php if ($_navIsTeacher): ?>
    <!-- Teacher-only links -->
    <li>
      <a href="<?php echo $_root; ?>exam/my-students.php"
         class="<?php echo _active('my-students.php','exam'); ?>">&#128101; My Students</a>
    </li>

    <?php elseif ($_navIsInstAdmin): ?>
    <!-- ── Institute-Admin mobile: scoped menu ──────────────── -->
    <li><a href="<?php echo $_root; ?>Admin/InstituteAdminHome.php"
           class="<?php echo _active('InstituteAdminHome.php','Admin'); ?>">&#127968; Dashboard</a></li>
    <li><a href="<?php echo $_root; ?>Admin/InstituteAdminStudents.php"
           class="<?php echo _sectionActive('InstituteAdminStudents.php','InstituteAdminStudentDetail.php'); ?>">&#128101; My Institute's Students</a></li>
    <li><a href="<?php echo $_root; ?>Admin/StudentApprovals.php"
           class="<?php echo _active('StudentApprovals.php','Admin'); ?>">&#9989; Student Approvals
      <?php if ($_navPendingStudents > 0): ?>
        <span style="background:#ef4444;color:#fff;border-radius:10px;padding:1px 7px;font-size:.72rem;margin-left:4px;"><?php echo $_navPendingStudents; ?></span>
      <?php endif; ?>
    </a></li>
    <li><a href="<?php echo $_root; ?>Admin/ResetStudentPassword.php"
           class="<?php echo _active('ResetStudentPassword.php','Admin'); ?>">&#128274; Reset Student Password</a></li>
    <li class="mobile-nav-cat">&#128100; Account</li>
    <li><a href="<?php echo $_root; ?>exam/settings.php"
           class="<?php echo _sectionActive('settings.php'); ?>">&#9881; My Settings</a></li>

    <?php elseif ($_navIsAdmin): ?>
    <!-- ── Admin mobile: categorized ──────────────────────── -->
    <li><a href="<?php echo $_root; ?>exam/dashboard.php"
           class="<?php echo _active('dashboard.php','exam'); ?>">&#127968; Dashboard</a></li>
    <!-- Exams -->
    <li class="mobile-nav-cat">&#128196; Exams</li>
    <li><a href="<?php echo $_root; ?>exam/search.php"
           class="<?php echo _active('search.php','exam'); ?>">&#128269; Exam List</a></li>
    <li><a href="<?php echo $_root; ?>exam/manage.php?InfoId=0"
           class="<?php echo _active('manage.php','exam'); ?>">&#10010; Add Exam</a></li>
    <li><a href="<?php echo $_root; ?>exam/browse-subjects.php"
           class="<?php echo _active('browse-subjects.php','exam'); ?>">&#128218; Browse &amp; Enroll (Student View)</a></li>
    <li><a href="<?php echo $_root; ?>Admin/ExamAttemptOverrides.php"
           class="<?php echo _active('ExamAttemptOverrides.php','Admin'); ?>">&#128260; Attempt Overrides</a></li>
    <li><a href="<?php echo $_root; ?>exam/questions-hub.php"
           class="<?php echo _active('questions-hub.php','exam'); ?>">&#10067; Manage Questions</a></li>
    <li><a href="<?php echo $_root; ?>exam/log.php"
           class="<?php echo _active('log.php','exam'); ?>">&#128203; Exam Log</a></li>
    <li><a href="<?php echo $_root; ?>exam/setup.php"
           class="<?php echo _active('setup.php','exam'); ?>">&#9881; Setup</a></li>
    <!-- Users -->
    <li class="mobile-nav-cat">&#128101; Users</li>
    <li><a href="<?php echo $_root; ?>Admin/AdminUsers.php?tab=students"
           class="<?php echo _active('AdminUsers.php','Admin'); ?>">&#128203; Registered Students</a></li>
    <li><a href="<?php echo $_root; ?>Admin/AdminUsers.php?tab=logins">&#128204; Logged-in Users</a></li>
    <li><a href="<?php echo $_root; ?>Admin/StudentApprovals.php"
           class="<?php echo _active('StudentApprovals.php','Admin'); ?>">&#9989; Student Approvals
      <?php if ($_navPendingStudents > 0): ?>
        <span style="background:#ef4444;color:#fff;border-radius:10px;padding:1px 7px;font-size:.72rem;margin-left:4px;"><?php echo $_navPendingStudents; ?></span>
      <?php endif; ?>
    </a></li>
    <li><a href="<?php echo $_root; ?>Admin/UserChangeRequests.php"
           class="<?php echo _active('UserChangeRequests.php','Admin'); ?>">&#128221; Change Requests</a></li>
    <li><a href="<?php echo $_root; ?>Admin/ManageTeachers.php"
           class="<?php echo _active('ManageTeachers.php','Admin'); ?>">&#127891; Teachers</a></li>
    <li><a href="<?php echo $_root; ?>Admin/SearchUser.php">&#128269; Search User</a></li>
    <li><a href="<?php echo $_root; ?>Admin/ManageInstitutes.php"
           class="<?php echo _active('ManageInstitutes.php','Admin'); ?>">&#127982; Manage Institutes</a></li>
    <li><a href="<?php echo $_root; ?>Admin/InstituteStudents.php"
           class="<?php echo _active('InstituteStudents.php','Admin'); ?>">&#127979; Institutes &amp; Students</a></li>
    <!-- Study References (mobile admin) -->
    <li class="mobile-nav-cat">&#128218; References</li>
    <li><a href="<?php echo $_root; ?>Admin/StudyReferences.php"
           class="<?php echo _active('StudyReferences.php','Admin'); ?>">&#128203; All References</a></li>
    <li><a href="<?php echo $_root; ?>Admin/AddEditStudyReference.php?RefId=0">&#10010; Add Reference</a></li>
    <li><a href="<?php echo $_root; ?>Admin/StudyReferences.php?category=Interview+Questions&sub=MCQ">&#10067; Interview Q. – MCQ</a></li>
    <li><a href="<?php echo $_root; ?>Admin/StudyReferences.php?category=Interview+Questions&sub=Technical">&#128295; Interview Q. – Technical</a></li>
    <!-- Chapters (mobile admin) -->
    <li class="mobile-nav-cat">&#128213; Chapters</li>
    <li><a href="<?php echo $_root; ?>Admin/ChapterInfo.php"
           class="<?php echo _active('ChapterInfo.php','Admin'); ?>">&#128213; All Chapters</a></li>
    <li><a href="<?php echo $_root; ?>Admin/AddEditChapterInfo.php?ChapterInfoId=0">&#10010; Add Chapter</a></li>
    <!-- Results -->
    <li class="mobile-nav-cat">&#128202; Results</li>
    <li><a href="<?php echo $_root; ?>Admin/ExamResults.php">&#127942; Exam Scores</a></li>
    <li><a href="<?php echo $_root; ?>Admin/ExamHistoryList.php">&#127941; Rank &amp; History</a></li>
    <li><a href="<?php echo $_root; ?>Admin/marks.php">&#128200; Subject-wise Marks</a></li>
    <li><a href="<?php echo $_root; ?>Admin/Charts.php">&#128202; Performance Graph</a></li>
    <!-- Certificates -->
    <li class="mobile-nav-cat">&#127942; Certificates</li>
    <li><a href="<?php echo $_root; ?>Admin/GenerateCertificates.php">&#10010; Generate Certificates</a></li>
    <li><a href="<?php echo $_root; ?>Admin/CertificatesIssued.php"
           class="<?php echo _active('CertificatesIssued.php','Admin'); ?>">&#128203; Issued Certificates</a></li>
    <li><a href="<?php echo $_root; ?>Admin/CertificateTemplates.php"
           class="<?php echo _active('CertificateTemplates.php','Admin'); ?>">&#127912; Templates</a></li>
    <li><a href="<?php echo $_root; ?>exam/verify-certificate.php">&#10003; Verify Certificate</a></li>
    <!-- Notifications -->
    <li class="mobile-nav-cat">&#128276; Notifications</li>
    <li><a href="<?php echo $_root; ?>Admin/Notices.php">&#9201; Exam Reminders</a></li>
    <li><a href="<?php echo $_root; ?>Admin/NoticesSent.php">&#128232; Result Announcements</a></li>
    <li><a href="<?php echo $_root; ?>Admin/EnrollmentPayments.php">&#128203; Enrollment Updates</a></li>
    <!-- Payments -->
    <li class="mobile-nav-cat">&#128181; Payments</li>
    <li><a href="<?php echo $_root; ?>Admin/EnrollmentPayments.php">&#128179; Payment History</a></li>
    <li><a href="<?php echo $_root; ?>Admin/EnrollmentPayments.php?receipts=1">&#129534; Fee Receipts</a></li>
    <li><a href="<?php echo $_root; ?>Admin/ManageCoupons.php">&#127991; Coupon Usage</a></li>
    <li><a href="<?php echo $_root; ?>Admin/reports.php">&#128200; Reports</a></li>
    <!-- Feedback -->
    <li class="mobile-nav-cat">&#128172; Feedback</li>
    <li><a href="<?php echo $_root; ?>Admin/FeedbackDashboard.php"
           class="<?php echo _active('FeedbackDashboard.php','Admin'); ?>">&#128202; Feedback Dashboard</a></li>
    <!-- Support / Tickets -->
    <li class="mobile-nav-cat">&#127381; Support</li>
    <li><a href="<?php echo $_root; ?>Admin/TicketDashboard.php"
           class="<?php echo _active('TicketDashboard.php','Admin'); ?>">&#128202; Ticket Dashboard</a></li>
    <li><a href="<?php echo $_root; ?>Admin/Tickets.php"
           class="<?php echo _active('Tickets.php','Admin'); ?>">&#127381; All Tickets</a></li>
    <li><a href="<?php echo $_root; ?>Admin/Tickets.php?sla=breached">&#9888; SLA Breached</a></li>
    <!-- Monitor -->
    <li class="mobile-nav-cat">&#128250; Monitor</li>
    <li><a href="<?php echo $_root; ?>Admin/LiveExamCenter.php"
           class="<?php echo _active('LiveExamCenter.php','Admin'); ?>">&#128250; Live Exam Center</a></li>
    <li><a href="<?php echo $_root; ?>Admin/CheatingDashboard.php"
           class="<?php echo _active('CheatingDashboard.php','Admin'); ?>">&#128270; Cheating Dashboard</a></li>
    <li><a href="<?php echo $_root; ?>Admin/LoginTrack.php"
           class="<?php echo _active('LoginTrack.php','Admin'); ?>">&#128203; Login History</a></li>
    <!-- Configuration -->
    <li class="mobile-nav-cat">&#9881; Configuration</li>
    <li><a href="<?php echo $_root; ?>Admin/AppSettings.php"
           class="<?php echo _active('AppSettings.php','Admin'); ?>">&#9881; App Settings</a></li>

    <?php else: ?>
    <!-- ── Student mobile: Dashboard ─────────────────────── -->
    <li class="mobile-nav-cat">&#127968; Dashboard</li>
    <li><a href="<?php echo $_root; ?>exam/search.php"
           class="<?php echo _active('search.php','exam'); ?>">&#128197; Upcoming Exams</a></li>
    <li><a href="<?php echo $_root; ?>exam/history.php?filter=completed">&#10003; Completed Exams</a></li>
    <li><a href="<?php echo $_root; ?>exam/browse-subjects.php"
           class="<?php echo _active('browse-subjects.php','exam'); ?>">&#128218; Browse &amp; Enroll</a></li>
    <li><a href="<?php echo $_root; ?>exam/history.php">&#128200; Recent Results</a></li>
    <li><a href="<?php echo $_root; ?>exam/performance.php"
           class="<?php echo _active('performance.php','exam'); ?>">&#128201; My Performance</a></li>
    <li><a href="<?php echo $_root; ?>exam/certificates.php"
           class="<?php echo _active('certificates.php','exam'); ?>">&#127942; Certificates Earned</a></li>
    <!-- ── Student mobile: My Exams ──────────────────────── -->
    <li class="mobile-nav-cat">&#128196; My Exams</li>
    <li><a href="<?php echo $_root; ?>exam/search.php">&#128269; Available Exams</a></li>
    <li><a href="<?php echo $_root; ?>exam/search.php?filter=scheduled">&#128197; Scheduled Exams</a></li>
    <li><a href="<?php echo $_root; ?>exam/write.php"
           class="<?php echo _active('write.php','exam'); ?>">&#9654; Start Exam</a></li>
    <?php
      /* Was hardcoded to exam/browse-subjects.php#instructions — a dead
         anchor (no element on that page has id="instructions"), so this
         just silently dropped the student onto Browse & Enroll, a
         different section entirely, instead of showing any instructions.
         Instructions are inherently per-exam (see the collapsible
         "Exam Instructions" banner in exam/write.php, #examInstructionsBanner)
         so a global nav item can't show them without knowing which exam —
         write.php stashes the last-opened exam in $_SESSION['InfoId'], so
         reuse that to jump straight back to that exam's instructions when
         there is one; otherwise send the student to My Exams to pick an
         exam, never to the unrelated enroll/catalogue page. */
      $_instrExamId = (int)($_SESSION['InfoId'] ?? 0);
      $_instrHref   = $_instrExamId > 0
          ? $_root . 'exam/write.php?InfoId=' . $_instrExamId . '#examInstructionsBanner'
          : $_root . 'exam/search.php';
    ?>
    <li><a href="<?php echo $_instrHref; ?>">&#128221; Exam Instructions</a></li>
    <li><a href="<?php echo $_root; ?>exam/teacher-courses.php"
           class="<?php echo _active('teacher-courses.php','exam'); ?>">&#127891; Teacher Courses</a></li>
    <!-- Student study references (mobile) -->
    <li class="mobile-nav-cat">&#128218; Study Resources</li>
    <li><a href="<?php echo $_root; ?>exam/study-references.php"
           class="<?php echo _active('study-references.php','exam'); ?>">&#128218; All References</a></li>
    <li><a href="<?php echo $_root; ?>exam/study-references.php?type=Book">&#128214; Books</a></li>
    <li><a href="<?php echo $_root; ?>exam/study-references.php?type=Video">&#127909; Videos</a></li>
    <li><a href="<?php echo $_root; ?>exam/study-references.php?type=Website">&#127760; Websites</a></li>
    <li><a href="<?php echo $_root; ?>exam/study-references.php?category=Interview+Questions&sub=MCQ">&#10067; Interview Q. – MCQ</a></li>
    <li><a href="<?php echo $_root; ?>exam/study-references.php?category=Interview+Questions&sub=Technical">&#128295; Interview Q. – Technical</a></li>
    <!-- Student Support -->
    <li class="mobile-nav-cat">&#127381; Support</li>
    <li><a href="<?php echo $_root; ?>exam/tickets.php"
           class="<?php echo _active('tickets.php','exam'); ?>">&#127381; My Tickets</a></li>
    <li><a href="<?php echo $_root; ?>exam/tickets.php" onclick="setTimeout(function(){document.querySelector('[name=priority][value=critical]')&&(document.querySelector('[name=priority][value=critical]').selected=true)},100);">&#128308; Raise Urgent Ticket</a></li>
    <!-- Student Account -->
    <li class="mobile-nav-cat">&#128100; Account</li>
    <li><a href="<?php echo $_root; ?>exam/settings.php"
           class="<?php echo _active('settings.php','exam'); ?>">&#9881; Settings &amp; Profile</a></li>
    <li><a href="<?php echo $_root; ?>exam/settings.php?tab=password"
           class="<?php echo ($_navCurrent==='settings.php' && ($_GET['tab']??'')==='password') ? 'active' : ''; ?>">&#128274; Change Password</a></li>
    <?php endif; ?>

    <li>
      <a href="<?php echo $_root; ?>exam/feedback.php"
         class="<?php echo _active('feedback.php','exam'); ?>">&#128172; Feedback</a>
    </li>
  </ul>

  <!-- User info in mobile drawer -->
  <div class="mobile-user">
    <?php if ($_navIsAdmin || $_navIsInstAdmin): ?>
      <div style="margin-bottom:8px;"><?php echo Pii::toggleButton($_navReturnTo); ?></div>
    <?php endif; ?>
    <span class="user-name">&#128100; <?php echo htmlspecialchars($_navUser); ?></span>
    <span class="badge <?php echo $_navIsAdmin ? 'badge-admin' : ($_navIsInstAdmin ? 'badge-instadmin' : 'badge-student'); ?>">
      <?php echo htmlspecialchars($_navRole); ?>
    </span>
    <a href="<?php echo $_root; ?>auth/logout.php" class="btn-logout">Logout</a>
  </div>
</div>

<!-- ── Session expiry warning banner ─────────────────────── -->
<div id="sessionWarning" role="alert" aria-live="assertive"
     style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:9999;
            background:#7c3aed;color:#fff;text-align:center;
            padding:12px 16px;font-size:.9rem;font-weight:600;
            box-shadow:0 -2px 12px rgba(0,0,0,.25);">
  &#9203; Your session will expire in <span id="sessionCountdown">2:00</span> due to inactivity.
  <a href="#" onclick="fetch('',{method:'HEAD'});document.getElementById('sessionWarning').style.display='none';return false;"
     style="color:#c4b5fd;margin-left:12px;text-decoration:underline;">Stay signed in</a>
  &nbsp;|&nbsp;
  <a href="<?php echo $_root; ?>auth/logout.php" style="color:#fca5a5;text-decoration:underline;">Sign out now</a>
</div>

<!-- ── App shell opens here: sidebar + main content ────── -->
<div class="app-shell">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <main class="app-main">

<script>
/* ── Hamburger nav toggle ───────────────────────────────── */
(function() {
  var toggle   = document.getElementById('navToggle');
  var nav      = document.getElementById('mobileNav');
  var backdrop = document.getElementById('navBackdrop');
  if (!toggle) return;

  function openNav() {
    toggle.classList.add('open');
    toggle.setAttribute('aria-expanded', 'true');
    nav.classList.add('is-open');
    nav.setAttribute('aria-hidden', 'false');
    backdrop.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }
  function closeNav() {
    toggle.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
    nav.classList.remove('is-open');
    nav.setAttribute('aria-hidden', 'true');
    backdrop.classList.remove('is-open');
    document.body.style.overflow = '';
  }
  toggle.addEventListener('click', function() {
    nav.classList.contains('is-open') ? closeNav() : openNav();
  });
  backdrop.addEventListener('click', closeNav);

  /* Close on Escape */
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeNav();
  });

  /* Close if viewport goes above lg breakpoint */
  var mq = window.matchMedia('(min-width: 1024px)');
  mq.addEventListener('change', function(e) { if (e.matches) closeNav(); });

  /* Auto-wrap tables inside .card-body for horizontal scroll */
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.card-body table.tbl, .card-body > table').forEach(function(tbl) {
      if (tbl.parentElement.classList.contains('tbl-wrap')) return;
      var wrap = document.createElement('div');
      wrap.className = 'tbl-wrap';
      tbl.parentNode.insertBefore(wrap, tbl);
      wrap.appendChild(tbl);
    });
  });
})();

/* ── Session expiry countdown ─────────────────────────────── */
(function () {
  var TIMEOUT   = <?php echo defined('SESSION_TIMEOUT') ? (int)SESSION_TIMEOUT : 900; ?>; // seconds
  var WARN_AT   = 120;  // show banner this many seconds before expiry
  // While an exam is in progress, don't let this purely-client-side clock
  // force a logout before the exam's allotted time (+ grace) is up — a
  // student reading a long question without touching the mouse/keyboard
  // shouldn't get evicted mid-exam. Mirrors $_SESSION['exam_deadline'] set
  // by exam/write.php and cleared by exam/submit.php.
  var examDeadlineTs = <?php echo !empty($_SESSION['exam_deadline']) ? (int)$_SESSION['exam_deadline'] : 0; ?>;
  var remaining = TIMEOUT;
  var banner    = document.getElementById('sessionWarning');
  var countEl   = document.getElementById('sessionCountdown');
  if (!banner || !countEl) return;

  function fmt(s) {
    var m = Math.floor(s / 60), sec = s % 60;
    return m + ':' + (sec < 10 ? '0' : '') + sec;
  }

  // Reset timer on any user interaction
  function resetTimer() { remaining = TIMEOUT; banner.style.display = 'none'; }
  ['click','keydown','mousemove','touchstart','scroll'].forEach(function(ev) {
    document.addEventListener(ev, resetTimer, {passive:true});
  });

  // Exposed so exam/write.php can nudge this timer on every successful
  // autosave ping, not just on raw DOM interaction.
  window.__resetSessionCountdown = resetTimer;

  setInterval(function () {
    remaining = Math.max(0, remaining - 1);

    var effectiveRemaining = remaining;
    if (examDeadlineTs > 0) {
      var examRemaining = Math.floor(examDeadlineTs - (Date.now() / 1000));
      effectiveRemaining = Math.max(remaining, examRemaining);
    }

    if (effectiveRemaining <= 0) {
      window.location.href = '<?php echo $_root; ?>auth/logout.php?timeout=1';
      return;
    }
    if (effectiveRemaining <= WARN_AT) {
      banner.style.display = 'block';
      countEl.textContent = fmt(effectiveRemaining);
    } else {
      banner.style.display = 'none';
    }
  }, 1000);
})();
</script>


