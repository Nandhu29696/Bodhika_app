<?php
/**
 * auth/otp-verify.php — OTP Verification Step
 *
 * Shown after a successful password check when 2FA is enabled.
 * Reads $_SESSION['otp_pending'] set by Auth::login().
 * On correct code → Auth::completeOtpLogin() → dashboard.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/AppSettings.php';
require_once __DIR__ . '/../Lib/Otp.php';

/* Must have a pending OTP session to be here */
$pending = $_SESSION['otp_pending'] ?? null;
if (!$pending || time() > ($pending['expires'] ?? 0)) {
    unset($_SESSION['otp_pending']);
    header('Location: login.php?timeout=1');
    exit;
}

/* Already fully logged in → no reason to be here */
if (Auth::isLoggedIn()) {
    header('Location: ' . Auth::postLoginUrl());
    exit;
}

$error   = '';
$success = '';
$loginId = (int)$pending['login_id'];
$loginName = $pending['login_name'] ?? '';

/* ── Resend handler ────────────────────────────────────────────────────── */
if (isset($_GET['resend'])) {
    /* Look up email/phone fresh from DB */
    try {
        $urow = Database::fetchOne(
            "SELECT COALESCE(u.EMail,'') AS EMail, COALESCE(u.Mobile,'') AS Mobile
               FROM userinfo u
               JOIN logininfo l ON l.LoginName = u.LoginName
              WHERE l.LoginInfoId = ? LIMIT 1",
            [$loginId]
        );
        $email  = (string)($urow['EMail']  ?? '');
        $mobile = (string)($urow['Mobile'] ?? '');
    } catch (Exception $e) {
        $email = $mobile = '';
    }

    $result = Otp::dispatch($loginId, $loginName, $email, $mobile);
    if ($result['sent']) {
        $success = 'A new verification code has been sent.';
    } else {
        $error = 'Could not resend the code. Please try again or contact support.';
    }
}

/* ── Verify POST ───────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();

    /* Collect the 6 digit inputs and join */
    $digits = '';
    for ($i = 1; $i <= 6; $i++) {
        $digits .= preg_replace('/\D/', '', $_POST["d$i"] ?? '');
    }
    /* Also accept a single field fallback */
    if (strlen($digits) !== 6) {
        $digits = preg_replace('/\D/', '', $_POST['otp_code'] ?? '');
    }

    if (strlen($digits) !== 6) {
        $error = 'Please enter all 6 digits of the verification code.';
    } else {
        $result = Otp::verify($loginId, $digits);

        if ($result === true) {
            if (Auth::completeOtpLogin()) {
                header('Location: ' . Auth::postLoginUrl());
                exit;
            } else {
                $error = 'Session error. Please sign in again.';
                unset($_SESSION['otp_pending']);
            }
        } elseif ($result === 'expired' || $result === 'max_attempts') {
            $error = 'This code has expired or been used too many times. '
                   . '<a href="otp-verify.php?resend=1" style="color:inherit;font-weight:700;">Send a new code →</a>';
        } else {
            $error = 'Incorrect code. Please check and try again.';
        }
    }
}

/* ── Build hint messages ────────────────────────────────────────────────── */
$channelHints = [];
if (!empty($pending['email_masked'])) $channelHints[] = 'email <strong>' . htmlspecialchars($pending['email_masked']) . '</strong>';
if (!empty($pending['phone_masked'])) $channelHints[] = 'SMS to <strong>' . htmlspecialchars($pending['phone_masked']) . '</strong>';
$sentTo = $channelHints ? 'Sent to ' . implode(' and ', $channelHints) : 'Code sent';

