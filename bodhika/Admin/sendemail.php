<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');
	
?>

	<body>

	<table width="100%" height="400">
			<tr>
            	<td align="center">
                	<b>Email Sent Successfully. </b>
                </td>
            </tr>
        </table>			

</body>

 <?php
	//session_destroy();
      include_once('Includes/Bottom.php');
?>