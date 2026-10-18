<?php
/** Postbit appearance adapter. Selectors and CSS are owned code, never user input. */
if (!defined('IN_MYBB')) die('No direct access');

function af_aa_postbit_fields(): array
{
    $fields=[
        'text_align'=>['enum',['left','center','right','justify']], 'hover_background_color'=>['color'], 'hover_border_color'=>['color'], 'divider_color'=>['color'],
        'background_url'=>['url'], 'image_url'=>['url'], 'image_media_id'=>['media'],
        'background_saturation'=>['number',0,100,1],
        'background_repeat'=>['enum',['no-repeat','repeat','repeat-x','repeat-y']],
        'overlay_mode'=>['enum',['none','darken','lighten']], 'overlay_gradient'=>['enum',['uniform','linear']], 'overlay_color'=>['color'],
        'overlay_intensity'=>['number',0,1,.01], 'overlay_start'=>['number',0,1,.01], 'overlay_end'=>['number',0,1,.01], 'overlay_angle'=>['number',0,360,1],
        'haze_color'=>['color'], 'haze_intensity'=>['number',0,1,.01], 'haze_opacity'=>['number',0,1,.01], 'haze_angle'=>['number',0,360,1],
        'glow_color'=>['color'], 'glow_intensity'=>['number',0,20,1], 'gem_color'=>['color'], 'gem_glow'=>['number',0,12,1],
        'icon_color'=>['color'], 'value_color'=>['color'], 'hover_color'=>['color'],
        'image_fit'=>['enum',['cover','contain','fill','none']], 'image_position'=>['enum',['center','top','bottom','left','right']],
        'image_scale'=>['number',25,200,1], 'label_text'=>['text'], 'font_style'=>['enum',['normal','italic']],
    ];
    return $fields;
}

/** Shared compiler/UI defaults: inherited sliders must not silently start at transparent stops. */
function af_aa_postbit_overlay_defaults(): array
{
    return ['overlay_mode'=>'darken','overlay_intensity'=>.5,'overlay_start'=>0,'overlay_end'=>1,'overlay_angle'=>180,'overlay_gradient'=>'uniform'];
}

/** Linear opacity, with spatial stops independent from strength. Old 1/1 defaults remain uniform. */
function af_aa_postbit_overlay_mask(array $v): string
{
    if(($v['overlay_mode']??'darken')==='none')return 'none';
    $color=$v['overlay_color']??(($v['overlay_mode']??'darken')==='lighten'?'#ffffff':'#000000');
    $paint='color-mix(in srgb,'.$color.' '.(100*($v['overlay_intensity']??.5)).'%,transparent)';
    $start=(float)($v['overlay_start']??0);$end=(float)($v['overlay_end']??1);
    $hasStops=array_key_exists('overlay_start',$v)||array_key_exists('overlay_end',$v);
    $gradient=$v['overlay_gradient']??($hasStops&&!($start===1.0&&$end===1.0)?'linear':'uniform');
    if($gradient==='uniform')return 'linear-gradient('.$paint.','.$paint.')';
    // Reverse endpoints produce the same spatial interval instead of invalid/backtracking stops.
    return 'linear-gradient('.($v['overlay_angle']??180).'deg,transparent '.(100*min($start,$end)).'%,'.$paint.' '.(100*max($start,$end)).'%)';
}

function af_aa_postbit_profile_field_defaults(int $fid): array
{
    return $fid===4?['background_color'=>'#000000','background_opacity'=>.45,'radius'=>4,'padding'=>5]:[];
}

