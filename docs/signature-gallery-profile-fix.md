# AdvancedSignatureBuilder 1.0.3: Gallery, предпросмотр и профиль

## Причины

Кнопки использовали реальный `AF_GalleryPicker`, но Gallery до проверки manifest выполняла старый blacklist, содержащий весь `usercp.php`. В результате собственный callback удалял/не подключал CSS и JS Picker, несмотря на `data-aa-gallery-picker` в форме. Кроме того, повторный callback Gallery на `pre_output_page` с приоритетом 9999 переписывал ресурсы после основного конвейера AF. Все изображения ошибочно использовали назначение `appearance_postbit_bg`.

В профиле не было хука ASB, разрешённого публичного контекста `member.php` и подключения его view stylesheet. ATF получал обычный результат парсинга переносимой BBCode-подписи, а компилятор постбит-пресета использовал корень `.atf-post`. Полная композиция и оформление опубликованной модели поэтому не применялись.

## Исправления

- Gallery: manifest явно разрешает editsig/do_editsig; доставка идёт единственным каноническим owner callback AF, без старого blacklist. Зарегистрированы `signature_background` и `signature_images` в существующем реестре служебных альбомов и Picker. Сохранены его загрузчик, проверки прав, CSRF, владения, approved/MIME, retention и выдача оригиналов GIF.
- Штатный `AF_GalleryPicker.open()` возвращает Promise открытия; дополнительный необязательный `onError` выводит ошибки внутри конструктора. Старые потребители продолжают работать с прежними аргументами и уведомлениями.
- Каждый callback изменяет только выбранный слот; `media_id` хранится числом, включение, миниатюра и кнопка «Заменить» обновляются. Удаление очищает только этот слот.
- В левой колонке отдельные панели «Фон подписи» и «Публикация подписи». URL фона свёрнут, основной способ выбора — Gallery. Переключатель статуса находится возле статуса. Панель действий последняя, её собственные угловые псевдоэлементы и двойная обводка убраны.
- Общий `af_asb_render()` получил необязательный `editorPreview`: три позиции галереи, одна позиция карточки и подписи пустых текстовых полей. Placeholders не попадают в JSON или публичный HTML. Все режимы используют одну модель/рендерер.
- Черновик и preview допускают пустую галерею. Публикация требует хотя бы одно включённое изображение с ID/URL. Клиент показывает ошибку возле группы, прокручивает и фокусирует её; сервер возвращает такую же привязку при POST с сохранением введённых данных. Проверки остальных изображений и MyBB не обходятся.
- Profile: `member_profile_end` использует UID владельца, опубликованную модель и совпадающий хеш native signature. Пустой native-вывод и устаревшая модель не восстанавливаются принудительно. Обычные подписи сохраняют существующий парсинг MyBB.
- ATF: существующий disclosure/frame стал самостоятельным `signature_surface`; прежние правила камней, нейтрального металла и раскрытия перенесены в общий `assets/surfaces/signature.css`. Источник зарегистрирован через Theme/File и подключается в профиле, постбитах и предпросмотрах. Не копируется разметка целого постбита, нет второй рамки вокруг готового компонента.
- Appearance: signature-only адаптер переиспользует оба существующих компилятора, включая legacy-параметры, шрифты и маску фона. Корень `.af-aa-signature-user-UID` ограничивает оформление владельцем подписи; параметры посетителя не используются. Обновлён перечень CSS предпросмотра Appearance.
- В `member.php` разрешён только публичный view CSS ASB; constructor/Picker/SCEditor там не подключаются. Native `<details>` и небольшой существующий ATF runtime отвечают за раскрытие.

## Проверки и пределы

Проверены PHP/JS-синтаксис, реальные функции manifest/Theme/File и Gallery owner delivery в контексте UserCP, открытие модального окна через shipped JS API на коротком DOM-стенде, draft/preview/publish валидация, сохранение ID и слотов, общий HTML четырёх композиций в UserCP/Showthread/Member, owner-scoped CSS, native fallback, миграция и отсутствие повторных обработчиков. Это проверки кода и контрактов, а не визуальная проверка авторизованной страницы warprift.ru.

На сайте после загрузки файлов обновить/реактивировать ASB, Gallery, ATF и Appearance через ACP, чтобы штатно синхронизировались новые версии manifest и Theme stylesheets. Пользовательские данные и таблицы этой правкой не изменяются. Используются существующие права Gallery Picker; доступность загрузки и прохождение модерации определяются настройками Gallery. При отсутствии активного Appearance применяется оформление темы; без ATF сохраняется обычный вывод композиции без его декоративного контейнера.

Заказчику остаётся проверить на сайте реальное открытие Gallery, выбор/загрузку GIF, сохранение/замену/удаление и визуальное совпадение всех трёх контекстов, в том числе при просмотре чужого профиля. Изолированный стенд не подтверждает доставку ассетов через конфигурацию, кеши и права реального сайта.

## Изменённые компоненты

- ASB: `manifest.php`, `advancedsignaturebuilder.php`, `modules/{integrations,model,preview,publishing,renderer,usercp}.php`, `templates/signature_builder.php`, `assets/{af_asb_builder.css,signature-builder.js,signature-view.css}`.
- Gallery: `manifest.php`, `advancedgallery.php`, `media_api.php`, `assets/advancedgallery.js`.
- ATF: `manifest.php`, `adaptivethemeframework.php`, `postbit-signature.php`, `assets/surfaces/{postbit.css,signature.css}`.
- Appearance: `manifest.php`, `studio.php`, `postbit_studio.php`.
- Короткие регрессии: `tests/{signature_builder_regression.php,signature_builder_integration_regression.php,signature_builder_asset_delivery_regression.php,appearance_postbit_settings_regression.php,signature_editor_regression.php,signature_runtime_regression.cjs,signature_gallery_runtime_regression.cjs}`. Из этого списка изменены builder/asset-delivery/appearance проверки и добавлена Gallery runtime проверка, остальные выполнены без изменений.
