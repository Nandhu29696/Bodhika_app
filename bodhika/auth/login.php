<?php
/**
 * auth/login.php — Application entry point / login form.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
/* OTP support (graceful — if tables not yet created, login works without 2FA) */
if (file_exists(__DIR__ . '/../Lib/AppSettings.php')) require_once __DIR__ . '/../Lib/AppSettings.php';
if (file_exists(__DIR__ . '/../Lib/Otp.php'))         require_once __DIR__ . '/../Lib/Otp.php';

// Already logged in → go to the right landing page for this role
if (Auth::isLoggedIn()) {
    header('Location: ' . Auth::postLoginUrl());
    exit;
}

$error    = '';
$warning  = '';
$success  = '';
$errUser  = false;   // true when the username field needs highlighting
$errPass  = false;   // true when the password field needs highlighting

// Status messages from redirects
if (isset($_GET['timeout'])) {
    $warning = 'Your session expired after 15 minutes of inactivity. Please sign in again.';
} elseif (isset($_GET['kicked'])) {
    $warning = 'You were signed out because your account was accessed from another location.';
} elseif (isset($_GET['reset'])) {
    $success = 'Your password has been reset successfully. Please sign in with your new password.';
}

// Flash message from register.php redirect
if (!empty($_SESSION['reg_success'])) {
    $success = $_SESSION['reg_success'];
    unset($_SESSION['reg_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $user = trim($_POST['txtUserName'] ?? '');
    $pass = $_POST['txtPassword']     ?? '';

    if ($user === '' && $pass === '') {
        $error   = 'Please enter your username and password.';
        $errUser = true;
        $errPass = true;
    } elseif ($user === '') {
        $error   = 'Please enter your username.';
        $errUser = true;
    } elseif ($pass === '') {
        $error   = 'Please enter your password.';
        $errPass = true;
    } elseif (!Auth::login($user, $pass, $error)) {
        // $error is filled by Auth::login (lockout or bad credentials)
    } elseif (!empty($_SESSION['otp_pending'])) {
        /* OTP required — redirect to verification page */
        header('Location: otp-verify.php');
        exit;
    } else {
        // Land on the right dashboard for this role. If a password reset
        // flagged this account (MustChangePassword), the very next
        // Auth::requireLogin() call — which every protected page runs —
        // bounces here to auth/change-password-forced.php automatically.
        header('Location: ' . Auth::postLoginUrl());
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#312e81">
  <title>Login &mdash; <?php echo APP_NAME; ?></title>
  <link rel="icon" type="image/svg+xml" href="../assets/logo.svg">
  <link rel="icon" type="image/png" href="../assets/logo.png">
  <link rel="apple-touch-icon" href="../assets/logo.png">
  <link rel="stylesheet" href="../<?php echo asset_version('assets/style.css'); ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-header">
    <div class="auth-logo">
      <img src="../assets/logo.png" alt="Logo"
           onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="auth-logo-placeholder" style="display:none;">YOUR<br>LOGO</div>
    </div>
    <h1><?php echo APP_NAME; ?></h1>
  </div>

  <div class="auth-body">
    <?php if ($success !== ''): ?>
      <div class="alert alert-success">&#10003; <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($warning !== ''): ?>
      <div class="alert alert-warning">&#9888; <?php echo htmlspecialchars($warning); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="alert alert-danger">&#10006; <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" action="" autocomplete="off" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">

      <div class="form-group">
        <label for="txtUserName">Username</label>
        <input type="text" id="txtUserName" name="txtUserName"
               class="form-control<?php echo $errUser ? ' is-invalid' : ''; ?>"
               required autofocus autocomplete="username"
               value="<?php echo htmlspecialchars($_POST['txtUserName'] ?? ''); ?>">
      </div>

      <div class="form-group">
        <label for="txtPassword">Password</label>
        <input type="password" id="txtPassword" name="txtPassword"
               class="form-control<?php echo $errPass ? ' is-invalid' : ''; ?>"
               required autocomplete="current-password">
      </div>

      <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">
        Sign In
      </button>
    </form>

    <div style="text-align:center;margin-top:16px;font-size:.85rem;color:var(--clr-text-muted);">
      <a href="forgot-password.php">Forgot your password?</a>
      &nbsp;&bull;&nbsp;
      <a href="register.php">Create a free account</a>
    </div>
  </div>
</div>

<script src="../<?php echo asset_version('assets/password-toggle.js'); ?>"></script>
</body>
</html>
