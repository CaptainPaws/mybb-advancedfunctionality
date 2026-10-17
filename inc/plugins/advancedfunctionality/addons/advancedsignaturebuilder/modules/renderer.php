<?php
if (!defined('IN_MYBB')) die('No direct access');
function af_asb_render(array $v,bool $viewerImages=true,bool $editorPreview=false): string {
    $esc=static fn($s)=>htmlspecialchars_uni($s);$images=$viewerImages && !empty($GLOBALS['mybb']->settings['sigmycode']) && !empty($GLOBALS['mybb']->settings['sigimgcode']);
    $text=static fn($s)=>$esc(function_exists('bad_word_filter')?bad_word_filter($s):$s);
    // Labels belong only to this rendering call, never to the persisted content model.
    if ($editorPreview) {
        if ($v['title']==='') $v['title']='Заголовок подписи';
        if ($v['subtitle']==='') $v['subtitle']='Подзаголовок';
        if ($v['text']==='') $v['text']=$v['template']==='quote'?'Текст цитаты':'Текст подписи';
    }
    $html='<section class="af-asb-signature af-asb-signature--'.$v['template'].'">';
    $html.='<div class="af-asb-signature__layout">';$pictures='';
    foreach (af_asb_used_images($v) as $slot=>$image) {
        $url=$images?af_asb_image_url($image):'';
        if ($url!=='') $pictures.='<img src="'.$esc($url).'" alt="" loading="lazy">';
        elseif ($editorPreview) $pictures.='<span class="af-asb-signature__placeholder" role="img" aria-label="Изображение '.($slot+1).'"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="2"/><path d="m2 18 6-7 5 5 4-6 5 8"/><circle cx="8" cy="7" r="1"/></svg>Изображение '.($slot+1).'</span>';
    }
    if ($pictures!=='') $html.='<div class="af-asb-signature__images">'.$pictures.'</div>';
    $html.='<div class="af-asb-signature__copy">';
    foreach (['title'=>'h3','subtitle'=>'p'] as $key=>$tag) if ($v['show_'.$key] && $v[$key]!=='') $html.='<'.$tag.' class="af-asb-signature__'.$key.'">'.$text($v[$key]).'</'.$tag.'>';
    if (($v['show_divider']??true) && (($v['show_title'] && $v['title']!=='') || ($v['show_subtitle'] && $v['subtitle']!=='')) && $v['text']!=='') $html.='<hr class="af-asb-signature__divider">';
    if ($v['text']!=='') {$tag=$v['template']==='quote'?'blockquote':'div';$html.='<'.$tag.' class="af-asb-signature__text">'.af_asb_render_body($v,$images).'</'.$tag.'>';}
    if ($v['buttons'] && !empty($GLOBALS['mybb']->settings['sigmycode'])) {$html.='<nav class="af-asb-signature__links" aria-label="Ссылки подписи">';foreach ($v['buttons'] as $button) $html.='<a class="af-asb-signature__button" href="'.$esc($button['url']).'" rel="ugc noopener">'.$text($button['label']).'</a>';$html.='</nav>';}
    if ($v['show_status'] && $v['status']!=='') $html.='<p class="af-asb-signature__status">'.$text($v['status']).'</p>';
    return $html.'</div></div></section>';
}

/** Native signature parser for the editor body only; labels/status always remain escaped plain text. */
function af_asb_render_body(array $model,bool $images): string {
    $source=$model['text'];$settings=$GLOBALS['mybb']->settings;
    if (($model['text_format']??'plain')==='bbcode') {
        if (!class_exists('postParser') && defined('MYBB_ROOT') && is_file(MYBB_ROOT.'inc/class_parser.php')) require_once MYBB_ROOT.'inc/class_parser.php';
        if (class_exists('postParser')) {
            $parser=new postParser();
            return $parser->parse_message($source,['allow_html'=>0,'allow_mycode'=>!empty($settings['sigmycode']),
                'allow_smilies'=>!empty($settings['sigsmilies']),'allow_imgcode'=>$images,'allow_videocode'=>0,
                'filter_badwords'=>1,'nofollow_on'=>1,'nl2br'=>1]);
        }
    }
    return nl2br(htmlspecialchars_uni(function_exists('bad_word_filter')?bad_word_filter($source):$source),false);
}
