<?php 
require_once('../Lib/Config.php');
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
include_once('Includes/Top.php');
 //include_once("ps_pagination.php");
//$id=$_SESSION['LoginId'];
//$appid=$_GET['10001'];

$pkid=$_POST['InfoId'];
$StudentDtlId=$_POST['InfoId'];
$starttime=$_POST['starttime'];
$today = mktime(0,0,0,date("m"),date("d"),date("Y"));
$CreatedDate = date("Y/m/d", $today);

//echo "	starttime:  ".$starttime;

 $endtime=date("D dS M h:i A");
 $to_time=strtotime($endtime);
// $data_ref =$to_time+100;
//$from_time=strtotime($data_ref);
//$from_time=strtotime($data_ref);
//$TimeTaken= round(abs($to_time - $from_time) / 60,2); 
$TimeTaken= round(abs($to_time - $starttime) / 60,2); 
//echo "	to_time:  ".$to_time;
//echo $TimeTaken;
//exit;
 $today=date("Y/m/d");
$m=date("m");
$y=date("Y");
if($m<=5)
$curryear=$y-1;
else
$curryear=$y;


$Grade=$_POST['hdntxtAdmnGrade'];
//$Section=$_POST['hdntxtSection'];
$Subject=$_POST['hdntxtSubject'];
//$Term=$_POST['hdntxtTerm'];
$MarksOutOf=$_POST['hdnMarksOutOf'];
$PassingMarks=$_POST['hdnPassingMarks'];

$query="select * from userinfo where LoginName ='".$UserName."'";
$strExeQuery=mysql_query($query,$conn);
$row=mysql_fetch_array($strExeQuery);
$UserInfoId = $row['UserInfoId'];




//$SubjectNm=$_POST['txtSubjectNm'];

					$strExeQueryGrade=mysql_query("select * from gradeinfo where GradeInfoId=".$Grade);
					while($ExamGrade=mysql_fetch_array($strExeQueryGrade))
					{  
								$GradeNm = $ExamGrade['GradeName'];
					}
		
					$strExeQuerySubject=mysql_query("select SubjectName from subjectinfo where SubjectInfoId=".$Subject);
					while($ExamSubject=mysql_fetch_array($strExeQuerySubject))
					{  
								$SubjectNm = $ExamSubject['SubjectName'];
					}



				$chkstudentexam="select * from studentexam  where ExamInfoId=".$pkid." and UserInfoId =".$UserInfoId ;
		//echo $chkstudentexam;
				$strExeQuery=mysql_query($chkstudentexam,$conn);
				
				if(mysql_num_rows($strExeQuery)==0)
					$studentexam="insert into studentexam (ExamInfoId,UserInfoId,TimeTaken,CreateDate) values('$pkid','$UserInfoId','$TimeTaken','$CreatedDate')";
				else
					$studentexam="update studentexam  set  TimeTaken=".$TimeTaken." where ExamInfoId=".$pkid;

$number_of_ques = $_POST['number_of_ques'];
$GradeId = $_POST['GradeId'];

			
	//echo $studentexam;
			//exit;
				mysql_query($studentexam,$conn) or die(mysql_error());

				$strExeQueryMaxId=mysql_query("SELECT Max(StudentExamId) as news_id from studentexam",$conn);
				$rowMaxId=mysql_fetch_array($strExeQueryMaxId);	
				//echo $rowMaxId['news_id'];
				$NEW_ID = $rowMaxId['news_id'];

