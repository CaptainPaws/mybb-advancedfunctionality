<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_advancedmessenger_e(string $text): string { return htmlspecialchars_uni($text); }

function af_advancedmessenger_t(string $key): string
{
    global $mybb;
    $ru = [
        'draft_action'=>'Черновик', 'preview_action'=>'Просмотр', 'send_action'=>'Отправить', 'cancel'=>'Отмена', 'search_title'=>'Расширенный поиск ЛС', 'search_submit'=>'Найти сообщения', 'criteria'=>'Основные критерии', 'filters'=>'Фильтры', 'sorting'=>'Сортировка', 'sent_copy'=>'Сохранённая отправленная копия; подтверждение прочтения недоступно', 'receipt_confirmed'=>'Прочтение подтверждено MyBB', 'quoted_history'=>'Предыдущие цитаты', 'online'=>'В сети', 'profile'=>'Профиль собеседника', 'title'=>'Личные сообщения', 'compose'=>'Написать сообщение', 'dialogs'=>'Диалоги', 'all'=>'Все сообщения',
        'folders'=>'Папки', 'tools'=>'Инструменты', 'search'=>'Поиск собеседника',
        'content_search'=>'Поиск по сообщениям', 'advanced_search'=>'Расширенный поиск',
        'tracking'=>'Отслеживание', 'export'=>'Экспорт', 'empty'=>'Очистка папок', 'manage'=>'Управление папками',
        'none'=>'Диалогов пока нет.', 'select'=>'Выберите диалог или напишите новое сообщение.',
        'unknown'=>'Удалённый пользователь', 'group'=>'Отдельное / групповое сообщение',
        'older'=>'Предыдущие сообщения', 'latest'=>'Последние сообщения', 'loading'=>'Загрузка…', 'error'=>'Не удалось загрузить сообщения. Повторите попытку.',
        'back'=>'К списку диалогов', 'close'=>'Закрыть', 'actions'=>'Действия', 'reply'=>'Ответить',
        'quote'=>'Цитировать', 'replyall'=>'Ответить всем', 'forward'=>'Переслать', 'delete'=>'Удалить', 'move'=>'Переместить',
        'read'=>'Прочитано вами', 'unread'=>'Не прочитано', 'sent'=>'Сохранённая отправленная копия',
        'delete_confirm'=>'Удалить выбранное сообщение? В корзине оно будет удалено окончательно.',
        'empty_confirm'=>'Очистить выбранные папки? Это действие нельзя отменить.',
        'folder_messages'=>'Сообщения папки и групповые действия', 'sending'=>'Отправка…',
        'saved'=>'Черновик сохранён', 'sent_ok'=>'Сообщение отправлено', 'draft_local'=>'Текст сохранён в этой вкладке',
        'draft_restore'=>'Восстановить текст из этой вкладки', 'open'=>'Открыть сообщение', 'retry'=>'Повторить',
        'session'=>'Сессия недействительна или истекла. Откройте личные сообщения заново.',
        'csrf'=>'Неверный CSRF-токен. Обновите страницу, предварительно сохранив текст.',
        'recipient'=>'Получатель', 'recipient_hint'=>'Дополнительный фильтр: укажите также ключевые слова или отправителя. Скрытые получатели не участвуют в поиске.',
        'response'=>'Сервер вернул страницу вместо ответа API. Откройте диалог обычным переходом, чтобы увидеть сообщение MyBB.',
    ];
    $en = [
        'draft_action'=>'Draft', 'preview_action'=>'Preview', 'send_action'=>'Send', 'cancel'=>'Cancel', 'search_title'=>'Advanced message search', 'search_submit'=>'Find messages', 'criteria'=>'Main criteria', 'filters'=>'Filters', 'sorting'=>'Sort', 'sent_copy'=>'Saved sent copy; read confirmation unavailable', 'receipt_confirmed'=>'Read receipt confirmed by MyBB', 'quoted_history'=>'Earlier quotes', 'online'=>'Online', 'profile'=>'Participant profile', 'title'=>'Messages', 'compose'=>'New message', 'dialogs'=>'Conversations', 'all'=>'All conversations',
        'folders'=>'Folders', 'tools'=>'Tools', 'search'=>'Find a participant',
        'content_search'=>'Search messages', 'advanced_search'=>'Advanced search',
        'tracking'=>'Tracking', 'export'=>'Export', 'empty'=>'Empty folders', 'manage'=>'Manage folders',
        'none'=>'No conversations yet.', 'latest'=>'Latest messages', 'select'=>'Select a conversation or write a new message.',
        'unknown'=>'Deleted user', 'group'=>'Separate / group message', 'older'=>'Older messages',
        'loading'=>'Loading…', 'error'=>'Could not load messages. Please try again.', 'back'=>'Back to conversations',
        'close'=>'Close', 'actions'=>'Actions', 'reply'=>'Reply', 'quote'=>'Quote', 'forward'=>'Forward',
        'delete'=>'Delete', 'move'=>'Move', 'replyall'=>'Reply to all', 'read'=>'Read by you', 'unread'=>'Unread',
        'sent'=>'Saved sent copy', 'delete_confirm'=>'Delete this message? Messages in Trash are permanently removed.',
        'empty_confirm'=>'Empty the selected folders? This cannot be undone.', 'folder_messages'=>'Folder messages and bulk actions',
        'sending'=>'Sending…', 'saved'=>'Draft saved', 'sent_ok'=>'Message sent',
        'draft_local'=>'Text saved in this tab', 'draft_restore'=>'Restore text from this tab',
        'open'=>'Open message', 'retry'=>'Retry', 'session'=>'Your session has expired. Open private messages again.',
        'csrf'=>'Invalid CSRF token. Save your text before refreshing this page.',
        'recipient'=>'Recipient', 'recipient_hint'=>'Additional filter: also enter keywords or a sender. Hidden recipients are not searched.',
        'response'=>'The server returned a page instead of an API response. Open the conversation normally to see the MyBB message.',
    ];
    $language = (string)(($mybb->user['language'] ?? '') ?: ($mybb->settings['bblanguage'] ?? 'english'));
    return str_starts_with($language, 'russian') ? ($ru[$key] ?? $key) : ($en[$key] ?? $key);
}

