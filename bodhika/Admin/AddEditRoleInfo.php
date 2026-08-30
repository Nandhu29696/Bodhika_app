<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');

$primkeyId=$_GET['InfoId'];
if($primkeyId=="")
	$primkeyId =0;
 $selectdata = "SELECT * FROM role where RoleId =".$primkeyId;

 $strExeQueryRole=mysql_query("SELECT * FROM role where RoleId =".$primkeyId);
$rsRole=mysql_fetch_array($strExeQueryRole);

//echo $primkeyId;
//echo  $selectdata;
//exit;

				
if(isset($_POST['save']))
{
	echo "in save\n";
	$primkeyId=$_GET['InfoId'];
	echo $primkeyId."\n";
	$selectdata = "SELECT * FROM role where RoleId =".$primkeyId;
	echo $selectdata;
	$qry2 = mysql_query($selectdata);
	 $n=mysql_num_rows($qry2);
	
	$RoleName=$_POST['txtRoleNm'];
	$RoleDesc=$_POST['txtRoleDesc'];
	$Active=$_POST['Active'];

	//echo  $RoleName;
	//echo  $Active;
	//exit;
	if($n>0)
	{
	       	   $sql="UPDATE role set  RoleNm='$RoleName',RoleDesc='$RoleDesc',Active='$Active' where RoleId='$_GET[InfoId]'";
			  // 	echo  $sql;
	//exit;

$qry=mysql_query($sql,$conn) or die(mysql_error());

		$qry=mysql_query($qry,$conn);

		if(isset($qry))
		{
			echo 'Successfully Updated.<br>';
			header("Location:RoleInfo.php");
		}
		else 
		{
			echo "Not Saved";
		}
	
	} 
	else
	{			
		$query="insert into role (RoleNm,RoleDesc,Active) values ('$RoleName','$RoleDesc','$Active')";

		//echo $query;
		//exit;

		$qry=mysql_query($query,$conn);

		if(isset($qry))
		{
			echo 'Successfully Added.<br>';
			header("Location:RoleInfo.php");
		}
		else 
		{
			echo "Not Added";
		}
	}
}


?>
<html>
<head>

<title>Add/Edit Role Information</title>

<style>

.error {
font-family: Tahoma;
font-size: 8pt;
color: red;
display:none;
}
.manda{
font-family: Tahoma;
font-size: 8pt;
color: red;
}

</style>

<script type="text/javascript">
function checkForm2()
{

if(document.frmAddEditRole.txtRoleName.value == "")
       {
               alert("Please enter the Role Name");
               document.frmAddEditRole.txtRoleName.focus();
               return false;
       }

if(document.frmAddEditRole.Active.value == "")
       {
               alert("Please enter the Active or Not?");
               document.frmAddEditRole.Active.focus();
               return false;
       }	   
return true;
}
function echeck(str) {

		var at="@"
		var dot="."
		var lat=str.indexOf(at)
		var lstr=str.length
		var ldot=str.indexOf(dot)
		if (str.indexOf(at)==-1){
		   alert("Invalid E-mail ID")
		   return false
		}

		if (str.indexOf(at)==-1 || str.indexOf(at)==0 || str.indexOf(at)==lstr){
		   alert("Invalid E-mail ID")
		   return false
		}

		if (str.indexOf(dot)==-1 || str.indexOf(dot)==0 || str.indexOf(dot)==lstr){
		    alert("Invalid E-mail ID")
		    return false
		}

		 if (str.indexOf(at,(lat+1))!=-1){
		    alert("Invalid E-mail ID")
		    return false
		 }

		 if (str.substring(lat-1,lat)==dot || str.substring(lat+1,lat+2)==dot){
		    alert("Invalid E-mail ID")
		    return false
		 }

		 if (str.indexOf(dot,(lat+2))==-1){
		    alert("Invalid E-mail ID")
		    return false
		 }
		
		 if (str.indexOf(" ")!=-1){
		    alert("Invalid E-mail ID")
		    return false
		 }

 		 return true					
	}



</script>


</head>



<body leftmargin="0" topmargin="0" marginwidth="0" marginheight="0">

<table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">

	<tr>
			<td class="lakstoppad1" valign="top">
        		

					            		<form name="frmAddEditRole" action="" method="post" onSubmit="return checkForm2();">

					
					<!-- form name="frmEditStudent" action="" method="post" onSubmit="return checkForm2();" -->
                	

                    	<table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#DDDDDD">

                    		<tr>
                            	<td class="tblhdr" colspan="4" height="24">
                                	Role Information
								</td>
                            </tr>

							
                              <tr>

                            	<td class="tbldt" width="20%">

                                	Role Name <span class="manda">*</span>                                </td>

                                <td class="tbldt"  width="20%">

				<input type="text" id="txtFstName" name="txtRoleNm" size="30" tabindex="1" size="35" value="<?php echo $rsRole['RoleNm']?>"/> 

									<div class=error id=txtNameError>*</div>

									</td>

				                            	<td class="tbldt" width="20%">

                                	Role Description <span class="manda">*</span>                                </td>

                                <td class="tbldt"  width="20%">

				<input type="text" id="txtFstName" name="txtRoleDesc" size="30" tabindex="2" size="35" value="<?php echo $rsRole['RoleDesc']?>"/> 

									<div class=error id=txtNameError>*</div>

									</td>
</tr>

									<tr>
					 
				                           	<td class="tbldt" >

                                	Active <span class="manda">*</span>                                     </td>

                                <td class="tbldt" colspan="3">

                                	<select name="Active" id="Active" tabindex="3" value="<?php echo $rsRole['Active']?>">
				<option value="N">No</option>
				<option value="Y">Yes</option>
				</select>  
				
									 
				                           	
                            </tr>
                             <tr>

                             	
                                <td colspan="4" align="center" valign="baseline">

                                	
<input type="submit" value="Save" name="save" id="save" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>

<input type="button" value="Back" name="Back" id="Cancel" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="history.go(-1);"/>
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