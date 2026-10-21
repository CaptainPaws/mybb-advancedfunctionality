# ACS-2.1.6 — Integration adapters и capability diagnostics

Исходная main: `e8f8a6c70dbf444209911e942d982d75ec21d736` (включает ACS-2.1.5).
Область: только ACS, один fixture и этот отчёт. Design Baseline, формулы H1/D1/S1, AF Core и другие аддоны не изменены.

## Адаптеры

`AF_ACS_KnowledgeBaseAdapter::readDefinition(type, key, actorUid, abilityIndex = null)` поддерживает `character`, `arpg_bestiary`, `arpg_ability`, `arpg_element`. Последний тип охватывает основные стихии и направления; `primaryElements(actorUid)` читает фиксированный каталог основных стихий.

Адаптер использует реальные KB read/normalize/schema APIs, не собственный JSON parser. Для таксономии вердикт берётся у специализированного `af_kb_arpg_read_element`: generic mechanics recognizer не распознаёт минимальные legacy element definitions. Для монстров/способностей штатная schema validation проверяет копию источника; результат не записывается и не используется для скрытого исправления.

Проверяются текущий аутентифицированный MyBB actor, enabled ACS/KB, KB view/visibility, доступ к каждому вложенному reference. Для полной character definition требуется владелец availability.owner_uid либо штатное право KB editor. source_uid/роль GM/переданный uid сами права не предоставляют. После повторного owner read проверяются актуальные права и fingerprint. При недоступном приватном reference возвращается минимальный отказ, без data.

DTO содержит `schema_version=1`, `contract_id=IC-KB.read.v1`, adapter version, source identity, `definition_version` с schema/revision/declared version/fingerprint, `data`, diagnostics и capabilities. `ready` относится к выбранному определению; `executable=false`, `persistent=false`. У element дополнительно сохраняется identity_fingerprint и element revision из KB. Полная character definition возвращает **definition inputs**, а не рассчитанный CharacterSnapshot; явная `readiness_scope` предотвращает их смешение.

`AF_ACS_CharacterSheetAdapter::createSnapshot(sheetId, actorUid, context)` расширен поверх API ACS-2.1.5. Он не вызывает calculator/view/sync и не пересчитывает totals. Проверяются owner-issued schema/builder/policies, hash, source versions/read set, обязательные stats, 17 RES и equipment trace. Недоступный/несовместимый API блокируется без fallback на старый view.

Owner DTO сохраняет base/calculated stats, resources, ability/direction refs, source versions и equipment provenance. Добавляются adapter_version/capabilities. `fingerprint` остаётся hash исходного owner DTO, а не нового envelope с capability metadata; при последующей фиксации это нужно учитывать. Постоянная фиксация и повторная авторизация/read-set validation остаются ACS-2.2.

## Стихии, направления и diagnostics

Источник canonical identities — только KB registry: fire, water, air, earth, ice, lightning, nature, light, shadow, death, life, chaos, mind, abyss, gravity, astral, space. Независимого ACS-реестра нет.

- wind→air нормализуется KB с сохранением source_key; коллизии air/wind не дают второй RES.
- Для готовых характеристик нужны все 17 конечных неотрицательных RES; missing не подменяется нулём.
- Направления читаются с parent/root/provenance, версиями и fingerprints. Цикл, отсутствующий/устаревший parent, неизвестный key и child RES блокируют готовность.
- `blood` остаётся направлением `life`, без RES_blood и без автоматического life damage. Elemental-определение требует явного damage_element: legacy поле element сохраняется источником, но не подменяет требуемое поле нового контракта.
- Для versioned taxonomy требуются реальные element revisions; старый root с fingerprint, но без revision, остаётся читаемым с `element_version_invalid`, без автоматической миграции. Исправление выполняется владельцем данных через существующий KB editor с проверкой зависимых pins.

