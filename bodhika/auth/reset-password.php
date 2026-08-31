<?php
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

$error = '';
$token = trim((string)($_GET['token'] ?? ''));
$tokenValid = false;

if ($token !== '') {
    $tokenValid = Auth::validatePasswordResetToken($token) !== null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $postedToken = trim((string)($_POST['token'] ?? ''));
    $newPwd = (string)($_POST['txtNewPassword'] ?? '');
    $confirm = (string)($_POST['txtConfirmPassword'] ?? '');

    if ($postedToken === '') {
        $error = 'This password reset link is invalid or has expired.';
    } elseif (!Auth::completePasswordReset($postedToken, $newPwd, $confirm, $error)) {
        // error is set by Auth::completePasswordReset
    } else {
        header('Location: login.php?reset=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset Password &mdash; Exam System</title>
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
    <h1>Reset Password</h1>
    <p>Create a new password for your account</p>
  </div>

  <div class="auth-body">
    <?php if ($error !== ''): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!$tokenValid && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
      <div class="alert alert-warning">This password reset link is invalid or has expired. Please request a new one.</div>
      <div style="text-align:center;margin-top:14px;">
        <a href="forgot-password.php">Request a new reset link</a>
      </div>
    <?php else: ?>
      <form method="post" action="" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

        <div class="form-group">
          <label for="txtNewPassword">New Password</label>
          <input type="password" id="txtNewPassword" name="txtNewPassword" class="form-control" required autofocus autocomplete="new-password">
        </div>

        <div class="form-group">
          <label for="txtConfirmPassword">Confirm Password</label>
          <input type="password" id="txtConfirmPassword" name="txtConfirmPassword" class="form-control" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px;">Update Password</button>
      </form>

      <div style="text-align:center;margin-top:14px;font-size:.85rem;">
        <a href="login.php">&larr; Back to Login</a>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
