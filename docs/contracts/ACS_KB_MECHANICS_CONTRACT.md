# KB и ATF: механические определения для ACS, B3

## Назначение и совместимость

B3 расширяет API `af_kb_arpg_normalize_entry()` / `af_kb_arpg_read_definition()` из B1 и математические определения B2. Отдельного нормализатора способностей, Combat Resolver, обработчика статусов или очереди событий нет. Все результаты остаются `executable=false`.

KB хранит определения; ATF хранит параметры анкеты и переносит их в существующий character contract; CharacterSheets считает исходный лист и прежние previews; будущий ACS создаёт собственное состояние и исполняет утверждённые правила. HP/кулдауны/статусы сессии, транзакции ресурсов/инвентаря, владение предметами и экземпляры монстров здесь не создаются.

Сохраняются правила источников B1: целая data_json-проекция перед meta_json.rules, диагностика конфликтов, отсутствие смешивания конкурирующих полей, raw и детерминированные fingerprints. Никаких массовых миграций, новых таблиц или записей по умолчанию. Коэффициенты формул и код CharacterSheets не изменены.

## Доступ к данным

```php
$definition = af_kb_arpg_read_definition('arpg_mechanics', 'status_def');
$ability = af_kb_arpg_read_definition('arpg_ability', 'example_ability');
$personalAbility = af_kb_arpg_read_definition('character', 'example_character', 0);
$item = af_kb_arpg_read_definition('arpg_item', 'example_item');
$monster = af_kb_arpg_read_definition('arpg_bestiary', 'example_monster');
```

Индекс встроенной способности по-прежнему нулевой и не равен slot_index. Авторизация вызывающего серверного кода остаётся его обязанностью. Новый API ничего не записывает в исходный персонаж/анкету/предмет.

## Типизированные служебные определения

Общий opt-in marker строки `rules.entries[]`: **`schema=af_kb.arpg.mechanic.v1`**, обязательные key и version. Служебная KB-запись имеет `type=arpg_mechanics`, `rules.service_kind` из таблицы ниже. Существующий envelope/service.visibility остаётся прежним. Новые строки — список не более 128 элементов, ключи уникальны внутри набора.

Без marker старые entries не получают новых игровых полей. Их можно сохранять и редактировать; ACS получает `mechanic_contract_missing`, а исходное определение остаётся доступным. Присутствие текстового описания не считается исполняемым правилом. Старые description/notes/labels и неизвестные расширения не вырезаются новым JSON-редактором или новым read API.

`af_kb_arpg_mechanics_contract()` публикует виды, поля и общие структуры. `af_kb_arpg_service_mechanics_errors()` проверяет typed service entry; `af_kb_arpg_public_mechanics_errors()` проверяет только явно предоставленные новые публичные поля. Проверки подключены к существующему пути сохранения и B1 API; это валидаторы данных, не интерпретаторы.

### Общие структуры

| Структура | Поля и проверка |
|---|---|
| time | `{value,unit}`: конечное неотрицательное JSON-число и явная unit |
| counter | `{event,amount}`: явное событие уменьшения и конечная неотрицательная величина |
| condition_ref | `condition_template:<key>`; null допустим только там, где это явно разрешено схемой |
| formula_ref | `{key,version}`: ключ formula_def и точная строка версии B2 |
| effect_ref | `{type,key,effect_index}`: type arpg_ability/arpg_item/arpg_bestiary, индекс исходного корневого effects[] ≥0 |
| counts | Объект именованных неотрицательных JSON integer ограничений; имена не исполняются KB |
| scope | `{scope,key}`: явные токены области и идентификатора иммунитета |
| resistance | `{scope,unit,value}`; value конечное число любого знака; необязательные damage_type/element/status_key сохраняются отдельно |
| periodic | `{effect:effect_ref,period:time,phase:token}` |

Токены — непустые идентификаторы длиной до 64 символов, начинаются с буквы, допускают цифры/`_`/`.`/`/`/`-`. Key/version служебной строки допускают также начальную цифру и не генерируются из времени. Прикладной смысл токена phase/type/repeat_rule/limit не выбирается KB автоматически. Списки ограничены 128 строками, вложенные структурные проверки — глубиной 12; условия имеют отдельное ограничение ветвления.

### Status definition

Обязательные поля typed `status_def`:

- type — идентификатор вида статуса;
- duration — time; phase — явная фаза срабатывания;
- decrement — counter, отдельно от duration и periodic period;
- repeat_rule — явный идентификатор правила повторного наложения;
- max_stacks — положительный JSON integer;
- removal_conditions и expiry_conditions — списки condition_ref;
- immunities — список scope, resistances — список resistance;
- periodic_effects — список periodic, formula_refs — список formula_ref;
- events — объект событий со списками effect_ref.

Поддерживаемые имена событий: `on_apply`, `on_turn_start`, `on_turn_end`, `on_round_end`, `on_expire`. В events можно задать только нужные события; отсутствие конкретного события не создаёт обработчик. Counter.event использует тот же словарь. Никаких таймеров, стаков, проверок сопротивления или снятия статуса при чтении не происходит.

Пустые списки в новом typed definition вводятся автором явно. Они не подставляются в старые определения. Значение repeat_rule и порядок его применения должны быть поддержаны будущим ACS; валидный токен не доказывает, что игровой алгоритм уже утверждён.

### Trigger, condition, modifier, scaling и combat templates

| service_kind | Обязательные typed поля после schema/key/version |
|---|---|
| trigger_template | event (идентификатор), condition_ref (ссылка или null), effect_ref, priority (integer), limits (counts), formula_refs |
| condition_template | predicate по грамматике ниже |
| modifier_template | modifier — вложенный контракт `af_kb.arpg.math-modifier.v1` из B2 |
| scaling_table | input, unit, rows, interpolation, out_of_range, formula_refs |
| combat_template | phases (список токенов), limits, formula_refs |
| resource_def | resource_key, unit; min_value/max_value необязательны и проверяются как в B2 |

Для всех этих типов дополнительно можно явно задать condition_ref, effect_ref, priority, limits, formula_refs, если они не обязательны в конкретном виде. Их структура валидируется, ссылки передаются в normalized references. Notes/description остаются описанием, не кодом действия.

Predicate — JSON-объект одного из видов:

```json
{"operator":"lte","input":"actor.hp","value":0}
{"operator":"all","conditions":[{"operator":"eq","input":"actor.kind","value":"example"}]}
```

Сравнения: eq/neq/lt/lte/gt/gte, input — именованный путь-токен, value — конечное число, boolean или строка. Комбинаторы: all/any с 1–8 дочерними условиями; not ровно с одним. Посторонние поля узла и неизвестные операторы отклоняются. Код, вызовы функций и доступ к реальному actor не исполняются; разрешение контекста — задача ACS.

Scaling rows — непустой список `{at,value}` с конечными JSON-числами и строго возрастающим at. Interpolation задаётся явно как none/step/linear, out_of_range — error/clamp. KB проверяет структуру и порядок строк, но не интерполирует и не выбирает коэффициенты.

Combat template хранит именованные фазы, ограничения и зависимости формул. Сам по себе список phases не запускает бой, не задаёт автоматически универсальный порядок всех модификаторов и не создаёт участников.

Существующий B2 modifier_template с marker `af_kb.arpg.math-modifier.v1` остаётся допустимым без упаковки B3. Старые flat/percent не меняются, B2 math_modifiers не попадает в legacy modifiers/base_stats. Вложенные opt-in math_modifiers, отвергавшиеся B2, B3 автоматически не включает.

## Публичные способности KB

Поля formula_profile/effects/targeting/damage_type/range/resources/cooldown/max_charges/duration сохраняются. Новые поля необязательны:

| Поле | Содержимое |
|---|---|
| action_cost | Неотрицательное конечное число; для встроенного ATF-формата также числовая строка. Отсутствие не равно 0 |
| target_count | `{min,max}`: integer, 0≤min≤max |
| application_conditions | Список condition_ref |
| application_mode | Явный токен режима, без интерпретации |
| summon | `{bestiary_key,count}` с положительным count; необязательная duration:time |
| usage_limits | counts |
| timing | Явно заданные cooldown/recharge/period:time и decrement:counter |
| costs | Список затрат по общему контракту ниже |
| formula_refs | Версионированные зависимости B2 |

Action_cost не добавляется старым записям. Пустое поле редактора означает отсутствие, а 0 сохраняется как явно указанное значение. На этом этапе не навязывается целочисленная стоимость AP: допустимость дробных очков действий требует отдельного решения баланса. Списания AP нет.

