<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
<link type="text/css" rel="stylesheet" href="style.css" />
<?php require_once('../dbconnect.php');?>
<script type="text/javascript" src="Messages.js">
</script>
<script language="JavaScript">
<!--
function mmLoadMenus() {
  if (window.mm_menu_1225114449_0) return;
                  window.mm_menu_1225114449_0 = new Menu("root",131,18,"Arial, Helvetica, sans-serif",12,"#000000","#FFFFFF","#FDF4A7","#0186BF","left","middle",3,0,1000,-5,7,true,true,true,0,true,true);
  mm_menu_1225114449_0.addMenuItem("By&nbsp;Application&nbsp;Id","location='Search/ApplicantId.php'");
  mm_menu_1225114449_0.addMenuItem("By&nbsp;Evaluator","location='Search/Evaluator.php'");
  mm_menu_1225114449_0.addMenuItem("By&nbsp;Company","location='Search/Company.php'");
  mm_menu_1225114449_0.addMenuItem("By&nbsp;Email&nbsp;Id","location='Search/EMail.php'");
  mm_menu_1225114449_0.addMenuItem("By&nbsp;Name","location='Search/Name.php'");
  mm_menu_1225114449_0.addMenuItem("By&nbsp;Status","location='Search/Status.php'");
   mm_menu_1225114449_0.hideOnMouseOut=true;
   mm_menu_1225114449_0.bgColor='#555555';
   mm_menu_1225114449_0.menuBorder=1;
   mm_menu_1225114449_0.menuLiteBgColor='#FFFFFF';
   mm_menu_1225114449_0.menuBorderBgColor='#999999';
window.mm_menu_1225125221_0 = new Menu("root",87,18,"Arial, Helvetica, sans-serif",12,"#000000","#FFFFFF","#FFF3AB","#0186BF","left","middle",3,0,1000,-5,7,true,true,true,0,true,true);
  mm_menu_1225125221_0.addMenuItem("Pending","location='Status/Pending.php'");
  mm_menu_1225125221_0.addMenuItem("Processed","location='Status/Processed.php'");
  mm_menu_1225125221_0.addMenuItem("Rejected","location='Status/Rejected.php'");
  mm_menu_1225125221_0.addMenuItem("Unfinished","location='Status/Unfinished.php'");
  mm_menu_1225125221_0.addMenuItem("All","location='Status/All.php'");
   mm_menu_1225125221_0.hideOnMouseOut=true;
   mm_menu_1225125221_0.bgColor='#555555';
   mm_menu_1225125221_0.menuBorder=0;
   mm_menu_1225125221_0.menuLiteBgColor='#FFFFFF';
   mm_menu_1225125221_0.menuBorderBgColor='#999999';
  window.mm_menu_1225140038_0 = new Menu("root",180,18,"Arial, Helvetica, sans-serif",12,"#000000","#FFFFFF","#FDF2B0","#0186BF","left","middle",3,0,1000,-5,7,true,true,true,0,true,true);
  mm_menu_1225140038_0.addMenuItem("Company&nbsp;Contact&nbsp;Details","location='Reports/CompanyContacts.php'");
  mm_menu_1225140038_0.addMenuItem("Evaluator&nbsp;Contact&nbsp;Details","location='Reports/EvaluatorContact.php'");
   mm_menu_1225140038_0.hideOnMouseOut=true;
   mm_menu_1225140038_0.bgColor='#555555';
   mm_menu_1225140038_0.menuBorder=0;
   mm_menu_1225140038_0.menuLiteBgColor='#FFFFFF';
   mm_menu_1225140038_0.menuBorderBgColor='#999999';

mm_menu_1225140038_0.writeMenus();
} // mmLoadMenus()
//-->
</script>
<script language="JavaScript" src="mm_menu.js"></script>
<style type="text/css">
<!--
.style1 {color: #FFFFFF}
-->
</style>
</head>

<body topmargin="0" bottommargin="0" leftmargin="0" rightmargin="0" class="bdyfont">
<script language="JavaScript1.2">mmLoadMenus();</script>
<table width="1000px" border="0" cellspacing="0" cellpadding="0" align="center">
  <tr>
    <td><img src="../Images/Logo.jpg" alt="elbit" /></td>
  </tr>
  <tr>
    <td valign="middle" style="font-weight:bold" height="30" align="center" background="../Images/buttonbg.gif"><a href="Index.php" class="newlink">Home</a> <span class="style1">|</span> <a href="AddStudent.php" class="newlink">Add Student</a> <span class="style1">|</span> <a href="AddUser.php"class="newlink">Add User</a> <span class="style1">|</span> <a href="ApplicationForm.php"class="newlink">New Student's Form</a> <span class="style1">|</span> <a href="HomeWorkInfo.php" class="newlink">Home Work</a> <span class="style1">|</span> <a href="TimeTable.php" class="newlink">Time Table</a> <span class="style1">|</span> <a href="BusInfo.php" class="newlink">Bus Info</a> <span class="style1">|</span> <a href="reports.php" name="link6" class="newlink" id="link5" >Reports</a> <span class="style1">|</span> <a href="search.php" class="newlink" name="link4" id="link1" >Search</a> <span class="style1">|</span> <a href="ChangePwd.php" class="newlink" >Change Password</a> <span class="style1">|</span> <a href="Email.php" class="newlink" >Notice</a> <span class="style1">|</span>  <a href="Logout.php" class="newlink">Logout</a></td>
  </tr>


  <!-- tr>
    <td valign="middle" style="font-weight:bold" height="30" align="center" background="../Images/buttonbg.gif"><a href="Index.php" class="newlink">Home</a> <span class="style1">|</span> <a href="AddUsers.php" class="newlink">Add Users</a> <span class="style1">|</span> <a href="AddCompany.php"class="newlink">Add Companies</a> <span class="style1">|</span> <a href="AddEvaluator.php"class="newlink">Add Evaluators</a> <span class="style1">|</span> <a href="OrgApplications.php" class="newlink">Organize Applications</a> <span class="style1">|</span> <a href="#" name="link7" id="link3"class="newlink" onmouseover="MM_showMenu(window.mm_menu_1225125221_0,0,19,null,'link7')" onmouseout="MM_startTimeout();">Status</a> <span class="style1">|</span> <a href="#" name="link6" class="newlink" id="link5" onmouseover="MM_showMenu(window.mm_menu_1225140038_0,0,19,null,'link6')" onmouseout="MM_startTimeout();">Reports</a> <span class="style1">|</span> <a href="http://ees.hudsoneval.com/Admin/search.php" class="newlink" name="link4" id="link1" >Search</a> <span class="style1">|</span> <a href="ChangePwd.php" class="newlink" >Change Password</a> <span class="style1">|</span>  <a href="Logout.php" class="newlink">Logout</a></td>
  </tr -->
	<tr><td>