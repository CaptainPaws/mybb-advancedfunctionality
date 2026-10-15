<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_advancedmessenger_e(string $text): string { return htmlspecialchars_uni($text); }

function af_advancedmessenger_t(string $key): string
{
    global $mybb;
    $ru = [
        'title'=>'Сообщения', 'compose'=>'Написать', 'dialogs'=>'Диалоги', 'all'=>'Все диалоги',
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
        'title'=>'Messages', 'compose'=>'New message', 'dialogs'=>'Conversations', 'all'=>'All conversations',
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

function af_advancedmessenger_link(string $url, string $label, bool $panel = false): string
{
    return '<a href="'.af_advancedmessenger_e($url).'"'.($panel ? ' data-am-panel="1"' : '').'>'.af_advancedmessenger_e($label).'</a>';
}

function af_advancedmessenger_avatar(array $user): string
{
    if (function_exists('af_avatar_render')) return af_avatar_render($user, 'post', ['decorative'=>true,'allow_letter'=>false]);
    $src = (string)($user['avatar'] ?? '');
    if ($src !== '' && preg_match('~^(?:https?://|/|(?:\./)?(?:uploads|images)/)~i', $src)) {
        return '<img class="am-avatar" src="'.af_advancedmessenger_e($src).'" alt="" loading="lazy">';
    }
    return '<span class="am-avatar" aria-hidden="true">'.af_advancedmessenger_e(my_substr($user['username'],0,1)).'</span>';
}

function af_advancedmessenger_sidebar(array $context): string
{
    global $mybb, $lang, $parser;
    $folder = $context['folder'];
    $html = '<header class="am-sidebar__head"><h1>'.af_advancedmessenger_e(af_advancedmessenger_t('title')).' <span data-am-unread-count="1">'
        .af_advancedmessenger_unread().'</span></h1>';
    if (!empty($mybb->usergroup['cansendpms'])) $html .= af_advancedmessenger_link('private.php?action=send', af_advancedmessenger_t('compose'), true);
    $html .= '</header><form method="get" action="private.php" class="am-name-search">'
        .'<input type="hidden" name="am_folder" value="'.$folder.'">'
        .'<label for="am-search">'.af_advancedmessenger_e(af_advancedmessenger_t('search')).'</label><input id="am-search" name="am_name" type="search" maxlength="120" value="'
        .af_advancedmessenger_e($context['name']).'"><button type="submit">'.af_advancedmessenger_e($lang->search_button).'</button></form>';
    $html .= '<details class="am-folders"><summary>'.af_advancedmessenger_e(af_advancedmessenger_t('folders')).'</summary><nav>'
        .af_advancedmessenger_link('private.php', af_advancedmessenger_t('all'));
    foreach (af_advancedmessenger_folders() as $id => $name) {
        $html .= '<a href="private.php?fid='.((int)$id === 1 ? 0 : (int)$id).'&amp;am_folder='.(int)$id.'"'
            .($folder === (int)$id ? ' aria-current="page"' : '').'>'.af_advancedmessenger_e($name).'</a>';
    }
    $html .= af_advancedmessenger_link('private.php?action=folders', af_advancedmessenger_t('manage'), true).'</nav></details>';
    $html .= '<details class="am-tools"><summary>'.af_advancedmessenger_e(af_advancedmessenger_t('tools')).'</summary><nav>';
    foreach (['advanced_search'=>'advanced_search', 'tracking'=>'tracking', 'export'=>'export', 'empty'=>'empty'] as $action => $label) {
        if ($action === 'tracking' && empty($mybb->usergroup['cantrackpms'])) continue;
        $html .= af_advancedmessenger_link('private.php?action='.$action, af_advancedmessenger_t($label), true);
    }
    $html .= '</nav></details><form action="private.php" method="post" class="am-content-search">'
        .'<input type="hidden" name="my_post_key" value="'.af_advancedmessenger_e($mybb->post_code).'">'
        .'<input type="hidden" name="action" value="do_stuff"><input type="hidden" name="fid" value="'.$folder.'">'
        .'<label for="am-keywords">'.af_advancedmessenger_e(af_advancedmessenger_t('content_search')).'</label><input id="am-keywords" name="keywords" type="search" required>'
        .'<button name="quick_search" value="1">'.af_advancedmessenger_e($lang->search_pms).'</button></form>';
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
            .my_date('relative', (int)$row['dateline']).($dialog['unread'] ? '<b class="am-unread">'.$dialog['unread'].'</b>' : '')
            .'</span></a>';
    }
    if (!$context['dialogs']['items']) $html .= '<p class="am-empty">'.af_advancedmessenger_e(af_advancedmessenger_t('none')).'</p>';
    $html .= '</nav><nav class="am-pages" aria-label="'.af_advancedmessenger_e(af_advancedmessenger_t('dialogs')).'">';
    $data = $context['dialogs'];
    foreach ([-1=>'←', 1=>'→'] as $delta => $label) {
        $page = $data['page'] + $delta;
        if ($page < 1 || $page > $data['pages']) continue;
        $html .= af_advancedmessenger_link('private.php?am_folder='.$folder.'&am_page='.$page.'&am_name='.rawurlencode($context['name']), $label);
    }
    return $html.'</nav>';
}

function af_advancedmessenger_bubble(array $row, string $postbit): string
{
    global $mybb;
    $outgoing = (int)$row['fromid'] === (int)$mybb->user['uid'];
    $id = (int)$row['pmid'];
    // Never infer recipient read state from a saved copy: MyBB doesn't store it there.
    $unread = (int)$row['status'] === 0 && !in_array((int)$row['folder'],[3,4],true);
    $state = $unread ? 'unread' : ($outgoing ? 'sent' : 'read');
    $name = $row['author_username'] ?? null;
    if ($name === null) { $author = get_user((int)$row['fromid']); $name = $author['username'] ?? af_advancedmessenger_t('unknown'); }
    // Stock PM postbits have empty/zero forum pid attributes. Scope those DOM
    // IDs after rendering, never assign a forum pid or invoke forum actions.
    $postbit = preg_replace_callback('~\b(id|name)="((?:post_|pid_|pid|post_meta_|edited_by_)(?:0)?)"~',
        static fn(array $m): string => $m[1].'="'.$m[2].'pm'.$id.'"', $postbit);
    $html = '<article class="am-bubble'.($outgoing ? ' am-bubble--out' : '').'" data-am-pmid="'.$id
        .'" data-am-unread="'.($unread ? '1' : '0').'">'
        .'<header class="am-bubble__head"><span>'.af_advancedmessenger_e((string)$name).'</span><a href="private.php?action=read&amp;pmid='.$id.'">'
        .my_date('relative',(int)$row['dateline']).'</a></header>'.$postbit
        .'<footer class="am-bubble__foot"><span data-am-state="1">'.af_advancedmessenger_e(af_advancedmessenger_t($state)).'</span>'
        .'<details class="am-message-actions"><summary>'.af_advancedmessenger_e(af_advancedmessenger_t('actions')).'</summary><nav>';
    if (!empty($mybb->usergroup['cansendpms'])) {
        foreach (['reply'=>'reply', 'quote'=>'reply', 'forward'=>'forward'] as $label => $do) {
            $html .= '<a data-am-panel="1" href="private.php?action=send&amp;do='.$do.'&amp;pmid='.$id
                .($label === 'reply' ? '&amp;am_noquote=1' : '').'">'.af_advancedmessenger_e(af_advancedmessenger_t($label)).'</a>';
        }
        $recipients = is_array($row['recipients']) ? $row['recipients'] : my_unserialize($row['recipients']);
        if (is_array($recipients['to'] ?? null) && count($recipients['to']) > 1) {
            $html .= af_advancedmessenger_link('private.php?action=send&do=replyall&pmid='.$id,af_advancedmessenger_t('replyall'),true);
        }
    }
    $html .= af_advancedmessenger_link('private.php?action=read&pmid='.$id, af_advancedmessenger_t('open'));
    $html .= '</nav><form action="private.php" method="post" class="am-message-form">'
        .'<input type="hidden" name="my_post_key" value="'.af_advancedmessenger_e($mybb->post_code).'">'
        .'<input type="hidden" name="action" value="do_stuff"><input type="hidden" name="check['.$id.']" value="1">'
        .'<label>'.af_advancedmessenger_e(af_advancedmessenger_t('move')).'<select name="fid">';
    foreach (af_advancedmessenger_folders() as $fid => $name) {
        // Core supports moving to its folder list; drafts are editing objects,
        // not a supported target for an already sent message in this view.
        if ((int)$fid === 3) continue;
        $html .= '<option value="'.(int)$fid.'">'.af_advancedmessenger_e($name).'</option>';
    }
    return $html.'</select></label><button name="moveto" value="1">'.af_advancedmessenger_e(af_advancedmessenger_t('move')).'</button>'
        .'<button name="delete" value="1" data-am-confirm="'.af_advancedmessenger_e(af_advancedmessenger_t('delete_confirm')).'">'
        .af_advancedmessenger_e(af_advancedmessenger_t('delete')).'</button></form></details></footer></article>';
}

function af_advancedmessenger_render_history(array $history, int $nativeId = 0, string $nativePostbit = ''): string
{
    global $groupscache, $lang;
    $saved = [];
    foreach (['pmid', 'replyall', 'message'] as $key) $saved[$key] = $GLOBALS[$key] ?? null;
    $html = '';
    try {
        foreach ($history['rows'] as $row) {
            if ((int)$row['pmid'] === $nativeId && $nativePostbit !== '') {
                $html .= af_advancedmessenger_bubble($row,$nativePostbit);
                continue;
            }
            $GLOBALS['pmid'] = (int)$row['pmid'];
            $GLOBALS['replyall'] = false; // Group entries are opened through core's canonical read view.
            $raw = $row;
            $row['uid'] = (int)$row['fromid'];
            $row['username'] = $row['author_username'] ?: ($lang->na ?? af_advancedmessenger_t('unknown'));
            $row['userusername'] = $row['username'];
            $row['subject'] = af_advancedmessenger_e($row['subject']);
            $row['subject_extra'] = '';
            foreach (['title'=>'grouptitle', 'usertitle'=>'groupusertitle', 'stars'=>'groupstars',
                      'starimage'=>'groupstarimage', 'image'=>'groupimage', 'namestyle'=>'namestyle'] as $source=>$target) {
                $row[$target] = $groupscache[$row['usergroup'] ?? 0][$source] ?? '';
            }
            $html .= af_advancedmessenger_bubble($raw, build_postbit($row, 2));
        }
    } finally {
        foreach ($saved as $key=>$value) $GLOBALS[$key] = $value;
    }
    return $html;
}
