<?php
define('DS', DIRECTORY_SEPARATOR); //Short hand DIR separator value
define('BASE_DIRECTORY', dirname(get_included_files()[count(get_included_files())-2]).DS); //Compile base DIR for use by script
define('cdURL', (empty($_SERVER['HTTPS']) || strtolower($_SERVER['HTTPS']) == 'off' ? "http" : "https")."://".$_SERVER['HTTP_HOST'].explode('?', $_SERVER['REQUEST_URI'])[0]); //Compile proxy URL base for use by script
if (count(explode("&", $q=$_SERVER['QUERY_STRING']))>count($_GET)) { $_GET = array(); parse_str($q, $_GET); }

class censorDodge {
    public $version = "1.81 BETA";
    public $cookieDIR, $isSSL = "";
    private $URL, $contentType, $HTTP, $getParam, $logToFile, $miniForm = "";
    private $blacklistWebsites = array("localhost", "127.0.0.1");
    private $blacklistIPs = array();
    private static $pluginFunctions = array();

    //General settings for the 'virtual browser'
    public $encryptURLs = true;
    public $allowCookies = true;
    public $stripJS = false;
    public $stripObjects = false;
    public $customUserAgent = null;
    public $customReferrer = null;

    //Settings which are applied to cURL during request
    public $curlSettings = array();

    function __construct($URL = "", $logToFile = true, $debugMode = false) {
        set_time_limit(0); //Allow script to run indefinitely if possible
        set_exception_handler(array($this, 'errorHandler')); //Set our custom error handler
        if (!$debugMode) { error_reporting(0); } //Enable or disable error messages
        $this->isSSL = !(empty($_SERVER['HTTPS']) || strtolower($_SERVER['HTTPS']) == 'off'); //Boolean for if proxy is running using SSL

        $this->createCookieDIR(false); //Populate cookieDIR with directory, but don't create file
        $this->logToFile = $logToFile; //Enable or disable URL logging into a file

        //Check that the server meets all the requirements
        if (!(version_compare(PHP_VERSION, $required = "5.1")>=0)) { throw new Exception("You need at least PHP ".$required." to use Censor Dodge V".$this->version."!"); }
        if (!function_exists('curl_init')) { throw new Exception("You need to enable and configure cURL to use Censor Dodge V".$this->version."!"); }
        if (!is_callable(array(@new DOMDocument(),"loadHTML"))) { throw new Exception("You need to have DOMDocument installed and enabled to use Censor Dodge V".$this->version."!"); }

        if (!empty($URL)) {
            $this->getParam = array_search($URL,$_GET,true); //Find GET param for resubmission later
            if (empty($this->getParam)) { $this->getParam = "URL"; if (isset($_GET[$this->getParam])) { $URL = @$_GET[$this->getParam]; } } //Create a GET parameter if one isn't found
            if (isset($_POST) && isset($_POST[substr(md5("cdGET"),0,20)])) { $_GET = array_merge($_GET, $_POST); $_POST = array(); } //Move POST params to GET if needed

            //Base64 decode URL if needed, if not just URL decode it
            preg_match("/[a-z0-9\+\/]+([\=]+|)/i",$URL,$matches); if (strlen($matches[0]) % 4 != 0 || !($decode = base64_decode($matches[0], true))) { preg_match("/[a-z0-9\+]+([\=]+|)/i",$URL,$matches); $decode = base64_decode($matches[0], true); }
            if ((base64_encode($decode)===$matches[0] || $decode) && filter_var("http://".$decode, FILTER_VALIDATE_URL)) { $URL = str_replace($matches[0], $decode, $URL); } else { $URL = rawurldecode($URL); }
            $this->URL = $this->modifyURL(trim($URL)); //Use coded function to fix URL if needed
        }

        foreach ($_FILES as $uploadName => $files) {
            for ($i=0; $i<count($files["name"]); $i++) { //Loop through all files in each upload box
                if ($files["error"][$i]!=false) { continue; } $name = (count($files["name"])>1 ? $uploadName."[$i]" : $uploadName); //Generate name of file array item
                $_POST[$name] = new CURLFile($files["tmp_name"][$i], $files["type"][$i], $files["name"][$i]); //Add the file and name into the post array
            }
        }

        $form = "<script> function goToPage() { event.preventDefault(); if (document.getElementsByName('cdURL')[0].value!='') { window.location = '?cdURL=' + ".($this->encryptURLs ? 'btoa(document.getElementsByName("cdURL")[0].value)' : 'escape(document.getElementsByName("cdURL")[0].value)')."; } } </script>";
        $form .= "<div id='miniForm' style='z-index: 9999999999; position: fixed; left:15px; top:10px;'><form style='display:none;' onsubmit='goToPage();' id='miniFormBoxes' action='".cdURL."'><input type='text' autocomplete=\"off\" style='all:initial; background:#fff; border:1px solid #a9a9a9; padding:3px;border-radius:2px;' placeholder='URL' value='' name='cdURL'>
            <input type='submit' style='all:initial; cursor:pointer; margin-left:5px; margin-right:5px; border-radius:2px;background:#fff; border:1px solid #989898; padding:3px; background: linear-gradient(to bottom, #f6f6f6 0%,#dedede 100%);' value='Go!'></form>
            <span style='all:initial; cursor:pointer; display:inline-block; background:#fff; border:1px solid #ccc; border-radius:7px; padding:5px 10px 5px 10px;' onclick=\"var box = document.getElementById('miniFormBoxes'); if (box.style.display=='none') { box.style.display = 'inline'; this.innerHTML = 'X'; } else { box.style.display = 'none'; this.innerHTML = '+'; }\">+</span></div>";
        $this->addMiniFormCode($form);

        //Load plugins for running functions when ready
        foreach(glob(BASE_DIRECTORY."plugins".DS."*") as $plugin) {
            if (is_dir($plugin)) {
                foreach (glob($plugin.DS."*.php") as $folderPlugin) {
                    include("$folderPlugin"); //Load plugin PHP file from folder into script
                }
            }
            elseif (pathinfo($plugin,PATHINFO_EXTENSION)=="php") {
                include("$plugin"); //Load plugin PHP file into script for running later
            }
        }

        $this->callAction("onStart", array("",&$this->URL,$this)); //Run onStart function for plugins
        if ($this->blacklistIPs && preg_match('#('.implode("|", $this->blacklistIPs).')#', $_SERVER['REMOTE_ADDR'], $i)) { throw new Exception("You are currently not permitted on this server."); }
    }

