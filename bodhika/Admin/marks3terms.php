<?php
include("Charts.php");
require_once('../Lib/Config.php');
//include_once('Includes/Top.php');
include("FC_Colors.php");
 include_once("ps_pagination.php");

$role = $_SESSION['Role'];
$UserName = Auth::currentUser();

 if($role=="Admin" or $role=="PRCIPAL" or $role=="CLSTEACH")
{
include_once('Includes/Top.php');
$primkeyId = $_GET['AppId'];
$StudntDtlId = $_GET['AppId'];

}
else if($role=="STDNT")
{
include_once('Includes/StudentTop.php');
$query="select * from studentinfo where LoginName ='".$UserName."'";
//echo $query;
$strExeQuery=mysql_query($query,$conn);
//echo $strExeQuery;
$row=mysql_fetch_array($strExeQuery);
//echo $row;
$StudentInfoId = $row['StudentInfoId'];
//echo $strExeQuery;
//exit;
$primkeyId = $StudentInfoId;
$StudntDtlId = $_GET['AppId'];
}

//$sub_namess[]="";
?>
<HTML>
<HEAD>
	<TITLE>
	</TITLE>
	<?php
	?>	
	<SCRIPT LANGUAGE="Javascript" SRC="Charts.js"></SCRIPT>
	<style type="text/css">
	<!--
	body {
		font-family: Arial, Helvetica, sans-serif;
		font-size: 12px;
	}
	.text{
		font-family: Arial, Helvetica, sans-serif;
		font-size: 12px;
	}
	-->
	</style>
</HEAD>
<BODY>

<CENTER>


<table border="1">
<tr>
<td width="60%" valign="top">

	




	<table border="0" cellpadding="0" cellspacing="0" width="100%" align="center">


	
		  <?php 
//select * from feetable a, feeinfo b, groupinfo c where a.GroupId = c.GroupId and a.FeeId = b.FeeId and a.Active = 'Y' order by a.GroupId,a.FeeId"

				$strExeQuery="SELECT * FROM studentresultdetail a, subjectgradeinfo  b , studentdetails c ,subjectinfo d WHERE a.StudentDtlId =".$StudntDtlId."  and TermId in(1) and a.SubjectInfoId=b.SubjectInfoId and d.SubjectInfoId=b.SubjectInfoId and a.StudentDtlId=c.StudentDtlId and c.GradeInfoId = b.GradeInfoId";
				$strExeQuery1="SELECT * FROM studentresultdetail a, subjectgradeinfo  b , studentdetails c,subjectinfo d WHERE a.StudentDtlId=".$StudntDtlId."  and TermId in(2) and a.SubjectInfoId=b.SubjectInfoId and d.SubjectInfoId=b.SubjectInfoId and a.StudentDtlId=c.StudentDtlId and c.GradeInfoId = b.GradeInfoId";
	//$pager2= mysql_query($strExeQuery1) or die(mysql_error());
//$strExeQuery3="SELECT * FROM studentresultdetail a, subjectinfo  b WHERE studentdtlid=1 and termid in(3) and a.SubjectInfoId=b.SubjectInfoId";
$strExeQuery3="SELECT * FROM studentresultdetail a, subjectgradeinfo  b , studentdetails c,subjectinfo d WHERE a.StudentDtlId=".$StudntDtlId."  and TermId in(3) and a.SubjectInfoId=b.SubjectInfoId and d.SubjectInfoId=b.SubjectInfoId and a.StudentDtlId=c.StudentDtlId and c.GradeInfoId = b.GradeInfoId";

//echo $strExeQuery;

				$pager = new PS_Pagination($conn,$strExeQuery,18,4);
$rs0 = $pager->paginate();
$rs = $pager->paginate();
				$pager1 = new PS_Pagination($conn,$strExeQuery1,18,4);
$rs1 = $pager1->paginate();

$pager3 = new PS_Pagination($conn,$strExeQuery3,18,4);
$rs3 = $pager3->paginate();
$rs4 = $pager3->paginate();
$rs5 = $pager3->paginate();


