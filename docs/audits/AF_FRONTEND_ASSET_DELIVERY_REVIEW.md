# AF-PERF-01 — Доставка frontend-ресурсов

Срез `main` — `b9ecc184`. Результаты ниже получены из исходников. Заголовки фактических ответов, число запросов, размер передачи, Coverage и время загрузки warprift.ru в Network имеют статус **NOT_VERIFIED**, включая настройки конкретных тем и состояния входа.

## Как формируется ответ

Прочитаны [контракт manifest](../contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md), [контракт оболочки ATF](../contracts/ATF_PAGE_SHELL_CONTRACT.md), [аудит архитектуры](FRONTEND_ASSET_ARCHITECTURE_AUDIT.md) и [аудит AdvancedJSBundle](ADVANCED_JS_BUNDLE_FRONTEND_AUDIT.md). Исторические описания сверены с текущими исходниками.

1. MyBB подставляет прикреплённые Theme stylesheets в `$stylesheets` внутри `headerinclude`. AF может восстановить отсутствующую ссылку по registry и активному attachment. У обеих веток есть собственный путь до браузера, пока запись Theme прикреплена глобально.
2. Аддоны добавляют ресурсы через очередь, `headerinclude` и `pre_output_page`. `af_frontend_asset_allowed()` проверяет запрошенный ресурс и контекст. Определённые владельцем response facts описывают уже созданный компонент. Backend-зависимость сама по себе не даёт разрешения.
3. Центральный collector проверяет разрешение и legacy blacklist, доставляет Theme sources в File mode по attachment и применяет directory fallback только при разрешающем manifest. Сканирование кандидатов для **Theme** независимо от directory fallback и предлагает `global` для верхнеуровневых CSS без явной декларации. Поэтому выключенный fallback ещё не доказывает отсутствие CSS в Theme mode.
4. AdvancedJSBundle выполняется после владельцев и collector, собирает свой упорядоченный каталог по разрешениям отдельных ресурсов и заменяет собственный маркер в `headerinclude`. Чужие runtime и inline config остаются у владельцев. Финальная нормализация AF убирает повторы пути, типового theme cache layer и script src. Разные URL одного исходника и ручные ссылки могут требовать отдельной проверки.

## Матрица ресурсов

