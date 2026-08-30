	<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');
require_once('calendar/classes/tc_calendar.php');

 
  $uname=$_GET['uname'];

function dateDiff($dformat, $endDate, $beginDate)
{
$date_parts1=explode($dformat, $beginDate);
$date_parts2=explode($dformat, $endDate);
$start_date=gregoriantojd($date_parts1[0], $date_parts1[1], $date_parts1[2]);
$end_date=gregoriantojd($date_parts2[0], $date_parts2[1], $date_parts2[2]);
return $end_date - $start_date;
}


 //echo $uname;
   //exit;

 	if(isset($_POST['btnRegister']))
	{

 $uname=$_POST['txtUserName'];
  $IsAdmitted=$_POST['IsAdmitted'];	
 $selectdata = "SELECT * FROM logininfo where LoginName='$uname'";
 $Gender=$_POST['rdoGender'];

 
 //echo $IsAdmitted;


       $qry1 = mysql_query($selectdata);
 $n=mysql_num_rows($qry1);




         if($n>0)
		 {
	        echo "Please select other Username";
	
} 
else{

$today=date("Y/m/d");
$m=date("m");
$y=date("Y");
$d=date("d");

							
					 $numadmittedstd = "SELECT * FROM studentinfo where AdmittedInd ='Y'";
 $qrynumadmittedstd = mysql_query($numadmittedstd);
 $numadmitted=mysql_num_rows($qrynumadmittedstd);

 $numadmitted =$numadmitted+1;

							$strExeQueryMaxId=mysql_query("SELECT Max(StudentInfoId) as news_id from studentinfo",$conn);
						$rowMaxId=mysql_fetch_array($strExeQueryMaxId);	
						
						echo $rowMaxId['news_id'];
						$NEW_ID = $rowMaxId['news_id'] +1;
                 
	//$StudentUniqueId = "S".$NEW_ID;
		//$StudentUniqueId = $NEW_ID."/".$m."/".$y;
		$StudentUniqueId = $numadmitted."/".$m."/".$y;

		

   $query="insert into studentinfo (StudentUniqueId,StudentFstNm,StudentMiddleName,StudentLstNm,Gender,DOB,DateOfAdmission,AdmnForYear,AdmnForGrade,EMail,HomeSTD,HomePhone,Mobile,ServProvider,Address,City,State,Country,PIN,FathersNm,MothersNm,GardianNm,DoctorNm,FatherContNum,MotherContNum,GardianContNum,DoctorContNum,AdmittedInd,Note,MotherToungue,FatherOccup,MotherOccup,FatherEmail,MotherEmail) values ('$StudentUniqueId','$_POST[txtFstName]','$_POST[txtMidName]','$_POST[txtLstName]','$Gender','$_POST[txtDOB]','$_POST[txtAdmnDate]','$_POST[txtAdmnYear]',	'$_POST[txtAdmnGrade]','$_POST[txtEmail]','$_POST[txtHomeSTD]','$_POST[txtPhone]','$_POST[txtMobile]','$_POST[txtBLDGRP]','$_POST[txtAddress]','$_POST[txtCity]','$_POST[txtState]','$_POST[txtCountry]','$_POST[txtPIN]','$_POST[txtFName]','$_POST[txtMName]','$_POST[txtGName]','$_POST[txtDName]','$_POST[txtFCont]','$_POST[txtMCont]','$_POST[txtGCont]','$_POST[txtDCont]','$IsAdmitted','$_POST[txtNote]','$_POST[MotherToungue]','$_POST[FatherOccup]','$_POST[MotherOccup]','$_POST[FatherEmail]','$_POST[MotherEmail]')";


	$qry=mysql_query($query,$conn);


  // printf ("Last inserted record has id %d\n", mysql_insert_id()); 
	//$query="insert into studentdetails (StudentInfoId,SectionInfoId,GradeInfoId,UserInfoId,CurrYear,PassYear,Status) values (1,'$_POST[txtFstName]','$_POST[txtMidName]','$_POST[txtLstName]','FeMale','$_POST[txtDOB]','$_POST[txtAdmnDate]','$_POST[txtAdmnYear]',	'$_POST[txtAdmnGrade]','$_POST[txtEmail]','$_POST[txtHomeSTD]','$_POST[txtPhone]','$_POST[txtMobile]','$_POST[txtAddress]','$_POST[txtCity]','$_POST[txtState]','$_POST[txtCountry]','$_POST[txtPIN]','$_POST[txtFName]','$_POST[txtFCont]','$_POST[txtMName]','$_POST[txtMCont]','$_POST[txtGName]','$_POST[txtGCont]','$_POST[txtNote]')";


	//$qry=mysql_query($query,$conn);

//echo $query;

//$res = mysql_query("select last_insert_id()"); 
 
//echo $res; 


		$res=mysql_query("insert into logininfo (LoginName,Password,Role,Email,Active) values ('$_POST[txtUserName]','$_POST[txtPwd]','STDNT','$_POST[txtEmail]','Y')",$conn) ;
if(isset($qry)&&isset($res))
{
  echo 'Successfully Registered.<br>';

  
 $to=$_POST['txtEmail'];
 $subject = "Welcome New User- Hansel";

 $message = "<html><body><table  border='0' width='820px' cellspacing='0' cellpadding='0'>";
 $message.="<tr><td align='left' valign='top' style='padding-left:50px; padding-right:20px; font-family:Arial, Helvetica, sans-serif; font-size:12px'>";

 $message.="Dear    ". $_POST['txtUserName'].",";
  $message.= "<br/><br/><br/><br/>";
   $message.= "Thank you for choosing Hansel School  for your credential information. <br/><br/>";

   $message.= "Your account information is as follows:";
  $message.= "<br/><br/><strong>Your Username :</strong>&nbsp;&nbsp;".$_POST['txtUserName'];
  $message.= "<br /><strong>Your Password :</strong>&nbsp;&nbsp;".$_POST['txtPwd'];
 
  $message.= "<br/><br/>";
$message.="<br/><br/>Thanks  and Regards,<br/><br/>Admin Hansel School";



  $message.="</td></tr>";
  $message.="</table></body></html>";





 $headers = 'From: pravinsalunkhe@hotmail.com'."\r\n";
                    $headers .= 'Bcc: pravinsalunkhe@hotmail.com'."\r\n";
                    $headers .='Content-type: text/html; charset=iso-8859-1; format=flowed\n';
                    $headers .="MIME-Version: 1.0\n";
                    $headers .="Content-Transfer-Encoding: 8bit\n";
                    $headers .="X-Mailer: PHP\n";


//$message1="test message";

//	                mail($to, $subject, $message, $headers);

					
	                if(mail($to, $subject, $message, $headers))
					   header("location: MsgPage.php");
					else
						echo "$to. $subject. $message. $headers";
	}

					
                    
}
	}				
