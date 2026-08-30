<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
require_once('../Lib/Config.php');
//include_once('Includes/Top.php');

$role = $_SESSION['Role'];
$pos = strpos($role,"TEACH");
//echo $role;
if($role=="Admin" or $role=="PRCIPAL" or $pos>0)
{
include_once('Includes/Top.php');
}
else if($role=="STDNT")
include_once('Includes/StudentTop.php');

include_once("ps_pagination.php");

$msg="";

  	if(isset($_POST['btnChangePwd']))
	{
		if(($_POST['txtNewPwd']!='') && ($_POST['txtOldPwd']!='') && ($_POST['txtConfirmPwd']!=''))
		{
		$strExeQuery=mysql_query("select * from logininfo  WHERE LoginName='$_POST[txtUserName]'",$conn);
		$Result=mysql_fetch_array($strExeQuery);
		$pass=$Result['Password'];
		if($pass==$_POST['txtOldPwd'])
		{
		
		//print $Result[0];
			if($_POST['txtNewPwd']==$_POST['txtConfirmPwd'])
			{
			$strExeUpdate=mysql_query("UPDATE logininfo set Password='$_POST[txtNewPwd]' WHERE LoginName='$UserName' and Password='$_POST[txtOldPwd]'");
			$msg = "Password changed sucessfully.";
			}
			else
				$msg = "Confirm Password does not match.";
		}
		else
		$msg = "Old password incorrect.";
		}
		}
		?>

<title>HANSEL: Change Password</title>

<script type="text/javascript">
		function CompareValidationPwd()
		{
			if(document.getElementById("txtNewPwd").value!=document.getElementById("txtConfirmPwd").value)
			{
				document.getElementById("txtConfirmPwd").value="";
				document.getElementById("txtNewPwd").value="";
				document.getElementById("txtNewPwd").focus();
			}
		}
		function RequireFeilds()
		{
			if(document.getElementById("txtOldPwd").value.length==0)
				document.getElementById("txtOldPwd").focus();
			if(document.getElementById("txtNewPwd").value.length==0)
				document.getElementById("txtNewPwd").focus();
			if(document.getElementById("txtConfirmPwd").value.length==0)
				document.getElementById("txtConfirmPwd").focus();
		}	

</script>

<table border="0" cellpadding="0" cellspacing="0" width="1000" align="center">
<form name="frmChangePwd" method="post" action="ChangePwd.php">


	<tr>
		
		<td width="10">&nbsp;</td>
		<td  valign="top" class="tbldt">
				<br><br>
<table border="0" cellpadding="0" cellspacing="1" width="300" align="center" >
						 
						<tr><td >
				<fieldset>
					<table border="0" cellpadding="0" cellspacing="1" width="300" bgcolor="#EEEEEE">
						 
						<tr>
							<td class="tblhdr" colspan="2" height="24">
								Change Password
							</td>
						   
						</tr> 

       
    	<tr>
        	<td class="tbldt">
            	User Name
            </td>
            <td class="tbldt">
            	<input type="text" id="txtUserName" name="txtUserName" readonly="readonly" style="background-color:#CCCCCC" value="<?php echo $UserName?>" tabindex="1"/>
            </td>
        </tr>
        <tr>
            <td class="tbldt">
            	Old Password
            </td>
            <td class="tbldt">
            	<input type="password" id="txtOldPwd" name="txtOldPwd" tabindex="2"/>
            </td>
          </tr>
          <tr>
            <td class="tbldt">
            	New Password
            </td>
            <td class="tbldt">
            	<input type="password" id="txtNewPwd" name="txtNewPwd" tabindex="3"/>
            </td>
        </tr>
        <tr>
            <td class="tbldt">
            	Confirm Password
            </td>
            <td class="tbldt">
            	<input type="password" id="txtConfirmPwd" name="txtConfirmPwd" tabindex="4" onblur="CompareValidationPwd()"/>
            </td>
        </tr>
      
            <tr height="40">
            	<td class="tbldt" align="center" colspan="2">
                	<input type="submit" id="btnChangePwd" name="btnChangePwd" tabindex="5" style="background-image:url('../Images/btnSmall.gif');width:85px;height:26px;border:0" style="width:150px" value="Update" class="btnbg"/>

<input type="button" value="Back" name="Cancel" id="Cancel" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('Index.php');"/>
                </td>
            </tr>
     <tr align="center"> <font color="blue" > <b> <?php echo $msg; ?> </b></font> </tr>   
  
</table>

				
</fieldset>
   
        </td>
    </tr>

	
</table>
<br><br>
 </td>
    </tr>

	
</table>
	<tr><td colspan="5"><?php
  include_once('Includes/Bottom.php');
  ?></td></tr>
</form>



