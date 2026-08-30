<?php
/**
 * includes/sidebar.php — Persistent left navigation.
 *
 * Included by header.php (inside .app-shell, before <main>). Relies on
 * globals/helpers already set up by header.php: $_root, $_navCurrent,
 * $_navIsAdmin, $_navIsTeacher, $_navIsInstAdmin, and the
 * _active()/_sectionActive() functions.
 *
 * Two render helpers keep every item declarative (href/icon/label/active):
 *   _sbFlat()  — a single-destination link (no children)
 *   _sbGroup() — a collapsible <details> section; auto-expands itself when
 *                the current page belongs to it, so deep links land "open".
 */

/** True if the current page (basename) is one of $files. */
function _sbOpen(array $files): bool {
    global $_navCurrent;
    return in_array($_navCurrent, $files, true);
}

function _sbFlat(string $href, string $icon, string $label, bool $active = false, string $extra = ''): void {
    ?>
    <a class="sb-link sb-flat<?php echo $active ? ' active' : ''; ?>" href="<?php echo $href; ?>">
      <span class="sb-ic"><?php echo $icon; ?></span><span class="sb-label"><?php echo htmlspecialchars($label); ?></span><?php echo $extra; ?>
    </a>
    <?php
}

/**
 * @param array<int,array{href:string,icon:string,label:string,active?:bool,extra?:string}> $links
 */
