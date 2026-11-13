# ACS-8.3.3 — Environment/Awareness/Objectives/Spatial integration

База main: `53b9ed1b` (merge ACS-8.3.2, PR #1049). Один Integration PR.
Среда локальных SQL checks: PHP 8.5.12 / disposable MySQL 8.0.46.
`docs/ROADMAP.md` не изменён. Этап 9 не начат.

## Источники

Прочитаны ACS_MASTER_SPECIFICATION, ACS_TECHNICAL_ARCHITECTURE,
ACS_IMPLEMENTATION_ROADMAP, ACS_INTEGRATION_CONTRACTS,
ACS_KB_MECHANICS_CONTRACT и отчёты:

- [ACS-4.5-R2 acceptance](ACS_4_5_R2_INTEGRATION_ACCEPTANCE.md);
- [intercept 5.3.2](ACS_5_3_2_IMPLEMENTATION.md);
- [custody/owner integration 6.3.2](ACS_6_3_2_IMPLEMENTATION.md);
- [control/spawn integration 7.3.3](ACS_7_3_3_IMPLEMENTATION.md);
- [environment 8.1.1](ACS_8_1_1_IMPLEMENTATION.md),
  [awareness 8.1.2](ACS_8_1_2_IMPLEMENTATION.md);
- [interaction 8.2.1](ACS_8_2_1_IMPLEMENTATION.md),
  [cooperation 8.2.2](ACS_8_2_2_IMPLEMENTATION.md);
- [spatial 8.3.1](ACS_8_3_1_IMPLEMENTATION.md),
  [cover/obstacles 8.3.2](ACS_8_3_2_IMPLEMENTATION.md).

Эти отчёты — основание существующих implementations и ограничений; повторной
реализации прошлых этапов нет. Полные KB grammars остаются в
[mechanics contract](../../contracts/ACS_KB_MECHANICS_CONTRACT.md).

## Implemented

Сохранён единственный pipeline: Builder → Draft/Seal → PublishService →
CombatCommitWriter → Resolver/Effects → immutable event/result/receipt.
Authorization, control/delegation, native/session rights, shared group AP,
behavior, audience/target policy и сохранённые rolls не заменяются новым
процессором. Hit/crit, defenses, reactions/redirect, barriers, integer damage
и derived effects сохраняют прежний порядок фаз. Перечисление проверок задачи
не введено как новая универсальная последовательность фаз.

Исправлены четыре выявленных integration gaps:

1. Object interaction/cooperative actions теперь проверяют object blockers/LoE
   тем же Cover helper после Spatial range. До исправления эти ветви выходили
   из Resolver раньше ordinary-strike Cover check. Assist отдельно проверяет
   actual recipient: active/ally, stealth targetability, range и blockers/LoE.
   Доступная задача не даёт права помогать недоступному получателю. Pinned
   forced movement также проходит source→target cover/obstacle gate.
2. Builder сохраняет object ability binding по action index. При двух одинаковых
   interaction keys каждый Resolver step использует свою retained reservation;
   последний key больше не заменяет tool pin первого шага. Sealed actions,
   Inventory intents/lookup/consume receipts и action costs остаются прежними.
3. Cooperative progress, catalog, prepare и draft restore используют одну
   awareness/position policy. Скрытый объект не раскрывается через progress
   aggregate, скрытый assist recipient не возвращается из старого draft.
4. Spatial configure закрепляет полный encounter config новым immutable
   snapshot вместе с отдельным map snapshot. Прежде round readiness сравнивал
   дополненный config со старым base snapshot и блокировал следующий раунд.
   Для legacy 8.3.1 base-only snapshot readiness сначала проверяет полный
   отдельный map pin, затем сравнивает все остальные base fields. Только две
   подтверждённые spatial extension fields допускаются вне старого base pin.
   Никаких read-side записей, изменения старых snapshot hashes или истории.

Все последующие разрешённые actions исполняются по общему virtual state.
Смешанный move → unlock/progress → attack использует новую позицию и 3 общих
AP. Invalid later action не оставляет частичный object/spatial/HP результат.
Preview не делает RNG или записи; seal сохраняет tape, quota и pinned checks,
не исполняя durable mechanics. Другой объект вместо original target не выбирается.
Требования reconfirm и frozen quarantine/recovery остаются прежними.

Spawn использует Stage-7 identity: real combat.resolved event + spawn_index,
existing entity_id и immutable template snapshot. Проверен combat spawn и его
последующее unlock/cover/contribution в обычном следующем admitted group turn,
без второго object ID, дополнительных AP или повторного spawn при recovery.

Existing Stage-8 Builder уже содержит известные object actions, awareness,
выбранные targets, spatial positions/transitions/contact, cover и objective
progress. Исправления подключены к этим DTO, второго UI runtime/editor нет.
CombatCardQuery/ThreadComponents остаются SQL-only readers с прежним native/
audience cache и GM detail gate; public Publish/Recovery receipts фильтруются
AudienceService. Нового notification/export/outbox consumer не добавлено.

Stage-6 owner authority, global reservations, custody и recovery не изменены.
Stage-7 pet/summon shared AP, exclusive/shared/gm_pool, delegation/revocation,
temporary control и behavior gates сохранены. Расход инструмента — только
прежний IC-INV-1 same-writer participant; requires не превращён в consume.

## Locally verified

Использованы существующие authoritative Stage-6/7 MySQL fixtures с одним новым
смешанным extension fixture, без нового тестового framework. Все БД случайные,
создаются на disposable socket `/tmp/acs222-socket/mysql8.sock` и удаляются в
finally. Native posts — известные fixture PID 501/502; Gateway не вызывается,
production messages/storage не используются.

| Проверка задачи | Фактическое доказательство |
|---|---|
| 1–2: скрытая ловушка HTML/JSON, GM details | awareness SQL; mixed role/HTML matrix |
| 3–4: audience и search без нового roll | awareness SQL: group/side, retained tape/replay |
| 5–6: interaction version/progress/repeat | object interaction SQL + mixed unlock/version |
| 7–8: multiple contributions/completion once/prose-only | cooperation SQL: два contributor/group AP, UNIQUE/receipt, prose denial |
| 9–10: move → attack, shared AP | mixed move→unlock→attack; Stage-7 character/pet/summon shared group |
| 11–12: KB cover, ignore не проходит wall | cover SQL; pure cover/intercept fixture |
| 13: destruction меняет connection | cover SQL: version/status/LoE и duplicate destruction |
| 14: own cover interceptor, same samples | pure acs_cover_intercept.php: собственные DEF/RES/barriers/LoE |
| 15: hidden mass без reveal | awareness SQL + cover SQL zone mass/filtering |
| 16: IC-INV-1 tool costs | object interaction SQL: requires, consumes, два identical keys с разными reservations |
| 17: submission/recovery без второго event | mixed capture сравнивает все ACS tables до/после replay/recover |
| 18: late rollback всего ACS state | mixed movement/object/HP/AP; cover graph/assignment; cooperative completion и owner rollback |
| 19: смена стороны после seal | mixed audited side_change: blocked hostile target, тот же tape/original target |
| 20: cosmetic mask identity | mixed input mask/username substitution сохраняет confirmation/tape; Stage-7 cosmetic fixture |

Целевые fixtures:

- `tests/acs_stage8_integration_sql.php` (новый extension existing cover setup):
  obstacle-gated interaction/contribution/assist, independent recipient range,
  valid/invalid mixed virtual sequences, shared AP, late fault, replay/recovery,
  side conflict, cosmetic separation, combat-spawn follow-up, rich reactivation;
- `tests/acs_object_interaction_sql.php`: существующая матрица 8.2.1 и новая
  проверка двух identical consumes-tool keys в одном post, отдельные owner
  receipts/reservations/contributions, расход ровно двух единиц;
- `tests/acs_cooperation_sql.php`: objective/check/assist, completion once,
  concurrent writer/CAS, retained rolls, inactive/object policy, rollback;
- `tests/acs_awareness_sql.php`: stealth/detection, audience, hidden mass,
  filtered Publish/Recovery receipts, cards/cache и rollback;
- `tests/acs_cover_sql.php` и `tests/acs_environment_sql.php`: cover/obstacle,
  retained tape, destruction/trigger/lifetime, version/CAS и late rollback;
- `tests/acs_stage7_integration_sql.php`: pet/summon/group AP, lost ACK/recovery,
  masks, exclusive/gm_pool admission. Исправлен только устаревший assertion
  cache-version Builder asset 0812 → фактический main 0832; JS не менялся;
- `tests/acs_delegation_sql.php`, `tests/acs_custody_sql.php`: scoped consent,
  revocation/expiry после publication, category guards, owner authority,
  temporary use/return, contention и unknown-result recovery;
- `tests/acs_cover_intercept.php`: existing pure Resolver/Effects fixture,
  own DEF/RES/cover и blocked redirect, сохранённые hit/crit samples.

PHP lint изменённых/новых PHP и `git diff --check` — PASS. JavaScript не
менялся, `node --check` для неизменённого JS не запускался. Полный suite,
browser E2E и проверки всех аддонов не запускались.

## Роли и секретность

В mixed fixture отдельно проверены owner, GM, player, delegate, observer и
посторонний пользователь. Owner/GM получают разрешённые objective details.
Player и delegate не получают unknown object/GM details по факту контроля.
Delegate использует тот же turn и maximum AP; новая группа не создаётся.
Control-group suspected hint не передаётся observer; encounter-wide knowledge
другой сущности при этом остаётся разрешённым. Outsider отклоняется до DTO.
HTML renderer не пишет БД и не содержит private object/objective strings.

После скрытия recipient старый assist draft не восстанавливается. После
скрытия позиции задачи её progress и label отсутствуют в aggregate DTO.
Hidden target IDs, raw snapshots, policies и GM reasons не включаются в
player selectors/disabled choices. Нового shared GM/player cache нет.

## Миграции и сохранность

Новых миграций в ACS-8.3.3 **нет**. Ledger остаётся `020_cover`; исторические
001–020 и их checksums не изменены. Repeated run/assertInstalled не создаёт
игровое состояние. Реальный deactivate → activate через прежний AF lifecycle
проверен с local ACP identity/CSRF, без включения publication.

До/после буквально совпадают все ACS rows, включая objects, zones/connections,
positions, cover, awareness, objective/contributions, published sealed tape,
custody, events/results/completed receipts. Отдельно совпадают Inventory items,
reservations и owner state. Существуют два completed combat receipts, непустые
awareness/contributions и held custody, а не только пустая схема. Legacy
base-only encounter config pin проходит обычный следующий раунд после полной
проверки map snapshot; чтение не переписывает старую запись.

## Installed NOT_VERIFIED

MyBB 1.8.41 указан как целевая среда; установленный warprift.ru, реальные MyBB
source/hooks, routing/engines, AF enable persistence, Appearance/ATF/Editor,
дополнительные owner addons и publication acceptance не проверены. Локальные
PHP 8.5.12/MySQL 8.0.46 PASS не означают installed production READY.

MyBB Core/MybbPostGateway, PublishService/RecoveryService protocol и
`publication_enabled=false` не изменялись. Нет общей MyBB+ACS transaction,
blind retry неизвестного PID, нового publication process или owner side-effect
consumers. Сохраняются все blocking gates ACS-4.5-R2 и Stage-6/7 acceptance.

## Explicitly BLOCKED / UNSUPPORTED

Сохраняются точные KB subsets шести предыдущих отчётов: нет arbitrary PHP/JSON
callbacks, неизвестных formulas/predicates, геометрии/grid/pathfinding,
автоматического reveal, automatic hidden-creature area selector, attacks-object
вне прежнего fixed durability contract, universal opportunity attacks, rewards
или обычного unsafe Inventory remove_item. Derived reflection/passive damage
не получает новый неописанный cover contract.

Не вводятся ссылки на ещё не получивший entity_id future spawn, blind selection
неизвестной цели после search внутри того же draft или автоматическая замена
sealed target. Такие новые wire/KB capabilities требуют отдельного утверждённого
контракта. Post-confirmed stale dependencies остаются в прежнем recovery/quarantine.
Полноценный интерфейс отложен до этапа 11, installed R2 acceptance — отдельная
проверка. Старый legacy SQL intercept harness из 8.3.2 не переделывался; current
pure intercept и authoritative SQL fixtures не подменяют native installed test.

## Матрица требований

| Требование | Implemented | Locally verified | Installed / граница subset |
|---|---|---|---|
| A1.57 | server target policy/audience, original/resolved target | role/HTML, side conflict, intercept | NOT_VERIFIED; новые automatic area selectors unsupported |
| A1.70 | versioned encounter/spatial config и immutable pins | configure/legacy snapshot/next round | NOT_VERIFIED; reward/completion config не расширен |
| A1.71 | object identity/state/durability/triggers | environment + spawn follow-up/destruction | NOT_VERIFIED; только typed KB subset |
| A1.72 | knowledge отдельно от stealth/status | awareness/search/group/side/replay | NOT_VERIFIED; arbitrary detection predicates unsupported |
| A1.73 | object checks/progress/tools | mixed sequence + IC-INV-1 repeated tool keys | NOT_VERIFIED; текущие formulas/outcomes contract |
| A1.74 | assist/cooperative objective/contribution UNIQUE | sequential contributors/completion/rollback | NOT_VERIFIED; sequential commit, без distributed publication |
| A1.79 | explicit target relationships/friendly-fire gates | Stage-7 side/control + side conflict | NOT_VERIFIED; прежние KB mass capabilities |
| A1.81 | hidden mechanics без knowledge grants | hidden mass/role/privacy/filtering | NOT_VERIFIED; full hidden-area UI не добавлен |
| A1.82 | optional zones/range/engagement/group movement | mixed move→attack, config round boundary | NOT_VERIFIED; direct connections, без grid |
| A1.83 | explicit cover/obstacle/LoE/destruction | cover/intercept/rollback + interaction gates | NOT_VERIFIED; flat bonuses/direct blockers |

## Будущая ручная приёмка

1. На staging сверить установленный ledger/engines/routing и AF lifecycle;
   повторная активация сохраняет populated environment, obligations и receipts.
2. С реальными GM/player/delegate/observer проверить Builder HTML/JSON/cards:
   unknown trap, hints/known objects, скрытые позиции/recipient, безопасные ошибки.
3. Выполнить разрешённые move→interaction→attack, assist/contributions,
   destruction/cover/intercept и reconfirm после изменения стороны/объекта;
   сверить реальные group AP, tape и immutable event sequence.
4. С реальным Inventory проверить requires/consumes, global reservations/custody,
   потерю ACK/lookup/recovery и поздний ACS rollback, без нового owner расхода.
5. В рамках прежней отдельной R2 acceptance проверить native known/unknown PID,
   moderation/quarantine, duplicate/recovery и маски. Публикацию не включать на
   основании локальных fixtures; новое разрешение этим отчётом не выдаётся.
