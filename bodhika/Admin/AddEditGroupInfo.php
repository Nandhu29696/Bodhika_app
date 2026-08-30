<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');

$primkeyId=$_GET['InfoId'];
if($primkeyId=="")
	$primkeyId =0;
 $selectdata = "SELECT * FROM groupInfo where GroupId =".$primkeyId;

 $strExeQueryGroup=mysql_query("SELECT * FROM groupInfo where GroupId =".$primkeyId);
$rsGroup=mysql_fetch_array($strExeQueryGroup);

//echo $primkeyId;
//echo  $selectdata;
//exit;

				
if(isset($_POST['save']))
{
	echo "in save\n";
	$primkeyId=$_GET['InfoId'];
	echo $primkeyId."\n";
	$selectdata = "SELECT * FROM groupInfo where GroupId =".$primkeyId;
	echo $selectdata;
	$qry2 = mysql_query($selectdata);
	 $n=mysql_num_rows($qry2);
	
	$GroupName=$_POST['txtGroupName'];
	$Active=$_POST['Active'];





	//echo  $GroupName;
	//echo  $Active;
	//exit;
	if($n>0)
	{
	       	   $sql="UPDATE groupInfo set  GroupName='$GroupName',Active='$Active' where GroupId='$_GET[InfoId]'";
			  // 	echo  $sql;
	//exit;

$qry=mysql_query($sql,$conn) or die(mysql_error());

		$qry=mysql_query($qry,$conn);

		if(isset($qry))
		{
			echo 'Successfully Updated.<br>';
			header("Location:GroupInfo.php");
		}
		else 
		{
			echo "Not Saved";
		}
	
	} 
	else
	{			
		$query="insert into groupInfo (GroupName,Active) values ('$GroupName','$Active')";

		//echo $query;
		//exit;

		$qry=mysql_query($query,$conn);

		if(isset($qry))
		{
			echo 'Successfully Added.<br>';
			header("Location:GroupInfo.php");
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

<title>Add/Edit Group Information</title>

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

if(document.frmAddEditGroup.txtGroupName.value == "")
       {
               alert("Please enter the Group Name");
               document.frmAddEditGroup.txtGroupName.focus();
               return false;
       }

if(document.frmAddEditGroup.Active.value == "")
       {
               alert("Please enter the Active or Not?");
               document.frmAddEditGroup.Active.focus();
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
        		

					            		<form name="frmAddEditGroup" action="" method="post" onSubmit="return checkForm2();">

					
					<!-- form name="frmEditStudent" action="" method="post" onSubmit="return checkForm2();" -->
                	

                    	<table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#DDDDDD">

                    		<tr>
                            	<td class="tblhdr" colspan="4" height="24">
                                	Group Information
								</td>
                            </tr>

							
                              <tr>

                            	<td class="tbldt" width="20%">

                                	Group Name <span class="manda">*</span>                                </td>

                                <td class="tbldt"  width="20%">

				<input type="text" id="txtFstName" name="txtGroupName" size="30" tabindex="4" size="35" value="<?php echo $rsGroup['GroupName']?>"/> 

									<div class=error id=txtNameError>*</div>

									</td>
					 
				                           	<td class="tbldt">

                                	Active <span class="manda">*</span>                                     </td>

                                <td class="tbldt" >

                                	<select name="Active" id="Active" value="<?php echo $rsGroup['Active']?>">
									<option value="Y">Yes</option>
				<option value="N">No</option>
				
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