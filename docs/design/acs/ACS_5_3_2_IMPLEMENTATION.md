# ACS-5.3.2 — Intercept, Mass Defense и Resolver integration

Дата: 2026-11-04. База main: `efba562be3de7d5c025e0010a278809f79a415f1` (ACS-5.3.1). Один ограниченный PR; Stage 6 не начинается.

## Реализовано

Единственный `Resolver` сохраняет `original_target` и `resolved_target`. Успешный control/intercept меняет только серверный результат, не sealed action. Accuracy/Speed, hit outcome, DEF/RES, integer damage и поглощение рассчитываются для фактической цели с **теми же hit/crit samples**. Неудачный перехват оставляет следующую допустимую реакцию в очереди; недоступный перехват не оплачивается. Один успешный redirect возможен только для single-target способности. Для multiple атаки redirect запрещён, даже если выбран один участник.

Существующие фазы и порядок ACS-5.3.1 сохранены. До выполнения повторно проверяются источник, контроллер/assignment, текущий EntityTurn/reaction_used, союзность, участие, разрешённая цель, условия, HP/resources и KB overrides. Resolved обычного хода не расходует право на реакцию и не открывается реакцией. Активная реакция не вызывает очередь активных реакций.

Явный mass shield создаёт per-target либо shared pool через **существующий Effects/barrier_apply**, с одним основным платежом и одним reaction_used. Дополнительные resource costs на цель действуют только при явном costs_per_target. Multiple targets сохраняют выбранный порядок; all_allies выбирается сервером в стабильном порядке entity ID при регистрации. Есть проверка явного target_count. Общий пул последовательно поглощает урон в установленном порядке. Его остаток сохраняется между действиями. Длительность уменьшается только на существующих завершающих Clock boundaries; duplicate boundary не уменьшает её повторно. Combat reset завершает оставшиеся combat pools.

Точный consumer binding описан в `docs/contracts/ACS_KB_MECHANICS_CONTRACT.md`. Существующие KB kinds control/shield и owner duration_value/duration_unit используются без изменения KB owner. Цена и вероятность не выводятся из текстов или масок. Неподдержанные override/schema блокируются.

Для явно связанных пассивных дочерних причин добавлен небольшой структурный `CausalGraph`: cycle/reference/depth validation, стабильный обход, visited по cause + pinned component, однократное применение общей DAG-вершины. Числового глобального trigger depth в Architecture нет: необходимы явные KB limits.max_depth и per_cause=1; новый игровой предел не вводится. Поддержан узкий literal heal/damage child без crit/затрат и дополнительных override; произвольные callback/formula chains не исполняются. Цикл возвращает `ACS_CAUSAL_CYCLE` до принятия результата. В Writer это оставляет ACS состояние, черновик и sealed tape без частичных изменений. Native post, если уже создан, остаётся в действующей recovery/quarantine модели Stage 4 R2.

## Реальные интеграции и повторное использование

- `Effects.php`, `Resolver.php`, `Clock.php`: исходные HitCrit/Damage/Numeric, ресурсы/HP, барьеры, status triggers/instances, immediate sustained interruption и Clock end boundaries. Формулы не изменены; нового Resolver/Effects/Clock/upkeep engine нет.
- `QueryService.php`, `BuilderService.php`: authoritative third-party projections, retained stats/RES/resources, controller/roster checks, sealed dependency proofs и упорядоченные multiple targets. Чужой скрытый источник не становится доступным через player selector.
- `ReactionService.php`, `ReactionPersistence.php`, `KnowledgeBaseAdapter.php`, ACS router: серверная регистрация protected targets с существующими CSRF/CAS/idempotency; pinned definition graph, отдельные deterministic reaction roll identities, повторная проверка цели. Маски не входят в identity/conditions.
- `SealService.php`, `RandomSource.php`, `SealPersistence.php`: прежняя single-target tape остаётся совместимой; для multiple сохраняются отдельные hit/crit/status rows с `sealed-action:ID:target:ENTITY` в существующей rolls. Одно action расходует AP один раз. Клиент не передаёт samples. Preview/status/confirm/commit/recovery не генерируют RNG. Уже предусмотренный prepare_reactions может записать только отсутствующий серверный causal check; прежняя атака не перебрасывается.
- `CombatCommitWriter.php`, `CombatCommitPersistence.php`: прежние confirmed receipt/post_link, CAS fence, journal, outbox и ACS transaction rollback. При изменении третьей сущности или пула действует тот же dependency fence. Pool insert/update, ресурс, reaction_used и result входят в одну ACS операцию. Virtual pool IDs сопоставляются SQL IDs в changes.barriers; история результата неизменна.
- `StatusPersistence.php`, `ClockPersistence.php`: status rows и tagged barrier rows не смешиваются. Обязательные start ticks/state transitions/upkeep проходят прежде допуска действия; границы и их receipts используются повторно. Барьеры доступны для поглощения tick damage. Orphan/projection/pin/schema mismatch блокируются.
- `CombatCardQuery.php`, `TerminalReceiptPersistence.php`, `CombatCard.php`: карточка читает только подтверждённый immutable combat result и зафиксированную в нём boundary history. Доступному GM/owner видны статусы/стаки/сроки, ticks/upkeep, исполненные/пропущенные реакции, original/resolved target и результат перехвата. Player по существующей policy получает только статус, без скрытых details в JSON/DOM. HTML экранируется; showthread не подключает Resolver и не исполняет механику.

