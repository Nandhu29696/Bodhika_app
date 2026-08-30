<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');

 	if(isset($_POST['btnRegister']))
	{

 $uname=$_POST['txtUserName'];
 $selectdata = "SELECT * FROM logininfo where Role='User' and LoginName='$uname'";

       $qry1 = mysql_query($selectdata);
 $n=mysql_num_rows($qry1);


         if($n>0)
		 {
	        echo "Please select other Username";
	
		} 
		else{
		

   $query="insert into studentinfo (StudentUniqueId,StudentFstNm,StudentMiddleName,StudentLstNm,Gender,DOB,DateOfAdmission,AdmnForYear,AdmnForGrade,EMail,HomeSTD,HomePhone,Mobile,Address,City,State,Country,PIN,FathersNm,MothersNm,GardianNm,FatherContNum,MotherContNum,GardianContNum,Note) values (1,'$_POST[txtFstName]','$_POST[txtMidName]','$_POST[txtLstName]','FeMale','$_POST[txtDOB]','$_POST[txtAdmnDate]','$_POST[txtAdmnYear]',	'$_POST[txtAdmnGrade]','$_POST[txtEmail]','$_POST[txtHomeSTD]','$_POST[txtPhone]','$_POST[txtMobile]','$_POST[txtAddress]','$_POST[txtCity]','$_POST[txtState]','$_POST[txtCountry]','$_POST[txtPIN]','$_POST[txtFName]','$_POST[txtFCont]','$_POST[txtMName]','$_POST[txtMCont]','$_POST[txtGName]','$_POST[txtGCont]','$_POST[txtNote]')";


	$qry=mysql_query($query,$conn);



	//$query="insert into studentdetails (StudentInfoId,SectionInfoId,GradeInfoId,UserInfoId,CurrYear,PassYear,Status) values (1,'$_POST[txtFstName]','$_POST[txtMidName]','$_POST[txtLstName]','FeMale','$_POST[txtDOB]','$_POST[txtAdmnDate]','$_POST[txtAdmnYear]',	'$_POST[txtAdmnGrade]','$_POST[txtEmail]','$_POST[txtHomeSTD]','$_POST[txtPhone]','$_POST[txtMobile]','$_POST[txtAddress]','$_POST[txtCity]','$_POST[txtState]','$_POST[txtCountry]','$_POST[txtPIN]','$_POST[txtFName]','$_POST[txtFCont]','$_POST[txtMName]','$_POST[txtMCont]','$_POST[txtGName]','$_POST[txtGCont]','$_POST[txtNote]')";


	//$qry=mysql_query($query,$conn);

echo $query;

		$res=mysql_query("insert into logininfo (LoginName,Password,Role,Email,Active) values ('$_POST[txtUserName]','$_POST[txtPwd]','User','$_POST[txtEmail]','Y')",$conn) ;
if(isset($qry)&&isset($res))
{
  echo 'Successfully Registered.<br>';

  
 $to=$_POST['txtEmail'];
 $subject = "Welcome New User- Hansel";

 $message = "<html><body><table  border='0' width='820px' cellspacing='0' cellpadding='0'>";
 $message.="<tr><td align='left' valign='top' style='padding-left:50px; padding-right:20px; font-family:Arial, Helvetica, sans-serif; font-size:12px'>";

 $message.="Dear    ". $_POST['txtUserName'].",";
  $message.= "<br/><br/><br/><br/>";
   $message.= "Thank you for choosing Hudson  for your credential evaluations. Please click the below link to start your evaluation.<br/><br/>";

   $message.= "Your account information is as follows:";
  $message.= "<br/><br/><strong>Your Username :</strong>&nbsp;&nbsp;".$_POST['txtUserName'];
  $message.= "<br /><strong>Your Password :</strong>&nbsp;&nbsp;".$_POST['txtPwd'];
  $message.= "<br/><br/>To activate your account please click the below link.<a href='http://ees.hudsoneval.com/index.php'>Click here</a>";

  $message.= "<br/><br/>";
$message.="<br/><br/>If you have any questions please feel free to call us or email any time.<br/><br/><br/><br/><br/><br/>Thanks  and Regards,<br/><br/>Vijaya Rao<br/>Hudson Credential Evaluations LLC";

$message.="<br/>P.O.BOX  974 ,Marysville,OH - 43040<br/>Phone: 937-738-7543<br/>Fax: 216-916-0979<br/>";

$message.="Email: pravinsalunkhe@hotmail.com<br/>";
$message.="URL: www.hansel.com";

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
	

// saving the user 

if(isset($_POST['save']))
	{
$uname=$_POST['txtUserName'];
$selectdata = "SELECT * FROM logininfo where Role='User' and LoginName='$uname'";
         $qry2 = mysql_query($selectdata);
$n1=mysql_num_row($qry2);
         if($n1>0)
		 {
	        echo "Please select other Username";
	
		} 
		else
{			
					$query="insert into studentinfo (StudentUniqueId,StudentFstNm,StudentMiddleName,StudentLstNm,DOB,DateOfAdmission,AdmnForYear,AdmnForGrade,EMail,HomeSTD,HomePhone,Mobile,Address,City,State,Country,PIN,FathersNm,MothersNm,GardianNm,FatherContNum,MotherContNum,GardianContNum,Note) values (1,'$_POST[txtFstName]','$_POST[txtMidName]','$_POST[txtLstName]','$_POST[txtDOB]','$_POST[txtAdmnDate]','$_POST[txtAdmnYear]',	'$_POST[txtAdmnGrade]','$_POST[txtEmail]','$_POST[txtHomeSTD]','$_POST[txtPhone]','$_POST[txtMobile]','$_POST[txtAddress]','$_POST[txtCity]','$_POST[txtState]','$_POST[txtCountry]','$_POST[txtPIN]','$_POST[txtFName]','$_POST[txtFCont]','$_POST[txtMName]','$_POST[txtMCont]','$_POST[txtGName]','$_POST[txtGCont]','$_POST[txtAdmnDate]','$_POST[txtNote]')";

	$qry=mysql_query($query,$conn);


		$res=mysql_query("insert into logininfo (LoginName,Password,Role,Email,Active) values ('$_POST[txtUserName]','$_POST[txtPwd]','User','$_POST[txtEmail]','N')",$conn) ;
if(isset($qry)&&isset($res))
{
  echo 'Successfully Saved.<br>';
}
else 
{
echo "Not Saved";
}
}
}
?>
<html>
<head>

<title>Adding User Login Information</title>

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

if(document.frmAddUser.txtFstName.value == "")
       {
               alert("Please enter the User Name");
               document.frmAddUser.txtFstName.focus();
               return false;
       }
if(document.frmAddUser.txtMidName.value == "")
       {
               alert("Please enter the User Name");
               document.frmAddUser.txtMidName.focus();
               return false;
       }

if(document.frmAddUser.txtLstName.value == "")
       {
               alert("Please enter the User Name");
               document.frmAddUser.txtLstName.focus();
               return false;
       }

if(document.frmAddUser.txtPwd.value == "")
       {
               alert("Please enter the Password");
               document.frmAddUser.txtPwd.focus();
               return false;
       }
if(document.frmAddUser.txtConfirmPwd.value == "")
       {
               alert("Please enter the Confirm Password");
               document.frmAddUser.txtConfirmPwd.focus();
               return false;
       }


if(document.frmAddUser.txtConfirmPwd.value !=document.frmAddUser.txtPwd.value)
       {
               alert("Please enter the Same Password ");
               document.frmAddUser.txtConfirmPwd.focus();
               return false;
       }
	   if(document.frmAddUser.txtName.value == "")
       {
               alert("Please enter the Name");
               document.frmAddUser.txtName.focus();
               return false;
       }
	   
	   if(document.frmAddUser.txtEmail.value == "")
       {
               alert("Please enter the E-mail");
               document.frmAddUser.txtEmail.focus();
               return false;
       }
	   
//	   var emailID=document.frmApplicationForm.txtEMail

	   var emailID=document.frmAddUser.txtEmail
	
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

	   
	   if(document.frmAddUser.txtAddress.value == "")
       {
               alert("Please enter the Address");
               document.frmAddUser.txtAddress.focus();
               return false;
       }
	   
	    if(document.frmAddUser.txtHomeSTD.value == "")
       {
               alert("Please enter the STD CODE");
               document.frmAddUser.txtHomeSTD.focus();
               return false;
       } 
	    if(document.frmAddUser.txtHomeSTD.value == "")
       {
               alert("Please enter the STD CODE");
               document.frmAddUser.txtHomeSTD.focus();
               return false;
       } 
	   
	     if(document.frmAddUser.txtMobile.value == "")
       {
               alert("Please enter the Mobile  Number");
               document.frmAddUser.txtMobile.focus();
               return false;
       } 
	   
  
	   if(document.frmAddUser.txtMobile.value == "")
       {
               alert("Please enter the Mobile Number");
               document.frmAddUser.txtMobile.focus();
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

            		<form name="frmAddUser" action="" method="post" onSubmit="return checkForm2();">

                    	

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

									<input type="text" id="txtFstName" name="txtFstName" size="30" tabindex="4" size="35"/> 

									<div class=error id=txtNameError>*</div>

									</td>
					 	<td class="tbldt"  width="20%">

                                	Middle Name <span class="manda">*</span>                                </td>

                                <td class="tbldt"  width="30%">

									<input type="text" id="txMidName" name="txtMidName" size="30" tabindex="4" size="35"/> 

									<div class=error id=txtNameError>*</div>

									</td>

                            </tr>
							
							<tr>

<td class="tbldt">
Last Name <span class="manda">*</span>
</td>
<td class="tbldt">
<input type="text" id="txtLstName" name="txtLstName" size="30" tabindex="4" size="35"/> 
<div class=error id=txtNameError>*</div>
</td>
<td class="tbldt">
Admission Date <span class="manda">*</span>  
</td>
<td class="tbldt">
<input type="text" id="txtAdmnDate" name="txtAdmnDate" tabindex="3"/>
<div class=error id=txtConfirmPwdError>*</div>
</td>
                            </tr>


							               <tr>

							                            	<td class="tbldt">

                                	DOB <span class="manda">*</span>                                     </td>

                                <td class="tbldt">

                                	<input type="text" id="txtDOB" name="txtDOB" tabindex="2"/>

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Admission For Year <span class="manda">*</span>                                     </td>

                                <td class="tbldt">

                                	<input type="text" id="txtAdmnYear" name="txtAdmnYear" tabindex="2"/>

									<div class=error id=txtPwdError>*</div>

								 </td>

                            </tr>

				<tr>

							                            	<td class="tbldt">

                                	EMail <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtEmail" name="txtEmail" size="50" tabindex="5"  size="35"/>

									<div class=error id=txtEmailError>*</div>

									<div class=error id=txtEmail1Error>*</div>

								</td>    

                            	<td class="tbldt">

                                	Admission For Grade <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtAdmnGrade" name="txtAdmnGrade" tabindex="3"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>

                            </tr>



                            <tr>

                            	<td class="tbldt">

                                	Address                                </td>

                                <td class="tbldt">

                                	<textarea id="txtAddress" name="txtAddress" tabindex="6" style="width:90%" rows="5"></textarea>                                </td>   
								
								
                            	<td class="tbldt">

                               	Home Phone <span class="manda">*</span>                               </td>

                                <td class="tbldt">

                                    <input name="txtHomeSTD" type="text"  id="txtHomeSTD" tabindex="7" size="4" maxlength="7" />

									<div class=error id=txtHomeSTDError>*</div>

									<div class=error id=txtHomeSTD1Error>*</div>

                                    <input type="text" id="txtPhone" name="txtPhone" size="10" maxlength="10"  tabindex="8"/> 

									<div class=error id=txtPhoneError>*</div>

									<div class=error id=txtPhone1Error>*</div>

								 </td>

                            </tr>

                             <tr>

                            	

									                            	<td class="tbldt">

                                	City <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtCity" name="txtCity" tabindex="3"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>

<td class="tbldt">

                                	State <span class="manda">*</span>                                     </td>

                                <td class="tbldt">

                                	<input type="text" id="txtState" name="txtState" tabindex="2"/>

									<div class=error id=txtPwdError>*</div>

								 </td>


                            </tr>

							


							<tr>

							                            	

                            	<td class="tbldt">

                                	Country <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtCountry" name="txtCountry" tabindex="3"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>

<td class="tbldt">

                                	PIN <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtPIN" name="txtPIN" tabindex="3"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>


                            </tr>


                           <tr>


							                            	<td class="tbldt">

                                	Father's Name <span class="manda">*</span>                                     </td>

                                <td class="tbldt">

                                	<input type="text" id="txtFName" name="txtFName" tabindex="2"/>

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Father's Contact# <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtFCont" name="txtFCont" tabindex="3"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>
                                

                              </tr>
							                             <tr>


							                            	<td class="tbldt">

                                	Mother's Name <span class="manda">*</span>                                     </td>

                                <td class="tbldt">

                                	<input type="text" id="txtMName" name="txtMName" tabindex="2"/>

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Mother's Contact# <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtMCont" name="txtMCont" tabindex="3"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>
                                

                              </tr>
							                             <tr>


							                            	<td class="tbldt">

                                	Gardian's Name <span class="manda">*</span>                                     </td>

                                <td class="tbldt">

                                	<input type="text" id="txtGName" name="txtGName" tabindex="2"/>

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Gardian's Contact# <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="text" id="txtGCont" name="txtGCont" tabindex="3"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>
                                

                              </tr>


                            	<td class="tbldt">

                                	Mobile <span class="manda">*</span>                                    </td>

                                <td class="tbldt" colspan="3">

                                <input name="txtMobile" type="text" id="txtMobile" tabindex="12" size="10" maxlength="10"/> 

								<div class=error id=txtMobileError>*</div> 

								<div class=error id=txtMobile1Error>*</div>                              </td>

                            </tr>


							<tr>

																                            	

                           	<td class="tbldt">

                                	User Name <span class="manda">*</span>                                     </td>

                                <td class="tbldt" colspan="3">

                                	<input type="text" id="txtUserName" name="txtUserName" tabindex="1"/>

									<div class=error id=txtUserNameError>*</div>
									

                                </td>

                 

                             

                            </tr>

                            <tr>

							                            	<td class="tbldt">

                                	Password <span class="manda">*</span>                                     </td>

                                <td class="tbldt">

                                	<input type="password" id="txtPwd" name="txtPwd" tabindex="2"/>

									<div class=error id=txtPwdError>*</div>

								 </td>

                            	<td class="tbldt">

                                	Confirm Password <span class="manda">*</span>                                    </td>

                                <td class="tbldt">

                                    <input type="password" id="txtConfirmPwd" name="txtConfirmPwd" tabindex="3"/>                                <div class=error id=txtConfirmPwdError>*</div>

									</td>

                            </tr>



                             

                            

                            <tr>

                            	<td class="tbldt">

                                	Note                                </td>

                                <td class="tbldt" colspan="3">

                                	<textarea id="txtNote" name="txtNote" tabindex="13" style="width:90%" rows="5"></textarea>                                </td>

                            </tr>

                             <tr>

                             	
                                <td colspan="4" align="center" valign="baseline">

                                	<input type="submit" value="Register" name="btnRegister" id="btnRegister" tabindex="14" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>
                                    <input type="reset" value="Clear" tabindex="15" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>
<input type="submit" value="Save" name="save" id="save" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>

<input type="button" value="Back" name="Back" id="Cancel" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="history.go(-1);"/>
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