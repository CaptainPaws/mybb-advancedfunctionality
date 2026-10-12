# AdvancedAppearance Studio: архитектурный контракт

## 1. Статус, источник и границы

Этап 1 для warprift.ru; целевые версии MyBB 1.8.40 и PHP 8.5.0. Это контракт последующих этапов, а не реализованная функциональность или выполненная миграция.

Проверенная `main`: `f869e405f70bb14825eea3de9dc2a344a841723b`, подтверждена GitHub API 12 октября 2026. Локальная рабочая ветка `work` находится на непосредственном родителе `dc5008464968c8c15e14ea89664e2e9bbbe7d971`; локальная ссылка `origin/main` устарела. Исследованы локальные исходники перечисленных аддонов и diff единственного последующего commit, включая изменения ATF/APUI и `modules/profile_providers.php`. Поэтому ссылки на символы относятся к проверенной main; документ добавлен в предоставленный checkout без переключения или перезаписи его ветки. Git HTTPS через окружение недоступен; чтение удалённой main выполнено через GitHub connector.

Область: Appearance, Shop, Gallery, Editor, ATF, ElementTheme, непосредственная интеграция APUI и только appearance-граница Inventory. Общего аудита репозитория нет. Схемы описаны по установочному коду, а не по production-БД. Состояние данных, настройки сайта и фактические права групп не проверены. PHP-код не запускался, БД не подключалась, тесты не запускались; совместимость исполнения на PHP 8.5 этим документом не сертифицируется.

## 2. Аудит существующего поведения

Все таблицы ниже указаны без MyBB `TABLE_PREFIX`. Базовый путь аддонов: `inc/plugins/advancedfunctionality/addons/`.

### 2.1. AdvancedAppearance

Источник: `advancedappearance/advancedappearance.php`, прежде всего `af_aa_ensure_schema`, реестры целей, CRUD, runtime resolver и frontend page renderers.

| Таблица | Существующие поля и индексы | Повторное использование |
| --- | --- | --- |
| `af_aa_presets` | `id`, `slug`, `title`, `description`, `preview_image`, `target_key`, `settings_json`, `enabled`, `sortorder`, `created_at`, `updated_at`; UNIQUE `(slug,target_key)` | Единый каталог старых магазинных и новых личных пресетов. Сейчас UID и версия формата отсутствуют. |
| `af_aa_assignments` | `id`, `entity_type`, `entity_id`, `target_key`, `preset_id`, `is_enabled`, timestamps; UNIQUE `(entity_type,entity_id,target_key)`, индексы цели и пресета | Основной источник активного назначения; сейчас используются `user`/UID и `thread`/TID. Можно добавить `post`/PID без изменения структуры. |

`assets/apstudio.php` и `assets/fittingroom.php` — исходники корневых page aliases. Их синхронизация/удаление защищена сигнатурами владения (`af_aa_sync_alias_file`, `af_aa_alias_is_owned`). Не создавать новый addon или конкурирующие aliases.

`af_aa_render_apstudio_page()` требует enabled Appearance и `af_aa_is_admin_user()`; POST проверяет `my_post_key`. Создание/редактирование — `af_aa_save_front_preset`, toggle/delete — отдельные функции. CRUD helpers сами не проверяют владельца и действуют по ID. Админ определяется super-admin, группой 4/дополнительной группой 4 либо записью `adminoptions`. Это редактор общего каталога, не пользовательская мастерская.

`af_aa_render_fittingroom_page()` проверяет enabled addon, читает enabled-каталог, показывает примерку, но не выдаёт владение и не сохраняет assignment. Нет требования покупки для просмотра каталога. При добавлении личных записей все каталожные выборки должны сохранить прежнюю публичность только для legacy-каталога.

Реестр `af_aa_get_supported_targets_registry()`:

| target_key | Реальный контракт |
| --- | --- |
| `apui_profile_pack` | Профиль: body и banner; user assignment. |
| `apui_postbit_pack` | Постбит: author/name/plaque и связанные параметры; user assignment. |
| `apui_thread_pack` | Страница темы: фон/banner; thread assignment, выбор автором темы при создании и редактировании первого поста. |
| `apui_sheet_pack` | Лист персонажа, включая modal/fragment; user assignment. |
| `apui_fragment_pack` | Запись каталога дробного пресета; назначается конкретная цель с суффиксом fragment key. |
| `apui_theme_pack` | Legacy-only: исключён из нового создания, покупки и применения реестром; данные не удалять. |

Fragment keys: `profile_body`, `profile_banner`, `profile_avatar_frame`, `postbit_author`, `postbit_name`, `postbit_plaque`, `postbit_plaque_icon`, `postbit_avatar_frame`, `thread_body`, `thread_banner`. Запись в реестре не гарантирует независимую реализацию каждой поверхности: в payload, например, profile avatar frame объединяется с banner keys. Отдельную рамку нужно картировать явно; нельзя обещать её только по наличию target key. `af_aa_get_surface_registry()` сейчас содержит только `sheet`, не универсальный registry всех окон/аватаров.

