<?php
/**
 * auth/change-password-forced.php — Mandatory password change.
 *
 * Reached automatically: Auth::requireLogin() redirects every protected
 * page here whenever $_SESSION['must_change_password'] is set (migration_v59
 * MustChangePassword flag), which Auth::resetPasswordToDefault() sets any
 * time an admin or Institute-Admin resets a student's password
 * (Admin/ResetStudentPassword.php, Admin/EditUser.php). The user cannot
 * reach any other page — including exam/search.php — until they set a new
 * password here.
 *
 * This page itself calls Auth::requireLogin() (a valid session is required
 * to change a password), but is explicitly exempted from the very gate it
 * exists to satisfy — see the basename check in Auth::requireLogin().
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('login.php');

// Nothing to do here if this account isn't flagged — send them on their way.
if (empty($_SESSION['must_change_password'])) {
    header('Location: ' . Auth::postLoginUrl());
    exit;
}

$error   = '';
$errNew  = false;
$errConf = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $newPwd  = (string)($_POST['txtNewPassword']     ?? '');
    $confirm = (string)($_POST['txtConfirmPassword'] ?? '');

    if ($newPwd === '') {
        $error  = 'Please enter a new password.';
        $errNew = true;
    } elseif (strlen($newPwd) < 8) {
        $error  = 'Password must be at least 8 characters.';
        $errNew = true;
    } elseif ($newPwd === DEFAULT_RESET_PASSWORD) {
        $error  = 'Please choose a password other than the temporary one you were given.';
        $errNew = true;
    } elseif ($newPwd !== $confirm) {
        $error   = 'Passwords do not match.';
        $errConf = true;
    } else {
        $hash = password_hash($newPwd, PASSWORD_DEFAULT);
        try {
            if (Database::hasColumn('logininfo', 'MustChangePassword')) {
                Database::execute(
                    "UPDATE logininfo SET Password = ?, MustChangePassword = 0 WHERE LoginInfoId = ?",
                    [$hash, Auth::currentLoginId()]
                );
            } else {
                Database::execute(
                    "UPDATE logininfo SET Password = ? WHERE LoginInfoId = ?",
                    [$hash, Auth::currentLoginId()]
                );
            }
            unset($_SESSION['must_change_password']);
            header('Location: ' . Auth::postLoginUrl() . '?pwd_changed=1');
            exit;
        } catch (Exception $e) {
            error_log('auth/change-password-forced.php: update failed: ' . $e->getMessage());
            $error = 'Could not update your password — please try again.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#312e81">
  <title>Change Password &mdash; <?php echo APP_NAME; ?></title>
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
    <div style="text-align:center;margin-bottom:16px;">
      <div style="font-size:2rem;margin-bottom:8px;">&#128274;</div>
      <h2 style="margin:0 0 4px;font-size:1.2rem;color:#312e81;">Set a New Password</h2>
      <p style="font-size:.85rem;color:var(--clr-text-muted);margin:4px 0 0;">
        Your password was reset by an administrator. Please choose a new
        password before continuing.
      </p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger">&#10006; <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" action="" autocomplete="off" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">

      <div class="form-group">
        <label for="txtNewPassword">New Password</label>
        <input type="password" id="txtNewPassword" name="txtNewPassword"
               class="form-control<?php echo $errNew ? ' is-invalid' : ''; ?>"
               required autofocus autocomplete="new-password">
        <small style="color:var(--clr-text-muted);">At least 8 characters.</small>
      </div>

      <div class="form-group">
        <label for="txtConfirmPassword">Confirm New Password</label>
        <input type="password" id="txtConfirmPassword" name="txtConfirmPassword"
               class="form-control<?php echo $errConf ? ' is-invalid' : ''; ?>"
               required autocomplete="new-password">
      </div>

      <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">
        Save New Password &amp; Continue
      </button>
    </form>

    <div style="text-align:center;margin-top:16px;font-size:.85rem;color:var(--clr-text-muted);">
      <a href="logout.php">Sign out instead</a>
    </div>
  </div>
</div>
<script src="../<?php echo asset_version('assets/password-toggle.js'); ?>"></script>
</body>
</html>
