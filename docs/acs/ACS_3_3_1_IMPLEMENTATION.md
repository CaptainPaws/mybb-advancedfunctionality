# ACS-3.3.1 — Barrier Pools, HP и Resource Costs

Исходная main: `e1bcfa2adbf057f3f4a9d78d325bb9dfece5615c`.
ACS 0.15.0; virtual engine `acs-effects-1`; Numeric `binary64-half_up-v1`.
Статус: ready_for_manual_test. Design Baseline не изменён.

## Файлы / DDL

- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Effects.php`:
  AF_ACS_Effects и AF_ACS_EffectsResult.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Administration/EffectsDiagnostics.php`:
  фиксированный read-only пример для ACP.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/admin.php`: существующий
  раздел «Диагностика» и сведения о версии в «Основных».
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/manifest.php`: версия.
- `tests/acs_effects_regression.php` и этот отчёт.

Миграций нет, текущая схема остаётся 005_clock_resources. Architecture §5 содержит
проектные barrier_pools (pool_kind/strength/maximum_strength/barrier_priority/
applied_order/status/pool_version/absorbs_json) и barrier_targets(pool_id/entity_id).
В этом PR они не нужны: результат существует только в памяти. Зависимые таблицы
полного effect lifecycle этапа5 не создаются, альтернативного SQL-хранилища нет.
Virtual scope/targets/version — нормализованные поля будущего caller, не новая DDL.

## API и атомарность

`AF_ACS_Effects::simulate(state,action)` возвращает typed result. `attempt(...)`
возвращает ready/evaluation/null/structured diagnostics. Входной state сначала
полностью проверяется и отделяется JSON round-trip от PHP references. На ошибке
не возвращаются частичный state/events. Входы, таблицы, CharacterSheets, AP, HP и
Inventory не изменяются. Версии изменяются только в возвращаемом virtual state.

Один вызов представляет **одно** действие: все costs сначала, effects после них
в фиксированном порядке. Сигнал incapacitated после оплаты не отменяет остаток
текущего разрешённого действия. Следующий обычный вызов с actor incapacitated/dead
отклоняется. Полный Resolver/reaction/turn lifecycle здесь не реализован.

## Virtual-state DTO

```text
schema_version: 1
entities[id]:
  hp, max_hp: nonnegative int
  life_state: active | incapacitated | dead
  recovery_blocked: bool (явное ограничение возврата в active)
  group_id: stable ID
  resources[key]: {current, maximum}: nonnegative int
  source_ref: {key, version, fingerprint}
  version: monotonic positive int
groups[id]: {ap:{current,maximum}, version}
barriers[id]:
  source_entity_id, source_ref, scope:per_target|shared_pool, scope_key
  targets: уникальный список entity IDs
  strength, maximum_strength: nonnegative int
  barrier_priority, applied_order, status:active|destroyed|replaced, version
  absorbs: subset [physical,elemental]
  duration: {value,unit:own_turn|target_turn|round}
next_barrier_order: monotonic integer sequence
```

ID — канонический положительный decimal BIGINT, сравнивается численно без float.
per_target имеет одну цель и scope_key этой цели; shared_pool имеет явную область
и единый запас. Duration сохраняется, не тикает от preview. Unknown/несогласованные
состояния блокируются. Active требует HP>0; dead имеет HP0. Incapacitated с HP>0
допустим только при явном recovery_blocked. Никаких порогов смерти не добавлено.

Источник/authorization/hash readback, состав целей, допустимость KB-операций,
актуальность versions/Clock и право использования ресурсов подтверждает доверенный
caller до Domain. Метаданные source_ref не являются криптографическим доказательством
разрешения произвольного POST. Нового endpoint для state/action нет.

## Action / HP cost

Action содержит actor_id (необязателен для эффекта без затрат), costs[], effects[].
Поддержаны costs: hp, ap и resource с amount:int, для resource — явный key.
Actor должен быть active; AP берётся из его общей group, HP/energy — из его entity.
Ресурсы hp/ap запрещены в generic resources, чтобы нельзя было обойти специальные
ограничения. Питомец с тем же group_id использует тот же виртуальный запас AP.
Начальный бюджет не создаётся/не обновляется этим модулем.

По умолчанию HP cost оставляет минимум1. Для жертвы требуется явная нормализованная
`hp_cost_policy={allow_zero:true,source_ref}` от утверждённого pinned KB-контракта
доверенного caller. Domain проверяет структуру policy, не разрешает его по одному
UID/параметру клиента и не объявляет новый KB editor/schema реализованным.
Без такого разрешения весь action с оплатой до0 отклоняется. Больше текущего HP
списать нельзя даже с разрешением. HP cost не проходит DEF/RES/барьеры и не создаёт
on_hit/on_damage; trace отмечает is_damage=false. Не хватает любого cost — нет результата
всего действия. Реальное атомарное списание при публикации остаётся будущему coordinator.

## Барьеры

Единая очередь individual/shared: barrier_priority ascending → applied_order FIFO →
numeric ID ascending. Непереопределённый priority нового барьера —0. Для каждого
целого компонента проверяются active/status, protected targets и absorbs.
True всегда обходит обычные пулы; unsupported true-absorbing фильтр блокируется.

