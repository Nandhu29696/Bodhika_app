<?php
/**
 * exam/enroll.php — DEPRECATED (migration_v51).
 *
 * Subject-level checkout has been retired: pricing, discounts, and coupons
 * now live on the exam (see exam/enroll-exam.php). Nothing in the current UI
 * links here any more — this file only exists in case an old bookmark or
 * search-engine link still points at it — so it just forwards the student
 * to the current "Browse Exams" page instead of running any checkout logic
 * against the legacy subject fee columns.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

header('Location: browse-subjects.php?msg=' . urlencode(
    'Enrollment now happens per exam. Pick the exam you want below.'));
exit;
