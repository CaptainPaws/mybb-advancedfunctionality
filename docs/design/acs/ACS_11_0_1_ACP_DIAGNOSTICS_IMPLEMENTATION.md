# ACS-11.0.1 — Актуализация диагностики и статусов интеграций ACP

## Объём

Один ограниченный PR от `main` `9d07993f`, AdvancedCombatSystem **0.19.0**. Изменены существующие ACP-представления и read-only диагностические DTO. Механика, права, owner authorization, миграции, игровые данные и installed acceptance не изменены. `docs/ROADMAP.md` не изменён. Следующая задача не начата.

Основания: применимые положения ACS_MASTER_SPECIFICATION (A1.89–A1.94), ACS_TECHNICAL_ARCHITECTURE (R2, lifecycle, безопасность), ACS_IMPLEMENTATION_ROADMAP и ACS_INTEGRATION_CONTRACTS; implementation/acceptance reports 4.5-R2, 6.3.2, 7.3.3, 8.3.3, 9.3.4, 10.4.3. Исторические PROPOSED/pending тексты сверены с текущими реализациями; они не используются как доказательство отсутствия уже добавленных адаптеров. ACS-10.4.3 в текущем main присутствует.

## Обнаруженные устаревшие проверки

1. `Diagnostics::capabilities()` требовал `schema_version === '001_foundation'`, хотя MigrationManager регистрирует `029_statistics`.
2. KB snapshot и CS snapshot помечались не реализованными, независимо от KnowledgeBaseAdapter, CharacterSheetAdapter, RegistrationSource и SnapshotStore.
3. Inventory описывался через общие get/remove APIs вместо OwnerProtocol и действующей same-writer ACS composition.
4. Statistics сохранял `CONFIRMED_EVENT_CONSUMER_PENDING`, несмотря на отдельные completed aggregate/receipt API, StatisticsRepository и archived recovery.
5. Пустые источники инициализировались ошибкой `required_field_missing`. Общий catch/ранний KB dependency return мог подменять результаты всех проверок.
6. KB ошибки собирались без адреса элемента: причина `element_version_invalid` не объясняла, какой versioned reference нарушен.
7. «Основные» выводили полный ledger и загружали все движки для списка версий. Описание утверждало, что атаки ещё не исполняются.
8. PublicationDiagnostics обобщал отсутствие всех consumers, смешивая generic combat delivery с уже реализованными Statistics и отдельными reward issuer.

## core.schema

`Diagnostics::schema()` использует **существующий** `af_advancedcombatsystem_migrations()->status()` и добавляет `required_version` / `ready`. `ready` допустим только при `error === null` и точном совпадении с `AF_ACS_Migrations::VERSION`.

Это не сравнение одного номера: перед возвратом успешного `status()` MigrationManager проверяет наличие журнала, registry/checksums, допустимые состояния и завершённые steps, завершение **каждой** зарегистрированной миграции и фактические таблицы/колонки/индексы/engines/collations. Отсутствующий ledger, running/failed/missing migration, несовпавший checksum или schema drift сохраняют ошибку и блокировку, даже если последняя версия присутствует.

`core.schema` дополнительно требует обнаруженного аддона. Installed schema evidence отражается как `installation_status=VERIFIED`, `verification_level=schema_integrity_readback`. Это подтверждение текущей схемы, **не installed acceptance боевой публикации**. Диагностика не вызывает `run`, install/upgrade/uninstall, DDL или исправление ledger. `Migrations.php` изучен и не изменён: действующий status уже содержит нужные проверки.

## Разделение уровней

Каждый capability отдельно содержит:

- `detected`, `enabled`, owner/version и источник version metadata;
- `implementation_status` и точный поддерживаемый `implementation_scope`;
- `interfaces` и `runtime_status`: `LOADED`, `LAZY_LOADABLE`, `UNAVAILABLE` либо `INTERFACE_MISSING`;
- `data_check`: результат, причина, область и безопасные diagnostics;
- `installation_status` / `installation_scope`, отдельно `installed_acceptance=NOT_VERIFIED`;
- общий ограничивающий статус, причину, уровень проверки и русское действие для оператора.

`IMPLEMENTED` основан на проверенной в source композиции owner/ACS, с наблюдением её конкретных declarations; это не `READY` исполнения. `LAZY_LOADABLE` сообщает о поддерживаемом пути загрузки модуля, не сертифицирует вызов. Диагностика не загружает Inventory/Statistics consumers ради появления класса и не вызывает их операции. Owner installation/schema, кроме ACS MigrationManager, **не проверяются автоматически**: этот уровень честно остаётся NOT_VERIFIED; detected/enabled не подменяют установку или acceptance. Owner lifecycle callbacks не вызываются.

Успешный выбранный источник может иметь `data_check.readiness=READY` только в указанном scope. Общая интеграция остаётся `NOT_VERIFIED`, либо `BLOCKED` при реальной ошибке/отсутствующей зависимости. Успех проверки данных не включает publication и не снимает operation gates. `Diagnostics::gate()` по-прежнему требует READY всех зависимостей и enabled ACS; MyBB publication остаётся BLOCKED.

