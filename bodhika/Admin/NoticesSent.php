<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
$role = $_SESSION['Role'];

if($role=="Admin" or $role=="PRCIPAL" or $role=="CLSTEACH")
{
include_once('Includes/Top.php');
$strExeQuery="select * from notices order by CreatedDate desc";
}
else if($role=="STDNT")
{
include_once('Includes/StudentTop.php');
$strExeQuery="select * from notices where NoticeTo='All' order by CreatedDate desc";
}
 
 include_once("ps_pagination.php");

?>
<title>Notices Sent</title>
		<!-- link href="../CSS/GridStyle.css" rel="stylesheet" type="text/css"/ -->



	<table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">
<form name="frmOrgApp" action="" method="post">

	
		  <?php 
				//$strExeQuery="select * from notices order by CreatedDate desc";
				$pager = new PS_Pagination($conn,$strExeQuery,50,4);
				$rs = $pager->paginate();
				if(mysql_num_rows(mysql_query($strExeQuery))!=0)
				{
			?>
        	<tr>
	
			<td class="lakstoppad1" valign="top">
<form name="OrgApp" id="OrgApp" method="post" action="">
	                      	<table border="0" cellpadding="2" cellspacing="1" width="100%" bgcolor="#EEEEEE">
								<tr>
                            	<td class="tblhdr" colspan="9" height="24">
                                	List of Notices Sent
								</td>
                               
                           </tr>

                		<tr class="HeaderStyle" height="24">
                    	<td align="left" width="10%">
                        	Subect
                        </td>
                        <td align="left" >
                        	Message <br/>
                        </td>

						<td align="left" width="10%">
                        	Notice To <br/>
                        </td>
			 <td align="left" width="15%">
                        	Email Id / Mobile#
                        </td>
			<td align="left" width="10%">
                        	Sent By
                        </td>
                        <td align="center"  width="10%">
                        	Sent Date
                        </td>
					 



                    </tr>
        <?php
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
                        	<?php echo $Result['Subject']; ?>
                        </td>
                  
                        <td align="left">
                        	<?php echo $Result['Message']; ?>
                        </td>

						<td align="left">
                        	<?php echo $Result['NoticeTo']; ?>
                        </td>
						<td align="left">
                        	<?php echo $Result['Email']; ?>
                        </td>
						<td align="left">
                          <?php 
						$strExeQueryCreatedBy=mysql_query("select * from userinfo where UserInfoId =".$Result['CreateById'],$conn);
						$rowCreatedBy=mysql_fetch_array($strExeQueryCreatedBy);	
						
						echo $rowCreatedBy['FstName']." ".$rowCreatedBy['LstName']; ?>
                        </td>


						
						<td align="center">
                        	<?php echo $Result['CreatedDate']; ?>
                        </td>
						 
								




                    </tr>

                    <?php
						}
					
					
					?>
					<tr><td colspan="9" align="right"><a href="Export2_XL_Notices.php" >Export to MS XL</a></td></tr>
			<tr>
			<td height="22" class="" colspan="6" align="center">

<?php
if($role=="Admin" or $role=="PRCIPAL" or $role=="CLSTEACH")
{
echo " " ;

}
else
{
echo "<input name='BtnAddNew' type='button' id='Cancel' value='Back' style='background-image:url(../Images/btnsmall2.gif);width:100px;height:26px;border:0' class='btnbg'  onclick='location.href('Index.php');'/>" ;

}


?>


<!-- input name="BtnCancel" type="button" id="Cancel" value="Back" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('reports.php');"/ -->
</td></tr>     
         
             
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
            	<legend style='color:#000099'><b>Notices</b></legend>
				No Records Found
									</fieldset>
										</td>
									</tr>
								</table>
								
							  ";
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
