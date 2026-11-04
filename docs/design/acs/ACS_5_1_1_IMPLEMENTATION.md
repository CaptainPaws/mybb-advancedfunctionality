# ACS-5.1.1 — Status Probability, Immunity и Trigger Evaluation

База: `main` = `e0f595639a7cab3c1708f2415ca2ef66e1b8e1e5`, после Stage 4 Revision 2 и реорганизации документации. Scope: детерминированная **оценка** статуса существующим Resolver. Статусные экземпляры не создаются; успешная проверка не означает, что уже реализованы их хранение, сила, длительность или воздействие на характеристики. ACS-5.1.2 не начинается.

## Изменённые файлы

Все PHP-пути ниже относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

| Файл | Изменение |
|---|---|
| `modules/Domain/Effects.php` | Чистый `status()` и закрытый по умолчанию `AF_ACS_StatusEvaluation`; проверка definition refs, trigger, immunity, B/H/R, отдельного sample. Это расширение существующего Effects, не второй исполнитель |
| `modules/Domain/Resolver.php` | Подготовленные `statuses[]`, declared trigger phases, порядок KB внутри фазы, реальные primary HP loss, status-only оценка без фиктивного damage component, отдельные tape indexes |
| `modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php` | `statusEffect()` проецирует уже разрешённый и закреплённый B1 status effect; проверяет точный typed status definition/version/fingerprint и исходный root effect index |
| `modules/Application/QueryService.php` | Hydration status effects из pinned ability DTO; typed status immunities из registration snapshot; старые проверки прав, hostile targets и visibility остаются обязательными |
| `modules/Application/BuilderService.php` | Объединяет status immunity context и пары source/target relationship при нескольких действиях |
| `modules/Application/SealService.php` | До RNG определяет полный набор отдельных status checks; сохраняет их существующим appendSeal; включает hash оценки в confirmation summary |
| `modules/Infrastructure/RandomSource.php` | Дополнительные server-only uniform53 samples по root KB effect indexes, без переиспользования hit/crit |
| `modules/Infrastructure/SealPersistence.php` | Читает/пишет `check_kind=status`, `roll_index=KB index` в существующей таблице rolls; строго проверяет samples и восстанавливает порядок |
| `modules/Infrastructure/CombatCommitWriter.php` | Тот же status evaluation hash в representation подтверждения Seal/Commit; сам Resolver по-прежнему исполняется только с сохранённым tape |

Дополнительно: `tests/acs_status_evaluation.php` и этот отчёт. `Numeric.php` проверен и используется без изменений. Миграций, новых KB schemas/editor controls и изменений `docs/ROADMAP.md` нет. Сохраняются `acs-resolver-2`, numeric policy `binary64-half_up-v1`, RNG `uniform53-v1`; status evaluation имеет отдельный trace version `acs-status-evaluation-1`. Для действий без статусов формат двух бросков и representation summary не расширяются.

## Нормативная семантика

S1.1–S1.3, C1.3/C1.4 и A1.17–A1.19 читаются вместе с порядком из [Architecture §4](ACS_TECHNICAL_ARCHITECTURE.md) и [Master §4.1](ACS_MASTER_SPECIFICATION.md). Используется непосредственно:

```text
H=R=0: P_status = B
иначе: P_status = clamp(B + 30*(H-R)/(H+R), 0, 100)
успех: 100*u < P_status
```

Промежуточного округления нет. H/R — неотрицательные показатели; B — явное число 0..100 конкретного эффекта. B=0 не вводит отдельный veto: например, H=30/R=10 даёт P=15. Противоречивый комментарий S1.3 не исправлялся. Canonical `status_resistance` использует существующий boundary alias `status_resist`; конфликт двух значений закрывает расчёт, показатели не суммируются. Explicit null не получает fallback из legacy alias.

Для positive effect на явно переданного ally/self базовое правило — гарантированная применимость по вероятности, P=100, без status roll; immunity/trigger всё равно проверяются. Explicit boolean `probability_check=true` требует обычной формулы и отдельного sample. Запрос выключить обязательную негативную проверку отвергается; неизвестные overrides не получают default semantics.

