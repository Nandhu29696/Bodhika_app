<?php require_once('../Lib/Config.php');
include_once('Includes/Top.php');
//include_once('Includes/LeftNav.php');
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
<title>Search</title>
<link href="style.css" rel="stylesheet" type="text/css"/>

<style type="text/css">
<!--
.style1 {
	font-family: Arial, Helvetica, sans-serif;
	font-size: 12px;
	font-weight: bold;
}
-->
</style>
</head>

<body topmargin="0" bottommargin="0" leftmargin="0" rightmargin="0" class="bdyfont">


<table width="1025" border="0" cellpadding="0" cellspacing="0" align="center">
  <tr>
    <td valign="top">
	<form name="f1" action="" method="post">
	
	<table border="0" cellpadding="0" cellspacing="1"  width="100%" bgcolor="#EEEEEE">
    <tr height="24">
        <td class="tblhdr" colspan="4">Search</td> </tr>
   <tr height="24">
        <td width="95" width="25%" class="tbldt">First Name </td>
        <td width="168" width="25%" class="tbldt"><input name="FstNm" SIZE="50" type="text" id="evaluator" /></td>
             <td class="tbldt"  width="25%" >Last Name</td>
        <td class="tbldt" width="25%" ><input name="LstNm" type="text" SIZE="50" id="company" /></td>
      </tr>
      <tr height="24">
        <td class="tbldt" width="25%">Address</td>
        <td class="tbldt" width="25%"><input name="Address" type="text" SIZE="50" id="name" /></td>
            <td class="tbldt" width="25%">Email Id</td>
        <td class="tbldt" width="25%"><input name="email" type="text" SIZE="50" id="email" /></td>
      </tr>
      <tr height="24">
		                            	<td class="tbldt" width="25%">
                                	Role <span class="manda">*</span>                                    </td>
    <td class="tbldt" colspan="3">
	
	<select id='role' name='role' >
						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select RoleId,RoleNm ,RoleDesc  from role ");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['RoleId'];?>"><?php echo $ResultEval['RoleDesc']?></option>";
						<?php }
						?>
                        	</select></td>
		  

		  
      </tr>

  <tr height="24">
     <td colspan="4" align="center"><input name="Search" type="submit" id="Search" value="Search" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>

<input type="button" value="Back" name="Cancel" id="Cancel" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('Index.php');"/>

</td>
      </tr>

         </table>
	</form>

