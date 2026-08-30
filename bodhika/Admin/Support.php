<?php
require_once('../Lib/Config.php');
require_once('calendar/classes/tc_calendar.php');

$role = $_SESSION['Role'];

  require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

if($role=="Admin" or $role=="PRCIPAL" or $role=="CLSTEACH")
{
include_once('Includes/Top.php');
}
else if($role=="STDNT")
{
include_once('Includes/StudentTop.php');
}

$LoginInfoId=$_SESSION['LoginInfoId'];

$query="select * from studentinfo where LoginName ='".$UserName."'";
//echo $query;
$strExeQuery=mysql_query($query,$conn);
//echo $strExeQuery;
$row=mysql_fetch_array($strExeQuery);
//echo $row;
$StudentInfoId = $row['StudentInfoId'];
//echo $strExeQuery;
//exit;
$primkeyId = $StudentInfoId;

		$strExeQuery123=mysql_query("select * from studentinfo where StudentInfoId=".$primkeyId);
		$Row123=mysql_fetch_array($strExeQuery123);


?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
<title>Issues / Suggestions  Request Form</title>

<!--

<link href="style.css" rel="stylesheet" type="text/css"/>

<style type="text/css">
.style1 {
	font-family: Arial, Helvetica, sans-serif;
	font-size: 12px;
	font-weight: bold;
}
</style>
-->

</head>

<body leftmargin="0" topmargin="0" marginwidth="0" marginheight="0" class="bdyfont">

<script type="text/javascript">
function checkForm2()
{

	if(document.frmSupport.txtStudName.value == "")
       {
               alert("Please enter the Email Address");
               document.frmSupport.txtStudName.focus();
               return false;
       }
if(document.frmSupport.txtDesc.value == "")
       {
               alert("Please enter Issue/ Suggestion Description");
               document.frmSupport.txtDesc.focus();
               return false;
       }


	return true					
}


</script>




<table border="0" cellpadding="0" cellspacing="0" width="1025"  height="100%" align="center">
<form name="frmSupport" onSubmit="return checkForm2();"  action="SupportMail.php" method="post">


<tr bgcolor="#EEEEEE">
<td colspan="2" valign="top">



	
    	<table width="100%" height="330px">

			<tr>




            	<td align="center" bgcolor="#EEEEEE"  valign="top">
                	<table cellspacing="1" width="100%">
                    	
<tr>
          
          <td align="left" height="22" colspan="2" class="tblhdr">Issues / Suggestions Request Form</td>
                        </tr>

  <tr height="35" >
<td class="tbldt"  align="left" width="20%"> User Name </td>
<td class="tbldt" align="left"><input type="text" id="txtUserName" name="txtUserName" value="<?php echo $Row123['StudentFstNm']?>"/>
</td>
</tr>

<tr height="35" >
<td class="tbldt"  align="left">  Email Address</td>
<td class="tbldt" align="left"><input type="text" id="txtStudName"  name="txtStudName" size="50" value="<?php echo $Row123['EMail']?>"/>
</td>
</tr>

<tr height="35" >
<td class="tbldt"  align="left">Issues / Suggestions </td>
<td class="tbldt" align="left"><textarea id="txtDesc" name="txtDesc" rows="6" style="height:350;width:80%" /></textarea>
</td>
</tr>



                    	
<input type="hidden" name="txtEMail" size="50" value="shyam_my@hotmail.com"/>


                        <tr height="35" >
                        	
                            <td align="center" colspan="2">
                            	<input type="submit" name="btnSend" value="Submit" tabindex="2" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>
	   <input type="reset" value="Cancel" name="btnCancel" tabindex="3"  style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" />
                            </td>
                        </tr>
                      </table>
                                    </td>
            </tr>
    	</table>
    </form>




</td></tr>


</table>








<?php 
include_once('Includes/Bottom.php');
?>
</body>
</html>
