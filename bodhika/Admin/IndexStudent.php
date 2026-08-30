<?php
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

// Auth guard runs BEFORE any HTML is output – this is the fix for the
// "headers already sent" warning caused by the old code that included
// TopStudent.php (which emits HTML) before the redirect check.
Auth::requireLogin('../index.php');

// Enforce student-only access
if (Auth::currentRole() !== 'STDNT') {
    header('Location: Index.php');
    exit;
}

$userName = Auth::currentUser();

// Now it is safe to output HTML
include_once 'Includes/TopStudent.php';
?>
<title>Welcome To <?php echo htmlspecialchars($userName); ?> Home Page</title>
<form name="frmCompanyHome">
  <fieldset>
    <legend>Welcome <b><?php echo htmlspecialchars($userName); ?></b> to Education Evaluation System</legend>
    <table width="1000" border="0" cellspacing="0" cellpadding="0" height="290" align="center">
      <tr>
        <td align="center"></td>
      </tr>
    </table>
  </fieldset>
</form>
<?php include_once 'Includes/Bottom.php'; ?>
