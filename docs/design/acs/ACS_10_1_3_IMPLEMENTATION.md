# ACS-10.1.3 — Session Close, Reopen, Archive и Outstanding Obligations

## Статус и границы

Реализованы A1.39–A1.40 поверх завершённых ACS-10.1.1–10.1.2 на `main`.
Объём — lifecycle сессии, сохранение обязательств и отдельный recovery scope.
Миграция не нужна: существующее поле `sessions.status` допускает новые значения.
Новые таблицы, второй журнал команд, Resolver и система раундов не добавлены.

Прочитаны ACS Master Specification, Technical Architecture, Implementation
Roadmap, Integration Contracts, KB Mechanics Contract и отчёты ACS-10.1.1,
ACS-10.1.2, ACS-6.3.2, ACS-9.3.4.

`docs/ROADMAP.md` не изменён. ACS-10.2.1 не начат. Loot, EXP, currency,
reward outcomes, loot pools и outbox consumer не создаются.

## Команды и переходы

Используются существующие SessionService, SessionRepository::command,
OperationStore receipts, events/event_details и session_version CAS.
Команды доступны через существующий AF session API и формы сессии.

| Команда | Серверное исходное состояние | Результат |
| --- | --- | --- |
| request_close | active | pending_close |
| close | pending_close | closed |
| reopen_session | closed | active |
| archive | closed | archived |

`reopen_session` нормализуется в `reopen`: audit kind `session.reopen`, одна
идентичность команды и receipt, в том числе при повторе через alias.
Прямые active→closed, active→archived и archived→active запрещены.
Из pending_close нельзя начать обычную игру или изменить состав сессии.

Каждая команда требует POST, MyBB CSRF, текущего authenticated actor,
operation_key, непустой reason, expected_version, native thread access и
актуальную сохранённую роль GM либо owner с совпадающим owner_uid.
Глобальный ACP administrator без соответствующих session permissions не
получает lifecycle-права. Client status не участвует в переходе.

Существующий command boundary повторно проверяет права до возврата старого
receipt, сравнивает payload hash/kind и возвращает прежний результат до
новой проверки версии. Другая нагрузка под тем же ключом запрещена.
Переход статуса проверяет исходный status и version под session lock;
событие, receipt, итоговый session CAS и status входят в одну транзакцию.
Ошибка позднего audit insert откатывает весь переход. Потерянный ACK
восстанавливается прежним ключом, без второго события.

## Проверка обязательств

SessionObligationsQuery читает текущие факты с authoritative connection.
Перед request_close, close и archive проверка выполняется внутри command
transaction под тем же session lock. Read-only summary доступен GM в явном
session GET/странице и InventoryObligationsQuery. Внутренние обычные view
не запускают обход обязательств.

| Класс | Примеры и политика |
| --- | --- |
| confirmed_completed | Серверные completed encounters и returned custody; история агрегируется по числу записей |
| pending | Held custody, зарегистрированные pending Inventory operations; сохраняются с отдельным recovery scope |
| recovery_required | Неизвестный Inventory ACK, terminal prepared cost release; сохраняются без объявления исполнения |
| explicitly_blocked | Работающий/paused/ambush/pending_resolution encounter, unresolved publication, sealed action, armed prepared execution, active paid preparation, неизвестная nonterminal operation, повреждённые проверяемые данные |

Explicitly blocked запрещает request_close/close/archive с
`ACS_SESSION_OBLIGATIONS_BLOCKED`. Работающую механику нужно разрешить,
а активные paid preparations — явно отменить, пока сессия active. Это
предотвращает замораживание механики без доступного способа завершить её.
Preparation encounter сам по себе не запускается и не блокирует закрытие.

Held custody проверяется существующим payload contract. Для terminal
prepared recovery проверяются status и contract hash; для pending
Inventory — ledger payload hash, исходные key/ref/scope и owner payload
hash. Проверка не объявляет отсутствующий owner ACK успешным. Terminal
Inventory receipts по-прежнему проверяет существующий Inventory recovery.

Unresolved источники читаются с пределом 200 записей на источник; превышение
даёт explicitly_blocked/scan_limit, а не пропуск остатка. Завершённая история
считается отдельно и не создаёт барьер из-за количества старых записей.
Подробные owner reservations остаются в существующей paginated Inventory
панели; lifecycle не читает и не изменяет приватные owner-таблицы напрямую.