Для reuse: `af_aa_get_active_assignment`, `af_aa_get_preset_by_id` (enabled-only), `af_aa_get_preset_row` (включая disabled), `af_aa_upsert_user_assignment`, `af_aa_delete_user_assignment`, `af_aa_upsert_thread_assignment`, `af_aa_get_valid_thread_preset`, `af_aa_get_thread_preset_settings`, `af_aa_get_user_preset_settings_for_target`, `af_aa_merge_keys`, `af_aa_prime_runtime_cache`, `af_aa_build_user_css_payload`, `af_aa_render_page_css`.

Inventory apply проверяет управление owner UID и принадлежность item, пишет `af_aa_assignments`, очищает legacy `af_aa_active`; qty при применении не расходуется. `af_aa_is_user_assignable_target()` исключает thread pack и thread fragments. Thread resolver повторно проверяет автора темы и Inventory ownership. User runtime resolver читает assignment и enabled preset, но не является полноценной проверкой права владения; helpers upsert также не авторизуют вызывающего. Нужен единый сервис авторизации поверх существующих storage helpers.

Существующий sanitizer разворачивает defaults в полный набор settings, допускает URL и custom CSS. `af_aa_sanitize_custom_css()` лишь обрабатывает теги style; prefixing CSS не является security boundary. Это допустимо сохранять для доверенных legacy/admin записей, но нельзя открывать пользователям.

### 2.2. AdvancedShop и непосредственная граница Inventory

Источник: `advancedshop/advancedshop.php`; в Inventory просмотрены только resolver/apply/unapply appearance.

Существующие таблицы: `af_shop_shops`, `af_shop_categories`, `af_shop_slots`, `af_shop_carts`, `af_shop_cart_items`, `af_shop_orders`. Shop slot — товарная позиция, не право хранения пресетов. `source_type=appearance`, `source_ref_id=preset.id`, `kb_type=appearance`, `kb_key=appearance:<id>` связывают товар с каталогом. Reuse: `af_advancedshop_appearance_resolve_preset`, `af_advancedshop_appearance_validate_target`, `af_advancedshop_slot_payload_from_source`, `af_advancedshop_checkout_collect_items`.

Checkout (`af_advancedshop_checkout`) требует UID и включённый Inventory; dispatch использует существующие buy/manage permissions и POST key. Собирает корзину и проверяет баланс до `START TRANSACTION`; затем создаёт paid order, списывает валюты, вызывает grant, очищает корзину, COMMIT; при исключении ROLLBACK. `af_advancedshop_grant_inventory_item()` выдаёт через `af_inv_add_item` customization item с subtype по цели, qty и metadata `appearance.preset_id/target_key/preview_image/settings_json`. Покупка не активирует пресет автоматически. `af_advancedshop_grant_legacy_inventory_bypass()` присутствует в цепочке; нельзя добавлять ещё одну независимую выдачу.

`af_shop_orders`: `order_id`, `shop_id`, `uid`, `total`, `currency`, `created_at`, `status`, `items_json`; индекс `(uid,created_at)`. Это существующая история покупок и snapshot товаров, не журнал факта однократной выдачи каждой строки. Существующая схема не имеет checkout idempotency key или grant UNIQUE по строке заказа. Очистка корзины защищает последовательный повтор после успеха, но не два запроса, прочитавших одну корзину до транзакции. Отдельная проверка уникальности таланта не является защитой appearance grant. Гарантия rollback зависит от движков участвующих таблиц и поведения Balance/Inventory; её нельзя выводить только из наличия START TRANSACTION.

`af_aa_active`, создаваемая Shop: `entity_type`, `entity_id`, `target_key`, `item_id`, enabled, applied_at, UNIQUE entity/target. Это legacy item-based активность; новая нормальная активность — preset-based `af_aa_assignments`. Inventory `af_advinv_active_appearance_map()` читает assignments. Не использовать active как вторую систему личных назначений. Сохранить API `api_appearance_apply/unapply`, `af_advinv_resolve_appearance_item`, `af_inv_get_item_for_owner`, `af_inv_can_manage_owner` и metadata старых items.

### 2.3. AdvancedGallery

Источник: `advancedgallery/advancedgallery.php`, `assets/advancedgallery.js`.

| Таблица | Назначение |
| --- | --- |
| `af_gallery_media` | `id`, `uid_owner`, local/remote, approved/pending/rejected, paths, MIME/ext/size/dimensions, remote URL/provider/embed, timestamps и описания. |
| `af_gallery_albums` | Владелец, visibility public/registered/groups/private, allowed_groups, cover, sort; default album schema добавляет `is_default`. |
| `af_gallery_album_media` | Membership, sort_order; UNIQUE `(album_id,media_id)`; файл не копируется. |
| `af_gallery_logs` | Действия с actor/owner/media_id и details. |