    public function errorHandler($exception) {
        if (is_object($exception) && trim(strtolower(@get_class($exception)))=="exception") {
            $message = trim($exception->getMessage()); //Get message from exception

            if (!empty($message)) {
                echo $message; //Output message to screen, script will be terminated automatically
            }
        }
    }

    public function addMiniFormCode($code) {
        $this->miniForm = $code; //Add the mini-form code to injecting later
        return true;
    }

    public function getURL() {
        return $this->URL; //Return URL as it cannot be accessed publicly
    }

    public function setURL($URL) {
        if (!empty($URL)) {
            $this->URL = $this->modifyURL($URL); //Set the new URL with any changes needed
            return true;
        }

        return false;
    }

    public function blacklistWebsite($website) {
        if (!is_array($website)) { $website = (array) $website; } //Convert to array format if not already

        foreach ($website as $u) {
            if (!empty($u) && is_string($u)) { //Check that the URL is valid
                $this->blacklistWebsites[] = $u; //Add individual URL to the array

                if (preg_match('#('.$u.')#', $this->URL, $d)) {
                    //URL is not permitted, so send new error to stop script
                    if (empty($d[0])) { $d[0] = parse_url(trim($this->URL), PHP_URL_HOST); }
                    throw new Exception("Access to ".$d[0]." is not permitted on this server.");
                }
            }
        }

        return true;
    }

    public function blacklistIPAddress($IP) {
        if (!is_array($IP)) { $IP = (array) $IP; } //Convert to array format if not already

        foreach ($IP as $u) {
            if (!empty($u) && is_string($u)) { //Check that the IP is valid
                $this->blacklistIPs[] = $u; //Add individual IP to the array

                if (preg_match('#('.$u.')#', $_SERVER['REMOTE_ADDR'], $i)) {
                    //IP has been banned, so send new error to deny access to script
                    throw new Exception("You are currently not permitted on this server.");
                }
            }
        }

        return true;
    }

    public function proxyURL($URL) {
        $regex = preg_replace(array("#[a-z]+://#i", "#".basename($_SERVER['PHP_SELF'])."#i"),array("(http(s|)://|)", "(".basename($_SERVER['PHP_SELF'])."|)"),cdURL)."\?.*?=";
        if (!empty($URL) && !preg_match("#".$regex."#i",$URL)) {
            parse_str($anchor = parse_url($URL,PHP_URL_FRAGMENT), $parseFrag); //Find anchors if any are in original URL
            if ($anchor && count($parseFrag)<=1) { $anchor = "#".$anchor; $URL = str_replace($anchor,"",$URL); } else { $anchor = ""; }

            //Recompile the new proxy URL with anchors if available
            if ($this->encryptURLs) { $URL = base64_encode($URL); } else { $URL = rawurlencode($URL); }
            $URL = cdURL."?".(empty($this->getParam) ? "URL" : $this->getParam)."=".$URL.$anchor;
        }

        return $URL; //Return compiled proxy URL
    }

    public function unProxyURL($URL) {
        $regex = preg_replace(array("#[a-z]+://#i", "#".basename($_SERVER['PHP_SELF'])."#i"),array("(http(s|)://|)", "(".basename($_SERVER['PHP_SELF'])."|)"),cdURL)."\?.*?=";
        if (!empty($URL) && preg_match("#".$regex."#i",$URL)) {
            $URL = preg_replace("!".$regex."!i", "", $URL); //Remove useless string from beginning of URL, revealing only GET param value
            if (!empty($URL)) {
                preg_match("/[a-z0-9\+\/]+([\=]+|)/i",$URL,$matches); if (strlen($matches[0]) % 4 != 0 || !($decode = base64_decode($matches[0], true))) { preg_match("/[a-z0-9\+]+([\=]+|)/i",$URL,$matches); $decode = base64_decode($matches[0], true); }
                if ((base64_encode($decode)===$matches[0] || $decode) && filter_var("http://".$decode, FILTER_VALIDATE_URL)) { $URL = str_replace($matches[0], $decode, $URL); } else { $URL = rawurldecode($URL); }
            }
        }

        return $URL; //Return decoded proxy URL
    }

