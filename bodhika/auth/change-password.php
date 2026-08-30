<?php
/**
 * auth/change-password.php  — Change password for the currently logged-in user.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('login.php');

$pageTitle = 'Change Password';
$msg = ''; $isErr = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnSave'])) {
    Auth::validateCsrf();
    $oldPwd  = $_POST['txtOldPwd']     ?? '';
    $newPwd  = $_POST['txtNewPwd']     ?? '';
    $confirm = $_POST['txtConfirmPwd'] ?? '';

    if ($oldPwd === '' || $newPwd === '' || $confirm === '') {
        $msg = 'All fields are required.'; $isErr = true;
    } elseif (strlen($newPwd) < 8) {
        $msg = 'New password must be at least 8 characters.'; $isErr = true;
    } elseif ($newPwd !== $confirm) {
        $msg = 'New password and confirmation do not match.'; $isErr = true;
    } else {
        // Use LoginInfoId from session — no login-name DB lookup needed
        $row = Database::fetchOne(
            "SELECT LoginInfoId, Password FROM logininfo WHERE LoginInfoId = ? LIMIT 1",
            [Auth::currentLoginId()]);
        if (!$row) {
            $msg = 'Account not found.'; $isErr = true;
        } elseif (!password_verify($oldPwd, $row['Password'])
               && $oldPwd !== $row['Password']   // legacy plain-text fallback
               && md5($oldPwd) !== $row['Password']) {
            $msg = 'Current password is incorrect.'; $isErr = true;
        } else {
            $hash = password_hash($newPwd, PASSWORD_DEFAULT);
            Database::execute(
                "UPDATE logininfo SET Password = ? WHERE LoginInfoId = ?",
                [$hash, $row['LoginInfoId']]);
            $msg = 'Password changed successfully!'; $isErr = false;
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:500px;margin:0 auto;">
  <div class="card-header">&#128274; Change Password</div>
  <div class="card-body">
    <?php if ($msg !== ''): ?>
      <div class="alert <?php echo $isErr ? 'alert-error' : 'alert-success'; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <div class="form-group">
        <label for="txtOldPwd">Current Password</label>
        <input type="password" id="txtOldPwd" name="txtOldPwd" class="form-control" required>
      </div>
      <div class="form-group">
        <label for="txtNewPwd">New Password <small style="color:#718096">(min 8 characters)</small></label>
        <input type="password" id="txtNewPwd" name="txtNewPwd" class="form-control" required minlength="8"
               oninput="checkStrength(this.value)">
        <div id="strengthBar" class="answer-progress mt-1" style="display:none">
          <div id="strengthFill" class="answer-progress-bar" style="width:0;background:#e53e3e"></div>
        </div>
        <small id="strengthLabel" style="color:#718096"></small>
      </div>
      <div class="form-group">
        <label for="txtConfirmPwd">Confirm New Password</label>
        <input type="password" id="txtConfirmPwd" name="txtConfirmPwd" class="form-control" required
               oninput="checkMatch()">
        <small id="matchLabel" style="color:#e53e3e"></small>
      </div>
      <div class="flex gap-2 mt-2">
        <button type="submit" name="btnSave" class="btn btn-success">Save Password</button>
        <a href="../exam/search.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
<script>
function checkStrength(val) {
  var bar = document.getElementById('strengthBar');
  var fill = document.getElementById('strengthFill');
  var lbl  = document.getElementById('strengthLabel');
  bar.style.display = 'block';
  var score = 0;
  if (val.length >= 8)  score++;
  if (val.length >= 12) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  var colors = ['#e53e3e','#dd6b20','#d69e2e','#38a169','#276749'];
  var labels = ['Very Weak','Weak','Fair','Strong','Very Strong'];
  fill.style.width = (score * 20) + '%';
  fill.style.background = colors[score - 1] || '#e53e3e';
  lbl.textContent = labels[score - 1] || '';
  lbl.style.color = colors[score - 1] || '#e53e3e';
}
function checkMatch() {
  var n = document.getElementById('txtNewPwd').value;
  var c = document.getElementById('txtConfirmPwd').value;
  var lbl = document.getElementById('matchLabel');
  lbl.textContent = (c && n !== c) ? 'Passwords do not match' : '';
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
