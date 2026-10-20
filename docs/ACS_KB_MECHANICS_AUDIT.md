# Аудит механического ядра KB для AdvancedCombatSystem

Дата: 20 октября 2026. Проект: warprift.ru. Целевая платформа: MyBB 1.8.40 / PHP 8.5.0.

## 1. Область, источники и достоверность

Проверен исходный PHP и относящиеся к контрактам редакторы JS репозитория CaptainPaws/mybb-advancedfunctionality, снимок **`origin/main`, commit `0502965b9c1b3e35a1ac1b75147c3b001c49fddf`**. Исследование выполнено в отдельном detached worktree; исходная рабочая ветка среды `work` (`c95713d…`) отличается от main и не использована как основание выводов. Проверка актуального HEAD на GitHub через `git ls-remote` не удалась: недоступен proxy:8080. Поэтому выводы относятся к указанному локальному снимку main, а не к непроверенному текущему состоянию удалённой ветки.

Использованы существующие `ARPG_INTEGRATION_CONTRACT.md`, `CHARACTER_ABILITY_EFFECTS_CONTRACT.md`, `CHARACTER_SHEET_RULES_PIPELINE_AUDIT.md`, `KB_EDIT_SCHEMA_ROUNDTRIP_AUDIT.md`; их утверждения сверены с реализацией. Производственная БД, установленные записи справочников, конфигурация сайта и работа под PHP 8.5 не проверялись. «Определено» ниже означает наличие контракта/seed в коде, а не доказанное наличие заполненной записи на сайте.

Изменён только настоящий документ. Runtime PHP/JS, баланс, ядро MyBB не изменены. Тесты, браузер и игровые сценарии не запускались. Неопределённые игровые правила оставлены вопросами, новые формулы не предлагаются.

Для краткости в таблицах:

| Обозначение | Файл от корня репозитория |
|---|---|
| KB | `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php` |
| KB-JS | `inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js` |
| CS-C | `inc/plugins/advancedfunctionality/addons/charactersheets/modules/calculator.php` |
| CS-R | `inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php` |
| CS-B | `inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php` |
| CS-S | `inc/plugins/advancedfunctionality/addons/charactersheets/modules/sheets_crud.php` |
| ATF | `inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php` |
| INV | `inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php` |

Номера строк ниже привязаны к аудируемому commit; имена функций — устойчивые ориентиры.

## 2. Главный вывод

KB содержит каталог механических данных и частичные вычисления исходных характеристик. CharacterSheets исполняет расчёт базовых статов и **серверную арифметику предварительного результата старых способностей**. Однако полноценного разрешения действия против цели, изменения боевого HP/ресурсов, поглощения щитами, жизненного цикла статусов и событийного движка в проверенных потребителях нет.

Считать все формулы «только UI» неверно: `af_charactersheets_arpg_build_formula_profile_applied()` действительно считает числа в PHP. Считать эти числа результатом боя тоже неверно: функция возвращает строки карточки, не учитывает защитника и отключается при структурированных `effects[]`.

`formula_profile` — справочник профилей с метаданными; арифметика зашита в CS-R. `formula_def.expression` не имеет найденного серверного исполнителя и даже отдельной типизированной схемы выражения. Обещанный единый `normalized.*` остаётся целевым контрактом, а не полностью реализованным API. Для ACS сначала нужны согласованные KB-контракты; переносить правила в ATF или записывать боевое состояние в CharacterSheets нельзя.

## 3. Общая цепочка создания, хранения и чтения

### 3.1. Определения KB

`af_kb_default_type_profile_payload_arpg()` (KB:341), `af_kb_default_arpg_type_definitions()` (KB:932) задают defaults и UI-схемы. Публичные типы и внутренний `arpg_mechanics` перечислены в `af_kb_arpg_type_registry()`; служебные kinds — `af_kb_arpg_service_entity_kinds()` (KB:6704).

Редактирование: маршрут `kb_edit` → `af_kb_handle_edit()` (KB:12397), шаблоны `knowledgebase_edit.html` / `af_kb_edit_fullpage.html`, JS-редактор KB-JS. Типы и схемы редактируются через `af_kb_handle_type_edit()` и хранятся в `af_kb_types`. Определения — строки **`af_kb_entries` с `type` и `key`**, `data_json` и `meta_json.rules`; блоки/отношения — сопутствующие представления и ссылки, не боевой исполнитель.

Сохранение разбирает `kb_rules_json`, синхронизирует `meta_json.rules` и `data_json`, вызывает `af_kb_validate_rules_json_by_type()` → ARPG-ветку `af_kb_validate_rules_json_by_type_arpg()` (KB:5933). Последняя мигрирует legacy-поля, добавляет defaults через `array_replace_recursive`, устанавливает envelope/schema/type_profile и вызывает `af_kb_validate_arpg_entry_by_type()` → envelope/public/service validators.

Основной envelope: `af_kb.arpg.meta.v1` с `mechanic`, `tags`, `ui`, `blocks`, вложенным `rules` (`af_kb.arpg.rules.v1`). Персонажи — особый контракт `af_kb.character.contract.v1`, а не обычный ARPG envelope. Наличие defaults может сделать отсутствовавшее поле формально валидным; это не доказывает осмысленную настройку механики.

### 3.2. Реальное чтение и нормализация

