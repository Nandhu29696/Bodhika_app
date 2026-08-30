<?php
/**
 * exam/razorpay-order.php — DEPRECATED (migration_v51).
 *
 * Subject-level checkout has been retired; pricing/coupons now live on the
 * exam (see exam/razorpay-order-exam.php, called from exam/enroll-exam.php).
 * Nothing in the current UI calls this endpoint any more. Kept only as a
 * safe stub — in case a stale client still posts here — so it fails loudly
 * with a clear message instead of silently running checkout logic against
 * the legacy subject fee columns.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

header('Content-Type: application/json');

if (!Auth::isLoggedIn()) { echo json_encode(['error' => 'Not logged in']); exit; }

echo json_encode([
    'error' => 'Subject-level checkout has been retired. Please enroll from the exam catalogue (Browse & Enroll) instead.',
]);
