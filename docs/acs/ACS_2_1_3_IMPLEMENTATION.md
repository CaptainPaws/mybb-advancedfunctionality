# ACS-2.1.3 — KB: 17 основных стихий и направления

Исходная main: `0793c929644030ba05d01a344bb9fb3f22bac675`. Основание — D1.4 / ACS-DOC-01 в четырёх проектных документах. Этот PR реализует хранение и чтение классификации KB; ACS adapters, CharacterSnapshot и Resolver не реализуются и не объявляются READY. CharacterSheets, ATF, Inventory, Theme и Editor не изменены.

## Хранение

Один существующий тип `arpg_element` в `af_kb_entries`, с существующими title/body/icon/UI полями. Новых таблиц/колонок нет. Канонические основные keys:

`fire water air earth ice lightning nature light shadow death life chaos mind abyss gravity astral space`.

В обычном ARPG envelope (`data_json` и тот же envelope в `meta_json.rules`) новые rules:

```json
{
  "schema": "af_kb.arpg.rules.v1",
  "type_profile": "element",
  "element_schema": "af_kb.element.v1",
  "entity_kind": "magic_direction",
  "element_revision": 1,
  "parent_ref": {
    "kind": "primary_element",
    "key": "life",
    "definition_version": 1,
    "fingerprint": "sha256:..."
  },
  "root_element": "life",
  "family": "",
  "counter_element": "",
  "description_text": "..."
}
```

Основная стихия имеет `entity_kind=primary_element`, `parent_ref=null`, корень равен canonical key. Версию и parent pin вычисляет сервер при сохранении; root из клиента не считается достоверным. Новые canonical identities не добавляются сверх 17. Кровь `blood` создаётся вручную как направление, примеры автоматически не seed-ятся. Направление не имеет собственного базового RES. Числовые RES персонажей/NPC остаются в existing typed maps/rows по основным keys, с явными `scope=elemental`, `unit=RES`, конечным неотрицательным value. Отсутствующее значение не создаётся как 0.

`magic_direction_ref {key,definition_version,fingerprint}` — классификация способности/таланта/предмета, отдельно от фактического `damage_type` и `damage_element`. Elemental-компонент требует основной damage_element; parent не подставляет его. `blood` с physical, явным elemental/life, status либо heal — разные definitions. Existing legacy enum не переписывается, к нему добавлены категории существующего контракта elemental/healing/hybrid. Старые неоднозначные damage_type читаются с прежней диагностикой, не превращаются в новые стихии по догадке.

## Совместимость и версии

- Только `wind → air`. Seed сохраняет существующую запись wind и не создаёт рядом air. Новый пустой KB получает air. Ключи/тексты/оформление старых записей не переписываются; в выборе и отображении wind подписывается «Воздух / Air».
- Возвращаются requested_key, source_key, canonical key, registry_version и alias_policy. При двух активных air/wind — диагностика `element_alias_collision`, не два независимых RES. Новый конфликт отклоняется при сохранении. Каталог/JSON-поиск не показывает вторую карточку wind рядом с air, прямые старые ссылки сохраняются. Уже имеющийся конфликт требует явного административного решения; автоматического удаления/переноса нет.
- После сохранения revision монотонно растёт; проверяется присланная исходная revision (CAS). Fingerprint канонизирует key и rules. Legacy definitions без revision остаются без выдуманной исторической версии, с fingerprint и исходным key; при явном редактировании получают revision 1.
- Parent ref закрепляет kind/key/version/fingerprint. Цикл, self-parent, отсутствующий/неактивный родитель, несовпадение версии/hash или сохранённого root диагностируются. Правки родителей сериализуются MySQL/MariaDB advisory lock, общий с seed и удалением элементов.
- Редактирование родителя не переписывает детей: их прежние pins становятся диагностируемо устаревшими. Для использования новой цепочки администратор явно пересохраняет дочерние definitions. Старые snapshots/provenance остаются значениями; будущий ACS adapter обязан сохранять их, а не перечитывать текущую KB для истории.

## Read-only helpers

Все находятся в KB `modules/arpg_elements.php`. Include объявляет только функции, без SQL, hooks, seed или записи.

