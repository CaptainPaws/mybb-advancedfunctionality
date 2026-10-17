<?php
/** Typed content model and portable MyBB BBCode. No presentation settings live here. */
if (!defined('IN_MYBB')) die('No direct access');
class AF_ASB_FieldException extends InvalidArgumentException {
    public function __construct(string $message,public string $field) { parent::__construct($message); }
}
function af_asb_templates(): array {
    return ['minimal'=>['Минимализм','Заголовок, текст и ссылки.'],'quote'=>['Цитата','Главное — ваше высказывание.'],'card'=>['Карточка','Изображение и текст рядом.'],'gallery'=>['Галерея','От одного до трёх изображений.']];
}
function af_asb_defaults(): array {
    $image=['enabled'=>true,'url'=>'','media_id'=>null];
    return ['version'=>1,'template'=>'minimal','title'=>'','show_title'=>true,'subtitle'=>'','show_subtitle'=>true,'show_divider'=>true,'text'=>'','text_format'=>'plain','status'=>'','show_status'=>true,'images'=>[$image,$image,$image],'buttons'=>[]];
}
function af_asb_limits(): array {
    $out=[];foreach(['title'=>100,'subtitle'=>100,'text'=>500,'status'=>100] as $key=>$default) $out[$key]=max(1,min(10000,(int)($GLOBALS['mybb']->settings['af_advancedsignaturebuilder_signature_'.$key.'_limit']??$default)));
    return $out;
}
function af_asb_url(string $url,bool $image=false): bool {
    if ($url==='' || strlen($url)>2048 || preg_match('/[\x00-\x20\x7f<>"\'\\\\\[\]]/',$url)) return false;
    $p=parse_url($url);if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme']??''),['http','https'],true) || isset($p['user']) || isset($p['pass'])) return false;
    if (!$image) return filter_var($url,FILTER_VALIDATE_URL)!==false;
    if (!preg_match('/\.(?:png|jpe?g|webp|gif)$/iD',$p['path']??'')) return false;
    $site=parse_url((string)($GLOBALS['mybb']->settings['bburl']??''));
    return ($site && strtolower($p['host'])===strtolower($site['host']??'') && strtolower($p['scheme'])===strtolower($site['scheme']??'') && ($p['port']??null)===($site['port']??null))
        || (function_exists('ag_remote_is_domain_allowed') && ag_remote_is_domain_allowed($p['host']));
}
function af_asb_decode(string $json): array {
    if (strlen($json)>131072) throw new InvalidArgumentException('Слишком большая конфигурация подписи.');
    try {$v=json_decode($json,true,8,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new InvalidArgumentException('Некорректная конфигурация подписи.');}
    if (!is_array($v) || array_is_list($v)) throw new InvalidArgumentException('Нужен объект конфигурации подписи.');return $v;
}
function af_asb_validate(array $v,int $uid,bool $checkMedia=true,bool $publish=false): array {
    unset($v['background']); // v1 legacy decoration is never editable content.
    $d=af_asb_defaults();if (array_diff(array_keys($v),array_keys($d))) throw new InvalidArgumentException('Неизвестное поле конструктора.');
    $v=array_replace($d,$v);
    if ($v['version']!==1 || !is_string($v['template']) || !isset(af_asb_templates()[$v['template']])) throw new InvalidArgumentException('Неизвестный шаблон подписи.');
    if (!in_array($v['text_format'],['plain','bbcode'],true)) throw new InvalidArgumentException('Некорректный формат текста.');
    foreach (['show_title','show_subtitle','show_status','show_divider'] as $key) if (!is_bool($v[$key])) throw new InvalidArgumentException('Некорректный переключатель.');
    foreach (af_asb_limits() as $key=>$max) {
        if (!is_string($v[$key]) || ($key==='text' && $v['text_format']==='bbcode'?mb_strlen(html_entity_decode($v[$key],ENT_QUOTES|ENT_HTML5,'UTF-8')):mb_strlen($v[$key]))>$max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$v[$key]) || ($key!=='text' && preg_match('/[\r\n]/',$v[$key]))) throw new InvalidArgumentException('Превышен лимит или недопустимый текст: '.$key.' (до '.$max.').');
        $v[$key]=str_replace(["\r\n","\r"],"\n",$v[$key]);
    }
    if (!is_array($v['images']) || !array_is_list($v['images']) || count($v['images'])>3 || !is_array($v['buttons']) || !array_is_list($v['buttons']) || count($v['buttons'])>6) throw new InvalidArgumentException('Допустимы до трёх изображений и шести ссылок.');
    $v['images']=array_pad($v['images'],3,$d['images'][0]);
    $images=$v['images'];
    foreach ($images as &$image) {
        if (!is_array($image) || array_diff(array_keys($image),['enabled','url','media_id'])) throw new InvalidArgumentException('Некорректное изображение.');
        $image=array_replace($d['images'][0],$image);
        if (!is_bool($image['enabled']) || !is_string($image['url']) || ($image['url']!=='' && !af_asb_url($image['url'],true))) throw new InvalidArgumentException('Изображения: прямой PNG/JPG/WebP/GIF на разрешённом домене или загрузка Gallery.');
        $id=$image['media_id'];if ($id!==null && (!is_int($id) || $id<1 || $id>4294967295)) throw new InvalidArgumentException('Некорректный ID изображения.');
        if ($id && $checkMedia) {
            $row=function_exists('ag_get_owned_media')?ag_get_owned_media($uid,$id):null;
            if (!$row || $row['type']!=='local' || $row['status']!=='approved' || !in_array($row['mime'],['image/jpeg','image/png','image/webp','image/gif'],true)) throw new InvalidArgumentException('Изображение должно принадлежать вам и пройти модерацию Gallery.');
        }
        if ($id) $image['url']='';
    }unset($image);
    $v['images']=$images;
    foreach ($v['buttons'] as $button) if (!is_array($button) || array_diff(array_keys($button),['label','url']) || !is_string($button['label']??null) || trim($button['label'])==='' || mb_strlen($button['label'])>60 || preg_match('/[\x00-\x1f\x7f]/u',$button['label']) || !is_string($button['url']??null) || !af_asb_url($button['url'])) throw new InvalidArgumentException('У ссылки нужны название (до 60 символов) и HTTP(S)-адрес.');
    if (!empty($v['buttons']) && empty($GLOBALS['mybb']->settings['sigmycode'])) throw new InvalidArgumentException('Ссылки в подписи запрещены настройками MyBB.');
    if ($publish && (empty($GLOBALS['mybb']->settings['sigmycode']) || empty($GLOBALS['mybb']->settings['sigimgcode'])) && array_filter(af_asb_used_images($v),static fn($i)=>$i['enabled'] && ($i['media_id'] || $i['url']!==''))) throw new InvalidArgumentException('Изображения в подписи запрещены настройками MyBB.');
    if ($publish && $v['template']==='gallery' && !array_filter($v['images'],static fn($i)=>$i['enabled'] && ($i['media_id'] || $i['url']!==''))) throw new AF_ASB_FieldException('Добавьте хотя бы одно изображение в галерею.','images');
    return $v;
}
function af_asb_image_url(array $image): string {
    if (empty($image['enabled'])) return '';
    if (!empty($image['media_id'])) return function_exists('ag_resolve_media_url')?(string)ag_resolve_media_url((int)$image['media_id'],'full'):'';
    return af_asb_url($image['url']??'',true)?$image['url']:'';
}
function af_asb_used_images(array $v): array {
    $n=match($v['template']){'card'=>1,'gallery'=>3,default=>0};return array_slice($v['images'],0,$n);
}
function af_asb_plain(string $text): string {
    return str_replace(['[',']'],['&#91;','&#93;'],htmlspecialchars_uni($text));
}
/** Decimal references remain literal through MyBB's parse_html() and HTML-enabled native fallbacks. */
function af_asb_editor_plain(string $text): string {
    return str_replace(['&','<','>','[',']'],['&#38;','&#60;','&#62;','&#91;','&#93;'],$text);
}
function af_asb_bbcode_native(string $text): string {
    $text=preg_replace('/&(?!#[0-9]+;)/','&#38;',$text);
    return str_replace(['<','>'],['&#60;','&#62;'],$text);
}
function af_asb_compile(array $v): string {
    $parts=[];$mycode=!empty($GLOBALS['mybb']->settings['sigmycode']);
    foreach (af_asb_used_images($v) as $image) if (($url=af_asb_image_url($image))!=='') $parts[]='[img]'.$url.'[/img]';
    foreach (['title'=>'b','subtitle'=>'i'] as $key=>$tag) if ($v['show_'.$key] && $v[$key]!=='') $parts[]=$mycode?'['.$tag.']'.af_asb_plain($v[$key]).'[/'.$tag.']':af_asb_plain($v[$key]);
    if ($v['text']!=='') $parts[]=($v['text_format']??'plain')==='bbcode'?af_asb_bbcode_native($v['text']):af_asb_plain($v['text']);
    foreach ($v['buttons'] as $button) $parts[]='[url='.$button['url'].']'.af_asb_plain($button['label']).'[/url]';
    if ($v['show_status'] && $v['status']!=='') $parts[]=af_asb_plain($v['status']);
    return implode("\n",$parts);
}
/** Retain editable known values after an error, without trusting arbitrary nested shapes in page generation. */
function af_asb_seed_model(array $raw): array {
    $out=af_asb_defaults();
    if (($raw['text_format']??'plain')==='bbcode') $out['text_format']='bbcode';
    foreach (['title','subtitle','text','status'] as $key) if (is_string($raw[$key]??null)) $out[$key]=$raw[$key];
    foreach (['show_title','show_subtitle','show_status','show_divider'] as $key) if (is_bool($raw[$key]??null)) $out[$key]=$raw[$key];
    if (is_string($raw['template']??null) && isset(af_asb_templates()[$raw['template']])) $out['template']=$raw['template'];
    foreach ([0,1,2] as $slot) {
        $src=$raw['images'][$slot]??[];
        $image=af_asb_defaults()['images'][0];
        if (is_array($src)) {
            if (is_bool($src['enabled']??null)) $image['enabled']=$src['enabled'];
            if (is_string($src['url']??null)) $image['url']=$src['url'];
            if (is_int($src['media_id']??null)) $image['media_id']=$src['media_id'];
        }
        $out['images'][$slot]=$image;
    }
    foreach (array_slice(is_array($raw['buttons']??null)?$raw['buttons']:[],0,6) as $button) $out['buttons'][]=['label'=>is_string($button['label']??null)?$button['label']:'','url'=>is_string($button['url']??null)?$button['url']:''];
    return $out;
}
