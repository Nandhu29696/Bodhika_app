<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');
//include_once('Includes/LeftNav.php');

 $role1 = $_SESSION['Role'];

// echo $role;
 
 include_once("ps_pagination.php");

 $today=date("Y/m/d");
$m=date("m");
$y=date("Y");
if($m<=5)
$curryear=$y-1;
else
$curryear=$y;

function dateDiff($dformat, $endDate, $beginDate)
{
$date_parts1=explode($dformat, $beginDate);
$date_parts2=explode($dformat, $endDate);
$start_date=gregoriantojd($date_parts1[0], $date_parts1[1], $date_parts1[2]);
$end_date=gregoriantojd($date_parts2[0], $date_parts2[1], $date_parts2[2]);
return $end_date - $start_date;
}


?>
<title>Student's List</title>
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
                                	Section <span class="manda"></span>                                    </td>
    <td class="tbldt">
  <select name="txtSection" id="txtSection">
				<option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select SectionInfoId,SectionName  from sectioninfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['SectionInfoId'];?>"><?php echo $ResultEval['SectionName']?></option>";
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

$role="STDNT";

		  if(isset($_POST['Search']))
		  {

			  //echo "Inside search";

				if(isset($_POST['fname']))
				  {
	$Grade=$_POST['txtAdmnGrade'];	
	$F=$_POST['fname'];
	$L=$_POST['lname'];
	$Section=$_POST['txtSection'];
	if($Section=="select")
		$Section="";
	if($Grade=="select")
		$Grade="";



$sql=0;

if($Grade=="" && $Section=="")
{
	//$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c,sectioninfo d  where a.StudentInfoId=b.StudentInfoId and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y' and b.CurrYear ='$curryear' and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId and a.AdmittedInd ='Y' order by a.StudentInfoId desc";
	$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c,sectioninfo d  where a.StudentInfoId=b.StudentInfoId and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y' and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId and b.CurrYear ='$curryear' order by a.StudentFstNm";
	$sql=1;
}
else if($Grade!="" && $Section!="")
{
		//$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c,sectioninfo d  where a.StudentInfoId=b.StudentInfoId and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y' and b.CurrYear ='$curryear' and b.SectionInfoId=".$Section."  and b.GradeInfoId =".$Grade." and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId and a.AdmittedInd ='Y' order by a.StudentInfoId desc";
		$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c,sectioninfo d  where a.StudentInfoId=b.StudentInfoId and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y'  and b.SectionInfoId=".$Section."  and b.GradeInfoId =".$Grade." and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId and b.CurrYear ='$curryear' order by a.StudentFstNm";
		$sql=2;
}
else if($Grade!="" && $Section=="")
{
		//$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c,sectioninfo d  where a.StudentInfoId=b.StudentInfoId and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y' and b.CurrYear ='$curryear' and b.GradeInfoId =".$Grade." and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId and a.AdmittedInd ='Y' order by a.StudentInfoId desc";
		$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c,sectioninfo d  where a.StudentInfoId=b.StudentInfoId and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y'  and b.GradeInfoId =".$Grade." and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId and b.CurrYear ='$curryear' order by a.StudentFstNm";
		$sql=3;
}

				
				  } 
		  }
		  else
		  {
								//$strExeQuery="select * from StudentInfo where AdmittedInd ='Y' order by StudentInfoId desc";
								//$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c  where a.StudentInfoId=b.StudentInfoId  and a.AdmittedInd ='Y' and b.CurrYear ='$curryear' and c.GradeInfoId = b.GradeInfoId  order by a.StudentInfoId desc";
								//$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c  where a.StudentInfoId=b.StudentInfoId  and a.AdmittedInd ='Y'  and c.GradeInfoId = b.GradeInfoId  order by a.StudentInfoId desc";

								$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c  where a.StudentInfoId=b.StudentInfoId  and a.AdmittedInd ='Y'   and c.GradeInfoId = b.GradeInfoId  and b.CurrYear ='$curryear' order by a.StudentFstNm";

								$sql=4;

		  }


