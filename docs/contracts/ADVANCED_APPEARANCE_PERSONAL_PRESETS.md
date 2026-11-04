# AdvancedAppearance 1.1 — личные пресеты и слоты (этап 3)

## Границы и установка

Реализован серверный контракт личных postbit-пресетов внутри существующего
AdvancedAppearance. Визуальный конструктор — этап 4; PID-маски — этап 5;
продажи слотов — этап 6. Нового addon, upload mechanism или отдельного каталога
appearance нет. Gallery/ATF/MyBB core не изменяются этим этапом.

После обновления файлов повторно активировать AdvancedAppearance в AF ACP либо
вызвать его upgrade lifecycle. Новая миграция запускается только при
install/activate/upgrade; обычные personal GET/POST не выполняют DDL.
Gallery должен быть обновлён и активирован по этапу 2, если используются media IDs.
Без Gallery можно сохранить разрешённые параметры цвета/шрифта/статического эффекта.

Миграция добавляет в `af_aa_presets`:

| Поле | Назначение |
| --- | --- |
| `owner_uid`, default 0 | 0 — старый системный/магазинный каталог; >0 — личная запись UID. |
| `schema_version`, default 1 | 1 — прежний доверенный формат; 2 — строгий пользовательский JSON. |
| `publication_state`, default ready | ready — опубликованная definition; draft — запись без опубликованного варианта. |
| `draft_json`, nullable | Независимый рабочий JSON; не подменяет опубликованные `settings_json`. |

Добавлен индекс `(owner_uid,target_key,enabled)`; старый UNIQUE `(slug,target_key)`
сохранён. Личные slugs генерируются сервером с UID и random ID. Виртуальный
«По умолчанию» имеет ID 0 только в API, в таблице нет соответствующей записи.
Его нельзя редактировать, переименовывать, удалять или клонировать как definition.
Создание нового draft с id=0 — создание новой личной записи, не правка системного.

`af_aa_assignments` остаётся единственным обычным источником активного выбора.
Миграция не меняет старые IDs, settings_json, targets, titles, previews или
активные assignments и не превращает покупки в личные черновики.
Личные строки исключены из legacy ACP/catalog CRUD и fittingroom-каталога;
их нельзя изменить через старый редактор с подставленным ID.
Shop/Inventory продолжают работать с прежними target keys/definition IDs/API.

Для атомарности personal create/publish/delete/activate миграция проверяет движки
только `af_aa_presets`, `af_aa_assignments`, `af_aa_slot_grants` и переводит их
в InnoDB, если это необходимо. Это не конверсия всего форума. На больших старых
MyISAM таблицах ALTER требует обычного окна обслуживания и резервной копии;
данные проверяются до/после. Production-БД и deployment здесь не выполнялись.

## Registry и slots

`af_aa_personal_targets()` задаёт:

| Public target key | Статус | Совместимый assignment target |
| --- | --- | --- |
| postbit | реализован | apui_postbit_pack, entity_type=user, entity_id=UID |
| profile | registry-only | apui_profile_pack, будущий user contract |
| sheet | registry-only | apui_sheet_pack, будущий user contract |
| theme | registry-only | apui_thread_pack, будущий thread/TID contract |

Не заменять legacy pack keys этими короткими ключами. В personal definition
хранится `target_key=postbit`, но активный выбор пишет существующий совместимый
assignment target. Для будущих персонажей/PID используется расширяемая пара
entity_type/entity_id существующей таблицы; в этом этапе нет записи post/character
assignments и нет принятия entity_id из пользовательского запроса.

По умолчанию postbit получает 1 пользовательский слот + 1 виртуальный системный.
`list` возвращает `slots.total_with_system=2`. Системный всегда доступен
авторизованному пользователю реализованной поверхности: reset работает даже после
отключения новых личных пресетов в ACP. Reserved targets имеют системный descriptor
в registry/list, но их создание/активация запрещены, редакторы не создаются.

Настройки в обычной ACP-группе AdvancedAppearance:

- `af_advancedappearance_free_slots_postbit/profile/sheet/theme`: defaults 1/0/0/0;
- `af_advancedappearance_max_slots_postbit/profile/sheet/theme`: defaults 20;
- `af_advancedappearance_personal_types`: default postbit, CSV; не может включить
  ещё не реализованную поверхность одним изменением настройки;
- `af_advancedappearance_personal_json_limit`: default 4096 байт, безопасный диапазон 2–32768;
- `af_advancedappearance_personal_title_limit`: default 80 Unicode символов, 1–191;
- `af_advancedappearance_personal_media_limit`: default 3 уникальных IDs, 0–3.