Имеющиеся Media API — процедурные helpers и HTTP actions Gallery, не готовый универсальный серверный resolver для Appearance. Reuse: `ag_get_album`, `ag_can_view_album`, `ag_can_manage_album`, `ag_can_view`, `ag_can_upload`, `ag_can_moderate`, `ag_can_delete_media`, `ag_media_is_direct_image`, `ag_media_thumb_url`, `ag_remote_resolve_url`, `af_ag_resolve_upload_path`, `ag_json_response`.

Upload `ag_handle_upload`: CSRF, group upload permission, размер, allowlist extension, MIME jpeg/png/gif/webp, getimagesize, random filename, Gallery thumbnails. Path: `<uploadspath>/af_gallery/<uid>/<Y>/<m>/`; uploadspath разрешается относительно MYBB_ROOT либо как абсолютный путь. Appearance не принимает path и не пишет туда самостоятельно. Remote-add имеет собственный Gallery provider/domain resolver; embed/video нельзя превращать в CSS image.

Picker: `gallery.php?action=picker`, `picker_data`, `upload_do`, `remote_add_do`; `ag_handle_picker_data` требует авторизацию и upload permission, выбирает `uid_owner=current UID`, выдаёт массив `{id,title,thumb,url_full,type,provider,created_at}`. Не фильтрует approved status; неверный/чужой album_id не вызывает обязательный отказ, а оставляет owner selection без join. JS callback вставляет BBCode/URL, не типизированную media reference. Поэтому новый selection mode должен возвращать media ID, сохранив текущий редакторский режим и формат ответа по умолчанию.

`ag_can_view_album()` учитывает владельца, модератора, visibility и группы. Однако доступ к album page не означает права публичного повторного использования файла. Публичный storage URL сам по себе не защищает приватный альбом от доступа по известному URL. Для публичного postbit безопасный начальный policy: только собственное approved direct image в public-альбоме, без membership в ограниченных альбомах; orphan/ambiguous membership запрещается. Gallery должна централизованно проверять это правило и при выборе, и при рендере. Для приватного preview проверка учитывает viewer, но разрешение preview не разрешает public publish.

### 2.4. AdvancedEditor

Источники: `advancededitor/advancededitor.php`, `shell.php`, `assets/advancededitor_shell.js`.

Toolbar строится `af_advancededitor_shell_registry`/`af_advancededitor_shell_toolbar`; доступны metadata `cmd`, `handler`, `capability`, `runtime`, packs/custom buttons. JS принимает `afAePayload`/`afAdvancedEditorPayload`, `afAeButtons`, `afAeCapabilities`; handler вызывается через `af_ae_<handler>_exec` либо `afAeBuiltinHandlers`. Регистрировать Appearance capability до инициализации toolbar, с lazy loading через `afAdvancedEditorShell.loadCapability`; не подменять SCEditor целиком.

Shell обслуживает `textarea[name=message]` для newthread/newreply/editpost/Quick Reply. Quick Edit отдельно распознаёт `quickedit_<pid>`, `name=value`, showthread, native form внутри `#pid_<pid>` и `.post`. Lifecycle: `af:editor-ready` с textarea/instance/quickEdit, `af:editor-destroyed`, `af:capability-ready`, `af:capability-activate`, MutationObserver init/destroy для AJAX DOM. `afAdvancedEditorShell` экспортирует init/destroy/activate/insert.

Это editor lifecycle, не API сохранения PID mask. Сейчас Appearance обрабатывает только newthread/editpost для thread preset; нет полноценной цепочки масок newreply/Quick Reply/Quick Edit. Hidden field обычной формы сам по себе не доказывает передачу через native AJAX сериализацию Quick Reply/Quick Edit. Последующий этап должен проверить фактический payload и добавить узкий adapter, сохранив native MyBB response contract.

### 2.5. Adaptive Theme Framework и APUI

Источники: `adaptivethemeframework/adaptivethemeframework.php`, `ownership.php`, `templates/postbit_classic.html`, `assets/surfaces/postbit.css`; `advancedprofileui/advancedprofileui.php`, `modules/profile_providers.php`.

ATF владеет полной структурой postbit/compose/profile через template seeds и ownership/handoff. APUI использует этот ownership contract и отдаёт данные/providers; Appearance не становится владельцем postbit шаблонов. Reuse: `af_adaptivethemeframework_post_context`, `af_adaptivethemeframework_preload_postbit_data`, `af_adaptivethemeframework_compose_postbit`, component/slot registry.

Корень: `article#post_<pid>.post.classic.atf-post.af-atf-display`, `data-author-uid`, `data-element`, `data-element-surface=postbit`, `af_aa_user_class`; тело: `#pid_<pid>`. Per-post context содержит native post и slots; mask надо разрешать по настоящему post UID/PID, не по текущему зрителю. Сохранить все ID, кнопки, moderation tokens, anchors, signature и вложенность.

