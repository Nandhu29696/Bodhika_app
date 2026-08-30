<?php
	require_once('../Lib/Config.php');
	require_once('../dbconnect.php');
	  require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

	//Include the PS_Pagination class
	include('ps_pagination.php');
	
	//Connect to mysql db
	if($_GET['AppId']!='')
		
		 $_SESSION['id']=$_GET['AppId'];

$EventInfoId = $_GET['AppId'];
		
			
//$id=$_SESSION['id'];
// $d=$_GET['degree'];

		
	//$query1="SELECT * FROM t_applicantcertificates WHERE Application_Id ='$_SESSION[id]' and Degree='$d'";
	$query1="select * from eventinfo a,eventdetails b  where a.EventInfoId=b.EventInfoId and a.EventInfoId=".$EventInfoId." order by b.EventDetailId desc";

	$sql=mysql_query($query1);
	
	//echo $query1;
	//Create a PS_Pagination object
	echo "<table width='100%'>



	<tr><td align='right'>
		<a href='#' onclick='window.close()' style='font-weight:bold;color:blue;'>Close</a><br><br>
	</td></tr>
	<tr>
	<td align='left'><b>File Attachments</b><br><br><br>";
	
	while($row=mysql_fetch_array($sql))
	{
		if($row['Location']!='')
		{
			$filenm = $row['Location'];
			$filenm = strrchr($filenm,"/"); 
			//$filenm = substr_replace($filenm, '/', '');
			//echo $filenm;
			//$filenm =  substr($filenm,0,4) ;

		?>
        <ul><li><a href="<?php echo $row['Location'];?>" target="_blank" style="font-weight:bold" ><?php echo $filenm; ?></a> </li></ul>
        <?php
		}
		
	}
	echo "</td></tr></table>";
	?>
<title>Your Files </title>
