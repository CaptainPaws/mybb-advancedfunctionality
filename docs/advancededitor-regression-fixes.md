# AdvancedEditor: исправления после bb68efbc

## Причины (проверено 6 октября 2026)

1. **Source/WYSIWYG:** обязательный alignment runtime имел hardcoded URL `assets/bbcodes/bbcodes/align/align.js`. На warprift.ru пакеты установлены в `assets/bbcodes/<pack>/`. Запрос с двойным каталогом возвращает Apache 404; цепочка lazy loader прекращается до загрузки `advancededitor_wysiwyg_bbcodes.js` и `advancededitor.js`. Сам SCEditor core и BBCode runtime доступны. По git history этот hardcode введён в `b408b8b8` (`load BBCode alignment renderer when WYSIWYG is activated`) и сохранён в `bb68efbc`; утверждать, что именно bb68 впервые добавил URL, неверно.
2. **Незагружавшийся модуль:** `align.js`. Исправление использует `packs[align].assets.js`, уже разрешённые по установленному manifest pack. Source/WYSIWYG остаётся lazy.
3. **Network:** `https://warprift.ru/inc/plugins/advancedfunctionality/addons/advancededitor/assets/bbcodes/bbcodes/align/align.js?v=1791283757` — HTTP 404, `text/html; charset=iso-8859-1`, 274 байта, HTML страницы Apache Not Found. Та же ошибка без query. Правильный `.../assets/bbcodes/align/align.js?v=1791283757` — HTTP 200, `application/javascript; charset=utf-8`, 21395 байт. Аналогично ошибались `charcountandprew_view.js` и `charcountandprew_view.css`: двойные пути — 404, одинарные — 200. Общий resolver теперь применяется также к view assets; observer, добавленный в bb68, получает разрешённые URLs.
4. **Frontend permissions:** причиной не являются. Это Apache static 404 для неверного пути, не отказ общего AF frontend contract. Manifest permission architecture и ядро AF не изменены; blacklist не добавлен.
5. **Белый текст:** shell использовал `--atf-surface` и светлый fallback, тогда как реальная тема задаёт `--atf-color-surface` и светлый `--atf-color-text`. После удаления у shell класса `sceditor-container` в bb68 также перестали совпадать theme selectors toolbar/surface. Теперь у shell согласованная пара surface/text, iframe получает ту же палитру. Цвета BBCode не перезаписываются: проверен красный `[color]`.
6. **Formatting Help/status:** bb68 перенёс help в нижнюю панель, но error остался `position:absolute; bottom:4px`. Теперь status помещается в `.af-ccp-bar`; flex wrap и min-width/overflow-wrap исключают наложение. Высота editing surface не зависит от status.
7. **KB:** PHP Knowledge Base hook безусловно вставлял HTML перед каждым `<!--af-ae-toolbar-end-->`, даже если AdvancedEditor уже включил KB через external registry. JS guard не мог предотвратить этот server-side дубль. Теперь late hook использует idempotent compiler helper: каждая toolbar получает команду только при её отсутствии. Проверено повторное выполнение hook, без DOM cleanup или скрывающего CSS.
8. **Доп. меню/icons:** compiler использовал текст title как visual, если title не был SVG/URL. Теперь текст остаётся в title/aria-label, visual использует существующий `starmenu.svg`. Проверены реальные SVG URLs: `assets/img/img/starmenu.svg` и `bold.svg` — 404, `assets/img/starmenu.svg` и `bold.svg` — 200. Icon resolver учитывает обе структуры установки; aliases source/spoiler/KB получают SVG. Добавлены SVG book, emoji, clipboard и video для controls без собственных векторов. Toolbar имеет единые размеры, separators, hover/active/disabled и перенос при ширине 375px.

## Изменённые файлы