if(isset($_POST['save']))
	{
$uname=$_POST['txtUserName'];
 $IsAdmitted=$_POST['IsAdmitted'];	
  $Gender=$_POST['rdoGender'];

$selectdata = "SELECT * FROM logininfo where LoginName='$uname'";
//echo $selectdata;
         $qry2 = mysql_query($selectdata);
//echo "qry2 :  ".$qry2;

//$result = mysql_query("SELECT username FROM test2 where user_loginname = '".($_SESSION['user_loginname'])."'");

if(mysql_num_rows($qry2) == 0){
//echo "Nothing Selected";
//} //else {
// {
//	 echo "Please select other Username";
//display results
//}

///////////////////////////////////////////////////////////////////  need to update instead of Insert- Pravin ///////////////////////////////////

//$n1=mysql_num_row($qry2);
//         if($n1>0)
//		 {
//	        echo "Please select other Username";
	
//} 
//else
//{		
	
	$today=date("Y/m/d");
$m=date("m");
$y=date("Y");
$d=date("d");

 $numadmittedstd = "SELECT * FROM studentinfo where AdmittedInd ='Y'";
 $qrynumadmittedstd = mysql_query($numadmittedstd);
 $numadmitted=mysql_num_rows($qrynumadmittedstd);

 $numadmitted =$numadmitted+1;

							$strExeQueryMaxId=mysql_query("SELECT Max(StudentInfoId) as news_id from studentinfo",$conn);
						$rowMaxId=mysql_fetch_array($strExeQueryMaxId);	
						
						echo $rowMaxId['news_id'];
						$NEW_ID = $rowMaxId['news_id'] +1;
                 
	//$StudentUniqueId = "S".$NEW_ID;
		//$StudentUniqueId = $NEW_ID."/".$m."/".$y;
		$StudentUniqueId = $numadmitted."/".$m."/".$y;


		

   $query="insert into studentinfo (StudentUniqueId,StudentFstNm,StudentMiddleName,StudentLstNm,Gender,DOB,DateOfAdmission,AdmnForYear,AdmnForGrade,EMail,HomeSTD,HomePhone,Mobile,ServProvider,Address,City,State,Country,PIN,FathersNm,MothersNm,GardianNm,DoctorNm,FatherContNum,MotherContNum,GardianContNum,DoctorContNum,AdmittedInd,Note,MotherToungue,FatherOccup,MotherOccup,FatherEmail,MotherEmail) values ('$StudentUniqueId','$_POST[txtFstName]','$_POST[txtMidName]','$_POST[txtLstName]','$Gender','$_POST[txtDOB]','$_POST[txtAdmnDate]','$_POST[txtAdmnYear]',	'$_POST[txtAdmnGrade]','$_POST[txtEmail]','$_POST[txtHomeSTD]','$_POST[txtPhone]','$_POST[txtMobile]','$_POST[txtBLDGRP]','$_POST[txtAddress]','$_POST[txtCity]','$_POST[txtState]','$_POST[txtCountry]','$_POST[txtPIN]','$_POST[txtFName]','$_POST[txtMName]','$_POST[txtGName]','$_POST[txtDName]','$_POST[txtFCont]','$_POST[txtMCont]','$_POST[txtGCont]','$_POST[txtDCont]','$IsAdmitted','$_POST[txtNote]','$_POST[MotherToungue]','$_POST[FatherOccup]','$_POST[MotherOccup]','$_POST[FatherEmail]','$_POST[MotherEmail]')";

	$qry=mysql_query($query,$conn);


		$res=mysql_query("insert into logininfo (LoginName,Password,Role,Email,Active) values ('$_POST[txtUserName]','$_POST[txtPwd]','STDNT','$_POST[txtEmail]','N')",$conn) ;
if(isset($qry)&&isset($res))
{
  echo 'Successfully Saved.<br>';
}
else 
{
echo "Not Saved";
}
}

else 
 {
	 echo "Please select other Username";
//display results
}

}
?>