for($i=1;$i<=$number_of_ques;$i++)
{

			if($_POST["QuestionId".$i]!='')
			{
				$QuestionId = $_POST["QuestionId".$i];
			}
			if(!isset($_POST["rdoAnswer".$i]))
			{
				$StdAnswerId = 0;
			}
			else
			{
				if($_POST["rdoAnswer".$i]!='')
				{
					$StdAnswerId = $_POST["rdoAnswer".$i];
				}
			}

					//$studentexamresults ="insert into studentexamresults (StudentExamId,QuestionId,StdAnswerId) values('$NEW_ID','$QuestionId','$StdAnswerId')";

				//echo $studentexamresults;
				//exit;
					
				$chkstudentexamresult="select * from studentexamresults  where StudentExamId=".$NEW_ID." and QuestionId =".$QuestionId ;
				//echo $chkstudentexam;
				$strExeQueryexamresult=mysql_query($chkstudentexamresult,$conn);
				
				if(mysql_num_rows($strExeQueryexamresult)==0)
					$studentexamresults ="insert into studentexamresults (StudentExamId,QuestionId,StdAnswerId) values('$NEW_ID','$QuestionId','$StdAnswerId')";
				else
					$studentexamresults="update studentexamresults  set  StdAnswerId=".$StdAnswerId."  where StudentExamId=".$NEW_ID." and QuestionId =".$QuestionId;

				mysql_query($studentexamresults,$conn) or die(mysql_error());


			//echo "StudentDtlId: ".$StudentDtlId;
			//echo "txtNumPresetnDays: ".$txtNumPresetnDays;

//exit;

}

//echo "Grade  ".$GradeNm;
//echo "  Section : ".$SectionNm;
//echo "  Subject : ".$SubjectNm;

$File = "TimeTable.txt"; 
$Handle = fopen($File, 'w');

?>
<title>Write Exam</title>
<link href="style.css" rel="stylesheet" type="text/css"/>
<!-- link href="../CSS/ViewDetail.css" rel="stylesheet" type="text/css"/>
<link href="../CSS/GridStyle.css" rel="stylesheet" type="text/css"/ --> 

