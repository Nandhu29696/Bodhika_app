<?php
/**
 * exam/teacher-enroll.php
 *
 * Handles student enrollment in a teacher's course.
 * For free courses: enroll instantly (GET ?csid=N&free=1 or POST).
 * For paid courses: show fee summary + Razorpay checkout.
 *
 * Razorpay order creation: POST to teacher-enroll.php?action=create_order (JSON)
 * Razorpay verify:         POST to teacher-enroll.php?action=verify        (JSON)
 * All other requests:      show the enrollment UI page.
 *
 * This is a completely separate payment table (teacher_enrollments) — the
 * existing enrollment_payments table for exam access is NOT touched here.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$myUid   = Auth::currentUserId();
$action  = $_GET['action'] ?? '';

/* ── Helper: load course + teacher ────────────────────────────────────────── */
function loadCourse(int $csid): ?array {
    return Database::fetchOne(
        "SELECT ts.*, tp.TeacherId, tp.OffersOnline,
                u.FstName AS TFst, u.LstName AS TLst,
                COALESCE(s.SubjectName,'—') AS SubjectName
           FROM teacher_subjects ts
           JOIN teacher_profiles tp ON tp.TeacherId = ts.TeacherId
           JOIN userinfo u ON u.UserInfoId = tp.UserInfoId
      LEFT JOIN subjectinfo s ON s.SubjectInfoId = ts.SubjectInfoId
          WHERE ts.TeacherSubjectId = ? AND ts.Active = 'Y' AND tp.Active = 'Y'
          LIMIT 1", [$csid]) ?: null;
}

function getEnrollment(int $csid, int $uid): ?array {
    return Database::fetchOne(
        "SELECT * FROM teacher_enrollments
          WHERE TeacherSubjectId=? AND UserInfoId=? LIMIT 1",
        [$csid, $uid]) ?: null;
}

/* ── AJAX: Create Razorpay order ────────────────────────────────────────── */
if ($action === 'create_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body  = json_decode(file_get_contents('php://input'), true);
    $csid  = (int)($body['csid'] ?? 0);
    $token = $body['csrf_token'] ?? '';
    if (!$csid || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        echo json_encode(['error' => 'Invalid request.']); exit;
    }
    $course = loadCourse($csid);
    if (!$course || $course['IsFree']) { echo json_encode(['error' => 'Invalid course.']); exit; }

    $existing = getEnrollment($csid, $myUid);
    if ($existing && in_array($existing['PaymentStatus'], ['Paid','Free','Waived'], true)) {
        echo json_encode(['already_enrolled' => true]); exit;
    }

    $rzpKeyId     = defined('RZP_KEY_ID')     ? RZP_KEY_ID     : getenv('RZP_KEY_ID');
    $rzpKeySecret = defined('RZP_KEY_SECRET') ? RZP_KEY_SECRET : getenv('RZP_KEY_SECRET');
    if (!$rzpKeyId || !$rzpKeySecret) {
        echo json_encode(['error' => 'Payment gateway not configured.']); exit;
    }

    $amountPaise = (int)round((float)$course['CourseFee'] * 100);
    $orderData   = [
        'amount'   => $amountPaise, 'currency' => 'INR',
        'receipt'  => 'tcs_'.$csid.'_uid_'.$myUid.'_'.time(),
        'payment_capture' => 1,
    ];
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode($orderData),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => $rzpKeyId . ':' . $rzpKeySecret,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $err = json_decode($response, true);
        echo json_encode(['error' => $err['error']['description'] ?? 'Payment gateway error.']); exit;
    }
    $order   = json_decode($response, true);
    $orderId = $order['id'] ?? '';
    if (!$orderId) { echo json_encode(['error' => 'Could not create order.']); exit; }

    /* Upsert pending enrollment */
    Database::execute(
        "INSERT INTO teacher_enrollments (TeacherSubjectId,UserInfoId,PaymentStatus,AmountPaid,RazorpayOrderId)
         VALUES (?,?,'Pending',?,?)
         ON DUPLICATE KEY UPDATE PaymentStatus='Pending', RazorpayOrderId=VALUES(RazorpayOrderId), AmountPaid=VALUES(AmountPaid)",
        [$csid, $myUid, (float)$course['CourseFee'], $orderId]);

    echo json_encode(['orderId' => $orderId, 'amount' => $amountPaise, 'currency' => 'INR', 'key' => $rzpKeyId]);
    exit;
}