    public function modifyURL($URL) {
        if (!preg_match("#http(s|)://#is", substr($URL = htmlspecialchars_decode(trim($URL)),0,8))) {
            $currentURL = $this->URL;
            if ($URL!="/" && $URL!="#" && (@$URL[0]!="/" || strpos(substr($URL,0,3), "./")!==false || substr($URL,0,2)=="//")) {
                while (substr($URL,0,1)=="/") { $URL = substr($URL,1); } //Remove any rough slashes
                $validDomainName = (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", parse_url("http://".$URL, PHP_URL_HOST))
                        && preg_match("/^.{1,253}$/", parse_url("http://".$URL, PHP_URL_HOST))
                        && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", parse_url("http://".$URL, PHP_URL_HOST))) && $this->convertExtension(pathinfo(parse_url("http://".$URL, PHP_URL_HOST), PATHINFO_EXTENSION))=="URL";

                if (!$validDomainName && parse_url($currentURL, PHP_URL_HOST)) {
                    if (!empty($URL) || trim(pathinfo($URL,PATHINFO_EXTENSION))) {
                        $path = parse_url(explode("?",$currentURL)[0], PHP_URL_PATH); //Find path from original URL
                        if (pathinfo(pathinfo(explode("?",$currentURL)[0],PATHINFO_BASENAME),PATHINFO_EXTENSION)!="") {
                            $path = str_replace(pathinfo(explode("?",$currentURL)[0],PATHINFO_BASENAME),"",$path); //Remove path if needed
                        }

                        //Remove any slashes from end of URL which are not needed
                        while (substr($path,strlen($path)-1,strlen($path))=="/") { $path = substr($path,0,strlen($path)-1); }
                        $URL = $path."/".$URL; //Recompile the URL so that it is valid
                    }
                }
            }

            $host = ((isset($validDomainName) && !$validDomainName) || @$URL[0]=="/" ? (($s = parse_url($currentURL, PHP_URL_SCHEME))!="" ? strtolower($s)."://" : "").parse_url($currentURL, PHP_URL_HOST) : (($s = parse_url($URL, PHP_URL_SCHEME))!="" ? strtolower($s) : "http")."://");
            $URL = ($URL=="#" ? $currentURL : $host.$URL); //Compile all needed URL components
            while(preg_match("!/[A-Za-z0-9_]+/\.\./!",$URL)) { $URL = preg_replace("!/[A-Za-z0-9_]+/\.\./!","/",$URL); }
        }

        return str_replace(" ","+",$URL);
    }

    public static function addAction($function, $event, $case = "") {
        if (function_exists($function) && !empty($function) && !empty($event)) { //Validate parameters sent
            if (!isset(self::$pluginFunctions[$event][$function])) {
                self::$pluginFunctions[$event][$function] = $case; //Add function (and case if specified) to array
                return isset(self::$pluginFunctions[$event][$function]); //Check if array was added properly
            }
        }

        return false;
    }

    public function callAction($event, $vars = array()) {
        if (isset(self::$pluginFunctions[$event])) {
            foreach (@self::$pluginFunctions[$event] as $function => $case) {
                if (@preg_match($case, $this->URL) || empty($case)) { //If needed run against URL for specific case
                    @call_user_func_array($function,$vars); //Run plugin function with variables
                }
            }
        }

        return (count(@self::$pluginFunctions[$event])>0 ? true : false); //Return if a bool for run functions
    }

    public function getRunningPlugins() {
        $plugins = array();
        foreach (get_included_files() as $file) { //Get all loaded files
            if (strpos($file, BASE_DIRECTORY."plugins".DS)!==false) { //Check if file is in plugins folder
                $plugins[] = $file; //File is a plugin, so add it to the array
            }
        }

        return $plugins; //Return any plugins found
    }

    public function getPluginFunctions() {
        $functions = array();
        foreach (@self::$pluginFunctions as $hook => $fns) { //Loop through all the hooks
            foreach ($fns as $name => $case) {
                //Validate the function and then add to array for returning later
                if (function_exists($name)) { $functions[$hook][] = $name; }
            }
        }

        return $functions; //Return the initialised functions
    }

    public function createCookieDIR($autoCreateFile = true) {
        $cookieFN = base64_encode($_SERVER['REMOTE_ADDR']); //Generate cookie file name
        $this->cookieDIR = dirname(__FILE__).DS.'cookies'.DS.$cookieFN.".txt";

        if (!file_exists(dirname($this->cookieDIR)) && $autoCreateFile) {
            mkdir(dirname($this->cookieDIR),0777); //Create cookie DIR if not already set
        }

        return file_exists(dirname($this->cookieDIR)); //Return if the cookie file was created
    }

    public function clearCookies() {
        //Delete cookie file and clear browser cookies (excluding the "PHPSESSID" cookie)
        $cookies = $_COOKIE; unset($cookies["PHPSESSID"]); foreach ($cookies as $name => $cookie)  { setcookie($name, '', time() - 1000); setcookie($name, '', time() - 1000, '/'); }
        if (!empty($this->cookieDIR) && file_exists($this->cookieDIR)) { $deleted = @unlink($this->cookieDIR); } else { $deleted = true; }

        return $deleted && count($_COOKIE)<count($cookies);
    }

    public function openPage() {
        if (!empty($this->URL)) {
            $page = ""; session_write_close(); //Used for simultaneous loading of pages
            $this->callAction("preRequest", array(&$page,&$this->URL,$this)); //Run preRequest function for plugins

            if ($this->allowCookies) { $this->createCookieDIR(true); } //Run cookie DIR function if cookies are enabled
            $return = $this->curlRequest($this->URL, $_GET, $_POST); //Run the cURL function to get the page for parsing

            $this->HTTP = $return["HTTP"]; $this->contentType = $return["contentType"]; //Set variables which may be useful later
            if (!$this->HTTP) { throw new Exception("Could not resolve host: ".(($h = parse_url($this->URL,PHP_URL_HOST))!="" ? $h : $this->URL)); } //Check that page was resolved right
            if ($this->blacklistWebsites && preg_match('#('.implode("|", $this->blacklistWebsites).')#', $this->URL, $d)) { throw new Exception("Access to ".$d[0]." is not permitted on this server."); }
            if ($this->URL!=$return["URL"]) { @header("Location: ".$this->proxyURL($return["URL"])); exit; } //Go to new proxy URL if curl has been redirected to it

            $this->logAction($this->HTTP, $this->URL); //Log URL and HTTP code to file
            if (is_null($return["page"])) { return null; } else { $page .= $return["page"]; } //Check that content hasn't already been outputted, so needs parsing

            $this->callAction("postRequest", array(&$page,&$this->URL,$this)); //Run postRequest function for plugins

            if (!empty($page) || strlen($page)>0) {
                $this->callAction("preParse", array(&$page,&$this->URL,$this)); //Run preParse function for plugins

                if (in_array($this->convertExtension($this->contentType),array("html","php"))) {
                    //Encrypt JS, fixes issues with DOM incorrectly editing and then breaking it
                    if (!$this->stripJS) { $encJS = array(); preg_match_all('/<script.*?>(.*?)<\/script>/s', $page, $e); foreach ($e[1] as $match) { if (trim($match)) { $page = str_replace($match,"CD_JS_PLACEHOLDER",$page); $encJS[] = $match; } } }

                    $html = new DOMDocument();
                    $html->preserveWhiteSpace = false; $html->formatOutput = false;
                    @$html->loadHTML($page, (strpos($page,"<head>")>150 ? LIBXML_HTML_NOIMPLIED : null)); //Add implied tags when page isn't too big
                    $removeElements = array();

                    //Parse META redirect URLs, fav icons and content types
                    foreach($html->getElementsByTagName("meta") as $element) {
                        if (strtolower($element->getAttribute("http-equiv"))=="refresh") {
                            $content = $element->getAttribute("content");

                            if (!empty($content)) {
                                $modURL = preg_replace("/[\"'](.*?)[\"']/is","$1",@explode("url=", strtolower($content))[1]); //Find URL from content attribute
                                if (!empty($modURL)) {
                                    $moddedURL = $this->proxyURL($this->modifyURL($modURL)); //Fix and then proxy the URL
                                    $element->setAttribute("content",str_replace($modURL,$moddedURL,$content)); //Change old URL in content attribute
                                }
                            }
                        }
                        elseif (strtolower($element->getAttribute("http-equiv"))=="content-type" || $element->getAttribute("charset")) {
                            if ($element->getAttribute("charset")) {
                                $element->setAttribute("charset","UTF-8");
                            }
                            else{
                                $content = $element->getAttribute("content");
                                $element->setAttribute("content", trim(preg_replace("#(;\s)charset=(.*)#is","charset=UTF-8",$content)));
                            }
                        }
                        elseif (strtolower($element->getAttribute("itemprop"))=="image" || in_array(strtolower($element->getAttribute("property")), array("og:image","og:url")) || in_array(strtolower($element->getAttribute("rel")), array("shortcut icon","icon"))) {
                            if ($element->hasAttribute("href")) { $t = "href"; } else { $t = "content"; }
                            $modURL = $element->getAttribute($t);

                            if (!empty($modURL) && !empty($t)) {
                                $moddedURL = $this->proxyURL($this->modifyURL($modURL)); //Fix and proxy URL from href
                                $element->setAttribute($t,$moddedURL); //Set href attribute to new URL
                            }
                        }
                    }

                    foreach (array("img","a","area","script","noscript","link","iframe","frame", "base") as $tag) {
                        foreach($html->getElementsByTagName($tag) as $element) {
                            if ($this->stripJS && $element->tagName=="script") {
                                $removeElements[] = $element; //Remove script tags if stripJS is enabled
                            }
                            elseif ($element->tagName=="noscript") {
                                if ($this->stripJS) {
                                    while($element->hasChildNodes()) { //Check that the noscript element has any content
                                        $child = $element->removeChild($element->firstChild);
                                        $element->parentNode->insertBefore($child, $element); //Prepend contents of noscript
                                    }
                                    $removeElements[] = $element; //Remove noscript as content has been prepended now
                                }
                            }
                            else{
                                if ($element->hasAttribute("data-thumb") && $element->tagName=="img") { $element->setAttribute("src",$element->getAttribute("data-thumb")); } //Relocate data-thumb vars to src
                                if ($element->hasAttribute("data-src") && filter_var($element->getAttribute("data-src"), FILTER_VALIDATE_URL) && $element->tagName=="img") { $element->setAttribute("src",$element->getAttribute("data-src")); } //Relocate data-src vars to src
                                if ($element->hasAttribute("srcset")) { $element->removeAttribute("srcset"); } //Remove srcset vars
                                if (method_exists($element->parentNode,"hasAttribute")) { if ($element->parentNode->hasAttribute("data-ip-src") && $element->tagName=="img") { $element->setAttribute("src",$element->parentNode->getAttribute("data-ip-src")); } } //Relocate data-src vars to src
                                if ($element->hasAttribute("href")) { $t = "href"; } else { $t = "src"; }

                                $modURL = $element->getAttribute($t);

                                if (!preg_match("/^(javascript:|mailto:|data:)/is",$modURL) && !empty($modURL) && isset($modURL)) {
                                    $moddedURL = $this->modifyURL($modURL); //Fix URL from element
                                    $element->setAttribute($t,$this->proxyURL($moddedURL)); //Use proxyURL then set the element value to it
                                }
                            }
                        }
                    }

                    foreach (array("video","source","param","embed","object") as $tag) {
                        foreach($html->getElementsByTagName($tag) as $element) {
                            if (!$this->stripObjects) {
                                if ($element->tagName=="embed" || $element->tagName=="source" || $element->tagName=="video") {
                                    if ($element->getAttribute("src")!="") {
                                        $moddedURL = $this->proxyURL($this->modifyURL($element->getAttribute("src")));
                                        $element->setAttribute("src", $moddedURL); //Set src attribute of video elements
                                    }
                                }
                                elseif($element->tagName=="object") {
                                    if ($element->getAttribute("data")!="") {
                                        $moddedURL = $this->proxyURL($this->modifyURL($element->getAttribute("data")));
                                        $element->setAttribute("data", $moddedURL); //Set data attribute of object elements
                                    }
                                }
                                elseif($element->tagName=="param" && $element->getAttribute("name")=="movie") {
                                    $moddedURL = $this->proxyURL($this->modifyURL($element->getAttribute("value")));
                                    $element->setAttribute("value",$moddedURL); //Set value attribute of param elements
                                }
                            }
                            else{
                                $removeElements[] = $element; //Remove element if stripObjects is enabled
                            }
                        }
                    }

                    foreach($html->getElementsByTagName("form") as $element) {
                        if (!$action = $element->getAttribute("action")) { $action = "#"; }
                        $element->setAttribute("action",$this->proxyURL($this->modifyURL($action)));

                        if (strtoupper(trim($element->getAttribute("method")))!="POST") {
                            $element->setAttribute("method","POST"); //Force method to be POST

                            $newE = $html->createElement("input","");
                            $newE->setAttribute("type","hidden");
                            $newE->setAttribute("name", substr(md5("cdGET"),0,20));

                            $element->appendChild($newE); //Add new attribute to be intercepted later
                        }

                        //Form is multi-part so more parsing needed to allow PHP to use it properly
                        if ($element->getAttribute("enctype")=="multipart/form-data") {
                            foreach($element->getElementsByTagName("input") as $input) {
                                if ($input->getAttribute("name")!="") { //Check for valid input name
                                    $name = rawurlencode($input->getAttribute("name")); //Safely include names of inputs for key values
                                    $input->setAttribute("name", str_replace(array('%5B','%5D'), array('[',']'), $name)); //Reinsert name since it has been parsed
                                }
                            }
                        }
                    }

                    if (count($removeElements)>0)  { //Check for any elements to remove
                        foreach ($removeElements as $element) {
                            $element->parentNode->removeChild($element); //Remove each element
                        }
                    }

                    $page = @$html->saveHTML();
                    if (isset($encJS)) { foreach ($encJS as $match) { $page = implode($match, explode("CD_JS_PLACEHOLDER", $page, 2)); } } //Add back JS values as parsing using DOM is done
                }

                if (in_array($this->convertExtension($this->contentType), array("html","php","js","css"))) {
                    $multiJC = array(); $preg_matches = array();

                    if ($this->convertExtension($this->contentType)=="js") {
                        if (!$this->stripJS) {
                            $multiJC = (array)$page;  //If the page is JS, add page to array for parsing
                            $preg_matches = array("!(?<URL>[^\"]+)!", '!(?<URL>[^\']+)!'); //Use 2 separate regex for more accurate detection of string values
                        }
                    }
                    elseif ($this->convertExtension($this->contentType)=="css") {
                        $multiJC = (array)$page; //If page is CSS, add page to array for parsing
                        $preg_matches = array("/url\((\"|'|)(?<URL>\S+)(\"|'|)\s*\)/iU", '~@import([\\S+]|)[\'"](?<URL>.*?)[\'"]~i');
                    }
                    else {
                        if (!$this->stripJS) { //Find any JS values if stripJS is set to false
                            preg_match_all('!(\son[a-zA-Z]+)\s*=\s*([\"\'])(.*?)\\2!si', $page, $events); //Find attribute scripts
                            preg_match_all("/<script.*?>(.*?)<\/script>/is", $page, $scripts); //Find all script elements

                            if (isset($events[3]) || isset($scripts[1])) {
                                $multiJC = array_merge($multiJC, $events[3]);
                                $multiJC = array_merge($multiJC, $scripts[1]);
                                $preg_matches = array("!(?<URL>[^\"]+)!", '!(?<URL>[^\']+)!');
                            }
                        }

                        preg_match_all("/<!--(.*?)-->/s", $page, $commentTags); //Find all html comments (could contain if IE code)
                        if (isset($commentTags[0])) { $multiJC = array_merge($multiJC, $commentTags[0]); $preg_matches = array("!(?<URL>[^\"]+)!", '!(?<URL>[^\']+)!'); } //Add html comments to array

                        preg_match_all('!style\s*=\s*([\"\'])(.*?)\\1!si', $page, $inline); //Find inline CSS in attributes
                        preg_match_all("/<style.*?>(.*?)<\/style>/is", $page, $styles); //Find all script elements

                        if (isset($inline[2]) || isset($styles[1])) {
                            $multiJC = array_merge($multiJC, @$inline[2]); //Add in the inline CSS styles
                            $multiJC = array_merge($multiJC, @$styles[1]); //Add the regular styles which are CSS
                            $preg_matches = array_merge(array("/url\((\"|'|)(?<URL>\S+)(\"|'|)\s*\)/iU", '~@import([\\S+]|)[\'"](?<URL>.*?)[\'"]~i'),$preg_matches);
                        }
                    }

                    //Remove duplicate code values from array to help save time when parsing
                    $jcArrayOriginal = $jcArray = array_unique($multiJC);

                    if (count($jcArrayOriginal)>0 && count($preg_matches)>0) {
                        foreach ($jcArrayOriginal as $key => $jcContent) {
                            foreach ($preg_matches as $preg) {
                                preg_match_all($preg,$jcContent,$value); //Look for any possible URLs in the code
                                foreach (array_filter($value["URL"]) as $jcURL) {
                                    $ext = ""; $modURL = preg_replace("![\\\\]+/!is","/",urldecode($jcURL)); //Standardise URL to make sure its readable
                                    $modURL = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', (function ($match) { return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE'); }), $modURL);
                                    if (strpos($modURL,".")!==false && $modURL!=".") { //Attempt to establish a extension (com, co.uk, js, php, ect)
                                        $ext = pathinfo(parse_url(explode("?", $modURL)[0],PHP_URL_HOST),PATHINFO_EXTENSION); //Try multiple method of getting a valid extension
                                        if (empty($ext)) { $ext = pathinfo(parse_url("http://".explode("?", $modURL)[0], PHP_URL_HOST),PATHINFO_EXTENSION); }
                                        if (empty($ext)) { $ext = pathinfo(explode("?",preg_replace('/#.*/', '', $modURL))[0], PATHINFO_EXTENSION); }
                                    }

                                    $convertedExt = $this->convertExtension($ext); //Convert extension to content type to check if URL is valid
                                    if (!empty($convertedExt) && $modURL!=".".$ext && !(($modURL[0]=="." && strpos(substr($modURL,0,3),"./")===false) || $modURL[0]=="$")) {
                                        $filter = "http://".explode("?",preg_replace("!^(http(s):|)(//|\.|\/)!is","",$modURL))[0];

                                        //Check if string is a URL of some kind, if so edit it as required
                                        if (($convertedExt!="URL" && pathinfo($filter,PATHINFO_FILENAME)!="")
                                            || ($convertedExt=="URL" && filter_var($filter, FILTER_VALIDATE_URL)!==false)) {
                                            $moddedURL = $this->proxyURL($this->modifyURL($modURL)); //Modify the URL and then proxy it
                                            $s = preg_quote($jcURL); $s = (strlen($s)>500 ? substr_replace($s, '(.*?)', 250, strlen($s)-500) : $s);
                                            $jcArray[$key] = @preg_replace("!".$s."!", $moddedURL, $jcArray[$key], 1); //Add the new URL back into the array
                                        }
                                    }
                                }
                            }

                            if (@$jcArray[$key]!=@$jcArrayOriginal[$key]) { $page = str_replace($jcArrayOriginal[$key], $jcArray[$key], $page); } //Replace old code with fixed code in JS values
                        }
                    }
                }

