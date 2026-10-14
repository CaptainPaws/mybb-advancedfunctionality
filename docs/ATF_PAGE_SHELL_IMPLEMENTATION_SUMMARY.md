# ATF unified page shell — Summary

Реализация в локальной ветке `main`, исходный checkout `74b0621d`; актуальное исправление рамки
из удалённого main `0b2fb0b8` перенесено через GitHub connector.
ATF обновлён до 0.30.0. Утверждённая декоративная рамка и семь frame variables
сохранены; внешний root и геометрия централизованы в ATF.

## Охват

Переиспользованы существующие MyBB shells: index, forumdisplay, showthread,
member, usercp, private, memberlist/userlist, newthread, newreply, editpost,
modcp, moderation и остальные поддерживаемые ATF full-page seeds.

Добавлены/унифицированы страницы Appearance (apstudio/studio/workshop,
fittingroom), KB/AdvancedCharacters, Wanted, Shop, Inventory (inventory,
abilities, inventories), CharacterSheets (catalog/standalone), Gallery, Buddy,
Rules, Balance management и PostCounter activity/posts-by-user. Statistic
остаётся частью index; shells AccountSwitcher/Alerts UCP переиспользованы.

Embedded CharacterSheets, Inventory entity iframe и AJAX не оборачиваются.
В Appearance preview удаляются shell-классы извлечённого полного шаблона.
Внутренние интерфейсы, postbit, редактор, Canvas, Workflow, ARPG и покупки
не переделывались. Изменения Balance/PostCounter относятся только к page root.

## ACP

- `af_atf_page_max_width`: 1200px, диапазон 800–1920.
- `af_atf_page_padding`: 20px, диапазон 8–48.
- `af_atf_page_mobile_gutter`: 12px, диапазон 4–32.
- `af_atf_breakpoint_tablet`: 1024px.
- `af_atf_breakpoint_mobile`: 768px.
- `af_atf_breakpoint_narrow`: 480px.

Редактор Advanced functionality → ATF: внешняя рамка отклоняет неверные
диапазоны и порядок narrow < mobile < tablet. При чтении значений из обычного
MyBB ACP/БД также действует серверная нормализация. Breakpoints генерируют
числовые media queries для общего shell и общих компонентов. Специфические
breakpoints внутренних интерфейсов сохранены. Регистрация настроек выполняется
при install/activate/upgrade и сохраняет существующие значения.

## Устранённые конфликты

- Desktop shell больше не вычитает отступы из настроенной максимальной ширины.
- Убраны отдельные геометрические ограничения KB, Gallery, Appearance, Buddy,
  CharacterSheets и Balance при активном ATF; fallback сохранён.
- Рамка Wanted перенесена с внутреннего semantic root на общий shell.
- Inventory standalone больше не рисует конкурирующий viewport ::before и
  дополнительную внешнюю подложку; embedded/fallback сохранены.
- Убраны независимые block padding основных ATF page roots.
- Preview Appearance больше не создаёт вложенный shell.
- Общие статические ATF media rules перенесены в небольшой генерируемый CSS.

## Минимальные проверки

- `git diff --check`: PASS после исправления whitespace в изменённых строках.
- Целевая статическая проверка затронутых шаблонов: нет повторных literal
  page shells в одном page template; условный standalone CharacterSheets и
  удаление shell в preview проверены по коду.
- CSS/settings/delivery: проверены шесть keys, defaults, ranges, ordering,
  CSS mapping, числовые media queries, frame ownership и декларации ассетов.
- PHP lint: НЕ ВЫПОЛНЕН — PHP отсутствует в PATH; изменены 12 PHP-файлов.
  Runtime не устанавливался. Синтаксис PHP просмотрен вручную.
- JS syntax: не требуется — JS-файлы не менялись.
- Не запускались браузеры, screenshots, visual regression, repository-wide
  suites, SQL/MyBB environment или полные интеграционные тесты.

Визуальная и функциональная приёмка остаётся за владельцем установленного
форума. Не утверждается, что страницы визуально проверены.

## Установка и Git

Выполнить обычное обновление ATF и синхронизацию шаблонов/CSS аддонов.
Существующий manifest-based механизм и Theme/File identities сохранены.
Custom Theme CSS автоматически не перезаписывается: при manual override
объединить новые shell rules вручную. Контракт связан с документацией ATF и AF.

Git CLI fetch/push недоступны из-за proxy:8080. Через GitHub connector
подтверждён удалённый main 0b2fb0b8; сравнение показало один upstream commit.
Его исправление рамки сохранено: root paint, minifier-safe universal selector,
#content specificity. Публикация использует дерево этого main и проверку
expected_sha без force, сохраняя остальные upstream файлы. Итоговый commit hash
указан в сообщении задачи.

## Изменённые файлы

- `docs/ADAPTIVE_THEME_FRAMEWORK.md`
- `docs/ATF_ORNATE_FRAME.md`
- `docs/ATF_PAGE_SHELL_CONTRACT.md`
- `docs/ATF_PAGE_SHELL_FINAL_AUDIT.md`
- `docs/FRONTEND_ASSET_MANIFEST_CONTRACT.md`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/admin.php`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.css`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/compose.css`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/forum.css`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/gallery.css`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/knowledgebase.css`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/showthread.css`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/usercp.css`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/manifest.php`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/page-shell.php`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/assets/advancedappearance.css`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/assets/studio.css`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/studio.php`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/surfaces.php`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/templates/advancedappearance_apstudio.html`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/templates/advancedappearance_fittingroom.html`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/templates/advancedappearance_workshop.html`
- `inc/plugins/advancedfunctionality/addons/advancedbuddylist/advancedbuddylist.php`
- `inc/plugins/advancedfunctionality/addons/advancedbuddylist/assets/advancedbuddylist.css`
- `inc/plugins/advancedfunctionality/addons/advancedgallery/assets/advancedgallery.css`
- `inc/plugins/advancedfunctionality/addons/advancedgallery/templates/advancedgallery.html`
- `inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php`
- `inc/plugins/advancedfunctionality/addons/advancedinventory/assets/advancedinventory.css`
- `inc/plugins/advancedfunctionality/addons/advancedinventory/templates/advancedinventory_abilities_page.html`
- `inc/plugins/advancedfunctionality/addons/advancedinventory/templates/advancedinventory_inventory_page.html`
- `inc/plugins/advancedfunctionality/addons/advancedpostcounter/templates/advancedpostcounter.html`
- `inc/plugins/advancedfunctionality/addons/advancedrules/advancedrules.php`
- `inc/plugins/advancedfunctionality/addons/advancedshop/templates/advancedshop_fullpage.html`
- `inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php`
- `inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted.css`
- `inc/plugins/advancedfunctionality/addons/balance/assets/balance.css`
- `inc/plugins/advancedfunctionality/addons/balance/balance.php`
- `inc/plugins/advancedfunctionality/addons/balance/templates/balance.html`
- `inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets.css`
- `inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php`
- `inc/plugins/advancedfunctionality/addons/charactersheets/templates/charactersheet_fullpage.html`
- `inc/plugins/advancedfunctionality/addons/charactersheets/templates/charactersheets_catalog.html`
- `inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.css`
- `inc/plugins/advancedfunctionality/addons/knowledgebase/templates/af_kb_edit_fullpage.html`
- `inc/plugins/advancedfunctionality/addons/knowledgebase/templates/knowledgebase_page.html`
