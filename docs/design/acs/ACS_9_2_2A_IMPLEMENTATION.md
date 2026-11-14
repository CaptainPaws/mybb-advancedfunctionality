# ACS-9.2.2A — Pre-round preparation and durable AP commitments

База main `09db283b` (ACS-9.2.2 foundation, PR #1057).
Основания: A1.77, A1.47–A1.49, A1.75–A1.76; четыре нормативных ACS документа,
ACS_KB_MECHANICS_CONTRACT и исторические отчёты 9.1.1/1B/2/2A/2B, 9.2.1/2.
Один Backend PR. Исторические отчёты, миграции 001–021 и `docs/ROADMAP.md`
не изменены. ACS-9.2.2B не реализуется.

## Implemented: supported pre-round subset

Используются существующие BuilderService, DraftService, SealService,
PreparedActionService и cost-only ветка того же Resolver. Новых сервисов
подготовленного действия, Clock, InventoryAdapter или боевого журнала нет.

Право задаёт immutable KB `prepared_action.pre_round`:

```json
{"schema":"ic-acs.pre-round-preparation.v1","allowed":true}
```

Descriptor описан в KB Mechanics Contract. Без него обычная способность или
обычная prepared ability не получает pre-round admission. Неизвестные поля,
false/null и неподдержанные варианты отклоняются. Поддержаны существующие
single fixed target, on_targeted/source, until_next_turn, requires_active,
скрытые declarative concealment requirements и ресурсные/предметные costs.
Режим не выдаёт бонусов внезапности, дополнительного ordinary action или reaction.

Сервер требует active session, encounter preparation с round_number=0, отсутствие
first round/start intent, engaged source в стартовом составе и текущие native,
session, controller/delegation/ability permissions. Pending/recovery obligations
проверяются прежним guard. Builder выводит pre-round scope из фактов encounter;
клиент не передаёт capability, qty, owner, trigger или вычисленную стоимость.

В существующих NOT NULL legacy полях drafts.turn_id и
prepared_actions.source_entity_turn_id **0 означает отсутствие turn** в явно
помеченном pre-round scope. SQL turn/EntityTurn с ID 0 не создаётся. В контексте
Resolver source admission содержит только group_id, без фиктивного started
EntityTurn/clock boundary. Builder возвращает mode=pre_round, round=null и
planning scope key 0 с status=preparation, а не pending ordinary turn.
Prepared payload сохраняет immutable pre_round_scope с encounter ID.
Положительные ordinary turn identities и прежние проверки остаются прежними.

Draft — один source/ability/target. Seal хранит прежние immutable definition,
ability/target/trigger pins и deferred tape без будущих hit/crit. Никаких ticks,
AP платежей, resource consume или owned_qty уменьшения на preview/seal нет.
Seal резервирует только разрешённые item costs через существующий Stage 6 writer.
Квота seal считается по encounter + source control_group, включая опубликованные
и отменённые sealed attempts: прежняя политика невозврата seal quota сохранена.

## Durable AP and publication

Миграция **022_pre_round_commitments** создаёт только одну InnoDB таблицу;
используется прежний resumable migration ledger, registry и exact schema readback.
Никаких ALTER исторических таблиц или Inventory количеств.

Каждая запись связывает session/encounter, source entity/control_group,
prepared_action, source seal, **фактический paid_ap**, KB pin, source event и
publication operation identity. UNIQUE по prepared, seal и operation не допускают
повторного учёта. Shared session lock сериализует оплату/групповой cap.

Поддержан существующий первый AP budget=3 с plain group config/layers.
Неизвестные AP modifiers/overrides блокируются, не игнорируются. Суммируются
стоимости, а не число подготовок; персонаж, pet и source-group summon используют
одну группу. Independent NPC сохраняет отдельную group identity и бюджет.

Confirmed PreparedActionService.publish в прежней ACS command transaction:

1. Повторно проверяет confirmed seal, authority, pins, статус и остаток cap.
2. Выполняет разрешённые on_prepare owner costs существующим participant.
3. Регистрирует prepared action и AP commitment, оплачивает on_prepare resources.
4. Сохраняет bindings, pending on_trigger costs и owner obligations/receipts.
5. Публикует source seal/draft без pid и связывает source event/operation/audit.

Нет закрытия или переоткрытия обычного хода до первого раунда. Ошибка до commit
откатывает ledger, ресурсы, owner consume/receipts и подготовку вместе. После
потерянного ACK тот же operation key возвращает прежний confirmed receipt.
Отмена/expiry не возвращают AP commitment или уже оплаченные preparation costs.

При чтении сумм проверяются prepared contract hash/typed contract, source entity,
source seal/snapshot/pin/deferred tape, paid cost, confirmed publication event и
committed operation. Проверка полноты выявляет пропущенный ledger даже для
cancelled/terminal preparation: утрата записи не превращается в бесплатный старт.

## Resources and Inventory

On_prepare ресурсы оплачиваются ровно один раз в existing resources/state CAS.
On_trigger остаётся pending; timing не переносится ради реализации.

Item subset прежний: explicit owner-selected consumable/ammo, positive integer
qty, pinned arpg_item definition, retained exact item/reservation/owner version.
Seal reserve не уменьшает owned_qty и защищает количество глобально. Publication
consume использует прежний stable seal/cost operation и authoritative same-writer
OwnerProtocol::participate. Pending trigger reservation и narrow owner binding
сохраняются, новый резерв при retry не создаётся. Server original principal и
реальный request actor не подменяются.

Owner-only character costs не расширены на pet, GM или делегата. Такой конкретный
item cost блокируется прежней owner policy; ресурсная подготовка делегата может
пройти при отдельном действующем combat consent. Для cancel/expiry используются
прежние unused-reservation release/lookup/recovery obligations; unknown outcome
не освобождает предмет и не возвращает AP. Actual trigger consume сюда не входит.

## Ready, first roster and exhausted groups

AmbushStartService.ready принимает подтверждённые pre-round preparations после
проверки ledger и обязательств. **Start с active prepared actions остаётся
ACS_AMBUSH_EXECUTION_BLOCKED до 9.2.2B**, в том числе через обычный round_start.
Исполнение start action/roll/reveal не подменяется пустой успешной операцией.
Пустой start сохраняет прежний durable intent/replay путь.

После допустимого запуска openFirstRound/openRoundProjection создаёт единственный
обычный round/roster и групповые turns. Подтверждённая сумма фиксируется в
roster fingerprint и turns.pre_round_ap_commitment; каждый ledger получает
applied_round_id в той же transaction. Проверяется, что paid source/group входят
в настоящий стартовый состав, нет чужого/повторного applied round.

Прежний AP refresh устанавливает budget=3 один раз, сверяя roster + paid journal
и applied round. Остаток считается Clock.availableAP:
`3 − confirmed commitment − ap_spent`. On-trigger повторной оплаты AP нет.
Следующие раунды не получают старые commitments.

Для исчерпавшей бюджет группы вызывается существующий RoundManagement.closeTurn
со строгим internal exhausted-first-round guard. Выполняются реальные
processEntityStart/processEntityEnd каждого члена группы, ticks/upkeep и durable
boundary receipts; итог — passed, не фиктивный resolved. Parent start event
содержит exhausted_groups/boundary outcomes. Extra encounter sequence/version
для внутренних частей одной проекции не создаётся. Все операции, включая
terminal start receipt и owner/Clock projections, подтверждаются одним commit.

## Locally verified

PHP **8.5.12**, authoritative disposable MySQL **8.0.46**, существующий
acs_combat_items_sql/acs_prepared_items_sql harness. Socket внутри контейнеров
/tmp/acs222-socket/mysql8.sock. Случайные базы удаляются finally; production
и Gateway не используются, native fixture post не создаётся заново.

`acs_pre_round_sql.php`:

| Проверка | SQL evidence |
|---|---|
| 2 AP preparation | Одна paid запись AP=2; source event/operation/seal/pin подтверждены |
| No fake round | После preview/seal/publication rounds/turns/entity_turns пусты; RNG/native post неизменны |
| Payment timing | EP 5→3 только на publish; on_trigger EP pending; seal не уменьшает owned_qty |
| Owner items | on_prepare qty=2 consume, прежний reserve receipt; on_trigger qty=1 active reservation и owner binding |
| Duplicate/lost ACK | Реальный publication COMMIT с потерянным ACK; retry возвращает прежний receipt без нового ledger/расхода |
| Group cap | Character 2 AP + pet 1 AP = 3; дополнительная подготовка отклонена |
| Independent NPC | Своя запись AP=1, не добавляется к player group; первый остаток NPC=2 |
| 2 AP → 1 first round | После корректной отмены active preparation paid commitment остаётся; start даёт available=1 |
| 3 AP → 0 first round | Shared group passed, оба EntityTurn start/end и durable boundaries; NPC turn pending |
| Mandatory status | Seeded pinned poison тикает ровно один раз при exhausted-group start; HP уменьшается на 4, replay не тикает снова |
| Sealed history | После ready автор читает прежний deferred tape без записи; draft не editable, quota ограничена собственной группой/encounter |
| Start gate | Ready принимает active paid preparation; start блокируется до создания round/intent |
| Next round | После закрытия pending NPC раунд продвигается; во втором раунде AP=3, commitment=0 для всех групп |
| Duplicate start | Один round, две group turns, три EntityTurn; нет повторного AP refresh или ticks |
| Authorization | Revoked controller запрещает publish без записи; настоящий delegate UID 8 не тратит owner item |
| Delegation revoke | Реальный grant/revoke между seal и publish; отказ без commitment/payment |
| Late preparation rollback | SQL fault после owner consume откатывает owner/resource/prepared/ledger полностью |
| Late launch rollback | Нет half roster/applied commitment/poison tick; durable intent позволяет тот же retry |
| Missing ledger | Ready fail-closed при утрате confirmed commitment, включая отсутствие бесплатного пересчёта |
| Migration | Repeat и resume с существующей таблицей/оплаченными записями сохраняют paid history |

Поскольку active start намеренно закрыт, roster проверки выполняют явную
авторизованную отмену подготовок перед запуском. Их AP остаются оплаченными;
это не свидетельство start execution, trigger consume или reveal.

Точечные регрессии: acs_ambush_start_sql (empty start, durable interruption/replay),
acs_prepared_items_sql (обычный Stage 6/prepared item pipeline),
acs_prepared_execution_sql (существующий combat.primary Resolver/recovery).
Полный test suite не запускался. PHP lint изменённых PHP и git diff --check;
JavaScript не менялся.

## Remaining boundaries / BLOCKED

- Start с active actions, causal start rolls, start-trigger resource/item execution
  и reveal — только ACS-9.2.2B, здесь закрыты явным guard.
- Неподдержанные KB trigger/targeting/stealth формулы, автономные или recursive
  chains и AP overrides не становятся разрешёнными от pre_round opt-in.
- Foreign-owner/pet item preparation не получает нового owner grant. Remote или
  independent-commit Inventory protocol не добавлен.
- Нового GM UI/HTTP endpoint и редизайна нет: это Backend PR поверх существующих
  service APIs; publication_enabled не включён.

## Installed NOT_VERIFIED

Реальный warprift.ru/MyBB 1.8.41, production migration, KB opt-in abilities,
установленный pet provider, actual owner permissions и browser acceptance не
проверялись. SQL pet использует явный owner provider fixture; новый pet owner
plugin не создаётся. Stage-6 owner schema prerequisites остаются прежними.

## Validation result

- `acs_pre_round_sql.php` — PASS, включая paid publication ACK loss,
  exhausted-group poison/rollback и продвижение в следующий раунд.
- `acs_ambush_start_sql.php` — PASS (проверяет прежний empty-start subset;
  историческая строка stdout о BLOCKED не является статусом новых pre-round tests).
- `acs_prepared_items_sql.php`, `acs_prepared_execution_sql.php` — PASS.
- `php -l` всех 18 изменённых/новых PHP — PASS; `git diff --check` — PASS.
- Полный suite и `node --check` не запускались: JS не менялся.