В standalone KB editor доступны отдельные необязательные поля action_cost, formula_profile, единиц legacy cooldown/duration и JSON-поля остальных расширений. Старые параметры не заменяются новым timing. API сохраняет `legacy_timing` отдельно: он не утверждает эквивалентность старых счётчиков новым.

## ATF и исправленные round-trip пути

Исправлены только обработка и редакторы способностей. ATF по-прежнему использует af_atf_values и существующий мост character contract; схема таблиц и маршруты синхронизации не изменены.

Сохраняются `formula_profile`, `cooldown_value`, `cooldown_unit`, `cost_value`, `cost_resource`, `effects[]`, `duration_value`, **`duration_unit` и `action_cost`**. Серверная post-validation отклоняет отрицательный/нечисловой/бесконечный action_cost; отсутствие и пустое поле допустимы. Нормализация не назначает AP автоматически.

Подтверждённые аудитом потери устранены точечно:

- ATF PHP/JS и KB PHP/JS больше не заменяют каждый cooldown_unit на own_turn и каждый effect.duration_unit на target_turn;
- явно сохранённая unit, в том числе неизвестная legacy-строка, сохраняется; неизвестное значение не преобразуется в другую единицу;
- при отсутствии unit она не синтезируется даже при сохранении формы по другому поводу;
- верхнеуровневая duration_unit и action_cost проходят ATF → character_abilities → KB inline projection;
- effect formula_ref не превращается в formula_profile; value_mode/hit_count/formula_ref и дополнительные поля эффекта сохраняются;
- нормализаторы KB/ATF для эффектов больше не обрезают эти поля whitelist-проекцией; aliases kind/targeting/duration добавляются без удаления исходника;
- строка эффекта из одной unit не становится самостоятельным эффектом.

Это исправления конкретных ошибок совместимости из аудита, а не замена API B1/B2. Старые JSON в БД не переписываются при чтении. Поля общего редактора KB и ATF позволяют явно выбрать/изменить unit. Карточка ATF показывает action_cost только при наличии; CharacterSheets не изменён и его старые подписи времени могут не отражать новые единицы — его renderer нельзя считать боевым DTO.

## Время и стоимость ресурсов

Новый typed time использует только подтверждённые машинные ключи **own_turn/target_turn/round**. Секунды встречались как подписи legacy previews, но подтверждённого общего машинного ключа секунд нет. Поэтому seconds/legacy_seconds и другие старые строки сохраняются в legacy-данных, диагностируются B1 как неподдержанные и не конвертируются в ходы.

Раздельны: duration эффекта, cooldown, recharge заряда, period срабатывания и counter/decrement. Никаких эквивалентностей между этими величинами нет. При наличии старого cooldown/duration без unit API не угадывает её; author должен явно заполнить единицу или подготовить согласованное механическое определение. Max_charges остаётся определением количества, не состоянием оставшихся зарядов.

Typed cost:

```json
{"kind":"resource","resource_key":"energy","value":0,"timing":"use"}
{"kind":"resource","resource_key":"energy","value":1,"timing":"sustain","period":{"value":1,"unit":"own_turn"}}
{"kind":"item","item_key":"example_consumable","value":1,"timing":"use"}
```

Cost.kind — resource/item; timing — use/sustain. Value — конечное неотрицательное число, для item — JSON integer количества. Sustain требует явного period; use не получает период автоматически. Resource key разрешается как resource_def, item_key — как arpg_item. Списки затрат не агрегируются; несколько затрат остаются отдельными строками.

Для совместимости новый normalized.costs может содержать legacy-проекцию cost_value/cost_resource либо resources[] с op=spend. Сохраняются исходный source, raw и legacy_per, **без подстановки timing**; предупреждение legacy_cost_timing_unspecified сообщает о необходимости явного решения. Другие op из resources остаются исходными данными, а не расходами. При одновременном наличии нескольких источников costs API выдаёт cost_source_conflict и ничего не смешивает. Явный ноль не теряется. Никаких проверок доступности/оплаты/возвратов или инвентарных транзакций здесь нет.

## Предметы и бестиарий

У arpg_item сохраняются слоты, base_stats, modifiers/effects/passive_effects/grants и B2 math_modifiers. Дополнительные необязательные поля:

- equip_requirements — condition_ref[];
- slot_restrictions — `{allowed:[tokens],blocked:[tokens]}`;
- on_equip / on_unequip — списки обычных inline effects, проходящие **существующий effect adapter B1**;
- costs — общая стоимость использования; consumption_type — явный токен расходования;
- use_ability_ref — key arpg_ability; formula_refs — зависимости B2.

Проверяется структура новых полей и эффектов. Результат содержит подготовленные эффекты и ссылки, а не их применение. Операции над AdvancedInventory, глобальное владение, экипировка или её боевой снимок не меняются.

У arpg_bestiary B1 по-прежнему выдаёт combat_stats/resists/weaknesses/ability_keys/immunities; B3 дополнительно выдаёт normalized.effects через тот же B1 adapter. Объекты ability_keys сохраняют форму объектов и новые поля вроде action_cost/units. Типизированные immunity scopes не выводятся автоматически из старых строк иммунитетов или значения сопротивления; числовая семантика resists/weaknesses не придумывается. Actor factory и группы монстров отсутствуют.

## Редакторы, ссылки, диагностика и версии

Служебные редакторы затронутых видов используют компактный entries JSON editor; сведения о новых полях — в таблицах этого документа и PHP UI schema metadata. Старые текстовые entries загружаются без пересборки. Новый marker/key/version вводится автором явно. Для переключения service_kind может понадобиться сохранить вид и открыть форму снова, как в существующем редакторе B2. Общего редизайна нет.

Внешняя форма нормализованного ответа B1 не меняется. Дополнительные данные:

- normalized.mechanics_definitions — typed snapshots с исходным definition, version/fingerprint, references и executable=false;
- новые явные публичные поля, normalized.costs и legacy_timing;
- mechanic_references для условий/формул/затрат/призыва/используемой способности;
- вложенная способность персонажа/монстра имеет те же расширения внутри ability_profile/ability DTO; raw исходника остаётся доступным.

Typed ссылки читаются через lookup/source selector B1; formula links используют snapshot и pinned version B2. Effect_ref проверяет существование записи и эффекта, подготавливает эффект общим B1 adapter и сохраняет fingerprint. Прямые зависимости возвращаются отдельно; **граф не раскрывается рекурсивно**. Например, ссылка на status_def возвращает его definition, но не исполняет и не разворачивает по кругу on_apply. ACS должен собрать нужный граф, проверить поддержку правил и сохранить собственные snapshots до исполнения.

Новые коды диагностики: invalid_mechanic_contract, mechanic_contract_missing, invalid_mechanic_field, effect_reference_unresolved, cost_source_conflict; предупреждение legacy_cost_timing_unspecified. Сохраняются source_conflict, reference_unresolved, unsupported_duration_unit, duration_unit_missing и остальные ошибки B1/B2. Ошибки обязательных полей имеют path/detail. Наличие raw/definition не равно supported=true; supported=true не равно исполняемости или одобрению игровых правил.

Явная version typed entry и fingerprint содержимого позволяют ACS фиксировать точное определение. Полная история ревизий не создаётся. Старым строкам version не придумывается; изменение актуальной записи не должно менять уже рассчитанные события — ACS обязан использовать собственный снимок, а не свежий KB lookup.

## Проверки и оставшиеся решения

Выполняются lint PHP 8.5 и синтаксис JS; точечные серверные save/read/normalization проверки всех семи служебных видов, legacy-определений, новых ability/item/bestiary полей и конфликтов затрат; ATF → KB round-trip единиц/стоимости/effects. Pure JS-нормализаторы проверяются без DOM и браузера. Lookup проверяется изолированным read-only double MyBB DB, без записей на сайт. Нет полного suite, браузера, нагрузочных тестов, боёв или миграций.

До ACS остаются решения баланса: смысл repeat_rule и фаз; порядок снятия/истечения/tick; сброс/композиция лимитов и повторные триггеры; диапазоны/округление AP и ресурса; оплата отменённых действий; правила восстановления зарядов; единицы сопротивлений/иммунитетов; приоритеты и правила выбора целей; управление/время жизни призванных существ. Эти решения не подставлены автоматически. Производственный UI/DB round-trip не подтверждён браузерным прогоном; CharacterSheets не обещает отображать новую механику без будущей отдельной задачи.

### ACS-5.3.2: retained reaction protection bindings

