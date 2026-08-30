<?php 
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');
 include_once("ps_pagination.php");
//$id=$_SESSION['LoginId'];

 $today=date("Y/m/d");
$m=date("m");
$y=date("Y");
if($m<=5)
$curryear=$y-1;
else
$curryear=$y;


$strExeQueryExam=mysql_query("select * from examinfo where ExamInfoId=".$_GET['InfoId']);
while($ExamDtl=mysql_fetch_array($strExeQueryExam))
{  
								//$GradeNm = $ExamGrade['GradeName'];
								$ExamName = $ExamDtl['ExamName'];
								$GradeId = $ExamDtl['GradeInfoId'];
								$Subject = $ExamDtl['SubjectInfoId'];
								$MarksOutOf=$ExamDtl['NumOfQuestions'];
								$PassingMarks=$ExamDtl['MinPassing'];
								$TimeAlloted=$ExamDtl['TimeAlloted'];
								$Section=1;
								$Term=1;
								
}

/*
$Grade=$_POST['txtAdmnGrade'];
$Section=$_POST['txtSection'];
$Subject=$_POST['txtSubject'];
$Term=$_POST['txtTerm'];
$MarksOutOf=$_POST['txtMarksOutOf'];
$PassingMarks=$_POST['txtPassingMarks'];

*/




//$SubjectNm=$_POST['txtSubjectNm'];

					$strExeQueryGrade=mysql_query("select GradeName from gradeinfo where GradeInfoId=".$GradeId);
					while($ExamGrade=mysql_fetch_array($strExeQueryGrade))
					{  
								$GradeNm = $ExamGrade['GradeName'];
					}
					$strExeQuerySection=mysql_query("select SectionName from sectioninfo where SectionInfoId=".$Section);
					while($ExamSection=mysql_fetch_array($strExeQuerySection))
					{  
								$SectionNm = $ExamSection['SectionName'];
					}
					$strExeQuerySubject=mysql_query("select SubjectName from subjectinfo where SubjectInfoId=".$Subject);
					while($ExamSubject=mysql_fetch_array($strExeQuerySubject))
					{  
								$SubjectNm = $ExamSubject['SubjectName'];
					}
					$strExeQueryTerm=mysql_query("select TermDesc from terminfo where TermId=".$Term);
					while($ExamTerm=mysql_fetch_array($strExeQueryTerm))
					{  
								$TermDesc = $ExamTerm['TermDesc'];
					}

//echo "Grade  ".$GradeNm;
//echo "  Section : ".$SectionNm;
//echo "  Subject : ".$SubjectNm;

$File = "TimeTable.txt"; 
$Handle = fopen($File, 'w');

?>
<title>Add/Edit Exam</title>
<link href="style.css" rel="stylesheet" type="text/css"/>
<!-- link href="../CSS/ViewDetail.css" rel="stylesheet" type="text/css"/>
<link href="../CSS/GridStyle.css" rel="stylesheet" type="text/css"/ --> 

<script type="text/javascript">
function checkForm2()
{
if(document.frmAddEditExam.txtAdmnGrade.value == "select")
       {
               alert("Please Select Grade");
               document.frmAddEditExam.txtAdmnGrade.focus();
               return false;
       }
if(document.frmAddEditExam.txtSection.value == "select")
       {
               alert("Please Select Section");
               document.frmAddEditExam.txtSection.focus();
               return false;
       }
	   
return true;
}

    function isNumberKey(evt)
      {
         var charCode = (evt.which) ? evt.which : event.keyCode
         if (charCode > 31 && (charCode < 48 || charCode > 57))
            return false;

         return true;
      }

function validateNumber(NumControl) 
{
    var input = document.getElementById(NumControl)
    var returnval=false
//alert("Please use only positive numbers!");

	var MarksOutOf = document.frmAddEditExam.hdnMarksOutOf.value;

//document.write(MarksOutOf)
    if (input.value>MarksOutOf)
	{
		alert("Marks obtained can't be more than Total Marks.");
		returnval=false;
		
	}
	else
		returnval=true;

    if (returnval==false) input.focus()
    return returnval
} 



