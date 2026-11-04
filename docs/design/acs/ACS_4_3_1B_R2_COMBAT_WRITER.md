# ACS-4.3.1B-R2 — Transactional Combat Writer

## Результат и границы

База: main `2c4a216d` (включает Gateway PR #1014). Реализован внутренний `Infrastructure/CombatCommitWriter.php` и SQL-only trait `CombatCommitPersistence.php`. Это задача **1** из продолжения Stage 4 R2; PublishService, RecoveryService, moderation hooks, frontend и outbox consumers не реализуются и не запускаются этой поставкой.

Writer — единственный producer окончательного persistent результата существующего Resolver. Он не вызывает и не загружает MybbPostGateway/PostDataHandler, не создаёт MyBB-post, не расходует Inventory, не начисляет Balance/EXP и не доставляет уведомления. `publication_enabled=false` сохранено. Боевые формулы, Resolver, DraftService, SealService и Gateway не менялись. Прежние миграции 001–008 неизменны; MyBB engines и core не менялись, удалённый migration wizard не восстановлен.

## Схема 009

`009_combat_commit` создаёт четыре собственные таблицы InnoDB/utf8mb4/Dynamic, без физических FK к MyBB:

| Таблица | Назначение и ограничения |
|---|---|
| `af_acs_post_links` | submission/session/encounter/turn ↔ pid/tid/fid/author; UNIQUE submission_id, pid, turn_id |
| `af_acs_combat_results` | Persistent wrapper с engine/numeric versions, summary, полным plan/ordered effects traces, initial/final state и changes; PK submission_id, UNIQUE turn_id/event_id, SHA256 payload |
| `af_acs_combat_receipts` | Неизменяемый terminal COMMITTED receipt с ссылками, control/result/request/sealed hashes; PK submission_id, UNIQUE turn_id/pid, SHA256 receipt |
| `af_acs_outbox` | Одна pending domain message `combat.committed` на submission; UNIQUE message key и submission/kind, payload hash. Dispatcher/owner consumers отсутствуют и не включены |

Для staging требуется штатный явный schema upgrade ACS до 009; на production он не выполнялся. MyBB-post хранится отдельно, только читается по ранее подтверждённому pid. Эта схема не обещает общую SQL-атомарность MyBB+ACS. UNIQUE turn_id соответствует существующей модели: один combat result на глобально уникальный group turn; следующие раунды получают другие turn IDs.

## Серверный API

```php
$writer = new AF_ACS_CombatCommitWriter($mybbDatabase, $sessionAccess);
$receipt = $writer->commit($submissionId, $expectedSubmissionStateVersion);
$known = $writer->receipt($submissionId); // авторизованный read-only writer lookup после потери ответа
```

Ни request data, ни caller-supplied damage/plan/pid/tape/actor не принимаются. Новый commit требует POST+CSRF, актуального actor, доступа к теме, server-frozen submission в POST_CONFIRMED с подтверждённым `visible=1` и собственной активной turn claim. MODERATION_PENDING, POSTING, RECOVERY_REQUIRED и неподтверждённый pid не допускаются к механике.

Важная существующая граница прав: у начальных/preparation controller assignments сохранён `combat_operations_enabled=false`. Writer **не** превращает это в разрешение. Действующие actors должны иметь актуальное exclusive controller assignment с `schema_version=1` и явным `combat_operations_enabled=true`; member role сама по себе не даёт управление чужим существом. Выдача такого grant и production enablement этой задачей не реализуются. Fixture задаёт grant явно только в одноразовой БД. Будущий coordinator обязан пользоваться проверенным серверным контрактом прав, а не выставлять grant из HTTP или автоматически обходить false.

Receipt lookup доступен только автору с текущим доступом к сессии/теме. Replay завершённой операции не требует pending turn или сохранения прежней активности существа: он возвращает историю без нового Resolver/HP/AP. Скрытие/удаление поста после COMMITTED не переписывает receipt и не откатывает механику автоматически. GM recovery/read policy — следующая задача, а не неявный admin bypass writer.

## Алгоритм и атомарность

1. На входе отклоняется ambient transaction/ACS lock без COMMIT/ROLLBACK чужой работы. Native права и CSRF проверяются до открытия собственной tx. Сначала проверяется terminal receipt; повтор после успешного commit возвращает его без расчёта и записи.
2. Сверяются frozen draft, request hash, текущие active sealed actions, их hash/pin/definition snapshots и исходный tape. Frozen copy сравнивается с живыми immutable sealed records; проза и mechanical/dependency/confirmation payload также должны соответствовать frozen draft. Никакого нового RandomSource.
3. Вне tx существующий Builder/QueryService подготавливает доверенный текущий context/pinned definitions. Снимается консервативный fence собственных ACS dependencies до/после подготовки: session/encounter/round/turn versions, состояния/HP resources, ownership/controllers/roles, participants/sides, snapshots, seals/rolls и clock receipts. Маски и таблицы Appearance в fence не входят.
4. Одна `TransactionManager::run`: общий существующий ACS lock, session row lock, повторный CAS submission и locking reads dependencies. Проверяются текущая роль/владение/контроль/expiry/grant, roster/individual identities и текущий open/pending turn. Внутри нет MyBB/plugin callbacks; используется authenticated request UID и собственные SQL reads.
5. На authoritative writer читаются конкретный pid, uid/tid/fid/visibility, фактические thread/fid/visible/closed и forum open/type. Это проверка ранее доказанного pid, не поиск identity по автору, времени или тексту. MyISAM не получает INSERT/UPDATE от writer и не участвует в ACS rollback.
6. **Один** вызов существующего Resolver в `seal` mode внутри tx, на подготовленном context и прежнем tape. Pure Resolver не вызывает внешние hooks. Точная confirmation-v1 representation совпадает с SealService: пересчитываются dependency/simulation/confirmation hashes; устаревший результат отклоняется, новый roll/автоподтверждение не создаются.
7. В той же tx: COMMITTING audit; HP/life state/HP resource и AP spent; individual start/end receipts и flags; resolved turn/closed_by_submission/deadline cleanup; aggregate combat event с ordered effect traces; mechanical result; post_link; session/encounter versions/sequences; published draft/seal lifecycle statuses; COMMITTED audit; terminal receipt/result_json/generic operation receipt; pending outbox.
8. SQL commit фиксирует всё вместе. При ошибке весь набор откатывается, включая уже записанные terminal rows; последняя достоверная стадия остаётся POST_CONFIRMED. Writer не маскирует ошибку как успех и не переводит её автоматически в REGISTERED. Quarantine/coordinator decisions остаются следующими задачами; существующий unresolved barrier продолжает блокировать зависимые mutations.

Receipt и terminal state читаются одним joined InnoDB SELECT; проверяются request/sealed/receipt/result hashes и связь с post_link/result/event IDs. Повтор с другого соединения после потери ответа использует этот receipt. Если COMMIT/ответ неизвестен, сначала нужен authoritative receipt lookup (при потере соединения — на новом writer connection); нельзя создавать новый submission или повторять MyBB INSERT. Повтор `commit` того же ID также начинает с receipt lookup. Повреждённый/неполный terminal receipt даёт явную ошибку, не второе применение.

COMMITTING и COMMITTED находятся внутри одной ACS tx: при rollback промежуточный COMMITTING не выдаётся за достоверно завершённую отдельную фазу. В успешно зафиксированном publication audit остаются оба перехода. Выделенной второй модели submission или журнала нет.

## Поддерживаемая механика и блокировки

Поддержан текущий исполняемый scope Seal/Builder/QueryService: последовательные direct attacks, положительные AP costs, HP loss/overkill/incapacitated и синхронный HP-resource projection. Несколько actors одной control group используют общий AP. Детерминированные числовые правила задаёт неизменённый Resolver; writer не придумывает стоимость, урон, crit, fallback stats или AP refund.

Текущий preparer не поддерживает дополнительные resource costs, active effects/barriers, cooldown abilities, reflected/lifesteal definitions и Inventory costs. Такие данные отклоняются; writer дополнительно проверяет, что нет непредставленных resource/effect/group changes. Наличие columns/resources не объявляет их готовыми. HP-resource и AP полностью транзакционны в поддержанном scope.

Individual start/end используют существующие clock operation keys. Уже начатый ход требует committed start receipt. Для ещё не начатого неактивного участника группы допустим только доказанный empty-layer/no-cooldown start без эффектов, затем end; иначе операция блокируется. Новые тики/cooldown effects не выдумываются. Encounter auto-completion, победа/награды, переход в следующий раунд и расширенные end effects не добавляются: разрешается текущий group turn, дальнейший lifecycle остаётся существующим сервисам/последующим задачам.

Необходимая адаптация `assertNoObligations`: read-only sealed calculation может игнорировать **только собственный frozen submission с действующей turn claim**; другие pending operations по-прежнему блокируют. COMMITTED признаётся terminal наряду с историческим published, чтобы закрытый ход не оставался вечным blocker. Это не разрешает механические команды в обход общего session barrier.

Native MyBB permissions проверяются перед ACS tx, физическое состояние известного post/thread/forum перечитывается в tx; владение и права ACS перечитываются и блокируются внутри неё. MyISAM остаётся независимо изменяемым: concurrent delete/edit после проверки не становится общей SQL-атомарностью. Последующее reconciliation и installed hooks — задачи Recovery/moderation, не основание заявлять глобальный rollback.

## Проверки

Локальная среда: PHP **8.5.12**, **MariaDB 11.8.8**, disposable Unix socket. Production не использовалась; MySQL 8.0.46 и installed MyBB этой поставкой **не проверены**. В fixture известный native post заранее создан в собственной `posts ENGINE=MyISAM`; Gateway вообще не загружается.

```sh
php tests/acs_combat_commit_regression.php /tmp/acs222-socket/r2.sock
```

Проверены:

- Upgrade 001–008→009, interrupted DDL resume/repeat; прежние migration records/checksums не меняются.
- Точные Resolver HP/life/AP projections и synced HP resource, закрытие хода, один post_link/result/receipt/outbox.
- Ошибка на outbox после всех механических/terminal writes: полный rollback таблиц/версий/clock receipts/audit, затем безопасный повтор на том же tape.
- Duplicate submission, stale journal version, независимый receipt lookup и повтор после потерянного ответа без повторных HP/AP/outbox.
- Изменение dependency после seal и в preparation→transaction gap; stale result не применяется.
- Два actual writer connections: конкурирующий commit не получает ACS lock, после winner commit возвращает тот же receipt; альтернативный результат того же хода запрещён UNIQUE.
- MODERATION_PENDING, неверные uid/tid/fid/visible, preparation-only controller, повреждённый tape, чужой actor/CSRF и ambient tx отклоняются без частичной механики.
- Sealed payloads (кроме lifecycle status), snapshots, tape и RNG draw count неизменны; cosmetics не меняют confirmation; MyBB prose/engines и внешние owner data не меняются.

Также `php -l`, `git diff --check` и статическая проверка сохранения миграций 001–008 и защищённых компонентов. Полный suite не запускался.

Следующая задача **ACS-4.3.2-R2 / PublishService не начата**. Ей остаются владение одним native request lifecycle, выпуск/повтор submission ID, согласование Gateway outcomes и writer, безопасный карантин и дальнейшая installed приёмка. Outbox сейчас только durable storage: consumers должны получить подтверждённые idempotent owner APIs отдельно. Включение публикации требует решения администратора после всей installed матрицы; оно не является следствием этого PR.
