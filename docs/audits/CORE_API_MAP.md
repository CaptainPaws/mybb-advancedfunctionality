# Карта API ядра AdvancedFunctionality

Область: `inc/plugins/advancedfunctionality.php` и `inc/plugins/advancedfunctionality/` без `addons/`. Документ описывает фактические контракты кода, а не отдельный стабильный публичный интерфейс с версионированием.

## 1. Минимальный аддон

Структура:

```text
inc/plugins/advancedfunctionality/addons/example/
├── manifest.php
├── example.php
├── admin.php                  # необязательно
├── templates/example.html     # необязательно
└── assets/example.css         # необязательно
```

`manifest.php` должен вернуть массив как минимум с `id` и `name`; для загрузки исполняемого файла нужен `bootstrap`. Discovery превращает относительный `bootstrap` в абсолютный путь и добавляет `path` (`inc/plugins/advancedfunctionality.php:3001-3021`):

```php
<?php
return [
    'id' => 'example',
    'name' => 'Example',
    'description' => 'Example addon',
    'version' => '1.0.0',
    'bootstrap' => 'example.php',
    'lang' => [
        'english' => ['front' => ['af_example_hello' => 'Hello'], 'admin' => []],
        'russian' => ['front' => ['af_example_hello' => 'Привет'], 'admin' => []],
    ],
    'assets' => [
        'front' => ['css' => ['assets/example.css'], 'js' => []],
    ],
];
```

`example.php`:

```php
<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_example_install(): void
{
    // Создание собственных таблиц и настроек аддона.
}

function af_example_init(): void
{
    global $plugins;
    // $plugins->add_hook('needed_mybb_hook', 'af_example_handler');
}
```

Включение в ACP загружает bootstrap, у обычного аддона вызывает только `af_example_install()` (если есть), создаёт/обновляет `af_example_enabled=1`, пересобирает настройки и синхронизирует CSS (`inc/plugins/advancedfunctionality.php:1189-1243`). На следующем frontend-запросе enabled-аддон получает язык, повторно подключается через `require_once` и вызывает `af_example_init()` (`inc/plugins/advancedfunctionality.php:2370-2380`). Отключение ставит флаг в `0`, отсоединяет CSS и вызывает необязательный `af_example_deactivate()`; удаления таблиц или вызова `uninstall` при этом нет (`inc/plugins/advancedfunctionality.php:1246-1262`).

## 2. Загрузка ядра и обработка запроса

- MyBB загружает `inc/plugins/advancedfunctionality.php` как обычный плагин. Файл требует `IN_MYBB`, объявляет константы путей и сразу регистрирует хуки через глобальный `$plugins` (`inc/plugins/advancedfunctionality.php:11-29`, `inc/plugins/advancedfunctionality.php:2292-2320`).
- На `global_start` сначала загружается язык ядра (priority `0`), затем enabled-аддоны (priority `1`) (`inc/plugins/advancedfunctionality.php:2295-2301`). Bootstrap однократен на запрос: флаг `$GLOBALS['af_addons_bootstrapped']` (`inc/plugins/advancedfunctionality.php:2322-2328`).
- Для enabled-аддона порядок: синхронизация языковых файлов, загрузка языка, `require_once bootstrap`, `af_{id}_init()` (`inc/plugins/advancedfunctionality.php:2370-2380`).
- Перед отдачей HTML `pre_output_page` запускает общий pipeline: отложенная синхронизация шаблонов и CSS, `af_{id}_pre_output($page)` всех enabled-аддонов, инъекция assets, theme stylesheets и дедупликация (`inc/plugins/advancedfunctionality.php:2625-2701`). Для `usercp.php` и `misc.php` дополнительно работает output buffer на случай вывода без `output_page()` (`inc/plugins/advancedfunctionality.php:2483-2508`, `inc/plugins/advancedfunctionality.php:2580-2619`).
- AJAX/JSON не проходит HTML-pipeline: пропуск задаётся флагом `$GLOBALS['af_disable_pre_output']`, JSON `Content-Type`, `ajax=1`, XHR/`Accept` или константой `AF_NO_PRE_OUTPUT` (`inc/plugins/advancedfunctionality.php:2960-2998`).
- XMLHTTP имеет отдельную инициализацию: enabled bootstrap + `af_{id}_xmlhttp_init()`, а не обычный `af_{id}_init()` (`inc/plugins/advancedfunctionality.php:2510-2577`).
- ACP-страница идёт по пути `admin/modules/advancedfunctionality/index.php` → `admin/router.php` → основной файл → `AF_Admin::dispatch()` (`inc/plugins/advancedfunctionality/admin/router.php:1-8`). Установка создаёт ACP proxy и `module_meta.php`, добавляет системный пункт меню и динамические ссылки enabled-аддонов (`inc/plugins/advancedfunctionality.php:1507-1638`).
- Gateway `advancedfunctionality_gateway.php` копируется в корень форума из `gateway_stub.php`; чужой runtime без AF-маркера не перезаписывается (`inc/plugins/advancedfunctionality.php:1742-1790`).

