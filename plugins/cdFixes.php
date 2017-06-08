<?php
function FaceBook_preRequest() {
    //Set "m_pixel_ratio" cookie for facebook (only signs in if cookie is set).
    if (!isset($_COOKIE["m_pixel_ratio"])) {
        setcookie("m_pixel_ratio","1");
    }
}

function DailyMotion_postParse(&$page, $URL, $proxy) {
    if(preg_match('/video\/([^_]+)/', $URL, $matches)) { //Check if DailyMotion URL is a video
        $html = $proxy->curlRequest("http://www.dailymotion.com/embed/video/".$matches[1])["page"]; //Get basic embed video source

        if(preg_match_all('#type":"video\\\/mp4","url":"([^"]+)"#is', $html, $matches) && !$proxy->stripObjects) {
            $url = stripslashes(end($matches[1])); //Find the best available video source

            //Build and insert basic video element into page which user can watch
            $embed = "<embed src='".$proxy->proxyURL($url)."' style='box-sizing: border-box; -moz-box-sizing: border-box; -webkit-box-sizing: border-box; width: 100%; height: 100%;' bgcolor='000000' allowscriptaccess='always' allowfullscreen='true' />";
            $page = preg_replace('#\<div\sid\=\"player_container(.*?)>.*?\<\/div\>#s', '<div id="player_container${1} style="width:880px; height:495px;">'.$embed.'</div>', $page, 1);
        }
    }
}

function YouTube_preRequest($page, $URL, $proxy) {
    if (strpos($URL,"common.js")!==false) { exit; } //Fixes a search issue by blocking access to this js file
    if (isset($proxy->customUserAgent)) { $UserAgent = $proxy->customUserAgent; } else { $UserAgent = $_SERVER['HTTP_USER_AGENT']; }

    //Force any mobile user to be put on the YouTube desktop website
    if (preg_match("/m.youtube.[a-zA-z]+/i", $URL) || preg_match("/(iPhone|iPod|iPad|Android|BlackBerry)/isU", $UserAgent)) {
        $proxy->setURL(preg_replace("/m.youtube/i","youtube",$URL));
        $proxy->customUserAgent = "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36";
    }
}

function YouTube_preParse(&$page, $URL, $proxy) {
    if (preg_match('#url_encoded_fmt_stream_map["\']:\s*["\']([^"\'\s]*)#', $page, $url_encoded_fmt_stream_map) && !$proxy->stripObjects) {
        $url_encoded_fmt_stream_map[1] = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', (function ($match) { return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE'); }), $url_encoded_fmt_stream_map[1]);
        $fmt_maps = explode(',', $url_encoded_fmt_stream_map[1]); //Find all video URLs

        foreach($fmt_maps as $fmt_map) {
            $url = $type = ''; parse_str($fmt_map); //Parse values in stream maps
            if ($type!="x-flv") { //See if video is supported by player
                $html = "<video controls autoplay style='width:100%; height:100%;'><source src='".$proxy->proxyURL($url)."'></video>";
                $page = preg_replace('#<div id="player-api"([^>]*)>.*<div class="clear"#s', '<div id="player-api"$1>'.$html.'</div></div><div class="clear"', $page, 1);
                break; //Video added to screen, exit out of loop now
            }
        }
    }

    //Remove advertisement since this helps to speed up page load times
    $page = preg_replace("#<div id=\"video-masthead.*?<\/div>#is",'',$page);
}

censorDodge::addAction("FaceBook_preRequest","preRequest","#facebook.[a-zA-z.]+#i");
censorDodge::addAction("DailyMotion_postParse","postParse","#dailymotion.[a-zA-z.]+#i");
censorDodge::addAction("YouTube_preRequest","preRequest","#(youtube|ytimg).[a-zA-z.]+#i");
censorDodge::addAction("YouTube_preParse","preParse","#youtube.[a-zA-z.]+#i");