$expiry   = max(1, (int)AppSettings::get('otp_expiry_minutes', '10'));
$appName  = defined('APP_NAME') ? APP_NAME : 'App';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#312e81">
  <title>Verify Code &mdash; <?= htmlspecialchars($appName) ?></title>
  <link rel="icon" type="image/svg+xml" href="../assets/logo.svg">
  <link rel="icon" type="image/png" href="../assets/logo.png">
  <link rel="apple-touch-icon" href="../assets/logo.png">
  <link rel="stylesheet" href="../<?= asset_version('assets/style.css') ?>">
  <style>
    .otp-input-row { display:flex;gap:10px;justify-content:center;margin:20px 0; }
    .otp-digit {
      width:52px;height:60px;border:2px solid var(--clr-border);border-radius:8px;
      font-size:1.6rem;font-weight:900;text-align:center;color:#312e81;
      background:#f8fafc;outline:none;transition:.15s;
      appearance:textfield;-moz-appearance:textfield;
    }
    .otp-digit::-webkit-inner-spin-button,
    .otp-digit::-webkit-outer-spin-button { -webkit-appearance:none; }
    .otp-digit:focus { border-color:#6366f1;background:#eef2ff;box-shadow:0 0 0 3px rgba(99,102,241,.18); }
    .otp-digit.filled { border-color:#059669;background:#ecfdf5; }
    .countdown { font-size:.82rem;color:var(--clr-text-muted);text-align:center;margin-top:8px; }
    .countdown.urgent { color:#dc2626;font-weight:700; }
  </style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-header">
    <div class="auth-logo">
      <img src="../assets/logo.png" alt="Logo"
           onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="auth-logo-placeholder" style="display:none;">YOUR<br>LOGO</div>
    </div>
    <h1><?= htmlspecialchars($appName) ?></h1>
  </div>

  <div class="auth-body">
    <div style="text-align:center;margin-bottom:16px;">
      <div style="font-size:2rem;margin-bottom:8px;">🔐</div>
      <h2 style="margin:0 0 4px;font-size:1.2rem;color:#312e81;">Two-Factor Verification</h2>
      <p style="font-size:.85rem;color:var(--clr-text-muted);margin:4px 0 0;"><?= $sentTo ?></p>
      <p style="font-size:.8rem;color:var(--clr-text-muted);margin:2px 0 0;">Valid for <?= $expiry ?> minutes</p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger" style="margin-bottom:12px;">&#10006; <?= $error ?></div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
      <div class="alert alert-success" style="margin-bottom:12px;">&#10003; <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="post" action="" id="otpForm" autocomplete="off" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">

      <!-- Individual digit boxes -->
      <div class="otp-input-row" id="otpBoxes">
        <?php for ($i = 1; $i <= 6; $i++): ?>
        <input type="number" min="0" max="9" maxlength="1"
               name="d<?= $i ?>" id="d<?= $i ?>"
               class="otp-digit" inputmode="numeric" pattern="\d">
        <?php endfor; ?>
      </div>
      <!-- Fallback single field (hidden, synced by JS) -->
      <input type="hidden" name="otp_code" id="otpHidden">

      <div class="countdown" id="countdownMsg"></div>

      <button type="submit" class="btn btn-primary btn-block" style="margin-top:16px;font-size:1rem;">
        Verify &amp; Sign In
      </button>
    </form>

    <div style="text-align:center;margin-top:14px;font-size:.83rem;">
      Didn't get the code?
      <a href="otp-verify.php?resend=1" style="color:var(--clr-primary);font-weight:600;">Resend</a>
      &nbsp;&bull;&nbsp;
      <a href="login.php" onclick="if(!confirm('Cancel sign-in and return to login?'))return false;">Cancel</a>
    </div>
  </div>
</div>

<script>
/* ── Digit-box UX ─────────────────────────────────────────────────────── */
(function () {
  var boxes   = Array.from(document.querySelectorAll('.otp-digit'));
  var hidden  = document.getElementById('otpHidden');
  var form    = document.getElementById('otpForm');

  function syncHidden() {
    hidden.value = boxes.map(b => b.value.replace(/\D/,'')).join('');
  }

  boxes.forEach(function (box, i) {
    /* On input: keep only one digit, move to next */
    box.addEventListener('input', function (e) {
      var v = this.value.replace(/\D/g,'');
      this.value = v.slice(-1);
      this.classList.toggle('filled', this.value !== '');
      syncHidden();
      if (this.value && i < boxes.length - 1) boxes[i+1].focus();
      /* Auto-submit when all filled */
      if (boxes.every(b => b.value !== '')) form.requestSubmit ? form.requestSubmit() : form.submit();
    });

    /* Backspace on empty → go to previous */
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !this.value && i > 0) boxes[i-1].focus();
    });

    /* Allow only digits */
    box.addEventListener('keypress', function (e) {
      if (!/\d/.test(e.key)) e.preventDefault();
    });

    /* Paste: spread digits across boxes */
    box.addEventListener('paste', function (e) {
      e.preventDefault();
      var text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
      for (var j = 0; j < text.length && (i+j) < boxes.length; j++) {
        boxes[i+j].value = text[j];
        boxes[i+j].classList.add('filled');
      }
      syncHidden();
      var nextEmpty = boxes.find(b => b.value === '');
      if (nextEmpty) nextEmpty.focus(); else boxes[boxes.length-1].focus();
      if (boxes.every(b => b.value !== '')) form.requestSubmit ? form.requestSubmit() : form.submit();
    });
  });

  /* Focus first empty box on load */
  var first = boxes.find(b => b.value === '');
  if (first) first.focus();
})();

/* ── Countdown timer ──────────────────────────────────────────────────── */
(function () {
  var el  = document.getElementById('countdownMsg');
  if (!el) return;
  var sec = <?= $expiry * 60 ?>;
  function tick() {
    var m = Math.floor(sec / 60), s = sec % 60;
    el.textContent = 'Code expires in ' + m + ':' + (s<10?'0':'') + s;
    el.className   = 'countdown' + (sec <= 60 ? ' urgent' : '');
    if (sec <= 0) { el.textContent = 'Code expired. Please resend.'; return; }
    sec--;
    setTimeout(tick, 1000);
  }
  tick();
})();
</script>
</body>
</html>
