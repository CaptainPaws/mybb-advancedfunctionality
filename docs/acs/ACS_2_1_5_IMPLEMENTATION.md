# ACS-2.1.5 — Read-only CombatSnapshot preparation

Исходная main: `af90a13c1b6f80eab506030d0ff02f73f7b542af` (включает ACS-2.1.4).
Это серверный API подготовки DTO, без endpoint, сессий, entities и INSERT в ACS snapshots.
Формулы H1, S1.2, D1.4, N1/N2/M1 не меняются.

## API и права

- `af_charactersheets_create_combat_snapshot(int $sheetId, int $actorUid, array $context = []): array` — CharacterSheets API.
- `AF_ACS_CharacterSheetAdapter::createSnapshot(...)` — согласованная с Architecture точка входа ACS; делегирует владельцу CharacterSheets.
- `context.expected_source_fingerprint` — необязательная проверка конкретной версии исходного KB character. Контекст не предоставляет прав, ролей, источников или готовых totals.

Проверяется совпадение actor с текущим аутентифицированным MyBB user, enabled CS/KB/Inventory (adapter также ACS), наличие листа, штатное право редактирования листа CS, форумные canview/canviewthreads, canonlyviewownthreads, опубликованная тема и доступ KB. Парольные форумы консервативно отклоняются: неподтверждённый password bypass не создаётся.

У листа требуется явная существующая связь acceptance.kb_entry_id; KB availability.owner_uid и source_tid должны совпадать с sheet.uid/tid. source_uid не считается правом собственности. Проверяется ARPG schema/mode. Новое игровое право управления персонажем, GM bypass и право регистрации участника этим API не выдаются.

Вложенные definitions проходят KB visibility. При отказе доступа не возвращаются ни источники, ни их rules, ни вложенная иерархия; отказ actor также не раскрывает существование/содержимое листа. DTO серверный и не предназначен для прямой выдачи игроку: будущий Presentation должен фильтровать его отдельно.

## DTO

- `schema_version=1`, `contract_id=IC-CS.combat-preparation.v1`, `builder_version=cs-readonly-1`.
- `numeric_policy=binary64-half_up-v1`; промежуточного/финального округления builder не делает. `calculation_policy=cs-arpg-legacy-additive-v1` описывает существующие KB/CS flat/percent semantics, не объявляет их новым M1 resolver.
- `engine_version=null`, `executable=false`, `persistent=false`: resolver ещё не реализован.
- `identity`: sheet ID, owner UID, tid, KB key, source type, KB schema/revision/declared version/fingerprint и fingerprint исходного sheet row.
- `base_characteristics`: значения до live equipment/talents; `characteristics`: итоговые характеристики реестра KB, с одним canonical `status_resistance`. `stat_provenance.status_resistance` сохраняет исходные status aliases.
- `resources`: HP maximum из характеристики, current=null, исходные объявленные resources. Существующий владелец пока не даёт доказанного permanent-current-HP контракта; builder не назначает HP=max и не меняет HP.
- `resistances`: sparse map только из 17 основных ключей KB с исходным source index/key. Для готового DTO требуются все 17 конечных неотрицательных RES с явными scope=elemental/unit=RES. Missing не становится нулём. wind→air использует KB; duplicate/unknown/child RES диагностируются.
- `classification`, `abilities`: versioned KB direction refs, parent/root provenance, explicit damage_element и нормализованные ability definitions. Направление не выводит тип урона и не создаёт RES. Inline ability закреплена fingerprint персонажа + индексом; внешняя ability требует ability_definition_version/ability_fingerprint из ACS-2.1.4.
- `equipment`, `talents`, `sources`, `source_breakdown`: instance/binding IDs и fingerprints, KB refs/versions, источники модификаторов, operation/value, included_in_total и before/after.
- `read_set`: точные предикаты и hashes захваченных rows; `diagnostics`, `ready`, итоговый `fingerprint`.