/* ── AJAX: Verify Razorpay payment ─────────────────────────────────────── */
if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body      = json_decode(file_get_contents('php://input'), true);
    $csid      = (int)($body['csid']                  ?? 0);
    $orderId   = trim($body['razorpay_order_id']       ?? '');
    $paymentId = trim($body['razorpay_payment_id']     ?? '');
    $signature = trim($body['razorpay_signature']      ?? '');
    $token     = $body['csrf_token'] ?? '';

    if (!$csid || !$orderId || !$paymentId || !$signature
        || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request.']); exit;
    }

    $rzpKeySecret = defined('RZP_KEY_SECRET') ? RZP_KEY_SECRET : getenv('RZP_KEY_SECRET');
    $expected     = hash_hmac('sha256', $orderId.'|'.$paymentId, $rzpKeySecret);
    if (!hash_equals($expected, $signature)) {
        error_log("teacher-enroll verify: signature mismatch uid=$myUid csid=$csid");
        echo json_encode(['ok' => false, 'error' => 'Payment verification failed.']); exit;
    }

    try {
        $updated = Database::execute(
            "UPDATE teacher_enrollments
                SET PaymentStatus='Paid', RazorpayPaymentId=?, RazorpaySignature=?, PaidAt=NOW()
              WHERE TeacherSubjectId=? AND UserInfoId=? AND RazorpayOrderId=?",
            [$paymentId, $signature, $csid, $myUid, $orderId]);

        if ($updated === 0) {
            /* Row might not exist — insert it */
            $course = loadCourse($csid);
            Database::execute(
                "INSERT INTO teacher_enrollments
                    (TeacherSubjectId,UserInfoId,PaymentStatus,AmountPaid,RazorpayOrderId,RazorpayPaymentId,RazorpaySignature,PaidAt)
                 VALUES (?,?,'Paid',?,?,?,?,NOW())",
                [$csid, $myUid, (float)($course['CourseFee'] ?? 0), $orderId, $paymentId, $signature]);
        }
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        error_log('teacher-enroll verify DB: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Could not save payment. Contact support.']);
    }
    exit;
}

/* ── GET: Free instant enrollment ─────────────────────────────────────────── */
$csid   = filter_input(INPUT_GET, 'csid', FILTER_VALIDATE_INT) ?: 0;
$isFreeGet = isset($_GET['free']);

if (!$csid) { header('Location: teacher-courses.php'); exit; }
$course = loadCourse($csid);
if (!$course) { header('Location: teacher-courses.php'); exit; }

$existing = getEnrollment($csid, $myUid);

/* Process free instant enrollment */
if ($isFreeGet && $course['IsFree']) {
    if (!$existing || !in_array($existing['PaymentStatus'], ['Paid','Free','Waived'], true)) {
        Database::execute(
            "INSERT INTO teacher_enrollments (TeacherSubjectId,UserInfoId,PaymentStatus,AmountPaid,PaidAt)
             VALUES (?,?,'Free',0,NOW())
             ON DUPLICATE KEY UPDATE PaymentStatus='Free', PaidAt=NOW()",
            [$csid, $myUid]);
    }
    header('Location: teacher-courses.php?enrolled=1'); exit;
}

/* Already enrolled → redirect */
if ($existing && in_array($existing['PaymentStatus'], ['Paid','Free','Waived'], true)) {
    header('Location: teacher-courses.php?enrolled=1'); exit;
}

/* Free course but arrived without ?free=1 — just enroll */
if ($course['IsFree']) {
    Database::execute(
        "INSERT INTO teacher_enrollments (TeacherSubjectId,UserInfoId,PaymentStatus,AmountPaid,PaidAt)
         VALUES (?,?,'Free',0,NOW())
         ON DUPLICATE KEY UPDATE PaymentStatus='Free', PaidAt=NOW()",
        [$csid, $myUid]);
    header('Location: teacher-courses.php?enrolled=1'); exit;
}

$rzpKeyId = defined('RZP_KEY_ID') ? RZP_KEY_ID : getenv('RZP_KEY_ID');

