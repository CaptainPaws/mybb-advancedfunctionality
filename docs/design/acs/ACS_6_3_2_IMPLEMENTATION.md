# ACS-6.3.2 — Cross-session isolation, obligations и Inventory recovery

База: main `cb570055` (merge ACS-6.3.1). Один ограниченный Integration PR. Использованы четыре нормативных ACS-документа, IC-INV-1, отчёты ACS-6.1.1–6.3.1, Recovery Service R2, Post Lifecycle R2 и ACS-5.3.2. Основание: A1.32–A1.33/A1.60 и этап 6.3. `docs/ROADMAP.md` не изменяется. Этап 7 не начинается.

## Локально реализованные гарантии

### Глобальный owner и изоляция custody

Источник qty, постоянного owner UID, создания/удаления экземпляров и общей доступности остаётся AdvancedInventory. Все прежние reserve/consume/release, transfer/grant, protected writers и durable receipts сохранены. ACS не создаёт второго Inventory и не хранит альтернативный остаток.

Custody по-прежнему имеет UNIQUE(session_id,inventory_item_id); разрешение временного пользователя и equipment projection сессионные. Возврат одной сессии освобождает только reservation ID из её hashed custody payload с проверкой owner/scope/исходного reserve receipt. Запрос чужой сущности другой сессии отвергается. Не добавлено ни bulk release по owner UID, ни автоматического возврата при close/archive.

В `OwnerProtocol.php` добавлены read-only `contextReservations(scope, authorization_ref, after)` и `contextReservation(scope, authorization_ref, id)`: authoritative writer, schema/dependency/actor/owner-scope authorization, проверка исходного durable reserve receipt, без guard creation или mutation. Список активных резервов пагинируется по ID, 100 строк/страница; точечный lookup различает active/terminal. Панель не получает сырой Inventory.

### Durable внешние операции

`Application/InventoryOperationService.php` использует **существующий** `Infrastructure/OperationStore.php` / `af_acs_operations`, а не второй журнал. BIGINT IDs/versions из OperationStore нормализуются в точные decimal strings для CAS/DTO. Новых таблиц/индексов/DDL нет; ledger остаётся `014_custody`, миграции 001–014 не переписаны.

- Внутренний `execute(session_id, owner_envelope)` поддерживает только standalone **transfer/grant** при явно переданном доверенном owner composition. До staging выполняются серверная авторизация, native доступ, POST/CSRF, строгий owner payload validation/preflight. Default composition **не даёт** transfer/grant authority. Публичного endpoint для произвольного execute/grant или нового loot flow нет.
- В `request_json` сохраняется весь canonical owner envelope с прежними operation_key, payload_hash, actor/scope и expected versions. `operations.payload_hash` — SHA256 полного canonical request_json, а owner payload_hash хранится внутри envelope; эти два разных hash не подменяются. Перестановка object keys не меняет intent. `external_system=advancedinventory`, `external_ref=owner operation_key`.
- Сначала durable prepared intent, затем CAS → recovery_required **до внешнего вызова**. Только победитель CAS вызывает owner. Поздний concurrent/repeated execute не перехватывает dispatching phase, а идёт в lookup. Owner write происходит вне ACS transaction; её locks не удерживаются при ACS acknowledgement.
- Известный результат проверяется `Domain/InventoryReceipt.php`: result_hash, key, operation type, payload_hash, actor, scope и допустимый outcome конкретной операции. В одной ACS CAS transaction сохраняются owner receipt, операция и append-only audit event/details; session version/sequence повышаются. Owner receipt сам по себе **не применяет ACS боевые эффекты** и не создаёт COMMITTED submission.
- `inspect` только читает; `recover` выполняет **только owner lookup прежнего ключа**, затем CAS reconciliation. Нет consume/transfer/grant/release в recovery. Terminal lookup/repeat возвращает прежний подтверждённый результат; stale pending CAS отклоняется. not_found сохраняет barrier: автоматического retry даже с новой key нет. До поддержанного доказательства безопасного повтора оператор не может «отклонить» неизвестный эффект по отсутствию HTTP-ответа.
- `InventoryRecoveryAccess.php` даёт исходному authenticated автору только исторический lookup точного сохранённого intent при текущем session membership/native доступе. Он не выдаёт spending/grant permission и не позволяет GM подменить автора. Owner API дополнительно проверяет собственные actor/scope/principal и result integrity.