function af_advancedmessenger_folders(): array
{
    global $foldernames;
    $folders = is_array($foldernames ?? null) ? $foldernames : [];
    // 0 is MyBB's actual inbox alias; 1 can be the virtual Unread view.
    if (isset($folders[0])) { $folders[1] = $folders[0]; unset($folders[0]); }
    ksort($folders);
    return $folders;
}

function af_advancedmessenger_link(string $url, string $label, bool $panel = false, string $icon = ''): string
{
    return '<a href="'.af_advancedmessenger_e($url).'"'.($panel ? ' data-am-panel="1"' : '').'>'.($icon !== '' ? af_advancedmessenger_icon($icon) : '').'<span>'.af_advancedmessenger_e($label).'</span></a>';
}

function af_advancedmessenger_avatar(array $user): string
{
    $src = (string)($user['avatar'] ?? '');
    if ($src !== '' && preg_match('~^(?:https?://|/|(?:\./)?(?:uploads|images)/)~i', $src)) {
        return '<img class="am-avatar" src="'.af_advancedmessenger_e($src).'" alt="" loading="lazy">';
    }
    return '<span class="am-avatar" aria-hidden="true">'.af_advancedmessenger_e(my_substr((string)($user['username'] ?? af_advancedmessenger_t('unknown')),0,1)).'</span>';
}