Используется существующая `af_kb.arpg.rules.v1`, без новых effect kinds и без правок KB owner. ACS исполняет только явно заданный поддержанный компонент `trigger_template.effect_ref`:

- Перехват: `kind=control`, `control_type=intercept`, ability `targeting=single_ally`, фаза `on_targeted`/`before_hit`. `chance`, если задан, использует отдельный persisted reaction check; основной hit/crit не меняется. Выбранный союзник фиксируется регистрацией с CAS. Атака должна быть single target.
- Mass defense: `kind=shield`, `mass=true`, `targeting=multiple` либо `all_allies`, целое положительное `value`, `scope=shared_pool|per_target`, `absorbs=[physical,elemental]` (допустимо непустое подмножество), `duration_value` и `duration_unit`. Shared pool требует `scope_key`; его длительность — own_turn/round, per_target также поддерживает target_turn. Необязателен явный целый `barrier_priority`. Основные `costs` оплачиваются один раз; дополнительные `costs_per_target` — только явно заданный список обычных resource/use costs. Порядок `multiple` сохраняется; `all_allies` определяется сервером при регистрации в порядке стабильных entity ID. Новые участники не включаются автоматически.
- Пассивные дочерние причины задаются retained `passive_effects` ссылками `{kind: control, control_type: passive, effect_ref: {type: arpg_ability, key, effect_index}}`. Поддержанный узкий child: self-targeting ability с одним literal heal/damage компонентом `target=self|attacker`, без цены, crit и дополнительных overrides. Все ссылки pinned; trigger обязан явно задать `limits.max_depth` и `limits.per_cause=1`. Цикл, отсутствующая ссылка и превышение заданной KB глубины блокируют механический результат целиком. Нового глобального игрового лимита глубины нет. Дочернее исполнение не вызывает очередь активных реакций.

Это описание поддержанного ACS consumer binding, а не разрешение выводить цену, вероятность, выбор целей или глубину из декоративного текста. Неизвестные schema/overrides блокируются; произвольные формулы, chained active reactions и автоматическое исполнение неподдержанных passive kinds отсутствуют. Внешний UI KB может не иметь редактора всех этих явных полей; наличие сохранённого typed definition обязательно.

## ACS-7.2.1 consumer binding: создание существа/объекта

Это opt-in consumer существующего B1 `effects[].kind=summon`, а не изменение
KB normalizer или разрешение исполнять любой `supported` DTO. ACS принимает
только отдельную active/self способность с явным положительным целым
`action_cost` (AP), без урона, статусов, cooldown, upkeep и других механических
эффектов. `effects[].target=self`; каждый summon содержит **полный** объект
`spawn` следующей формы (fingerprint в примере нужно заменить настоящим):

```json
{
  "schema": "ic-acs.spawn.v1",
  "kind": "spawn_entity",
  "template": {
    "type": "arpg_bestiary",
    "key": "wolf",
    "version": "1.0",
    "fingerprint": "sha256:<64 lowercase hex characters>"
  },
  "count": 1,
  "scope": "encounter",
  "group": "source",
  "controller": "source",
  "side": "source",
  "zone_policy": "unplaced",
  "duration": {"value": 3, "unit": "round"},
  "limits": {"per_source": 3, "per_encounter": 5},
  "conditions": ["source_active"],
  "upkeep": [],
  "on_spawn_effect": [],
  "despawn": "lifetime_end"
}
```

Все ключи обязательны, неизвестные ключи запрещены. `count` — 1..100;
лимиты — 1..10000, не меньше count. До 16 эффектов и 128 экземпляров на commit,
с существующим ограничением сериализованного ACS payload. Лимиты считают
созданные идентичности одного type/key в encounter (включая прежние версии
шаблона); expiry не освобождает лимит автоматически. `duration.value` —
1..2147483647, unit — round/own_turn. Lifetime и его исходный turn сохраняются
как данные создания; roster admission и продвижение lifetime здесь не выполняются.

Для entity разрешены пары group/controller `source/source` и
`independent/gm_pool`; вторая пара — только для исходного npc/monster.
Первая сохраняет текущую группу источника и копирует базовое назначение
контроллеров, без предметных/административных grants и без превращения
делегата в владельца. Независимая группа помечается next_round_pending.
Ни один вариант не создаёт turn, EntityTurn, AP или seal quota в этом этапе.
Существа до ACS-7.2.2 имеют admission=pending_roster и закрытые combat operations.

Шаблон существа — точный доступный `arpg_bestiary` IC-KB.read.v1 с указанными
version/fingerprint и проверенными характеристиками/17 сопротивлениями.
HP берётся из pinned combat_stats. Непустые loot, модификаторы, equipment,
дополнительные resources, conditions, grants, passive effects, triggers и
weaknesses не исполняются и блокируют этот consumer. Ability refs шаблона
закрепляются существующим registration-time обходом графа, без автоматической
выдачи этих способностей источнику. Никакие CS/Inventory owner records не создаются.

Для `spawn_object`: template.type=arpg_mechanics, group/controller=none/none.
Это явный service_mechanics/combat_template wrapper со schema
`af_kb.arpg.rules.v1`, version и ровно одной entries-строкой:

```json
{
  "schema": "af_kb.arpg.mechanic.v1",
  "key": "banner",
  "version": "1.0",
  "phases": [],
  "limits": [],
  "formula_refs": [],
  "object_definition": {
    "schema": "ic-acs.object-template.v1",
    "name": "Знамя",
    "properties": {"description": "Пассивный объект", "tags": ["banner"]}
  }
}
```

Object name — непустая строка до 191 символа; description — до 4096 байт;
tags — до 32 токенов `[a-z][a-z0-9_-]{0,63}`. Другие свойства запрещены.
Объект не имеет state/resources/controllers/group/participant/turn и не
является запасным bestiary NPC. Он существует в общем реестре entities
как passive_object; этот этап не добавляет его в UI боевых целей.

Единственная zone policy — unplaced, zone_id=NULL. Spatial-поля, неизвестные
шаблоны, неполный legacy summon, условия появления кроме source_active,
upkeep/on_spawn_effect и иные capabilities завершаются отказом, без координат
или правил, выведенных из имени.

Шаблоны читаются и закрепляются при исходной регистрации способности.
Preview/Seal работают только с этим графом и создают intent, без SQL spawn.
Commit не читает живую KB. Порядок intent — action/effect/ordinal; плоский
spawn_index начинается с 0 внутри source_event_id существующего
combat.resolved. Durable key `spawn:<source_event_id>:<spawn_index>` защищён
существующим UNIQUE entities.spawn_once. Result/event details и terminal
receipt содержат созданные ID/snapshot hashes. Повтор commit возвращает
тот же receipt; отдельного spawn endpoint или MyBB post нет.

## ACS-7.2.2 consumer extension: roster и lifecycle

Расширение действует поверх `ic-acs.spawn.v1` выше. Старые pinned DTO не
перечитываются из KB. У `spawn_entity` теперь разрешён необязательный объект
`lifecycle` с **точным** набором полей:

```json
{
  "admission": "current_round",
  "source_inactive": "despawn",
  "disappear_on": ["self_dead", "encounter_end"]
}
```

- admission: current_round / next_round. Без поля — next_round. Current round
  возможен только у source-group с уже существующим pending turn; изменяется
  только append roster/EntityTurn с CAS и аудитом before/after. Нет нового turn,
  AP, seal quota или deadline. Обычный publish закрывает групповой turn до
  создания identity: появление в таком commit не открывает ход повторно.
- source_inactive: persist / despawn / independent; отсутствие — persist.
  Inactive означает существующий life_state != active, не уход участника из
  боя. Effects проверяет условие после каждой атомарной операции, до следующего
  лечения. Independent прекращает обычную доступность до следующего roster;
  group/GM-pool назначаются при следующем round admission, без постоянного owner.
- disappear_on: уникальный список из self_dead / encounter_end. По умолчанию
  только self_dead. Ноль HP остаётся incapacitated, а не выдуманной смертью.
  Encounter end выполняется существующим combat_reset boundary.

Duration round уменьшается на round_end соответствующего encounter, включая
раунд создания. Duration own_turn — на end **самого призыва**, после его
roster admission. Это не счётчик страниц или литературных сообщений. Stamp
последней границы и её существующий durable receipt исключают повтор.

`spawn.upkeep` принимает те же явные B3 sustain costs, что ACS-5.2.2:

```json
[
  {"kind":"resource","resource_key":"hp","value":5,"timing":"sustain","period":{"value":1,"unit":"own_turn"}},
  {"kind":"resource","resource_key":"ep","value":3,"timing":"sustain","period":{"value":1,"unit":"own_turn"}}
]
```