`RecoveryService::inspectInventory/recoverInventory` делегируют этому протоколу. Existing AF API: `inventory_operation_lookup(session_id,operation_key)` и POST `inventory_operation_recover(session_id,operation_key,expected_operation_version,my_post_key)`. Они не принимают client-reported owner result, qty, PID, новый payload или strategy повторного расхода.

### Combat/Seal/Publish и отказ зависимости

Critical боевые reserve/consume/release остаются **только** в прежнем доказанном same-writer InnoDB participation: Seal резервирует, CombatCommitWriter списывает при подтверждённом commit; provisional result не становится публичным success до outer COMMIT. Custody также сохраняет прежнюю atomic reserve/release boundary. Новый standalone coordinator не является альтернативным consume API.

- `Repository::assertInventorySettled`, `ItemActionService::prepare`, `CustodyService::command` блокируют зависимые item writes при prepared/recovery_required owner операции. Для предметных команд действует консервативный session barrier.
- Для текущего equipment/temporary-use проверяются pending операции по **глобальным item IDs**, включая другую сессию. Подтверждённый standalone transfer исходного item ID инвалидирует старый pinned kit (`ACS_ITEM_DEPENDENCY_CONFLICT`), а не автоматически переэкипирует нового владельца. Проверка сопоставляет owner version pinned snapshot с source after-version из receipt: новый подтверждённый pin оставшейся части partial stack разрешён, прежний блокируется. Такая смена требует отдельной актуальной подготовки/kit change.
- `CombatCommitPersistence::facts` включает scoped pending Inventory operations и transfer dependencies текущих equipment/custody IDs с hashes/versions. Это прежний prepare→lock/fence, а не новый Resolver. Изменение Inventory version/fingerprint для предметного action по-прежнему перепроверяет `ItemCommitPersistence` под owner guard; прежние gap/version fixtures сохранены.
- `RoundManagementPersistence::assertNoObligations` сохраняет строгий barrier для round/turn management. Read-only preview/Writer preparation пропускают **несвязанные** owner operations, поскольку нужные item dependencies проверяются отдельно. Неизвестный grant свободного предмета не отключает обычную непредметную способность.
- При отключённом Inventory item commands закрыты, текущие owner receipts/события не переписываются. Pending owner recovery показывает unavailable и сохраняет identity/barrier. Ordinary non-item actions используют прежние capability gates и закреплённые механические источники; общий Inventory outage не вводит обязательный live Inventory read для каждого удара. Подтверждённые HP/AP/status/reaction results, prose и tape не пересоздаются при возвращении зависимости.

`PublishService`, `SubmissionStore`, прежние publication states/leases, post lifecycle observers и terminal receipt protocol не заменены. Unknown combat outcome восстанавливается прежним submission/receipt lookup через RecoveryService; отдельный Inventory lookup не возобновляет боевую публикацию. Нет нового MyBB post, MyBB Core/engine изменений или включения publication flags.

### Close/archive и минимальная панель

`SessionService::command(close|archive)` и `SessionRepository::lifecycle` добавляют явные POST/CSRF + native permission/session CAS/reason команды GM/owner по прежней session policy. active → closed; active/closed → archived. Только эти сохраняющие состояние transitions допускаются при pending publication. Они не завершают столкновение, не списывают/возвращают предметы, не выдают награды, не снимают claims и не удаляют custody, reservations, operations, drafts, sealed tape, event refs или receipts. Сессионная запись, новый audit event и версия меняются атомарно. Закрытие может остановить дальнейшую публикацию; её прежний unresolved journal остаётся для recovery. Reopen/финализация этапа 10 не реализованы.

В `Presentation/Sessions.php` доступны соответствующие кнопки с явной подписью о сохранении обязательств. `InventoryObligationsQuery.php` / `Presentation/InventoryObligations.php` лениво подключены к существующей `RecoveryPage.php`, доступной по GM-ссылке ACS. Видны item ID, owner UID, custodian/active user entity IDs, custody status/version, reservation status, pending/recovery_required и доступные команды. Пагинация: custody/operations по 20, owner reservations по 100. GM может confiscate/assign/temporary_use/return через существующий CustodyService; после неизвестного ответа предусмотрен lookup прежней custody key. Для внешнего owner ack lookup выполняет его исходный автор.