                if (in_array($this->convertExtension($this->contentType),array("html","php"))) {
                    //Attempt to find a text box to place the URL in, it isn't necessary though
                    $this->miniForm = preg_replace(array("~(type\=[\"']text[\"'].*value\=[\"'])([\"'])~i", "~(value\=[\"'])([\"'].*type\=[\"']text[\"'])~i"),"$1".$this->URL."$2",$this->miniForm);
                    $page = preg_replace("!<body(.*?)>!i", "<body$1>".PHP_EOL.preg_replace('/[\s\t\n\r\s]+/', ' ',$this->miniForm), $page, 1); //Add the mini-form to the top of the body

                    $injectCode = "<div style='".base64_decode("cG9zaXRpb246Zml4ZWQ7IGJhY2tncm91bmQ6IzAwMDsgcG9pbnRlci1ldmVudHM6IG5vbmU7IHotaW5kZXg6OTk5OTk5OTk5OTsgcmlnaHQ6MTVweDsgYm90dG9tOjEwcHg7IHBhZGRpbmc6NXB4OyBvcGFjaXR5OjAuMzsgY29sb3I6I2ZmZjsgZm9udC1zaXplOjEzcHg7IGZvbnQtZmFtaWx5OiBcIlRpbWVzIE5ldyBSb21hblwiLCBUaW1lcywgc2VyaWY7IGxpbmUtaGVpZ2h0OiBpbml0aWFsICFpbXBvcnRhbnQ7")."'>".base64_decode("UG93ZXJlZCBCeSBDZW5zb3IgRG9kZ2UgVg==").$this->version."</div>";
                    if (!$this->stripJS) { //Add custom coded JS parser to page if JS isn't being stripped
                        $injectCode .= preg_replace('/[\s\t\n\r\s]+/', ' ', "<script type=\"text/javascript\">target='".base64_encode(explode("?", $this->URL)[0])."';
                              function init() { if (arguments.callee.done) { return; } if (target && document.forms.length) { if (typeof(document.forms[0].u)=='object') { if (document.forms[0].u.value=='') { document.forms[0].u.value=atob(target); } } } arguments.callee.done = true; if (_timer) clearInterval(_timer); }
                              if (document.addEventListener) { document.addEventListener('DOMContentLoaded', init, false); } if (/WebKit/i.test(navigator.userAgent)) { var _timer = setInterval(function() { if (/loaded|complete/.test(document.readyState)) { init(); } }, 10); } window.onload = init;</script>");
                    }
                    $page = preg_replace("!</body>!i", $injectCode.PHP_EOL."</body>", $page, 1); //Add the code to the end of the body
                }

                $this->callAction("postParse", array(&$page,&$this->URL,$this)); //Run postParse function for plugins
            }
            else {
                throw new Exception("Unable to load page content."); //Page was resolved but no content was returned
            }

