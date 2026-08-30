<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');

$primkeyId=$_GET['InfoId'];
if($primkeyId=="")
	$primkeyId =0;
 $selectdata = "SELECT * FROM msgtemplate where MsgtemplateId  =".$primkeyId;

 $strExeQueryGrade=mysql_query("SELECT * FROM msgtemplate where MsgtemplateId  =".$primkeyId);
$rsMessage=mysql_fetch_array($strExeQueryGrade);

//echo $primkeyId;
//echo  $selectdata;
//exit;

				
if(isset($_POST['save']))
{
	echo "in save\n";
	$primkeyId=$_GET['InfoId'];
	echo $primkeyId."\n";
	$selectdata = "SELECT * FROM msgtemplate where MsgtemplateId  =".$primkeyId;
	echo $selectdata;
	$qry2 = mysql_query($selectdata);
	 $n=mysql_num_rows($qry2);
	
	$TemplateName=$_POST['txtTemplateName'];
	$Active=$_POST['Active'];
	$Message=$_POST['txtMessage'];

	//echo  $Message;
	//echo  $Active;
	//echo "TemplateName: ".$TemplateName;
	//exit;
	if($n>0)
	{

		
	       	   $sql="UPDATE msgtemplate set  TemplateName='$TemplateName',Message='$Message',Active='$Active' where MsgtemplateId='$_GET[InfoId]'";
			  // 	echo  $sql;
	//exit;


$qry=mysql_query($sql,$conn) or die(mysql_error());

		$qry=mysql_query($qry,$conn);

		if(isset($qry))
		{
			echo 'Successfully Updated.<br>';
			header("Location:MsgTemplateInfo.php");
		}
		else 
		{
			echo "Not Saved";
		}
	
	} 
	else
	{			
		$query="insert into msgtemplate (TemplateName,Message,Active) values ('$TemplateName','$Message','$Active')";

		//echo $query;
		//exit;

		$qry=mysql_query($query,$conn);

		if(isset($qry))
		{
			echo 'Successfully Added.<br>';
			header("Location:MsgTemplateInfo.php");
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

<title>Add/Edit Message Template Information</title>

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

	if(document.frmAddEditMessage.txtTemplateName.value == "")
       {
               alert("Please enter the Message Template Name");
               document.frmAddEditMessage.txtTemplateName.focus();
               return false;
       }

if(document.frmAddEditMessage.txtMessage.value == "")
       {
               alert("Please enter the Message");
               document.frmAddEditMessage.txtMessage.focus();
               return false;
       }


if(document.frmAddEditMessage.Active.value == "")
       {
               alert("Please enter the Active or Not?");
               document.frmAddEditMessage.Active.focus();
               return false;
       }	   
return true;
}



</script>


</head>



<body leftmargin="0" topmargin="0" marginwidth="0" marginheight="0">

<table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">

	<tr>
			<td class="lakstoppad1" valign="top">
        		

					            		<form name="frmAddEditMessage" action="" method="post" onSubmit="return checkForm2();">

					
					<!-- form name="frmEditStudent" action="" method="post" onSubmit="return checkForm2();" -->
                	

                    	<table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#DDDDDD">

                    		<tr>
                            	<td class="tblhdr" colspan="4" height="24">
                                	Message Template Information
								</td>
                            </tr>

							
                              <tr>

                            	<td class="tbldt" width="20%">

                                	Message Template Name <span class="manda">*</span>                                </td>

                                <td class="tbldt"  width="20%">

				<input type="text" id="txtTemplateName" name="txtTemplateName" size="30" tabindex="1" size="35" value="<?php echo $rsMessage['TemplateName']?>"/> 

									<div class=error id=txtNameError>*</div>

									</td>

													 
				                           	<td class="tbldt">

                                	Active <span class="manda">*</span>                                     </td>

                                <td class="tbldt" >

                                	<select name="Active" id="Active"  tabindex="2" value="<?php echo $rsMessage['Active']?>">				
				<option value="Y">Yes</option>
				<option value="N">No</option>
				</select>                        

                            </tr>

  <tr>

                            	<td class="tbldt">

                                	Message                                </td>

                                <td class="tbldt" colspan="3">

                                	<textarea id="txtNote" name="txtMessage" tabindex="3" style="width:90%" rows="5"><?php echo $rsMessage['Message']?></textarea>                                </td>

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