Иммунитет запрещает успех независимо от P. Поддержанный typed subset: `{scope:status_key,key:<status key>}` и `{scope:status_type,key:<definition.type>}`; сравнение точное. Строки, неизвестные scopes, числовая resistance вместо immunity и неявные wildcard не интерпретируются.

`on_cast` оценивается после успешной проверки/виртуальной оплаты способности, до damage. `on_hit` — после установленного hit, включая damage=0. `on_damage` — после barrier/HP consumption по **реальной primary HP loss**, без absorbed/overkill/HP cost/reflection. Фазы не переносятся в конец действия. Внутри фазы root effect indexes идут в сохранённом порядке. Неподдержанное определение возвращает структурированную причину через Effects; Resolver/Seal preflight закрывает такое действие целиком.

## Реальная граница KB

Typed B3 `status_def` описывает payload/duration/events, но не гарантирует наличие application B/polarity/trigger в legacy effects. Поэтому adapter **не объявляет старые статусы автоматически готовыми**. Для узкого production projection нужны явные `parameters.base_chance`, `parameters.polarity=negative|positive`, `parameters.trigger=on_cast|on_hit|on_damage` конкретного root `effects[]`; optional `parameters.probability_check` — boolean. Это входной ACS application contract для уже сохранённых явных данных, а не миграция/добавление механики в KB.

Не используются fallback из `value`, parent ability, текстов, названий, status `phase` или `duration`. B1 status reference должен быть resolved, typed `af_kb.arpg.mechanic.v1`, с совпадающими key/version и вычисленным definition fingerprint. Отдельно удерживаются parent ability key/version/fingerprint, type и исходный effect index. Изменение retained definition без смены fingerprint отвергается. Формулы/условия и неизвестные application extensions закрыты; status-definition overrides condition_ref/effect_ref/limits/resistances/immunities/formula_refs и effect can_crit=true тоже требуют отдельного поддержанного контракта; они не игнорируются ради исполнения. Payload status_def остаётся данными: events, ticks, periodic effects и stacking не запускаются этой оценкой.

QueryService по-прежнему ограничен разрешённым GM-visible single_enemy scope, положительной AP cost и текущими snapshot/roster/dependency checks. Allies/self поддержаны **подготовленным Domain-контрактом и fixtures**, но публичный target selector не расширен и новые права/visibility policy не добавлены. Неполный либо неподдержанный KB contract остаётся BLOCKED. Никакого чтения latest KB в Resolver/Commit нет.

## Tape, confirmation и recovery

Для каждого contested status резервируется отдельный sample независимо от будущего hit/immunity gate. Условно неиспользованный sample сохраняется и не заменяется новым. Non-contested positive status не резервирует status sample. Существующий action-level hit/crit tape остаётся совместимым, в том числе для status-only action; его неиспользованный crit не становится status roll.

Форма дополнительной части: `tape.status[<root effect index>] = {sample_n,u}`. Persistent identity:

```text
causal_key = sealed-action:<sealed_action_id>
check_kind = status
roll_index = root KB effect index
```

Используется существующий UNIQUE `(causal_key,check_kind,roll_index)` миграции 007. В server result `roll_ref={action_index,check_kind:status,roll_index}` сопоставляется с соответствующим retained sealed action из submission seals; это ссылка на сохранённый бросок, не клиентское значение. Все action/component IDs и fingerprints доступны только серверному plan. Tape hash включает status samples; отсутствующие/лишние/corrupt samples не восполняются. Старый tape с двумя checks воспроизводится без добавления `status:[]` и без изменения прежнего tape hash.

Preview рассчитывает P и gate/immunity, но не случайный success/failure. Seal получает samples только из server RandomSource и сохраняет их в своей существующей транзакции. Commit, confirmation/read и recovery используют прежний Resolver mode `seal` с сохранёнными samples. Hash status evaluation включён одинаково в Seal и CombatCommitWriter confirmation summary; в общий DTO не добавляются status definition payload, H/R, immunity details или samples. `jsonSerialize()` status/result DTO выдаёт только безопасные metadata; полный `serverData()/serverPlan()` остаётся внутренним.

