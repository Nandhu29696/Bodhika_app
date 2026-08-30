<?php 
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/TopNew.php');

//$role = $_SESSION['Role'];
//echo $role;

//include_once('Includes/Top.php');
//else if($role=="STDNT")
//include_once('Includes/StudentTop.php');

 $LoginInfoId= $_SESSION['LoginInfoId'];
//echo "LoginInfoId--- ".$LoginInfoId;
	else
 $UserName = ucfirst(Auth::currentUser());
//echo  "UserName--- ".$UserName;

 if(isset($_POST['save']))
{
	 $LoginInfoId= $_SESSION['LoginInfoId'];

	//echo "in save ";
	
	$rolenm =$_POST['role'];
	$_SESSION['Role']=$rolenm;

$strExeQuery=mysql_query("select * from role a,userinfo b,logininfo c,userrole d where c.LoginInfoId=".$LoginInfoId." and a.RoleId=d.RoleId and b.LoginName =c.LoginName and b.UserInfoId= d.UserInfoId and a.RoleNm='".$rolenm."'",$conn);
$row=mysql_fetch_assoc($strExeQuery);		
 $_SESSION['UserInfoId'] =$row['UserInfoId'];

//$_SESSION['Role']=$row['Role'];
$_SESSION['LoginInfoId']=$loginid;


	echo "in save "."select * from role a,userinfo b,logininfo c where c.LoginInfoId=".$LoginInfoId." and a.RoleId=b.RoleId and b.LoginName =c.LoginName and a.RoleNm='".$rolenm."'";
	//exit;
	//header("Location:Admin/Index.php");
	header("Location:Index.php");
}


?>
<head>
<title>Welcome To <?php print $UserName;?> Home Page</title>
<script type="text/javascript">
	function checkForm2()
	{
		alert "in checkForm2";
			if ( ( frmselectRole.role[0].checked == false ) && ( frmselectRole.role[1].checked == false ) )) 
			{ 
					alert ( "Please Enter Role"); 
					return false; 
			}
	return true;
	}
</script>
<link rel="stylesheet" type="text/css" href="style.css">
</head>
<body leftmargin="0" topmargin="0" marginwidth="0" marginheight="0">
	
	
 <table width="1025" border="0" cellspacing="0" cellpadding="0" height="20%" align="center">
<form name="frmselectRole" id="frmselectRole" action="SelectRole.php" method="post" onSubmit="return checkForm2();" enctype="multipart/form-data">


			<tr>
<td class="tblhdr" height="22" colspan="2">

                                	Select Role                                    </td></tr>
<tr>
<td class="" height="20%" colspan="2">
&nbsp; </td></tr>


<tr height="20%">
	
<td class="tbldt" width="40%">&nbsp;</td>
<td class="tbldt" align="left">			
		<?php
$query_disp="select * from role";
$result_disp = mysql_query($query_disp, $conn);


?>


						<?php
							$strExeQueryEval=mysql_query("select * from role a,userinfo b,logininfo c,userrole d where c.LoginInfoId=".$LoginInfoId." and a.RoleId=d.RoleId and b.LoginName =c.LoginName and b.UserInfoId= d.UserInfoId");
							//echo "select * from role a,userinfo b,logininfo c where c.LoginInfoId=".$LoginInfoId." and a.RoleId=b.RoleId and b.LoginName =c.LoginName";
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?> 
							
							<input type="radio" name="role"  value="<?php echo $ResultEval['RoleNm']?>"><?php echo $ResultEval['RoleDesc']?> 
							<br>

						<?php }

						?>
                        	</select>							

									</td>
			</tr>
<tr>
			<td class="" colspan="2" align="right">
&nbsp;
</td></tr>


						<tr>
			<td height="22" class="" colspan="2" align="center">

								<input type="submit" value="Ok" name="save" id="save" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>


<input name="BtnCancel" type="button" id="Cancel" value="Back" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('Index.php');"/>
</td></tr>     


			<tr>
   				 <td bgcolor="#A89972" align="center" height="30" colspan="2"><font color="#000000" size="2" face="Arial, Helvetica, sans-serif">Copyright &copy; 2009 hansel., All Rights Reserved. 
<br/>
<small>For a better view use 1024 x 768 resolution</small>

</font></td>  			</tr>

		</table>



</form>