Плательщик — исходный source_entity, anchor — его EntityTurn start, после
mandatory ticks и существующего status upkeep. Несколько призывов платят в
стабильном entity-ID порядке. Используются только существующие integer combat
balances; `ap` — тот же остаток общей группы, HP не может упасть до нуля от
поддержания. Нет implicit AP, новых EP или Inventory/item costs. Оплата одного
призыва атомарна: недостаток любого ресурса не списывает остальные и завершает
этот призыв. Ранние успешные оплаты не возвращаются. Last payment привязан к
boundary key. Смена на independent не меняет исходного плательщика upkeep.

Active count освобождается только после зафиксированного despawn. Source/key
и история сохраняются, независимость не обходит maximum count. На objects
это расширение не распространяется: lifecycle/upkeep объекта по-прежнему
UNSUPPORTED; Stage 8 не имитируется. Recursive summoning, произвольные
условия/скрипты, пространственные правила и on_spawn_effect остаются закрыты.

### Pinned presence rule для partial withdrawal/rejoin

GM-команды `partial_withdraw` / `rejoin_encounter` работают только при явном
правиле, закреплённом в config snapshot данного столкновения. При `create`
необязательный backend-параметр `presence_template` выбирает owner-authorized
arpg_mechanics/combat_template IC-KB DTO. В нём ровно одна B3 entries-строка
с schema/key/version, пустыми phases/limits/formula_refs и полем:

```json
{
  "presence_policy": {
    "schema": "ic-acs.presence.v1",
    "partial_withdraw": true,
    "rejoin": true,
    "requires_active": true
  }
}
```

Впоследствии используется этот pinned DTO, без live KB read. Это узкое правило
того же encounter и active entity, без дистанции, побега, наград, defeat или
смены стороны. Обе команды требуют session/encounter/participant CAS, reason,
POST/CSRF, operation key и существующую GM/native авторизацию. Меняется только
participation указанной identity; питомцы/остальные призывы не удаляются.
Возвращение не добавляет отсутствующего участника в текущий frozen roster,
не открывает terminal turn и не меняет AP, seal attempts, reaction/cooldowns
или ресурсы. Неизвестное либо отсутствующее правило блокирует команду.

Terminal despawn завершает состояния на самом существе, его source-bound
upkeep/own_turn и emitter barrier pools; target_turn/round эффекты на других
целях продолжают свои закреплённые сроки. Общий shield постоянного источника
не исчезает из-за удаления одного summon-target. История и ресурсы сохраняются.

## ACS-7.3.1 consumer binding: target policy и control_effect

Три независимые проекции: encounter participant.side_id/side_relationships,
base controllers.assignment_version и временные effects instances. Ни один
control_effect не записывает owner UID, базовые controllers или CharacterSheets.

### Target policy

Необязательный `rules.target_policy` способности — строка hostile_only,
allied_only, self_only, any_entity либо объект с полем kind. Custom имеет
точно такую форму:

```json
{
  "kind": "custom",
  "relationships": ["neutral", "hostile"],
  "entity_kinds": ["npc", "monster", "summon"]
}
```

Relationships — непустой уникальный список из self/allied/neutral/hostile;
entity_kinds — из character/pet/npc/monster/summon. Не-custom объект содержит
только kind. Неизвестные условия/ключи, scripting/spatial predicates не
исполняются. Any_entity означает доступные combat identities этого consumer,
без Stage-8 object interactions и без автоматического обнаружения скрытых целей.
Allied_only включает self; исключение self выражается custom relationships.

Без поля legacy single_enemy/multiple остаётся hostile_only; self spawn
остаётся self_only. `targeting` сохраняет существующую поддерживаемую структуру
single_enemy/multiple, а отношение определяется target_policy. Self spawn
не может получить расширенную policy. Status polarity, immunity и chance
проверяются отдельно: target_policy не является bypass этих правил.

Сервер строит только видимые и допустимые selector entries. Отношения берутся
из текущих сторон/таблицы relationships, не из постоянной faction. Resolver
повторно проверяет выбранные цели и eligible redirects; не заменяет сохранённые
цели при обновлении отношений. Relation/participant/assignment generations,
status/control proof и текущие protection dependencies связаны с Seal.

Direct damage, pinned periodic damage по союзнику/self и возможный видимый
allied intercept дают friendly_fire warning до Seal. Предупреждение не содержит
скрытых identity/definition данных. Seal/confirm принимают отдельный
`friendly_fire_confirmation` — opaque текущий server hash, не bool от клиента.
Без подтверждения новые rolls/quota не выделяются. ACK хранится в существующем
draft seal metadata. При изменении риска нужны свежий ACK и обычное reconfirm
с прежней tape. Урон союзникам не получает автоматического множителя.

### Control overlay

Opt-in KB status_def `mechanical_parameters.control_effect`:

```json
{
  "schema": "ic-acs.control-effect.v1",
  "controller": "caster",
  "priority": 10,
  "permitted_actions": ["ordinary"],
  "ability_keys": ["basic-hit"]
}
```

Ключи точные. Priority — целое -2147483647..2147483647, ability_keys —
1..64 уникальных KB keys. Это ограничение уже существующих способностей цели,
а не выдача новых. Status обязан иметь scope=combat, repeat_rule=independent,
source_scope=source, положительную duration target_turn/round. Periodic,
upkeep и blocks_ordinary_actions в этом control status не допускаются;
behavior constraints/charm из ACS-7.3.2 не угадываются по названию.

Применение проходит прежние status chance/resistance/immunity и механический
commit. Runtime control_overlay содержит authenticated caster UID и
base_assignment_version на момент применения. UID нельзя передать в draft или
вывести из permanent owner UID: при делегированном cast это действующий автор.
При нескольких слоях побеждает больший priority, затем более поздний applied_order.

Authorization читает активные pinned effects поверх **текущей** базовой
assignment generation. Overlay временно исключает обычные base/delegate
боевые действия для этой identity, но не меняет ownership и не переносится
автоматически на питомцев/призывов. Expiry/cleanse/dispel снимают только слой;
нижний слой либо актуальная базовая запись становятся эффективными без записи
старого значения в controllers. Новое законное назначение остаётся на месте.

Доступ требует текущей playable session membership, native topic rights,
combat gate и visibility. Overlay не открывает GM-hidden identity игроку,
не даёт полный snapshot, Inventory consume/equip/transfer/grant, GM management,
reward/CS editing, reaction registration или upkeep ordering. Builder выдаёт
только разрешённые keys с нейтральными labels, без private ability descriptions.
Права, уже существующие независимо от overlay (например GM management),
проверяются своими прежними policy, а не приобретаются через control_effect.

## ACS-7.3.2 — behavior_constraint binding

Опциональный typed `status_def.mechanical_parameters.behavior_constraint` исполняется
как ограничение допустимых действий/целей существующего status instance. Название,
`type`, локализованный label или ключ taunt/fear/compulsion не определяют правило.
Отсутствие descriptor сохраняет прежнее поведение; неизвестный descriptor не
превращается в разрешение. Новый registry/engine/таймер не создаётся.

```json
{
  "schema": "ic-acs.behavior-constraint.v1",
  "priority": 10,
  "scope": ["ordinary", "reaction"],
  "compatibility": {"group": "attention", "mode": "exclusive"},
  "on_no_legal_target": "block",
  "rules": [{"kind": "target_priority", "target": "source"}]
}
```

Все шесть полей обязательны; лишние поля отвергаются. `priority` — int32,
`scope` — непустой unique list ordinary/reaction (максимум 64); ordinary включает
обычные способности, item_consume, equipment_change и spawn, **без выдачи прав**.
`rules` — 1..16 правил. Target selectors только `source` (source entity этого
экземпляра) и `self` (affected entity); произвольные UID, entity IDs, локализованные
имена, spatial predicates и forced_flee не поддерживаются.

| kind | Точные поля | Поведение |
|---|---|---|
| target_lock | kind, target | Допустима только указанная цель |
| target_priority | kind, target | Если указанная цель допустима, выбирать нужно её; иначе применяется объявленная fallback policy |
| target_exclusion | kind, target | Исключить указанную цель |
| action_restriction | kind, mode, actions, ability_keys | mode=allow/deny; проверка категории и точного ключа способности |