При недоступном Inventory резерв помечается unverified, доступные изменения скрываются; отсутствие списка не выдается за отсутствие обязательств. GET не пишет в БД; HTML экранирован. Raw Inventory, normalized_item, скрытые snapshots и owner intent JSON в DOM не выводятся. Постоянный polling и редизайн этапа 11 отсутствуют.

## Lock order и неизвестные результаты

1. Critical combat/custody: прежний ACS advisory/session/fact order → один owner guard → sorted items/reservations/bindings → ACS CAS/receipt/audit → фактический общий same-writer COMMIT. Owner authorization выполняет non-locking ACS reads, без owner→ACS lock inversion.
2. Standalone property coordination: короткий ACS advisory → session → operation CAS/audit; **release ACS locks**; owner standalone transaction (owner UIDs ASC, item IDs ASC, reservations/bindings); **release owner locks**; короткий ACS acknowledgement CAS/audit. Общей SQL transaction MyBB/ACS/owner между этими фазами нет.
3. Lookup выполняется вне ACS locks, immutable receipt проверяется, затем CAS повторно проверяет авторство/membership и version. Если другой процесс уже сохранил acknowledgement, caller перечитывает исходную operation; второй расход не выполняется.
4. При неизвестном ACK регистрации/dispatch/ack caller сохраняет прежние key/hash и перечитывает journal/lookup. Late ACS audit failure после owner COMMIT не означает owner rollback: pending операция уже durable, следующий recovery получает прежний результат. При недоступной БД нет fictitious terminal result; unresolved барьер не заменяется новым intent.

## Подтверждённые минимальные проверки

**PHP 8.5.12 / MySQL 8.0.46**, official CLI/minimal runtime, отдельный local socket; случайные disposable DB удаляются в finally. MyBB users/posts/threads/forums в fixtures остаются MyISAM. Production DB/обычные пользовательские данные не подключались. Полный test suite и browser E2E не запускались. JS не изменён.

| Проверка | Подтверждённый результат |
| --- | --- |
| `php -l` изменённых/новых PHP; `git diff --check` | PASS |
| `acs_inventory_owner_sql.php` | Две независимые worker/DB handles одновременно ждут SQL owner guard; scopes двух сессий, одна единица → один reserve. Concurrent/duplicate consume, duplicate release, over-request, unknown COMMIT, owner permissions и rollback PASS |
| `acs_inventory_property_sql.php` | Concurrent transfer, concurrent/duplicate grant/provenance dedupe, owner lookup, late rollback и обычный Shop checkout с reserve PASS |
| `acs_inventory_isolation_sql.php SOCKET` | Две реальные ACS-сессии: чужая reservation не принимается, отдельные custody изолированы, cross-session custodian denied, return/close не снимают чужой reserve, owner/qty unchanged, close/archive с held custody PASS |
| Deactivate/reactivate | Реальные ACS lifecycle callbacks с fixture ACP permission/MyBB version boundary, assertInstalled и repeat migrations сохраняют custody/reservations. Это не полный installed ACP route |
| GM query/render | Actual RecoveryPage GET integration, scoped owner DTO, outage/unverified/hidden commands, permission denial, escaped HTML и отсутствие GET writes PASS |
| `acs_inventory_recovery_sql.php SOCKET` | Реальный owner COMMIT + потеря ответа grant → durable recovery_required → default historical lookup → один grant; changed payload conflict/canonical reordered retry, stale recovery CAS и unauthorized author PASS |
| Late owner/ACS split failure | Owner transfer уже committed; поздняя ACS audit SQL ошибка сохраняет recovery barrier. Lookup завершает acknowledgement без второй передачи; duplicate terminal calls не мутируют имущество |
| Global equipment dependency | Pending transfer в A блокирует зависимый pinned kit в B; после подтверждения transfer старый kit отклонён как stale. Fresh pin после partial stack transfer разрешён; старый pin блокируется. Несвязанный kit не объявляется изменённым |
| `acs_inventory_outage_sql.php SOCKET` | Актуальный InventoryFixtureDatabase + owner-normalized pinned ability/direct grant fixture: при disabled Inventory и несвязанном pending grant доступны обычные preview/seal/confirm/commit. Late outbox rollback сохраняет projections/draft/tape; duplicate publication/recovery и возврат зависимости не меняют confirmed results |
| `acs_combat_items_sql.php SOCKET` | Existing combat swap/consume, duplicate submission/lookup, owner version conflict до подготовки и gap перед BEGIN, late owner/ACS rollback, original post_link/AP и permanent ownership PASS |

