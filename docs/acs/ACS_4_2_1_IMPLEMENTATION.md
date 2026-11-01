# ACS-4.2.1 — серверные черновики

Исходная main: `c914e718f85446db6ab8741e44fff9687c964c83`. Версия ACS — `0.17.0`, схема — `006_drafts`. IC-MYBB-1 остаётся BLOCKED; draft API не зависит от публикационного Gateway или AdvancedAppearance. Задача реализует только хранение и восстановление незапечатанной подготовки по уточнению ACS-4.0, не seal/commit.

## Файлы и хранение

Новые компоненты относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

- `modules/Application/DraftService.php` — авторизация, структура unsealed действий, сохранение/восстановление.
- `modules/Infrastructure/DraftPersistence.php` — trait **существующего** EncounterRepository; нет параллельного Repository, transaction manager или command/event pipeline.
- `modules/Presentation/ApiController.php` — два draft op существующего AF router, безопасные JSON-ошибки, no-store. Ошибки не попадают в SQL diagnostics writer команд.
- `migrations/006_drafts.php` — следующая свободная versioned migration, только `af_acs_drafts`.

Изменены bootstrap ACS (ленивый dispatch двух op), manifest (версия), Migrations registry/VERSION, Repository allowlist/проверка ledger, EncounterRepository (подключение trait), SessionAccess (обычные posting restrictions). AF Core, MyBB, Editor, Appearance и другие аддоны не изменены. В tests обновлены три ожидания текущей схемы и posting permission fixture; добавлен `tests/acs_drafts_regression.php`. Этот отчёт — отдельный новый Markdown; нормативные документы не меняются.

DDL взят из Architecture §5: id, encounter_id, turn_id, author_uid, status, draft_version, mechanics_json, prose, dependency_json, confirmation_hash, pid, updated_at. PK id; KEY turn_drafts(turn_id,status); KEY author_drafts(author_uid,status,updated_at). InnoDB/utf8mb4_unicode_ci/ROW_FORMAT=DYNAMIC, реальный TABLE_PREFIX, без физических FK. Session ID выводится через encounter; service проверяет согласованность session→encounter→round→turn→group, параллельная колонка session_id не добавлена. `confirmation_hash`/`pid` всегда NULL; status создаётся только `draft`.

Миграция исполняется штатным административным install/upgrade lifecycle ACS с readback, checksum и возобновлением. Обычные запросы/endpoint её не запускают. После обновления файлов сначала применить upgrade в ACP и убедиться, что схема 006 готова; без этого запись fail-closed, без скрытой установки.

## API

Существующий маршрут: `xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem&op=<op>` при включённом ACS. Транспорт save — обычные form/urlencoded POST-поля, не raw JSON body. Не принимаются actor_uid/author_uid/status/sealed results/rolls от клиента.

### draft_save — POST + MyBB CSRF

| Поле | Значение |
|---|---|
| my_post_key | Текущий MyBB CSRF token |
| session_id, encounter_id, turn_id | Десятичные строковые ID существующих связанных записей |
| draft_id | Пусто при создании; ID своего draft при обновлении |
| expected_draft_version | `0` для создания, затем точная полученная версия |
| operation_key | Новый lowercase key для новой попытки изменения, 16–96 символов `[a-z0-9_.-]`; при сетевом повторе тот же key и payload |
| prose | Исходный UTF-8 BBCode, включая пустую строку; без NUL |
| actions | JSON-массив упорядоченных незапечатанных action descriptors |

Пример **структуры** actions, не фактически исполненная способность:

```json
[{"actor_id":"12","ability_key":"existing-kb-key","targets":["19"],"parameters":{"choice":"first"}}]
```

Разрешены только `actor_id`, `ability_key`, `targets`, `parameters` (последний необязателен). IDs нормализуются в строки. Порядок действий/целей и параметры сохраняются, массив может быть пустым. Действующий контроллер/владелец проверяется для каждого actor, но способность/цели/parameters **не объявляются исполнимыми**: это editable data, не готовый plan. Нет вызова KB reader, расчёта, Resolver или проверки бросков; параметры не являются серверными outcomes. Неизвестные action-level поля, включая rolls, отклоняются. Полная validation действия относится к preview/seal следующих задач.

