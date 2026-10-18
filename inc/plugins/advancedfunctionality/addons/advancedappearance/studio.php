<?php
/** Workshop schema/compiler. Trusted component selectors only, shared by preview and runtime. */
if (!defined('IN_MYBB')) die('No direct access');

require_once __DIR__.'/postbit_studio.php';

function af_aa_studio_components(string $target='postbit'): array {
    return array_map(static fn($meta)=>$meta['selector'],af_aa_studio_surface_registry()[$target]['components']??[]);
}
function af_aa_studio_fields(): array {
    return array_merge(af_aa_postbit_fields(), [
        'background_color'=>['color'], 'gradient_color'=>['color'], 'gradient_angle'=>['number',0,360,1],
        'background_opacity'=>['number',0,1,.05], 'darkening'=>['number',0,1,.05],
        'background_position'=>['enum',['center','top','bottom','left','right']], 'background_size'=>['enum',['cover','contain','auto']],
        'background_media_id'=>['media'], 'text_color'=>['color'], 'link_color'=>['color'], 'heading_color'=>['color'],
        'border_color'=>['color'], 'button_color'=>['color'], 'accent_color'=>['color'],
        'font_family'=>['enum',array_keys(af_aa_personal_fonts())], 'font_size'=>['number',8,48,1],
        'font_weight'=>['enum',['400','500','600','700']], 'line_height'=>['number',1.2,2.2,.1],
        'letter_spacing'=>['number',-1,3,.1], 'radius'=>['number',0,24,1], 'border_width'=>['number',0,3,1],
        'shadow'=>['enum',['none','soft','glow']], 'padding'=>['number',0,24,1], 'decorative_line'=>['enum',['none','solid','dashed']],
    ]);
}
function af_aa_studio_validate_components($value, array &$media, string $target='postbit'): array {
    if (!$value instanceof stdClass) throw new AF_AA_PersonalException('Components must be an object.',422);
    $out=[];
    foreach ((array)$value as $component=>$fields) {
        if (!isset(af_aa_studio_components($target)[$component]) || !$fields instanceof stdClass) throw new AF_AA_PersonalException('Unknown component.',422);
        $out[$component]=[];
        foreach ((array)$fields as $key=>$v) {
            $rule=af_aa_studio_fields()[$key]??null;
            if (!$rule || !in_array($key,af_aa_studio_surface_registry()[$target]['components'][$component]['fields'],true)) throw new AF_AA_PersonalException('Unknown component setting.',422);
            if ($v===null) {$out[$component][$key]=null;continue;}
            if ($key==='font_family' && is_string($v) && preg_match('/^ae_[a-f0-9]{24}$/D',$v) && !isset(af_aa_personal_fonts()[$v])) $v='inherit';
            $valid=match($rule[0]) {
                'color'=>is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/D',$v),
                'enum'=>is_string($v) && in_array($v,$rule[1],true),
                'number'=>(is_int($v)||is_float($v)) && is_finite((float)$v) && $v>=$rule[1] && $v<=$rule[2],
                'url'=>is_string($v) && af_aa_postbit_image_url_valid($v),
                'text'=>is_string($v) && mb_strlen($v)<=160 && !preg_match('/[\x00-\x1f<>]/u',$v),
                'media'=>is_int($v) && $v>0 && $v<=4294967295,
                default=>false,
            };
            if (!$valid) throw new AF_AA_PersonalException('Invalid component setting: '.$key,422);
            if ($rule[0]==='media') $media[$v]=true;
            $out[$component][$key]=$v;
        }
    }
    return $out;
}
function af_aa_studio_validate_canvas($v): array {
    if (!$v instanceof stdClass || !function_exists('af_elementtheme_normalize_effects')) throw new AF_AA_PersonalException('ElementTheme effects unavailable.',422);
    $data=(array)$v;
    if (array_diff(array_keys($data),['preset','intensity','speed','density','opacity','color'])) throw new AF_AA_PersonalException('Unknown effect parameter.',422);
    foreach (['intensity','speed','density','opacity'] as $key) if (isset($data[$key]) && (!is_int($data[$key]) || $data[$key]<0 || $data[$key]>100)) throw new AF_AA_PersonalException('Invalid effect range.',422);
    if (isset($data['color']) && (!is_string($data['color']) || ($data['color']!=='' && !preg_match('/^#[a-fA-F0-9]{6}$/D',$data['color'])))) throw new AF_AA_PersonalException('Invalid effect color.',422);
    try {$out=af_elementtheme_normalize_effects($data);}catch(InvalidArgumentException $e){throw new AF_AA_PersonalException($e->getMessage(),422);}
    return array_intersect_key($out,array_flip(['preset','intensity','speed','density','opacity','color']));
}
function af_aa_studio_compile(int $uid, array $s, bool $preview=false, string $target='postbit'): string {
    return af_aa_studio_compile_base($uid,$s,$preview,$target).($target==='postbit'?af_aa_postbit_compile($uid,$s,$preview):'');
}
function af_aa_studio_compile_base(int $uid, array $s, bool $preview=false, string $target='postbit',?string $signatureRoot=null): string {
    if ($target==='postbit') $s=af_aa_postbit_effective_settings($s);
    $prefix=$preview?'#aa-studio-preview ':'';
    $root=$signatureRoot??af_aa_studio_root($uid,$target,$preview);
    $scope=static fn($suffix)=>'body.atf-active '.$root.$suffix.', '.$root.$suffix;
    $css='';
    foreach ($s['components']??[] as $component=>$v) {
        if ($signatureRoot!==null && !in_array($component,['signature','decoration','signature_title','signature_subtitle','signature_quote','signature_buttons'],true)) continue;
        if ($target==='postbit' && in_array($component,['signature_title','signature_subtitle','signature_quote','signature_buttons'],true)) continue;
        if ($target==='postbit' && in_array($component,['topbar','sidebar','message_background'],true)) {
            // Background paint belongs to ATF. Keep unrelated legacy properties readable.
            $v=array_diff_key($v,array_flip(['background_color','background_opacity','background_media_id','background_position','background_size','gradient_color','gradient_angle','darkening']));
        }
        $selector=af_aa_studio_components($target)[$component]??null;if($selector===null)continue;
        $suffix=$selector===''?'':' '.$selector;
        if($target==='theme' && $selector!=='')$suffix.=':not(:where([data-element-surface],[data-element-surface] *))';
        if($target==='profile' && $selector!=='')$suffix.=':not(:where(.af-cs-page,.af-cs-page *))';
        $vars=[];$rules=[];
        $properties=['text_color'=>'color','font_size'=>'font-size','font_weight'=>'font-weight','line_height'=>'line-height',
            'letter_spacing'=>'letter-spacing','radius'=>'border-radius','padding'=>'padding','background_position'=>'background-position','background_size'=>'background-size'];
        foreach ($properties as $key=>$property) if (isset($v[$key])) {
            $unit=in_array($key,['font_size','letter_spacing','radius','padding'],true)?'px':'';
            $vars[]='--aa-'.$key.':'.$v[$key].$unit.';';$rules[]=$property.':var(--aa-'.$key.');';
        }
        if (isset($v['font_family']) && $v['font_family']!=='inherit') {$vars[]='--aa-font-family:'.af_aa_personal_fonts()[$v['font_family']].';';$rules[]='font-family:var(--aa-font-family);';}
        $accent=$v['accent_color']??'var(--atf-post-accent,var(--af-element-accent,#789abc))';
        if (isset($v['accent_color'])) $vars[]='--atf-post-accent:'.$accent.';--af-element-accent:'.$accent.';--af-profile-accent:'.$accent.';--af-cs-accent:'.$accent.';--atf-color-accent:'.$accent.';';
        $alpha=$v['background_opacity']??1;
        if (isset($v['background_color'])) {$vars[]='--aa-background:color-mix(in srgb, '.$v['background_color'].' '.($alpha*100).'%, transparent);';$rules[]='background-color:var(--aa-background);';}
        $layers=[];
        if (isset($v['darkening'])) $layers[]='linear-gradient(rgb(0 0 0 / '.$v['darkening'].'),rgb(0 0 0 / '.$v['darkening'].'))';
        if (isset($v['gradient_color'])) $layers[]='linear-gradient('.($v['gradient_angle']??135).'deg,color-mix(in srgb,'.($v['background_color']??'#000000').' '.($alpha*100).'%,transparent),color-mix(in srgb,'.$v['gradient_color'].' '.($alpha*100).'%,transparent))';
        if (array_key_exists('background_media_id',$v)) {
            $url=$v['background_media_id']!==null && function_exists('ag_resolve_media_url')?ag_resolve_media_url($v['background_media_id'],'full'):'';
            $layers[]=af_aa_css_url_value($url);
        }
        if ($layers) {$vars[]='--aa-background-image:'.implode(',',$layers).';';$rules[]='background-image:var(--aa-background-image);background-repeat:no-repeat;';}
        if (isset($v['border_width']) || isset($v['border_color'])) {$vars[]='--aa-border:'.($v['border_width']??1).'px solid '.($v['border_color']??$accent).';';$rules[]='border:var(--aa-border);';}
        if (isset($v['shadow'])) {$shadow=['none'=>'none','soft'=>'0 4px 12px rgb(0 0 0 / .25)','glow'=>'0 0 12px '.$accent][$v['shadow']];$vars[]='--aa-shadow:'.$shadow.';';$rules[]='box-shadow:var(--aa-shadow);';}
        if (isset($v['decorative_line'])) $rules[]='border-bottom:'.($v['decorative_line']==='none'?'0':'1px '.$v['decorative_line'].' '.$accent).';';
        $css.=$scope($suffix).'{'.implode('',$vars).implode('',$rules).'}';
        foreach (['link_color'=>'a','heading_color'=>':is(h1,h2,h3,h4,h5,h6)','button_color'=>':is(button,.button)'] as $key=>$child) if (isset($v[$key])) $css.=$scope($suffix.' '.$child.($target==='profile'?':not(:where(.af-cs-page,.af-cs-page *))':($target==='theme'?':not(:where([data-element-surface],[data-element-surface] *))':''))).'{--aa-'.$key.':'.$v[$key].';color:var(--aa-'.$key.');}';
    }
    return $css;
}
/** User choices cannot enable a surface disabled by ACP, or manufacture an approved identity. */
function af_aa_studio_canvas(int $uid,array $s,string $target='postbit'): array {
    if (in_array($target,['theme','postbit'],true)) return [];
    if (!isset($s['canvas']) || !function_exists('af_adaptivethemeframework_post_element') || !function_exists('af_elementtheme_get_effects')) return [];
    $key=af_adaptivethemeframework_post_element($uid);
    if ($key==='') return [];
    $base=af_elementtheme_get_effects($key);
    if (empty($base['enabled']) || !in_array($target,$base['surfaces'],true)) return [];
    $out=array_replace($base,$s['canvas']);$out['element_key']=$key;return $out;
}
function af_aa_studio_effect_payload(array $uids): string {
    $out=[];
    foreach($uids as $uid) {
        $row=af_aa_personal_active_row((int)$uid);if (!$row) continue;
        try {$s=af_aa_personal_decode($row['settings_json'],(int)$uid,true,false,true);}catch(AF_AA_PersonalException $e){continue;}
        $config=af_aa_studio_canvas((int)$uid,$s);if ($config) $out[(int)$uid]=$config;
    }
    return $out?'<script type="application/json" data-af-appearance-effects-config>'.json_encode($out,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>':'';
}
/** Substitute fixture data into ATF's actual owned source; never accept client template/HTML. */
function af_aa_studio_preview_markup(int $uid, array $settings=[]): string {
    $path=AF_ADDONS.'adaptivethemeframework/templates/postbit_classic.html';
    if (!is_file($path) || !function_exists('af_adaptivethemeframework_post_element')) throw new AF_AA_PersonalException('Activate Adaptive Theme Framework first.',503);
    $user=get_user($uid);$name=htmlspecialchars_uni($user['username']??'Автор');
    $key=htmlspecialchars_uni(af_adaptivethemeframework_post_element($uid));
    $avatarUrl=af_aa_preview_abs_url((string)($user['avatar']??''));
    if ($avatarUrl==='') $avatarUrl=rtrim((string)($GLOBALS['mybb']->settings['bburl']??''),'/').'/images/default_avatar.png';
    $avatar='<img src="'.htmlspecialchars_uni($avatarUrl).'" alt="Аватар" />';
    $status=(string)($user['usertitle']??'');
    if(trim($status)===''&&function_exists('get_usertitle'))$status=(string)get_usertitle($uid);
    $fixture=array_replace($user,['af_aa_preview_settings'=>$settings,'user_details'=>'','af_atf_is_preview'=>true,'pid'=>0,'tid'=>0,'message'=>'','uid'=>$uid,'username'=>$user['username']??'Автор','useravatar'=>$avatar,'af_atf_slots'=>[]]);
    // MyBB stores custom values separately from users; get_user() alone never includes fidN.
    if(function_exists('af_apf_render_postbit_fields')&&isset($GLOBALS['db'])) {
        $query=$GLOBALS['db']->simple_select('userfields','*','ufid='.(int)$uid);
        $fixture=array_replace($fixture,(array)($GLOBALS['db']->fetch_array($query)?:[]));
        $fixture['user_details']=af_apf_render_postbit_fields($fixture,true);
    }
    if (function_exists('af_apui_postbit_compose_userdetails')) af_apui_postbit_compose_userdetails($fixture);
    $fixture['af_apf_secondary_avatar_url']=$fixture['af_apui_secondary_avatar']??(function_exists('af_apf_get_secondary_avatar')?af_apf_get_secondary_avatar($uid):'');
    af_adaptivethemeframework_compose_postbit($fixture);
    af_aa_postbit_apply_images($fixture,$settings);
    // Native controls are formatted by ATF's shared icon adapter, not text/Unicode stand-ins.
    $fixture['profilelink']='<a href="member.php?action=profile&amp;uid='.$uid.'">'.$name.'</a>';
    if(isset($GLOBALS['templates'])) {
        $post=$fixture;$templates=$GLOBALS['templates'];$lang=$GLOBALS['lang']??null;$mybb=$GLOBALS['mybb'];
        $controls=[];
        if(!empty($mybb->usergroup['cansearch']))$controls['button_find']='postbit_find';
        if(empty($user['hideemail'])&&$uid!==(int)$mybb->user['uid']&&!empty($mybb->usergroup['cansendemail']))$controls['button_email']='postbit_email';
        $groups=isset($GLOBALS['cache'])?(array)$GLOBALS['cache']->read('usergroups'):[];
        $ownerGroup=$groups[(int)($user['usergroup']??0)]??[];
        if(!empty($mybb->settings['enablepms'])&&$uid!==(int)$mybb->user['uid']&&
            ((!empty($user['receivepms'])&&!empty($ownerGroup['canusepms'])&&!empty($mybb->usergroup['cansendpms'])&&!in_array((string)$mybb->user['uid'],explode(',',(string)($user['ignorelist']??'')),true))||!empty($mybb->usergroup['canoverridepm'])))$controls['button_pm']='postbit_pm';
        if(!empty($user['website'])&&!empty($ownerGroup['canchangewebsite'])&&function_exists('is_member')&&!is_member($mybb->settings['hidewebsite']??'')) {
            $post['website']=htmlspecialchars_uni($user['website']);$controls['button_www']='postbit_www';
        }
        foreach($controls as $controlKey=>$template) {eval('$post["'.$controlKey.'"] = "'.$templates->get($template).'";');$fixture[$controlKey]=$post[$controlKey];}
    }
    $plaque=$fixture['af_apui_plaque_html']??(function_exists('af_apui_build_postbit_plaque_html')?af_apui_build_postbit_plaque_html(['uid'=>$uid,'af_aa_preview_settings'=>$settings]):'');
    $signature='';
    if (!class_exists('postParser') && is_file(MYBB_ROOT.'inc/class_parser.php')) require_once MYBB_ROOT.'inc/class_parser.php';
    if (!empty($user['signature']) && class_exists('postParser')) {
        $parser=new postParser();
        $signature=$parser->parse_message($user['signature'],['allow_html'=>0,'allow_mycode'=>1,'allow_smilies'=>1,'allow_imgcode'=>1,'allow_videocode'=>0,'filter_badwords'=>1,'nl2br'=>1]);
    }
    if (function_exists('af_asb_published_html')) {
        $built=af_asb_published_html($uid,(string)($user['signature']??''));
        if ($built!=='') $signature=$built;
    }
    if ($signature==='') $signature='<p>Подпись персонажа · <a href="#">История продолжается</a></p>';
    $signatureMarkup=af_adaptivethemeframework_signature_markup($signature,'appearance-preview',$fixture['af_atf_gothic_frame']);
    $values=['af_atf_signature_html'=>$signatureMarkup,'af_atf_gothic_frame'=>$fixture['af_atf_gothic_frame'],'af_atf_post_reputation_html'=>$fixture['af_atf_post_reputation_html'],'pid'=>'appearance_preview','uid'=>(string)$uid,'af_aa_user_class'=>'af-aa-postbit-user-'.$uid,'af_atf_element'=>$key,
        'usertitle'=>htmlspecialchars_uni($status),'groupimage'=>'','userstars'=>'',
        'af_atf_primary_avatar'=>$fixture['af_atf_primary_avatar'],'af_atf_secondary_avatar'=>$fixture['af_atf_secondary_avatar'],'af_atf_meta_line'=>'<div class="atf-post__meta-line"><time class="atf-post__date">Сегодня</time><a href="#">#1</a><div class="atf-post__management postbit_buttons"><a class="atf-post__management-action" href="#" aria-label="Ответить"><i class="fa-solid fa-reply" aria-hidden="true"></i></a></div></div>',
        'message'=>'<h3>На границе миров</h3><p>Свет отражается в воде. Этот текст помогает выбрать шрифт и проверить читаемость оформления.</p><p><a href="#">Пример ссылки</a> и <strong>выделенный текст</strong>.</p>',
        'signature'=>$signature];
    $slots=['post.author.identity'=>$name,'post.author.character'=>'Персонаж','post.author.character_name'=>($fixture['af_apui_character_name_html']??$fixture['af_atf_slots']['post.author.character_name'])?:('<div class="atf-post__character-name">'.$name.'</div>'),
        'post.author.profile_fields'=>$fixture['af_apui_profile_fields_html']??$fixture['user_details'],'post.author.rail'=>$fixture['af_apui_rail_html']??af_aa_postbit_preview_statistics($user),'post.author.plaque'=>$plaque,'post.author.profile_actions'=>af_adaptivethemeframework_post_context($fixture)['post']['profile_actions_html'],'post.post_counter'=>'#1'];
    $source=file_get_contents($path);
    $source=preg_replace_callback('/\{\$post\[\'([^\']+)\'\](?:\[\'([^\']+)\'\])?\}/',static function($m)use($values,$slots){return isset($m[2])?($slots[$m[2]]??''):($values[$m[1]]??'');},$source);
    return preg_replace('/\{\$[a-z_]+\}/','',$source);
}
function af_aa_studio_preview(int $uid,string $json,string $target='postbit'): array {
    af_aa_personal_actor($uid);af_aa_personal_target($target);
    // Validate ownership/approved media, but do not pin or save during preview.
    $s=af_aa_personal_decode($json,$uid,false,true,false,$target);
    return ['css'=>af_aa_personal_compile_css($uid,$s,true,$target),'canvas'=>af_aa_studio_canvas($uid,$s,$target),'markup'=>$target==='postbit'?af_aa_studio_preview_markup($uid,$s):null];
}
function af_aa_render_workshop(): void {
    global $mybb,$headerinclude;
    $uid=(int)($mybb->user['uid']??0);
    try {af_aa_personal_actor($uid);$target=(string)($mybb->get_input('target')?:'postbit');af_aa_personal_target($target,false);$list=af_aa_personal_list($uid,$target);$preview=af_aa_studio_surface_preview_markup($uid,$target);}catch(AF_AA_PersonalException $e){error($e->getMessage());return;}
    $aa_workshop_atf_class = function_exists('af_adaptivethemeframework_presentation_is_active')
        && af_adaptivethemeframework_presentation_is_active() ? ' atf-active atf-profile-page' : '';
    af_aa_ensure_header_bits();$headerinclude.=af_aa_studio_fonts_link();add_breadcrumb('Мастерская оформления','apstudio.php');
    $base=rtrim((string)($mybb->settings['bburl']??''),'/').'/inc/plugins/advancedfunctionality/addons/advancedappearance/assets/';
    $version=(string)max(filemtime(__DIR__.'/assets/studio.js'),filemtime(__DIR__.'/assets/studio.css'));
    foreach(af_aa_studio_surface_registry()[$target]['css'] as $asset) $headerinclude.='<link rel="stylesheet" href="'.htmlspecialchars_uni(rtrim((string)($mybb->settings['bburl']??''),'/').'/inc/plugins/advancedfunctionality/addons/'.$asset.'?v='.substr(hash_file('sha256',AF_ADDONS.$asset),0,16)).'" />';
    if($target==='postbit'&&!str_contains($headerinclude,'font-awesome-6/css/all.min.css')) {
        $fa='advancedfontawesome/assets/font-awesome-6/css/all.min.css';
        if(is_file(AF_ADDONS.$fa))$headerinclude.='<link rel="stylesheet" href="'.htmlspecialchars_uni(rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/'.$fa).'" data-af-font-awesome="6" />';
    }
    $headerinclude.='<link rel="stylesheet" href="'.htmlspecialchars_uni($base.'studio.css?v='.$version).'" /><script src="'.htmlspecialchars_uni($base.'studio.js?v='.$version).'" defer></script>';
    $seed=['list'=>$list,'target'=>$target,'surface'=>af_aa_studio_surface_registry()[$target],'uid'=>$uid,'csrf'=>$mybb->post_code,'fields'=>af_aa_studio_fields(),'fonts'=>af_aa_personal_fonts(),'components'=>array_keys(af_aa_studio_components($target)),
        'canvas_presets'=>$target!=='postbit' && function_exists('af_elementtheme_effect_presets')?af_elementtheme_effect_presets():[],
        'title_limit'=>af_aa_personal_setting('personal_title_limit',80,1,191),'media_limit'=>af_aa_personal_setting('personal_media_limit',8,0,8)];
    $categories='';foreach(af_aa_personal_targets() as $key=>$meta)$categories.='<a href="apstudio.php?target='.$key.'"'.($key===$target?' aria-current="page"':'').'>'.htmlspecialchars_uni($meta['label']).'</a>';
    $componentOptions='';foreach(af_aa_studio_surface_registry()[$target]['components'] as $key=>$meta)$componentOptions.='<option value="'.$key.'">'.htmlspecialchars_uni($meta['label']).'</option>';
    $vars=['aa_workshop_atf_class'=>$aa_workshop_atf_class,'aa_categories'=>$categories,'aa_component_options'=>$componentOptions,'aa_target_label'=>htmlspecialchars_uni(af_aa_personal_targets()[$target]['label']),'aa_target'=>$target,'aa_seed'=>json_encode($seed,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT),'aa_preview'=>$preview,
        'aa_signature_editor'=>defined('THIS_SCRIPT') && THIS_SCRIPT==='apstudio.php' && basename((string)($_SERVER['SCRIPT_NAME']??THIS_SCRIPT))!=='studio.php' && function_exists('af_asb_studio_link')?af_asb_studio_link():'',
        'aa_catalog'=>af_aa_is_admin_user()?'<a href="apstudio.php?mode=catalog">Каталог магазина</a>':''];
    $html=af_aa_render_template_output('advancedappearance_workshop',$vars);
    if ($target==='postbit') $html=preg_replace('~<fieldset id="aa-canvas-fieldset">.*?</fieldset>~s','',$html);
    output_page($html);exit;
}

/** Override only ATF's avatar payload after composition; native MyBB/avatar identity stays intact. */
function af_aa_studio_postbit_avatar(array &$post): void {
    $uid=(int)($post['uid']??0);if ($uid<=0 || !af_aa_is_enabled()) return;
    $row=af_aa_personal_active_row($uid);if (!$row || !function_exists('ag_resolve_media_url')) return;
    try {$s=af_aa_personal_decode($row['settings_json'],$uid,true,false,true);}catch(AF_AA_PersonalException $e){return;}
    af_aa_postbit_apply_images($post,$s);
}
