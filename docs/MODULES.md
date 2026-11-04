# Модули AdvancedFunctionality

**Дата актуальности:** 2026-11-04.  
**Источники:** [ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md), [ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), для эталонов — [ANATOMY_OF_ADDON](design/core/ANATOMY_OF_ADDON.md).

В репозитории подтверждено **35 аддонов**, а не 37 (`docs/audits/ADDONS_REGISTRY.md:3-4`). Статический аудит завершён для всех 35: 31 неэталонного аддона и четырёх эталонных (`docs/audits/ADDONS_AUDIT_2026.md:1-5`, `docs/audits/ADDONS_AUDIT_2026.md:7-261`). Среди неэталонных **6** имеют статус `ok`, **25** — `tech-debt`; назначения `legacy` в источниках не найдено.

Статусы: **эталон** — архитектурная роль по `ANATOMY_OF_ADDON`, а не отсутствие техдолга; **tech-debt/ok** — итоговая классификация аудита. Эталонные `advancedmenu` и `charactersheets` также имеют подтверждённый техдолг. Зависимости ниже фактические, а не декларированные: общего поля зависимостей в manifest не найдено (`docs/audits/ADDONS_REGISTRY.md:6-10`). Динамические проверки Enable/Disable, HTTP и PHP lint не выполнялись (`docs/audits/ADDONS_AUDIT_2026.md:5`).

## 1. Сводная таблица