| Ресурс | Подключение и маршруты | Назначение и эффект удаления | Дублирование |
|---|---|---|---|
| AdvancedJSBundle `scroll-buttons.css/js`, `postcontrols-tooltips.js` | Глобальные ресурсы каталога, CSS также имеет Theme attachment | Общие кнопки прокрутки и обработчик подсказок. Для первого PR остаются глобальными | Theme/File и поздний каталог сходятся на нормализации AF |
| AdvancedJSBundle `af_quickquote.css/js` | Каталог разрешает `showthread/forumdisplay`. До правки CSS Theme имел также `global` | Плавающее действие цитирования. Без CSS на рабочем маршруте кнопка теряет положение и состояние | Один CSS может появляться из MyBB/collector и блока аддона |
| AdvancedJSBundle `fimp.css/js` | Каталог разрешает `showthread/forumdisplay`. До правки CSS Theme имел также `global` | Выдвижной инструмент `#fimp`. CSS нужен для скрытия и расположения | Тот же путь на нормализацию |
| AdvancedJSBundle `postbit-fa-icons.css/js` | Каталог разрешает `showthread/forumdisplay/private?action=read`. До правки CSS Theme был глобальным и имел весь `private.php` | Иконки и выравнивание даты postbit | Тот же путь на нормализацию |
| AdvancedJSBundle `quote-avatars.css/js` | Каталог разрешает `showthread/newreply/newthread/editpost/private?action=read,send`. До правки CSS Theme был глобальным, с явными `showthread/private` | Заголовок цитаты и аватар, включая предпросмотр в формах | Тот же путь на нормализацию |
| AdvancedJSBundle `af_popup_detach.js`, `kill-threaded-mode-link.js` | Поздний каталог, соответственно `showthread` и `showthread/forumdisplay` | Работа с popup и ссылкой режима темы | Один каталог, fallback отключён |
| AdvancedEditor `advancededitor_shell.css/js`, main runtime, vendor и BBCode packs | `af_advancededitor_pre_output` проверяет формы и опубликованный контент. Shell JS и файловый shell CSS идут с редактором. У shell CSS отдельно объявлен глобальный Theme attachment. Main и pack CSS с `disable_theme_integration` выдаёт владелец | Shell нужен для тулбара, CSS/JS опубликованных BBCode могут понадобиться без редактора. Пакеты просмотра проверяют маркеры. Удаление целиком ломает кнопки или представление BBCode | Shell имеет отдельные Theme- и owner-пути. Проверить двоякую доставку и ручные Theme-версии отдельно |
| KB `knowledgebase.css`, `knowledgebase_kbui.css` | Оба имеют `global` + `kb.php` в Theme. `af_kb_ensure_header_bits` и `af_knowledgebase_pre_output` обслуживают KB и response facts для чипов и редакторов | Первый содержит витрину и формы, второй — в том числе стили чипов, tooltip и модалок. Оба требуют инвентаризации встроенных представлений перед отсоединением | KB удаляет свои файловые теги перед инъекцией. Theme href и ручные ссылки проверяются отдельно |
| KB `knowledgebase.js`, SCEditor, `knowledgebase_chips*.js`, `knowledgebase_insert.css/js` | KB pre-output подбирает стек редактора, полный runtime KB или bootstrap найденных чипов. Bootstrap подгружает обработчик на взаимодействии. Insert зарегистрирован как capability AdvancedEditor, собственный fallback KB остаётся на формах | Редактирование KB, показ чипов и picker. Удаление bootstrap/config оставляет чипы или кнопку без обработчика | Файловый JS очищается владельцем перед инъекцией. У loader чипов один in-flight promise |
| CharacterSheets `charactersheets-trigger.css/js`, `charactersheets.css/js` | `modules/frontend.php` выбирает лёгкий launcher для реально выведенного триггера и полный набор для самостоятельного листа, iframe или встроенного листа. `embedded_runtime` проверяет отдельный fact | Launcher открывает iframe при взаимодействии, полному листу нужны вкладки и controls. Оба CSS предлагаются как глобальные кандидаты Theme через отдельное сканирование | Есть очередь и поздний путь, владелец канонизирует теги. Проверить registry и legacy bundle отдельно |
| Inventory `advancedinventory.css/js` | `append_runtime_assets` на inventory/inventories/abilities и misc aliases. `append_embedded_assets` у виджета/профиля, включая ленивый tab/entity. HTML caller или самостоятельный iframe получает CSS до интерфейса | Стили карточек, вкладок и действия инвентаря. Убрать из профиля без переноса lifecycle — сломать открытие фрагмента. CSS является глобальным кандидатом Theme | Две точки `headerinclude` для разных поверхностей. Возможный Theme/файловый дубль проверять по URL и registry |
| AdvancedThreadFields `advancedthreadfields.css`, `advancedthreadfields-form.js`, `advancedthreadfields-view.js` | Владелец в pre-output проверяет `has_atf_component`, затем форму `newthread/editpost` либо CTA каталога `forumdisplay`. Showthread формируется сервером. CSS является глобальным кандидатом Theme | Форма настроек темы, CTA и стили серверного представления полей. Удаление CSS нарушает форму/вывод полей. JS формы отдельно от view | Один owner-маркер. Возможен отдельный Theme source того же CSS |
| ACS `presentation.css`, `thread-panel.js`, `standalone.css`, `modal-gm.css`, `builder.css/js`, `runtime.js`, `ambush.js`, `publication.css/js` | `ThreadComponents`, `StandalonePage`, R2 fragments, Ambush/Builder и явная точка публикации. Панель разрешена по факту; GM/Builder поставляют собственные ресурсы открытого инструмента. Верхнеуровневый CSS может быть отдельным кандидатом Theme | Карточка боя, лёгкий launcher и отдельные инструменты. Обычная тема не требует боевого runtime. CSS модалки R2 загружается до вставки HTML | PHP-выдача и реестр ресурсов launcher предотвращают повторную доставку runtime в документе. Проверить Theme registry для CSS отдельно |
| Adaptive Theme Framework, AdvancedMenu, Font Awesome | Глобальные оболочка, навигация и иконки; ATF `surfaces/*.css` отдельно привязаны к маршрутам и модальным провайдерам | Общая геометрия, типографика, топбар, прокрутка и иконки. Сохраняются на главной | Доставка Theme/File через AF |
| AdvancedAlertsAndMentions и AdvancedAccountSwitcher | Глобальные интеграции header/menu и модалки, стили account-add и notifications-modal имеют отдельную выдачу владельца | Уведомления, счётчики, выбор аккаунта и связанные действия. Сохраняются | Отдельно проверить гостевой ответ и наличие реального триггера прежде чем менять доставку |
| AdvancedWanted | `wanted.php`, late fact чипа, JS каталога только на каталоге, `advancedwanted.css` имеет глобальный Theme attachment | Чипы и модалки реально встраиваются вне каталога. Удаление без проверки ломает чипы | Возможная Theme/owner-доставка у чипа. Самостоятельная задача владельца |

## Матрица маршрутов

Ниже — условия штатной выдачи по коду после изменения manifest и синхронизации Theme. Отдельно указанные широкие Theme attachments или legacy bundles могут менять фактический ответ.