function af_advancedmessenger_sidebar(array $context): string
{
    global $mybb, $lang, $parser, $db;
    $counts = [];
    $q = $db->simple_select('privatemessages', 'folder, COUNT(*) AS unread', 'uid='.(int)$mybb->user['uid'].' AND status=0 AND folder NOT IN (2,3,4)', ['group_by'=>'folder']);
    while ($count = $db->fetch_array($q)) $counts[(int)$count['folder']] = (int)$count['unread'];
    $folder = $context['folder'];
    $html = '<header class="am-sidebar__head"><h1>'.af_advancedmessenger_e(af_advancedmessenger_t('title')).' <span data-am-unread-count="1">'
        .af_advancedmessenger_unread().'</span></h1>';
    if (!empty($mybb->usergroup['cansendpms'])) $html .= af_advancedmessenger_link('private.php?action=send', af_advancedmessenger_t('compose'), true, 'compose');
    $html .= '</header><form method="get" action="private.php" class="am-name-search">'
        .'<input type="hidden" name="am_folder" value="'.$folder.'">'
        .'<label class="am-visually-hidden" for="am-search">'.af_advancedmessenger_e(af_advancedmessenger_t('search')).'</label>'
        .'<div class="am-search-control"><input id="am-search" name="am_name" type="search" maxlength="120" placeholder="'.af_advancedmessenger_e(af_advancedmessenger_t('search')).'" value="'.af_advancedmessenger_e($context['name']).'">'
        .'<button type="submit" aria-label="'.af_advancedmessenger_e($lang->search_button).'">'.af_advancedmessenger_icon('search').'</button></div></form>';
    $html .= '<div class="am-sidebar-sections"><details class="am-folders"><summary>'.af_advancedmessenger_icon('folder').'<span>'.af_advancedmessenger_e(af_advancedmessenger_t('folders')).'</span>'.af_advancedmessenger_icon('chevron').'</summary><nav>'
        .'<a href="private.php"'.($folder === 0 ? ' aria-current="page"' : '').'>'.af_advancedmessenger_icon('folder').'<span>'.af_advancedmessenger_e(af_advancedmessenger_t('all')).'</span>'.(array_sum($counts) ? '<b class="am-unread">'.array_sum($counts).'</b>' : '').'</a>';
    foreach (af_advancedmessenger_folders() as $id => $name) {
        $html .= '<a href="private.php?fid='.((int)$id === 1 ? 0 : (int)$id).'&amp;am_folder='.(int)$id.'"'
            .($folder === (int)$id ? ' aria-current="page"' : '').'>'.af_advancedmessenger_icon([1=>'inbox',2=>'sent',3=>'draft',4=>'trash'][(int)$id] ?? 'folder').'<span>'.af_advancedmessenger_e($name).'</span>'.(!empty($counts[(int)$id]) ? '<b class="am-unread">'.$counts[(int)$id].'</b>' : '').'</a>';
    }
    $html .= '</nav></details>';
    $html .= '<details class="am-tools"><summary>'.af_advancedmessenger_icon('tools').'<span>'.af_advancedmessenger_e(af_advancedmessenger_t('tools')).'</span>'.af_advancedmessenger_icon('chevron').'</summary><nav>';
    foreach (['advanced_search'=>'advanced_search', 'folders'=>'manage', 'tracking'=>'tracking', 'export'=>'export', 'empty'=>'empty'] as $action => $label) {
        if ($action === 'tracking' && empty($mybb->usergroup['cantrackpms'])) continue;
        $html .= af_advancedmessenger_link('private.php?action='.$action, af_advancedmessenger_t($label), true, ['advanced_search'=>'search','folders'=>'folder','tracking'=>'tracking','export'=>'export','empty'=>'trash'][$action]);
    }
    $html .= '</nav><form action="private.php" method="post" class="am-content-search">'
        .'<input type="hidden" name="my_post_key" value="'.af_advancedmessenger_e($mybb->post_code).'">'
        .'<input type="hidden" name="action" value="do_stuff"><input type="hidden" name="fid" value="'.$folder.'">'
        .'<label class="am-visually-hidden" for="am-keywords">'.af_advancedmessenger_e(af_advancedmessenger_t('content_search')).'</label>'
        .'<div class="am-search-control"><input id="am-keywords" name="keywords" type="search" placeholder="'.af_advancedmessenger_e(af_advancedmessenger_t('content_search')).'" required>'
        .'<button name="quick_search" value="1" aria-label="'.af_advancedmessenger_e(af_advancedmessenger_t('content_search')).'">'.af_advancedmessenger_icon('search').'</button></div></form></details></div><h2 class="am-dialogs-heading">'.af_advancedmessenger_e(af_advancedmessenger_t('dialogs')).'</h2>';
    $html .= '<nav id="am-dialogs" class="am-dialogs" aria-label="'.af_advancedmessenger_e(af_advancedmessenger_t('dialogs')).'">';
    foreach ($context['dialogs']['items'] as $dialog) {
        $row = $dialog['row'];
        $user = $dialog['user'];
        $direct = str_starts_with($dialog['key'], 'user:');
        $name = $direct ? $user['username'] : $parser->parse_badwords((string)($row['subject'] ?? af_advancedmessenger_t('group')));
        $url = (int)$row['folder'] === 3 ? 'private.php?action=send&pmid='.(int)$row['pmid']
            : 'private.php?action=read&pmid='.(int)$row['pmid'].'&am_folder='.$folder;
        if ((int)$row['folder'] !== 3) $url .= '&am_name='.rawurlencode($context['name']).'&am_page='.(int)$context['dialogs']['page'];
        $active = $dialog['key'] === $context['active'];
        $avatar = af_advancedmessenger_avatar($user);
        $snippet = $parser->text_parse_message((string)($row['message'] ?? ''), ['filter_badwords'=>1]);
        $snippet = my_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($snippet)) ?? ''), 0, 110);
        $html .= '<a class="am-dialog'.($active ? ' is-active' : '').'" href="'.af_advancedmessenger_e($url).'"'
            .((int)$row['folder'] === 3 ? ' data-am-panel="1"' : ' data-am-dialog="1"')
            .' data-am-key="'.af_advancedmessenger_e($dialog['key']).'"'.($active ? ' aria-current="page"' : '').'>'
            .'<span class="am-dialog__avatar">'.$avatar.'</span><span class="am-dialog__text"><strong>'.af_advancedmessenger_e($name)
            .'</strong><span>'.af_advancedmessenger_e($snippet).'</span></span><span class="am-dialog__meta">'
            .my_date((int)$row['dateline'] >= TIME_NOW - 86400 ? (string)$mybb->settings['timeformat'] : 'd.m.y', (int)$row['dateline']).($dialog['unread'] ? '<b class="am-unread">'.$dialog['unread'].'</b>' : '')
            .'</span></a>';
    }
    if (!$context['dialogs']['items']) $html .= '<p class="am-empty">'.af_advancedmessenger_e(af_advancedmessenger_t('none')).'</p>';
    $html .= '</nav><nav class="am-pages" aria-label="'.af_advancedmessenger_e(af_advancedmessenger_t('dialogs')).'">';
    $data = $context['dialogs'];
    foreach ([-1=>'', 1=>''] as $delta => $label) {
        $page = $data['page'] + $delta;
        if ($page < 1 || $page > $data['pages']) continue;
        $html .= af_advancedmessenger_link('private.php?am_folder='.$folder.'&am_page='.$page.'&am_name='.rawurlencode($context['name']), (string)$page, false, $delta < 0 ? 'previous' : 'next');
    }
    return $html.'</nav>';
}

