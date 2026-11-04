# ACS-2.2.3 — Encounter, стороны и GM-панель

Исходная main: `b755cc40bb10b4da7e28c9f6be65c7b178689cff` (merge PR #988).
Аддон `0.6.0`, новая версия схемы `003_encounters`. Design Baseline не изменён.

## Изменения и хранение

Новые файлы внутри `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

- `migrations/003_encounters.php` — четыре проектные таблицы Architecture §5.
- `modules/Application/EncounterService.php` — авторизация GM, подготовка, проверки существ/сторон/контроля и readiness.
- `modules/Infrastructure/EncounterRepository.php` — подготовленные scoped SQL, projections и CAS.
- `modules/Presentation/Encounters.php` — пять минимальных вкладок и обычные POST-формы, без JS/JSON-редактора.

Изменены:

- `advancedcombatsystem.php` — lazy factory и существующий AF API dispatch; версии session/encounter в envelope.
- `manifest.php` — версия и описание.
- `modules/Administration/Migrations.php` и `modules/Infrastructure/Repository.php` — последовательный registry/readiness 001/002/003, разрешённые таблицы.
- `modules/Infrastructure/SessionRepository.php` — encounter scope/sequence в operation/event, прежняя единая транзакция/CAS/idempotency.
- `modules/Application/AuthorizationService.php` — сессионное разрешение `encounter.manage`; критические игровые полномочия остаются deny.
- `modules/Bootstrap/Context.php` — VIEW только для явно открываемого ACS-контекста темы; это не разрешение доступа.
- `modules/Presentation/ThreadComponents.php` — расширен существующий provider `advancedcombatsystem::session_link`, без повторной регистрации/нового hook.
- `modules/Presentation/Sessions.php` — ссылка из сессии в связанную тему.
- `tests/acs_entities_regression.php` — актуальная версия схемы и маленькая точка переиспользования изолированной fixture.
- `tests/acs_sessions_regression.php` — проверка отсутствия GM stack на обычной теме.

Добавлены `tests/acs_encounters_regression.php` и этот отчёт. Четыре проектных документа, 001/002/checksums, AF Core, MyBB, ATF templates, AdvancedEditor и другие аддоны не изменены.

Миграция **003**, а не новая редакция 002: `002_entities` уже принята и объединена, её checksum нельзя менять.

| Таблица без TABLE_PREFIX | PK | UNIQUE / KEY |
|---|---|---|
| af_acs_encounters | id | UNIQUE start_once(start_operation_id); session_state(session_id,status) |
| af_acs_sides | id | encounter_side(encounter_id,id) |
| af_acs_side_relationships | id | UNIQUE side_pair(encounter_id,side_a,side_b) |
| af_acs_participants | id | UNIQUE encounter_entity(encounter_id,entity_id); encounter_side_state(encounter_id,side_id,participation); entity_history(entity_id,encounter_id) |

DDL сверено с Architecture: типы, nullable поля, PK/UNIQUE/KEY сохранены. Как в 001/002, InnoDB, utf8mb4_unicode_ci, явный ROW_FORMAT=DYNAMIC и настоящий TABLE_PREFIX, без физических FK. Используются существующие groups/entities/states/resources/controllers/snapshots/events. Таблиц rounds/turns/AP не добавлено.

## Поведение

Создание сохраняет `preparation`, encounter_version=1, round_number=0, отдельный immutable config snapshot. На данном этапе поддержаны только безопасные инфраструктурные defaults `schema_version=1`, `defaults_version=acs-preparation-1`, visibility=`hidden` (GM-only) и введённое название. Произвольные deadlines/environment/rewards/AP не принимаются: для них требуются следующие KB/механические контракты. Нет автоматического изменения старой конфигурации после изменения кода/defaults. Readiness сверяет и hash конфигурации, и соответствие её сохранённой проекции.

Участие хранится отдельно от существа, owner, controller, life_state и группы: сторона принадлежит participant, а не перезаписывает entity/group. Подготовленный participant имеет `engaged` по DDL/модели участия; это не начало боя/хода. Используются только зарегистрированные существа той же сессии и их сохранённые snapshots. Повторное добавление одного entity в один encounter отдельным ключом отклоняется. Нахождение существа в active/paused/ambush_ready/pending_resolution другого encounter блокирует его использование.

Стороны создаются явно. Отношения — `allied`, `neutral`, `hostile`; одна симметричная пара хранится с упорядоченными IDs, без второй обратной строки. Для используемых разных сторон readiness требует явного отношения, а не придумывает вражду или нейтралитет. Изменения стороны/отношения имеют собственные версии и подтверждённый audit. Реакции, targeting и модификаторы не реализуются.

Персонаж и постоянный питомец сохраняют owner и общую player control_group. Самостоятельные NPC/монстры сохраняют отдельные independent groups даже при одном GM. Никаких общих AP всем монстрам мастера.

`controller_assign` работает **только в preparation**, только для зарегистрированного в encounter NPC/монстра без owner_uid, только на существующего сессионного owner/gm с native доступом к теме. Режим — exclusive. История прежнего назначения сохраняется, assignment_version монотонна, ресурсы/собственность/группа не меняются. Персонажам и постоянным питомцам команда отказывает. Shared/gm_pool, делегирование и боевой transfer контроля относятся к этапу 7; зарезервированное `control.transfer` не превращено в универсальный bypass.

## Права, API и транзакции

Каждый read/command проверяет current MyBB actor, native forum/thread access и реальные persisted owner/gm роли. Owner дополнительно соответствует session.owner_uid. ACP administrator использует существующее AF ACP permission только внутри ACP; это не автоматическая игровая роль на showthread. Player/observer/assistant_gm не получают GM-панель. Глобальные GM-группы дают право создавать сессию по 2.2.1, но не доступ ко всем чужим сессиям.

Commands: POST, native CSRF, reason, operation_key, session expected_version, а для изменения существующего encounter — encounter_version. Все ссылки проверяются на same session/encounter. На странице темы дополнительно сверяется фактический tid ATF-контекста, session.tid и POST tid/session. Owner/роль/controller/group клиента не являются источником прав.

Серверная фабрика `af_advancedcombatsystem_encounters()` создаёт `AF_ACS_EncounterService` с методами:

- `context`, `list`, `view`, `events`, `candidates` — разрешённые GM metadata views с курсорами.
- `readiness` — read-only результат `preparation_ready`, diagnostics, версии и отдельная блокировка запуска.
- `command`: `create`, `side_create`, `relation_set`, `participant_add`, `participant_side`, `controller_assign`.

Существующий AF transport принимает `encounter_list/get/events/readiness` и `encounter_<command>`. `ApiController.php` в ACS отсутствует; параллельный контроллер не создавался. Неизвестные команды/start/close/ambush блокируются.

Используется существующий ACS advisory lock и SQL transaction: scope/auth/CAS → projections → operation/event/event_details → session version → committed result. Encounter version/sequence также меняются атомарно. Event/operation получают encounter_id; encounter events имеют последовательный event_sequence отдельно от session_sequence. В audit сохраняются причина, ссылки, результат и snapshot refs; смена контроллера дополнительно сохраняет прежний/новый UID и assignment version. SQL данные связаны параметрами. Неизменяемые snapshots/events не редактируются.

Committed retry перепроверяет текущий доступ и возвращает прежний результат без второго encounter/участника/события. Изменённый payload с тем же ключом запрещён. Read-only GM events выводят только метаданные операций `committed` той же сессии и столкновения, без raw event_details/приватных snapshots персонажей.

## ATF и минимальный UI

Существующий разрешённый slot `thread.before_posts` расположен вне quick reply/editor. Его provider сначала делает прежнюю маленькую проверку membership. В обычной теме нет панели; в связанной теме GM видит ссылку. Только явный `showthread.php?tid=…&acs_session=…` с подтверждённой owner/gm ролью лениво загружает панель. View/cmd повторно проверяют native права и связь темы. Нет альтернативного рендера без ATF, изменений чужих шаблонов, глобальных JS/CSS или SCEditor.

Вкладки: Обзор (название/статус), Участники (entity/group/side/controller), Раунды (не запущен), События (confirmed metadata), Управление (формы подготовки/проверки). Выбор существ, сторон и мастеров сделан обычными select; названия/отношения/действия русские. GM не получает полного исходного snapshot игрока через панель. Списки имеют bounded projections/курсоры; readiness проверяет всех участников указанного encounter, не весь форум и не каждый пост.

## Готовность и ограничения

Readiness проверяет активную сессию/preparation, config snapshot, наличие участников, стороны/отношения, same-scope entities/groups, независимость монстров, controller ownership/roles/topic access, hash/version/contract зарегистрированного snapshot, обязательные характеристики и 17 RES из единого KB registry, equipment policy, HP ranges. Он не пересобирает snapshot из live KB/CS, не меняет ресурсы и не запускает синхронизацию. Проверка — диагностический read с возвращёнными версиями; будущий start обязан повторить commit checks под своей транзакцией.

**Переход preparation → active здесь не выполняется.** `preparation_ready=true` не означает доступность боя: `can_start=false`, capability `first_round_roster` — PROPOSED/BLOCKED до **ACS-2.3.1**. Без roster нельзя безопасно объявлять encounter active. Засада, пауза, завершение encounter и закрытие сессии — отдельные будущие команды; закрытие одного не закрывает другое. Никаких AP/атак/Resolver/combat_post/Inventory/object instances нет.

Питомцы: по уточнению заказчика в ACS-2.2.2 реального pet-плагина пока нет. Связь с владельцем/группой подготовлена; тест использует явный fake owner provider прежней задачи. Реальная ручная регистрация питомца остаётся BLOCKED до подключения будущего плагина. Произвольный шаблон не объявляется питомцем ради прохождения приёмки.

## Проверки

Пройдены `php -l` изменённых PHP; сверка четырёх DDL/индексов с Architecture; `git diff --check`.

`tests/acs_encounters_regression.php` переиспользует маленькую write-forbidden KB/CS fixture и disposable MariaDB из entities regression, а не новый framework. Проверены:

- create/retry; две стороны и hostile; character+pet общая группа; два независимых монстра;
- GM-only доступ, отказ игроку и постороннему, same-scope refs и wrong thread;
- запрет controller_assign персонажу/питомцу, назначение NPC на другого GM без смены группы;
- session/encounter CAS, CSRF/GET, confirmed audit scope и непрерывная sequence;
- readiness без пересборки источников, повреждённый pinned hash, явный запрет start;
- пять server-rendered вкладок, escaping/отсутствие raw snapshots, GET без записей;
- повтор/возобновление 003 после искусственного прерывания второго DDL шага, сохранение старых snapshots;
- сохранение после disable/installation check; существующий sessions regression отдельно проверяет настоящие deactivate/activate, ATF connection charset/sql_mode, ACP и обычный thread slot без GM stack.

PHP **8.4.24**, MariaDB **10.11** локально. PHP 8.5.0 и установленный MyBB 1.8.40 требуют ручной приёмки. Полный suite, массовые браузерные/E2E/нагрузочные проверки не запускались. Production БД не использовалась.

## Установка, приёмка и восстановление

1. Резервная копия. Установить файлы PR; **явно ACP ACS → Основные → Обновить / продолжить миграции** до `003_encounters`, completed. При отключённом аддоне сначала upgrade, затем включение. Не реактивировать старую схему вместо upgrade. AF Core/ATF реактивировать не требуется.
2. В тестовой сессии зарегистрировать персонажа и двух монстров как в 2.2.2; питомца — только при реальном будущем provider. Назначить пользователю явную owner/gm роль.
3. В связанной теме открыть «Панель GM», создать столкновение, проверить status «Подготовка». Создать стороны, явно задать отношение, добавить ранее зарегистрированных существ, назначить стороны.
4. Проверить разные группы монстров и сохранение общей группы owner/pet. Назначить NPC на разрешённого GM; попытка назначить GM персонажу игрока должна отказать.
5. Открыть все пять вкладок, проверить confirmed события и «Проверить готовность». Ожидается отдельная блокировка первого roster до 2.3.1, без фальшивого active/раунда.
6. Проверить player/observer/постороннего: GM-панели/команд нет. Обычная тема без ACS не получает панель. Проверить быстрый ответ и AdvancedEditor.
7. Перезагрузить тему, отключить/включить ACS после upgrade: encounter/sides/participants/groups/snapshots сохранены; источники KB/CS и ресурсы не менялись.

При прерванной 003 устранить конкретную причину и повторить штатный upgrade с теми же файлами. Runner проверяет фактический DDL и продолжает; нельзя подделывать journal/checksum или удалять таблицы ради повторного запуска. При неопределённом ответе команды повторять тот же operation_key/payload, а не создавать новый вслепую.

Безопасный операционный откат: отключить ACS, сохранить все таблицы/историю. Down migration нет. Старый код 002 нельзя снова включить поверх journal 003; полный откат — только согласованное восстановление совместимой резервной копии с сохранением новых данных. Uninstall не является откатом.

ACS-2.3.1 не выполнялась.