Topbar primary avatar = native `$post['useravatar']`, сохранённый в `af_atf_primary_avatar`; sidebar secondary avatar = APF/APUI secondary, `af_atf_secondary_avatar`. ATF явно не перезаписывает useravatar. Маска может менять только локальное отображение этих двух поверхностей через разрешённые media references; не обновляет users.avatar, APF secondary или аватары глобального header/menu.

CSS consumers: `--af-apui-postbit-author-bg-image/overlay`, `--af-apui-postbit-name-bg-image/overlay`; ATF layout/цвета используют `--atf-*`, элементные акценты читают `--af-element-*` через `--atf-post-accent*`. Не все Appearance fragment keys имеют самостоятельный ATF consumer. Составить явную карту параметр → переменная → существующий узел; без изменения DOM.

APUI plaque/icon resolver использует defaults → legacy theme pack → postbit pack → fragment. Новый per-post resolver должен подавать те же итоговые значения и для markup plaque, а не только CSS: UID-global payload не способен различить два PID одного автора.

В main `f869e405` sheet уходит из обычных profile tabs в quick modal; добавлен `profile.extra`, quick route `member.php?action=profile&uid=…&af_profile_tab=quick&af_profile_quick=…`, JSON `{html,assets}`, событие `af:profile-tab-loaded`, lazy asset loading и refresh effects. Sheet открывается с `embed=1`; application fragment имеет owner/surface attributes. Новый Appearance resolver обязан работать на фрагментных ответах явно: quick application renderer вызывает ElementTheme, но не гарантирует Appearance page-wide pre_output CSS. Не привязывать оформление sheet к существованию прежней вкладки.

### 2.6. AdvancedElementTheme

Источники: `advancedelementtheme.php`, `css.php`, `effects.php`, `preferences.php`, `assets/element-effects.js`, `assets/element-canvas-engine.js`.

Существующие `af_element_theme_styles`, `af_element_theme_surfaces` хранят административные metadata палитры/эффектов; `af_presentation_preferences` — `(uid,preference_key)` store ATF/ElementTheme, с JSON `element_effects` для зрителя. Не использовать их как каталог личных Appearance пресетов или slots.

`af_elementtheme_get_variables`: built-in palette → global metadata variables → surface variables. Canonical keys берутся через registry/`af_elementtheme_resolve_key` (в том числе legacy alias dark→shadow); Appearance не меняет эти ключи и `data-element`. `af_elementtheme_resolve_surface_key(uid,surface,key,tid)` разрешает profile/postbit/sheet только при active application через `af_characterworkflow_resolve_active_application` и `af_apui_is_approved_character_application`. Для application существует отдельное разрешение canonical key: не превращать его в обход approval для postbit/profile/sheet.

Canvas owns effect hosts и runtime. Reuse: `af_elementtheme_normalize_effects`, `af_elementtheme_mark_surface`, `af_elementtheme_preferences`, `afElementEffects.refresh`, событие `af-element-effects-refresh`. Preferences `effects_enabled` и `effects_profile/sheet/application/postbit` принадлежат зрителю; engine учитывает reduced motion. Preset автора не может включить анимацию вопреки зрителю. Допуск эффекта = approval surface gate ∧ административно разрешённый эффект/поверхность ∧ viewer permission ∧ motion policy. Новый Canvas engine или произвольный пользовательский shader не нужен.

## 3. Целевая модель и минимальная схема

Все изменения в этом разделе — предложения для будущего этапа. На этапе 1 DDL не выполняется.

### 3.1. Preset и аудитория

Обычный пользователь создаёт личный preset в пределах slots, редактирует только свой и применяет к собственному персонажу/поверхности/сообщению. Администратор сохраняет редактор магазинного каталога и отдельную аудируемую возможность поддержки. Гость может видеть уже разрешённое публичное оформление, но не создавать и не применять. Переключение аккаунтов не даёт права на произвольный переданный UID; owner определяется серверной сессией и существующей проверенной связью, а не hidden input.

Один storage `af_aa_presets`. Минимальные новые поля: `owner_uid INT UNSIGNED NOT NULL DEFAULT 0`, `schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1`, индекс `(owner_uid,target_key,enabled)`. Старые записи остаются owner_uid=0 — системный/магазинный каталог; это явное исключение совместимости, а не присвоение старых товаров случайному пользователю. Все новые личные записи имеют owner_uid>0. Ownership покупки системного preset остаётся в Inventory; покупка не меняет владельца каталожной записи.

`schema_version=1` — прежний доверенный формат; `2` — строго типизированный пользовательский формат. Само поле owner_uid/schema_version не принимает значения из запроса. Существующий UNIQUE `(slug,target_key)` сохранить: для личных записей генерировать глобально уникальный slug с UID и случайным идентификатором; не использовать пользовательский title как identity.

Типы личного хранения: `profile`, `postbit`, `thread`, `sheet`, `fragment`, `post_mask`. Сопоставление первым пяти — существующие pack target keys; fragment уточняется fragment_key. Для mask добавить только новый target `apui_post_mask` в Appearance registry, разрешённый для personal storage и post assignment, запрещённый для старого магазина готовых appearance items и user assignment. Canonical element key к этому target не относится.

