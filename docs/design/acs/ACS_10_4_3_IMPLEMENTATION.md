# ACS-10.4.3 — Stage 10 Lifecycle, Loot, Rewards, Achievements и Archive Integration

База: main `14fb9f32`, merged ACS-10.4.2 (#1075). Один ограниченный Integration PR.
Нормативные основания: ACS Master Specification, Technical Architecture,
Implementation Roadmap, Integration Contracts, Inventory Owner Protocol,
Revision 2 acceptance 4.5, integration reports 6.3.2/7.3.3/8.3.3/9.3.4 и все
11 implementation reports 10.1.1–10.4.2. Исторические отчёты не переписаны.
`docs/ROADMAP.md` не изменён. Этап 11 не начат.

## Implemented

Сохранён один механический путь Builder/Draft/Seal → PublishService →
CombatCommitWriter/Resolver/Effects → immutable receipt. Нового Resolver,
publication coordinator, Balance storage, Inventory ledger, RNG или общего
MyBB+ACS+Inventory+Balance COMMIT нет. Generation, allocation, owner issue,
non-item distribution, achievement, correction и statistics остаются отдельными
идемпотентными операциями с прежними CAS/ключами/retained receipts.

Найден и закрыт lifecycle integration gap статистики: `statistics.refresh` и
`statistics.aggregate` уже сохранялись в outbox, но не были видны в
SessionObligationsQuery и общей recovery facade. Теперь pending producer messages
классифицируются с проверкой hash/session как `statistics`, recovery scope
`statistics.sync`. Сбой/отключение consumer не превращает бой либо reward в failed.
Закрытие с нормальными pending statistics сохраняет очередь и разрешённый recovery.
Повреждённый producer proof остаётся explicitly_blocked по прежней lifecycle policy;
общий bounded scan limit не отменён.

`RecoveryService::inspectStatistics(sid,eid)` читает producer version/visibility и
pending kinds/counts без записей; `recoverStatistics(sid,eid)` вызывает прежний
StatisticsService, включая после closed/archive. Visibility берётся из сохранённой
projection, при отсутствии первой публикации — conservative private. Recovery
не принимает новый payload/recipient/visibility, не меняет source sequence и не
создаёт дополнительный агрегат при неизменном confirmed source.

Существующий AF transport дополнен:

- `statistics_status`: GET, только session_id/encounter_id, read-only;
- `statistics_recover`: POST/CSRF, только session_id/encounter_id;
- прежние `statistics_sync` и `statistics_profile` сохранены.

Все диагностические/recovery пути требуют current native/session GM, проверяют
принадлежность encounter сессии и enabled state. Player, delegate, observer и
outsider не получают queue counts/operation details через новые endpoints.
Consumer недоступен — aggregate остаётся pending; replay и lookup receipts
используют прежние IC-STATS semantics, а не новый delivery engine.
Фонового scheduler, recovery UI redesign или автоматической выдачи по close нет.

## Интеграционные конфликты

| Конфликт | Решение |
| --- | --- |
| Statistics outbox отсутствовал в lifecycle summary | Добавлен scoped producer source с реальным archived recovery capability |
| Общий RecoveryService умел Inventory/loot/Balance, но не statistics | Тонкие inspect/recover delegates, без копии consumer или journal |
| При recovery требовалось повторно сообщать publication visibility | Новый scoped recovery использует retained producer visibility |
| Stage 9 acceptance сравнивал ledger с историческим literal 022 | Assertion теперь сравнивает с AF_ACS_Migrations::VERSION, сохраняя точный snapshot ledger/rows |
| Stage 10 fixtures выбирали либо loot issue, либо rewards/statistics continuation | Добавлены пять optional hooks и два узких composition fixtures; обычные fixture paths сохранены |

Runtime loot/reward/achievement/participation/Resolver/publication/Inventory/Balance
engines не переписаны. Hooks не регистрируются приложением, не дают новых прав и
не являются глобальным тестовым framework.

## Проверенные owner gateways и границы

| Подсистема | Фактическая граница |
| --- | --- |
| ACS mechanics / paid prepared items | Прежний same-writer critical participant; provisional owner result durable только после outer ACS COMMIT; native MyBB R2 остаётся отдельным участником |
| Loot issue | ACS durable loot.issue_prepare → dispatch CAS → standalone AF_ADVINV_OwnerProtocol/PropertyOperations grant → verified owner receipt → отдельный ACS owner/entry acknowledgement |
| Loot unknown ACK | RecoveryService::recoverLoot → retained Inventory lookup; generic Inventory ACK сам не объявляет entry issued; qty/winner/allocation/key сохраняются |
| EXP/currency | RewardService → AF_ACS_BalanceAdapter/AF_ACS_BalanceRewardAuthority → AF_BALANCE_RewardCredit credit/lookup; exact positive minor amounts, отдельный owner COMMIT |
| Partial recipients | Отдельные obligations/intents/status на каждого; issued recipient не кредитуется снова, pending до dispatch и unknown после dispatch различаются |
| Statistics | Отдельный IC-STATS hash/versioned consumer/receipt; consumer failure оставляет pending, не откатывает outcome/reward |
| Permanent CharacterSheets sync | BLOCKED: durable owner API отсутствует; amount marker не является EXP/валютой |
| Compensation | BLOCKED без собственного owner contract; correction не превращает credit/grant в debit/clawback |

Используются прежние issuer/native/current session/owner guards. Inventory failure
после winner не освобождает allocation и не открывает lottery. Balance unknown ACK
не разрешает credit новой key; not_found не становится доказанным rollback.

## Locally verified

PHP 8.5.12 CLI, 64-bit mysqli/mysqlnd/mbstring; MySQL 8.0.46, отдельный disposable
Unix socket. Использован существующий authoritative MySQL harness со случайными
БД и DROP DATABASE в finally. У каждого сценария своя БД. Production storage и
пользовательские сообщения не подключались. Полный suite и browser E2E не запускались.

`acs_stage10_integration_sql` продолжает существующий реальный
combat → pending_resolution → GM outcome → immutable generation harness.
До non-item distribution добавляются allocation/need-greed и реальные standalone
Inventory grants: один owner COMMIT с потерянным ACK, второй durable intent при
отключённом Inventory. Затем прежние настоящие Balance partial/outage/ACK checks,
achievements и statistics/corrections проходят с теми же pending loot obligations.
Actual close/archive из reward harness сохраняет их; archived lookup завершает
первый issue без новой qty, prepared intent второго исполняется с прежним winner/key.
Новая correction создаёт pending statistics уже в archive, затем общий recovery
settles producer outbox. Это последовательные subsystem boundaries, не одна SQL tx.

Второй encounter внутри прежнего statistics fixture явно seeded с согласованными
terminal receipts/post-link/outcome IDs; он не выдан за второй native publish.
Дополнительные зарегистрированные reward participants — явные SQL fixture seeds
до end boundary; они не требуют нанесённого урона. Проверки KB eligibility
engaged/escaped/surrendered/captured/withdrawn применяют реальные pinned правила
к frozen facts; pet/summon и unowned independent NPC проверяются отдельно от
наличия account recipient. Runtime participation/withdrawal/rejoin проверены
отдельным authoritative participation fixture, runtime summon — Stage 9 fixture.

`acs_stage10_advanced_completion_sql` имеет два режима: реальный обычный paid
prepared child и реальный paid ambush start с двумя owner consumes. В обоих end
check видит финальное состояние, затем отдельный GM confirm/replay, completed
statistics при отключённом consumer, actual close/reopen/close/archive и recovery.
Сравниваются mechanics/rolls/resources/owner reservations до/после; новые AP/HP,
child execution и Inventory consume не появляются от lifecycle или статистики.

Все восемь целевых SQL запусков завершились с exit 0; PHP lint изменённых файлов
и git diff --check — PASS. Выплаченные Balance/Inventory owner journal rows
дополнительно сравниваются по operation_key до и после поздних коррекций.

### Минимальная матрица проверок

| № | Проверка | Доказательство |
| --- | --- | --- |
| 1 | GM confirm twice | Integration base и оба advanced completion modes: один receipt/event/outcome |
| 2 | Guaranteed/weighted replay | Реальный generation harness, сохранённые pins/rolls/hash/source replay |
| 3 | Need/greed winner once | Новый integration: две need claims, sealed tie, повтор close без нового RNG/winner |
| 4–5 | Grant ACK lost / outage winner | Реальные owner COMMIT и disabled Inventory, те же allocation/version/key через archive/recovery |
| 6–8 | EXP once, currency once, partial recovery | Настоящий Balance owner: partial recipients, pending-only issue, lookup after unknown COMMIT и ACS ACK rollback |
| 9 | Pet/group no duplication | Reward/loot/statistics reducers и реальные registered pet facts; Stage 9 summon общий бюджет |
| 10 | Escape/surrender eligibility | Pinned KB/frozen facts matrix + authoritative participation actions, capture/withdraw/rejoin |
| 11 | Achievement one_time/progressive | Прежний achievement harness с настоящими reward receipts и source replay |
| 12 | Correction new version | Statistics fixture: include/exclude revisions, immutable originals, retained awards/receipts, без clawback |
| 13 | Archive with pending rewards | Реальный request_close/close/archive с pending Balance и двумя pending Inventory loot intents |
| 14 | Reopen no refill | Оба новых advanced modes + session lifecycle fixture: completed encounter/HP/AP/cooldowns/tape unchanged |
| 15–16 | Completed-only statistics / correction replay | Прежний statistics fixture + actual prepared/ambush completion; old acknowledged и stale unacknowledged версии не откатывают новую |
| 17 | Private/hidden JSON/CSV | Archive fixture: member/GM policy, export scopes/filters/native origins, hidden counters/IDs, deterministic bytes |
| 18 | AdvancedStatistic outage | Integration и оба advanced modes: completed outcome сохранён, pending в lifecycle, archived recovery/replay |
| 19 | Populated reactivation | Новый integration fingerprints всех t_* tables: loot/winners/rewards/achievements/statistics/receipts/archive/balances/Inventory; отдельный lifecycle fixture — held custody/owner recovery |
| 20 | Stage 9 result → completion | Оба новых advanced modes используют прежние fixed tape и actual owner consumption; без второго Resolver/publication |

Целевые команды (через isolated PHP container, socket /tmp/acs222-socket/mysql.sock):

```text
php tests/acs_stage10_integration_sql.php SOCKET
php tests/acs_stage10_advanced_completion_sql.php SOCKET
ACS_STAGE10_END=prepared php tests/acs_stage10_advanced_completion_sql.php SOCKET
php tests/acs_archive_sql.php SOCKET
php tests/acs_session_lifecycle_sql.php SOCKET
php tests/acs_participation_sql.php SOCKET
php tests/acs_stage9_integration_sql.php SOCKET effects
php tests/acs_stage9_integration_sql.php SOCKET summon
php -l <только изменённые/новые PHP>
git diff --check
```

JS не изменён; node --check для изменённого JS неприменим. Целевые fixtures не
доказывают native MyBB INSERT/Gateway acceptance: используются известные fixture
PID 501/502. Основной integration содержит всю цепь Stage 10, но recovery/late
correction намеренно происходят уже после архивирования — закрытие не является
условием для выдачи и не означает завершённости всех обязательств.

## Матрица A1.27–A1.40 и A1.62–A1.66

| Требование | Реализованный путь / доказательство / граница |
| --- | --- |
| A1.27 | Full mechanical result → pending → отдельный GM outcome; normal/prepared/ambush replay |
| A1.28 | Surrender/escape/group withdrawal через прежний paid action pipeline; participation SQL |
| A1.29 | Fixed separate Speed check и pinned pursuit policy; прежний participation fixture, без нового RNG |
| A1.30 | Rejoin/remaining pet group без refill; participation + Stage 9 shared roster; reopen не создаёт turn |
| A1.31 | Capture/release отдельно от HP/life/status; прежние typed participation gates сохранены |
| A1.32 | Custody и permanent owner раздельны; lifecycle SQL сохраняет held custody, archived return/lookup |
| A1.33 | Temporary use не ownership; прежние custody/contracts и global reservations не менялись |
| A1.34 | Generated pool не issued item; exact allocation/issue/owner receipt и pending после archive |
| A1.35 | Pinned guaranteed/independent/weighted generation once; no damage/Luck fallback, retained rolls |
| A1.36 | GM assign/need-greed distinct; один закрытый winner и exact issue key, no reallocation on outage |
| A1.37 | Each/split/manual, exact EXP/currency owner, per-recipient recovery; CS permanent sync BLOCKED |
| A1.38 | Frozen official group eligibility, no required damage, pet/summon dedupe; late live roster не источник награды |
| A1.39 | Owner issue независимо от close; pending Inventory/Balance/statistics и custody сохранены |
| A1.40 | Close/reopen/archive не refill/restart; append-only correction, compensation только отдельным owner contract |
| A1.62 | Immutable event/source PID/sequence + linked correction, full/approximate/hidden archive projection |
| A1.63 | Current native origins/session policy + JSON/CSV scopes, keep_hidden/reveal_results/reveal_all |
| A1.64 | Actual HP/healing/barriers, отдельные entity/group/pet/summon, completed only, no score/auto awards |
| A1.65 | Separate versioned AdvancedStatistic consumer, pending sync/recovery, read-only profile/filtered totals |
| A1.66 | Pinned achievements, one_time/progressive/source dedupe, retained awards/review без автоматического изъятия |

## Ledger и совместимость этапов 1–9

Актуальный ledger **029_statistics**, 001–029. Новых migrations в этом PR нет;
старые migrations/checksums не изменены. Повтор runner и actual deactivate/activate
проверяют populated state. Balance owner upgrade (ACS-10.3.1) и Inventory owner
schema остаются отдельными controlled prerequisites, не ACS DDL на GET/activate.

R2 durable publication/quarantine/lookup, fixed tape, current Clock/AP,
status/reaction/reflect/absorb, custody/global reservations, pets/summons/NPC,
spatial/cover/environment, prepared/ambush и transform/copy/grants сохраняют
свои прежние implementations. Stage 9 effects fixture проверяет rich immutable
cards и role matrix, source transfers/grants и populated lifecycle; summon fixture
— real spawn/source-group/control и paid preparation/trigger без дополнительного AP.
Переоткрытие сессии и награды не переписывают confirmed combat history.
Publication flags/Core/MybbPostGateway не менялись и автоматически не включались.

## Installed NOT_VERIFIED

Установленный warprift.ru, реальные MyBB 1.8.41 source/hooks, write routing,
дополнительные writers/providers, native moderation/post lifecycle, production
KB/Inventory/Balance schemas и фактические permissions не проверены. PHP/MySQL
versions совпадают с целевыми, но SQL fixtures не заменяют installed acceptance.
Нет новой сертификации IC-MYBB-1 READY или разрешения production publication.

## BLOCKED / UNSUPPORTED

- Permanent CharacterSheets sync и автоматическая compensation/clawback не имеют
  нужного owner contract. Исторические award/owner receipts не удаляются.
- Loot grant остаётся ограниченным owner profile consumable/ammo; неописанные
  kinds/metadata/KB schemas не выдаются через обходной INSERT.
- General arbitrary KB predicates/conditions, contribution-based reward default,
  automatic reward reallocation, reroll, owner actor substitution и blind retry
  unknown dispatch не поддержаны.
- Statistics первой публикации по-прежнему explicit, visibility закрепляется;
  фонового scheduler нет. Точный profile aggregate требует разрешённый reveal_all
  archive/native-session scope; encounters со скрытой механикой исключаются целиком.
- Remote/multi-database critical consume, новая общая SQL transaction и
  автоматический восстановительный mechanical commit в archived session не добавлены.
- Полная стилизация/lazy UI acceptance, notifications и прочий этап 11 не начаты.

## Будущая ручная приёмка

1. На staging проверить фактические InnoDB/write routing, ACS 029 и отдельные owner
   Inventory/Balance upgrades; повтор activation на populated copies без изменения
   balances/ownership/custody/receipts и без включения publication.
2. С GM, owner, player, delegate, observer и outsider проверить Builder/card/archive,
   JSON/CSV/profile/totals и новые statistics_status/recover. Hidden IDs/names/counts
   не должны появляться в selectors, disabled options, errors или HTML.
3. По явным KB правилам пройти normal и prepared/ambush completion, allocation,
   item grant, EXP/currency/achievement, correction и statistics. Проверить исходные
   PID links, unchanged fixed tape и один owner receipt на reward/recipient.
4. Прервать ACK после owner COMMIT; отключить Inventory, Balance и AdvancedStatistic
   по отдельности. Использовать retained key/lookup, сверить preserved winner,
   partial recipient states и отсутствие повторной generation/credit/grant.
5. Закрыть/переоткрыть/архивировать с pending issue/sync и held custody; выполнить
   разрешённый recovery в archive. Сравнить HP/AP/cooldowns/turns и все owner ledgers.
6. Оставить неподдержанный CS sync/compensation явно blocked, без ручного удаления
   audit либо имитации debit через положительный credit API.
