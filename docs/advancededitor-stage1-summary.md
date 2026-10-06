# AdvancedEditor — Stage 1 Summary

Изменения поставляются в main вместе с этой документацией. Изменения не развёртывались на warprift.ru.

## 1. Initial editor shell

- `advancededitor_shell.js`: lightweight insertion, source adapter, click routing, generic capability loader, counter и Quick Edit lifecycle.
- `advancededitor_shell.css`: wrapper, textarea, группы, кнопки, dropdown shell, иконки, резервирование counter layout.
- HTML toolbar, button metadata и runtime registry готовятся сервером в `shell.php`/`advancededitor.php`; иконки доступны до загрузки пакетов. Сохранён configured layout, help placement и custom DB buttons.
- Существующий local-font stylesheet сохраняется, если настроен. Базовый jQuery остаётся MyBB dependency.
- В измеренном стенде initial JS — jQuery, `charcountandprew_view.js` для опубликованных post counters и `advancededitor_shell.js`. View runtimes для уже опубликованных BBCode загружаются отдельно по manifest markers; это не editor dialogs.

Focus не загружает SCEditor. Bold/Italic/Underline/Strike/Quote/Code/URL/Image/basic lists/basic align/simple spoiler/custom opentag-closetag работают без отдельных JS requests.

## 2–3. Lazy runtimes и triggers

| Runtime | Первый trigger | Что загружается |
| --- | --- | --- |
| Tables | Tables / `af_tables` | shared jscolor helper, `tables.js`, `tables.css` |
| Color | Color / `color` | `jscolor.js`, `jscolorpiker.js`, picker CSS |
| Stickers | `af_stikers` | `stikers.js`, `stikers.css`; observer появляется после активации |
| KB | `af_kb_insert` | `knowledgebase_insert.js`, `knowledgebase_insert.css`; SCEditor не требуется |
| WYSIWYG | `af_togglemode`, `source` или явно объявленная dependency | SCEditor core, BBCode plugin, доступный локальный MyBB bridge, native theme/content CSS, `advancededitor_wysiwyg_bbcodes.js`, `advancededitor.js`, full editor CSS |
| Drafts | `af_drafts` | `drafts.js`, `drafts.css`; recovery/autosave выбранного editor/form |
| Font | `font`, `af_font` | fontfamily JS/CSS |
| Font size | `size`, `af_fontsize` | fontsize JS/CSS |
| Tquote | `af_tquote` | tquote JS/CSS, shared jscolor |
| Resize image | `af_resizeimg` | resizeimg JS/CSS |
| Lock content | `af_lockcontent` | lockcontent JS/CSS |
| Tabs | `af_tabs` | tabs dialog JS/CSS |
| Embed video | `af_embedvideos` | embedvideos dialog JS/CSS |
| Advanced spoiler | `af_spoiler` | titled spoiler dialog JS/CSS |
| Abbr, Anchors, Mark, Indent, Float, HTMLBB, advanced Lists/Align | Соответствующая pack button | Только assets её manifest |
| Preview | form button `previewpost` | charcountandprew preview JS/CSS; initial counter остаётся lightweight |
| Accordion | `af_accordion` | Generic insertion; отдельного editor JS request нет |
| Custom heavy buttons | button с `handler`/`runtime`/`capability` | Декларативные assets/dependencies; generic loader |

Loader: unloaded/loading/loaded/failed, shared Promise, asset/dependency dedup, ordered JS, timeout/network failure, локальное сообщение и retry. Pack directories не сканируются по кликам. AF core и MyBB core не изменены.

Feature CSS исключён из global autodiscovery через manifest flags. Добавлена миграция старых structured theme bundle sections с recovery-копией, сохранением edited feature bodies и per-theme CSS exports. Published-content CSS выделен отдельно.

## 4–5. BEFORE / AFTER

| Метрика | BEFORE | AFTER |
| --- | ---: | ---: |
| Initial JS requests | 31 | 3 |
| JS transferred, bytes (resource transferSize) | 267580 | 40526 |
| Decoded JS, bytes | 948056 | 117090 |
| Scripting, ms | 261.8 | 77.1 |
| DOMContentLoaded, ms | 474.5 | 267.6 |
| Load, ms | 595.5 | 269.6 |


