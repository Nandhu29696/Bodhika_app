<?php require_once('../Lib/Config.php');
include_once('Includes/Top.php');
//include_once('Includes/LeftNav.php');

 $role = $_SESSION['Role'];
// echo $role;

$pos = strpos($role,"TEACH");


?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
<title>Reports</title>
<link href="style.css" rel="stylesheet" type="text/css"/>

<style type="text/css">
<!--
.style1 {
	font-family: Arial, Helvetica, sans-serif;
	font-size: 12px;
	font-weight: bold;
}
-->
</style>
</head>

<body leftmargin="0" topmargin="0" marginwidth="0" marginheight="0" class="bdyfont">


<table width="1025" border="0" cellpadding="0" cellspacing="0" align="center">
  <tr>
    <td valign="top">
	<form name="f1" action="" method="post">
	
	<table border="0" cellpadding="0" cellspacing="1"  width="100%" bgcolor="#EEEEEE" align="center">
    <tr height="24">
        <td class="tblhdr" colspan="4">Reports</td> </tr>
   <tr height="24">
        <td class="tbldt" colspan="4">


<table border="0" cellpadding="0" cellspacing="1"  width="500"  align="center">
    

<tr height="24">
        <td class="" width="30">   
&nbsp;</td>
<td >
&nbsp;</td>
</tr>


<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
<a class="bodynav" href="active.php">User List</a><br/>
</td>
</tr>

<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
<a class="bodynav" href="Register.php">Registered Not Admitted Students List</a><br/>
</td>
</tr>

<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
 <a class="bodynav" href="AdmittedStudentList.php">Admitted Student's List</a><br/>
</td>
</tr>


<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
 <a class="bodynav" href="ResultSearch.php">Result</a><br/>
</td>
</tr>

<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
 <a class="bodynav" href="AttendenceSearch.php">Attendence</a><br/>
</td>
</tr>

<?php
 if($role=="Admin" or $role=="PRCIPAL")
{
	?>

<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
 <a class="bodynav" href="LoginTrack.php">Login Tracking</a><br/>
</td>
</tr>


    <tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
<a class="bodynav" href="ClassTeacherList.php">Teacher's List</a><br/>
</td>
</tr>

<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
 <a class="bodynav" href="FeeInformation.php">Fee Information</a><br/>
</td>
</tr>

<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
 <a class="bodynav" href="PendingPayments.php">Pending Term Payments</a><br/>
</td>
</tr>

<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
 <a class="bodynav" href="PendingOtherPayments.php">Pending Other Payments</a><br/>
</td>
</tr>


<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
 <a class="bodynav" href="NoticesSent.php">Notices Sent</a><br/>
</td>
</tr>

<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
 <a class="bodynav" href="RptFreeTeacher.php">Free Teachers</a><br/>
</td>
</tr>

<tr height="24">
        <td class="" width="30">   
<img src="../Images/arrowicon.gif" border="0">
</td>
<td >
 <a class="bodynav" href="TimeTableComplete.php">Complete Time Table </a><br/>
</td>
</tr>

<?php
}
	?>


                   </table>


 </td>
              </tr>
                   </table>
	</form>

</td>
</tr>
</table>

<?php 
include_once('Includes/Bottom.php');
?>
</body>
</html>