</script>
</head>



<body>
	<table border="0" cellpadding="0" cellspacing="1" width="1024" align="center">	


						 
				
<form name="frmAddEditExam" action="AddEditExamAction.php" method="post" onSubmit="return checkForm2();">

							<tr>
                            	<td class="tblhdr" colspan="9" height="24">
                                	Student Exam for Year <?php  $nxtyear=$curryear+1; echo $curryear." - ".$nxtyear;?>
								</td>
                               
                            </tr>
        <tr height="24"> 
<td colspan="2" class="tbldt">

                                 Grade <span class="manda">*</span>                                    </td>

                                <td colspan="2" class="tbldt">

                                    <input type="text" id="txtAdmnGrade" name="txtAdmnGrade" tabindex="3" value="<?php echo $GradeNm;?>" disabled> 

									
	 </td>
							
				<td colspan="2" class="tbldt">

                                 Section <span class="manda">*</span>                                    </td>
			
 <td colspan="2" class="tbldt">
                                     <input type="text" id="txtSection" name="txtSection" tabindex="3" value="<?php echo $SectionNm;?>" disabled> 



 </td>
</tr>

<tr>

<td colspan="2" class="tbldt">

                                 Term <span class="manda">*</span>                                    </td>
			
 <td colspan="2" class="tbldt">
                                     <input type="text" id="txtTerm" name="txtTerm" tabindex="3" value="<?php echo $TermDesc;?>" disabled> 



 </td>

 				<td colspan="2" class="tbldt">

                                 Subject <span class="manda">*</span>                                    </td>
			
 <td colspan="2" class="tbldt">
                                     <input type="text" id="txtSubject" name="txtSubject" tabindex="3" value="<?php echo $SubjectNm;?>" disabled> 



 </td>

 										
                            </tr>

							<tr>

  				<td colspan="2" class="tbldt">

                                 Marks Out Of <span class="manda">*</span>                                    </td>
			
 <td colspan="2" class="tbldt">
                                     <input type="text" id="MarksOutOf" name="MarksOutOf" tabindex="3" value="<?php echo $MarksOutOf;?>" disabled> 

<input type="hidden" value="<?php echo $MarksOutOf; ?>" name="hdnMarksOutOf" />


 </td>

   				<td colspan="2" class="tbldt">

                                 Pass Mark (%) <span class="manda">*</span>                                    </td>

 <td colspan="2" class="tbldt">
                                     <input type="text" id="PassingMarks" name="PassingMarks" tabindex="3" value="<?php echo $PassingMarks;?>%" disabled> 



 </td>
							


                            </tr>


							
  <tr height="24">
      </tr>				



							<tr>
                            	<td class="tblhdr" colspan="9" height="24">
                                	Add Questions 
								</td>
                               
                            </tr>

							<tr class="HeaderStyle" height="24">
						<td align="center" width="40%" colspan="5" >
                        	Question Description 
                        </td>
						<td align="center" >
                        	Answer1
							</td>
      					<td align="center"  >
                        	Answer2
							</td>
				<td align="center" >
                        	Answer3
							</td>
				<td align="center"  >
                        	Answer4
							</td>
						 </tr>
                            	
                        	
							<?php 

						//$qry="select * from codevalue where CodeType='WKDY' and CodeValue in(select DayOfWeek from timetable)";
						$qry="select * from answers a, questions b,gradeinfo c,sectioninfo d where a.QuestionId=b.QuestionId and b.SectionInfoId=".$Section." and b.GradeInfoId =".$GradeId."  and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId";
//echo $qry;

							$res=mysql_query($qry,$conn) or die(mysql_error);
							$iCount=0;
							$i=0;
							$j=0;
							$t=1;


					//$pager = new PS_Pagination($conn,$strExeQuery,65,4);
				//$rs = $pager->paginate();


		$number_of_rows = mysql_num_rows($res);

fwrite($Handle,"res number_of_rows :".$number_of_rows."\n\n");

