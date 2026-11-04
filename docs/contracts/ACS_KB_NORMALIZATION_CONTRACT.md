# KB → ACS: read-only нормализация ARPG, B1

## Назначение и граница

Реализация находится в `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php`. Это API подготовки определений для последующих этапов ACS. Оно не считает формулы, не создаёт боевые HP/статусы/кулдауны, не вызывает CharacterSheets/ATF, не сохраняет JSON и не выполняет миграций. Существующие функции чтения, формулы и порядок чтения CharacterSheets не изменены.

Основание: `ACS_KB_MECHANICS_AUDIT.md`, G1/G5/G9/G10; `ARPG_INTEGRATION_CONTRACT.md` и `CHARACTER_ABILITY_EFFECTS_CONTRACT.md`. Этот документ конкретизирует реализованный поднабор `af_kb.arpg.sheet-normalized.v1`; остальные целевые helpers интеграционного контракта не считаются реализованными.

## Публичный API

```php
// Чтение сырой строки af_kb_entries по type/key и нормализация.
af_kb_arpg_read_definition(string $type, string $key, ?int $abilityIndex = null): array;

// Та же нормализация уже полученной строки; ссылки могут читать KB через $db.
af_kb_arpg_normalize_entry(array $entry, ?int $abilityIndex = null): array;
```

Поддерживаются `arpg_ability`, `arpg_item`, `arpg_bestiary`, `arpg_mechanics` и отдельная встроенная способность `character`.

```php
$ability = af_kb_arpg_read_definition('arpg_ability', 'flame_strike');
$characterAbility = af_kb_arpg_read_definition('character', 'example_character', 0);
$item = af_kb_arpg_read_definition('arpg_item', 'example_weapon');
$monster = af_kb_arpg_read_definition('arpg_bestiary', 'example_monster');
$mechanics = af_kb_arpg_read_definition('arpg_mechanics', 'example_status');
```

`abilityIndex` — нулевой индекс исходного `character_abilities[]`, **не** slot_index или sortorder. Порядок не сортируется. Индекс обязателен для character; вне character переданный индекс даёт ошибку. Читается оригинальная строка анкеты в KB character contract, а не повторно нормализованный UI-payload. Требуются schema `af_kb.character.contract.v1` и `character_meta.mechanic=arpg`. Выбор активного персонажа и проверка прав остаются обязанностью вызывающего серверного слоя; новых публичных HTTP-маршрутов нет.

При отсутствии записи/БД результат структурированный, `supported=false`, с `definition_not_found`. API возвращает в том числе inactive-запись для диагностики, но помечает её неподдержанной. Lookup не фильтрует чужую принадлежность: это API определений, не авторизация.

## Выбор JSON-проекции

1. Непустой объект `data_json` имеет приоритет целиком.
2. Legacy-обёртка `data_json.meta.rules` поддерживается, если у внешнего объекта нет schema/type_profile/rules. Её происхождение указывается явно.
3. При отсутствии/пустом объекте `{}` в data используется `meta_json.rules`.
4. Отсутствующий column, null/пустая строка column и пустой объект определения не считаются заданным определением. Непустая некорректная JSON-строка, JSON scalar/list или не-объект `meta_json.rules` дают ошибку. Fallback можно вернуть для исследования, но при ошибке результат остаётся `supported=false`.
5. Если обе проекции содержат определение, сравниваются механические leaves: envelope разворачивается, порядок ключей объектов игнорируется, порядок массивов и типы скаляров сохраняются. Различие даёт `source_conflict` и `supported=false`. Выбранный data payload остаётся в результате; поля из другой проекции не добавляются.
6. UI-поля envelope не смешиваются с правилами и не участвуют в сравнении механических leaves. Исходные строки обоих columns сохраняются в raw. Глубина разворачивания ограничена четырьмя уровнями; превышение диагностируется.

Использован существующий `af_kb_arpg_extract_rules_leaf()` для извлечения выбранного rules leaf. `af_kb_extract_rules_for_consumer()` не менялся: его старый precedence иной. `af_kb_normalize_inline_ability_row()` не вызывается новым API, поскольку теряет hit_count/value_mode и заменяет единицы/ref. `af_kb_get_normalized_ability_profile()` / `…item_profile()` — legacy-проекции, не общий ARPG DTO. `af_kb_get_arpg_mechanics_entry()` — lookup/options-путь с собственным precedence, поэтому новый reference-reader не подменяет им выбранное определение. `af_kb_get_entry()` также не подходит: возвращает UI-проекцию без исходного data_json/id. Эти функции проверены и сохранены без изменений.

## Контракт результата

