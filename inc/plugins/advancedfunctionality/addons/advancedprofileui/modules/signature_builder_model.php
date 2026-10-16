<?php
/** Typed content model and portable MyBB BBCode. No presentation settings live here. */
if (!defined('IN_MYBB')) die('No direct access');
function af_apui_sig_templates(): array {
    return ['minimal'=>['Минимализм','Заголовок, текст и ссылки.'],'quote'=>['Цитата','Главное — ваше высказывание.'],'card'=>['Карточка','Изображение и текст рядом.'],'gallery'=>['Галерея','От одного до трёх изображений.']];
}
function af_apui_sig_defaults(): array {
    $image=['enabled'=>true,'url'=>'','media_id'=>null];
    return ['version'=>1,'template'=>'minimal','title'=>'','show_title'=>true,'subtitle'=>'','show_subtitle'=>true,'text'=>'','status'=>'','show_status'=>true,'background'=>$image,'images'=>[$image,$image,$image],'buttons'=>[]];
}
function af_apui_sig_limits(): array {
    $out=[];foreach(['title'=>100,'subtitle'=>100,'text'=>500,'status'=>100] as $key=>$default) $out[$key]=max(1,min(10000,(int)($GLOBALS['mybb']->settings['af_advancedprofileui_signature_'.$key.'_limit']??$default)));
    return $out;
}
function af_apui_sig_url(string $url,bool $image=false): bool {
    if ($url==='' || strlen($url)>2048 || preg_match('/[\x00-\x20\x7f<>"\'\\\\\[\]]/',$url)) return false;
    $p=parse_url($url);if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme']??''),['http','https'],true) || isset($p['user']) || isset($p['pass'])) return false;
    if (!$image) return filter_var($url,FILTER_VALIDATE_URL)!==false;
    if (!preg_match('/\.(?:png|jpe?g|webp|gif)$/iD',$p['path']??'')) return false;
    $site=parse_url((string)($GLOBALS['mybb']->settings['bburl']??''));
    return ($site && strtolower($p['host'])===strtolower($site['host']??'') && strtolower($p['scheme'])===strtolower($site['scheme']??'') && ($p['port']??null)===($site['port']??null))
        || (function_exists('ag_remote_is_domain_allowed') && ag_remote_is_domain_allowed($p['host']));
}
function af_apui_sig_decode(string $json): array {
    if (strlen($json)>131072) throw new InvalidArgumentException('Слишком большая конфигурация подписи.');
    try {$v=json_decode($json,true,8,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new InvalidArgumentException('Некорректная конфигурация подписи.');}
    if (!is_array($v) || array_is_list($v)) throw new InvalidArgumentException('Нужен объект конфигурации подписи.');return $v;
}
function af_apui_sig_validate(array $v,int $uid,bool $checkMedia=true,bool $publish=false): array {
    $d=af_apui_sig_defaults();if (array_diff(array_keys($v),array_keys($d))) throw new InvalidArgumentException('Неизвестное поле конструктора.');
    $v=array_replace($d,$v);
    if ($v['version']!==1 || !is_string($v['template']) || !isset(af_apui_sig_templates()[$v['template']])) throw new InvalidArgumentException('Неизвестный шаблон подписи.');
    foreach (['show_title','show_subtitle','show_status'] as $key) if (!is_bool($v[$key])) throw new InvalidArgumentException('Некорректный переключатель.');
    foreach (af_apui_sig_limits() as $key=>$max) {
        if (!is_string($v[$key]) || mb_strlen($v[$key])>$max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$v[$key]) || ($key!=='text' && preg_match('/[\r\n]/',$v[$key]))) throw new InvalidArgumentException('Превышен лимит или недопустимый текст: '.$key.' (до '.$max.').');
        $v[$key]=str_replace(["\r\n","\r"],"\n",$v[$key]);
    }
    if (!is_array($v['images']) || !array_is_list($v['images']) || count($v['images'])>3 || !is_array($v['buttons']) || !array_is_list($v['buttons']) || count($v['buttons'])>6) throw new InvalidArgumentException('Допустимы до трёх изображений и шести ссылок.');
    $v['images']=array_pad($v['images'],3,$d['background']);
    $images=array_merge([$v['background']],$v['images']);
    foreach ($images as &$image) {
        if (!is_array($image) || array_diff(array_keys($image),['enabled','url','media_id'])) throw new InvalidArgumentException('Некорректное изображение.');
        $image=array_replace($d['background'],$image);
        if (!is_bool($image['enabled']) || !is_string($image['url']) || ($image['url']!=='' && !af_apui_sig_url($image['url'],true))) throw new InvalidArgumentException('Изображения: прямой PNG/JPG/WebP/GIF на разрешённом домене или загрузка Gallery.');
        $id=$image['media_id'];if ($id!==null && (!is_int($id) || $id<1 || $id>4294967295)) throw new InvalidArgumentException('Некорректный ID изображения.');
        if ($id && $checkMedia) {
            $row=function_exists('ag_get_owned_media')?ag_get_owned_media($uid,$id):null;
            if (!$row || $row['type']!=='local' || $row['status']!=='approved' || !in_array($row['mime'],['image/jpeg','image/png','image/webp','image/gif'],true)) throw new InvalidArgumentException('Изображение должно принадлежать вам и пройти модерацию Gallery.');
        }
        if ($id) $image['url']='';
    }unset($image);
    $v['background']=array_shift($images);$v['images']=$images;
    foreach ($v['buttons'] as $button) if (!is_array($button) || array_diff(array_keys($button),['label','url']) || !is_string($button['label']??null) || trim($button['label'])==='' || mb_strlen($button['label'])>60 || preg_match('/[\x00-\x1f\x7f]/u',$button['label']) || !is_string($button['url']??null) || !af_apui_sig_url($button['url'])) throw new InvalidArgumentException('У ссылки нужны название (до 60 символов) и HTTP(S)-адрес.');
    if (!empty($v['buttons']) && empty($GLOBALS['mybb']->settings['sigmycode'])) throw new InvalidArgumentException('Ссылки в подписи запрещены настройками MyBB.');
    if ($publish && (empty($GLOBALS['mybb']->settings['sigmycode']) || empty($GLOBALS['mybb']->settings['sigimgcode'])) && array_filter(array_merge([$v['background']],af_apui_sig_used_images($v)),static fn($i)=>$i['enabled'] && ($i['media_id'] || $i['url']!==''))) throw new InvalidArgumentException('Изображения в подписи запрещены настройками MyBB.');
    if ($publish && $v['template']==='gallery' && !array_filter($v['images'],static fn($i)=>$i['enabled'] && ($i['media_id'] || $i['url']!==''))) throw new InvalidArgumentException('Для галереи выберите хотя бы одно изображение.');
    return $v;
}
function af_apui_sig_image_url(array $image): string {
    if (empty($image['enabled'])) return '';
    if (!empty($image['media_id'])) return function_exists('ag_resolve_media_url')?(string)ag_resolve_media_url((int)$image['media_id'],'full'):'';
    return af_apui_sig_url($image['url']??'',true)?$image['url']:'';
}
function af_apui_sig_used_images(array $v): array {
    $n=match($v['template']){'card'=>1,'gallery'=>3,default=>0};return array_slice($v['images'],0,$n);
}
function af_apui_sig_plain(string $text): string {
    return str_replace(['[',']'],['&#91;','&#93;'],htmlspecialchars_uni($text));
}
function af_apui_sig_compile(array $v): string {
    $parts=[];$mycode=!empty($GLOBALS['mybb']->settings['sigmycode']);
    // The background remains a normal image in the portable BBCode fallback.
    foreach (array_merge([$v['background']],af_apui_sig_used_images($v)) as $image) if (($url=af_apui_sig_image_url($image))!=='') $parts[]='[img]'.$url.'[/img]';
    foreach (['title'=>'b','subtitle'=>'i'] as $key=>$tag) if ($v['show_'.$key] && $v[$key]!=='') $parts[]=$mycode?'['.$tag.']'.af_apui_sig_plain($v[$key]).'[/'.$tag.']':af_apui_sig_plain($v[$key]);
    if ($v['text']!=='') $parts[]=af_apui_sig_plain($v['text']);
    foreach ($v['buttons'] as $button) $parts[]='[url='.$button['url'].']'.af_apui_sig_plain($button['label']).'[/url]';
    if ($v['show_status'] && $v['status']!=='') $parts[]=af_apui_sig_plain($v['status']);
    return implode("\n",$parts);
}
function af_apui_sig_render(array $v,bool $viewerImages=true): string {
    $esc=static fn($s)=>htmlspecialchars_uni($s);$images=$viewerImages && !empty($GLOBALS['mybb']->settings['sigmycode']) && !empty($GLOBALS['mybb']->settings['sigimgcode']);
    $html='<section class="af-apui-signature-composition af-apui-signature-composition--'.$v['template'].'">';
    if ($images && ($url=af_apui_sig_image_url($v['background']))!=='') $html.='<span class="af-apui-signature-composition__backdrop" aria-hidden="true"><img src="'.$esc($url).'" alt="" loading="lazy"></span>';
    $html.='<div class="af-apui-signature-composition__layout">';$pictures='';
    if ($images) foreach (af_apui_sig_used_images($v) as $image) if (($url=af_apui_sig_image_url($image))!=='') $pictures.='<img src="'.$esc($url).'" alt="" loading="lazy">';
    if ($pictures!=='') $html.='<div class="af-apui-signature-composition__images">'.$pictures.'</div>';
    $html.='<div class="af-apui-signature-composition__copy">';
    foreach (['title'=>'h3','subtitle'=>'p'] as $key=>$tag) if ($v['show_'.$key] && $v[$key]!=='') $html.='<'.$tag.' class="af-apui-signature-composition__'.$key.'">'.$esc($v[$key]).'</'.$tag.'>';
    if ($v['text']!=='') {$tag=$v['template']==='quote'?'blockquote':'p';$html.='<'.$tag.' class="af-apui-signature-composition__text">'.nl2br($esc($v['text']),false).'</'.$tag.'>';}
    if ($v['buttons'] && !empty($GLOBALS['mybb']->settings['sigmycode'])) {$html.='<nav class="af-apui-signature-composition__links" aria-label="Ссылки подписи">';foreach ($v['buttons'] as $button) $html.='<a class="af-apui-signature-composition__button" href="'.$esc($button['url']).'" rel="ugc noopener">'.$esc($button['label']).'</a>';$html.='</nav>';}
    if ($v['show_status'] && $v['status']!=='') $html.='<p class="af-apui-signature-composition__status">'.$esc($v['status']).'</p>';
    return $html.'</div></div></section>';
}