### Хуки MyBB, регистрируемые ядром

| Хук | Обработчик | Priority | Назначение |
|---|---|---:|---|
| `global_start` | `advancedfunctionality_outputbuffer_start` | -5 | Буфер для `usercp.php`/`misc.php` |
| `global_start` | `advancedfunctionality_load_lang` | 0 | Язык ядра |
| `global_start` | `advancedfunctionality_bootstrap_addons` | 1 | Bootstrap enabled-аддонов |
| `global_start` | `advancedfunctionality_profile_lazy_endpoint_bridge` | 2 | Отложенный profile endpoint |
| `usercp_start` | `advancedfunctionality_avatar_state_bridge` | 0 | Read-only avatar JSON |
| `pre_output_page` | `advancedfunctionality_bootstrap_addons_preoutput` | 1 | HTML filters/assets |
| `xmlhttp` | `af_xmlhttp_router` | -1 | `af_addon_api` и AAM route |
| `xmlhttp` | `af_core_xmlhttp_bootstrap_addons` | 1 | XMLHTTP bootstrap |
| `postbit`, `postbit_prev`, `postbit_pm` | `advancedfunctionality_apf_postbit_provider` | 20 | APF secondary avatar bridge |
| `admin_style_themes_edit_stylesheet_simple_commit`, `..._advanced_commit` | `af_theme_stylesheet_acp_resign` | 100 | Переподпись structured CSS |

Источник регистрации: `inc/plugins/advancedfunctionality.php:2292-2320`.

Дополнительно gateway регистрирует `xmlhttp` → `af_gateway_xmlhttp_router` с priority `-1`; он перебирает enabled-аддоны и вызывает `af_{id}_xmlhttp()` для `action`, начинающегося с `af_`, а также для перечисленных MyAlerts-совместимых действий (`inc/plugins/advancedfunctionality/gateway_stub.php:21-104`).

## 3. Контракт `manifest.php`

| Ключ | Контракт |
|---|---|
| `id`, `name` | Обязательны для discovery; `id` участвует в именах настроек и функций (`inc/plugins/advancedfunctionality.php:3010-3017`). |
| `description`, `version`, `author`, `authorsite` | Отображаются в обзоре ACP (`inc/plugins/advancedfunctionality.php:471-500`). |
| `bootstrap` | Относительный путь к главному PHP-файлу; преобразуется discovery в абсолютный (`inc/plugins/advancedfunctionality.php:3014-3016`). |
| `lang` | `russian`/`english`, внутри `front`/`admin` — пары языковых ключей (`inc/plugins/advancedfunctionality.php:2198-2239`). |
| `assets` | `front`/`admin`/`both`, внутри списки `css`/`js` от корня папки аддона (`inc/plugins/advancedfunctionality.php:7915-7951`). |
| `frontend` | Маршрутный фильтр assets: `legacy`, `global` или `contextual` (`inc/plugins/advancedfunctionality.php:3031-3186`). |
| `theme_stylesheets` | Явные CSS-источники, их `attach`, режим и условия (`inc/plugins/advancedfunctionality.php:3563-3594`). |
| `admin` | `slug`, `title`, `controller`, необязательное `menu` (`inc/plugins/advancedfunctionality.php:1146-1178`, `inc/plugins/advancedfunctionality.php:1558-1587`). |
| `legacy_ids`, `legacy_lifecycle_settings` | Старые настройки, при наличии которых до enabled-check вызывается `af_{id}_migrate_identity()` (`inc/plugins/advancedfunctionality.php:2344-2368`). |

