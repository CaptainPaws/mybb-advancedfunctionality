> Историческое описание. Реализация подписи заменена самостоятельным [AdvancedSignatureBuilder](advanced-signature-builder.md); актуальные установка, архитектура и ограничения приведены там.

# Подпись постбита: раскрытие и редактирование

## Изменения

- ATF выводит подпись в прежней точке постбита: нативный закрытый `details`, компактный сепаратор с камнем/надписью/стрелкой, раскрываемый контейнер и облегчённая рамка. Используются существующие готические уголки, фасеты камней, металл и переменные акцентов. Пустая подпись не создаёт контейнер; идентификаторы различаются между постами и экземплярами.
- Раскрытие работает средствами браузера, включая клавиатуру. Небольшой независимый JS синхронизирует `aria-expanded` и фокус; один делегированный обработчик поддерживает динамические посты. Ресурс доставляется через существующий footer-компоновщик ATF только при наличии подписей. SCEditor для раскрытия не нужен.
- Содержимое подписи и его разбор не меняются. Изображения/GIF остаются исходными; ширина адаптивна, высота определяется содержимым. Настройки `.atf-post__signature` AdvancedAppearance продолжают применяться внутри рамки.
- На `/apstudio.php` добавлен блок «Подпись → Редактировать подпись». Штатная форма `/usercp.php?action=editsig` загружается в iframe того же origin при первом раскрытии. Закрытие/открытие блока сохраняет тот же документ и редактор. На `/studio.php` блок содержимого не добавляется.
- AdvancedProfileUI формирует компактный документ из существующего шаблона `usercp_editsig` только в рамках запроса. Все нативные поля, ошибки, текущая подпись, предпросмотр, CSRF и обработчик `do_editsig` остаются MyBB. БД шаблонов и формат хранения `users.signature` не меняются. Неизвестный шаблон сохраняет исходный вид. Перенаправление после успешного штатного сохранения сохраняет встроенный контекст и показывает подтверждение.
- Введённый текст при ошибках валидации возвращает сам MyBB. Для ошибки/логина без формы блок хранит резервную копию в памяти вкладки и предлагает возврат к редактированию. Никакого второго хранилища подписи нет.
- AdvancedEditor распознаёт только сигнатурный маршрут UserCP и поле `signature`; исключение из прежнего общего blacklist не распространяется на другие разделы UserCP. Используются прежние тулбар, SCEditor lifecycle, переключение режимов и реестр шрифтов ACP. При отключённом `sigmycode` остаётся обычное поле; кнопки изображений/смайлов/HTML учитывают настройки подписей, видео скрыто. Сохранение по-прежнему проверяет MyBB.

## Проверки

- PHP 8.5.11: синтаксис изменённых рабочих PHP-файлов; дополнительно PHP 8.4 для манифестов и теста.
- Node.js `--check` трёх изменённых/новых JS-файлов.
- `php tests/signature_editor_regression.php`: закрытая подпись, независимые ID, исходные HTML/GIF, сохранность нативной формы/CSRF/ошибок, права, lazy-блок, тулбар/шрифты, отсутствие дублирования shell.
- `node tests/signature_runtime_regression.cjs`: один обработчик, динамические подписи, ARIA/фокус, отсутствие ранней загрузки iframe и повторной загрузки при раскрытии.
- `php tests/appearance_postbit_settings_regression.php`: сохранность существующих пресетов и настроек оформления.
- `git diff --check`.

Целевые проверки прошли. Существующий `advancededitor_feature_lazy_regression.php` падает на `Tables must not carry the Tabs menu Font Awesome icon`; та же ошибка воспроизведена на неизменённом базовом коммите `2f262cf7`. Она не исправлялась в рамках задачи.

Реальная запись в БД MyBB через HTTP и визуальные/E2E-тесты не выполнялись. Маршрут сохранения проверен по исходнику MyBB 1.8.40: `UserDataHandler::verify_signature()`, штатная CSRF-проверка, обновление `users.signature`, затем `usercp_do_editsig_end`.

## Обновление

Через ACP обновить ATF до 0.31.0, AdvancedAppearance до 1.6.1, AdvancedProfileUI и AdvancedEditor до 1.1.0. Это синхронизирует принадлежащие плагинам шаблоны и стили. Ядро MyBB не изменено, новых таблиц нет. AdvancedProfileUI нужен для блока APStudio; обычный UserCP подключает AdvancedEditor независимо от него.

Визуально проверить сепаратор/рамку, мобильную ширину, переключение режимов и сохранение на warprift.ru. Native MyBB остаётся конечным источником прав и ограничений; выбор пользовательского шрифта зависит от существующей библиотеки AdvancedEditor и доступности её файлов.

## Файлы

- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/postbit.css`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/manifest.php`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/templates/postbit_classic.html`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/assets/studio.js`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/manifest.php`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/studio.php`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/templates/advancedappearance_workshop.html`
- `inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php`
- `inc/plugins/advancedfunctionality/addons/advancededitor/manifest.php`
- `inc/plugins/advancedfunctionality/addons/advancededitor/shell.php`
- `inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php`
- `inc/plugins/advancedfunctionality/addons/advancedprofileui/manifest.php`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/postbit-signature.php`
- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.signature.js`
- `inc/plugins/advancedfunctionality/addons/advancedprofileui/modules/signature_editor.php`
- `inc/plugins/advancedfunctionality/addons/advancedprofileui/assets/signature-editor.js`
- `inc/plugins/advancedfunctionality/addons/advancedprofileui/assets/signature-editor.css`
- `tests/signature_editor_regression.php`
- `tests/signature_runtime_regression.cjs`
- `docs/postbit-signature-editor.md`
