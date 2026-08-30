<?php
require_once('classes/tc_calendar.php');
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>

<title></title>


<link href="calendar.css" rel="stylesheet" type="text/css" />
<script language="javascript" src="calendar.js"></script>

</head>

<body leftmargin="0" topmargin="0" marginwidth="0" marginheight="0">

             
                <table border="0" cellspacing="0" cellpadding="2">
                  <tr>
                    <td>Date 3 :</td>
                    <td><?php
	  $myCalendar = new tc_calendar("date5", true, false);
	  $myCalendar->setIcon("./images/iconCalendar.gif");
	  //$myCalendar->setDate(5, 10, 2009);
	  $myCalendar->setPath("./");
	  $myCalendar->setYearInterval(2000, 2015);
	  //$myCalendar->dateAllow('2008-05-13', '2010-03-01');
	  $myCalendar->setDateFormat('j F Y');
	  //$myCalendar->setHeight(350);	  
	  //$myCalendar->autoSubmit(true, "form1");
	  $myCalendar->writeScript();
	  ?></td>
                    <td><input type="button" name="button" id="button" value="Check the value" onClick="javascript:alert(this.form.date5.value);"></td>
                  </tr>
                </table>
                <ul>
                  <li>Default date to 5 October 2009 </li>
                  <li>Set year navigate from 2000 to 2015 </li>
                  <li>Allow date selectable from 13 May 2008 to 01 March 2010</li>
                  <li>Allow to navigate other dates from above </li>
                  <li>Date input box set to false </li>
                </ul>
               
              
             
</body>
</html>
