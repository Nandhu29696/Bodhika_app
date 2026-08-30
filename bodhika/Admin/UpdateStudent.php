	<?php
require_once('../Lib/Config.php');
include_once('Includes/Top.php');

 
  $StudentInfoId=$_GET['StudentInfoId'];


$StudentUniqueId=$_POST['StudentUniqueId'];
$StudentFstNm=$_POST['txtFstName'];
//$StudentMiddleName=$_POST['txtMidName'];
$StudentMiddleName="";
$StudentLstNm=$_POST['txtLstName'];
$Gender=$_POST['rdoGender'];
$DOB=$_POST['txtDOB'];
$DateOfAdmission=$_POST['txtAdmnDate'];
$AdmnForYear=$_POST['txtAdmnYear'];
$AdmnForGrade=$_POST['txtAdmnGrade'];
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
$AdmittedInd=$_POST['IsAdmitted']; 
$Note=$_POST['txtNote'];
$Section=$_POST['txtSection'];
$Grade=$_POST['txtAdmnGrade'];
$Bus=$_POST['txtBus'];
if($Bus=="select")
$Bus=0;
if($Section=="select")
$Section=0;


$LoginName=$_POST['LoginName'];


$MotherToungue=$_POST['MotherToungue'];
$FatherOccup=$_POST['FatherOccup'];
$MotherOccup=$_POST['MotherOccup'];
$FatherEmail=$_POST['FatherEmail'];
$MotherEmail=$_POST['MotherEmail']; 


$today=date("Y/m/d");
$m=date("m");
$y=date("Y");
$d=date("d");

//echo "Bus ".$Bus;
//exit;



$email="pravinsalunkhe@hotmail.com";
$subject="Updated student information";
$message="";
$from ="hansel@microsolutiononline.com";
	

     //
	 
		   
//$sqlTrack="insert into t_applicantinfoTrack VALUES ('',


