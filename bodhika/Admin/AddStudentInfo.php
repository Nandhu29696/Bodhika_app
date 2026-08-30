	<?php
require_once('../Lib/Config.php');
require_once('calendar/classes/tc_calendar.php');

include_once('Includes/Top.php');



//  if(!isset($_SESSION['Admin']))

//	header("location: ../index.php");

 				
if(isset($_POST['save']))
	{
 $uname=$_POST['txtUserName'];
  $IsAdmitted=$_POST['IsAdmitted'];	
   $Gender=$_POST['rdoGender'];
 $sysUserName = $_POST['txtFstName'];
 $password = $_POST['txtPwd'];

 if ($uname=="")
	 $uname = $sysUserName;
  if ($password=="")
	 $password = "password";

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
						
						//echo $rowMaxId['news_id'];
						$NEW_ID = $rowMaxId['news_id'] +1;
                 
	//$StudentUniqueId = "S".$NEW_ID;
		//$StudentUniqueId = $NEW_ID."/".$m."/".$y;
		$StudentUniqueId = $numadmitted."/".$m."/".$y;


$File =  $NEW_ID.".txt"; 
$Handle = fopen($File, 'w');
		

   $querystudentinfo ="insert into studentinfo (StudentUniqueId,StudentFstNm,StudentLstNm,Gender,DOB,DateOfAdmission,AdmnForYear,AdmnForGrade,EMail,HomeSTD,HomePhone,Mobile,ServProvider,Address,City,State,Country,PIN,FathersNm,MothersNm,GardianNm,DoctorNm,FatherContNum,MotherContNum,GardianContNum,DoctorContNum,AdmittedInd,Note,LoginName,MotherToungue,FatherOccup,MotherOccup,FatherEmail,MotherEmail) values ('$NEW_ID','$_POST[txtFstName]','$_POST[txtLstName]','$Gender','$_POST[txtDOB]','$_POST[txtAdmnDate]','$_POST[txtAdmnYear]',	'$_POST[txtAdmnGrade]','$_POST[txtEmail]','$_POST[txtHomeSTD]','$_POST[txtPhone]','$_POST[txtMobile]','$_POST[txtSMSDMN]','$_POST[txtAddress]','$_POST[txtCity]','$_POST[txtState]','$_POST[txtCountry]','$_POST[txtPIN]','$_POST[txtFName]','$_POST[txtMName]','$_POST[txtGName]','$_POST[txtDName]','$_POST[txtFCont]','$_POST[txtMCont]','$_POST[txtGCont]','$_POST[txtDCont]','$IsAdmitted','$_POST[txtNote]','$uname','$_POST[MotherToungue]','$_POST[FatherOccup]','$_POST[MotherOccup]','$_POST[FatherEmail]','$_POST[MotherEmail]')";

fwrite($Handle,"querystudentinfo :".$querystudentinfo."\n\n");

	$qry=mysql_query($querystudentinfo,$conn);

	//echo $querystudentinfo;


		//$res=mysql_query("insert into logininfo (LoginName,Password,Role,Email,Active) values ('$uname','$password','STDNT','$_POST[txtEmail]','N')",$conn) ;
	$querylogin = "insert into logininfo (LoginName,Password,Email,Active) values ('$uname','$password','$_POST[txtEmail]','N')";
//echo $querylogin;
fwrite($Handle,"querylogin :".$querylogin."\n\n");

	$insertlogin=mysql_query($querylogin,$conn);

	$Address = '$_POST[txtAddress]'." ".'$_POST[txtCity]'." ".'$_POST[txtState]'." ".'$_POST[txtCountry]'." ".'$_POST[txtPIN]';
//echo $Address ;
	$queryuser = "insert into userinfo (LoginName,FstName,MiddleName,LstName,Gender,EMail,HomeSTD,HomePhone,OfficeSTD,OfficePhone,Fax,Mobile,Address,ImageLoc,Note) values('$uname','$_POST[txtFstName]',null,'$_POST[txtLstName]','$Gender','$_POST[txtEmail]','$_POST[txtHomeSTD]','$_POST[txtPhone]','$_POST[txtHomeSTD]','$_POST[txtPhone]',null,'$_POST[txtMobile]','$_POST[txtAddress]',null,null)";
	//echo $queryuser;
	fwrite($Handle,"queryuser :".$queryuser."\n\n");

	$insertuser=mysql_query($queryuser,$conn);

	$strExeQueryMaxId=mysql_query("SELECT Max(UserInfoId) as news_id from userinfo",$conn);
	$rowMaxId=mysql_fetch_array($strExeQueryMaxId);	
						
						//echo $rowMaxId['news_id'];
	$NEW_ID = $rowMaxId['news_id'] +1;

	$userrole="insert into userrole (UserInfoId,RoleId,Active) values('$NEW_ID',7,'Y')";
					
					
	$qry1=mysql_query($userrole,$conn)or die(mysql_error());



$sqlstudentdetails ="insert into studentdetails  (StudentInfoId,SectionInfoId,GradeInfoId,BusInfoId,UserInfoId,CurrYear,PassYear,Status) VALUES ('$NEW_ID','$_POST[txtSection]','$_POST[txtAdmnGrade]',null,'$NEW_ID','$_POST[txtAdmnYear]',null,null)";
//echo  $sqlstudentdetails;
fwrite($Handle,"sqlstudentdetails :".$sqlstudentdetails."\n\n");

fclose($Handle); 

$qry1=mysql_query($sqlstudentdetails,$conn) or die(mysql_error());


if(isset($qry)&&isset($qry1))
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
<html>
<head>

<title>Adding Student Information</title>

<link href="calendar/calendar.css" rel="stylesheet" type="text/css" />
<script language="javascript" src="calendar/calendar.js"></script>

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

if(document.frmAddStudent.txtFstName.value == "")
       {
               alert("Please enter the Student First Name");
               document.frmAddStudent.txtFstName.focus();
               return false;
       }

if(document.frmAddStudent.txtLstName.value == "")
       {
               alert("Please enter the Student Last Name");
               document.frmAddStudent.txtLstName.focus();
               return false;
       }

/*if(document.frmAddStudent.txtPwd.value == "")
       {
               alert("Please enter the Password");
               document.frmAddStudent.txtPwd.focus();
               return false;
       }
if(document.frmAddStudent.txtConfirmPwd.value == "")
       {
               alert("Please enter the Confirm Password");
               document.frmAddStudent.txtConfirmPwd.focus();
               return false;
       }


if(document.frmAddStudent.txtConfirmPwd.value !=document.frmAddStudent.txtPwd.value)
       {
               alert("Please enter the Same Password ");
               document.frmAddStudent.txtConfirmPwd.focus();
               return false;
       }
	   
	   if(document.frmAddStudent.txtName.value == "")
       {
               alert("Please enter the Name");
               document.frmAddStudent.txtName.focus();
               return false;
       }*/

	   if(document.frmAddStudent.txtDOB.value == "")
       {
               alert("Please enter Date of Birth");
               document.frmAddStudent.txtDOB.focus();
               return false;
       }
	   if(document.frmAddStudent.txtAdmnYear.value == "select")
       {
               alert("Please enter the Admission for Year");
               document.frmAddStudent.txtAdmnYear.focus();
               return false;
       }

	   if(document.frmAddStudent.MotherToungue.value == "")
       {
               alert("Please enter Mother Toungue");
               document.frmAddStudent.MotherToungue.focus();
               return false;
       }


		   
	   if(document.frmAddStudent.txtEmail.value == "")
       {
               alert("Please enter the E-mail");
               document.frmAddStudent.txtEmail.focus();
               return false;
       }

	   
	   
//	   var emailID=document.frmApplicationForm.txtEMail

	   var emailID=document.frmAddStudent.txtEmail
	
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

	
    if(document.frmAddStudent.txtAdmnGrade.value == "select")
       {
               alert("Please enter the Admission for Grade");
               document.frmAddStudent.txtAdmnGrade.focus();
               return false;
       }

   
	   if(document.frmAddStudent.txtAddress.value == "")
       {
               alert("Please enter the Address");
               document.frmAddStudent.txtAddress.focus();
               return false;
       }
	   
	    if(document.frmAddStudent.txtHomeSTD.value == "")
       {
               alert("Please enter the STD CODE");
               document.frmAddStudent.txtHomeSTD.focus();
               return false;
       } 
	    if(document.frmAddStudent.txtPhone.value == "")
       {
               alert("Please enter the Phone");
               document.frmAddStudent.txtPhone.focus();
               return false;
       } 


	    if(document.frmAddStudent.txtFName.value == "")
       {
               alert("Please enter the Father's Name");
               document.frmAddStudent.txtFName.focus();
               return false;
       }

	   	    if(document.frmAddStudent.txtFCont.value == "")
       {
               alert("Please enter the Father's Contact#");
               document.frmAddStudent.txtFCont.focus();
               return false;
       }

	   	    if(document.frmAddStudent.txtMName.value == "")
       {
               alert("Please enter the Mother's Name");
               document.frmAddStudent.txtMName.focus();
               return false;
       }

	   	   	    if(document.frmAddStudent.txtMCont.value == "")
       {
               alert("Please enter the Mother's Contact#");
               document.frmAddStudent.txtMCont.focus();
               return false;
       }

	   	   	    if(document.frmAddStudent.txtGName.value == "")
       {
               alert("Please enter the Guardian's Name");
               document.frmAddStudent.txtGName.focus();
               return false;
       }

	   	   	   	    if(document.frmAddStudent.txtGCont.value == "")
       {
               alert("Please enter the Guardian's Contact#");
               document.frmAddStudent.txtGCont.focus();
               return false;
       }

	   

	     if(document.frmAddStudent.txtMobile.value == "")
       {
               alert("Please enter the Mobile  Number");
               document.frmAddStudent.txtMobile.focus();
               return false;
       } 

     if(document.frmAddStudent.txtSMSDMN.value == "select")
       {
               alert("Please enter the Mobile Service Provider");
               document.frmAddStudent.txtSMSDMN.focus();
               return false;
       } 

	   
  	   if(document.frmAddStudent.FatherEmail.value == "")
       {
               alert("Please enter the Father EMail");
               document.frmAddStudent.FatherEmail.focus();
               return false;
       } 




	   	   	   if(document.frmAddStudent.txtUserName.value == "")
       {
               alert("Please enter the User Name");
               document.frmAddStudent.txtUserName.focus();
               return false;
       } 

	   	   	   if(document.frmAddStudent.txtPwd.value == "")
       {
               alert("Please enter the Password");
               document.frmAddStudent.txtPwd.focus();
               return false;
       } 

	   	   	   if(document.frmAddStudent.txtConfirmPwd.value == "")
       {
               alert("Please enter the Confirm Password");
               document.frmAddStudent.txtConfirmPwd.focus();
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

            		<form name="frmAddStudent" action="" method="post" onSubmit="return checkForm2();">

                    	

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

									<input type="text" id="txtFstName" name="txtFstName" size="40" tabindex="1" size="35"/> 

									<div class=error id=txtNameError>*</div>

									</td>
					 	<td class="tbldt"  width="20%">

                                	Last Name <span class="manda">*</span>                                </td>

                                <td class="tbldt"  width="30%">

									<input type="text" id="txtLstName" name="txtLstName" size="40" tabindex="2" size="35"/> 

									<div class=error id=txtNameError>*</div>

									</td>

                            </tr>
							
							<tr>

<td class="tbldt">
Gender  <span class="manda">*</span>
</td>
<td class="tbldt">

<input type="radio" name="rdoGender" id="rdoGender" tabindex="3" value="Male" checked="checked"/>Male
<input type="radio" name="rdoGender" id="rdoGender" tabindex="4" value="Female"/>Female


<div class=error id=txtNameError>*</div>
</td>
<td class="tbldt">
Admission Date (yyyy-mm-dd)<span class="manda">*</span>  
</td>
<td class="tbldt">
<!-- input type="text" id="txtAdmnDate" name="txtAdmnDate" tabindex="3"/ -->
<div class=error id=txtConfirmPwdError>*</div>


<?php
// date control
	  $myCalendar = new tc_calendar("txtAdmnDate", true, false);
	  $myCalendar->setIcon("calendar/images/iconCalendar.gif");
	  //$myCalendar->setDate(5, 10, 2009);
	  $myCalendar->setPath("./calendar");
	  $myCalendar->setYearInterval(2006, 2015);
	  //$myCalendar->dateAllow('2008-05-13', '2010-03-01');
	  $myCalendar->setDateFormat('j F Y');
	  //$myCalendar->setHeight(350);	  
	  //$myCalendar->autoSubmit(true, "form1");
	  $myCalendar->writeScript();
	  ?>
</td>
                            </tr>


							               <tr>

							                            	<td class="tbldt">

                                	DOB <span class="manda">*</span>                                     </td>

                                <td class="tbldt">

                                	<!-- input type="text" id="txtDOB" name="txtDOB" tabindex="2"/ -->

									<div class=error id=txtPwdError>*</div>

									<?php
	  $myCalendar = new tc_calendar("txtDOB", true, false);
	  $myCalendar->setIcon("calendar/images/iconCalendar.gif");
	  //$myCalendar->setDate(5, 10, 2009);
	  $myCalendar->setPath("./calendar");
	  $myCalendar->setYearInterval(1980, 2015);
	  //$myCalendar->dateAllow('2008-05-13', '2010-03-01');
	  $myCalendar->setDateFormat('j F Y');
	  //$myCalendar->setHeight(350);	  
	  //$myCalendar->autoSubmit(true, "form1");
	  $myCalendar->writeScript();
	  ?>

								 </td>

                            	<td class="tbldt">

                                	Admission For Year <span class="manda">*</span>                                     </td>

                                <td class="tbldt">

            
	<select id='txtAdmnYear' name='txtAdmnYear' tabindex="5">
						    <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select CodeValue from codevalue where CodeType='Year'");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['CodeValue'];?>"><?php echo $ResultEval['CodeValue']?></option>";
						<?php }
						?>
                        	</select>



									<div class=error id=txtPwdError>*</div>

								 </td>

                            </tr>
				<tr>

							                            	<td class="tbldt">

                                	Age As of 1 st June  <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtAge" name="txtAge" size="20" tabindex="5"  />

								</td>  
								<td class="tbldt">
								   Mother Tongue <span class="manda">*</span>                                   
</td>
<td class="tbldt">
									   <input type="text" id="MotherToungue" name="MotherToungue" size="40" tabindex="6"  value="" />

</td>
</tr>

				<tr>

							                            	<td class="tbldt">

                                	EMail <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtEmail" name="txtEmail" size="40" tabindex="7"  />

									<div class=error id=txtEmailError>*</div>

									<div class=error id=txtEmail1Error>*</div>

								</td>    

                            	<td class="tbldt">

                                	Admission For Grade <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <!-- input type="text" id="txtAdmnGrade" name="txtAdmnGrade" tabindex="3"/ --> 
									
									


				<select id='txtAdmnGrade' name='txtAdmnGrade' tabindex="7">
						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select GradeInfoId,GradeName from gradeinfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['GradeInfoId'];?>"><?php echo $ResultEval['GradeName']?></option>";
						<?php }
						?>
                        	</select>
							
				<select id="txtSection" name="txtSection"  tabindex="7">

						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select SectionInfoId,SectionName  from sectioninfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['SectionInfoId'];?>"><?php echo $ResultEval['SectionName']?></option>";
						<?php }

						?>
                        	</select>				

		  <!-- select name="txtSection" id="txtSection">
				<option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select SectionName  from sectioninfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['SectionName'];?>"><?php echo $ResultEval['SectionName']?></option>";
						<?php }
						?>
                        	</select -->



									
									<div class=error id=txtConfirmPwdError>*</div>

									</td>

                            </tr>



                            <tr>

                            	<td class="tbldt">

                                	Address                                </td>

                                <td class="tbldt">

                                	<textarea id="txtAddress" name="txtAddress" tabindex="8" style="width:90%" rows="5"></textarea>                                </td>   
								
								
                            	<td class="tbldt">

                               	Home Phone     <span class="manda">*</span>     	

                                	   
                            

                                 


								                         </td>

                                <td class="tbldt">

                                    <input name="txtHomeSTD" type="text"  id="txtHomeSTD" tabindex="9" size="4" maxlength="7" />

									<div class=error id=txtHomeSTDError>*</div>

									<div class=error id=txtHomeSTD1Error>*</div>

                                    <input type="text" id="txtPhone" name="txtPhone" size="10" maxlength="10"  tabindex="10"/> 

									<div class=error id=txtPhoneError>*</div>

									<div class=error id=txtPhone1Error>*</div>


								 </td>

                            </tr>

                             <tr>

                            	

									                            	<td class="tbldt">

                                	City <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtCity" name="txtCity" tabindex="11" value="Hyderabad"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>

