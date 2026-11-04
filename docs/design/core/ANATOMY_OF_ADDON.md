# Анатомия аддона AdvancedFunctionality

Документ описывает фактический паттерн четырёх эталонных аддонов: `advancedmenu`, `adaptivethemeframework` (ATF), `knowledgebase` (KB) и `charactersheets` (CS). Пути ниже заданы относительно корня репозитория.

## 1. Минимальная структура

```text
inc/plugins/advancedfunctionality/addons/<id>/
├── manifest.php          # обязательная точка discovery
├── <id>.php              # bootstrap и публичные функции af_<id>_*
├── admin.php             # необязательный ACP-контроллер
├── assets/               # JS/CSS и, при необходимости, seed PHP-страницы
├── templates/            # HTML-шаблоны, если нужны
└── modules/              # необязательное разбиение большого bootstrap
```

Ядро сканирует непосредственные подкаталоги `AF_ADDONS`, включает `manifest.php`, принимает только массив с непустыми `id` и `name`, добавляет `path` и превращает относительный `bootstrap` в абсолютный путь (`inc/plugins/advancedfunctionality.php:3001`-`3021`). Сортировка по `name` влияет на порядок обхода, но не заменяет явные приоритеты хуков (`inc/plugins/advancedfunctionality.php:3020`).

## 2. `manifest.php`: декларативный паспорт

### 2.1. Обязательная и типовая часть

Файл возвращает PHP-массив, а не объявляет класс и не выполняет установку. Минимальный каркас:

```php
<?php
return [
    'id' => 'example',
    'name' => 'Example',
    'description' => '...',
    'version' => '1.0.0',
    'author' => '...',
    'bootstrap' => 'example.php',
];
```

`id` и `name` обязательны для discovery; `bootstrap` нужен для загрузки исполняемого кода (`inc/plugins/advancedfunctionality.php:3011`-`3016`). Эталонные записи: AdvancedMenu (`inc/plugins/advancedfunctionality/addons/advancedmenu/manifest.php:6`-`16`), KB (`inc/plugins/advancedfunctionality/addons/knowledgebase/manifest.php:2`-`12`), CS (`inc/plugins/advancedfunctionality/addons/charactersheets/manifest.php:7`-`20`), ATF (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/manifest.php:5`-`16`).

Канон идентичности: имя каталога, `id`, имя bootstrap и префикс функций согласованы между собой. Настройка включения имеет имя `af_<id>_enabled`, группа настроек — `af_<id>` (`inc/plugins/advancedfunctionality.php:1274`-`1287`). Пример CS: каталог/`id`/bootstrap — `charactersheets`, а lifecycle-функции — `af_charactersheets_*` (`inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php:97`-`137`).

### 2.2. Frontend и assets

`frontend.mode` принимает фактические режимы `legacy`, `global`, `contextual`; неизвестный режим откатывается к legacy (`inc/plugins/advancedfunctionality.php:3041`-`3072`). Для нового аддона следует явно выбрать `global` или `contextual`, описать routes/response rules и осознанно задать `directory_fallback`; сам контракт запрещает рассчитывать на автоматическую загрузку всего каталога `assets/` везде (`docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:96`-`108`).

- Глобальный runtime: AdvancedMenu (`inc/plugins/advancedfunctionality/addons/advancedmenu/manifest.php:14`-`16`) и ATF (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/manifest.php:18`-`27`).
- Контекстный runtime с HTML-routes: KB (`inc/plugins/advancedfunctionality/addons/knowledgebase/manifest.php:10`-`37`).
- Контекстный runtime с response rule и отдельным embedded resource: CS (`inc/plugins/advancedfunctionality/addons/charactersheets/manifest.php:16`-`37`).
- CSS для theme integration объявляется записями `theme_stylesheets` с `id`, `file`, `attach`, `enabled_setting`; примеры — KB (`inc/plugins/advancedfunctionality/addons/knowledgebase/manifest.php:43`-`64`) и ATF (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/manifest.php:25`-`54`).

Секция `assets.front` может перечислять файлы для collector, как у ATF (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/manifest.php:27`). Любая ручная вставка `<script>`, `<link>`, inline config или изменение `$headerinclude` должна отдельно проходить `af_frontend_asset_allowed()` (`docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:63`-`71`).

### 2.3. ACP

Типовой блок:

