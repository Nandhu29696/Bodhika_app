<?php
/**
 * auth/forgot-password.php  — Sends a one-time reset link.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Mailer.php';

$msg = ''; $isErr = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnSend'])) {
    Auth::validateCsrf();
    $uname = trim($_POST['txtUserName'] ?? '');
    $email = trim($_POST['txtEMail']    ?? '');

    if ($uname === '' || $email === '') {
        $msg = 'Please enter both your username and email address.'; $isErr = true;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please enter a valid email address.'; $isErr = true;
    } else {
        $row = Database::fetchOne(
            "SELECT LoginInfoId, LoginName FROM logininfo
              WHERE LoginName = ? AND (Email = ? OR EMail = ?) AND Active = 'Y' LIMIT 1",
            [$uname, $email, $email]);
        if ($row) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            Database::execute(
                "UPDATE logininfo SET reset_token = ?, reset_expires = ? WHERE LoginInfoId = ?",
                [$token, $expires, $row['LoginInfoId']]);
            $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST']
                . rtrim(dirname($_SERVER['REQUEST_URI']), '/')
                . '/reset-password.php?token=' . urlencode($token);
            $body = "Dear " . htmlspecialchars($row['LoginName']) . ",\r\n\r\n"
                  . "Click the link below to reset your password (valid 1 hour):\r\n"
                  . $resetLink . "\r\n\r\nIf you did not request this, ignore this email.\r\n";
            Mailer::sendPlainText($email, 'Password Reset - ' . APP_NAME, $body, $row['LoginName']);
        }
        $msg = 'If that account exists, a reset link has been sent to the email on file.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Forgot Password &mdash; Exam System</title>
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
    <h1>Recover Password</h1>
    <p>Enter your username and registered email</p>
  </div>
  <div class="auth-body">
    <?php if ($msg !== ''): ?>
      <div class="alert <?php echo $isErr ? 'alert-error' : 'alert-success'; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <div class="form-group">
        <label for="txtUserName">Username</label>
        <input type="text" id="txtUserName" name="txtUserName" class="form-control" required
               value="<?php echo htmlspecialchars($_POST['txtUserName'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label for="txtEMail">Email Address</label>
        <input type="email" id="txtEMail" name="txtEMail" class="form-control" required
               value="<?php echo htmlspecialchars($_POST['txtEMail'] ?? ''); ?>">
      </div>
      <button type="submit" name="btnSend" class="btn btn-primary" style="width:100%;margin-top:8px;">Send Reset Link</button>
    </form>
    <div style="text-align:center;margin-top:14px;font-size:.85rem;">
      <a href="login.php">&larr; Back to Login</a>
    </div>
  </div>
</div>
</body>
</html>