| Путь | Реальное поведение | Ограничение для ACS |
|---|---|---|
| `af_kb_extract_rules_for_consumer()` (KB:7860 и далее) | Предпочитает `meta_json.rules`, затем `data_json.meta.rules`; для CS разворачивает leaf; возвращает `status=arpg_partial` | `supported=true` означает возможность чтения, не исполнение механик. Consumer `acs` не входит в allowlist ARPG-потребителей |
| `af_charactersheets_arpg_extract_entry_rules()` (CS-R:1039) | Сначала `data_json`, затем `meta_json.rules`, затем KB-адаптер/leaf | При расхождении проекций потребители могут увидеть разные правила |
| `af_kb_arpg_extract_rules_leaf()` (KB:10706) | Разворачивает вложенные `rules` максимум на четыре уровня | Это извлечение массива, не типизированная семантическая нормализация |
| `af_kb_get_normalized_item_profile()` | Частичный legacy/DnD-ориентированный профиль плюс `raw_rules`; часть ARPG читает через вложенный `rules` | `equip_slot`, rarity и прочие поля не образуют полный ARPG combat item contract |
| `af_kb_get_normalized_ability_profile()` | Читает прежде всего `spell`/`ritual`, root `effects`, возвращает raw | Для ARPG envelope может дать пустые cost/cooldown/effects и тип `ritual`; нельзя принимать как готовый ACS ability adapter |
| `af_kb_normalize_inline_ability_row()` (KB:6368) | Нормализует встроенные способности, aliases и фиксированный набор полей массивов | Теряет неописанные поля; смешивает старые `formula_ref` и новые `formula_profile`; единицы принудительные |
| `af_kb_get_arpg_mechanics_entry()` / `…options()` (KB:2931/3007) | Получает `arpg_mechanics` из `data_json.rules`, нормализует строки справочника, фильтрует активность; при отсутствии набора использует seed fallback | Это lookup/options API, не resolver графа формул/триггеров |

`docs/ARPG_INTEGRATION_CONTRACT.md` §1.3 и §4 прямо описывает целевой `af_kb.arpg.sheet-normalized.v1`: `base_stats`, `modifiers`, `ability_profile`, `item_profile`, `costs`, `cooldown`, `statuses`, `triggers`, `conditions`, `scaling`. В PHP проверенных аддонов **не найдены реализации** `af_kb_arpg_normalize_entry`, `af_kb_arpg_assemble_meta_json`, `af_kb_arpg_collect_item_modifiers`, `af_kb_arpg_compose_character_stats`, `af_kb_arpg_resolve_ability_rows`, `af_kb_arpg_resolve_service_kind_entries`, `af_kb_arpg_resolve_bestiary_payload`. Существующие частичные helpers не эквивалентны этим функциям. Список в документе помечен как функции, которые нужно добавить/переписать; его нельзя трактовать как перечень готовых API.

## 4. Карта компонентов KB

Для строк со служебным kind общий путь создания/хранения/валидации: `kb_edit` → `arpg_mechanics`, `rules.service_kind=<kind>`, `rules.entries[]` → `af_kb_entries`; envelope/defaults/migration и `af_kb_validate_arpg_service_entity()` (KB:7035). Видимость служебных записей требует `category=service.mechanics`, `catalog=false`, `search=false`, `internal=true`.

| Компонент | Создание и редактирование; сохранение | Валидация и нормализация | Фактический потребитель и серверная математика | Ограничение / точка расширения |
|---|---|---|---|---|
| Реестр характеристик | `af_kb_arpg_character_stat_registry()` (KB:149), PHP, не отдельная редактируемая запись | Канонические key → label/vm_field; bestiary проверяет числовые combat_stats по реестру | KB/ATF editors, CS stat mapping; сам реестр не считает | Нет unit, диапазонов, категории/боевой роли. Расширять описание реестра аддитивно |
| Реестр пассивов | `af_kb_arpg_passive_effect_registry()` (KB:184); значения пассивов в `rules.passive_effects` талантов/предметов | Реестр названий; UI `renderPassiveEffectEditor`; public validator проверяет в основном массив | Справочники и описание; CS применяет отдельные `modifiers`, не универсальный dispatch passive kinds | `damage_reduction`, `immunity`, `triggered_effect` в списке не означают работающий обработчик. Нужны typed payloads/валидация в KB, handlers в ACS |
| `formula_profile` | Seed `af_kb_arpg_mechanics_option_set_definitions()` (KB:2467); `renderServiceRules()` (KB-JS:2384); служебная запись | `entries`: key/title/group/description/calc_family/duration_supported/ui_hint/active; специальный validator и `af_kb_normalize_arpg_mechanics_entry_row_by_service_kind()` | ATF labels; CS-R `…build_formula_profile_applied()` выполняет legacy-арифметику | Нет связи с исполняемой `formula_def`, переменных и версий вычислителя; текст/название не меняет коэффициенты CS |
| `formula_def` | Допустимый service kind, общий редактор entries и raw JSON | Общая service-валидация; нет отдельной схемы expression, inputs, AST, outputs | Миграционная карта/список kinds/общий lookup; вычислитель не найден | Нужны typed schema/ссылки/валидация выражения в KB; безопасное исполнение в ACS. Не исполнять произвольный PHP |
| `status_def` | Общая служебная запись; lookup поддерживает и отдельные записи kind | Только общий service validator; options projection возвращает labels | KB/ATF выбор status_key и рендеринг | Нет обязательных полей применения, иммунитетов, stacking, tick, expire; расширять service schema, `…options` не использовать как полный status payload |
| `modifier_template` | Общий service kind | Общая проверка entries; отдельной арифметической схемы нет | Общие lookup/display, не универсальное применение | Нужны типизированные stat/mode/value/условия/порядок; разделить контракт с origin modifiers |
| `trigger_template` | Общая служебная запись; отдельные записи могут попадать в options | Общий validator; у ability template_ref проверяется синтаксис ссылки | UI пассивов и список триггеров; event loop отсутствует | Нет резолвинга действий, очереди, приоритетов, ограничения рекурсии; KB definition + ACS dispatcher |
| `condition_template` | Общая служебная запись и options | Общий validator; ability template_ref — только синтаксис | Условия сохраняются/показываются, не вычисляются | Нет typed predicate/context/логики AND/OR; KB schema + ACS predicate evaluator |
| `scaling_table` | Допустимый service kind, общий entries/raw | Общая проверка; нет специальной таблицы уровней/границ | Отдельный runtime lookup/interpolation не найден | Формат rows, level/rank и out-of-range должны быть определены в KB; lookup в ACS |
| `combat_template` | Допустимый service kind | Общая проверка; отдельной обязательной структуры боя нет | Отдельного сервиса запуска/расчёта боя не найдено | KB хранит параметры шаблона; ACS создаёт собственную сессию |
| `arpg_ability` | Defaults KB:341; schema KB:932; KB-JS `renderAbilityRules()` | `af_kb_arpg_migrate_legacy_rules_contract()`; `af_kb_validate_arpg_public_entity()` проверяет обязательные тексты, числа, массивы и syntax template_ref | CS читает ability rows/карточки; эффекты в основном форматируются; legacy profiles могут считать предварительное число | Нет глубокой проверки effects/resources/stacking и ссылочной целостности; top-level `formula_profile` не обязательный элемент defaults/schema arpg_ability |
| `arpg_item` | `renderItemRules()`, schema/defaults; та же KB-таблица | kind/rarity/stage, numeric levels, level_min≤level_max, equip_slot для экипируемых, массивы | CS применяет `base_stats`/`modifiers`; Inventory — источник владения/экипировки | `base_damage`, `base_defense`, `resist_profile_text`, `effects`, `passive_effects` не равны автоматической обработке боевого действия. Нужен единый combat item adapter |
| `arpg_bestiary` | `renderBestiaryRules()`, `rules.combat_stats`, `resists`, `weaknesses`, `ability_keys`, loot | Все 13 combat_stats числовые; resist rows damage_type/value; inline ability name или ref; loot поля/qty order | KB display, нормализация встроенных abilities | Нет боевого actor factory. `ability_keys` фактически массив объектов, не только строки; `resists` отличается от CS `resistances`; нужен отдельный адаптер |
| `rules.effects[]` | Старый редактор ability/bestiary и новый редактор character abilities | См. §5; у обычной ability сервер проверяет массив, не полный смысл каждой строки | KB humanize/inline render; CS `…build_effect_lines`; ATF labels | Нет общего серверного effect executor и одинакового round-trip для двух форматов |
| Математические операции | `af_kb_arpg_origin_modifier_operations()` (KB:248): **flat, percent**; `modifier_modes_registry` в mechanic_profile; отдельный options `ability_effect_operation` | Origin validator проверяет stat_key/mode/numeric value; общий registry — просто массив | KB `…apply_origin_variant_modifiers()`; CS `…apply_equipment_rules()` | `percent` здесь численно аддитивен; effect operations add/subtract/set/percent — другой справочник. multiply/override и общий порядок не реализованы |

