# ACS-3.2.1 — Hit Check и Critical Damage

Исходная main: `47432b7d417621cacf7f83a4144691666ec94a7a`.
ACS 0.13.0, чистый модуль `acs-hit-crit-1`, Numeric `binary64-half_up-v1`.
Статус: ready_for_manual_test. Нормативные документы и Design Baseline не изменены.

## Реальные файлы

- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/modules/Domain/HitCrit.php`:
  AF_ACS_HitCrit, AF_ACS_HitCritPolicy, AF_ACS_HitCritResult.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/admin.php`:
  версия Hit/Crit в существующем защищённом ACP → Основные.
- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/manifest.php`: версия.
- `tests/acs_hit_crit_regression.php`: точечные числовые/owner-policy проверки.
- Этот отчёт.

Numeric, Formula, Modifiers используются без изменения через существующую чистую
Domain dependency chain. Effective stats/raw components передаются после нужной
формулы/стадии модификаторов: повторного расчёта CS или оборудования нет.
Новый компонент не является Resolver и не подключён к игровым командам.

## H1

`AF_ACS_HitCrit::hit(effectiveAccuracy, effectiveSpeed, policy=null)` — внутренний
серверный DTO с effective_accuracy, effective_speed, P_hit, P_evade и trace.
Оба значения проверяются Numeric и должны быть неотрицательными.

```text
P_hit = 75, если A=0 и S=0
P_hit = clamp(75 + (30*(A-S))/(A+S), 5, 95), иначе
P_evade = 100 - P_hit
```

Шаги выполняются в указанном порядке через Numeric, без алгебраических перестановок
и без integer rounding вероятности. Обычный hit использует terminal number/none,
затем clamp5..95. Уклонение — дополнение той же вероятности, без второго броска.
Luck, текст поста и Speed=0 не создают auto-hit.

## C1

`strike(A,S,critRate,critDamage,components,criticalOutcome,policy=null)` возвращает
AF_ACS_HitCritResult. `attempt(...)` возвращает ready/evaluation/diagnostics.
CritRate конечный в 0..100; critDamage неотрицательный дополнительный процент,
без нового игрового потолка (технические пределы Numeric сохраняются).

```text
critical_component = raw_component * (1 + crit_dmg/100)
```

Один внешний bool outcome относится ко всему удару. Перекомпонентных исходов
API не принимает. Для отдельного следующего удара вызывается отдельный расчёт.
Outcome не вычисляется из rate. Противоречия true при rate0 и false при rate100
отклоняются. Строки/числа не преобразуются в bool. Null — preview без исхода:
raw_after_crit и crit_applied остаются null, даже при rate0/100. Это не новый roll.

Components — список `{kind,raw,kb_effect_index?}`. Допустимые контексты:
ordinary_attack/direct_attack критуют по умолчанию; dot/reflected/natural/
healing/shield — нет. Natural означает природное/средовое воздействие, а не
каноническую стихию nature: классификацию воздействия задаёт trusted caller.
Неизвестный kind, отрицательный raw и клиентский can_crit внутри component дают отказ.

При заданном true множитель применяется только к разрешённым компонентам,
до будущих DEF/RES, без округления и без HP loss. При false сохраняется raw.
True при полном отсутствии разрешённых компонентов даёт crit_not_allowed.
Разрешённый компонент с raw0 остаётся нулевым. Общий integer budget D1.13 здесь
не рассчитывается, чтобы не вводить вторую границу округления.

## Явные правила KB

Отдельного типизированного reader-контракта auto_hit/can_crit в KB пока нет;
B1/adapter сохраняют эти явные поля definition/effects без их исполнения.
`AF_ACS_HitCritPolicy::fromDefinition(dto,expectedSource,expectedVersion)` принимает
только доверенный owner-authorized результат существующего KnowledgeBaseAdapter
readDefinition для standalone arpg_ability, ready=true, IC-KB.read.v1/schema1,
rules.v1 и совпадающие source id/type/key и полный definition_version/fingerprint.

Авторизация и проверка hash источника выполняются существующим адаптером до Domain;
factory не доказывает подлинность произвольно переданного клиентом массива.
Вызывающий сервер обязан брать DTO/pins из проверенных retained источников, а не
из POST. Никакого нового endpoint, принимающего policy/flags от клиента, нет.
Непривязанные bool auto_hit/can_crit не являются аргументами математического API.

Factory читает только явные boolean auto_hit/can_crit из сохранённого definition;
component can_crit берётся из исходного effects[index], если caller явно передал
kb_effect_index. Отсутствующая ссылка/индекс блокируется; автоматического
сопоставления случайного компонента с первым KB эффектом нет. Effect override
имеет приоритет над ability override; без них действует baseline. Другие типы
KB/character-inline policy пока дают hit_crit_policy_unavailable, без догадок.

Auto_hit=true подтверждённой политики даёт P_hit100/P_evade0; обычная H1-трасса
сохраняется отдельно. Can_crit=false может запретить прямую атаку, true — явно
разрешить healing и другие исключения. Эти флаги не утверждают готовность всех
остальных механик способности. Изменение live KB не обновляет объект retained policy.

## DTO и безопасная трасса

`serverData()` включает effective Accuracy/Speed, P_hit/P_evade, crit_rate/crit_dmg,
can_crit, supplied critical_outcome, components с raw_component/raw_after_crit,
разрешением и источником разрешения. `serverTrace()` добавляет engine/numeric
versions, H1 difference/scaled_difference/sum/correction/ordinary clamp,
critical_multiplier, один check scope per strike, KB refs и before_def_res phase.
Ни одного случайного значения/tape или операции состояния нет.

Обычная JSON-сериализация strike result содержит лишь ready/версии. Полные
serverData/serverTrace и прямой hit DTO предназначены для доверенного сервера;
их нельзя без фильтрации раскрывать игроку с учётом скрытой Speed/Accuracy/KB.

## Проверки и ограничения

`php -l` затронутых файлов, git diff --check и
`php tests/acs_hit_crit_regression.php` — успешно.
Fixture использует существующие точечные read-only CS/KB/adapter doubles,
без production и без SQL writer API. Не запускались полный suite, браузер и SQL.
Локально PHP 8.4.24, target PHP 8.5.0 требует ручной приёмки.

| Сценарий | Результат |
|---|---|
| A0/S0 | 75/25 hit/evade |
| A0/S10 | 45/55 |
| A10/S0 | 95/5, без auto-hit |
| A5/S5 | 75/25 |
| A80/S20 | 93/7 |
| crit100, dmg50, raw10 | 15 при supplied true |
| crit0, raw10 | 10 при supplied false |
| raw0.4, dmg50 | 0.6000000000000001 binary64, без rounding |
| смешанный удар с DoT raw4 | DoT остаётся4 при общем true |
| только DoT/reflected/natural/heal/shield + true | crit_not_allowed |
| preview outcome=null | не создаётся исход или raw_after_crit |

Проверены повторяемость trace, отрицательные/нечисловые/Infinity параметры,
rate101, невозможные/не-boolean исходы, неизвестный kind, запрет inline can_crit,
настоящий owner-normalized KB auto_hit/crit override, source fingerprint drift,
component-index permission и retained policy после редактирования fixture.
Отдельный showthread smoke подтвердил inactive без загрузки Hit/Crit/Numeric.
После подготовки источника Domain-вызов не увеличивает даже число read queries
и не меняет таблицы fixture.

Ограниченная арифметика может отказать при небезопасном промежуточном значении
(например, огромном 30*(A-S)), даже если теоретическое отношение мало. Нет
скрытого пересчёта в другой последовательности или нового лимита баланса.
Модуль не генерирует/не сохраняет RNG, rolls, hit outcome, AP, HP, посты или snapshots.

## Ручная приёмка / откат

1. ACP ACS → Основные: Hit/Crit acs-hit-crit-1.
2. Выполнить CLI regression на PHP8.5.0, проверить обычную тему, ACS этапа2,
   KB и CharacterSheets: без изменений данных или глобального runtime.
3. Для отдельного доверенного diagnostics caller использовать effective stats,
   pinned owner DTO и supplied outcome; serverTrace не отправлять игроку напрямую.

Откат — revert данного PR, без SQL/пересчёта истории. ACS-3.2.2 не начата.