Для 17 стихий используется только реестр/нормализация KB, с `primary-elements-17-v1` и `wind-air-v1`. Отдельного реестра CS нет.

## Equipment provenance и реальный pipeline

Аудит установил: `compute_sheet_view()` синхронизирует fixed skills; `cs_build_character_state()` вызывает этот же путь и не отклоняет UID mismatch. Новый API не вызывает ни одну из этих функций и не загружает calculator/render/experience/sheets_crud.

В штатном ARPG pipeline KB сначала складывает origin/archetype/variant/growth и ручные character_stats; Accuracy является особым явным base total. CS затем применяет live equipment и активные talents. Числовой код KB выделен в чистый `af_kb_build_arpg_character_stats_from_sources()` с неизменным порядком операций. Существующий KB loader делегирует этому helper, сохраняя прежнее поведение. Это необходимое небольшое изменение KB: использовать cached/lookup builder или копировать его арифметику в ACS небезопасно.

Шесть чистых ARPG helpers CS перемещены из render.php в arpg_stats.php дословно. Рендер подключает этот файл и продолжает работать с прежними функциями. Snapshot применяет каждый equipment/talent modifier через существующий CS helper и фиксирует его before/after, source ID/type/version и included_in_total. При Accuracy base=20 и item+5 результат равен 25, а не 30.

`equipment_policy=already_included_do_not_reapply`: следующий потребитель использует characteristics как итог и **не применяет source_breakdown снова**. base_characteristics нужны для provenance, а не для неявного второго расчёта.

Стандартные источники разделены на ручной base input, origin, archetype, origin_variant/growth, talents, equipment и ability definitions (боевые modifiers способностей не применяются к постоянному итогу). Неизвестные permanent/legacy bonus buckets, неясные instance bonuses, conditional/неподдерживаемые modifiers не угадываются и блокируют ready с diagnostics. Неизвестный producer character_meta.source не доказывает исключение экипировки из base; готовность блокируется. Поддержан проверенный producer `af_atf_bridge` и его pre-equipment контракт.

## Read-only и согласованность

- Нет запросов записи, sync, изменения build_json/EXP/Inventory/KB/resources или ACS persistence.
- Явный вызов лениво загружает только новый CS module и чистые арифметические helpers. showthread bootstrap не строит DTO и не загружает эти модули.
- Inventory читается адресно: equipment bindings владельца, batch rows только нужных item IDs/активных talent refs. Не используется UI exporter с ошибочным per_page/пагинацией и отсутствует глобальный каталог.
- Definition reads точечные, uncached; версии вложенных направлений и разрешённых references включены в read set. Состав источников повторно читается, изменение даёт source_changed_during_read.
- Это optimistic preparation, **не атомарная фиксация**. ACS-2.2 обязан повторить авторизацию, проверить весь read set/версии и выполнить immutable append в своём протоколе регистрации. `ready` не означает готовность боя или наличие session permission. Existing immutable cs.combat_snapshot gate этапа 2.2 не объявляется READY.

## Ограничения и diagnostics

Неполные legacy stats/RES, отсутствующая явная KB-связь/owner relation, неизвестные aliases/producer, конфликт Status Resistance, небезопасные/внутренние refs, несовместимый schema, opaque legacy modifiers, неоднозначные несколько weapon slots или повторные item/talent bindings блокируют ready. Никаких исправляющих записей при чтении нет.

Canonical status_resistance VM поддержан; origin status_resistance_base без согласования старого view даёт canonical_base_requires_view_alignment. Legacy variant accuracy_base, где KB и CS расходятся, диагностируется, а не пересчитывается догадкой. Дополнительные RES/resources из equipment/origin/talent требуют доказательства включения и пока дают resource_resistance_inclusion_unverified. Текущие permanent ресурсы не выдумываются; current_resources_not_owned_by_contract — предупреждение. Для полноценных новых контрактов нужны следующие отдельные задачи владельцев данных.