//$rTrack=mysql_query($sqlTrack)or die(mysql_error());
   
   if($AdmittedInd=='Y')
   {

	   				//$strExeQueryUniqueId=mysql_query("select substring(StudentUniqueId,1,4) as news_id from studentinfo  order by StudentUniqueId  desc",$conn); 

if($StudentInfoId==$StudentUniqueId OR $StudentUniqueId=="")
{

	$strExeQueryUniqueId=mysql_query("select substring(StudentUniqueId,1,4) as news_id from studentinfo WHERE LENGTH(StudentUniqueId) > 4  order by StudentUniqueId  desc",$conn); 
	$rowMaxId=mysql_fetch_array($strExeQueryUniqueId);	
										
										//echo $rowMaxId['news_id'];
	$NEW_UNIQEID = $rowMaxId['news_id'];
	$NEW_UNIQEID = str_replace("/", "", $NEW_UNIQEID); 
	//$NEW_UNIQEID = REPLACE($NEW_UNIQEID,"/","");
	$StudentUniqueId = $NEW_UNIQEID +1;
	$StudentUniqueId = $StudentUniqueId."/".$m."/".substr($y,2,2);
	//$y;
	//echo "NEW_UNIQEID : ".$NEW_UNIQEID;

}
//echo $StudentInfoId;
//echo "StudentUniqueId:".$StudentUniqueId;
	//exit;

	   $sql="UPDATE studentinfo set  StudentUniqueId='$StudentUniqueId',StudentFstNm='$StudentFstNm',StudentLstNm='$StudentLstNm',Gender='$Gender',DOB='$DOB',DateOfAdmission='$DateOfAdmission',AdmnForYear='$AdmnForYear',AdmnForGrade='$AdmnForGrade',EMail='$EMail',HomeSTD='$HomeSTD',HomePhone='$HomePhone',Mobile='$Mobile',BloodGroup='$BloodGroup',Address='$Address',City='$City',State='$State',Country='$Country',PIN='$PIN',FathersNm='$FathersNm',MothersNm='$MothersNm',GardianNm='$GardianNm',DoctorNm='$DoctorNm',FatherContNum='$FatherContNum',MotherContNum='$MotherContNum',GardianContNum='$GardianContNum',DoctorContNum='$DoctorContNum',AdmittedInd='$AdmittedInd', Note='$Note',MotherToungue='$MotherToungue',FatherOccup='$FatherOccup',MotherOccup='$MotherOccup',FatherEmail='$FatherEmail',MotherEmail='$MotherEmail'  where StudentInfoId='$_GET[StudentInfoId]'";  

	

$qry=mysql_query($sql,$conn) or die(mysql_error());


		$chkLoginName="select * from userinfo where LoginName='$LoginName'";

//echo "chkLoginName   ".$chkLoginName." ";

		$chk=mysql_query($chkLoginName);
		$numlogin=mysql_num_rows($chk);
		//echo "numlogin   ".$numlogin;
		//exit;
		if($numlogin==0)
	   {
				$queryuser = "insert into userinfo (LoginName,FstName,MiddleName,LstName,Gender,EMail,HomeSTD,HomePhone,OfficeSTD,OfficePhone,Fax,Mobile,Address,ImageLoc,Note) values('$LoginName','$_POST[txtFstName]',null,'$_POST[txtLstName]','$Gender','$_POST[txtEmail]','$_POST[txtHomeSTD]','$_POST[txtPhone]','$_POST[txtHomeSTD]','$_POST[txtPhone]',null,'$_POST[txtMobile]','$_POST[txtAddress]',null,null)";
					echo $queryuser;
					fwrite($Handle,"queryuser :".$queryuser."\n\n");

					$message1="$queryuser";

					$insertuser=mysql_query($queryuser,$conn);

										$strExeQueryMaxId=mysql_query("SELECT Max(UserInfoId) as news_id from userinfo",$conn);



										$rowMaxId=mysql_fetch_array($strExeQueryMaxId);	
										
										//echo $rowMaxId['news_id'];
										$NEW_ID = $rowMaxId['news_id'];

			

									$userrole="insert into userrole (UserInfoId,RoleId,Active) values('$NEW_ID',7,'Y')";
									fwrite($Handle,"userrole :".$userrole."\n\n");
				$message1=$message1."          "."$userrole";
									sendEmail($email,$subject,$message1,$from);

				$qry1=mysql_query($userrole,$conn)or die(mysql_error());
	   }


	      $message="in if sql--- ".$sql;
	   sendEmail($email,$subject,$message,$from);

//echo "here ".$sql;

/*$abc="select * from studentdetails where LoginName='$_POST[txtUserName]'";
$c=mysql_query($abc);
  $r=mysql_num_rows($c);


if($r>0)
{
echo "Student Information updated successfully.";
}
else
{
	*/
		//$chkstudentdetails="select * from studentdetails where StudentInfoId='$_GET[StudentInfoId]' and GradeInfoId=".$Grade;
		$chkstudentdetails="select * from studentdetails where StudentInfoId='$_GET[StudentInfoId]'";

		$c1=mysql_query($chkstudentdetails);
		$r1=mysql_num_rows($c1);

		$result_STUDDTL= mysql_query($chkstudentdetails, $conn);
		$Rs_studdtl = mysql_fetch_array($result_STUDDTL);

//echo "chkstudentdetails ".$chkstudentdetails;
//echo "r1 ".$r1;


		if($r1>0)
		{
			$sqlstuddtl="UPDATE studentdetails set SectionInfoId=$Section,GradeInfoId=$Grade,BusInfoId=$Bus,CurrYear='$AdmnForYear' where StudentDtlId =".$Rs_studdtl['StudentDtlId'];
			
			//echo "sqlstuddtl--------".$sqlstuddtl; 
			//exit;

		$message= "sqlstuddtl--- ".$sqlstuddtl;
	   sendEmail($email,$subject,$message,$from);

			$qrystuddtl=mysql_query($sqlstuddtl,$conn) or die(mysql_error());

			echo "Student Information updated successfully.";
		}
		else
		{
	
			$sqlSection="insert into studentdetails  (StudentInfoId,SectionInfoId,GradeInfoId,BusInfoId,UserInfoId,CurrYear,PassYear,Status) VALUES ('$_GET[StudentInfoId]','$Section','$Grade','$Bus',null,'$AdmnForYear',null,null)";
//print $sqlSection;

	//	$message= "sqlSection--- ".$sqlSection;
	//   sendEmail($email,$subject,$message,$from);
			$qry1=mysql_query($sqlSection,$conn) or die(mysql_error());

			//echo "Student addmitted successfully. Student Id is :".$StudentUniqueId;

		}

//}


   }
   else
   {


                 
 $numadmittedstd = "SELECT * FROM studentinfo where AdmittedInd ='Y'";
 $qrynumadmittedstd = mysql_query($numadmittedstd);
 $numadmitted=mysql_num_rows($qrynumadmittedstd);

 $numadmitted =$numadmitted+1;
$StudentUniqueId = $numadmitted."/".$m."/".$y;

						

$sql="UPDATE studentinfo set StudentFstNm='$StudentFstNm',StudentLstNm='$StudentLstNm',Gender='$Gender',DOB='$DOB',DateOfAdmission='$DateOfAdmission',AdmnForYear='$AdmnForYear',AdmnForGrade='$AdmnForGrade',EMail='$EMail',HomeSTD='$HomeSTD',HomePhone='$HomePhone',Mobile='$Mobile',Address='$Address',City='$City',State='$State',Country='$Country',PIN='$PIN',FathersNm='$FathersNm',MothersNm='$MothersNm',GardianNm='$GardianNm',DoctorNm='$DoctorNm',FatherContNum='$FatherContNum',MotherContNum='$MotherContNum',GardianContNum='$GardianContNum',DoctorContNum='$DoctorContNum',AdmittedInd='$AdmittedInd', Note='$Note',MotherToungue='$MotherToungue',FatherOccup='$FatherOccup',MotherOccup='$MotherOccup',FatherEmail='$FatherEmail',MotherEmail='$MotherEmail'  where StudentInfoId='$_GET[StudentInfoId]'";  
	

	// $message="in else sql--- ".$sql;
	 //  sendEmail($email,$subject,$message,$from);

$qry=mysql_query($sql,$conn) or die(mysql_error());
   }


