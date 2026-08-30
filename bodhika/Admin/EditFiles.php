<?php
	require_once('../Lib/Config.php');
	include_once('../dbconnect.php');

	$date=date('y-m-d');
	$today=date('m').date('d').date('Y'); 

	
	$EventInfoId = $_GET['AppId'];
		
			
	$query1="select * from eventinfo a,eventdetails b  where a.EventInfoId=b.EventInfoId and a.EventInfoId=".$EventInfoId." order by b.EventDetailId desc";
	//Create a PS_Pagination object

	$sql=mysql_query($query1);


	//echo $sql;
	echo "<table width='100%'>
	<tr height='24px'>
	<td align='left' class='tblhdr'><b>File Attachments</b></td>
</tr>	
<tr>
	<td class='tbldt'><br>";
	
	while($row=mysql_fetch_array($sql))
	{
		if($row['Location']!='')
		{
			$filenm = $row['Location'];
			$filenm = strrchr($filenm,"/"); 
			$Location = chmod(($row['Location']),0755);
//$row['Location']
		?>
        <ul><li><a class='bodynav' href="<?php echo $row['Location']; ?>" target="_blank" style="font-weight:bold" ><?php echo $filenm; ?> </a> &nbsp; &nbsp;  <a class='bodynav'  href="RemoveFile.php?Id=<?php echo $row['EventDetailId'];?>&&AppId=<?php echo $row['EventInfoId'];?>" onClick="return confirm('Are You Sure ! Do You Want To Delete')">Remove</a> </li></ul>  
        <?php

		}
		
	}
	echo "</td></tr></table>";
	?>
    <title>Edit Your Files</title>
<link type="text/css" rel="stylesheet" href="style.css" />
<body topmargin="0" bottommargin="0" leftmargin="0" rightmargin="0" class="bdyfont">

	<?php
		if(isset($_POST['btnUpload']))
		{
			
				@$tmp_name = $_FILES["attachments1"]["tmp_name"];
		        @$name = $_FILES["attachments1"]["name"];

				$txtName1=$_POST['txtName1'];
//echo "name: ".$name;
//echo "txtName1: ".$txtName1;
				
				//$dir="../Certificates/".$_GET['AppId']."/"."$degree1";
				//$path="$dir"."/"."$name";
				//$dir="../Attachments/$today";
				$dir="Att/$today";
if(!file_exists($dir))
	mkdir($dir) or die("Filename all ready exits");

				$dir1="$dir"."/"."$EventInfoId";
				$Path="$dir1"."/"."$name";

				echo "dir1: ".$dir1;
				//echo "Path: ".$Path; 
				
				
			if(!file_exists($dir1))
			{
				//mkdir($dir1) or die;
				mkdir($dir1) or die("Filename all ready exits");

//echo "dir1111: ".$dir1;
//exit;
			}
					
				//@move_uploaded_file($tmp_name, "$dir1"."/"."$name");

				//chmod("test.txt",0755);
				chmod(@move_uploaded_file($tmp_name, "$dir1"."/"."$name"),0755);


				
				//move_uploaded_file($tmp_name,$Path);
				
				if($name!='')
				{
					$sql1="insert into  eventdetails  (EventInfoId,Location,Description) values('$EventInfoId','$Path','$txtName1')";
					mysql_query($sql1,$conn)or die(mysql_error());
					header("Location:EditFiles.php?AppId=$EventInfoId");
				}


			//	$abc=mysql_query("insert into t_applicantcertificates (Application_Id,Path,Certificate_Img,Degree) values ('$_GET[AppId]','$path','$name','$degree1')",$conn) or die(mysql_error());

		//if(isset($abc))
		//{
		//header("Location:EditFiles.php?AppId=$EventInfoId");
		//}
		
			
				
			    
		}
	?>
    <form name="frmEditCertificates" action="" method="post" enctype="multipart/form-data">
    	 <table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#EEEEEE">
		 <tr><td width="25%" class="tbldt">Name
</td>
<td width="25%" class="tbldt" valign="top">
<input  name="txtName1" type="text" id="txtName1" tabindex="20" size="20" /><span class="style1">*</span></td>

                    	<tr>
                        	<td class="tbldt">
                            	Upload File                            </td>
                            <td class="tbldt">
								<input type="file" name="attachments1" id="fileUpload1" tabindex="1"/>                            </td>
                        </tr>
                                            
                        <tr>
                        	<td></td>
                            <td align="left">
                            	<input type="submit" name="btnUpload"  value="Upload" tabindex="11" style="background-image:url('../Images/btnsmall.gif');width:85px;height:26px;border:0" class="btnbg" />    
<input type="button" name="btnclose"  value="Close" style="background-image:url('../Images/btnsmall.gif');width:85px;height:26px;border:0" class="btnbg" onclick='window.close()'  /> 
                        </td>
                        </tr>
                    </table>
    
    </form>
</body>    