/** Retain editable known values after an error, without trusting arbitrary nested shapes in page generation. */
function af_apui_sig_seed_model(array $raw): array {
    $out=af_apui_sig_defaults();
    foreach (['title','subtitle','text','status'] as $key) if (is_string($raw[$key]??null)) $out[$key]=$raw[$key];
    foreach (['show_title','show_subtitle','show_status'] as $key) if (is_bool($raw[$key]??null)) $out[$key]=$raw[$key];
    if (is_string($raw['template']??null) && isset(af_apui_sig_templates()[$raw['template']])) $out['template']=$raw['template'];
    foreach (['background',0,1,2] as $slot) {
        $src=$slot==='background'?($raw['background']??[]):($raw['images'][$slot]??[]);
        $image=af_apui_sig_defaults()['background'];
        if (is_array($src)) {
            if (is_bool($src['enabled']??null)) $image['enabled']=$src['enabled'];
            if (is_string($src['url']??null)) $image['url']=$src['url'];
            if (is_int($src['media_id']??null)) $image['media_id']=$src['media_id'];
        }
        if ($slot==='background') $out['background']=$image;else $out['images'][$slot]=$image;
    }
    foreach (array_slice(is_array($raw['buttons']??null)?$raw['buttons']:[],0,6) as $button) $out['buttons'][]=['label'=>is_string($button['label']??null)?$button['label']:'','url'=>is_string($button['url']??null)?$button['url']:''];
    return $out;
}