Version 2 stores sparse allowlisted `settings_json`: отсутствующее поле означает inherit; явное clear изображений означает `media_id=null`. Color — literal bounded format; размеры/opacity/позиции — bounded числа/enums; crop — нормализованные числа; overlay — серверный enum и значения, из которых компилятор строит CSS. Никаких `custom_css`, `*_css`, selector, HTML, JS, raw gradient, raw URL, var()/url() из запроса. Unknown keys и неверные типы отклоняются с field errors. Legacy sanitizer нельзя использовать как единственный validator V2, иначе defaults превратятся в непреднамеренные overrides.

### 3.2. Slot

Slot — постоянное право хранить N личных пресетов конкретного типа. Не Inventory qty, не Shop product slot, не число назначений и не количество сообщений. Assignment одного mask preset к ста PID не расходует сто slots. Базовые бесплатные лимиты — server policy по типу/группам; конкретные числа задаёт администрация, код не предполагает их наличие.

Одна новая таблица `af_aa_slot_grants`: `id`, `uid`, `preset_type`, `capacity`, `source_key VARCHAR(191)`, `is_enabled`, `created_at`, `updated_at`; UNIQUE `source_key`, индекс `(uid,preset_type,is_enabled)`. capacity — положительное целое. Для покупки source_key = стабильный `shop:<order_id>:<line_index>:<preset_type>`; для ручной выдачи — уникальный audit key. Это одновременно журнал постоянных прав и защита повторной выдачи той же строки, а не агрегат без происхождения.

Лимит = base capacity + сумма enabled grants. Все сохранённые личные записи считаются, в том числе disabled: toggle не освобождает слот. Удаление освобождает слот; уменьшение capacity не удаляет данные: существующие назначения сохраняются, создание/копирование запрещается до освобождения места. При create/copy/type change блокировать user row для owner UID в транзакции, затем повторно считать лимит и число записей; параллельные сохранения не должны превышать capacity. Изменение типа существующего используемого preset запрещается: создаётся новая запись.

### 3.3. Assignment и Post Mask

Повторно использовать `af_aa_assignments`, без новой таблицы масок или новых колонок posts. `user`/UID сохраняется для character account surfaces; `thread`/TID сохраняется для тем; `post`/PID + `target_key=apui_post_mask` — ровно одна активная маска PID благодаря существующему UNIQUE. На этом этапе отдельная несуществующая identity персонажа не вводится: текущие API разрешают персонажа по UID/active application. Поддержка нескольких identities под одним UID потребовала бы отдельного подтверждённого character contract.

Post Mask — ссылка на личный mask preset; это live reference, поэтому редактирование preset обновляет все назначенные ему сообщения. В UI последующего этапа это должно быть явно видно; для неизменности старого сообщения пользователь создаёт копию, которая занимает отдельный slot. Не дублировать settings snapshot в каждом assignment.

Writer и renderer проверяют: настоящий post существует; preset enabled и корректного типа; preset.owner_uid равен post.uid; actor имеет право управлять этим owner и редактировать post в MyBB с forum/thread/time/lock permissions. Модерация текста сама по себе не даёт владение личными пресетами. Администраторская поддержка — отдельное разрешение и audit. Ни assignment helper, ни renderer не доверяют posted UID/PID. Принятие анкеты для элементных поверхностей проверяется существующим gate при каждом разрешении.

Newthread/newreply/Quick Reply: выбор хранится как несохранённое состояние формы, валидируется до записи, assignment создаётся только после успешного получения настоящего PID. Ошибка/preview не создают assignment; при сбое после сохранения поста текст не теряется, маска остаётся unapplied с понятным результатом. Editpost/Quick Edit: отсутствие поля сохраняет прежнюю маску, явный clear удаляет assignment, set меняет ссылку; успешная запись маски привязана к успешному редактированию. Quick Edit должен передавать отдельное состояние, а не BBCode в `value`. Для drafts сохраняется выбор без назначения живому PID; при отправке повторная авторизация.

При удалении preset удаляются только его assignments, после чего поверхности наследуют базовое оформление; для legacy catalog не менять нынешнюю политику удаления в рамках migration. Удаление post очищает post assignment; move/split сохраняют PID-связь, clone не наследует автоматически. Merge/смена автора требуют повторной owner validation и снятия несовместимой маски. PM/preview с совпавшими числовыми ID не получают mask другого post; post resolver вызывается только в подтверждённом публичном post context.

### 3.4. Media references

V2 images в `settings_json`: именованная карта, например `media_refs.sidebar_avatar={media_id:123,fit:"cover",position_x:0.5,position_y:0.5}`; preview карточки — такая же reference с role `preview`. Appearance хранит только media ID и параметры отображения, без path/URL/копии файла/второй галереи. Дополнительная таблица references для первого варианта не нужна; JSON — источник ссылок, batch collector извлекает ID. Если позже понадобится эффективный reverse lookup, отдельный индекс ссылок можно проектировать как derived data, не второй медиакаталог.

