# UserCP: единый интерфейс двух аватаров

## Макет и границы

Референс: https://chatgpt.com/s/m_6ad40f5114f881918ec08e9259590c31 . Из публичной страницы получено и просмотрено исходное изображение «Готическая панель управления аватарами». Локальный рендер сверён с ним: две вертикальные панели; текущий аватар слева; вкладки и рабочая область справа; зона загрузки рядом с Gallery и полосой миниатюр; тёмно-синие поверхности, тонкие рамки, компактные уголки, голубой акцент и красное удаление. Размеры в макете не зафиксированы: ограничения выводятся из конфигурации соответствующего обработчика.

Сохранены существующие pun, header, горизонтальная навигация и внешний шаблон UserCP. Новая разметка — только внутренний PHP-компонент ATF. CSS добавлен в существующий surfaces/usercp.css с namespace .atf-avatar-editor, поэтому работает через прежние Theme/File источники. Нет нового глобального bundle или зависимостей. Runtime подключается при рендеринге action=avatar, включая возврат туда после ошибки сохранения.

## Владельцы и хранение

- Основной аватар: MyBB, action=do_avatar, поле multipart avatarupload, удаление remove; штатные users.avatar/avatardimensions/avatartype. Используются upload_avatar(), проверки размеров/MIME/групп/файла, штатный URL/Gravatar и native remove. Ядро не менялось.
- Аватар персонажа: AdvancedProfileFields, action=do_apf_secondary_avatar, поле secondary_avatar_upload, удаление remove_secondary_avatar. Действуют af_apf_validate_secondary_avatar_upload(), move_uploaded_file(), af_apf_set_system_value(), af_apf_delete_secondary_avatar_file(). Прежнее поле profile_secondary_avatar в af_apf_values и каталог uploads/avatars сохранены.
- AdvancedProfileUI и ATF продолжают получать дополнительный аватар через af_apf_get_secondary_avatar(); публичные провайдеры профиля/постбита не изменялись. Core используется через действующие hooks/assets/template ownership, новых таблиц или полей нет.

## Интерфейс и сохранение

Для каждой карточки отдельная форма с собственными action, именем файла, CSRF и удалением. Доступны выбор файла и drag-and-drop, проверка MIME/размера, preview, отмена и явная кнопка сохранения. Выбор изображения ничего не сохраняет автоматически. Вкладки переключаются локально, поддерживают ARIA и клавиатурные стрелки. Изображения не искажаются; узкий экран складывает блоки вертикально, миниатюры прокручиваются внутри полосы.

Runtime отправляет FormData в прежний usercp.php, с одним соответствующим источником. Файл/URL другой вкладки не подмешиваются. Удаление подтверждается; кнопка удаления не является первым submit формы, поэтому Enter в URL не запускает удаление. Успешный ответ штатного обработчика обновляет текущий аватар только в своей карточке. Поддержаны как HTTP Location, так и промежуточный MyBB meta-refresh на известный usercp?action=avatar. Возвращённые скрипты не выполняются, внешняя навигация не заменяется. При серверной/сетевой ошибке выбранный File или Gallery media и preview остаются в текущем состоянии.

## Единый Gallery Picker

Прежние ag_avatar_usercp_block(), шаблонная вставка «Выбрать из моей галереи» и bindAccountAvatarPicker() удалены, а не скрыты CSS. Оба новых контекста используют один AF_GalleryPicker:

- account_avatar — основной аватар и допустимые MyBB форматы;
- character_avatar — существующий обработчик APF и его JPG/PNG/WebP/GIF.

Новые методы recent/select — тонкие обёртки существующих picker_data/picker_select. Миниатюры используют те же owner-bound запросы и тот же серверный выбор, что модалка. Новых выборок, хранения или независимой модалки нет. Через picker_select возвращаются только проверенные metadata/URL, без серверного пути.

После подтверждения браузер получает оригинальные байты Gallery и передаёт их в штатную multipart-форму: обоим обработчикам нужен настоящий PHP HTTP upload. Сервер до сохранения повторно проверяет владельца, approved/local, канонический путь внутри каталога пользователя, MIME, CSRF и SHA-256 оригинала/загруженных байтов. APF-проверка Gallery идёт на usercp_start(4), перед родным APF usercp_start(5); основной hook остаётся usercp_do_avatar_start. Типизированный параметр контекста вынесен в отдельный helper, чтобы аргумент MyBB run_hooks не подменял назначение аватара.

Оригинал Gallery не удаляется, не перекодируется и не становится новым хранилищем аватара. Ранее закреплённые изображения не открепляются. Новый Gallery upload использует существующий загрузчик и служебные альбомы. При отключённой Gallery или отсутствии прав её вкладка/кнопки недоступны; нативные способы продолжают работать. Если runtime Picker не загрузился, показывается ошибка, а файл остаётся доступным способом выбора.

