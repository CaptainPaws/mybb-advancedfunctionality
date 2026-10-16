<?php
/** Surface adapters for the shared studio/schema/compiler. Layout and mechanics stay with their owners. */
if (!defined('IN_MYBB')) die('No direct access');

function af_aa_studio_surface_registry(): array
{
    $background=['background_color','gradient_color','gradient_angle','background_opacity','darkening','background_position','background_size','background_media_id'];
    $all=array_values(array_diff(array_keys(af_aa_studio_fields()),array_keys(af_aa_postbit_fields())));
    $entry=static fn($label,$selector,$fields=null)=>['label'=>$label,'selector'=>$selector,'fields'=>$fields??$all];
    return [
        'postbit'=>af_aa_postbit_surface(),
        'profile'=>['purpose'=>'appearance_profile_bg','css'=>['adaptivethemeframework/assets/surfaces/profile.css'],'canvas'=>true,'components'=>[
            'page'=>$entry('Фон страницы','',$background),'hero'=>$entry('Hero','.atf-profile__hero'),
            'banner'=>$entry('Баннер','.atf-profile-hero'),'cards'=>$entry('Карточки',':is(.atf-profile__panel:not([data-panel="sheet"]),.atf-profile-native,.atf-profile-signature)'),
            'navigation'=>$entry('Навигация','.atf-profile-nav'),'infobox'=>$entry('Инфобокс',':is(.af-apui-character-infobox,.atf-profile-native)'),
            'headings'=>$entry('Заголовки',':is(.atf-profile-hero__name,.atf-profile-signature__title)')]],
        'sheet'=>['purpose'=>'appearance_sheet_bg','css'=>['charactersheets/assets/charactersheets.css'],'canvas'=>true,'components'=>[
            'page'=>$entry('Фон листа','',$background),'hero'=>$entry('Панель персонажа',':is(.af-cs-summary,.af-cs-arpg-top__right)'),
            'panels'=>$entry('Декоративные панели',':is(.af-cs-section,.af-cs-panel,.af-cs-tab,.af-cs-arpg-panel)'),
            'text'=>$entry('Текст',':is(.af-cs-tab,.af-cs-summary,.af-cs-arpg-top__right)'),
            'headings'=>$entry('Заголовки',':is(h1,h2,h3,.af-cs-section-title)')]],
        'theme'=>['purpose'=>'appearance_theme_bg','css'=>['adaptivethemeframework/assets/surfaces/forum.css','adaptivethemeframework/assets/surfaces/navigation.css'],'canvas'=>false,'components'=>[
            'page'=>$entry('Фон интерфейса','',$background),'cards'=>$entry('Карточки форума',':is(.atf-forum-category,.atf-forum-row,.atf-thread-row)'),
            'navigation'=>$entry('Навигация',':is(.atf-breadcrumbs,.atf-forumdisplay__toolbar,.atf-pagination)'),
            'headings'=>$entry('Заголовки',':is(.atf-forum-category__title,.atf-forum-row__title)')]],
    ];
}

function af_aa_studio_root(int $uid,string $target,bool $preview=false): string
{
    $prefix=$preview?'#aa-studio-preview ':'';
    return $prefix.match($target){
        'postbit'=>'.atf-post.af-aa-postbit-user-'.$uid,
        'profile'=>'.atf-profile.af-aa-profile-user-'.$uid,
        'sheet'=>'.af-cs-page[data-af-apui-owner-uid="'.$uid.'"]',
        'theme'=>$preview?'.aa-theme-preview':'body.af-aa-theme-viewer-'.$uid,
        default=>throw new AF_AA_PersonalException('Unknown surface.',422),
    };
}

function af_aa_surface_settings(int $uid,string $target): ?array
{
    if (!af_aa_is_enabled()) return null;
    $row=af_aa_personal_active_row($uid,$target);if (!$row) return null;
    try{return af_aa_personal_decode($row['settings_json'],$uid,true,false,true,$target);}
    catch(AF_AA_PersonalException $e){return null;}
}

