# Архитектура AdvancedFunctionality

**Дата актуальности:** 2026-11-01.  
**Ветка:** `main`.  
**Назначение документа:** единая карта проекта для автора и AI-агентов. Документ описывает фактическую архитектуру, а не версионированный стабильный API; детали и границы API см. в [CORE_API_MAP](audits/CORE_API_MAP.md), текущие риски — в [CORE_AUDIT](audits/CORE_AUDIT_2026.md).

## 1. Назначение и границы проекта

AdvancedFunctionality — плагин MyBB 1.8.x и экосистема внутренних аддонов с общей точкой включения, отключения и настройки (`inc/plugins/advancedfunctionality.php:42-53`). Заголовок ядра декларирует совместимость с PHP 8.0–8.5 (`inc/plugins/advancedfunctionality.php:2-5`); целевая конфигурация проекта — MyBB 1.8.41 и PHP 8.5.12.

Ядро отвечает за discovery аддонов, lifecycle, языки, master templates, доставку CSS/JS, theme stylesheets, ACP-роутинг и XMLHTTP-маршруты (`docs/audits/CORE_API_MAP.md:58-86`). Предметная логика находится в аддонах; ядро не предоставляет общего frontend-реестра навигации (`inc/plugins/advancedfunctionality.php:1558-1587`; отсутствие общего реестра зафиксировано в `docs/audits/CORE_API_MAP.md:102`).

На дату среза в `addons/` находится **35** папок, а не 37; полный список и фактические связи см. в [ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md) (`docs/audits/ADDONS_REGISTRY.md:3-4`, `docs/audits/ADDONS_REGISTRY.md:52-54`).

## 2. Структура репозитория

```text
mybb-advancedfunctionality/
├── inc/plugins/advancedfunctionality.php       # основной плагин и API ядра
├── inc/plugins/advancedfunctionality/
│   ├── addons/<id>/                            # аддоны
│   ├── admin/router.php                        # вход ACP-роутера
│   ├── assets/                                 # ресурсы ACP ядра
│   ├── avatar.php                              # специализированный avatar API
│   ├── gateway_stub.php                        # seed XMLHTTP gateway
│   └── cache/                                  # runtime-подписи и флаги
├── inc/tasks/                                  # задачи планировщика аддонов
├── docs/                                       # 3 главных файла, contracts/, audits/, design/, archive/
├── tests/                                      # регрессионные PHP/CJS-тесты
└── *.php                                       # установленные публичные entry points
```

Состав подтверждён инвентаризацией (`docs/audits/REPO_INVENTORY.md:6-46`). Корневые `abilities.php`, `balancemanage.php`, `characters.php`, `charactersheets.php`, `inventories.php`, `inventory.php`, `kb.php`, `shop.php`, `shop_manage.php`, `wanted.php` — публичные точки входа соответствующих аддонов (`docs/audits/REPO_INVENTORY.md:12-21`). Их наличие в корне не означает, что ядро автоматически копирует любой PHP из `assets/`: установку alias выполняет lifecycle конкретного аддона (`inc/plugins/advancedfunctionality.php:3001-3021`; пример KB — `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1942-1958`).

Типовой аддон:

```text
addons/<id>/
├── manifest.php          # обязательный discovery-файл
├── <id>.php              # bootstrap и af_<id>_* API
├── admin.php             # необязательный ACP-контроллер
├── assets/               # CSS/JS и seed PHP-страницы
├── templates/            # необязательные template bundles
└── modules/              # необязательные внутренние модули
```

Это фактический паттерн эталонных аддонов, а не требование создавать все каталоги (`docs/design/core/ANATOMY_OF_ADDON.md:5-15`). Подробный жизненный цикл и пошаговый шаблон — в [ANATOMY_OF_ADDON](design/core/ANATOMY_OF_ADDON.md).

## 3. Архитектура ядра

### 3.1. Точки входа и запрос

MyBB загружает `inc/plugins/advancedfunctionality.php`; файл проверяет `IN_MYBB`, определяет пути `AF_BASE`, `AF_ADDONS`, `AF_ADMIN`, `AF_ASSETS`, `AF_CACHE` и регистрирует MyBB-хуки (`inc/plugins/advancedfunctionality.php:11-29`, `inc/plugins/advancedfunctionality.php:2292-2320`).

