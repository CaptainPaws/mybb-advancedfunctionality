# ACS-11.1.3 — AdvancedEditor, автосохранение и публикационный UX

## База и область

База — `origin/main` **c29325af** (merge ACS-11.1.2, PR #1080), после `git fetch origin`. Один UI PR. Прочитаны применимые A1.50–A1.51/A1.55/A1.58–A1.59/A1.87/A1.89 Master Specification, R2/Presentation Technical Architecture, Implementation Roadmap, IC-EDITOR/IC-MYBB Integration Contracts, Revision 2 acceptance 4.5, отчёты 9.3.4, 10.4.3 и предыдущие UI 11.1.1/11.1.1B/11.1.2. Исторические отчёты не переписаны; `docs/ROADMAP.md` не изменён.

Проверены текущие DraftService/DraftPersistence, SealService, PublishService/SubmissionStore, EditorPublicationService/Controller/Entry и AdvancedEditor `advancededitor_shell.js`. Новых Resolver, publication coordinator, API записи механики, миграций или изменений прав нет. `publication_enabled` не включается; installed acceptance не меняется.

## Найденные разрывы

- Builder находился над постами отдельно от native формы. В форме публикации оставался только сохранённый план, а не сам конструктор.
- `draft_save` уже имел CAS, idempotency key и сохранение confirmation при prose-only update, но UI сохранял вручную, без отдельного состояния автосохранения.
- Весь DraftModel автоматически восстанавливался из sessionStorage. Publication также автоматически подставлял локальную прозу; срока действия не было.
- Ввод прозы во время pending save запрещался, а ACK безусловно снимал dirty. Это несовместимо с ненавязчивым автосохранением.
- Публикационный журнал уже сохранял прежний submission и pending prepare, однако recovery не имел отдельного блока, а ошибки не имели связанного `error_id` на этих UI surfaces.

## Одна форма и существующий редактор

На явно открытом `showthread?acs_builder=…` переиспользуется уже отрендеренный Builder. На `newreply/showthread?acs_draft=…` контроллер сначала проверяет владельца draft, native/thread membership, соответствие TID и точный encounter/turn. Для editable draft получает context существующим BuilderService и рендерит тот же компонент. Для publishing/published показывается прежний публикационный lookup, без попытки открыть новый механический план.

`publication.js` находит существующую `textarea[name=message]` формы newreply/Quick Reply, помещает перед её текущим editor container переключатель режима и переносит **существующий DOM Builder** внутрь той же формы. Внутреннее подготовительное поле prose скрывается, его модель связывается с единственным AdvancedEditor/SCEditor instance. Нет создания/инициализации/destroy второго редактора, клонирования формы или замены editor DOM. Используются текущие `val`, `valuechanged`, `af:editor-ready`, source textarea input и source adapter. Поздняя инициализация обрабатывается событиями/focus, без polling.

- **Литературный пост:** прежний native action, пустой acs_operation, обычный submit/Quick Reply и правила MyBB. ACS не распознаёт механику из текста, не seal/commit и не закрывает ход. Боевой план остаётся отдельным черновиком; выбранный литературный текст может сохраняться как его проза.
- **Боевой пост:** тот же редактор и форма плюс существующий пошаговый Builder. Submit/click перехватываются до native Quick Reply, публикация проходит прежний newreply ownership bridge и PublishService.
- Сворачивание/раскрытие details и смена ширины только скрывают тот же DOM. Состояние действий, порядка целей и текста не пересоздаётся. Переключение режима также не сбрасывает модель.
- Quick Edit/full edit не получают этот config/dispatch: доступны только showthread/newreply. Серверная история/receipt и прежняя independent combat card не меняются при редактировании прозы.

Если native reply form отсутствует или шаблон не предоставляет подходящую textarea, второй редактор/форма не создаются. Доставка, расположение и фактические editor events установленной темы требуют ручной проверки.

## Автосохранение и конфликты

Изменения добавленных действий/порядка/прозы сохраняются с задержкой **1200 мс** после последнего изменения через прежний `draft_save`. Отдельный autosave endpoint или вторая модель черновика не добавлены. Staged action становится частью черновика только после явного «Добавить/Применить»; промежуточные фильтры и ещё не добавленная карточка серверным планом не являются.

Показываются реальные состояния: «Есть несохранённые изменения», «Сохранение…», «Сохранено», «Ошибка сохранения», «Конфликт другой вкладки». В форме состояния зеркалируются и в литературном режиме. IME composition приостанавливает debounce. Network request не выполняется синхронно на unload.

Один outstanding save содержит неизменные `expected_draft_version`, operation_key, actions и prose. Ввод текста во время сохранения разрешён. ACK сравнивается с отправленной прозой/действиями: более новый ввод остаётся dirty и сохраняется следующим запросом после подтверждения первого. Изменение mechanics при outstanding request по-прежнему блокируется.

- CAS/conflict не подменяет ожидаемую версию свежей автоматически. Автосохранение останавливается; серверная версия восстанавливается явно. `storage` metadata notification другой вкладки дополняет CAS, но не заменяет его.
- Неизвестный ответ также останавливает autosave. Ручная кнопка повторяет **тот же** pending request/key. Новый ключ не выдаётся как реакция на потерю ACK.
- После reload повтор pending save подтверждает старый запрос, но не запускает автоматическую запись поверх восстановленной версии: требуется отдельное серверное restore.
- Автоматический restore после seal сохраняет прозу, введённую во время обоих сетевых ожиданий. Если и серверная проза изменилась относительно исходной, сохраняется локальный ввод, показывается conflict и автозапись блокируется.
- Save/restore/seal сериализованы UI-флагами; scope/group switch во время outstanding request недоступен. Ответ initial compose из прежнего scope не применяется к новой группе.

## Локальная резервная копия

В localStorage резервируется **только проза**, scoped текущим UID/session/encounter/turn, срок **24 часа**. Истёкшая копия не предлагается, удаление просроченных prose keys выполняется при открытии scoped Builder. Это lazy cleanup, не фоновый процесс удаления в закрытом браузере. Есть отдельная явная кнопка восстановления с подтверждением замены; автоматической подстановки локальной прозы больше нет. Сохранённый серверный текст можно первоначально подставить в пустой editor; введённый native текст/цитата сохраняется.

В sessionStorage отдельно остаются ограниченные по сроку **журналы неизвестных запросов**: точный pending save envelope, seal command/key, pending prepare envelope, submission/status и frozen prose. Они нужны для повтора прежней операции. Tape, rolls, preview/summary, HP/AP и целый серверный sealed DTO туда не сохраняются. Механические действия/префикс после reload читаются с сервера; request journal не становится источником авторизации или бросков. Старый whole-model session key удаляется.

Недоступность browser storage не мешает серверному сохранению, но не позволяет обещать локальное восстановление после закрытия вкладки. При утрате журнала server compose/operation fence остаются authoritative; новое подтверждение или новый post не выполняются автоматически.

## Preview, seal, confirm

Сохраняется прежний no-RNG conditional preview с server costs, разрешёнными probabilities и виртуальным состоянием. Очередь показывает последовательность/цели, существующие предупреждения friendly fire и серверные причины отказов. JS не вычисляет вероятности, осведомлённость, дистанцию или механику.

Seal остаётся отдельной явной командой. В его UI к каждому серверному результату добавлена строка исполнителя, упорядоченных целей и расходов, включая действия без damage. История бросков, предварительные итоги, quota/dependency/friendly-fire warnings и отдельное подтверждение используют прежний SealService. Конфликт версии блокирует новое seal; unknown seal повторяется только с прежним command/key. Локальная копия не содержит tape.

Литературный текст редактируется **после seal и confirm**. Prose-only save сохраняет серверную confirmation по действующему контракту. Следующая стадия `prepare/issue` сохраняет immutable publication snapshot, включая прозу; после регистрации отправки или неизвестного issue текст замораживается, как требует существующий R2 контракт. Редактирование уже зарегистрированного immutable request этим UI PR не вводится.

## Publication и recovery

Перед issue проза сохраняется единственным Builder bridge, затем authoritative compose проверяет текущие version/confirmation. Prepare использует прежние draft_id/version/confirmation; при потере ответа exact envelope сохраняется. Lookup читает compose/status, а ручной retry повторяет прежнюю prepare, без autosave с новой version между повторами.

Пользователь видит стадии сохранения, проверки, регистрации, отправки и фактический серверный статус. После регистрации нужен отдельный checkbox перед native submit. busy устанавливается до первого await: двойной click не стартует второй клиентский publish. Серверные locks/identity receipts остаются окончательной защитой.

- UNKNOWN/POSTING/COMMITTING/RECOVERY_REQUIRED запрещают publish POST и новые identity. Отдельный блок содержит прежний submission_id и кнопку **GET lookup** без автоматического polling/retry. RECOVERY_REQUIRED переводит фокус в этот блок и требует GM recovery; кнопки исправления механики не добавлены.
- REGISTERED/VALIDATING/POST_CONFIRMED и author moderation continuation используют только прежний ID по действующему протоколу.
- COMMITTED показывается успехом лишь при `mechanics_confirmed=true`; MODERATION_PENDING явно не означает расход HP/AP. Ссылка на существующий post показывается только для подтверждённого pid соответствующего статуса.
- После **серверно подтверждённого REJECTED** есть отдельный явный возврат в серверный Builder. Он удаляет только завершённый клиентский публикационный journal; не делает POST, seal или новых бросков. Для UNKNOWN/RECOVERY_REQUIRED эта команда недоступна.

## Gates, безопасность и файлы

Подготовительный UI теперь доступен на явном разрешённом Builder scope и без test-publication opt-in. Это не расширяет публикационный доступ: `can_publish` лишь отражает прежний server test gate; все compose/status/prepare/publish HTTP guards, UID/origin/source/lifecycle checks, CSRF и IC-MYBB-1 остаются прежними. При false нет publication fetch/POST, а форма объясняет блокировку. Публичный `publication_enabled=false` не изменён. Обычные страницы без acs_builder/acs_draft не получают новый config/assets/runtime.

В контроллерах draft/seal и editor добавлены безопасные category/error_id и запись только machine code + error_id через существующую ограниченную AF diagnostic cache. SQL, проза, snapshot, hidden IDs и credentials не выводятся/не логируются этим изменением. Server-side control/awareness/target validation не ослаблены.

Изменены:

- `modules/Presentation/BuilderController.php` — authenticated context reuse, save status/local restore markup, cache version.
- `modules/Presentation/EditorPublicationController.php` — scoped native-form preparation context, сохранённые publication gates, diagnostics.
- `modules/Presentation/ApiController.php` — безопасный error_id/category существующих draft/seal ошибок.
- `assets/builder.js` — autosave/CAS UX, prose backup, request journal, editor bridge, preservation при seal refresh.
- `assets/publication.js` — одна форма/режимы, editor events, стадии и explicit recovery lookup.
- `assets/publication.css` — scoped hidden/details/recovery/mobile rules.
- Этот отчёт.

PHP/CSS/JS следует поставлять вместе (asset version `1113`). Действующие manifest response facts `has_acs_builder`/`has_acs_publication` достаточны и не изменены. ATF/postbit/sticky, AdvancedEditor owner code, Domain/Resolver, DraftService/SealService/PublishService, схема, права и игровые данные не менялись.

## Проверки и ручная приёмка

- **PASS:** `node --check` двух изменённых JS (проверка синтаксиса без исполнения).
- **PASS:** `git diff --check`; статически сверены direct Builder markup references и реальные APIs/registration/gates.
- **NOT_VERIFIED:** `php -l` трёх изменённых PHP — PHP CLI отсутствует; среда не устанавливалась.
- Автоматические/regression/integration/SQL/browser tests не создавались и не запускались согласно AGENTS.md. Production/runtime MyBB/БД/установленный AdvancedEditor не проверялись. Никакой installed acceptance или production readiness не заявлено.

Ручная проверка владельцем на изолированной установленной копии:

1. Полная форма и Quick Reply с текущим AdvancedEditor: ровно один editor; один Builder внутри native form, quote/BBCode сохраняются. Narrative с обычными MyBB options не меняет механику; Quick Edit/full edit не меняют receipt/tape/history.
2. Ввод прозы в source/WYSIWYG, IME, resize, details close/open, смена режима/вкладок: debounce и статусы; ввод во время медленного save/seal/status; отсутствие потери текста и staged actions при скрытии DOM.
3. Две вкладки: CAS conflict/metadata notification, локальный текст остаётся доступным, stale вкладка не получает auto-refresh version/auto-overwrite. Explicit server restore и explicit local prose restore проверяются раздельно.
4. Reload до/после save ACK, потеря ответа, storage disabled, просроченная копия: только прежний request/key при неизвестном исходе, нет автоматического восстановления local tape или записи поверх серверного плана.
5. До seal проверить costs/probabilities/sequence без RNG; после — rolls/targets/costs/outcomes/warnings и отдельный confirm. Prose edit не перебрасывает tape; version/dependency conflict не создаёт новый roll.
6. Publication с test gate false: доступна подготовка, нет publication request. С разрешённым opt-in на staging: prepare loss/reload, double click, lost publish response, moderation, COMMITTED, REJECTED, RECOVERY_REQUIRED; прежний ID/один post, real receipt, GET lookup и explicit rejected return.
7. Проверить ролевую матрицу owner/player/delegate/GM/observer/outsider, отсутствие чужих numeric/hidden данных и безопасные error_id в ACP diagnostics.

Это матрица для владельца, а не выполненные проверки Codex. Следующая задача этапа 11 не начата.
