<?php 
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
//include_once('Includes/Top.php');
include_once("ps_pagination.php");
$LoginInfoId=$_SESSION['LoginInfoId'];
$role = $_SESSION['Role'];

if($role=="Admin" or $role=="PRCIPAL" or $role=="CLSTEACH")
{
include_once('Includes/Top.php');
$primkeyId = $_GET['AppId'];
}
else if($role=="STDNT")
{
include_once('Includes/StudentTop.php');
$query="select * from studentinfo where LoginName ='".$UserName."'";
//echo $query;
$strExeQuery=mysql_query($query,$conn);
//echo $strExeQuery;
$row=mysql_fetch_array($strExeQuery);
//echo $row;
$StudentInfoId = $row['StudentInfoId'];
//echo $strExeQuery;
//exit;
$primkeyId = $StudentInfoId;
}


?>
<title>Student's Information Form</title>
<link href="style.css" rel="stylesheet" type="text/css"/>
<!-- link href="../CSS/ViewDetail.css" rel="stylesheet" type="text/css"/>
<link href="../CSS/GridStyle.css" rel="stylesheet" type="text/css"/ --> 

<script>

function fees()
{
alert("Fees details will be available soon.")
}


function results()
{
alert("Result details will be available soon.")
}

</script>

</head>

<body>
	<table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">	
	
                    <?php
						$strExeQuery=mysql_query("select * from studentinfo where StudentInfoId=".$primkeyId,$conn);
						$row=mysql_fetch_array($strExeQuery);
						?>
				
<form name="frmApplicationForm" action="Assign.php?AppId=<?php echo $primkeyId;?>" method="post">
        <tr>
        	
			<td class="lakstoppad1" valign="top">


                	
                    <table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#EEEEEE">
						
                    	<tr>
                            	<td class="tblhdr" colspan="5" height="24">
                                	Student's Information
								</td>
                               
                            </tr>
						
						<tr>
                        	<td class="tbllbl">
                            	Student's Id 
                            </td>
                            
                            <td align="left"  class="tblvalue" colspan="3">
                             <?php 	echo "$row[StudentUniqueId]"; ?>
                            </td>
                       
                        
                            <!-- td class="tblvalue" rowspan="6" align="center">
                            <img src="images/photo.gif">
                            </td -->

<td class="tblvalue" rowspan="6" align="center">
                            <img src='photos/<?php 

$input ="$row[StudentFstNm]"."$row[StudentLstNm]"; 
$output = str_replace(" ","",$input); 


