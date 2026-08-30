<?php 
require_once('../Lib/Config.php');
include_once('Includes/StudentTop.php');
  require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
//$id=$_SESSION['id'];
$LoginInfoId=$_SESSION['LoginInfoId'];
//echo $UserName;
//echo $LoginInfoId;

//$appid=$_GET['10001'];
?>
<title>Student Information</title>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Student Information</title>
<script type="text/javascript">

</script>
<link type="text/css" rel="stylesheet" href="style.css" />

</head>

<body>
<table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">	
<form name="frmApplicationForm" action="" method="get">

         <tr>
        	
			<td class="lakstoppad1" valign="top">


                	
                    <?php

					$Query="select * from studentinfo where LoginName ='".$UserName."'";
//echo $Query;
						$strExeQuery=mysql_query("select * from studentinfo where LoginName ='".$UserName."'",$conn);
						//echo $strExeQuery;
						$row=mysql_fetch_array($strExeQuery);
						?>
					
					<table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#EEEEEE">
                        	<tr>
                        	<td class="tblhdr" colspan="5" height="24">
                            	Student Information
                            </td>
							</tr>
							
							
							<tr>
								<td class="tbllbl">
									Student Id : 
								</td>
                            <td class="tblvalue">
                             <?php 	echo "$row[StudentUniqueId]"; ?>
                            </td>
							    <td class="tbllbl">
                            	
                            </td>
                            <td class="tblvalue">
                            
                            </td>
							<td class="tblvalue" rowspan="5" align="center">
                            <img src="images/photo.gif">
                            </td>
                        </tr>
                        <tr>
                        	<td class="tbllbl" width="18%">
                            	Name : 
                            </td>
                           <td class="tblvalue"  width="30%">
                            <?php 	if($row['StudentMiddleName']!='')
				echo "$row[StudentFstNm]"." "."$row[StudentMiddleName]"." "."$row[StudentLstNm]"; 
			else
				echo "$row[StudentFstNm]".". "."$row[StudentLstNm]";?></td>
                            
							<td class="tbllbl" width="18%">
                            	Gender : 
                            </td>
                            <td class="tblvalue" width="22%">
                            <?php 	echo "$row[Gender]"; ?>
                            </td>

						
                            
                        </tr>
						 
                        <tr>
                        	<td class="tbllbl">
                            	Date Of Birth : 
                            </td>
                            <td class="tblvalue">
                            <?php 	echo "$row[DOB]"; ?>
                            </td>
							
							<td class="tbllbl">
								Date Of Admission  : 
							</td>
							<td class="tblvalue">
							<?php 	echo "$row[DateOfAdmission]"; ?>
							</td>
							
                            
                        </tr>





						<tr>
							<td class="tbllbl">
								Admission Year : 
							</td>
							<td class="tblvalue">
							<?php 	echo "$row[AdmnForYear]"; ?>
							</td>
							<td class="tbllbl">
								Admission Grade  : 
							</td>
							<td class="tblvalue">
							<?php 	echo "$row[AdmnForGrade]"; ?>
							</td>
						</tr>

<tr>
	<td class="tbllbl">
		FathersNm  : 
	</td>
	<td class="tblvalue">
	<?php 	echo "$row[FathersNm]"; ?>
	</td>
		<td class="tbllbl">
		Father Contact Number   : 
	</td>
	<td class="tblvalue">
	<?php 	echo "$row[FatherContNum]"; ?>
	</td>
</tr>
<tr>
	<td class="tbllbl">
		MothersNm  : 
	</td>
	<td class="tblvalue">
	<?php 	echo "$row[MothersNm]"; ?>
	</td>
		<td class="tbllbl">
		Mother Contact Number  : 
	</td>
	<td class="tblvalue" colspan="2">
	<?php 	echo "$row[MotherContNum]"; ?>
	</td>
</tr>

<tr>
	<td class="tbllbl">
		GardianNm  : 
	</td>
	<td class="tblvalue">
	<?php 	echo "$row[GardianNm]"; ?>
	</td>
		<td class="tbllbl">
		Gardian Contact Number  : 
	</td>
	<td class="tblvalue" colspan="2">
	<?php 	echo "$row[GardianContNum]"; ?>
	</td>
</tr>

						


                        <tr>
                        	<td class="tbllbl" valign="top">
                            	Address : 
                            </td>
                            <td class="tblvalue"  valign="top" colspan="4">
                            <?php 	echo "$row[Address]<br>$row[City]<br>$row[State]<br>$row[PIN]"; ?>
                            </td>
                        </tr>
                         <tr>
                        	<td class="tbllbl">
                            	Country : 
                            </td>
                            <td class="tblvalue" colspan="4">
                            <?php 	echo "$row[Country]"; ?>
                            </td>
                        </tr>
                         <tr>
                        	<td class="tbllbl">
                            	Home : 
                            </td>
                            <td class="tblvalue">
                            <?php 	echo "$row[HomeSTD]"." - "."$row[HomePhone]"; ?>
                            </td>
							<td class="tbllbl">
                            Mobile : 
                            </td>
                            <td class="tblvalue" colspan="2">
                            <?php 	echo "$row[Mobile]"; ?>
                            </td>
                        </tr>
                       
                        <tr>
                        	<td class="tbllbl">
                            EMail : 
                            </td>
                            <td class="tblvalue" colspan="4">
                             <?php 	echo "$row[EMail]"; ?>
                            </td>
                        </tr>
                    
                        	<tr>
                            	<td class="tbllbl">
                                	Notes : 
                                </td>
                                <td class="tblvalue" colspan="4">
                                	<?php echo "$row[Note]";  ?>
                                </td>
                            </tr>

							<tr><td colspan="4" align="center" height="26">&nbsp;

							<!-- input type="button" value="Home" id="btnClear" name="btnClear" tabindex="43" style="cursor:hand;background-image:url('../Images/btnsmall.gif');width:85px;height:26px;border:0" class="btnbg" onClick="location.href('Index.php')"/ -->


								   									  							</td></tr>


            </td>
        </tr>
    </table>
</form>
</body>
</html>