## Актуальная матрица capabilities

Статус реализации в таблице относится к проверенному текущему source. На установленном warprift.ru эти пути этим PR не исполнялись.

| Capability | Реализация / проверенная композиция | Runtime / данные / installed ограничения |
|---|---|---|
| `core.facilities` | Стандартные шесть AF APIs и ACP router | READY только при прежнем полном наборе callable APIs/router текущего bootstrap; не certification публикации |
| `core.schema` | MigrationManager status/assertInstalled, registry 001–029 | READY только после успешного полного schema readback текущей зарегистрированной версии |
| `kb.definition_reader` | KnowledgeBaseAdapter::readDefinition и owner normalizer | Один выбранный key, права, версия и references; installed KB acceptance NOT_VERIFIED |
| `kb.combat_snapshot` | RegistrationSource::read/definitions и SnapshotStore::append | Immutable registration path IMPLEMENTED; ACP только проверяет выбранное определение, снимок не сохраняет |
| `cs.combat_snapshot` | CharacterSheetAdapter + owner create/prepare combat snapshot | Один выбранный лист, owner identity, read set, equipment provenance; installed NOT_VERIFIED |
| `inv.reserve_consume` | OwnerProtocol reserve/consume/lookup/participate + InventoryAdapter::forCombat + CombatInventoryAccess | IMPLEMENTED для same-writer InnoDB, отдельные spending grants обязательны; schema/writers/routing/authorization installed NOT_VERIFIED |
| `stats.consumer` | StatisticsAdapter, owner combat_statistics publish/receipt, StatisticsRepository consume/receipt | IMPLEMENTED: completed only, version/hash, durable receipts; delivery/archived recovery installed NOT_VERIFIED; диагностика не потребляет outbox |
| `aam.delivery` | Native `af_aam_add_alert` существует; ACS idempotent delivery **NOT_IMPLEMENTED** | **BLOCKED**, optional; нет owner-approved operation key/receipt/lookup, native alert не повторять |
| `theme.components` | ATF register/slots + ACS thread/card providers | IMPLEMENTED; отсутствие frontend регистрации в ACP не делает реализацию отсутствующей; installed rendering/roles/mobile NOT_VERIFIED |
| `editor.combat_form` | EditorPublicationController native/Quick Reply test binding + AE | IMPLEMENTED в test scope; installed form acceptance NOT_VERIFIED, production publication закрыта |
| `mybb.atomic_publish` | R2 MybbPostGateway::publish + PublishService::issue | IMPLEMENTED, **BLOCKED** по IC-MYBB-1 до installed acceptance; ключ исторический, общую MyBB/ACS транзакцию не обещает |
| 13 validation capabilities | Accuracy, 17 elements, direction hierarchy, aliases, explicit damage, provenance, resistance mapping, bestiary/character snapshots, equipment trace, readonly и versioning | Отдельные memory / primary catalog / selected source scopes; отсутствие проверки = NOT_CHECKED, ошибка конкретных данных = BLOCKED |

## Источники и read-only проверки

Открытие вкладки показывает source/declaration evidence; точечные проверки запускаются только явным GET `acs_probe=1` через кнопку. Нет просмотра всех листов или всего каталога KB. Явная проверка охватывает существующие memory contracts, фиксированный каталог **17** основных стихий и не более одного sheet ID / одного KB key.

Пустой sheet ID или KB key возвращает `NOT_CHECKED / SOURCE_NOT_SELECTED` без error diagnostics. Для непустого неправильного ID — адресная `invalid_source_id`; выбранный источник проходит прежний owner permission-checked read-only adapter. Сбои memory/catalog/source больше не заменяют результат всех остальных проверок одним общим `source_read_failed`. При объединении результатов сохраняется уже обнаруженная блокировка: поздний успешный источник не стирает ошибку catalog/memory.

### KB: element_version_invalid

В owner taxonomy `rules.element_revision` становится `definition_version`. Owner validator отклоняет неположительное/нецелое указанное значение; ACS reference validator также отклоняет отсутствующую версию (`null`). Versioned references должны сохранять согласованные version/fingerprint; обычное наличие записи стихии недостаточно.

При наличии `element_version_invalid` canonical catalog показывает именно эту причину. Диагностические строки включают:

- `canonical_key` из фиксированного реестра, в том числе canonical `air` для legacy `wind`;
- `entry_type=arpg_element`;
- `code`, `path`, адрес `arpg_element:<stored-key>.element_revision -> definition_version`;
- `actual_type`, безопасный `observed_revision` (целое значение либо отсутствие/нецелый тип), ожидаемую положительную целую версию и согласованный fingerprint;
- рекомендацию согласовать исправление конкретной записи/references с владельцем KB, без массовой замены версий/fingerprints.