Поздние prepared/causal triggers в эту поставку не входят. Для них Architecture требует отдельный **persistent causal-event roll key**; текущие sealed-action rolls нельзя переиспользовать или перегенерировать. Новый поздний causal command/handler не зарегистрирован. RNG при GET/showthread/recovery не добавлен. Publication test-only/disabled guards Stage 4 R2 не снимаются.

## Выполненные точечные проверки

Локально доступен **PHP 8.4.24 CLI**, не целевой PHP 8.5.12. Команды завершились с exit 0:

```sh
php tests/acs_status_evaluation.php
php -l <каждый из девяти изменённых PHP и tests/acs_status_evaluation.php>
git diff --check
```

Новый fixture переиспользует узкие существующие Resolver/Effects/Damage/source fixtures, без нового framework. Покрыты:

- 0/0=B; H>R; clamp 0/100; B=0/H>R без дополнительного запрета.
- Status-key/type immunity; positive ally без status roll; explicit contested override; запрет отключения negative check.
- on_hit с damage=0; on_damage при полном absorption; on_cast на успешном cast при miss и отказ на failed cast.
- Порядок разных trigger phases и двух компонентов одной фазы; status-only action не наносит фиктивного урона.
- Fixed tape replay, отдельность hit/status samples, missing/corrupt status tape, legacy alias/conflict и совместимость двух старых checks.
- Настоящий KB readDefinition/normalizer → QueryService projection; B=0 и exact references; missing definition, missing B, unknown trigger, fingerprint drift.
- Production `appendSeal()/sealTape()` через fixture с перехватом SQL: causal key, индексы, float/JSON roundtrip и идентичность Resolver result после восстановления. Это проверка SQL boundary, **не** реальной транзакции MariaDB/MySQL.
- Preview не изменяет входной state, не вызывает RNG; owner DB fixture вообще не имеет write методов, baseline таблиц не меняется. Полный HTTP/DB preview не запускался.
- Вложенные существующие fixtures подтверждают прежние hit/crit/miss, DEF/17 RES, barriers, reflection/lifesteal, AP и source permissions. Полный suite и браузерные E2E не запускались.

## Ограничения и ручная проверка

Нет installed проверки warprift.ru, PHP 8.5.12 или реального MySQL. Нет status instances/persistence, duration/ticks, stacking, cleanse/dispel, upkeep, reactions, публикации реакций или поздних causal handlers. Сохранение server evaluation в существующем combat result не является наложением persistent status. Positive ally Domain semantics не являются новым publicly playable allied flow.

На изолированной копии форума с целевой средой:

1. Выполнить fixture и lint на PHP 8.5.12; оставить publication guards Stage 4 R2. Использовать только уже разрешённый тестовый scope и существующие права контроллера/GM.
2. Закрепить явное тестовое KB ability/status definition штатным registration flow, без переписывания прежних snapshots. Проверить missing B/ref/trigger: action BLOCKED, RNG/rolls не добавлены. Для prepared ally/self case использовать доверенный Domain context, не POST с произвольными stats/rolls.
3. Сравнить DB rows до/после preview, включая rolls/sealed_actions/events/resources: ноль записей. Проверить P для 0/0, H>R и B=0 по fixture.
4. Seal действия с несколькими negative status components: одна новая строка `status` на contested root index, отдельные от hit/crit. Сверить tape hash и definition pins. При immunity/miss sample остаётся сохранённым, `roll_used=false`.
5. В разрешённой test-only публикации подтвердить итоговую summary/hash, сохранённый server plan и compound roll identity. Повторить status/read/recovery/потерю ответа: те же samples, ни новых rolls, ни повторной HP/AP мутации. Отсутствие status instance ожидаемо.
6. На подготовленном virtual barrier проверить hit с нулевым damage и полностью absorbed strike: on_hit допускается, on_damage=false. Публичная hydration persistent barriers не добавлена.
7. Проверить projection visibility: скрытые H/R/immunities/definition payload отсутствуют у неуполномоченных; result card не пересчитывает и не бросает RNG. Подменённый client roll не входит в allowlist существующих endpoints.

Для дальнейшего installed допуска требуются прежние доказательства Stage 4 R2. Этот PR их не заменяет и не включает publication автоматически.
