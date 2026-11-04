# ADDONS_REGISTRY — реестр аддонов AdvancedFunctionality (Задача 0.2)

Дата среза: 2026-11-01. Ветка: `main`. Местоположение: `inc/plugins/advancedfunctionality/addons/`.
Всего аддонов: **35** (в постановке задачи указано 37 — фактическое число папок в `addons/` по `ls` на срезе 2026-11-01 равно 35; критичность: medium, расхождение постановки с фактом). Код и существующие документы не изменялись; создан только этот файл.

Легенда колонок:
- **Файлы** — php-модули аддона (без `templates/`, подробности в заметках).
- **manifest / admin / assets** — `+` есть, `−` нет (для admin у `fastnews` админ-контроллер называется `fastnews_admin.php`, см. заметку).
- **Зависимости заявленные** — поле в manifest.php. Во всех 37 манифестах явное поле зависимостей **отсутствует** (проверено grep по `depend|require` — совпадений только на нерелевантных строках, например advancedcombatsystem/manifest.php:16). Считать: не заявлены.
- **Зависимости фактические** — перекрёстные вызовы API `af_<addon>_...()` из кода других аддонов (grep по всем php-файлам), включая чтение общих таблиц (`af_shop_orders`, `af_aa_slot_grants`).

## Таблица реестра

