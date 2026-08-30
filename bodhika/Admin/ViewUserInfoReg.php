<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
$UserName = Auth::currentUser();
include_once('Includes/Top.php');

  $uname=$_GET['uname'];
$rolenm=$_GET['rolename'];
?>
<title>View User Profile</title>
<body topmargin="0" bottommargin="0" leftmargin="0" rightmargin="0" class="bdyfont">
<link href="../CSS/GridStyle.css" rel="stylesheet" type="text/css"/>
<link href="style.css" rel="stylesheet" type="text/css"/>



<?php
//echo $uname;
//echo $rolenm;
//exit;

//$strExeQuery=mysql_query("SELECT * from t_applicant where UserName='$uname'",$conn);
if($rolenm=="STDNT")
$strExeQuery1="SELECT * from studentinfo where StudentFstNm='$uname'";
else
$strExeQuery1="SELECT * from userinfo where LoginName='$uname'";
//else if($rolenm=="Doctor")
//$strExeQuery1="SELECT * from evaluatorinfo where LoginName='$uname'";

//echo $strExeQuery1;

$strExeQuery=mysql_query($strExeQuery1,$conn);

		$Row=mysql_fetch_array($strExeQuery);


if($rolenm=="STDNT")
{
$Email = $Row['EMail']; 
$Name = $Row['StudentFstNm']." " .$Row['StudentMiddleName']." ".$Row['StudentLstNm'];
$UserName = $Row['StudentFstNm'];
$StudentLstNm = $Row['StudentLstNm'];
$offstd = $Row['HomeSTD']; 
$offmain = $Row['Mobile'];
$HomeStd = $Row['HomeSTD'];
$ImageLoc="../Images/photo.gif";
}
else
{
$Email = $Row['EMail']; 
$UserName = $Row['LoginName'];
$Name = $Row['FstName']." " .$Row['MiddleName']." ".$Row['LstName'];
$offstd = $Row['HomeSTD']; 
$offmain = $Row['HomePhone'];
$HomeStd = $Row['HomeSTD'];
$ImageLoc = $Row['ImageLoc'];
}

//$ImageLoc ="../Images/".$ImageLoc;
//echo $ImageLoc;
?>

 <table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">      
 <form name="frmUpdateCompany" action="" method="post">

<tr>
        	
			<td class="lakstoppad1" valign="top">
        
								
						<table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#EEEEEE">
                        	

							<tr>
                            	<td height="24" colspan="4" class="tblhdr">
                                	View User Profile                                <span class="tbldt"></td>
                            </tr>
								
							<tr>
                            	<td width="28%" class="tbldt">
                                	User Name                                </td>
                                <td class="tbldt">
                                	<?php echo $UserName; ?>                                </td>
                                <td width="130" colspan=2 rowspan="5" class="tbldt" align="center"><center><img src='photos/<?php 

$input ="$Name"; 
$output = str_replace(" ","",$input); 


echo "$output"?>.jpg'> </center></td>
							</tr>
														<tr>
                            	<td class="tbldt">
                                	Name                                </td>
                                <td class="tbldt">
                                	<?php echo $Name; ?>                                </td>
                            </tr>

                            <tr>
                            	<td class="tbldt">
                                	Address                                </td>
                                <td class="tbldt">
                                <?php echo $Row['Address'];  ?>                                </td>
                            </tr>
                            <tr>
                            	<td class="tbldt">
                                	Home Phone                                </td>
                                <td class="tbldt">
                                	<?php echo $Row['HomeSTD']; ?>                                                

                                	<?php echo $Row['HomePhone']; ?>                                </td>
                          </tr>
                            <tr>
                            	<td class="tbldt">
                                	Office Phone                                </td>
                                <td class="tbldt">
                                	<?php echo $offstd;?>
                                    <?php echo $offmain;?>                                
                                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Ext&nbsp;&nbsp;&nbsp;&nbsp;                                

                                <?php echo ""; ?>                                </td>
                            </tr>


                            <tr>
                            	<td class="tbldt">
                                Mobile                                </td>
                               <td class="tbldt" colspan="3">
                           <?php echo $Row['Mobile']; ?>                                </td>
                            </tr>
                            <tr>
                            	<td class="tbldt">
                                EMail                                </td>
                                <td class="tbldt" colspan="3">
                                	<?php echo $Email; ?>                                </td>
                            </tr>

                            <tr>
                            	                                <td colspan="4" align="center">
                                     <!-- input name="BtnCancel" type="button" id="Cancel" value="Back" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="history.go(-1);"/ -->
									  <input type="button" value="Back" name="btnCancel" tabindex="15"  style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('Register.php')"/>

									 </td>
                            </tr>
                        </table>                        	
    </td>
        	</tr>

<tr><td height="22"></td></tr>
    	</table>
    <?php 
     include_once('Includes/Bottom.php');
?>
    </form>
</body>