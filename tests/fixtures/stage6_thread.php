<?php
// Render production postbit/showthread seeds with a bounded native-value facade.
define('IN_MYBB', true);
define('THIS_SCRIPT', 'showthread.php');
define('AF_ADDONS', dirname(__DIR__, 2) . '/inc/plugins/advancedfunctionality/addons/');
require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';
function htmlspecialchars_uni($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$mybb = (object)['settings'=>['bburl'=>''], 'user'=>['uid'=>42]];
af_adaptivethemeframework_register_post_providers();
foreach (['identity'=>'profilelink','profile_fields'=>'af_apui_profile_fields_html','rail'=>'af_apui_author_statistics_html','character'=>'af_apui_actionbar_html'] as $key=>$field) {
    af_adaptivethemeframework_register_component(['owner'=>'mybb','key'=>'fixture_'.$key,'slot'=>'post.author.'.$key,
        'renderer'=>static fn(array $ctx): string => $ctx['post'][$field] ?? '']);
}
$render = static function(string $seed, array $values): string {
    return preg_replace_callback('~\{\$([^}]+)\}~', static function(array $m)use($values): string {
        if (preg_match("~^post\\['([^']+)'\\](?:\\['([^']+)'\\])?$~",$m[1],$p)) {
            return isset($p[2]) ? (string)($values['post'][$p[1]][$p[2]]??'') : (string)($values['post'][$p[1]]??'');
        }
        return (string)($values[$m[1]] ?? '');
    },$seed);
};
$posts='';
for($i=1;$i<=5;++$i){
    $post=['pid'=>$i,'uid'=>42,'username'=>'Author','postdate'=>'Сегодня, 12:34',
        'posturl'=>'<input type="checkbox" name="inlinemod_'.$i.'"> <a href="#pid'.$i.'">#'.$i.'</a>',
        'profilelink'=>'<a href="/profile">Имя автора</a>','usertitle'=>'Статус автора',
        'message'=>str_repeat('<p>Текст сообщения с содержимым и ссылкой <a href="/topic">в теме</a>.</p>',25+$i*5),
        'useravatar'=>'<img alt="avatar" src="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%27200%27 height=%27200%27%3E%3Crect width=%27200%27 height=%27200%27 fill=%27%2388aacc%27/%3E%3C/svg%3E">',
        'af_apui_profile_fields_html'=>'<div class="af-apui-profile-field">Поле персонажа</div>',
        'af_apui_author_statistics_html'=>'<div class="author_statistics"><span>Посты: 42</span><span>Темы: 8</span></div>',
        'af_apui_actionbar_html'=>'<div class="af-apui-postbit-actions"><a class="af-apui-postbit-action af-apui-postbit-action--sheet af-cs-plaque__btn" data-afcs-open="1" data-afcs-sheet="/sheet" href="/sheet"><i class="af-apui-postbit-action__icon">Лист</i></a></div>',
        'button_quote'=>'<a href="#reply">Ответить</a>','button_edit'=>'<a href="#edit">Правка</a>',
        'button_pm'=>'<a href="/private">ЛС</a>', 'button_rep'=>'<a href="#rep">Оценка</a>'];
    af_adaptivethemeframework_preload_postbit_data($post);
    af_adaptivethemeframework_compose_postbit($post);
    $posts.=$render(file_get_contents(AF_ADDONS.'adaptivethemeframework/templates/postbit_classic.html'),['post'=>$post]);
}
$seed=file_get_contents(AF_ADDONS.'adaptivethemeframework/templates/showthread.html');
$seed=preg_replace('~<script\b[^>]*>.*?</script>~s','',$seed);
$page=$render($seed,['posts'=>$posts,'thread[\'subject\']'=>'Тема для проверки',
    'thread[\'displayprefix\']'=>'','atf_thread_breadcrumbs'=>'<nav class="atf-breadcrumbs"><a href="/">Форум</a></nav>',
    'header'=>'<nav class="af-am-navigation">Navigation</nav>',
    'quickreply'=>'<form class="atf-card atf-editor atf-quick-reply" id="quick_reply_form"><h2>Быстрый ответ</h2><textarea rows="5"></textarea><input class="button" type="submit" value="Ответить"></form>',
    'footer'=>'<footer>Footer</footer>']);
af_adaptivethemeframework_mark_page($page);
echo $page;