//echo "number_of_rows: ",$number_of_rows;
$i=1;

							//while($rowEdu=mysql_fetch_array($res))
							while($Result=mysql_fetch_array($res))
							{
					fwrite($Handle,"i :".$i."\n");
					//fwrite($Handle,"CodeDesc :".$rowEdu['CodeDesc']."\n\n");

								
							
							?>
							

<tr >


 <td align="left" colspan="5" >
 

	 <input type="text"  id="<?php echo "QuestionDesc1".$i;?>" size ="30" maxlength="50" name="<?php echo "QuestionDesc1".$i;?>" value="<?php echo $Result['QuestionDesc'];?>" tabindex="3" > 

			<input type="hidden" value="<?php echo $Result['QuestionId'];?>" name="<?php echo "QuestionId".$i;?>" />

								
	</td>

 <?php 
				$Answer1=$Result['Answer1'];
$Answer2=$Result['Answer2'];
$Answer3=$Result['Answer3'];
$Answer4=$Result['Answer4'];
				

					
								//echo ">>>>>>>>";
							?>  
							<td align="center" bgcolor="#EEEEEE"> 
                       	

							 
	 <input type="text"  id="<?php echo "Answer1".$i;?>" size ="30" maxlength="30" name="<?php echo "Answer1".$i;?>" value="<?php echo $Answer1;?>" tabindex="3" > 
	 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer1" />

	 
								</td>
							<td align="center" bgcolor="#EEEEEE"> 
                       	
 <input type="text"  id="<?php echo "Answer2".$i;?>" size ="30" maxlength="30" name="<?php echo "Answer2".$i;?>" value="<?php echo $Answer2;?>" tabindex="3" > 
 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer2" />

							 
								
								</td>
							<td align="center" bgcolor="#EEEEEE"> 
                       	
 <input type="text"  id="<?php echo "Answer3".$i;?>" size ="30" maxlength="30" name="<?php echo "Answer3".$i;?>" value="<?php echo $Answer3;?>" tabindex="3" > 
 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer3" />

							 
								
								</td>
							<td align="center" bgcolor="#EEEEEE"> 
                       	
 <input type="text"  id="<?php echo "Answer4".$i;?>" size ="30" maxlength="30" name="<?php echo "Answer4".$i;?>" value="<?php echo $Answer4;?>" tabindex="3" > 
 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer3" />

							 
								
								</td>


             <!--input type="text"  id="<?php echo "txtMarksObtained".$i;?>" size ="2" maxlength="3" onkeypress="return isNumberKey(event)" onBlur="<?php echo $funnm;?>"   name="<?php echo "txtMarksObtained".$i;?>" value="<?php echo $MarksObtained;?>" tabindex="3"--> 

  


</tr>
							
	<?php
$i++;
		}
	?>
	

							
				
                      
                            



						<tr>
                            	<td class="HeaderStyle" colspan="9" height="24">&nbsp;

								</td>
                               
                            </tr>
						

<tr height="22">
					<td align="center" colspan="9">

					<input type="submit" value="Save" name="save" id="save" tabindex="16" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg"/>

						<input type="button" value="Back" tabindex="15" class="btnbg" onClick="location.href('ExamSearch.php')" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0"/>
<input type="hidden" value="<?php echo $number_of_rows; ?>" name="number_of_rows" />
			<input type="hidden" value="<?php echo $Subject; ?>" name="SubjectId" />
			<input type="hidden" value="<?php echo $MarksOutOf; ?>" name="MarksOutOf" />
			<input type="hidden" value="<?php echo $Grade; ?>" name="GradeId" />
			<input type="hidden" value="<?php echo $Section; ?>" name="SectionId" />
		<input type="hidden" value="<?php echo $Term; ?>" name="TermId" />
			<input type="hidden" value="<?php echo $PassingMarks; ?>" name="PassingMarks" />

							<!-- a href="#" onClick="history.go(-1)">Back</a -->
					</td>
			</tr>

<tr>
                            	<td class="" colspan="4" height="24">&nbsp;

								</td>
                               
                            </tr>
                        </table>





            </td>
        </tr>
		</form>

<tr><td bgcolor="#A89972">
<?php 
  include_once('Includes/Bottom.php');
fclose($Handle); 

?>
</td></tr>
    </table>

