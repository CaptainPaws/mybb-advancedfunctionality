<?php
/** Postbit appearance adapter. Selectors and CSS are owned code, never user input. */
if (!defined('IN_MYBB')) die('No direct access');

function af_aa_postbit_fields(): array
{
    $fields=[
        'background_url'=>['url'], 'image_url'=>['url'], 'image_media_id'=>['media'],
        'background_repeat'=>['enum',['no-repeat','repeat','repeat-x','repeat-y']],
        'overlay_mode'=>['enum',['none','darken','lighten']], 'overlay_color'=>['color'],
        'overlay_intensity'=>['number',0,1,.01], 'overlay_start'=>['number',0,1,.01], 'overlay_end'=>['number',0,1,.01], 'overlay_angle'=>['number',0,360,1],
        'haze_color'=>['color'], 'haze_intensity'=>['number',0,1,.01], 'haze_opacity'=>['number',0,1,.01], 'haze_angle'=>['number',0,360,1],
        'glow_color'=>['color'], 'glow_intensity'=>['number',0,20,1], 'gem_color'=>['color'], 'gem_glow'=>['number',0,12,1],
        'icon_color'=>['color'], 'value_color'=>['color'], 'hover_color'=>['color'],
        'image_fit'=>['enum',['cover','contain','fill','none']], 'image_position'=>['enum',['center','top','bottom','left','right']],
        'image_scale'=>['number',25,200,1], 'label_text'=>['text'], 'font_style'=>['enum',['normal','italic']],
    ];
    return $fields;
}

function af_aa_postbit_surface(): array
{
    $background=['background_color','background_opacity','background_media_id','background_url','background_position','background_size','background_repeat','gradient_color','gradient_angle','darkening','overlay_mode','overlay_color','overlay_intensity','overlay_start','overlay_end','overlay_angle'];
    $type=['text_color','font_family','font_size','font_weight','font_style','line_height','glow_color','glow_intensity'];
    $image=['image_media_id','image_url','image_fit','image_position','image_scale'];
    // Keep validating old sparse presets; obsolete geometry fields are never offered by this adapter.
    $legacy=array_diff(array_keys(af_aa_studio_fields()),array_keys(af_aa_postbit_fields()));
    $entry=static fn($label,$selector,$fields,$old=false)=>['label'=>$label,'selector'=>$selector,'editable_fields'=>array_values(array_diff($fields,['darkening'])),'fields'=>$old?array_values(array_unique(array_merge($fields,$legacy))):$fields];
    return ['purpose'=>'appearance_postbit_bg','css'=>['adaptivethemeframework/assets/surfaces/postbit.css','adaptivethemeframework/assets/surfaces/signature.css','advancedprofileui/assets/advancedprofileui.css'],'canvas'=>false,'components'=>[
        'decoration'=>$entry('Декоративные акценты','',['gem_color','gem_glow']),
        'topbar'=>$entry('Верхняя панель','.atf-post__topbar',array_merge($background,['haze_color','haze_intensity','haze_opacity','haze_angle']),true),
        'sidebar'=>$entry('Боковая панель','.atf-post__sidebar-inner',$background,true),
        'primary_avatar'=>$entry('Аватары · основной','.atf-post__primary-avatar-media img',$image),
        'secondary_avatar'=>$entry('Аватары · дополнительный','.atf-post__secondary-avatar > img',$image),
        'buttons'=>$entry('Кнопки и текст · кнопки',':is(.atf-post__profile-action,.atf-post__management-action,.af-apui-postbit-action)',['text_color','icon_color','hover_color','glow_color','glow_intensity']),
        'character_name'=>$entry('Кнопки и текст · имя персонажа','.atf-post__character-name',['text_color','glow_color','glow_intensity']),
        'message'=>$entry('Кнопки и текст · сообщение','.atf-post__message-body',['text_color','glow_color','glow_intensity'],true),
        'metadata'=>$entry('Метаданные и статистика · метаданные','.atf-post__meta-line',['background_color','text_color','link_color','hover_color']),
        'statistics'=>$entry('Метаданные и статистика · статистика','.atf-post__statistics',['background_color','text_color','value_color','icon_color','glow_color','glow_intensity']),
        'plaque'=>$entry('Плашка персонажа · фон','.af-apui-postbit-plaque',$background),
        'plaque_icon'=>$entry('Плашка персонажа · изображение','.af-apui-postbit-plaque__media',$image),
        'plaque_title'=>$entry('Плашка персонажа · основная подпись','.af-apui-postbit-plaque__title',array_merge(['label_text'],$type)),
        'plaque_subtitle'=>$entry('Плашка персонажа · вторая подпись','.af-apui-postbit-plaque__subtitle',array_merge(['label_text'],$type)),
        'signature'=>$entry('Подпись','.atf-post__signature',array_merge($background,$type,['link_color','hover_color']),true),
    ]];
}