Технические пределы транспорта, не игровые квоты: prose и mechanics JSON до 64 KiB каждый, ≤100 action descriptors, ≤100 target IDs/descriptor, JSON depth ≤32, конечные числа и существующая проверка точных целых JSON. POST body ≤256 KiB; 60 новых сохранений/минуту на автора, повторы committed key не расходуют этот лимит. Эти пределы не задают стоимость AP, число разрешённых боевых действий или seal quota.

Успешный JSON envelope: `ok:true`, `data:{schema_version:1,draft_id,draft_version,session_id,encounter_id,turn_id,status:"draft",saved_at}`. ID/версии/timestamp — строки. Сохранение не изменяет session/encounter/turn/entity versions.

### draft_restore — GET

Вход: только `draft_id` в прикладном контракте, кроме routing fields. CSRF для чтения не требуется. Возвращаются собственные prose/actions, status/version/scope, сохранённые dependencies, `dependency_changed`, `editable`, `validated_for_execution:false`. Нет публикации/rolls/новых receipt или diagnostics. Чужой/несуществующий/утративший доступ draft получает одинаковое безопасное сообщение; наличие ID не даёт доступа. Клиент обязан экранировать prose/parameters при показе, не вставлять их как доверенный HTML.

Dependencies фиксируются **сервером** на save: session/encounter/turn versions, round ID, roster fingerprint, разрешённые actor entity/state/controller versions и immutable source snapshot ID/hash. Scope — `draft_control`, не полный simulation dependency_set или подтверждение актуальности цели/формулы. Restore сравнивает с текущим собственным scope; изменения отражает флагом, не пересчитывает механики и не исправляет данные. Mask/preset refs и их versions не читаются и не включаются.

## Права и состояния

SessionService проверяет действующий MyBB user, форумные view/password permissions и доступ к сессии. Для draft дополнительно нужна сессионная роль owner/gm/assistant_gm/player. Global ACP/GM **не обходит** ownership/control: player group должна принадлежать actor UID; roster должен иметь хотя бы одно существо с единственным актуальным controller actor UID и owner NULL либо actor UID. Все action actors должны входить в разрешённое множество этого turn/group. Author берётся только из текущего MyBB user.

Save также проверяет `canpostreplys`, canonlyreplyownthreads, suspendposting и закрытую тему (обычный canpostclosedthreads moderator permission). Это ограничения **подготовки**, не замена будущей MyBB validation/flood/moderation при публикации. Save разрешён только active session, active/paused encounter, текущий open round/pending turn. Draft не продвигает часы при паузе. Закрытый/старый turn нельзя редактировать; свой текст можно восстановить с `editable:false`, если сохраняются доступ к сессии и контроль. Отзыв контроля блокирует восстановление. Published/sealed/связанный с PID/confirmation draft не перезаписывается этим сервисом.

## CAS, идемпотентность и отсутствие боевых мутаций

В одной существующей ACS transaction/advisory lock: проверить ledger/engines, lock session, заново авторизовать текущий scope, проверить operation receipt, CAS/insert draft и записать receipt. Не используется SessionRepository::command(), потому что он создаёт боевой event/увеличивает session version.

- Ключ receipt — `draft:<uid>:<operation_key>` в существующей `af_acs_operations`, kind `draft.save`. Hash покрывает точный нормализованный request/prose/actions; другой payload с тем же key → `ACS_OPERATION_KEY_CONFLICT`.
- Committed replay возвращает прежний receipt, не увеличивает версию и не перезаписывает более свежий draft. Авторизация проверяется и на replay; после закрытия хода вместо blind save клиент восстанавливает draft.
- Новая запись имеет version 1. Update — SQL CAS `WHERE draft_version=expected AND author_uid=... AND status='draft'`, version увеличивается один раз. Старая вкладка получает `ACS_DRAFT_VERSION_CONFLICT`, актуальный текст не меняется.
- Для author+turn допускается один открытый draft через сериализованный create/check под общим ACS lock; повтор create другим key без draft_id → `ACS_DRAFT_ALREADY_EXISTS`. Проектный DDL не расширяется новым UNIQUE вопреки Architecture. При потере ответа повторять **тот же key/payload**, не новый create.
- В operations сохраняются только scope/request IDs/expected version и receipt, **не prose/actions**. Боевая история/events/event_details/snapshots/resources не изменяются. Ошибка откатывает draft и receipt вместе.

