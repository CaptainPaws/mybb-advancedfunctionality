# ACS-9.2.2B — Ambush start execution and atomic resolution

База: main `c824a5dc` (ACS-9.2.2A, PR #1058). Основания: A1.75–A1.77,
четыре нормативных ACS документа, KB Mechanics Contract, отчёты
9.1.2/2A/2B, 9.2.1/2/2A и 8.3.3. Исторические отчёты и
`docs/ROADMAP.md` сохранены. ACS-9.2.2C не реализуется.

## Implemented: typed encounter-start subset

Добавлен отдельный pinned `prepared_action.start_execution`:

```json
{
  "schema": "ic-acs.prepared-start.v1",
  "event_kind": "encounter.start",
  "stage": "ambush_resolution",
  "priority": 100,
  "on_unavailable": "block",
  "emit_prepared": false,
  "emit_active_reactions": false,
  "limits": {"max_depth": 2, "per_cause": 1}
}
```

Требуются существующий `pre_round.allowed=true` и отдельный registration trigger
`{"kind":"encounter_start","subject":"encounter"}`. Ordinary `execution`
одновременно запрещён. Полная схема и owner extension описаны в KB Mechanics
Contract. Неизвестные поля/варианты UNSUPPORTED до механической записи.
Прежний combat.primary descriptor не переименовывается и не получает start
admission автоматически. Start-only способность запрещена в ordinary Builder.

| Descriptor / execution | Фактическая поддержка |
|---|---|
| encounter.start / ambush_resolution | Single fixed-target damage ability; несколько последовательных preparations |
| Порядок | Явный KB priority, registration event sequence, prepared ID; BIGINT сравниваются decimal string |
| Внутренние механические фазы | Прежние Resolver hit/crit/damage/Effects; отдельной start формулы нет |
| Стоимость | AP уже оплачены; только конкретные pending on_trigger resource/item costs |
| Недоступный обязательный action | Весь mechanical start блокируется, частичного success нет |
| Hidden reveal | Только null либо explicit source-relative policy, reveal_source=false |
| combat.primary, derived triggers, recursive chains | Не преобразуются в start; UNSUPPORTED/BLOCKED |
| Startup active reactions | Непустой registry блокируется; до round нет доказанных EntityTurn slots |

Это порядок завершённых start actions, не generic interleaving всех событий.
Существующий Resolver вызывается для каждого действия с общим virtual state:
первый удар меняет HP/resources/barriers до следующего. Сохраняются status и
behavior restrictions, range/cover/LoE, source/target control/awareness checks.
Прямые prepared status/removal abilities остаются за пределами прежнего subset.
CausalGraph проверяет root/sibling graph с explicit KB depth; разрешения эмиссии
новых prepared/active reactions нет. Автоматического surprise bonus, потери хода
жертвы, дополнительного AP или reaction_used не добавлено.

`PreparedStartPersistence` — coordinator над тем же Resolver, journal и CAS
projection. Общие source authorization/reveal вынесены из прежнего prepared
pipeline в `PreparedExecutionSupport`; entity/resource CAS writer — в
`EntityDeltaPersistence`. Отдельного Resolver, Clock, боевого state store,
Inventory ledger или RNG нет. Миграций не требуется: используются существующие
operations/events/rolls/prepared_executions и owner reservation bindings.

## Causal seal, identity and immutable history

Сохраняется трёхчастная последовательность:

1. Durable start intent: прежний start_operation_id, immutable request/hash,
   текущий GM issuer и encounter binding; mechanics_confirmed=false.
2. Отдельная metadata transaction сохраняет один `encounter.start_sealed` event,
   typed event_kind=encounter.start, manifest и armed executions. Только здесь
   прежний RandomSource генерирует server hit/crit, каждый один раз. Это не урон,
   не успешный start и не новый combat post.
3. Одна mechanical command transaction подтверждает исполнение, owner расходы,
   projections, первый обычный roster и terminal start event/receipt.

Manifest сохраняет start_operation_id, trigger event, prepared ID/version/hash,
source seal/hash/event, originating UID, fixed target, pinned ability/definition,
порядок, dependency hash и samples. UNIQUE prepared + trigger event и прежний
check-once roll identity защищают повтор. Перед механикой сверяются manifest,
сохранённые rolls, hashes, версии и source provenance. Session/encounter metadata
sequence от durable seal исключены из dependency hash; механические зависимости
остаются проверяемыми. Новый ключ не обходит уже закреплённый start intent.

Оригинальный prepared seal остаётся deferred; будущие samples не записываются
в его tape. Fixed target не заменяется. Ошибка до final commit оставляет armed
seal для того же start, без повторного RNG. При изменении frozen dependencies
start блокируется; автоматического reseal с другими samples нет.

## Owner authorization: separate start obligation

При подготовке исходный authenticated owner подтверждает отдельный внутренний
`prepared_reserved_start_consume.v1`. Owner-side issuer gate сверяет authored
confirmed source draft/seal без post/EntityTurn, typed start contract, конкретные
item ID/qty/cost index/definition pin, item fingerprint и reserve receipt.
Binding и grant generation сохраняются в существующей reservation. Согласие
относится только к уникальному будущему start этого encounter: start ID ещё не
известен на preparation, но при consume обязательно связывается с durable intent.

При start реальный HTTP UID остаётся GM issuer. Owner-side
`authorizePreparedStartObligation` отдельно проверяет persisted session GM/owner
role, POST/CSRF, exact start intent/request hash/actor, root event/manifest,
armed prepared execution, original confirmed publication и retained binding.
Ветка не требует фиктивного parent combat submission. Авторизация не принимается
из HTTP; reference `acs-prepared-start:P:TRIGGER:COST` создаёт только сервер.
Operation key прежний `acs:prepared:trigger-consume:P:TRIGGER:COST`.

OwnerProtocol независимо проверяет **текущую** original-owner generation через
`preparedGrantGeneration`: owner identity, active source, controller/assignment,
combat и inventory spending flags, session role versions/expiry. Также проверяются
live reservation/version/remaining qty, item identity/fingerprint/ownership и
inventory version fence. Отзыв прав после подготовки блокирует будущий расход;
старый receipt не восстанавливает spending grant. Исторический успешный receipt
доступен прежнему exact intent после отзыва, без нового consume.

Same-writer `OwnerProtocol::participate` выполняется на том же authoritative
mysqli connection внутри ACS InnoDB transaction. Owner mutation/receipt,
terminal execution, HP/resources/status/barriers, awareness, первый roster,
applied commitments и terminal event/outcome подтверждаются одним COMMIT.
Нет внешнего owner COMMIT, direct qty UPDATE из ACS, fallback remove_item,
новой reservation на trigger или подмены глобального UID. Owner guard использует
SQL proofs без native callbacks под owner locks; native scene permission проверяет
сам start command, исходный principal перепроверяется source inspection до и
после owner participation. GM не получает обычный Inventory catalog/consume
permission и body control. Owner-only character item subset не расширен на pet,
чужие delegation grants или произвольные cross-owner операции.

## AP, first roster and hidden disclosure

После successful resolution используется прежний openFirstRound и свежий roster
с текущим состоянием существ. Реальные commitments из 9.2.2A применяются один раз:
2 AP дают остаток 1, character 2 + pet 1 дают 0, independent NPC имеет свою группу.
Нет повторной оплаты AP на trigger или refresh поверх commitment. Exhausted
player group проходит прежние реальные EntityTurn boundaries и становится passed;
NPC без истраченного бюджета сохраняет обычный pending turn. Первый round один.

Reveal выполняется shared awareness implementation только для сработавшего
prepared subject и explicit audience. В SQL fixture observer видит detected
preparation, но source creature остаётся unknown; второе действие без reveal
остаётся unknown. Литературный текст/native MyBB posts не меняются. Raw manifest,
rolls и start management outcome остаются за существующими management permissions.

## Recovery and safe refusal

Потеря ACK metadata intent или causal seal сохраняет durable identity; тот же
request продолжает с сохранёнными samples. Потеря final COMMIT ACK выполняет
точный terminal operation lookup и возвращает прежний confirmed outcome.
Terminal replay не пишет round, samples, costs или events снова.

Unknown transaction outcome без terminal receipt, orphan owner receipt и owner
outcome integrity error переводят durable intent в recovery_required после
повторного lookup под session lock. Armed tape/owner history не удаляются.
Автоматического повторного consume, освобождения unknown расхода или refund нет.
Доказанная SQL ошибка до commit откатывает всю механику, оставляя прежний seal.
Отказы due to revoked owner/insufficient resource/invalid target не становятся
успехом или обычным silent skip. Никакого отдельного частичного start COMMIT.

## Locally verified

PHP **8.5.12**, disposable authoritative MySQL **8.0.46**, действующий SQL fixture
harness. Два реальных authenticated контекста: owner UID 7 публикует preparation,
GM UID 8 запускает; observer UID 9 проверяет audience. В same-actor режиме issuer
UID 7. Данные находятся в disposable DB, удаляемой finally; production не трогается.

`acs_ambush_execution_sql.php` modes:

| Проверки | SQL evidence |
|---|---|
| normal / same | Один настоящий Resolver strike, две retained trigger reservations, source principal сохраняется |
| multiple | Character + pet attacks последовательны, суммарный damage 20 без bonus; commitment 3, остаток 0; стабильный registration tie-break |
| npc | Independent NPC второй action меняет HP первого source; отдельный commitment 1 и остаток 2; player commitment 2 и остаток 1 |
| ack | Late rollback, затем реальный successful COMMIT с lost ACK; exact receipt replay без RNG; исторический owner lookup после отзыва |
| interrupted | Реальные lost ACK отдельно для durable intent и causal seal; samples/identity сохранены |
| revoked | Owner inventory spending revoked после подготовки; consume отклонён, damage/roster отсутствуют |
| resource | Недостаточный on_trigger EP; вся механика и owner mutations откатываются |
| target | Мёртвая фиксированная цель не заменяется; урона/первого roster нет |
| orphan | Намеренно инъецированный owner outcome без ACS terminal; recovery barrier, никаких blind consume/release |

Успешные режимы проверяют fixed rolls/replay, sealed tape unchanged, on_trigger
EP и два независимых item bindings consumed ровно один раз, отсутствие item on_use
эффекта от cost-only consume, hidden reveal без creature identity, неизменный
реальный GM UID, запрет обычного owner catalog, отсутствие нового MyBB post,
один round/causal root/terminal start event и reaction_used=0. В них SQL fault
после owner participation при записи terminal event доказывает полный late
rollback HP/resources/items/receipts/roster при сохранённом causal seal.
CausalGraph cycle и unsupported emit_prepared descriptor проверяются явно;
исполнение generic recursive chain не заявляется.

Точечные регрессии PASS:

- `acs_pre_round_sql.php`: paid commitments, group cap, owner reserve/consume,
  exhausted-group boundaries/status ticks, rollback/replay и следующий round.
- `acs_ambush_start_sql.php`: empty start, durable intent и terminal ACK recovery.
  Историческая stdout строка BLOCKED не описывает новый typed start subset.
- `acs_prepared_owner_sql.php normal`: прежняя чужая combat publication, exact
  owner binding, rollback/ACK/revocation/history без нового GM permission.
- `acs_prepared_execution_sql.php`: ordinary combat.primary Resolver, fixed tape,
  transactional resource/item costs, late rollback, no repeated AP/reaction/post.

`php -l` всех 23 изменённых/новых PHP — PASS. `git diff --check` — PASS.
JavaScript не менялся; node --check и полный suite не запускались. Старый
intercept baseline fixture не переписывался и не использовался как доказательство
нового start subset.

## Remaining UNSUPPORTED / BLOCKED

- Legacy preparations без explicit start opt-in и уже выпущенные ordinary owner
  capabilities не повышаются до start capability; нужен новый approved KB contract.
- Multi-target start abilities, прямые status/removal prepared abilities,
  active-reaction startup, derived events и recursive prepared chains не поддержаны.
- reveal_source=true, trigger_actor-relative start reveal, автоматический surprise
  bonus/потеря хода и AP overrides вне 9.2.2A subset не добавлены.
- Pet/delegate/foreign-owner предметы не получают новых spending rights. Два item
  costs одного preparation доказаны; глобальные inventory version fences между
  независимыми preparations одного owner не ослаблены и могут блокировать конфликт.
- Нет автоматического takeover intent другим GM, repair/reseal stale frozen
  dependencies или resolver для orphan owner outcomes. Они требуют явного recovery.
- UI/новых HTTP endpoint нет; используется существующий GM service API.
  publication_enabled не включён. ACS-9.2.2C не начата.

## Installed NOT_VERIFIED

warprift.ru/MyBB **1.8.41**, реальная установленная KB с новым descriptor,
production owner grants/provider/schema readiness, browser acceptance и deployment
не проверялись. Существующие Stage 6 owner schema prerequisites сохранены.
Никакой production migration, установка, merge или включение публикации не выполнены.