Перед выводом ошибки версии получают приоритет; вывод ограничен 40 диагностическими строками на capability. Печатаются только разрешённые scalar metadata, с HTML escaping; raw JSON/rules/snapshots/exception text отсутствуют. Изменение adapter касается обогащения diagnostics в fixed primary catalog, не условий исполнения или owner data. Конкретный повреждённый ключ production не угадывается: его покажет явный ACP probe на установленной среде. Production KB здесь не читалась и не исправлялась.

## ACP и публикация

- **Основные:** версия 0.19.0/enabled, фактическая/требуемая схема, сводка completed ledger, блокирующая ошибка схемы, публикация BLOCKED / false, ссылки на диагностику и совместимость. Полного ledger здесь нет.
- **Диагностика:** прежний подробный ledger, последние безопасные ошибки, версии движков (перенесены с «Основных»), EffectsDiagnostics и явные read-only probes.
- **Интеграции:** отдельные колонки реализации, owner/установки, runtime, данных и installed acceptance/причины/действия. Старые английские рекомендации о необходимости реализовать уже завершённые этапы удалены.
- **Права:** прежняя readonly matrix; RESERVED_DENY и серверные команды не изменены.
- **Публикация:** различены generic combat.committed delivery, существующий IC-STATS consumer, отдельные reward issuer и неподдержанные permanent CS sync/compensation. Отсутствие AAM delivery остаётся BLOCKED. IC-MYBB-1 и acceptance checklist сохранены.

В manifest обновлены описание и notice RU/EN: механические подсистемы реализованы в поддерживаемом объёме, функциональный UI развивается, installed acceptance не подтверждена, publication capability отключена. Версия не повышалась. Используется прежняя синхронизация языков AF без принудительной перезаписи пользовательских overrides.

## Изменённые файлы

Относительно корня ACS:

1. `admin.php` — краткая сводка, diagnostic versions, отдельные evidence levels, explicit probes и безопасные поля ошибок.
2. `manifest.php` — актуальные описания и подписи RU/EN.
3. `modules/Administration/Diagnostics.php` — MigrationManager readiness, фактическая матрица интеграций, lazy/runtime/data/acceptance evidence.
4. `modules/Administration/IntegrationCapabilities.php` — NOT_CHECKED, независимые bounded probes, локальные ошибки и сохранение предыдущих блокировок.
5. `modules/Administration/PublicationDiagnostics.php` — актуальные сведения о Statistics/reward consumers без изменения publication gate.
6. `modules/Infrastructure/Adapters/CapabilityValidator.php` — additive `notChecked()` DTO; прежние validation rules не менялись.
7. `modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php` — адресные metadata ошибок fixed element catalog.
8. Этот отчёт.

`Migrations.php` проверен, не изменён. Owner addons, migrations/DDL/checksums, AuthorizationService/RESERVED_DENY, PublicationTestGate, публикационные и механические сервисы не изменены.

## Разрешённые статические проверки

- `git diff --check` — PASS.
- Проверены существующие owner/ACS API и их вызовы, состав `status()`/`assertInstalled()`, lazy loading, сохранение `gate()` и отсутствие команд записи в новых путях диагностики.
- Проверен список затронутых файлов: только перечисленный ACP/diagnostic scope и этот отчёт; новых migrations/fixtures/tests нет; `docs/ROADMAP.md` не изменён.
- `php -l` — **NOT_VERIFIED**: PHP CLI отсутствует (`php: command not found`), доступного PHP binary в окружении не найдено. Выполнение синтаксических проверок изменённых PHP остаётся необходимым перед merge; системный runtime не устанавливался.
- Автоматические, regression, SQL/integration и browser-тесты не создавались и не запускались согласно заданию и AGENTS.md. Локальная проверка не заявляется успешной функциональной приёмкой ACP.

## Ручная проверка владельцем / остающиеся ограничения

1. Выполнить `php -l` семи изменённых PHP в рабочем PHP 8.5.x окружении.
2. На установленной копии сравнить `core.schema` с ledger 001–029 и schema readback. Проверить сохранение BLOCKED при ошибке/незавершённой миграции/несовпавшем checksum или schema drift, без переписывания ledger ради проверки.
3. Открыть «Основные», «Диагностика», «Интеграции» и «Права»: краткая сводка отдельно от ledger, RESERVED_DENY сохранены, незагруженные модули не объявлены отсутствующей реализацией.
4. Пустые inputs → NOT_CHECKED; выбранный разрешённый/недоступный лист и KB key → фактический scoped результат, не массовое чтение источников.
5. Для ошибочного element catalog сверить canonical key, reference field, тип версии и безопасную рекомендацию; не исправлять версии автоматически.
6. Успешный source probe не меняет installed acceptance или publication_enabled. IC-MYBB-1 остаётся BLOCKED; AAM delivery не включается, Statistics/Inventory операции из диагностики не запускаются.

Installed readiness warprift.ru, фактические owner schemas/routing/hooks/forms, source permissions, внешняя доставка и игровые сценарии остаются **NOT_VERIFIED**. Статусы этой диагностики не заменяют отдельную приёмку, не предоставляют новых прав и не объявляют production publication READY.