Основные потоки:

1. **Обычный frontend:** `global_start` загружает язык ядра и инициализирует enabled-аддоны; `pre_output_page` запускает фильтры аддонов, синхронизации и asset pipeline (`inc/plugins/advancedfunctionality.php:2295-2301`, `inc/plugins/advancedfunctionality.php:2625-2701`).
2. **XMLHTTP:** вместо обычного `af_{id}_init()` вызывается `af_{id}_xmlhttp_init()`; отдельные core/gateway routes вызывают `af_{id}_xmlhttp()` (`inc/plugins/advancedfunctionality.php:2510-2577`, `inc/plugins/advancedfunctionality.php:6247-6328`).
3. **ACP:** `admin/modules/advancedfunctionality/index.php` → `admin/router.php` → основной плагин → `AF_Admin::dispatch()` (`inc/plugins/advancedfunctionality/admin/router.php:1-8`).
4. **Gateway:** seed `gateway_stub.php` копируется в корень как `advancedfunctionality_gateway.php`; чужой runtime без AF-маркера не перезаписывается (`inc/plugins/advancedfunctionality.php:1742-1790`).

Bootstrap выполняется один раз за запрос через `$GLOBALS['af_addons_bootstrapped']` (`inc/plugins/advancedfunctionality.php:2322-2328`). HTML-pipeline пропускается для AJAX/JSON по `af_disable_pre_output`, `Content-Type`, `ajax=1`, XHR/`Accept` или `AF_NO_PRE_OUTPUT` (`inc/plugins/advancedfunctionality.php:2960-2998`).

### 3.2. Discovery и manifest

Discovery сканирует непосредственные подкаталоги `AF_ADDONS`, включает `manifest.php`, принимает массив с непустыми `id`/`name`, добавляет `path`, нормализует `bootstrap` и сортирует результат по `name` (`inc/plugins/advancedfunctionality.php:3001-3021`).

Ключи manifest:

| Ключ | Назначение |
|---|---|
| `id`, `name` | Обязательная identity; `id` определяет имена функций и настроек (`inc/plugins/advancedfunctionality.php:3010-3017`). |
| `bootstrap` | Главный PHP-файл аддона (`inc/plugins/advancedfunctionality.php:3014-3016`). |
| `description`, `version`, `author`, `authorsite` | Метаданные обзора ACP (`inc/plugins/advancedfunctionality.php:471-500`). |
| `lang` | RU/EN, front/admin переводы (`inc/plugins/advancedfunctionality.php:2198-2239`). |
| `assets` | Явные списки CSS/JS для front/admin/both (`inc/plugins/advancedfunctionality.php:7915-7951`). |
| `frontend` | Режим доставки `legacy`, `global`, `contextual`, routes и response rules (`inc/plugins/advancedfunctionality.php:3031-3186`). |
| `theme_stylesheets` | CSS-источники, attach-правила и delivery mode (`inc/plugins/advancedfunctionality.php:3563-3594`). |
| `admin` | `slug`, `title`, `controller`, необязательное `menu` (`inc/plugins/advancedfunctionality.php:1146-1178`). |
| `legacy_ids`, `legacy_lifecycle_settings` | Миграция старой identity до enabled-check (`inc/plugins/advancedfunctionality.php:2344-2368`). |

Канонические имена: группа `af_{id}`, enabled-настройка `af_{id}_enabled`, функции `af_{id}_*` (`inc/plugins/advancedfunctionality.php:1274-1299`). Фактические аддоны могут использовать исторические короткие префиксы; перед интеграцией следует сверяться с реестром (`docs/audits/ADDONS_REGISTRY.md:18`, `docs/audits/ADDONS_REGISTRY.md:33-38`).

### 3.3. Lifecycle

