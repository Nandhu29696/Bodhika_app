<?php
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

// Auth check BEFORE any HTML output
Auth::requireLogin('../index.php');

$role     = Auth::currentRole();
$userName = Auth::currentUser();

// Route students away
if ($role === 'STDNT') {
    header('Location: IndexStudent.php');
    exit;
}

// Route teachers to their own dashboard (they must not see the admin panel)
if (Auth::isTeacher()) {
    header('Location: ../exam/my-students.php');
    exit;
}

// Safe to output HTML now
if ($role === 'Admin' || $role === 'PRCIPAL' || (strpos($role, 'TEACH') !== false)) {
    include_once 'Includes/Top.php';
} else {
    include_once 'Includes/Top.php';  // fallback: show generic nav
}
?>
<title>Welcome To <?php echo htmlspecialchars($userName); ?> Home Page</title>
<form name="frmHomeAdmin">
  <table width="1025" border="0" cellspacing="0" cellpadding="0" height="290" align="center">
    <tr>
      <td align="center" valign="top"><img src="../Images/MainBg.gif" alt=""></td>
    </tr>
    <tr>
      <td align="center" valign="top" bgcolor="#A89972">
        <?php include_once 'Includes/Bottom.php'; ?>
      </td>
    </tr>
  </table>
</form>
