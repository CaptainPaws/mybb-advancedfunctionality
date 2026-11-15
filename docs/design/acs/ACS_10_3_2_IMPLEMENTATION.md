# ACS-10.3.2 — Reward Distribution, EXP/Currency и Permanent Sync

База — `main` c2e93617 (merged ACS-10.3.1, #1071). Требования A1.37–A1.39.
Прочитаны ACS Master Specification, Technical Architecture, Implementation Roadmap,
Integration Contracts, KB Mechanics Contract, отчёты ACS-10.1.1, ACS-10.2.1,
ACS-10.3.1, ACS-6.3.2 и CS/KB integration contracts. `docs/ROADMAP.md` не изменён.
ACS-10.3.3 не начат. Полный test suite не запускался.

## Реализованный профиль

Новый RewardService использует EncounterRepository, существующие command receipts,
events, SnapshotStore boundary, OperationStore и RecoveryService. Loot Domain/Rewards
сохранён; небольшая Domain/RewardDistribution отвечает за непредметные allocations
и проверку Balance receipt. Нет второго Balance ledger, Resolver, outbox consumer,
постового reward hook, Inventory write или общего MyBB/ACS/Balance COMMIT.

Поддержаны `experience → exp` и `currency → credits` через действующий
AF_BALANCE_RewardCredit. Канонический положительный minor amount — integer,
owner scale=100; major float, повторное умножение и округление не используются.
Другие currencies/kinds не получают неявного owner contract.

Правило `ic-acs.reward-source.v1` читается существующим KB adapter из явного
arpg_mechanics/combat_template; definition/version/fingerprint закрепляются до
старта в snapshot и encounter config. Полный исполняемый subset описан в
[KB Mechanics Contract](../../contracts/ACS_KB_MECHANICS_CONTRACT.md#acs-1032--opt-in-non-item-reward-source-v1).
Нет ad-hoc GM суммы, HP/Luck fallback или начисления по непроверенному event ID.
Поддержан источник confirmed encounter outcome с точным terminal event; произвольные
contribution/другие event branches этим профилем не выдаются.

## Схема и identities

Фактический ACS ledger до PR — 025_loot_claims, `af_acs_rewards` отсутствовала.
Новая следующая resumable migration **026_rewards** создаёт только эту таблицу,
по архитектурной форме с payload_hash. Исторические migrations/checksums не менялись.
UNIQUE `(reward_key,recipient_key,reward_type)` и UNIQUE issuing `operation_key`.
Нет FK cascade/delete, Balance DDL или повторной схемы af_balance_tx.

reward_key = hash(session, encounter, confirmed outcome, pinned KB source key).
recipient_key = `group:<id>` либо явно разрешённый `entity:<id>`.
Owner operation key закреплён за reward/recipient/type; source_ref=`acs-reward:<id>`.
Exact payload содержит source terminal event/outcome, KB snapshot/hash, eligibility
hash, mode, frozen recipient UID/group/entity IDs и точный minor amount.
Этот payload/hash не изменяется после создания, включая retry/reopen/owner changes.

Distribution command/event receipt сохраняет весь eligible set, allocations и IDs
обязательств. Пустой результат также имеет receipt. Повтор той же команды либо
источника с новым ключом возвращает исходный plan до CAS; изменение manual allocation
после подтверждения запрещено. Повтор GM confirmation не вызывает распределение
или начисление снова.

## Получатели и арифметика

EndConditionPersistence использует прежний frozen eligibility helper также для
encounters с reward_sources без loot. Facts сохраняются после полного mechanical
resolution, до установленного завершения и GM confirmation. Выдача проверяет
confirmed outcome receipt, config/KB pins, candidate hash и immutable command audit;
текущий roster/owner/controller не служит новым списком получателей.

- each: pinned amount каждому eligible recipient;
- split_equal: точный integer quotient/remainder, explicit KB policy
  recipient_key_order, без потерянных minor units и скрытого округления;
- manual: положительные exact amounts только eligible keys, UNIQUE keys,
  сумма равна pinned total; GM не выбирает произвольный UID.

Base control_group не умножается на питомцев/призывы, damage/action не требуется.
Entity reward допускается лишь при явных KB subject/entity_kinds; payout идёт
зафиксированному group owner этой сущности. Unowned сущности не превращаются в
произвольный аккаунт. Life_state, surrender/escape/withdrawal/capture и outcome
проверяются отдельно по pinned eligibility. Late join/смена группы/владельца после
end boundary не переписывают историю.

## Owner issuer, выдача и recovery

Создание durable obligation отделено от выдачи. Для каждого recipient отдельно:

1. При доступной безопасной owner schema сервер читает owner_version, затем
   сохраняет exact envelope в прежний OperationStore и `reward.prepare` receipt.
2. CAS prepared → recovery_required фиксируется **до** внешнего owner credit.
   Только победитель CAS выполняет dispatch; конкурентный request идёт в lookup.
3. AF_ACS_BalanceRewardAuthority заново проверяет outcome/pins/recipient/source,
   retained issuing envelope/prepare audit, включённые addons, authenticated actor,
   native thread access и текущие session GM permissions. Authority scope/hash
   привязаны к сохранённому issuer/source, не произвольным request данным.
4. Credit выполняется в отдельной owner transaction. Receipt проверяется по exact
   intent, authority/payload/result hashes, recipient, minor amount, owner version,
   balance arithmetic и accounting tx identity.
5. Поздняя ACS CAS transaction атомарно сохраняет owner receipt, operation committed,
   reward issued и audit. Ошибка acknowledgement оставляет прежний recovery barrier.

У каждого recipient свой статус/intent: partial success не переигрывает выданные
награды. Если Balance недоступен до prepare, obligation остаётся pending и может
быть выдана после восстановления. После dispatch/неизвестного ACK `recoverReward`
делает **только lookup** прежнего ключа. Unknown/not_found не означает failed или
no-credit, не освобождает obligation и не создаёт новый ключ/версию/получателя.
Даже known owner version rejection после dispatch консервативно сохраняет barrier:
автоматического rebase/recredit нет; отдельный correction/retry protocol не добавлен.

Current permitted session GM может восстановить exact retained intent другого GM;
первоначальная issuer identity и payload остаются прежними. Account ownership,
боевой controller или один GM flag без подтверждённого source не дают mint rights.

## Lifecycle и permanent sync

SessionObligationsQuery распознаёт pending/prepared, recovery_required, issued и
blocked rewards, проверяет исходный proof и предоставляет отдельные
reward.balance_issue / reward.balance_lookup scopes. Пока pinned confirmed source
не распределён, close блокируется. После distribution разрешённый close/archive
сохраняет unresolved obligations; возможны initial issue pending reward и lookup
unknown credit, без возобновления completed encounter или active session.

CS permanent branch — **BLOCKED**. Изучен текущий experience.php:
af_charactersheets_grant_exp всегда false; обычный EXP callback использует JSON
mutators без durable key/version/receipt/lookup. Безопасного permanent sync owner
API не найдено. Pinned KB definition/change удерживается в snapshot и blocked
obligation/plan; amount_minor=1 — marker этой ветки, не валюта/EXP. Не придумываются
owner expected version, успешный outcome или выполненная синхронизация. Blocked
obligation не объявляется выполненной и блокирует close по generic lifecycle policy.

Нет прямого UPDATE build_json, вызова CS EXP callback из reward path, изменения
CharacterSheets/KB или пересчёта активных snapshots других сессий. Отдельный owner
sync adapter допускается только после появления доказанного durable contract.

## Backend API

Существующий AF transport `xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem`:

- reward_status: GM GET session_id, encounter_id, optional after (100 rows/page);
- reward_configure: GM POST session/encounter versions, kb_key, operation_key, reason;
- reward_distribute: те же поля и allocations (JSON list, [] для each/split_equal);
- reward_issue: POST session_id, reward_id, expected_version,
  expected_reward_version, operation_key, reason. UID/amount/source не принимает;
- reward_recover: POST session_id, reward_id, expected_operation_version,
  только retained owner lookup. RecoveryService::inspectReward/recoverReward
  используют тот же сервис, без альтернативного executor.

Все writes требуют существующий POST/CSRF/native-session GM boundary, prepared и
terminal transitions журналируются с CAS. GET не создаёт obligations/owner rows.
Loot UI этапа 11 и автоматический reward consumer сюда не добавлены.

## Проверки и prerequisites

PHP 8.4.27, MySQL 8.0.46, 64-bit mysqli/mysqlnd, disposable databases через Unix
socket и DROP DATABASE в finally. Runtime использует owner prerequisites ACS-10.3.1:
explicit CLI Balance upgrade, InnoDB af_balance/af_balance_tx/external_operations,
owner_version, strict SQL mode/utf8mb4, completed legacy scale migration. ACS
026 и owner Balance upgrade — разные контролируемые upgrades; ordinary requests
не выполняют DDL. Production installed MyBB/owner schema и PHP 8.5.12 NOT_VERIFIED.

```text
php -l <все новые/изменённые PHP-файлы>
php tests/acs_reward_distribution_sql.php /tmp/acs222-socket/mysql8.sock
ACS_REWARD_SYNC_FIXTURE=1 php tests/acs_reward_distribution_sql.php /tmp/acs222-socket/mysql8.sock
git diff --check
```

Fixture продолжает существующий узкий реальный combat → pending → GM confirmation →
loot harness; новые participant/KB seeds явные. Проверяется настоящий IC-BALANCE с
production ACS issuer, не заглушка credit. Throttle timestamps aged между фазами,
production rate limit не изменён.

PASS: each/split_equal с остатком/manual; group+pet dedupe; explicit individual pet;
frozen owner/KB pins; repeated GM confirmation/source/new key; distribution rollback;
GM/POST/CSRF; EXP/currency once; two recipients partial/offline; pending-only retry;
actual owner COMMIT then lost ACK; forged recipient/receipt; ACS ack rollback;
конкурирующий второй SQL request после dispatch CAS; pending и unknown obligations
через настоящий close/archive; generic RecoveryService lookup без повторного credit;
CS blocked без owner write; отсутствие изменений snapshots, sealed rolls, combat
history, HP/AP/resources, cooldowns и Inventory; повтор migration сохраняет выдачи.
