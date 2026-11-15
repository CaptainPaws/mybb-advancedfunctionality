# ACS-9.3.4 — Prepared/Ambush/Transform/Advanced Effects Integration

База: main `f777d15f` (ACS-9.3.3, PR #1063). Один ограниченный Integration PR.
Основание: четыре нормативных документа ACS, KB Mechanics Contract, отчёты
4.5 Revision 2, 5.3.2, 6.3.2, 7.3.3, 8.3.3 и этапа 9, включая amendments
9.1.1B, 9.1.2A/B и 9.2.2A/B/C. Полная реализация всех возможных KB combinations
не заявляется. Следующий этап не начинается.

## Implemented

Сохранён один путь Builder → Draft/Seal → PublishService → CombatCommitWriter →
Resolver/Effects → immutable receipt. AmbushStartService использует ранее
реализованный start intent и тот же Resolver. Новых Resolver, Clock, Status Engine,
OperationStore, publication coordinator, owner ledger или RNG нет.
MybbPostGateway, общая MyBB+ACS transaction и publication flags не изменены.
`docs/ROADMAP.md`, исторические migrations/checksums и отчёты сохранены.

Закрыты интеграционные разрывы в читателях и передаче контекста:

- Builder выдаёт разрешённому контроллеру компактный `advanced_state`: текущие
  формы (slot/mode/duration), доступные temporary grants (alias/uses/duration),
  reflect/absorb presence/duration. Нет raw definitions, original source identity,
  Inventory bindings или causal samples. Истёкшие/исчерпанные grants исключены.
- Behavior evaluation каталога использует исходный pinned key granted ability,
  как Builder preparation и Resolver, а не технический alias `granted:<id>`.
- Pre-round DTO получает бюджет через `preRoundBudget` и отдельно показывает
  оплаченный commitment и available AP каждой группы. Поле turn.ap сохраняет
  контракт бюджета seal quota: оплаченный commitment нельзя вычесть второй раз.
  Это не создаёт round/EntityTurn. Поддержанный бюджет по-прежнему 3 AP;
  произвольные KB budget overrides этим PR не добавлены.
- Prepared child получает отсутствующие authenticated resource proofs для
  absorption из своей QueryService inspection. Новые proofs не синтезируются;
  текущие virtual HP/resources и уже исполненные эффекты остаются authoritative.
- CombatCardQuery дополняет прежние owner/GM immutable details whitelist-сводками
  форм, grants, linked transfer, prepared result, reflect и absorb. Linked IDs
  переводятся через сохранённый status id_map. Player/delegate/observer остаются
  на прежнем status-only DTO; outsider не получает карточку. Подробности не
  загружаются для публичного reader. Карточка не запускает Resolver или writes.
- В существующем lazy Builder показана сводка advanced_state; подготовка и GM
  ambush controls остаются в прежней панели. В карточке добавлены подписи
  copy/steal/dispel; для действия без damage components не рисуется фиктивный
  блок hit/crit. Второго редактора, глобального runtime или redesign нет.

## Locally verified

PHP 8.5.12 CLI/mysqli и disposable MySQL 8.0.46, authoritative same-writer SQL
harness. Проверки используют существующие заранее созданные native PID;
application не создаёт MyBB posts. Полный suite и browser E2E не запускались.
Команды: `docker exec -w /repo acs911-php php tests/<fixture>.php
/tmp/acs222-socket/mysql8.sock [mode]`.

| Fixture / mode | Проверяемый результат |
| --- | --- |
| acs_stage9_integration_sql / summon | Реальный spawn, общий roster character+summon, отдельный NPC turn, paid summon preparation, закрытый групповой ход, duplicate preparation/trigger, без второго AP/post |
| acs_stage9_integration_sql / effects | Реальные copy/use/exhaustion/expiry, pinned stat policies, linked steal/dispel, ticks; owner/GM/player/delegate/observer/outsider card matrix; read-only DTO; populated migration/lifecycle preservation |
| acs_stage9_prepared_spatial_sql | Реальная paid preparation с fixed target, последующая GM смена зоны и назначение cover, causal execution с актуальным DEF+20, AP один раз, replay |
| acs_prepared_owner_sql / normal | A7 подготовил, B8 вызвал trigger; exact owner reservation/qty/pin, расход один раз, late rollback, lost ACK, replay и retained history после revoke |
| acs_hidden_prepared_sql / search | Independent entity/prepared awareness, unknown absence, detection retain, sealed search replay, explicit reveal без identity disclosure, rollback/recovery |
| acs_ambush_api_sql / normal | GM-only POST/CSRF/CAS, 2 AP commitment → 1 AP первого roster, реальный Resolver и две owner reservations, hidden DTO, double click, lookup/lost ACK, reactivation |
| acs_ambush_api_sql / multiple | Две подготовки общей группы, commitment=3 → 0 AP, passed turn через штатные boundaries, один round; те же API/owner/replay assertions |
| acs_ambush_execution_sql / revoked | Отзыв текущего owner grant блокирует старт; HP/resources/roster/Inventory не получают частичный commit, causal seal сохранён |
| acs_transform_sql / normal | modifier/override/hybrid, primary compatibility, absolute HP clamp/return, equipment retain/suppress/adapt, status policies/ticks, identity/AP/resources/cooldown retention, CAS/rollback |
| acs_transform_sql / prepared | Настоящая оплаченная подготовка, истечение формы на boundary, trigger admission без подмены ability/rolls или нового AP |
| acs_prepared_execution_conditions_sql / delegation, expired | Отозванный delegate не исполняет прежнее обязательство; expired prepared не создаёт execution/RNG; duplicate stability |
| acs_prepared_phases_sql / stale after_damage | Late rollback, durable armed tape; stale original target требует reconfirm без изменения исходных/causal rolls |
| acs_effect_responses_sql | Stored child check, default/bounded reflection, cycle guard до RNG, current cover/LoE/hidden target, real resource CAS и barrier queue, no double absorption/AP/turn/post, rollback/ACK recovery |
| acs_stage8_integration_sql | Сохранение object/cooperation/awareness/zone/cover pipeline, immutable publication и audience boundaries |
| acs_stage7_integration_sql | Shared character/pet AP, control/delegation/recovery и cosmetic mask без изменения mechanical identity/tape |

Новый fixture расширяет существующие authoritative setups optional hooks;
обычные сценарии без hooks не изменены. `php -l` всех изменённых PHP,
`node --check` единственного изменённого JS и `git diff --check` — PASS.

## Матрица A1.75–A1.77 и A1.80–A1.86

| Требование | Поддержанный интеграционный путь / граница |
| --- | --- |
| A1.75 prepared | Paid/sealed commitment, закрытие ordinary turn, stored causal identity/rolls, no trigger AP, current delegation/form/target revalidation, owner-reserved items, durable replay |
| A1.76 hidden ambush | Independent prepared awareness; discovery не отменяет; explicit reveal audience; raw contracts отсутствуют в чужом DTO |
| A1.77 encounter start | preparation → ambush_ready → durable encounter_start/ambush_resolution → ordinary first roster; exact paid commitment, exhausted group boundaries, duplicate/ACK lookup |
| A1.80 behavior | Прежний Behavior/Control projection; granted aliases проверяются по mechanical source key; новых прав delegate/GM нет |
| A1.81 stealth | Audience/Awareness остаются authoritative; secret source/target не восстанавливаются из key или карточки |
| A1.82 zones | Прежние pinned Spatial/Query/Resolver checks, без geometry engine или free movement |
| A1.83 cover | Прежний Cover/Damage и separate LoE; reflected child не проходит complete cover, ignores_cover не означает wall penetration |
| A1.84 spawn | Настоящий summon выполняет paid prepared из общей группы; independent NPC сохраняет отдельный бюджет; нет extra turn |
| A1.85 transform | Temporary layers на прежнем entity, absolute HP, current equipment projection, no resource/AP/cooldown reset; prepared form requirement повторно проверяется |
| A1.86 advanced effects | Temporary granted abilities/usage, linked independent-effect transfer/dispel, bounded causal reflect, barrier→resource→HP absorption и immutable receipt |

Сохранены CAS, pins, source provenance, Clock boundaries, custody/reservations и
revision-2 quarantine/recovery. Неизвестный PID/owner outcome не становится
успешной механикой или новым consume. Item authorization остаётся в Stage-6
OwnerProtocol::participate на том же writer; GM/start actor не impersonates owner.
Confirmed owner consent не заменяет текущую owner policy/revocation check.

## BLOCKED / UNSUPPORTED и пределы проверки

Наследуются точные typed subsets предыдущих отчётов; этот integration PR не
расширяет KB language или Inventory authority:

- Generic recursive prepared chains, derived-event triggers и произвольные
  start descriptors не поддержаны. Combat phase/start descriptor не подменяются.
- Prepared-trigger reflection, prepared-transform chains, temporary grant как
  prepared/reaction/start action, смешанные unsupported special operations не
  объявляются готовыми по наличию нескольких primary actions.
- Sustained effect steal/смена upkeep payer остаются UNSUPPORTED. Fixture проверяет
  отказ и сохранение tick/duration history у разрешённого independent effect;
  успешная кража sustained не заявляется и старые ticks/upkeep не возобновляются.
- Non-flat/unproven equipment, borrowed-kit adapt/suppress, virtual same-post
  equipment swap, отдельный form HP pool и generic status recalculate вне
  поддержанного typed binding остаются закрытыми.
- Resource absorption требует proven existing integer resource definition и
  поддержанную conversion policy; receipt или GM role не создают ресурс/grant.
- Paid prepared после GM zone relocation и subsequent partial cover assignment
  проверен end-to-end. Это не доказывает все combinations forced movement,
  dynamic obstacles, penetration и recursive prepared chains.
- Новый merge resource proofs проверен общим response/owner pipeline; отдельный
  third-party prepared→resource-absorb SQL scenario не заявлен verified.

Известный старый intercept fixture падает на baseline с
`ACS_AUTHORITATIVE_CONNECTION_REQUIRED` (SnapshotReadOnlyDb), как зафиксировано
в ACS-9.3.3. В этом PR он не переписан и его падение не выдано за regression PASS.

## Изменённые файлы и migrations

ACS modules: Application/EffectSummary.php (новый whitelist helper),
Application/BuilderService.php; Infrastructure/PreparedExecutionPersistence.php,
CombatCardQuery.php, TerminalReceiptPersistence.php;
Presentation/CombatCard.php, BuilderController.php; assets/builder.js.
Fixtures: новый tests/acs_stage9_integration_sql.php и optional hooks в
acs_spawn_sql.php, acs_ability_operations_sql.php, acs_cover_sql.php; новый
acs_stage9_prepared_spatial_sql.php. Stage-7/8 integration fixtures больше не
сравнивают текущую версию JS/ledger с историческими literals: проверяются
наличие versioned asset и AF_ACS_Migrations::VERSION. Assertions о сохранении
всех captured rows не ослаблены. Этот отчёт.

Новых migrations нет. Ledger после 9.3.3: `022_pre_round_commitments`.
Повторный runner дважды проверяется на populated механике с точным сравнением
ledger и captured rows; deactivate/reactivate также не должны менять формы,
transfers, receipts, Inventory или rolls. Ambush API отдельно проверяет повторную
активацию с populated start/commitments/reservations. Миграции 001–022 неизменны.

## Installed NOT_VERIFIED / будущая ручная приёмка

На warprift.ru не устанавливалось; MyBB 1.8.41 installed hooks/theme/providers,
production KB и реальные grants не проверены. Публикация автоматически не
включалась. SQL verification не является browser/install acceptance.

Ручная приёмка на staging:

1. Owner/GM, player, delegated controller, observer и outsider: Builder/card/JSON
   без hidden contracts, source identity, private targets, samples и item bindings.
2. Lazy showthread GM panel: preparation, commitments, ready, start, terminal
   result; lost ACK сохраняет operation key и предлагает lookup, без double click.
3. Участник с 2/3 AP commitment, pet/summon и independent NPC; exhausted boundaries
   один раз; empty start и ordinary encounter сохраняют поведение.
4. Форма и обратное превращение с текущей экипировкой, статусами и cooldowns;
   grants видны только пока доступны. Cosmetic Appearance mask не меняет механику.
5. Prepared после zone move/cover reassignment, stale dependency reconfirm на
   прежнем tape; явные unsupported combinations не получают guessed execution.
6. Реальные owner revocation/custody/Inventory outage, unknown outcome quarantine,
   lookup recovery; отсутствие лишнего post, round, AP refresh и повторного RNG.
