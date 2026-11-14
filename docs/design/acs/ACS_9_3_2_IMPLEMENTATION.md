# ACS-9.3.2 — Copy ability, steal effect and dispel effect

База: main `a3637804`, merge ACS-9.3.1 (PR #1061). Основание: A1.86,
четыре нормативных ACS документа, KB Mechanics Contract, отчёты 9.3.1,
5.1.2, 5.2.2 и 8.3.3. Один ограниченный Backend PR.
`docs/ROADMAP.md`, исторические отчёты, MyBB Core, CharacterSheets,
AdvancedInventory и AdvancedAppearance не изменены. publication_enabled не
включён. ACS-9.3.3 не начинается.

## Implemented

`AbilityOperations` — чистый typed helper над прежним status state, не второй
Status Engine/Resolver/Clock. KB `effect_operation` проходит штатные
Builder → Seal → Resolver → Effects → CombatCommitWriter. Общие checks
control/delegation, behavior, target policy, awareness, range/cover/LoE,
ресурсы, подтверждение friendly fire и immutable tape сохраняются.
Контракты и точные поля описаны в ACS_KB_MECHANICS_CONTRACT.md.

| Операция | Поддержанный subset |
| --- | --- |
| copy_ability | Временный combat granted_ability; конкретная direct pinned single-target damage ability источника с явным copy_policy и identified source |
| stat policies | caster_stats; source_snapshot текущей разрешённой проекции источника; custom — только пять явных численных stat bindings |
| grant lifecycle | target_turn/round; 1..100 uses; AP + существующие resource costs; version/CAS; retained use identities; expiry/exhaustion без удаления истории |
| steal_effect | Один явно экспортируемый independent combat round effect; прежняя selection policy; закрытие old + linked child в одной транзакции |
| dispel_effect | Прежний removalCandidates с exact KB effect_keys и opt-in visibility; без нового алгоритма снятия статусов |
| Tick history | Периодические pins, last_tick_boundary/last_duration_boundary и literal remaining duration копируются; on_apply и старые тики не запускаются |

Grant сохраняет source/recipient, исходный DTO и pin, compiled damage binding,
stat snapshot, duration, use costs, remaining_uses, granted_version, append-only
sealed-action use history и origin combat event. В каталоге контроллера используется
отдельный alias `granted:<effect_id>`. Постоянные abilities/build_json не меняются.
Form/behavior restrictions проверяют исходный ключ способности; alias не обходит
запрет. Temporary control overlay не получает новые grants. Неподдержанный item
cost не превращается в бесплатное применение и не выдаёт Inventory permission.

Preview и seal не уменьшают uses и не оплачивают grant use. Механический расход
выполняется на detached state и подтверждается только прежним ACS commit.
Сохранённый random tape используется штатным HitCrit/Resolver; policy объекта
восстанавливается из pinned DTO, runtime PHP object не сериализуется в snapshot.
Повтор терминального submission возвращает прежний receipt до нового исполнения.

Transfer сохраняет старую строку и её историю. Новый экземпляр использует
parent_effect_id, исходные definition/status refs и definition_snapshot_id,
источник, stacks, remaining duration и обработанные boundaries. Отдельно
сохраняются producer snapshot, исходная цель/version, sealed cause и combat event.
SQL projection проверяет обе связи и immutable snapshot hashes. Плательщик
sustained не угадывается: такие переносы в v1 блокируются до механической записи.

Новых таблиц и миграций нет: effects уже имеет parent_effect_id; статусные
изменения, AP/resources, event, terminal receipt и outbox подтверждаются одним
InnoDB COMMIT под прежним session lock и CAS. Origin event связывается после его
INSERT, до terminal receipt; orphan provenance не принимается как действующий
grant/transfer. Удаление старой строки и повышение uses запрещены.

## Locally verified

Среда: disposable MySQL 8.0.46, PHP 8.5.12 CLI/mysqli. Точечный authoritative
harness, без полного suite. `tests/acs_ability_operations_sql.php` использует
существующий item SQL harness и реальные Draft/Seal/Publish/CombatCommitWriter,
Clock/RoundManagement. Native post rows — заранее посеянные readback fixtures;
приложение не вызывает MyBB posting gateway, итоговый набор posts неизменен.

Проверено в новом fixture:

- Copy pin и explicit opt-in; caster_stats/source_snapshot/custom дают разные
  разрешённые параметры попадания; неподдержанный custom и hidden target
  отклоняются до механической записи.
- Настоящий grant появляется в authorized catalogue; seal/confirm не оплачивают
  uses. Два опубликованных использования исчерпывают grant; отдельный unused
  grant истекает через реальные Clock/RoundManagement boundaries с сохранёнными
  remaining_uses/history.
- Duplicate commit/receipt и lost ACK используют прежние sealed rolls и outcome;
  usage не уменьшается повторно. Stale granted_version и SQL effect CAS
  отклоняются; resource shortage не подтверждает использование.
- Steal между разными retained entity (NPC victim → caster recipient) сохраняет
  parent link, исходного caster, remaining duration и закрытую
  old row. На SQL-hydrated virtual state проверены сохранённые tick boundaries,
  отсутствие повторного DoT и ровно один следующий tick; unsupported sustained
  не исполняется. Dispel использует прежнюю eligibility и закрывает eligible row.
- Late outbox fault после mechanical projection откатывает effects, HP/resources,
  AP, event и owner state; retry затем даёт один confirmed receipt. Native posts,
  постоянная регистрация entity и Inventory ownership остаются прежними.

Дополнительно прошли точечные regressions `acs_transform_sql.php` (обычный и
writer режимы), `acs_prepared_execution_sql.php` и `acs_combat_items_sql.php`.
PHP lint изменённых файлов и `git diff --check` проходят. JS не менялся,
поэтому node --check не требуется. Полный suite и browser E2E не запускались.

## BLOCKED / UNSUPPORTED

- Копирование без explicit source copy_policy, по произвольному клиентскому key,
  неизвестной/скрытой способности, temporary grant-of-grant или другого special
  operation. Mass, prepared/reaction/start-time use временного grant не заявлены.
- Произвольные custom формулы, HP/item/free use costs, перенос постоянного таланта
  или ownership. Новых owner grants и Inventory mutations нет.
- Sustained/upkeep transfer и смена плательщика, entity-turn duration transfer,
  recursive steal, control/behavior/form/granted/barrier transfer, массовый или
  вероятностный выбор. Отклонение sustained сохраняет прежнюю оплату и историю;
  поддержка такого переноса не заявляется по факту сохранения tick markers.
- Same-publication изменение только что перенесённого экземпляра требует
  отдельного подтверждённого history contract; текущая strict persistence
  проверка отклоняет несовпадение с исходным состоянием, без partial commit.
- Dynamic same-publication source-stat drift относительно sealed copy binding
  блокируется; не подменяет snapshot и не перебрасывает tape.
- Отражение/поглощение относятся к следующему этапу.

## Installed NOT_VERIFIED

На warprift.ru не устанавливалось. Production KB descriptors, реальные
межпользовательские audience/delegation scenarios, browser flow и installed
compatibility MyBB 1.8.41 требуют отдельной приёмки. SQL fixtures доказывают
локальную транзакционную механику, а не production readiness всех KB сценариев.