| # | Аддон | Назначение | Статус | Зависимости |
|---:|---|---|---|---|
| 1 | `adaptivethemeframework` | Ядро презентации (`docs/audits/ADDONS_REGISTRY.md:16`) | эталон | advancedelementtheme, advancedprofilefields, advancedappearance, advancedprofileui, characterworkflow, advancedthreadfields |
| 2 | `advancedaccountswitcher` | Переключение учётных записей; standalone `userlist.php` (`inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/advancedaccountswitcher.php:357-367`) | tech-debt | adaptivethemeframework, advancedmenu, advancedmessenger |
| 3 | `advancedalertsandmentions` | Уведомления и упоминания; префикс API `af_aam_` (`docs/audits/ADDONS_REGISTRY.md:18`) | tech-debt | adaptivethemeframework, advancedmenu |
| 4 | `advancedappearance` | Appearance Studio и настройка внешнего вида (`docs/audits/ADDONS_REGISTRY.md:19`; `inc/plugins/advancedfunctionality/addons/advancedappearance/studio.php:51-57`) | tech-debt | adaptivethemeframework, advancedelementtheme, advancedprofilefields, advancededitor, advancedshop, advancedsignaturebuilder, advancedprofileui |
| 5 | `advancedbuddylist` | Список друзей/игнорируемых на данных MyBB (`inc/plugins/advancedfunctionality/addons/advancedbuddylist/advancedbuddylist.php:131-134`) | tech-debt | не найдено |
| 6 | `advancedcharacters` | Каталог и карточки персонажей (`inc/plugins/advancedfunctionality/addons/advancedcharacters/manifest.php:9-43`) | tech-debt | knowledgebase |
| 7 | `advancedcombatsystem` | Боевая система и интеграционные адаптеры (`inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Infrastructure/Adapters/CharacterSheetAdapter.php:21`) | tech-debt | knowledgebase, charactersheets, adaptivethemeframework |
| 8 | `advancededitor` | Редактор, медиа и sticker pack (`docs/audits/ADDONS_REGISTRY.md:23`; `inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php:1657-1659`) | tech-debt | не найдено |
| 9 | `advancedelementtheme` | Темы элементов KB (`docs/audits/ADDONS_REGISTRY.md:24`) | tech-debt | knowledgebase, advancedprofileui, characterworkflow |
| 10 | `advancedfontawesome` | Поставщик иконок (`docs/audits/ADDONS_REGISTRY.md:25`) | tech-debt | не найдено |
| 11 | `advancedgallery` | Галерея, альбомы и media API (`docs/audits/ADDONS_REGISTRY.md:26`) | tech-debt | advancedprofilefields, advancededitor |
| 12 | `advancedinventory` | Инвентарь и выдача предметов (`inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:2511`) | tech-debt | advancedappearance, advancedshop, charactersheets, knowledgebase, characterworkflow |
| 13 | `advancedjsbandle` | JS bundle; имя папки зафиксировано с фактической опечаткой (`docs/audits/ADDONS_REGISTRY.md:28`) | tech-debt | не найдено |
| 14 | `advancedmenu` | Навигация и меню (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:2077-2122`) | эталон, tech-debt | advancedmessenger |
| 15 | `advancedmessenger` | Личные сообщения (`docs/design/messenger/ADVANCEDMESSENGER.md`; реестр: `docs/audits/ADDONS_REGISTRY.md:30`) | ok | advancedappearance, advancedprofileui, advancedfontawesome |
| 16 | `advancedpostcounter` | Счётчик сообщений (`docs/audits/ADDONS_REGISTRY.md:31`) | tech-debt | adaptivethemeframework |
| 17 | `advancedposteravatar` | Аватар автора сообщения (`docs/audits/ADDONS_REGISTRY.md:32`) | tech-debt | adaptivethemeframework |
| 18 | `advancedprofilefields` | Поля профиля и secondary avatar API (`docs/audits/ADDONS_REGISTRY.md:33`) | tech-debt | adaptivethemeframework |
| 19 | `advancedprofileui` | Интегратор интерфейса профиля (`docs/audits/ADDONS_REGISTRY.md:34`) | tech-debt | adaptivethemeframework, advancedelementtheme, advancedprofilefields, advancedappearance, charactersheets, balance, knowledgebase, advancedinventory, characterworkflow, advancedthreadfields |
| 20 | `advancedrules` | Правила форума (`docs/audits/ADDONS_REGISTRY.md:35`) | ok | adaptivethemeframework, advancedfontawesome |
| 21 | `advancedshop` | Магазин; владелец `af_shop_orders` (`docs/audits/ADDONS_REGISTRY.md:36`) | tech-debt | advancedappearance, balance, knowledgebase, advancedinventory |
| 22 | `advancedsignaturebuilder` | Конструктор подписей; префикс `af_asb_` (`docs/audits/ADDONS_REGISTRY.md:37`) | ok | adaptivethemeframework, advancededitor, advancedappearance |
| 23 | `advancedstatistic` | Статистика (`docs/audits/ADDONS_REGISTRY.md:38`) | ok | advancedpostcounter |
| 24 | `advancedthreadfields` | Поля тем и интеграция с презентацией (`docs/audits/ADDONS_REGISTRY.md:39`) | tech-debt | adaptivethemeframework, advancedelementtheme, charactersheets, knowledgebase, characterworkflow |
| 25 | `advancedwanted` | Wanted-заявки; маршрут `wanted.php` (`docs/audits/ADDONS_REGISTRY.md:40`) | tech-debt | adaptivethemeframework, knowledgebase, advancedthreadfields |
| 26 | `balance` | Баланс; API `af_balance_get()` (`docs/audits/ADDONS_REGISTRY.md:41`) | tech-debt | adaptivethemeframework, charactersheets |
| 27 | `charactersheets` | Листы персонажей и потребление KB Meta JSON (`docs/audits/ADDONS_REGISTRY.md:42`) | эталон, tech-debt | adaptivethemeframework, advancedelementtheme, advancedappearance, balance, knowledgebase, advancedinventory, characterworkflow, advancedthreadfields |
| 28 | `characterworkflow` | Workflow персонажей (`docs/audits/ADDONS_REGISTRY.md:43`) | ok | adaptivethemeframework, advancedwanted, charactersheets, knowledgebase, advancedthreadfields |
| 29 | `fakeonline` | Отображение имитируемого присутствия (`docs/audits/ADDONS_REGISTRY.md:44`) | tech-debt | не найдено |
| 30 | `fastnews` | Новости; нестандартный `fastnews_admin.php` (`docs/audits/ADDONS_REGISTRY.md:45`) | ok | advancedfontawesome |
| 31 | `forcerefresh` | Принудительное обновление; отдельный JS asset (`docs/audits/ADDONS_REGISTRY.md:46`) | tech-debt | не найдено |
| 32 | `headerwelcomeavatar` | Аватар в приветствии шапки (`docs/audits/ADDONS_REGISTRY.md:47`) | tech-debt | advancedmenu |
| 33 | `indexredirect` | Перенаправление главной страницы (`docs/audits/ADDONS_REGISTRY.md:48`) | tech-debt | не найдено |
| 34 | `knowledgebase` | База знаний и источник канона Meta JSON (`docs/audits/ADDONS_REGISTRY.md:49`) | эталон, ok | adaptivethemeframework, advancedelementtheme, advancededitor, charactersheets, advancedthreadfields |
| 35 | `smarturltitles` | URL-заголовки (`docs/audits/ADDONS_REGISTRY.md:50`) | tech-debt | не найдено |

Зависимости строк 1–35 основаны на реестре (`docs/audits/ADDONS_REGISTRY.md:14-50`) и уточнены итоговым аудитом: для FakeOnline вызовы FontAwesome не найдены, для HeaderWelcomeAvatar подтверждён optional AdvancedMenu (`docs/audits/ADDONS_AUDIT_2026.md:212`, `docs/audits/ADDONS_AUDIT_2026.md:233`). Связи кода не гарантируют ни обязательность, ни порядок загрузки; конкретные hard/soft зависимости уточнены ниже там, где это подтверждает аудит.

## 2. Эталонные аддоны

### 2.1. `advancedmenu`

Назначение — меню и frontend-навигация; bootstrap сам обеспечивает установку через `ensure_installed()` из `init()` (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:357-385`, `inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:2077-2091`).

