# ACS-3.1.2 — Formula Compiler / AST Evaluator

Исходная main: `b51b25027d0abc3730e726371ab14f112cbdee4a`.
ACS 0.11.0; compiler `acs-formula-1`; formula schema `af_kb.arpg.formula.v1`;
numeric policy `binary64-half_up-v1`. Статус: ready_for_manual_test.
Design Baseline, KB/CharacterSheets, Numeric и схема БД не изменены.

## Файлы

- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Formula.php`:
  AF_ACS_Formula, AF_ACS_FormulaEvaluation, AF_ACS_FormulaError.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php`:
  новый read-only метод readFormula.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/admin.php`:
  сведения о compiler/schema в существующем защищённом ACP → Основные.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/manifest.php`: версия.
- `tests/acs_formula_regression.php`: изолированный AST + KB adapter fixture.
- Этот отчёт.

## Источник и pins

`KnowledgeBaseAdapter::readFormula(pin, actorUid, expectedUnit)` проверяет
текущего пользователя, enabled и права KB. Использует реальные
`af_kb_arpg_acs_reference('formula_def', ...)`, source selector, structural
validators и `af_kb_arpg_formula_snapshot()`. Поддержаны отдельная запись и
aggregate formula_def; существующая неоднозначность ключа даёт отказ.
Проверяются видимость обоих возможных источников, активность, повторное чтение
source fingerprint/id, точные key/version/fingerprint и единица.

Выход ready=true содержит `data.formula` — снимок B2, provenance записи и
executable=false. Это разрешение структурного чтения; не combat capability и
не подтверждение боевой применимости. Флаг KB executable=false не переписывается.
Адаптер не вычисляет AST, не ищет latest и не подбирает legacy preview.
Отсутствующие/несовместимые/скрытые данные возвращают безопасные diagnostics
без raw, определений или подробных внутренних сообщений.

Чтение live KB допустимо только для получения копии с уже известными pins.
При несовпадении pins копия не возвращается. Последующее исполнение читает
только переданный сохранённый snapshot, без адаптера/KB/SQL. Изменение KB не
пересобирает ранее скомпилированную формулу. Историю версий KB этот PR не создаёт.

## Compiler и evaluator

`AF_ACS_Formula::compile(snapshot, pin, expectedUnit)` возвращает закрытый
скомпилированный объект после полной проверки. Pin содержит key, version и
fingerprint вида sha256:<64 lowercase hex>. Fingerprint пересчитывается по
той же канонизации B1/B2: сортируются ключи объектов, сохраняются порядок списков
и типы скаляров. Вложенная структура проверяется до канонизации. JSON round-trip
контролирует точность и отделяет PHP references от retained definition.

Используется существующая грамматика:

| Узел / операция | Аргументы |
|---|---|
| literal | конечный JSON int/float, без строк/boolean |
| input | объявленное имя из inputs |
| add, subtract, multiply, divide | ровно 2 |
| negate | ровно 1 |
| min, max | 2–8 |

Неизвестные узлы/операции/поля отклоняются. Пределы KB: 256 узлов,
16 рёбер глубины, 64 объявленных входа; имена/типы/key/version/active/notes,
output_type, rounding, bounds проверяются по B2 с ограничениями Numeric.
Константные поддеревья не вычисляются при компиляции: constant div0 компилируется
структурно и диагностируется при evaluate. Нет нового синтаксиса, eval,
function dispatch, URL lookup, индексации объектов или формульных ссылок внутри AST.

`compiled->evaluate(dynamicInputs, parameters=[])` возвращает typed
AF_ACS_FormulaEvaluation. Все объявленные inputs обязательны; лишние имена,
неверные JSON-типы и совпадение ключей parameters/dynamicInputs отклоняются,
даже если значения одинаковы. Параметры профиля должен передавать доверенный
вызывающий слой из своего pinned источника; этот PR не выбирает профиль/боевой
контекст и не принимает пользовательские параметры через новый endpoint.

Выполнение — фиксированный postorder слева направо по сохранённым args.
Min/max сворачиваются слева направо. Нет constant folding/reassociation,
коротких путей, округления промежуточных результатов или повторного расчёта CS.
Вся арифметика проходит Numeric; на выходе один terminal с явной политикой
формулы и затем clamp. Terminal DTO не превращается обратно в raw автоматически.

