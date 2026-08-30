<?php
/**
 * auth/force-password.php — Mandatory password change.
 *
 * Reached automatically (via Auth::requireLogin()'s forced-redirect) by any
 * account with logininfo.MustChangePassword = 'Y' — e.g. a student created
 * by Admin > Bulk Upload Students, whose temporary password is their own
 * mobile number. No "current password" field is required here: the user
 * already proved they know the temporary password by successfully logging
 * in to reach this page.
 *
 * On success: hashes the new password, clears MustChangePassword, clears
 * the session flag, and sends the user on to their normal landing page.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('login.php');

// Nothing to do here if this account was never flagged — send them onward
// rather than showing a pointless "change your password" screen.
if (empty($_SESSION['must_change_password'])) {
    header('Location: ../exam/search.php');
    exit;
}

$error   = '';
$loginId = Auth::currentLoginId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $new1 = $_POST['new_password']     ?? '';
    $new2 = $_POST['confirm_password'] ?? '';

    if (strlen($new1) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new1 !== $new2) {
        $error = 'New passwords do not match.';
    } else {
        $hash = password_hash($new1, PASSWORD_DEFAULT);
        try {
            Database::execute(
                "UPDATE logininfo SET Password = ?, MustChangePassword = 'N' WHERE LoginInfoId = ?",
                [$hash, $loginId]
            );
        } catch (Exception $e) {
            // MustChangePassword column missing (migration_v54 not run) —
            // still update the password so the user isn't stuck.
            Database::execute("UPDATE logininfo SET Password = ? WHERE LoginInfoId = ?", [$hash, $loginId]);
        }
        unset($_SESSION['must_change_password']);
        header('Location: ../exam/search.php');
        exit;
    }
}

$appName = defined('APP_NAME') ? APP_NAME : 'App';
$_cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#312e81">
  <title>Change Password &mdash; <?php echo htmlspecialchars($appName); ?></title>
  <link rel="icon" type="image/svg+xml" href="../assets/logo.svg">
  <link rel="icon" type="image/png" href="../assets/logo.png">
  <link rel="apple-touch-icon" href="../assets/logo.png">
  <link rel="stylesheet" href="../assets/style.css?v=<?php echo $_cssVer; ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-header">
    <div class="auth-logo">
      <img src="../assets/logo.png" alt="Logo"
           onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="auth-logo-placeholder" style="display:none;">YOUR<br>LOGO</div>
    </div>
    <h1><?php echo htmlspecialchars($appName); ?></h1>
  </div>

  <div class="auth-body">
    <div style="text-align:center;margin-bottom:16px;">
      <div style="font-size:2rem;margin-bottom:8px;">&#128273;</div>
      <h2 style="margin:0 0 4px;font-size:1.2rem;color:#312e81;">Set a New Password</h2>
      <p style="font-size:.85rem;color:var(--clr-text-muted);margin:4px 0 0;">
        You're signed in with a temporary password. Please choose your own before continuing.
      </p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger">&#10006; <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" action="" autocomplete="off" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">

      <div class="form-group">
        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password"
               class="form-control" required minlength="6" autocomplete="new-password" autofocus>
      </div>

      <div class="form-group">
        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password"
               class="form-control" required minlength="6" autocomplete="new-password">
      </div>

      <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">
        Set Password &amp; Continue
      </button>
    </form>

    <div style="text-align:center;margin-top:16px;font-size:.85rem;color:var(--clr-text-muted);">
      <a href="logout.php">Sign out instead</a>
    </div>
  </div>
</div>
</body>
</html>
