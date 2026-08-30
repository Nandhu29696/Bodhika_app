<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');
//include_once('Includes/LeftNav.php');
 
 include_once("ps_pagination.php");

?>
<title>Email Group Information</title>
		<!-- link href="../CSS/GridStyle.css" rel="stylesheet" type="text/css"/ -->



	<table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">
<form name="frmOrgApp" action="" method="post">
	
		  <?php 
				$strExeQuery="select * from emailgrpinfo";

				$pager = new PS_Pagination($conn,$strExeQuery,15,4);
				$rs = $pager->paginate();
				if(mysql_num_rows(mysql_query($strExeQuery))!=0)
				{
			?>
        	<tr>
	
			<td class="lakstoppad1" valign="top">
<form name="OrgApp" id="OrgApp" method="post" action="">
	                      	<table border="0" cellpadding="2" cellspacing="1" width="100%" bgcolor="#EEEEEE">
								<tr>
                            	<td class="tblhdr" colspan="4" height="24">
                                	Email Group List
								</td>
                             
                            </tr>

                		<tr class="HeaderStyle" height="24">
                    	<td align="left" width="25%">
                        	Email Group Name
                        </td>
                        <td align="left" width="25%">
                        	Description <br/>
                        </td>

						<td align="center" width="25%">
                        	Active <br/>
                        </td>

					<td align="left">
                        	 <br/>
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

						 <td align="left">
                        	<?php echo $Result['EmailGrpName']; ?>
                        </td>
                  
                        <td align="left">
                        	<?php echo $Result['Description']; ?>
                        </td>

						                  <td align="center">
                        	<?php echo $Result['Active']; ?>
                        </td>
							<td align="center">
                            <a href="AddEditEmailGrpInfo.php?InfoId=<?php print $Result['EmailGrpId'];?>" class='bodynav'>Edit</a> <span class="style1">

				<a href="ViewEmailGrpDtlInfo.php?InfoId=<?php print $Result['EmailGrpId'];?>" class='bodynav'>View Contact</a> <span class="style1">
                        </td>

                    </tr>

                    <?php
						}
					
					
					?>
					
			<tr><td height="22" class="" colspan="4" align="center">

<input name="BtnAddNew" type="button" id="Cancel" value="Add New" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('AddEditEmailGrpInfo.php?InfoId=0')"/>


<input name="BtnCancel" type="button" id="Cancel" value="Back" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('Admin.php');"/>
</td>
</tr>     
         
             
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
            	<legend style='color:#000099'><b>Organizing Applications</b></legend>
				No Records Found
									</fieldset>
										</td>
									</tr>
								</table>
								
							  ";

							  ?>

							  				<tr><td height="22" class="" colspan="4" align="center">

<input name="BtnAddNew" type="button" id="Cancel" value="Add New" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('AddEditEmailGrpInfo.php?InfoId=0')"/>


<input name="BtnCancel" type="button" id="Cancel" value="Back" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('Admin.php');"/>
</td>
</tr>

<?php
				}
				


			 ?>
           
             </td>
             </tr>
			 
            </table>
<?php
include_once('Includes/Bottom.php');
?>
	</form>
	
     
</body>
</html>