- `af_kb_arpg_primary_elements()` — утверждённые 17 identities/названия, без I/O.
- `af_kb_arpg_canonical_element_key()` — boundary alias, без I/O.
- `af_kb_arpg_read_element(key)` — определение, root, исходный/canonical key, revision/fingerprint, цепочка provenance и diagnostics; supported не означает executable.
- `af_kb_arpg_list_primary_elements()` — 17 результатов, включая diagnostics для отсутствующих definitions; один ограниченный запрос к primary keys, без полного каталога.
- `af_kb_arpg_list_magic_directions(offset,limit)` — пагинация, список направлений с диагностикой; общие предки кешируются только внутри одной операции чтения.
- Existing `af_kb_arpg_read_definition('arpg_element', key)` использует тот же контракт, включая wind/air.
- Existing `af_kb_arpg_acs_resistances()` возвращает canonical_element и исходные данные, отклоняет дочерние/неизвестные elemental RES, отрицательные/нечисловые значения и wind/air collision.

Сохранение использует существующий `af_kb_handle_edit()`: штатные права KB и CSRF, затем mutex, readback текущей версии и подготовка definitions до записи existing transaction. На GET нет seed, нормализации с записью или repair.

## Ограничения

- Технический предел parent walk — 128 узлов, с явной ошибкой depth_limit; число направлений в KB не ограничивается игровым правилом.
- Список направлений постраничный (100 по умолчанию, максимум 500 на вызов). Editor предлагает первую страницу родителей, но принимает произвольный допустимый key; root проверяется сервером на save. Это не лимит хранения/числа направлений.
- В записи направления доступна read-only JSON-ссылка для копирования в `magic_direction_ref` редактора способности/таланта/предмета. Сохранение/чтение устаревших refs требует явного обновления, без автоматического repin.
- SQL mutex рассчитан на MySQL/MariaDB. Fixture проверяет его вызовы, а не production concurrency/permissions. Локальный PHP — 8.4.24; MyBB/PHP 8.5.0 и installed БД требуют ручной форумной приёмки.
- Не реализованы архив KB definitions, ACS snapshot adapter и Resolver: исторические данные для будущего боя должны сохраняться в immutable ACS snapshots. Этот PR не обещает чтение старой revision из mutable KB после её замены.

## Проверки и ручная приёмка

Точечный `tests/kb_element_hierarchy_roundtrip.php`: 17 keys; свежий/repeated seed; сохранность custom wind; alias collision; save/schema/read обычного и вложенного направления; self/cycle/missing/deleted parent; root и version mismatch; явный re-pin; запрет 18-й основной стихии и дочернего RES; elemental ability round trip; отсутствие implicit life damage; zero RES; сохранность legacy rows; отсутствие writes в read helpers и I/O на bootstrap. Отдельно — php -l, node --check, существующие KB schema/Accuracy round-trip. Полный suite/E2E не запускался.

1. Сделать резервную копию KB и установить файлы. Штатно реактивировать только KB, чтобы existing seed добавил отсутствующие основные стихии. Обычные просмотры seed не запускают.
2. Проверить 17 основных identities и один Воздух, старые описания/иконки и прямые ссылки wind.
3. В existing arpg_element editor создать blood: тип «Магическое направление», родитель life; сохранить, открыть, проверить root/version/JSON ref.
4. Создать вложенное направление с родителем blood. Проверить цикл/self-parent и отсутствующий parent — ожидается отказ.
5. Изменить parent: старый child должен получить diagnostics, без тихой смены root; явно пересохранить child после проверки новой цепочки.
6. Проверить явный elemental/life и physical/heal без automatic damage_element. Проверить KB/анкету/лист/тему без изменения их runtimes.

Откат: revert PR/восстановление прежних файлов. Не удалять seeded entries или направления автоматически: они могут получить пользовательские ссылки. Данные и pins сохранить; при необходимости отключить новые definitions штатно после проверки зависимостей. Если нужно вернуть именно исходные данные, использовать проверенную резервную копию KB, а не массовый DELETE.

ACS-2.1.4 и ACS-2.2 не выполняются автоматически.
