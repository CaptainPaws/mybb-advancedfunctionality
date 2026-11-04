# ASB 1.0.2: исправление реальной доставки UI CSS

## Установленная причина

Проверены публичные HTTP-ответы warprift.ru, а не локальная демонстрация:

- `/inc/plugins/advancedfunctionality/addons/advancedsignaturebuilder/assets/signature-builder.css` возвращает **200, text/css, 16235 байт**. Файл содержит актуальные `.af-asb`-селекторы; отличие от репозитория — конечный перевод строки.
- Гостевой ответ `/usercp.php?action=editsig` содержит ссылку на `/cache/themes/theme2/signature-builder.min.css?v=9429ed63e8e91255`.
- Этот theme-кеш возвращает **200, text/css, 4062 байта**, но начинается с `.af-sig-builder{...}` и содержит `.af-sig-builder__columns`. В нём нет `.af-asb__layout`. Это CSS прежнего конструктора AdvancedProfileUI, несовместимый с текущей разметкой.

Таким образом, подтверждённый дефект — **выбор старого Theme CSS с другими селекторами**, а не отсутствие исходного CSS-файла. Поэтому правки `2dd8d777` в физическом файле не меняли вид страницы в Theme-режиме.

Механизм воспроизведён на производственных функциях AF: регистрация ищет существующий `themestylesheets` по basename. После переноса из APUI имя `signature-builder.css` сохранилось. При отсутствии подходящего `installed_checksum` AF принимает существующие отличающиеся байты за `customized_untracked` и сохраняет их вместо нового seed. Это защищает ручные правки, но не обеспечивает передачу ответственности между разными аддонами. Живой реестр/БД не читались; конкретные SID и флаги production-реестра неизвестны. Несовместимое содержимое публичного theme-кеша проверено непосредственно.

## Исправления

### AdvancedSignatureBuilder

- Физический UI CSS переименован в `assets/af_asb_builder.css`, без дублирования его содержимого. Manifest теперь использует самостоятельную идентичность `signature_builder_ui`; фактический Theme stylesheet AF будет называться `af_asb_builder.css`. Это исключает повторное принятие старой строки APUI с именем `signature-builder.css`.
- При install/activate/upgrade в стандартном реестре AF переносится только выбранный для прежнего источника режим **Theme/File/Auto**. Старый SID, старые CSS-байты и флаг ручного редактирования не присваиваются новому источнику. Повторная миграция сохраняет более новый выбор режима. Старые строки и их CSS не удаляются и не перезаписываются.
- Вся доставка продолжает использовать `af_theme_stylesheet_delivery_decision()`, стандартную регистрацию/sync AF и frontend permission API. В Theme выбирается самостоятельный актуальный Theme источник; в File — физический файл аддона. Нового механизма доставки или whitelist в Core нет.
- Введён правильный callback владельца `af_advancedsignaturebuilder_pre_output()`, вызываемый штатным AF-конвейером до коллектора, бандлера и нормализации. Прежний отдельный поздний MyBB-хук удалён.
- Для UserCP CSS/JS готовятся при размещении компонента до вычисления `{$headerinclude}`. Для stock-MyBB fallback используются те же функции. Резервная обработка полного HTML распознаёт также `</HEAD>`; маркер обеспечивает однократное включение.
- Переключатель «Показывать разделитель» в третьей строке связан с общим рендерером: он управляет внутренним разделителем между заголовками и текстом, а не внешним раскрывающимся сепаратором ATF. Добавлен только необязательный boolean `show_divider` с default `true`; старые JSON-модели совместимы. Таблицы, формат подписи MyBB, публикация и AJAX API не менялись.

### Adaptive Theme Framework 0.32.1

Слот `ucp.content` существует и на `editsig` получает корректный контекст `route.action=editsig`. ASB готовит HTML на приоритете 900, ATF размещает его на 1000. Сохраняются `pun`, общий каркас и обе навигации.

Реестр компонентов получил **общий необязательный callback `assets`**. ATF вызывает его только для разрешённого компонента с непустым результатом рендеринга. ASB передаёт собственный callback подготовки ассетов. Это расширение существующего реестра, без второго слота и без жёсткой привязки ATF к конструктору. Сам интерфейс остался в ASB.

### AF Core

