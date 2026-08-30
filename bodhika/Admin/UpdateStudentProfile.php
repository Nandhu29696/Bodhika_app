	<?php
require_once('../Lib/Config.php');
include_once('Includes/StudentTop.php');

 
  $StudentInfoId=$_GET['StudentInfoId'];


//$StudentUniqueId=$_POST['StudentUniqueId'];
$StudentFstNm=$_POST['txtFstName'];
//$StudentMiddleName=$_POST['txtMidName'];
$StudentMiddleName="";
$StudentLstNm=$_POST['txtLstName'];
$Gender=$_POST['rdoGender'];
//$DOB=$_POST['txtDOB'];
$DateOfAdmission=$_POST['txtAdmnDate'];
$EMail=$_POST['txtEmail'];
$HomeSTD=$_POST['txtHomeSTD'];
$HomePhone=$_POST['txtPhone'];
$Mobile=$_POST['txtMobile'];
$BloodGroup=$_POST['txtBLDGRP'];
$Address=$_POST['txtAddress'];
$City=$_POST['txtCity'];
$State=$_POST['txtState'];
$Country=$_POST['txtCountry'];
$PIN=$_POST['txtPIN'];
$FathersNm=$_POST['txtFName'];
$MothersNm=$_POST['txtMName'];
$GardianNm=$_POST['txtGName'];
$DoctorNm=$_POST['txtDName'];
$FatherContNum=$_POST['txtFCont'];
$MotherContNum=$_POST['txtMCont'];
$GardianContNum=$_POST['txtGCont'];
$DoctorContNum=$_POST['txtDCont'];




$MotherToungue=$_POST['MotherToungue'];
$FatherOccup=$_POST['FatherOccup'];
$MotherOccup=$_POST['MotherOccup'];
$FatherEmail=$_POST['FatherEmail'];
$MotherEmail=$_POST['MotherEmail']; 



//echo "Bus ".$Bus;
//exit;




	

     //
	 
		   
//$sqlTrack="insert into t_applicantinfoTrack VALUES ('',


//$rTrack=mysql_query($sqlTrack)or die(mysql_error());
   




	   $sql="UPDATE studentinfo set  StudentFstNm='$StudentFstNm',StudentLstNm='$StudentLstNm',Gender='$Gender',DateOfAdmission='$DateOfAdmission',EMail='$EMail',HomeSTD='$HomeSTD',HomePhone='$HomePhone',Mobile='$Mobile',BloodGroup='$BloodGroup',Address='$Address',City='$City',State='$State',Country='$Country',PIN='$PIN',FathersNm='$FathersNm',MothersNm='$MothersNm',GardianNm='$GardianNm',DoctorNm='$DoctorNm',FatherContNum='$FatherContNum',MotherContNum='$MotherContNum',GardianContNum='$GardianContNum',DoctorContNum='$DoctorContNum', MotherToungue='$MotherToungue',FatherOccup='$FatherOccup',MotherOccup='$MotherOccup',FatherEmail='$FatherEmail',MotherEmail='$MotherEmail'  where StudentInfoId='$_GET[StudentInfoId]'";  

$qry=mysql_query($sql,$conn) or die(mysql_error());



//}





//echo $sql;


		
		 print "<table width='100%'> 
					<tr>
						<td align='center'>
							<table>
								<tr>
									<td align='left'>
						 		
								Student Information updated </b>
									</td>
								</tr>
							</table>
						 </td>
					</tr>
				</table>";
				
				