# ACS-8.2.1 — Object interaction, outcomes, tools и progress

База: `18305981` (main после ACS-8.1.2, PR #1045). Один ограниченный PR.
Источники: ACS_MASTER_SPECIFICATION (A1.71–A1.73),
ACS_TECHNICAL_ARCHITECTURE, ACS_IMPLEMENTATION_ROADMAP,
ACS_INTEGRATION_CONTRACTS, ACS_KB_MECHANICS_CONTRACT и отчёты
8.1.1, 8.1.2, 6.3.2, 7.3.3. `docs/ROADMAP.md` не изменён.

## Реализация и поддержанный KB subset

Добавлен общий `Domain/ObjectInteraction.php`. Единственный интерпретатор
проверяет typed contract `ic-acs.object-interactions.v1` внутри pinned
`ic-acs.object-template.v2`. Полная грамматика и границы subset описаны в
[KB mechanics contract](../../contracts/ACS_KB_MECHANICS_CONTRACT.md#acs-821-typed-object-interaction-subset).
Нет handler по имени двери, ловушки или предмета.

KB задаёт actor kinds, required abilities/minimum stats, разрешённые состояния,
AP/resource costs, инструмент, Formula snapshot и его bindings, outcome bands,
переход состояния/activation, ремонт, progress и retry limit. Формула проходит
существующий Formula compiler с проверкой fingerprint и единицы измерения;
общей таблицы порогов нет. Поддержаны только явно перечисленные
critical_success/success/partial_success/failure. Последствия текущего subset:
physical/true damage исполнителю через Damage/Effects, heal исполнителю,
изменение object state/activation, bounded repair, progress add/set (включая
reset/отрицательный вклад). Это позволяет описать disarm failure как урон
ловушки, не создавая второй event processor.

Progress хранится в typed interaction state: значение, KB cap, attempts и
contributions. Каждый contribution содержит actor/action/outcome/before/after.
Scope retry — actor или весь object; limit и terminal outcomes задаёт KB.
Успешное подтверждённое взаимодействие и провал увеличивают object_version.
Повторный commit возвращает прежний terminal receipt. Дополнительный доменный
ключ вклада запрещает повторное начисление одного sealed action.
Явный round decay для round-lifetime объектов использует существующий Clock round_end и last_boundary;
GET и preview не запускают границы.

## Реальные source/event bindings и запись

Wire key `object:<entity_id>:<action_key>` выбирает ровно один существующий
spawn entity; targets содержит этот же entity_id. Сервер проверяет current
awareness identified для конкретного actor, controller/native/session access,
обычные status/behavior ограничения, активный ход и AP/resources. Object-only
policy не делает объект обычной creature target и не выдаёт ему stats/AP/turn.
Unknown/suspected/detected объекты отсутствуют в interaction choices и targets.
Наличие осведомлённости не снимает invisible/hidden/concealed.

`ObjectInteractionService` извлекает исходный `source_snapshot` из проверенного
spawn snapshot; pin key/version/fingerprint принадлежит реальному KB combat
template. Sealed definition сохраняет action, object entity_id/object_version
и выбранный tool binding. Клиент не передаёт результат проверки или JSON
состояния. Builder → Draft → Seal → PublishService → publication R2 →
CombatCommitWriter остаётся прежним маршрутом. Seal резервирует стандартный
uniform53 hit/crit tape; использует hit.u только при roll binding, crit остаётся
резервом. Preview возвращает неизвестный outcome и не делает roll.

Commit пересчитывает действия с тем же tape и повторно проверяет зависимости.
Изменение object_version меняет dependency/confirmation hash; прежние rolls
сохраняются для существующего reconfirm. Иная цель автоматически не выбирается.
`reactionContext` задаёт cause `sealed-action:<sealed_actions.id>`;
CausalGraph ограничивает плоские дочерние последствия без рекурсии.
Полный before/after объектов и action plan входят в существующие event_details
`combat.resolved`, receipt/result hashes и audit. Spawn source event сохраняется.

Новых migrations нет: используются `016_environment_objects` и awareness
`017_awareness`. `objects.interaction_json` содержит typed state, а
`objects.progress_json` — проверяемую полную runtime projection. Идентичность,
CAS и object history записывает прежний ObjectPersistence в общей ACS InnoDB
transaction. Старые checksums и migration ledger не изменены.

## Inventory

Requires использует authoritative owner read и повторный same-writer owner
fence/version/fingerprint/availability check при commit. Ни reserve, ни consume
для проверки наличия не вызывается. Consumes выбирает реальный свободный item
по KB key на сервере; seal резервирует 1 единицу с ключом
`acs:tool:reserve:<draft>:<history_index>`. Повторная подготовка использует тот же
item/reservation; автоматической замены после seal нет. Cancel tail освобождает
только свой резерв, не возвращая quota.

Небольшой `Infrastructure/InteractionToolPersistence.php` выполняет lookup
первоначального owner receipt и consume с ключом
`acs:tool:consume:<submission_id>:<sealed_action_id>`. IC-INV-1 receipt и изменения
ACS становятся durable одной транзакцией. Поздняя ошибка откатывает qty,
reservation/owner receipt, progress, attempts, AP и event. `remove_item` не
используется, item on_use не исполняется. RecoveryService/publication не
обходятся, новых MyBB posts этот код не создаёт, CharacterSheets не изменяет.

Tool subset — собственный character, один неvisual equipment/arpg_item, qty=1;
consumes допускает только owner-protocol subtypes consumable/ammo.
Делегированное управление не даёт права использовать чужой инструмент.
Inventory outage блокирует owner-dependent действие; non-item interaction
продолжает работать при отсутствии иных прежних dependency barriers.

## Интерфейс и ограничения

Builder содержит группу «Взаимодействия с объектами», разрешённые choices,
AP/resource costs и безопасные причины отказа. Preview и sealed summary
показывают корректную семантику interaction, без фиктивных P_hit/P_crit.
GM combat card читает сохранённый typed outcome/progress; player card сохраняет
существующее ограничение подробностей. Новый SCEditor не создаётся.

UNSUPPORTED: положительный round decay у own_turn-lifetime объектов, spatial условия/зоны, произвольные callbacks, status outcomes и
стихийные/чужие цели, HP costs, timed cooldown, несколько инструментов,
чужой owner/custody tools, произвольные dynamic parameters. Runtime history
ограничена 16000 contributions на объект; выход за лимит блокируется.
Произвольный JSON состояния и клиентский outcome не принимаются. Installed
acceptance warprift.ru и live DB deployment этим PR не заявляются.
ACS-8.2.2 не начат.

## Проверки

Проверки выполняются в disposable MySQL 8.0.46 / PHP 8.5.12 на существующем
authoritative Stage-6/7 fixture. Полный suite не запускается.

Пройдены:

- `tests/acs_object_interaction_sql.php`: unlock success, disarm failure damage,
  partial/critical outcomes, explicit Formula roll binding и retained tape,
  несколько contributions/cap/reset, repair/activation, resource costs,
  insufficient AP, retry denied, duplicate submission/contribution,
  object_version conflict, tool presence без расхода, consume once,
  независимый spending permission, смешанные item/interaction действия,
  Inventory outage с работающим non-item действием, unknown object filtering,
  duplicate Clock decay boundary, unsupported KB outcome/own_turn decay,
  late ACS/owner rollback и повтор commit.
- `tests/acs_environment_sql.php`: прежний object lifecycle, migration install /
  repeat / resumed ledger, identity, passive/trigger/destruction и rollback.
- `tests/acs_awareness_sql.php`: прежние audience, hidden filtering, stealth,
  fixed detection, card/cache isolation и rollback.
- `tests/acs_combat_items_sql.php`: прежний Stage-6 reserve/consume/swap,
  publication receipt/replay и owner rollback.
- `node tests/acs_builder_state.js`, `node --check` Builder JS,
  PHP 8.5.12 `php -l` для всех изменённых/новых PHP, `git diff --check`.

SQL-команда: `docker exec acs811-php php tests/<fixture>.php
/tmp/acs222-socket/mysql8.sock`. Каждый fixture создаёт и удаляет отдельную БД;
production storage не используется.
