<?php
/**
 * exam/razorpay-verify.php — DEPRECATED (migration_v51).
 *
 * Subject-level checkout has been retired; verification now happens in
 * exam/razorpay-verify-exam.php. Nothing in the current UI calls this
 * endpoint any more. Kept only as a safe stub so a stale client fails
 * loudly instead of writing to the legacy enrollment_payments ledger.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

header('Content-Type: application/json');

if (!Auth::isLoggedIn()) { echo json_encode(['ok' => false, 'error' => 'Not logged in']); exit; }

echo json_encode([
    'ok'    => false,
    'error' => 'Subject-level checkout has been retired. Please enroll from the exam catalogue (Browse & Enroll) instead.',
]);
