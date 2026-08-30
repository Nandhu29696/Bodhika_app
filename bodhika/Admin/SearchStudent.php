<?php
require_once('../Lib/Config.php');
include_once('Includes/TopConn.php');
//include_once('Includes/LeftNav.php');
  require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
 
 include_once("ps_pagination.php");
 ?>
<html>
<title>User's List</title>
		<!-- link href="../CSS/GridStyle.css" rel="stylesheet" type="text/css"/ -->

<body topmargin="0" bottommargin="0" leftmargin="0" rightmargin="0" class="bdyfont">
		<table width="800" border="0" cellpadding="0" cellspacing="0" >
  <tr>
    <td valign="top">
	<form name="f1" action="" method="post">
	
	<table border="0" cellpadding="2" cellspacing="1"  width="800" bgcolor="#DDDDDD">
    <tr height="24">
        <td class="tblhdr" colspan="4">Search</td> </tr>

      <tr height="24">
        <td class="tbldt" width="20%">First Name</td>
        <td class="tbldt" width="30%"><input name="fname" type="text" id="name" /></td>
        <td class="tbldt" width="20%">Active</td>
        <td class="tbldt"  width="30%"><select name="active" id="active">
				<option value="Y">Yes</option>
				<option value="N">No</option>
          </select>
</td>
      </tr>

	  <tr height="24">
        <td class="tbldt" > Last Name</td>
        <td class="tbldt" ><input name="lname" type="text" id="name" /></td>
     
<td class="tbldt" >
                                	Role <span class="manda"></span>                                    </td>
    <td class="tbldt">
	
	<select id='role' name='role' >
						   <option value='Student' selected='selected'>Student</option>
						<?php
							$strExeQueryEval=mysql_query("select RoleNm ,RoleDesc  from role where RoleId =7");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<!--option value="<?php echo $ResultEval['RoleDesc'];?>"><?php echo $ResultEval['RoleDesc']?></option-->";
						<?php }
						?>
                        	</select>
</td>
      </tr>

  <tr height="24">
     <td colspan="4" align="center"><input name="Search" type="submit" id="Search" value="Search" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>

<input type="button" value="Close" name="Cancel" id="Cancel" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="window.close();"/>

</td>
      </tr>

         </table>