Точный запуск: `docker exec -e ACS_INV_FIXTURE_SOCKET=SOCKET PHP_CONTAINER php /repo/tests/acs_inventory_owner_sql.php` (аналогично property); новые fixtures принимают socket первым аргументом. Fixture socket требует `/tmp/acs222-socket/`; эти scripts не предназначены для рабочей БД.

## Поддерживаемые owner operations и оставшиеся BLOCKED

- reserve/consume/release: существующие IC-INV-1 standalone owner APIs; combat/custody используют только прежние разрешённые same-writer primitives и собственные durable ACS receipts.
- transfer/grant: существующие owner APIs, включая idempotency/provenance и ограничения KB из 6.1.3. Новый внутренний coordinator требует **явно доверенного** property authorization composition. Default combat policy не разрешает ни transfer, ни grant. Loot и публичный reward dispatcher не добавлены.
- lookup: owner lookup по прежнему operation_key; внешний receipt reconcile не означает completion зависимой ACS submission. Неизвестный/недоступный owner и доказанный not_found не разрешают этой панели blind retry. Автоматическое повторное dispatch/compensation отсутствуют.
- Remote/multi-database critical consume, произвольные owner callbacks внутри ACS tx, force reserve bypass, прочие grant kinds/KB schemas, foreign consume, stacks/неподдержанные custody mechanics, spatial pass и Stage 7 остаются BLOCKED/UNSUPPORTED.
- Исходный equipment baseline и KB pins остаются snapshot-моделью 6.2.1; изменение постоянного loadout не является автоматической сменой ACS kit. Live owner availability/version обязательны для owner-dependent item actions. Привилегированный ручной SQL и неизвестные installed writers вне доказанных owner boundaries не сертифицированы.

## Ограничения установленной среды

На установленном warprift.ru не проверялись фактическая owner schema/engines/read-write routing, MyBB 1.8.41 source/hooks, AF ACP permissions/enable persistence, реальная выдача server grants, дополнительные addons, ATF theme/mobile layout и полноценная публикационная acceptance. Local MyISAM fixtures и version guard не являются installed acceptance. Старые stage-2–5 fixture chains с SnapshotReadOnlyDb не подтверждают новый authoritative Inventory reader; целевые новые fixtures используют текущий SQL facade. Production publication flags не изменялись. Сохраняются installed gates предыдущих R2/Stage 6 отчётов.

## Минимальная ручная проверка после этапа 11

1. На staging-копии проверить owner I/E/S/state/reservations/external_operations/grant_sources schema, InnoDB, authoritative routing и ACS ledger 014; повторить разрешённую активацию. Публикацию включать только в рамках отдельной прежней R2 acceptance.
2. Один owner, две ACS-сессии, один qty=1 item: резерв A, попытка B, обычные sell/delete/equip. Проверить общий отказ, отдельные custody/хранителей и отсутствие изменения owner UID при confiscate/temporary use.
3. В GM-панели проверить явное temporary use, отключение исходных бонусов, return без auto-restore, pending/recovery statuses и отсутствие данных в player/outsider HTML/JSON.
4. Прервать ответ после owner COMMIT и после ACS acknowledgement; использовать прежние key/hash и lookup/CAS. Сверить один consume/transfer/grant, один post_link, неизменные tape/HP/AP/status/reaction receipts. Новый key либо ручной повтор расхода не использовать.
5. Отключить Inventory: item actions блокируются, резерв показан unverified; обычная непредметная способность остаётся доступна по своим gates. Включить зависимость, сверить прежние receipts и draft/tape без пересчёта истории.
6. Закрыть/архивировать с held custody/pending operation, деактивировать/активировать ACS: записи/owner/резервы/возвратные обязательства сохранены. Выполнить отдельный разрешённый return; чужая сессия не изменяется. Награды, reopen и Stage 7 этим PR не включаются.
