# ACS-10.3.1 — IC-BALANCE: Idempotent Reward Credit Gateway

База: `main` 35678f49, merge ACS-10.2.3 (#1070). Требования A1.37–A1.39.
Изучены Master Specification, Technical Architecture (§5.5), Implementation
Roadmap, Integration Contracts (§14), ACS-10.2.3, ACS-6.3.2 и действующий Balance.
`docs/ROADMAP.md` не изменён. ACS-10.3.2 не реализован.

## Фактический owner contract

`balance/modules/RewardCredit.php`, загружается самим Balance:

```php
$owner = new AF_BALANCE_RewardCredit($authoritativeMysqli, $trustedRewardAuthority);
$owner->credit($operation_key, $uid, $kind, $amount_minor, $source_ref, $expected_version);
$owner->lookup($operation_key);
```

Это внутренний standalone API, без HTTP route, hook регистрации issuer, GM form
или player credit endpoint. Вызов без authority запрещён. Только `exp` и
`credits`, положительные **PHP int** minor amounts. Float/string amount отвергается,
не округляется. Все существующие scales равны 100: 123 minor = 1.23 EXP/credits.
`af_balance_add` по-прежнему принимает major, floor(amount × 100); новый API его
не вызывает. Ability tokens существуют в native Balance и post rewards, но новый
reward grant для них не разрешён: отдельный ACS authority contract пока отсутствует.

Возвращается immutable committed receipt: исходный intent, payload_hash,
balance_before/after, owner_version, tx_id, authority_hash, result_hash.
Повтор с тем же intent возвращает прежний receipt до проверки текущей версии.
Одинаковый key с другим intent — `BALANCE_INTENT_CONFLICT`. UNIQUE source_hash
запрещает вторую выдачу источника даже с новым key/recipient. `source_ref` должен
быть **постоянной identity конкретной награды/получателя/типа**, созданной reward
owner, а не ключом запроса. Это позволяет отдельные each/split allocations,
не создавая их в этом PR.

`expected_version` относится ко всей постоянной balance row, не отдельной валюте.
Начальная версия — 1, успешный native delta/credit повышает её. Будущий серверный
coordinator читает её на authoritative writer (`af_balance.owner_version`),
закрепляет в durable intent и не подменяет при retry. Stale version отклоняется
без delta/receipt. Изменение intent требует отдельного доказанно безопасного решения
coordinator, а не автоматического refresh-and-retry.

## Авторизация и граница следующего этапа

`AF_BALANCE_RewardAuthority::authorize($exactIntent)` — code-only trust boundary.
Provider обязан читать с authoritative writer подтверждённую неизменяемую награду,
её confirmed source/outcome, exact recipient/kind/minor amount и действующие issuer
permissions; historical lookup также требует разрешения. Ответ содержит ровно
scope=`acs.reward`, source_ref, uid, kind, amount_minor, authority_ref,
confirmation_ref, issuer_ref. Полный stable authority scope сохраняется в owner
ledger, его hash входит в payload и receipt. Авторизация повторяется под balance
lock; scope/recipient/amount не могут измениться между preflight и delta.

**Production ACS reward provider в этом этапе не зарегистрирован.** Нет ещё
подтверждённого непредметного reward ledger/recipient allocation этапа 10.3.2;
создавать его фиктивно или считать GM/account ownership mint authority нельзя.
Новый owner API реализован и проверен с durable SQL test provider, но обычная ACS
сессия сейчас не получает право начисления. При подключении следующего этапа
provider должен реализовать именно этот строгий контракт; запрещено подставлять
request fields, `validated=true`, произвольный callback или один GM permission.

## Транзакция и recovery

Один mysqli writer и одна InnoDB transaction: UNIQUE operation/source claim →
постоянная balance row FOR UPDATE → повтор authority → version check → exact scaled
UPDATE → **один существующий `af_balance_tx` INSERT** → immutable receipt → COMMIT.
Нового accounting transaction table нет. Для gateway запись tx обязательна,
независимо от optional legacy log setting; legacy trim policy не изменена, owner
receipt хранится отдельно и не зависит от сохранности старых optional tx logs.

Ambient transaction/autocommit=0 запрещены до BEGIN: `SET TRANSACTION` используется
как MySQL guard и не коммитит чужую transaction. Общая ACS/MyBB/Balance transaction
не заявлена. Внешний coordinator обязан сохранить intent **до** owner вызова.
Ошибка/потеря ACK в COMMIT возвращает `recovery_required` с прежним key/hash;
последующее действие — lookup того же key. Новый credit/key при неизвестном исходе
не допускается. Lookup not_found — только отсутствие доказанного receipt, не
разрешение blind retry. Ошибка до COMMIT откатывает claim, delta, version и tx;
неподтверждённый rollback также возвращает recovery_required.

Receipt lookup проверяет JSON hash, связь с сохранённым intent/authority, UID,
kind, source, minor amount, expected/after version и арифметику balance. Внешний
клиент не передаёт готовый owner receipt. Нет автоматической коррекции/изъятия
награды, изменения snapshots, CharacterSheets build_json или CS EXP callback
для нового reward credit.

## Installed schema prerequisites и upgrade

До upgrade новый API не READY. Readiness **ничего не создаёт**. Требуются:

- MySQL/mysqli, 64-bit PHP, authoritative `write_link`, utf8mb4, strict SQL mode;
- native `af_balance` и `af_balance_tx` с проверенными типами, InnoDB и PK;
- завершённая legacy credits scale migration: persisted setting
  `af_balance_credits_scale_migrated=1`;
- owner column `af_balance.owner_version BIGINT UNSIGNED NOT NULL DEFAULT 1`;
- owner table `af_balance_external_operations`: exact operation key (ascii_bin PK),
  payload/source hashes, UNIQUE source_once(source_hash), UID/kind/scaled amount,
  expected_version, source_ref, authority_json, status, result_json/hash, updated_at.

**Контролируемый operator upgrade:** остановить все Balance writers/старые PHP
workers, сделать backup, завершить native schema/scale и CharacterSheets import,
развернуть новый код на всех workers, затем из доверенного MyBB CLI bootstrap вызвать:

```php
(new AF_BALANCE_RewardCredit($db->write_link))->upgrade();
(new AF_BALANCE_RewardCredit($db->write_link))->assertStorage();
```

Upgrade допускается только в CLI и без ambient transaction. Добавляет только
отсутствующие owner column/table, resumable при прерванном DDL; не очищает версии,
receipts или balances. Не конвертирует MyISAM, не исправляет несовместимую существующую
таблицу молча. При partial schema native owner writers тоже fail closed.
Не вызывается install/activate, GET/POST, post hooks или runtime readiness.
Это Balance owner upgrade, не новая ACS migration: ledger ACS остаётся
025_loot_claims. Production installed schema и production upgrade не проверялись
и не выполнялись; безопасность действительна только после проверки prerequisites.

## Совместимость native writers

До owner upgrade остаётся прежний native read/modify/write path. После upgrade
`af_balance_apply_scaled_delta` и ручная корректировка используют атомарный SQL
relative delta с owner_version, поэтому не затирают concurrent reward. Старые
floor/round, negative-award settings, clamp, optional tx logging и EXP callbacks
сохранены. Native helper не делает BEGIN/COMMIT: существующая Shop transaction
может держать ту же row lock и откатить свою покупку целиком. Нулевая balance row
создаётся idempotent INSERT, без duplicate UID ошибки при конкуренции.

Credits scale migration после owner upgrade блокируется, если legacy migrated flag
не установлен. Legacy CharacterSheets import после upgrade не повторяется; он должен
завершиться до включения owner protocol. Нет второго постового хука/начисления.
Out-of-band SQL и старые воркеры не являются поддержанными writers; maintenance
upgrade и единая версия кода обязательны. Обычные post recalc/hash/edit semantics
не заменены новой reward системой.

Close/archive ACS не удаляют этот Balance-owned ledger. Сам owner не требует
боевого active state и не возобновляет encounter; разрешение historical source
после close/archive должен подтвердить trusted reward provider. В этом PR нет
новых ACS reward obligations, consumer, session lifecycle изменения или loot issue.

## Проверки

PHP 8.4.27, MySQL 8.0.46, 64-bit mysqli/mysqlnd + mbstring, isolated disposable
SQL database и Unix socket, DROP DATABASE в finally. Production/MyBB lifecycle,
PHP 8.5.12, реальный ACS reward provider следующего этапа — NOT_VERIFIED.
Полный test suite не запускался.

```text
php -l inc/plugins/advancedfunctionality/addons/balance/balance.php
php -l inc/plugins/advancedfunctionality/addons/balance/modules/RewardCredit.php
php -l tests/acs_balance_credit_sql.php
php tests/acs_balance_credit_sql.php /tmp/acs1031-socket/mysql.sock
git diff --check
```

PASS: exact EXP/credits; same-key receipt replay/lookup; changed intent; wrong UID,
amount, kind, float; default issuer deny; unconfirmed/unauthorized source; same
provenance/new key; stale version; missing schema; MyISAM tx; missing source UNIQUE; partial upgrade blocks native writes; unsafe SQL mode;
revoked lookup authority; resumable upgrade; rollback after balance update; actual COMMIT then lost ACK;
lookup after unknown COMMIT; ambient transaction preservation; real SQL concurrent
same/different-key credits; native writer race; one accounting tx per receipt;
receipt corruption; legacy major floor; ordinary post/hash/edit; native clamp;
native writer participation/rollback in an outer Shop-style transaction; no CS EXP
callback for reward; upgrade preserves history.
