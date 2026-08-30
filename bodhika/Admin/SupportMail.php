<?php
require_once('../Lib/Config.php');
require_once('calendar/classes/tc_calendar.php');

$role = $_SESSION['Role'];

if($role=="Admin" or $role=="PRCIPAL" or $role=="CLSTEACH")
{
include_once('Includes/Top.php');
}
else if($role=="STDNT")
{
include_once('Includes/StudentTop.php');
}

?>
<title>Issues / Suggestions Sent</title>


	<?php
		


 $to=$_POST['txtEMail'];
 $subject = "*** Hansel Issue Logged ***";

 $message = "<html><body><table  border='0' width='820px' cellspacing='0' cellpadding='0'>";
 $message.="<tr><td align='left' valign='top' style='padding-left:50px; padding-right:20px; font-family:Arial, Helvetica, sans-serif; font-size:12px'>";

   $message.= "<br/>";
 
$message.="<b>Student Name :</b>     ".$_POST['txtUserName'];
$message.="<br/>";
$message.="<b>Student Email :</b>   ".$_POST['txtStudName'];
$message.="<br/>";
$message.="<br/>";
$message.="<b>Issues / Suggestions :</b>     ".$_POST['txtDesc'].".";

 
$message.="<br/>";

  $message.="</td></tr>";
  $message.="</table></body></html>";

//echo "email ".$_POST['txtStudName'];


 $headers = 'From: hanselstudent@microsolutiononline.com'."\r\n";
$headers .= 'Cc: pravinsalunkhe@hotmail.com,hanselstudent@microsolutiononline.com'."\r\n";                    
$headers .= 'Bcc: shyam@microsolutiononline.com'."\r\n";
                    $headers .='Content-type: text/html; charset=iso-8859-1; format=flowed\n';
                    $headers .="MIME-Version: 1.0\n";
                    $headers .="Content-Transfer-Encoding: 8bit\n";
                    $headers .="X-Mailer: PHP\n";


//$message1="test message";

//	                mail($to, $subject, $message, $headers);

					if(mail($to, $subject, $message, $headers))
					   $msg = " Please check your email for the password.";
				//	else
				//		echo "$to. $subject. $message. $headers";

	?>


<br>
<br>
<center>Thank you for Logged Issue / Suggestion. Our Support Team will get back to you soon.</center>
