# ACS-3.1.1 — Numeric Engine

Исходная main: `2224a36eef424b1ff55e4848e97572248fb00418`.
ACS: 0.10.0. Numeric policy: **binary64-half_up-v1** — идентификатор из
Technical Architecture, согласованный с существующими source payloads.
Нормативные документы и Design Baseline не изменены. Схема БД не меняется.

## Файлы и API

- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Numeric.php`:
  чистые `AF_ACS_Numeric` и неизменяемый terminal DTO `AF_ACS_NumericResult`.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/admin.php`:
  версия Numeric Engine в существующем разделе «Основные», после штатной проверки прав.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/manifest.php`: версия аддона.
- `tests/acs_numeric_regression.php`: самостоятельные числовые проверки без MyBB/SQL.
- Этот отчёт.

`number()` принимает только int/float; строки, boolean и null не преобразуются.
`operation(op,left,right)` выполняет ровно одну binary64 операцию в порядке вызовов:
add/subtract/multiply/divide/min/max либо unary negate. Нет AST, формул KB,
сортировки операндов, ассоциативных оптимизаций, округления или clamp внутри арифметики.

`terminal(raw,rounding='half_up',outputType='integer',min=null,max=null)` —
единственная объявленная выходная граница. Поддержаны none/floor/ceil/truncate/
half_up/half_even. Явный режим никогда не заменяется стандартным. Сначала rounding,
затем проверка совместимости результата с integer, затем clamp. Нецелые границы
integer-результата и min>max отклоняются до clamp. Режим none с дробным integer
результатом ошибочен, даже если clamp мог бы сделать его целым.

DTO содержит numeric_policy, raw, rounded, clamped, rounding, output_type.
Его нельзя передать обратно в terminal или operation: ALREADY_FINALIZED.
Извлечённый scalar не содержит provenance: будущий evaluator обязан сохранять
тип результата и declared boundary, а не вручную превращать DTO обратно в raw.
Самостоятельные результаты разных действий не объединяются здесь автоматически.
D1.13 allocation/barriers/HP loss не реализованы; двойного округления HP нет.

`integer(value,min=0,max=9007199254740991)` принимает только настоящий PHP int
и проверяет игровые границы без float coercion. `terminal(...,'integer')`
возвращает rounded/clamped как int. `encode()` сохраняет integer JSON типы,
проверяет round-trip исходных float и не меняет глобальный serialize_precision.
Если настройка хоста теряет дроби, возвращается SERIALIZATION_PRECISION.

## Ограничения и диагностика

Требуется 64-битный PHP с binary64 (проверяются int size, float digits/epsilon/max).
Поддерживаемый непрерывный безопасный диапазон чисел: абсолютное значение не выше
2^53−1. Для AP/HP/count/barrier дополнительно требуются целочисленный тип и
границы владельца ресурса. Большие числа и identifiers нельзя прогонять через
это арифметическое API; специализированные exact-ID payloads этапа 2 сохранены.

DomainException содержит стабильный безопасный ACS_NUMERIC_* код: NAN, INFINITY,
OVERFLOW, UNDERFLOW, DIVISION_BY_ZERO, UNSAFE_INTEGER, PRECISION_LOSS,
BOUNDS_INVALID, INTEGER_REQUIRED/RANGE, TYPE_INVALID, ARITY_INVALID,
OPERATION_UNSUPPORTED, ROUNDING_UNSUPPORTED, OUTPUT_INVALID,
ALREADY_FINALIZED, SERIALIZATION_PRECISION, PLATFORM_UNSUPPORTED.
В сообщениях нет исходного определения, SQL или пользовательских данных.

Binary64 не является decimal/arbitrary precision арифметикой. Конечные дроби
хранятся в представимом binary64 виде без промежуточного десятичного округления.
Полное поглощение ненулевого операнда при add/subtract и обнуление ненулевого
произведения/частного диагностируются; epsilon repair отсутствует. Уже потерянные
до вызова API цифры исходной десятичной строки восстановить нельзя. На границе
внешнего payload нужна отдельная проверка формата; парсер/AST — следующая задача.

## Проверки

PHP lint всех затронутых PHP-файлов и git diff --check — успешно.
`php tests/acs_numeric_regression.php` — успешно; без MyBB constants, SQL и globals.
Локальный PHP 8.4.24; целевой 8.5.0 требует ручной приёмки на сервере.

Примеры:

| Вход / операция | Результат |
|---|---|
| 1 / 3, затем ×3 | промежуточная дробь сохранена; 1.0 |
| 0.4 + 0.4, terminal half_up | raw 0.8, rounded/clamped 1 (int) |
| half_up ±0.5, ±1.5, ±2.5 | ±1, ±2, ±3 |
| half_even −2.5 | −2 |
| floor/ceil/truncate −2.5 | −3/−2/−2 |
| 2.6, half_up number, max 2.4 | raw 2.6 → rounded 3.0 → clamped 2.4 |
| none + integer 2.5 | INTEGER_REQUIRED, без скрытого half_up |
| деление на ±0, NaN/±Infinity | диагностируемый отказ |
| 1e15 / 1e−300; 1e−300 × 1e−300 | OVERFLOW; UNDERFLOW |
| 2^53−1; 2^53; (2^53−1)+1 | первое сохраняется; остальные UNSAFE_INTEGER |
| повтор terminal(DTO), operation(DTO,…) | ALREADY_FINALIZED |
| (1e15 + −1e15)+0.1 / 1e15+(−1e15+0.1) | 0.1 / 0.125: порядок не переассоциирован |

Проверены явный ноль, integer JSON, несовместимые bounds, все политики и
ошибка при недостаточном serialize_precision с восстановлением настройки fixture.
Отдельный bootstrap smoke: обычный showthread остаётся inactive, Numeric и
EncounterService не загружены. Полный suite, браузерные тесты, SQL и игровой
симулятор не запускались.
Bootstrap/Context, Clock и интерфейсы этапа 2 не изменены; Numeric подключается
только явно и в ACP main после авторизации. Нет нового публичного endpoint,
калькулятора, hooks, CSS/JS или записи состояния.

## Ручная приёмка / откат

1. ACP → AdvancedCombatSystem → Основные: Numeric Engine binary64-half_up-v1.
2. Открыть обычную тему и существующую панель ACS; AP/раунды/дедлайны прежние,
   обновление страницы не запускает арифметические команды и не меняет состояние.
3. На PHP 8.5.0 выполнить указанный точечный CLI regression и lint.

Откат — revert данного PR/возврат предыдущих файлов. Нет миграций, новых данных
или пересчёта исторических snapshots/events; существующие версии не переписываются.
ACS-3.1.2 не реализована. Статус: ready_for_manual_test.
