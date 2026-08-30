<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<title>Send SMS from your web page</title>
</head>
<body>


<?php
// Copyright (c) 2006, Simon Jansson <http://www.litewebsite.com> all rights reserved.
// License <http://litewebsite.com/license_BSD.html>

// Setttings
$footer = 'Your footer text'; // could be your site name or maybe an ad
$maxSizeSms = 160 - strlen($footer); // chars left (after footer)

// Clickatell settings
// API manual: http://www.clickatell.com/downloads/http/Clickatell_http_2.2.7.pdf
DEFINE('API_ID', '');
DEFINE('API_USER', '');
DEFINE('API_PWD', '');

if( !extension_loaded('curl') ){
        die('You need to load/activate the cURL extension (http://www.php.net/cURL).');
}



// check if form has been "posted"
if( isset($_GET['mobile']) && isset($_GET['sms']) ){

        // phone number and message (message + footer)
        preg_match_all('/[\d]{11}/', $_GET['mobile'], $mobile);
        $sms = urlencode(substr($_GET['sms'], 0, $maxSizeSms).$footer);

        // URL for sending the SMS
        $apiCallUrl  = 'http://api.clickatell.com/http/sendmsg?';
        $apiCallUrl .= 'api_id='.API_ID.'&user='.API_USER.'&password='.API_PWD;
        $apiCallUrl .= '&to='.$mobile[0][0].'&text='.$sms.'&from=';

        // more cURL info at <http://www.php.net/cURL> and <http://curl.haxx.se/docs/features.html>
        $curlHandle = curl_init(); // init curl

        // cURL options
        curl_setopt($curlHandle, CURLOPT_URL, $apiCallUrl); // set the url to fetch
        curl_setopt($curlHandle, CURLOPT_HEADER, 0); // set headers (0 = no headers in result)
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, 1); // type of transfer (1 = to string)
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 15); // time to wait in seconds

        $content = curl_exec($curlHandle); // Make the call for sending the SMS

        curl_close($curlHandle); // Close the connection to Clickatell

        if( preg_match('/^ID:/', $content) ){
                echo '<h2>Message have been sent</h2>';
        }else{
                echo '<h2>Error sending message</h2>';
        }
}
?>



<form method="get" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">

Phone number (international format)<br />
<input type="text" name="mobile" size="11" maxlength="11" value="" /><br />

Message (max <?php echo $maxSizeSms ?> letters)<br />
<input type="text" name="sms" size="30" maxlength="<?php echo $maxSizeSms ?>" value="" /><br />

<input type="submit" value="Send SMS" />

</form>

</body>
</html>
