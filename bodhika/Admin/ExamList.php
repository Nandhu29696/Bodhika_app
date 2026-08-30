<?php
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
$role = $_SESSION['Role'];

$pos = strpos($role,"TEACH");
if($role=="Admin" or $role=="PRCIPAL" or $pos>0)
{
include_once('Includes/Top.php');
}
else if($role=="STDNT")
{
include_once('Includes/StudentTop.php');
}
 
 include_once("ps_pagination.php");

?>
<title>Library Information</title>
		<!-- link href="../CSS/GridStyle.css" rel="stylesheet" type="text/css"/ -->



	<table border="0" cellpadding="0" cellspacing="0" width="1025" align="center">
<form name="frmOrgApp" action="" method="post">

		<table width="1025" border="0" cellpadding="0" cellspacing="0" align="center">
  <tr>
    <td valign="top">
	<form name="f1" action="" method="post">
	
	<table border="0" cellpadding="0" cellspacing="1"  width="100%" bgcolor="#DDDDDD">
    <tr height="24">
        <td class="tblhdr" colspan="4">Search</td> </tr>

      <tr height="24">
        <td class="tbldt" width="20%">Book/CD Id</td>
        <td class="tbldt" width="30%">	
		
		<select id='LIBTYPE' name='LIBTYPE'>
						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select CodeValue,CodeDesc,OrderNum  from codevalue WHERE CodeType ='LIBTYP' order by OrderNum");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['CodeValue'];?>"><?php echo $ResultEval['CodeDesc']?></option>";
						<?php }
						?>
                        	</select>
							
							<input name="bookId" type="text" id="bookId" /></td>
		                            	<td class="tbldt">

                                	Grade <span class="manda">*</span>    
									</td>



			<td class="tbldt">	

<?php

	//$GradeInfoId = $Row123['AdmnForGrade'];
	$SectionInfoId = 0;
	$BusInfoId=0;



$query_disp="select GradeInfoId,GradeName from gradeinfo";
$result_disp = mysql_query($query_disp, $conn);

?>

	<select id="txtGrade" name="txtGrade" >

						   <option value='0' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select GradeInfoId,GradeName from gradeinfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['GradeInfoId'];?>"><?php echo $ResultEval['GradeName']?></option>";
						<?php }

						?>
                        	</select>

		<?php
$query_disp="select SectionInfoId,SectionName  from sectioninfo";
$result_disp = mysql_query($query_disp, $conn);

?>

	<select id="txtSection" name="txtSection" >

						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select SectionInfoId,SectionName  from sectioninfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['SectionInfoId'];?>"><?php echo $ResultEval['SectionName']?></option>";
						<?php }
						?>
                        	</select>
							



								
									<div class=error id=txtConfirmPwdError>*</div>

									</td>
</tr>

      <tr height="24">
        <td class="tbldt" width="20%">Book/CD Name</td>
        <td class="tbldt" width="30%"><input name="bookname" type="text" id="bookname" /></td>

        <td class="tbldt" width="20%">Category</td>
            <td class="tbldt">

	
	<select id='catg' name='catg' >
						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select CodeValue,CodeDesc,OrderNum  from codevalue WHERE CodeType ='BOOKCATG' AND Active ='Y' order by OrderNum");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['CodeValue'];?>"><?php echo $ResultEval['CodeDesc']?></option>";
						<?php }
						?>
                        	</select>
							</td>
     
	      </tr>


		  
		 <tr height="24">
<td class="tbldt">

                                	Subject <span class="manda">*</span>                                     </td>

								 <td class="tbldt">

			<select id='subject' name='subject' >
						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select SubjectInfoId,SubjectName from subjectinfo");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['SubjectInfoId'];?>"><?php echo $ResultEval['SubjectName']?></option>";
						<?php }
						?>
                        	</select>

 </select>
</td>
        <td class="tbldt" width="20%">Allotted</td>
        <td class="tbldt">
	
	<select id='allotted' name='allotted' >
						   <option value='select' selected='selected'>Select</option>
						<?php
							$strExeQueryEval=mysql_query("select CodeValue,CodeDesc,OrderNum  from codevalue WHERE CodeType ='ActiveCd' order by OrderNum");
							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{?>  
								<option value="<?php echo $ResultEval['CodeValue'];?>"><?php echo $ResultEval['CodeDesc']?></option>";
						<?php }
						?>
                        	</select>
							</td>      

     
	      </tr>


	 <tr height="24">

     <td colspan="4" align="center">
	 
	   <input name="BtnAddNew" type="button" id="Cancel" value="Add New" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('AddEditClassLibBookInfo.php?InfoId=0')"/>

 <input name="Search" type="submit" id="Search" value="Search" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>

