<html> 
    <head> 
        <title>Timer</title> 

	<?php

	$Time=$TimeAlloted;

	//echo "Time: ".$Time;

	?>

        <script type="text/javascript"> 
                var Timer = { 
                        // Main method for counting down 
                        countDown: function (displayID, min, sec, callback) { 
                                // Update timer display 
                                document.getElementById(displayID).innerHTML = (min < 10 ? "0" : "") + min + ":" + (sec < 10 ? "0" : "") + sec; 
 
                                // If there is time left on the timer, continue counting down 
                                if (sec > 0 || min > 0) { 
                                        setTimeout(function () { Timer._countDownCallback(displayID, min, sec, callback); }, 1000); 
                                } 
                                // When time has run out invoke option callback function 
                                else if (typeof callback == "function") { 
                                        callback(); 
                                } 
                        }, 
 
                        // Internal method for processing remaining time 
                        _countDownCallback: function (displayID, min, sec, callback) { 
                                // Update time left for the timer 
                                sec = (sec > 0 ? sec - 1 : 59); 
                                if (sec == 59) min = (min > 0 ? min - 1 : 59); 
 
                                // Call main method to update display, etc. 
                                Timer.countDown(displayID, min, sec, callback); 
                        } 
                }; 
 
                // Start the timer when the page loads 
                window.onload = function () { 
                        Timer.countDown("timerDisplay", "<?php echo $Time;?>", 00, function () { alert("The time has come!"); }); 
                } 
        </script> 
    </head> 
    <body> 
        <div>Time left: <span id="timerDisplay"></span></div> 
	    </body> 
</html> 