function af_advancedmessenger_bubble(array $row): string
{
    global $mybb;
    $outgoing = (int)$row['fromid'] === (int)$mybb->user['uid'];
    $id = (int)$row['pmid'];
    // Never infer recipient read state from a saved copy: MyBB doesn't store it there.
    $unread = (int)$row['status'] === 0 && !in_array((int)$row['folder'],[3,4],true);
    $state = $unread ? 'unread' : ($outgoing ? 'sent' : 'read');
    $name = $row['author_username'] ?? null;
    if ($name === null) { $author = get_user((int)$row['fromid']); $name = $author['username'] ?? af_advancedmessenger_t('unknown'); }
    // Message content is parsed directly: never compose a forum postbit here.
    $author = get_user((int)$row['fromid']) ?: ['uid'=>0, 'username'=>$name, 'avatar'=>''];
    $profile = get_profile_link((int)$row['fromid']); // Already attribute-escaped by MyBB.
    $avatar = !empty($author['uid'])
        ? '<a class="am-bubble__avatar" href="'.$profile.'" aria-label="'.af_advancedmessenger_e((string)$name).'">'.af_advancedmessenger_avatar($author).'</a>'
        : '<span class="am-bubble__avatar">'.af_advancedmessenger_avatar($author).'</span>';
    $html = '<article class="am-bubble'.($outgoing ? ' am-bubble--out' : '').'" data-am-pmid="'.$id
        .'" data-am-unread="'.($unread && !$outgoing ? '1' : '0').'">'
        .(!$outgoing ? $avatar : '')
        .'<div class="am-bubble__body">'.af_advancedmessenger_parse_message($row).'</div>'
        .'<footer class="am-bubble__foot"><a href="private.php?action=read&amp;pmid='.$id.'"><time datetime="'.gmdate('c',(int)$row['dateline']).'">'.my_date((string)$mybb->settings['timeformat'],(int)$row['dateline']).'</time></a>'
        .($outgoing ? af_advancedmessenger_receipt_indicator($row) : '')
        .(!$outgoing ? '<span class="am-visually-hidden" data-am-state="1">'.af_advancedmessenger_e(af_advancedmessenger_t($state)).'</span>' : '')
        .'<details class="am-message-actions" data-am-menu="1"><summary aria-label="'.af_advancedmessenger_e(af_advancedmessenger_t('actions')).'" title="'.af_advancedmessenger_e(af_advancedmessenger_t('actions')).'">'.af_advancedmessenger_icon('actions').'</summary><div class="am-dropdown" popover="manual"><nav>';
    if (!empty($mybb->usergroup['cansendpms'])) {
        foreach (['reply'=>'reply', 'quote'=>'reply', 'forward'=>'forward'] as $label => $do) {
            $html .= af_advancedmessenger_link('private.php?action=send&do='.$do.'&pmid='.$id.($label === 'reply' ? '&am_noquote=1' : ''), af_advancedmessenger_t($label), true, $label);
        }
        if (count(af_advancedmessenger_reply_all_ids($row, (int)$mybb->user['uid'])) > 1) {
            $html .= af_advancedmessenger_link('private.php?action=send&do=replyall&pmid='.$id,af_advancedmessenger_t('replyall'),true,'replyall');
        }
    }
    $html .= af_advancedmessenger_link('private.php?action=read&pmid='.$id, af_advancedmessenger_t('open'), false, 'open').'</nav>';
    $fields = '<input type="hidden" name="am_panel" value="'.($mybb->get_input('am_panel') ? '1' : '0').'"><input type="hidden" name="my_post_key" value="'.af_advancedmessenger_e($mybb->post_code).'">'
        .'<input type="hidden" name="action" value="do_stuff"><input type="hidden" name="check['.$id.']" value="1">';
    $html .= '<details class="am-move"><summary>'.af_advancedmessenger_icon('move').'<span>'.af_advancedmessenger_e(af_advancedmessenger_t('move')).'</span>'.af_advancedmessenger_icon('chevron').'</summary><form action="private.php" method="post" class="am-message-form">'.$fields
        .'<label for="am-move-'.$id.'">'.af_advancedmessenger_e(af_advancedmessenger_t('folders')).'</label><select id="am-move-'.$id.'" name="fid">';
    foreach (af_advancedmessenger_folders() as $fid => $name) {
        if ((int)$fid === 3) continue;
        $html .= '<option value="'.(int)$fid.'">'.af_advancedmessenger_e($name).'</option>';
    }
    $html .= '</select><button name="moveto" value="1">'.af_advancedmessenger_icon('move').af_advancedmessenger_e(af_advancedmessenger_t('move')).'</button></form></details>';
    return $html.'<form action="private.php" method="post" class="am-dropdown-danger">'.$fields.'<button class="am-danger" name="delete" value="1" data-am-confirm="'.af_advancedmessenger_e(af_advancedmessenger_t('delete_confirm')).'">'
        .af_advancedmessenger_icon('trash').'<span>'.af_advancedmessenger_e(af_advancedmessenger_t('delete')).'</span></button></form></div></details></footer></article>';

}