Общего frontend-реестра навигации в ядре **не найдено**. ACP-навигация строится из `manifest['admin']`; frontend-навигацию аддон создаёт собственными MyBB-хуками/шаблонами.

### `frontend` и ограничение assets

- Без `frontend` действует `legacy`: directory fallback разрешён (`inc/plugins/advancedfunctionality.php:3041-3053`).
- `global`: assets разрешены глобально; `directory_fallback` по умолчанию `true` (`inc/plugins/advancedfunctionality.php:3074-3088`).
- `contextual`: `routes` — список условий `script`, `action`, `fid`, `tid`; все заданные условия маршрута должны совпасть. `response_rules` позволяют разрешить assets по отдельно переданным фактам ответа. `directory_fallback` по умолчанию `true` (`inc/plugins/advancedfunctionality.php:3091-3186`, `inc/plugins/advancedfunctionality.php:3189-3203`).
- `frontend.resources[resource]` может сузить контракт конкретного ресурса (`inc/plugins/advancedfunctionality.php:3210-3231`).
- Решение без вывода HTML: `af_frontend_asset_decision($addonOrId, $resource = null, ?array $context = null, array $responseFacts = [])`; булев вариант — `af_frontend_asset_allowed(...)` (`inc/plugins/advancedfunctionality.php:3210-3281`).
- Дополнительные blacklist-настройки: `af_{id}_assets_blacklist` и `af_{id}_disable_on`; строки имеют вид `script.php` или `script.php?action=name` (`inc/plugins/advancedfunctionality.php:6784-6912`).

## 4. Функции жизненного цикла аддона

| Функция в bootstrap | Когда вызывается |
|---|---|
| `af_{id}_init()` | На каждом обычном bootstrap enabled-аддона (`inc/plugins/advancedfunctionality.php:2370-2380`). |
| `af_{id}_install()` | При включении обычного аддона; возвращаемое значение не проверяется (`inc/plugins/advancedfunctionality.php:1234-1237`). |
| `af_{id}_deactivate()` | При отключении, после установки enabled=0 и отключения CSS (`inc/plugins/advancedfunctionality.php:1246-1261`). |
| `af_{id}_pre_output($page)` | Для enabled-аддона в HTML pipeline; вернуть можно изменённую непустую строку (`inc/plugins/advancedfunctionality.php:2637-2667`). |
| `af_{id}_xmlhttp_init()` | В XMLHTTP bootstrap вместо обычного init (`inc/plugins/advancedfunctionality.php:2562-2576`). |
| `af_{id}_xmlhttp()` | Обработчик XMLHTTP; core-router вызывает без аргументов (`inc/plugins/advancedfunctionality.php:6287-6318`). |
| `af_{id}_migrate_identity()` | До enabled-check при обнаружении старой identity (`inc/plugins/advancedfunctionality.php:2344-2368`). |

Специальные исключения, которые не следует копировать в новый аддон: `advancedcombatsystem` имеет отдельные `is_installed/install/activate`; `adaptivethemeframework` требует, чтобы `install()` и `activate()` при необходимости вернули строго `true` (`inc/plugins/advancedfunctionality.php:1205-1233`). Для нового обычного аддона `activate()` автоматически не вызывается.

Общего `af_{id}_uninstall()` при disable **не найдено**; uninstall самого ядра также не вызывает uninstall аддонов (`inc/plugins/advancedfunctionality.php:136-165`).

## 5. API ядра для аддонов

### 5.1. Discovery, состояние и настройки