Используются существующие KB/CS коды: required_stat_missing, invalid_stat_value, status_resistance_alias_conflict, element_resistance_key_invalid/value_invalid/alias_collision, required_resistance_missing, element_alias_collision, element_cycle, element_parent_missing, element_version_invalid, damage_element_required, magic_direction_version_mismatch, source_breakdown_unavailable, source_changed_during_read, definition_access_denied, source_read_failed, unsupported_rules_schema, unsupported_character_contract и прочие owner diagnostics. Новый параллельный набор aliases вроде accuracy_missing не создаётся. Для отказа legacy string-based schema validator используется общий unsupported_rules_schema; исходные сообщения/значения в ACP не раскрываются.

## Capabilities и область READY

EXISTING/PROPOSED относятся к наличию интерфейса; READY/BLOCKED/OPTIONAL_UNAVAILABLE — к проверке возможности. Функция/таблица сами READY не дают.

| Capability | Что реально проверяется |
|---|---|
| accuracy_registry | Runtime registry и VM mapping |
| accuracy_roundtrip | Настоящий KB schema/JSON/normalize round-trip в памяти: явный 0 и отсутствие без fallback |
| canonical_elements_17 | Фиксированный реальный каталог 17 identities, root/version/fingerprint и отсутствие коллизий; у выбранного snapshot также полнота/валидность RES |
| magic_direction_hierarchy | KB validator на валидной parent/root цепочке, цикле и orphan в памяти; выбранная цепочка дополнительно проверяется как данные |
| legacy_element_aliases | Runtime wind→air, реальные коллизии каталога и aliases выбранных RES |
| explicit_damage_element | Отдельность physical/elemental и обязательное явное damage_element, без вывода из root |
| element_snapshot_provenance | Версии/fingerprints parent chain и provenance выбранного источника |
| status_resistance_mapping | Одна характеристика, явный 0, конфликт aliases без выбора значения |
| bestiary_snapshot | Одно выбранное определение монстра со schema/stats/RES/refs, без создания NPC instance |
| character_snapshot | Один выбранный owner-authorized CS read-only DTO |
| equipment_source_breakdown | Inclusion policy, instance/binding IDs, source versions, stat/operation/value и before/after trace; никаких повторных бонусов |
| snapshot_read_only | Выполненный owner API с поддержанным read-only contract/builder, persistent=false, executable=false и read set; поддержано write-forbidden fixture |
| definition_versioning | Реальные schema/version/hash и повторяемость нормализации; согласованность выбранных источников |

В ACP `scope` различает `kb_memory_contract`, `primary_catalog`, `selected_character`, `selected_definition`. READY в памяти не является сертификатом всех production-записей, UI или полного Fields round-trip. IC-FIELDS-ELEMENTS не объявляется готовым одним наличием KB normalizer; соответствующий pipeline сохраняется владельцами и проверяется при ручной приёмке.

При полном каталоге без выбора источников готовы девять contract/catalog checks; четыре source-specific checks (bestiary_snapshot, character_snapshot, equipment_source_breakdown, snapshot_read_only) остаются BLOCKED с просьбой выбрать источник. С корректными выбранными листом и монстром fixture получает READY по всем 13 capabilities. Несколько областей объединяются через AND: хороший выбранный источник не перекрывает плохой каталог или другой неготовый выбранный источник.

Существующий kb.definition_reader может быть READY для проверенного выбранного определения. Immutable kb.combat_snapshot/cs.combat_snapshot остаются PROPOSED/BLOCKED этапа 2.2; Inventory reservation — этапа 6, atomic publish и боевой editor — следующих этапов. Необязательные AAM/Statistics по-прежнему OPTIONAL_UNAVAILABLE и не блокируют базовые данные.

## ACP и производительность

Существующий AF Admin Router, permissions ACS-1.3 и Table сохранены. Раздел «Интеграции» выполняет read-only contract/catalog checks и показывает detected/enabled, manifest version, фактически объявленные/загруженные API, scope, причины BLOCKED, stage/remediation и ограниченный список диагностических code/path.

GET-форма позволяет явно проверить один sheet ID и/или один KB type/key. Она не меняет состояние и не требует нового CSRF-протокола; существующие изменяющие ACP POST-команды и CSRF не изменены. Нет repair-кнопок, SQL/PHP console или вывода полных защищённых snapshots.