<script type="text/javascript">
function checkForm2()
{
if(document.frmWriteExam.txtAdmnGrade.value == "select")
       {
               alert("Please Select Grade");
               document.frmWriteExam.txtAdmnGrade.focus();
               return false;
       }
if(document.frmWriteExam.txtSection.value == "select")
       {
               alert("Please Select Section");
               document.frmWriteExam.txtSection.focus();
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

	var MarksOutOf = document.frmWriteExam.hdnMarksOutOf.value;

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


						 
				
<form name="frmWriteExam" action="WriteExamAction.php" method="post" onSubmit="return checkForm2();">

							<tr>
                            	<td class="tblhdr" colspan="9" height="24">
                                	Student Exam for Year <?php  $nxtyear=$curryear+1; echo $curryear." - ".$nxtyear;?>
								</td>
                               
                            </tr>
        <tr height="24"> 
<td colspan="2" class="tbldt">

                                 Grade <span class="manda">*</span>                                    </td>

                                <td colspan="3" class="tbldt">

                                    <input type="text" id="txtAdmnGrade" name="txtAdmnGrade" tabindex="3" value="<?php echo $GradeNm;?>" disabled> 

									
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
			
 <td colspan="3" class="tbldt">
                                     <input type="text" id="MarksOutOf" name="MarksOutOf" tabindex="3" value="<?php echo $MarksOutOf;?>" disabled> 

<input type="hidden" value="<?php echo $MarksOutOf; ?>" name="hdnMarksOutOf" />


 </td>

   				<td colspan="2" class="tbldt">

                                 Passing Marks <span class="manda">*</span>                                    </td>
			
 <td colspan="2" class="tbldt">
                                     <input type="text" id="PassingMarks" name="PassingMarks" tabindex="3" value="<?php echo $PassingMarks;?>" disabled> 



 </td>
							


                            </tr>


							
  <tr height="24">
      </tr>				



							<tr>
                            	<td class="tblhdr" colspan="9" height="24">
                                	Answer Sheet 
								</td>
                               
                            </tr>

							<tr class="HeaderStyle" height="24">
						<td align="center" >
                        	Sr.No.
							</td>
						<td align="center" width="40%" colspan="2" >
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
					                       	
                				<td align="center"  >
                        	Your Answer
							</td>
                		<td align="center"  >
                        	Correct Answer
							</td>

						 </tr>        	
							<?php 

						//$qry="select * from codevalue where CodeType='WKDY' and CodeValue in(select DayOfWeek from timetable)";
						//$qry="select * from answers a, questions b,gradeinfo c,sectioninfo d where a.QuestionId=b.QuestionId and b.SectionInfoId=".$Section." and b.GradeInfoId =".$Grade."  and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId";

						$qry="select * from answers a, questions b, examinfo c ,studentexam d ,studentexamresults e where a.QuestionId=b.QuestionId and  d.StudentExamId=e.StudentExamId and a.QuestionId=e.QuestionId and d.ExamInfoId=c.ExamInfoId and d.ExamInfoId=".$pkid." and d.UserInfoId =".$UserInfoId;
//echo $qry;

		//	select * from studentexam  where ExamInfoId=".$pkid." and UserInfoId =".$UserInfoId
			
			$res=mysql_query($qry,$conn) or die(mysql_error);
							$iCount=0;
							$i=0;
							$j=0;
							$t=1;
							$c=0;
							$w=0;


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

<td align="center" width="5%" colspan="1" >
 <?php  echo $t; ?>
 </td>

	<td align="left" width="40%" colspan="2" >

	<?php 

		echo $Result['QuestionDesc'];

		?>

	<br>

	<?php
		$imageind = $Result['ImageInd'];
		$AnsImageInd = $Result['AnsImageInd'];
		$MultiImageInd = $Result['MultiImageInd'];
		$AnswerId = $Result['AnswerId'];
		$QuestionId = $Result['QuestionId'];
		$OperatorInd = $Result['OperatorInd'];
		$ImageLoc ="";
		$NumofImages =0;
		//echo "AnsImageInd ".$AnsImageInd;
		if($imageind=='Y')
		{


			$ImageLoc  = $Result['ImageLoc'];
			$NumofImages   = $Result['NumofImages'];
		
		
				?>

	<br>

	<?php		


			//echo $ImageLoc;
				for($i=0;$i<$NumofImages;$i++)
				{
										
			?>



				<img src='<?php 

			//$input ="$row[StudentFstNm]"."$row[StudentLstNm]"; 
			$input = $ImageLoc;
			//echo $input;
			$output = str_replace(" ","",$input);
			echo $output;?>'>
			

			<?php
				}

		}
		if($OperatorInd=='Y')
		{
					$strExeQueryOperator=mysql_query("select * from quesOperators where QuestionId=".$QuestionId);
					while($RsOperator=mysql_fetch_array($strExeQueryOperator))
					{  
								$Opeartor = $RsOperator['Opeartor'];
								$Number1 = $RsOperator['Number1'];
								$Number2 = $RsOperator['Number2'];
								$Number3 = $RsOperator['Number3'];
								$space ="     ";
					}
				?>
				<br>

			<?php

				echo $space." ".$Number1;

				?>
			<br>

<?php
			echo $Opeartor." ",$space." ".$Number2;

		}
			?>

			<input type="hidden" value="<?php echo $Result['QuestionId'];?>" name="<?php echo "QuestionId".$i;?>" />

								
	</td>

 <?php 
	$Answer1=$Result['Answer1'];
	$Answer2=$Result['Answer2'];
	$Answer3=$Result['Answer3'];
	$Answer4=$Result['Answer4'];
?>				

<td align="center" bgcolor="#EEEEEE"> 

<?php
		//$imageind = $Result['ImageInd'];
		//$AnsImageInd = $Result['AnsImageInd'];
		//$ImageLoc ="";
		//$NumofImages =0;
		//echo "Ansimageind ".$Ansimageind;
		if($AnsImageInd=='Y')
		{
			if($MultiImageInd=='Y')
			{

					$strExeQueryAns1=mysql_query("select * from answerImages where AnswerId=".$AnswerId);
					while($RsAns1=mysql_fetch_array($strExeQueryAns1))
					{  
								$AnsImageLoc = $RsAns1['AnswerImage1Loc'];
					}
			}
			else
				$AnsImageLoc  = $Result['ImageLoc'];
			$NumofImages1   = $Result['Answer1'];
?>
 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer1" disabled/>

	<?php	
				for($i=0;$i<$NumofImages1;$i++)
				{
										
			?>
				<img src='<?php 
			$input = $AnsImageLoc;
			//echo $input;
			$output = str_replace(" ","",$input);
			echo $output;?>'>			

	<?php
				}

		}
		else
		{
	?>

			 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer1" disabled/> <?php echo $Answer1;?>
 <?php
		}
	?>
								
	</td>


<td align="center" bgcolor="#EEEEEE"> 

<?php

		if($AnsImageInd=='Y')
		{
			if($MultiImageInd=='Y')
			{

					$strExeQueryAns2=mysql_query("select * from answerImages where AnswerId=".$AnswerId);
					while($RsAns2=mysql_fetch_array($strExeQueryAns2))
					{  
								$AnsImageLoc = $RsAns2['AnswerImage2Loc'];
					}
			}
			else
				$AnsImageLoc  = $Result['ImageLoc'];

			$NumofImages2   = $Result['Answer2'];
?>
 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer2" disabled/>

	<?php	
				for($i=0;$i<$NumofImages2;$i++)
				{
										
			?>
				<img src='<?php 
			$input = $AnsImageLoc;
			//echo $input;
			$output = str_replace(" ","",$input);
			echo $output;?>'>			

	<?php
				}

		}
		else
		{
	?>

			 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer2" disabled/> <?php echo $Answer2;?>
 <?php
		}
	?>
								
	</td>


<td align="center" bgcolor="#EEEEEE"> 

<?php

		if($AnsImageInd=='Y')
		{
			if($MultiImageInd=='Y')
			{

					$strExeQueryAns3=mysql_query("select * from answerImages where AnswerId=".$AnswerId);
					while($RsAns3=mysql_fetch_array($strExeQueryAns3))
					{  
								$AnsImageLoc = $RsAns3['AnswerImage3Loc'];
					}
			}
			else
				$AnsImageLoc  = $Result['ImageLoc'];
			$NumofImages3   = $Result['Answer3'];
?>
 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer3" disabled/>

	<?php	
				for($i=0;$i<$NumofImages3;$i++)
				{
										
			?>
				<img src='<?php 
			$input = $AnsImageLoc;
			//echo $input;
			$output = str_replace(" ","",$input);
			echo $output;?>'>			

	<?php
				}

		}
		else
		{
	?>

			 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer3" disabled/> <?php echo $Answer3;?>
 <?php
		}
	?>
								
	</td>


							 
								
<td align="center" bgcolor="#EEEEEE"> 

<?php

		if($AnsImageInd=='Y')
		{
			if($MultiImageInd=='Y')
			{

					$strExeQueryAns4=mysql_query("select * from answerImages where AnswerId=".$AnswerId);
					while($RsAns4=mysql_fetch_array($strExeQueryAns4))
					{  
								$AnsImageLoc = $RsAns4['AnswerImage4Loc'];
					}
			}
			else
				$AnsImageLoc  = $Result['ImageLoc'];
			$NumofImages4   = $Result['Answer4'];
?>
 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer4" disabled/>

	<?php	
				for($i=0;$i<$NumofImages4;$i++)
				{
										
			?>
				<img src='<?php 
			$input = $AnsImageLoc;
			//echo $input;
			$output = str_replace(" ","",$input);
			echo $output;?>'>			

	<?php
				}

		}
		else
		{
	?>

			 <input type="radio" name="rdoAnswer" id="rdoAnswer" tabindex="3" value="Answer4" disabled/> <?php echo $Answer4;?>
 <?php
		}
	?>
								
	</td>


<td align="center" width="5%" colspan="1" >
 <?php 
	
		$CorrectAnswer = $Result['CorrectAnswer']; 
		$CorrectAnswer =str_replace("Answer","",$CorrectAnswer);
		
	if($Result['StdAnswerId']>0)
	{
		$StdAnswer = $Result['StdAnswerId'];
		if($StdAnswer==$CorrectAnswer)
		{
			echo "Correct";
			$c++;
		}
		else
		{
			$w++;
			?>
		<img src='images/Exam/erase.png'>
<?php
		}
	}
	else
		echo "Try Now";

?>
 </td>

 <td align="center" width="5%" colspan="1" >
 <?php  $CorrectAnswer = $Result['CorrectAnswer']; 
		$CorrectAnswer =str_replace("Answer","",$CorrectAnswer);
		echo $CorrectAnswer;
 ?>
 </td>

        </tr>
							
	<?php
$i++;
	$t++;
		}
	?>
	

							
				
                      
                            



						<tr>
                            	<td class="HeaderStyle" align="center" colspan="9" height="24">&nbsp;
					<?php

					$n = $number_of_rows-$c-$w;

					echo "Total Questions : ".$number_of_rows."  Correct Answer :".$c." Wrong Answer :".$w." Not Attempted :".$n;

					?>

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