| API | Назначение |
|---|---|
| `af_discover_addons(): array` | Массив manifest-метаданных, отсортированный по `name`, с добавленными `path` и абсолютным `bootstrap` (`inc/plugins/advancedfunctionality.php:3001-3021`). |
| `af_is_addon_enabled(string $id): bool` | Строгая проверка `$mybb->settings['af_'.$id.'_enabled'] === '1'` (`inc/plugins/advancedfunctionality.php:6033-6037`). |
| `af_get_addon_bootstrap_path(string $id): ?string` | Путь bootstrap по id (`inc/plugins/advancedfunctionality.php:6333-6370`). |
| `af_ensure_settinggroup(name, title, desc): int` | Найти или создать группу настроек MyBB (`inc/plugins/advancedfunctionality.php:1820-1838`). |
| `af_ensure_setting(group, name, title, desc, type, value, disporder): void` | Создать/обновить настройку и удалить дубликаты по имени; **перезаписывает value переданным значением** (`inc/plugins/advancedfunctionality.php:1840-1894`). |
| `af_rebuild_and_reload_settings(): void` | `rebuild_settings()`, invalidate OPcache и перезагрузка `$mybb->settings` с сохранением `internal` (`inc/plugins/advancedfunctionality.php:205-230`). |
| `af_reload_settings_runtime(): void` | Перечитать `inc/settings.php` без rebuild (`inc/plugins/advancedfunctionality.php:2275-2288`). |
| `af_write_cache(key, value): void` | Записать `cache/{key}.txt` (`inc/plugins/advancedfunctionality.php:1690-1693`). |

Каноническая группа настроек аддона — `af_{id}`, enabled-настройка — `af_{id}_enabled`; ACP создаёт их автоматически (`inc/plugins/advancedfunctionality.php:1274-1299`).

### 5.2. Языки

- Языковые файлы генерируются из manifest как `inc/languages/{lang}/advancedfunctionality_{id}.lang.php` и аналогично в `admin/` (`inc/plugins/advancedfunctionality.php:2198-2239`).
- `af_load_addon_lang(string $id, $admin = null, ?string $override = null): void` загружает и сливает `$l` в `$lang`; второй аргумент `true` — ACP, `false` — front, строка трактуется как язык (`inc/plugins/advancedfunctionality.php:6186-6240`).
- Порядок выбора языка: override; в ACP `cplanguage`; язык пользователя; `bblanguage`; `english` (`inc/plugins/advancedfunctionality.php:6058-6096`).
- При отсутствии файла создаётся placeholder (`inc/plugins/advancedfunctionality.php:6098-6156`). Ручные правки сгенерированных файлов не являются источником: синхронизация перезаписывает содержимое по manifest (`inc/plugins/advancedfunctionality.php:2242-2273`).

### 5.3. Шаблоны

- Все `templates/*.html` **только верхнего уровня** читаются при активации ядра; рекурсивный обход не выполняется (`inc/plugins/advancedfunctionality.php:6466-6493`).
- Формат файла — последовательность блоков `<!-- TEMPLATE: template_name -->`; имя допускает латиницу, цифры, `_`, `-`, `.` (`inc/plugins/advancedfunctionality.php:6495-6518`).
- Каждый блок upsert-ится в MyBB `templates` как master template `sid=-2`, `version=1800`; дубликаты удаляются (`inc/plugins/advancedfunctionality.php:6520-6577`).
- Runtime-синхронизация enabled-аддонов запускается по подписи mtime+size или флагу `needs_refresh.txt` (`inc/plugins/advancedfunctionality.php:6390-6457`).
- API: `af_sync_all_addon_templates(bool $force = false)`, `af_sync_addon_templates(array $meta, bool $force = false)`, `af_parse_templates_bundle(string $raw): array`, `af_upsert_master_template(string $title, string $html, bool $force = false)` (`inc/plugins/advancedfunctionality.php:6372-6384`, `inc/plugins/advancedfunctionality.php:6460-6577`).
- Для вывода самостоятельной front-страницы есть `af_front_output_template_string(string $pageTitle, string $templateString, array $vars = []): void`: он подставляет переменные, оборачивает контент в header/footer, вызывает `output_page()` и завершает запрос (`inc/plugins/advancedfunctionality.php:253-299`).