- **Lifecycle:** `ensure_installed()` создаёт или восстанавливает БД, настройки и шаблоны, сбрасывает кэш; static guard предотвращает повторную инициализацию (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:357-385`).
- **Идемпотентность:** мягко добавляет отсутствующие поля и устраняет дубликаты setting groups (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:388-430`, `inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:468-508`).
- **Frontend:** глобальный runtime объявлен manifest (`inc/plugins/advancedfunctionality/addons/advancedmenu/manifest.php:14-16`); pre-output проверяет marker, permission и enabled setting (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:2096-2122`).
- **ACP:** контроллер `AF_Admin_Advancedmenu`, URL через `af_view`, собственные операции — через `do` (`inc/plugins/advancedfunctionality/addons/advancedmenu/admin.php:22`, `inc/plugins/advancedfunctionality/addons/advancedmenu/admin.php:28-46`).
- **Зависимости:** вызов AdvancedMessenger защищён `function_exists` (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:1588`).
- **Не найдено:** отдельные `install/uninstall/activate/deactivate` и политика удаления данных (`docs/design/core/ANATOMY_OF_ADDON.md:86-91`).
- **medium — итог аудита:** language manifest использует нестандартные `lang.front/admin` вместо RU/EN; архитектурный статус «эталон» не отменяет этот техдолг (`inc/plugins/advancedfunctionality/addons/advancedmenu/manifest.php:14-38`).

### 2.2. `adaptivethemeframework` (ATF)

Назначение — ядро презентации (`docs/audits/ADDONS_REGISTRY.md:16`). ATF имеет специальную ветку Enable: ядро требует `is_installed()`, при необходимости `install()` и обязательный `activate(): true` (`inc/plugins/advancedfunctionality.php:1212-1233`).

