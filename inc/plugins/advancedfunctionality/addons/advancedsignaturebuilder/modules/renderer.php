<?php
if (!defined('IN_MYBB')) die('No direct access');
function af_asb_render(array $v,bool $viewerImages=true): string {
    $esc=static fn($s)=>htmlspecialchars_uni($s);$images=$viewerImages && !empty($GLOBALS['mybb']->settings['sigmycode']) && !empty($GLOBALS['mybb']->settings['sigimgcode']);
    $text=static fn($s)=>$esc(function_exists('bad_word_filter')?bad_word_filter($s):$s);
    $html='<section class="af-asb-signature af-asb-signature--'.$v['template'].'">';
    if ($images && ($url=af_asb_image_url($v['background']))!=='') $html.='<span class="af-asb-signature__backdrop" aria-hidden="true"><img src="'.$esc($url).'" alt="" loading="lazy"></span>';
    $html.='<div class="af-asb-signature__layout">';$pictures='';
    if ($images) foreach (af_asb_used_images($v) as $image) if (($url=af_asb_image_url($image))!=='') $pictures.='<img src="'.$esc($url).'" alt="" loading="lazy">';
    if ($pictures!=='') $html.='<div class="af-asb-signature__images">'.$pictures.'</div>';
    $html.='<div class="af-asb-signature__copy">';
    foreach (['title'=>'h3','subtitle'=>'p'] as $key=>$tag) if ($v['show_'.$key] && $v[$key]!=='') $html.='<'.$tag.' class="af-asb-signature__'.$key.'">'.$text($v[$key]).'</'.$tag.'>';
    if (($v['show_divider']??true) && (($v['show_title'] && $v['title']!=='') || ($v['show_subtitle'] && $v['subtitle']!=='')) && $v['text']!=='') $html.='<hr class="af-asb-signature__divider">';
    if ($v['text']!=='') {$tag=$v['template']==='quote'?'blockquote':'p';$html.='<'.$tag.' class="af-asb-signature__text">'.nl2br($text($v['text']),false).'</'.$tag.'>';}
    if ($v['buttons'] && !empty($GLOBALS['mybb']->settings['sigmycode'])) {$html.='<nav class="af-asb-signature__links" aria-label="Ссылки подписи">';foreach ($v['buttons'] as $button) $html.='<a class="af-asb-signature__button" href="'.$esc($button['url']).'" rel="ugc noopener">'.$text($button['label']).'</a>';$html.='</nav>';}
    if ($v['show_status'] && $v['status']!=='') $html.='<p class="af-asb-signature__status">'.$text($v['status']).'</p>';
    return $html.'</div></div></section>';
}
