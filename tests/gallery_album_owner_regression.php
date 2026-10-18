<?php
/** Short list/template/access fixture; no forum database or network. */
define('IN_MYBB',1);
define('TABLE_PREFIX','test_');
define('AF_ADDONS',dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
$mybb=(object)['user'=>['uid'=>7,'usergroup'=>2,'additionalgroups'=>''],
    'settings'=>['bburl'=>'https://forum.test','af_advancedgallery_enabled'=>1,'af_advancedgallery_can_moderate_groups'=>'4']];
$lang=(object)['af_advancedgallery_create_album'=>'Создать альбом'];
function htmlspecialchars_uni($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function build_profile_link($name,$uid){return '<a href="member.php?action=profile&amp;uid='.(int)$uid.'">'.$name.'</a>';}
function error_no_permission(){throw new RuntimeException('Permission denied');}
function album_check($value,$message){if (!$value)throw new RuntimeException($message);}
class OwnerQuery {public int $offset=0;function __construct(public array $rows){}}
class OwnerDB {
    public array $queries=[];
    function write_query($sql){
        $this->queries[]=$sql;
        if (str_contains($sql,'LEFT JOIN test_users')) {
            album_check(str_contains($sql,'u.uid=a.uid_owner'),'Owner join mismatch');
            $rows=[];
            foreach ([1=>[7,'private','Own'],2=>[8,'public','Public'],3=>[8,'private','Secret'],4=>[9,'public','Deleted'],5=>[8,'registered','Registered']] as $id=>[$uid,$visibility,$title]) {
                if (str_contains($sql,"WHERE a.uid_owner='7'") && $uid!==7)continue;
                $rows[]=['id'=>$id,'uid_owner'=>$uid,'visibility'=>$visibility,'title'=>$title,'system_key'=>null,'cover_media_id'=>0,
                    'owner_uid'=>$uid===9?null:$uid,'owner_username'=>$uid===9?null:($uid===7?'Owner':'Long<&"'.str_repeat('Name',40))];
            }
            return new OwnerQuery($rows);
        }
        if (str_contains($sql,'COUNT(*)'))return new OwnerQuery([['cnt'=>2]]);
        if (str_contains($sql,'af_gallery_album_media'))return new OwnerQuery([]);
        throw new RuntimeException('Unexpected query: '.$sql);
    }
    function fetch_array($q){return $q->rows[$q->offset++]??false;}
    function fetch_field($q,$key){$row=$this->fetch_array($q);return $row[$key]??null;}
}
class OwnerTemplates {
    function get($name){
        $source=file_get_contents(AF_ADDONS.'advancedgallery/templates/advancedgallery.html');
        preg_match('~<!-- TEMPLATE: '.preg_quote($name,'~').' -->\s*(.*?)(?=<!-- TEMPLATE:|\z)~s',$source,$match);
        return addslashes($match[1]??'');
    }
}
$db=new OwnerDB();$templates=new OwnerTemplates();
require AF_ADDONS.'advancedgallery/advancedgallery.php';
$public=ag_render_home_albums();
album_check(str_contains($public,'Автор: <a href="member.php?action=profile&amp;uid=8">'),'Public owner link absent');
album_check(str_contains($public,'Long&lt;&amp;&quot;') && !str_contains($public,'Long<&"'),'Username not escaped');
album_check(!str_contains($public,'Secret'),'Private album leaked to regular user');
album_check(str_contains($public,'Удалённый пользователь') && !str_contains($public,'profile&amp;uid=9'),'Deleted owner fallback broken');
album_check(count(array_filter($db->queries,fn($q)=>str_contains($q,'JOIN test_users')))===1,'Additional owner queries');
$db->queries=[];$mine=ag_render_albums();
album_check(str_contains($mine,'profile&amp;uid=7') && !str_contains($mine,'Public'),'My albums scope changed');
album_check(str_contains($db->queries[0],"WHERE a.uid_owner='7'") && str_contains($db->queries[0],'ORDER BY a.created_at DESC'),'My albums query changed scope/order');
$mybb->user['usergroup']=4;$db->queries=[];$moderator=ag_render_home_albums();
album_check(str_contains($moderator,'Secret') && str_contains($moderator,'profile&amp;uid=8'),'Moderator lacks private album owner');
album_check(str_contains($db->queries[0],'ORDER BY a.updated_at DESC'),'Catalog order changed');
$mybb->user=['uid'=>0,'usergroup'=>1,'additionalgroups'=>''];$guest=ag_render_home_albums();
album_check(!str_contains($guest,'Secret') && !str_contains($guest,'Registered') && !str_contains($guest,'>Own<'),'Guest access changed');
$css=file_get_contents(AF_ADDONS.'advancedgallery/assets/advancedgallery.css');
album_check((bool)preg_match('/\.ag-meta\s*\{[^}]*overflow-wrap:\s*anywhere/s',$css),'Long names lack wrapping');
echo "Album owners passed: joined catalog/my/moderator lists, linked escaped long names, deleted owner, no per-card user query, private/guest access, existing wrap styling.\n";