echo "$output"?>.jpg'>
                            </td>



                        </tr>
							<tr>
                        	<td class="tbllbl">
                            	Student's Name 
                            </td>
                            
                            <td class="tblvalue" colspan="3">
                            <?php 	echo "$row[StudentFstNm]"." $row[StudentMiddleName]"." ". "$row[StudentLstNm]"; ?>
                            </td>
                           
                        </tr>
                        <tr>
                        	<td class="tbllbl" width="18%">
                            	Gender  
                            </td>
                            
                            <td class="tblvalue" width="32%">
                            <?php 	echo "$row[Gender]"; ?>
                            </td>
                     
                        	<td class="tbllbl" width="18%">
                            	Date Of Birth  
                            </td>
                            
                            <td class="tblvalue" width="18%">
                            <?php 	echo "$row[DOB]"; ?>
                            </td>
                        </tr>
                        <tr>
                        	<td class="tbllbl">
                            	Address  
                            </td>
                            
                            <td class="tblvalue">
                            <?php 	echo "$row[Address]"; ?>
                            </td>
                     
                        	<td class="tbllbl">
                            	Country  
                            </td>
                            
                            <td class="tblvalue">
                            <?php 	echo "$row[Country]"; ?>
                            </td>
                        </tr>
                         <tr>
                        	<td class="tbllbl">
                            	Home  
                            </td>
                            
                            <td class="tblvalue">
                            <?php 	echo "$row[HomeSTD]"." - "."$row[HomePhone]"; ?>
                            </td>

							                       	<td class="tbllbl">
                            Mobile  
                            </td>
                            
                            <td class="tblvalue">
                            <?php 	echo "$row[Mobile]"; ?>
                            </td>
                  
                       
					    </tr>
                         <tr>
 
                   
                        	<td class="tbllbl">
                            EMail  
                            </td>
                            
                            <td class="tblvalue">
                             <?php 	echo "$row[EMail]"; ?>
                            </td>

	                        	<td class="tbllbl">
                            Bus Route  
                            </td>
                            
                            <td class="tblvalue">
                             <?php 							
							 $strExeQueryBusRoute=mysql_query("select * from studentdetails a,businfo b where a.BusInfoId=b.BusInfoId and a.StudentInfoId =".$row['StudentInfoId']." order by StudentDtlId desc",$conn);

							 //echo "select from studentdetails a,BusInfo b where a.BusInfoId=b.BusInfoId and a,Ststus='' and a.StudentInfoId =".$row['StudentInfoId']." order by StudentDtlId desc";
						$rowBusRoute=mysql_fetch_array($strExeQueryBusRoute);	
						
						echo $rowBusRoute['BusDesc']; ?>
                            </td>
                        </tr>
                   

					



							<tr>
                            	<td class="tblhdr" colspan="5" height="24">
                                	Education Information
								</td>
                               
                            </tr>
						
						<tr>
                            	<td colspan="5">
                                	

 <table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#EEEEEE">
                        	<tr class="HeaderStyle" height="24">

                            	<td align="center" width="17%">
									Academic Year
                                </td>
			      <td align="center" width="17%">
                                	Class
                                </td>
                                <td align="center" width="17%">
								    Pass Year
                                </td>
								<td align="center" width="17%">
								    Status
                                </td>
								<td align="center" width="">
								    Details 
                                </td>
                            </tr>
							<?php 
							//print $_GET['AppId'];
							$qry="select * from studentdetails a, studentinfo b , sectioninfo c, gradeinfo d where a.SectionInfoId = c.SectionInfoId and a.StudentInfoId = b.StudentInfoId and a.GradeInfoId=d.GradeInfoId and a.StudentInfoId =".$primkeyId;
							$res=mysql_query($qry,$conn) or die(mysql_error);
							$iCount=0;
							while($rowEdu=mysql_fetch_array($res))
							{
								if($rowEdu['CurrYear']!=0)
								{
							?>
							<tr height="24" class="<?php
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
										?>">

								   <td align="center">
									<?php if($rowEdu['PassYear']=="")
										{
											$to = $rowEdu['CurrYear'];
											$to =$to + 1;
										}
										else
										{
											$to = $rowEdu['PassYear'];
										}							   
							        ?>

							     <?php echo  "$rowEdu[CurrYear]"." - "."$to"; ?>
							   </td>

							    <td align="center">
							   	  <?php echo  "$rowEdu[GradeName]"." "."$rowEdu[SectionName]"; ?>
							   </td>
							   <td align="center">
							     <?php echo $rowEdu['PassYear']; ?>
							   </td>
							    <td align="center">
							      <?php echo $rowEdu['Status']; ?>
							   </td>
							   <td class="tbldt" align="center">


<a class="bodynav" href="javascript:fees();" >Fee Details</a>&nbsp;&nbsp;&nbsp;&nbsp;
<a class="bodynav" href="javascript:results();" >Result Details</a>


								  <!-- ?php echo "Fees and Result details will be available soon."; ? -->
							
							   </td>

							                     
							   

							</tr>
							<?php	
									}
							 	}
							 ?>
                        </table>

                                </td>
                            </tr>



						<tr>
                            	<td class="tblhdr" colspan="5" height="24">&nbsp;

								</td>
                               
                            </tr>
						
						<!-- tr>
                            	<td class="tbllbl">
                                	Certificates  
                                </td>
                                
                                <td class="tblvalue">
                                	<a class="bodynav" href=#self onclick=window.open('ViewCertificate.php?AppId=<?php print  $row['ApplicantId'];?>','','width=500,scrollbars=1,height=500')>View</a>
                                </td>
                            </tr -->

					<!-- tr>
                            	<td class="tblhdr" colspan="2" height="24">
                                	Note
								</td>
                               
                            </tr -->
						
						
						
								
						
						
						<tr>
                            	<td class="tbllbl" colspan="1">
                                	Note  
                                </td>
                                
                                <td class="tblvalue" colspan="4">
                                	<?php 	echo "$row[Note]"; ?>
                                </td>
								
                            </tr>
<tr height="22">
					<td align="center" colspan="5">
						<!-- input type="button" value="Back" tabindex="15" class="btnbg" onClick="location.href('Index.php');" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0"/ -->
							<!-- a href="#" onClick="history.go(-1)">Back</a -->
					</td>
			</tr>


                        </table>





            </td>
        </tr>
		</form>
    </table>

<?php 
  include_once('Includes/Bottom.php');

?>