```php
'admin' => [
    'slug' => 'example',
    'title' => 'Example',
    'controller' => 'admin.php',
],
```

Роутер ищет включённый аддон по `admin.slug`, загружает `admin.controller`, вычисляет класс `AF_Admin_<Slug>` и вызывает статический `dispatch()` (`inc/plugins/advancedfunctionality.php:1168`-`1177`, `inc/plugins/advancedfunctionality.php:441`-`452`). Ссылка имеет вид `index.php?module=advancedfunctionality&af_view=<slug>`; пункт меню создаётся только для включённого аддона с заданным slug (`inc/plugins/advancedfunctionality.php:1558`-`1571`). Примеры manifest: KB (`inc/plugins/advancedfunctionality/addons/knowledgebase/manifest.php:38`-`42`), ATF (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/manifest.php:12`-`16`).

### 2.4. Языки

Каноническая форма — `lang.russian|english.front|admin`, как у KB и CS (`inc/plugins/advancedfunctionality/addons/knowledgebase/manifest.php:66`-`68`, `inc/plugins/advancedfunctionality/addons/charactersheets/manifest.php:47`-`52`). Ядро создаёт `advancedfunctionality_<id>.lang.php` во front/admin-каталогах RU и EN (`inc/plugins/advancedfunctionality.php:2206`-`2239`). Имя загружаемого языкового файла в ACP должно соответствовать этому формату, например у AdvancedMenu (`inc/plugins/advancedfunctionality/addons/advancedmenu/admin.php:24`-`26`, `inc/plugins/advancedfunctionality/addons/advancedmenu/admin.php:53`-`74`).

## 3. Жизненный цикл: что реально вызывает ядро

### 3.1. Включение

Общий путь Enable: загрузить bootstrap → вызвать `af_<id>_install()`, если функция существует → создать/обновить `af_<id>_enabled=1` → перестроить settings → синхронизировать stylesheets (`inc/plugins/advancedfunctionality.php:1189`-`1203`, `inc/plugins/advancedfunctionality.php:1234`-`1243`).

**Важно:** для обычного аддона ядро в этом пути не вызывает `af_<id>_activate()`. ATF — специальная ветка: `is_installed()` → при необходимости `install()` → обязательный `activate(): true` (`inc/plugins/advancedfunctionality.php:1212`-`1233`). ACS также особый, но не входит в этот разбор (`inc/plugins/advancedfunctionality.php:1205`-`1211`).

### 3.2. Выключение и runtime

Disable ставит `af_<id>_enabled=0`, обновляет CSS, загружает bootstrap и вызывает `af_<id>_deactivate()`, если он существует (`inc/plugins/advancedfunctionality.php:1246`-`1261`). Общего вызова `af_<id>_uninstall()` в Enable/Disable не найдено; удаление — отдельная операция, которую нельзя считать автоматической частью Disable.

На `global_start` ядро подключает bootstrap только включённых аддонов, загружает язык и вызывает `af_<id>_init()` (`inc/plugins/advancedfunctionality.php:2322`-`2328`, `inc/plugins/advancedfunctionality.php:2370`-`2380`). Отдельный pre-output-проход вызывает `af_<id>_pre_output($page)`, если функция есть (`inc/plugins/advancedfunctionality.php:2637`-`2667`).

### 3.3. Четыре модели эталонов

| Аддон | Install / activate | Deactivate / uninstall | Данные при удалении |
|---|---|---|---|
| AdvancedMenu | Отдельные `af_advancedmenu_install/uninstall/activate/deactivate` не найдены. `ensure_installed()` создаёт/чинит БД, настройки, шаблоны и сбрасывает кэш; вызывается из `init()` (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:357`-`385`, `inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:2077`-`2091`). | Не найдено. | Отдельная политика удаления не найдена. |
| ATF | `install()` обеспечивает settings и schema readiness; `activate()` захватывает templates (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:2659`-`2677`). `is_installed()` проверяет ledger-таблицу (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:2665`-`2672`). | Оба освобождают templates; ledger специально сохраняется (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:2686`-`2695`). | Ledger не удаляется. |
| KB | `install()` создаёт/мигрирует схему и сопутствующие объекты; `activate()` дополнительно обеспечивает settings, templates, seed, alias и task (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1539`-`1567`, `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1859`-`1903`). | Deactivate удаляет только owned alias; uninstall удаляет settings, templates, task и alias, но не таблицы/записи (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1837`-`1856`, `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1906`-`1909`). | Таблицы и записи сохраняются. |
| CS | Публичные функции — тонкие делегаты в `modules/bootstrap.php` (`inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php:97`-`125`). Install: schema/settings/templates/alias; activate: templates/schema/alias (`inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:26`-`45`). | Deactivate удаляет owned alias; uninstall дополнительно удаляет settings и templates (`inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:48`-`93`). | DROP таблиц в uninstall не найдено. |

### 3.4. Идемпотентность

Повторный запуск не должен дублировать или без нужды перезаписывать пользовательские данные:

1. Проверять существование таблиц/полей либо использовать `CREATE TABLE IF NOT EXISTS`; AdvancedMenu также выполняет мягкое добавление отсутствующих полей (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:388`-`430`).
2. Искать setting group/setting по стабильному имени и обновлять существующую запись; AdvancedMenu дополнительно устраняет дубликаты групп (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:468`-`508`).
3. Повторно синхронизировать owned templates и seed-данные, не считая Disable удалением данных (`inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:26`-`51`).
4. Делать одноразовую runtime-инициализацию защищённой от повторов, как static guard в AdvancedMenu (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:357`-`363`).
5. Перестраивать settings после изменений БД (`inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:30`-`37`).