| Событие | Фактическое поведение |
|---|---|
| Install ядра | Scaffold → языки → `af_core` → таблица CSS registry → ACP proxy → языки аддонов → CSS sync → gateway → cache (`inc/plugins/advancedfunctionality.php:63-96`). |
| Activate ядра | Scaffold/языки → master templates → CSS sync → gateway → cache (`inc/plugins/advancedfunctionality.php:105-131`). |
| Enable аддона | Bootstrap → `af_{id}_install()` → enabled=1 → rebuild settings → CSS sync (`inc/plugins/advancedfunctionality.php:1189-1243`). |
| Runtime аддона | Language sync/load → `require_once bootstrap` → `af_{id}_init()` (`inc/plugins/advancedfunctionality.php:2370-2380`). |
| Pre-output | `af_{id}_pre_output($page)` для enabled-аддонов (`inc/plugins/advancedfunctionality.php:2637-2667`). |
| Disable аддона | Enabled=0 → detach CSS → bootstrap → `af_{id}_deactivate()` (`inc/plugins/advancedfunctionality.php:1246-1262`). |
| Deactivate ядра | Только `needs_refresh=1` (`inc/plugins/advancedfunctionality.php:168-171`). |
| Uninstall ядра | Удаляются core-настройки и ACP module directory; registry и языки сохраняются (`inc/plugins/advancedfunctionality.php:136-165`). |

Для обычного аддона `activate()` при Enable **не вызывается**; специальные ветки существуют для `advancedcombatsystem` и `adaptivethemeframework` (`inc/plugins/advancedfunctionality.php:1205-1237`). Общий вызов `af_{id}_uninstall()` при Disable и uninstall ядра **не найден** (`inc/plugins/advancedfunctionality.php:1246-1262`, `inc/plugins/advancedfunctionality.php:136-165`). Поэтому обязательная идемпотентная установка обычного аддона должна быть доступна из `af_{id}_install()`, а политика удаления данных должна быть задана отдельно (`docs/design/core/ANATOMY_OF_ADDON.md:152-162`).

## 4. API ядра для аддонов

### 4.1. Lifecycle и hooks

