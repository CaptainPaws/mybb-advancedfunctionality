# KB: типизированные определения формул и модификаторов, B2

## Граница ответственности

KB проверяет структуру математических определений, сохраняет их обычным путём `kb_edit` и передаёт будущему ACS. **Исполнителя в KB нет.** AST не вычисляется, строки PHP не принимаются, новые коэффициенты/боевые формулы не добавлены. CharacterSheets, его `af_charactersheets_arpg_build_formula_profile_applied()` и существующие damage/heal/shield/crit previews не изменены.

Основание: `ACS_KB_MECHANICS_AUDIT.md` (G2/G3/G6), `ACS_KB_NORMALIZATION_CONTRACT.md` (API B1), `ARPG_INTEGRATION_CONTRACT.md`. B2 добавляет типизированные данные и небольшие hooks к B1; выбор JSON-проекции, raw, fingerprint родительской записи и precedence CharacterSheets остаются прежними.

## formula_profile и formula_def

- `formula_profile` остаётся справочником пользовательского выбора. Его key/title/group/calc_family/duration_supported/ui_hint/active и существующие названия не изменяются.
- `formula_def` содержит машинное математическое правило. Хранение: `af_kb_entries(type=arpg_mechanics)`, envelope `af_kb.arpg.meta.v1`, `rules.service_kind=formula_def`, **`rules.entries[]`**. Можно использовать реестр с ключом записи `formula_def` либо отдельную запись, ключ которой совпадает с ключом определения в её entries. Новые определения в root rules вместо entries не создаются.
- Новые необязательные поля строки профиля: `formula_def_key`, `formula_def_version`, `parameters`. Если ссылка задана, key/version задаются парой; версия — точная строка, без диапазона, latest или автоматического выбора.
- Профиль без ссылки по-прежнему допустим при сохранении и работает в CharacterSheets как прежде. В ACS API отсутствие связи даёт `formula_binding_missing`, `supported=false`; старый calc_family не используется для угадывания формулы.

Нет автоматической генерации formula_def из legacy previews, установки ссылок старым профилям, миграций или перезаписи существующих записей на чтении. Опциональные поля редактора не добавляются в профиль просто при загрузке формы.

## Контракт formula_def

`af_kb_arpg_math_contract()` публикует описание `af_kb.arpg.formula.v1`; schema-id находится в snapshot, а не обязательным полем строки определения. Структура одной строки entries:

| Поле | Серверное требование |
|---|---|
| key | Строка `[a-z][a-z0-9_-]{1,63}`, уникальная внутри реестра |
| version | Явная строка содержимого, 1–64 символа: буквы/цифры, затем также `.`, `_`, `-` |
| inputs | Объект `имя → number или integer`, максимум 64 переменные; имена `[a-zA-Z][a-zA-Z0-9_]{0,63}` |
| expression | JSON AST по грамматике ниже; строка математического выражения или PHP запрещена |
| output_type | number или integer |
| output_unit | Явный непустой токен единицы; смысл/конверсия единиц здесь не определяются |
| rounding | Обязательно: none, floor, ceil, truncate, half_up, half_even; значение по умолчанию не подставляется |
| min_value / max_value | Необязательные конечные JSON-числа; если заданы оба, min≤max; null/строки не заменяют число |
| notes | Обязательная строка описания, допускается пустая |
| active | Необязательно: boolean или JSON 0/1 |

Неизвестные поля новой formula_def отклоняются. В числовых полях допускаются JSON integer/float, но не числовые строки, boolean, NaN/Infinity. Явный ноль сохраняется. Unit/phase tokens: буква, затем буквы/цифры/`_`/`.`/`/`/`-`, максимум 64 символа. Это идентификаторы, не инструкция произвольно смешивать единицы.

AST:

```json
{"type":"literal","value":0}
{"type":"input","name":"amount"}
{"type":"operation","operator":"add","args":[
  {"type":"input","name":"amount"},
  {"type":"literal","value":0}
]}
```

Допустимые операции: add/subtract/multiply/divide ровно с двумя аргументами, negate с одним, min/max с 2–8. В каждом узле разрешены только указанные ключи. Input должен быть объявлен в inputs. Глубина не более 16 рёбер от корня, всего не более 256 узлов; entries — список максимум из 128 определений.

