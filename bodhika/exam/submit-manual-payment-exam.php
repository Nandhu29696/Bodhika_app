<?php
/**
 * exam/submit-manual-payment-exam.php
 * Records a student's UPI/QR Transaction ID for an EXAM-LEVEL fee override
 * (migration_v50), for admin verification. Mirrors submit-manual-payment.php,
 * but against exam_fee_payments. Status always stays 'Pending' — an admin
 * must verify and flip it to 'Paid' before Enrollment::canAccess() grants
 * access to this exam.
 *
 * POST body (JSON): { examId, coupon, transactionId }
 * Response: { ok: true } or { error: "..." }
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Enrollment.php';
require_once __DIR__ . '/../Lib/AppSettings.php';

header('Content-Type: application/json');

if (!Auth::isLoggedIn()) { echo json_encode(['error' => 'Not logged in']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST required']); exit; }

if (!AppSettings::isEnabled('payment_upi_enabled')) {
    echo json_encode(['error' => 'Manual UPI payment is not enabled.']);
    exit;
}

$body          = json_decode(file_get_contents('php://input'), true);
$examId        = (int)($body['examId'] ?? 0);
$coupon        = trim($body['coupon'] ?? '');
$transactionId = trim($body['transactionId'] ?? '');
$userId        = (int)Auth::currentUserId();

if ($examId <= 0) { echo json_encode(['error' => 'Invalid exam']); exit; }

$result = Enrollment::submitManualExamPayment($userId, $examId, $transactionId, $coupon, 'UPI');

echo json_encode($result['ok'] ? ['ok' => true] : ['error' => $result['error']]);