/** Same PM parser options as MyBB private postbits, without postbit hooks/layout. */
function af_advancedmessenger_parse_message(array $row): string
{
    global $mybb, $parser;
    if (!is_object($parser)) {
        require_once MYBB_ROOT.'inc/class_parser.php';
        $parser = new postParser;
    }
    return $parser->parse_message((string)$row['message'], [
        'allow_html'=>af_advancedmessenger_trusted_system_message((string)$row['message']) ? 1 : 0, 'allow_mycode'=>(int)$mybb->settings['pmsallowmycode'],
        'allow_smilies'=>empty($row['smilieoff']) ? (int)$mybb->settings['pmsallowsmilies'] : 0,
        'allow_imgcode'=>!empty($mybb->user['showimages']) ? (int)$mybb->settings['pmsallowimgcode'] : 0,
        'allow_videocode'=>!empty($mybb->user['showvideos']) ? (int)$mybb->settings['pmsallowvideocode'] : 0,
        'filter_badwords'=>1, 'me_username'=>af_advancedmessenger_e((string)($row['author_username'] ?? (get_user((int)$row['fromid'])['username'] ?? af_advancedmessenger_t('unknown')))),
    ]);
}

function af_advancedmessenger_render_history(array $history): string
{
    global $mybb;
    $html = '';
    $lastDate = '';
    foreach ($history['rows'] as $row) {
        // my_date applies the current user's timezone, including DST.
        $date = my_date((string)$mybb->settings['dateformat'], (int)$row['dateline']);
        if ($date !== $lastDate) {
            $html .= '<div class="am-date" data-am-date="'.af_advancedmessenger_e($date).'"><span>'.af_advancedmessenger_e($date).'</span></div>';
            $lastDate = $date;
        }
        $html .= af_advancedmessenger_bubble($row);
    }
    return $html;
}