Добавить в Gallery узкий серверный batch resolver (предлагаемый API, сейчас отсутствует): `resolve_appearance_media(ids, owner_uid, viewer_uid, public_use)` → descriptors по ID либо причина отказа. Он проверяет existence, approved, owner, direct-image MIME/provider, membership/visibility policy, ограничения размеров/формата; URL создаёт только Gallery. Appearance получает transient descriptor и компилирует экранированный CSS/image markup. Для первого публичного варианта разрешать только local raster images; remote images остаются legacy до формализации безопасного Gallery policy, embed_html не допускается.

После удаления/rejection/смены visibility reference не копируется и не раскрывает старый cached URL: при render повторная проверка, fallback к inherited image; caches учитывают media status/membership и viewer. Gallery остаётся владельцем delete/moderation/upload. Удаление файла не блокируется Appearance и не каскадирует удаление всего preset. Новый picker selection mode не принимает сам URL как доказательство права.

## 4. CSS overrides и render contract

Существующее AA разрешение: APUI defaults → profile/postbit pack → surface pack → fragment, затем CSS по `.af-aa-postbit-user-<uid>` и другим scopes. Thread settings разрешаются отдельно по TID. ElementTheme: default palette → global vars → surface vars. Legacy custom CSS добавляется как отдельные блоки; APUI plaque resolver ещё учитывает legacy theme pack, поэтому два resolver сейчас не полностью тождественны.

Целевой порядок для разрешённых свойств:

1. ATF base layout и APUI defaults.
2. Одобренная canonical ElementTheme palette: defaults → global → surface.
3. Существующее назначенное оформление поверхности (legacy shop ИЛИ personal preset в одном assignment, не два конкурирующих активных pack).
4. Более конкретные fragment assignments, сохраняя старый pack→fragment приоритет.
5. PID mask, только sparse разрешённые свойства локального postbit, выше UID-оформления.
6. Viewer accessibility/motion permissions и moderation visibility как обязательные ограничения результата, не продаваемый override.

Inheritance palette — default: не записывать скопированные element colors в personal preset. Допустимый персональный цвет задаёт локальный presentation token, не element_key и не новый доступ к эффекту. Mask не меняет страницу темы/body, профиль, canonical character name, пользовательские поля, подпись, сообщение, права или структуру postbit. Локальные avatar displays не изменяют данные аккаунта.

Нельзя реализовать этот порядок только порядком style tags: CSS specificity, !important и layers имеют отдельную семантику. ElementTheme уже объявляет `@layer af_elementtheme_surface_custom, af_elementtheme_global_custom`; unlayered rules и important cascade могут иметь другой приоритет. Новые V2 значения компилируются в единый итоговый per-post payload и ограниченные variables на существующем `#post_<pid>`/его узлах; UID rules не должны повторно перекрывать разрешённые PID значения. Точный consumer mapping — часть следующего backend/render этапа. Не менять существующие layer names или массово переписывать trusted legacy CSS ради нового порядка.

Legacy custom CSS сохраняется для уже существующих admin/catalog presets и API. Он может конфликтовать с V2 mask через !important/direct properties; это явное ограничение совместимости, требуется диагностика конкретного свойства, а не разрешение пользователю сырого CSS или глобальное !important. Нельзя обещать безусловное перекрытие произвольного старого CSS без изменения его поведения.

Reuse `af_aa_prime_runtime_cache` для UID назначения; добавить batch PID assignments/presets и batch Gallery references до рендера списка постов. Cache per-post payload включает PID, owner, preset revision и viewer/media/approval context; не сохраняет право доступа навсегда. Инвалидировать после save/apply/unapply/удаления и изменения медиа. Не допускать N+1 query на каждое изображение.

Полная страница и native AJAX fragment должны получить одинаковый effective payload. Page `pre_output_page` injection в head не гарантирован для Quick Reply/Quick Edit JSON/фрагментов. Компилировать разрешённые per-post значения на render boundary с безопасной доставкой через существующие атрибуты/содержимое ответа, сохраняя его формат и DOM. Preview использует этот же compiler, но не persistent assignment. После вставки/замены AJAX узла reinit idempotent и `af-element-effects-refresh`; не создавать дубли Canvas/toolbar/listeners.

## 5. Контракты интеграции следующих этапов