- **Lifecycle:** `install()` обеспечивает settings/schema readiness, `activate()` захватывает templates (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:2659-2677`).
- **Disable/uninstall:** освобождают templates, но ledger сохраняется (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:2686-2695`).
- **Hooks:** явные приоритеты, ранние preload и поздние compose; группы хуков ограничены request context (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:199-277`).
- **Manifest:** ACP, global frontend и theme stylesheets объявлены явно (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/manifest.php:12-54`).
- **ACP:** `AF_Admin_Adaptivethemeframework`; mutation валидируется, обновляется и завершается redirect (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/admin.php:5-43`).
- **Зависимости:** интеграции с APF/APUI/Appearance выполняются через адаптеры с `function_exists`-guard (`docs/audits/ADDONS_REGISTRY.md:16`).
- **Контракты:** [ATF_PAGE_SHELL_CONTRACT](contracts/ATF_PAGE_SHELL_CONTRACT.md), [ATF_TEMPLATE_COMPATIBILITY_CONTRACT](contracts/ATF_TEMPLATE_COMPATIBILITY_CONTRACT.md), [ATF_GLOBAL_MODAL_CONTRACT](contracts/ATF_GLOBAL_MODAL_CONTRACT.md).

### 2.3. `knowledgebase` (KB)

Назначение — база знаний и источник канона Meta JSON (`docs/audits/ADDONS_REGISTRY.md:49`).

- **Lifecycle:** `install()` создаёт/мигрирует schema и связанные объекты; `activate()` дополнительно обеспечивает settings, templates, seed, alias и task (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1539-1567`, `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1859-1903`).
- **Disable/uninstall:** deactivate удаляет owned alias; uninstall удаляет settings, templates, task и alias, но сохраняет таблицы и записи (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1837-1856`, `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1906-1909`).
- **Hooks:** `misc_start`, `pre_output_page`, `parse_message_end` (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:9314-9321`).
- **Standalone page:** `assets/kb.php` копируется в `MYBB_ROOT`, ownership проверяется marker; при недоступном alias используется `misc.php?action=kb` (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1912-1988`).
- **Manifest:** contextual routes, response-aware delivery, ACP и RU/EN (`inc/plugins/advancedfunctionality/addons/knowledgebase/manifest.php:10-68`).
- **Контракты:** [ARPG_INTEGRATION_CONTRACT](contracts/ARPG_INTEGRATION_CONTRACT.md), [ACS_KB_NORMALIZATION_CONTRACT](contracts/ACS_KB_NORMALIZATION_CONTRACT.md), [ACS_KB_MECHANICS_CONTRACT](contracts/ACS_KB_MECHANICS_CONTRACT.md), [ACS_KB_FORMULA_CONTRACT](contracts/ACS_KB_FORMULA_CONTRACT.md).

### 2.4. `charactersheets` (CS)

Назначение — листы персонажей и ключевой потребитель KB Meta JSON (`docs/audits/ADDONS_REGISTRY.md:42`).

- **Bootstrap:** публичные `af_charactersheets_*` — тонкие делегаты в `modules/bootstrap.php` (`inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php:97-125`).
- **Lifecycle:** install обеспечивает schema/settings/templates/alias; activate — templates/schema/alias (`inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:26-45`).
- **Disable/uninstall:** deactivate удаляет owned alias; uninstall дополнительно удаляет settings/templates; DROP таблиц не найден (`inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:48-93`).
- **Hooks:** thread/postbit, pre-output, misc и moderation hooks (`inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php:127-137`).
- **Standalone page:** owned alias с marker-проверкой и безопасным удалением (`inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:197-228`).
- **Manifest:** contextual runtime, response rule, embedded resource и RU/EN (`inc/plugins/advancedfunctionality/addons/charactersheets/manifest.php:16-52`).
- **Контракт:** [CHARACTER_ABILITY_EFFECTS_CONTRACT](contracts/CHARACTER_ABILITY_EFFECTS_CONTRACT.md).
- **high — итог аудита:** equipment AJAX после guarded чтения напрямую вызывает `af_inv_map_legacy_support_slot()` и `af_inv_support_slots()`, поэтому Disable Inventory оставляет путь к undefined function (`inc/plugins/advancedfunctionality/addons/charactersheets/modules/ajax.php:776-807`, `inc/plugins/advancedfunctionality/addons/charactersheets/modules/ajax.php:843-847`).

## 3. Аддоны со статусом `tech-debt`

### 3.1. `advancedaccountswitcher`

- **blocker:** Disable удаляет template placeholder, но оставляет `userlist.php`; его seed вызывает `af_aas_render_account_list_page()`, которая после Disable не загружается (`inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/advancedaccountswitcher.php:357-367`, `inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/assets/userlist.php:24`).
- **Положительно:** install идемпотентно проверяет таблицы/поле; просмотренные POST-маршруты проверяют post key (`inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/advancedaccountswitcher.php:205-305`, `inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/advancedaccountswitcher.php:961`).
- **Зависимости:** ATF navigation имеет fallback, AAM alert — `function_exists`; при отключении AAM теряются дополнительные уведомления (`inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/advancedaccountswitcher.php:54-59`, `inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/advancedaccountswitcher.php:1695-1696`).

### 3.2. `advancedalertsandmentions`

- **medium:** installer перезаписывает любой отличающийся `inc/tasks/af_aam_cleanup.php` без ownership marker (`inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php:312-332`).
- **Положительно:** install создаёт/мигрирует schema и устанавливает task; deactivate сохраняет данные; ACP mutations проверяют post key (`inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php:335-380`, `inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php:824-840`, `inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/admin.php:36-52`).
- **Зависимости:** отключение ATF оставляет pending navigation fallback; AccountSwitcher проверяет наличие `af_aam_add_alert` (`inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php:57-67`, `inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/advancedaccountswitcher.php:1695-1696`).

### 3.3. `advancedappearance`

- **medium:** uninstall удаляет presets/assignments и slot grants — пользовательские данные; это отдельная операция, не Disable (`inc/plugins/advancedfunctionality/addons/advancedappearance/advancedappearance.php:104-126`).
- **Положительно:** install охватывает schema/settings/templates/aliases, Disable удаляет aliases; frontend mutations проверяют CSRF (`inc/plugins/advancedfunctionality/addons/advancedappearance/advancedappearance.php:64-102`, `inc/plugins/advancedfunctionality/addons/advancedappearance/advancedappearance.php:4659-4667`).
- **Зависимости:** Studio имеет hard dependency на ATF и ElementTheme и возвращает 503 при их недоступности; Inventory вызовы защищены `function_exists` (`inc/plugins/advancedfunctionality/addons/advancedappearance/studio.php:51-57`, `inc/plugins/advancedfunctionality/addons/advancedappearance/studio.php:127-130`, `inc/plugins/advancedfunctionality/addons/advancedappearance/advancedappearance.php:560-579`).

### 3.4. `advancedbuddylist`

- **blocker:** Disable ничего не делает и оставляет `buddy.php`; seed вызывает функцию из незагруженного bootstrap (`inc/plugins/advancedfunctionality/addons/advancedbuddylist/advancedbuddylist.php:28`, `inc/plugins/advancedfunctionality/addons/advancedbuddylist/assets/buddy.php:17`).
- **Положительно:** install/remove alias ownership-safe и идемпотентны (`inc/plugins/advancedfunctionality/addons/advancedbuddylist/advancedbuddylist.php:54-118`).
- **Зависимости:** внешних аддонных зависимостей не найдено (`docs/audits/ADDONS_AUDIT_2026.md:30-35`).

### 3.5. `advancedcharacters`

- **medium:** функциональная зависимость от KB: после её отключения `characters.php` остаётся установленным, но показывает «KnowledgeBase is unavailable»; fatal предотвращён `function_exists` (`inc/plugins/advancedfunctionality/addons/advancedcharacters/advancedcharacters.php:105-112`).
- **Положительно:** install/Disable синхронизируют owned alias с marker-проверкой; asset insertion проходит permission API и dedup (`inc/plugins/advancedfunctionality/addons/advancedcharacters/advancedcharacters.php:7-11`, `inc/plugins/advancedfunctionality/addons/advancedcharacters/advancedcharacters.php:84-103`, `inc/plugins/advancedfunctionality/addons/advancedcharacters/advancedcharacters.php:37-51`).

### 3.6. `advancedcombatsystem`

- **high:** боевые адаптеры напрямую вызывают KB/CharacterSheets; отключение владельца делает source/snapshot недоступным и требует capability-блокировки (`inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Infrastructure/Adapters/CharacterSheetAdapter.php:21`, `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php:14-28`).
- **high:** ATF thread component регистрируется напрямую и требует включённый ATF (`inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Presentation/ThreadComponents.php:6`).
- **Положительно:** миграции resumable, Disable сохраняет данные, uninstall требует подтверждение; lifecycle защищён ACP permission и CSRF (`inc/plugins/advancedfunctionality/addons/advancedcombatsystem/advancedcombatsystem.php:45-107`).
- **Особенность:** использует специальный lifecycle ядра, который нельзя копировать как общий паттерн (`inc/plugins/advancedfunctionality.php:1205-1211`).
- **Stage 4 Revision 2:** публикация переведена на ACS-only транзакцию и координируемый MyBB lifecycle без миграции форумных таблиц; backend fixtures пройдены, но installed-приёмка не выполнена и `publication_enabled=false` (`docs/audits/ACS_STAGE_4_REVISION_2_AUDIT.md:9-22`, `docs/design/acs/ACS_4_5_R2_INTEGRATION_ACCEPTANCE.md:84-102`). Основание: [Integration Contracts](contracts/ACS_INTEGRATION_CONTRACTS.md), [Implementation Roadmap](design/acs/ACS_IMPLEMENTATION_ROADMAP.md), [аудит R2](audits/ACS_STAGE_4_REVISION_2_AUDIT.md).

### 3.7. `advancededitor`

- **medium:** нестандартные `lang.front/admin` вместо canonical RU/EN `front/admin`, поэтому генератору ядра нечего синхронизировать (`inc/plugins/advancedfunctionality/addons/advancededitor/manifest.php:36-54`, `inc/plugins/advancedfunctionality.php:2206-2239`).
- **medium — потребители:** Appearance и SignatureBuilder используют font API; после Disable AE custom fonts недоступны, вызовы защищены условиями (`inc/plugins/advancedfunctionality/addons/advancedappearance/personal.php:25`, `inc/plugins/advancedfunctionality/addons/advancedsignaturebuilder/modules/assets.php:25`).
- **Положительно:** install идемпотентен для таблицы/settings; uninstall отдельный; standalone PHP после Disable не остаётся (`inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php:1397-1420`, `inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php:1674-1726`).

### 3.8. `advancedelementtheme`

- **medium:** KB API загружается даже при выключенной KB; без неё каталог элементов пуст (`inc/plugins/advancedfunctionality/addons/advancedelementtheme/advancedelementtheme.php:55-85`).
- **medium — потребители:** Appearance Studio требует ElementTheme; отключение CharacterWorkflow/APUI убирает approved identity для profile/postbit/sheet (`inc/plugins/advancedfunctionality/addons/advancedappearance/studio.php:51-57`, `inc/plugins/advancedfunctionality/addons/advancedelementtheme/advancedelementtheme.php:103-125`).
- **Положительно:** schema проверяется перед созданием, metadata сохраняется; injection проходит permission API (`inc/plugins/advancedfunctionality/addons/advancedelementtheme/advancedelementtheme.php:16-46`, `inc/plugins/advancedfunctionality/addons/advancedelementtheme/advancedelementtheme.php:342-347`).

### 3.9. `advancedfontawesome`

- **medium:** нестандартные `lang.front/admin` вместо RU/EN (`inc/plugins/advancedfunctionality/addons/advancedfontawesome/manifest.php:16-56`, `inc/plugins/advancedfunctionality.php:2206-2239`).
- **medium:** uninstall удаляет `forums.af_fa_icon`, то есть настроенные иконки (`inc/plugins/advancedfunctionality/addons/advancedfontawesome/advancedfontawesome.php:91-104`).
- **Положительно:** повторный install проверяет поле; Disable удаляет только headerinclude-интеграцию; ACP mutation проверяет post key (`inc/plugins/advancedfunctionality/addons/advancedfontawesome/advancedfontawesome.php:72-88`, `inc/plugins/advancedfunctionality/addons/advancedfontawesome/advancedfontawesome.php:112-115`, `inc/plugins/advancedfunctionality/addons/advancedfontawesome/admin.php:31-40`).
- **Потребители:** AdvancedRules, AdvancedMessenger, FakeOnline и FastNews (`docs/audits/ADDONS_REGISTRY.md:25`).

### 3.10. `advancedgallery`

- **blocker:** Disable ничего не делает и оставляет `gallery.php`; seed вызывает функцию отключённого bootstrap (`inc/plugins/advancedfunctionality/addons/advancedgallery/advancedgallery.php:650-653`, `inc/plugins/advancedfunctionality/addons/advancedgallery/assets/gallery.php:10`).
- **medium:** сохранён assets blacklist, хотя контракт запрещает новую blacklist-модель (`inc/plugins/advancedfunctionality/addons/advancedgallery/manifest.php:114-115`, `docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:103-108`).
- **Данные:** uninstall удаляет пользовательские media/albums/logs отдельно от Disable (`inc/plugins/advancedfunctionality/addons/advancedgallery/advancedgallery.php:566-636`).
- **Положительно:** install повторно безопасен по schema/settings; upload/mutation routes проверяют CSRF; вызов AE response facts защищён `function_exists` (`inc/plugins/advancedfunctionality/addons/advancedgallery/advancedgallery.php:245-287`, `inc/plugins/advancedfunctionality/addons/advancedgallery/advancedgallery.php:551-563`, `inc/plugins/advancedfunctionality/addons/advancedgallery/advancedgallery.php:1489`, `inc/plugins/advancedfunctionality/addons/advancedgallery/advancedgallery.php:695-703`).

### 3.11. `advancedinventory`

- **high — потребитель Shop:** Shop вызывает `af_inv_add_item()` и mapping Inventory; при выключенном Inventory checkout должен блокироваться, иначе grant невозможен (`inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:2511`, `inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:2648`).
- **medium:** bootstrap выполняет DB migration сразу при include, включая Disable, а не только в install (`inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:18-57`).
- **medium:** legacy assets blacklist остаётся в settings (`inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:83`).
- **Положительно:** Disable удаляет только owned aliases; install повторно мигрирует schema/settings; mutations проверяют post key (`inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:64-108`, `inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:242-249`, `inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:1689`).
- **Зависимости:** optional Shop/Appearance/Workflow вызовы защищены `function_exists` (`inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:2049-2099`, `inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:1050-1075`).

### 3.12. `advancedjsbandle`

- **medium:** нестандартные `lang.front/admin` вместо canonical RU/EN (`inc/plugins/advancedfunctionality/addons/advancedjsbandle/manifest.php:67-78`).
- **low:** версия bootstrap `1.0.2` отстаёт от manifest `1.0.3`; общие имена функций создают риск коллизий (`inc/plugins/advancedfunctionality/addons/advancedjsbandle/manifest.php:6`, `inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php:11`, `inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php:53-77`).
- **Положительно:** Enable/Disable идемпотентно синхронизируют owned marker; asset permission API используется (`inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php:19-40`, `inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php:153-169`, `inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php:253`).

### 3.13. `advancedpostcounter`

- **high:** административный `postbit_label_html` выводится как HTML после неполной substring-фильтрации; опасная разметка и event handlers не исключены (`inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php:1796-1799`, `inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php:1815-1827`, `inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php:1837-1842`).
- **blocker:** Disable не удаляет `postsactivity.php` и `postsbyuser.php`; deactivate не найден, aliases удаляются только uninstall (`inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php:32-48`, `inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php:65-71`, `inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php:1524-1594`).
- **medium:** сохранена legacy blacklist-модель assets (`inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php:187-220`).
- **Зависимости:** ATF имеет legacy fallback; AdvancedStatistic теряет источник счётчиков после Disable APC (`inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php:1041-1050`, `inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php:947-988`).

### 3.14. `advancedposteravatar`

- **medium:** deactivate не найден, Disable оставляет template markers; их удаление выполняет только uninstall (`inc/plugins/advancedfunctionality/addons/advancedposteravatar/advancedposteravatar.php:65-74`, `inc/plugins/advancedfunctionality/addons/advancedposteravatar/advancedposteravatar.php:620-630`, `inc/plugins/advancedfunctionality/addons/advancedposteravatar/advancedposteravatar.php:708-725`).
- **Положительно:** повторный install не дублирует settings/markers; ATF optional и имеет legacy renderer (`inc/plugins/advancedfunctionality/addons/advancedposteravatar/advancedposteravatar.php:88-113`, `inc/plugins/advancedfunctionality/addons/advancedposteravatar/advancedposteravatar.php:282-303`).

### 3.15. `advancedprofilefields`

- **medium:** нестандартные `lang.front/admin` и legacy assets blacklist (`inc/plugins/advancedfunctionality/addons/advancedprofilefields/manifest.php:27-55`, `inc/plugins/advancedfunctionality/addons/advancedprofilefields/advancedprofilefields.php:275-288`).
- **medium:** uninstall удаляет таблицу пользовательских значений (`inc/plugins/advancedfunctionality/addons/advancedprofilefields/advancedprofilefields.php:299-319`).
- **Положительно:** install идемпотентен, Disable откатывает patches без удаления значений; upload/ACP mutations проверяют post key (`inc/plugins/advancedfunctionality/addons/advancedprofilefields/advancedprofilefields.php:232-297`, `inc/plugins/advancedfunctionality/addons/advancedprofilefields/advancedprofilefields.php:324-340`, `inc/plugins/advancedfunctionality/addons/advancedprofilefields/advancedprofilefields.php:1135-1141`).
- **Зависимости:** ATF/APUI/Appearance/Messenger интеграции guarded, Disable владельца убирает avatar без прямого fatal (`inc/plugins/advancedfunctionality/addons/advancedprofilefields/advancedprofilefields.php:1240-1246`, `inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php:631-635`).

### 3.16. `advancedprofileui`

- **medium:** manifest объявляет `apstudio.php`, но seed/installer этой страницы не найден (`inc/plugins/advancedfunctionality/addons/advancedprofileui/manifest.php:4-34`, `inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php:26-40`).
- **medium:** uninstall удаляет backup table (`inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php:61-75`).
- **Положительно:** install повторно обеспечивает schema/settings/overrides, Disable восстанавливает шаблоны; ACP mutation проверяет post key (`inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php:26-59`, `inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php:226-242`, `inc/plugins/advancedfunctionality/addons/advancedprofileui/admin.php:97-103`).
- **Зависимости:** ATF/Balance/KB/APF/Appearance/CharacterSheets/Inventory вызовы guarded (`inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php:397-399`, `inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php:1277-1286`).

### 3.17. `advancedshop`

- **high:** Shop создаёт и мигрирует чужую `af_aa_active`, включая runtime init вне Enable (`inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:605-608`, `inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:667-704`, `inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:1061-1064`).
- **high:** Shop напрямую загружает bootstrap выключенного Inventory и продолжает grant вместо блокировки checkout (`inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:2598-2605`, `inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:2863-2880`).
- **medium:** сохранён legacy assets blacklist (`inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:984-993`).
- **Положительно:** Disable удаляет owned aliases; schema повторно безопасна; frontend/ACP mutations проверяют post key (`inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:995-1071`, `inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:1115-1122`, `inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:1415-1420`).

### 3.18. `advancedthreadfields`

- **medium:** RU/EN помещены в нестандартные `lang.front/admin` с суффиксами ключей (`inc/plugins/advancedfunctionality/addons/advancedthreadfields/manifest.php:20-52`, `inc/plugins/advancedfunctionality/addons/advancedthreadfields/manifest.php:74-79`).
- **high — потребитель Wanted:** ACP Wanted напрямую подключает bootstrap ThreadFields независимо от enabled state и обходит Disable (`inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php:324-340`, `inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php:390-395`).
- **Положительно:** install проверяет таблицы, Disable откатывает template edits и сохраняет значения; ACP mutations защищены post key (`inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php:150-160`, `inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php:266-296`, `inc/plugins/advancedfunctionality/addons/advancedthreadfields/admin.php:308-312`).

### 3.19. `advancedwanted`

- **blocker:** deactivate не найден, `wanted.php` удаляется только uninstall; после Disable остаётся seed без bootstrap (`inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php:9-17`, `inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php:35-59`).
- **high:** ThreadFields bootstrap загружается напрямую независимо от его enabled state (`inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php:324-340`).
- **Положительно:** install/schema повторно безопасны, frontend/ACP mutations проверяют post key; KB resolvers guarded (`inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php:46-81`, `inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php:435-438`, `inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php:172-200`).

### 3.20. `balance`

- **blocker:** Disable только возвращает true и оставляет `balancemanage.php`; owned alias удаляется лишь uninstall (`inc/plugins/advancedfunctionality/addons/balance/balance.php:15-18`, `inc/plugins/advancedfunctionality/addons/balance/balance.php:51-84`).
- **medium:** legacy assets blacklist присутствует в настройках (`inc/plugins/advancedfunctionality/addons/balance/manifest.php:123-124`).
- **Положительно:** install повторно создаёт отсутствующие таблицы и owned alias; AJAX mutation проверяет post code (`inc/plugins/advancedfunctionality/addons/balance/balance.php:172-195`, `inc/plugins/advancedfunctionality/addons/balance/balance.php:1673-1679`).
- **Зависимости:** CharacterSheets schema читается с `table_exists`; Shop/APUI используют guards (`inc/plugins/advancedfunctionality/addons/balance/balance.php:1923-1929`, `inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:4212-4218`).

### 3.21. `fakeonline`

- **medium:** нестандартные `lang.front/admin`, frontend в manifest не найден (`inc/plugins/advancedfunctionality/addons/fakeonline/manifest.php:20-57`).
- **medium:** uninstall удаляет state table (`inc/plugins/advancedfunctionality/addons/fakeonline/fakeonline.php:91-107`).
- **Положительно:** task deploy/remove синхронизированы с Enable/Disable; ACP POST использует `check_post_check` при наличии функции (`inc/plugins/advancedfunctionality/addons/fakeonline/fakeonline.php:59-83`, `inc/plugins/advancedfunctionality/addons/fakeonline/fakeonline.php:110-128`, `inc/plugins/advancedfunctionality/addons/fakeonline/admin.php:28-34`).

### 3.22. `forcerefresh`

- **medium:** языки заданы как `ru/en` вместо `russian/english.front/admin`; сохранён legacy assets blacklist (`inc/plugins/advancedfunctionality/addons/forcerefresh/manifest.php:23-55`, `inc/plugins/advancedfunctionality/addons/forcerefresh/forcerefresh.php:164-191`).
- **Положительно:** lifecycle ограничен settings; assets проходят permission API; внешних зависимостей не найдено (`inc/plugins/advancedfunctionality/addons/forcerefresh/forcerefresh.php:13-30`, `inc/plugins/advancedfunctionality/addons/forcerefresh/forcerefresh.php:38-61`).

### 3.23. `headerwelcomeavatar`

- **medium:** плоский нестандартный `lang` вместо RU/EN front/admin (`inc/plugins/advancedfunctionality/addons/headerwelcomeavatar/manifest.php:15-36`).
- **Положительно:** lifecycle ограничен settings; assets проходят permission API; AdvancedMenu имеет fallback (`inc/plugins/advancedfunctionality/addons/headerwelcomeavatar/headerwelcomeavatar.php:11-109`, `inc/plugins/advancedfunctionality/addons/headerwelcomeavatar/headerwelcomeavatar.php:515-524`).

### 3.24. `indexredirect`

- **medium:** нестандартные `lang.front/admin`; точное сравнение только с `/index.php` не поддерживает заявленный `/forum/index.php` (`inc/plugins/advancedfunctionality/addons/indexredirect/manifest.php:14-26`, `inc/plugins/advancedfunctionality/addons/indexredirect/indexredirect.php:59-67`).
- **Положительно:** redirect ограничен GET/HEAD и исключает ACP; lifecycle ограничен settings (`inc/plugins/advancedfunctionality/addons/indexredirect/indexredirect.php:17-25`, `inc/plugins/advancedfunctionality/addons/indexredirect/indexredirect.php:41-50`).

### 3.25. `smarturltitles`

- **medium:** нестандартные `lang.front/admin`; при отсутствии CA bundle проверка TLS-сертификата явно отключается (`inc/plugins/advancedfunctionality/addons/smarturltitles/manifest.php:19-43`, `inc/plugins/advancedfunctionality/addons/smarturltitles/smarturltitles.php:793-798`).
- **Положительно:** SSRF-защита проверяет resolved IP, ограничивает протоколы и закрепляет DNS; размер/timeout ограничены, redirects повторно проходят проверки (`inc/plugins/advancedfunctionality/addons/smarturltitles/smarturltitles.php:701-712`, `inc/plugins/advancedfunctionality/addons/smarturltitles/smarturltitles.php:735-777`, `inc/plugins/advancedfunctionality/addons/smarturltitles/smarturltitles.php:800-843`).
- **Lifecycle:** install/uninstall ограничены settings/templates, Disable не оставляет aliases (`inc/plugins/advancedfunctionality/addons/smarturltitles/smarturltitles.php:45-48`, `inc/plugins/advancedfunctionality/addons/smarturltitles/smarturltitles.php:125-130`, `inc/plugins/advancedfunctionality/addons/smarturltitles/smarturltitles.php:148-160`).

## 4. Аддоны со статусом `ok`

Статус `ok` присвоен статическим аудитом и не заменяет runtime-проверку (`docs/audits/ADDONS_AUDIT_2026.md:5`).

- `advancedmessenger`: contextual frontend, POST/CSRF/membership, сохранение PM и guarded Appearance/APUI/FontAwesome (`docs/audits/ADDONS_AUDIT_2026.md:103-108`).
- `advancedrules`: canonical misc-route, POST/post key в ACP, отсутствие standalone aliases и незащищённых зависимостей (`docs/audits/ADDONS_AUDIT_2026.md:141-146`).
- `advancedsignaturebuilder`: идемпотентная schema, POST/CSRF/ownership, guarded AE/Appearance/ATF (`docs/audits/ADDONS_AUDIT_2026.md:155-160`).
- `advancedstatistic`: read-only frontend, ограниченный settings/templates lifecycle, guarded AdvancedPostCounter (`docs/audits/ADDONS_AUDIT_2026.md:162-167`).
- `characterworkflow`: идемпотентные миграции, отсутствие публичного mutation endpoint, guarded CharacterSheets/KB/Wanted (`docs/audits/ADDONS_AUDIT_2026.md:190-195`).
- `fastnews`: RU/EN/ACP согласованы, POST/post key, lifecycle ограничен settings/cache (`docs/audits/ADDONS_AUDIT_2026.md:214-219`).

Эталонные `adaptivethemeframework` и `knowledgebase` также получили `ok`; их детали приведены в разделе 2 (`docs/audits/ADDONS_AUDIT_2026.md:242-247`, `docs/audits/ADDONS_AUDIT_2026.md:256-261`).