`AF_ACS_Formula::attempt(...)` — structured boundary: ready, evaluation либо null,
diagnostics. Ошибки compile/evaluate имеют тип AF_ACS_FormulaError и безопасные
severity/category/code/path. Подробных значений в ошибках нет.

## Единицы и трасса

B2 не содержит единиц для каждого input, literal или ребра. Проверяется
существующий output_unit token и точное совпадение с expectedUnit вызывающего
слоя. Нет выдуманной размерностной алгебры или неявной конвертации. Реальные
единицы входного боевого контекста должен подтверждать будущий typed caller.

`evaluation->serverResult()` даёт NumericResult raw/rounded/clamped/type/rounding.
`serverTrace()` — только для доверенного сервера/авторизованной диагностики:
compiler/schema/numeric versions, pinned ref, входы и параметры, output_unit,
упорядоченные шаги с индексами аргументов и raw, terminal trace.
Обычная JSON-сериализация evaluation возвращает только readiness/версии/diagnostics;
скрытые inputs, результаты и trace не сериализуются автоматически. Новый публичный
HTTP-калькулятор или endpoint не создан. Доступ к сохранённым snapshots и
раскрытие конкретного результата остаются обязанностью авторизованного caller.

## Проверки / диагностика

PHP lint всех затронутых PHP, git diff --check — успешно.
Точечные scripts:
- `php tests/acs_formula_regression.php`;
- `php tests/acs_numeric_regression.php`;
- `php tests/acs_integration_adapters.php` (затронутый существующий adapter).

Все прошли локально на PHP 8.4.24; target PHP 8.5.0 требует ручной проверки.
Отдельный showthread bootstrap smoke: inactive, Formula/Numeric/KB не загружены.
БД fixture разрешает только чтение и не имеет методов записи. Полный suite,
SQL-миграции, production-записи и браузерные тесты не выполнялись.

Проверены:
- 2+3=5; вложенная amount + 2×factor с pinned parameter 0.25: raw10.5 → integer11;
- все операции, min с восемью аргументами, стабильная трасса и группировка binary64;
- политики floor/ceil/truncate/half_up/half_even; none сохраняет 1/3;
- допустимые 256 узлов и глубина16, отказы при превышении и 65 inputs;
- div0, overflow, Infinity, неверный тип, отсутствующий/лишний input,
  неизвестная операция, неверная arity, поля вне схемы, collision параметров;
- неизвестная schema, version mismatch, fingerprint mismatch при том же version,
  несовпадение output_unit, неверные bounds и rounding;
- реальные KB snapshot/fingerprint/reader, закрытая запись, spoofed actor,
  отключённый KB, отсутствие записей, retained replay после live-изменения;
- неизменность compiled snapshot после изменения PHP reference исходного массива;
- безопасная JSON-сериализация без скрытого trace.

Используются существующие коды invalid_formula_contract, formula_version_mismatch,
formula_parameter_mismatch, unsupported_operation, inactive_definition и ACS_NUMERIC_*.
Добавлены formula_fingerprint_mismatch, unsupported_formula_schema,
formula_ast_limit, formula_input_undeclared/missing/collision, formula_arity_invalid,
formula_unit_mismatch. Отсутствующий owner/reference сохраняет существующие коды адаптера.

## Ручная приёмка / ограничения

1. ACP → ACS → Основные: compiler acs-formula-1, schema af_kb.arpg.formula.v1.
2. Для существующего доступного formula_def получить exact key/version/fingerprint
   штатного KB snapshot. В доверенном серверном диагностическом вызове использовать
   readFormula(pin, currentActorUid, exactOutputUnit): ready=true без вычисления.
3. Передать полученный data.formula в compile с теми же pins, затем evaluate с
   объявленными inputs/параметрами. Рассматривать serverTrace только с отдельными
   разрешениями на исходное определение. Публичного ввода PHP/SQL в ACP нет.
4. Изменить тестовую KB-формулу: старый retained snapshot даёт прежний результат,
   новое readFormula со старым pin возвращает mismatch, без автоматического fallback.
5. Открыть обычную тему, панель ACS этапа2, KB editor и CharacterSheets: нет нового
   глобального runtime, синхронизации или изменения данных.

Выбор/pinning formula_profile, единицы динамического контекста, modifier pipeline,
Resolver, публикация и persistence результатов не реализованы. Доступ к production
определениям здесь не проверялся. Существующие формулы/KB записи не создавались.
Откат — revert PR; миграций/новых данных нет. ACS-3.1.3 не начата.