Для `action_restriction.actions` — непустой unique list из ordinary, reaction,
item_consume, equipment_change, spawn; `ability_keys` — unique list 0..64 точных
ключей (0 означает все ключи **только перечисленных категорий**). Match — категория
в actions AND (список ключей пуст OR ключ присутствует). allow запрещает всё, что
не match; deny запрещает match. Это только сужение существующих grant, не право
применить незакреплённую способность, потратить чужой предмет или передать имущество.
Например compulsion может объявить allow ordinary + конкретные ability_keys;
fear — target_exclusion source; taunt — target_priority source. Возможны другие
имена и комбинации тех же примитивов, без нового кода по имени статуса.

`compatibility.group` — точный KB key до 64 символов; mode=exclusive/combine.
Для одной группы все активные применимые экземпляры должны объявить одинаковый
mode: несовпадение даёт UNSUPPORTED, а не угаданный приоритет. В exclusive берётся
один экземпляр: **больший priority**, затем **меньший applied_order**, затем
стабильное строковое instance ID. В combine используются все экземпляры в том же
порядке. Разные группы пересекаются, также в этом порядке; внутри экземпляра
правила выполняются по индексу KB. Никаких случайных tie-break или повторной очереди.

Сначала строится полный **серверный видимый** набор целей с текущей relationship
policy способности; он не равен переданным клиентом targets. source/self должны
входить в этот набор и быть active для lock/priority. Нельзя открыть hidden source
или заменить фиксированную цель при seal/commit. `on_no_legal_target`:

- block: недоступный lock/priority или пустое пересечение блокируют действие;
- ignore_rule: только неосуществимое target-правило пропускается; exclusion,
  оставляющий пустой набор, также пропускается. Предыдущий разрешённый набор
  сохраняется, новый противник не выбирается. Action restriction не отменяется.

Если итоговых целей меньше pinned target_count.min, действие BLOCKED. Неизвестный
fallback/selector/compatibility — UNSUPPORTED. Public результат содержит только
READY/BLOCKED/UNSUPPORTED, безопасный code/reason и разрешённые **видимые** ID,
без effect/source identity, скрытых участников или definitions.

Descriptor поддержан только для combat status, source_scope=source, duration>0
в существующих own_turn/target_turn/round. Срок, decrement, refresh/stack/removal,
pins и audit принадлежат Status/Clock. Нельзя совместить descriptor в одном spec
с control_effect, periodic или upkeep; это UNSUPPORTED. Отдельные совместимые
экземпляры могут сосуществовать. Refresh сохраняет прежний pinned spec/силу по
5.1.2, а не обновляет правило из latest KB.

Проверки: Builder catalog/target IDs, prepare/preview/seal, текущий Resolver
перед каждым действием, регистрация и исполнение реакции. Redirect eligibility
также сужается, но behavior сам не исполняет intercept или перенос атаки. При
блокировке реакции costs/slot не тратятся. Status и candidate-universe dependencies
входят в существующее confirmation/fact fence; старые samples/цели сохраняются.
Prepared actions ещё не исполняются: scope=prepared и принудительный побег не
эмулируются; будущий исполнитель обязан вызвать тот же gate при срабатывании.

## ACS-8.1.1 consumer binding: environment instances

Это opt-in расширение ACS consumer, а не изменение B1 normalization или
разрешение исполнять любой прочитанный JSON. Предыдущие ограничения 7.2.1/7.2.2
на object lifecycle заменяются только перечисленным здесь subset. Wrapper
`arpg_mechanics / service_mechanics / combat_template`, spawn descriptor,
registration-time template pin, fingerprint/version и source policies сохраняются.
`ic-acs.object-template.v1` остаётся совместимым passive marker без механических
эффектов, durability или GM operations; получает object state и срок из spawn.
Неизвестные поля `properties` по-прежнему запрещены.

Новый `object_definition`:

```json
{
  "schema": "ic-acs.object-template.v2",
  "name": "Знамя",
  "properties": {"description": "Источник восстановления", "tags": ["banner"]},
  "mechanics": {
    "categories": ["passive", "triggered", "interactive", "destructible"],
    "initial_status": "active",
    "durability": {"maximum": 12, "damage_types": ["physical"]},
    "passive": [{"key": "renew", "event": "source_turn_start", "effect": {"kind": "heal", "target": "source", "amount": 2}}],
    "triggers": [{"key": "bless", "event": "source_turn_end", "once": true, "effect": {"kind": "heal", "target": "source", "amount": 7}}],
    "gm_operations": ["create", "activate", "deactivate", "damage"]
  }
}
```

Все шесть полей mechanics обязательны. Categories — непустой уникальный subset
passive/triggered/interactive/destructible, в любой комбинации. Initial status —
active/inactive. Durability — null без destructible, иначе строго maximum
(положительный integer не выше 2^53−1) и непустой уникальный damage_types из
physical/elemental/true. Это уже конечный фиксированный урон прочности: DEF/RES,
HP живого существа, crit или universal durability не подставляются.

Passive/triggers — списки до 16 записей каждый; key уникален между обоими
списками и соответствует `[a-z][a-z0-9_-]{0,63}`. Требуется соответствующая
category. Event — строго source_turn_start/source_turn_end, совпадающий с
существующим Clock entity_start/entity_end **исходного source_entity_id**.
Эффекты исполняются только пока объект active и источник существует и не dead.
Heal разрешён только на source, amount — положительный точный integer.
Дополнительный triggered effect:

```json
{"kind": "damage_object", "target": "self", "amount": 1, "damage_type": "physical"}
```

Он требует destructible и явно разрешённый damage_type, уменьшает собственную
прочность через Effects; при нуле сохраняется terminal destroyed. Последующие
эффекты объекта не запускаются. Это позволяет выразить фиксированный расход
прочности механизма при срабатывании без произвольного callback.

Triggers требуют `once=true`: один раз за жизнь экземпляра, включая запрет
повтора на том же causal event. Passive здесь — автоматический периодический
источник фиксированного эффекта на явно названной границе, один раз на boundary;
не постоянный stat modifier и не spatial aura. Отдельных rolls нет: это fixed
causal subset без вероятности/выбора цели. Random triggers и прочие эффекты —
UNSUPPORTED, без получения новых samples или обхода существующей random tape.
CausalGraph проверяет ограниченный root→effect граф глубиной 2; дочерние heal/
self-damage не являются новыми object causal events.

Срок берётся только из immutable spawn.duration: round — round_end encounter,
own_turn — entity_end исходного source. Inactive тоже расходует срок. Истечение
переводит в terminal expired, не удаляя entity/snapshot/spawn event. Нет upkeep,
переноса source clock или автоматического воскресения destroyed/expired.
Повтор boundary использует прежний parent receipt и сохранённые stamps.

GM operations — уникальный subset create/activate/deactivate/damage. Activation/
deactivation требуют interactive; damage требует destructible. Create разрешает
GM выбрать только уже retained object spawn ability зарегистрированного engaged
source в этом encounter; весь ability должен создавать только objects. Свежий
KB lookup, JSON definition из клиента и GM override шаблона запрещены. Сохраняются
обычные per_source/per_encounter limits; terminal objects не освобождают лимит.

Backend API: `EncounterService::environment()->command($operation, $input)`.
Обязательны session_id, encounter_id, expected_version (session),
expected_encounter_version, expected_object_version, operation_key, reason.
Для create: expected_object_version=0, source_entity_id, ability_key. Для других:
entity_id; для damage дополнительно amount и damage_type. Неизвестные поля
отклоняются. POST/CSRF, native thread access и текущая session GM/owner role
проверяются до операции и повторно под session lock. Общий unresolved-publication
barrier сохранён. Команда не создаёт MyBB post и не является боевым действием
игрока или бесплатной выдачей ему AP.

Ordinary object targeting/interaction checks, progress, tools, awareness, cover,
spatial/zone predicates, arbitrary on_destroy/rewards, callbacks и random object
triggers не включены в этот binding. Их определения возвращают UNSUPPORTED.

## ACS-8.1.2 consumer binding: awareness, detection и stealth

Awareness — отдельный encounter ledger, не object status и не status effect.
Ключ: encounter + subject_kind(entity|object) + subject_id(existing entity_id) +
audience_kind(actor|control_group|side|encounter) + audience_id. Для encounter
используется ID самого encounter. Отсутствие записи означает unknown;
материализованная unknown допустима для коррекции. Уровни: unknown, suspected,
detected, identified. Изменения защищены awareness_version и parent transaction.
Прямое управление собственной identity является отдельным разрешением знать её;
оно не означает знания всех объектов группы. Temporary control не наследует
приватное знание всей source group.

### Perception и разрешённое раскрытие

