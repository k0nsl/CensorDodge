<?php
session_start(); //Start session for settings of proxy to be stored and recovered
require("includes/class.censorDodge.php"); //Load censorDodge class
$proxy = new censorDodge(@$_GET["cdURL"], true, true); //Instantiate censorDodge class

//Clear cookies and resetting settings session
if (isset($_GET["clearCookies"])) { $proxy->clearCookies(); echo '<meta http-equiv="refresh" content="0; url='.cdURL.'">'; }
if (isset($_POST["resetSettings"])) { unset($_SESSION["settings"]); echo '<meta http-equiv="refresh" content="0; url='.cdURL.'">'; }

//Settings to be put on page and editable
$settings = array(
    array("encryptURLs","Encrypt URLs"),
    array("allowCookies","Allow Cookies"),
    array("stripJS","Remove Javascript"),
    array("stripObjects","Remove Objects")
);

//Update settings in session for changing in proxy later
if (isset($_POST["updateSettings"])) {
    foreach ($settings as $setting) {
        if (isset($proxy->{$setting[0]})) {
            $_SESSION["settings"][$setting[0]] = isset($_POST[$setting[0]]); //Store settings in session for later
            $proxy->{$setting[0]} = isset($_POST[$setting[0]]); //Update proxy instance settings
        }
    }

    echo '<meta http-equiv="refresh" content="0; url='.cdURL.'">'; //Reload page using META redirect
}
else {
    foreach ($settings as $setting) {
        if (isset($proxy->{$setting[0]}) && isset($_SESSION["settings"][$setting[0]])) {
            $proxy->{$setting[0]} = $_SESSION["settings"][$setting[0]]; //Update proxy instance settings
        }
    }
}

$errorTemplate = findTemplate("","error");
if (!empty($errorTemplate)) {
    function outside_handler($e) { global $proxy, $error_string, $settings, $errorTemplate; $m = $e->getMessage(); if (!empty($m) && !empty($errorTemplate)) { $error_string = $m; include("$errorTemplate"); } }
    set_exception_handler("outside_handler");
}

if (!@$_GET["cdURL"]) { //Only run if no URL has been submitted
    $homeTemplate = findTemplate("","home");

    if (empty($homeTemplate)) {
        echo "<html><head><title>".ucfirst(strtolower($_SERVER['SERVER_NAME']))." - Censor Dodge ".$proxy->version."</title></head><body>"; //Basic title

        //Basic submission form with base64 encryption support
        echo "
        <script> function goToPage() { event.preventDefault(); if (document.getElementsByName('cdURL')[0].value!='') { window.location = '?cdURL=' + ".($proxy->encryptURLs ? 'btoa(document.getElementsByName("cdURL")[0].value)' : 'document.getElementsByName("cdURL")[0].value')."; } } </script>
        <h2>Welcome to Censor Dodge ".$proxy->version."</h2>
        <form action='#' method='GET' onsubmit='goToPage();'>
            <input type='text' size='30' name='cdURL' placeholder='URL'>
            <input type='submit' value='Go!'>
        </form>";

        echo "<hr><h3>Proxy Settings:</h3><form action='".cdURL."' method='POST'>";
        foreach($settings as $setting) { //Toggle option for setting listed in array, completely dynamic
            echo '<span style="padding-right:20px;"><input type="checkbox" '.($proxy->{$setting[0]} ? "checked" : "") .' name="'.$setting[0].'" value="'.$proxy->{$setting[0]} .'"> '.$setting[1]."</span>";
        }
        echo "<br><input style='margin-top: 20px;' type='submit' name='updateSettings' value='Update Settings'><form action='".cdURL."' method='POST'><input style='margin-left: 5px;' type='submit' value='Reset' name='resetSettings'></form></form>";

        $file = $proxy->parseLogFile(date("d-m-Y").".txt"); //Parse log file of current date format
        echo "<hr><h3>Pages Viewed Today (Total - ".count($file)." By ".count($proxy->sortParsedLogFile($file, "IP"))." Users):</h3>";

        if (count($views = $proxy->sortParsedLogFile($file, "URL"))>0) {
            echo "<table><thead><td><b>Website</b></td><td><b>View Count</b></td></thead>"; //Table title
            foreach($views as $URL => $logs)  {
                echo "<tr><td style='padding-right: 80px;'>".$URL."</td><td>".count($logs)."</td></tr>"; //Table row for each parsed log
            }
            echo "</table>";
        }
        else {
            echo "<p>No pages have been viewed yet today!</p>"; //No logs in file so just display generic message
        }

        if (file_exists($proxy->cookieDIR)) {
            echo "<hr><h3>Cookie File - <a href='?clearCookies'>[Delete File]</a>:</h3>"; //Option to delete file
            echo "<p style='word-wrap: break-word;'>".nl2br(wordwrap(trim(file_get_contents($proxy->cookieDIR)),190,"\n",true))."</p>"; //Output cookie file to screen
        }
        else {
            echo "<hr><h3>Cookie File:</h3>";
            echo "<p>No cookie file could be found!</p>"; //No file found so just display generic message
        }
        echo "</body></html>";
    }
    else {
        @include("$homeTemplate");
    }
}
else {
    $miniFormTemplate = findTemplate("","miniForm");
    if (!empty($miniFormTemplate)) { //Get our mini-form file with PHP code compiled for result
        ob_start(); include("".$miniFormTemplate.""); $output = ob_get_contents();
        ob_end_clean(); $proxy->addMiniFormCode($output);
    }
    echo $proxy->openPage(); //Run proxy with URL submitted when proxy class was instantiated
}

function findTemplate($themeName, $location = "home", $startLocation = "") {
    if (file_exists(BASE_DIRECTORY.DS."plugins".DS.$themeName)) {
        if (empty($startLocation)) { $startLocation = BASE_DIRECTORY."plugins".DS.(!empty($themeName) ? $themeName.DS : "")."*"; }
        foreach(glob($startLocation) as $file) {
            if (preg_match("~".preg_quote($location.".cdTheme")."~i",pathinfo($file,PATHINFO_BASENAME)) && !is_dir($file)) {
                return $file;
            }
            elseif(is_dir($file)) {
                $file = findTemplate($themeName,$location, $file.DS."*");
                if (!empty($file)) { return $file; }
            }
        }
    }

    return false;
}