Общий service editor (KB-JS:2448) для kinds кроме formula_profile/weapon_type показывает key/labels/image/notes/sortorder/is_active. Наличие `formula_def` в select не означает специализированный редактор `expression`. Общий options-нормализатор отбрасывает неизвестные поля; raw lookup может вернуть их, но гарантии их значения/исполнения нет.

## 5. Два контракта effects и ATF

### 5.1. Что реально хранится в анкете

`af_atf_save_values()` (ATF:4314) сохраняет строки в **`af_atf_values(tid, fieldid, value)`**. Поле `character_abilities` содержит JSON-массив объектов; legacy alias — `character_abil`. Отдельной таблицы боевых способностей здесь нет.

`af_atf_normalize_character_abilities_json()` (ATF:4654) принимает массив, `items` или `abilities`, отбрасывает строки без имени/описания/ref/icon, применяет `af_atf_normalize_character_ability_row()` (ATF:4505). Невалидный JSON превращается в `[]`; это не отказ сохранения с диагностикой. Нормализация ограничивает длины, приводит скаляры к строкам, но не доказывает числовую корректность, допустимость отрицательных значений, enum membership или существование ref.

Bridge `af_atf_bridge_extract_oc_abilities()` → `af_atf_bridge_sync_character_kb_from_thread()` (ATF:5294/5588) формирует `af_kb.character.contract.v1` с `character_profile`, `character_abilities`, `character_links`, `character_meta` и записывает KB-проекции. Для канонических персонажей существует отдельная ветка синхронизации; это не повод создавать новую сущность Ability или копию формул в ATF.

| Параметр анкеты | Фактический контракт / нормализация | KB / CharacterSheets | Что требуется ACS |
|---|---|---|---|
| `formula_profile` | Строковый key до 128 символов | KB service options; CS legacy preview выбирает ветку по key/prefix/calc_family | Проверять существование и поддержку профиля; иметь явную связь с KB-правилом |
| `cooldown_value/unit` | value строка до 64; **unit всегда own_turn** | CS подписывает «ходов персонажа»; обычная KB ability использует numeric `cooldown` | Явный адаптер старого cooldown; счётчик и момент уменьшения в ACS |
| `cost_value/resource` | Строки; отдельная пара | KB ability — `resources[{op,resource_key,value,per,duration,notes}]`; CS показывает стоимость | Преобразование без потери нескольких затрат; проверка доступности и списание в сессии |
| `effects[]` | effect_type/value/formula_profile/coefficient/target/damage_type/element/duration_value/duration_unit/status_key/stat_key/operation/resource_key/notes | KB inline normalizer поддерживает этот формат; CS только текст эффекта | Typed effect dispatch и наследование полей; coefficient пока не вычисляется |
| `targeting` / `target` | Aliases записываются одновременно | KB targeting enum/options; CS labels | Проверка допустимых целей/отношений и контекста |
| `damage_type` | Строка, выбирается из physical/elemental/true/healing/hybrid | Старые KB/CS также используют конкретные элементы; новый effect имеет отдельный element | Развести категорию урона и элемент; запретить молчаливое переосмысление legacy |
| `range` | Строка, options melee/short/medium/long/global | Обычная KB ability требует число | Нельзя конвертировать категорию в расстояние без правил |
| ability `duration_value` | Строка, fallback из `duration`; top-level `duration_unit` не возвращается нормализатором | У KB ability numeric duration; карточки могут показывать секунды | Указать единицу старых значений отдельно, не угадывать |
| effect `duration_value/unit` | Строки; **unit всегда target_turn** | KB inline нормализатор делает то же; CS выводит длительность | Определить tick/expiry; не считать принудительную замену безопасной миграцией |