function _sbGroup(string $icon, string $label, array $links, bool $forceOpen = false): void {
    $isOpen = $forceOpen;
    foreach ($links as $l) { if (!empty($l['active'])) { $isOpen = true; break; } }
    ?>
    <details class="sb-group"<?php echo $isOpen ? ' open' : ''; ?>>
      <summary class="sb-link sb-summary<?php echo $isOpen ? ' active' : ''; ?>">
        <span class="sb-ic"><?php echo $icon; ?></span><span class="sb-label"><?php echo htmlspecialchars($label); ?></span>
        <span class="sb-caret" aria-hidden="true">&#9656;</span>
      </summary>
      <div class="sb-sub">
        <?php foreach ($links as $l): ?>
          <a class="sb-sublink<?php echo !empty($l['active']) ? ' active' : ''; ?>" href="<?php echo $l['href']; ?>">
            <?php echo $l['icon']; ?> <?php echo htmlspecialchars($l['label']); ?><?php echo $l['extra'] ?? ''; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </details>
    <?php
}
?>
<aside class="app-sidebar" aria-label="Section navigation">

  <?php if ($_navIsAdmin): ?>
  <!-- ══════════════════════ ADMIN SIDEBAR ══════════════════════ -->

  <?php /* Admin quick-stats + "+ Add Exam" button previously lived here
           (.sb-stats-widget). The stats now render in the top navbar
           (includes/header.php, top-right) and the standalone "+ Add Exam"
           button was removed outright — "Add Exam" is still reachable from
           the Exams section below. */ ?>

  <?php _sbFlat($_root.'exam/dashboard.php', '&#127968;', 'Dashboard', _sbOpen(['dashboard.php'])); ?>

  <?php _sbGroup('&#128196;', 'Exams', [
        ['href'=>$_root.'exam/search.php',                       'icon'=>'&#128269;', 'label'=>'Exam List'],
        ['href'=>$_root.'exam/manage.php?InfoId=0',              'icon'=>'&#10010;',  'label'=>'Add Exam'],
        ['href'=>$_root.'exam/browse-subjects.php',              'icon'=>'&#128218;', 'label'=>'Browse & Enroll (Student View)'],
        ['href'=>$_root.'Admin/ExamAttemptOverrides.php',        'icon'=>'&#128260;', 'label'=>'Attempt Overrides'],
        ['href'=>$_root.'exam/questions-hub.php',                'icon'=>'&#10067;',  'label'=>'Manage Questions'],
        ['href'=>$_root.'Admin/BulkUploadQuestions.php',         'icon'=>'&#11014;',  'label'=>'Bulk Upload'],
        ['href'=>$_root.'Admin/ImportExamTemplate.php',          'icon'=>'&#128196;', 'label'=>'Import Test Paper (.docx)'],
        ['href'=>$_root.'Admin/TranslateExam.php',               'icon'=>'&#127760;', 'label'=>'Translate Exam'],
        ['href'=>$_root.'Admin/Languages.php',                   'icon'=>'&#127760;', 'label'=>'Languages'],
        ['href'=>$_root.'exam/log.php',                          'icon'=>'&#128203;', 'label'=>'Exam Log'],
        ['href'=>$_root.'exam/setup.php',                        'icon'=>'&#9881;',   'label'=>'Setup'],
      ], _sbOpen(['manage.php','questions-hub.php','questions.php','question-edit.php','log.php','setup.php','BulkUploadQuestions.php','ImportExamTemplate.php','ExamSearch.php','AddEditExam.php','ViewExamDetails.php','ExamAttemptOverrides.php','TranslateExam.php','Languages.php','browse-subjects.php'])); ?>

  <?php
    $__pendingCount = 0;
    try {
        $__pendingCount = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM user_change_requests WHERE Status='pending'", [])['c'] ?? 0);
    } catch (\Throwable $e) {}
    _sbGroup('&#128101;', 'Users', [
        ['href'=>$_root.'Admin/AdminUsers.php?tab=students',     'icon'=>'&#128203;', 'label'=>'Registered Students'],
        ['href'=>$_root.'Admin/BulkUploadStudents.php',          'icon'=>'&#11014;',  'label'=>'Bulk Upload Students', 'active'=>_sbOpen(['BulkUploadStudents.php'])],
        ['href'=>$_root.'Admin/AdminUsers.php?tab=logins',       'icon'=>'&#128204;', 'label'=>'Logged-in Users'],
        ['href'=>$_root.'Admin/UserChangeRequests.php',          'icon'=>'&#128221;', 'label'=>'Change Requests',
            'extra'=> $__pendingCount > 0
                ? ' <span class="sb-badge">'.$__pendingCount.'</span>'
                : ''],
        ['href'=>$_root.'Admin/ManageTeachers.php',              'icon'=>'&#127891;', 'label'=>'Teachers'],
        ['href'=>$_root.'Admin/SearchUser.php',                  'icon'=>'&#128269;', 'label'=>'Search User'],
        ['href'=>$_root.'Admin/AddUser.php',                     'icon'=>'&#10010;',  'label'=>'Add User'],
        ['href'=>$_root.'Admin/ManageInstitutes.php',            'icon'=>'&#127982;', 'label'=>'Manage Institutes', 'active'=>_sbOpen(['ManageInstitutes.php'])],
        ['href'=>$_root.'Admin/InstituteStudents.php',           'icon'=>'&#127979;', 'label'=>'Institutes & Students', 'active'=>_sbOpen(['InstituteStudents.php'])],
        ['href'=>$_root.'Admin/BulkAssignInstitute.php',         'icon'=>'&#128279;', 'label'=>'Bulk Assign Institute', 'active'=>_sbOpen(['BulkAssignInstitute.php'])],
        ['href'=>$_root.'Admin/StudentGroups.php',               'icon'=>'&#128101;', 'label'=>'Student Groups', 'active'=>_sbOpen(['StudentGroups.php','StudentGroupEdit.php','StudentGroupMembers.php','StudentGroupExams.php'])],
        ['href'=>$_root.'Admin/ChangeUserRole.php',              'icon'=>'&#128273;', 'label'=>'Change User Role', 'active'=>_sbOpen(['ChangeUserRole.php'])],
        ['href'=>$_root.'Admin/ResetStudentPassword.php',        'icon'=>'&#128274;', 'label'=>'Reset Student Password', 'active'=>_sbOpen(['ResetStudentPassword.php'])],
      ], _sbOpen(['AdminUsers.php','ManageTeachers.php','SearchUser.php','AddUser.php','ManageInstitutes.php','InstituteStudents.php','BulkAssignInstitute.php','BulkUploadStudents.php','StudentGroups.php','StudentGroupEdit.php','StudentGroupMembers.php','StudentGroupExams.php','AddStudent.php','AdmittedStudentList.php','SearchStudent.php','ViewStudent.php','EditStudent.php','ChangeUserRole.php','LoginTrack.php','UserChangeRequests.php','ResetStudentPassword.php']));
  ?>

  <?php _sbGroup('&#128218;', 'References', [
        ['href'=>$_root.'Admin/StudyReferences.php',             'icon'=>'&#128203;', 'label'=>'All References'],
        ['href'=>$_root.'Admin/AddEditStudyReference.php?RefId=0','icon'=>'&#10010;', 'label'=>'Add Reference'],
        ['href'=>$_root.'exam/study-references.php',             'icon'=>'&#128270;', 'label'=>'Student View'],
        ['href'=>$_root.'Admin/StudyReferences.php?category=Interview+Questions&sub=MCQ',
            'icon'=>'&#10067;', 'label'=>'Interview Q. – MCQ'],
        ['href'=>$_root.'Admin/StudyReferences.php?category=Interview+Questions&sub=Technical',
            'icon'=>'&#128295;', 'label'=>'Interview Q. – Technical'],
      ], _sbOpen(['StudyReferences.php','AddEditStudyReference.php','study-references.php'])); ?>

  <?php _sbGroup('&#128213;', 'Chapters', [
        ['href'=>$_root.'Admin/ChapterInfo.php',                 'icon'=>'&#128213;', 'label'=>'All Chapters'],
        ['href'=>$_root.'Admin/AddEditChapterInfo.php?ChapterInfoId=0','icon'=>'&#10010;', 'label'=>'Add Chapter'],
      ], _sbOpen(['ChapterInfo.php','AddEditChapterInfo.php'])); ?>

  <?php _sbGroup('&#127942;', 'ExamPath Directory', [
        ['href'=>$_root.'Admin/ExamDirectoryList.php',                    'icon'=>'&#128213;', 'label'=>'All Directory Exams'],
        ['href'=>$_root.'Admin/AddEditExamDirectory.php?ExamDirectoryId=0','icon'=>'&#10010;',  'label'=>'Add Directory Exam'],
        ['href'=>$_root.'exam/exam-directory.php',                        'icon'=>'&#128269;', 'label'=>'Student View'],
      ], _sbOpen(['ExamDirectoryList.php','AddEditExamDirectory.php'])); ?>

  <?php _sbGroup('&#128218;', 'Catalog', [
        ['href'=>$_root.'Admin/SubjectInfo.php',                 'icon'=>'&#128218;', 'label'=>'All Subjects'],
        ['href'=>$_root.'Admin/AddEditSubjectInfo.php?InfoId=0', 'icon'=>'&#10010;',  'label'=>'Add Subject'],
        ['href'=>$_root.'exam/grades.php',                       'icon'=>'&#127891;', 'label'=>'Grades'],
        ['href'=>$_root.'exam/groups.php',                       'icon'=>'&#127959;', 'label'=>'Groups'],
      ], _sbOpen(['SubjectInfo.php','AddEditSubjectInfo.php','grades.php','groups.php'])); ?>

  <?php _sbGroup('&#128202;', 'Results', [
        ['href'=>$_root.'Admin/ExamResults.php',                 'icon'=>'&#127942;', 'label'=>'Exam Scores'],
        ['href'=>$_root.'Admin/ExamHistoryList.php',             'icon'=>'&#127941;', 'label'=>'Rank & History'],
        ['href'=>$_root.'Admin/marks.php',                       'icon'=>'&#128200;', 'label'=>'Subject-wise Marks'],
        ['href'=>$_root.'Admin/Charts.php',                      'icon'=>'&#128202;', 'label'=>'Performance Graph'],
      ], _sbOpen(['ExamResults.php','ExamHistoryList.php','marks.php','Charts.php','GradeInfo.php','reports.php','InstituteReports.php'])); ?>

  <?php _sbGroup('&#127942;', 'Certificates', [
        ['href'=>$_root.'Admin/GenerateCertificates.php',        'icon'=>'&#10010;',  'label'=>'Generate Certificates'],
        ['href'=>$_root.'Admin/CertificatesIssued.php',          'icon'=>'&#128203;', 'label'=>'Issued Certificates', 'active'=>_sbOpen(['CertificatesIssued.php'])],
        ['href'=>$_root.'Admin/CertificateTemplates.php',        'icon'=>'&#127912;', 'label'=>'Templates', 'active'=>_sbOpen(['CertificateTemplates.php','CertificateTemplateDesign.php','CertificateTemplateWordMap.php'])],
        ['href'=>$_root.'exam/verify-certificate.php',           'icon'=>'&#10003;',  'label'=>'Verify Certificate'],
      ], _sbOpen(['CertificatesIssued.php','GenerateCertificates.php','CertificateTemplates.php','CertificateTemplateDesign.php','CertificateTemplateWordMap.php'])); ?>

  <?php _sbGroup('&#128276;', 'Notify', [
        ['href'=>$_root.'Admin/Notices.php',                     'icon'=>'&#9201;',   'label'=>'Exam Reminders'],
        ['href'=>$_root.'Admin/NoticesSent.php',                 'icon'=>'&#128232;', 'label'=>'Result Announcements'],
        ['href'=>$_root.'Admin/EnrollmentPayments.php',          'icon'=>'&#128203;', 'label'=>'Enrollment Updates'],
        ['href'=>$_root.'Admin/EMail.php',                       'icon'=>'&#9993;',   'label'=>'Send Email'],
      ], _sbOpen(['Notices.php','NoticesSent.php','EMail.php','sendSMS.php','AddEditMsgTemplateInfo.php'])); ?>

  <?php _sbGroup('&#127381;', 'Support', [
        ['href'=>$_root.'Admin/TicketDashboard.php',             'icon'=>'&#128202;', 'label'=>'Ticket Dashboard', 'active'=>_sbOpen(['TicketDashboard.php'])],
        ['href'=>$_root.'Admin/Tickets.php',                     'icon'=>'&#127381;', 'label'=>'All Tickets', 'active'=>_sbOpen(['Tickets.php'])],
        ['href'=>$_root.'Admin/Tickets.php?status=open',         'icon'=>'&#128308;', 'label'=>'Open Tickets'],
        ['href'=>$_root.'Admin/Tickets.php?sla=breached',        'icon'=>'&#9888;',   'label'=>'SLA Breached'],
      ], _sbOpen(['Tickets.php','TicketView.php','TicketDashboard.php'])); ?>

  <?php _sbGroup('&#128181;', 'Payments', [
        ['href'=>$_root.'Admin/EnrollmentPayments.php',          'icon'=>'&#128179;', 'label'=>'Payment History'],
        ['href'=>$_root.'Admin/EnrollmentPayments.php?receipts=1','icon'=>'&#129534;', 'label'=>'Fee Receipts'],
        ['href'=>$_root.'Admin/ManageCoupons.php',               'icon'=>'&#127991;', 'label'=>'Coupon Usage'],
        ['href'=>$_root.'Admin/InstituteDiscounts.php',          'icon'=>'&#127982;', 'label'=>'Institute Discounts'],
        ['href'=>$_root.'Admin/StudentGroups.php',               'icon'=>'&#128101;', 'label'=>'Student Group Discounts'],
        ['href'=>$_root.'Admin/reports.php',                     'icon'=>'&#128200;', 'label'=>'All Reports'],
      ], _sbOpen(['EnrollmentPayments.php','PendingPayments.php','ManageCoupons.php','InstituteDiscounts.php'])); ?>

  <?php // Feedback Dashboard lived here (buried under an unrelated "Payments"
        // group, 7th item deep) — moved to "Monitor" below, where the rest of
        // the admin dashboards/reports already live, so it's actually findable. ?>
  <?php _sbGroup('&#128250;', 'Monitor', [
        ['href'=>$_root.'Admin/LiveExamCenter.php',              'icon'=>'&#128250;', 'label'=>'Live Exam Center', 'active'=>_sbOpen(['LiveExamCenter.php'])],
        ['href'=>$_root.'Admin/CheatingDashboard.php',           'icon'=>'&#128270;', 'label'=>'Cheating Dashboard', 'active'=>_sbOpen(['CheatingDashboard.php'])],
        ['href'=>$_root.'Admin/FeedbackDashboard.php',           'icon'=>'&#128172;', 'label'=>'Feedback Dashboard', 'active'=>_sbOpen(['FeedbackDashboard.php'])],
        ['href'=>$_root.'Admin/LoginTrack.php',                  'icon'=>'&#128203;', 'label'=>'Login History', 'active'=>_sbOpen(['LoginTrack.php'])],
        ['href'=>$_root.'Admin/AppSettings.php',                 'icon'=>'&#9881;',   'label'=>'App Settings', 'active'=>_sbOpen(['AppSettings.php'])],
      ], _sbOpen(['LiveExamCenter.php','CheatingDashboard.php','FeedbackDashboard.php','LoginTrack.php','AppSettings.php'])); ?>

  <div class="sb-divider"></div>
  <?php _sbFlat($_root.'exam/settings.php', '&#9881;', 'My Settings', _sbOpen(['settings.php'])); ?>

  <?php elseif ($_navIsInstAdmin): ?>
  <!-- ══════════════════ INSTITUTE-ADMIN SIDEBAR ══════════════════ -->
  <!-- Deliberately small: Institute-Admin only sees the students in their
       own institute (Auth::currentInstituteId()) — never the full Admin
       menu (AddUser, ManageExams, other institutes, etc.). -->

  <?php _sbFlat($_root.'Admin/InstituteAdminHome.php', '&#127968;', 'Dashboard',
            _sbOpen(['InstituteAdminHome.php'])); ?>

  <?php _sbFlat($_root.'Admin/InstituteAdminExams.php', '&#128220;', 'My Exams',
            _sbOpen(['InstituteAdminExams.php'])); ?>

  <?php _sbGroup('&#128101;', 'My Institute', [
        ['href'=>$_root.'Admin/InstituteAdminStudents.php', 'icon'=>'&#128203;', 'label'=>'Students'],
        ['href'=>$_root.'Admin/ResetStudentPassword.php',   'icon'=>'&#128274;', 'label'=>'Reset Student Password'],
      ], true); ?>

  <div class="sb-divider"></div>
  <?php _sbFlat($_root.'exam/settings.php', '&#9881;', 'My Settings', _sbOpen(['settings.php'])); ?>

  <?php else: ?>
  <!-- ═══════════════════ STUDENT / TEACHER SIDEBAR ═══════════════════ -->

  <?php if ($_navIsTeacher): ?>
  <?php _sbGroup('&#127891;', 'Teaching', [
        ['href'=>$_root.'exam/my-students.php', 'icon'=>'&#128101;', 'label'=>'My Students'],
      ], _sbOpen(['my-students.php'])); ?>
  <?php endif; ?>

  <?php _sbGroup('&#127968;', 'Dashboard', [
        ['href'=>$_root.'exam/search.php',
            'icon'=>'&#128197;', 'label'=>'Upcoming Exams',
            'active'=>_sbOpen(['search.php']) && empty($_GET['filter'])],
        ['href'=>$_root.'exam/history.php?filter=completed',
            'icon'=>'&#10003;', 'label'=>'Completed',
            'active'=>_sbOpen(['history.php']) && ($_GET['filter'] ?? '') === 'completed'],
        ['href'=>$_root.'exam/browse-subjects.php',
            'icon'=>'&#128218;', 'label'=>'Browse & Enroll',
            'active'=>_sbOpen(['browse-subjects.php'])],
        ['href'=>$_root.'exam/history.php',
            'icon'=>'&#128200;', 'label'=>'Recent Results',
            'active'=>_sbOpen(['history.php']) && ($_GET['filter'] ?? '') !== 'completed'],
        ['href'=>$_root.'exam/performance.php',
            'icon'=>'&#128201;', 'label'=>'My Performance',
            'active'=>_sbOpen(['performance.php'])],
        ['href'=>$_root.'exam/certificates.php',
            'icon'=>'&#127942;', 'label'=>'Certificates',
            'active'=>_sbOpen(['certificates.php'])],
      ], true); ?>

  <?php _sbGroup('&#128196;', 'My Exams', [
        ['href'=>$_root.'exam/search.php',                              'icon'=>'&#128269;', 'label'=>'Available'],
        ['href'=>$_root.'exam/search.php?filter=scheduled',              'icon'=>'&#128197;', 'label'=>'Scheduled'],
        ['href'=>$_root.'exam/browse-subjects.php#instructions',         'icon'=>'&#128221;', 'label'=>'Instructions'],
        ['href'=>$_root.'exam/teacher-courses.php', 'icon'=>'&#127891;', 'label'=>'Teacher Courses', 'active'=>_sbOpen(['teacher-courses.php'])],
      ]); ?>

  <?php _sbGroup('&#128218;', 'Study Resources', [
        ['href'=>$_root.'exam/study-references.php',
            'icon'=>'&#128218;', 'label'=>'All References',
            'active'=>_sbOpen(['study-references.php']) && empty($_GET['type']) && empty($_GET['category'])],
        ['href'=>$_root.'exam/study-references.php?type=Book',
            'icon'=>'&#128214;', 'label'=>'Books',
            'active'=>_sbOpen(['study-references.php']) && ($_GET['type'] ?? '') === 'Book'],
        ['href'=>$_root.'exam/study-references.php?type=Video',
            'icon'=>'&#127909;', 'label'=>'Videos',
            'active'=>_sbOpen(['study-references.php']) && ($_GET['type'] ?? '') === 'Video'],
        ['href'=>$_root.'exam/study-references.php?type=Website',
            'icon'=>'&#127760;', 'label'=>'Websites',
            'active'=>_sbOpen(['study-references.php']) && ($_GET['type'] ?? '') === 'Website'],
        ['href'=>$_root.'exam/study-references.php?category=Interview+Questions&sub=MCQ',
            'icon'=>'&#10067;', 'label'=>'Interview Q. – MCQ',
            'active'=>_sbOpen(['study-references.php']) && ($_GET['sub'] ?? '') === 'MCQ'],
        ['href'=>$_root.'exam/study-references.php?category=Interview+Questions&sub=Technical',
            'icon'=>'&#128295;', 'label'=>'Interview Q. – Technical',
            'active'=>_sbOpen(['study-references.php']) && ($_GET['sub'] ?? '') === 'Technical'],
      ]); ?>

  <?php _sbGroup('&#127942;', 'ExamPath Directory', [
        ['href'=>$_root.'exam/exam-directory.php', 'icon'=>'&#128269;', 'label'=>'All Exams & Colleges',
            'active'=>_sbOpen(['exam-directory.php'])],
        ['href'=>$_root.'exam/exam-timeline.php',  'icon'=>'&#128197;', 'label'=>'Timeline & Deadlines',
            'active'=>_sbOpen(['exam-timeline.php'])],
        ['href'=>$_root.'exam/career-compass.php', 'icon'=>'&#129517;', 'label'=>'Career Compass',
            'active'=>_sbOpen(['career-compass.php'])],
        ['href'=>$_root.'exam/exam-tracker.php',   'icon'=>'&#128203;', 'label'=>'My Exam Tracker',
            'active'=>_sbOpen(['exam-tracker.php'])],
      ], _sbOpen(['exam-directory.php','exam-timeline.php','career-compass.php','exam-tracker.php'])); ?>

  <div class="sb-divider"></div>
  <?php _sbFlat($_root.'exam/settings.php', '&#9881;', 'Settings',
            _sbOpen(['settings.php']) && ($_GET['tab'] ?? '') !== 'password'); ?>
  <?php _sbFlat($_root.'exam/settings.php?tab=password', '&#128274;', 'Change Password',
            _sbOpen(['settings.php']) && ($_GET['tab'] ?? '') === 'password'); ?>

  <?php endif; ?>
</aside>
