<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');
?>
<title>Successfully Completed</title>
<body topmargin="0" bottommargin="0" leftmargin="0" rightmargin="0" class="bdyfont">
<?php 

$date=date('y-m-d');

$today=date('m').date('d').date('Y'); 

$id =$_GET['id'];
$active =$_GET['active'];
if($active=="Y")
$active="N";
else if($active=="N")
$active="Y";

$RoleId =$_GET['roleid'];
$UserInfoId =$_GET['UserInfoId'];


echo $RoleId;
echo "active  ".$active;
//exit;
$a="select * from userrole where UserInfoId=".$UserInfoId;


$strExeQuery=mysql_query($a,$conn);

if(mysql_num_rows($strExeQuery)>0)
{


		$upduserrole="update userrole set Active='".$active."' where UserInfoId =".$UserInfoId." and RoleId=".$RoleId;
		echo $upduserrole;
		//exit;


		$Qryupduserrole=mysql_query($upduserrole,$conn) or die(mysql_error());
}
else
{
			$abc="update logininfo set Active='".$active."' where LoginInfoId =".$id;

		//echo $abc;
		$InsQryActivity=mysql_query($abc,$conn) or die(mysql_error());

		$upduserrole="update userrole set Active='".$active."' where UserInfoId =".$UserInfoId;
		//echo $upduserrole;
		//exit;
		$Qryupduserrole=mysql_query($upduserrole,$conn) or die(mysql_error());
}



header("Location:active.php");

                 
?>