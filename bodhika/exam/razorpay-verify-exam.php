<?php
/**
 * exam/razorpay-verify-exam.php
 * Verifies a Razorpay payment signature for an EXAM-LEVEL fee override
 * (migration_v50) and marks the exam_fee_payments row as Paid. Mirrors
 * razorpay-verify.php, but keyed by ExamInfoId instead of SubjectInfoId.
 *
 * POST body (JSON): { razorpay_order_id, razorpay_payment_id, razorpay_signature, examId, coupon }
 * Response: { ok: true } or { ok: false, error: "..." }
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Enrollment.php';

header('Content-Type: application/json');

if (!Auth::isLoggedIn()) { echo json_encode(['ok' => false, 'error' => 'Not logged in']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); exit; }

$body      = json_decode(file_get_contents('php://input'), true);
$orderId   = trim($body['razorpay_order_id']   ?? '');
$paymentId = trim($body['razorpay_payment_id'] ?? '');
$signature = trim($body['razorpay_signature']  ?? '');
$examId    = (int)($body['examId'] ?? 0);
$coupon    = strtoupper(trim($body['coupon'] ?? ''));
$userId    = (int)Auth::currentUserId();

if (!$orderId || !$paymentId || !$signature || $examId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing payment data.']);
    exit;
}

/* Verify signature: HMAC-SHA256 of "orderId|paymentId" using key secret */
$rzpKeySecret = defined('RZP_KEY_SECRET') ? RZP_KEY_SECRET : getenv('RZP_KEY_SECRET');
$expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $rzpKeySecret);

if (!hash_equals($expected, $signature)) {
    error_log("Razorpay signature mismatch (exam fee) for user $userId examId $examId");
    echo json_encode(['ok' => false, 'error' => 'Payment verification failed. Contact support.']);
    exit;
}

/* Mark as Paid */
try {
    $updated = Database::execute(
        "UPDATE exam_fee_payments
            SET PaymentStatus     = 'Paid',
                RazorpayPaymentId = ?,
                RazorpaySignature = ?,
                PaidAt            = NOW(),
                StartDate         = COALESCE(StartDate, CURDATE())
          WHERE UserInfoId = ? AND ExamInfoId = ? AND RazorpayOrderId = ?",
        [$paymentId, $signature, $userId, $examId, $orderId]);

    if ($updated === 0) {
        /* Row may not exist yet — insert it */
        Database::execute(
            "INSERT INTO exam_fee_payments
                (ExamInfoId, UserInfoId, FeeAtTime, PaymentStatus, RazorpayOrderId, RazorpayPaymentId,
                 RazorpaySignature, PaidAt, StartDate)
             SELECT ?, ?, COALESCE(ExamFee,0), 'Paid', ?, ?, ?, NOW(), CURDATE()
               FROM examinfo WHERE ExamInfoId = ? LIMIT 1",
            [$examId, $userId, $orderId, $paymentId, $signature, $examId]);
    }

    if ($coupon !== '') Enrollment::incrementCoupon($coupon);

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    error_log('razorpay-verify-exam.php DB error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Could not save payment record. Contact support.']);
}