### 5.4. Assets: очередь и автоматическая инъекция

| API | Назначение |
|---|---|
| `af_add_js_once(string $urlOrPath, array $meta = [])` | Поставить JS в request-local очередь (`inc/plugins/advancedfunctionality.php:7054-7057`). |
| `af_add_css_once(string $urlOrPath, array $meta = [])` | Поставить CSS с учётом file/theme delivery (`inc/plugins/advancedfunctionality.php:7059-7062`). |
| `af_add_asset_once(type, urlOrPath, meta)` | Общий метод: `$meta['scope']` = `front`/`admin`/`both`, `raw_url`, `version`; дедупликация по clean path/raw URL (`inc/plugins/advancedfunctionality.php:7064-7121`). |
| `af_asset_build_url(cleanPath, opts): string` | URL от `bburl` + `?v=` с filemtime, переданной версией или `TIME_NOW` (`inc/plugins/advancedfunctionality.php:6742-6763`). |
| `af_inject_enabled_addon_assets(page): string` | Инъекция очереди в `<head>` (fallback `</body>`/конец) (`inc/plugins/advancedfunctionality.php:6580-6618`, `inc/plugins/advancedfunctionality.php:8026-8042`). |
| `af_frontend_asset_allowed(...)` | Проверка разрешения по manifest/context (`inc/plugins/advancedfunctionality.php:3276-3281`). |

При наличии `assets` в manifest автоматически обрабатываются только перечисленные файлы; без него сканируются непосредственные `.css`/`.js` в `assets/` (`inc/plugins/advancedfunctionality.php:7915-7999`). Frontend injection не выполняется в ACP, XMLHTTP и redirect-страницах (`inc/plugins/advancedfunctionality.php:8008-8024`). В конце pipeline ядро дедуплицирует stylesheet/script tags (`inc/plugins/advancedfunctionality.php:2690-2701`).

### 5.5. Theme stylesheets

CSS-источники собираются из трёх мест с приоритетом: явный `theme_stylesheets` → `assets` manifest → автоматический поиск непосредственных `.css` в `assets/` (`inc/plugins/advancedfunctionality.php:3487-3657`). Admin-only CSS исключается из frontend registry (`inc/plugins/advancedfunctionality.php:3660-3688`).

Элемент `theme_stylesheets` поддерживает:

```php
[
    'file' => 'assets/example.css',       // обязательно
    'id' => 'main',                      // необязательный логический суффикс
    'attach' => [['file' => 'global']], // или ['file'=>'showthread.php','action'=>'...']
    'enabled_setting' => 'af_example_enabled',
    'admin_only' => 0,
    'exclude_autodiscovery' => false,
    'disable_theme_integration' => false,
    'conditional' => false,
    'label' => 'Example CSS',
    'delivery_mode' => 'auto',           // auto | theme | file
]
```

Поля читаются в `inc/plugins/advancedfunctionality.php:3563-3594`; `attach` нормализуется в `inc/plugins/advancedfunctionality.php:3691-3753`.

- `auto`: использовать usable Theme stylesheet, иначе исходный файл; `theme`: только Theme stylesheet; `file`: только исходный файл (`inc/plugins/advancedfunctionality.php:7677-7869`).
- Обычная синхронизация сохраняет ACP-изменённый CSS; force может заменить его seed-файлом после recovery snapshot (`inc/plugins/advancedfunctionality.php:5021-5229`).
- Runtime-синхронизация выполняется по подписи включённых CSS-источников (`inc/plugins/advancedfunctionality.php:5967-6031`).
- Основные API: `af_sync_theme_stylesheets(bool $force = false, ?string $onlyAddonId = null)`, `af_theme_stylesheet_delivery_decision(string $addonId, string $fileRel)`, `af_theme_stylesheet_set_delivery_mode(...)`, `af_collect_theme_stylesheet_diagnostics(...)` (`inc/plugins/advancedfunctionality.php:5406-5552`, `inc/plugins/advancedfunctionality.php:5599-5747`, `inc/plugins/advancedfunctionality.php:5749-5791`, `inc/plugins/advancedfunctionality.php:7677-7870`).