ATF `af_atf_get_character_ability_select_payload()` (3103) получает справочники KB для type/subtype/slot/damage_type/targeting/formula_profile/range/duration/effect_type/operation/resource/status, элементы и статы. `af_atf_character_ability_profile_applied_lines()` (4572) выбирает подпись/значение/описание и длительность; **боевую формулу не вычисляет**. Это корректная роль ATF, её следует сохранить.

### 5.2. Несовместимость старого и нового effects

Старый `renderAbilityRules()` (KB-JS:1862) создаёт `kind`, `damage_type`, `targeting`, `value_mode`, numeric `value`, **`formula_ref`**, numeric `duration`, `hit_count`, `status_key`, `notes`. Есть kinds `barrier` и `proc`, которых нет в новом `ability_effect_type`.

Новый контракт встроенных способностей описан выше. KB `af_kb_normalize_inline_ability_row()` преобразует kind→effect_type, targeting→target, formula_ref→formula_profile, duration→duration_value, затем оставляет фиксированный набор ключей. **`value_mode` и `hit_count` в этом наборе отсутствуют**. Формульная ссылка и профиль семантически не доказаны как одно и то же. ATF-нормализатор не выполняет все те же aliases (например formula_ref/duration эффекта), а также отбрасывает неизвестные поля.

Документ `CHARACTER_ABILITY_EFFECTS_CONTRACT.md` говорит о наследовании пустых profile/target/damage_type от способности. Проверенные нормализаторы оставляют пустые строки, а CS `…build_effect_lines()` только форматирует их. Универсального вычислительного наследования не найдено. Контракт нужно реализовать в будущем KB-адаптере, не добавляя вычислитель в ATF.

Пустой effect в ATF получает `duration_unit=target_turn` **до** проверки непустоты, поэтому может остаться в массиве даже при прочих пустых полях. CS проверяет наличие непустого типа прежде, чем отключить legacy preview; простая проверка `effects !== []` в ACS даст иное поведение.

## 6. Матрица формул и исполнителей

Статусы: **И** — реализовано и исполняется (в указанной области); **Ч** — реализовано частично; **С** — есть только схема/справочник; **О** — отсутствует; **Б** — требует уточнения правил баланса. Б — дополнительный флаг, не замена фактическому статусу. Наличие числа в карточке не означает боевое применение.

| Механика | Определение / источник | Реальное выполнение | Статус | Следующий этап и точка расширения |
|---|---|---|---|---|
| Исходные характеристики | Origin/archetype/variant, KB stat registry; таланты, экипировка | KB `…build_arpg_character_stats`, CS-R `…build_stats_from_kb`, `…apply_equipment_rules` | И для базовых статов | Снимать исходное состояние без боевых записей в CS; provenance в KB normalized adapter |
| Исходный урон | formula_profile и legacy damage_value; item base_damage | CS-R `…build_formula_profile_applied` считает предварительный damage | Ч; Б | KB typed formula/profile contract; ACS action evaluator. Не трактовать ATK или weapon base_damage как готовый damage |
| Физический/элементальный урон | damage_type, element, element_damage_bonus, mastery | CS preview выбирает ATK/EM и общий damage bonus; разделения по защитнику нет | Ч; Б | Уточнить damage category/element в KB; ACS damage pipeline |
| Попадание | Нет отдельного hit/accuracy в ARPG stat registry | Бросок попадания не найден; status_hit относится к статусам | О; Б | Решить, нужна ли механика; затем optional KB stat/rule и ACS RNG |
| Уклонение | Нет отдельного evasion/dodge в реестре | Speed считается/выводится, но не превращается в уклонение | О; Б | Не выводить формулу из speed; правило в KB, исполнение ACS |
| Шанс крита | crit_rate_base → character_crit_rate | Считается исходный stat, нет проверки критического события | Ч; Б | KB пределы/единицы; ACS бросок и обработка multi-hit |
| Критический урон | crit_damage_base, formula_profile damage | CS preview считает отдельное число крит. урона | Ч; Б | ACS применяет по результату критического события; правила в KB |
| Защита | defense_base/per_level, base_defense_bonus, modifiers | Итоговый DEF рассчитывается; перевода DEF в уменьшение урона нет | Ч; Б | KB defense rule/profile; ACS расчёт по защитнику |
| Уменьшение входящего урона | Passive kind damage_reduction | Нет универсального обработчика kind | С; Б | KB typed passive schema; ACS стадия damage reduction |
| Сопротивления/уязвимости | CS resistances/weaknesses; bestiary resists rows | Числовое объединение коллекций; при уроне не применяются | Ч; Б | KB единый resist profile, adapter row/map; ACS damage stage |
| Иммунитеты | immunities/grants, passive immunity, status_def | CS умеет собирать часть immunity keys; боевых запретов нет | Ч; Б | Нормализовать источники/категории в KB; ACS gate до соответствующего эффекта |
| Создание щита | shield_flat/percent, shield_value, shield_strength | CS preview считает прочность, экземпляр щита не создаётся | Ч; Б | KB shield definition; ACS shield instance/state |
| Поглощение щитом | effect kind shield/barrier | Нет вычитания прочности, очередности щитов, переноса остатка | С; Б | KB absorption/stacking policy; исключительно ACS применение |
| Лечение | heal_flat/percent, heal_value, healing_bonus | CS preview считает число; нет изменения HP/cap/overheal | Ч; Б | KB heal rule/received modifiers; ACS применение к цели |
| Шанс наложения статуса | status_hit; status_apply passive; status_key | Базовое значение есть, броска/композиции с целью нет | Ч; Б | KB chance rule и base chance эффекта; ACS roll |
| Сопротивление статусу | status_resist; status_resistance passive | Исходный stat есть, противопоставления шансу нет | Ч; Б | KB units/caps/status scope; ACS resolution |
| Наложение/снятие/стаки статусов | status_def, effects status/dispel/cleanse, stacking | Labels/хранение; жизненного цикла нет | С; Б | KB typed status/stacking rules; ACS status controller |
| Длительность | duration/duration_value, combat_duration_unit | Нормализация и отображение; местами подпись секунд, местами ходы | С; Б | KB явная time domain; ACS tick/expire |
| Периодическое срабатывание | duration, proc, triggers | Нет расписания tick/DoT/HoT; duration не означает периодичность | С; Б | KB period/phase definitions, ACS scheduler |
| Ресурсные затраты | KB resources[]; анкета cost_value/resource | Значения сохраняются и выводятся; расход/отказ действия не найден | С; Б | KB cost adapter и политика оплаты; ACS транзакция боевого действия |
| Кулдауны/заряды | KB cooldown/max_charges; анкета cooldown_value | Хранение/labels, таймеров/проверки готовности нет | С; Б | KB units/recharge rules; ACS counters |
| Плоские модификаторы | origin modifiers, item/talent base_stats/modifiers | KB/CS складывают подходящие stat_key/value | И для исходных статов; Ч для боя | Сохранить существующее сложение; временные модификаторы только ACS |
| Процентные модификаторы | origin modes flat/percent; effect operation percent | Origin/equipment percent тоже складывается как число; универсальной относительной % операции нет | Ч; Б | Не менять legacy mode; новый явный operation/units в KB и ACS |
| Условные модификаторы | condition_text, conditions[], conditional_bonus | CS equipment/talents пропускает строки с condition_text; predicate engine нет | С; Б | KB typed conditions; ACS context evaluator |
| Множительные/override | Нет поддержанного origin operation; общий modifier_modes_registry — массив | Нет универсального multiplier/override pipeline | О для исполнения; Б | KB операция/порядок/совместимость, ACS применение; не переименовывать legacy percent |
| Триггеры/события | trigger_template; triggers[] event/action_text/condition_text | Запись/ссылки/рендеринг; dispatch отсутствует | С; Б | KB machine event/action refs; ACS очередь, приоритеты, ограничения циклов |
| Масштабирование по таблице | scaling_table | Специализированный lookup/интерполяция не найдены | С; Б | KB typed rows/границы; ACS lookup по согласованным правилам |
| Выбор целей/дальность | targeting/range | Options/labels, проверки геометрии/доступности нет | С; Б | KB category/unit contract; ACS target validation |
| Инициализация NPC | arpg_bestiary combat_stats/resists/ability_keys | Хранение/валидация/отображение; боевой actor не создаётся | С | KB bestiary normalized resolver; ACS actor factory |
| `formula_def.expression` | Только допустимый service kind, произвольный raw JSON | Не найдены tokenizer/parser/AST/allowlist evaluator и вызов expression | О для исполнения | KB безопасная типизированная спецификация; отдельный ограниченный исполнитель в ACS |