| Владелец | Минимальное расширение и неизменяемая граница |
| --- | --- |
| Appearance | В том же addon V2 CRUD/ownership/slot validation, typed compiler, PID resolver; новые сервисы с явным actor context. Старые signatures и legacy lookup shapes сохраняются. Catalog APIs выбирают owner_uid=0 по умолчанию; personal APIs требуют owner scope. Low-level get-by-ID не выставляется как публичный endpoint. |
| Gallery | Batch permission-aware resolver и ID selection callback mode; upload/moderation/album/delete остаются Gallery. Старый BBCode picker и default JSON array не ломаются. Отказ resolver означает fallback, не чтение paths напрямую в Appearance. |
| Shop | Сохранить `source_type=appearance` для готовых старых товаров. Добавить отдельный распознаваемый source type для постоянного slot entitlement; не пропускать его через текущую normalize_source_type, которая сводит неизвестное к kb. Capacity/type берутся из серверного товара и фиксируются в order snapshot. |
| Inventory | Сохранить купленные appearance items и apply/unapply. Slot grant не выдаёт расходуемый mask item и не вызывает дополнительный legacy grant. Existing user assignment и новый personal assignment проходят единый authorization service. |
| Editor | Lazy toolbar capability только управляет выбором/preview; POST/AJAX сохраняет сервер Appearance вместе с подтверждённым MyBB post context. Поддержать ready/destroyed и оба режима редактора; preserve native submit/Quick Edit transport. |
| ATF/APUI | ATF сохраняет template ownership/DOM; Appearance отдаёт validated presentation payload. APUI plaque/icon markup получает PID-aware значения. Lazy sheet/application/profile responses используют owner attributes и явно доставляют appearance variables/assets. |
| ElementTheme | Канонический key и approval resolver неизменны. Палитра наследуется; только разрешённые локальные presentation overrides. Canvas и viewer preferences остаются единственными управляющими анимациями. |

Для Shop slot покупки нужна также минимальная защита checkout: добавить nullable `checkout_key VARCHAR(64)` в `af_shop_orders` и UNIQUE `(uid,checkout_key)` (старые rows NULL). Один server-issued ключ относится к конкретной ревизии корзины; retry возвращает прежний order, новая покупка получает новый ключ. В транзакции сериализовать checkout по cart row, заново читать товары/баланс после lock, проверить key и выдавать slot grants с UNIQUE source_key. Это две разные защиты: повтор checkout не создаёт второй оплаченный order, повтор выдачи одного order не увеличивает capacity. Старые paid orders не проигрывать автоматически; items_json без entitlement marker не создаёт grants. История покупок остаётся в orders, происхождение capacity — в grants.

Новая grant ветка выполняется внутри общей checkout транзакции ровно один раз, без `af_inv_add_item` для masks/slots. До включения продажи удостовериться в транзакционности участвующих таблиц и Balance/Inventory contract узкой проверкой; полный аудит этих аддонов не требуется.

## 6. Совместимая миграция

1. Снимок schema/data и counts только затронутых таблиц. Versioned migration при install/upgrade, не DDL во время обычного browse; существующие `ensure_schema` calls в frontend сейчас есть, их наличие не повод проводить новую миграцию из user request.
2. Additive поля presets с defaults и индекс; существующие ID/slug/target/settings_json/preview_image без изменений. Создать grants, добавить nullable checkout key/index. Не менять posts, Gallery schema, canonical element registry или postbit templates.
3. Reader понимает V1/V2, пишет V2 только через защищённый новый сервис. Системные catalog selectors явно фильтруют owner_uid=0, чтобы личные presets не появились в fittingroom/shop/admin catalog без соответствующего scope. Не открывать прежний admin CRUD простым снятием admin gate.
4. Старые Inventory metadata и public APIs сохраняются. Обычные assignments остаются на месте. `af_aa_active` не удалять; существующая очистка при apply/unapply сохраняется. При обнаружении legacy rows сначала dry-run reconciliation с owner/item/preset checks; конфликт не заменяет валидный assignment. Не выполнять blind backfill или автоматическую выдачу slots по старой покупке preset.
5. Legacy URL не конвертировать массово в Gallery records: внешние URL не доказывают владение. V1 продолжает прежний render; добровольная копия в личный V2 preset проходит строгую валидацию, отбрасывает CSS и требует выбора разрешённого Gallery media. Никакой второй загрузки или auto-import.
6. Backend CRUD/quotas/resolver и integration adapters внедрять под отключённым feature gate. Затем отдельные этапы editor transport, render mapping и frontend мастерской. Default slot capacities/цены и включение продаж — отдельная настройка, не часть этапа 1.
7. Rollback: выключить V2 gate, восстановить legacy readers/writers без удаления новых данных. В откате decoder/queries не должны ошибочно воспринимать V2 как V1. Не удалять старые catalog presets, orders, Inventory items или Gallery files. Новые nullable поля/таблица могут оставаться до согласованной отдельной процедуры отката схемы.

## 7. Конкретные файлы и последовательность будущих работ

Пути относительно `inc/plugins/advancedfunctionality/addons/`, если не указано иначе. Новые имена модулей ниже — предложения, сейчас таких API нет.