### 5.6. ACP-страница аддона

Manifest:

```php
'admin' => [
    'slug' => 'example',
    'title' => 'Example',
    'controller' => 'admin.php',
    // 'menu' => [['do' => 'tools', 'title' => 'Tools']],
],
```

`admin.php` должен объявить класс по производному от slug имени `AF_Admin_{Slug}` с публичным статическим `dispatch()`; для `example` — `AF_Admin_Example`. Ядро подключает bootstrap, затем controller и вызывает dispatch (`inc/plugins/advancedfunctionality.php:441-451`, `inc/plugins/advancedfunctionality.php:1168-1178`). URL: `index.php?module=advancedfunctionality&af_view=example`.

Страница доступна в меню только enabled-аддону (`inc/plugins/advancedfunctionality.php:1146-1165`, `inc/plugins/advancedfunctionality.php:1558-1563`). Пункты `admin.menu` добавляют `&do=...` (`inc/plugins/advancedfunctionality.php:1573-1587`). Обработка POST, CSRF и собственные права внутри `dispatch()` остаются ответственностью аддона; общего API для них в ядре **не найдено**.

### 5.7. XMLHTTP

Два доступных маршрута:

1. Универсальный core-route `xmlhttp.php?action=af_addon_api&addon=example`: ядро проверяет enabled, подключает bootstrap и вызывает `af_example_xmlhttp()` (`inc/plugins/advancedfunctionality.php:6247-6281`, `inc/plugins/advancedfunctionality.php:6287-6328`).
2. Gateway перебирает enabled-аддоны для любого `action=af_*`; обработчик `af_{id}_xmlhttp()` должен сам проверить действие и вернуть `true`, если обработал его; тогда gateway делает `exit` (`inc/plugins/advancedfunctionality/gateway_stub.php:21-95`).

Проверка прав, `my_post_key`, чтение входа, JSON-заголовки и завершение ответа остаются за аддоном. Для JSON можно использовать `af_avatar_json()` как существующий core-helper: он отключает HTML pre-output, ставит `no-store` и завершает запрос (`inc/plugins/advancedfunctionality/avatar.php:35-41`).

### 5.8. Прочие вспомогательные API

- `af_is_admin_context(): bool` — определение ACP (`inc/plugins/advancedfunctionality.php:6044-6056`).
- `af_frontend_request_context(): array` — неизменяемый request context: script/action/fid/tid/ajax/method/admin/xmlhttp и response capabilities (`inc/plugins/advancedfunctionality.php:6659-6712`).
- `af_current_theme_tid(): int` — текущая тема с учётом выбора пользователя (`inc/plugins/advancedfunctionality.php:7197-7222`).
- Avatar helpers: `af_avatar_state(role)`, `af_avatar_capabilities(role)`, `af_avatar_saved_response(role, expected)`, `af_avatar_native_saved()`, `af_avatar_state_route()`, `af_avatar_primary_compat()` (`inc/plugins/advancedfunctionality/avatar.php:5-158`). Это специализированный API аватаров, а не общий storage аддонов.

## 6. Схема БД ядра

Ядро создаёт одну собственную таблицу: `{TABLE_PREFIX}af_theme_stylesheets`, в install через `af_theme_stylesheets_install_schema()` (`inc/plugins/advancedfunctionality.php:78-80`, `inc/plugins/advancedfunctionality.php:3299-3335`). Движок InnoDB, collation — `build_create_table_collation()`.

