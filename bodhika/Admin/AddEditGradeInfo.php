<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');

$primkeyId=$_GET['InfoId'];
if($primkeyId=="")
	$primkeyId =0;
 $selectdata = "SELECT * FROM gradeinfo where GradeInfoId  =".$primkeyId;

 $strExeQueryGrade=mysql_query("SELECT * FROM gradeinfo where GradeInfoId  =".$primkeyId);
$rsGrade=mysql_fetch_array($strExeQueryGrade);

//echo $primkeyId;
//echo  $selectdata;
//exit;

				
if(isset($_POST['save']))
{
	echo "in save\n";
	$primkeyId=$_GET['InfoId'];
	echo $primkeyId."\n";
	$selectdata = "SELECT * FROM gradeinfo where GradeInfoId  =".$primkeyId;
	echo $selectdata;
	$qry2 = mysql_query($selectdata);
	 $n=mysql_num_rows($qry2);
	
	$GradeName=$_POST['txtGradeName'];
	$Active=$_POST['Active'];
	$GroupId=$_POST['txtGroupId'];

	//echo  $GradeName;
	//echo  $Active;
	//echo "GroupId: ".$GroupId;
	//exit;
	if($n>0)
	{

		
	       	   //$sql="UPDATE gradeinfo set  GradeName='$GradeName',GroupId='$GroupId',Active='$Active' where GradeInfoId='$_GET[InfoId]'";
			   $sql="UPDATE gradeinfo set  GradeName='$GradeName',GroupId='$GroupId',Active='$Active' where GradeInfoId =".$primkeyId;

			 	//echo  $sql;
	//exit;

$qry=mysql_query($sql,$conn) or die(mysql_error());

		$qry=mysql_query($qry,$conn);

		if(isset($qry))
		{
			echo 'Successfully Updated.<br>';
			header("Location:GradeInfo.php");
		}
		else 
		{
			echo "Not Saved";
		}
	
	} 
	else
	{			
		$query="insert into gradeinfo (GradeName,GroupId,Active) values ('$GradeName','$GroupId','$Active')";

		//echo $query;
		//exit;

		$qry=mysql_query($query,$conn);

		if(isset($qry))
		{
			echo 'Successfully Added.<br>';
			header("Location:GradeInfo.php");
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

<title>Add/Edit Grade Information</title>

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

if(document.frmAddEditGrade.txtGradeName.value == "")
       {
               alert("Please enter the Grade Name");
               document.frmAddEditGrade.txtGradeName.focus();
               return false;
       }

if(document.frmAddEditGrade.Active.value == "")
       {
               alert("Please enter the Active or Not?");
               document.frmAddEditGrade.Active.focus();
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
        		

					            		<form name="frmAddEditGrade" action="" method="post" onSubmit="return checkForm2();">

					
					<!-- form name="frmEditStudent" action="" method="post" onSubmit="return checkForm2();" -->
                	

                    	<table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#DDDDDD">

                    		<tr>
                            	<td class="tblhdr" colspan="4" height="24">
                                	Grade Information
								</td>
                            </tr>

							
                              <tr>

                            	<td class="tbldt" width="20%">

                                	Grade Name <span class="manda">*</span>                                </td>

                                <td class="tbldt"  width="20%">

				<input type="text" id="txtFstName" name="txtGradeName" size="30" tabindex="1" size="35" value="<?php echo $rsGrade['GradeName']?>"/> 

									<div class=error id=txtNameError>*</div>

									</td>

									<td class="tbldt">

                                	Group Name <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

       
									<!-- input type="text" id="txtAdmnGrade" name="txtAdmnGrade" tabindex="3"/ --> 
									
									<?php

									$GroupInfoId=$rsGrade['GroupId'];
									
									
			$query_disp="select GroupId,GroupName from groupinfo";
$result_disp = mysql_query($query_disp, $conn);
if($_GET['InfoId']>0)
{
?>
	<select id="txtGroupId" name="txtGroupId"  tabindex="8">
 <option value='select' selected='selected'>Select</option>
<?php
echo $GroupInfoId;
while($query_data = mysql_fetch_array($result_disp))
{
?>
<option value="<?php echo $query_data['GroupId']; ?>"<?php if ($query_data['GroupId']==$GroupInfoId) {?>selected<?php } ?>><?php echo $query_data['GroupName']; ?></option>
<?php }}
 else{
?>

	<select id="txtGroupId" name="txtGroupId"  tabindex="9">

						   <option value='0' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select GroupId,GroupName from groupinfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['GroupId'];?>"><?php echo $ResultEval['GroupName']?></option>";
						<?php }
 }
						?>
                        	</select>
						



							</tr>
							<tr>
					 
				                           	<td class="tbldt">

                                	Active <span class="manda">*</span>                                     </td>

                                <td class="tbldt" colspan="3">

                                	<select name="Active"  tabindex="2" id="Active" value="<?php echo $rsGrade['Active']?>" tabindex="3">
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