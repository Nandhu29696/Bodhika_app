<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');
//include_once('Includes/LeftNav.php');
 
 include_once("ps_pagination.php");
if(isset($_POST['yes'])){
 $lid=$_GET['id'];
 
$q1="update logininfo set Active='Y' where LoginInfoId='$lid'";
$abc=mysql_query($q1,$conn) or die(mysql_error());
if(isset($abc))
{
$b=mysql_query("select * from t_login where LoginId='$lid'");
$r=mysql_fetch_array($b);
$pass=$r['Password'];
$name=$r['LoginName'];
$email=$r['Email'];

				
}
}

if(isset($_POST['no'])){
$lid=$_GET['id'];
$q2="update logininfo set Active='N' where LoginInfoId='$lid'";
mysql_query($q2,$conn) or die(mysql_error());
}
?>
<title>User's List</title>
		<!-- link href="../CSS/GridStyle.css" rel="stylesheet" type="text/css"/ -->

		<table width="1025" border="0" cellpadding="0" cellspacing="0" align="center">
  <tr>
    <td valign="top">
	<form name="f1" action="" method="post">
	
	<table border="0" cellpadding="0" cellspacing="1"  width="100%" bgcolor="#DDDDDD">
    <tr height="24">
        <td class="tblhdr" colspan="4">Search</td> </tr>

      <tr height="24">
        <td class="tbldt" width="20%">Name</td>
        <td class="tbldt" width="30%"><input name="name" type="text" id="name" /></td>
        <td class="tbldt" width="20%">AdmittedInd?</td>
        <td class="tbldt"  width="30%"><select name="active" id="active">
				<option value="Y">Yes</option>
				<option value="N">No</option>
          </select>
      </tr>

  <tr height="24">
     <td colspan="4" align="center"><input name="Search" type="submit" id="Search" value="Search" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>

<input type="button" value="Back" name="Cancel" id="Cancel" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('reports.php');"/>

</td>
      </tr>

         </table>



	<table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">

		  <?php 

	 // $strExeQuery="select * from LOGININFO where Role ='STDNT' order by LoginInfoId desc";
	  				  $strExeQuery="SELECT * FROM studentinfo   WHERE AdmittedInd='N'";
					 // echo  $strExeQuery;

  		 // $strExeQuery="select * from LOGININFO where Role ='Admin' order by LoginInfoId asc";


		  if(isset($_POST['Search']))
		  {

				if(isset($_POST['name']))
				  {
			  $active=$_POST['active'];	
	$z=$_POST['name'];
				  //
				  
				  //$strExeQuery="SELECT * FROM LOGININFO WHERE Active='$active' and LoginName LIKE '%$z%'and not role='Admin'";
				  $strExeQuery="SELECT * FROM studentinfo   WHERE AdmittedInd='N' and StudentLstNm LIKE '%$z%'";
				  // $sql=mysql_query("SELECT * FROM t_applicantinfo WHERE UserName LIKE CONVERT( _utf8 '%$z' USING latin1 ) COLLATE latin1_swedish_ci");
				  } 
				   

		}

//echo $strExeQuery;
				//$strExeQuery="select * from t_login  order by LoginId asc";
				$pager = new PS_Pagination($conn,$strExeQuery,15,4);
				$rs = $pager->paginate();
				if(mysql_num_rows(mysql_query($strExeQuery))!=0)
				{
			?>
        	<tr>
	
			<td class="lakstoppad1" valign="top">
<form name="OrgApp" id="OrgApp" method="post" action="">
	                      	<table border="0" cellpadding="3" cellspacing="1" width="100%" bgcolor="#DDDDDD">
								<tr>
                            	<td class="tblhdr" colspan="8" height="22">
                                	Applicant's List
								</td>
                               
                            </tr>

                		<tr class="HeaderStyle" height="22">
                    	<td align=" " width="20%">
                        	Student Name    </td>
		              	<td align=""  width="15%">
                        	Home Phone    </td>
                        
                        <td align=" "  width="30%">
                        	Email
                        </td>
               <td align="center"  width="10%">
                        	Admitted?
                        </td>

	<td align="center"  width="10%">
                        	View Details
                        </td>
         
						<td align="center">
                        	
                        </td>
						<!-- td align="center">
                        	
                        </td-->
                    </tr>
        <?php
					if(mysql_num_rows(mysql_query($strExeQuery))>15)
					{
						echo "<center> ";
						echo "<br/>".$pager->renderFullNav()."<br/>"; 
						echo"</center>";
						echo "<br>";
					}
					$iCount=0;
					while($Result=mysql_fetch_array($rs))
					{
					$name=$Result['StudentFstNm']." ".$Result['StudentLstNm'];
					$HomePhone=$Result['HomePhone'];
					$email=$Result['EMail'];
					$id=$Result['StudentInfoId'];
					$active=$Result['AdmittedInd'];
		  
		 // print_r($result);
	
		  ?>				
                      <tr class="<?php
										if($iCount==0)
										{
											echo "RowStyle";
											$iCount=1;
										}
										else
										{
											echo "AlternateRowStytle";
											$iCount=0;
										}
											?>" > 
		

                    	<td align="">
                        	<?php echo $name; ?>
                        </td>
			                    	<td align="">
                        	<?php echo $HomePhone; ?>
                        </td>
                             <td align="">
                        	<?php echo $email; ?>
                        </td>
						<td align="center">
                        	<?php echo $active; ?>
		 </td>

                 <td align="center">
                        	<a href="ViewUserInfo.php?uname=<?php print $Result['StudentInfoId'];?>" class='bodynav'>View </a> <span class="style1">


							<a href="EditStudent.php?uname=<?php print $Result['StudentInfoId'];?>" class='bodynav'>Edit </a> <span class="style1">
                        </td>


		
<?php if($active=="yes")
		{	
		?>
		 <form action="active.php?id=<?php echo $id; ?>" method="post" name="no">
			 <td align="center">
                        	<input type="submit"  style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"name="no" value="Inactive">



</td></form><?php 
} 

else{
?>
						
						<form action="active.php?id=<?php echo $id; ?>" method="post" name="yes">
			 <td align="center">
                        	<input type="submit" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"name="yes" value="Admit">
                        	
                        </td></form>
						<?php }?>
					</tr>

                    <?php
						}
					
					?>
			         
             
			 </table>
             </form>
			 <?php
			 	}
			 	else
				{
						echo "
								<table width=100% align='center'>
									<tr>
										<td align='center'><fieldset style='width:80%' class='lakstoppad'>
            	<legend style='color:#000099'><b>Organizing Applications</b></legend>
				No Records Found
									</fieldset>
										</td>
									</tr>
								</table>
								
							  ";
				}
			 ?>
           
             </td>
             </tr>
			 
            </table>
<?php
include_once('Includes/Bottom.php');
?>
	
	
     
</body>
</html>