```text
schema: af_kb.arpg.sheet-normalized.v1
source:
  type, id, key, projection, ability_index, updated_at, origin
definition_key: entry key либо <character-key>#character_abilities/<index>
definition_version:
  revision: реальное entry.revision либо rules.revision, иначе null
  declared_version: исходное rules.version, иначе null
  fingerprint: sha256:<детерминированный hash выбранного mechanical leaf>
normalized:
  definition: исходный rules leaf целиком
  ...типизированные проекции ниже
diagnostics: [{severity: error|warning, code, path}, ...]
supported: нет ошибок нормализации и разрешения обнаруженных ссылок
executable: false
raw:
  entry: исходная строка полностью, включая строки data_json/meta_json
  candidates: разобранные целые проекции с именами источников
```

`declared_version` не объявляется ревизией содержимого; `updated_at` не превращается в версию. Если ревизии нет, возвращается null. Fingerprint рассчитан по содержимому, а не по времени запроса. Он учитывает неизвестные поля leaf, явные нули, типы и порядок списков, но не зависит от порядка ключей JSON-объектов. У способности character fingerprint относится к выбранному родительскому character leaf; индекс входит в definition_key. Связанные определения имеют отдельные fingerprints в references. Это метаданные происхождения, не полный числовой ledger источников CharacterSheets.

**`supported` означает пригодность к чтению в рамках B1, не разрешение применять эффект в бою.** Отсутствие ошибок не утверждает полноту правил баланса или валидность формульного выражения. Все результаты и эффекты имеют `executable=false`, включая существующие formula_def. Следующий этап обязан дополнительно проверять поддержку механики, единицы, обязательные правила и наличие своего исполнителя. Никакого исполнения expression в B1 нет.

Отсутствующие механические числовые поля не заполняются нулями. Явные `0` и `"0"` сохраняются со своим типом; отсутствующий key остаётся отсутствующим. Числовые поля проверяются на numeric/finite без арифметики и без изменения значения. Null не заменяется default-числом. Ошибки не уничтожают исходный raw payload.

### Проекции

| Тип | normalized |
|---|---|
| arpg_ability / character ability | `ability_profile={parameters,effects,references,damage,executable}` |
| arpg_item | `item_profile` — исходные параметры целиком; `effects` — общий effect DTO; base_stats/modifiers/слоты/пассивы остаются сохранены |
| arpg_bestiary | combat_stats, ability_keys как список `{source_index,ability,raw}`, resists/resistances/weaknesses, исходные immunities/grants |
| arpg_mechanics | service_kind, entries без whitelist-усечения и definition со всеми полями |

Общие base_stats/modifiers/passive_effects/triggers/conditions/stacking/resources/immunities/grants копируются при наличии. Они не агрегируются и не исполняются. Неизвестные расширения доступны в definition, item_profile или ability_profile.parameters и raw. Для character ability её параметры находятся в ability_profile.parameters, а definition остаётся родительским контрактом.

## Effects: один общий адаптер

Один `af_kb_arpg_acs_ability()` используется для standalone ability, character ability, item effects и объектов bestiary ability_keys. Эффект возвращает:

```text
source_index: индекс исходного эффекта
parameters: все исходные поля + однозначные aliases и наследованные значения
inherited: перечень наследованных полей
damage: {category, element, scope}
references: ссылки и результат их разрешения
supported: нет ошибок данного эффекта
executable: false
raw: эффект до любых преобразований
```

- Aliases: kind→effect_type, targeting→target, duration→duration_value. Legacy-ключи не удаляются. Если оба непустых значения отличаются, возвращается alias_conflict; приоритет canonical виден в parameters, оригинал сохранён.
- **formula_ref остаётся ссылкой на formula_def, formula_profile — отдельным profile key. Между ними нет alias.** Сохраняются value_mode/hit_count/coefficient и все неизвестные поля.
- Пустые/отсутствующие formula_profile, target и damage_type наследуются от способности. Target способности предварительно получает alias targeting. Отсутствие значения отличается от null/0; ноль не считается пустой строкой. Duration/coefficient/element автоматически не наследуются.
- Duration unit не подставляется. Для заданной duration без единицы возвращается duration_unit_missing; известный здесь словарь единиц — own_turn/target_turn/round, другая явная единица сохраняется с unsupported_duration_unit. Значения не переводятся между секундами/ходами.
- Эффект из одних пустых полей/notes/единиц/параметров без kind/value/другого содержания пропускается с warning empty_effect; исходник остаётся в raw/definition. Ноль value — содержательный эффект. Неизвестное непустое расширение не выбрасывается как пустая строка: оно остаётся с ошибкой неизвестного типа, если тип не задан.
- Неизвестный kind (включая legacy barrier/proc) не преобразуется в похожий эффект. Неизвестные операции дают unsupported_operation. Effect operations add/subtract/set/percent — словарь распознавания, не реализованный вычислитель; modifiers отдельно распознают существующие flat/percent, сохраняя их прежнюю семантику.
- Damage/heal/shield требуют value либо формульный ключ; status требует status_key; resource требует resource_key; stat_modifier требует известный stat_key. Resource/stat_modifier требуют value и operation. При обнаруженной ссылке проверяется существование доступного определения; неразрешённая ссылка — ошибка, даже если рядом есть value.
- Top-level legacy damage_value/heal_value/shield_value остаются в parameters. Из них не синтезируются effects и не вычисляется результат. Они не удаляются при наличии structured effects.

