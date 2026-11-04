# ACS-3.2.2 — DEF/RES и Mixed Damage Allocation

Исходная main: `e86eeb65d05bcbd7951dd07819b672433f43288f`.
ACS 0.14.0; trace `acs-damage-1`; Numeric `binary64-half_up-v1`.
Статус: ready_for_manual_test. Design Baseline и четыре нормативных документа
не изменены. Миграций нет. ACS-3.3.1 не начата.

## Файлы

- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Damage.php`:
  чистые AF_ACS_Damage и AF_ACS_DamageResult.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/admin.php`:
  версия Damage в существующем защищённом ACP → Основные.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/manifest.php`: версия.
- `tests/acs_damage_regression.php`: числовые и owner-catalog fixtures.
- Этот отчёт.

HitCrit/Numeric/Formula/Modifiers подключаются по существующей чистой dependency
chain без изменений. Нового движка, RNG или gameplay command не создаётся.

## API и источники

`AF_ACS_Damage::calculate(components,target,penetration,elementCatalog)` возвращает
typed result; `attempt(...)` — ready/evaluation/structured diagnostics.
Входы передаёт доверенный серверный caller из авторизованных pinned sources:

```text
components[]:
  source_index: уникальный исходный индекс KB (int >=0)
  damage_type: physical | elemental | true
  damage_element: явная каноническая стихия для elemental; иначе null/отсутствие
  raw: исходная величина >=0
  after_crit: уже рассчитанная величина >=0, до DEF/RES

target:
  def: неотрицательное число (обязательно для physical)
  resistances: canonical key -> {scope:elemental, unit:RES, value:nonnegative}
  vulnerabilities: canonical key -> ordered nonnegative numeric contributions[]
  immunities: canonical key -> boolean

penetration:
  physical: {percent:ordered numeric[], flat:ordered nonnegative numeric[]}
  elemental: canonical key -> {percent:ordered numeric[], flat:ordered nonnegative numeric[]}