### 6.1. Точные существующие формулы preview (не новые правила)

Источник: CS-R `af_charactersheets_arpg_build_formula_profile_applied()` (706–822). Они приводятся как факт реализации, **не как рекомендация баланса ACS**.

Обозначения: `V` — legacy damage/heal/shield value; `S` — scaling stat; `EM` — mastery; `B` — element_damage_bonus; `C` — crit_dmg; `H` — healing_bonus; `Q` — shield_strength.

| Ветка | Реально исполняемое выражение |
|---|---|
| damage_percent | base = S × V / 100 |
| damage_hybrid_flat | base = V + S × 0.35 |
| damage_hybrid_percent | base = S × V / 100 + EM × 0.25 |
| Остальной damage_* или calc_family=damage | base = V + S × 0.2 |
| Общий damage bonus | result = base × (1 + B/100) |
| Критический preview, если C>0 | crit = result × (1 + C/100) |
| shield_percent | base = P × V / 100; P = HP>0 ? HP : (DEF>0 ? DEF : ATK) |
| Остальная shield ветка | base = V + P × 0.4; result = base × (1 + Q/100) |
| shield_percent итог | result = base × (1 + Q/100) |
| heal_percent | base = T × V / 100; T = ATK>0 ? ATK : HP |
| Остальная heal ветка | base = V + T × 0.25; result = base × (1 + H/100) |
| heal_percent итог | result = base × (1 + H/100) |

Выводимые damage/heal/shield числа округляются `round`, ограничиваются снизу нулём; крит считается от результата до округления. `S=EM` для damage_type anemo/geo/dendro или profile key, содержащего mastery; иначе ATK, включая physical. **Общий element_damage_bonus применяется и к physical preview**; это факт текущей ветки, не согласованное правило разделения типов урона.

Buff/debuff/passive/mobility/control/utility ветки в основном форматируют заданные значения; control_fixed подписывает длительность секундами. `af_charactersheets_arpg_parse_scalar_value()` удаляет посторонние символы, а не разбирает математическое выражение (например, строка с разделителями может стать другим числом). Это не безопасный formula evaluator.

`af_charactersheets_arpg_normalize_ability_card()` (CS-R:825) отключает legacy profile arithmetic, если есть effect с непустым effect_type/kind. Новые effects выводятся строками через `…build_effect_lines()`, не считаются той же формулой. Ни коэффициент эффекта, ни hit_count, ни защита/сопротивление цели здесь не исполняются.

Поиск `expression`, formula_def и функций формул в PHP KB/CS/ATF, а также ссылок service kinds в PHP аддонов не выявил исполнителя `formula_def.expression`. Найденные `eval` относятся к шаблонам MyBB; они не связаны с вычислением формульного поля и не являются допустимым способом его исполнения.

## 7. CharacterSheets: исходное состояние и граница повторного использования

### 7.1. Расчёт ARPG

`af_kb_build_arpg_character_stats()` (KB:10746) собирает origin, проверенный по parent variant и archetype. Для HP/DEF/ATK/mastery использует сумму base плюс сумму per_level × `max(0, level−1)`. Остальные базовые показатели суммируются без роста; speed берётся из movement_speed origin/variant. Archetype base_damage_bonus прибавляется к ATK, base_defense_bonus — к DEF. Затем применяются variant modifiers и manualStats по aliases. Дополнительно существует character_healing_received_bonus, отсутствующий в основном 13-статовом реестре: его нельзя автоматически считать полноценно стандартизованным combat stat.