Нет function-call, доступа к объектам/массивам по индексу, строковых литералов, присваивания, кода, внешних данных или AST-ссылок на другие формулы. Сервер проверяет grammar/types/arity/limits, но **не вычисляет** даже константные поддеревья. Деление на ноль, переполнение, результат integer, единицы, момент округления и применения границ проверит будущий исполнитель по утверждённому механическому правилу. Выбор порядка этих действий в B2 не сделан.

Пример определения без игровых коэффициентов:

```json
{
  "key":"identity_value",
  "version":"1",
  "inputs":{"amount":"number"},
  "expression":{"type":"input","name":"amount"},
  "output_type":"number",
  "output_unit":"stat_points",
  "rounding":"none",
  "notes":"Пример передачи входного значения, не правило баланса"
}
```

## Связь профиля и параметры

Пример дополнительных полей профиля:

```json
{
  "formula_def_key":"identity_value",
  "formula_def_version":"1",
  "parameters":{"amount":0}
}
```

Parameters — необязательный объект именованных конечных JSON-чисел. При разрешении связи ACS API проверяет, что каждый параметр объявлен в inputs, а integer-параметры имеют JSON integer тип. Это фиксированные параметры профиля; остальные inputs должен предоставить будущий Resolver. Коллизии между параметрами и динамическими входами он обязан обрабатывать явно, без незаметной подстановки.

На сохранении проверяется структура новой связи. Разрешение существующей записи, соответствие pinned version и inputs проверяются при чтении ACS. Это позволяет подготовить профиль до публикации определения; такое определение ещё не готово к использованию ACS. Неподдержанные/конфликтующие/неактивные источники не подменяются seed fallback.

Key должен однозначно разрешаться среди отдельной записи и реестра. Дубликаты entries отклоняются при сохранении; коллизия между реестром и отдельной записью при чтении даёт `formula_key_ambiguous`. Исторических версий под одним key в entries нет: key уникален, текущая версия ровно одна. Неявного выбора «первой» из двух подходящих формул нет.

## Новый opt-in контракт модификатора

Для новой математики используется **отдельный** `math_modifiers[]` в корневом rules предмета/standalone-способности/другого ARPG определения. Шаблоны можно хранить в `arpg_mechanics` с `service_kind=modifier_template`, entries — список контрактов. Вложенные opt-in контейнеры в character_abilities/ability_keys на этом этапе отклоняются: старые inline-проекции могут их потерять. Для таких механик следует использовать отдельное ARPG-определение; форматы анкет не расширяются.

```json
{
  "schema":"af_kb.arpg.math-modifier.v1",
  "stat_key":"atk",
  "operation":"add_flat",
  "value":0,
  "unit":"stat_points",
  "phase":"example_phase",
  "priority":0,
  "condition_ref":null
}
```

Все показанные поля обязательны. Дополнительно разрешены min_value/max_value — конечные числа с min≤max. Неизвестные поля отклоняются. Stat_key должен присутствовать в существующем KB stat registry; priority — JSON integer. Condition_ref — null для безусловного определения либо `condition_template:<key>`; ACS API разрешает ссылку тем же reader B1, но условие не вычисляет.

Операции:

| operation | Намерение автора определения |
|---|---|
| add_flat | Плоская прибавка |
| add_relative_percent | Относительный процент с явно заданной unit |
| multiply | Множитель |
| set | Установка значения |
| clamp_min | Нижняя граница |
| clamp_max | Верхняя граница |

Value — явный числовой операнд. Unit, phase, priority и bounds передаются как данные; сами по себе они не утверждают фазовый порядок, композицию процентов, величину базиса, направление сортировки приоритетов, порядок clipping/rounding или решение равных приоритетов. Это должен задать отдельный утверждённый контракт исполнения ACS. B2 не применяет операции и не выбирает эти правила автоматически.

**Не класть новый контракт в legacy `modifiers[]` или `base_stats[]`.** CharacterSheets мог бы прочитать его как flat-строку. При обнаружении нового schema marker в этих контейнерах сервер отклоняет сохранение, ACS API возвращает диагностику. Старые flat/percent строки не преобразуются; их числовое аддитивное поведение не меняется. Отдельный math_modifiers не читается существующей арифметикой CharacterSheets.

## Редактор и сохранение