/** Only real actions; no inferred presence/read receipt or placeholder controls. */
function af_advancedmessenger_dialog_header(string $key, string $title, int $pmid, int $folder): string
{
    global $mybb;
    $peer = str_starts_with($key, 'user:') ? (int)substr($key, 5) : 0;
    $user = $peer ? get_user($peer) : false;
    $profile = $user ? get_profile_link($peer) : ''; // MyBB already escapes this attribute URL.
    $image = af_advancedmessenger_peer_background($peer);
    $html = '<div class="am-peer-background" style="--am-peer-image:'.af_advancedmessenger_e($image).'" aria-hidden="true"></div><div class="am-peer">';
    if ($user) $html .= '<a class="am-peer__avatar" href="'.$profile.'" aria-label="'.af_advancedmessenger_e($user['username']).'">'.af_advancedmessenger_avatar($user).'</a>';
    $html .= '<div class="am-peer__text"><h2 id="am-title">'.($user ? '<a href="'.$profile.'">'.$title.'</a>' : $title).'</h2>';
    // Use MyBB's activity window and invisibility permission, never infer presence from PMs.
    if ($user && (int)$user['lastactive'] > TIME_NOW - (int)$mybb->settings['wolcutoff'] * 60
        && (empty($user['invisible']) || !empty($mybb->usergroup['canviewwolinvis']) || $peer === (int)$mybb->user['uid'])) {
        $html .= '<span class="am-peer__online">'.af_advancedmessenger_e(af_advancedmessenger_t('online')).'</span>';
    }
    $html .= '</div></div><nav class="am-conversation-actions">';
    $html .= '<a data-am-panel="1" href="private.php?action=advanced_search&amp;am_peer='.$peer.'" aria-label="'.af_advancedmessenger_e(af_advancedmessenger_t('content_search')).'" title="'.af_advancedmessenger_e(af_advancedmessenger_t('content_search')).'">'.af_advancedmessenger_icon('search').'</a>';
    if ($user) $html .= '<a href="'.$profile.'" aria-label="'.af_advancedmessenger_e(af_advancedmessenger_t('profile')).'" title="'.af_advancedmessenger_e(af_advancedmessenger_t('profile')).'">'.af_advancedmessenger_icon('info').'</a>';
    $html .= '<details class="am-dialog-actions" data-am-menu="1"><summary aria-label="'.af_advancedmessenger_e(af_advancedmessenger_t('actions')).'">'.af_advancedmessenger_icon('actions').'</summary><div class="am-dropdown" popover="manual"><nav>';
    $html .= '<a id="am-latest" data-am-dialog="1" href="private.php?action=read&amp;pmid='.$pmid.'&amp;am_latest=1&amp;am_folder='.$folder.'">'.af_advancedmessenger_icon('latest').'<span>'.af_advancedmessenger_e(af_advancedmessenger_t('latest')).'</span></a>';
    if (!empty($mybb->usergroup['cansendpms'])) {
        $html .= af_advancedmessenger_link('private.php?action=send&do=reply&am_noquote=1&pmid='.$pmid, af_advancedmessenger_t('reply'), true, 'reply');
        $html .= af_advancedmessenger_link('private.php?action=send&do=forward&pmid='.$pmid, af_advancedmessenger_t('forward'), true, 'forward');
    }
    return $html.'</nav></div></details></nav>';
}

