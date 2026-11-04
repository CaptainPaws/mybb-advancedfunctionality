# ACS-4.1-R2 — MyBB Post Gateway

## Статус и границы

Исходная main: `e7288723`. Реализован внутренний Gateway для ограниченного native reply lifecycle MyBB 1.8.41. Код проверен с upstream `mybb_1841`; **installed-интеграция warprift.ru не READY**: установленный source, полный bootstrap, конфигурация и активные callbacks недоступны. Действует [Revision 2](ACS_TECHNICAL_ARCHITECTURE.md#publication-r2), а не общая MyBB/ACS транзакция.

Это один backend PR: Gateway, DTO, минимальные изменения существующего журнала/transaction guard и fixtures. PublishService, RecoveryService, combat writer, outbox, frontend и production hooks не создавались. `publication_enabled=false` сохранено. Core, движки, Resolver, DraftService, SealService, editor и исторические миграции не менялись. Удалённый migration wizard не восстановлен.

## Контракт подключения

`Infrastructure/Adapters/MybbPostGateway.php` предоставляет `AF_ACS_MybbPostGateway::publish(AF_ACS_MybbPostContext): AF_ACS_MybbPostResult`.

- Контекст выдаётся только `AF_ACS_MybbPostContext::fromStore(store, submission_id, expected_state_version, options)`. Он содержит серверный ряд SubmissionStore и frozen sealed snapshot, а не присланные actor/prose/pid/calculation. При исполнении ряд перечитывается, сверяются actor, request hash и sealed snapshot.
- Предусловие первого dispatch: существующий `VALIDATING` submission. Его регистрацию, корректное предыдущее подтверждение и свежесть механического плана должен обеспечить будущий PublishService. Gateway проверяет native права, frozen draft, scope/доступ участника через существующий DraftService read API, active/pending turn и claim; не вычисляет бой и не утверждает актуальность старой simulation простым совпадением hashes.
- Контекст текущего запроса: аутентифицированный MyBB POST с CSRF, native bootstrap и `THIS_SCRIPT=newreply.php`. Это сохраняет surface-sensitive hooks AdvancedAppearance. Любая другая поверхность для первого dispatch отклоняется; `THIS_SCRIPT` не подделывается. Нельзя вызывать Gateway посреди уже исполняющегося native insert или продолжать первоначальный insert после него.
- Поддерживается MySQLi writer; native read/write handles должны совпадать с ACS writer. Split routing отклоняется. Для первого dispatch проверяются версия 1.8.41 и четыре upstream SHA из [аудита R2](ACS_STAGE_4_REVISION_2_AUDIT.md); незнакомый core требует отдельной проверки, а не обходного ACP-флага.
- Options: subject, replyto, icon, signature, disablesmilies, subscriptionmethod, пустой/неиспользованный attachment posthash и quick_reply. Caller передаёт штатный выбор формы, особенно subscriptionmethod; quick_reply использует native `get_subscription_method`. `pid`, `savedraft`, client actor и client results не принимаются. Проза берётся из frozen snapshot, mask input остаётся в обычном request scope.
- Результат содержит enum `POST_CONFIRMED`, `MODERATION_PENDING`, `REJECTED` или `RECOVERY_REQUIRED`, submission ID, state version, безопасный code и native validation error codes. `pid` выдаётся только для подтверждённых post outcomes. Эти четыре исхода — DTO, не второй журнал состояний.

При недействительном CSRF/actor отклоняется сам запрос, без изменения чужого/неавторизованного intent. При невозможности записать журнал возвращается RECOVERY_REQUIRED с последней доступной версией; caller не должен считать ответ доказательством успешной смены состояния. Источник истины — durable journal и последующий writer lookup.

## Native lifecycle

1. Вне ACS tx/locks: POST/CSRF, actor, session/draft/turn и native thread/forum permissions. Проверяются canview/canviewthreads/canpostreplys, own-thread ограничения, suspendposting, закрытая тема, открытый форум, password cookies/native password check, approved topic, maxposts/day. Guest posting заблокирован, поэтому guest StopForumSpam/captcha branches не обходятся.
2. В scoped native request вызывается `newreply_do_newreply_start`. Сохраняются/восстанавливаются обычные globals/input, включая cosmetic mask. Литературные transformations стартовых hooks разрешены; смена actor/topic/branch запрещена.
3. Реальный `PostDataHandler::validate_post()` проверяет автора, message/subject, image/video, icon/options, flood и `datahandler_post_validate_post`. Нативный duplicate-content check даёт только отказ, не найденный pid и не подтверждение submission identity. Сохраняется штатный `mark_thread_read`/отложенный read-marker путь.
4. `AF_ACS_MybbReplyHandler` наследует upstream handler. Он не выключает merge: вызывает native `verify_post_merge`, а при merge candidate отказывает. Повторная проверка внутри native insert предотвращает гонку merge. Draft conversion/savedraft/modoptions/override не допускаются. Поздно обнаруженный неподдержанный branch после входа в insert conservatively требует recovery.
5. До `insert_post()` существующий SubmissionStore фиксирует POSTING/control hashes и hash подготовленного native request. Транзакция/ACS lock завершаются. Ошибка/неизвестный COMMIT этой стадии **не** разрешает dispatch.
6. Вызывается только native `insert_post()`: native subscribers, moderation, counters, parser, post hooks и обычные addon side effects не заменяются и не отключаются. После native end hooks выполняется `newreply_do_newreply_end`; это не выполнение HTML/redirect/Quick Reply renderer.
7. PID берётся из результата текущего доверенного handler invocation, сверяется с handler pid/new-reply branch и writer readback `pid/uid/tid/fid/visible`. Затем короткий ACS-only `confirmPost` пишет POST_CONFIRMED либо MODERATION_PENDING. Никаких HP/AP/turn-resolved mutations.

Native side effects могут выполняться и до INSERT (например, subscriptions/user counters). R2 не обещает их rollback при native failure; Gateway не вызывает повторно весь handler для их «исправления».

## Поддерживаемые и закрытые ветки

| Ветка | Поведение |
|---|---|
| Новый обычный authenticated reply в approved/open теме | Native validation/insertion; POST_CONFIRMED после writer readback |
| Quick Reply, backend path | Та же native reply pipeline, subscription method; AJAX HTML/JSON renderer и реальный browser submit ещё не подключены |
| Native post moderation (`visible=0`) | MODERATION_PENDING; никакой механики; native approval/revalidation — будущие задачи |
| Новый post без attachments; обычный posthash без привязанных attachments | Поддерживается, с проверкой отсутствия pending attachments |
| Upload/update/remove attachments, pending preuploaded attachments | REJECTED до insert. Не молча игнорируются и не присваиваются обходным SQL; полная attachment lifecycle — отдельное расширение |
| Native merge candidate / draft conversion / save draft / preview / moderation tools | Не входят в поддерживаемый combat reply; ранний отказ или recovery при поздней гонке |
| Guest / unapproved topic / иной script / split DB routes / незнакомый core | Ранний отказ, без native insertion |
| Переотправка подтверждённого submission | Проверка известного pid и возврат durable результата без creation hooks; изменённый/удалённый известный post — recovery signal |
| POSTING/RECOVERY_REQUIRED replay | Никогда не новый INSERT. Консервативный карантин; нет автоматического lease timeout или reset в REGISTERED |

Moderation approval не преобразуется Gateway автоматически в механическое подтверждение. Для COMMITTED обнаруженное изменение post не откатывает history: Gateway возвращает recovery signal, не меняя terminal ACS состояние.

## Транзакции, ошибки и аддоны

`assertMybbBoundary` проверяет идентичность handles, autocommit и владение именованным ACS lock. Guard `SET TRANSACTION ISOLATION LEVEL <session isolation>` отвергается MySQL/MariaDB внутри BEGIN, в том числе пустой tx; он не вызывает START/COMMIT/ROLLBACK, не освобождает чужие locks и повторяет текущую session isolation для следующей tx. Native hooks вызываются вне ACS transaction/lock; journal callbacks содержат только проверки actor/hash.

При exception/exit после POSTING — RECOVERY_REQUIRED, сохранение last_phase и ограниченных диагностических полей `gateway_phase`/`observed_pid`. Последнее — наблюдение, не confirmed link и не команда recovery. Shutdown — best effort: SIGKILL, потеря соединения, OOM или оставленная сторонним hook ambient tx могут не позволить записать quarantine; durable POSTING всё равно запрещает повтор. Не выполняется rollback чужой tx. Не используются MAX(pid), поиск по uid/tid/time или тексту как identity proof. Exactly-once INSERT не заявляется.

AdvancedAppearance/Balance/CS/AAM/PostCounter не менялись. Проверены текущие регистрации из прежнего аудита; дополнительно учтён `THIS_SCRIPT` filter Appearance и `newreply_do_newreply_start` SmartURLTitles. Маски сохраняют native input/hook path, не включаются в mechanical hashes/tape. Реальная работа активных addons, swallowed warnings и любые дополнительные hooks требуют installed staging-проверки. Fixture проверяет вызов native hook order, не полноценные addon начисления/уведомления.

## Проверки

Локально: PHP **8.5.12**, MariaDB **11.8.8**, disposable Unix socket, `posts ENGINE=MyISAM`; это **не** проверка MySQL 8.0.46 или installed warprift.ru. Использованы настоящие upstream PostDataHandler, DataHandler, parser, DB_MySQLi, functions_user и functions_indicators; permissions/cache/counter callbacks и окружение — ограниченные fixtures. Четыре SHA взяты из готового R2 аудита, полный upstream-аудит не повторялся.

Точечный запуск (нужны disposable SQL server и upstream tree, никакого production socket):

```sh
php tests/acs_mybb_gateway_regression.php /tmp/acs222-socket/r2.sock /absolute/mybb_1841
php tests/acs_mybb_gateway_regression.php /tmp/acs222-socket/r2.sock /absolute/mybb_1841 --interrupt /tmp/acs-gateway-interrupt.json
# Ожидаемый exit code предыдущей команды: 86.
php tests/acs_mybb_gateway_interruption_check.php /tmp/acs222-socket/r2.sock /tmp/acs-gateway-interrupt.json
php tests/acs_publication_journal_regression.php /tmp/acs222-socket/r2.sock
```

Проверены native success/failure, новый pid, flood, permissions/closed forum/topic/password, CSRF/actor без записи intent, moderation, repeat/lost response, merge/draft refusal, wrong uid/tid/fid, pre/post-insert exceptions, deleted-pid replay, reentrancy, attachments refusal, pending POSTING replay и запрет вызова hooks внутри BEGIN/ACS lock. Interrupted процесс после INSERT создаёт один reply и durable quarantine без confirmed link. HP/AP/events/sealed actions/snapshots/tape неизменны. Native user post counter не повторяется на replay. Также `php -l`, `git diff --check`, совместимость старого journal fixture. Полный suite не запускался.

## Что обязан обеспечить следующий PublishService

1. Проверенную установленную точку владения whole native reply request: полный bootstrap и активные hooks, newreply surface, **один** creation lifecycle. Не вставлять Gateway внутрь уже вызываемого handler и не продолжать original insert после Gateway. Не дублировать `_start/_end` callbacks внешним coordinator.
2. Native form options/CSRF и серверную выдачу/повтор ID; durable registration/VALIDATING, сохранность prose/seals. Не принимать «validated» или pid из HTTP как доверие.
3. Свежую механическую simulation/dependency/confirmation validation с исходным tape перед Gateway и повторную проверку версий/прав/поста перед отдельным ACS commit. Gateway лишь сравнивает сохранённые control hashes.
4. Обработку четырёх outcomes и достоверного журналирования. Pending/moderation/recovery не расходуют HP/AP и не закрывают ход. Нет blind retries при unknown journal/native result.
5. Собственную атомарную ACS-only механику и unique post_link/receipt/turn; механические исправления отдельными событиями. Эти части, RecoveryService, approval/delete/move reconciliation и outbox **не реализованы этим PR**.
6. Installed ручную приёмку addon effects, native Quick Reply response/redirect, editing и cosmetics до включения публикации. До этого `publication_enabled=false`.
