<?php
require_once('../dbconnect.php');
$File = "Export2xl.txt"; 
$Handle = fopen($File, 'w');

// Get data records from table. 
//$result=mysql_query("select * from FeeInfo order by FeeId asc");

$result=mysql_query("select * from notices a, userinfo b where a.CreateById=b.UserInfoId",$conn);

fwrite($Handle,"result :".$result."\n\n");

$label="Notices Sent Report";

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
xlsWriteLabel(0,0,$label);


// Make column labels. (at line 3)
xlsWriteLabel(2,0,"Sr.No.");
xlsWriteLabel(2,1,"Subect");
xlsWriteLabel(2,2,"Message");
xlsWriteLabel(2,3,"Notice To");
xlsWriteLabel(2,4,"Email");
xlsWriteLabel(2,5,"Sent By");
xlsWriteLabel(2,6,"Sent Date");

   
  
       

$xlsRow = 3;
$i=1;

// Put data records from mysql by while loop.
while($row=mysql_fetch_array($result)){

//fwrite($Handle,"FeeName :".$row['GroupName']."\n\n");

$CreateBy= $row['FstName']." ".$row['LstName'];
fwrite($Handle,"i :".$i."\n\n");
fwrite($Handle,"Subect :".$row['Subect']."\n\n");
fwrite($Handle,"Message :".$row['Message']."\n\n");
fwrite($Handle,"NoticeTo :".$row['NoticeTo']."\n\n");
fwrite($Handle,"Email :".$row['Email']."\n\n");
fwrite($Handle,"CreateBy :".$row['CreateBy']."\n\n");
fwrite($Handle,"CreatedDate :".$row['CreatedDate']."\n\n");


xlsWriteNumber($xlsRow,0,$i);
xlsWriteLabel($xlsRow,1,$row['Subject']);
xlsWriteLabel($xlsRow,2,$row['Message']);
xlsWriteLabel($xlsRow,3,$row['NoticeTo']);
xlsWriteLabel($xlsRow,4,$row['Email']);
xlsWriteLabel($xlsRow,5,$CreateBy); 
xlsWriteLabel($xlsRow,6,$row['CreatedDate']);      


$xlsRow++;
$i++;
} 
fwrite($Handle,"after while :");

xlsEOF();
fclose($Handle); 
exit();
?>