| Маршрут | Что получает разрешение |
|---|---|
| `index.php` | Глобальные оболочка, меню, иконки, уведомления и переключатель при доступном UI, прокрутка и подсказки. Из AdvancedJSBundle ни один из четырёх специализированных CSS не прикреплён. Встроенные чипы и опубликованные форматы учитываются по их фактам |
| `forumdisplay.php` | Quick Quote, FIMP и postbit icons по маршруту. Стили полей и view JS по CTA. KB bootstrap при чипах |
| Обычный `showthread.php` | Статический контент, редактор при реальном quick reply, picker как capability, чипы и триггеры листов по наличию, четыре CSS AdvancedJSBundle. ACS проверяет тип и факты ответа |
| Боевой `showthread.php` | Те же зависимости темы, плюс панель ACS и launcher по правам и сессии. GM/Builder загружаются при открытии или через явный fallback |
| `newthread.php` | Quote avatars, AdvancedEditor при форме, AdvancedThreadFields form JS при сконфигурированных полях, KB picker по редактору |
| `editpost.php` | Quote avatars, редактор при форме, runtime полей при выведенных контролах, KB picker по редактору |
| `member.php` | ATF profile, Inventory при интеграции профиля, CharacterSheets launcher/полный набор по surface, KB по чипам, BBCode просмотра по разметке |
| `charactersheets.php` | Компоненты каталога/листа по ветке и настройкам. Открытый iframe получает собственные assets листа |
| `inventory.php` | CSS/JS Inventory в соответствующем представлении, включая варианты tab/entity. Backend-чтение KB само по себе не включает редактор KB |
| KB `kb.php`, совместимые `misc.php?action=kb*` и `characters.php` | Runtime каталога/просмотра или редактор KB по действию, AdvancedEditor/SCE по форме и действующему пути. Модалки и чипы используют существующие зависимости KB |
| Страницы ACS `misc.php?action=acs_sessions/acs_recovery/acs_loot/acs_archive` | Standalone stylesheet и ресурсы конкретной страницы. Launcher у сессионного входа GM. Без автоматического открытия инструментов |
| AJAX-модалки | JSON сам не проходит цепочку полного HTML. Существующие launcher и зависимости остаются у вызывающего документа. ACS R2 загружает стили перед вставкой фрагмента и монтирует runtime после неё. KB и CharacterSheets сохраняют свои lazy/iframe paths |

## Исправление первого PR

Изменён только владелец AdvancedJSBundle. Четыре Theme attachment повторяют текущие разрешения соответствующих ресурсов, включая действия личных сообщений и формы предпросмотра цитат. Глобальные scroll buttons и tooltips, каталог, порядок и lifecycle сохранены. Сумма размеров четырёх исходных CSS — 4928 байт. Это размер исходников, фактическая экономия трафика **NOT_VERIFIED**.

Для уже установленной темы применить штатное **«Синхронизировать аддон»** для JS Bundle в AF → Theme Stylesheets. Обычный sync обновляет `attachedto` и сохраняет ручное содержимое. Хеш автоматического sync использует имена и отпечатки CSS, поэтому изменение одного manifest может не инициировать пересинхронизацию. Force Resync для этого изменения не требуется. Если остался старый `advancedstyles.css`, проверить, содержит ли он те же секции, и отдельно использовать предусмотренную миграцию Theme sources.

## Независимые следующие задачи

1. AdvancedEditor. Подтвердить потребителей shell CSS и согласовать файл владельца с установленным Theme source без потери пользовательских правок и BBCode на страницах просмотра.
2. KB. Разделить витрину/формы и общие стили чипов. Проверить оба Theme source, `characters.php`, embed-ответы, порядок config/SCEditor, регистрацию picker и загрузку CSS до показа модалки.
3. CharacterSheets, Inventory, AdvancedThreadFields и ACS. Рассматривать отдельно у каждого владельца глобальных кандидатов Theme. Проверить profile/iframe/AJAX, реальную форму полей, picker и права ACS. Сопоставить MyBB attachments, фактические owner URL и ручные источники.
4. Wanted и оставшиеся глобальные помощники. Сначала определить реальные триггеры, чипы и зависимости. Отдельно проверить старый bundle, гостя, авторизованный header и различные темы.

## Верификация

Прошли `git diff --check` и `php -l` для изменённого manifest. JS не менялся. Автоматические тесты не создавались и не запускались. После синхронизации на установленной теме сверить index и маршруты таблицы в Theme и File mode. Проверить мобильные меню/модалки, предпросмотр цитат и личные сообщения. Фактическая загрузка и показатели Network — **NOT_VERIFIED**.
