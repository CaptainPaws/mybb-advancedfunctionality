# ACS-11.1.1B — Исправление отсутствия панели ACS на showthread

## База и границы доказательства

База — актуальный `origin/main` **a9f586fe** после `git fetch origin main` (включает #1076, #1077, #1078). `ACS_10_4_3_IMPLEMENTATION.md` существует и прочитан. Утверждение об отсутствующей зависимости в историческом отчёте ACS-11.1.1 не относится к этой базе; исторический отчёт не переписывался.

Прочитаны ACS_11_1_1_IMPLEMENTATION, ACS_10_4_3_IMPLEMENTATION, применимые A1.53–A1.54/A1.87/A1.91–A1.94 Master Specification, Presentation/lifecycle/visibility части Technical Architecture, IC-THEME/IC-EDITOR/R2 Integration Contracts и текущие компоненты ACS/ATF.

Установленного MyBB runtime, базы и активных шаблонов warprift.ru в workspace **нет** (`inc/init.php` отсутствует). Поэтому конкретная причина исчезновения панели на production **не установлена наблюдением runtime**. Исправлены доказуемые статические разрывы ниже. Installed appearance, фактическое исполнение hooks и доставка ассетов остаются **NOT_VERIFIED**, до ручной проверки пользователя. Наличие файлов и синтаксические checks не выданы за функциональное завершение установленной задачи.

## Доказанные причины исчезновения / ошибочного поведения

1. `ThreadPanelQuery::current()` возвращал `null` при отсутствии encounter. Renderer создавал только прежние ссылки, но не новую панель. Для существующей сессии без столкновения результат ACS-11.1.1 действительно не достигался.
2. Ошибка discovery/current/render целиком поглощалась внешним catch. На обычном URL без `acs_session` возвращалась пустая строка, без сохранённого error_id. Любой schema/query/projection failure мог выглядеть как отсутствие регистрации или сессии.
3. При нескольких сессиях выбиралась первая **с encounter**. Сессия без encounter могла незаметно подменяться другой; понятного выбора не было. Ограничение списка 10 строк скрывало дальнейшие разрешённые сессии.
4. Отказы ATF registration и разрешения ассетов не диагностировались.
5. JS предполагал обязательные фильтры участников. Для минимальной панели без encounter требовалась поддержка отсутствующих фильтров.

**Поздняя регистрация в исходниках не подтверждена.** Перенос hooks на предполагаемую «более раннюю» фазу не сделан: существующий `global_end` уже предшествует исполнению slot. Новый fallback через глобальную замену HTML не добавлен. Активный installed шаблон без slot остаётся отдельной проверкой владельца, а не объявляется исправленным без доступа к нему.

## Прослеженная цепочка подключения

Сверены repository pipeline и reference source MyBB **mybb_1841** (`global.php`, `showthread.php`, получены только для чтения). Reference source не объявляется идентичным установленному warprift.ru.

| Шаг | Статическое доказательство / результат |
|---|---|
| AF bootstrap | Core регистрирует `advancedfunctionality_bootstrap_addons` на `global_start`, priority 1; enabled addon вызывается через `af_<id>_init` |
| ACS init | На authenticated showthread `af_advancedcombatsystem_init()` подключает лёгкий ThreadComponents и регистрирует один callback `global_end` |
| MyBB порядок | Reference global.php: session init до `global_start` (строки 49/100), `global_end` 1269; showthread.php требует global.php 28, затем вызывает `showthread_end` 1656 и оценивает шаблон 1658 |
| ACS registration | `af_advancedcombatsystem_register_thread_component()` регистрирует identity `advancedcombatsystem::session_link`, slot `thread.before_posts`, callable renderer; добавлены once guard и проверка bool результата |
| ATF acceptance | `register_component()` принимает owner/key/slot/renderer; `thread.before_posts` есть в slots, компоненты с другими identities не заменяются |
| ATF invocation | ATF init регистрирует `af_adaptivethemeframework_compose_showthread` на `showthread_end`, priority 100 |
| Context | `showthread_context()` возвращает верхнеуровневый `tid` из native `$thread['tid']`; context `uid` — автор темы и не используется как viewer |
| Slot / template | Composer вызывает `render_slot('thread.before_posts', $context)`; `templates/showthread.html` содержит `{$atf_thread_before_posts}` в `.atf-thread__slot--before` непосредственно перед сообщениями |
| Discovery | Native SessionAccess::thread + prepared read с current UID, TID, membership role и expiry; query-параметр не требуется |
| Current | Одна сессия открывается автоматически; несколько — явный выбор; current encounter выбирается прежней status/id policy; без encounter возвращается минимальный DTO |
| Render | ThreadPanel выводит full либо empty-session panel; только один body/DOM для desktop/mobile |
| Assets | Manifest `presentation` / `thread_panel` допускает `has_acs_panel=true`; helpers передают этот response fact, используют once guard; версия файлов обновлена на `1111b` |

В main других PHP providers именно этого slot не найдено; совместимость обеспечивается обычной ATF конкатенацией identities/sortorder, без присваивания slot globals из ACS. Combat card registration и её renderer не переписаны. ATF, его шаблоны, postbit, meta-line и sticky-код не изменены.

## Сессии и минимальная панель

- Одна разрешённая сессия текущего native TID показывается без `acs_session`, без ACP и без ручного перехода в отдельную страницу.
- Несколько сессий дают форму выбора `acs_panel_session`. Этот параметр только выбирает read-only панель и не открывает GM-инструменты. Чужой/устаревший/некорректный выбор не подменяется первой сессией.
- Разрешённый список ограничен 100 строками на страницу, с «Другие доступные сессии». Явно выбранная разрешённая сессия проверяется отдельно, если она вне текущей страницы. В options нет чужих сессий или числа скрытых участников.
- Без encounter выводятся **«Сессия ACS активна»** и **«Столкновение ещё не создано»**. Для closed/pending_close/archived текст соответствует фактическому статусу. GM получает ссылку «Создать столкновение / Панель GM» по существующему маршруту; участник — read-only пояснение.
- Открытие GET не создаёт session, encounter, round, roster или draft. Builder/GM остаются ленивыми по прежним явным `acs_builder` / `acs_session`; окончательные права проверяют их существующие контроллеры.
- Ошибка отдельно открытого инструмента больше не уничтожает уже собранную read-only панель.
- Если сессии нет либо viewer не авторизован, нет пустой панели, CSS/JS или загрузки ThreadPanelQuery/SessionService/EncounterRepository. Не добавлена общедоступная точка создания сессий.

## Ошибки и диагностика

Различаются нормальное отсутствие membership, отказ native access, отсутствие encounter и техническая ошибка. Нормальные отказы прав не получают HTML, раскрывающего скрытую сессию. Отсутствие encounter — штатная минимальная панель, не ошибка.

Безопасные коды: `ACS_PANEL_ATF_UNAVAILABLE`, `ACS_PANEL_REGISTRATION_FAILED`, `ACS_PANEL_CONTEXT_INVALID`, `ACS_PANEL_DISCOVERY_FAILED`, `ACS_PANEL_STORAGE_UNAVAILABLE`, `ACS_PANEL_VIEW_FAILED`, `ACS_PANEL_ASSET_FAILED`, `ACS_PANEL_ASSET_DENIED`, `ACS_PANEL_TOOL_FAILED`. Сохранение исходного exception message допускается **только** как одиночный machine code `ACS_[A-Z_]`; SQL, параметры, произвольный текст и stack trace не сохраняются этой функцией и не выводятся.

`af_advancedcombatsystem_panel_failure()` создаёт error_id и пишет безопасную запись через существующий **AF diagnostic cache** (`af_store_addon_lifecycle_diagnostic`, operation `thread_panel`) и server error log. ACP Diagnostics уже читает последний safe record этого кэша. Это запись диагностического файла, **не INSERT в БД на GET**. Если кэш недоступен для записи, остаётся server log; успешная запись на production здесь не проверена.

Только после доказанного membership и native доступа GM/настоящий owner видит code/error_id и предложение открыть ACP диагностику. Player/observer получают общий временный статус без технических подробностей. При ошибке discovery до подтверждения роли HTML не выводится, диагностический код остаётся в кэше/логе для оператора. При непринятой регистрации renderer вызвать невозможно — причину проверяют в ACP/логе, а не неподдержанным обходным HTML injection.

Отказы CSS/JS имеют отдельный код; native details остаётся доступным при отказе enhancement. Ошибка логируется один раз на code/phase за запрос. Нет автоматических reload/fetch/retry: JS только переключает существующий DOM, фильтрует уже разрешённые карточки и отображает локальный countdown.

## Видимость и производительность

SessionService/SessionAccess и AudienceService authorization/awareness правила не ослаблены. GM/audit получает прежний расширенный DTO. Player/owner — точные сведения своей группы. Observer и чужие известные существа используют прежний `identified` + `canTarget`, без выдачи numeric state/механических связей. Outsider не получает session options, HTML панели, ассеты или диагностические сведения. Общие counts скрытых участников, пустые карточки и приблизительные значения не добавлены.

Discovery — только ограниченный prepared membership read (дополнительный exact read при выбранной сессии вне страницы). Query/SessionService грузятся после обнаружения реальной сессии. Audience/EncounterRepository — после обнаружения encounter; AudienceService не создаётся для GM или roster только из своей группы. HP/resources/statuses/barriers остаются пакетными reads; KB каталог не читается.

Для mixed roster устранён очевидный participant N+1 в `entity()`/`controllers()` существующего AudienceService: отдельный экземпляр EncounterRepository панели один раз получает их session read shapes пакетно. `cacheThreadPanelReads()` opt-in включает private request-local cache повторных SELECT и единственное вычисление прежней проверенной statusProjection. Неизвестные данные не угадываются; validators и control/awareness policy сохранены. Командные репозитории не включают этот режим. В нём запрещены SQL записи и locking reads; кэш не переживает запрос, не разделяется между viewers и не используется seal/commit/GM writers.

Проверки происхождения отдельных эффектов всё ещё могут требовать собственных SELECT; постоянное число запросов, количественные пороги и performance acceptance не заявляются. Batch acquisition читает ACS snapshots в выбранной сессии, а не live KB/CharacterSheets. Для больших исторических сессий память/стоимость требуют установленной проверки; benchmark не выполнялся.

Панель не вызывает `Resolver::resolve`, Clock boundary, reserve/consume или другие mechanical writers. Существующая statusProjection при проверке copy-grants может подключить QueryService/класс Resolver для `compileAbility`; это сохранённая проверка retained source, не запуск резолвера или чтение всего KB. Ошибка такой проверки теперь получает диагностический код вместо исчезновения без следа. Builder/GM по явному URL — отдельный interactive scope.

## Изменённые файлы

Относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

- `modules/Presentation/ThreadComponents.php` — once registration/renderer, bounded membership selection, ошибки, lazy tools и asset diagnostics.
- `modules/Presentation/ThreadPanel.php` — session chooser и минимальная панель без encounter.
- `modules/Infrastructure/ThreadPanelQuery.php` — штатный empty-session DTO, отложенная audience projection и opt-in read cache.
- `modules/Infrastructure/EncounterRepository.php` — изолированный read-only opt-in cache панели; обычный путь при `null` cache сохраняет прежние методы/validation.
- `assets/thread-panel.js` — optional participant filters, чтобы empty-session panel использовала тот же desktop/mobile enhancement.
- `assets/presentation.css` — компактная адаптивная форма выбора сессии.
- Этот отчёт `docs/design/acs/ACS_11_1_1B_IMPLEMENTATION.md`.

`advancedcombatsystem.php`, SessionService, SessionAccess и manifest изучены, не изменены: статического разрыва их phase/context/permission/resource contracts не найдено. Ни ATF, ни owner addons, DDL/migrations, publication flags, RESERVED_DENY, игровые состояния или `docs/ROADMAP.md` не изменены.

## Разрешённые проверки

- `node --check assets/thread-panel.js` — PASS.
- `git diff --check` — PASS.
- Статически сверены реальные hooks/slots/context/template/asset API, ограниченность discovery, отсутствие новых DML/Clock/Resolver calls на view, использование read-cache только одним panel query caller.
- `php -l` — **NOT_VERIFIED**: PHP CLI отсутствует (`command -v php` не находит binary). Новая среда не устанавливалась.
- Автоматические, regression, integration, SQL-fixture и browser-тесты **не создавались и не запускались**. Production не подключалась. Полная installed-проверка **NOT_VERIFIED**.

## Ручная проверка пользователем

1. Проверить PHP syntax четырёх изменённых PHP в штатном PHP окружении перед merge/deploy.
2. Открыть обычный showthread без параметров с одной разрешённой сессией без encounter: минимальная панель; GM ссылка открывает существующие формы, сам GET encounter не создаёт.
3. Открыть сессию с encounter как GM/owner/player/observer и без membership: проверить карточки/HTML/options/ассеты и отсутствие скрытых IDs/чисел/counts у неуполномоченных.
4. Несколько сессий, выбор без encounter, explicit choice вне страницы, чужой/устаревший ID: нет молчаливой подмены выбранной сессии.
5. Если панель не появилась, сначала проверить факт вызова init/global_end/renderer и активный шаблон `showthread`: содержит ли он `{$atf_thread_before_posts}`. Если renderer вызывается, проверить диагностические коды `ACS_PANEL_*` в ACP/AF cache/server log и membership для настоящего TID. Отсутствие вызова slot и ошибка projection — разные причины.
6. Network/DOM: одна `presentation.css?v=1111b`, один `thread-panel.js?v=1111b`, одна выбранная `[data-acs-panel]`; при чистой теме без сессии их нет. Учитывать действующий AF CSS delivery mode и установленный asset pipeline.
7. Desktop/mobile/resize, empty/full panel, open/close, фильтры и длинные значения: одна DOM-панель, без reset или горизонтального overflow; navigation/meta-line/postbit не перекрываются. Убедиться в совместимости с фактически установленными другими providers.
8. Отдельные Builder/GM URLs, ошибки инструмента и отказ ассетов: read-only панель не исчезает молча, технический код видит только GM; публикация остаётся закрытой по прежнему gate.

Это исправление готово для ограниченной ручной проверки; successful installed rendering и функциональная приёмка **не заявляются**. ACS-11.1.2 не начата.
