<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');
//include_once('Includes/LeftNav.php');
 
 include_once("ps_pagination.php");
 ?>
<title>User's List</title>
		<!-- link href="../CSS/GridStyle.css" rel="stylesheet" type="text/css"/ -->

		<table width="1025" border="0" cellpadding="0" cellspacing="0" align="center">
  <tr>
    <td valign="top">
	<form name="f1" action="" method="post">
	
	<table border="0" cellpadding="2" cellspacing="1"  width="100%" bgcolor="#DDDDDD">
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
      </tr>

	  <tr height="24">
        <td class="tbldt" width="20%"> Last Name</td>
        <td class="tbldt" width="30%"><input name="lname" type="text" id="name" /></td>
     
<td class="tbldt" width="25%">
                                	Role <span class="manda"></span>                                    </td>
    <td class="tbldt">
	
	<select id='role' name='role' >
						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select RoleNm ,RoleDesc  from role where Active='Y'");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['RoleDesc'];?>"><?php echo $ResultEval['RoleDesc']?></option>";
						<?php }
						?>
                        	</select>
      </tr>

  <tr height="24">
     <td colspan="4" align="center"><input name="Search" type="submit" id="Search" value="Search" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>

<input type="button" value="Back" name="Cancel" id="Cancel" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('reports.php');"/>

</td>
      </tr>

         </table>



	<table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">

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
				  $strExeQuery="SELECT * FROM userinfo a,logininfo b,role c,userrole d WHERE d.Active='$active' and a.LoginName=b.LoginName and a.FstName LIKE '%$F%' and a.LstName LIKE '%$L%' and c.RoleDesc like'%$role%' and c.RoleId =d.RoleId and a.UserInfoId =d.UserInfoId ";
				  // $sql=mysql_query("SELECT * FROM t_Userinfo WHERE UserName LIKE CONVERT( _utf8 '%$z' USING latin1 ) COLLATE latin1_swedish_ci");
				  //echo "in search";
				//echo  $strExeQuery;
				// exit;
				  } 
				   

		//}


				//$strExeQuery="select * from t_login  order by LoginId asc";
				$pager = new PS_Pagination($conn,$strExeQuery,50,4);
				$rs = $pager->paginate();
				if(mysql_num_rows(mysql_query($strExeQuery))!=0)
				{
			?>
        	<tr>
	
			<td class="lakstoppad1" valign="top">
<form name="OrgApp" id="OrgApp" method="post" action="">
	                      	<table border="0" cellpadding="3" cellspacing="1" width="100%" bgcolor="#DDDDDD">
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
		              	<td align=""  width="15%">
                        	Role    </td>
                        
                        <td align=" "  width="33%">
                        	Email
                        </td>
               <td align="center"  width="6%">
                        	Active
                        </td>

	<td align="center"  width="6%">
                        	Details
                        </td>
         
						<!-- td align="center">
                        	
                        </td-->
						<td align="center">
                        	
                        </td>
                    </tr>
        <?php
					
//echo " Total :".mysql_num_rows(mysql_query($strExeQuery));
if(mysql_num_rows(mysql_query($strExeQuery))>50)
					{
						echo "<center> ";
						echo "<br/>".$pager->renderFullNav()."<br/>"; 
						echo"</center>";
						echo "<br>";
					}
					$iCount=0;
					while($Result=mysql_fetch_array($rs))
					{
					$name=$Result['LoginName'];
					$FstName=$Result['FstName'];
					$LstName=$Result['LstName'];
					$RoleDesc=$Result['RoleDesc'];
					$email=$Result['Email'];
					$id=$Result['LoginInfoId'];
					$active=$Result['Active'];

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
                        	<?php echo $FstName; ?>
                        </td>
						 <td align="">
                        	<?php echo $LstName; ?>
                        </td>
			                    	<td align="">
                        	<?php echo $RoleDesc; ?>
                        </td>
                             <td align="">
                        	<?php echo $email; ?>
                        </td>
						<td align="center">
                        	<?php echo $active; ?>
		 </td>

                 <td align="center">
                 
							<a href="ViewUserInfo.php?AppId=<?php print $Result['UserInfoId'];?>" class='bodynav'>View </a> <span class="style1">

					<a href="EditUser.php?UserInfoId=<?php print $Result['UserInfoId'];?>" class='bodynav'>Edit </a> <span class="style1">

                        </td>


		
<?php

$UserInfoId=$Result['UserInfoId'];
$RoleId=$Result['RoleId'];
if($active=="Y")
	$btn="Inactive";
else
	$btn="Active";


//echo "in active = ".$active." , id==".$id.", UserInfoId==".$UserInfoId.", RoleId==".$RoleId;

?>
		 <form action="activeinactive.php?id=<?php echo $id; ?>&&active=<?php echo $active; ?>&&roleid=<?php echo $RoleId; ?>&&UserInfoId=<?php echo $UserInfoId; ?>" method="post" name="no">
			 <td align="center">
                        	<input type="submit"  style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"name="yes" value="<?php print $btn;?>">



</td></form>
						

					</tr>

                    <?php
						}
					
					?>
			         
             
			 </table>
             </form>
			 <?php
			 	}
			 	else
				{
						echo "
								<table width=100% align='center'>
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