| Этап | Файлы |
| --- | --- |
| Backend/model | `advancedappearance/advancedappearance.php`, `admin.php`, `manifest.php`; предложенные `advancedappearance/studio_contract.php`, `studio_service.php` для schema/version/typed validation/ownership/quotas; существующие storage функции остаются совместимыми. |
| Gallery boundary | `advancedgallery/advancedgallery.php`, `assets/advancedgallery.js` — resolver, public reuse policy, media ID callback; `assets/gallery.php` сохраняет alias role. |
| Shop entitlement | `advancedshop/advancedshop.php`, `admin.php`, `assets/shop_manage.php` и позднее `assets/advancedshop.js` — отдельный source type, checkout key, grant/history; `advancedinventory/advancedinventory.php` только appearance apply/unapply boundary при необходимости. |
| PID transport | `advancedappearance/advancedappearance.php` (post save hooks + предложенный `post_masks.php`), `advancededitor/shell.php`, `advancededitor.php`, `assets/advancededitor_shell.js` — новая capability/typed state adapter. Проверить реальный MyBB newthread/newreply/editpost/xmlhttp lifecycle 1.8.40 при реализации, не угадывать hook/JSON key. |
| Render integration | `adaptivethemeframework/adaptivethemeframework.php`, `assets/surfaces/postbit.css`, `advancedprofileui/advancedprofileui.php`, `modules/profile_providers.php`, `assets/advancedprofileui.js`, `advancedappearance/advancedappearance.php` и `assets/advancedappearance.modal-scope.js`. `adaptivethemeframework/templates/postbit_classic.html` и `ownership.php` — контракт для чтения, не цель структурных изменений. |
| Palette/effects | `advancedelementtheme/advancedelementtheme.php`, `css.php`, `effects.php`, `preferences.php`, `assets/element-effects.js`, `assets/element-canvas-engine.js` — использовать существующие gates/variables/lifecycle; изменения только при подтверждённой интеграционной необходимости. |
| Поздний frontend | `advancedappearance/assets/apstudio.php`, `assets/fittingroom.php`, `templates/advancedappearance_apstudio.html`, `templates/advancedappearance_fittingroom.html`, `assets/advancedappearance.js`, `assets/advancedappearance.css` — личная мастерская/preview/лимиты. На этапе 1 не изменяются. |

Будущие целевые проверки: migration counts/ID/API preservation; чужой UID/preset/PID/media отказ; quota race; concurrent/retry checkout и grant replay; deleted/rejected/private media fallback; две маски двух PID одного UID; newthread/newreply/Quick Reply/editpost/Quick Edit create/set/clear/omit/validation error; preview/PM без утечки PID; CSS consumer mapping; disabled animations/reduced motion; unapproved character; lazy sheet/application fragment scope. Это план проверок, а не отчёт о запущенных тестах.

## 8. Summary: решения и потенциальные конфликты

Решения: расширить существующий AdvancedAppearance; единая таблица presets с UID и versioned typed payload; постоянные slot grants с происхождением покупки; assignments повторно используются для user/thread/post; mask — live preset reference по PID без расходования; Gallery владеет media и проверяет reuse, Appearance хранит только media_id; ATF владеет структурой, ElementTheme — canonical palette/approval/Canvas и viewer permissions. Сохраняются legacy presets/items/orders/URLs/API; изменения схемы только additive и только на следующем этапе.

Конфликты, которые должны быть решены перед включением мастерской:

- Общий catalog без owner и public выборки: добавление personal rows без фильтра даст утечку личных presets.
- Admin CRUD helpers не проверяют ownership, assignment helpers не авторизуют actor; снятие page gate небезопасно.
- CSS sanitizer и raw-value legacy поля не дают безопасного пользовательского CSS; V2 требует отдельной строгой схемы.
- Gallery picker ориентирован на BBCode/URL, upload permission и owner selection, а не approved/public reuse; album permissions не защищают прямой storage URL.
- Shop transaction/cart cleanup не обеспечивают exactly-once checkout/grant при параллельном запросе; orders history не заменяет entitlement ledger.
- Два слоя active/assignments и snapshot Inventory metadata требуют явного legacy adapter, а не третьего источника активности.
- UID runtime cache/CSS и plaque markup не различают PID; head injection не покрывает автоматически AJAX fragments.
- Реестр fragments шире независимых render consumers, включая avatar frames; нельзя менять postbit DOM для устранения этого разрыва.
- Sparse V2 inheritance несовместимо с автоматическим разворачиванием V1 defaults; legacy !important/layers могут препятствовать ожидаемому override.
- APUI main использует lazy quick modals и отдельный sheet embed: старые assumptions о profile sheet tab и whole-page CSS неверны.
- Approval application surface имеет иной gate, чем postbit/profile/sheet; копирование key из application не даёт права оформить неутверждённого персонажа.
- Авторы не управляют viewer motion permission; GIF/remote/Canvas требуют policy, а avatar mask не должна менять users.avatar или APF secondary.

Этап 1 заканчивается этим документом. Frontend, DDL, продажи slots, PID persistence и переписывание плагинов здесь не реализуются.
