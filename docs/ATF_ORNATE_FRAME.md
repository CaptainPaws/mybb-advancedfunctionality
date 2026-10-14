# ATF: общая внешняя рамка, 0.29.6

Утверждённая гибридная рамка централизована в существующем `assets/adaptivethemeframework.css`: внешний срез 6px, градиентный сине-фиолетовый контур толщиной 1px, две приглушённые внутренние линии, восемь световых штрихов по внутренним углам, тёмный фиолетовый градиент. Семь публичных переменных `--atf-frame-*` имеют значения из спецификации. Стихия персонажа их не меняет.

## Изменённые файлы

Пути ниже относительно `inc/plugins/advancedfunctionality/addons/`, кроме tests/docs:

- `adaptivethemeframework/assets/adaptivethemeframework.css`: компонент `.atf-ornate-frame`, aliases реальных page roots, безопасные отступы ATF, прозрачный geometry wrapper.
- `adaptivethemeframework/assets/surfaces/knowledgebase.css`: удалена собственная внешняя рамка и её псевдоэлементы; размеры, padding и типографика сохранены.
- `adaptivethemeframework/manifest.php`: версия 0.29.6; существующие asset permissions и stylesheet attachments сохранены.
- `adaptivethemeframework/adaptivethemeframework.php`: версия 0.29.6 для обновления asset URL; композиция и runtime не менялись.
- `advancedwanted/assets/advancedwanted.css`: внешняя рамка передана ATF; прежний dossier-контур оставлен только для выключенного ATF.
- `advancedwanted/assets/advancedwanted_decoration.css`: прежний каталоговый контур оставлен только для выключенного ATF.
- `tests/atf_ornate_frame_browser.cjs`: проверки геометрии, paint, переполнения, слоёв и fallback на production CSS и корнях существующих шаблонов.
- `docs/ATF_ORNATE_FRAME.md`: Summary и результаты проверок.

## Контейнеры

| Контейнер | Страницы |
| --- | --- |
| `.pun.atf-page-shell` | index, forumdisplay, showthread; member/profile и другие собственные member-страницы ATF; usercp, private, modcp, moderation; memberlist, программно формируемый userlist; compose, polls, reputation и остальные шаблоны с этим root; UCP-интеграции account switcher и alerts |
| `.af-kb-page` | каталог KB, категории, статьи, help/edit и другие представления KB; каталог и публичные представления AdvancedCharacters, использующие общий KB renderer |
| `.pun.atf-layout-shell > .af-wanted` | каталог, публичное dossier, формы Wanted; внешний `.atf-layout-shell` остаётся прозрачным geometry wrapper |
| `.atf-ornate-frame` | явное подключение компонента к будущему самостоятельному root |

Aliases позволяют обновить существующие установленные шаблоны без миграции БД и копирования CSS в аддоны. Вложенные page roots исключены из декорирования. Отдельные постбиты, карточки, формы, таблицы и модальные окна не получают рамку.

В текущем ownership/template registry ATF нет самостоятельных page shells для `search.php` и `portal.php`. Шаблоны этих страниц не создавались и не подменялись; если пользовательский шаблон уже использует ATF page root, компонент применяется автоматически.

Clipping есть только у двух декоративных псевдоэлементов. Контентный root имеет `overflow: visible`; `isolation: isolate` удерживает отрицательные paint-слои за содержимым. Нет transform/filter/contain на root. Декорации имеют `pointer-events: none`. Двойной контур и орнамент принадлежат тому же компоненту. Новых bundles, JS, hooks или зависимостей аддонов нет.

## Проверки

Chromium / Playwright:

- `ATF_BASELINE_CSS=work/base.css node tests/atf_ornate_frame_browser.cjs`: PASS. 71 реальный ATF template root и 8 дополнительных контейнерных fixtures, 316 проверок на 1440/768/375/320px. Данные провайдеров заменены инертным HTML; это не установленный форум.
- Проверены четыре фактически нарисованных среза, прозрачные углы, внешний контур и внутренний орнамент. Проверен desktop-скриншот.
- Длинный неразрывный текст не создаёт горизонтальный scroll. Popover выходит за край; fixed overlay сохраняет viewport containing block; кнопка у края доступна; вложенная KB не получает вторую рамку; dossier не рисует второй контур.
- Computed presentation постбита, topbar, sidebar и meta-line совпадает с исходным core CSS. Переменная стихии не меняет стандартный цвет; настройка cut через публичную переменную работает.
- При снятии `atf-active` сохранены KB padding/fallback и прежний Wanted dossier-контур.
- `PHP_BIN=work/php/php node tests/stage6_atf_browser.cjs`: PASS. Существующий showthread fixture: 6 ширин, 12 комбинаций ширины/scroll, sticky/sidebar resizing, добавление поста, lazy modal open/close и mutation handling. Это запуск без baseline equality mode: изменение внешней рамки и page padding ожидаемо.

PHP 8.4.24 CLI (изолированно распакован в work/, системная установка не менялась):

10 PASS:

- `adaptivethemeframework_index_shell_regression.php`
- `adaptivethemeframework_showthread_contract_regression.php`
- `adaptivethemeframework_compose_regression.php`
- `adaptivethemeframework_ucp_profile_navigation_regression.php` (существующие warnings fixture)
- `adaptivethemeframework_userlist_online_indicator_regression.php`
- `atf_kb_modal_content_regression.php`
- `advancedcharacters_catalog_regression.php`
- `theme_stylesheet_real_repository_integration.php`
- `advancedwanted_frontend_permission_regression.php`
- `advancedwanted_catalog_regression.php`

8 FAIL, каждый воспроизведён с исходными файлами актуального `main` (194bb3b0), до изменений рамки:

- `adaptivethemeframework_design_system_regression.php`: проверка scope принимает keyframe `from` за selector.
- `adaptivethemeframework_postbit_classic_regression.php`: устаревшее ожидание CSS sidebar accent frame.
- `stage6_atf_css_delivery_regression.php`: существующий лимит 85 KB; исходная доставка showthread уже 87 286 байт. Компонент добавляет несколько KB статического CSS; новых runtime нет.
- `stage6_atf_hot_path_regression.php`: `Missing schema not diagnosed`.
- `global_addon_frontend_manifest_regression.php`: отсутствует `advresponsivelayout/manifest.php`.
- `theme_stylesheet_bundle_regression.php`: ожидания прежнего source seeding/import.
- `advancedwanted_chip_isolation_layout_regression.php`: ожидание прежнего detail grid sizing.
- `advancedwanted_settings_detail_regression.php`: ожидание прежнего outer pun wrapper.

PHP lint двух изменённых PHP-файлов, `node --check`, `git diff --check`: PASS. Пороговые значения и существующие проверки не ослаблялись. Production warprift.ru, установленный MyBB с реальными данными и PHP 8.5.0 в этой среде не проверялись.

## Обновление установки

Обновить файлы ATF и Wanted, выполнить обычное обновление ATF / синхронизацию Theme stylesheets через существующий механизм AF. Версия ATF согласована в manifest и bootstrap. Если Theme stylesheet изменён администратором, действуют штатные правила сохранения custom CSS; принудительная перезапись пользовательских стилей автоматически не выполняется. После синхронизации проверить живые страницы с активной темой форума.