Повторная активация сохраняет эти значения и существующий enabled setting.
Предел ёмкости ограничен 0–1000 для каждого target. Ёмкость = min(maximum,
free + enabled grants). Все личные строки считаются, включая draft/disabled;
сохранение draft существующего preset не занимает ещё один слот. Удаление освобождает
слот. Уменьшение лимита не удаляет сохранённые definitions/assignments: создание и
clone блокируются при отсутствии места, существующие записи можно редактировать
в пределах новых write limits, сбросить или удалить.

Новая таблица `af_aa_slot_grants` хранит `uid`, `preset_type`, `capacity`,
`source_key UNIQUE`, `is_enabled`, timestamps. Это только storage contract купленной
ёмкости; никаких цен, покупок, выдачи grants из HTTP или масок-расходников здесь нет.
Этап 6 должен записывать положительные grants с idempotent source_key и пользоваться
той же owner lock при совместной проверке ёмкости. Existing shop preset items не
дают автоматически новые слоты и не расходуют бесплатный личный слот.

Проверка количества выполняется внутри `af_aa_personal_transaction()` под MySQL
GET_LOCK по database/prefix/owner, затем в транзакции Appearance. Это работает
независимо от движка MyBB users и защищает параллельные create/clone/delete.
Клиентский capacity/free_slots/owner_uid/schema_version не определяют результат.

## JSON V2

Схема плоская, sparse: отсутствующее поле наследует базу. Неизвестные поля,
невалидный JSON, массив вместо object, строки вместо media ID, неверные типы/диапазоны
отклоняются. В БД сохраняются параметры и media IDs, но не вычисленные URL/CSS.

| Ключ | Допустимые значения |
| --- | --- |
| author_bg_media_id, name_bg_media_id, plaque_bg_media_id | positive integer Gallery ID либо null (явно убрать изображение). |
| text_color, accent_color, author_tint | #RRGGBB либо null (наследовать). |
| overlay_opacity | JSON number 0…1, не boolean/string. |
| font_family | inherit, system, sans, serif, mono; сервер сопоставляет фиксированным font families. |
| effect_preset | inherit, flat, soft_glow; только статические разрешённые decorations. |

Нет raw CSS, HTML, selectors, произвольных font families, URLs, grid/width/position,
canonical element keys или пользовательской конфигурации Canvas. Эффекты этого
контракта — статическая тень/её отсутствие. Они не запускают Canvas-анимацию;
ElementTheme approval gate, palette identity, viewer motion preferences и reduced
motion продолжают работать через существующего владельца.

Пример:

```json
{"author_bg_media_id":123,"font_family":"serif","text_color":"#ddeeff","accent_color":"#99bbcc","overlay_opacity":0.25,"effect_preset":"soft_glow"}
```

Media проверяются через `ag_get_owned_media`, а перед publish/activate — через
`ag_retain_owned_media`. Foreign/rejected/remote/non-raster IDs запрещены. Pending
собственное local image может быть в draft, но не в published definition/assignment.
Один ID можно использовать в нескольких разрешённых полях/пресетах; лимит считает
уникальные IDs. Gallery retention выполняется до сохранения опубликованной ссылки.
Изображения разрешаются при render через `ag_resolve_media_url`; при недоступном
URL поле наследует базу. Приватный альбом не запрещает публичное отображение
approved изображения согласно уточнению этапа 2. Никакого SQL к Gallery tables или
filesystem path assembly внутри Appearance нет.

## Операции и HTTP API

Endpoint существующего alias: `apstudio.php?api=personal`. Старый админский
`apstudio.php` без этого параметра сохраняет admin gate, формы и catalog semantics.
Нет нового editor/frontend. Следующий этап строит визуальный UI поверх этого API.

GET: `operation=list&target=postbit`, `operation=get&id=<own_id>`.
`get&id=0&target=postbit` возвращает виртуальный системный descriptor.
`list` возвращает system_preset, personal presets, slots, registry/font/effect
allowlists, assignment и `active_selection.kind=system|personal|catalog`. Таким
образом старый магазинный выбор не ошибочно отображается как личный или default.

POST: application/json либо form-urlencoded. Общие поля: `operation`,
`my_post_key` (MyBB CSRF), необязательный `target` (postbit), `id`, `title`,
`settings_json` — строка, содержащая JSON object. Save/draft существующей записи
обязательно передают settings_json, чтобы пропуск поля не стирал definition.

| operation | Поля и поведение |
| --- | --- |
| create | title, settings_json (default {}); создаёт ready definition, не активирует автоматически. |
| rename | id, title; сохраняет только название. |
| save | id, settings_json; публикует definition, очищает draft_json, активный выбор сохраняется. |
| draft | id>0, settings_json; сохраняет рабочую копию без изменения опубликованного вида. id=0 + title создают новый draft с отдельным слотом. |
| clone | own id, новый title; копирует definition/draft в свободный слот, не активирует. Legacy catalog не клонируется автоматически. |
| delete | own id; удаляет definition и ссылающиеся assignments, освобождает слот, не удаляет Gallery media. |
| activate | own ready id, target=postbit; выбирает definition в user assignment, не смешивает выбор с settings. id=0 эквивалентен reset. |
| reset | target=postbit; снимает postbit pack/fragment assignments и legacy postbit active markers; возвращает текущую базу ATF/APUI/ElementTheme. Definitions/purchases сохраняются. |