$c=mysql_query($strExeQuery);
$r=mysql_num_rows($c);





				if(mysql_num_rows(mysql_query($strExeQuery))!=0)
				{
			?>
        	<tr>
	
			<td class="lakstoppad1" valign="top" width="35%">

	                      	<table border="0" cellpadding="0" cellspacing="1" width="100%" bgcolor="#EEEEEE">
								<tr>
                            	<td class="tblhdr" colspan="2" height="24">
                                	All Terms Marks
								</td>
                             
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



					
		  
		  ?>	

                      <tr height="27" class="<?php
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

					<td align="center">
                        
							<table width="100%" cellspacing="1" cellpadding="0" border="0"  bgcolor="#DDDDDD">
							<tr class="HeaderStyle" height="22">
							<td>Term </td>
							<?php 

																$i=0;
								while($Result5=mysql_fetch_array($rs0))
								{
								?>
							
										<td>
										<?php echo $Result5['SubjectName'];
										//"subj".$i=$Result5['SubjectName'];
										
										$sub_namess[] = $Result5['SubjectName'];




										//echo "$subj"$i;
											
										?>		
										</td>
<?php
								}
										?>
							</tr>
							<tr class="HeaderStyle" height="22">
														<td>Total Mark </td>
							<?php 

																$i=0;
								while($Result6=mysql_fetch_array($rs4))
								{
								?>
							
										<td>
										<?php echo $Result6['TotalMarks'];
										
										?>		
										</td>
<?php
								}
										?>
							</tr>
														<tr class="HeaderStyle" height="22">
														<td>Pass Mark </td>
							<?php 

																$i=0;
								while($Result7=mysql_fetch_array($rs5))
								{
								?>
							
										<td>
										<?php echo $Result7['PassMarks'];
										
										?>		
										</td>
<?php
								}
										?>
							</tr>
<tr>


<?php

						$strExeQueryEval=mysql_query("select TermId,TermDesc from terminfo");


							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{ 
								?>

									<td><?php 							
						echo $ResultEval['TermDesc']; ?>		
 </td>

<?php
								$strExeQuery100="SELECT * FROM studentresultdetail a, subjectgradeinfo  b , studentdetails c ,subjectinfo d WHERE a.StudentDtlId =".$StudntDtlId."  and TermId =".$ResultEval['TermId']." and a.SubjectInfoId=b.SubjectInfoId and d.SubjectInfoId=b.SubjectInfoId and a.StudentDtlId=c.StudentDtlId and c.GradeInfoId = b.GradeInfoId";
								$pager = new PS_Pagination($conn,$strExeQuery100,18,4);
								$rs100 = $pager->paginate();
								
						
								$i=0;
								while($Result100=mysql_fetch_array($rs100))
								{
								?>						
									
										
										<td class="tblvalue">
										<?php echo $Result100['MarksObtained']; ?>		
										</td>
										
									
									
													<?php 
											$i=$i++;
										} 
										?>
										</tr>
										<?php 
							}?>
							</tr></table>
                        </td>


							
							

                    </tr>

                    
					
			
         
             
			 </table>
            
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

	</form>















</td>
</tr>
<tr>

<td>
<?php

    //Request the factory Id from Querystring
    //$FactoryId = $_GET['FactoryId'];

    //Generate the graph element string
    //$strXML = "<graph caption='Factory " . $FactoryId . " Output ' subcaption='(In Units)' xAxisName='Date' formatNumberScale='0' decimalPrecision='0'>";
	$strXML = "<graph caption='' subcaption='' yAxisName='Marks' xAxisName='Subject' formatNumberScale='0' decimalPrecision='0' yAxisMaxValue='100'  showAlternateHGridColor='1' AlternateHGridAlpha='30' AlternateHGridColor='CCCCCC'>";
//graph xaxisname="Continent" yaxisname="Export" hovercapbg="DEDEBE" hovercapborder="889E6D" rotateNames="0" yAxisMaxValue="100" numdivlines="9" divLineColor="CCCCCC" divLineAlpha="80" decimalPrecision="0" showAlternateHGridColor="1" AlternateHGridAlpha="30" AlternateHGridColor="CCCCCC" caption="Global Export" subcaption="In Millions Tonnes per annum pr Hectare">
    // Connet to the DB
    //$link = connectToDB();

    //$strQuery = "select * from Factory_Output where FactoryId=" . $FactoryId;
    $strQuery= "SELECT * FROM studentresultdetail a, subjectgradeinfo  b , studentdetails c,subjectinfo d WHERE a.StudentDtlId=".$StudntDtlId."  and TermId in(1) and a.SubjectInfoId=b.SubjectInfoId and d.SubjectInfoId=b.SubjectInfoId and a.StudentDtlId=c.StudentDtlId and c.GradeInfoId = b.GradeInfoId";
	
	$result = mysql_query($strQuery) or die(mysql_error());

	$strQuery1= "SELECT * FROM studentresultdetail a, subjectgradeinfo  b , studentdetails c,subjectinfo d WHERE a.StudentDtlId=".$StudntDtlId."  and TermId in(2) and a.SubjectInfoId=b.SubjectInfoId and d.SubjectInfoId=b.SubjectInfoId and a.StudentDtlId=c.StudentDtlId and c.GradeInfoId = b.GradeInfoId";
	
	$result1 = mysql_query($strQuery1) or die(mysql_error());

	$result3 = mysql_query($strQuery1) or die(mysql_error());

	$strQuery5= "SELECT * FROM studentresultdetail a, subjectgradeinfo  b , studentdetails c,subjectinfo d WHERE a.StudentDtlId=".$StudntDtlId."  and TermId in(3) and a.SubjectInfoId=b.SubjectInfoId and d.SubjectInfoId=b.SubjectInfoId and a.StudentDtlId=c.StudentDtlId and c.GradeInfoId = b.GradeInfoId";
	
	$result5 = mysql_query($strQuery5) or die(mysql_error());

    
$strXML .="<categories font='Arial' fontSize='11' fontColor='000000'>";

 //if ($result1) {
   //     while($ors1 = mysql_fetch_array($result1)) {
            //Here, we convert date into a more readable form for set name.
     //       $strXML .= "<category name='". $ors1['SubjectInfoId']."' hoverText='". $ors1['MarksObtained']."' /> ";
       // }
    //}

if(count($sub_namess)>0)
{

			foreach( $sub_namess as $subjname )
			{

				// echo $subjname."<br />\n";

			 $strXML.= "<category name='".$subjname."' hoverText='".$subjname."'/>";

			//echo $strXML;

			}
}

						$strExeQueryEval=mysql_query("select TermId,TermDesc from terminfo");

 $strXML .="</categories>";
 $jj=336699;

							while($ResultEval=mysql_fetch_array($strExeQueryEval))
							{ 


								//echo "coming here";
								 $strXML .="<dataset seriesname='".$ResultEval['TermDesc']."' color='".$jj."'>";

								 							
						//echo $ResultEval['TermDesc']; 
								$strExeQuery100="SELECT * FROM studentresultdetail a, subjectgradeinfo  b , studentdetails c ,subjectinfo d WHERE a.StudentDtlId =".$StudntDtlId."  and TermId =".$ResultEval['TermId']." and a.SubjectInfoId=b.SubjectInfoId and d.SubjectInfoId=b.SubjectInfoId and a.StudentDtlId=c.StudentDtlId and c.GradeInfoId = b.GradeInfoId";
								$pager = new PS_Pagination($conn,$strExeQuery100,18,4);
								$rs100 = $pager->paginate();
								
						
								$i=0;
								while($Result100=mysql_fetch_array($rs100))
								{
									//$jj=336699;

												
									//echo "coming here 111111";
									//echo "SubjectInfoId: ".$Result100['SubjectInfoId'];
										//echo "MarksObtained: ".$Result100['MarksObtained'];
//$strXML.= "<set name='".$Result100['SubjectInfoId']."' value='".echo $Result100['MarksObtained']."' color='FDC12E'/>";
										
				            $strXML .= "<set name='". $Result100['SubjectInfoId']."' value='" . $Result100['MarksObtained'] . "' color='".$jj."'/>";
					
									
											$i=$i++;
											
								}
								$jj=$jj+222222;
$strXML.= "</dataset>";

							}
							
								


	$strXML.= "</graph>";
	
//echo $strXML;

    //Create the chart - Column 2D Chart with data from strXML
	echo renderChart("FCF_MSColumn3D.swf", "", $strXML, "FactoryDetailed", 900, 345);
?>

</td>
</tr>


                             <tr>

                             	
                                <td colspan="4" align="center" valign="baseline">

                                	

<input type="button" value="Back" name="Back" id="Cancel" tabindex="42" style="background-image:url('../Images/btnsmall2.gif');width:100px;height:26px;border:0" class="btnbg" onclick="history.go(-1);"/>
                                           </td>

                             </tr>  
</table>
<?php
include_once('Includes/Bottom.php');
?>
</CENTER>
</BODY>
</HTML>