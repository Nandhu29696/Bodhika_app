<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');
	
?>
<title>Sending your documents</title>
<script type="text/javascript">
	function CheckAll()
	{
		if(document.getElementById("cbxAll").checked)
		{
			document.getElementById("cbxApplInfo").checked=false;
			document.getElementById("cbxCertificates").checked=false;
			document.getElementById("cbxEvalDoc").checked=false;
		}
			
	}
	function CheckApplInfo()
	{
		if(document.getElementById("cbxApplInfo").checked)
			document.getElementById("cbxAll").checked=false;
	}
	function CheckCertificates()
	{
		if(document.getElementById("cbxCertificates").checked)
			document.getElementById("cbxAll").checked=false;
	}
	function CheckEvalDoc()
	{
		if(document.getElementById("cbxEvalDoc").checked)
			document.getElementById("cbxAll").checked=false;
	}

	function sentmail()
	{
	location.href('sendemail.php');
	}

</script>

		
		
		
		<table width="1025" border="0" cellpadding="0" cellspacing="0" align="center">
  <tr>
    <td valign="top">
	<form name="frmEMail" id="frmEMail">
	
	<table border="0" cellpadding="0" cellspacing="1"  width="100%" bgcolor="#DDDDDD">
<tr>
                        	<td class="tblhdr" colspan="4" height="24">
                            	Sending EMail to Student's
                            </td>
                        </tr>
<tr>
<td colspan="4" align="left" height="22">
</td>
</tr>

<tr>
<td align="left" valign="top">
Subject
</td>
<td align="left" valign="top">
<textarea style="width:70%;" rows="10"></textarea>
</td>
</tr>
<tr>
<td align="left" width="25%" valign="top">
Mail Sending Criteria
</td>
<td align="left" colspan="2">

<input type="radio" name="cbxCertificates" id="cbxCertificates" tabindex="3" onclick="CheckCertificates()" />Student Wise <input type="text" name="txtEMail" id="txtEMail" size="30" tabindex="1" /><br><br>


<input type="radio" name="cbxCertificates" id="cbxCertificates" tabindex="3" onclick="CheckCertificates()" />Class Wise

<input type="checkbox" name="" id=""/>I
<input type="checkbox" name="" id=""/>II
<input type="checkbox" name="" id=""/>III
<input type="checkbox" name="" id=""/>IV
<input type="checkbox" name="" id=""/>VI
<input type="checkbox" name="" id=""/>VI
<input type="checkbox" name="" id=""/>VII
<input type="checkbox" name="" id=""/>VIII
<input type="checkbox" name="" id=""/>IX
<input type="checkbox" name="" id=""/>X
<br><br>

<input type="radio" name="cbxAll" id="cbxAll" tabindex="5" onclick="CheckAll()" />All<br><br>


</td>
</tr>

<tr>
<td width="22">&nbsp;</td>
</tr>
<tr>
<td>

</td>
<td align="left">
<input type="button" name="btnSend" id="btnSend" value="Send" tabindex="6"  onclick="sentmail(
);" style="background-image:url('../Images/btnsmall.gif');width:85px;height:26px;border:0" class="btnbg" />
</td>
</tr>
</table>


		
		
		
		
		
	</form>
     <?php
	//session_destroy();
      include_once('Includes/Bottom.php');
?>