function sendEmail($to,$subject,$message,$from)
{

$to = $to;
$subject = $subject;
$message = $message;
$from = $from;

 $headers = "From: ".$from."\r\n";                    
 $headers .="Content-type: text/html; charset=iso-8859-1; format=flowed\n";
 $headers .="MIME-Version: 1.0\n";
 $headers .="Content-Transfer-Encoding: 8bit\n";
 $headers .="X-Mailer: PHP\n";
//echo "$headers ".$headers;
//echo "$to :".$to;
//echo "$subject :".$subject;
//echo "$message :".$message;
mail($to,$subject,$message,$headers);
echo "Mail Sent.";
}

function sendEmail2($to,$subject,$message,$from)
{

// Obtain file upload vars
$fileatt      = $_FILES['fileatt']['tmp_name'];
$fileatt_type = $_FILES['fileatt']['type'];
$fileatt_name = $_FILES['fileatt']['name'];

$headers = "From: $from";

//echo $headers;

//exit;

if (is_uploaded_file($fileatt)) {
  // Read the file to be attached ('rb' = read binary)
  $file = fopen($fileatt,'rb');
  $data = fread($file,filesize($fileatt));
  fclose($file);

  // Generate a boundary string
  $semi_rand = md5(time());
  $mime_boundary = "==Multipart_Boundary_x{$semi_rand}x";
  
  // Add the headers for a file attachment
  $headers .= "\nMIME-Version: 1.0\n" .
              "Content-Type: multipart/mixed;\n" .
              " boundary=\"{$mime_boundary}\"";

  // Add a multipart boundary above the plain message
  $message = "This is a multi-part message in MIME format.\n\n" .
             "--{$mime_boundary}\n" .
             "Content-Type: text/plain; charset=\"iso-8859-1\"\n" .
             "Content-Transfer-Encoding: 7bit\n\n" .
             $message . "\n\n";

  // Base64 encode the file data
  $data = chunk_split(base64_encode($data));
 $message .= "--{$mime_boundary}\n" .
              "Content-Type: {$fileatt_type};\n" .
              " name=\"{$fileatt_name}\"\n" .
              //"Content-Disposition: attachment;\n" .
              //" filename=\"{$fileatt_name}\"\n" .
              "Content-Transfer-Encoding: base64\n\n" .
              $data . "\n\n" .
              "--{$mime_boundary}--\n";
		}

		// Send the message

echo "headers: ".$headers;
echo "To: ".$to."\r\n";
echo "Subject: ".$subject;
echo "Message: ".$message;

		
		$ok = @mail($to, $subject, $message, $headers);
		if ($ok) {
		  echo "<p><p><center>Mail sent! <center></p></p>";
		} else {
		echo "<p><p><center>Mail could not be sent. Sorry!</center></p></p>";
		}
		

}



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
				
				