<input type="button" value="Back" name="Cancel" id="Cancel" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="history.go(-1);"/>

</td>
      </tr>

		 </table>
	
		  <?php 


		  if(isset($_POST['Search']))
		  {

	
			  $allotted=$_POST['allotted'];	
				 $publname=$_POST['publname']; 
			 $bookname=$_POST['bookname'];
			  $bookId=$_POST['bookId'];
			   $Section=$_POST['txtSection'];
			    $Grade=$_POST['txtGrade'];
				 $catg=$_POST['catg'];
				 $LibType=$_POST['LIBTYPE'];
				  $authname=$_POST['authname'];
				  $subject=$_POST['subject'];

			//	  echo "subject  ".$subject;

				  if($allotted=="select")
					  $allotted="";
				  if($catg=="select")
					  $catg="";
				   if($LibType=="select")
					  $LibType="";
				  if($Section=="select")
					  $Section=0;
				  if($Grade=="select")
					  $Grade=0;
				    if($subject=="select")
					  $subject=0;

				  $where=" where 1=1 ";

				  if($authname!="")
					  $where=$where." and AuthorName like '%$authname%' ";
				  if($publname!="")
					  $where=$where." and Publication like '%$publname%' ";
				  if($bookname!="")
					  $where=$where." and BookName like '%$bookname%' ";
				   if($subject!="")
					  $where=$where." and Subject=$subject ";
				 if($Grade >0)
					  $where=$where." and GradeId=$Grade ";
				if($Section!="")
					  $where=$where." and SectionId=$Section ";
				  if($catg!="")
					  $where=$where." and Category  like '%$catg%' ";
				  if($LibType!="")
					  $where=$where." and LibType like '%$LibType%' ";
				  if($bookId!="")
					  $where=$where." and BookId like '%$bookId%' ";

		//BookName  BookId  Subject  AuthorName  Publication  Note  Active  Allotted  GradeId  SectionId  
//$strExeQuery="select * from librarybookinfo where AuthorName like '%$authname%' and BookName like '%$bookname%' and Publication like '%$publname%' and Allotted like '%$allotted%'";

$strExeQuery="select * from librarybookinfo".$where;

				  //$strExeQuery="select * from librarybookinfo a, gradeinfo b, sectioninfo c where a.GradeInfoId = b.";


				  // $sql=mysql_query("SELECT * FROM t_Userinfo WHERE UserName LIKE CONVERT( _utf8 '%$z' USING latin1 ) COLLATE latin1_swedish_ci");
				  //echo "in search";
				//echo  $strExeQuery;
				// exit;
				 
		}
		else
			$strExeQuery="select * from librarybookinfo";

		//echo $strExeQuery;

			
				$pager = new PS_Pagination($conn,$strExeQuery,100,4);
				$rs = $pager->paginate();
				if(mysql_num_rows(mysql_query($strExeQuery))!=0)
				{
			?>
        	<tr>
	
			<td class="lakstoppad1" valign="top">
<form name="OrgApp" id="OrgApp" method="post" action="">
	                      	<table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#EEEEEE">
								<tr>
                            	<td class="tblhdr" colspan="13" height="24">
                                	Library Information
								</td>
                               
                           </tr>

                		<tr class="HeaderStyle" height="24">
						
						  <td align="center">
                        	Book/CD Id <br/>
                        </td>
						<td align="center">
                        	Book or CD? <br/>
                        </td>
                    	<td align="center">

                        	Book/CD Category
                        </td>

                        <td align="center">
                        	Book/CD Name <br/>
                        </td>

						<td align="center">
                        	Subject <br/>
                        </td>
			 <td align="center">
                        	Author's Name
                        </td>
						<td align="center" >
                        	Publication
                        </td>

						<td align="center">
                        	Class
                        </td>
					<td align="center">
                        	Notes
                        </td>

						<td align="center">
                        	</td>


                    </tr>
        <?php
					if(mysql_num_rows(mysql_query($strExeQuery))>100)
					{
						echo "<center> ";
						echo "<br/>".$pager->renderFullNav()."<br/>"; 
						echo"</center>";
						echo "<br>";
					}
					$iCount=0;
					while($Result=mysql_fetch_array($rs))
					{
						$LibGradeId = $Result['GradeId'];
						$LibSectionId = $Result['SectionId'];

									//echo $SectionId;
						//echo ",".$LibSectionId;
						//echo ",".$GradeId;
						//echo ",".$LibGradeId;
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

						 <td align="left">
                        	<?php echo $Result['BookId']; ?>
                        </td>
						<td align="left">
                        	<?php echo $Result['LibType']; ?>
                        </td>
						 <td align="left">
                        	<?php 
		$strExeQueryEval=mysql_query("select CodeValue,CodeDesc  from codevalue WHERE CodeType ='BOOKCATG' and CodeValue='".$Result['Category']."'");
							$ResultEval=mysql_fetch_array($strExeQueryEval);
												
											echo $ResultEval['CodeDesc']; ?>
                        </td>
				

                  
                        <td align="left">
                        	<?php echo $Result['BookName']; ?>
                        </td>

						
                        	  <td  align="left">
	
						  
						<?php
							if($Result['Subject']=="select" or $Result['Subject']=="")
						{}
						else
						{
							$strExeQuerysubject=mysql_query("select SubjectInfoId,SubjectName from subjectinfo where SubjectInfoId=".$Result['Subject'],$conn);
						$rsSubject=mysql_fetch_array($strExeQuerysubject);							
						echo $rsSubject['SubjectName'];
						}
					?>

							</td>      
                      
						<td align="left">
                        	<?php echo $Result['AuthorName']; ?>
                        </td>
						
						<td align="left">
                        	<?php echo $Result['Publication']; ?>
                        </td>
						
						<td align="left">
                        
						<?php
							if($Result['GradeId']=="")
							{
							}
							else
							{
								$strExeQueryEval=mysql_query("select GradeInfoId,GradeName  from gradeinfo where GradeInfoId=".$Result['GradeId']);
								while($ResultEval=mysql_fetch_array($strExeQueryEval))
								{  
									 echo $ResultEval['GradeName']." ";
								 }
							}
							if($Result['SectionId']=="")
							{
							}
							else
							{
								$strExeQueryEval=mysql_query("select SectionInfoId,SectionName  from sectioninfo where SectionInfoId=".$Result['SectionId']);
								while($ResultEval=mysql_fetch_array($strExeQueryEval))
								{  
									 echo $ResultEval['SectionName'];
								 }
							}
						?>

                        </td>

												
						<td align="left">
                        	<?php echo $Result['Note']; ?>
                        </td>
								
   					         <td align="center">

							 <?php if ($Result['Allotted'] !='Y')
							 {

								 if($role=="Admin" or $role=="PRCIPAL" or ($role=="CLSTEACH" && $SectionId==$LibSectionId && $GradeId==$LibGradeId))
								{
								 
								 ?>
                           
				                  	<a href="AllotBook.php?BookId=<?php print $Result['LibBookInfoId'];?>" class='bodynav'>Allot </a> <span class="style1">
                      <?php
								}
							 }
						else
						{
							
								 if($role=="Admin" or $role=="PRCIPAL" or ($role=="CLSTEACH" && $SectionId==$LibSectionId && $GradeId==$LibGradeId))
								{

						?>
                           
				                  	<a href="ReceiveBook.php?BookId=<?php print $Result['LibBookInfoId'];?>" class='bodynav'>Receive </a> <span class="style1">
                      <?php
								}
						}

					
								if($role=="Admin" or $role=="PRCIPAL" or ($role=="CLSTEACH" && $SectionId==$LibSectionId && $GradeId==$LibGradeId))
								{

										echo $role;
			
							?>

							<a href="ClassLibraryBookTracking.php?BookId=<?php print $Result['LibBookInfoId'];?>" class='bodynav'>Tracking </a> <span class="style1">

							<a href="AddEditClassLibBookInfo.php?InfoId=<?php print $Result['LibBookInfoId'];?>" class='bodynav'>  Edit </a> <span class="style1">
							 <?php
								}
						?>

                                    </td>
                        

                    </tr>

                    <?php
						}
					
					
					?>
					<tr><td colspan="13" align="right" height="25"><!-- a href="Export2_XL_Notices.php" >Export to MS XL</a --></td></tr>
			<tr>
			<td height="22" colspan="13" align="center" class="tbllbl">

	   <input name="BtnAddNew" type="button" id="Add" value="Add New" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="location.href('AddEditClassLibBookInfo.php?InfoId=0')"/>

<input type="button" value="Back" name="Back" id="Cancel" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="history.go(-1);"/>

</td></tr>     
         
             
			 </table>
             </form>
			 <?php
			 	}
			 	else
				{
						
				}
			 ?>
           
             </td>
             </tr>
			 
            </table>
<?php
include_once('Includes/Bottom.php');
?>
	</form>
	
     
</body>
</html>
