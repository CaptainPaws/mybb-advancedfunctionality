<?php
/**
 * Advanced functionality → Addon: Advanced Rules
 * Маршрут: /misc.php?action=advancedrules
 * Рендер: ТОЧНО как в референсном плагине:
 *   - templategroup 'rules'
 *   - templates: rules_index, rules_category, rules_paragraph (sid = -2)
 *   - полный HTML в rules_index с {$headerinclude}, {$header}, {$footer}
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

/* ========================= УСТАНОВКА (templategroup + templates как в эталоне) ========================= */

function af_advancedrules_install()
{
    global $db, $lang;

    // Таблицы данных (наши)
    if(!$db->table_exists('af_rules_categories')) {
        $db->write_query("
            CREATE TABLE ".TABLE_PREFIX."af_rules_categories (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              title VARCHAR(255) NOT NULL,
              disporder INT UNSIGNED NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
    // Колонка описания для категорий (upgrade-safe)
    if ($db->table_exists('af_rules_categories') && !$db->field_exists('description', 'af_rules_categories')) {
        $db->write_query("
            ALTER TABLE ".TABLE_PREFIX."af_rules_categories
            ADD COLUMN description TEXT NULL AFTER title
        ");
    }

    if(!$db->table_exists('af_rules_items')) {
        $db->write_query("
            CREATE TABLE ".TABLE_PREFIX."af_rules_items (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              cid INT UNSIGNED NOT NULL,
              title VARCHAR(255) NOT NULL,
              body TEXT NOT NULL,
              disporder INT UNSIGNED NOT NULL DEFAULT 1,
              CONSTRAINT fk_af_rules_items_cid
                FOREIGN KEY (cid) REFERENCES ".TABLE_PREFIX."af_rules_categories(id)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    // Настройки (включение/текст ссылки/селектор)
    $gid = af_fast_ensure_group(
        'af_advancedrules',
        $lang->af_advancedrules_group ?? 'AF: Правила',
        $lang->af_advancedrules_group_desc ?? 'Настройки внутреннего аддона «Правила».'
    );

    af_fast_ensure_setting($gid, 'af_advancedrules_enabled',
        $lang->af_advancedrules_enabled ?? 'Включить страницу правил',
        $lang->af_advancedrules_enabled_desc ?? 'Да/Нет',
        'yesno', '1', 1
    );
    af_fast_ensure_setting($gid, 'af_advancedrules_nav_text',
        $lang->af_advancedrules_nav_text ?? 'Название ссылки в меню',
        $lang->af_advancedrules_nav_text_desc ?? 'По умолчанию «Правила».',
        'text', 'Правила', 2
    );
    af_fast_ensure_setting($gid, 'af_advancedrules_nav_where',
        $lang->af_advancedrules_nav_where ?? 'CSS-селектор контейнера меню',
        $lang->af_advancedrules_nav_where_desc ?? 'По умолчанию ul.menu.top_links',
        'text', 'ul.menu.top_links', 3
    );

    // === templategroup 'rules' (как в эталоне) ===
    $existing_group = $db->simple_select('templategroups', 'gid', "prefix='rules'", ['limit' => 1]);
    if(!(int)$db->fetch_field($existing_group, 'gid')) {
        $db->insert_query('templategroups', [
            'prefix' => 'rules',
            'title'  => 'Rules Templates'
        ]);
    }

    // === templates (sid = -2) — точные копии из эталона ===
    $templates = [
        'rules_index' => '<html><head><title>{$lang->rules_title}</title>{$headerinclude}</head><body>{$header}<div class="rules"><h1>{$lang->rules_title}</h1><div class="rules-categories">{$rules_categories}</div></div><script type="text/javascript">document.addEventListener("DOMContentLoaded",function(){var headers=document.querySelectorAll(".rules-cat-header");for(var i=0;i<headers.length;i++){headers[i].addEventListener("click",function(){var id=this.getAttribute("data-id");var el=document.getElementById("rules-paragraphs-"+id);var cat=document.getElementById("rules-cat-"+id);if(el){var open=el.style.display==="none";el.style.display=open?"":"none";if(cat){if(open){cat.classList.add("open");}else{cat.classList.remove("open");}}}});}});</script>{$footer}</body></html>',
        'rules_category' => '<div class="rules-category" id="rules-cat-{$category[\'id\']}"><div class="rules-cat-header" data-id="{$category[\'id\']}"><div class="rules-cat-icon">✼</div><div class="rules-cat-title">{$category[\'name\']}</div><div class="rules-cat-arrow">&#9660;</div></div><p class="rules-cat-desc rules-muted">{$category[\'description\']}</p><div class="rules-paragraphs" id="rules-paragraphs-{$category[\'id\']}" style="display:none;">{$rules_paragraphs}</div></div>',
        'rules_paragraph' => '<div class="rules-paragraph"><h3>{$paragraph[\'display_title\']}</h3><div class="rules-body">{$paragraph[\'body\']}</div></div>',
    ];

    foreach($templates as $title => $template) {
        $title_esc    = $db->escape_string($title);
        $template_esc = $db->escape_string($template);
        $check = $db->simple_select('templates', 'tid', "title='".$title_esc."' AND sid='-2'", ['limit' => 1]);
        $tid   = (int)$db->fetch_field($check, 'tid');
        $row   = ['template' => $template_esc, 'version' => 1, 'dateline' => TIME_NOW];
        if($tid) {
            $db->update_query('templates', $row, 'tid='.$tid);
        } else {
            $db->insert_query('templates', [
                'title'    => $title_esc,
                'template' => $template_esc,
                'sid'      => -2,
                'version'  => 1,
                'dateline' => TIME_NOW
            ]);
        }
    }
}

function af_advancedrules_deactivate() {}

/* ========================= ИНИЦИАЛИЗАЦИЯ ХУКОВ ========================= */

function af_advancedrules_init()
{
    global $plugins;
    // Роут на /misc.php?action=advancedrules — ПОЛНОСТЬЮ как в эталоне
    $plugins->add_hook('misc_start', 'af_advancedrules_misc_route', 10);
    // Ссылка «Правила» в меню
    $plugins->add_hook('pre_output_page', 'af_advancedrules_pre_output', 10);
}

/* ========================= РОУТЕР + РЕНДЕР (аналог rules_misc_route) ========================= */

function af_advancedrules_misc_route()
{
    global $mybb, $db, $templates, $theme, $header, $headerinclude, $footer, $lang, $page;

    if ($mybb->get_input('action') !== 'advancedrules') {
        return;
    }

    if (empty($mybb->settings['af_advancedrules_enabled'])) {
        error_no_permission();
    }

    // НЕ ГРУЗИМ rules.lang.php (его нет) — просто задаём заголовок вручную.
    // Шаблон rules_index ожидает {$lang->rules_title}, заполним его здесь.
    if (!isset($lang->rules_title) || $lang->rules_title === '') {
        $lang->rules_title = htmlspecialchars_uni($mybb->settings['af_advancedrules_nav_text'] ?? 'Правила');
    }

    // Хлебные крошки
    add_breadcrumb($lang->rules_title, 'misc.php?action=advancedrules');

    // === ДАННЫЕ: категории (map под ожидаемые поля шаблона) ===
    $categories = [];
    $cquery = $db->simple_select('af_rules_categories', '*', '', [
        'order_by' => 'disporder, id',
        'order_dir' => 'ASC'
    ]);

    while ($row = $db->fetch_array($cquery)) {
        $desc_raw = (string)($row['description'] ?? '');
        // Можно без BBCode, но дадим базовую поддержку разметки:
        if (!class_exists('postParser')) {
            require_once MYBB_ROOT.'inc/class_parser.php';
        }
        $parser = new postParser;
        $parser_opts_cat = [
            'allow_html'        => 1,
            'allow_mycode'      => 1,
            'allow_basicmycode' => 1,
            'allow_smilies'     => 0,
            'allow_imgcode'     => 0,
            'allow_videocode'   => 0,
            'filter_badwords'   => 1,
            'nl2br'             => 1,
        ];

        $categories[] = [
            'id'          => (int)$row['id'],
            'name'        => $row['title'],
            'description' => $parser->parse_message($desc_raw, $parser_opts_cat),
        ];
    }


    // Парсер BBCode (как в эталоне)
    if (!class_exists('postParser')) {
        require_once MYBB_ROOT.'inc/class_parser.php';
    }
    $parser = new postParser;
    $parser_options = [
        'allow_html'         => 1,
        'allow_mycode'       => 1,
        'allow_basicmycode'  => 1,
        'allow_smilies'      => 1,
        'allow_imgcode'      => 1,
        'allow_videocode'    => 1,
        'allow_list'         => 1,
        'allow_alignmycode'  => 1,
        'allow_font'         => 1,
        'allow_color'        => 1,
        'allow_size'         => 1,
        'filter_badwords'    => 1,
        'nl2br'              => 1,
    ];

    // === Сборка блоков под шаблоны rules_* ===
    $rules_categories = '';
    $cat_index = 0;

    foreach ($categories as $cat) {
        $cat_index++;

        // Пункты правил
        $paragraphs = [];
        $pquery = $db->simple_select(
            'af_rules_items',
            '*',
            'cid='.(int)$cat['id'],
            ['order_by' => 'disporder, id', 'order_dir' => 'ASC']
        );
        while ($prow = $db->fetch_array($pquery)) {
            $paragraphs[] = $prow;
        }

        $rules_paragraphs = '';
        $para_index = 0;

        foreach ($paragraphs as $paragraph) {
            $para_index++;
            $safe_title = htmlspecialchars_uni($paragraph['title']);
            $paragraph = [
                'display_title' => "⌗{$cat_index}.{$para_index} - {$safe_title}",
                'body'          => $parser->parse_message($paragraph['body'], $parser_options),
            ];
            eval("\$rules_paragraphs .= \"".$templates->get('rules_paragraph')."\";");
        }

        $category = $cat; // доступно в шаблоне rules_category
        eval("\$rules_categories .= \"".$templates->get('rules_category')."\";");
    }

    // Полная страница из rules_index (внутри уже {$headerinclude}, {$header}, {$footer})
    eval("\$page = \"".$templates->get('rules_index')."\";");
    output_page($page);
    exit;
}


/* ========================= ИНЖЕКТ ССЫЛКИ В МЕНЮ (по ссылке) ========================= */

function af_advancedrules_pre_output(&$page = '')
{
    global $mybb;

    // Нормализация входа
    if ($page === null) { $page = ''; }
    if (!is_string($page)) { $page = (string)$page; }
    if ($page === '') { return $page; }

    if (empty($mybb->settings['af_advancedrules_enabled'])) {
        return $page;
    }

    // Простой режим — стандартный UL top_links
    $selector = trim((string)($mybb->settings['af_advancedrules_nav_where'] ?? 'ul.menu.top_links'));

    if ($selector === '' || stripos($selector, 'ul.menu.top_links') !== false) {
        // уже вставляли — выходим
        if (strpos($page, '<!--af_advancedrules_nav-->') !== false) {
            return $page;
        }
        // ссылка уже есть — просто пометим, чтобы не дублить
        if (stripos($page, 'misc.php?action=advancedrules') !== false) {
            $page = str_replace('misc.php?action=advancedrules', 'misc.php?action=advancedrules<!--af_advancedrules_nav-->', $page);
            return $page;
        }

        $link_text = htmlspecialchars_uni($mybb->settings['af_advancedrules_nav_text'] ?? 'Правила');
        $li = '<li class="advancedrules-link"><a href="misc.php?action=advancedrules">'.$link_text.'</a></li><!--af_advancedrules_nav-->';

        $patched = preg_replace(
            '~(<ul[^>]*class="[^"]*\bmenu\b[^"]*\btop_links\b[^"]*"[^>]*>)(.*?)(</ul>)~is',
            '$1$2'.$li.'$3',
            $page,
            1
        );
        if ($patched !== null) {
            $page = $patched;
        }
    }

    return $page; // КРИТИЧЕСКОЕ: всегда возвращаем строку
}


/* ========================= УТИЛИТЫ (ACP settings) ========================= */

function af_fast_ensure_group(string $name, string $title, string $desc): int
{
    global $db;
    $q = $db->simple_select('settinggroups','gid',"name='".$db->escape_string($name)."'", ['limit'=>1]);
    $gid = (int)$db->fetch_field($q, 'gid');
    if ($gid) return $gid;

    $max = $db->fetch_field($db->simple_select('settinggroups','MAX(disporder) AS m'), 'm');
    $disp = (int)$max + 1;

    $db->insert_query('settinggroups', [
        'name'        => $db->escape_string($name),
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'disporder'   => $disp,
        'isdefault'   => 0
    ]);
    return (int)$db->insert_id();
}

function af_fast_ensure_setting(int $gid, string $name, string $title, string $desc, string $type, string $value, int $order): void
{
    global $db;
    $q = $db->simple_select('settings','sid',"name='".$db->escape_string($name)."'");
    $sid = (int)$db->fetch_field($q, 'sid');

    $row = [
        'name'        => $db->escape_string($name),
        'title'       => $db->escape_string($title),
        'description' => $db->escape_string($desc),
        'optionscode' => $db->escape_string($type),
        'value'       => $db->escape_string($value),
        'disporder'   => $order,
        'gid'         => $gid
    ];

    if ($sid) {
        $db->update_query('settings', $row, "sid=".$sid);
    } else {
        $db->insert_query('settings', $row);
    }
}