echo  $strExeQuery;
				// exit;
				$pager = new PS_Pagination($conn,$strExeQuery,65,4);
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
                                	Student's List
								</td>
                               
                            </tr>

                		<tr class="HeaderStyle" height="24">
                    	<td align="left" width="8%">
                        	Admission #
                        </td>
                        <td align="left" width="15%">
                        	Name <br/>
              				<!-- (First, Middle. Last Name) -->
                        </td>
						<td align="left">
                        	Age as of Today
                        </td>
			<td align="left">
                        	Address
                        </td>

                        <td align="center">
                        	Mobile#
                        </td>
                         <td align="center" width="5%">
                        	Grade
                        </td>
                         <td align="center" width="5%">
                        	Section
                        </td>
						<td align="center" class="tblsechdr" width="5%">
                          	History
                         </td>


                    </tr>
        <?php
					if(mysql_num_rows(mysql_query($strExeQuery))>65)
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
                        	<?php $date1= $Result['DOB'];
$yy=substr($date1,0,4);
$dd=substr($date1,8,2);
$mm=substr($date1,5,2);
$date1 =$mm."/".$dd."/".$yy;
$date2yr=date('Y');
$date2mth=date('m');
if($date2mth < 6)
$date2yr=$date2yr-1;
$date2="06/"."01/".$date2yr;

//print "If we minus " . $date1 . " from " . $date2 . " we get " . dateDiff("/", $date2, $date1) . ".";

$diffdays = dateDiff("/", $date2, $date1);

$today=date("Y/m/d");
$m=date("m");
$y=date("Y");
$d=date("d");

//echo $diffdays;
$today =$m."/".$d."/".$y;
$diffdaystoday = dateDiff("/", $today, $date1);


$diffyr = floor($diffdays/365);
$diffmth = $diffdays%365;
$diffmth = floor($diffmth/30);
//echo $date2mth;
$diffyrtoday = floor($diffdaystoday/365);
$diffmthtoday = $diffdaystoday%365;
$diffmthtoday = floor($diffmthtoday/30);

echo $diffyrtoday." Yr ".$diffmthtoday." Mth"?>
                        </td>
                        <td align="left">
                        	<?php echo $Result['Address']; ?>
                        </td>

			 <td align="center">
                        	<?php if($Result['Mobile']=="0")
								echo "";
								else
								echo $Result['Mobile']; ?>
                        </td>
			 <td align="center">
                        	<?php echo $Result['GradeName']; ?>
                        </td>

                        <td align="center">
                        	<?php 

$SectionName="";

						if($sql==4)
							echo $SectionName ;
						else
							echo $Result['SectionName'];

?>
                        </td>
					                                    <td align="center">

										<?php

											//echo $role;
											 if($role1=="Admin" or $role1=="PRCIPAL")
											{
												?>

							<a href="EditStudent.php?uname=<?php print $Result['StudentInfoId'];?>" class='bodynav'>Edit </a> <span class="style1">

								<?php
											}
												?>
                           
				                  	<a href="ViewUserInfo.php?uname=<?php print $Result['LoginName'];?>&&rolename=<?php echo $role;?>" class='bodynav'>View </a> <span class="style1">

	<a href="Promote.php?StudentInfoId=<?php print $Result['StudentInfoId'];?>" class='bodynav'>Promote </a> <span class="style1">



                                    </td>

								

                    </tr>

                    <?php
						}
					
					
					?>
					<tr><td colspan="9" align="right"><a href="Export2_XL_AdmittedStudentList.php?sql=<?php echo $sql;?>&&f=<?php echo $F;?>&&l=<?php echo $L;?>&&s=<?php echo $Section;?>&&g=<?php echo $Grade;?>">Export to MS XL</a></td></tr>
			<tr><td height="22" class="" colspan="9" align="center">

<input name="BtnCancel" type="button" id="Cancel" value="Back" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('reports.php')"/ >

<input name="ExportToXL" type="submit" id="ExportToXL" value="Export XL" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>
			<input type="hidden" name="sql" value="<?php echo $strExeQuery;?>">

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
