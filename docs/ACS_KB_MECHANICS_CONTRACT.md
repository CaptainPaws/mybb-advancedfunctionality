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