<?php
	
	 
		$strExeQuery123=mysql_query("select * from studentinfo where StudentInfoId=".$uname);
		$Row123=mysql_fetch_array($strExeQuery123);
	?>



<html>
<head>

<title>Update Student Information</title>

<link href="calendar/calendar.css" rel="stylesheet" type="text/css" />
<script language="javascript" src="calendar/calendar.js"></script>
<link href="calc.css" rel="stylesheet" type="text/css">
<SCRIPT language=JavaScript src="CalendarPopup.js"></SCRIPT>

<STYLE>

.cpYearNavigation {
	FONT-WEIGHT: bold; COLOR: #ffffff; BACKGROUND-COLOR: #6677dd; TEXT-ALIGN: center; TEXT-DECORATION: none
}
.cpMonthNavigation {
	FONT-WEIGHT: bold; COLOR: #ffffff; BACKGROUND-COLOR: #6677dd; TEXT-ALIGN: center; TEXT-DECORATION: none
}
.cpDayColumnHeader {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
.cpYearNavigation {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
.cpMonthNavigation {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
.cpCurrentMonthDate {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
.cpCurrentMonthDateDisabled {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
.cpOtherMonthDate {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
.cpOtherMonthDateDisabled {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
.cpCurrentDate {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
.cpCurrentDateDisabled {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
.cpTodayText {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
.cpTodayTextDisabled {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
.cpText {
	FONT-SIZE: 8pt; FONT-FAMILY: arial
}
TD.cpDayColumnHeader {
	BORDER-RIGHT: #6677dd 0px solid; BORDER-TOP: #6677dd 0px solid; BORDER-LEFT: #6677dd 0px solid; BORDER-BOTTOM: 

#6677dd 1px solid; TEXT-ALIGN: right
}
.cpCurrentMonthDate {
	TEXT-ALIGN: right; TEXT-DECORATION: none
}
.cpOtherMonthDate {
	TEXT-ALIGN: right; TEXT-DECORATION: none
}
.cpCurrentDate {
	TEXT-ALIGN: right; TEXT-DECORATION: none
}
.cpCurrentMonthDateDisabled {
	COLOR: #d0d0d0; TEXT-ALIGN: right; TEXT-DECORATION: line-through
}
.cpOtherMonthDateDisabled {
	COLOR: #d0d0d0; TEXT-ALIGN: right; TEXT-DECORATION: line-through
}
.cpCurrentDateDisabled {
	COLOR: #d0d0d0; TEXT-ALIGN: right; TEXT-DECORATION: line-through
}
.cpCurrentMonthDate {
	FONT-WEIGHT: bold; COLOR: #6677dd
}
.cpCurrentDate {
	FONT-WEIGHT: bold; COLOR: #ffffff
}
.cpOtherMonthDate {
	COLOR: #808080
}
TD.cpCurrentDate {
	BORDER-RIGHT: #000000 thin solid; BORDER-TOP: #000000 thin solid; BORDER-LEFT: #000000 thin solid; COLOR: #ffffff; 

BORDER-BOTTOM: #000000 thin solid; BACKGROUND-COLOR: #6677dd
}
TD.cpCurrentDateDisabled {
	BORDER-RIGHT: #ffaaaa thin solid; BORDER-TOP: #ffaaaa thin solid; BORDER-LEFT: #ffaaaa thin solid; BORDER-BOTTOM: 

#ffaaaa thin solid
}
TD.cpTodayText {
	BORDER-RIGHT: #6677dd 0px solid; BORDER-TOP: #6677dd 1px solid; BORDER-LEFT: #6677dd 0px solid; BORDER-BOTTOM: 

#6677dd 0px solid
}
TD.cpTodayTextDisabled {
	BORDER-RIGHT: #6677dd 0px solid; BORDER-TOP: #6677dd 1px solid; BORDER-LEFT: #6677dd 0px solid; BORDER-BOTTOM: 

#6677dd 0px solid
}
A.cpTodayText {
	HEIGHT: 20px
}
SPAN.cpTodayTextDisabled {
	HEIGHT: 20px
}
A.cpTodayText {
	FONT-WEIGHT: bold; COLOR: #6677dd
}
SPAN.cpTodayTextDisabled {
	COLOR: #d0d0d0
}
.cpBorder {
	BORDER-RIGHT: #6677dd thin solid; BORDER-TOP: #6677dd thin solid; BORDER-LEFT: #6677dd thin solid; BORDER-BOTTOM: 

#6677dd thin solid
}
</STYLE>

<script language="javascript" src="CalendarPopup.js"></script>

<style>

.error {

font-family: Tahoma;

font-size: 8pt;

color: red;

display:none;

}



.manda{

font-family: Tahoma;

font-size: 8pt;

color: red;

}

</style>


<script type="text/javascript">
function checkForm2()
{

	alert "comin here";
	return false;

if(document.frmEditStudent.txtFstName.value == "")
       {
               alert("Please enter the Student First Name");
               document.frmEditStudent.txtFstName.focus();
               return false;
       }
if(document.frmEditStudent.txtMidName.value == "")
       {
               alert("Please enter the Student Middle Name");
               document.frmEditStudent.txtMidName.focus();
               return false;
       }

if(document.frmEditStudent.txtLstName.value == "")
       {
               alert("Please enter the Student Last Name");
               document.frmEditStudent.txtLstName.focus();
               return false;
       }


	   if(document.frmEditStudent.txtDOB.value == "0000-00-00")
       {
               alert("Please enter Date of Birth");
               document.frmEditStudent.txtDOB.focus();
               return false;
       }
	   if(document.frmEditStudent.txtAdmnYear.value == "select")
       {
               alert("Please enter the Admission for Year");
               document.frmEditStudent.txtAdmnYear.focus();
               return false;
       }
	    if(document.frmEditStudent.txtAdmnGrade.value == "select")
       {
               alert("Please enter the Admission for Grade");
               document.frmEditStudent.txtAdmnGrade.focus();
               return false;
       }

	   	    if(document.frmEditStudent.txtSection.value == "select")
       {
               alert("Please enter the Admission for Section");
               document.frmEditStudent.txtSection.focus();
               return false;
       }
	   
	   if(document.frmEditStudent.txtEmail.value == "")
       {
               alert("Please enter the E-mail");
               document.frmEditStudent.txtEmail.focus();
               return false;
       }
	   
//	   var emailID=document.frmEditStudent.txtEMail

	   var emailID=document.frmEditStudent.txtEmail
	
	if ((emailID.value==null)||(emailID.value=="")){
		alert("Please Enter your Email ID")
		emailID.focus()
		return false
	}
	if (echeck(emailID.value)==false){
		emailID.value=""
		emailID.focus()
		return false
	}

	   
	   if(document.frmEditStudent.txtAddress.value == "")
       {
               alert("Please enter the Address");
               document.frmEditStudent.txtAddress.focus();
               return false;
       }
	   
	    if(document.frmEditStudent.txtHomeSTD.value == "")
       {
               alert("Please enter the STD CODE");
               document.frmEditStudent.txtHomeSTD.focus();
               return false;
       } 
	    if(document.frmEditStudent.txtHomeSTD.value == "")
       {
               alert("Please enter the STD CODE");
               document.frmEditStudent.txtHomeSTD.focus();
               return false;
       } 
	   
	     if(document.frmEditStudent.txtMobile.value == "")
       {
               alert("Please enter the Mobile  Number");
               document.frmEditStudent.txtMobile.focus();
               return false;
       } 
	   
  
	   if(document.frmEditStudent.txtMobile.value == "")
       {
               alert("Please enter the Mobile Number");
               document.frmEditStudent.txtMobile.focus();
               return false;
       } 
	   

	   
return true;
}
function echeck(str) {

		var at="@"
		var dot="."
		var lat=str.indexOf(at)
		var lstr=str.length
		var ldot=str.indexOf(dot)
		if (str.indexOf(at)==-1){
		   alert("Invalid E-mail ID")
		   return false
		}

		if (str.indexOf(at)==-1 || str.indexOf(at)==0 || str.indexOf(at)==lstr){
		   alert("Invalid E-mail ID")
		   return false
		}

		if (str.indexOf(dot)==-1 || str.indexOf(dot)==0 || str.indexOf(dot)==lstr){
		    alert("Invalid E-mail ID")
		    return false
		}

		 if (str.indexOf(at,(lat+1))!=-1){
		    alert("Invalid E-mail ID")
		    return false
		 }

		 if (str.substring(lat-1,lat)==dot || str.substring(lat+1,lat+2)==dot){
		    alert("Invalid E-mail ID")
		    return false
		 }

		 if (str.indexOf(dot,(lat+2))==-1){
		    alert("Invalid E-mail ID")
		    return false
		 }
		
		 if (str.indexOf(" ")!=-1){
		    alert("Invalid E-mail ID")
		    return false
		 }

 		 return true					
	}


</script>


</head>



<body leftmargin="0" topmargin="0" marginwidth="0" marginheight="0">

<table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">




	<tr>
	
			<td class="lakstoppad1" valign="top">

            		
					<form name="frmEditStudent" onSubmit="return checkForm2();" action="UpdateStudent.php?StudentInfoId=<?php print $_GET['uname'];?>" method="post" enctype="multipart/form-data" >

                    	

                    	<table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#DDDDDD">

                    		<tr>
                            	<td class="tblhdr" colspan="4" height="24">
                                	Student Registration
								</td>
                            </tr>

							
                              <tr>

                            	<td class="tbldt" width="20%">

                                	First Name <span class="manda">*</span>                                </td>

                                <td class="tbldt"  width="20%">

				<input type="text" id="txtFstName" name="txtFstName" size="30" tabindex="1" size="35" value="<?php echo $Row123['StudentFstNm']?>"/> 

									<div class=error id=txtNameError>*</div>

									</td>
					 	<td class="tbldt"  width="20%">

                                	Last Name <span class="manda">*</span>                                </td>

                                <td class="tbldt"  width="30%">

									
									<input type="text" id="txtLstName" name="txtLstName" size="30" tabindex="2" size="35" value="<?php echo $Row123['StudentLstNm']?>"/> 


									<div class=error id=txtNameError>*</div>

									</td>

                            </tr>
							
							<tr>

 <td class="tbldt">
DOB <span class="manda">*</span>  
</td>
<td class="tbldt">

<SCRIPT language=JavaScript id=jscal1xx>
var cal1xx = new CalendarPopup("testdiv1");
cal1xx.showNavigationDropdowns();
</SCRIPT>

<!-- ?php
// date control
$DOB= $Row123['DOB'];
$yy=substr($DOB,0,4);
$dd=substr($DOB,8,2);
$mm=substr($DOB,5,2);

$today=date("Y/m/d");
$m=date("m");
$y=date("Y");
$d=date("d");

	  $myCalendar = new tc_calendar("txtDOB", true, false);
	  $myCalendar->setIcon("calendar/images/iconCalendar.gif");
	  $myCalendar->setDate($dd, $mm, $yy);
	  $myCalendar->setPath("./calendar");
	  $myCalendar->setYearInterval(1990, 2020);
	  //$myCalendar->dateAllow('2008-05-13', '2010-03-01');
	  $myCalendar->setDateFormat('j F Y');
	  //$myCalendar->setHeight(350);	  
	  //$myCalendar->autoSubmit(true, "form1");
	  $myCalendar->writeScript();
	  ? -->

<INPUT size="10" maxlength="10" name=txtDOB value="<?php echo $Row123['DOB']?>"> <A id=anchor2xx 
      title="cal1xx.select(document.forms[0].txtDOB,'anchor2xx','yyyy-MM-dd'); return false;" 
      onclick="cal1xx.select(document.forms[0].txtDOB,'anchor2xx','yyyy-MM-dd'); return false;" 
      href="#" 
      name=anchor2xx><img src="calendar/images/iconCalendar.gif" border="0"></A>

</td>


<td class="tbldt">
Gender  <span class="manda">*</span>
</td>
<td class="tbldt">

 <?php 
if($Row123['Gender']=="Male")
{
	//echo "gender is male";
	//exit;
$gender="Male";

?> 
<input type="radio" name="rdoGender" id="rdoGender" tabindex="3" value="Male" checked="checked"/>Male
<input type="radio" name="rdoGender" id="rdoGender" tabindex="3" value="Female"/>Female

<?php 
}
else if($Row123['Gender'] =="Female")
{
?>
<input type="radio" name="rdoGender" id="rdoGender" tabindex="3" value="Male"/>Male
<input type="radio" name="rdoGender" id="rdoGender" tabindex="3" value="Female"checked="checked"/>Female
<?php 

}
?>



<div class=error id=txtNameError>*</div>
</td>


                            </tr>


							               <tr>

												
<td class="tbldt">
Admission Date <span class="manda">*</span>  
</td>
<td class="tbldt">

<!-- ?php
// date control
$DateOfAdmission= $Row123['DateOfAdmission'];
$yy=substr($DateOfAdmission,0,4);
$dd=substr($DateOfAdmission,8,2);
$mm=substr($DateOfAdmission,5,2);

	  $myCalendar = new tc_calendar("txtAdmnDate", true, false);
	  $myCalendar->setIcon("calendar/images/iconCalendar.gif");
	  $myCalendar->setDate($dd, $mm, $yy);
	  $myCalendar->setPath("./calendar");
	  $myCalendar->setYearInterval(2006, 2015);
	  //$myCalendar->dateAllow('2008-05-13', '2010-03-01');
	  $myCalendar->setDateFormat('j F Y');
	  //$myCalendar->setHeight(350);	  
	  //$myCalendar->autoSubmit(true, "form1");
	  $myCalendar->writeScript();
	  ? -->


<SCRIPT language=JavaScript id=jscal1xx>
var cal1xx = new CalendarPopup("testdiv1");
cal1xx.showNavigationDropdowns();
</SCRIPT>

      <INPUT size="10" maxlength="10" name=txtAdmnDate value="<?php echo $Row123['DateOfAdmission']?>"> <A id=anchor1xx 
      title="cal1xx.select(document.forms[0].txtAdmnDate,'anchor1xx','yyyy-MM-dd'); return false;" 
      onclick="cal1xx.select(document.forms[0].txtAdmnDate,'anchor1xx','yyyy-MM-dd'); return false;" 
      href="#" 
      name=anchor1xx><img src="calendar/images/iconCalendar.gif" border="0"></A> 
</td>



                            	<td class="tbldt">

                                	Admission For Year <span class="manda">*</span>                                     </td>

								 <td class="tbldt">	

<?php
$query_disp="select * from codevalue where CodeType ='year'";
$result_disp = mysql_query($query_disp, $conn);
if($_GET['uname']>0)
{
?>
	<select id="txtAdmnYear" name="txtAdmnYear"  tabindex="5">

<?php

while($query_data = mysql_fetch_array($result_disp))
{
?>
<option value="<?php echo $query_data['CodeValue']; ?>"<?php if ($query_data['CodeValue']==$Row123['AdmnForYear']) {?>selected<?php } ?>><?php echo $query_data['CodeValue']; ?></option>
<?php }}
 else{
?>

	<select id="txtAdmnYear" name="txtAdmnYear"  tabindex="5">

						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select CodeValue from codevalue where CodeType ='year'");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['CodeValue'];?>"><?php echo $ResultEval['CodeValue']?></option>";
						<?php }
						?>
                        	</select>

<?php
	}
?>

    
                            </tr>

			




								<tr>

							                            	<td class="tbldt">

                                	Age As of 1 st June <?php
									
//$date1="03/18/2000";
//$date1="02/11/2008";
//$date2="09/04/2004";
$date1= $Row123['DOB'];
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
//echo $today;
//echo $date1;
$diffdaystoday = dateDiff("/", $today, $date1);


$diffyr = floor($diffdays/365);
$diffmth = $diffdays%365;
$diffmth = floor($diffmth/30);
//echo $date2mth;
$diffyrtoday = floor($diffdaystoday/365);
$diffmthtoday = $diffdaystoday%365;
$diffmthtoday = floor($diffmthtoday/30);
//echo "Age as of 1st June ".$date2yr." is ".$diffyr." years ".$diffmth." months.";
//echo $diffmthtoday;
//exit;

 echo $date2yr?>  <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <?php echo $diffyr." Yr ".$diffmth." Mth"?>

								</td> 
								
								
							                            	<td class="tbldt">

                                	Age As of Today  <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <?php echo $diffyrtoday." Yr ".$diffmthtoday." Mth"?>

								</td> 
								
</tr>

				<tr>

							                            	<td class="tbldt">

                                	EMail <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtEmail" name="txtEmail" size="50" tabindex="6"  size="35" value="<?php echo $Row123['EMail']?>" />

									<div class=error id=txtEmailError>*</div>

									<div class=error id=txtEmail1Error>*</div>

								</td>    

                            	<td class="tbldt">

                                	Admission For Grade <span class="manda">*</span>    
									</td>



			<td class="tbldt">	

<?php

	$GradeInfoId = $Row123['AdmnForGrade'];
	$SectionInfoId = 0;
	$BusInfoId=0;

//echo "before ";
if($Row123['AdmittedInd']=='Y')
{
	//echo "inside AdmittedInd ";

		$chkstudentdetails="select * from studentdetails where StudentInfoId=".$Row123['StudentInfoId']."  ORDER by StudentDtlId desc";

		//echo "chkstudentdetails ".$chkstudentdetails;

		$result_STUDDTL= mysql_query($chkstudentdetails, $conn);
		$Rs_studdtl = mysql_fetch_array($result_STUDDTL);


		$GradeInfoId = $Rs_studdtl['GradeInfoId'];
		$SectionInfoId = $Rs_studdtl['SectionInfoId'];
		$BusInfoId = $Rs_studdtl['BusInfoId'];
	//echo "SectionInfoId ".$SectionInfoId;

}

$query_disp="select GradeInfoId,GradeName from gradeinfo";
$result_disp = mysql_query($query_disp, $conn);
if($_GET['uname']>0)
{
?>
	<select id="txtAdmnGrade" name="txtAdmnGrade"  tabindex="8">
 <option value='select' selected='selected'>Select</option>
<?php

while($query_data = mysql_fetch_array($result_disp))
{
?>
<option value="<?php echo $query_data['GradeInfoId']; ?>"<?php if ($query_data['GradeInfoId']==$GradeInfoId) {?>selected<?php } ?>><?php echo $query_data['GradeName']; ?></option>
<?php }}
 else{
?>

	<select id="txtAdmnGrade" name="txtAdmnGrade"  tabindex="9">

						   <option value='0' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select GradeInfoId,GradeName from gradeinfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['GradeInfoId'];?>"><?php echo $ResultEval['GradeName']?></option>";
						<?php }
 }
						?>
                        	</select>

		<?php
$query_disp="select SectionInfoId,SectionName  from sectioninfo";
$result_disp = mysql_query($query_disp, $conn);
if($_GET['uname']>0)
{
?>
	<select id="txtSection" name="txtSection"  tabindex="10">
 <option value='select' selected='selected'>Select</option>
<?php

while($query_data = mysql_fetch_array($result_disp))
{
?>
<option value="<?php echo $query_data['SectionInfoId']; ?>"<?php if ($query_data['SectionInfoId']==$SectionInfoId) {?>selected<?php } ?>><?php echo $query_data['SectionName']; ?></option>
<?php }}
 else{
?>

	<select id="txtSection" name="txtSection"  tabindex="11">

						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select SectionInfoId,SectionName  from sectioninfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['SectionInfoId'];?>"><?php echo $ResultEval['SectionName']?></option>";
						<?php }
 }
						?>
                        	</select>
							



								
									<div class=error id=txtConfirmPwdError>*</div>

									</td>

                            </tr>



                            <tr>

                            	<td class="tbldt">

                                	Address                                </td>

                                <td class="tbldt">

                                	<textarea id="txtAddress" name="txtAddress" tabindex="12" style="width:90%" rows="5"><?php echo $Row123['Address']?></textarea>                                </td>   
								
								
                            	<td class="tbldt">

                               	Home Phone                               </td>

                                <td class="tbldt">

                                    <input name="txtHomeSTD" type="text"  id="txtHomeSTD" tabindex="13" size="4" maxlength="7" value="<?php echo $Row123['HomeSTD']?>"/>

									<div class=error id=txtHomeSTDError>*</div>

									<div class=error id=txtHomeSTD1Error>*</div>

                                    <input type="text" id="txtPhone" name="txtPhone" size="10" maxlength="10"  tabindex="14" value="<?php echo $Row123['HomePhone']?>"/> 

									<div class=error id=txtPhoneError>*</div>

									<div class=error id=txtPhone1Error>*</div>

								 </td>

                            </tr>

                             <tr>

                            	

									                            	<td class="tbldt">

                                	City <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtCity" name="txtCity" tabindex="15" size="40" value="<?php echo $Row123['City']?>"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>

<td class="tbldt">

                                	State <span class="manda">*</span>                                     </td>

                                <td class="tbldt">

                                	<input type="text" id="txtState" name="txtState" tabindex="16" size="40" value="<?php echo $Row123['State']?>"/>

									<div class=error id=txtPwdError>*</div>

								 </td>


                            </tr>

							


							<tr>

							                            	

                            	<td class="tbldt">

                                	Country <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtCountry" name="txtCountry" tabindex="17" size="40" value="<?php echo $Row123['Country']?>"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>

<td class="tbldt">

                                	PIN                                     </td>

                                <td class="tbldt">

                                    <input type="text" id="txtPIN" name="txtPIN" tabindex="18" size="10" maxlength="6" value="<?php echo $Row123['PIN']?>"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>


                            </tr>


                           <tr>


							                            	<td class="tbldt">

                                	Father's Name       <span class="manda">*</span>                              </td>

                                <td class="tbldt">

                                	<input type="text" id="txtFName" name="txtFName" tabindex="19" size="40" value="<?php echo $Row123['FathersNm']?>"/>

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Father's Contact# <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtFCont" name="txtFCont" tabindex="20" size="10" maxlength="10" value="<?php echo $Row123['FatherContNum']?>"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>
                                

                              </tr>
							                             <tr>


							                            	<td class="tbldt">

                                	Mother's Name                                    </td>

                                <td class="tbldt">

                                	<input type="text" id="txtMName" name="txtMName" tabindex="21" size="40" value="<?php echo $Row123['MothersNm']?>"/>

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Mother's Contact# <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtMCont" name="txtMCont" tabindex="22" size="10" maxlength="10" value="<?php echo $Row123['MotherContNum']?>"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>
                                

                              </tr>
							                             <tr>


							                            	<td class="tbldt">

                                	Guardian's Name                                     </td>

                                <td class="tbldt">

                                	<input type="text" id="txtGName" name="txtGName" tabindex="23" size="40" value="<?php echo $Row123['GardianNm']?>"/>

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Guardian's Contact#                                     </td>

                                <td class="tbldt">

                                    <input type="text" id="txtGCont" name="txtGCont" tabindex="24" size="10" maxlength="10" value="<?php echo $Row123['GardianContNum']?>"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>
                                

                              </tr>
							   <tr>


							                            	<td class="tbldt">

                                	Doctor's Name                                     </td>

                                <td class="tbldt">

                                	<input type="text" id="txtDName" name="txtDName" tabindex="25" size="40" value="<?php echo $Row123['DoctorNm']?>"/>

									

								 </td>

                            	<td class="tbldt">

                                	Doctor's Contact#                                   </td>

                                <td class="tbldt">

                                    <input type="text" id="txtDCont" name="txtDCont" tabindex="26" size="10" maxlength="10" value="<?php echo $Row123['DoctorContNum']?>"/>                          

									</td>
                                

                              </tr>


                            	<td class="tbldt">

                                	Mobile# for SMS Purpose          </td>

                                <td class="tbldt" >

                                <input name="txtMobile" type="text" id="txtMobile" tabindex="27" size="10" maxlength="10" value="<?php
								if($Row123['Mobile'] >0 ) echo $Row123['Mobile']?>"/> 

								<div class=error id=txtMobileError>*</div> 

								<div class=error id=txtMobile1Error>*</div>                              </td>

								<td class="tbldt">

                                	Blood Group <span class="manda">*</span>                                     </td>

								 <td class="tbldt">	

<?php
$query_disp="select * from codevalue where CodeType ='BLDGRP'";
$result_disp = mysql_query($query_disp, $conn);
if($_GET['uname']>0)
{
?>
	<select id="txtBLDGRP" name="txtBLDGRP"  tabindex="28">
	<option value='select' selected='selected'>Select</option>

<?php

while($query_data = mysql_fetch_array($result_disp))
{
?>
<option value="<?php echo $query_data['CodeValue']; ?>"<?php if ($query_data['CodeValue']==$Row123['BloodGroup']) {?>selected<?php } ?>><?php echo $query_data['CodeValue']; ?></option>
<?php }}
 else{
?>

	<select id="txtBLDGRP" name="txtBLDGRP"  tabindex="29">

						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select CodeValue from codevalue where CodeType ='BLDGRP'");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['CodeValue'];?>"><?php echo $ResultEval['CodeValue']?></option>";
						<?php }
						?>
                        	</select>

<?php
	}
?>

</td>
</tr>

	<tr>

							                            	<td class="tbldt">

                                	Father's EMail <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="FatherEmail" name="FatherEmail" size="50" tabindex="30"  size="35" value="<?php echo $Row123['FatherEmail']?>" />

									<div class=error id=txtEmailError>*</div>

									<div class=error id=txtEmail1Error>*</div>

								</td>  
															                            	<td class="tbldt">

                                	Mother's EMail <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="MotherEmail" name="MotherEmail" size="50" tabindex="31"  size="35" value="<?php echo $Row123['MotherEmail']?>" />

									<div class=error id=txtEmailError>*</div>

									<div class=error id=txtEmail1Error>*</div>

								</td> 

		</tr>

			<tr>

							                            	<td class="tbldt">

                                	Father's Occupation <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="FatherOccup" name="FatherOccup" size="50" tabindex="32"  size="35" value="<?php echo $Row123['FatherOccup']?>" />

								</td>  
															                            	<td class="tbldt">

                                	Mother's Occupation <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="MotherOccup" name="MotherOccup" size="50" tabindex="33"  size="35" value="<?php echo $Row123['MotherOccup']?>" />


								</td> 

		</tr>



<tr>

								   	<td class="tbldt">

                                	Select Bus                                    </td>

	<td class="tbldt">			
		<?php
$query_disp="select BusInfoId,BusName  from businfo";
$result_disp = mysql_query($query_disp, $conn);
if($_GET['uname']>0)
{

	//echo "Bus Info: ".$BusInfoId;
?>
	<select id="txtBus" name="txtBus"  tabindex="34">
 <option value='select' selected='selected'>Select</option>
<?php

while($query_data = mysql_fetch_array($result_disp))
{
?>
<option value="<?php echo $query_data['BusInfoId']; ?>"<?php if ($query_data['BusInfoId']==$BusInfoId) {?>selected<?php } ?>><?php echo $query_data['BusName']; ?></option>
<?php }}
 else{
?>

	<select id="txtBus" name="txtBus"  tabindex="34">

						   <option value='0' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select BusInfoId,BusName  from businfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['BusInfoId'];?>"><?php echo $ResultEval['BusName']?></option>";
						<?php }
 }
						?>
                        	</select>
							



								
									<div class=error id=txtConfirmPwdError>*</div>

									</td>
<td class="tbldt">

                                	Mother Tongue <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="MotherToungue" name="MotherToungue" size="50" tabindex="35"  size="35" value="<?php echo $Row123['MotherToungue']?>" />


								</td>
																			                            	

                            </tr>


							<tr>

																                            	

                           	<td class="tbldt">

                                	User Name                                     </td>

                                <td class="tbldt" >

                                	<input type="text" id="txtUserName" name="txtUserName" tabindex="36" style="border:0" value="<?php echo $Row123['LoginName']?>" disabled/>
<input type="hidden" value="<?php echo $Row123['LoginName']; ?>" name="LoginName" />

									<div class=error id=txtUserNameError>*</div>
									

                                </td>
				                           	<td class="tbldt">

                                	Is Admitted ? <span class="manda">*</span> 
									
									</td>


				 <td class="tbldt">	

<?php
$query_disp="select * from codevalue where CodeType ='IsAdmit'";
$result_disp = mysql_query($query_disp, $conn);
if($_GET['uname']>0)
{
?>
	<select id="IsAdmitted" name="IsAdmitted"  tabindex="37">

<?php

while($query_data = mysql_fetch_array($result_disp))
{
?>
<option value="<?php echo $query_data['CodeValue']; ?>"<?php if ($query_data['CodeValue']==$Row123['AdmittedInd']) {?>selected<?php } ?>><?php echo $query_data['CodeDesc']; ?></option>
<?php }}
 else{
?>

	<select id="IsAdmitted" name="IsAdmitted"  tabindex="38">

						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select CodeValue from codevalue where CodeType ='IsAdmit'");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['CodeValue'];?>"><?php echo $ResultEval['CodeDesc']?></option>";
						<?php }
						?>
                        	</select>

<?php
	}
?>

                                               

                             

                            </tr>

                            <tr>

							                            	<td class="tbldt">

                                	Password                                      </td>

                                <td class="tbldt">

                                	<input type="password" id="txtPwd" name="txtPwd" tabindex="39" style="border:0" disabled/>

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Confirm Password                                     </td>

                                <td class="tbldt">

                                    <input type="password" id="txtConfirmPwd" name="txtConfirmPwd" tabindex="39" style="border:0" disabled/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>

                            </tr>



                             

                            

                            <tr>

                            	<td class="tbldt">

                                	Medical History ( if any )          </td>

                                <td class="tbldt" colspan="3">

                                	<textarea id="txtNote" name="txtNote" tabindex="40" style="width:90%" rows="5"><?php echo $Row123['Note']?></textarea>                                </td>

                            </tr>

                             <tr>

                             	
                                <td colspan="4" align="center" valign="baseline">

                                	
<input type="submit" value="Save" name="save" id="save" tabindex="41" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>

<input type="hidden" value="<?php echo $Row123['StudentUniqueId']; ?>" name="StudentUniqueId" />



<input type="button" value="Back" name="Back" id="Cancel" tabindex="42" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"  onClick='backbtn();'/>
                                           </td>

                             </tr>       

                    	</table>
                
				   </form>

 <DIV id=testdiv1 
      style="VISIBILITY: hidden; POSITION: absolute; BACKGROUND-COLOR: white; layer-background-color: white"></DIV>
            </td>

        </tr>

    </table>



<?php

	  include_once('Includes/Bottom.php');

?>