Optional `perception` у object-template.v2 либо pinned bestiary definition:

```json
{
  "schema": "ic-acs.perception.v1",
  "tags": ["trap"],
  "stealth": {"schema": "ic-acs.stealth.v1", "states": ["hidden"], "targeting": "forbid"},
  "disclosure": {
    "suspected": {"hint": "Есть признаки механизма"},
    "detected": {"hint": "Обнаружен механизм"},
    "identified": {"hint": "Известный механизм", "name": "Ловушка"}
  },
  "passive": null
}
```

Все пять полей perception обязательны. Tags — до 16 уникальных tokens
`[a-z][a-z0-9_-]{0,63}`. Stealth — null либо точный descriptor выше, states —
непустой unique subset invisible/hidden/concealed; targeting — forbid/identified.
Эти состояния не являются уровнями knowledge. Forbid запрещает обычный выбор
цели даже при identified/GM knowledge; identified сохраняет требования к знанию,
авторизации и общей target policy. Self-действия управляемой identity сохраняются.

Disclosure имеет ровно три уровня. Допустим только hint, а на identified ещё
name; UTF-8 strings до 191 символа без NUL. Нет description/raw snapshot, KB ref,
GM reason или произвольного JSON. Suspected/detected DTO не содержит subject_id,
subject_kind или name. Identified разрешает стабильную identity; name выдаётся
только из разрешённых details, иначе используется нейтральная подпись.

Optional фиксированный passive check:

```json
{"event": "source_turn_end", "once": true, "level": "suspected", "audience": "control_group"}
```

Audience необязателен, default control_group. Только source_turn_end исходного
source объекта (для существа — самого subject), только один раз за instance.
Фиксированный результат не требует RNG. Срабатывание связано с реальным
entity_turn:<id>:end и отдельным durable awareness.check receipt. Не исполняется
по GET. Пассивная проверка знания не зависит от active/destroyed объекта: это
явно разрешённое обнаружение subject по событию, в том числе исторического следа.
Random passive, spatial/area/zone predicates и arbitrary callbacks UNSUPPORTED.

### Активный поиск и передача сведений

Active self ability, positive action_cost, effects=[]; отдельный opt-in field:

```json
{
  "schema": "ic-acs.awareness-action.v1",
  "operation": "search",
  "subject_kind": "object",
  "tag": "trap",
  "level": "identified",
  "chance": 65,
  "audience": "control_group"
}
```

Все поля обязательны кроме audience (default control_group). Operation —
search/share. Chance — integer 0..100, у share строго 100. Subject_kind —
entity/object; tag — точный KB token. Это encounter-wide tag scope без геометрии:
участвуют только subjects с собственным pinned perception/disclosure и этим
тегом. Нет client subject IDs, скрытых entries в selector или списка найденных
целей в preview/seal. Стоимость — AP исполнителя в существующей группе.
Смешанные damage/status/spawn effects, upkeep, dynamic costs/formulas и неизвестные
механические поля этого action закрыты.

Search — **одна** проверка на весь объявленный tag scope, независимо от числа
или отсутствия subjects. Preview не выбирает success и не вызывает RNG. В Seal
существующий uniform53 hit slot этой самостоятельной search action используется
как detection sample; crit slot сохраняется неиспользованным. Это не повторное
использование hit другой атаки. Success: `100*u < chance`. Parent causal key —
sealed-action:<sealed_action_id>, существующие rolls/check_kind=hit/roll_index=0.
Commit/reconfirmation используют прежний tape. No-match и failed search имеют
тот же публичный shape. Новая попытка требует новой разрешённой sealed action,
AP и квоты; retry прежней publication возвращает receipt.

Share — самостоятельное разрешённое действие с AP cost; источник должен уже
знать subject на передаваемом уровне. Передаются только имеющиеся у источника
разрешённые details, с отсечением name ниже identified. Private KB disclosure
не дополняет сведения отправителя. Actor/group/side вычисляются сервером по
текущей identity, а не UID/маске/произвольному audience ID из клиента. Литературный
текст knowledge не меняет. Actor/side/group/awareness dependencies входят в
существующий confirmation/fact fence. Изменение knowledge требует reconfirm,
не новых samples и не автоматической подмены целей.

### Явное массовое воздействие на скрытые объекты

Отдельный self action с positive action_cost, effects=[] и полем:

```json
{"hidden_mass_effect": {"schema": "ic-acs.hidden-object-mass.v1", "tag": "trap", "amount": 2, "damage_type": "physical"}}
```

Не совмещается с awareness_action или другими effects. Это fixed final durability
pulse по всем object subjects encounter с данным perception tag, нетерминальным
состоянием и явно допускающим данный damage_type destructible definition.
Порядок — стабильный entity_id. Нет geometry/zone lookup, stat/RES fallback,
неявной прочности или выбора recipient ID в клиенте. Пустой eligible scope тоже
расходует AP и имеет тот же публичный shape. Standard sealed samples сохраняются
неиспользованными; нового causal RNG нет. Unknown recipients не попадают в
выбранные action targets, counts, summary или receipt. Изменяется durability,
но не knowledge; отдельно объявленный passive detection на parent boundary
остаётся самостоятельным причинным событием. Обычный targeting=forbid не
является запретом этой явно разрешённой KB mass policy. Creature mass selectors
и spatial area effects остаются UNSUPPORTED.

### Скрытность существ

Typed status_def.mechanical_parameters.stealth использует тот же
ic-acs.stealth.v1 descriptor. Только combat status; его наложение, срок, снятие,
версии и pinned source принадлежат существующим Status/Effects/Clock. Detection
не удаляет статус. Пересечение нескольких forbid слоёв остаётся запрещающим.
Неподписанные boolean invisible/hidden/concealed/reveal_on_action не получают
семантики по имени; unsupported. Штрафы/бонусы, reveal_on_action и пространственные
проверки в этом binding не реализованы.

### Авторизация и выдача

AudienceService применяется до селекторов, choices/legal_targets, object/knowledge
notices и HTML/JSON. Native session membership и прежний participant visibility
ceiling остаются отдельными gates: visibility=participants само по себе больше
не создаёт знание, а visibility=gm не открывается одной записью identified.
Действительные owner/base/delegate grants дают знание управляемой identity;
аудитории остальных subjects проверяются по отдельным ledger rows. Unknown не
попадает в notices/totals/disabled target choices. Draft restore с отозванным
знанием отказывает безопасно, не меняя сохранённые actions/prose/tape.

Combat cards сохраняют прежний GM-only detail gate. Public Publish/Recovery
receipts фильтруют неизвестные spawns и не выдают snapshot IDs/hashes известным
player spawns; immutable stored receipt/result/hash не переписываются. Игроку
не возвращаются hidden-state summary hash fields. Журнал/GM diagnostics остаются
под GM gate; Outbox не содержит нового knowledge/subject payload. Новых notification,
export или statistics consumers нет; будущие consumers обязаны применить эту же
policy до формирования DTO. Public request cache карточек разделён по viewer,
session/encounter, native admin flag, ролям, assignments/delegations, audience и
awareness generations; истечение прав учитывается. Общего GM/player DTO cache нет.

GM backend `EncounterService::awareness()->set()` принимает точный tuple,
expected session/encounter/awareness versions (0 для отсутствующей строки), level,
whitelisted details, reason и operation_key. POST/CSRF, native/session GM rights,
subject/audience scope проверяются сервером. Аудит и CAS атомарны, повтор ключа
идемпотентен. Смена аудитории — отдельная tuple-запись с собственным CAS; прежнее
знание не удаляется неявно. Изменения не обновляют mechanical stealth/AP/ходы.

### ACS-8.2.1: typed object interaction subset

`ic-acs.object-template.v2` категории `interactive` может содержать optional
`interactions` с schema `ic-acs.object-interactions.v1`. Это данные общего
интерпретатора ACS, не callbacks. Неизвестные поля и неподдержанные последствия
возвращают UNSUPPORTED. Старые templates сохраняют прежнюю семантику.

Обязательные поля descriptor:

| Поле | Контракт |
|---|---|
| states / initial_state | 1–32 уникальных KB-токена и один из них |
| progress | null либо `{initial, cap, decay_per_round}`; целые, cap 1–1000000, остальные 0..cap |
| actions | 1–16 явно определённых взаимодействий |

