# ACS-9.3.1 — Transform effects, form layers and equipment policies

База main `6465395b` (merge ACS-9.2.2C, PR #1060). Основания: A1.85, A1.1,
A1.75; четыре нормативных ACS документа, KB Mechanics Contract и отчёты 9.1.2,
7.3.3, 6.2.1, 6.3.2, 5.1.2. Один Backend PR. `docs/ROADMAP.md`, исторические
отчёты, MyBB Core, CharacterSheets, AdvancedInventory и AdvancedAppearance
не изменены. ACS-9.3.2 не начинается; publication_enabled не включён.

## Implemented: typed transform subset

Механический transform — новый typed `mechanical_parameters.transform` в
существующем status instance. Ability применяет его прежним status component
через Query → Resolver → Effects; definition и source pin хранятся прежними
sealed snapshots/effects. Новый Domain/FormLayers — чистая проекция временных
слоёв, не второй Resolver, CharacterSheetAdapter, Inventory или Clock.
Схема `ic-acs.transform.v1` подробно описана в KB Mechanics Contract.

Поддержаны modifier (additive stat-point deltas), override (явные базовые значения),
hybrid (override + additive modifiers), max_hp/accuracy/speed/crit_rate/crit_dmg/def.
Вычисления выполняет прежний Modifiers engine с explicit form stage; damage
остаётся в прежнем Damage/Resolver pipeline. DEF формы добавляется к существующей
defense projection как дельта, сохраняя отдельные cover bonuses.

В supported casting subset ability содержит один on_cast status, self_only
policy, без смешанных damage/removal компонентов. Стоимость — существующий
положительный KB AP cost. Обратное превращение возможно разрешённым on_cast
dispel со своей стоимостью либо штатным Clock expiry/combat reset. Не создаются
бесплатные action/reaction/turn. Длительность использует существующие единицы и
границы, а не новый scheduler.

Одна active primary_form, дополнительные form_modifier (additive only) и
cosmetic_form (без численных изменений). Совместимость взаимная и явная: обе
формы перечисляют ключ другой формы. Второй primary отклоняется, автоматического
выбора по названию или замены нет. Порядок Modifiers детерминирован существующим
applied_order. Все authoritative status writers сериализованы прежним session
lock и effect CAS; conflicting stored primary set отвергается при projection.
Новая таблица/миграция не нужна: schema ledger остаётся 022. Это не сертификация
произвольных привилегированных SQL writers в обход ACS.

## Identity, HP and immutable base

Форма не пишет entities, owner UID, control_group, controller/delegation base,
session/encounter identity, CS build_json, stats_json или registration snapshot.
Механическое состояние — временный effect с version, source/pins и историей.
Cosmetic_form не читает маску/аватар/фон/рамку и не меняет Appearance assignments.
Смена маски не регистрируется как transform и не вызывает этот pipeline.

При первом активном слое status instance сохраняет immutable form_base_max_hp.
Совместимые слои используют ту же базу. Effective maximum вычисляется из неё;
HP всегда `min(previous_absolute_hp, new_max_hp)`. Снижение 90→max60 даёт HP60;
рост max до120 сохраняет прежние90. Возврат после урона до40 оставляет40, не
восстанавливает старые90. Dead/incapacitated не становятся active от снятия формы.
Для характеристик max HP отражается также в текущей stats.hp projection.

HP/max и resource hp maximum сохраняются прежними CAS projections вместе со
status instance/event/receipt. Clock boundary и combat-reset cleanup также
проецируют восстановленный maximum в entity_states/resources в той же ACS
transaction. Не выполняется healing event при возврате. Отдельный form HP pool,
сторонний set_max_hp во время active form и неизвестные HP stacking contracts
остаются закрытыми.

AP, sealed quota, reaction_used, текущие non-HP ресурсы, cooldown rows и закрытые
ходы не сбрасываются. Удалённая форма не отменяет paid costs. Естественный AP
refresh/Clock tick выполняется только прежним разрешённым boundary, не от смены
формы. Изменения form version входят в прежний status dependency hash; stale
seal требует прежней revalidation/reconfirm, без reroll.

## Equipment policies

Используются retained CharacterSheets/equipment baseline и существующая Stage-6
current loadout/custody projection. Inventory authority/version checks остаются
в loadoutStats. Новый Equipment.formContributions выделяет только доказанные
текущие flat bonuses. Для stat override сначала отделяется equipment contribution,
затем применяется form base/modifiers и текущий разрешённый equipment вклад
добавляется ровно один раз. Retain не теряет бонус из-за override.

- retain сохраняет разрешённые текущие бонусы.
- suppress исключает явно указанные slot contributions (factor=0).
- adapt применяет явные KB slot factors к текущему комплекту, например 0.5.

Остальные слоты остаются с factor=1, пустой слот даёт0. Количество, собственник,
physical equip/support slots, global reservations, custody и owner grants не
изменяются. При возврате нет записи старого набора поверх текущего: следующая
проекция снова читает current ACS equipment rows. Виртуальная same-publication
смена оружия под suppress/adapt пока BLOCKED; неизвестная/non-flat provenance и
borrowed custody kit не объявляются доказанными. Retain не выдаёт новых прав на
расходники; существующий owner protocol не меняется.

## Status policies and boundaries

Primary form задаёт exact effect_key rules, default=persist:

- persist не изменяет instance, pins или выполненные тики.
- suppress временно исключает поддержанные periodic/action-blocking mechanics;
  identity, duration и last_tick/last_duration boundaries сохраняются.
- remove завершает matching instances с version/audit, не восстанавливает их
  после возврата.
- recalculate масштабирует только будущие pinned periodic ticks по явному
  periodic_factor; не создаёт заново экземпляр/HoT/DoT и не вызывает on_apply.

Clock отмечает обработанный tick boundary и при suppression. Снятие формы и
повтор того же boundary не запускают пропущенный tick задним числом. Heal rounding
и Damage allocation используют прежний Numeric/Damage; RNG/ticks от GET нет.
Неподдержанные non-persist политики для control/behavior/stealth/upkeep, другого
transform и untyped mechanics отвергаются. Это не generic status migration engine.

## Abilities and prepared actions

Пин способности может содержать `ic-acs.form-requirement.v1` с разрешёнными
primary_forms. Только уже доказанный direct KB grant может стать доступным;
форма не импортирует произвольную способность или чужие права. Primary может
дополнительно restrict keys. Query catalog и Resolver проверяют current form.

Shared prepared source inspection проверяет ту же requirement по исходному
sealed definition и текущим statuses, затем выполняет прежние authorization,
KB/target/awareness/range/cover проверки. Child Resolver повторно проверяет
допуск на shared virtual state. Не выбирается новая ability/target, не меняется
исходный seal и не вызывается RNG при form revalidation. Form unavailable —
обычное условие для прежнего retain/cancel policy; form integrity/projection
errors прерывают parent transaction, не маскируются skip.

Causal hydration ordinary/start preparations переносит отсутствующие current
form equipment projections вместе с entities/groups в прежний shared state.
Generic transform/prepared chains и ранее неподдержанные trigger branches не
становятся доступными от этого изменения.

## Locally verified

PHP **8.5.12**, authoritative disposable MySQL **8.0.46**, существующий
acs_combat_items_sql/acs_prepared_items_sql harness, socket
`/tmp/acs222-socket/mysql8.sock` внутри контейнеров. Базы случайные и удаляются
finally; production не используется. Никакого Gateway/native post INSERT.

`acs_transform_sql.php normal`:

- Реальные KB mechanic/ability refs, pinned registration, Query compilation и
  Resolver; modifier/override/hybrid, one primary и совместимые modifier/cosmetic.
- Absolute HP clamp/return, отсутствие revival; equipment retain/suppress/adapt
  на двух реальных retained equipment contributions без двойного учёта.
- Persist/suppress/remove/recalculate future DoT; повтор Clock boundary и снятие
  suppression не повторяют tick.
- Authoritative SQL status/entity/resource projection в существующем command
  transaction. В этом fixture primitive EntityEnd отложен до явного реального
  RoundManagement close; это не альтернативный production publisher.
- Полный rollback после status/HP write, exact pins, effect CAS и stale version
  отказ без partial write. Entities/Inventory/AP/reaction rows сохраняются.
- Штатный gm_skip закрывает существующий turn и истекающую форму; HP maximum
  возвращается без лечения. Повтор boundary не меняет SQL. Положительный cooldown
  с отдельным подтверждённым provenance сохраняется, не сбрасывается на expiry.

`acs_transform_sql.php writer`:

Реальный Draft → Seal → confirmation → PublishService → CombatCommitWriter,
с authoritative readback заранее существующего native fixture post501. Late
outbox fault откатывает effects, HP/resources и AP; повтор подтверждает один
terminal result. AP spent1→2, turn resolved, entity/owner/pins/Inventory и native
posts неизменны. End boundary обрабатывается обычным writer, не новой формой.

`acs_transform_sql.php prepared`:

Форма активна при реальных deferred seal/confirmation/paid registration ability,
требующей wolf. Существующий end boundary истекает форму. Shared trigger admission
отклоняет исходную ability с ACS_FORM_ABILITY_UNAVAILABLE, сохраняя active prepared
и его explicit retain policy. Нет замены, SQL writes или RNG от повторной проверки.
Этот fixture проверяет admission/deferred tape; прежний separate prepared execution
regression проверяет durable causal samples/replay/owner consume.

`acs_transform_sql.php reset`: на явно staged completed encounter штатный
Clock combat_reset снимает форму и возвращает HP maximum без лечения/Inventory
mutation. Повтор reset не меняет SQL; completion service этим не заявляется.

Точечные регрессии: acs_prepared_execution_sql, acs_ambush_execution_sql multiple,
acs_combat_items_sql и acs_pre_round_sql. PHP lint изменённых PHP и git diff --check.
Все перечисленные transform modes и точечные регрессии — PASS. `php -l` всех
11 изменённых/новых PHP и `git diff --check` — PASS. JS не менялся; node --check
не требуется. Полный suite и browser E2E не запускались.

## Remaining BLOCKED / UNSUPPORTED

- Enemy/on_hit transform, mixed transform+damage/removal, hit/damage-phase form
  dispel, отдельный HP pool, resistance/resource-max templates и неизвестные
  modifier operators не поддержаны этим subset.
- Non-flat/unproven equipment, borrowed-kit adapt/suppress и виртуальный swap
  внутри той же публикации под такой policy не исполняются предположительно.
- Generic status recalculate/control/stealth/upkeep transfer, копирование
  способностей/динамический импорт чужих grants и prepared-transform chains
  не реализованы.
- Прежний Query guard положительного cooldown без поддержанного action contract
  не обходится ради transform. Cooldown retention не является новым cooldown
  producer или разрешением кастовать недоступную способность.
- Не добавлены UI форм, отдельный GM transform endpoint, автоматическое лечение,
  новая owner capability, таймер или новая группа/turn.

## Installed NOT_VERIFIED

warprift.ru/MyBB **1.8.41**, реальная KB с новым transform descriptor, production
writer/hooks, реальные equipment/custody providers и установленная theme/Appearance
не проверялись. Нет production migration/merge/deployment или включения публикации.
На staging нужна ручная проверка формы и возврата через разрешённую публикацию,
актуального loadout после изменений, Clock expiry/reset, form-dependent preparation,
прав GM/player/delegate и неизменности CS/Appearance/Inventory history.
