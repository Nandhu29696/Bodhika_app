<?php
	require_once('../Lib/Config.php');
	include_once('../dbconnect.php');
	//print "DELETE * FROM eventdetails WHERE EventDetailId='$_GET[Id]'";
	$Result=mysql_query("DELETE FROM eventdetails WHERE EventDetailId=$_GET[Id]",$conn) or die(mysql_error());
	print "$Result";
	if($Result==1)
		header("location:EditFiles.php?AppId=$_GET[AppId]");
?>
<title>Deleting</title>