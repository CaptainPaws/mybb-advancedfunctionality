<?php
/** Read-only adapter for native MyBB postbit fields, also used by appearance previews. */
if (!defined('IN_MYBB')) die('No direct access');
function af_apf_postbit_field_definitions(): array
{
    $cache=$GLOBALS['cache']??null;
    $fields=is_object($cache)&&method_exists($cache,'read')?$cache->read('profilefields'):[];
    $out=[];
    foreach((array)$fields as $field) {
        if(!is_array($field)||empty($field['postbit'])||(int)($field['fid']??0)<=0)continue;
        $out[(int)$field['fid']]=$field;
    }
    return $out;
}
function af_apf_render_postbit_fields(array $post,bool $preview=false): string
{
    global $templates,$mybb;
    $fields=af_apf_postbit_field_definitions();if(!$fields)return '';
    if(!class_exists('postParser')&&is_file(MYBB_ROOT.'inc/class_parser.php'))require_once MYBB_ROOT.'inc/class_parser.php';
    if(!class_exists('postParser'))return '';
    $parser=new postParser();$html='';
    foreach($fields as $fid=>$field) {
        $raw=(string)($post['fid'.$fid]??'');
        if($raw==='') {
            if($preview)$html.='<span class="af-apf-postbit-field af-apf-postbit-field--fid'.$fid.'" data-field-id="'.$fid.'" data-aa-empty-field="1"><span class="af-apf-value">'.htmlspecialchars_uni($field['name']).' · не заполнено</span></span>';
            continue;
        }
        $post['fieldname']=htmlspecialchars_uni($field['name']);$post['fieldvalue']='';
        $type=trim(explode("\n",(string)$field['type'],2)[0]);
        if(in_array($type,['multiselect','checkbox'],true)) {
            $post['fieldvalue_option']='';
            foreach(explode("\n",$raw) as $val)if($val!=='') {
                $val=htmlspecialchars_uni($val);
                eval('$post["fieldvalue_option"] .= "'.$templates->get('postbit_profilefield_multiselect_value').'";');
            }
            if($post['fieldvalue_option']!=='')eval('$post["fieldvalue"] = "'.$templates->get('postbit_profilefield_multiselect').'";');
        } else {
            $options=['allow_html'=>$field['allowhtml']??0,'allow_mycode'=>$field['allowmycode']??0,'allow_smilies'=>$field['allowsmilies']??0,'allow_imgcode'=>$field['allowimgcode']??0,'allow_videocode'=>$field['allowvideocode']??0,'filter_badwords'=>1];
            if($type==='textarea')$options['me_username']=$post['username']??'';else $options['nl2br']=0;
            if(!empty($mybb->user['uid'])?empty($mybb->user['showimages']):empty($mybb->settings['guestimages']))$options['allow_imgcode']=0;
            $post['fieldvalue']=$parser->parse_message($raw,$options);
        }
        // Use the same native field template (including ACP customisations) as build_postbit().
        $chunk='';eval('$chunk = "'.$templates->get('postbit_profilefield').'";');
        // Stable wrapper also supports an unpatched native template without APF classes.
        if(!str_contains($chunk,'af-apf-postbit-field--fid'.$fid))$chunk='<span class="af-apf-postbit-field af-apf-postbit-field--fid'.$fid.'" data-field-id="'.$fid.'">'.$chunk.'</span>';
        $html.=$chunk;
    }
    return $html;
}
