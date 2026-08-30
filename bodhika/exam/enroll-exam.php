<?php
/**
 * exam/enroll-exam.php — Enrollment & payment page for a single exam.
 *
 * migration_v51: pricing is exam-level only — every exam has its own fee,
 * default discount %, and coupon eligibility (examinfo.ExamFee /
 * ExamDiscountPct + discount_coupons.ExamInfoId). This page replaces the
 * retired subject-level exam/enroll.php as the one and only checkout flow.
 *
 * Accessed via enroll-exam.php?examId=N from search.php. Shows fee (with
 * institute discount / exam default discount / coupon applied), then either:
 *   - Razorpay checkout (instant access on success), or
 *   - "Scan & Pay via UPI" (admin-configured QR + Transaction ID submission;
 *     stays Pending until an admin verifies it in Admin/EnrollmentPayments.php).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Enrollment.php';
require_once __DIR__ . '/../Lib/AppSettings.php';

Auth::requireLogin('../auth/login.php');

$examId = filter_input(INPUT_GET, 'examId', FILTER_VALIDATE_INT);
if (!$examId) { header('Location: search.php'); exit; }

$userId  = (int)Auth::currentUserId();
$isAdmin = Auth::isAdmin();

/* Exam-level pricing (migration_v51) may not be applied on every install yet
   — same resilience pattern as exam/browse-subjects.php: prefer the per-exam
   ExamFee/ExamDiscountPct once they exist, otherwise fall back to the older
   per-subject fee they superseded, rather than a hard SQL error either way. */
$hasExamPricing = Database::hasColumn('examinfo', 'ExamFee');
$feeSelect = $hasExamPricing
    ? 'e.ExamFee, e.ExamDiscountPct'
    : 'COALESCE(s.ExamFee, 0) AS ExamFee, COALESCE(s.DiscountPct, 0) AS ExamDiscountPct';