`af_charactersheets_arpg_build_stats_from_kb()` (CS-R:1232) повторяет базовую композицию, использует KB-helper variant modifiers и опционально equipment sources. В VM сначала получается character payload, затем calculated KB stats объединяются `af_charactersheets_arpg_merge_runtime_stats()` (CS-R:955): отсутствующее/null/пустое/числовой ноль заменяется computed, ненулевое значение сохраняется. Это не чистая сумма всех источников и делает ноль особым значением.

После merge отдельно применяются live equipment и активные talent sources, чтобы ненулевой payload не перекрыл предметы. `af_charactersheets_arpg_apply_equipment_rules()` (CS-R:1171) обрабатывает **base_stats и modifiers**, принимает flat/percent и пропускает непустой condition_text. Оба режима численно аддитивны. Прямые item base_damage/base_defense не превращаются этой функцией в ATK/DEF; passive_effects не исполняются этим циклом. Вызов variant helper сам по себе не проверяет condition_text — нельзя использовать его как общий evaluator условных модификаторов.

Таланты: `…arpg_talent_state()` проверяет владение по `af_advinv_items` (`entity=abilities`, `subtype=talent`, `kb_type=arpg_talent`, qty>0), активные ключи берёт из `build.talents.slots`; `…collect_talent_rule_sources()` получает KB rules и применяет их тем же статическим путём. Сам факт наличия passive_effects/grants не означает изменение всех заявленных характеристик.

Экипировка: `…arpg_live_equipment_state()` вызывает **INV `af_advinv_export_charactersheet_equipment_state()` (3543)**. Это установленная зависимость, поэтому проверен только её экспорт. CS rule-source collector получает реально надетые предметы; допускает один weapon source. Источником экипировки в этом main является live Inventory, а не только `build_json.equipment.slots`, как можно заключить из старого аудита. Отключённый INV/uid≤0 даёт пустое состояние.

Уровень sheet-view получается `af_charactersheets_compute_level($exp)` (CS-C:1144), опыт может приходить из `af_balance_get`; KB-character builder использует profile.character_level/level. Эти входы не следует считать автоматически одинаковыми: снимок ACS должен фиксировать уже выбранное итоговое значение.

### 7.2. Коллекции и разбивка источников

`af_charactersheets_arpg_merge_rule_collections()` (CS-R:1196) складывает числовые maps `resistances`, `weaknesses`, `resources`; объединяет `immunities`, `abilities`, `skills`, `proficiencies` по key; дописывает grants. VM возвращает `resources`, `resistances`, `rule_collections`, `arpg_sources`, `arpg_stats_trace` (CS-R:1670 и далее).

Сопротивления можно читать из VM.resistances, уязвимости/иммунитеты — из VM.rule_collections. Однако это не полная гарантия всех источников: в начальную ARPG merge-коллекцию передаются resources/resistances из sheet_view, **не initial immunities/weaknesses**, и grants просто добавляются, не преобразуются там в immunity/resistance. Bestiary `resists[{damage_type,value}]` нельзя передавать этому map merger напрямую.

CS-C `apply_kb_rules_to_character_state()` (57) в общем/DnD-пути действительно агрегирует resistance/weakness/immunity и некоторые grants с `op`, сохраняет source tags/debug_trace. `active_effects` и `modifiers` в нём — накопленные payloads, не исполняемые боевые эффекты. CS-B `cs_kb_rules_normalize()` (2596) — преимущественно DnD/fixed schema, не универсальная ARPG нормализация.

**Итог с частичной трассировкой получить можно**, через ARPG VM и общий sheet_view (`rules_debug`, `rules_engine_state.debug_trace`, sources), но единого числового ledger «каждый stat → каждое слагаемое → источник → операция → порядок» нет. ARPG trace содержит raw origin/archetype/equipment и totals, но не полный talent/variant пооперационный вклад. Это важно при проверке двойного применения базовых и временных бонусов.

### 7.3. Что переиспользовать без изменения CharacterSheets

| Функция / данные | Допустимое использование ACS | Ограничение |
|---|---|---|
| `af_kb_arpg_character_stat_registry`, `…origin_modifier_stat_definitions` | Карта ключей и VM fields | Не вводит боевые единицы/пределы |
| `af_kb_extract_character_contract`, `…build_arpg_character_stats` | Чтение и базовая композиция KB-character | Не включает live equipment/talents сама по себе |
| CS-R `…arpg_extract_entry_rules`, `…arpg_build_stats_from_kb`, `…apply_equipment_rules` | Переиспользование существующей статической арифметики на исходных массивах | Не применять второй раз поверх уже рассчитанного VM; сохранить legacy семантику percent |
| CS-R collectors equipment/talents | Определить актуальные источники на старте боя | Требуют uid/Inventory и выбранных слотов; не дергать для каждого удара |
| CS-R `af_charactersheets_build_arpg_view_model` | Получить согласованные с карточкой итоговые значения/коллекции | Это UI-oriented сборщик с HTML/labels и смешанным precedence, не чистый ACS DTO |
| `cs_build_character_state`, `af_charactersheets_compute_sheet_view` | Существующая точка получения sheet_view | **Не гарантированно read-only**; нет полной ARPG VM на выходе; не использовать как бесконтекстный combat evaluator |
| CS-C `apply_kb_rules_to_character_state` | Источники/trace общего state | Не превращать DnD fixed-агрегатор в ARPG боевой resolver |
| CS-R `…arpg_final_luck` | Получение итоговой luck | Не определяет loot/combat roll |

Критичный побочный эффект: `af_charactersheets_compute_sheet_view()` (CS-C:844) при sheet_id>0 вызывает `af_charactersheets_sync_fixed_skills()`. CS-S:552 и далее до строки 652 вызывает `af_charactersheets_upsert_sheet_skill()`. Комментарий в `cs_build_character_state()` о read-only не гарантирует отсутствие записи по цепочке. Кроме того, переданный uid не даёт самостоятельного отказа доступа: проверку прав обязан выполнить вызывающий слой.