            $this->callAction("onFinish", array(&$page,&$this->URL,$this)); //Run onFinish function for plugins
            return $page; //Return fully parsed page
        }

        return null; //Return null as no URL was set
    }

    private function convertExtension($convert) {
        $rules = array(
            "text/javascript" => "js",
            "application/javascript" => "js",
            "application/x-shockwave-flash" => "swf",
            "audio/x-wav" => "wav",
            "video/quicktime" => "mov",
            "video/x-msvideo" => "avi",
            "text/*" => array("php","html","htm","css","xml","plain"),
            "application/*" => array("pdf","zip","xml"),
            "font/*" => array("ttf","otf","woff","eot"),
            "image/*" => array("jpeg","jpg","gif","png","svg"),
            "video/*" => array("3gp","mreg","mpg","mpe","mp3"),
            "URL" => array("a[c-gilmoqs-uwxz]","arpa","asia","b[abd-jm-or-twyz]","biz","c[acdf-ik-oru-z]","cat","com","coop","d[ejkmoz]","e[cegrtu]","edu",
                "f[i-kmor]","g[ad-il-npqs-uwy]","gov","h[mnrtu]","i[elnoq-t]","info","int","j[emop]","jobs","k[eg-imnprwyz]","l[a-cikr-vy]","m[ac-eghk-su-z]","mil",
                "mobi","museum","n[ace-gilopruz]","net","om","org","p[af-hkmnr-twy]","post","pro","qa","r[eosuw]","rocks","s[a-eg-ik-ort-vx-z]", "t[cdf-hj-otvwz]",
                "travel","u[ksyz]","v[acgiu]","w[fs]","world","y[te]","xxx","z[amw]")
        );

        if (!empty($convert)) {
            $isContentType = strpos($convert,"/")!==false; //Check if value is a content type
            $cExt = ""; if ($isContentType) { $cExt = explode("/",$convert)[1]; }
            foreach ($rules as $key => $ext) {
                if (strpos($key, "*")!==false && $isContentType) { $key = str_replace("*",$cExt,$key); } //Replace * with extension submitted in content type
                if ($key==$convert || !$isContentType) {
                    foreach((array)$ext as $e) {
                        if ($isContentType && (preg_match("!^".$e."$!i",$cExt) || count($ext)==1)) {
                            return $e; //Return validated content type
                        }
                        elseif (!$isContentType && preg_match("!^".$e."$!i",$convert)) {
                            return $key; //Return validated extension
                        }
                    }
                }
            }
        }

        return false; //No matching conversion has been found
    }

    private function logAction($HTTP, $URL) {
        if ($this->logToFile && !empty($URL)) {
            $dir = BASE_DIRECTORY.DS."logs".DS; if (!file_exists($dir)) { mkdir($dir, 0777); } //Create logs DIR if not found already
            $line = "[".date("H:i:s d-m-Y")."][".$_SERVER["REMOTE_ADDR"]."][$HTTP] ".$URL.PHP_EOL;
            $attempt = file_put_contents($dir.date("d-m-Y").".txt", $line, FILE_APPEND | LOCK_EX);
            
            return ($attempt!==false); //Return write attempt boolean
        }

        return false; //Logging was disabled or no URL was submitted, so return false
    }

    public function parseLogFile($logFileName = "ALL") {
        $parsedFile = array(); $logs = "";

        if (file_exists(BASE_DIRECTORY.DS."logs".DS.$logFileName) || trim(strtoupper($logFileName))=="ALL") {
            if (trim(strtoupper($logFileName))=="ALL") { $logFileName = "*.txt"; } //Loop through all files with txt format if is null
            foreach (glob(BASE_DIRECTORY."logs".DS.$logFileName) as $file) { $logs .= file_get_contents($file); }

            if (!empty($logs)) {
                foreach(explode(PHP_EOL, trim($logs)) as $line) {
                    preg_match("/\[(.*?)\]\[(.*?)\]\[([a-zA-Z0-9]+)\](.*?)/isU", $line, $matches); unset($matches[0]); //Parse format of log files
                    if (count($matches)>0) { $parsedFile[] = array_combine(array("time", "IP", "HTTP", "URL"), $matches); } //Add array to complete parsed file array
                }
            }
        }

        return $parsedFile; //Return array format of file
    }

    public function sortParsedLogFile($parsedLogArray, $sortingVar) {
        $sortedArray = array();

        if ((is_array($parsedLogArray) && count($parsedLogArray)>0) && !empty($sortingVar)) { //Check for all needed vars before sorting
            foreach ($parsedLogArray as $log) {
                $var = @array_change_key_case($log, CASE_LOWER)[trim(strtolower($sortingVar))]; //Make array lookup of sorting var case insensitive

                if (parse_url(trim(explode("?",$var)[0]),PHP_URL_HOST)) { //Check if string is URL, and normalize it for better sorting
                    preg_match("/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i", parse_url(trim(explode("?",$var)[0]),PHP_URL_HOST), $d);
                    if (isset($d["domain"]) && !empty($d["domain"])) { $var = $d["domain"]; } else { $var = parse_url(trim($var),PHP_URL_HOST); }
                }

                if (!is_array(@$sortedArray[$var])) { @$sortedArray[$var] = array(); } //Set a default value for each item
                $sortedArray[$var][] = $log; //Add new log to into the array
            }
        }

        return $sortedArray; //Return dynamically sorted array
    }

    public function curlRequest($URL, $getParameters = array(), $postParameters = array()) {
        unset($getParameters[$this->getParam]); unset($_GET[substr(md5("cdGET"),0,20)]);
        $curl = curl_init((count($getParameters)>0) ? $URL."?".http_build_query($getParameters) : $URL); //Add GET params to base URL
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); //Get page content back
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); //Follow page redirects
        curl_setopt($curl, CURLOPT_ENCODING, "gzip, UTF-8"); //Force encoding to be UTF-8 or gzip
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Accept:")); //Add a basic Accept header

        curl_setopt_array($curl, array( //Add some settings to make the cURL request more efficient
            CURLOPT_TIMEOUT => false, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_DNS_CACHE_TIMEOUT => 200,
            CURLOPT_SSL_VERIFYHOST => ($this->isSSL ? 2 : 0), CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_LOW_SPEED_LIMIT => 5, CURLOPT_LOW_SPEED_TIME => 20,
        ));
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, (function ($curl, $p) use (&$body, $URL) {
            if (empty($ct)) { $ct = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); } //Get content type if not already captured
            if (preg_match("#(video/|image/)#i", $ct)) { $body = null; echo $p; } else { $body .= $p; } //Just output page if no parsing needed (quicker)
            return strlen($p);
        }));
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, (function ($curl, $hl) use (&$body, $URL, &$headersAdded) {
            $allowedHeaders = array('content-disposition', 'last-modified', 'cache-control', 'content-type',  'content-range',  'content-language',  'expires', 'pragma');
            if (!isset($headersAdded['content-disposition'])) { $headersAdded["content-disposition"] = true; header('Content-Disposition: filename="'.pathinfo(explode("?",$this->URL)[0],PATHINFO_BASENAME).'"'); }
            $hn = strtolower(explode(":",$hl,2)[0]); if (in_array($hn, $allowedHeaders) && $curl) { $headersAdded[$hn] = true; header($hl); } //Control which headers are set as we receive them from cURL
            return strlen($hl);
        }));

        //Set user agent, referrer, cookies and post parameters based on 'virtual' browser values
        if (!is_null($this->customUserAgent)) { curl_setopt($curl, CURLOPT_USERAGENT, $this->customUserAgent); } else { curl_setopt($curl, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']); }
        if (!is_null($this->customReferrer)) { curl_setopt($curl, CURLOPT_REFERER, $this->customReferrer); } else { curl_setopt($curl, CURLOPT_REFERER, (!preg_match("#".preg_replace(array("#[a-z]+://#i", "#".basename($_SERVER['PHP_SELF'])."#i"), array("(http(s|)://|)", "(".basename($_SERVER['PHP_SELF'])."|)"), cdURL)."#is", $r = $this->unProxyURL(@$_SERVER["HTTP_REFERER"]))) ? $r : "" ); }
        if ($this->allowCookies) { $cookies = $_COOKIE; unset($cookies["PHPSESSID"]); $cs = ""; foreach( $cookies as $key => $value ) {  if (!is_array($value)) { $cs .= "$key=".$value."; "; } } curl_setopt($curl, CURLOPT_COOKIE, $cs); curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookieDIR); curl_setopt($curl, CURLOPT_COOKIEJAR, $this->cookieDIR); } //Set cookie file in CURL
        if (count($postParameters)>0) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, (count($_FILES)>0 ? $postParameters : http_build_query($postParameters))); } //Send POST values using cURL
        curl_setopt_array($curl, $this->curlSettings); //Use cURL settings array before running

        curl_exec($curl); //Run cURL with settings set previously
        $vars = array("URL" => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
            "contentType" => explode(";", curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0],
            "HTTP" => curl_getinfo($curl, CURLINFO_HTTP_CODE), "page" => $body
        );
        curl_close($curl); //Close curl connection safely once complete

        return $vars;
    }
}