Shared pool представлен единственным объектом, а не копиями у целей. Target order
передаётся caller явно и сохраняется; каждый component идёт в KB source-index порядке
из DamageResult. Strength уменьшается один раз на поглощённую часть, не бывает
отрицательной. При0 status=destroyed: дальнейшие цели немедленно теряют защиту.
Исходный integer budget не пересчитывается и не уменьшается в allocation DTO.

`barrier_apply` получает новый pool_id и pool с параметрами источника/области/силы/
фильтра/срока. Повтор key того же source_entity в той же scope/scope_key заменяет
старый active экземпляр: old=replaced, новый получает next_barrier_order.
Старый ID повторно использовать нельзя. Новая прочность/срок берутся из параметров KB. Нулевая новая прочность
сохраняется как destroyed, искусственный минимум1 не вводится.
Независимый stack не включается по умолчанию; неоднозначная старая stack-группа
блокируется (effects_barrier_stack_unsupported). Полная KB stack-механика этапа5 не создана.

`barrier_restore(pool_id,amount?)` восстанавливает существующий active pool на
amount либо до maximum, без изменения duration/applied_order/targets. Излишек
ограничивается maximum и отражается в trace. Destroyed/replaced не оживляются.

## HP и сигналы

Effects: damage, heal, set_max_hp, barrier_apply, barrier_restore.
Damage принимает **AF_ACS_DamageResult** из ACS-3.2.2. Проверяются версия,
integer budget, allocated ints, source-index order и sum(allocated)=B.
Ни rounding компонентов, ни rounding остатка на HP здесь нет.

После очереди барьеров hp_loss=min(remainder,currentHP), overkill=remainder−hp_loss.
Нулевой урон допустим. При HP0 — incapacitated, не dead. Обычный damage по dead
отклоняется как unsupported target, смерть не подтверждается автоматически.
Heal: effective=min(amount,maxHP−currentHP), overheal=amount−effective;
обычный heal dead блокируется. При HP>0 incapacitated возвращается в active,
если recovery_blocked=false. Set_max_hp сохраняет абсолютный currentHP и только
ограничивает его новым максимумом; процентного масштабирования нет.

Trace содержит life_transition и stop_sustained_required при падении active до0.
Сигнал остаётся, даже если позже в том же действии произошло лечение: автоматического
восстановления sustained нет. Lifecycle статусов здесь не исполняется. Возвращаются
ordinary_actions_require_recheck, end_condition_check_required=true,
encounter_transition=null и current_action_may_complete=true. Caller проверяет
закрытые turn/roster и полные end conditions после всех разрешённых стадий;
encounter не переводится в completed/pending_resolution данным модулем.

Result.serverState()/serverTrace()/serverDependencies() — только для доверенного
сервера: копия нового состояния, события/сигналы и исходные зависимости.
Обычный JSON показывает лишь ready/engine_version/persistent=false, без скрытых HP.

## ACP и минимальные проверки

ACP → ACS → Диагностика показывает заранее заданный синтетический пример:
HP20 у двух участников, shared5, damage4 каждому; costs первого HP2/AP1/energy2,
затем heal5. Итог: HP20/17, shared0, AP2, energy8. Нет произвольного ввода JSON/PHP,
формул или доступа к реальным ресурсам. Повтор GET пересоздаёт только локальную
копию sample. Действуют существующие AF ACP permissions; отдельной авторизации нет.

PHP lint всех затронутых PHP и git diff --check — успешно.
`php tests/acs_effects_regression.php` — успешно:
- два FIFO pool, разные priority и равенство applied_order с numeric ID tie;
- shared5 на две цели по4, включая обратный target order;
- physical-only filter, elemental и true bypass;
- replacement уходит в конец; restore сохраняет очередь/срок; destroyed restore отказ;
- overkill30, overheal10, incapacitated/active и запрет dead heal;
- maxHP20→100 сохраняет current20, уменьшение до7 даёт current7;
- HP/AP/energy costs, sacrifice0 и недоступность следующего обычного action;
- поздний конфликт cost без частичного virtual result, zero damage, PHP references;
- настоящий Damage mixed allocation0.4+0.4 потребляет ровно1 HP;
- preview/ACP sample не выполняют SQL и не меняют fixture tables;
- ACP renderer доступен по штатным правам, без них отказ.

Использованы существующие узкие read-only KB/CS/Damage fixtures. Production не
использовался. Полный suite/SQL/E2E не запускались. Локально PHP8.4.24;
Отдельный showthread smoke подтвердил inactive без загрузки Effects/Numeric.
PHP8.5.0 и реальный ACP требуют ручной приёмки.

## Ручная приёмка / откат

1. ACP ACS → Основные: Effects acs-effects-1.
2. Диагностика: увидеть явно подписанный виртуальный пример с итогами выше;
   обновить страницу и убедиться в одинаковом результате.
3. Проверить реальные HP/AP/CS и обычную тему: состояние прежнее, глобальный
   Effects runtime не подключается. CLI regression повторить на PHP8.5.0.

Откат — revert PR. Новых таблиц/записей нет, восстанавливать ресурсы или удалять
историю не требуется. Реакции/отражение/вампиризм/публикация/Inventory/награды и
ACS-3.3.2 не реализованы.