function af_aa_surface_effect_block(int $uid,string $target,array $settings): string
{
    $config=af_aa_studio_canvas($uid,$settings,$target);
    return '<script type="application/json" data-af-appearance-surface-effects-config>'
        .json_encode($config?:new stdClass(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>';
}

/** CharacterSheets calls this after its permission-checked renderer, including AJAX/lazy fragments. */
function af_aa_surface_fragment(string $html,int $uid,string $target): string
{
    if ($target!=='sheet' || $uid<1 || $html==='') return $html;
    $settings=af_aa_surface_settings($uid,$target);if ($settings===null) return $html;
    $css=af_aa_personal_compile_css($uid,$settings,false,$target);
    $payload='<style data-aa-surface="sheet-'.$uid.'">'.$css.'</style>'.af_aa_surface_effect_block($uid,$target,$settings);
    return preg_replace_callback('~<div\b[^>]*\bclass=["\'][^"\']*\baf-cs-page\b[^"\']*["\'][^>]*>~i',
        static fn($m)=>$m[0].$payload,$html,1);
}

/** Profile belongs to the viewed owner; UI theme belongs exclusively to this request's viewer. */
function af_aa_surfaces_pre_output(string &$page): void
{
    global $mybb,$memprofile;
    if (!af_aa_is_enabled() || defined('IN_ADMINCP') || af_aa_is_preview_script() || !str_contains($page,'</head>')) return;
    if (function_exists('af_frontend_asset_allowed') && !af_frontend_asset_allowed(AF_AA_ID,'runtime_css',null,['has_appearance_runtime'=>true])) return;
    $css='';$profileUid=(int)($memprofile['uid']??0);
    if (defined('THIS_SCRIPT') && THIS_SCRIPT==='member.php' && $profileUid>0) {
        $s=af_aa_surface_settings($profileUid,'profile');
        if ($s!==null) {
            $css.=af_aa_personal_compile_css($profileUid,$s,false,'profile');
            $payload=af_aa_surface_effect_block($profileUid,'profile',$s);
            $page=preg_replace_callback('~<main\b[^>]*\bdata-profile-uid=["\']'.$profileUid.'["\'][^>]*>~i',static fn($m)=>$m[0].$payload,$page,1);
        }
    }
    $uid=(int)($mybb->user['uid']??0);
    if ($uid>0 && preg_match('~<body\b[^>]*class=["\'][^"\']*\batf-active\b~i',$page) && ($s=af_aa_surface_settings($uid,'theme'))!==null) {
        $class='af-aa-theme-viewer-'.$uid;
        $page=preg_replace_callback('~<body\b[^>]*>~i',static function($m)use($class){
            $tag=$m[0];if (str_contains($tag,$class)) return $tag;
            return preg_match('~\bclass=(["\'])~i',$tag)?preg_replace('~\bclass=(["\'])~i','class=$1'.$class.' ',$tag,1):substr($tag,0,-1).' class="'.$class.'">';
        },$page,1);
        $css.=af_aa_personal_compile_css($uid,$s,false,'theme');
    }
    if ($css!=='') $page=str_replace('</head>','<style data-aa-personal-surfaces>'.$css.'</style></head>',$page);
}

/** Server templates only, inert fixture data; never evaluate user input or load a real sheet calculator. */
function af_aa_studio_template_fragment(string $path,array $values): string
{
    $source=(string)file_get_contents(AF_ADDONS.$path);
    $source=preg_replace_callback('/\{\$([a-zA-Z_][a-zA-Z0-9_]*)(?:\[\'([^\']+)\'\])?(?:\[\'([^\']+)\'\])?\}/',static function($m)use($values){
        $value=$values[$m[1]]??'';foreach(array_slice($m,2) as $key)$value=is_array($value)?($value[$key]??''):'';
        return is_scalar($value)?(string)$value:'';
    },$source);
    $source=preg_replace('/\{\$[^}]+\}/','',$source);
    $source=preg_replace('~<script\b[^>]*>.*?</script>~is','',$source);
    // Preview uses the actual main component without duplicating html/head/body/document IDs.
    if (preg_match('~<main\b.*?</main>~is',$source,$m)) $source=$m[0];
    // This component is embedded in the workshop's own shell.
    $source=preg_replace_callback('~\bclass=(["\'])([^"\']*)\1~i',static function($m){
        $classes=preg_split('/\s+/',trim($m[2]))?:[];
        if (!in_array('atf-page-shell',$classes,true)) return $m[0];
        $classes=array_values(array_diff($classes,['pun','atf-page-shell']));
        return 'class='.$m[1].implode(' ',$classes).$m[1];
    },$source);
    return $source;
}

function af_aa_studio_surface_preview_markup(int $uid,string $target): string
{
    if ($target==='postbit') return af_aa_studio_preview_markup($uid);
    $user=get_user($uid);$name=htmlspecialchars_uni($user['username']??'Персонаж');
    $element=function_exists('af_adaptivethemeframework_post_element')?af_adaptivethemeframework_post_element($uid):'';
    if ($target==='profile') {
        if (!function_exists('af_apui_render_atf_profile_hero') || !function_exists('af_adaptivethemeframework_render_profile_main'))
            throw new AF_AA_PersonalException('Activate ATF and AdvancedProfileUI first.',503);
        $context=['uid'=>$uid,'username'=>$name,'appearance'=>['uid_class'=>'af-aa-profile-user-'.$uid],
            'identity'=>['formattedname'=>$name,'usertitle'=>'Путешественник','memregdate'=>'01.01.2025','memlastvisitdate'=>'Сегодня','timeonline'=>'12 часов'],
            'member'=>['postnum'=>128,'threadnum'=>12,'reputation'=>24],
            'avatars'=>['primary_avatar'=>rtrim((string)($GLOBALS['mybb']->settings['bburl']??''),'/').'/images/default_avatar.png'],
            'native'=>['signature'=>'<p>История продолжается…</p>','profilefields'=>'<tr><td>Мир</td><td>Варп</td></tr>'],
            'sections'=>['info'=>'Пример информации']];
        $values=['memprofile'=>['uid'=>$uid],'atf_profile_context'=>['appearance'=>['uid_class'=>'af-aa-profile-user-'.$uid,'element_theme_key'=>htmlspecialchars_uni($element)]],
            'atf_profile_hero'=>af_apui_render_atf_profile_hero($context),'atf_profile_navigation'=>af_apui_render_atf_profile_navigation($context),
            'atf_profile_main'=>af_adaptivethemeframework_render_profile_main($context)];
        return af_aa_studio_template_fragment('adaptivethemeframework/templates/member_profile.html',$values);
    }
    if ($target==='sheet') {
        return af_aa_studio_template_fragment('charactersheets/templates/charactersheet_inner.html',[
            'sheet_owner_uid'=>$uid,'sheet_element_theme_key'=>htmlspecialchars_uni($element),'sheet_title'=>$name,'sheet_name_ru'=>$name,
            'sheet_mode_attr'=>'dnd','sheet_id'=>0,'sheet_portrait_url'=>rtrim((string)($GLOBALS['mybb']->settings['bburl']??''),'/').'/images/default_avatar.png',
            'sheet_level'=>'5','sheet_class'=>'Путешественник','sheet_race'=>'Человек','sheet_info_table_html'=>'<tr><td>Класс</td><td>Путешественник</td></tr>',
            'sheet_attributes_html'=>'<div>Сила 12 · Ловкость 14 · Выносливость 13</div>','sheet_mechanics_title'=>'Особенности','sheet_mechanics_html'=>'<p>Пример описания и <a href="#">ссылки</a>. Механики не меняются.</p>']);
    }
    if ($target==='theme') {
        $forum=['fid'=>0,'name'=>'Истории миров','description'=>'Пример интерфейса форума'];
        $category=af_aa_studio_template_fragment('adaptivethemeframework/templates/forumbit_depth1_cat.html',[
            'forum'=>$forum,'forum_url'=>'#','sub_forums'=>af_aa_studio_template_fragment('adaptivethemeframework/templates/forumbit_depth2_forum.html',[
                'forum'=>$forum,'forum_url'=>'#','threads'=>'12','posts'=>'128'])]);
        $main=af_aa_studio_template_fragment('adaptivethemeframework/templates/index.html',['forums'=>$category]);
        return '<div class="aa-theme-preview">'.$main.'</div>';
    }
    throw new AF_AA_PersonalException('Unknown target.',422);
}