`Diagnostics::capabilities()` без параметров остаётся лёгкой проверкой declarations для существующих callers/gates. Только `capabilities(true, targets)` включает новые owner probes. Нет построения всех листов/каталога направлений, новых hooks/endpoints/assets или изменения Context/bootstrap. На обычном showthread adapters/probes не загружаются. Проверка схемы использует существующий read-only status; migration runner run/install/upgrade не вызывается. Стандартная синхронизация языкового кеша AF в ACP остаётся штатной, проверяемые игровые данные не изменяются.

## Файлы

Относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

1. `modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php` — новый KB reader.
2. `modules/Infrastructure/Adapters/CharacterSheetAdapter.php` — проверка owner DTO без расчёта.
3. `modules/Infrastructure/Adapters/CapabilityValidator.php` — общая проверка контрактов/данных.
4. `modules/Administration/IntegrationCapabilities.php` — on-demand probes и scoped evidence.
5. `modules/Administration/Diagnostics.php` — те же 13 capability names, без параллельных aliases.
6. `admin.php` — существующая страница, GET selection, безопасные metadata.
7. `manifest.php` — три новые RU/EN подписи в существующем lang contract.
8. `tests/acs_integration_adapters.php` — относительно корня репозитория; переиспользует небольшой fixture ACS-2.1.5.
9. Этот отчёт — `docs/acs/ACS_2_1_6_IMPLEMENTATION.md`.

AF Core, KB, CS, Inventory, MyBB, ATF и Editor не изменены. SQL-миграций нет.

## Минимальные проверки

- PHP lint всех восьми затронутых PHP-файлов; git diff --check.
- `php tests/acs_integration_adapters.php`: реальный KB schema round-trip, корректный CS snapshot, отсутствие Accuracy, ноль, Status Resistance conflict, 17 RES, wind→air, blood→life, explicit damage, equipment +5 ровно один раз, права/приватные refs, неподдержанная inclusion policy, источник изменился во время чтения, native ACP dispatch без payload.
- Fixture не имеет SQL write/DDL методов, сравнивает исходные rows до/после, проверяет отсутствие heavy calculator/migration runtime. Диагностика не синхронизирует/не исправляет источники.
- `php tests/acs_permissions_diagnostics_regression.php`: native AF discovery/permissions dispatch, deny-by-default, declarations-only режим без external bootstraps, optional/mandatory gates и отсутствие глобального ACS runtime.
- Полный suite, E2E, SQL stress и визуальные облачные тесты не запускались. Среда PHP 8.4.24; PHP 8.5.0/MyBB 1.8.40 production требуют ручной приёмки.

## Ручная приёмка и откат

1. Открыть ACP → AdvancedCombatSystem → Интеграции. Проверить interface evidence, scopes, 17 primary catalog и конкретные причины BLOCKED. Отсутствие выбранного источника не является ошибкой установки.
2. Ввести реальный **sheet ID**, не tid, из подготовленного ARPG-листа; проверить character_snapshot, оборудование и diagnostics. API не выдаёт нового права управлять персонажем.
3. Выбрать arpg_bestiary и технический KB key монстра: проверить Accuracy, Status Resistance, 17 RES и direction/version readiness. Для проверки отдельного направления выбрать arpg_element и его key; wind должен читаться как air с legacy provenance.
4. Проверить legacy incomplete запись, отсутствующий RES/Accuracy, конфликт status aliases и несовместимые parent pins на тестовых данных. Должны появиться owner codes без автоматического исправления. Источник исправляется только его штатным редактором после проверки смысла данных.
5. Проверить отказ без AF ACP permission и отсутствие полных чужих snapshots/приватных definitions в HTML. При отключённой зависимости — BLOCKED только её возможностей, обычный форум работает.
6. Открыть тему, профиль, CS и KB несколько раз; сравнить build_json/KB/Inventory до/после явного чтения. Проконтролировать прежнюю работу lazy loading. Формулы не изменялись, бои не запускаются.

Откат: revert коммита PR и развернуть прежние ACS PHP; обновить opcode/lang cache штатным способом. Новых таблиц, настроек и пользовательских записей нет, откат данных не требуется. Production в рамках задачи не изменялся. ACS-2.2 автоматически не начинается; завершение интеграционного фундамента зависит от ручной приёмки.
