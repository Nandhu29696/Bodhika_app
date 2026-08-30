<?php
require_once('../dbconnect.php');
$File = "Export2xl.txt"; 
$Handle = fopen($File, 'w');

// Get data records from table. 
//$result=mysql_query("select * from FeeInfo order by FeeId asc");

$sql = $_GET['sql'];

	$Grade=$_GET['g'];	
	$F=$_GET['f'];
	$L=$_GET['l'];
	$Section=$_GET['s'];

if($sql==1)
{
				$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c,sectioninfo d  where a.StudentInfoId=b.StudentInfoId and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y' and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId  order by a.StudentFstNm";	
}
else if($sql==2)
{
				$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c,sectioninfo d  where a.StudentInfoId=b.StudentInfoId and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y'  and b.SectionInfoId=".$Section."  and b.GradeInfoId =".$Grade." and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId  order by a.StudentFstNm";

		
}
else if($sql==3)
{
		$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c,sectioninfo d  where a.StudentInfoId=b.StudentInfoId and a.StudentFstNm LIKE '%$F%' and a.StudentLstNm LIKE '%$L%' and a.AdmittedInd ='Y'  and b.GradeInfoId =".$Grade." and c.GradeInfoId = b.GradeInfoId and d.SectionInfoId=b.SectionInfoId  order by a.StudentFstNm";
		
}
else if($sql==4)
{
		$strExeQuery="select * from studentinfo a,studentdetails b,gradeinfo c  where a.StudentInfoId=b.StudentInfoId  and a.AdmittedInd ='Y'   and c.GradeInfoId = b.GradeInfoId  order by a.StudentFstNm";
		
}

$sql =$_POST['sql'];


//$result=mysql_query("select * from StudentInfo where AdmittedInd ='Y' order by StudentInfoId desc",$conn);
$result=mysql_query($strExeQuery,$conn);
//$result=mysql_query($sql,$conn);

fwrite($Handle,"sql :".$sql."\n\n");
fwrite($Handle,"strExeQuery :".$strExeQuery."\n\n");


//fwrite($Handle,"result :".$result."\n\n");

$label="Student Report";

// Functions for export to excel.
function xlsBOF() { 
echo pack("ssssss", 0x809, 0x8, 0x0, 0x10, 0x0, 0x0); 
return; 
} 
function xlsEOF() { 
echo pack("ss", 0x0A, 0x00); 
return; 
} 
function xlsWriteNumber($Row, $Col, $Value) { 
echo pack("sssss", 0x203, 14, $Row, $Col, 0x0); 
echo pack("d", $Value); 
return; 
} 
function xlsWriteLabel($Row, $Col, $Value ) { 
$L = strlen($Value); 

echo pack("ssssss", 0x204, 8 + $L, $Row, $Col, 0x0, $L); 
echo $Value; 
return; 
} 
header("Pragma: public");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0"); 
header("Content-Type: application/force-download");
header("Content-Type: application/octet-stream");
header("Content-Type: application/download");;
//header("Content-Disposition: attachment;filename=orderlist.xls "); 
header("Content-Disposition: attachment;filename=".$label.".xls "); 

header("Content-Transfer-Encoding: binary ");

xlsBOF();

/*
Make a top line on your excel sheet at line 1 (starting at 0).
The first number is the row number and the second number is the column, both are start at '0'
*/

//xlsWriteLabel(0,0,"List of car company.");
//xlsWriteLabel(0,0,$label);


// Make column labels. (at line 3)
xlsWriteLabel(0,0,"Sr.No.");
xlsWriteLabel(0,1,"Student Id");
xlsWriteLabel(0,2,"Name");
xlsWriteLabel(0,3,"Grade");
xlsWriteLabel(0,4,"Section");
xlsWriteLabel(0,5,"Address");
xlsWriteLabel(0,6,"Email");
xlsWriteLabel(0,7,"Mobile#");
xlsWriteLabel(0,8,"StudentInfoId");

$xlsRow = 1;
$i = 1;

// Put data records from mysql by while loop.
while($row=mysql_fetch_array($result)){


		if($row['StudentMiddleName']!='')
			$name = $row['StudentFstNm']." ".$row['StudentMiddleName']." ".$row['StudentLstNm'];
		else
			$name = $row['StudentFstNm']." ".$row['StudentLstNm'];
	
fwrite($Handle,"name :".$name."\n\n");

xlsWriteNumber($xlsRow,0,$i);
xlsWriteLabel($xlsRow,1,$row['StudentUniqueId']);
xlsWriteLabel($xlsRow,2,$name);
xlsWriteLabel($xlsRow,3,$row['GradeName']);      
xlsWriteLabel($xlsRow,4,$row['SectionName']); 
xlsWriteLabel($xlsRow,5,$row['Address']);
xlsWriteLabel($xlsRow,6,$row['EMail']);
xlsWriteNumber($xlsRow,7,$row['Mobile']);
xlsWriteLabel($xlsRow,8,$row['StudentInfoId']);
     

$i++;
$xlsRow++;
} 
xlsEOF();
fclose($Handle); 
exit();
?>