`PublishService`, `RecoveryService`, HitCrit/Damage, MyBB Core, SCEditor и общий native publication protocol не заменялись. CharacterSheets и постоянный Inventory не изменяются. Нового reaction post нет. Настройка publication_enabled не менялась.

## Схема

Перед изменениями в disposable MariaDB проверены фактическая схема и migration ledger: последняя применённая `011_reaction_registry`, `af_acs_reactions` уже существует; pool tables ещё отсутствуют. Поэтому необходима **012_barrier_pools**, а не повторная reactions migration.

012 создаёт предложенные Architecture `af_acs_barrier_pools` и `af_acs_barrier_targets` (ACS InnoDB). Пул связан с существующим af_acs_effects; tagged versioned payload хранит pinned reaction origin, source ref, duration и targets. Strength/version/status/FIFO/absorbs дублируются в typed SQL projection и проверяются против payload/pin. Unique effect_pool и membership PK защищают идентичность. `Administration/Migrations.php` и `Repository.php` используют новый ledger/writable gate. Старые migration checksums не изменялись. MyBB posts/threads/forums не мигрируются на InnoDB.

Новые файлы кода: `migrations/012_barrier_pools.php`, `modules/Domain/CausalGraph.php`, `modules/Infrastructure/BarrierPersistence.php`. Остальные изменённые PHP перечислены выше; корень — `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`. В tests добавлены `acs_intercept_mass_defense.php`, `acs_intercept_mass_sql.php`; старый status tape SQL stub обновлён под реальный SELECT causal_key, а 531 schema assertion использует текущую Migrations::VERSION.

## Локальные точечные проверки

Среда проверки: PHP **8.4.24**, MariaDB **11.8.6**, отдельный disposable database/socket. Это не заявленная installed PHP 8.5.12/MyBB 1.8.41. MyISAM fixture posts/threads/forums сохранены. Cloud/browser E2E, полный suite и общее тестирование аддонов не запускались. Существующие focused fixtures подключают собственные setup/regression dependencies.

| Проверка | Локальный результат |
| --- | --- |
| php -l всех изменённых/новых PHP; git diff --check | PASS |
| acs_intercept_mass_defense.php | PASS: другая Speed меняет hit; другая DEF меняет damage; original target не повреждён; hit/crit неизменны; replay детерминирован |
| Failed intercept / shortage / one redirect | PASS: следующая допустимая реакция проверяется; неоплаченная пропускается; повторный redirect отсутствует |
| Mass/shared pool, multiple order, одна оплата/лимит | PASS; target-specific tape roundtrip использует production SealPersistence |
| Barrier Clock end countdown/duplicate/expiry | PASS в Domain |
| Causal cycle/depth/visited + retained cycle in Resolver | PASS: весь результат отклоняется, входное состояние и tape неизменны |
| acs_intercept_mass_sql.php socket mass / intercept | PASS: реальный KB adapter, третья сущность, controller/grant/roster/targets, resolved source turn, schema 012 resume, CAS/provenance, shortage, rollback, duplicate commit, recovery, mask, visibility |
| Внедрённый retained graph cycle после seal, до Writer commit | PASS: ACS_CAUSAL_CYCLE, снимок всех ACS tables/черновика/tape не меняется; после восстановления graph прежняя tape применима |
| Late outbox failure после pool/resource/slot/result writes | PASS: полный ACS rollback; известный native PID не создаётся повторно |
| ACS-5.1.1–5.3.1 Domain regressions (dependencies нового fixture) | PASS: 0/0/H-R/immunity/on_hit zero/on_damage absorbed, source separation/refresh/stack/partial cleanse, poison=3/last tick/control=1/HoT/pause/pass, atomic upkeep/FIFO/custom order/0HP, phases/priority/affordability |
| acs_status_ticks_sql.php; acs_sustained_commit_sql.php | PASS: реальные start/last ticks, HoT/control, duplicate, pause/pass/forfeit, immediate sustained interruption, late rollback/replay |
| acs_reaction_registry_sql.php; acs_result_gm_ui_regression.php | PASS: прежняя регистрация и confirmed card/GM recovery/visibility/read-only policy |