Ответы: `{ok:true,data:...}` либо `{ok:false,error:...}`. HTTP 403 для доступа/CSRF,
404 для чужого/отсутствующего personal ID, 405 для mutation через GET, 409 для
отсутствия свободного слота/конкурентной занятости, 422 для validation,
503 для отсутствующей миграции/provider. Cache-Control private, no-store.
UID берётся из server session; если передан другой uid, запрос запрещается.
Права ACP/модерации не дают здесь права редактировать чужой личный preset.

PHP providers имеют префикс `af_aa_personal_`: create, save, rename, clone, delete,
activate, slots, list, owned_row. Все writer functions повторно проверяют actor,
ownership, implemented/enabled target, структурные параметры и ёмкость; HTTP handler
не является единственной защитой. Reset/delete остаются возможны при отключении
новых personal presets. Registry-only target не имеет writer.

## Runtime и совместимость

Порядок: base ATF geometry → стандартные APUI/ElementTheme значения → активный
личный sparse preset → будущий per-PID override (не реализован).
При personal postbit assignment старые отдельные postbit fragment assignments
сохраняются в БД, но не перекрывают личную definition; при возврате к магазинному
preset они снова работают. Явный reset очищает postbit назначения, чтобы «По
умолчанию» действительно снимал прежние локальные настройки. Profile/thread/sheet
назначения не сбрасываются.

APUI consumers получают только разрешённые legacy background keys из V2 bridge.
Сервер генерирует scoped variables для существующих postbit backgrounds и
presentation rules для message color/font, accent tokens, author tint и статической
тени. Specificity проверена против настоящего ATF postbit CSS. Layout/grid, markup,
ID/anchors, avatars/account fields, сообщения и права не изменяются. Appearance
заполняет уже существующий `af_aa_user_class` на postbit data hook, независимо от
того, заполнил ли его APUI. Request caches сбрасываются после writer operations.
Draft не попадает в runtime; понижение ACP write limits не переписывает старый
published JSON. При corrupt stored JSON compiler возвращается к безопасной базе.

Используется существующий `af_aa_render_page_css` / page output contract. Это
не расширение Editor/AJAX transports и не реализация PID masks. Доставка всего
runtime в native AJAX/Quick Edit responses проверяется в соответствующем этапе;
этот этап не меняет формат MyBB JSON responses или структуру postbit для этого.
Legacy trusted custom CSS сохраняется; его !important/direct rules могут требовать
разбора конкретного конфликта, а не открытия raw CSS пользователям.

## Проверки и последующие этапы

Targeted проверки на PHP 8.5.0 (mysqli/GD/mbstring), MySQL 8.4/InnoDB:

- `tests/appearance_personal_integration.php`: два запуска миграции, сохранение
  legacy rows/assignments, MyISAM→InnoDB только Appearance, ACP preservation,
  system/free slots, CRUD/clone/delete, отдельный draft, publish/cache/activate/reset,
  foreign UID/definition refusal, invalid JSON/fonts/effects/ranges/media,
  Gallery ownership/retention/reuse, новые и пониженные лимиты, legacy reactivation,
  подготовленный grant/max contract и 12 concurrent create (ровно один успех);
- `tests/appearance_personal_http.php`: реальные GET/POST/JSON requests, CSRF/method/
  ownership/quota checks без frontend, CRUD/drafts/reset и registry-only targets;
- `tests/appearance_personal_browser.cjs`: Chromium с настоящим ATF postbit.css —
  применённые color/font/accent, draft isolation, reset/reactivation, два сообщения
  одного автора, неизменные grid/markup/canonical element;
- существующий `advancedappearance_frontend_permission_regression.php`.

Fixtures работают только с временной `appearance_test` DB и `aa_test_` tables.
HTTP actor overrides — только в тестовой fixture, не в production controller.
Production schema/data/access не подключались. В main опубликованы исходники;
активация миграции на warprift.ru — отдельное действие владельца сайта.

Изменяемые файлы этого этапа: Appearance `personal.php`, `advancedappearance.php`,
`admin.php`, `manifest.php`; три targeted tests и этот документ. Этап 4 использует
API/allowlists и Gallery picker; этап 5 добавляет PID assignment/render contract;
этап 6 пишет idempotent capacity grants. Gallery/Shop/ATF/MyBB core здесь не меняются.