При проверке итоговых CSS-тегов найден дополнительный дефект DB fallback: нормализатор сравнивал только путь `/css.php` и удалял ссылки на другие stylesheet ID как дубли. Теперь ID `stylesheet` входит в ключ дедупликации. Разные DB-backed стили сохраняются; разные версии одной таблицы по-прежнему дедуплицируются. Это общее исправление нормализатора, без правил для конкретного аддона.

## Файлы

- `inc/plugins/advancedfunctionality.php` — ключ дедупликации MyBB `css.php`.
- `addons/adaptivethemeframework/adaptivethemeframework.php`, `manifest.php` — callback ассетов компонента и версия.
- `addons/advancedsignaturebuilder/advancedsignaturebuilder.php`, `manifest.php` — lifecycle, канонический callback, новая идентичность CSS, версия 1.0.2.
- `addons/advancedsignaturebuilder/modules/assets.php`, `usercp.php` — перенос режима и доставка перед выводом оболочки.
- `addons/advancedsignaturebuilder/assets/signature-builder.css` → `assets/af_asb_builder.css` — переименование действующего источника оформления.
- `addons/advancedsignaturebuilder/templates/signature_builder.php`, `modules/model.php`, `modules/renderer.php`, `assets/signature-view.css` — рабочий переключатель внутреннего разделителя.
- `tests/signature_builder_asset_delivery_regression.php`, `signature_builder_integration_regression.php`, `signature_builder_regression.php` — целевые короткие проверки.

Пути `addons/` здесь относительно `inc/plugins/advancedfunctionality/`.

## Проверки и пределы

На PHP 8.5.11 проверены синтаксис изменённых файлов и целевые сценарии:

1. Воспроизведение принятия старого Theme CSS как customized/untracked.
2. Независимая регистрация нового источника из фактического CSS-файла, с проверкой записанных байтов.
3. Перенос Theme/File режима и повторяемость без изменения прежнего CSS.
4. Решения Theme, File и Auto через производственные функции регистрации, resolver, permission API и нормализатор AF.
5. Обе ссылки DB fallback `css.php`, один editor runtime, отсутствие дублирования, отсутствие runtime на showthread.
6. Реальный ATF composer и контракт шаблонов MyBB: owner-ассеты подготовлены в `headerinclude` до вычисления родителя; каркас, навигация и нативный fallback сохранены.
7. Совместимость модели, сериализации и нативного сохранения; действующий разделитель в общем рендерере. JS-синтаксис проверен Node.

Локальная HTML-демонстрация и Chromium в этой задаче **не использовались как доказательство интеграции**. Доступа к авторизованному экрану и production-БД нет. Визуальное соответствие макету на warprift.ru после установки не проверено и не заявляется.

Дополнительно запускались два старых runner: `theme_stylesheet_bundle_regression.php` и `theme_stylesheet_activation_runtime_regression.php`. Они падают одинаково на исходном `2dd8d777`: первый содержит устаревшие строковые ожидания/интерполяцию переменных, второй сравнивает сохранённый integer `1` с boolean `true`. Новая целевая проверка использует объявления существующей DB-фикстуры и отдельно выполняет сценарии доставки; эти независимые ошибки старых runner не исправлялись.

## Обновление сайта

1. Загрузить изменения **AF Core, ATF и ASB**; новый `assets/af_asb_builder.css` обязателен. Удалить заменённый файл ASB `assets/signature-builder.css`, чтобы он не обнаруживался как отдельный устаревший source.
2. Реактивировать ASB **1.0.2** штатно через ACP AF: перенесётся выбранный режим, стандартный sync создаст отдельный `af_asb_builder.css`. **Force Resync для прежнего `signature-builder.css` не нужен.** Старый CSS остаётся резервом.
3. В AF CSS-диагностике активной темы проверить новый source `signature_builder_ui`: SID, выбранный режим, source checksum и актуальный Theme CSS. При ошибках sync исправить указанную AF причину; не считать один вызов sync подтверждением успеха.
4. На авторизованной странице проверить Network: в Theme загружается `af_asb_builder.min.css`/`af_asb_builder.css` или `css.php?stylesheet=<новый SID>` с `.af-asb__layout`; в File — `/addons/advancedsignaturebuilder/assets/af_asb_builder.css`. В computed styles `.af-asb__layout` должна иметь `display:grid`, на широком контейнере — две колонки, карточки — четыре колонки.
5. Заказчику остаётся проверить внешний вид активной темы, переключатели, изображения/ссылки, предпросмотр, сохранение и мобильную компоновку на реальном сайте. Изображения и пресет подписи остаются пользовательскими.