- `inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php`: общий pack resolver, resolved alignment/view URLs.
- `inc/plugins/advancedfunctionality/addons/advancededitor/shell.php`: установленный путь SVG, меню, idempotent late addon toolbar integration.
- `inc/plugins/advancedfunctionality/addons/advancededitor/assets/advancededitor.js`: согласованная iframe palette; сохранение исходной ошибки initialization для диагностики.
- `inc/plugins/advancedfunctionality/addons/advancededitor/assets/advancededitor_shell.js`: status в bar, idle/loading/loaded/failed, сохранение ошибки и освобождение rejected promise, SVG late controls.
- `inc/plugins/advancedfunctionality/addons/advancededitor/assets/advancededitor_shell.css`: palette/layout, toolbar и mobile wrapping.
- `inc/plugins/advancedfunctionality/addons/advancededitor/assets/img/img/{book,emoticon,pastetext,youtube}.svg`: vector controls.
- `inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php`: idempotent server-side KB insertion и icon metadata.
- `tests/advancededitor_installed_asset_paths_regression.php`: обе структуры pack installation.
- `tests/advancededitor_lifecycle_browser.cjs`: production theme fixture, mobile toolbar, SVG/network checks, цвета и formatted content.
- `tests/advancededitor_feature_lazy_browser.cjs`: error/help non-overlap.
- `tests/fixtures/advancededitor_showthread.php`: повторные late KB hooks.
- `docs/advancededitor-regression-fixes.md`: этот отчёт.

## Проверки

- Actual HTTP response probes на warprift.ru с version query и без него; проверены status, MIME и тело ошибочного ответа.
- Воспроизведение ДО исправления на установке с одним bbcodes: три те же 404, WYSIWYG loader `failed`, editor instance отсутствует.
- Chromium + настоящий SCEditor 3.2.1 из MyBB 1.8.40: lifecycle suite проходит для nested и flat bbcodes/img layouts, с CSS, полученным с warprift.ru.
- A/B/C: initial lazy shell, Source → WYSIWYG → Source, 10 переключений; одинаковый instance, неизменная высота, 1 toolbar/KB/native widget. Два textarea всего — скрытое original data field и единственный native source field, без второго редактора.
- D: Source и iframe имеют цвет shell; `[color=#ff0000]` сохраняет красный цвет.
- E/F: Formatting Help, font/fontsize, extra menu до/после mode switch и fullscreen, popup в body/top layer, без clipping.
- G: повторные PHP KB hooks не дублируют control; lazy browser suite вставляет `[kb=rules:one]` и проверяет однократную загрузку KB JS (HTTP endpoint fixture).
- Loader: failure 503 → failed → ручной повтор → loaded; shared dependencies и parallel loading/cycles. Initial scripts: jQuery, content observer, published counter view и shell; тяжёлый SCEditor не загружается при открытии страницы/фокусе.
- Quote/align metadata, nested/edited quotes, lazy table converters, spoiler AJAX view, Full Reply и Quick Edit destruction/fullscreen/popups — проходят.
- 37 из 38 выбранных PHP tests проходят. Один существующий test `global_addon_frontend_manifest_regression.php` не запускается из-за отсутствующего в репозитории `addons/advresponsivelayout/manifest.php`; это не изменение editor contract.
- PHP lint, JS syntax и git diff --check проходят. Доступный CLI PHP — 8.4.24; production сообщает PHP 8.5.0, запуск серверных tests на нём не проводился.

## Граница проверки и установка

Изменения в main не являются deployment warprift.ru. Авторизованная forum session, SSH/deployment и DB в этом окружении отсутствуют: настоящие authenticated Quick Reply Submit/KB endpoint не вызывались. Browser tests используют реальные JS/CSS и локальный MyBB boundary fixture. При установке следует сохранить фактическую структуру каталогов форума (single или nested); оба варианта поддержаны. Новые SVG также нужно загрузить в установленный assets/img каталог и обновить интегрированные theme assets штатным механизмом AF.