$pageTitle = 'Enroll: ' . $course['CourseName'];
include __DIR__ . '/../includes/header.php';
?>
<style>
.enroll-wrap{max-width:520px;margin:0 auto;padding:0 16px;}
.fee-box{background:#f0fdf4;border:2px solid #86efac;border-radius:8px;padding:20px 24px;margin-bottom:20px;}
.fee-row{display:flex;justify-content:space-between;align-items:baseline;padding:4px 0;font-size:.9rem;}
.fee-row.total{border-top:2px solid #86efac;margin-top:8px;padding-top:10px;font-size:1.1rem;font-weight:700;}
.btn-pay{width:100%;padding:14px;font-size:1rem;font-weight:700;background:#3b82f6;color:#fff;
         border:none;border-radius:6px;cursor:pointer;margin-top:8px;}
.btn-pay:disabled{background:#94a3b8;cursor:not-allowed;}
</style>

<div class="enroll-wrap">
  <a href="teacher-courses.php" style="color:#6b7280;font-size:.85rem;">← Back to Courses</a>

  <h2 style="margin:20px 0 4px;"><?php echo htmlspecialchars($course['CourseName']); ?></h2>
  <div style="color:#6b7280;font-size:.88rem;margin-bottom:20px;">
    By <?php echo htmlspecialchars(trim($course['TFst'].' '.$course['TLst'])); ?>
    &bull; <?php echo htmlspecialchars($course['SubjectName']); ?>
  </div>

  <div class="fee-box">
    <div class="fee-row"><span>Course Fee</span><span>₹<?php echo number_format((float)$course['CourseFee'],2); ?></span></div>
    <div class="fee-row total"><span>Amount Due</span><span>₹<?php echo number_format((float)$course['CourseFee'],2); ?></span></div>
  </div>

  <?php if (!$rzpKeyId): ?>
  <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:6px;">
    Payment gateway is not configured. Contact the administrator.
  </div>
  <?php else: ?>

  <div id="payStatus" style="display:none;padding:12px 16px;border-radius:6px;margin-bottom:12px;"></div>

  <button class="btn-pay" id="payBtn" onclick="startPayment()">
    &#128179; Pay ₹<?php echo number_format((float)$course['CourseFee'],2); ?> &amp; Enroll
  </button>

  <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
  <script>
  var CSRF  = '<?php echo Auth::csrfToken(); ?>';
  var CSID  = <?php echo (int)$csid; ?>;
  var RZP_KEY = '<?php echo htmlspecialchars($rzpKeyId); ?>';

  function showStatus(msg, ok) {
    var el = document.getElementById('payStatus');
    el.style.display = 'block';
    el.style.background = ok ? '#d1fae5' : '#fee2e2';
    el.style.color      = ok ? '#065f46' : '#991b1b';
    el.textContent = msg;
  }

  function startPayment() {
    var btn = document.getElementById('payBtn');
    btn.disabled = true; btn.textContent = 'Creating order…';

    fetch('teacher-enroll.php?action=create_order', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({csid: CSID, csrf_token: CSRF})
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (data.error) { showStatus(data.error, false); btn.disabled=false; btn.textContent='Retry'; return; }
      if (data.already_enrolled) { window.location.href='teacher-courses.php?enrolled=1'; return; }

      var options = {
        key:      data.key,
        amount:   data.amount,
        currency: data.currency,
        order_id: data.orderId,
        name:     '<?php echo addslashes(htmlspecialchars($course['CourseName'])); ?>',
        description: 'Teacher Course Enrollment',
        handler: function(resp) {
          btn.textContent = 'Verifying…';
          fetch('teacher-enroll.php?action=verify', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
              csid: CSID,
              razorpay_order_id:   resp.razorpay_order_id,
              razorpay_payment_id: resp.razorpay_payment_id,
              razorpay_signature:  resp.razorpay_signature,
              csrf_token: CSRF
            })
          })
          .then(function(r){ return r.json(); })
          .then(function(v) {
            if (v.ok) {
              showStatus('Payment successful! Redirecting…', true);
              setTimeout(function(){ window.location.href='teacher-courses.php?enrolled=1'; }, 1200);
            } else {
              showStatus(v.error || 'Verification failed. Contact support.', false);
              btn.disabled=false; btn.textContent='Retry';
            }
          });
        },
        prefill: { email: '<?php echo htmlspecialchars(Auth::currentUser()); ?>' },
        modal: { ondismiss: function(){ btn.disabled=false; btn.textContent='Pay & Enroll'; } }
      };
      (new Razorpay(options)).open();
    })
    .catch(function(){ showStatus('Network error. Please try again.', false); btn.disabled=false; });
  }
  </script>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
