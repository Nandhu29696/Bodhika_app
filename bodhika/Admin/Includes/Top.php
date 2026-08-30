<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
<link type="text/css" rel="stylesheet" href="style.css" />
<?php require_once('../dbconnect.php');?>
<script type="text/javascript" src="Messages.js">
</script>
<script>
function backbtn(){
if (window.history.previous== null)
{

window.history.go(-2);
}
else
{

window.history.go(-1);
}
}
</script>

<style type="text/css">
<!--
.style1 {color: #FFFFFF}
-->
</style>
</head>

<body topmargin="0" bottommargin="0" leftmargin="0" rightmargin="0" class="bdyfont">

<table width="1000px" border="0" cellspacing="0" cellpadding="0" align="center">
  <!-- tr>
    <td><img src="../Images/Logo.jpg" alt="Hansel" /></td>
  </tr -->

 <?php
 $role = $_SESSION['Role'];
  $UserInfoId = $_SESSION['UserInfoId'] ?? 0;

// echo $role;

$pos = strpos($role,"TEACH");


 if($role=="Admin" or $role=="PRCIPAL")
{
	?>


<tr height="105">
    <td>
<table width="100%" border="0" cellspacing="0" cellpadding="0" align="center" background="../Images/Logo.jpg" height="105">
<tr height="80">
    <td>&nbsp;</td>
</tr>
<tr height="25">
    <td align="right" style="padding-right:35">

<a href="Index.php" class="newlink"><img src="../Images/homeicon.gif" border="0" align="absmiddle" alt="Home"></a>

<a href="ChangePwd.php" class="newlink"><img src="../Images/ChangePasswordIcon.gif" border="0" align="absmiddle" alt="Change Password"></a>

<a href="Logout.php" class="newlink"><img src="../Images/logouticon.gif" border="0" align="absmiddle" alt="Logout"></a>

<a href="Support.php" class="newlink" ><img src="../Images/HelpIcons.gif" border="0" align="absmiddle" alt="Help"></a>

&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;

</td>
</tr>
<table>

</td>
  </tr>



  <tr>
    <td>
<!-- ═══════════════════════════════════════════════════════
     ADMIN MEGA-NAV  (CSS-only dropdowns, no JS required)
     ═══════════════════════════════════════════════════════ -->
<style>
/* ── Reset / container ───────────────────────────────────── */
.adminnav { margin:0; padding:0; list-style:none; background:#762F00;
            display:flex; align-items:stretch; flex-wrap:wrap; }
.adminnav > li { position:relative; }

/* ── Top-level links ─────────────────────────────────────── */
.adminnav > li > a {
    display:block; padding:7px 13px; color:#fff;
    font-family:Verdana,Arial,sans-serif; font-size:12px; font-weight:bold;
    text-decoration:none; white-space:nowrap; }
.adminnav > li > a:hover,
.adminnav > li:hover > a { background:#9a4000; color:#ffe8a0; }

/* ── Dropdown panels ─────────────────────────────────────── */
.adminnav .dropdown {
    display:none; position:absolute; top:100%; left:0; z-index:999;
    background:#fff; border:1px solid #762F00; border-top:3px solid #762F00;
    min-width:190px; box-shadow:2px 4px 8px rgba(0,0,0,.25); padding:4px 0; }
.adminnav li:hover > .dropdown { display:block; }

/* ── Dropdown category heading ───────────────────────────── */
.adminnav .dropdown .dd-head {
    background:#f5ece6; color:#762F00; font-weight:bold;
    font-size:11px; padding:5px 12px 3px; letter-spacing:.5px;
    text-transform:uppercase; border-bottom:1px solid #e0c4b0; }

/* ── Dropdown items ──────────────────────────────────────── */
.adminnav .dropdown a {
    display:block; padding:5px 16px; color:#333;
    font-family:Verdana,Arial,sans-serif; font-size:12px;
    text-decoration:none; white-space:nowrap; }
.adminnav .dropdown a:hover { background:#f5ece6; color:#762F00; padding-left:20px; }

/* ── Separator ───────────────────────────────────────────── */
.adminnav .dropdown .dd-sep { height:1px; background:#e0c4b0; margin:3px 0; }

/* ── Right-align last group dropdowns ────────────────────── */
.adminnav li.dd-right > .dropdown { left:auto; right:0; }
</style>

<ul class="adminnav">

  <!-- Home -->
  <li><a href="Index.php">&#x2302; Home</a></li>

  <!-- Students -->
  <li>
    <a href="#">Students &#9660;</a>
    <div class="dropdown">
      <div class="dd-head">Students</div>
      <a href="AddStudent.php">&#43; Add Student</a>
      <a href="AdmittedStudentList.php">Registered Students</a>
      <a href="AdminUsers.php?tab=students">All Registered Users</a>
      <a href="InstituteStudents.php">Institutes &amp; Students</a>
      <div class="dd-sep"></div>
      <a href="SearchStudent.php">Search Student</a>
    </div>
  </li>

  <!-- Users -->
  <li>
    <a href="#">Users &#9660;</a>
    <div class="dropdown">
      <div class="dd-head">User Management</div>
      <a href="AddUser.php">&#43; Add User</a>
      <a href="SearchUser.php">Search User</a>
      <a href="AdminUsers.php?tab=logins">Logged-In Users</a>
      <a href="LoginTrack.php">Login Activity</a>
      <a href="ChangeUserRole.php">Change Role</a>
    </div>
  </li>

  <!-- Exam -->
  <li>
    <a href="#">Exam &#9660;</a>
    <div class="dropdown">
      <div class="dd-head">Exam</div>
      <a href="ExamSearch.php">Search Exams</a>
      <a href="ExamList.php">Exam List</a>
      <a href="AddEditExam.php">Add / Edit Exam</a>
      <a href="SubjectInfo.php">Subjects</a>
      <div class="dd-sep"></div>
      <a href="../exam/questions-hub.php">&#10067; Manage Questions</a>
    </div>
  </li>

  <!-- Results -->
  <li>
    <a href="#">Results &#9660;</a>
    <div class="dropdown">
      <div class="dd-head">Results</div>
      <a href="ExamResults.php">Exam Scores</a>
      <a href="ExamHistoryList.php">Rank &amp; History</a>
      <a href="marks.php">Subject-wise Marks</a>
      <a href="Charts.php">Performance Graph</a>
      <a href="GradeInfo.php">Grade Info</a>
    </div>
  </li>

  <!-- Certificates -->
  <li>
    <a href="#">Certificates &#9660;</a>
    <div class="dropdown">
      <div class="dd-head">Certificates</div>
      <a href="../exam/result.php">Download Certificate</a>
      <a href="../exam/result.php?verify=1">Verify Certificate</a>
    </div>
  </li>

  <!-- Notifications -->
  <li>
    <a href="#">Notifications &#9660;</a>
    <div class="dropdown">
      <div class="dd-head">Notifications</div>
      <a href="Notices.php">Exam Reminders</a>
      <a href="NoticesSent.php">Result Announcements</a>
      <a href="EnrollmentPayments.php">Enrollment Updates</a>
      <div class="dd-sep"></div>
      <a href="EMail.php">Send Email</a>
      <a href="sendSMS.php">Send SMS</a>
    </div>
  </li>

  <!-- Payments -->
  <li class="dd-right">
    <a href="#">Payments &#9660;</a>
    <div class="dropdown">
      <div class="dd-head">Payments</div>
      <a href="EnrollmentPayments.php">Payment History</a>
      <a href="PendingPayments.php">Pending Payments</a>
      <a href="EnrollmentPayments.php?receipts=1">Fee Receipts</a>
      <a href="ManageCoupons.php">Coupon Usage</a>
      <a href="InstituteDiscounts.php">Institute Discounts</a>
    </div>
  </li>

  <!-- Reports -->
  <li class="dd-right">
    <a href="#">Reports &#9660;</a>
    <div class="dropdown">
      <div class="dd-head">Reports</div>
      <a href="reports.php">All Reports</a>
      <a href="InstituteReports.php">Institute Reports</a>
      <a href="Charts.php">Charts</a>
      <div class="dd-sep"></div>
      <div class="dd-head">Feedback</div>
      <a href="FeedbackDashboard.php">&#128172; Feedback Dashboard</a>
    </div>
  </li>

  <!-- Admin -->
  <li class="dd-right">
    <a href="#">Admin &#9660;</a>
    <div class="dropdown">
      <div class="dd-head">Administration</div>
      <a href="Admin.php">Admin Panel</a>
      <a href="ManageInstitutes.php">Manage Institutes</a>
      <a href="ManageTeachers.php">Manage Teachers</a>
      <a href="RoleInfo.php">Roles</a>
      <a href="Support.php">Support</a>
      <div class="dd-sep"></div>
      <a href="db-export.php">&#x1F4E5; DB Table Export</a>
    </div>
  </li>

</ul>
<!-- ─── end adminnav ─────────────────────────────────────── -->
    </td>
  </tr>
 <?php
}
else if($pos>0)
{
	?>



<tr height="105">
    <td>
<table width="100%" border="0" cellspacing="0" cellpadding="0" align="center" background="../Images/Logo.jpg" height="105">
<tr height="80">
    <td>&nbsp;</td>
</tr>
<tr height="25">
    <td align="right" style="padding-right:35">

<a href="Index.php" class="newlink"><img src="../Images/homeicon.gif" border="0" align="absmiddle" alt="Home"></a>
<a href="ChangePwd.php" class="newlink"><img src="../Images/ChangePasswordIcon.gif" border="0" align="absmiddle" alt="Change Password"></a>

<a href="L
ogout.php" class="newlink"><img src="../Images/logouticon.gif" border="0" align="absmiddle" alt="Logout"></a>

<a href="Support.php" class="newlink" ><img src="../Images/HelpIcons.gif" border="0" align="absmiddle" alt="Help"></a>

&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
</td>
</tr>
<table>

</td>
  </tr>




  <tr>
    <td valign="middle" style="font-weight:bold" height="30" align="center" background="../Images/buttonbg.gif"><!-- a href="Index.php" class="newlink"><img src="../Images/homeicon.gif" border="0" align="absmiddle"  alt="home"></a> <span class="style1">|</span --> <a href="EditUser.php?UserInfoId=<?php echo $UserInfoId;?>" class="newlink">Edit Profile </a><span class="style1">|</span> <a href="TimeTableList.php" class="newlink">Time Table</a> <span class="style1">|</span> <a href="BusInfo.php" class="newlink">Bus Info</a> <span class="style1">|</span> <a href="AsgnProjInfo.php" class="newlink">Assignments</a> <span class="style1">|</span> <a href="EventList.php" class="newlink">Events</a> <span class="style1">|</span> <a href="ActivityList.php" class="newlink">Activities</a> <span class="style1">|</span> <a href="AchievementsList.php" class="newlink">Achievements</a> <span class="style1">|</span>  <a href="ClassLibraryBookList.php" class="newlink">Library</a> <span class="style1">|</span> <a href="reports.php" name="link6" class="newlink" id="link5" >Reports</a> <span class="style1">|</span> <a href="eventcalc/index.php" class="newlink" onMouseover="return hidestatus()">Calendar</a>  <!-- span class="style1">|</span> <a href="ChangePwd.php" class="newlink" >Change Password</a --> <span class="style1">|</span> <a href="Notices.php" class="newlink" >Notice</a> <!-- span class="style1">|</span> <a href="Support.php" class="newlink" >Help</a> <span class="style1">|</span>  <a href="Logout.php" class="newlink"><img src="../Images/logouticon.gif" border="0" align="absmiddle"  alt="logout"></a --></td>
  </tr>
	 <?php
}
?>


	<tr><td>