Для независимости ACS исходный снимок статов/способностей/источников должен создаваться на границе входа в бой и далее принадлежать ACS. Текущие HP, временные щиты, статусы, затраты и cooldown не должны записываться ни в sheet build/progress/skills, ни в KB-character, ни в анкету. Отдельный адаптер ACS может использовать имеющиеся чистые helpers без изменения расчёта CharacterSheets; вызов полного compute-sheet пути внутри боевого цикла неприемлем.

## 8. Карты зависимостей

### 8.1. KB → CharacterSheets

```text
KB type schemas/defaults + kb_edit
    → af_kb_entries[data_json, meta_json.rules]
    → KB character contract/extractors
    → character_source.payload[profile, stats, abilities]
    → CS-R build_arpg_view_model
         ← origin/variant/archetype rules из KB
         ← live equipment из INV export → item KB rules
         ← build.talents.slots + INV ownership → talent KB rules
         → merge base stats → equipment/talent modifiers → collections
         → итоговые статы, trace, подписи и preview карточек
```

Параллельный общий путь: CS-B resolve KB sources/normalize → CS-C apply_kb_rules_to_character_state → character_computed_state/rules_debug. Он не заменяет ARPG VM. Будущий ACS читает подготовленный исходный снимок; обратной стрелки от боевой сессии к CS нет.

### 8.2. KB ↔ ATF → CharacterSheets

```text
KB mechanics options/stat registry/element entries
    → ATF character ability editor (выбор ключей + параметры)
    → ATF normalize_character_abilities_json
    → af_atf_values.value (JSON)
    → bridge normalize/extract
    → KB character contract.character_abilities[]
    → KB inline ability normalizer / character extraction
    → CS cards: structured effects как текст ИЛИ legacy profile preview
```

ATF владеет параметрами анкеты и синхронизацией, KB — значением выбранных ключей/правилами, CS — исходными статами и их представлением. `ability_kb_key` — ссылка, но в этой цепочке нельзя предполагать универсальный deep merge с arpg_ability и автоматическое разрешение всех ссылок: встроенные индивидуальные rows остаются самостоятельными параметрами character contract.

## 9. Подтверждённые пробелы и минимальные расширения

Предложения ниже — спецификация следующего этапа, не выполненные изменения и не утверждение новых игровых правил. Все новые контракты должны быть опциональными/версионированными; legacy читается с прежней семантикой.

| ID | Подтверждённый пробел | Минимальная точка расширения / владелец |
|---|---|---|
| G1 | Нет полного normalized layer, разный precedence JSON | KB: целевые helpers из ARPG_INTEGRATION_CONTRACT §4; один read adapter с diagnostics/source/schema/version, правила приоритета без автоматического переписывания записей |
| G2 | Нет typed formula_def.expression и безопасного исполнителя | KB `af_kb_default_arpg_type_definitions`, `…validate_arpg_service_entity`, `…normalize_arpg_mechanics_entry_row_by_service_kind`: version, inputs/output/unit, допустимая структура выражения/ссылки. ACS — ограниченный детерминированный evaluator с лимитами, finite/domain checks; не eval |
| G3 | Профиль не связан с исполняемой формулой; арифметика в CS | KB formula_profile: optional явная ссылка на definition/revision и поддерживаемые параметры. Существующий CS preview не менять в этой задаче; вопрос его будущего согласования вынести отдельно |
| G4 | Служебные kinds проверяются почти одинаково | KB validator/schema: отдельные поля для status/modifier/trigger/condition/scaling/combat; lookup полного payload отдельно от options labels |
| G5 | Два effects-контракта и потери value_mode/hit_count/ref | KB inline normalizer + новый normalized adapter: сохранить original/unknown legacy, отдельно typed normalized effect; discriminated kinds, aliases без смешения formula ref/profile; numeric/enum/ref diagnostics |
| G6 | Percent не имеет универсальной семантики, multiply/override отсутствуют | KB registry/schema: дополнительные явные operation/unit/phase/order, не переопределять flat/percent origin. ACS исполняет только объявленные поддержанные операции |
| G7 | Стоимость/кулдаун/range/duration представлены неодинаково | KB costs/cooldown/targeting/time descriptors и adapter ATF→KB; ATF хранит выбранные параметры, не решает правила и не считает бой |
| G8 | Нет полного механического status/trigger/condition runtime | KB определения с machine refs вместо action_text; ACS lifecycle/event queue/predicate engine; решения §11 обязательны до исполнения |
| G9 | Resistance maps, bestiary rows и immunity grants не унифицированы | KB `…resolve_bestiary_payload`, normalized resists/immunities/weaknesses, явные category/element/status scopes; CS арифметику не менять |
| G10 | Нет единого provenance ledger / версии исходного набора | KB normalized source metadata; ACS snapshot фиксирует character/sheet/level/items/talents/definitions/revisions и итоговые значения. CS trace использовать как диагностику, не доказательство всех слагаемых |
| G11 | Полный CS build path может писать skills | ACS входной адаптер использует готовый снимок/чистые helpers; не вызывает compute-sheet в бою. Не менять CS и не передавать session state в CS |
| G12 | Нет actor/damage/heal/shield/resource/cooldown resolution | Исключительно будущий ACS: actor state, action validation, damage stages, target HP, shield instances, status timers, costs и cooldown. KB хранит определения/утверждённые параметры |

Перед включением ACS неизвестное поле/неразрешённая ссылка/неподдержанный operation должны давать диагностируемую неподдержанную механику, а не подставленную «похожую» формулу. Нельзя подключить новый произвольный профиль к damage-family и принять старый default coefficient как утверждённое правило.

## 10. Риски миграции