## Ссылки и служебные определения

`af_kb_arpg_acs_reference()` читает только `af_kb_entries` и использует тот же selector источников. Ссылки на ability читают arpg_ability; formula_profile/formula_ref/status_key/resource_key/cost_resource — соответствующий service kind. Поддержаны отдельная запись с key определения и aggregate с key=service_kind и записью в entries[]. Неактивный, конфликтующий или неразрешённый источник не принимается. Seed fallback и labels не выдаются за установленные определения. Без БД ссылки остаются unresolved с supported=false.

Reference DTO содержит kind/key/resolved и при успехе entry_id/entry_key/fingerprint. Разрешение подтверждает существование определения, не работоспособность формулы. Рекурсивного раскрытия нет, поэтому циклы ссылок не запускают бесконечную загрузку. Вложенные неизвестные service payloads сохраняются; глубокая валидация математических/событийных схем относится к последующим задачам.

## Монстры и сопротивления

Combat_stats сохраняются без перерасчёта; отсутствие обязательного stat из KB registry диагностируется отдельно от нулевого значения. ability_keys остаётся списком объектов; поддержан legacy string ref с сохранением исходной строки в raw. Inline-объект проходит тот же ability adapter, ссылка даёт reference DTO; связанная способность не смешивается с inline-полями автоматически.

Resists/resistances/weaknesses принимают object rows или numeric maps. Каждая строка содержит source_index/parameters/damage/unit/raw. У map key сохраняется как legacy_key, **не** угадывается его смысл. Damage.category распознаёт только physical/elemental/true/healing/hybrid; конкретный element и scope читаются отдельно и не выводятся друг из друга. Нераспознанный damage_type сохраняется в parameters и даёт ambiguous_damage_type. Отсутствующие scope/unit — null и warning resistance_semantics_unspecified. Никаких процентов, рейтингов, caps, суммирования или применения к урону не добавлено. Immunities и grants сохраняются как исходные коллекции; иммунитет не выводится из величины сопротивления.

## Диагностика, проверки и ограничения B1

Ключевые ошибки: `invalid_json`, `expected_json_object`, `expected_rules_object`, `rules_missing`, `rules_depth_exceeded`, `source_conflict`, `mechanic_mismatch`, `unsupported_entry_type`, `unsupported_rules_schema`, `unsupported_character_contract`, `unsupported_service_kind`, `type_profile_mismatch`, `inactive_definition`, `unrecognized_definition`, `character_ability_missing`, `unexpected_ability_index`, `alias_conflict`, `invalid_number`, `unsupported_effect_type`, `unsupported_operation`, `unsupported_value_mode`, `unsupported_duration_unit`, `duration_unit_missing`, `required_field_missing`, `required_stat_missing`, `unknown_stat`, `effect_value_missing`, `reference_missing`, `reference_unresolved`, `definition_not_found`. Ошибки формы массива/строки имеют коды `expected_array`, `expected_object`, `expected_effect_object`, `expected_modifier_object`, `expected_ability_object`.

Warnings: empty_effect, legacy_schema_missing, resistance_semantics_unspecified. Потребитель должен учитывать diagnostics, а не только наличие normalized. Неизвестные schemas/неподдержанные поля не исправляются и не записываются обратно.

Проверки выполняются точечно в PHP 8.5 CLI: lint изменённого PHP, старые/новые effects и наследование, ноль/отсутствие, aliases, сохранение ref/value_mode/hit_count/unknown fields, единицы, неизвестные операции, выбор JSON и конфликтов, fingerprint, item/bestiary/character/service projections и отказ при недоступных ссылках. Полный suite, браузер, MyBB/БД и миграции не запускаются. Успешный lookup живых производственных определений требует интеграционной проверки при подключённой БД на следующем этапе; в B1 исходные игровые записи не создаются.