Доступные callback-контракты: `af_{id}_install()`, `af_{id}_init()`, `af_{id}_deactivate()`, `af_{id}_pre_output($page)`, `af_{id}_xmlhttp_init()`, `af_{id}_xmlhttp()`, `af_{id}_migrate_identity()`; условия вызова перечислены в [CORE_API_MAP §4](audits/CORE_API_MAP.md#4-функции-жизненного-цикла-аддона) (`inc/plugins/advancedfunctionality.php:2344-2380`, `inc/plugins/advancedfunctionality.php:2562-2576`, `inc/plugins/advancedfunctionality.php:2637-2667`, `inc/plugins/advancedfunctionality.php:6287-6318`).

`af_{id}_init()` регистрирует собственные MyBB-хуки через `$plugins->add_hook()`; пример эталонной регистрации — KB (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:9314-9321`). Callback должен существовать к моменту срабатывания, а приоритеты должны отражать необходимый порядок (`docs/design/core/ANATOMY_OF_ADDON.md:103-123`).

### 4.2. Настройки, языки и состояние

- `af_discover_addons()` — metadata всех аддонов (`inc/plugins/advancedfunctionality.php:3001-3021`).
- `af_is_addon_enabled($id)` — строгая проверка enabled=`'1'` (`inc/plugins/advancedfunctionality.php:6033-6037`).
- `af_get_addon_bootstrap_path($id)` — путь bootstrap по id (`inc/plugins/advancedfunctionality.php:6333-6370`).
- `af_ensure_settinggroup()` / `af_ensure_setting()` — создание и обновление настроек; второй helper **перезаписывает value** переданным значением (`inc/plugins/advancedfunctionality.php:1820-1894`).
- `af_rebuild_and_reload_settings()` — rebuild, OPcache invalidate и reload runtime settings (`inc/plugins/advancedfunctionality.php:205-230`).
- `af_load_addon_lang()` — загрузка перевода с выбором языка и ACP/front-контекста (`inc/plugins/advancedfunctionality.php:6186-6240`).

Языковые файлы генерируются из manifest как `advancedfunctionality_{id}.lang.php`; ручные правки сгенерированных файлов перезаписываются синхронизацией (`inc/plugins/advancedfunctionality.php:2198-2273`).

### 4.3. Templates

Ядро читает только `templates/*.html` верхнего уровня, без рекурсивного обхода (`inc/plugins/advancedfunctionality.php:6466-6493`). Файл состоит из блоков `<!-- TEMPLATE: template_name -->`; блоки сохраняются как master templates `sid=-2`, `version=1800` (`inc/plugins/advancedfunctionality.php:6495-6577`).

Основные API: `af_sync_all_addon_templates()`, `af_sync_addon_templates()`, `af_parse_templates_bundle()`, `af_upsert_master_template()` (`inc/plugins/advancedfunctionality.php:6372-6384`, `inc/plugins/advancedfunctionality.php:6460-6577`). Для standalone HTML-страницы доступен `af_front_output_template_string()` (`inc/plugins/advancedfunctionality.php:253-299`).

### 4.4. CSS/JS и theme stylesheets

- `af_add_js_once()`, `af_add_css_once()`, `af_add_asset_once()` — request-local очередь с дедупликацией (`inc/plugins/advancedfunctionality.php:7054-7121`).
- `af_frontend_asset_decision()` / `af_frontend_asset_allowed()` — проверка manifest/request/response context (`inc/plugins/advancedfunctionality.php:3210-3281`).
- `af_frontend_request_context()` — script/action/fid/tid/ajax/method/admin/xmlhttp и response capabilities (`inc/plugins/advancedfunctionality.php:6659-6712`).
- `af_sync_theme_stylesheets()` и `af_theme_stylesheet_delivery_decision()` — синхронизация и выбор theme/file delivery (`inc/plugins/advancedfunctionality.php:5406-5552`, `inc/plugins/advancedfunctionality.php:7677-7870`).

`delivery_mode`: `auto` выбирает usable theme stylesheet иначе файл; `theme` допускает только theme stylesheet; `file` — только исходный файл (`inc/plugins/advancedfunctionality.php:7677-7869`). Явные manifest assets имеют приоритет над directory fallback; автоматически обрабатываются только перечисленные файлы, а без `assets` — непосредственные CSS/JS в `assets/` (`inc/plugins/advancedfunctionality.php:7915-7999`). Обязательные правила доставки и ручных вставок см. в [FRONTEND_ASSET_MANIFEST_CONTRACT](contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md) (`docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:63-71`, `docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:96-120`).

### 4.5. ACP и XMLHTTP

ACP controller объявляет `AF_Admin_{Slug}` с `public static dispatch()`; URL — `index.php?module=advancedfunctionality&af_view=<slug>` (`inc/plugins/advancedfunctionality.php:441-452`, `inc/plugins/advancedfunctionality.php:1168-1178`). Ядро выводит общий ACP shell, но POST validation, `my_post_key`, права и экранирование остаются ответственностью аддона (`inc/plugins/advancedfunctionality.php:426-452`; `docs/design/core/ANATOMY_OF_ADDON.md:125-135`).

XMLHTTP-маршруты:

- `xmlhttp.php?action=af_addon_api&addon=<id>` → `af_{id}_xmlhttp()` (`inc/plugins/advancedfunctionality.php:6247-6328`);
- gateway перебирает enabled-аддоны для `action=af_*`; обработчик возвращает `true`, если обработал действие (`inc/plugins/advancedfunctionality/gateway_stub.php:21-95`).

Проверки прав, CSRF, входные данные, JSON-заголовки и завершение ответа выполняет аддон; общего полного security-wrapper для XMLHTTP **не найдено** (`inc/plugins/advancedfunctionality/gateway_stub.php:21-95`, `inc/plugins/advancedfunctionality.php:6287-6328`).

## 5. Схема базы данных

### 5.1. Собственная таблица ядра

Ядро создаёт одну таблицу `{TABLE_PREFIX}af_theme_stylesheets` через `af_theme_stylesheets_install_schema()` (`inc/plugins/advancedfunctionality.php:78-80`, `inc/plugins/advancedfunctionality.php:3299-3335`). Назначение — связать CSS-источник аддона, MyBB theme/stylesheet, seed/current checksums и delivery state.

| Группа полей | Поля |
|---|---|
| Identity | `id`, `theme_tid`, `stylesheet_sid`, `addon_id`, `logical_id`, `stylesheet_name` |
| Источник | `source_file`, `seed_file`, `discovered_from` |
| Three-way sync | `seed_checksum`, `installed_checksum`, `current_checksum`, `last_synced_checksum`, `sync_state`, `manual_override` |
| Доставка | `is_integrated`, `delivery_mode`, `is_admin_only` |
| Время | `last_synced_at`, `created_at`, `updated_at` |

Типы и defaults перечислены в [CORE_API_MAP §6](audits/CORE_API_MAP.md#6-схема-бд-ядра) (`inc/plugins/advancedfunctionality.php:3299-3335`). Индексы: `PRIMARY(id)`, уникальный `(theme_tid, addon_id, logical_id)`, `stylesheet_sid`, `addon_id`; для старых установок предусмотрены additive-миграции (`inc/plugins/advancedfunctionality.php:3329-3389`).

### 5.2. Таблицы MyBB и аддонов

Ядро использует, но не создаёт схему штатных таблиц MyBB:

- `settinggroups`, `settings` — core-настройки и enabled-флаги (`inc/plugins/advancedfunctionality.php:56-61`, `inc/plugins/advancedfunctionality.php:1274-1299`);
- `templates` — master templates (`inc/plugins/advancedfunctionality.php:6523-6577`);
- `themes`, `themestylesheets` — темы и фактические CSS (`inc/plugins/advancedfunctionality.php:5021-5229`).

Единой сводной схемы таблиц всех аддонов в ядре **не найдено**: install конкретного аддона владеет своей схемой, например KB (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1539-1567`) и CS (`inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:26-45`). Фактические общие таблицы и зависимости зафиксированы в [ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md) (`docs/audits/ADDONS_REGISTRY.md:56-62`).

Uninstall ядра намеренно сохраняет `af_theme_stylesheets` и языки (`inc/plugins/advancedfunctionality.php:136-165`). Disable не должен рассматриваться как удаление данных; KB и CS также сохраняют таблицы при uninstall (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1837-1856`, `inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:48-93`).

## 6. Подводные камни и обязательные проверки

- **high — горячий CSS path содержит N+1 и повторные SQL-запросы.** Новые интеграции не должны добавлять собственные per-file/per-post обходы поверх существующего pipeline (`inc/plugins/advancedfunctionality.php:7677-7718`, `inc/plugins/advancedfunctionality.php:7244-7273`; аудит: `docs/audits/CORE_AUDIT_2026.md:27-35`).
- **medium — discovery и language sync повторяются в runtime.** Не следует дополнительно вызывать полный discovery в per-post/per-item циклах (`inc/plugins/advancedfunctionality.php:2322-2382`, `inc/plugins/advancedfunctionality.php:3001-3021`; `docs/audits/CORE_AUDIT_2026.md:31-34`).
- **high — Enable не равен Install+Activate.** Для обычного аддона вызывается только install; перенос обязательной инициализации исключительно в activate оставит её невыполненной (`inc/plugins/advancedfunctionality.php:1234-1243`).
- **high — uninstall не является частью Disable.** Нельзя полагаться на автоматическое удаление owned objects или данных (`inc/plugins/advancedfunctionality.php:1246-1262`).
- **medium — `af_ensure_setting()` перезаписывает value.** Для пользовательской настройки нельзя безусловно передавать default при каждом runtime/repair (`inc/plugins/advancedfunctionality.php:1840-1894`).
- **medium — templates не рекурсивны.** Вложенные HTML-файлы не подхватываются общим bundle loader (`inc/plugins/advancedfunctionality.php:6466-6493`).
- **high — PHP в `assets/` не устанавливается автоматически.** Нужны owned copy/remove, marker и fallback; чужой target перезаписывать нельзя (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1922-1966`; `docs/design/core/ANATOMY_OF_ADDON.md:137-150`).
- **high — ручные assets не обходят permission API.** Любые `<script>`, `<link>`, inline config и изменения `$headerinclude` должны проходить `af_frontend_asset_allowed()` (`docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:63-71`).
- **medium — межаддонные зависимости не задекларированы.** Манифесты не содержат общего поля зависимостей, при этом перекрёстные вызовы существуют; фактический паттерн защиты — `function_exists`-guard (`docs/audits/ADDONS_REGISTRY.md:56-60`; пример `inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:1588`).
- **medium — identity бывает исторической.** Папка `advancedjsbandle` и префиксы `af_kb_*`, `af_atf_*`, `af_aam_*`, `af_apf_*`, `af_asb_*` нельзя механически «исправлять» при интеграции без миграции (`docs/audits/ADDONS_REGISTRY.md:28`, `docs/audits/ADDONS_REGISTRY.md:56-63`).
- **low — динамические свойства языкового объекта.** Совместимость `af_lang_merge()` с конкретной реализацией `MyLanguage` не подтверждена, поскольку её определение отсутствует в репозитории (`inc/plugins/advancedfunctionality.php:6161-6183`; `docs/audits/CORE_AUDIT_2026.md:44-53`).

Перед изменением аддона минимально проверить enable → повторный enable/repair → disable → повторный enable и отдельный uninstall, а также frontend asset contexts (`docs/design/core/ANATOMY_OF_ADDON.md:152-162`). Универсальный чек-лист — [ANATOMY_OF_ADDON §8](design/core/ANATOMY_OF_ADDON.md#8-универсальный-чек-лист-аудита-любого-аддона).

## 7. Карта документации

Базовые источники архитектуры:

- [CORE_API_MAP](audits/CORE_API_MAP.md) — полный фактический API ядра, manifest и схема core-таблицы.
- [CORE_AUDIT](audits/CORE_AUDIT_2026.md) — подтверждённые замечания по безопасности, производительности и PHP 8.5.
- [ANATOMY_OF_ADDON](design/core/ANATOMY_OF_ADDON.md) — эталонный lifecycle, bootstrap, ACP, standalone pages и чек-листы.
- [ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md) — фактический состав аддонов и связи.
- [REPO_INVENTORY](audits/REPO_INVENTORY.md) — структура репозитория и классификация документации.
- [FRONTEND_ASSET_MANIFEST_CONTRACT](contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md) — обязательный frontend/asset contract.

Предметные контракты, которые необходимо учитывать при соответствующих интеграциях:

- [ATF_PAGE_SHELL_CONTRACT](contracts/ATF_PAGE_SHELL_CONTRACT.md), [ATF_TEMPLATE_COMPATIBILITY_CONTRACT](contracts/ATF_TEMPLATE_COMPATIBILITY_CONTRACT.md), [ATF_GLOBAL_MODAL_CONTRACT](contracts/ATF_GLOBAL_MODAL_CONTRACT.md) — владение page shell, шаблонами и модалками.
- [ARPG_INTEGRATION_CONTRACT](contracts/ARPG_INTEGRATION_CONTRACT.md), [ARPG_ORIGIN_VARIANT_JSON_CONTRACT](contracts/ARPG_ORIGIN_VARIANT_JSON_CONTRACT.md), [ELEMENT_THEME_CONTRACT](contracts/ELEMENT_THEME_CONTRACT.md) — Meta JSON и element identity.
- [CHARACTER_ABILITY_EFFECTS_CONTRACT](contracts/CHARACTER_ABILITY_EFFECTS_CONTRACT.md) — эффекты способностей персонажей.
- [ADVANCED_APPEARANCE_STUDIO_CONTRACT](contracts/ADVANCED_APPEARANCE_STUDIO_CONTRACT.md) — Appearance Studio.
- [ADVANCED_GALLERY_MEDIA_API](contracts/ADVANCED_GALLERY_MEDIA_API.md) — Gallery Media API.
- [ACS_KB_NORMALIZATION_CONTRACT](contracts/ACS_KB_NORMALIZATION_CONTRACT.md), [ACS_KB_MECHANICS_CONTRACT](contracts/ACS_KB_MECHANICS_CONTRACT.md), [ACS_KB_FORMULA_CONTRACT](contracts/ACS_KB_FORMULA_CONTRACT.md) — интеграция ACS/KB.

Типы документов design/implementation/audit не следует смешивать со статусом фактического core API; классификация приведена в `docs/audits/REPO_INVENTORY.md:55-185`.