Безопасная точка расширения — AF_ACS_SessionObligationSource и code-owned
additionalSources()/SessionRepository::obligationsQuery(). Источник будущей
подсистемы читает её durable ledger на том же writer, возвращает только
четыре разрешённых класса с source/reference и объявляет отдельный реально
реализованный recovery capability, доступный также в archive. Pending или
recovery_required без такого scope переводится в explicitly_blocked.
HTTP/config_json не регистрируют источники или capabilities. Неизвестные
nonterminal operations существующего общего ledger остаются blocked:
будущая подсистема должна явно интегрировать их классификацию, а не
объявлять результат награды по закрытию сессии. Текущих reward источников нет.

## Recovery, reopen и archive

Lifecycle изменяет только sessions.status, версию/sequence и собственный
append-only audit/receipt. Ни одна ветка не удаляет или не финализирует
Inventory operations, custody, reservations, prepared costs, snapshots,
sealed history, события либо terminal receipts. Cascade/delete отсутствуют.

Сохраняются отдельные существующие пути:

- `inventory.lookup`: исходный actor сверяет прежний owner operation_key;
  not_found/unavailable остаются pending/recovery, повторной отправки нет.
- `custody.return`: проверяемый GM возврат доступности владельцу после
  close/archive; reserve, assign и temporary_use остаются active-only.
- `prepared.release`: owner восстанавливает release terminal unused reserve
  после close/archive. Native read scope не требует возможности публиковать
  новый пост. Сохраняются original owner, membership/controller и independent
  inventory_spending_enabled gates; consume/reserve не разрешаются.

Publication recovery после закрытия остаётся инспекцией/terminal lookup:
resume_known_post не предлагается и запрещён также в locked persistence
command. Обычный recover не пытается выполнить механический commit в archive.

Reopen сохраняет session/entity/encounter IDs, completed outcome identity,
прошлые encounters, HP/AP/resources, cooldowns, seal quota, reaction state,
потраченные предметы, completed rewards и журнал. Изменения encounter rows,
создания round_turn, нового outcome или выдачи наград в reopen нет.
Outstanding obligations после reopen остаются видимыми.

Archived не переоткрывается. GM получает отдельный can_audit для чтения
Audience/history, при этом can_manage остаётся false. История и recovery
по-прежнему требуют native и session permissions. На странице сессии есть
ссылка на существующее восстановление обязательств и сводка их состояний.

## Проверки

Disposable MySQL 8.0.46, PHP 8.4.27, 64-bit mysqli/mysqlnd, mbstring.
PHP 8.5.12 отдельно **NOT_VERIFIED**. Production DB не использовалась.
Полный test suite не запускался.

```text
php -l <все изменённые и новые PHP-файлы>
git diff --check
php tests/acs_session_lifecycle_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_session_prepared_recovery_sql.php /tmp/acs222-socket/mysql8.sock closed
php tests/acs_session_prepared_recovery_sql.php /tmp/acs222-socket/mysql8.sock archived
php tests/acs_inventory_recovery_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_custody_sql.php /tmp/acs222-socket/mysql8.sock
```

PASS: active→pending_close→closed, reopen_session, archive/no reopen,
POST/CSRF/native/session permissions, stale version, поздний rollback,
конкурирующий SQL writer, lost ACK и duplicate receipt без нового audit.
Основной fixture завершает реальный бой через Resolver и GM confirmation,
затем сохраняет completed outcome и механические таблицы неизменными.
Проверены pending custody, реальный потерянный owner transfer ACK,
lookup после close и archive, custody return из archive, запрет нового
reserve в archive и сохранение прав GM на чтение.

Prepared fixture использует настоящие reserve/on_prepare costs и сбой
release, затем проверяет recovery terminal reserve отдельно после close
и archive: без возврата оплаченного расхода, refill или перезаписи sealed
history. Его preparation lifecycle state задан SQL fixture; завершённый
encounter проверяется отдельно основным боевым fixture.

Проверены fail-closed future source без recovery capability и настоящие
повторные deactivate/activate на заполненной archived-сессии со снимком всех
ACS/Inventory таблиц. Старый Inventory recovery fixture адаптирован к
двухшаговому закрытию; его owner-only encounter задан как preparation.

## Эксплуатационная граница

Клиент старого прямого `close(active)` должен сначала вызвать request_close
и использовать полученную session_version. Recovery всегда использует
исходные operation identities и собственные права подсистемы. Архивирование
не доказывает исполнение обязательства и не заменяет будущие 10.2–10.3.