/** Font Awesome is delivered once per document by Advanced Font Awesome. */
function af_advancedmessenger_icon(string $name): string
{
    $icons = ['compose'=>'pen','search'=>'magnifying-glass','folder'=>'folder','inbox'=>'inbox','sent'=>'paper-plane',
        'draft'=>'file-pen','trash'=>'trash-can','tools'=>'toolbox','tracking'=>'eye','export'=>'file-export','info'=>'circle-info',
        'latest'=>'clock-rotate-left','actions'=>'ellipsis','reply'=>'reply','replyall'=>'reply-all','quote'=>'quote-right','forward'=>'share','open'=>'arrow-up-right-from-square',
        'move'=>'folder-open','chevron'=>'chevron-down','previous'=>'chevron-left','next'=>'chevron-right','close'=>'xmark','settings'=>'sliders','save'=>'floppy-disk','preview'=>'eye'];
    return '<i class="fa-solid fa-'.($icons[$name] ?? 'circle-info').'" aria-hidden="true"></i>';
}

/** Reuse the same per-UID payload that ATF's profile context consumes. */
function af_advancedmessenger_peer_background(int $uid): string
{
    if ($uid > 0 && function_exists('af_aa_build_user_css_payload')) {
        $payload = af_aa_build_user_css_payload($uid);
        return (string)($payload['vars']['--af-apui-profile-banner-image'] ?? 'none');
    }
    if (function_exists('af_apui_get_setting_value') && function_exists('af_apui_css_url_value')) {
        return af_apui_css_url_value(af_apui_get_setting_value('profile_banner_url', ''));
    }
    return 'none';
}

/**
 * MyBB send_pm() does not persist a trusted-HTML flag (buddy PMs even use the
 * requesting user's fromid). Never trust fromid=0, subject or HTML-looking text.
 * Only exact, parameter-free server language messages may enable HTML parsing.
 * A user copying that exact text gains no ability to supply different HTML.
 */
function af_advancedmessenger_trusted_system_message(string $message): bool
{
    global $lang;
    static $messages = null;
    if ($messages === null) {
        $messages = [];
        if (is_object($lang)) {
            $system = clone $lang;
            $system->load('usercp', false, true);
            foreach (['buddyrequest_received_message','buddyrequest_new_buddy_message','buddyrequest_accepted_request_message'] as $key) {
                $value = (string)($system->$key ?? '');
                if ($value !== '') $messages[] = trim(str_replace("\r\n", "\n", $value));
            }
        }
    }
    return in_array(trim(str_replace("\r\n", "\n", $message)), $messages, true);
}

/** No cross-mailbox inference: a saved copy has no native link to the tracking row. */
function af_advancedmessenger_receipt_indicator(array $row): string
{
    global $mybb;
    $owner = (int)$mybb->user['uid'];
    if ((int)$row['fromid'] !== $owner || (int)$row['folder'] === 3) return '';
    // A native receipt is proof only on its actual recipient row, not when
    // someone opens a sent copy. For an owned outgoing row this can be a self-PM.
    $confirmed = af_advancedmessenger_identity($row, $owner) === 'user:'.$owner
        && (int)($row['uid'] ?? 0) === (int)$row['toid'] && (int)$row['toid'] > 0
        && (int)($row['receipt'] ?? 0) === 2 && (int)($row['status'] ?? 0) !== 0 && (int)($row['readtime'] ?? 0) > 0;
    $saved = (int)$row['folder'] === 2 && (int)($row['status'] ?? 0) !== 0;
    if (!$confirmed && !$saved) return '';
    $label = af_advancedmessenger_e(af_advancedmessenger_t($confirmed ? 'receipt_confirmed' : 'sent_copy'));
    return '<span class="am-receipt" title="'.$label.'" role="img" aria-label="'.$label.'"><i class="fa-solid fa-'.($confirmed ? 'check-double' : 'check').'" aria-hidden="true"></i></span>';
}

/** Same folder[] values as core's advanced-search select; 0 means all folders. */
function af_advancedmessenger_search_folders(): string
{
    global $lang;
    $html = '<div class="am-folder-choices"><label><input type="checkbox" name="folder[]" value="0" checked data-am-all-folders="1"><span>'.af_advancedmessenger_e($lang->all_folders).'</span></label>';
    foreach (af_advancedmessenger_folders() as $id => $name) {
        $html .= '<label><input type="checkbox" name="folder[]" value="'.(int)$id.'"><span>'.af_advancedmessenger_e($name).'</span></label>';
    }
    return $html.'</div>';
}