Существующий `kb_edit`, без нового маршрута/таблиц:

1. Выбрать тип arpg_mechanics и service_kind=formula_def. Для нового вида может понадобиться сохранить вид записи и открыть её снова — редактор выбирается при рендере формы.
2. В entries добавить определение; заполнить key/version, inputs и expression как JSON, output type/unit, rounding и notes. Пустая новая строка не является валидной формулой.
3. Для formula_profile заполнить необязательные key/version/parameters. Пустые optional поля удаляются только при явном редактировании, а не создаются при загрузке.
4. Для нового modifier_template используется компактный JSON entries editor; math_modifiers у публичных ARPG типов также имеет отдельное необязательное JSON-поле.

Невалидный JSON в этих полях отмечается браузерной валидацией ввода; сервер независимо проверяет итоговую структуру при сохранении. Min/max очищаются как отсутствие, не превращаются в ноль. Путь `af_kb_validate_rules_json_by_type_arpg()` → service validator сохраняет новые поля; `af_kb_normalize_arpg_mechanics_entry_row_by_service_kind()` больше не обрезает typed formula_def и tagged modifier_template. Дополнительные поля профиля возвращаются только при исходном наличии.

## Нормализованный вход будущего Resolver

Публичный API B1 тот же: `af_kb_arpg_read_definition()` и `af_kb_arpg_normalize_entry()`.

- У service formula_def добавляется `normalized.formula_definitions[]` с валидированными snapshots.
- У service formula_profile добавляется `normalized.formula_bindings[]` с результатом разрешения, параметрами и snapshot формулы либо null.
- В references способности/эффекта поле formula_ref разрешается как formula_def; formula_profile — как профиль с отдельной связью. Между ними по-прежнему нет alias.
- При наличии math_modifiers добавляются данные контейнера и ссылки `math_modifier_conditions`. Исходные параметры и raw сохранены; вложенные неподдержанные контейнеры дают ошибку.
- Новые ошибки: invalid_formula_contract, formula_binding_missing, formula_version_mismatch, formula_parameter_mismatch, formula_key_ambiguous, invalid_math_modifier; сохраняются ошибки источников и ссылок B1.

Snapshot формулы:

```text
schema: af_kb.arpg.formula.v1
key: исходный ключ определения
version: явная версия содержимого
fingerprint: sha256 по содержимому данной formula_def
definition: полный валидированный payload, включая inputs/expression/output/rounding/bounds
executable: false
```

Fingerprint записи B1 и fingerprint отдельной формулы — разные уровни: изменение другого элемента aggregate не меняет fingerprint самой формулы. Parameters профиля возвращаются отдельно; profile definition/provenance также присутствует в reference.

`supported=true` означает только отсутствие ошибок подготовки B1/B2. **`executable` остаётся false везде.** Наличие валидного AST не означает наличие исполнителя, одобренного баланса, корректного dynamic context или безопасного результата конкретного действия.

Будущий ACS должен сохранить самостоятельный snapshot formula + profile parameters + fingerprints + входные значения и правила исполнения в своей сессии/событии. Пересчитывать уже записанное событие через текущий KB lookup нельзя. Изменение содержимого при прежней заявленной version обнаруживается по fingerprint; KB не хранит полный revision history и не гарантирует неизменность строки. При несовпадении pinned version готовой формулы не возвращается; восстановить старое событие обязан снимок ACS, не поиск «последней похожей» формулы.

## Проверки и ограничения

Точечные проверки PHP 8.5: typed validator и save/read JSON round-trip; сохранность AST/inputs/version/profile fields; legacy-профили без новых полей; успешная связь и нулевые parameters; несовпадение версии/параметров/ключей; отклонение PHP-строк, неизвестных узлов/операторов/полей, неверной arity, необъявленных inputs, бесконечных значений и избыточной глубины; неизменная legacy percent арифметика и игнорирование нового контейнера старым helper. Lookup проверяется изолированным read-only double границы MyBB DB, без производственной БД и сохранений на сайт.

Также проверяются PHP lint, JS syntax, whitespace/conflict markers и неизменность CharacterSheets. Полный suite, браузерные/нагрузочные тесты, боевые расчёты и миграции не выполняются. Live DB/UI round-trip не подтверждён браузером; логика save/read проверяется серверным контрактным путём.