| Столбец | Тип / default | Назначение по операциям ядра |
|---|---|---|
| `id` | `int unsigned`, AI, PK | ID registry row |
| `theme_tid` | `int unsigned`, `0` | MyBB theme ID |
| `stylesheet_sid` | `int unsigned`, `0` | Связь с `themestylesheets.sid` |
| `addon_id` | `varchar(120)`, `''` | Владелец-аддон |
| `logical_id` | `varchar(190)`, `''` | Логический CSS source |
| `stylesheet_name` | `varchar(190)`, `''` | Имя MyBB stylesheet |
| `source_file` | `varchar(255)`, `''` | Относительный source path |
| `seed_file` | `varchar(255)`, `''` | Физический seed path |
| `seed_checksum` | `char(40)`, `''` | SHA-1 текущего upstream seed |
| `installed_checksum` | `char(40)`, `''` | SHA-1 установленной AF версии |
| `current_checksum` | `char(40)`, `''` | SHA-1 текущего CSS |
| `sync_state` | `varchar(32)`, `'uninitialized'` | Состояние синхронизации |
| `last_synced_checksum` | `char(40)`, `''` | Legacy/three-way sync baseline |
| `is_integrated` | `tinyint(1)`, `0` | Интегрирован в тему |
| `delivery_mode` | `varchar(20)`, `'auto'` | `auto`/`theme`/`file` |
| `discovered_from` | `varchar(40)`, `''` | Способ discovery |
| `is_admin_only` | `tinyint(1)`, `0` | Только ACP |
| `last_synced_at` | `int unsigned`, `0` | Время sync |
| `manual_override` | `tinyint(1)`, `0` | Ручная правка ACP |
| `created_at`, `updated_at` | `int unsigned`, `0` | Временные метки |

Индексы: `PRIMARY(id)`, `UNIQUE uniq_theme_addon_logical(theme_tid, addon_id, logical_id)`, `idx_stylesheet_sid(stylesheet_sid)`, `idx_addon(addon_id)` (`inc/plugins/advancedfunctionality.php:3329-3333`). Для существующей таблицы реализованы additive-миграции недостающих столбцов (`inc/plugins/advancedfunctionality.php:3338-3389`).

Ядро также использует штатные таблицы MyBB, но не создаёт их схему:

- `settinggroups`/`settings`: признак установки ядра и настройки `af_core`, enabled-флаги аддонов (`inc/plugins/advancedfunctionality.php:56-61`, `inc/plugins/advancedfunctionality.php:1274-1299`).
- `templates`: master templates аддонов `sid=-2` (`inc/plugins/advancedfunctionality.php:6523-6577`).
- `themes`/`themestylesheets`: темы и фактические CSS (`inc/plugins/advancedfunctionality.php:5021-5229`).

При uninstall ядра таблица `af_theme_stylesheets` и языки намеренно сохраняются; удаляются core-настройки и ACP module directory (`inc/plugins/advancedfunctionality.php:136-165`).

## 7. Полный жизненный цикл

1. **Install ядра:** scaffold/языки → настройки `af_core` → таблица CSS registry → ACP proxy → языки существующих аддонов → CSS sync → gateway → cache timestamp (`inc/plugins/advancedfunctionality.php:63-96`). Enabled-аддоны при этом не включаются и их `install()` не вызывается.
2. **Activate ядра:** обновление scaffold/языков → языки всех аддонов → master templates всех аддонов → CSS sync → gateway → cache/reset refresh (`inc/plugins/advancedfunctionality.php:105-131`).
3. **Enable аддона в ACP:** bootstrap → install/специальный lifecycle → enabled=1 → reload settings → CSS sync (`inc/plugins/advancedfunctionality.php:1189-1243`).
4. **Обычный запрос:** enabled-check → язык → bootstrap → init; затем MyBB-хуки аддона и core pre-output (`inc/plugins/advancedfunctionality.php:2322-2383`, `inc/plugins/advancedfunctionality.php:2625-2701`).
5. **Disable аддона:** enabled=0 → reload settings → detach/reset CSS registry → bootstrap → deactivate (`inc/plugins/advancedfunctionality.php:1246-1262`). Данные аддона не удаляются.
6. **Deactivate ядра:** записывается только `needs_refresh=1` (`inc/plugins/advancedfunctionality.php:168-171`).
7. **Uninstall ядра:** удаление core-настроек и ACP proxy; registry/языки/файлы сохраняются (`inc/plugins/advancedfunctionality.php:136-165`).