```

Optional collections отсутствуют при отсутствии соответствующей возможности;
пустые списки означают отсутствие её вкладов. Явный null не превращается в список.
Обязательные DEF/RES/raw/after_crit не дополняются нулями. RES проверяется для
каждого присутствующего ключа и обязательно для используемой стихии, даже при
её иммунитете. Неполный legacy набор не исправляется; неиспользуемая отсутствующая
RES не нужна конкретному вычислению.

ElementCatalog — готовый `KnowledgeBaseAdapter::primaryElements(actorUid)`
IC-KB-2/schema1 с 17 первичными identities, af_kb.element.v1,
primary-elements-17-v1/wind-air-v1, source versions/fingerprints. В Domain нет
собственного списка стихий/aliases, запроса KB или синтеза отсутствующих записей.
Даже true/physical расчёт принимает тот же утверждённый registry contract.
Владелец KB выполняет wind→air; Domain принимает только canonical air.
Дочерние направления blood/metal/sound не являются ключами этого каталога.
Из magic direction не выводится damage_element.

Caller отвечает за авторизацию/целостность retained sources, явный фактический
тип воздействия и соответствие компонентов definitions. Domain проверяет форму,
контракт и числа, но не доказывает подлинность произвольного клиентского массива.
Публичного endpoint для этих входов нет. Порядок списков вкладов penetration/
vulnerability должен быть зафиксирован upstream; ассоциативность не меняется.

## D1 формулы и порядок

```text
pct = clamp(sum(percent_penetration),0,100)
flat = sum(nonnegative flat_penetration)
effective = max(0, defense*(1-pct/100)-flat)
post_defense = after_crit*100/(100+effective)
elemental_post = post_defense*(1+sum(nonnegative vulnerability[element])/100)
```

Операции идут через Numeric в сохранённом порядке, без промежуточного rounding.
Уязвимости аддитивны, отдельны от RES, без нового глобального cap. Отрицательные
RES/flat/vulnerability отклоняются. Percent sum ограничивается до 0..100.
DEF и RES цели не изменяются; отдельного контрпробивания нет.

Иммунитет обнуляет только соответствующий elemental-компонент до суммы/бюджета,
не отменяется уязвимостью и не скрывает отсутствующую RES. Валидная иммунная ветвь
может пропустить бессмысленное умножение урона, но её defence/данные проверяются.
True сохраняет after_crit без DEF/RES, помечается bypasses_ordinary_barriers=true.
Это метаданные для будущего обработчика, а не расход барьера или HP.

## D1.13: одна целочисленная граница

Компоненты сортируются по уникальному KB source_index, не по входному SQL-порядку.
Сохраняются все дробные post_defense. Их сумма один раз проходит Numeric terminal
half_up/integer. Floor каждого компонента используется только для largest remainder,
а не как самостоятельный terminal damage. Остаток B−sum(floor) распределяется
по убыванию фактической binary64 дробной части, при равенстве — source_index ascending.
Никакого epsilon tie repair нет. Компоненты возвращаются в исходном KB порядке.

Allocated/B/allocation_sum — int, строго sum(allocated)=B. Нулевая/иммунная ветвь
не получает искусственную единицу. Несогласованность precision/остатка блокируется
как damage_allocation_precision. Повторного округления HP/барьеров здесь нет;
будущий consumer обязан расходовать уже распределённые целые без нового rounding.

## DTO и секретность

`serverData()` содержит trace_version/numeric_policy, total_raw, total_after_crit,
total_post_defense, integer_budget, allocation_sum и компоненты:
damage_type, element, source_index, raw, after_crit, effective_defense, penetration,
vulnerability, immunity, post_defense, floor/remainder, allocated и bypass flag.
`serverTrace()` — registry/alias/source versions/fingerprints, allocation source-index
order, terminal raw/rounded/clamped и stage=before_barriers_hp.

Обычная JSON-сериализация result показывает только ready и версии. Полный DTO/trace
предназначен серверу; скрытые DEF/RES/иммунитеты нельзя отправлять игроку напрямую.
Используются безопасные AF_ACS_FormulaError/Numeric diagnostics без значений цели.

## Минимальные проверки

PHP lint затронутых файлов и git diff --check — успешно.
`php tests/acs_damage_regression.php` — успешно с существующим read-only KB/CS/
adapter fixture; production не использовался. Все 17 RES проверены по реальному
каталогу адаптера, air имеет provenance source_key=wind. Unknown wind/blood/metal/
sound на Domain boundary отклоняются; alias таблица не дублируется.

| Пример | Результат |
|---|---|
| physical raw100, DEF100 | post50, B50 |
| elemental100, RES100, vulnerability20+30 | post75 |
| DEF100, pen20+30%, flat10+10 | effective30, B77 из 10000/130 |
| pen80+80% / pen−30% | clamp100 / clamp0 |
| иммунитет + уязвимость | allocated0 |
| raw1, DEF1000 | post>0, B0; минимум1 не придуман |
| 0.4+0.4 | B1, allocation[1,0] |
| 2.7+1.8+0.6 | B5, allocation[3,2,0] |
| равные0.4 с индексами10/2 | индекс2 получает единицу |
| true10 при DEF/RES/element immunity | B10, effective=null |
| HitCrit raw100 → crit150 → DEF100 | post75 |

Проверены перестановка входных компонентов с неизменным DTO/trace, недостающая
RES даже при immunity, отрицательные значения, дубли индексов, отсутствие явного
элемента, приватность JSON. Domain не меняет fixture tables и не выполняет даже
read queries после получения источников. Полный suite, SQL/E2E не запускались.
Отдельный showthread smoke подтвердил inactive без загрузки Damage/Numeric.
Локально PHP 8.4.24; PHP 8.5.0 требует ручной приёмки.

Технические границы Numeric сохраняются: конечность, безопасные целые/промежуточные
величины и отказ при потере допустимой точности. Огромное intermediate может
блокироваться даже при небольшом теоретическом результате; порядок не переписывается.
Не реализованы HP, барьеры, отражение, вампиризм, статусы, публикация и Inventory.

## Ручная приёмка / откат

1. ACP ACS → Основные: Damage acs-damage-1.
2. Запустить точечный regression на PHP8.5.0.
3. Проверить обычную тему, KB, CS и интерфейс этапа2: состояние прежнее,
   просмотр не рассчитывает урон и не изменяет ресурсы.
4. Для доверенной диагностики использовать pinned canonical catalog/definitions
   и serverData/serverTrace только с отдельной политикой видимости.

Откат: revert PR. Миграций/записей нет, исторические snapshots не пересчитываются.
