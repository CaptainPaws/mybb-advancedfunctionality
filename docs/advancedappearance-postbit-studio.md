# AdvancedAppearance 1.6.0 — редактор постбита

Изменения подготовлены в локальной копии CaptainPaws/mybb-advancedfunctionality, база `fbfd2830`. Установка на warprift.ru выполняется отдельно.

## Добавлено

- 15 областей редактора в восьми группах: декоративные акценты; верхняя/боковая панели; оба аватара; кнопки, имя и текст; метаданные и статистика; фон, иконка и две подписи плашки; подпись сообщения.
- Камни готических рамок, аватаров, разделителей, плашки и репутационного угла используют существующие CSS-фасеты ATF: отдельно цвет и свечение. Металл, геометрия и материал рамок сохраняются.
- Верхняя панель: независимая полупрозрачная градиентная дымка и фоновая картинка. Панели, плашка и подпись: цвет/прозрачность фона, градиент, изображение, положение/масштаб/повторение, маска затемнения или осветления с цветом, интенсивностью, направлением и начальной/конечной непрозрачностью. Фоновые слои не перекрывают содержимое.
- Независимые аватары и изображение плашки: загрузка/выбор Gallery либо URL; замена/удаление, возврат к исходному механизму, object-fit/object-position и масштаб. Gallery `full` сохраняет оригинальный GIF.
- Обе подписи плашки: текст, цвет, семейство/размер/жирность/курсив шрифта и свечение. Настройки не содержат адреса, ID или обработчиков листа персонажа. Вывод сохраняет экранирование APUI.
- Подпись сообщения: фон, картинка, наложение, цвета текста/ссылок/наведения, шрифт, размер, жирность, курсив, интерлиньяж, цвет и интенсивность свечения. Сохранённые BBCode/HTML и изображения подписи не переписываются.
- Кнопки: текст, иконки, активный цвет и свечение. Метаданные: фон/текст/ссылки/наведение. Статистика: фон, подписи, числа, иконки и свечение. Расположение чисел под иконками сохранено.
- Основной текст: цвет и мягкое свечение с исключениями для цитат, кода, спойлеров и явно оформленного вложенного содержимого.
- Шрифты AdvancedEditor: существующий `af_advancededitor_collect_font_families_for_payload()`, его метаданные и CSS-кэш; стабильные ключи и fallback удалённого семейства. Файлы не дублируются. Выбранный шрифт показывается в селекторе и применяется в предпросмотре/постбите.
- Предпросмотр использует настоящий `postbit_classic.html`, компоновщики ATF/APUI, существующие рамки и компонент репутации. Собственная подпись проходит безопасный парсер MyBB; отсутствующие данные заменены примерами.
- Сброс свойства, группы свойств и области; ползунки с процентами; прежние черновики, сохранение, восстановление и назначения. Старые avatar/darkening-поля переводятся в новые контролы при открытии. Старая схема и таблицы пресетов сохранены.
- Совместимость существующих PID-снимков масок: учёт новых media IDs, разрешение аватаров и оформление плашки. CSS ограничен автором либо PID-снимком. Runtime Мастерской не добавлен в showthread.php.

## Отключение анимаций постбитов

Postbit исключён из нормализации эффектов и проверки необходимости их доставки. Диспетчер прекращает обработку до создания слоёв и удаляет старые слои; Canvas-движок дополнительно запрещает регистрацию постбита. Старые Canvas-значения пресетов игнорируются. Настройки удаляются из серверного HTML редактора постбита; переключатель удалён из виджета ElementTheme, используемого AdvancedMenu. Цвета остаются. Анкеты, профили, листы, GIF и функциональные CSS-переходы сохраняются.

## Использованные механизмы

Существующие JSON-пресеты, черновики, назначения и маски AdvancedAppearance; Media API Gallery с проверкой владения/MIME/модерации/retention; доменная политика Gallery; каталог и CSS-кэш AdvancedEditor; компоненты ATF/APUI. Новые параметры наследуют исходное оформление, если отсутствуют. Значения по умолчанию наложений и дымки заданы в компиляторе. Изменены точки подключения CSS-переменных в действующих жёстких правилах ATF; новые `!important` не добавлены. Ядро MyBB и содержимое подписи не изменены.

## Проверки

- PHP 8.5.11 CLI: lint изменённых PHP-файлов.
- Node.js `--check`: четыре изменённых JS-файла.
- `tests/appearance_postbit_settings_regression.php`: round-trip, старые поля, GIF-URL, шрифты/fallback, безопасные текст/URL, изоляция CSS, кадрирование и отключённый Canvas.
- `tests/element_preferences_regression.php`: права/CSRF, сохранение переключателей и отсутствие postbit-настройки.
- `tests/element_theme_effects_regression.php`: сохранность метаданных/других эффектов, игнорирование старого postbit, условная доставка ресурсов.
- `git diff --check`.

Проверки прошли. Mock-ошибки хранилища/KB в существующих проверках ожидаемы. Проверка с реальной БД MyBB, загрузка файлов через HTTP и визуальные/E2E-тесты не запускались. Обновлены ожидания существующего `appearance_studio_integration.php`, но его сценарий с БД не выполнялся.

## Установка и ограничения

Применить патч к базе `fbfd2830` либо перенести файлы из архива, сохранив пути. Выполнить обновление AdvancedAppearance до 1.6.0 через ACP. Прежние стандартные квоты 4096 байт/3 изображения повышаются до 32768 байт/8 изображений; другие настроенные значения ACP сохраняются. Ресурсы имеют версионирование; при необходимости очистить браузерный кэш.

Gallery, ATF, AdvancedProfileUI и AdvancedEditor должны быть доступны и настроены. Загрузки требуют прав Gallery и разрешённого статуса модерации. Внешние URL ограничены HTTP(S), прямыми PNG/JPG/WebP/GIF и доменной политикой Gallery; собственный origin сайта также разрешён. SVG, data:, JavaScript и URL с логином/паролем запрещены. Доступность внешнего изображения и блокировка смешанного HTTP/HTTPS зависят от браузера и источника. Предпросмотр шрифта внутри option зависит от браузера; выбранное семейство также применяется к самому селектору. CSS рассчитан на современные браузеры. Визуальная приёмка, sticky-поведение и анимация GIF на настоящих данных остаются для проверки на warprift.ru.

## Изменённые файлы

- `inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/postbit.css`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/advancedappearance.php`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/assets/studio.css`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/assets/studio.js`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/manifest.php`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/personal.php`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/post_masks.php`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/studio.php`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/surfaces.php`
- `inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets/element-canvas-engine.js`
- `inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets/element-effects.js`
- `inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets/element-preferences.js`
- `inc/plugins/advancedfunctionality/addons/advancedelementtheme/effects.php`
- `inc/plugins/advancedfunctionality/addons/advancedelementtheme/preferences.php`
- `inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php`
- `tests/appearance_studio_integration.php`
- `tests/element_preferences_regression.php`
- `tests/element_theme_effects_regression.php`
- `inc/plugins/advancedfunctionality/addons/advancedappearance/postbit_studio.php`
- `tests/appearance_postbit_settings_regression.php`
- `docs/advancedappearance-postbit-studio.md`