<td class="tbldt">

                                	State <span class="manda">*</span>                                     </td>

                            <td class="tbldt">
                            	<select id="txtState" tabindex="12" name="txtState">
                                	<option value="Andhra Pradesh" selected="selected">Andhra Pradesh</option>
<option value="Afghanistan">Maharashtra</option>
<option value="Albania">Karnataka</option>
<option value="Algeria">Goa</option>
<option value="Andorra">Gujarat</option>
<option value="Angola">Tamilnadu</option>


                                </select>
                            </td>


                            </tr>

							


							<tr>

							                            	

                            	<td class="tbldt">

                                	Country <span class="manda">*</span>                                    </td>







                            <td class="tbldt">
                            	<select id="txtCountry" tabindex="13" name="txtCountry">
                                	<option value="India" selected="selected">India</option>
<option value="Afghanistan">Afghanistan</option>
<option value="Albania">Albania</option>
<option value="Algeria">Algeria</option>
<option value="Andorra">Andorra</option>
<option value="Angola">Angola</option>
<option value="Antigua & Deps">Antigua & Deps</option>
<option value="Argentina">Argentina</option>
<option value="Armenia">Armenia</option>
<option value="Australia">Australia</option>
<option value="Austria">Austria</option>
<option value="Azerbaijan">Azerbaijan</option>
<option value="Bahamas">Bahamas</option>
<option value="Bahrain">Bahrain</option>
<option value="Bangladesh">Bangladesh</option>
<option value="Barbados">Barbados</option>
<option value="Belarus">Belarus</option>
<option value="Belgium">Belgium</option>
<option value="Belize">Belize</option>
<option value="Benin">Benin</option>
<option value="Bhutan">Bhutan</option>
<option value="Bolivia">Bolivia</option>
<option value="Bosnia Herzegovina">Bosnia Herzegovina</option>
<option value="Botswana">Botswana</option>
<option value="Brazil">Brazil</option>
<option value="Brunei">Brunei</option>
<option value="Bulgaria">Bulgaria</option>
<option value="Burkina">Burkina</option>
<option value="Burundi">Burundi</option>
<option value="Cambodia">Cambodia</option>
<option value="Cameroon">Cameroon</option>
<option value="Canada">Canada</option>
<option value="Cape Verde">Cape Verde</option>
<option value="Central African Rep">Central African Rep</option>
<option value="Chad">Chad</option>
<option value="Chile">Chile</option>
<option value="China">China</option>
<option value="Colombia">Colombia</option>
<option value="Comoros">Comoros</option>
<option value="Congo">Congo</option>
<option value="Congo {Democratic Rep}">Congo {Democratic Rep}</option>
<option value="Costa Rica">Costa Rica</option>
<option value="Croatia">Croatia</option>
<option value="Cuba">Cuba</option>
<option value="Cyprus">Cyprus</option>
<option value="Czech Republic">Czech Republic</option>
<option value="Denmark">Denmark</option>
<option value="Djibouti">Djibouti</option>
<option value="Dominica">Dominica</option>
<option value="Dominican Republic">Dominican Republic</option>
<option value="East Timor">East Timor</option>
<option value="Ecuador">Ecuador</option>
<option value="Egypt">Egypt</option>
<option value="El Salvador">El Salvador</option>
<option value="Equatorial Guinea">Equatorial Guinea</option>
<option value="Eritrea">Eritrea</option>
<option value="Estonia">Estonia</option>
<option value="Ethiopia">Ethiopia</option>
<option value="Fiji">Fiji</option>
<option value="Finland">Finland</option>
<option value="France">France</option>
<option value="Gabon">Gabon</option>
<option value="Gambia">Gambia</option>
<option value="Georgia">Georgia</option>
<option value="Germany">Germany</option>
<option value="Ghana">Ghana</option>
<option value="Greece">Greece</option>
<option value="Grenada">Grenada</option>
<option value="Guatemala">Guatemala</option>
<option value="Guinea">Guinea</option>
<option value="Guinea-Bissau">Guinea-Bissau</option>
<option value="Guyana">Guyana</option>
<option value="Haiti">Haiti</option>
<option value="Honduras">Honduras</option>
<option value="Hungary">Hungary</option>
<option value="Iceland">Iceland</option>
<option value="Indonesia">Indonesia</option>
<option value="Iran">Iran</option>
<option value="Iraq">Iraq</option>
<option value="Ireland {Republic}">Ireland {Republic}</option>
<option value="Israel">Israel</option>
<option value="Italy">Italy</option>
<option value="Ivory Coast">Ivory Coast</option>
<option value="Jamaica">Jamaica</option>
<option value="Japan">Japan</option>
<option value="Jordan">Jordan</option>
<option value="Kazakhstan">Kazakhstan</option>
<option value="Kenya">Kenya</option>
<option value="Kiribati">Kiribati</option>
<option value="Korea North">Korea North</option>
<option value="Korea South">Korea South</option>
<option value="Kosovo">Kosovo</option>
<option value="Kuwait">Kuwait</option>
<option value="Kyrgyzstan">Kyrgyzstan</option>
<option value="Laos">Laos</option>
<option value="Latvia">Latvia</option>
<option value="Lebanon">Lebanon</option>
<option value="Lesotho">Lesotho</option>
<option value="Liberia">Liberia</option>
<option value="Libya">Libya</option>
<option value="Liechtenstein">Liechtenstein</option>
<option value="Lithuania">Lithuania</option>
<option value="Luxembourg">Luxembourg</option>
<option value="Macedonia">Macedonia</option>
<option value="Madagascar">Madagascar</option>
<option value="Malawi">Malawi</option>
<option value="Malaysia">Malaysia</option>
<option value="Maldives">Maldives</option>
<option value="Mali">Mali</option>
<option value="Malta">Malta</option>
<option value="Marshall Islands">Marshall Islands</option>
<option value="Mauritania">Mauritania</option>
<option value="Mauritius">Mauritius</option>
<option value="Mexico">Mexico</option>
<option value="Micronesia">Micronesia</option>
<option value="Moldova">Moldova</option>
<option value="Monaco">Monaco</option>
<option value="Mongolia">Mongolia</option>
<option value="Montenegro">Montenegro</option>
<option value="Morocco">Morocco</option>
<option value="Mozambique">Mozambique</option>
<option value="Myanmar, {Burma}">Myanmar, {Burma}</option>
<option value="Namibia">Namibia</option>
<option value="Nauru">Nauru</option>
<option value="Nepal">Nepal</option>
<option value="Netherlands">Netherlands</option>
<option value="New Zealand">New Zealand</option>
<option value="Nicaragua">Nicaragua</option>
<option value="Niger">Niger</option>
<option value="Nigeria">Nigeria</option>
<option value="Norway">Norway</option>
<option value="Oman">Oman</option>
<option value="Pakistan">Pakistan</option>
<option value="Palau">Palau</option>
<option value="Panama">Panama</option>
<option value="Papua New Guinea">Papua New Guinea</option>
<option value="Paraguay">Paraguay</option>
<option value="Peru">Peru</option>
<option value="Philippines">Philippines</option>
<option value="Poland">Poland</option>
<option value="Portugal">Portugal</option>
<option value="Qatar">Qatar</option>
<option value="Romania">Romania</option>
<option value="Russian Federation">Russian Federation</option>
<option value="Rwanda">Rwanda</option>
<option value="St Kitts & Nevis">St Kitts & Nevis</option>
<option value="St Lucia">St Lucia</option>
<option value="Saint Vincent & the Grenadines">Saint Vincent & the Grenadines</option>
<option value="Samoa">Samoa</option>
<option value="San Marino">San Marino</option>
<option value="Sao Tome & Principe">Sao Tome & Principe</option>
<option value="Saudi Arabia">Saudi Arabia</option>
<option value="Senegal">Senegal</option>
<option value="Serbia">Serbia</option>
<option value="Seychelles">Seychelles</option>
<option value="Sierra Leone">Sierra Leone</option>
<option value="Singapore">Singapore</option>
<option value="Slovakia">Slovakia</option>
<option value="Slovenia">Slovenia</option>
<option value="Solomon Islands">Solomon Islands</option>
<option value="Somalia">Somalia</option>
<option value="South Africa">South Africa</option>
<option value="Spain">Spain</option>
<option value="Sri Lanka">Sri Lanka</option>
<option value="Sudan">Sudan</option>
<option value="Suriname">Suriname</option>
<option value="Swaziland">Swaziland</option>
<option value="Sweden">Sweden</option>
<option value="Switzerland">Switzerland</option>
<option value="Syria">Syria</option>
<option value="Taiwan">Taiwan</option>
<option value="Tajikistan">Tajikistan</option>
<option value="Tanzania">Tanzania</option>
<option value="Thailand">Thailand</option>
<option value="Togo">Togo</option>
<option value="Tonga">Tonga</option>
<option value="Trinidad & Tobago">Trinidad & Tobago</option>
<option value="Tunisia">Tunisia</option>
<option value="Turkey">Turkey</option>
<option value="Turkmenistan">Turkmenistan</option>
<option value="Tuvalu">Tuvalu</option>
<option value="Uganda">Uganda</option>
<option value="Ukraine">Ukraine</option>
<option value="United Arab Emirates">United Arab Emirates</option>
<option value="United Kingdom">United Kingdom</option>
<option value="United States">United States</option>
<option value="Uruguay">Uruguay</option>
<option value="Uzbekistan">Uzbekistan</option>
<option value="Vanuatu">Vanuatu</option>
<option value="Vatican City">Vatican City</option>
<option value="Venezuela">Venezuela</option>
<option value="Vietnam">Vietnam</option>
<option value="Yemen">Yemen</option>
<option value="Zambia">Zambia</option>
<option value="Zimbabwe">Zimbabwe</option>

                                </select>
                            </td>
                        



