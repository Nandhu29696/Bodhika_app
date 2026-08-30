<?php
/**
 * StudentTop.php — Top navigation bar for student-role pages.
 * Replaces the old pipe-separated flat links with a CSS navbar
 * that includes a "My Exams" dropdown.
 */
require_once __DIR__ . '/../../Lib/Config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="style.css">
<style>
/* ── Student Navbar ─────────────────────────────────────────── */
.student-navbar {
    width: 1000px;
    margin: 0 auto;
    background: url('../Images/buttonbg.gif') repeat-x center;
    background-color: #336699;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    min-height: 34px;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 12px;
    font-weight: bold;
}

.student-navbar a,
.student-navbar .nav-dropdown > .nav-dropbtn {
    color: #ffffff;
    text-decoration: none;
    padding: 8px 10px;
    display: inline-block;
    white-space: nowrap;
    transition: background 0.15s;
}

.student-navbar a:hover,
.student-navbar .nav-dropdown:hover > .nav-dropbtn {
    background-color: rgba(0,0,0,0.25);
    color: #FFE066;
}

/* Dropdown wrapper */
.nav-dropdown {
    position: relative;
    display: inline-block;
}

.nav-dropbtn {
    cursor: pointer;
    background: none;
    border: none;
    font: inherit;
    font-weight: bold;
    font-size: 12px;
    line-height: 1;
    padding: 8px 10px;
}

/* Arrow indicator */
.nav-dropbtn::after {
    content: " ▾";
    font-size: 10px;
}

/* Dropdown panel */
.nav-dropdown-content {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    background-color: #2a5480;
    min-width: 170px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.35);
    z-index: 9999;
    border-top: 2px solid #FFE066;
}

.nav-dropdown-content a {
    display: block;
    padding: 8px 14px;
    color: #ffffff;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.nav-dropdown-content a:last-child {
    border-bottom: none;
}

.nav-dropdown-content a:hover {
    background-color: #1a3e60;
    color: #FFE066;
}

/* Show on hover */
.nav-dropdown:hover .nav-dropdown-content,
.nav-dropdown:focus-within .nav-dropdown-content {
    display: block;
}

/* Separator */
.nav-sep {
    color: rgba(255,255,255,0.5);
    padding: 0 2px;
    user-select: none;
}
</style>
</head>

<body>
<table width="1000" border="0" cellspacing="0" cellpadding="0" align="center">
  <tr>
    <td><img src="../Images/Logo.jpg" alt="EES Logo"></td>
  </tr>
  <tr>
    <td>
      <nav class="student-navbar">

        <a href="IndexStudent.php">Home</a>
        <span class="nav-sep">|</span>

        <!-- My Exams dropdown -->
        <div class="nav-dropdown">
          <button class="nav-dropbtn">My Exams</button>
          <div class="nav-dropdown-content">
            <a href="ExamList.php?type=available">Available Exams</a>
            <a href="ExamList.php?type=scheduled">Scheduled Exams</a>
            <a href="ExamList.php">Start Exam</a>
            <a href="ExamInstructions.php">Exam Instructions</a>
            <a href="ExamHistoryList.php">Exam History</a>
            <a href="ExamResults.php">My Results</a>
          </div>
        </div>
        <span class="nav-sep">|</span>

        <a href="EditStudentProfile.php">Edit Profile</a>
        <span class="nav-sep">|</span>
        <a href="ViewStudentDetails.php">View Details</a>
        <span class="nav-sep">|</span>
        <a href="HomeWorkInfoStudent.php">Assignments</a>
        <span class="nav-sep">|</span>
        <a href="TimeTable.php">Time Table</a>
        <span class="nav-sep">|</span>
        <a href="BusInfo.php">Bus Info</a>
        <span class="nav-sep">|</span>
        <a href="NoticesSent.php">Notices</a>
        <span class="nav-sep">|</span>
        <a href="Events.php">Events</a>
        <span class="nav-sep">|</span>
        <a href="ActivityList.php">Activities</a>
        <span class="nav-sep">|</span>
        <a href="LibraryStudent.php">Library</a>
        <span class="nav-sep">|</span>
        <a href="eventcalc/indexstudent.php">Calendar</a>
        <span class="nav-sep">|</span>
        <a href="Food.php">Food</a>
        <span class="nav-sep">|</span>
        <a href="ChangePwd.php">Change Password</a>
        <span class="nav-sep">|</span>
        <a href="Support.php">Help</a>
        <span class="nav-sep">|</span>
        <a href="Logout.php">Logout</a>

      </nav>
    </td>
  </tr>
  <tr><td>