Каждое action содержит все поля:
`key`, `label`, `kind` (`disarm`, `unlock`, `activate`, `repair`, `interact`),
`actor_kinds` (character/pet/npc/monster/summon), `required_abilities` (прямые доступные
закреплённые способности), `minimum_stats` (минимумы именованных характеристик),
`allowed_states`, `costs`, `tool`, `check`, `outcomes`, `retry`.
Kind не выбирает PHP-handler и не добавляет последствий. Label — публичная
подпись для identified audience. Скрытые сведения в неё не помещаются.

Costs — один положительный `{kind:ap, amount}` и optional положительные
`{kind:resource, resource, amount}` без повторов ресурсов. Tool — null либо
`{mode:requires|consumes, kb_key, qty:1}`. В этом subset инструмент — реальный
неvisual `equipment/arpg_item` собственного character; custody/reservations
учитываются. Requires не резервирует и не расходует предмет. Consumes использует
same-writer IC-INV-1 reserve/consume только для поддержанных owner protocol
subtypes consumable/ammo; `on_use` предмета не выполняется.

Check содержит `formula` — полный fingerprint-verified snapshot
`af_kb.arpg.formula.v1` — и `bindings`. Output unit `interaction_score`, type
number, rounding none; min/max обязательны. Входы имеют тип number и связываются
с `roll` (сохранённый uniform53 u), `progress` либо `stat:<key>`. При отсутствии
roll binding никакой универсальный шанс не добавляется. Preview не вычисляет
исход; seal фиксирует существующий tape; commit использует тот же tape.

Outcomes — 1–4 непересекающихся смежных диапазона `[from,to)`, последний включает
to; вместе покрывают объявленные min/max формулы. Порядок — по from. Каждый
kind (`critical_success`, `success`, `partial_success`, `failure`) встречается
не более одного раза и существует только при явном описании. Все поля outcome
обязательны: `kind/from/to/state/activation/progress/repair/effects`. State —
один из states; activation — null/active/inactive. Progress — null либо
`{operation:add|set,value}`; add допускает отрицательный вклад, set=0 — явный
reset. Результат ограничивается 0..cap. Repair — целое 0..1000000, положительное
значение требует объявленной durability; максимум берётся только из template.
Терминальные объекты не ремонтируются и не активируются.

Effects — 0–8 `{kind:heal_actor,amount}` либо
`{kind:damage_actor,amount,damage_type:physical|true}`. Положительное целое
amount проходит существующий Damage/Effects; последствия не вызывают новый
processor. Статусы, стихийные эффекты, чужие/пространственные цели, произвольные
trigger callbacks не входят в этот subset и блокируются целиком.

Retry — `{scope:actor|object,limit:1..1000,terminal_outcomes:[...]}`. Сервер
сохраняет число подтверждённых попыток и terminal flag. Каждый вклад привязан к
`sealed-action:<id>` и combat event, actor и action key. Новая HTTP-отправка не
создаёт новый вклад. Cooldown по времени/раундам не подставляется вместо явно
описанного лимита; такой расширенный контракт пока UNSUPPORTED. Положительный
`decay_per_round` применяется только существующим round_end Clock boundary,
защищённым last_boundary, в этом subset только для duration.unit=round;
положительный round decay с own_turn lifetime — UNSUPPORTED, 0 означает отсутствие decay.

### ACS-8.2.2: sequential cooperation subset

Interactive `ic-acs.object-template.v2` может содержать `cooperation` с schema
`ic-acs.cooperation.v1`. На один object entity приходится одна задача; её KB
pin — исходный immutable object template snapshot. Неизвестные поля/условия и
неподдержанные политики блокируют контракт. Литературный текст не читается как
механическое действие.

Обязательные поля:

| Поле | Семантика |
|---|---|
| kind | `cooperative_check` или `cooperative_objective` |
| label / visibility | Подпись до 96 символов; `known` (только identified object audience) либо `gm` |
| cap | Явная целая цель 1..1000000; начальный прогресс 0 |
| object_statuses | Непустой список разрешённых active/inactive |
| object_changed | `reconfirm` — актуальное состояние и прежний tape; `block` — запрещать действие при любом отличии от creation object_version |
| contributor_inactive | Только `retain`: подтверждённый вклад/помощь сохраняются; новая попытка требует активного исполнителя |
| action | Typed action из 8.2.1 с уточнениями ниже |
| assist | null либо typed effect, описанный ниже |
| completion | Явный список 0..8 разрешённых последствий достижения cap |

Action использует actor_kinds, required_abilities, minimum_stats, AP/resource
costs, check/Formula snapshot, outcomes и retry из 8.2.1. Синтетические
allowed_states/state равны `open`; tool=null, activation=null, repair=0,
outcome effects=[]; progress=null либо `{operation:add,value:<неотрицательное
целое до cap>}`. Это состояние задачи, не подмена interaction state объекта.
Вклад ограничивается оставшимся cap. Retry scope/limit/terminal_outcomes
применяются к подтверждённым check/contribute records; assist не подменяет
попытку проверки. Условия числа/профессий участников, negative/reset/decay
общего прогресса, spatial predicates и arbitrary callbacks не поддержаны.

Check bindings дополнительно допускают `assist`: сумма действующей помощи
конкретному исполнителю этой задачи. Формула явно определяет, как использовать
её вместе с roll/progress/stat inputs. Универсального бонуса к результату,
успеху, атаке или урону нет. Если assist определён, binding должен присутствовать;
при отсутствии помощи input равен 0. Остальные исходы/пороги и проверка pin
остаются контрактом 8.2.1. Числовой score, outcome и фактический вклад сохраняются.

Assist содержит `ap_cost` (положительное целое), `value` (1..1000000),
`duration_rounds` (1..1000), `allow_self` (boolean). Optional `uses` — 1..1000,
по умолчанию 1; `stacking` — reject/replace/sum, по умолчанию reject;
`max_stacks` — 1..8, по умолчанию 1, значение больше 1 допустимо только для sum.
Идентичность помощи — один pinned effect definition задачи и recipient,
независимо от helper. Reject не складывает и не обновляет срок. Replace явно
замещает активную помощь, сохраняя её историю. Sum ограничен max_stacks.
Каждая использованная активная помощь теряет одно использование при check;
завершение задачи прекращает оставшуюся scoped помощь, сохраняя её историю.
Использованный/истёкший эффект не даёт бонуса следующей попытке.

Это отдельный effect instance со source, recipient, pinned definition, duration,
remaining uses, stacking policy и cause. Recipient — активный известный союзник,
доступный существующей target policy; контроль над ним не передаётся помощнику.
Duration уменьшается только existing Clock round_end, не GET/Builder. Source
incapacitation не отменяет подтверждённую помощь при объявленной retain policy.
Помощь за пределами этой задачи, ordinary attack modifiers, предметные расходы
и новые status handlers не добавляются.

Completion допускает `{kind:object_activation,status:active|inactive}` и
`{kind:heal_finisher,amount:<положительное целое>}`. Применение идёт через общий
Resolver/Effects, одним подтверждённым событием. Loot/Balance/EXP, произвольные
статусы и межпостовые распределённые транзакции не выводятся из завершения.

Wire: `object:<entity_id>:coop-check`, `...:coop-contribute` либо
`...:coop-assist-<recipient_entity_id>`, targets=[object entity_id], parameters=[].
Префикс coop- зарезервирован только у templates с cooperation. Прежние object
interactions без этого descriptor не меняют смысл.

Durable contribution key — `objective:<objective_id>:sealed-action:<id>`;
source_event_id — реальный `combat.resolved` journal event. Каждое sealed action
имеет отдельный tape/cause, в том числе внутри одного group post. Общая задача
CAS-защищена objective_version; contributions имеют global UNIQUE. Completion
содержит completion_cause и completed_event_id. Повтор submission/recovery
возвращает старый terminal receipt и не выполняет завершение заново. Sequence
успешных commit определяет порядок вкладов. Смена зависимостей до freeze
требует reconfirm на прежних rolls; frozen конфликт остаётся в прежнем R2
quarantine/recovery protocol. Подтверждённые чужие вклады не переписываются.

Public progress DTO содержит только разрешённые identity/label/progress/cap/
status/version. Unknown/suspected/detected и GM-only задачи отсутствуют целиком,
включая choices, disabled choices и aggregate totals. Contributors, conditions,
assist definitions и raw snapshots не входят в player DTO. Draft restore также
проверяет текущую видимость задачи и recipient; старый draft не даёт право
читать отозванные сведения. Общего публичного cache этого DTO нет.

## ACS-8.3.1 spatial subset