<td class="tbldt">

                                	PIN <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtPIN" name="txtPIN" tabindex="14" maxlength="6" size="10" />                                <div class=error id=txtConfirmPwdError>*</div>

									</td>


                            </tr>


                           <tr>


							                            	<td class="tbldt">

                                	Father's Name     <span class="manda">*</span>                                </td>

                                <td class="tbldt">

                                	<input type="text" id="txtFName" name="txtFName" tabindex="15" size="40" />

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Father's Contact# <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtFCont" name="txtFCont" tabindex="16" maxlength="10" size="10" />                                <div class=error id=txtConfirmPwdError>*</div>

									</td>
                                

                              </tr>
							                             <tr>


							                            	<td class="tbldt">

                                	Mother's Name        <span class="manda">*</span>                             </td>

                                <td class="tbldt">

                                	<input type="text" id="txtMName" name="txtMName" tabindex="17" size="40" />

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Mother's Contact# <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtMCont" name="txtMCont" tabindex="18"  maxlength="10" size="10" />                                <div class=error id=txtConfirmPwdError>*</div>

									</td>
                                

                              </tr>
							                             <tr>


							                            	<td class="tbldt">

                                	Guardian's Name       <span class="manda">*</span>                               </td>

                                <td class="tbldt">

                                	<input type="text" id="txtGName" name="txtGName" tabindex="19" size="40" />

									

								 </td>

                            	<td class="tbldt">

                                	Guardian's Contact# <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtGCont" name="txtGCont" tabindex="20" maxlength="10" size="10" />                                <div class=error id=txtConfirmPwdError>*</div>

									</td>
                                

                              </tr>

							                             <tr>


							                            	<td class="tbldt">

                                	Doctor's Name                                     </td>

                                <td class="tbldt">

                                	<input type="text" id="txtDName" name="txtDName" tabindex="21" size="40" />

									

								 </td>

                            	<td class="tbldt">

                                	Doctor's Contact#                                   </td>

                                <td class="tbldt">

                                    <input type="text" id="txtDCont" name="txtDCont" tabindex="22" maxlength="10" size="10" />                          

									</td>
                                

                              </tr>


                            	<td class="tbldt">

                                	Mobile# for SMS Purpose <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                <input name="txtMobile" type="text" id="txtMobile" tabindex="23" size="10" maxlength="10"/> 

								<div class=error id=txtMobileError>*</div> 

								<div class=error id=txtMobile1Error>*</div>                              </td>
						<td class="tbldt">

                                	Service Provider <span class="manda">*</span>                                    </td>
								 <td class="tbldt">

            
	<select id='txtSMSDMN' name='txtSMSDMN' tabindex="24">
						    <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select CodeValue,CodeDesc from codevalue where CodeType='SMSDMN'");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['CodeValue'];?>"><?php echo $ResultEval['CodeDesc']?></option>";
						<?php }
						?>
                        	</select>



									<div class=error id=txtPwdError>*</div>

								 </td>

                            </tr>
	<tr>

							                            	<td class="tbldt">

                                	Father EMail <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="FatherEmail" name="FatherEmail" size="40" tabindex="25"  value="" />

									<div class=error id=txtEmailError>*</div>

									<div class=error id=txtEmail1Error>*</div>

								</td>  
															                            	<td class="tbldt">

                                	Mother EMail <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="MotherEmail" name="MotherEmail" size="40" tabindex="26"   value="" />

									<div class=error id=txtEmailError>*</div>

									<div class=error id=txtEmail1Error>*</div>

								</td> 

		</tr>

			<tr>

							                            	<td class="tbldt">

                                	Father Occupation <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="FatherOccup" name="FatherOccup" size="40" tabindex="27"   value="" />

								</td>  
															                            	<td class="tbldt">

                                	Mother Occupation <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="MotherOccup" name="MotherOccup" size="40" tabindex="28"  value="" />


								</td> 

		</tr>


							<tr>

																                            	

                           	<td class="tbldt">

                                	User Name <span class="manda">*</span>                                     </td>

                                <td class="tbldt" >

                                	<input type="text" id="txtUserName" name="txtUserName" tabindex="29"/>

									<div class=error id=txtUserNameError>*</div>
									

                                </td>
				                           	<td class="tbldt">

                                	Is Admitted ? <span class="manda">*</span>                                     </td>

                                <td class="tbldt" >

                                	<select name="IsAdmitted" id="IsAdmitted" tabindex="30">
				<option value="Y">Yes</option>
          </select>

									



                 

                             

                            </tr>

                            <tr>

							                            	<td class="tbldt">

                                	Password <span class="manda">*</span>                                     </td>

                                <td class="tbldt">

                                	<input type="password" id="txtPwd" name="txtPwd" tabindex="31"/>

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Confirm Password <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="password" id="txtConfirmPwd" name="txtConfirmPwd" tabindex="32"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>

                            </tr>



                             

                            

                            <tr>

                            	<td class="tbldt">

                                	Note                                </td>

                                <td class="tbldt" colspan="3">

                                	<textarea id="txtNote" name="txtNote" tabindex="33" style="width:90%" rows="5"></textarea>                                </td>

                            </tr>

                             <tr>

                             	
                                <td colspan="4" align="center" valign="baseline">

               
<input type="submit" value="Save" name="save" id="save" tabindex="33" style="background-image:url('../Images/btnlarge.gif');width:100px;height:26px;border:0" class="btnbg"/>

                                           </td>

                             </tr>       

                    	</table>
                
				   </form>

            </td>

        </tr>

    </table>



<?php

	  include_once('Includes/Bottom.php');

?>