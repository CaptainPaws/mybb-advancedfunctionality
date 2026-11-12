# ACS-7.2.2 — Summon group AP, roster, lifetime и despawn

База main `467fc4d1` (merge ACS-7.2.1, PR #1039). Основание: A1.1, A1.30,
A1.47–A1.49, A1.84; четыре нормативных документа и отчёты 7.2.1,
2.3.1/2.3.2, 5.2.1/5.2.2, 6.3.2. Один ограниченный PR.
`docs/ROADMAP.md` не изменён. ACS-7.3.1 не реализован.

## Схема, identity и совместимость

Новых таблиц/колонок/миграций нет; registry остаётся 015_delegations.
Runtime lifecycle находится в существующем entities.definition_json,
CAS — entities.entity_version. Immutable spawn spec/source snapshot, entity ID,
source_event_id/spawn_index и история не переписываются. Старые 7.2.1 identity
без lifecycle получают начальное состояние из своего pinned spec при чтении;
первое реальное изменение записывается только в авторизованной транзакции.

SummonPersistence читает/проверяет pinned spec и runtime, хранит remaining,
status, boundary/payment stamps, source policy и pending independence.
Переходы имеют уникальные `summon:<entity>:version:<version>` операции;
причина/actor и before/after входят в существующий parent Clock/combat event.
Readiness/Query используют совместимый registration view поверх закреплённого
spawn DTO. Damage принимает этот же проверенный immutable element snapshot,
без фиктивных element hashes или повторной KB нормализации при публикации.

## AP, roster и права

Призыв группы источника использует прежний control_group. Нет нового AP pool,
turn или поста. Следующий roster содержит одну group turn и отдельные
EntityTurn существ, с их собственными HP/status/cooldown/reaction state.
AP=3 создаётся прежним initializeRoundAP один раз на group/round.

Явный KB current_round допускает append нового участника только в pending
turn существующей группы. CAS меняет roster fingerprint/turn version; audit
хранит полные before/after roster. Старые участники, AP spent, quota, reactions,
cooldowns и deadline не меняются. Это append-проекция допуска, а не пересборка
истории. Terminal turn не открывается. В обычном publish сам групповой ход
уже закрыт, поэтому его новый призыв начинает действовать со следующего roster.
Independent NPC получает ordinary turn только при следующем открытии раунда.

Появление добавляет participant с видимостью источника, не раскрывая скрытых
NPC. Роль GM не даёт управление чужим source character/pet. Source-controlled
summon проверяет **текущий** source assignment/роль, group и grants при каждом
действии; source control proof включён в подтверждение. Exact delegation
персонажа не расширяется автоматически на новый summon; действующий явный
shared-group grant проверяется по группе. Предметные права призывам этим PR
не выдаются. После independence действует текущий session GM-pool.

Preview/Seal/commit связывают lifecycle opaque hash и end-boundary trace;
commit повторно проверяет locked facts и выполняет ту же проекцию без второго
countdown. В публичном preview/sealed summary остаются только разрешённые
entities/groups; скрытые зависимости не раскрываются.

PermanentPetSource остаётся прежним owner interface. Регистрация проверяет
owner UID, source fingerprint и постоянный spawn key, выбирает существующую
player group. Нет создания питомца на GET/каждом раунде, отдельного 3 AP или
нового owner/Inventory provider. Реального pet owner plugin репозиторий
по-прежнему не содержит; тест использует явный owner provider fixture.

## Clock, upkeep, исчезновение

Binding описан в ACS_KB_MECHANICS_CONTRACT.md, раздел ACS-7.2.2.
Сохраняются существующие Clock/Effects, numeric policy и boundary identities.

- Round duration уменьшается на round_end данного encounter; own_turn — на
  end самого summon. GET/preview/Seal ничего не тикают в БД.
- Sustain costs используют прежнюю атомарную оплату HP/существующих ресурсов/
  shared AP в source EntityTurn start, после mandatory ticks/status upkeep.
  Shortage прекращает summon без частичного списания. Duplicate start не платит
  повторно. Новые балансы или item upkeep не создаются.
- Явная source_inactive policy проверяется сразу после механической операции:
  persist / despawn / independent. Последующее лечение не воскрешает исчезнувший
  summon. Pending independence запрещает обычное действие; новая group/GM pool
  назначаются на следующем roster, без нарушения ещё открытого старого хода.
- Despawn CAS сохраняет HP/history/snapshots, убирает participation и controllers
  из текущей доступности, отменяет reactions и завершает состояния на самом
  summon/source upkeep/source-own_turn состояния и emitter/полностью выбывшие barrier pools. Эффекты с target_turn/round на других целях сохраняют свои KB duration;
  source-bound upkeep/own_turn завершаются при terminal removal источника
  без выдуманного будущего Clock или бесплатного поддержания.
- AP/запечатанные попытки не возвращаются. Идемпотентность обеспечивают прежние
  parent receipts и уникальные version operations, а не GET-проверка.

Pending/recovery Inventory operations блокируют переход; held custody с этим
custodian/active user также блокирует despawn/смену группы. Обязательства,
резервы и имущество не удаляются и не освобождаются. Разрешение — существующий
Stage 6 recovery/return protocol, затем повтор того же boundary. Ошибка
позднего audit откатывает lifecycle, ресурсы, admission и все receipts вместе.
TransactionManager дополнен только проверкой принадлежности текущей
транзакции тому же ACS writer connection; нового transaction manager нет.

## Partial withdrawal и rejoin

Добавлены узкие GM backend-команды partial_withdraw/rejoin_encounter с
native/session авторизацией, POST/CSRF, reason, operation idempotency,
session/encounter/participant CAS. Они доступны только по explicit pinned
KB presence policy из config snapshot, выбранной при create через
presence_template. Без такого правила — отказ; runtime JSON из клиента
не является разрешением.

Меняется participation только указанной identity. Персонаж не забирает за
собой питомцев/призывов; они сохраняют бюджет. Rejoin не меняет roster, AP,
quota, cooldowns, reaction_used, HP или terminal status. Полная механика
побега/поражения и spatial условия этапов 7.3/8 сюда не включены.

## Проверки и ограничения

PHP **8.5.12**, disposable MySQL **8.0.46**, случайные базы existing fixtures,
MyBB users/posts остаются MyISAM. Production DB не подключалась.

- `acs_summon_lifecycle.php`: wrong/matching/duplicate boundary, round/own_turn,
  atomic HP/EP/AP sustain success/shortage, source interruption/no revival,
  pending independent и encounter-end expiry.
- `acs_summon_lifecycle_sql.php`: existing Draft/Seal/Publish/Writer/Clock,
  общий 3 AP, остаток 1 AP после 2 spent, quota/closed turn, один post на группу,
  next-round roster, реальные ordinary actions нескольких существ, duration,
  duplicate boundary, shortage, despawn history, late audit rollback/retry,
  pending owner operation/held custody, independent next roster, permanent pet
  через owner adapter и partial withdrawal/rejoin без AP reset.
- Точечные регрессии `acs_spawn_sql.php`, `acs_delegation_sql.php`,
  `acs_combat_items_sql.php`, `acs_npc_controllers_sql.php`,
  `acs_sustained_upkeep.php`, `acs_status_ticks.php`.
- `php -l` изменённых/новых PHP; `git diff --check`.

Полный test suite и browser E2E не запускались. Нового UI/редизайна нет.
Поддержаны только явно перечисленные policy/period/resource варианты; unknown
поля, spatial, arbitrary scripts/transform, recursive summon, on_spawn_effect
и object lifecycle/upkeep блокируются. Произвольный перенос source Clock на нового владельца не реализуется;
source-bound состояния завершаются вместе с terminal emitter. Временные
summon не восстанавливаются обычным group rest; постоянные участники группы
продолжают прежний rest protocol. Combat-end исчезновение выполняется существующей явной
combat_reset командой. Installed pet provider/публикационные gates и реальный
MyBB acceptance остаются прежними ограничениями проекта.