Опциональный `arpg_mechanics/combat_template` содержит ровно одну entry с
пустыми phases/limits/formula_refs и `spatial_definition`. Descriptor
`schema=ic-acs.spatial.v1` задаёт mode=single_zone|zones, default_zone
(единственный zone key для single_zone, null для zones), zones, connections,
legacy_range, leave_engagement=allow|block, engage_ap, leave_ap,
line_of_effect (boolean capability).

Zone: key, name, visibility=public|gm, line_of_effect boolean. Connection:
from/to zone keys, status=open|blocked, range=near|medium|far,
line_of_effect boolean, optional positive ap_cost (default 1). Максимум 64 зоны
и 512 уникальных направленных связей. Переход только по одной открытой связи;
транзитивный поиск пути отсутствует. В одной зоне distance=near, либо engaged
при явном активном контакте пары. Между зонами используется range прямой
открытой связи; отсутствие связи означает unreachable. Обратная связь не
подразумевается. Не назначенная позиция в zones блокирует пространственные
действия; single_zone допускает implicit default без записи на чтении.

Ability `spatial_range` содержит ровно schema=ic-acs.range.v1,
distances (непустой список engaged/near/medium/far), line_of_effect boolean.
При включённой карте пустой range использует явный legacy_range карты.
Legacy range=0/"0" означает незаданную дальность.
Без карты прежний пустой range сохраняет прежнее поведение; явный spatial
range без capability возвращает UNSUPPORTED. LoE требует включённой capability
и разрешения конкретной зоны/связи. Awareness проверяется отдельно до range.

Voluntary wire: spatial:move:<zone_id>, targets=[actor];
spatial:engage:<entity_id> и spatial:leave:<entity_id>, targets=[entity].
parameters=[]; AP вычисляет сервер по виртуальному состоянию. Contact требует
одной зоны и явного перехода; самостоятельной opportunity attack нет.

Зарегистрированная active single_enemy ability может содержать
`spatial_effect={schema:ic-acs.forced-movement.v1,kind:forced_move|teleport,
destination:<zone key>,break_engagements:true}` и явную target_policy.
Это deterministic on-cast effect через прежний Resolver; источник оплачивает
AP ability, цель не оплачивает AP. forced_move требует прямой открытой связи,
teleport — явного существующего destination. Произвольные направления,
условные on_hit movement, callback, координаты и эмуляция неизвестных spatial
predicates не поддерживаются. Неизвестные поля блокируются.

GM configure сохраняет полный KB pin в immutable ACS snapshot; команды
position/connection/zone/engagement требуют native/session GM, POST+CSRF,
версии session/encounter и соответствующего spatial row, reason и operation
key. Изменения пишутся в прежний ACS journal. Combat movement фиксируется
только existing atomic R2 commit. Read dependencies включают только реально
прочитанные позиции, зоны, directed edges и контакты, включая отсутствие
строки. Изменение посторонней связи не требует нового confirmation.

## ACS-8.3.2 cover and obstacle subset

Optional `cover` и `obstacle` — только в object-template.v2 с category passive.
Они используют прежний object entity/snapshot/version, не отдельный barrier HP.

`cover` содержит ровно:

```json
{"schema":"ic-acs.cover.v1","category":"partial","capacity":2,
 "occupy_ap":1,"leave_ap":1,"speed_bonus":10,"def_bonus":20,
 "res_bonuses":{},"blocks_line_of_effect":false,"same_zone":true,
 "on_move":"leave","on_destroy":"remove"}
```

Category — partial/substantial/complete, без встроенных процентов/формул.
Capacity и AP — positive integers <=1000000; bonus — nonnegative integer
<=1000000. RES keys должны существовать в retained elemental resistance
context фактической цели, иначе UNSUPPORTED. Speed добавляется после прежних
stat modifiers, DEF/RES передаются в прежний Damage; immunities, penetration,
rounding, barriers и HP не заменяются. True damage сохраняет прежние правила.
blocks_line_of_effect задаётся независимо от category и bonuses.

Один объект может иметь несколько active assignments до capacity; у существа
не больше одного действующего cover (автоматического stacking нет). Занятие
требует active object и одной явно определённой зоны. Same-zone не создаёт
assignment автоматически. Назначение хранит полный pinned cover policy,
assignment_version и existing entity IDs. Добровольные wire действия
`cover:enter:<cover entity_id>` / `cover:leave:<cover entity_id>` имеют
`targets=[actor]`, parameters=[], стоимость по KB, отдельный seal. Перемещение
защищаемого прекращает assignment; GM relocation тоже журналирует прекращение.
Укрытие с inactive/expired/destroyed object или вне текущей зоны цели не даёт
бонус. Destruction снимает active assignments один раз; history не удаляется.

`obstacle` содержит schema=ic-acs.obstacle.v1 и edges (1..64), каждый:
from/to — разные zone keys, blocks_movement и blocks_line_of_effect boolean,
on_destroy=null либо `{status:open|blocked,line_of_effect:boolean}`. Binding
направленный, только внутри encounter карты; никаких координат или поиска
пути. Active obstacle — самостоятельный gate, а не автоматически назначенное
укрытие. При отсутствии spatial capability такой контракт UNSUPPORTED.
On_destroy изменяет существующую точную connection с увеличением версии;
неизвестная зона/connection блокирует всю операцию. Другие blockers того же
edge продолжают действовать. Terminal damage повторно не запускает последствия.

Ordinary/multiple abilities и прежний hidden_mass_effect могут явно задавать:

```json
{"environment_policy":{"schema":"ic-acs.environment-target.v1",
 "ignores_cover":false,"requires_line_of_effect":true,
 "affects_zone":null,"penetrates_obstacles":false}}
```

affects_zone — null либо точный zone key. Для explicit multiple проверяет
выбранные цели, не раскрывает и не выбирает скрытых существ автоматически.
Hidden-object mass серверно выбирает tag scope прежнего perception contract,
ограничивает его заданной зоной и проверяет LoE/blockers каждого объекта.
Игнорирование cover отключает bonus, но не blocks_line_of_effect/преграды.
Penetration разрешает object blockers, но не отменяет awareness/target policy,
range, базовую capability/LoE зоны или связи. Отсутствующий descriptor означает
ignores_cover=false, requires_line_of_effect=false, affects_zone=null,
penetrates_obstacles=false; ранее заданный spatial_range.line_of_effect также
остаётся самостоятельным gate. Неизвестные поля/направления не исполняются.

Intercept использует собственные position, cover policy, defenses и barrier
pools; same hit/crit samples пересчитываются для resolved target. Недоступный
по range/obstacle/cover interceptor пропускается до расхода реакции. Cover
исходной цели не переносится. Публичный DTO не содержит hidden assignment,
policy, obstacle identity или причин отказа; GM detail gate карточки сохранён.

Dependency set включает relevant target assignments, capacity при занятии,
object versions и directed blocker set, включая отсутствие записи. Не связанные
cover/obstacle objects исключены из прежнего глобального object hash, их
реальные чтения/изменения входят в scoped cover hash и SQL fence. Прежние
non-cover object dependencies сохранены. Изменение relevant assignment/wall
требует reconfirm на прежней tape; target не переназначается. GM correction
требует native/session GM, POST/CSRF, CAS, reason, operation key и existing
encounter.cover_assignment audit; произвольный policy JSON не принимается.

## ACS-8.3.3 integration bindings

Новых KB schemas/formulas/default bonuses нет. Object interaction и cooperative
operations проходят существующий spatial range, затем тот же object obstacle/
LoE gate Resolver. Assist дополнительно проверяет actual recipient: текущего
активного известного союзника, его range/LoE/stealth. Доступность самой задачи
не разрешает помощь недоступному recipient. Публичный progress и draft restore
используют ту же awareness/position policy, что catalog/prepare.

Привязка object action хранится сервером по action index, включая уникальную
consumes-tool reservation. Повтор одного ability key не заменяет reservation
предыдущего sealed action. Клиентский action shape/parameters не расширен.

Spatial configure сохраняет новый immutable encounter config snapshot вместе
с отдельным KB map snapshot. Для уже существующего base-only config snapshot
8.3.1 readiness допускает только две spatial extension fields после полной
проверки отдельного immutable spatial pin; остальные config fields по-прежнему
должны точно совпадать. Чтение не переписывает snapshots или конфигурацию.

Spawn identity остаётся source_event_id + spawn_index / existing entity_id.
После commit и обычного admission/position/awareness checks этот же объект
доступен поддержанным interactions/objectives/cover. Не вводятся provisional
client entity IDs, ссылки на ещё неизвестный future spawn или автоматическое
раскрытие цели в той же незавершённой публикации. R2 и Inventory gates прежние.
