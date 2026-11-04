# ACS-2.1.4 — Status Resistance и бестиарий

Исходная main: `08b724eb3f7339d3477e3e0a758574081af4ce90`.
Область: Knowledge Base, без изменения Design Baseline и формул H1/S1.2/D1.4.

## Изменённые файлы

- `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php`: registry metadata, validation/save/read normalization, сохранение RES map и ссылок бестиария.
- `inc/plugins/advancedfunctionality/addons/knowledgebase/modules/arpg_bestiary.php`: чистые helpers alias/provenance, sparse RES projection и проверки ability pins; include не выполняет I/O.
- `inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js`: поля редактора без подстановки отсутствующих stats, сохранение keyed RES, versioned refs способностей.
- `tests/kb_bestiary_status_roundtrip.php`: изолированные минимальные сценарии.
- `tests/kb_accuracy_roundtrip.php`: независимый Status Resistance теперь задаётся в Accuracy fixture явно; тест больше не зависит от удалённого неявного default.
- Этот отчёт.

## Status Resistance

В legacy registry остаётся единственный `status_resist` с `vm_field=character_status_resist` и `acs_key=status_resistance`. В mechanics contract объявлено `stat_aliases.status_resist=status_resistance`. Вторая характеристика не регистрируется.

`af_kb_arpg_status_resistance()` возвращает `available`, исходные `source_values`, canonical key и значение только при однозначных корректных данных. В ACS-compatible bestiary DTO остаётся только `combat_stats.status_resistance`; provenance находится в `stat_provenance.status_resistance`. Сырой источник и его fingerprint сохраняются.

- `0` действителен; отсутствие/null/пустая строка не дают fallback-ноль.
- Одинаковые числовые aliases представляют одно значение, не сумму.
- Разные значения дают `status_resistance_alias_conflict`; нормализованное значение отсутствует, `supported=false`, сохранение отклоняется. Исправление обоих исходных полей выполняется администратором явно, при необходимости в существующем JSON-редакторе.
- Нечисловые/отрицательные/неконечные значения дают `invalid_stat_value`.
- Аналогичная проверка выполняется для `status_resist_base/status_resistance_base` и `character_status_resist/character_status_resistance`.
- Модификаторы с обоими вариантами имени адресуют одну существующую характеристику; DTO сохраняет `source_stat_key`. Legacy `flat/percent` и число записей модификаторов не переопределяются. Самостоятельные модификаторы не дедуплицируются по совпадению величины.

## Бестиарий и стихии

Сохраняются combat_stats (HP, ATK, DEF, Accuracy, Speed, crit_rate, crit_dmg, status_hit, Status Resistance и остальные registry stats), пользовательские дополнительные поля, массивы/словари RES и ability refs. Отсутствующая Accuracy не получает значение. Неполные legacy combat_stats остаются редактируемыми, но normalizer выдаёт `required_stat_missing` для требуемых характеристик.

RES используются только для `fire, water, air, earth, ice, lightning, nature, light, shadow, death, life, chaos, mind, abyss, gravity, astral, space`. `wind` читается как `air`, исходный ключ не переписывается. Повторные alias/element rows диагностируются и не дают пригодного значения в sparse `normalized.element_resistances`.

Поддерживаются прежние object rows и keyed maps. Для пригодного RES необходимы явные `scope=elemental`, `unit=RES` и конечное неотрицательное `value`. Неизвестные/дочерние ключи отклоняются при сохранении новых типизированных RES. Legacy числа без единиц сохраняются, но не объявляются пригодным RES: `resistance_semantics_unspecified` и `required_resistance_missing`. Ни отсутствующие RES, ни единицы измерения не угадываются.

Пример словаря (полный контракт содержит все 17 ключей):

```json
{"resists":{"fire":{"scope":"elemental","unit":"RES","value":0},"wind":{"scope":"elemental","unit":"RES","value":12}}}
```

Обычный список RES редактируется существующими полями с выбором 17 стихий; уже сохранённый словарь открывается как JSON, чтобы не терять его ключи.

`magic_direction_ref` использует модель ACS-2.1.3: `{key,definition_version,fingerprint}`. Read-only classification содержит root и provenance родительской цепочки; `blood` остаётся направлением `life`, без RES_blood и без автоматического урона Жизнью. У способности отдельно сохраняется явный `damage_element`.

В `ability_keys` сохраняются строковые legacy refs и inline objects (строковые refs при штатном save представляются объектом с `ability_kb_key`). Для закреплённой внешней способности:

- `ability_kb_key`: исходный ключ;
- `ability_definition_version`: объект `{revision,declared_version,fingerprint}` из `af_kb_arpg_acs_reference()`;
- `ability_fingerprint`: fingerprint того же исходного определения;
- `magic_direction_ref`, `damage_type`, `damage_element`: явные данные определения, без вывода из родителя.

Normalizer сверяет pins; отсутствие — `ability_version_pin_missing`, несовпадение — `ability_version_pin_mismatch`. Legacy refs сохраняются/редактируются, но без pins не объявляются пригодными для ACS. Исторические версии автоматически не восстанавливаются. Публикация snapshot и исполнение способностей остаются будущими задачами, `executable=false`.

## Проверки

В среде доступен PHP 8.4.24; PHP 8.5.0 проверяется при ручной приёмке на целевом форуме.

```text
php -l knowledgebase.php, modules/arpg_bestiary.php и оба затронутых PHP tests
node --check assets/knowledgebase.js
php tests/kb_bestiary_status_roundtrip.php
php tests/kb_accuracy_roundtrip.php
php tests/kb_element_hierarchy_roundtrip.php
php tests/kb_schema_roundtrip.php
git diff --check
```

Точечные сценарии: alias/0/conflict/missing, base/VM aliases, modifier aliases без повторного применения, save/read Accuracy 25 и 0, полный 17 RES с legacy wind, blood→life без выведенного damage_element, сохранение pins, отказ устаревшему fingerprint, неизвестному/дочернему RES. Read-only DB fixture не имеет методов записи; bootstrap не делает запросов. Формулы не изменены. Полный suite и браузерные E2E не запускались.

## Ручная приёмка и откат

1. На тестовой копии открыть старого монстра; проверить незаполненные Accuracy/Status Resistance без вымышленных нулей. У явно сохранённого 0 должен остаться 0.
2. Указать Accuracy и Status Resistance, сохранить/открыть повторно; проверить остальные характеристики и ссылки способностей.
3. Проверить 17 основных стихий, старый wind, типизированные RES, направление blood с root life и отсутствие отдельного RES_blood.
4. Проверить conflict на тестовой записи: оба aliases с разными значениями должны отклоняться с diagnostics; разрешить конфликт явным редактированием источника.
5. Проверить закреплённую способность и устаревший fingerprint, старые записи каталога, обычную тему, KB и CharacterSheets. На обычном showthread новые helpers не вызываются, каталог не загружается и синхронизация не запускается.
6. Обновить JS-кеш при развёртывании. Живой warprift.ru и production-данные в рамках проверки не изменялись.

Откат: revert коммита PR и развернуть прежние PHP/JS. SQL-миграций и массовых записей нет. Новые данные refs остаются JSON; перед production-редактированием обычная резервная копия KB позволяет вернуть конкретные определения при необходимости. Не удалять пользовательские записи и историю.

ACS-2.1.5 не реализуется.