1. **Две JSON-проекции.** При рассинхронизации data_json и meta_json.rules CS и KB consumer могут читать разные определения. Нужен отчёт конфликтов и явная политика выбора, не массовая пересборка на чтении.
2. **Неполный round-trip.** Inline KB и ATF оставляют whitelist полей; при сохранении legacy hit_count/value_mode/refs/unknown extensions могут исчезнуть. Вложенные массивы при recursive defaults также требуют осторожности. Сначала инвентаризация живых данных, затем отдельная контролируемая миграция.
3. **Необратимое изменение единиц.** Собственные/целевые ходы принудительно заменяют сохранённые units; round в options не означает round support нормализатора. Top-level duration_unit теряется; секунды preview нельзя объявить ходами.
4. **Переосмысление percent.** Сейчас `atk=100`, modifier `{mode:percent,value:20}` прибавляет 20, а не запускает общий относительный процентный pipeline. Изменение этой семантики меняет существующие листы; вводить отдельный opt-in контракт.
5. **Structured effects выключают preview.** Перенос legacy scalar в effects может убрать ранее вычисляемое число, хотя сами effects ещё не исполняются. Нельзя использовать этот переход как доказательство эквивалентности формул.
6. **Ноль как отсутствие.** CS merge заменяет нулевой payload computed значением; KB manualStats трактуются как добавки. Итоговый ненулевой payload нельзя повторно прибавлять как base. Снимок должен фиксировать выбранный путь вычисления.
7. **Изменяемые справочники.** Фallback seed при отсутствии набора и редактируемые DB-entries могут различаться. Без revision snapshot бой изменит результат после правки KB; отключённая запись не равна отсутствию определения.
8. **Изменяемая экипировка/таланты.** Live Inventory и ownership могут измениться после старта боя. Повторный live lookup вместо snapshot меняет базу посреди сессии; двойная агрегация начислит бонусы повторно.
9. **Свободные строки и числовые приведения.** ATF допускает строковые значения, KB местами приводит float, preview чистит символы. Перенос в строгий numeric contract выявит записи, которые раньше только отображались; их нельзя молча «исправлять».
10. **Смешение элементов и damage_type.** Старые elemental keys и новые physical/elemental/true/healing/hybrid не взаимозаменяемы; legacy barrier/proc и новые kinds требуют явного mapping либо ручного решения.
11. **Иммунитеты/сопротивления.** Numeric maps, object rows, grants и пассивы — разные структуры. Нельзя считать отсутствие в одном VM-блоке отсутствием иммунитета вообще или преобразовать объектную строку в число.
12. **Ложная read-only граница.** Вызов полного CS builder может синхронизировать навыки. Боевой обработчик должен исключать этот путь и самостоятельно проверять доступ к actor/sheet.

## 11. Вопросы баланса и архитектуры, не решённые автоматически

До реализации соответствующих стадий ACS нужны решения владельца правил:

- Нужны ли попадание/уклонение; какие статы ими управляют и при чём здесь speed/luck?
- Считать ли legacy коэффициенты CS официальным балансом; как отличать flat damage от «flat + scaling», откуда брать базу оружия?
- Какие категории урона и элементы допустимы, есть ли elemental reactions/mastery mechanics, что игнорирует true damage?
- Как DEF уменьшает урон; в каком порядке действуют защита, damage_reduction, резисты, уязвимости, щиты и округление?
- В чём единицы crit_rate/status_hit/status_resist/resistance: доли, процентные пункты или иной рейтинг; какие caps и как сочетаются источники?
- Как определяется crit damage, может ли быть отрицательным, что означает нулевой crit_dmg; бросок на действие, цель или каждый hit?
- Каковы base status chance, immunity scopes и связь с status_hit/resist; может ли статус накладываться при нулевом уроне?
- Как работают щиты: приоритет/стак/обновление, общий или отдельный пул по типам, истечение, перенос остатка, взаимодействие с true damage?
- Каковы правила overheal, receiving-heal bonus, antiheal и допустимость критического лечения/щитов?
- Каковы clock/phase для own_turn/target_turn/round/seconds, периодичность DoT/HoT, момент первого tick и expiry, обновление длительности?
- Когда оплачивается действие, что при промахе/отмене/недостатке ресурса, возможны ли несколько затрат, возврат/регенерация?
- Когда начинается и уменьшается cooldown; как восстанавливаются заряды и меняются cooldown от эффектов?
- Как трактовать percent для обычных статов, какие операции нужны сверх legacy, каковы порядок и совместимость flat/additive-percent/multiply/override?
- Как исполняются conditions/triggers: snapshot или актуальные статы, приоритет, повторные события, self-trigger, лимиты цепочки?
- Как переводить категории дальности анкеты в дистанцию/геометрию и как задавать доступные цели?
- Какие версии определений фиксирует бой и как обрабатывает отключённые/удалённые legacy определения?

Эти вопросы блокируют утверждение боевых правил, но не завершение аналитического аудита. Ни одно решение не подставлено автоматически.

## 12. Выполненные проверки и границы результата

Выполнены: проверка branch/commit/status, поиск существующих контрактов и точечных диапазонов функций; чтение PHP validators/normalizers/save handlers/consumer call chains; проверка JS-схем effects и служебных редакторов; поиск определений и вызовов formula/expression/service kinds; установленная зависимость от Inventory проверена только в границе CS export/ownership. Проверены фактические записи нормализаторов единиц, arithmetic branches и побочный вызов sync_fixed_skills.

После подготовки проверены git status (единственный новый файл — `docs/ACS_KB_MECHANICS_AUDIT.md`) и whitespace через `git diff --check` и `git diff --no-index --check /dev/null docs/ACS_KB_MECHANICS_AUDIT.md`. Runtime-изменений нет. Тесты, браузер, нагрузка, миграции БД, запуск PHP/MyBB не выполняются. Синтаксическая/динамическая совместимость PHP 8.5 и наличие всех определений на warprift.ru этим статическим аудитом не подтверждаются.

Итоговая граница: KB — определения, справочники, строгая нормализация и версии; CharacterSheets — существующий расчёт исходных характеристик без боевого изменения листа; ATF — параметры и ключи анкеты; ACS — независимое состояние сессии и исполнение утверждённых правил. Строки матрицы §6 и G1–G12 задают конкретные входы следующего этапа.
