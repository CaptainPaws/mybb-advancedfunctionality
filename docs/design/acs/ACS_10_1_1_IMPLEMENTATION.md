# ACS-10.1.1 — End Conditions, Pending Resolution и GM Confirmation

**Проект:** warprift.ru

**База:** main `56a4d6d0` (завершённый ACS-9.3.4, PR #1064).

**Объём:** один ограниченный Backend PR, A1.27.

**Статус:** implemented; локальные точечные проверки PASS; installed NOT_VERIFIED.

## 1. Основания

Прочитаны ACS_MASTER_SPECIFICATION, ACS_TECHNICAL_ARCHITECTURE,
ACS_IMPLEMENTATION_ROADMAP, ACS_INTEGRATION_CONTRACTS,
ACS_KB_MECHANICS_CONTRACT и implementation/acceptance отчёты 9.3.4, 8.3.3, 4.5-R2.
Приоритет сохраняет A1.27: проверка после полного механического результата,
затем pending_resolution, затем отдельное решение GM.

`docs/ROADMAP.md` не изменён. ACS-10.1.2 не начат.

## 2. Реализация

Сохранён один путь Builder/Draft/Seal → PublishService → CombatCommitWriter →
Resolver/Effects. Нового Resolver, Clock, системы раундов, RNG, Inventory ledger
или publication coordinator нет. Проверены EncounterService,
RoundManagementService, PublishService, EncounterRepository, CombatCommitWriter,
Resolver и Effects. Existing active-state guards уже блокируют ordinary
Builder/Query/Draft/Clock/Round/commit в pending; их не заменяет новый процессор.

Добавлен небольшой Domain/EndCondition и persistence trait на том же writer.
Проверка вызывается в конце механической транзакции CombatCommitWriter, после
полной Resolver projection, reactions, prepared phases, reflect/absorb/lifesteal,
связанных эффектов, EntityTurn boundaries и spawn admission, а также после записи
combat event/result/receipt. Ни preview, ни seal, ни GET её не вызывают.

Для encounter-start проверка встроена в конец прежнего audited
`encounter.ambush_start` command, после всех paid causal children и первого roster.
Terminal start receipt сохраняет pending result; replay не запускает засаду снова.
Нет отдельного механического commit между последним child и pending-переходом.

Условия берутся только из явной типизированной policy полного immutable
encounter-config snapshot. Нет live KB lookup, анализа прозы или default victory.
[Исполняемый subset](../../contracts/ACS_KB_MECHANICS_CONTRACT.md#acs-1011-typed-encounter-end-conditions):

- `last_active_side`: ровно одна из минимум двух engaged сторон имеет active
  участника, все пары сторон явно hostile. Сервер предлагает victory этой стороны.
- `no_active_sides`: active участников нет ни у одной из минимум двух hostile
  сторон. Winner null; GM выбирает только закреплённые draw/inconclusive.
- `allow_continuation` задаёт право вернуть pending в active. Оно не лечит,
  не пополняет AP, не открывает закрытые ходы и не повторяет последний удар.

Policy задаётся GM до старта. Отсутствующая policy сохраняет прежнее поведение.
Allied/neutral/missing relationships не превращаются в правило победы.
Incapacitated и dead остаются разными состояниями. HP totals не сравниваются.

## 3. Durable pending и GM confirmation

`active → pending_resolution` выполняется под прежними transaction/session locks,
с encounter/session CAS и отдельным `encounter.pending_resolution` event.
Audit содержит trigger event, pinned config и typed candidate с авторитетными
life/participation/relationship versions. SQL UNIQUE защищает trigger identity.
Ошибка после перехода откатывает весь mechanical commit, включая pending.

Backend API подключён к существующему AF transport:
`xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem`.

- `outcome_status` — GM-only GET, read-only pending/confirmed summary и версии.
- `end_conditions_set` — POST policy до старта.
- `outcome_confirm` — POST resolution_id и допустимый outcome.
- `outcome_continue` — POST resolution_id при закреплённом разрешении продолжить.

Commands требуют native/session owner/GM, POST, CSRF, expected session и encounter
versions, reason и operation_key. Глобальная admin роль без session GM не заменяет
право решения. Transport отклоняет неизвестные поля и client winner. GM выбирает
только kind исхода; winner берётся из серверного candidate. Перед решением
повторно проверяются policy pin, candidate hash и авторитетное состояние.

Решение фиксируется отдельным immutable `encounter.outcome_confirm` либо
`encounter.outcome_continue` event и terminal operation receipt. Projection
resolution меняется из pending ровно один раз. SQL UNIQUE completed_encounter_id
защищает одно завершение. Повтор того же запроса возвращает прежний результат;
изменённый payload с прежней key отклоняется. Новая key после completion не создаёт
новое завершение. Потеря ACK разрешается status lookup/replay исходной key.

`EncounterRepository::confirmedOutcome(sid,eid)` возвращает null до подтверждения,
затем проверяет terminal receipt hash, scope/identity и immutable confirmation
event/details/operation. Identity: `acs-outcome:<encounter_id>:<confirmation_event_id>`.
Следующая подсистема должна читать этот verified confirmed outcome, а не pending
candidate или клиентский winner.

Inventory reservations, custody, prepared/recovery obligations не удаляются,
не освобождаются и не исполняются автоматически. Подтверждённые damage/results,
sealed actions и rolls не изменяются. Loot, EXP, валюта, permanent sync и новый
outbox consumer в этот PR не входят.

## 4. Миграция

Новая `023_encounter_resolutions`: session/encounter, trigger/pending event,
config snapshot, candidate/hash, projection status, terminal event/receipt/hash и
nullable UNIQUE completed encounter. Historical migrations 001–022 и их checksums
не меняются. Schema registry/readiness обновлены согласованно. Повторный runner на
populated confirmed outcome проверен без изменения строк. Activation прежнего
ambush API проверяет populated lifecycle с новым ledger.

## 5. Минимальные проверки

Среда: PHP 8.4.27 CLI/mysqli, disposable MySQL 8.0.46 через Unix socket, без TCP.
Целевая установленная PHP 8.5.12 этой средой не сертифицируется. Fixtures создают
случайные БД и удаляют их в finally. Используются прежние fixture PID; native
публикации, production БД и сообщения warprift.ru не используются.

| Проверка | Доказательство |
|---|---|
| normal victory | реальный combat → pending; confirmed reader возвращает null до GM |
| mutual incapacitated | реальное lethal primary + reflected child; winner null, victory запрещён, draw разрешён |
| prepared child до завершения | оплаченный self-target child после primary меняет последнюю active сторону; end check видит final state |
| ambush child до завершения | реальный paid start с двумя owner consumes → pending, один roster, replay |
| repeated outcome / ACK loss | один confirmation event/identity, прежний terminal receipt, неизменная history |
| concurrent GM | второй DB writer пытается подтвердить при удерживаемом первым lock; ACS_MAINTENANCE_BUSY; повтор после completion не завершает снова |
| stale version | session и encounter CAS отклоняются отдельно |
| pending action denied | Draft admission и Query active actor запрещены; writes отсутствуют |
| allowed/forbidden continuation | pinned true возвращает active без refill/reopen; pinned false отклоняется |
| rollback | fault после pending CAS откатывает damage/receipt/pending; отдельный confirmation fault откатывает решение; prepared owner consume откатывается с parent |
| unauthorized actor | чужой actor, GET, плохой CSRF и forged winner отклоняются |
| obligations/history | реальная active owner reservation и recovery_required operation сохранены; sealed rolls, damage/results и receipts совпадают до/после GM |

Выполнены только целевые fixtures:

```sh
php tests/acs_end_conditions_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_end_prepared_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_end_ambush_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_effect_responses_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_ambush_api_sql.php /tmp/acs222-socket/mysql8.sock
```

`php -l` всех изменённых/новых PHP и `git diff --check` — PASS.
Полный test suite и browser E2E не запускались.

## 6. Границы и ручная приёмка

Installed warprift.ru, реальный KB, MyBB hooks, тема, native permissions и owner
providers не проверены. Сохраняются все R2 publication gates; флаг публикации не
включается. Новый backend endpoint не является интерфейсом этапа 11.

Unsupported: произвольные predicates/callbacks, alliance winners, HP tie breakers,
objective/round-limit outcomes, arbitrary GM winner override, новые
surrender/escape/capture/rejoin/close/archive правила. Наличие KB text или generic
combat_template не доказывает поддержку end condition.

На staging проверить GM/player/observer/native-denied API, lethal action с
реальными reaction/prepared/reflect/absorb, pending admission, concurrent GM и
повтор после потери HTTP-ответа. Сравнить seals/rolls/damage/owner reservations до
и после confirmation/continuation. Проверить сохранение obligations после
реактивации на целевой PHP/MyBB; rewards не запускать.