**Методика:** один сравнительный cold-context запуск Chromium, одинаковый локальный PHP/compiler fixture, gzip, реальные pack assets, jQuery 3.5.1, SCEditor 3.2.1 и native MyBB CSS. Включены jQuery и post counters. Timings зависят от локальной машины; это не production timings. В baseline один старый shared-jscolor URL не разрешается; новый resolver исправляет этот путь.

На initial AFTER нет requests к SCEditor, WYS bbcode bridge, tables editor, color picker, stickers, drafts, KB insert или остальным editor packs. В baseline эти editor runtimes запрашиваются сразу. Опциональный `jquery.sceditor.mybb.js` в доступной установке отсутствовал; loader включает его, если файл установлен.

Авторизованной production-сессии и deployment-доступа нет: live showthread BEFORE/AFTER, реальная тема warprift.ru и серверные Submit/Quick Edit Save после установки остаются непроверенными. Не утверждается, что production acceptance уже подтверждён. Редактированные published-BBCode стили нужно визуально сверить с выделенными view CSS.

## 6. Первый click

Tables открывает существующий builder после первого lazy load, вставка таблицы проверена. Color открывает picker после загрузки. Stickers открывает UI и только тогда создаёт observer. KB открывает picker, вставляет `[kb=type:key]` и использует текущий source/WYS mode. Повторный KB click не делает новый JS request.

WYSIWYG загружает отдельную capability и инициализирует существующий textarea. Набранный текст сохранён; неизменённая исходная BBCode spelling сохраняется при Submit и первом возврате в source, а реальные правки используют native serializer. Переключение режимов и неизменная высота toolbar проверены.

Initial source mode применяется независимо от remembered/default WYS preference, чтобы выполнить требование нулевого SCEditor на initial. Настройки full/partial работают при явной активации. Обязательного initial autosave requirement в коде не обнаружено; Drafts активирует существующий autosave/recovery только по запросу. Отдельного нового Drafts UI не добавлялось.

## 7. Regression tests

- `advancededitor_feature_lazy_regression.php`: real response compiler/metadata, initial exclusions, все declared assets, custom simple/heavy buttons, inline SVG, help placement, content/editor split и theme flags.
- `advancededitor_feature_theme_migration_regression.php`: real AF codec, сохранение edited bodies/других секций, idempotence и отказ от переписывания corrupt bundle.
- `advancededitor_feature_lazy_browser.cjs`: first-click Tables/Color/Font/Size/Spoiler/Tabs/Stickers/KB, Unicode selection/caret, network failure/retry, shared helper/Promise dedup, одиночные и параллельные dependency cycles, source focus, observer, Drafts после WYS, Preview в Quick Edit, Source/WYS Submit, initial-text preservation и дальнейшие правки.
- Три цикла Quick Edit Cancel/Save/Cancel с native DOM shape и delayed id: immediate shell, basic buttons, lazy packs, `af:editor-ready`, destruction, postcount/meta restoration. Server Save здесь моделируется, а не выполняется на реальном MyBB.
- Обновлены существующие Quick Edit/postbit/performance tests под новую принадлежность shell/view/preview кода.

## 8. Полный suite

BEFORE: **112 passed / 12 failed**, 124 PHP tests.
AFTER: **114 passed / 12 failed**, 126 PHP tests.
Новых failures нет. Browser suite прошёл. PHP 8.5 lint и Node syntax checks изменённых файлов прошли.

Существующие failures:

- `tests/adaptivethemeframework_design_system_regression.php`
- `tests/adaptivethemeframework_postbit_classic_regression.php`
- `tests/adaptivethemeframework_scaffold_regression.php`
- `tests/advancedbuddylist_ajax_json_regression.php`
- `tests/advancedbuddylist_friendship_model_regression.php`
- `tests/advancedbuddylist_menu_integration_regression.php`
- `tests/advancedbuddylist_search_request_ux_regression.php`
- `tests/advancedmenu_custom_section_regression.php`
- `tests/advresponsivelayout_unified_mobile_contract_regression.php`
- `tests/atf_reputation_navigation_regression.php`
- `tests/global_addon_frontend_manifest_regression.php`
- `tests/theme_stylesheet_incremental_sync_regression.php`

Они воспроизведены на исходном main до правок: ATF, buddylist/menu contracts, отсутствующий advresponsivelayout и helper в incremental-theme regression. Полный suite не является полностью зелёным.

## 9. Main

Изменения подготовлены для fast-forward в main. Хеш commit и результат remote verification сообщаются в ответе к задаче. Production deployment не выполнялся.