## Ограничения

- MyBB 1.8.40 не принимает WebP в upload_avatar(); основная карточка показывает только действительно разрешённые MIME из attachtypes/avatarfile/groups. APF принимает WebP.
- URL персонажа отключён с пояснением: APF не имеет безопасного URL-обработчика. Основной URL/Gravatar доступен только при allowremoteavatars и проверяется самим MyBB, без нового server-side fetch.
- Основной GIF, превышающий maxavatardims, отклоняется с пояснением риска потери анимации. Защита действует и при загрузке с компьютера в этом редакторе; автоматическое уменьшение GIF не обещается. Дополнительный GIF не перекодируется, размер файла проверяет прежний APF.
- При отключении APF отображается только основной аватар. При отключении ATF сохраняется прежний интерфейс MyBB/APF, новый ATF runtime не подключается.
- Автоматическое обновление относится к текущей карточке UserCP. Другие открытые вкладки форума обновят штатный аватар при следующем отображении, как прежде.
- Окончательная визуальная проверка на установленной теме остаётся заказчику; локальная сверка не включает пользовательские CSS и реальные портреты сайта.

## Проверки

Проведены короткие проверки PHP 8.5/JS, регистрации hooks/runtime и общих Gallery purpose. Проверены две формы, ограничения из настроек, независимые права, CSRF и отключение Gallery/APF. Реальный multipart через upstream upload_avatar() MyBB 1.8.40 и реальный af_apf_usercp_start() подтвердил сохранение GIF, совпадение SHA-256 оригинала/копии, независимое удаление APF, неизменность основного аватара при изменении APF, отказы CSRF/чужого media и игнорирование подменённого UID. Нативная загрузка при выключенной Gallery работает.

Короткий локальный Chromium-сценарий использует реальные Gallery/ATF runtime: открывает штатный modal для обоих контекстов, выбирает/отменяет миниатюру, проверяет оба multipart action, оригинальные GIF-байты, upload/URL, сохранение, удаление, сохранение preview при ошибке и отсутствие мобильного переполнения. Получены локальные desktop/mobile скриншоты для сверки с референсом. Длительных облачных UI-тестов нет.

Проверка secondary_avatar_postbit_runtime_regression.php проходит. Старая advancedprofilefields_secondary_avatar_regression.php всё ещё падает на требовании object-fit:cover/object-position:center center; воспроизведено также с исходными HEAD-версией теста и шаблона до этих изменений. Геометрия постбитов ради исправления старого теста не менялась. В тесте обновлено только имя нового внутреннего слота ATF.

Запуск компонентной проверки: ATF_AVATAR_HTML_OUTPUT=work/avatar-editor.html php tests/avatar_editor_regression.php (предварительно mkdir -p work), затем node tests/gallery_avatar_runtime_regression.cjs. Опциональный локальный HTTP fixture и upstream functions_upload.php описаны в tests/gallery_avatar_http_regression.py; не запускать fixture на рабочем форуме.

## Файлы

В inc/plugins/advancedfunctionality/addons/:

- adaptivethemeframework/avatar-editor.php — сборка контекста и защита GIF;
- adaptivethemeframework/templates/components/avatar-editor.php — две карточки;
- adaptivethemeframework/templates/usercp_avatar.html — внутренний слот без прежних форм;
- adaptivethemeframework/assets/adaptivethemeframework.avatar-editor.js — tabs/drop/preview/Gallery/native submit;
- adaptivethemeframework/assets/surfaces/usercp.css — локальные стили;
- adaptivethemeframework/adaptivethemeframework.php — display/save hooks и включение компонента;
- advancedgallery/avatar.php — два контекста серверной проверки; удалён старый HTML-инжектор;
- advancedgallery/assets/advancedgallery.js — общие recent/select API; удалён старый runtime;
- advancedgallery/media_api.php — purpose персонажа и общий выбор;
- advancedgallery/advancedgallery.php — хуки, права/фильтры обоих контекстов и owner assets;
- advancedprofilefields/advancedprofilefields.php — POST/UserCP guard, отключение старого блока при ATF; storage не менялся.

Проверки: avatar_editor_regression.php, gallery_avatar_regression.php, gallery_avatar_http_regression.py, gallery_avatar_runtime_regression.cjs, signature_gallery_runtime_regression.cjs, advancedprofilefields_secondary_avatar_regression.php.

При установке обновить совместно перечисленные исходники, управляемый шаблон usercp_avatar и существующий stylesheet surface_usercp штатной синхронизацией AF/ATF; очистить кэш статических ресурсов. Новых схем БД нет.
