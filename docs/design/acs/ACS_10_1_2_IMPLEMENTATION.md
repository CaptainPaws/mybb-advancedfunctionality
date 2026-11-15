# ACS-10.1.2 — Surrender, Escape, Withdrawal, Capture и Rejoin

**Проект:** warprift.ru

**База:** main `8f2445dd` (ACS-10.1.1, PR #1065).

**Объём:** один ограниченный Backend PR, A1.28–A1.33.

**Статус:** implemented; целевые participation checks PASS, installed NOT_VERIFIED.

## 1. Основания

Прочитаны нормативные Master Specification, Technical Architecture,
Implementation Roadmap, Integration Contracts, KB Mechanics Contract и отчёты
ACS-10.1.1, ACS-7.2.2, ACS-7.3.3, ACS-6.3.2. Стоимость и формула взяты из
A1.28–A1.31; граница собственности/custody — из A1.32–A1.33.

`docs/ROADMAP.md` не изменён. ACS-10.1.3 не начат.

## 2. Один механический путь

ParticipationService добавляет synthetic encounter actions в существующий
Builder. Используются прежние Draft/Seal, fixed tape, PublishService,
CombatCommitWriter, Resolver/Effects и Clock boundaries. Нового Resolver,
transaction manager, round manager, publication journal или Inventory нет.
Новые действия не обходят native/session/control/delegation/Behavior admission.

Domain/Participation содержит типизированную policy и чистые проверки;
Effects применяет отдельную participants projection. CombatCommitPersistence
сохраняет только participation/participant_version через CAS; before/after и
результаты отдельных checks входят в immutable combat event/result. Никакой
подмены HP/life_state, controller или owner по значению participation нет.
Поддержаны engaged/surrendered/escaped/withdrawn/captured; despawn остаётся
прежним отдельным lifecycle. Restrained остаётся KB status.

В encounter config до старта закрепляется явная policy
`ic-acs.participation.v1`. Источник — разрешённые настройки столкновения; arbitrary
KB expressions, текст и client-reported outcome не исполняются.
[Грамматика и subset](../../contracts/ACS_KB_MECHANICS_CONTRACT.md#acs-1012-participation-actions-и-возвращение)
описаны в Mechanics Contract. Live KB при commit не перечитывается.

## 3. Действия

| Действие | Реализация |
|---|---|
| surrender | active engaged actor, 0 AP, без RNG; отдельный запечатанный action → surrendered |
| escape | 1 AP, fastest capable hostile, retained uniform53 check; success → escaped, failure остаётся engaged |
| group_withdrawal | 1 общий AP, explicit controlled members текущего group roster; индивидуальные проверки, частичный успех → withdrawn |
| capture | 1 AP, видимая доступная surrendered либо incapacitated/engaged цель → captured; resisting active target — UNSUPPORTED |
| release | отдельная GM POST/CAS/audit command, captured → surrendered |
| rejoin_encounter | отдельная GM command по pinned rejoin_from, active life и lifecycle/spatial/obligation gates → engaged |

Формула побега:

```text
P = clamp(60 + 30*(Speed_escape-Speed_pursuer)/(Speed_escape+Speed_pursuer),10,90)%
0/0 → 60%
нет capable pursuers и препятствий → automatic
```

Speed проходит прежний effective-stat path Resolver/Form/Equipment. Из active
engaged hostile выбирается максимальный Speed; incapable/status/Behavior-blocked
не считается способным препятствовать. Неизвестная capability не становится
нулём. Невидимый caller подходящий pursuer блокирует ветку без раскрытия его
identity/скорости. Closed ordinary turn не означает incapacitated преследователя.

Группа задаёт участников явно. Контроль/action scope проверяется для каждого
selected member, включая повтор expiry/permission check под writer locks.
Incapacitated/dead/blocked участник не эвакуируется. Не выбранные питомцы и призывы
не выходят вслед за персонажем. Персонаж может выйти, а оставшийся питомец —
выполнить следующий action того же post с оставшимся общим AP. Обычный групповой
turn закрывает прежний writer; дополнительного post/turn/budget нет.

Surrender, обычный capture и полностью automatic escape сохраняют no_random
seal без новых rolls. Prepared deferred marker не переименован и не разрешён
для обычного PublishService. Для probabilistic group departure сохраняется
отдельный tape каждого выбранного существа. Неиспользованный crit/sample не
становится новой механикой. Failure оплачивается; exact seal/commit replay
не меняет rolls. Surrender zero-AP seal attempt ограничен одним на actor/group
turn, включая cancelled history. Отмена не возвращает это право.

Participation actions не являются attack `combat.primary`: attack-only prepared,
reaction/reflect checks для них не вооружаются. Если post содержит ещё и обычный
удар питомца, его прежние фазы работают отдельно. Обязательные end boundaries и
связанные эффекты завершаются перед A1.27. Проверка end conditions читает финальную
DB projection после всего commit, а не промежуточное состояние выхода персонажа.

## 4. Release, rejoin и сохранность

Прежний AF transport расширен `participation_set`, `release`,
`rejoin_encounter`. Нужны POST, CSRF, native/session owner/GM, reason,
operation_key и expected session/encounter versions; release/rejoin также
entity_id/participant_version. Replay использует прежний operation receipt.
Commands не предоставляют контроля над чужим персонажем и не вызывают Inventory.

Release сохраняет surrendered отдельно от engaged. Rejoin не меняет AP, seal
quota/history, reaction_used, resources, cooldowns, equipment, custody или items;
не открывает закрытый turn и не создаёт Round/Turn/EntityTurn. Если identity нет
в текущем roster, действие ждёт следующего штатного admission. У summon должна
сохраняться актуальная active lifecycle; despawn не отменяется rejoin.

Capture/release не меняют HP, status definitions, controller assignments,
Inventory owner/qty, equipment и custody. Фактическая конфискация и возвращение
вещей принадлежат отдельному Stage 6 CustodyService. Established held custody и
owner reservations проверяются как сохранённые строки, а не удаляются вместе с
participation. Pending/recovery obligations автоматически не очищаются.

## 5. Совместимость и end conditions

Новых миграций нет; ledger остаётся `023_encounter_resolutions`. Исторические
migrations/checksums, events, snapshots и sealed rolls не меняются.
Participation использует существующие participants/participant_version.

Stage-7 presence.v1 остаётся прежним pinned subset для старых encounters.
Новая policy добавляется явно до старта; текущие snapshots не переписываются.
С новой policy legacy partial_withdraw запрещён, чтобы не обходить 1 AP paid
withdrawal; rejoin использует новые explicit conditions. Отсутствие новой policy
не генерирует defaults или новые player actions.

При наличии participation policy EndCondition учитывает retained стороны
surrendered/escaped/withdrawn/captured, но active side требует engaged + active.
Последняя ушедшая сторона не исчезает из сравнения. Без новой policy сохраняется
прежняя интерпретация ACS-10.1.1. Pending/GM confirmation/confirmed outcome identity
не заменены. Нет автоматической смерти, winner по HP или автоматического completed.

Query не использует permanent-pet source как доказательство element registry:
расширенная inspection выбирает только уже поддержанные CS/KB retained contracts.
Это нужно для смешанного escape → pet attack: дополнительно прочитанный pet не
может заменить валидный retained catalog непригодным source contract.

## 6. Локальные проверки

PHP 8.4.27 CLI/mysqli, disposable MySQL 8.0.46 через Unix socket. Fixtures создают
случайные БД и удаляют их в finally; production forum/DB не используются. Native
PID в SQL harness заранее известен, новый MyBB post/Gateway не создаётся.

| Требование | Доказательство |
|---|---|
| surrender 0 AP/no roll | реальный Draft/Seal/commit, RNG callback запрещает вызов, AP не меняется |
| escape success/failure | samples 0/max, success escaped/failure engaged, оба платят ровно 1 AP |
| 0/0 и fastest capable | чистый Domain fixture: 60%, fastest из двух, incap/surrender исключены |
| no pursuer | SQL automatic escape без RNG, pet остаётся engaged |
| group partial | два реальных members общей группы, разные retained tapes, 1 общий AP |
| incapacitated не эвакуируется | selected incapacitated pet остаётся engaged/incapacitated |
| pet остаётся | character escape → pet attack в том же post; AP spent=2 одного turn |
| rejoin no refill | сравнение Turn/EntityTurn/seals/rolls/resources/cooldowns/items; отдельно spent=1/quota=1/reaction_used=1 |
| capture surrendered/incapacitated | две отдельные SQL ветви; life state остаётся прежним |
| resisting capture | отдельный unsupported отказ |
| custody unchanged | настоящая held custody через CustodyService; capture/release сохраняют custody/equipment/owner rows |
| repeat sealed roll/result | exact seal/commit replay, literal rolls и receipt без повторного результата |
| end integration | вся выбранная группа ушла → pending, surviving side по participation, confirmed reader ещё null |
| rollback/permission | late outbox fault откатывает все строки; revoked pet grant после seal не даёт частичный group commit |

Целевые команды:

```sh
php tests/acs_participation_domain.php
php tests/acs_participation_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_end_conditions_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_summon_lifecycle.php
```

Четыре перечисленные команды — PASS. `php -l` всех 22 изменённых/новых PHP
и `git diff --check` — PASS. Полный suite и browser E2E не запускались.

Отдельная регрессия `acs_summon_lifecycle_sql.php` **не PASS**: на проверяемой
ветке и на отдельном неизменённом main `8f2445dd` одинаково падает на старом
held-custody assertion: expected ACS_SUMMON_OBLIGATIONS_BLOCKED, actual
ACS_CUSTODY_INTEGRITY. Исторический fixture не переписывался и не выдаётся за
успешную приёмку. Новые participation/rejoin/custody checks выполняются текущим
same-writer harness с реальными protocol rows. Старый standalone
`acs_resolver_regression.php` остановился на bootstrap `No direct access` и
не используется как доказательство; новые Domain проверки загружают engine
с явным fixture IN_MYBB context.

## 7. Границы и ручная приёмка

Поддержан узкий encounter subset: unmapped abstraction либо существующий same-zone
Spatial/LoE. Любые active environment objects, cross-zone pursuit/escape routes,
перенос пленника, эвакуация incapacitated, resisting capture и arbitrary KB
predicates/callbacks остаются UNSUPPORTED. Seal не дорисовывает отсутствующие
checks при изменении dependencies; требуется проверка исходного tape.

Installed warprift.ru, реальный KB/owner provider, MyBB hooks/permissions,
целевая PHP 8.5.12, тема и browser flows — NOT_VERIFIED. Сохраняются прежние R2
publication gates и `publication_enabled=false`. Нового UI, loot, EXP/валюты,
outbox consumer и lifecycle закрытия/архива в этом PR нет.

На staging проверить роли owner/GM/player/delegate/observer, неизвестных
преследователей, 0/0, mixed group partial departure, surrendered/incapacitated
capture, separate custody/release и rejoin до/после нового roster. После повторов,
ошибок и reactivation сравнить tape, group budget, closed turns, reactions,
resources и held obligations. ACS-10.1.3 автоматически не запускать.
