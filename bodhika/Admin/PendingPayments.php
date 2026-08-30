<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');
//include_once('Includes/LeftNav.php');
 
 include_once("ps_pagination.php");

?>
<title>Pending Payments</title>
		<!-- link href="../CSS/GridStyle.css" rel="stylesheet" type="text/css"/ -->
		<table width="1025" border="0" cellpadding="0" cellspacing="0" align="center">
  <tr>
    <td valign="top">
	<form name="f1" action="" method="post">
	
	<table border="0" cellpadding="0" cellspacing="1"  width="100%" bgcolor="#DDDDDD">
    <tr height="24">
        <td class="tblhdr" colspan="4">Search</td> </tr>

      <tr height="24">
        <td class="tbldt" width="20%">First Name</td>
        <td class="tbldt" width="30%"><input name="fname" type="text" id="name" /></td>

        <td class="tbldt" width="20%"> Last Name</td>
        <td class="tbldt" width="30%"><input name="lname" type="text" id="name" /></td>
     
	      </tr>

	  <tr height="24">

<td class="tbldt" width="25%">
                                	Grade <span class="manda"></span>                                    </td>
									
									
<td class="tbldt">

				<select id='txtAdmnGrade' name='txtAdmnGrade' >
						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select GradeInfoId,GradeName from gradeinfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['GradeInfoId'];?>"><?php echo $ResultEval['GradeName']?></option>";
						<?php }
						?>
                        	</select>

							</td>
 

<td class="tbldt" width="25%">
                                	Term <span class="manda"></span>                                    </td>
    <td class="tbldt">
  <select name="txtTerm" id="txtTerm">
				<option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select TermId,TermDesc  from terminfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['TermId'];?>"><?php echo $ResultEval['TermDesc']?></option>";
						<?php }
						?>
                        	</select>

								</td>
      </tr>

  <tr height="24">
     <td colspan="4" align="center"><input name="Search" type="submit" id="Search" value="Search" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>

<input type="button" value="Back" name="Cancel" id="Cancel" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('reports.php');"/>

</td>
      </tr>

         </table>


	<table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">
<form name="frmOrgApp" action="" method="post">

   <?php 

	$Grade="";	
	$F="";
	$L="";
	$Term="";

		  if(isset($_POST['Search']))
		  {

			 // echo "Inside search";

				if(isset($_POST['fname']))
				  {
	$Grade=$_POST['txtAdmnGrade'];	
	$F=$_POST['fname'];
	$L=$_POST['lname'];
	$Term=$_POST['txtTerm'];
	if($Term=="select")
		$Term="";
	if($Grade=="select")
		$Grade="";



$sql=0;

if($Grade=="" && $Term=="")
{
		$strExeQuery="select * from studentinfo a,studentdetails b,studentpendingpayments c,terminfo d,gradeinfo e,sectioninfo f where a.StudentInfoId=b.StudentInfoId and b.StudentDtlId =c.StudentDtlId and b.GradeInfoId=e.GradeInfoId and b.SectionInfoId =f.SectionInfoId and c.TermId =d.TermId and c.DueAmt >0  and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y' order by b.GradeInfoId,b.SectionInfoId,c.TermId";

	$sql=1;
}
else if($Grade!="" && $Term!="")
{
		$strExeQuery="select * from studentinfo a,studentdetails b,studentpendingpayments c,terminfo d,gradeinfo e,sectioninfo f where a.StudentInfoId=b.StudentInfoId and b.StudentDtlId =c.StudentDtlId and b.GradeInfoId=e.GradeInfoId and b.SectionInfoId =f.SectionInfoId and c.TermId =d.TermId and c.DueAmt >0  and d.TermId=".$Term." and e.GradeInfoId =".$Grade." and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y' order by b.GradeInfoId,b.SectionInfoId,c.TermId";

		$sql=2;
}
else if($Term!="" && $Grade=="")
{
		$strExeQuery="select * from studentinfo a,studentdetails b,studentpendingpayments c,terminfo d,gradeinfo e,sectioninfo f where a.StudentInfoId=b.StudentInfoId and b.StudentDtlId =c.StudentDtlId and b.GradeInfoId=e.GradeInfoId and b.SectionInfoId =f.SectionInfoId and c.TermId =d.TermId and c.DueAmt >0   and d.TermId =".$Term." and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y' order by b.GradeInfoId,b.SectionInfoId,c.TermId";

		$sql=3;
}
else if($Term=="" && $Grade!="")
{
		$strExeQuery="select * from studentinfo a,studentdetails b,studentpendingpayments c,terminfo d,gradeinfo e,sectioninfo f where a.StudentInfoId=b.StudentInfoId and b.StudentDtlId =c.StudentDtlId and b.GradeInfoId=e.GradeInfoId and b.SectionInfoId =f.SectionInfoId and c.TermId =d.TermId and c.DueAmt >0   and e.GradeInfoId =".$Grade." and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y' order by b.GradeInfoId,b.SectionInfoId,c.TermId";

		$sql=5;
}

				// echo  $strExeQuery;
				 //exit;
				  } 
		  }
		  else
		  {
					$strExeQuery="select * from studentinfo a,studentdetails b,studentpendingpayments c,terminfo d,gradeinfo e,sectioninfo f where a.StudentInfoId=b.StudentInfoId and b.StudentDtlId =c.StudentDtlId and b.GradeInfoId=e.GradeInfoId and b.SectionInfoId =f.SectionInfoId and c.TermId =d.TermId and c.DueAmt >0 and a.AdmittedInd ='Y'  order by b.GradeInfoId,b.SectionInfoId,c.TermId";
								$sql=4;

		  }


			

