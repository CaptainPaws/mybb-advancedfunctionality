<?php
/** Presentation only: MyBB still owns signature visibility, parsing and storage. */
if (!defined('IN_MYBB')) die('No direct access');

function af_adaptivethemeframework_signature_markup(string $html,string $pid,string $frame,bool $open=false): string
{
    if (!$open && (trim($html)==='' || trim(strip_tags($html))==='' && !preg_match('~<(?:img|svg|video|audio|iframe|object|hr|table)\b~i',$html))) return '';
    static $instance=0;
    $id='atf-signature-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$pid).'-'.(++$instance);
    return '<details'.($open?' open':'').' class="atf-post__signature-spoiler" data-atf-signature>'
        .'<summary class="atf-post__signature-toggle" aria-controls="'.$id.'">'
        .'<span class="atf-post__divider atf-post__signature-separator" aria-hidden="true"><i class="atf-post__signature-terminal atf-post__signature-terminal--start"></i><i class="atf-post__signature-terminal atf-post__signature-terminal--end"></i></span>'
        .'<span class="atf-post__signature-label">Подпись</span>'
        .'<span class="atf-post__signature-arrow" aria-hidden="true"></span></summary>'
        .'<div class="atf-post__signature-content" id="'.$id.'">'
        .'<div class="atf-post__signature-frame">'.$frame
        .'<div class="atf-post__signature">'.$html.'</div></div></div></details>';
}

function af_adaptivethemeframework_gothic_frame(): string {
    return '<span class="atf-gothic-frame" aria-hidden="true">'
        . '<i class="atf-gothic-corner atf-gothic-corner--tl"></i><i class="atf-gothic-corner atf-gothic-corner--tr"></i>'
        . '<i class="atf-gothic-corner atf-gothic-corner--bl"></i><i class="atf-gothic-corner atf-gothic-corner--br"></i></span>';
}
