# ACS-9.1.1 — Prepared Action: paid contract, persistence и lifecycle

База: `main` / `2850bec9`, после ACS-8.3.3 (PR #1050).
Один ограниченный Backend PR. ACS-9.1.2 не реализован.

## Источники

Основание: A1.75, сопутствующие A1.1, A1.15, A1.49 в
[Master Specification](ACS_MASTER_SPECIFICATION.md),
[Technical Architecture](ACS_TECHNICAL_ARCHITECTURE.md),
[Implementation Roadmap](ACS_IMPLEMENTATION_ROADMAP.md),
[Integration Contracts](../../contracts/ACS_INTEGRATION_CONTRACTS.md),
[KB Mechanics Contract](../../contracts/ACS_KB_MECHANICS_CONTRACT.md),
отчёты [8.3.3](ACS_8_3_3_IMPLEMENTATION.md),
[7.3.3](ACS_7_3_3_IMPLEMENTATION.md),
[6.3.2](ACS_6_3_2_IMPLEMENTATION.md).

`docs/ROADMAP.md` и миграции 001–020 не изменены.

## Implemented

Подготовка — отдельное оплаченное обязательство, а не ACS-5.3 reaction.
Builder → Draft → Seal/confirm сохраняют прежние source/KB pins, native posting
rights, управление/делегирование, behavior, audience, target policy, spatial и
cover gates. Для opt-in способности выбор подписан «Подготовить действие».
Builder показывает условие, AP, срок и сохранённое состояние подготовки.
Второго редактора и нового литературного поста нет.

`PreparedActionService::publish` — явная POST/CSRF команда. В существующей
SessionRepository command transaction повторно проверяет автора, membership,
текущий controller grant, допустимость action, session/encounter/turn,
подтверждённую сводку и immutable definition. Публикация сохраняет контракт,
списывает общий AP, выполняет прежние EntityTurn end boundaries и закрывает
ход группы. Обычный PublishService отклоняет deferred seal до выдачи native
submission. MyBB Gateway, posts, post_links и combat submissions не вызываются.

Общий Resolver получил только deterministic cost-only ветвь регистрации:
никакого урона, реакций или будущих hit/crit/status samples. Effects сохраняет
проверку оплаты и состояния исполнителя. Seal использует существующие
sealed_actions/snapshots и прежнюю квоту, но deferred tape не создаёт rolls.
Будущие проверки должны получить prepared/trigger causal identity в 9.1.2.
Новый AdvancedEffects или второй Resolver не создан.

Одна resumable миграция `021_prepared_actions` создаёт prepared_actions и
prepared_executions. Существующий runner проверяет checksum/точную схему,
сохраняет running step и продолжает после DDL interruption. Durable UNIQUE:
source_sealed_action_id, execution (prepared_action_id,trigger_event_id),
execution operation_key. Таблица executions в этом PR остаётся пустой.

Контракт хранит session/encounter/source entity, owner/control_group, source
sealed action/EntityTurn/event, immutable KB definition snapshot и ability pin,
trigger, фиксированную цель и target policy, срок, стоимость, paid/pending
cost states, dependency references, исходный sealed hash и contract hash.
Source event — реальный encounter.prepared_register из существующего ACS journal;
он связывается в той же transaction до COMMIT. Исторические refs не меняются
при expiry/cancel.

Повтор operation key возвращает прежний journal receipt после текущей проверки
прав. Другой key не проходит опубликованный draft/закрытый turn. AP, ресурс,
EntityTurn, seal, prepared row, event/details и receipt откатываются вместе.
Unknown response требует повторить тот же запрос/key, без нового intent.

До следующего source EntityTurn start подготовка active. Existing Clock boundary
переводит её в expired, повышает prepared_version и сохраняет boundary/список
истёкших IDs в прежней истории Clock. GET ничего не истекает. Явная
`prepared_cancel` использует POST/CSRF, source control, session CAS и prepared
CAS; сохраняет cancelled history без возврата AP/ресурса и открытия хода.
Terminal expired/cancelled/executed не допускают возврата в active; executed
зарезервирован для будущего executor. Detection не подключён как причина отмены.

Чтение состояния подготовки требует native thread/membership и текущий контроль
источника. GM не получает отдельного обхода controller grant. DTO содержит
только ID/status/version/cost state/duration, без чужого contract JSON и pins.
Расходных прав делегату не добавлено.

### Поддержанный KB subset

Opt-in поле `definition.prepared_action` существующей pinned active single-target
атакующей способности:

```json
{
  "schema": "ic-acs.prepared.v1",
  "trigger": {"kind": "on_targeted", "subject": "source"},
  "target": {"kind": "fixed"},
  "ap_cost": 2,
  "additional_costs": [
    {"kind": "resource", "key": "ep", "amount": 2, "timing": "on_prepare"},
    {"kind": "resource", "key": "ep", "amount": 1, "timing": "on_trigger"}
  ]
}
```

Отсутствующие duration/requires_active нормализуются в until_next_turn/true.
Один prepared action в draft; targets содержит одну явную разрешённую цель.
Клиент не передаёт trigger descriptor через action parameters. В этой версии
opt-in ability используется как подготовка; отдельного переключателя её обычного
немедленного исполнения нет. Дополнительные resource costs on_prepare оплачиваются
при регистрации, on_trigger сохраняются pending. HP/AP как additional resource,
item costs, autonomous execution, multi-target, status/removal abilities,
неизвестные triggers/targeting/durations/fields и callbacks — UNSUPPORTED.

## Locally verified

Целевая среда fixtures: **PHP 8.5.12 / MySQL 8.0.46**, official CLI/container,
mysqli/mysqlnd, отдельный socket `/tmp/acs222-socket/mysql8.sock`.
Случайные disposable DB удаляются в finally. Production DB не подключалась.

`tests/acs_prepared_actions_sql.php` использует authoritative Stage-6 fixture,
реальные Builder/Draft/Seal/Resolver/SessionRepository/Clock и MyISAM native
post fixtures. Проверено:

- paid prepare из остатка общего AP (1 уже потрачен + 2 подготовки = 3);
- preview и seal без оплаты, RNG, prepared row, HP или reaction_used изменений;
- on_prepare resource payment и pending on_trigger без раннего расхода;
- duplicate prepare, другой key после публикации и закрытый turn;
- GET/CSRF denial, stale controller до публикации и после неё, закрытое чтение;
- недостаток ресурса после seal и запрет client trigger через parameters;
- неизвестные trigger, targeting и item-cost contracts → UNSUPPORTED;
- late SQL failure в event_details после prepared/AP/resource writes:
  полный rollback всего ACS capture, затем успешный повтор;
- отсутствие нового post, submission, post_link, группы или хода;
- until_next_turn expiry настоящей EntityTurn start boundary;
- cancel/replay без refund/нового хода и с сохранением history;
- repeated migrations, восстановление отсутствующей ledger entry и настоящий
  running step=1 resume с существующей populated prepared_actions и отсутствующей
  второй таблицей.

`tests/acs_combat_items_sql.php` — PASS на той же целевой среде: обычные
item swap/consume, owner reservation, поздний rollback, replay и AP сохранены.
PHP lint изменённых/новых PHP, node --check только изменённого builder.js и
git diff --check — PASS. Полный suite и browser E2E не запускались.

Предварительный запуск на старом onboarding PHP 8.4.27/MariaDB остановился
на existing Inventory schema gate INV_SCHEMA_NOT_READY; он не считается
проверкой новой механики. Целевые PASS выше получены отдельно на MySQL 8.0.46.

## Installed NOT_VERIFIED

Установленный warprift.ru / MyBB 1.8.41, реальные routing/engines/hooks,
ACP lifecycle, native permissions, тема/мобильный Builder и installed acceptance
не проверены. Production publication flags и настройки форума не изменялись.
Local SQL PASS не означает готовность установленной среды.

## BLOCKED / UNSUPPORTED

**Предметные prepared costs не реализованы.** В этой реализации не добавлена retained binding
KB cost → конкретный owner item/reservation для новой команды. Любой
kind=item отклоняется до seal/оплаты. Это сохраняет существующие Inventory
полномочия и не выдаёт незащищённое обещание будущего расхода, но оставляет
предметную часть ACS-9.1.1 незавершённой. PR должен оставаться draft до решения
этого ограничения либо явного принятия данного subset.

Исполнение trigger, его event matching, revalidation/range/target checks,
trigger-time RNG/execution receipt/cycle guards и расход on_trigger относятся
к ACS-9.1.2 и не реализованы. Засады/awareness подготовок/pre-round commitment
не добавлены. Сохраняются installed gates этапов 4, 6, 7 и 8.