JS не изменён, node --check не требуется. Команды запускаются установленным PHP, например `php tests/acs_intercept_mass_defense.php`; SQL fixtures требуют аргумент пути disposable socket: `php tests/acs_intercept_mass_sql.php /path/to/socket mass` и `... intercept`. Они не предназначены для рабочей БД форума.

## NOT_VERIFIED на установленном форуме и ограничения

Installed publication, native lifecycle hooks/moderation/recovery routes, installed migration upgrade, ATF/Appearance rendering и PHP 8.5.12 **NOT_VERIFIED**. Существующая приёмка ACS_4_5_R2_INTEGRATION_ACCEPTANCE не заменена локальными результатами. Если publication_enabled=false, она должна оставаться false до отдельной ручной приёмки; задача её не включает.

Numeric builder сохраняет существующую GM-scoped visibility. Новый полный player reaction editor/стадийный UI этапа 11 не создавался: protected targets передаются существующему reaction_register endpoint, проверяются и фиксируются сервером. Auto-selection закрепляется на регистрации, не пересобирается при commit. Не поддержаны произвольные reaction/passive formulas, другие виды перенаправления или массовые механики без явного binding; они блокируются. Cross-quest persistent transfer, этап 6 и новая CharacterSheets механика отсутствуют.

## Ручная приёмка

1. На отдельной копии форума проверить текущий ledger/schema, выполнить обычный migration runner до 012; повторить runner и сверить старые checksums/данные. Не менять движки основных MyBB tables и publication flag.
2. Подготовить pinned KB attack, два союзных защитника с различными Speed/DEF/RES и явные reaction definitions из consumer binding. Выдать существующие grants/controllers/combat permissions; открыть индивидуальные EntityTurns. Для reaction_register передать targets как JSON-массив строковых entity IDs, обычные session/encounter versions, CSRF и operation_key. Проверить запрет чужого player target и разрешённую регистрацию после resolved.
3. Preview/status: сверить отсутствие изменений HP/resources/effects/rolls. Seal: записать attack tape, при необходимости отдельно prepare_reactions. Подтвердить старую tape после регистрации; произвольный resolved_target в draft не принимается.
4. На отдельно разрешённом native publication пути отправить single attack с intercept. Сверить original/resolved target, повторный Hit/DEF/RES/barrier расчёт и прежние samples в подтверждённом event. Проверить недостаточную оплату, неудачный первый intercept, второй допустимый и запрет второго успешного redirect.
5. Проверить explicit mass shield/shared/per-target pool, порядок выбранных целей, одну основную оплату/лимит; явные extra target costs — отдельно. Следующий action и Clock boundaries должны использовать сохранённый остаток. Проверить expiration/reset и отсутствие продвижения при pause/GET/narrative.
6. Повторить poison=3, последний тик/control=1, два источника/stack cap/partial cleanse и HP+EP upkeep shortage. Проверить ticks → state transition → upkeep → action; healing не возвращает прекращённый sustained.
7. Повторить late fault, duplicate submission, recovery/lost response и cycle diagnostics. Проверить сохранность prose/tape и отсутствие повторной оплаты/тика/реакции. В showthread сопоставить карточку с receipt/event; под player/посторонним проверить отсутствие скрытых details в DOM/JSON. Смена маски не меняет механику.