## 4. Bootstrap и хуки

`af_<id>_init()` — место регистрации MyBB-хуков, а не место безусловного вывода HTML. Примеры:

```php
function af_example_init(): void
{
    global $plugins;
    $plugins->add_hook('misc_start', 'af_example_route', 10);
    $plugins->add_hook('pre_output_page', 'af_example_pre_output', 10);
}
```

KB регистрирует `misc_start`, `pre_output_page`, `parse_message_end` (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:9314`-`9321`). CS добавляет thread/postbit, pre-output, misc и moderation hooks (`inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php:127`-`137`). ATF показывает приоритеты и регистрацию групп хуков только в нужном request context (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:199`-`215`, `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:239`-`277`).

Правила по эталонам:

- Callback должен существовать к моменту срабатывания; большой bootstrap можно разбить на `modules/`, оставив публичные делегаты, как CS (`inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php:97`-`137`).
- Приоритет задавать явно там, где важен порядок: ATF использует ранние preload и поздние compose hooks (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:224`-`238`).
- Преобразование всего HTML делать идемпотентным; AdvancedMenu проверяет marker, frontend permission и enabled setting (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:2096`-`2122`).
- Не считать backend-зависимость разрешением загружать JS/CSS другого аддона (`docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:83`-`88`).

## 5. `admin.php`: контроллер внутри AF shell

Пошаговый шаблон:

1. Запретить прямой доступ (`IN_MYBB`, при необходимости `IN_ADMINCP`), как ATF (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/admin.php:1`-`5`).
2. Объявить класс, ожидаемый роутером: `AF_Admin_<Slug>` с `public static function dispatch()` (`inc/plugins/advancedfunctionality.php:1168`-`1177`). Примеры имён: `AF_Admin_Advancedmenu`, `AF_Admin_KnowledgeBase`, `AF_Admin_Charactersheets`, `AF_Admin_Adaptivethemeframework` (`inc/plugins/advancedfunctionality/addons/advancedmenu/admin.php:22`, `inc/plugins/advancedfunctionality/addons/knowledgebase/admin.php:4`, `inc/plugins/advancedfunctionality/addons/charactersheets/admin.php:11`, `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/admin.php:5`).
3. При необходимости подключить bootstrap и дополнительные admin helpers (`inc/plugins/advancedfunctionality/addons/advancedmenu/admin.php:15`-`20`).
4. Строить URL через `module=advancedfunctionality&af_view=<slug>`; собственные операции передавать в `do` или собственном параметре (`inc/plugins/advancedfunctionality/addons/advancedmenu/admin.php:28`-`46`).
5. Проверять `my_post_key` для изменений и класть его в формы (`inc/plugins/advancedfunctionality/addons/knowledgebase/admin.php:44`-`52`, `inc/plugins/advancedfunctionality/addons/knowledgebase/admin.php:116`-`120`).
6. Рендерить только внутреннее содержимое: роутер сам выводит header/footer (`inc/plugins/advancedfunctionality.php:426`-`450`, `inc/plugins/advancedfunctionality.php:530`; канон также зафиксирован в `inc/plugins/advancedfunctionality/addons/advancedmenu/admin.php:5`-`10`).
7. Экранировать вывод и валидировать вход; ATF валидирует settings, обновляет их и делает redirect (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/admin.php:14`-`43`).