</form>


	<table border="0" cellpadding="0" cellspacing="0" width="800" align="center">

		  <?php 

	 // $strExeQuery="select * from LOGININFO where NOT Role ='Admin' order by LoginInfoId asc";
  		 // $strExeQuery="select * from LOGININFO where Role ='Admin' order by LoginInfoId asc";


		  if(isset($_POST['Search']))
		  {

				if(isset($_POST['fname']))
				  {
			  $active=$_POST['active'];	
	$F=$_POST['fname'];
	$L=$_POST['lname'];
	$role=$_POST['role'];
	if($role=="select")
		$role="";


//echo $role;

					if($role=="Student")
					  {
						$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c,sectioninfo d  where a.StudentInfoId=b.StudentInfoId and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y' and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId  order by a.StudentFstNm";
					  }
					  else
				  $strExeQuery="SELECT * FROM userinfo a,logininfo b,role c,userrole d WHERE d.Active='$active' and a.LoginName=b.LoginName and a.FstName LIKE '%$F%' and a.LstName LIKE '%$L%' and c.RoleDesc like'%$role%' and c.RoleId =d.RoleId and a.UserInfoId =d.UserInfoId ";
				  // $sql=mysql_query("SELECT * FROM t_Userinfo WHERE UserName LIKE CONVERT( _utf8 '%$z' USING latin1 ) COLLATE latin1_swedish_ci");
				  //echo "in search";
				//echo  $strExeQuery;
				// exit;

				  } 
				   

		//}


				//$strExeQuery="select * from t_login  order by LoginId asc";
				$pager = new PS_Pagination($conn,$strExeQuery,15,4);
				$rs = $pager->paginate();
				if(mysql_num_rows(mysql_query($strExeQuery))!=0)
				{
			?>
        	<tr>
	
			<td class="lakstoppad1" valign="top">
<form  action="AddEditCompetitionInfo.php" method="post" target="_parent">

	                      	<table border="0" cellpadding="3" cellspacing="1" width="800" bgcolor="#DDDDDD">
								<tr>
                            	<td class="tblhdr" colspan="8" height="24">
                                	User's List
								</td>
                               
                            </tr>

                		<tr class="HeaderStyle" height="24">
                    	<td align=" " width="20%">
                        	First Name    </td>
						<td align=" " width="20%">
                        	Last Name    </td>
		        
                         <td align=" "  width="20%">
                        	Mobile#
                        </td>
                        <td align=" "  width="20%">
                        	Email
                        </td>
						 <td align=""  width="15%">
                        	Class    </td>
						  <td align=""  width="15%">
                        	Role    </td>
               <td align="center"  width="5%">
                        	Active
                        </td>


         
						<!-- td align="center">
                        	
                        </td-->
						<td align="center">
                        	
                        </td>
                    </tr>
        <?php
					if(mysql_num_rows(mysql_query($strExeQuery))>15)
					{
						echo "<center> ";
						echo "<br/>".$pager->renderFullNav()."<br/>"; 
						echo"</center>";
						echo "<br>";
					}
					$iCount=0;
					while($Result=mysql_fetch_array($rs))
					{
						if($role=="Student")
					  {
							$appid=$Result['StudentDtlId'];
							$name=$Result['LoginName'];
							$FstName=$Result['StudentFstNm'];
							$LstName=$Result['StudentLstNm'];
							$RoleDesc="Student";
							$Mobile=$Result['Mobile'];
							$email=$Result['EMail'];
							//$id=$Result['LoginInfoId'];
							$active=$Result['Active'];
							$GradeName=$Result['GradeName'];
							$SectionName=$Result['SectionName'];
					  }
					  else
						{

						  	$name=$Result['LoginName'];
							$FstName=$Result['FstName'];
							$LstName=$Result['LstName'];
							$RoleDesc=$Result['RoleDesc'];
							$Mobile=$Result['Mobile'];
							$email=$Result['Email'];
							$id=$Result['LoginInfoId'];
							$active=$Result['Active'];
							$GradeName="";
							$SectionName="";
						}




					//echo "id: ".$id;
		  
		 // print_r($result);
	
		  ?>				
                      <tr class="<?php
										if($iCount==0)
										{
											echo "RowStyle";
											$iCount=1;
										}
										else
										{
											echo "AlternateRowStytle";
											$iCount=0;
										}
											?>" > 
		

                    	<td align="">
                        

							                       	<input type="hidden" value="<?php $appid;?>" name="hdApplId" />

				<input type="radio" name="rdoStdDtlId" id="rdoStdDtlId" tabindex="3" value="<?php $appid;?>"/>

			<?php echo $FstName; ?>
                        </td>
						 <td align="">
                        	<?php echo $LstName; ?>
                        </td>

						  <td align="">
                        	<?php echo $Mobile; ?>
                        </td>
                             <td align="">
                        	<?php echo $email; ?>
                        </td>
						  <td align="">
                        	<?php echo $GradeName." ".$SectionName; ?>
                        </td>
						<td align="">
                        	<?php echo $RoleDesc; ?>
                        </td>
						<td align="center">
                        	<?php echo $active; ?>
		 </td>




		
<?php

$UserInfoId=$Result['UserInfoId'];
//$RoleId=$Result['RoleId'];
if($active=="Y")
	$btn="Inactive";
else
	$btn="Active";


//echo "in active = ".$active." , id==".$id.", UserInfoId==".$UserInfoId.", RoleId==".$RoleId;

?>
	
						

					</tr>

                    <?php
						}
					
					?>
						<tr height="24">
     <td colspan="4" align="center"><input name="Save" type="submit" id="Save" value="Save" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>

<input type="button" value="Close" name="Cancel" id="Cancel" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="window.close();"/>

</td>
      </tr>         
             
			 </table>
             </form>
			 <?php
			 	}
			 	else
				{
						echo "
								<table width='800' align='center'>
									<tr>
										<td align='center'><fieldset style='width:80%' class='lakstoppad'>
            	<legend style='color:#000099'><b>User List</b></legend>
				No Records Found
									</fieldset>
										</td>
									</tr>
								</table>
								
							  ";
				}
		  }
			 ?>
           
             </td>
             </tr>
 
            </table>
<?php
include_once('Includes/Bottom.php');
?>
	
	
     
</body>
</html>