//echo $strExeQuery;

				$pager = new PS_Pagination($conn,$strExeQuery,50,4);
				$rs = $pager->paginate();
				if(mysql_num_rows(mysql_query($strExeQuery))!=0)
				{
			?>
        	<tr>
	
			<td class="lakstoppad1" valign="top">
<form name="OrgApp" id="OrgApp" method="post" action="">
	                      	<table border="0" cellpadding="3" cellspacing="1" width="100%" bgcolor="#EEEEEE">
								<tr>
                            	<td class="tblhdr" colspan="9" height="24">
                                	Student's List
								</td>
                               
                            </tr>

                		<tr class="HeaderStyle" height="24">
                    	<td align="left" width="11%">
                        	Student Id
                        </td>
                        <td align="left">
                        	Name <br/>
              				<!-- (First, Middle. Last Name) -->
                        </td>
					 <td align="left">
                        	Grade
                        </td>
						<td align="left">
                        	Term
                        </td>
                         <td align="center" width="11%">
                        	Charge Amount
                        </td>
                        <td align="center" width="11%">
                        	Paid Amount
                        </td>
                        <td align="center" width="11%">
                        	Due Amount
                        </td>
                     <td align="center" width="11%">
                        	
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
                    	<td align="left" id="tdApplId">
                        	<input type="hidden" value="<?php echo $Result['StudentInfoId']; ?>" name="hdApplId" />
                        	<?php echo "<a class='bodynav' href='View.php?AppId=$Result[StudentInfoId]'>"."$Result[StudentUniqueId]"."</a>"; ?>
                        </td>
                        <td align="left"><?php 
							if($Result['StudentMiddleName']!='')
								echo "$Result[StudentFstNm]".", "."$Result[StudentMiddleName]"." "."$Result[StudentLstNm]"; 
							else
								echo "$Result[StudentFstNm]"." "."$Result[StudentLstNm]";?> </td>

			 
			 			 <td align="left">
                        	<?php echo $Result['GradeName']." ".$Result['SectionName']; ?>
                        </td>
						<td align="left">
                        	<?php echo $Result['TermDesc']; ?>
                        </td>
						<td align="right">
                        	<?php echo $Result['ChrgAmt']; ?>
                        </td>
                        <td align="right">
                        	<?php echo $Result['PaidAmt']; ?>
                        </td>
                        <td align="right">
                        	<?php 
								if($Result['DueAmt']=='')
									echo " - ";
								else 
									echo $Result['DueAmt']; ?>
                        </td>

<td align="center">
                            <a href="EditTermFeeInfo.php?AppId=<?php print $Result['StudentDtlId'];?>&&TermId=<?php print $Result['TermId'];?>&&scrn=<?php echo "PendingPayments";?>" class='bodynav'>Pay </a> <span class="style1">
				
                        </td>
                    </tr>

                    <?php
						}
					
					
					?>
					<tr><td colspan="9" align="right"><a href="Export2_XL_PendingPayments.php?sql=<?php echo $sql;?>&&f=<?php echo $F;?>&&l=<?php echo $L;?>&&t=<?php echo $Term;?>&&g=<?php echo $Grade;?>">Export to MS XL</a></td></tr>

				<tr><td colspan="9" align="right"><a href="Export2_XL_PendingPaymentsAll.php?sql=<?php echo $sql;?>&&f=<?php echo $F;?>&&l=<?php echo $L;?>&&t=<?php echo $Term;?>&&g=<?php echo $Grade;?>">New Format</a></td></tr>

			<tr><td height="22" class="" colspan="9" align="center">


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
            	<legend style='color:#000099'><b>Organizing Applications</b></legend>
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
