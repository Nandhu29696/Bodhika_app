<?php
/**
 * exam/razorpay-order-exam.php
 * Creates a Razorpay order for an exam's own fee (migration_v51 — pricing is
 * exam-level: fee, discount %, and coupon all resolve per exam) and returns
 * the orderId as JSON. Mirrors the retired subject-level razorpay-order.php,
 * but keyed by ExamInfoId against exam_fee_payments.
 *
 * Called via fetch() from enroll-exam.php before opening the checkout modal.
 *
 * POST body (JSON): { examId, coupon, checkOnly }
 * Response: { orderId, amount, currency, key } or { error: "..." } or { free: true }
 *           or, when checkOnly=true: { final, discount } or { error: "..." }
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Enrollment.php';

header('Content-Type: application/json');

if (!Auth::isLoggedIn()) { echo json_encode(['error' => 'Not logged in']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST required']); exit; }

$body      = json_decode(file_get_contents('php://input'), true);
$examId    = (int)($body['examId'] ?? 0);
$coupon    = trim($body['coupon'] ?? '');
$checkOnly = !empty($body['checkOnly']);
$userId    = (int)Auth::currentUserId();

if ($examId <= 0) { echo json_encode(['error' => 'Invalid exam']); exit; }

$fee    = Enrollment::getExamFee($examId);
$result = Enrollment::resolveExamPrice($examId, $userId, $coupon);

if ($result['error'] !== '') {
    echo json_encode(['error' => $result['error']]);
    exit;
}

/* Coupon-check-only request (used while the student is typing a code, before
   they hit Pay) — just report the resolved price, don't create anything. */
if ($checkOnly) {
    echo json_encode([
        'final'    => $result['final'],
        'discount' => $result['discount'],
        'free'     => ($result['final'] <= 0),
    ]);
    exit;
}

$finalAmount = $result['final'];

/* Razorpay credentials from config */
$rzpKeyId     = defined('RZP_KEY_ID')     ? RZP_KEY_ID     : getenv('RZP_KEY_ID');
$rzpKeySecret = defined('RZP_KEY_SECRET') ? RZP_KEY_SECRET : getenv('RZP_KEY_SECRET');
$sslVerify    = defined('CURL_SSL_VERIFY') ? (bool)CURL_SSL_VERIFY : filter_var(getenv('CURL_SSL_VERIFY') ?: 'false', FILTER_VALIDATE_BOOLEAN);

if (!$rzpKeyId || !$rzpKeySecret) {
    echo json_encode(['error' => 'Payment gateway not configured. Contact administrator.']);
    exit;
}

/* Amount = 0 (free exam, 100% discount/coupon, or institute waiver) — mark
   directly and skip Razorpay */
if ($finalAmount <= 0) {
    try {
        Enrollment::createExamPending($userId, $examId, $fee, $coupon, $result['discount'], 0);
        Database::execute(
            "UPDATE exam_fee_payments
                SET PaymentStatus='Free', PaidAt=NOW(), StartDate=CURDATE()
              WHERE UserInfoId=? AND ExamInfoId=?",
            [$userId, $examId]);
        if ($coupon !== '') Enrollment::incrementCoupon($coupon);
        echo json_encode(['free' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Could not process free enrollment: ' . $e->getMessage()]);
    }
    exit;
}

/* Create Razorpay order — amount in paise (1 INR = 100 paise) */
$amountPaise = (int)round($finalAmount * 100);

$orderData = [
    'amount'          => $amountPaise,
    'currency'        => 'INR',
    'receipt'         => 'exam_' . $examId . '_uid_' . $userId . '_' . time(),
    'payment_capture' => 1,
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($orderData),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_USERPWD        => $rzpKeyId . ':' . $rzpKeySecret,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => $sslVerify,
    CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    $curlErr = curl_error($ch);
    echo json_encode(['error' => 'Payment gateway request failed: ' . $curlErr]);
    exit;
}

if ($httpCode !== 200) {
    $err = json_decode($response, true);
    echo json_encode(['error' => $err['error']['description'] ?? 'Payment gateway error. Try again.']);
    exit;
}

$order   = json_decode($response, true);
$orderId = $order['id'] ?? '';

if (!$orderId) {
    echo json_encode(['error' => 'Failed to create payment order.']);
    exit;
}

/* Save pending record with Razorpay order id */
try {
    Enrollment::createExamPending($userId, $examId, $fee, $coupon, $result['discount'], $finalAmount);
    Database::execute(
        "UPDATE exam_fee_payments SET RazorpayOrderId=? WHERE UserInfoId=? AND ExamInfoId=?",
        [$orderId, $userId, $examId]);
} catch (Exception $e) {
    /* Non-fatal — continue */
}

echo json_encode([
    'orderId'  => $orderId,
    'amount'   => $amountPaise,
    'currency' => 'INR',
    'key'      => $rzpKeyId,
]);