## Изменённые файлы

1. `charactersheets/charactersheets.php`: lazy API/loader dependency.
2. `charactersheets/modules/render.php`: подключение общего чистого ARPG модуля.
3. `charactersheets/modules/arpg_stats.php`: шесть перенесённых без изменения функций.
4. `charactersheets/modules/combat_snapshot.php`: permissions/read/projection/provenance/readback.
5. `knowledgebase/knowledgebase.php`: чистая граница существующего расчёта над захваченными источниками.
6. `advancedcombatsystem/modules/Infrastructure/Adapters/CharacterSheetAdapter.php`: server-only делегация.
7. `tests/combat_snapshot_readonly.php`: изолированные fixtures.
8. Этот отчёт.

Пути аддонов приведены относительно inc/plugins/advancedfunctionality/addons/. ATF, Inventory, AdvancedEditor, MyBB, templates/JS/CSS и SQL не изменены.

## Минимальные проверки

- PHP lint всех затронутых PHP файлов.
- combat_snapshot_readonly.php: valid snapshot, Accuracy 20 и 0, missing Accuracy без fallback, все 17 RES/wind, blood→life без child RES, equipment +5 ровно один раз, origin/manual order, Status Resistance conflict, owner/spoofed actor и private reference denial, повторяемый hash, readback при изменении источника.
- Fixture DB не имеет INSERT/UPDATE/DELETE/DDL методов; повторное построение не меняет массивы данных. Проверяется отсутствие calculator/sync runtime даже после snapshot call и отсутствие snapshot запросов на showthread bootstrap.
- accuracy_fields_sheets_roundtrip.php и kb_accuracy_roundtrip.php проверяют сохранение прежнего пути Accuracy. Проверено дословное совпадение шести перенесённых функций с исходной main.
- Два дополнительно вызванных старых fixtures arpg_talent_stats_regression.php и origin_variant_regression.php падают на устаревших списках registry/modifier keys. Те же ошибки подтверждены в отдельном worktree исходной main af90a13c; новые snapshot assertions к ним не относятся, их глобальное обновление в задачу не включено.
- git diff --check. Полный suite/E2E/стресс-тесты не выполнялись. Среда PHP 8.4.24; PHP 8.5.0 остаётся проверкой целевого форума.

## Ручная приёмка

На тестовой копии, в доверенном серверном вызове с уже загруженным MyBB и аутентифицированным текущим actor:

```php
require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/Adapters/CharacterSheetAdapter.php';
$dto = (new AF_ACS_CharacterSheetAdapter)->createSnapshot($sheetId, (int)$mybb->user['uid']);
```

Не создавать публичный debug endpoint и не выводить полный серверный DTO игрокам.

1. Проверить identity, ready/diagnostics, Accuracy/Speed/Status Resistance, KB versions и 17 RES у подготовленного листа. Отсутствующие mandatory source values сначала исправляются владельцем данных отдельным штатным редактированием.
2. У персонажа с предметом +5 сравнить base/final/trace: 20→25, included_in_total=true. При повторном вызове результат не накапливается.
3. У старого неполного листа проверить missing diagnostics и отсутствие вымышленных 0; у blood проверить root life, parent versions и отсутствие RES_blood/implicit life damage.
4. Сравнить build_json/progress_json, KB и Inventory до/после нескольких вызовов; проверить неизменность и стабильный fingerprint при одинаковых источниках.
5. Проверить отказ чужому actor и защищённым refs, обычную тему и существующий UI CharacterSheets. Само открытие UI новый API не вызывает; это намеренно серверная интеграция будущего сервиса регистрации.

Откат: revert коммита PR, развернуть прежние PHP и перезапустить PHP/opcache по принятому процессу. SQL-миграций и пользовательских записей нет, откат данных не требуется. Production в этой задаче не изменялся. ACS-2.1.6 не реализуется.