Конфликты возвращают HTTP 409, invalid input/слишком большой request — 422, rate limit — 429, недоступность/права/CSRF — безопасный отказ 403. Ошибки не содержат SQL, stack, prose чужого автора или скрытые target данные. UI должен сохранять локальный текст при конфликте и предлагать восстановить серверную версию; автоматического overwrite здесь нет.

## Минимальные проверки

На изолированной MariaDB 10.11, PHP CLI 8.4.24 (не production/PHP 8.5.0): `tests/acs_drafts_regression.php` с существующим узким round/entity fixture, а не полным suite. Проверены:

- 006 DDL readback, прерывание/возобновление и повтор runner; повтор миграций сохраняет draft.
- Создание, BBCode/parameters/порядок, same-key replay, update/version 2, две вкладки/CAS, иной payload того же key, duplicate create.
- GET restore/no writes, чужой author/group даже у GM, запрет без CSRF/posting permission, отзыв контроля, closed-turn edit denial/read-only recovery, отказ injected rolls.
- Снимок всех участвующих ACS механических таблиц/events/diagnostics и owner fixture до/после: одинаков. Меняются только drafts и разрешённые draft.save receipts. Нет вызова RNG/Resolver/PostDataHandler/Appearance.
- `php -l` изменённых/new PHP, `git diff --check`, статическое сравнение 006 DDL с Architecture и ordinary showthread bootstrap без загрузки draft/Resolver stack.

Для локального запуска используется исключительно disposable socket `/tmp/acs222-socket/mysqld.sock`, подключаемые mysqli/mysqlnd соответствующей версии PHP. Большая инфраструктура, полный suite и E2E не создавались/не запускались. PHP 8.5.0 и реальные MyBB permissions требуют ручной приёмки.

## Ручная приёмка и откат

1. Сделать резервную копию ACS БД, обновить файлы, выполнить штатный ACP upgrade; проверить schema `006_drafts` и единственную новую таблицу с индексами.
2. В существующей тестовой сессии/раунде взять turn_id и entity_id своего управляемого существа. Через существующий AF endpoint отправить draft_save с пустым draft_id/version 0, prose и actions (или `[]`). Сохранить полученные draft_id/version; отдельный editor не нужен. Публичный визуальный конструктор/автосохранение в браузере относятся к 4.2.2 и здесь не добавлены.
3. Перезагрузить страницу и вызвать draft_restore по ID — текст и последовательность восстановлены. Отправить update с ожидаемой версией 1, затем из второй вкладки другой payload с той же версией и другим operation_key: 409, версия 2 сохранена.
4. Повторить точный успешный request с прежним key — тот же receipt, один draft. После network error не генерировать новый key вслепую; повторить старый и выполнить restore.
5. Под другим пользователем проверить отказ чтения по известному draft_id; у GM проверить отказ чужой player group. Проверить POST без CSRF и пользователя без posting permissions.
6. Проверить неизменность HP/AP/ходов/events/CharacterSheets и обычной темы/редактора, в том числе при смене маски. Боевой post/seal не отправлять: их endpoint нет, IC-MYBB-1 остаётся BLOCKED.

При ошибке миграции исправить конкретную schema/environment причину и повторить штатный runner; он проверяет фактическую схему и checksum. Не удалять таблицу drafts, receipts или ledger, не менять старые migration checksums.

Безопасный аварийный откат — отключить ACS, сохранив таблицы/черновики; обычный форум продолжает работать. Не развёртывать слепо runtime со схемой 005 поверх ledger 006: старый writable guard ожидает ровно пять миграций и откажет в командах. Возврат к старому поведению требует отдельного совместимого rollback patch, сохраняющего знание 006 и данные. Деактивация не очищает drafts. Следующая задача автоматически не выполняется.