## 6. `assets/` как автоустановщик PHP-страниц

Это **не общее автоматическое поведение ядра**: в рассмотренном discovery нет копирования PHP из `assets/` (`inc/plugins/advancedfunctionality.php:3001`-`3021`). Страницу устанавливает собственный lifecycle-код аддона.

Паттерн KB/CS:

1. Хранить seed в `assets/<route>.php`.
2. В seed определить `IN_MYBB`, `THIS_SCRIPT`, marker и подключить `global.php`; после копирования в корень `__DIR__` указывает на корень форума (`inc/plugins/advancedfunctionality/addons/knowledgebase/assets/kb.php:1`-`12`, `inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets.php:1`-`12`).
3. В install/activate копировать seed в `MYBB_ROOT.<route>.php` (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1912`-`1919`, `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1942`-`1958`; CS — `inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:197`-`218`).
4. Перед перезаписью существующего файла проверить ownership marker; чужой файл не трогать и сообщить об ошибке (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1922`-`1930`, `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1951`-`1958`).
5. На deactivate/uninstall удалять цель только если marker подтверждает владение (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1961`-`1966`; CS — `inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:221`-`228`).
6. Предусмотреть fallback, если установить alias нельзя: KB строит URL через `kb.php` только при доступном owned alias, иначе использует `misc.php?action=kb` (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1933`-`1939`, `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1969`-`1988`).

У AdvancedMenu PHP-страница в `assets/` и соответствующий installer не найдены; у ATF PHP-страница в `assets/` не найдена. Их структура не требует выдумывать alias.

## 7. Сводный пошаговый шаблон создания аддона

1. Создать каталог `<id>` и возвращающий массив `manifest.php`; заполнить `id`, `name`, metadata и `bootstrap` (`inc/plugins/advancedfunctionality.php:3010`-`3016`).
2. Объявить `frontend`, ACP, languages и CSS delivery по фактическим потребностям, без legacy directory fallback по умолчанию (`docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:96`-`120`).
3. Создать `<id>.php`; определить `af_<id>_install()` и `af_<id>_init()`. Если аддон не входит в специальную ветку ядра, не переносить обязательную установку только в `activate()` (`inc/plugins/advancedfunctionality.php:1234`-`1243`).
4. Сделать install повторно безопасным: schema → settings → templates/seed → owned aliases/tasks → rebuild settings (`inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:26`-`37`).
5. Реализовать `af_<id>_deactivate()` для временно отключаемых ресурсов; uninstall оформить отдельно и явно решить, какие пользовательские данные сохраняются (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1837`-`1856`).
6. Зарегистрировать hooks в `init()`, ограничить их контекстом и приоритетами (`inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php:127`-`137`).
7. Добавить `admin.php` только при необходимости; соблюсти имя класса, `af_view`, CSRF и отсутствие собственного shell (`inc/plugins/advancedfunctionality.php:441`-`452`).
8. Если нужна standalone PHP-страница, добавить seed и owned install/remove/fallback; просто положить PHP в `assets/` недостаточно (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1942`-`1958`).
9. Проверить enable → повторный enable/repair → disable → повторный enable и отдельный uninstall; активация/реактивация входит в обязательный frontend checklist (`docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:110`-`120`).

## 8. Универсальный чек-лист аудита любого аддона

Каждый пункт отмечать `pass / fail / не найдено / не применимо`; для `fail` фиксировать критичность `blocker / high / medium / low` и ссылку `файл:строка`.

### A. Discovery и именование

- [ ] Каталог находится непосредственно в `addons/`, `manifest.php` существует и возвращает массив (`inc/plugins/advancedfunctionality.php:3005`-`3013`).
- [ ] `id` и `name` непусты; bootstrap существует по объявленному относительному пути (`inc/plugins/advancedfunctionality.php:3013`-`3016`).
- [ ] Каталог, `id`, bootstrap, `af_<id>_*`, `af_<id>_enabled` и ACP slug согласованы (`inc/plugins/advancedfunctionality.php:1274`-`1287`).
- [ ] Нет конфликта глобальных функций/констант с другими аддонами; большой код корректно делегирован в modules (`inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php:97`-`137`).