$exam = Database::fetchOne(
    "SELECT e.ExamInfoId, e.ExamName, $feeSelect, s.SubjectName
       FROM examinfo e
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
      WHERE e.ExamInfoId = ? LIMIT 1", [$examId]);
if (!$exam) { header('Location: search.php'); exit; }

/* IsQuestionBank guard (migration_v65) — direct-URL defense in depth: even
   though browse-subjects.php already excludes bank exams from the
   catalogue, this stops a guessed/old ?examId=N link from letting anyone
   pay for / enroll into a question pool. Falls open on a database that
   hasn't run migration_v65 yet. */
try {
    if (Database::hasColumn('examinfo', 'IsQuestionBank')) {
        $isBank = (Database::fetchOne(
            "SELECT IsQuestionBank FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]
        )['IsQuestionBank'] ?? 'N') === 'Y';
        if ($isBank) { header('Location: search.php'); exit; }
    }
} catch (Exception $e) { /* migration_v65 not yet run */ }

/* Already enrolled? */
$existing = Enrollment::getExamPayment($examId, $userId);
if ($existing && in_array($existing['PaymentStatus'], ['Paid', 'Waived', 'Free'], true)) {
    header('Location: search.php?enrolled=1');
    exit;
}

/* Manual UPI submission already on file, awaiting admin verification? */
$pendingTxn = ($existing && !empty($existing['TransactionId']) && $existing['PaymentStatus'] === 'Pending')
    ? $existing : null;

/* UPI / QR settings (migration_v34) */
AppSettings::loadAll();
$upiId       = AppSettings::get('payment_upi_id');
$upiPayee    = AppSettings::get('payment_upi_payee_name') ?: APP_NAME;
$qrImage     = AppSettings::get('payment_qr_image'); // admin-uploaded QR, optional
$useCustomQr = ($qrImage !== '');
$upiEnabled  = AppSettings::isEnabled('payment_upi_enabled') && ($upiId !== '' || $useCustomQr);

$examFee     = (float)($exam['ExamFee'] ?? 0);
$defaultDisc = (float)($exam['ExamDiscountPct'] ?? 0);

/* Initial price resolution — institute discount, if any, wins over the
   exam's own default discount %; a coupon (applied client-side below)
   can beat both once entered. */
$initial = Enrollment::resolveExamPrice($examId, $userId, '');
$defaultFinal = $initial['final'];
$initialSource = $initial['source']; // 'institute' | 'student_group' | 'exam_default' | 'none'

$pageTitle = 'Enroll: ' . htmlspecialchars($exam['ExamName']);
$rzpKeyId  = defined('RZP_KEY_ID') ? RZP_KEY_ID : getenv('RZP_KEY_ID');

/* ── Back navigation ───────────────────────────────────────────────────
   This page is reached from more than one place (browse-subjects.php's
   catalogue, search.php's "My Exams" list, and write.php redirecting here
   when an attempt needs payment first), so a single hardcoded "back"
   target can't be right for everyone. The linking page passes ?from=...
   so this page can send the student back to where they actually came
   from — e.g. Browse & Enroll — instead of always dropping them on My
   Exams, which was the only navigation option before (whitelisted
   against a fixed map, never trust $_GET into a redirect target). */
$backTargets = [
    'browse' => ['url' => 'browse-subjects.php', 'label' => 'Browse & Enroll'],
    'search' => ['url' => 'search.php',          'label' => 'My Exams'],
];
$backTarget = $backTargets[$_GET['from'] ?? ''] ?? $backTargets['search'];
$backUrl    = $backTarget['url'];
$backLabel  = $backTarget['label'];

include __DIR__ . '/../includes/header.php';
?>
<style>
  .enroll-wrap { max-width:520px;margin:0 auto;padding:0 16px; }
  .fee-box { background:#f0fdf4;border:2px solid #86efac;border-radius:8px;padding:20px 24px;margin-bottom:20px; }
  .fee-row { display:flex;justify-content:space-between;align-items:baseline;padding:4px 0;font-size:.9rem; }
  .fee-row.total { border-top:2px solid #86efac;margin-top:8px;padding-top:10px;font-size:1.1rem;font-weight:700; }
  .coupon-wrap { display:flex;gap:8px;margin-bottom:16px; }
  .coupon-status { font-size:.82rem;margin-top:4px;min-height:18px; }
  .btn-pay { width:100%;padding:14px;font-size:1rem;font-weight:700;background:#3b82f6;color:#fff;
             border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;
             justify-content:center;gap:8px; }
  .btn-pay:hover { background:#2563eb; }
  .btn-pay:disabled { background:#94a3b8;cursor:not-allowed; }
  .shield { font-size:.78rem;color:#64748b;text-align:center;margin-top:12px; }

  .pay-tabs { display:flex;gap:8px;margin-bottom:18px; }
  .pay-tab  { flex:1;padding:10px 8px;font-size:.85rem;font-weight:700;text-align:center;cursor:pointer;
              background:#f1f5f9;color:#475569;border:2px solid transparent;border-radius:8px; }
  .pay-tab.active { background:#eff6ff;color:#1d4ed8;border-color:#93c5fd; }
  .pay-panel { display:none; }
  .pay-panel.active { display:block; }

  .qr-box { text-align:center;margin:8px 0 18px; }
  .qr-box #qrcode { display:inline-block;padding:12px;background:#fff;border:1px solid #e2e8f0;border-radius:10px; }
  .upi-id-row { display:flex;align-items:center;justify-content:center;gap:8px;font-size:.88rem;margin-bottom:18px; }
  .upi-id-row strong { font-family:monospace;background:#f1f5f9;padding:3px 8px;border-radius:4px; }
  .txn-status { font-size:.82rem;margin-top:8px;min-height:18px; }

  .pending-banner { background:#fef9c3;border:1px solid #facc15;border-radius:8px;padding:12px 16px;
                     font-size:.85rem;color:#854d0e;margin-bottom:18px; }
</style>

<div class="enroll-wrap">
  <div style="margin-bottom:14px;">
    <a href="<?php echo htmlspecialchars($backUrl); ?>"
       style="display:inline-flex;align-items:center;gap:6px;color:#475569;font-size:.85rem;
              font-weight:600;text-decoration:none;">
      &#8592; Back to <?php echo htmlspecialchars($backLabel); ?>
    </a>
  </div>
  <h1 style="font-size:1.3rem;color:#1e3a5f;margin-bottom:4px;">&#127891; Enroll in Exam</h1>
  <p style="color:#64748b;font-size:.875rem;margin-bottom:20px;">
    <?php echo htmlspecialchars($exam['ExamName']); ?>
    <?php if (!empty($exam['SubjectName'])): ?>
      <br><span style="font-size:.8rem;">(<?php echo htmlspecialchars($exam['SubjectName']); ?>)</span>
    <?php endif; ?>
  </p>

  <?php if ($pendingTxn): ?>
  <div class="pending-banner">
    &#9203; <strong>Submitted &mdash; awaiting verification.</strong>
    Transaction ID <strong><?php echo htmlspecialchars($pendingTxn['TransactionId']); ?></strong> was
    submitted<?php echo !empty($pendingTxn['SubmittedAt']) ? ' on ' . date('d M Y, h:i A', strtotime($pendingTxn['SubmittedAt'])) : ''; ?>.
    An admin will verify it and activate exam access shortly. Submitted the wrong ID? You can resubmit below.
  </div>
  <?php endif; ?>

  <div class="fee-box">
    <div class="fee-row">
      <span>Exam fee</span>
      <span id="displayFee">&#8377;<?php echo number_format($examFee, 2); ?></span>
    </div>
    <?php if ($initialSource === 'institute'): ?>
    <div class="fee-row" style="color:#2563eb;">
      <span>Institute discount</span>
      <span id="displayDefaultDisc">&minus;&#8377;<?php echo number_format($examFee - $defaultFinal, 2); ?></span>
    </div>
    <?php elseif ($initialSource === 'student_group'): ?>
    <div class="fee-row" style="color:#7c3aed;">
      <span>Group discount</span>
      <span id="displayDefaultDisc">&minus;&#8377;<?php echo number_format($examFee - $defaultFinal, 2); ?></span>
    </div>
    <?php elseif ($initialSource === 'exam_default' && $defaultDisc > 0): ?>
    <div class="fee-row" style="color:#059669;">
      <span>Default discount (<?php echo $defaultDisc; ?>%)</span>
      <span id="displayDefaultDisc">&minus;&#8377;<?php echo number_format($examFee - $defaultFinal, 2); ?></span>
    </div>
    <?php endif; ?>
    <div class="fee-row" id="couponDiscRow" style="color:#7c3aed;display:none;">
      <span>Coupon discount (<span id="couponCodeLabel"></span>)</span>
      <span id="couponDiscAmt"></span>
    </div>
    <div class="fee-row total">
      <span>You pay</span>
      <span id="displayFinal">&#8377;<?php echo number_format($defaultFinal, 2); ?></span>
    </div>
  </div>

  <!-- Coupon code (applies to either payment method) -->
  <div>
    <label style="font-size:.85rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;">
      Coupon / Promo Code (optional)
    </label>
    <div class="coupon-wrap">
      <input type="text" id="couponInput" class="form-control" placeholder="e.g. EARLY20"
             style="text-transform:uppercase;" maxlength="50">
      <button type="button" class="btn btn-secondary" id="btnApplyCoupon">Apply</button>
    </div>
    <div class="coupon-status" id="couponStatus"></div>
  </div>

  <?php if ($upiEnabled): ?>
  <div class="pay-tabs">
    <div class="pay-tab active" id="tabRazorpay" onclick="switchPayTab('razorpay')">&#128179; Pay Online</div>
    <div class="pay-tab" id="tabUpi" onclick="switchPayTab('upi')">&#128241; Scan &amp; Pay (UPI)</div>
  </div>
  <?php endif; ?>

  <!-- ── Razorpay panel ──────────────────────────────────────────────────── -->
  <div class="pay-panel active" id="panelRazorpay">
    <button class="btn-pay" id="btnPay">
      <span>&#128274;</span>
      <span id="btnPayLabel">Pay &#8377;<?php echo number_format($defaultFinal, 2); ?></span>
    </button>
    <p class="shield">&#128274; Secured by Razorpay &nbsp;|&nbsp; 256-bit SSL encryption</p>
  </div>

  <?php if ($upiEnabled): ?>
  <!-- ── UPI / QR panel ──────────────────────────────────────────────────── -->
  <div class="pay-panel" id="panelUpi">
    <div class="qr-box">
      <?php if ($useCustomQr): ?>
        <img src="../Admin/<?php echo htmlspecialchars($qrImage); ?>" alt="Scan to pay"
             style="width:200px;height:200px;object-fit:contain;background:#fff;
                    border:1px solid #e2e8f0;border-radius:10px;padding:8px;">
      <?php else: ?>
        <div id="qrcode"></div>
      <?php endif; ?>
    </div>
    <?php if ($upiId !== ''): ?>
    <div class="upi-id-row">
      <span>UPI ID:</span> <strong id="upiIdText"><?php echo htmlspecialchars($upiId); ?></strong>
      <button type="button" class="btn btn-secondary" style="padding:2px 10px;font-size:.78rem;" onclick="copyUpiId(this)">Copy</button>
    </div>
    <?php endif; ?>

    <label style="font-size:.85rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;">
      Transaction / UTR ID
    </label>
    <input type="text" id="txnIdInput" class="form-control" maxlength="100"
           placeholder="From your UPI app's payment confirmation"
           value="<?php echo $pendingTxn ? htmlspecialchars($pendingTxn['TransactionId']) : ''; ?>">

    <button class="btn-pay" id="btnSubmitTxn" style="margin-top:14px;background:#059669;">
      <span>&#9989;</span>
      <span id="btnSubmitTxnLabel"><?php echo $pendingTxn ? 'Update Transaction ID' : 'Submit for Verification'; ?></span>
    </button>
    <div class="txn-status" id="txnStatus"></div>
    <p class="shield">
      <?php if ($useCustomQr): ?>
        Scan the QR in any UPI app, enter the amount shown above yourself, pay, then paste the
        Transaction/UTR ID here. Access is granted once an admin verifies the payment.
      <?php else: ?>
        Scan the QR in any UPI app, pay the amount shown above, then paste the
        Transaction/UTR ID here. Access is granted once an admin verifies the payment.
      <?php endif; ?>
    </p>
  </div>
  <?php endif; ?>

  <div style="text-align:center;margin-top:16px;">
    <a href="<?php echo htmlspecialchars($backUrl); ?>" style="color:#6b7280;font-size:.85rem;">
      &larr; Back to <?php echo htmlspecialchars($backLabel); ?>
    </a>
  </div>
</div>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php if ($upiEnabled && !$useCustomQr): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<?php endif; ?>
<script>
var EXAM_ID      = <?php echo $examId; ?>;
var USER_ID      = <?php echo $userId; ?>;
var BASE_FEE     = <?php echo $examFee; ?>;
var appliedCoupon   = '';
var currentFinal    = <?php echo $defaultFinal; ?>;
var currentDiscount = <?php echo ($examFee - $defaultFinal); ?>;

var RZP_KEY   = <?php echo json_encode($rzpKeyId ?: ''); ?>;
var UPI_ID    = <?php echo json_encode($upiId); ?>;
var UPI_PAYEE = <?php echo json_encode($upiPayee); ?>;
var EXAM_NAME = <?php echo json_encode($exam['ExamName']); ?>;
var qrInstance = null;

/* ── Coupon application ─────────────────────────────────────────────────── */
document.getElementById('btnApplyCoupon').addEventListener('click', function() {
  var code = document.getElementById('couponInput').value.toUpperCase().trim();
  var st   = document.getElementById('couponStatus');
  if (!code) { st.textContent = ''; return; }
  st.textContent = 'Checking…'; st.style.color = '#94a3b8';

  fetch('razorpay-order-exam.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ examId: EXAM_ID, coupon: code, checkOnly: true })
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if (d.error) {
      st.textContent = d.error; st.style.color = '#ef4444';
      return;
    }
    if (d.free) {
      st.textContent = '100% discount applied — free enrollment!'; st.style.color = '#059669';
      appliedCoupon = code;
      currentFinal = 0; currentDiscount = BASE_FEE;
      updateDisplay();
      return;
    }
    appliedCoupon   = code;
    currentFinal    = d.final;
    currentDiscount = d.discount;
    updateDisplay();
    st.textContent = 'Coupon applied: -₹' + d.discount.toFixed(2);
    st.style.color = '#7c3aed';
  })
  .catch(function(){ st.textContent = 'Network error. Try again.'; st.style.color='#ef4444'; });
});

function updateDisplay() {
  document.getElementById('displayFinal').textContent = '₹' + currentFinal.toFixed(2);
  document.getElementById('btnPayLabel').textContent  = 'Pay ₹' + currentFinal.toFixed(2);
  if (appliedCoupon && currentDiscount > 0) {
    document.getElementById('couponDiscRow').style.display = 'flex';
    document.getElementById('couponCodeLabel').textContent = appliedCoupon;
    document.getElementById('couponDiscAmt').textContent   = '-₹' + currentDiscount.toFixed(2);
  }
  refreshQr();
}

/* ── Pay tabs ────────────────────────────────────────────────────────────── */
function switchPayTab(which) {
  var isRzp = which === 'razorpay';
  document.getElementById('tabRazorpay').classList.toggle('active', isRzp);
  document.getElementById('tabUpi').classList.toggle('active', !isRzp);
  document.getElementById('panelRazorpay').classList.toggle('active', isRzp);
  document.getElementById('panelUpi').classList.toggle('active', !isRzp);
  if (!isRzp) refreshQr();
}

/* ── QR generation (UPI deep link) ──────────────────────────────────────── */
function refreshQr() {
  var el = document.getElementById('qrcode');
  if (!el || !UPI_ID) return;
  var amount = currentFinal.toFixed(2);
  var uri = 'upi://pay?pa=' + encodeURIComponent(UPI_ID) +
            '&pn=' + encodeURIComponent(UPI_PAYEE) +
            '&am=' + encodeURIComponent(amount) +
            '&cu=INR&tn=' + encodeURIComponent(EXAM_NAME);
  if (!qrInstance) {
    el.innerHTML = '';
    qrInstance = new QRCode(el, { text: uri, width: 180, height: 180 });
  } else {
    qrInstance.clear();
    qrInstance.makeCode(uri);
  }
}
function copyUpiId(btn) {
  navigator.clipboard.writeText(UPI_ID).then(function() {
    var orig = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(function(){ btn.textContent = orig; }, 1500);
  });
}

/* ── Submit Transaction ID for manual verification ──────────────────────── */
var txnBtn = document.getElementById('btnSubmitTxn');
if (txnBtn) {
  txnBtn.addEventListener('click', function() {
    var btn = this;
    var txnId = document.getElementById('txnIdInput').value.trim();
    var st    = document.getElementById('txnStatus');
    if (!txnId) { st.textContent = 'Please enter the Transaction / UTR ID.'; st.style.color = '#ef4444'; return; }

    btn.disabled = true;
    var origLabel = document.getElementById('btnSubmitTxnLabel').textContent;
    document.getElementById('btnSubmitTxnLabel').textContent = 'Submitting…';
    st.textContent = '';

    fetch('submit-manual-payment-exam.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ examId: EXAM_ID, coupon: appliedCoupon, transactionId: txnId })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.error) {
        st.textContent = d.error; st.style.color = '#ef4444';
        btn.disabled = false;
        document.getElementById('btnSubmitTxnLabel').textContent = origLabel;
        return;
      }
      st.textContent = '✓ Submitted! Awaiting admin verification — you\'ll get access once approved.';
      st.style.color = '#059669';
      document.getElementById('btnSubmitTxnLabel').textContent = 'Submitted';
    })
    .catch(function(){
      st.textContent = 'Network error. Please try again.'; st.style.color = '#ef4444';
      btn.disabled = false;
      document.getElementById('btnSubmitTxnLabel').textContent = origLabel;
    });
  });
}

/* ── Pay button (Razorpay) ──────────────────────────────────────────────── */
document.getElementById('btnPay').addEventListener('click', function() {
  var btn = this;
  btn.disabled = true;
  btn.innerHTML = '<span>&#9203;</span> <span>Creating order…</span>';

  fetch('razorpay-order-exam.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ examId: EXAM_ID, coupon: appliedCoupon })
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if (d.error) {
      alert(d.error);
      btn.disabled = false;
      btn.innerHTML = '<span>🔒</span><span>Pay ₹' + currentFinal.toFixed(2) + '</span>';
      return;
    }
    if (d.free) {
      window.location.href = 'search.php?enrolled=1';
      return;
    }
    /* Open Razorpay modal */
    var options = {
      key:         d.key,
      amount:      d.amount,
      currency:    d.currency,
      order_id:    d.orderId,
      name:        '<?php echo addslashes(APP_NAME); ?>',
      description: '<?php echo addslashes($exam['ExamName']); ?>',
      handler: function(resp) {
        /* Verify on server */
        fetch('razorpay-verify-exam.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            razorpay_order_id:   resp.razorpay_order_id,
            razorpay_payment_id: resp.razorpay_payment_id,
            razorpay_signature:  resp.razorpay_signature,
            examId:              EXAM_ID,
            coupon:              appliedCoupon
          })
        })
        .then(function(r){ return r.json(); })
        .then(function(v){
          if (v.ok) { window.location.href = 'search.php?enrolled=1'; }
          else { alert('Payment verified but could not save. Please contact support.\n' + (v.error||'')); }
        });
      },
      modal: {
        ondismiss: function() {
          btn.disabled = false;
          btn.innerHTML = '<span>🔒</span><span>Pay ₹' + currentFinal.toFixed(2) + '</span>';
        }
      },
      prefill: {
        email: '<?php echo addslashes(Auth::currentUser()["Email"] ?? ""); ?>'
      },
      theme: { color: '#3b82f6' }
    };
    var rzp = new Razorpay(options);
    rzp.open();
  })
  .catch(function(e){
    alert('Network error. Please try again.');
    btn.disabled = false;
    btn.innerHTML = '<span>🔒</span><span>Pay ₹' + currentFinal.toFixed(2) + '</span>';
  });
});

<?php if ($pendingTxn && $upiEnabled): ?>
switchPayTab('upi');
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
