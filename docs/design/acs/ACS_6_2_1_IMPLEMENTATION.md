# ACS-6.2.1 — Equipment snapshot, pagination, source provenance

База: main `73006e8c` (ACS-6.1.1–6.1.3 завершены). Один Backend PR; `docs/ROADMAP.md` и миграции 001–012 не изменяются. Источники: Master Specification, Technical Architecture, ACS Implementation Roadmap, Integration Contracts, Inventory/Shop/CharacterSheets audit, ACS-2.1.5, ACS-2.1.6 и ACS-6.1.3 implementation reports.

## Реализовано

- `advancedinventory/modules/InventoryRead.php`: серверный owner-authorized полный read через фактический `af_inv_get_items()` с **perPage=100**. Проверяются total, номер/размер страницы, точное число строк, уникальный возрастающий ID, UID, положительное количество и owner inventory version до/после чтения. Нет ограничения первыми 500 предметами. Пропуск/дубликат страницы — ошибка; при изменении версии допустим максимум один полный повтор без explicit expected version, иначе отказ. Отсутствие owner schema/API/прав — безопасный отказ.
- `advancedinventory/advancedinventory.php::af_inv_get_items()`: отдельная внутренняя стратегия `_snapshot_current` использует writer connection, текущие locking reads и стабильный `ORDER BY id ASC`. Обычные фильтры/UI не изменены. Этот низкоуровневый API сам по себе не является authorization boundary; права проверяет `InventoryRead`.
- `EquipmentAdapter::prepare()`: переносит только постоянные equipped/support bindings, а не все имеющиеся предметы. Проверяет owner, slot allowlist фактического Inventory, consumable support policy, видимый единственный KB arpg_item, pinned definition, instance/binding fingerprints и согласованность CS equipment provenance. Непривязанные предметы не дают бонусов и не сохраняются в ACS.
- Для выбранного предмета фиксируются inventory ID/owner, слот, наблюдаемое owned_qty, inventory version, KB ref с version/fingerprint, механическое определение, source provenance, baseline inclusion, reservation bindings текущей ACS-сессии, общий наблюдаемый reserved qty и `snapshot_identity`. Это неизменяемое свидетельство, а не второй баланс имущества. Привязка резерва не даёт права consume и не заменяет проверку durable owner receipt в будущей операции.
- `Domain/Equipment.php::baseline()` проверяет хеши, уникальность предметов/слотов, полное покрытие CS equipment и traces. Возвращает исходные итоговые `characteristics` без повторной арифметики. Сохраняются отдельно `base_characteristics`, зарегистрированные totals и уже включённые traces. Неизвестные provenance/KB, несогласованные версии и неподдерживаемые temporary/disabled layers блокируются.
- `EntityService::prepare()` при явном character preview проверяет полный источник, но возвращает лишь готовность/диагностику. В registration command получает owner revision fence, читает полный Inventory, строит equipment bundle и сохраняет его рядом с исходным неизменённым CS DTO. Проверяется совпадение native writer connection Repository/Inventory/MyBB. Никаких reserve/consume из preview, seal или регистрации нет.
- `EntityRepository::register()/initializeEquipment()`: внутри существующей ACS command transaction сохраняет отдельный immutable item snapshot на каждый слот и initial projection `af_acs_equipment`. Проверяет owner и expected entity version, выполняет CAS 1→2. Инициализация закрыта private API; замена комплекта клиентским payload не предоставляется. Повтор operation key возвращает committed result до чтения живых источников. Duplicate entity и повторная инициализация отклоняются.
- `013_equipment.php`: следующий свободный номер после подтверждённого `012_barrier_pools`; таблица соответствует Technical Architecture (unique session/entity/slot, index item/status, snapshot reference, equipment_version, source_json). Registry и Repository writable ledger обновлены. Миграция выполняется существующим явным runner, не на чтении/preview.

## Проверенные существующие границы

`CharacterSheetAdapter::createSnapshot()` использует owner-issued CS fingerprint; его дополнительные adapter/capability diagnostics не подменяют owner hash. `SnapshotStore`/`Repository::insertSnapshot()` уже сохраняют immutable payload, arithmetic/engine identifiers и content hash; новая экипировка использует этот же механизм. `Modifiers` и `Resolver` не изменены: CS totals остаются baseline, доказанные включённые equipment modifiers повторно не накладываются.

`af_inv_get_equipped()` возвращает binding map без версии и transaction fence. `af_advinv_export_charactersheet_equipment_state()` — UI export с историческим `per_page=500`, который не соответствует `perPage`; он **не используется** новым ACS snapshot protocol и не объявляется полным/безопасным snapshot. Для ACS берутся все страницы через owner reader, equipped/support rows — current reads на той же writer connection.

## Lock order и постоянство

Существующая ACS boundary берёт session lock; затем registration fence материализует при необходимости **только owner revision metadata** и удерживает общий Inventory owner guard до завершения ACS transaction. После него читаются items по ID, equipped/support bindings и reservations. Все owner writers того же UID проходят общий guard и не могут изменить данные до завершения регистрации. Preview вне write transaction использует version-before/version-after и ограниченный retry. `fence()` отвергает вызов вне существующей транзакции, не начинает/не коммитит чужую транзакцию.

Нет общего MyBB+ACS owner mutation protocol: предметы/количество/постоянные bindings не записываются из ACS. Обновление owner guard metadata на регистрации не меняет inventory version или собственность. Разные сессии видят единые owner reservations; snapshot не создаёт резерва и не разрешает списание.

AA assignments, маски и декоративные Shop slots не читаются. Косметический источник не принимается как механический предмет; AP за маски не появляется. Повторный migration run/активация не пересобирает сохранённую экипировку.

## Проверки

Только disposable MySQL 8.0.46/InnoDB, изолированный Docker network namespace; PHP 8.4.27. Пользовательские/production данные не использовались.

- `tests/acs_equipment_snapshot_sql.php`: 605 предметов с одинаковыми timestamps; корректный perPage и полный набор, отсутствие raw items в стандартной JSON serialization; missing page; version drift другим DB handle; explicit version conflict; fence вне/внутри transaction; native migration/schema/index validation и repeat ledger; item snapshots, committed registration retry, CAS stale version rejection и сохранение equipment после повторной миграции.
- `tests/acs_equipment_provenance.php`: реальный owner CS snapshot (включая существующий focused read-only fixture), accuracy 20→25 ровно один раз, pinned KB ref, source/duplicate record rejection, unknown KB rejection, маска не меняет bundle.
- `tests/acs_modifiers_regression.php`: существующий focused modifier/provenance regression.
- `php -l` всех изменённых/новых PHP; `git diff --check`.

## Запланировано / BLOCKED

Смена активного комплекта, временное использование, отключение/дельты предметов, custody и combat commit integration не реализованы. Для них нельзя угадывать происхождение бонусов или использовать observed owned_qty вместо owner balance. Начальный snapshot не является обещанием будущей доступности; future action обязана заново подтвердить owner reservation/receipt и CAS.

Боевой расход остаётся **BLOCKED**: adapter composition/delegated spending и recovery должны быть подключены отдельной задачей. Нет fallback к `af_inv_remove_item()`, прямых ACS записей Inventory или автоматического разрешения spend. К ACS-6.2.2 этот PR не переходит.

Сущности, зарегистрированные до migration 013, автоматически не backfill-ятся: старый snapshot нельзя объявлять доказанным новым equipment bundle. Будущий расчёт, которому нужен такой bundle, должен блокироваться до отдельной подтверждённой подготовки.