### B. Lifecycle и идемпотентность

- [ ] Вся обязательная установка доступна из `af_<id>_install()` для обычного аддона (`inc/plugins/advancedfunctionality.php:1234`-`1237`).
- [ ] Если код рассчитывает на `is_installed()/activate()`, подтверждён соответствующий путь вызова; для обычного аддона он отсутствует (`inc/plugins/advancedfunctionality.php:1212`-`1237`).
- [ ] Повторный install не дублирует таблицы, поля, settings, templates, tasks и seed (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:357`-`379`).
- [ ] Disable вызывает только безопасный `deactivate()` и не удаляет пользовательские данные (`inc/plugins/advancedfunctionality.php:1246`-`1261`).
- [ ] Uninstall имеет явную политику сохранения/удаления каждого owned объекта; KB/CS сохраняют таблицы (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1837`-`1847`, `inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:54`-`93`).
- [ ] После изменений settings выполняется rebuild/reload (`inc/plugins/advancedfunctionality.php:1238`-`1243`).

### C. Hooks и frontend

- [ ] `af_<id>_init()` регистрирует все нужные callbacks; имена совпадают с реальными функциями (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:9314`-`9321`).
- [ ] Hook priorities отражают preload/compose/publish порядок (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:224`-`238`).
- [ ] `frontend.mode`, routes, response rules и `directory_fallback` явно соответствуют поведению (`docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:22`-`50`).
- [ ] Каждая owner-owned вставка assets проходит permission API (`docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:63`-`71`).
- [ ] JSON/AJAX endpoints не получают frontend runtime; caller page получает нужные assets (`docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:90`-`94`).
- [ ] HTML transforms и asset injection защищены marker/дedup от повторного применения (`inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php:2096`-`2122`).

### D. ACP

- [ ] `admin.slug/controller` ведут к существующему контроллеру, а имя класса соответствует алгоритму роутера (`inc/plugins/advancedfunctionality.php:1168`-`1177`).
- [ ] Есть `public static dispatch()`; контроллер не дублирует ACP header/footer (`inc/plugins/advancedfunctionality.php:426`-`452`, `inc/plugins/advancedfunctionality.php:530`).
- [ ] URL используют `af_view`; внутренние действия не перехватывают служебный `action` (`inc/plugins/advancedfunctionality/addons/advancedmenu/admin.php:28`-`41`).
- [ ] Все mutations проверяют post key, вход валидируется, вывод экранируется (`inc/plugins/advancedfunctionality/addons/adaptivethemeframework/admin.php:14`-`43`).
- [ ] ACP работает после enable и повторного enable; скрытие disabled addon соответствует роутеру (`inc/plugins/advancedfunctionality.php:1153`-`1157`).

### E. Standalone pages и assets

- [ ] Для каждого PHP в `assets/` найден явный installer; автоматического копирования ядром нет (`inc/plugins/advancedfunctionality.php:3001`-`3021`).
- [ ] Seed рассчитан на выполнение из целевого каталога и корректно загружает MyBB (`inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets.php:1`-`12`).
- [ ] Чужой target не перезаписывается и не удаляется; ownership проверяется marker (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1922`-`1930`, `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1961`-`1966`).
- [ ] Alias устанавливается повторно безопасно и удаляется на deactivate/uninstall (`inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php:40`-`58`).
- [ ] При невозможности установить alias есть рабочий fallback либо явная ошибка; KB имеет fallback через `misc.php` (`inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:1969`-`1988`).
- [ ] Manifest routes указывают на фактический canonical entry point (`inc/plugins/advancedfunctionality/addons/knowledgebase/manifest.php:13`-`29`).

### F. Языки и документация поведения

- [ ] RU/EN и front/admin структура соответствует генератору; используемые ключи присутствуют (`inc/plugins/advancedfunctionality.php:2206`-`2239`).
- [ ] Имя language file — `advancedfunctionality_<id>` (`inc/plugins/advancedfunctionality.php:2211`-`2213`).
- [ ] Публичные ссылки не регистрируются автоматически как обычные пункты AdvancedMenu; администратор добавляет CUSTOM link (`docs/contracts/FRONTEND_ASSET_MANIFEST_CONTRACT.md:127`-`134`).