function af_aa_postbit_surface(): array
{
    $background=['background_color','background_opacity','background_media_id','background_url','background_position','background_size','background_repeat','gradient_color','gradient_angle','darkening','overlay_mode','overlay_gradient','overlay_color','overlay_intensity','overlay_start','overlay_end','overlay_angle'];
    $haze=['haze_color','haze_intensity','haze_opacity','haze_angle'];
    $type=['text_align','letter_spacing','text_color','font_family','font_size','font_weight','font_style','line_height','glow_color','glow_intensity'];
    $image=['image_media_id','image_url','image_fit','image_position','image_scale'];
    // Keep validating old sparse presets; obsolete geometry fields are never offered by this adapter.
    $legacy=array_diff(array_keys(af_aa_studio_fields()),array_keys(af_aa_postbit_fields()));
    $entry=static fn($label,$selector,$fields,$old=false)=>['label'=>$label,'selector'=>$selector,'editable_fields'=>array_values(array_diff($fields,['darkening'])),'fields'=>$old?array_values(array_unique(array_merge($fields,$legacy))):$fields];
    $surface=['field_defaults'=>['background_saturation'=>100],'overlay_defaults'=>af_aa_postbit_overlay_defaults(),'purpose'=>'appearance_postbit_bg','css'=>['adaptivethemeframework/assets/surfaces/postbit.css','adaptivethemeframework/assets/surfaces/signature.css','advancedprofileui/assets/advancedprofileui.css'],'canvas'=>false,'components'=>[
        'decoration'=>$entry('Декоративные акценты','',['gem_color','gem_glow']),
        'topbar'=>$entry('Верхняя панель','.atf-post__topbar',array_merge($background,$haze),true),
        'sidebar'=>$entry('Боковая панель','.atf-post__sidebar-inner',array_merge($background,$haze),true),
        'message_background'=>$entry('Фон сообщения','.atf-post__layout',array_merge($background,$haze)),
        'primary_avatar'=>$entry('Аватары · основной','.atf-post__primary-avatar-media img',$image),
        'secondary_avatar'=>$entry('Аватары · дополнительный','.atf-post__secondary-avatar img',$image),
        'buttons'=>$entry('Кнопки и текст · кнопки',':is(.atf-post__profile-action,.atf-post__management-action,.af-apui-postbit-action)',['text_color','icon_color','hover_color','glow_color','glow_intensity']),
        'character_name'=>$entry('Кнопки и текст · имя персонажа','.atf-post__character-name',['text_color','glow_color','glow_intensity']),
        'message'=>$entry('Кнопки и текст · сообщение','.atf-post__message-body',['text_color','glow_color','glow_intensity'],true),
        'metadata'=>$entry('Метаданные и статистика · метаданные','.atf-post__meta-line',['background_color','text_color','link_color','hover_color']),
        'statistics'=>$entry('Метаданные и статистика · статистика','.atf-post__statistics',['background_color','text_color','value_color','icon_color','glow_color','glow_intensity']),
        'plaque'=>$entry('Плашка персонажа · фон','.af-apui-postbit-plaque',$background),
        'plaque_icon'=>$entry('Плашка персонажа · изображение','.af-apui-postbit-plaque__media',$image),
        'plaque_title'=>$entry('Плашка персонажа · основная подпись','.af-apui-postbit-plaque__title',array_merge(['label_text'],$type)),
        'plaque_subtitle'=>$entry('Плашка персонажа · вторая подпись','.af-apui-postbit-plaque__subtitle',array_merge(['label_text'],$type)),
        'signature_title'=>$entry('Подпись · заголовок','.af-asb-signature__copy .af-asb-signature__title',$type),
        'signature_subtitle'=>$entry('Подпись · подзаголовок','.af-asb-signature__copy .af-asb-signature__subtitle',$type),
        'signature_quote'=>$entry('Подпись · цитата','.af-asb-signature--quote .af-asb-signature__copy .af-asb-signature__text',array_merge($type,['background_color','background_opacity','divider_color','padding'])),
        'signature_buttons'=>$entry('Подпись · кнопки-ссылки','.af-asb-signature .af-asb-signature__button',array_merge($type,['background_color','border_color','hover_color','hover_background_color','hover_border_color'])),
        'signature'=>$entry('Подпись','.atf-post__signature',array_merge($background,$type,['link_color','hover_color']),true),
    ]];
    foreach(['primary_avatar','secondary_avatar'] as $zone)$surface['components'][$zone]['defaults']=['image_scale'=>100,'image_fit'=>'cover','image_position'=>'center'];
    foreach(['topbar','sidebar','message_background'] as $zone) {
        $surface['components'][$zone]['fields'][]='background_saturation';
        $surface['components'][$zone]['editable_fields'][]='background_saturation';
    }
    $surface['components']['profile_fields']=$entry('Дополнительные поля · все','.atf-post__profile-fields',$type);
    $surface['components']['user_status']=$entry('Статус пользователя','.atf-post__title',$type);
    if(function_exists('af_apf_postbit_field_definitions'))foreach(af_apf_postbit_field_definitions() as $fid=>$field) {
        $surface['components']['profile_field_'.$fid]=$entry('Поле · '.$field['name'],'.atf-post__profile-fields .af-apf-postbit-field--fid'.$fid,array_merge($type,['background_color','background_opacity','radius','padding']));
        // ATF's established personal-title field keeps one default in preview and live posts.
        $surface['components']['profile_field_'.$fid]['defaults']=af_aa_postbit_profile_field_defaults($fid);
    }
    return $surface;
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
    if(isset($settings['components']['profile_field_4']))$settings['components']['profile_field_4']=array_replace(af_aa_postbit_profile_field_defaults(4),$settings['components']['profile_field_4']);
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
    $css=$scope(' .af-asb-signature .af-asb-signature__button').'{color:var(--atf-post-accent,currentColor);border-color:var(--atf-post-accent,currentColor);}';
    foreach ($s['components']??[] as $key=>$v) {
        if ($signatureRoot!==null && !in_array($key,['signature','decoration','signature_title','signature_subtitle','signature_quote','signature_buttons'],true)) continue;
        $selector=af_aa_studio_components('postbit')[$key]??null;
        if ($selector===null) continue;
        $suffix=$selector===''?'':' '.$selector;
        $rule=[];
        if ($key==='decoration') {
            if (isset($v['gem_color'])) $rule[]='--aa-gem-color:'.$v['gem_color'].';';
            if (isset($v['gem_glow'])) $rule[]='--aa-gem-glow:'.$v['gem_glow'].'px;';
        }
        foreach (['text_align'=>'text-align','padding'=>'padding','text_color'=>'color','font_size'=>'font-size','font_weight'=>'font-weight','font_style'=>'font-style','line_height'=>'line-height','letter_spacing'=>'letter-spacing'] as $field=>$property) {
            if (isset($v[$field])) $rule[]=$property.':'.$v[$field].(in_array($field,['font_size','letter_spacing','padding'],true)?'px':'').';';
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
        if (isset($v['background_color']) && !in_array($key,['topbar','sidebar','message_background'],true)) {
            $color='color-mix(in srgb,'.$v['background_color'].' '.(($v['background_opacity']??1)*100).'%,transparent)';
            $rule[]='background-color:'.$color.';';
            if ($key==='statistics') $rule[]='--aa-statistics-background:'.$color.';';
            if ($key==='metadata') $rule[]='--aa-metadata-background:'.$color.';';
        }
        if (in_array($key,['topbar','sidebar','message_background'],true)) {
            $prefix='--aa-'.$key.'-';
            // Emit on the real host, including the layout that owns ::before.
            $vars=[];
            if (isset($v['background_color'])) $vars[]=$prefix.'color:color-mix(in srgb,'.$v['background_color'].' '.(100*($v['background_opacity']??1)).'%,transparent);';
            $image=af_aa_postbit_image($v,'background');
            if ($image!=='' || array_key_exists('background_media_id',$v) || array_key_exists('background_url',$v)) $vars[]=$prefix.'image:'.($image!==''?af_aa_css_url_value($image):'none').';';
            foreach (['background_size'=>'size','background_position'=>'position','background_repeat'=>'repeat'] as $field=>$property) if (isset($v[$field])) $vars[]=$prefix.$property.':'.$v[$field].';';
            if ($image!=='') {
                if (!isset($v['background_size'])) $vars[]=$prefix.'size:cover;';
                if (!isset($v['background_repeat'])) $vars[]=$prefix.'repeat:no-repeat;';
            }
            if(isset($v['background_saturation'])) {
                $alpha=1-$v['background_saturation']/100;
                $vars[]=$prefix.'desaturate:linear-gradient(rgb(128 128 128 / '.$alpha.'),rgb(128 128 128 / '.$alpha.'));';
            }
            if (isset($v['haze_color'])) $vars[]=$prefix.'haze:linear-gradient('.($v['haze_angle']??115).'deg,color-mix(in srgb,'.$v['haze_color'].' '.(100*($v['haze_intensity']??.18)*($v['haze_opacity']??1)).'%,transparent),transparent 70%);';
            $defaults=af_aa_postbit_overlay_defaults();
            $mode=$v['overlay_mode']??null;
            if ($mode===null && !array_key_exists('overlay_mode',$v) && isset($v['darkening'])) {$mode='darken';$v['overlay_intensity']=$v['darkening'];}
            // Sparse settings from a slider/color edit still express an overlay override.
            if($mode===null && array_intersect(array_keys($v),['overlay_gradient','overlay_color','overlay_intensity','overlay_start','overlay_end','overlay_angle']))$mode=$defaults['overlay_mode'];
            if ($mode!==null) {
                $mask=af_aa_postbit_overlay_mask($v+['overlay_mode'=>$mode]);
                $vars[]=$prefix.'mask:'.$mask.';';
            }
            if (isset($v['gradient_color'])) $vars[]=$prefix.'gradient:linear-gradient('.($v['gradient_angle']??135).'deg,color-mix(in srgb,'.($v['background_color']??'#000000').' '.(100*($v['background_opacity']??.18)).'%,transparent),color-mix(in srgb,'.$v['gradient_color'].' '.(100*($v['background_opacity']??.18)).'%,transparent));';
            // Preserve the original filtered theme texture until this area is customized.
            if ($key==='message_background' && $vars) $vars[]=$prefix.'filter:none;';
            if ($vars || $rule) $css.=$scope($suffix).'{'.implode('',$vars).implode('',$rule).'}';
            continue;
        }
        if (isset($v['divider_color'])) {
            $rule[]='border-inline-start-color:'.$v['divider_color'].';';
            if ($key==='signature_quote') $css.=$scope(' .af-asb-signature--quote .af-asb-signature__divider').'{background:linear-gradient(90deg,transparent,'.$v['divider_color'].',transparent);}';
        }
        if ($key==='signature_buttons') {
            if (isset($v['border_color'])) $rule[]='border-color:'.$v['border_color'].';';
            $hover=[];
            foreach (['hover_color'=>'color','hover_background_color'=>'background-color','hover_border_color'=>'border-color'] as $field=>$property) if (isset($v[$field])) $hover[]=$property.':'.$v[$field].';';
            if ($hover) $css.=$scope($suffix.':is(:hover,:focus-visible)').'{'.implode('',$hover).'}';
        }
        $layers=[];
        if (isset($v['haze_color'])) $layers[]='linear-gradient('.($v['haze_angle']??115).'deg,color-mix(in srgb,'.$v['haze_color'].' '.(100*($v['haze_intensity']??.18)*($v['haze_opacity']??1)).'%,transparent),transparent 70%)';
        if (($v['overlay_mode']??'none')!=='none') {
            $layers[]=af_aa_postbit_overlay_mask($v);
        } elseif (!array_key_exists('overlay_mode',$v) && isset($v['darkening'])) $layers[]='linear-gradient(rgb(0 0 0 / '.$v['darkening'].'),rgb(0 0 0 / '.$v['darkening'].'))';
        if (isset($v['gradient_color'])) $layers[]='linear-gradient('.($v['gradient_angle']??135).'deg,color-mix(in srgb,'.($v['background_color']??'#000000').' '.(100*($v['background_opacity']??.18)).'%,transparent),color-mix(in srgb,'.$v['gradient_color'].' '.(100*($v['background_opacity']??.18)).'%,transparent))';
        if ($key==='signature') $rule[]='--af-sig-overlay:'.($layers?implode(',',$layers):'none').';';
        $image=af_aa_postbit_image($v,'background');
        if ($image!=='') $layers[]=af_aa_css_url_value($image);
        elseif (array_key_exists('background_media_id',$v) || array_key_exists('background_url',$v)) $layers[]='none';
        // Native textures remain beneath haze/masks unless an image is explicitly replaced/removed.
        elseif ($layers && $key==='plaque') $layers[]='var(--af-apui-postbit-plaque-bg-image,none)';
        if ($layers) $rule[]='background-image:'.implode(',',$layers).';';
        foreach (['background_size'=>'background-size','background_position'=>'background-position','background_repeat'=>'background-repeat'] as $field=>$property) if (isset($v[$field])) $rule[]=$property.':'.$v[$field].';';
        if ($image!=='' && !isset($v['background_repeat'])) $rule[]='background-repeat:no-repeat;';
        if ($image!=='' && !isset($v['background_size'])) $rule[]='background-size:cover;';
        // Replace primary avatar in the preview via real markup, never CSS content (GIF stays original).
        $imageSuffix=$key==='plaque_icon'?$suffix.' > img':$suffix;
        $imageRules=[];
        $isAvatar=in_array($key,['primary_avatar','secondary_avatar'],true);
        $isPrimaryAvatar=$key==='primary_avatar';
        $fit=$v['image_fit']??'cover';
        // Keep old stored 'fill' readable, but never stretch an avatar's proportions.
        if($isAvatar && $fit==='fill')$fit='cover';
        if (isset($v['image_fit'])) {
            $imageRules[]='--aa-avatar-fit:'.$fit.';';
            if(!$isPrimaryAvatar)$imageRules[]='object-fit:'.$fit.';';
        }
        if($isAvatar)$imageRules[]='--aa-avatar-min-scale:'.(in_array($fit,['contain','none'],true)?0:1).';';
        if (isset($v['image_position'])) {
            $imageRules[]='--aa-avatar-position:'.$v['image_position'].';';
            if(!$isPrimaryAvatar)$imageRules[]='object-position:'.$v['image_position'].';';
        }
        if (isset($v['image_scale'])) $imageRules[]=$isAvatar?'--aa-avatar-scale:'.($v['image_scale']/100).';':'scale:'.($v['image_scale']/100).';';
        if ($imageRules) $css.=$scope($imageSuffix).'{'.implode('',$imageRules).'}';
        if ($key==='plaque_icon') $rule[]='overflow:hidden;';
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

/** Keep custom primary avatars inside MyBB's native postbit_avatar wrapper. */
function af_aa_postbit_avatar_markup(array $post,array $useravatar): string
{
    global $templates;
    if (empty($useravatar['image'])) return '';
    $uid=(int)($post['uid']??0);
    if (empty($post['profilelink_plain'])) {
        $post['profilelink_plain']=$uid>0?(function_exists('get_profile_link')?get_profile_link($uid):'member.php?action=profile&uid='.$uid):'#';
    }
    if (is_object($templates??null) && method_exists($templates,'get')) {
        $avatar='';
        eval('$avatar = "'.$templates->get("postbit_avatar").'";');
        if ($avatar!=='') return (string)$avatar;
    }
    $escape=static fn($value)=>function_exists('htmlspecialchars_uni')?htmlspecialchars_uni((string)$value):htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
    return '<div class="author_avatar"><a href="'.$escape($post['profilelink_plain']).'"><img src="'.$useravatar['image'].'" alt="" '.($useravatar['width_height']??'').' /></a></div>';
}

function af_aa_postbit_apply_images(array &$post,array $s): void
{
    foreach (['primary_avatar'=>'af_atf_primary_avatar','secondary_avatar'=>'af_atf_secondary_avatar'] as $key=>$slot) {
        $v=$s['components'][$key]??[];
        if ($key==='primary_avatar' && !empty($s['avatar_media_id']) && !array_key_exists('image_media_id',$v) && !array_key_exists('image_url',$v)) $v['image_media_id']=$s['avatar_media_id'];
        $url=af_aa_postbit_image($v);
        if ($url!=='') {
            $image=['image'=>htmlspecialchars_uni($url),'width_height'=>''];
            $post[$slot]=$key==='primary_avatar'?af_aa_postbit_avatar_markup($post,$image):'<img src="'.$image['image'].'" alt="'.htmlspecialchars_uni($post['username']??'').'" loading="lazy" decoding="async">';
        }
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
    foreach (['postnum'=>['Сообщения','fa-comments'],'threadnum'=>['Темы','fa-copy'],'reputation'=>['Репутация','fa-heart']] as $key=>[$label,$icon]) $html.='<div class="af-apui-stat-item"><span class="af-apui-stat-item__icon" title="'.$label.'"><i class="fa-solid '.$icon.'" aria-hidden="true"></i></span><span class="af-apui-stat-item__value">'.(int)($user[$key]??0).'</span></div>';
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