/** Reuse Gallery's configured source policy. No server-side fetch and no active image formats. */
function af_aa_postbit_image_url_valid(string $url): bool
{
    if ($url==='' || strlen($url)>2048 || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/',$url)) return false;
    $parts=parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme']??''),['http','https'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return false;
    if (!preg_match('/\.(?:png|jpe?g|webp|gif)$/iD',$parts['path']??'')) return false;
    // Site-owned raster images and Gallery's existing ACP host allow-list.
    $site=parse_url((string)($GLOBALS['mybb']->settings['bburl']??''));
    if ($site && strtolower($parts['host'])===strtolower($site['host']??'') && strtolower($parts['scheme'])===strtolower($site['scheme']??'') && ($parts['port']??null)===($site['port']??null)) return true;
    return function_exists('ag_remote_is_domain_allowed') && ag_remote_is_domain_allowed($parts['host']);
}

function af_aa_postbit_image(array $values,string $role='image'): string
{
    $id=$values[$role.'_media_id']??null;
    if ($id && function_exists('ag_resolve_media_url')) return (string)ag_resolve_media_url((int)$id,'full');
    $url=$values[$role.'_url']??'';
    return is_string($url) && af_aa_postbit_image_url_valid($url)?$url:'';
}

function af_aa_studio_fonts_link(): string
{
    if (!function_exists('af_advancededitor_ensure_local_fonts_css_file')) return '';
    $url=af_advancededitor_ensure_local_fonts_css_file();
    return $url!==''?'<link data-aa-local-fonts rel="stylesheet" href="'.htmlspecialchars_uni($url).'">':'';
}

/** Base preset decoration. Explicit image/null/empty values always win, including deliberate removal. */
function af_aa_signature_defaults(): array
{
    return ['background_url'=>'https://warprift.ru/uploads/af_gallery/1/2026/10/2240bb976cd73c5cbb555246b3001753.png',
        'background_size'=>'cover','background_position'=>'center','background_repeat'=>'no-repeat',
        'overlay_mode'=>'darken','overlay_color'=>'#000000','overlay_intensity'=>.4,'overlay_start'=>1,'overlay_end'=>1,'overlay_angle'=>180,
        'text_color'=>'#e7edff'];
}
function af_aa_postbit_effective_settings(array $settings): array
{
    $signature=$settings['components']['signature']??[];
    if (!array_key_exists('background_url',$signature) && !array_key_exists('background_media_id',$signature)) {
        $settings['components']['signature']=$signature+af_aa_signature_defaults();
    }
    return $settings;
}

function af_aa_postbit_compile(int $uid,array $s,bool $preview=false,?string $signatureRoot=null): string
{
    $s=af_aa_postbit_effective_settings($s);
    $root=$signatureRoot??af_aa_studio_root($uid,'postbit',$preview);
    // Match the canonical ATF rules without blanket !important declarations.
    $scope=$signatureRoot!==null
        ? static fn($suffix='')=>'body.atf-active .atf-signature-surface'.$root.$suffix.', '.$root.$suffix
        : static fn($suffix='')=>'body.atf-active '.$root.'.af-atf-display[data-element]'.$suffix.', '.$root.$suffix;
    $css='';
    foreach ($s['components']??[] as $key=>$v) {
        if ($signatureRoot!==null && !in_array($key,['signature','decoration'],true)) continue;
        $selector=af_aa_studio_components('postbit')[$key]??null;
        if ($selector===null) continue;
        $suffix=$selector===''?'':' '.$selector;
        $rule=[];
        if ($key==='decoration') {
            if (isset($v['gem_color'])) $rule[]='--aa-gem-color:'.$v['gem_color'].';';
            if (isset($v['gem_glow'])) $rule[]='--aa-gem-glow:'.$v['gem_glow'].'px;';
        }
        foreach (['text_color'=>'color','font_size'=>'font-size','font_weight'=>'font-weight','font_style'=>'font-style','line_height'=>'line-height','letter_spacing'=>'letter-spacing'] as $field=>$property) {
            if (isset($v[$field])) $rule[]=$property.':'.$v[$field].(in_array($field,['font_size','letter_spacing'],true)?'px':'').';';
        }
        if (isset($v['font_family']) && $v['font_family']!=='inherit') $rule[]='font-family:'.(af_aa_personal_fonts()[$v['font_family']]??'inherit').';';
        if (isset($v['glow_intensity'])) {
            $glow='0 0 '.$v['glow_intensity'].'px '.($v['glow_color']??'currentColor');
            if ($key==='message') {
                // Do not inherit a post glow into code, quotes, spoilers, or explicitly styled BBCode.
                $css.=$scope($suffix.' :is(blockquote,pre,code,.codeblock,.quote,.spoiler,.spoiler_body,[style])').'{text-shadow:none;}';
            }
            if (in_array($key,['buttons','statistics'],true)) $css.=$scope($suffix.' svg').'{filter:drop-shadow('.$glow.');}';
            if ($key!=='statistics') $rule[]='text-shadow:'.$glow.';';
            else $css.=$scope($suffix.' :is(.af-apui-stat-item__value,.af-apui-stat-item__icon)').'{text-shadow:'.$glow.';}';
        }
        if (isset($v['background_color'])) {
            $color='color-mix(in srgb,'.$v['background_color'].' '.(($v['background_opacity']??1)*100).'%,transparent)';
            $rule[]='background-color:'.$color.';';
            if ($key==='statistics') $rule[]='--aa-statistics-background:'.$color.';';
            if ($key==='metadata') $rule[]='--aa-metadata-background:'.$color.';';
        }
        $layers=[];
        if (isset($v['haze_color'])) $layers[]='linear-gradient('.($v['haze_angle']??115).'deg,color-mix(in srgb,'.$v['haze_color'].' '.(100*($v['haze_intensity']??.18)*($v['haze_opacity']??1)).'%,transparent),transparent 70%)';
        if (($v['overlay_mode']??'none')!=='none') {
            $color=$v['overlay_color']??($v['overlay_mode']==='lighten'?'#ffffff':'#000000');
            $intensity=$v['overlay_intensity']??.5;
            $layers[]='linear-gradient('.($v['overlay_angle']??180).'deg,color-mix(in srgb,'.$color.' '.(100*$intensity*($v['overlay_start']??1)).'%,transparent),color-mix(in srgb,'.$color.' '.(100*$intensity*($v['overlay_end']??1)).'%,transparent))';
        } elseif (!array_key_exists('overlay_mode',$v) && isset($v['darkening'])) $layers[]='linear-gradient(rgb(0 0 0 / '.$v['darkening'].'),rgb(0 0 0 / '.$v['darkening'].'))';
        if (isset($v['gradient_color'])) $layers[]='linear-gradient('.($v['gradient_angle']??135).'deg,color-mix(in srgb,'.($v['background_color']??'#000000').' '.(100*($v['background_opacity']??.18)).'%,transparent),color-mix(in srgb,'.$v['gradient_color'].' '.(100*($v['background_opacity']??.18)).'%,transparent))';
        if ($key==='signature') $rule[]='--af-sig-overlay:'.($layers?implode(',',$layers):'none').';';
        $image=af_aa_postbit_image($v,'background');
        if ($image!=='') $layers[]=af_aa_css_url_value($image);
        elseif (array_key_exists('background_media_id',$v) || array_key_exists('background_url',$v)) $layers[]='none';
        // Native textures remain beneath haze/masks unless an image is explicitly replaced/removed.
        elseif ($layers && $key==='topbar') $layers[]='linear-gradient(115deg,var(--atf-post-accent-soft),transparent 38%),var(--af-apui-postbit-name-bg-image,none)';
        elseif ($layers && $key==='sidebar') $layers[]='var(--af-apui-postbit-author-bg-image,none)';
        elseif ($layers && $key==='plaque') $layers[]='var(--af-apui-postbit-plaque-bg-image,none)';
        if ($layers) $rule[]='background-image:'.implode(',',$layers).';';
        foreach (['background_size'=>'background-size','background_position'=>'background-position','background_repeat'=>'background-repeat'] as $field=>$property) if (isset($v[$field])) $rule[]=$property.':'.$v[$field].';';
        if ($image!=='' && !isset($v['background_repeat'])) $rule[]='background-repeat:no-repeat;';
        if ($image!=='' && !isset($v['background_size'])) $rule[]='background-size:cover;';
        // Replace primary avatar in the preview via real markup, never CSS content (GIF stays original).
        $imageSuffix=$key==='plaque_icon'?$suffix.' > img':$suffix;
        $imageRules=[];
        if (isset($v['image_fit'])) $imageRules[]='--aa-avatar-fit:'.$v['image_fit'].';object-fit:'.$v['image_fit'].';';
        if (isset($v['image_position'])) $imageRules[]='--aa-avatar-position:'.$v['image_position'].';object-position:'.$v['image_position'].';';
        if (isset($v['image_scale'])) $imageRules[]='scale:'.($v['image_scale']/100).';';
        if ($imageRules) $css.=$scope($imageSuffix).'{'.implode('',$imageRules).'}';
        if ($key==='plaque_icon') $rule[]='overflow:hidden;';
        if ($key==='secondary_avatar' && isset($v['image_scale'])) $css.=$scope(' .atf-post__secondary-avatar').'{overflow:hidden;}';
        if ($rule) $css.=$scope($suffix).'{'.implode('',$rule).'}';
        foreach (['link_color'=>' a','hover_color'=>' :is(a,button):is(:hover,:focus-visible)','icon_color'=>' :is(i,svg,.af-apui-stat-item__icon)','value_color'=>' .af-apui-stat-item__value'] as $field=>$child) {
            if (isset($v[$field])) $css.=$scope($suffix.$child).'{color:'.$v[$field].';}';
        }
        if ($key==='buttons' && isset($v['hover_color'])) {
            $css.=$scope($suffix.':is(:hover,:focus-visible,:active)').'{color:'.$v['hover_color'].';}';
            $css.=$scope($suffix.':is(:hover,:focus-visible,:active) :is(i,svg)').'{color:'.$v['hover_color'].';}';
        }
        if ($key==='statistics' && isset($v['text_color'])) $css.=$scope($suffix.' .af-apui-stat-item').'{color:'.$v['text_color'].';}';
    }
    return $css;
}

function af_aa_postbit_apply_images(array &$post,array $s): void
{
    foreach (['primary_avatar'=>'af_atf_primary_avatar','secondary_avatar'=>'af_atf_secondary_avatar'] as $key=>$slot) {
        $v=$s['components'][$key]??[];
        if ($key==='primary_avatar' && !empty($s['avatar_media_id']) && !array_key_exists('image_media_id',$v) && !array_key_exists('image_url',$v)) $v['image_media_id']=$s['avatar_media_id'];
        $url=af_aa_postbit_image($v);
        if ($url!=='') $post[$slot]='<img src="'.htmlspecialchars_uni($url).'" alt="'.htmlspecialchars_uni($post['username']??'').'" loading="lazy" decoding="async">';
    }
}

function af_aa_postbit_plaque_settings(int $uid,array $settings): array
{
    // null/absent restores APUI's original content. No href, sheet ID or handlers are exposed.
    $v=$settings['components']??[];$out=[];
    $url=af_aa_postbit_image($v['plaque_icon']??[]);
    if ($url!=='') $out['postbit_plaque_media_image_url']=$url;
    foreach (['plaque_title'=>'postbit_plaque_title','plaque_subtitle'=>'postbit_plaque_subtitle'] as $key=>$field) {
        if (isset($v[$key]['label_text'])) $out[$field]=$v[$key]['label_text'];
    }
    return $out;
}

function af_aa_postbit_preview_statistics(array $user): string
{
    $html='<div class="af-apui-postbit-rail"><div class="author_statistics af-apui-postbit-userdetails">';
    foreach (['postnum'=>['Сообщения','✉'],'threadnum'=>['Темы','◇'],'reputation'=>['Репутация','♡']] as $key=>[$label,$icon]) $html.='<div class="af-apui-stat-item"><span class="af-apui-stat-item__icon" title="'.$label.'">'.$icon.'</span><span class="af-apui-stat-item__value">'.(int)($user[$key]??0).'</span></div>';
    return $html.'</div></div>';
}

function af_aa_postbit_settings(array $post): array
{
    if (isset($post['af_aa_preview_settings'])) return $post['af_aa_preview_settings'];
    $uid=(int)($post['uid']??0);$pid=(int)($post['pid']??0);
    if ($pid>0 && function_exists('af_aa_mask_runtime') && ($mask=af_aa_mask_runtime($pid,$uid))) return $mask['snapshot'];
    return af_aa_surface_settings($uid,'postbit')??[];
}

/** Signature-only adapter for non-postbit hosts. Shares the postbit compiler and owner preset. */
function af_aa_signature_compile_css(int $uid,array $settings): string
{
    $root='.af-aa-signature-user-'.(int)$uid;
    $css='';
    if (!empty($settings['accent_color'])) $css='body.atf-active .atf-signature-surface'.$root.', '.$root.'{--atf-post-accent:'.$settings['accent_color'].';--atf-post-accent-soft:color-mix(in srgb,'.$settings['accent_color'].' 18%,transparent);}';
    return $css.af_aa_studio_compile_base($uid,$settings,false,'postbit',$root).af_aa_postbit_compile($uid,$settings,false,$root);
}