| # | Аддон (папка) | Файлы | manifest | admin | assets | Зависимости заявленные | Зависимости фактические | Заметки |
|---|---|---|---|---|---|---|---|---|
| 1 | adaptivethemeframework | adaptivethemeframework.php, admin.php, avatar-editor.php, ownership.php, page-shell.php, postbit-signature.php, templates/ | + | + | + | не заявлены | advancedelementtheme, advancedprofilefields, advancedappearance, advancedprofileui, characterworkflow, advancedthreadfields | Ядро презентации; 8 php + templates. Обращается к APF/APUI/AA через адаптеры с `function_exists`-guard. |
| 2 | advancedaccountswitcher | advancedaccountswitcher.php, admin.php, presentation.php, tasks/, templates/ | + | + | + | не заявлены | adaptivethemeframework, advancedmenu, advancedmessenger | advancedmenu.php:1588 — guard `af_advancedmessenger_is_embedded_request()`. |
| 3 | advancedalertsandmentions | advancedalertsandmentions.php, admin.php, advancedalertsandmentionsformatters.php, mentions.php, repo.php, xmlhttp.php, tasks/, templates/ | + | + | + | не заявлены | adaptivethemeframework, advancedmenu | Префикс API — `af_aam_` (совпадает с логом `cache/af_aam_telegram.log`, задача `inc/tasks/af_aam_cleanup.php`). Попаданий af_advancedmessenger_* в коде нет — зависимости от advancedmessenger нет. |
| 4 | advancedappearance | advancedappearance.php, admin.php, personal.php, post_masks.php, postbit_studio.php, slot_entitlements.php, studio.php, surfaces.php, templates/ | + | + | + | не заявлены | adaptivethemeframework, advancedelementtheme, advancedprofilefields, advancededitor, advancedshop, advancedsignaturebuilder, advancedprofileui | Читает `af_aa_slot_grants`, `af_shop_orders` (slot_entitlements.php) — общие таблицы со Shop. |
| 5 | advancedbuddylist | advancedbuddylist.php | + | − | + | не заявлены | нет | Без admin.php и templates/. |
| 6 | advancedcharacters | advancedcharacters.php | + | − | + | не заявлены | knowledgebase | |
| 7 | advancedcombatsystem | advancedcombatsystem.php, admin.php, migrations/, modules/ | + | + | − | не заявлены | knowledgebase (56 вызовов af_kb_*), charactersheets, adaptivethemeframework | Без assets/. Документирован в docs/acs/* (ACS). |
| 8 | advancededitor | advancededitor.php, admin.php, media.php, quote_metadata.php, shell.php, theme_capabilities.php | + | + | + | не заявлены | нет (внешних af_* вызовов не найдено) | Префикс `af_advancededitor_`. |
| 9 | advancedelementtheme | advancedelementtheme.php, admin.php, css.php, effects.php, preferences.php | + | + | + | не заявлены | knowledgebase, advancedprofileui, characterworkflow | Element theme (тип `arpg_element` в KB). |
| 10 | advancedfontawesome | advancedfontawesome.php, admin.php, templates/ | + | + | + | не заявлены | нет | Поставщик иконок; используется другими (advancedrules, advancedmessenger, fakeonline, fastnews). |
| 11 | advancedgallery | advancedgallery.php, admin.php, admin_media.php, avatar.php, maintenance.php, media_api.php, providers.php, storage.php, storage_disk.php, providers/, templates/ | + | + | + | не заявлены | advancedprofilefields, advancededitor | Самый объёмный аддон (11 php). |
| 12 | advancedinventory | advancedinventory.php, admin.php, templates/ | + | + | + | не заявлены | advancedappearance, advancedshop, charactersheets, knowledgebase, characterworkflow | Читает `af_shop_orders` (admin.php) — общая таблица со Shop. |
| 13 | advancedjsbandle | advancedjsbandle.php | + | − | + | не заявлены | нет | **Опечатка в имени папки**: должно быть `advancedjsbundle`. Документация и тесты используют правильное имя: docs/audits/ADVANCED_JS_BUNDLE_FRONTEND_AUDIT.md:1, tests/advancedjsbundle_frontend_permission_regression.php:1. Критичность: high (рассинхрон имени аддона, манифеста, тестов и документации). |
| 14 | advancedmenu | advancedmenu.php, admin.php, admin_editor.php, templates/ | + | + | + | не заявлены | advancedmessenger (1 guard-вызов) | advancedmenu.php:1588 — `function_exists('af_advancedmessenger_is_embedded_request')`. |
| 15 | advancedmessenger | advancedmessenger.php, groups.php, repository.php, view.php, templates/ | + | − | + | не заявлены | advancedappearance, advancedprofileui, advancedfontawesome | Без admin.php. Описан в docs/design/messenger/ADVANCEDMESSENGER.md. |
| 16 | advancedpostcounter | advancedpostcounter.php, templates/ | + | − | + | не заявлены | adaptivethemeframework | Без admin.php. |
| 17 | advancedposteravatar | advancedposteravatar.php | + | − | + | не заявлены | adaptivethemeframework | Без admin.php и templates/. |
| 18 | advancedprofilefields | advancedprofilefields.php, admin.php, postbit-fields.php, templates/ | + | + | + | не заявлены | adaptivethemeframework | Префикс `af_apf_` (secondary avatar API). |
| 19 | advancedprofileui | advancedprofileui.php, admin.php, modules/, templates/ | + | + | + | не заявлены | adaptivethemeframework, advancedelementtheme, advancedprofilefields, advancedappearance, charactersheets, balance, knowledgebase, advancedinventory, characterworkflow, advancedthreadfields | Самый связный аддон: 10 внешних зависимостей (APUI — интегратор профиля). |
| 20 | advancedrules | advancedrules.php, admin.php | + | + | − | не заявлены | adaptivethemeframework, advancedfontawesome | Без assets/ и templates/. |
| 21 | advancedshop | advancedshop.php, admin.php, appearance_slots.php, templates/ | + | + | + | не заявлены | advancedappearance, balance, knowledgebase, advancedinventory | Владелец `af_shop_orders`; appearance_slots.php — мост к Appearance. |
| 22 | advancedsignaturebuilder | advancedsignaturebuilder.php, modules/, templates/ | + | − | + | не заявлены | adaptivethemeframework, advancededitor, advancedappearance | Без admin.php. Префикс `af_asb_`. |
| 23 | advancedstatistic | advancedstatistic.php, templates/ | + | − | + | не заявлены | advancedpostcounter | Без admin.php. |
| 24 | advancedthreadfields | advancedthreadfields.php, admin.php, templates/ | + | + | + | не заявлены | adaptivethemeframework (571 вызов), advancedelementtheme, charactersheets, knowledgebase, characterworkflow | Наибольшая теснота с ATF. |
| 25 | advancedwanted | advancedwanted.php, admin.php | + | + | + | не заявлены | adaptivethemeframework, knowledgebase, advancedthreadfields | Публичный маршрут `wanted.php`. |
| 26 | balance | balance.php, admin.php, templates/ | + | + | + | не заявлены | adaptivethemeframework, charactersheets | `af_balance_get()` используется charactersheets/advancedshop. |
| 27 | charactersheets | charactersheets.php, admin.php, modules/, templates/ | + | + | + | не заявлены | adaptivethemeframework, advancedelementtheme, advancedappearance, balance, knowledgebase (74 вызова), advancedinventory, characterworkflow, advancedthreadfields | Ключевой потребитель KB (Meta JSON canon). |
| 28 | characterworkflow | characterworkflow.php | + | − | − | не заявлены | adaptivethemeframework, advancedwanted, charactersheets, knowledgebase, advancedthreadfields | Минимальный аддон: 1 файл, нет assets/ и templates/. |
| 29 | fakeonline | fakeonline.php, admin.php | + | + | + | не заявлены | advancedfontawesome (125 вызовов) | |
| 30 | fastnews | fastnews.php, fastnews_admin.php | + | −* | − | не заявлены | advancedfontawesome (31 вызов) | *Админ-контроллер назван `fastnews_admin.php` вместо типового `admin.php`; assets/ отсутствует. Нестандартная структура, критичность low. |
| 31 | forcerefresh | forcerefresh.php | + | − | + | не заявлены | нет | Без admin.php и templates/. |
| 32 | headerwelcomeavatar | headerwelcomeavatar.php | + | − | + | не заявлены | нет | Без admin.php и templates/. |
| 33 | indexredirect | indexredirect.php | + | − | − | не заявлены | нет | Минимальный аддон: 1 файл, нет assets/, нет admin. |
| 34 | knowledgebase | knowledgebase.php, admin.php, modules/, templates/ | + | + | + | не заявлены | adaptivethemeframework, advancedelementtheme, advancededitor, charactersheets, advancedthreadfields | Источник канона Meta JSON; публичный маршрут `kb.php`. |
| 35 | smarturltitles | smarturltitles.php, admin.php, templates/ | + | + | + | не заявлены | нет | |

## Уточнение к таблице

В таблице перечислены все **35** папок `addons/` (пронумерованы строки 1–35; advancedmessenger — строка 15, продублированной строки в таблице нет, служебная строка 36 из черновика удалена). Алфавитный список: adaptivethemeframework, advancedaccountswitcher, advancedalertsandmentions, advancedappearance, advancedbuddylist, advancedcharacters, advancedcombatsystem, advancededitor, advancedelementtheme, advancedfontawesome, advancedgallery, advancedinventory, **advancedjsbandle**, advancedmenu, advancedmessenger, advancedpostcounter, advancedposteravatar, advancedprofilefields, advancedprofileui, advancedrules, advancedshop, advancedsignaturebuilder, advancedstatistic, advancedthreadfields, advancedwanted, balance, charactersheets, characterworkflow, fakeonline, fastnews, forcerefresh, headerwelcomeavatar, indexredirect, knowledgebase, smarturltitles. Ни одна папка не пропущена. Расхождение с цифрой «37» из постановки зафиксировано в шапке.

## Сводные замечания

- **high** — Опечатка `advancedjsbandle` (advancedjsbandle/manifest.php:3): имя аддона в коде и манифесте не совпадает с именем в тестах (`tests/advancedjsbundle_frontend_permission_regression.php`) и документации (`docs/audits/ADVANCED_JS_BUNDLE_FRONTEND_AUDIT.md`).
- **medium** — Зависимости между аддонами не заявлены в манифестах ни для одного из 37 аддонов (поле отсутствует), при этом фактические перекрёстные вызовы есть у ~30 аддонов; единственная защита — `function_exists`-guard (например, advancedmenu/advancedmenu.php:1588). Риск загрузки в неверном порядке.
- **medium** — Общие таблицы БД без единого владельца в манифестах: `af_shop_orders` (advancedshop, advancedappearance/slot_entitlements.php:1, advancedinventory/admin.php), `af_aa_slot_grants` (advancedappearance).
- **low** — Нестандартные структуры: `fastnews/fastnews_admin.php` вместо `admin.php` и отсутствие assets/; у 12 аддонов нет admin.php (advancedbuddylist, advancedcharacters, advancedjsbandle, advancedmessenger, advancedpostcounter, advancedposteravatar, advancedsignaturebuilder, advancedstatistic, characterworkflow, forcerefresh, headerwelcomeavatar, indexredirect).
- **low** — У 3 аддонов нет assets/: advancedcombatsystem, advancedrules, indexredirect (у characterworkflow нет и assets, и templates).
- **info** — Префикс `af_aam_` принадлежит advancedalertsandmentions (не advancedmessenger); совпадение с именем лога `cache/af_aam_telegram.log` подтверждается задачей inc/tasks/af_aam_cleanup.php:1.

Метод: grep по префиксам API `af_<id>_`, `af_kb_`, `af_aa_`, `af_atf_`, `af_aam_`, `af_asb_`, `af_apf_`, `af_apui_`, `af_balance_`, `af_acs_` во всех php-файлах аддонов; подсчёт вхождений без учёта собственных вызовов.