</td>
</tr>
<tr>
<td>

	
	<table width="100%" border="0" cellpadding="0" cellspacing="1">
      <tr height="24">
        <td align="center" class="tblhdr">Search Results </td>
      </tr>
      <tr height="24">
        <td valign="top">
		
		<?php if(isset($_POST['Search']))
	             {/*
				  if(isset($_POST['evaluator']))
				  { 
				  $x=$_POST['evaluator'];
				  $sql=mysql_query("SELECT * FROM t_applicantinfo WHERE Eval_Name LIKE CONVERT( _utf8 '%$x%' USING latin1 ) COLLATE latin1_swedish_ci ");
                 
				  }
				  if(isset($_POST['company']))
				  {
				  				  $sql=mysql_query("SELECT * FROM t_applicantinfo WHERE CompanyName  LIKE CONVERT( _utf8 '%$_POST[company]%' USING latin1 ) COLLATE latin1_swedish_ci
");
				  } 
				 if(isset($_POST['name']))
				  {$z=$_POST['name'];
				  				  $sql=mysql_query("SELECT * FROM t_applicantinfo WHERE UserName LIKE CONVERT( _utf8 '%$z' USING latin1 ) COLLATE latin1_swedish_ci
");
				  } 
if(isset($_POST['email']))
				  {$email=$_POST['email'];
				  				  $sql=mysql_query("SELECT * FROM t_applicantinfo WHERE EMail  LIKE CONVERT( _utf8 '%$email' USING latin1 ) COLLATE latin1_swedish_ci
");
				  } 
				  
				  if(isset($_POST['status']))
				  {$status=$_POST['status'];
				  				  $sql=mysql_query("SELECT * FROM t_applicantinfo WHERE Status ='$status'");
				  } 
				  
				  
				  
				  else
				  {
				echo "No records";  
				  }*/
				  
				  $swhere ="";

				  echo $x=$_POST['FstNm'];
				   echo $y=$_POST['LstNm'];
				  
				  
				 if(isset($_POST['FstNm']))
				  { 
				  $x=$_POST['FstNm'];
				  if($swhere != "")
				  {
				  $swhere=$swhere." and ";
				  }
				  $swhere= " FstName LIKE '%$x%' ";
                 
				  }
				  else
				  {
				  $swhere="";
				  }
				  
				  if(isset($_POST['LstNm']))
				  { 
				  $y=$_POST['LstNm'];
				  if($swhere != "")
				  {
				  $swhere=$swhere." and ";
				  }
				  $swhere.= " LstName LIKE '%$y%'";
                 
				  }
				  else
				  {
				  $swhere="";
				  }
				  
				  if(isset($_POST['Address']))
				  { 
				  $z=$_POST['Address'];
				  if($swhere != "")
				  {
				  $swhere=$swhere." and ";
				  }
				  $swhere.= " Address LIKE '%$z%'";
// $swhere.= " (StudentFstNm LIKE '%$z%' or StudentLstNm LIKE '%$z%')";
                 
				  }
				  else 
				  {
				  $swhere="";
				  }
				  
				  
				  
				  if(isset($_POST['email']))
				  { 
				  $a=$_POST['email'];
				  if($swhere != "")
				  {
				  $swhere=$swhere." and ";
				  }
				  $swhere.= " a.EMail LIKE '%$a%'";
                 
				  }
				  else
				  {
				  $swhere="";
				  }
				  
				  	if(isset($_POST['role']))
				  { 
				  		$b=$_POST['role'];
						
		
				 	 if($swhere != "")
				  	{
				 		 $swhere=$swhere." and ";
				 	 }
					 			 
					   if($b=='select')
					   {
					  //$swhere.= " (Role ='Pending' or Status='Rejected' or Status='Processed') ";
					 $swhere.= "1=1";
					  }
					  else
					  {
					  	$swhere.= " c.RoleId ='$b'";
					  }
                 
				  }
				  else
				  {
				  $swhere="";
				  }
				 // echo $swhere;

				  $swhere.= " and a.LoginName =b.LoginName ";
				  $a1="select * from userinfo a, logininfo b,Role c,userrole d  where a.UserInfoId= d.UserInfoId and d.RoleId = c.RoleId and  ".$swhere;
				//echo $a1;
				  $s=mysql_query($a1,$conn);	  
				/*   if(isset($_POST['company']))
				  {
				  				  $sql=mysql_query("SELECT * FROM t_applicantinfo WHERE CompanyName  LIKE CONVERT( _utf8 '%$_POST[company]%' USING latin1 ) COLLATE latin1_swedish_ci
");
				  } 
				 if(isset($_POST['name']))
				  {$z=$_POST['name'];
				  				  $sql=mysql_query("SELECT * FROM t_applicantinfo WHERE UserName LIKE CONVERT( _utf8 '%$z' USING latin1 ) COLLATE latin1_swedish_ci
");
				  } 
if(isset($_POST['email']))
				  {$email=$_POST['email'];
				  				  $sql=mysql_query("SELECT * FROM t_applicantinfo WHERE EMail  LIKE CONVERT( _utf8 '%$email' USING latin1 ) COLLATE latin1_swedish_ci
");
				  } 
				  
				  if(isset($_POST['status']))
				  {$status=$_POST['status'];
				  				  $sql=mysql_query("SELECT * FROM t_applicantinfo WHERE Status ='$status'");
				  } 
				  */    
				 ?> 
				 
			
				 
		<table width="100%" border="0" align="center" cellpadding="2" cellspacing="1" bgcolor="#DDDDDD">
		
          <tr height="24" class="HeaderStyle">
            <td class="tblsechdr"><span class="style1">UserInfo Id</span></td>
			 <td class="tblsechdr"><span class="style1">User Name</span></td>
			<td class="tblsechdr"><span class="style1">Role</span></td>
		
            <td class="tblsechdr">Email</td>
            <td class="tblsechdr">Address</td>
            <td class="tblsechdr">Mobile</td>
          </tr>  
		  
		  
		  <?php  while( $result=mysql_fetch_array($s))
				  {
$name = "$result[FstName]"." "."$result[MiddleName]"." "."$result[LstName]";
?>
          <tr height="24">
<td class="tblgrd1"><?php echo $result['UserInfoId'];?></td>
		  <td class="tblgrd1"><?php echo $name;?></td>
<td class="tblgrd1"><?php echo $result['RoleDesc'];?></td>
            <td class="tblgrd1"><?php echo $result['EMail'];?></td>
            <td class="tblgrd1"><?php echo $result['Address'];?></td>
            <td class="tblgrd1"><?php echo $result['Mobile'];?></td>

          </tr> <?php }
				  ?>
				  <tr><td><a href="export.php?company=<?echo $y;?>&email=<?php echo $a;?>&status=<?php echo $b;?>&name=<?php echo $z;?>&evaluator=<?php echo $x;?>">Export to MS XL</a></td></tr>
        </table>
		   
				<?php }
	
	     ?>			</td>
      </tr>



    </table>


</td>
  </tr>
</table>
<?php 
     include_once('Includes/Bottom.php');
?>

</body>
</html>
