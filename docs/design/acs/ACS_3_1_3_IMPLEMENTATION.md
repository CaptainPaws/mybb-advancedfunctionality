# ACS-3.1.3 — Modifier Pipeline

Исходная main: `1d96c711e7d3b26929c69edefec2dfcdbfede3bd`.
ACS 0.12.0, modifier policy `acs-modifiers-1`, numeric policy
`binary64-half_up-v1`, схема KB `af_kb.arpg.math-modifier.v1`.
Статус: ready_for_manual_test. Design Baseline и legacy CS/KB не изменены.

## Файлы

- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/Modifiers.php`:
  AF_ACS_Modifiers и AF_ACS_ModifierResult.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/admin.php`:
  версия pipeline в существующем защищённом разделе «Основные».
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/manifest.php`: версия.
- `tests/acs_modifiers_regression.php`: числовые примеры и настоящий CS fixture.
- Этот отчёт.

Нет миграций, глобальных hooks/assets, публичного калькулятора, Resolver или
изменений CharacterSheets, Numeric, Formula и пользовательских определений.

## API и границы стадий

`AF_ACS_Modifiers::stage(base, stat, phase, sources, policy, combatSnapshot=null)`
возвращает typed AF_ACS_ModifierResult. `attempt(...)` возвращает ready,
evaluation/null и structured diagnostics. Ошибки используют существующий
AF_ACS_FormulaError и безопасные Numeric-коды; значений определений в них нет.
Formula используется как существующая граница ошибок; формульных полей у B2
math_modifier нет, поэтому AST не придумывается и scalar value не исполняется как код.

Один вызов вычисляет **одну явно объявленную стадию/фазу**. Policy содержит
ordered `phases`, `stat_keys` из нормализованного KB registry, точные `unit`,
`percent_unit`, `factor_unit`, выходные `rounding`/`output_type` и необязательные
min_value/max_value. Все токены и выбранная фаза проверяются. У baseline нет
универсального списка фаз: caller обязан передать порядок утверждённого pinned
контракта, а не сортировать произвольные имена лексикографически. Другие объявленные
фазы/характеристики source валидируются, но не исполняются данным вызовом.
Полный межстадийный orchestration будущего Resolver здесь не реализован.

Каждая стадия является явной выходной границей. Нельзя дробить одну стадию на
несколько вызовов ради повторного округления. Для дробного выхода caller задаёт
number/none; engine не подставляет half_up вместо явно заданной политики.

Sources — доверенные retained snapshots вызывающего серверного слоя:

```text
snapshot_id: положительная decimal string (MyBB BIGINT, без float coercion)
source_id: стабильная identity, например inventory:9
source_type: токен вида источника
source_version: {fingerprint: sha256:...}
modifiers: список исходных math_modifiers, индексы сохраняются
source_path: исходный bucket, по умолчанию math_modifiers
```

Это метаданные ACS вокруг неизменённых строк KB, не новые поля KB schema.
Повтор snapshot_id отклоняется: нельзя удвоить один и тот же источник.
Существование SnapshotStore ID/права/соответствие hash полному исходному определению
доказывает доверенный caller/adapter до Domain; engine не делает SQL или live lookup.
Он проверяет типы метаданных, точные индексы и evidence включения в CS.

## Вычисления и tie-break

Внутри выбранной стадии полный порядок:
phase rank → operation type → priority ascending → numeric source snapshot ID
ascending → исходный modifier index ascending. Порядок типов:
add_flat, add_relative_percent, multiply, set, clamp_min, clamp_max.
Priority не переставляет flat после multiply. Равные ключи разных source невозможны
из-за уникальности snapshot_id, внутри source уникален исходный index.

Без set:

```text
v_flat = base + sum(add_flat)
v_percent = v_flat * (1 + sum(add_relative_percent)/100)
v_mult = v_percent * product(multiply)
```

Суммы и произведения выполняются Numeric последовательно по сохранённому
порядку; промежуточного округления нет. Set с максимальным priority задаёт итог
стадии. При равных максимальных priority разные числовые значения дают
modifier_set_conflict, одинаковые совместимы. Int7 и float7.0 — одно числовое
значение; для provenance выбирается первый по полному tie-break. Проигравшие set
не переопределяют победителя. Обычные бонусы не добавляются поверх set.
Ошибки Numeric в объявленных арифметических источниках не маскируются set.

Границы policy и применяемых stat-unit модификаторов пересекаются с clamp_min/
clamp_max. Противоречие — modifier_bounds_conflict. Для integer проверяется
целочисленность **каждой** границы, даже если другая граница могла бы её скрыть.
Финал: один Numeric terminal (rounding → clamp). Clamp не лечит ошибочную схему,
неверный тип или несовместимую единицу.

Проценты требуют exact percent_unit, множители — factor_unit, остальные типы —
unit характеристики. Смысл токенов задаёт утверждённый caller contract; конверсий
нет. Bounds у процентного/множительного операнда не имеют утверждённой размерностной
семантики B2: modifier_bound_unit_unsupported, вместо догадки об ограничении
значения характеристики. Condition_ref=null исполняется; ненулевой ref требует
будущего condition evaluator и даёт modifier_condition_unresolved для этой стадии.
Ни одна opt-in возможность не включается автоматически.

## Equipment и уже включённые источники

Для CS-рассчитанного base передаётся настоящий CombatSnapshot этапа2. Проверяются
schema/contract/numeric version, ready, fingerprint, equipment_policy и совпадение
base с characteristics[stat]. Отсутствующая/неполная evidence блокирует расчёт.
Для equipment дополнительно совпадают inventory instance, KB source fingerprint,
source_path/index, stat, value, operation и included_in_total=true в breakdown.
Идентичность inventory:* нельзя обойти, назвав источник effect.

Подтверждённо включённый бонус только отмечается в trace и исключается из расчёта.
Legacy flat/percent могут быть прочитаны как evidence предыдущего включения,
но никогда не исполняются/не конвертируются в ACS-арифметику. Исходный legacy bucket
допускается исключительно для такого исключения экипировки.
Новый/неподтверждённый equipment delta — equipment_provenance_incomplete.
Для прочих уже известных CS источников с агрегированным breakdown (origin/growth,
таланты и т.п.) нет доказательства отдельного opt-in delta: source_breakdown_unavailable,
без повторного применения. Новые эффекты с независимой подтверждённой source identity
могут применяться поверх calculated base. Полные адаптеры экипировки/эффектов здесь
не добавлены; старый CS snapshot с неподдержанными math_modifiers остаётся BLOCKED.

Result: serverResult() возвращает NumericResult, serverTrace() — policy/phase/order,
base, ordered source identities, included flags, суммы, промежуточные стадии,
выигравший set и terminal trace. Обычная JSON-сериализация показывает лишь
readiness и версии, без защищённых характеристик и источников.

## Проверки

- php -l затронутых PHP, git diff --check — успешно.
- `php tests/acs_modifiers_regression.php` — успешно.
- `php tests/acs_numeric_regression.php` — успешно.

Примеры: (100+10+10)×(1+(20+10)/100)×2=312; priority−99 у multiply не перемещает
его перед flat priority999; источники 2/10 сортируются численно; перестановка
входного массива не меняет trace/result. Set7 priority2 побеждает set20 priority1
и совместим с set7.0 priority2; set8 priority2 даёт конфликт. Min5/max4 дают
ошибку. Raw2.6 → half_up3 → max2.4 даёт clamped2.4 для number; integer граница2.4
ошибочна. Неизвестные фазы/единицы, conditions, Infinity, дубли source ID отклоняются.

Использован реальный read-only CS/KB fixture: base Accuracy20, предмет+5,
calculated25. Pipeline с inclusion evidence сохраняет25; потеря breakdown,
подмена base и маскировка inventory источника блокируются. Таблицы fixture
не меняются; fixture не имеет SQL writer API. Строка модификатора проверена
настоящим KB validator. Полный suite, SQL/production и браузерные тесты не запускались.
Отдельный showthread bootstrap smoke подтвердил inactive без загрузки Modifiers/Formula.
Локальный runtime PHP 8.4.24, target PHP 8.5.0 требует ручной приёмки.

## Ручная приёмка и откат

1. ACP ACS → Основные: Modifier Pipeline acs-modifiers-1.
2. Выполнить точечный CLI regression на PHP8.5.0.
3. Проверить обычную тему и панель ACS этапа2, CharacterSheets и редактор KB:
   без нового runtime, синхронизации, изменения статов/источников.
4. Для серверного diagnostic caller использовать только авторизованные retained
   snapshots и явный stage policy; serverTrace не отправлять игрокам без фильтрации.

Откат: revert PR, без отката SQL и без пересчёта истории. Автоматического подключения
pipeline к существующим gameplay-командам нет. ACS-3.2.1 не начата.
