# AdvancedAppearance: маски сообщений (этап 5)

MyBB 1.8.40, PHP 8.5.0. Appearance 1.3.0. Контракты: [архитектура](ADVANCED_APPEARANCE_STUDIO_CONTRACT.md), [личные пресеты](ADVANCED_APPEARANCE_PERSONAL_PRESETS.md), [мастерская](ADVANCED_APPEARANCE_WORKSHOP.md), [Gallery Media API](ADVANCED_GALLERY_MEDIA_API.md).

## Архитектура и storage

Прямое требование этапа 5 отменяет первоначальную live-reference модель этапа 1: сообщение получает **snapshot**, а не живую ссылку на редактируемое определение. Используется существующая `af_aa_assignments`:

| Поле | Значение для маски |
| --- | --- |
| `entity_type`, `entity_id`, `target_key` | `post`, настоящий PID, `apui_post_mask` |
| `preset_id` | Source ID для информации; 0 = виртуальное системное оформление |
| `mask_owner_uid` | Настоящий автор сообщения; nullable для прежних assignments |
| `mask_snapshot_json` | Проверенный safe-settings JSON V2, nullable для прежних assignments |
| `mask_topbar_media_id` | Nullable Gallery ID верхнего аватара |
| `mask_sidebar_media_id` | Nullable Gallery ID бокового аватара |
| `is_enabled` | 1 для сохранённого сообщения, 0 для нативного MyBB draft |
| `updated_at` | Существующий timestamp |

Добавляются только четыре nullable колонки при install/activate/upgrade. Existing UNIQUE `(entity_type,entity_id,target_key)` обеспечивает одну маску PID и идемпотентный upsert; таблица остаётся InnoDB по миграции этапа 3. Новых таблиц, колонок posts/users, appearance addon или медиа-хранилища нет. Маски не считаются личными пресетами и не занимают слоты. Покупки/предметы/Shop не меняются.

Выбираются виртуальный системный стиль и **свои опубликованные пользовательские postbit-пресеты**. Старые магазинные V1 definitions с URL/raw CSS продолжают обычную работу, но не импортируются в safe snapshot автоматически. Для их использования как источника маски нужен личный V2-пресет с выбранными Gallery IDs. Параметры собственного черновика не публикуются.

Изменение, отключение или удаление исходного V2-пресета не меняет snapshot. Personal delete сохраняет `post/apui_post_mask` assignments; остальные назначения удаляются как прежде. При открытии сохранённой маски выбирается «Сохранённый snapshot · прежний вид» (`preset_id=keep`), поэтому изменение только аватара не перечитывает изменённый исходник. Source ID может ссылаться на уже удалённое определение и не является внешним ключом.

## Запрос и успешная публикация

Отдельное hidden поле `af_aa_mask` содержит JSON операции; оно **не входит в message/BBCode**:

```json
{"mode":"set","preset_id":17,"topbar_avatar_media_id":123,"sidebar_avatar_media_id":456}
```

`preset_id=0` выбирает системный стиль; `preset_id="keep"` разрешён только для существующего собственного snapshot. `null` в поле аватара означает наследование из пресета/стандартного avatar provider. `{"mode":"clear"}` удаляет маску после успешного сохранения. Отсутствующее или пустое поле сохраняет прежнее опубликованное назначение. Клиент не может передать owner, PID внутри payload, snapshot, canonical key, CSS или другие параметры.

Verified MyBB hooks:

- `datahandler_post_validate_post`, `datahandler_post_validate_thread`: проверяют field/CSRF/actor/авторство/права/источник/медиа и запоминают подготовленные значения только в памяти текущего handler. Ошибка добавляется в стандартные ошибки PostDataHandler до записи.
- `datahandler_post_insert_post_end`, `datahandler_post_insert_thread_end`, `datahandler_post_update_end`: запись assignment только после успешного получения настоящего PID; источником ownership служит сохранённый post, а не submitted UID. Snapshot фиксируется валидацией этого submit.
- Нативный MyBB savedraft получает неактивные метаданные `is_enabled=0` после успешного сохранения самого draft. Они не отображаются в опубликованных постах. При публикации того же draft/PID выбор повторно авторизуется и включается даже при отсутствии нового поля. Сбой валидации/preview/отмена не вызывает post-end hook и не создаёт assignment.
- `datahandler_post_insert_merge`: автоматический MyBB merge сохраняет прежний PID и прежнюю маску. Новый выбор не заменяет оформление всего старого объединённого сообщения; это указано в модалке. Если native merge удалил промежуточный draft, его metadata очищается.

Если storage/retention неожиданно откажет уже после успешной записи native post, текст не удаляется: transaction маски откатывается, прежняя маска остаётся, событие журналируется; handler получает `af_aa_mask_warning`. Нельзя обещать атомарную транзакцию всей записи MyBB, поскольку его native flow не оборачивает запись posts вместе с внешними addon tables. Это ограничение не обходится изменением ядра.

## Editor UI и transport

Кнопка `af_post_mask` «Маска сообщения» с `fa-masks-theater` зарегистрирована через `af_ae_external_buttons` и `af_ae_external_capabilities`. Dialog assets загружаются по capability click; небольшой state/AJAX adapter подключается только к supported native routes. AdvancedEditor/SCEditor не переписываются и не дублируются. Используются `af:editor-ready`, `af:editor-destroyed` и исходная textarea shell; SOURCE/WYSIWYG используют одно hidden поле.

Диалог содержит source selector, два независимых аватара, Gallery picker/upload, безопасный небольшой preview на реальном ATF seed и Apply/Clear/Cancel. Apply валидирует выбор через server preview и меняет **только состояние формы**. Clear планирует удаление при следующем успешном сохранении текста. Cancel диалога или native Quick Edit сохраняет опубликованную маску. Preview имеет отдельный фиксированный CSS scope и собственный декоративный config; он не перекрашивает реальные посты автора на странице. Gallery остаётся доступен поверх dialog, в том числе при fullscreen editor.

Поддержаны:

| Editor | Передача |
| --- | --- |
| New Thread / New Reply / Edit Post | Штатный POST формы с hidden field |
| Quick Reply | Настоящий `Thread.quickReply()` / `#quick_reply_form.serialize()` |
| Quick Edit | Узкий `jQuery.ajaxPrefilter` для same-origin POST `xmlhttp.php?action=edit_post&do=update_post&pid=...`; добавляет отдельный field, сохраняет value/editreason и native response |

jEditable удаляет форму перед AJAX. Поэтому выбранное состояние удерживается по PID до отправки, а уничтожение/отмена editor очищают эту связь. При JSON-ошибке или network failure неотправленная маска доступна при повторном открытии Quick Edit до пяти минут; следующая явная отмена очищает этот reserve. Она не становится опубликованной автоматически.

Обычные формы сохраняют локальный резерв по UID + контексту thread/forum/PID; submitted field после ошибки имеет приоритет. Сервер заново проверяет восстановленные данные. Резерв очищается по подтверждённому native успеху/редиректу, а не при одном нажатии submit. MyBB server drafts и текстовые drafts Editor не заменяются.

## Права и Media

`af_aa_mask_require_edit()` требует enabled Appearance, авторизованный non-banned actor, совпадение с настоящим post UID, forum view/password/edit permissions, разрешённый thread/post status, учёт closed forum/thread, suspension и edit time limit. Moderator editing чужого текста не разрешает менять чужую персональную маску. Source preset принадлежит тому же UID, enabled/ready и target postbit; система неизменяемая и всегда виртуальная. CSRF учитывает boolean `verify_post_check(..., true)`, а не только исключение. Native MyBB дополнительно проверяет свои posting/edit rules до вызова save flow.

Gallery picker открывается с `purpose=appearance_avatars` для обоих аватаров. Он позволяет выбрать собственные доступные альбомы и загрузить новое изображение через **существующий** Gallery pipeline. Appearance хранит только ID; публичный URL сам по себе не доказывает ownership. Требуются owned approved local JPEG/PNG/GIF/WebP; pending/foreign/remote/SVG не публикуются как маска. Перед сохранением references закрепляются через `ag_retain_owned_media()`; reset/deletion не удаляют физических файлов.

`ag_preload_media_ids(array $ids)` — добавленный request-local read-only provider для batch URL resolution; `ag_resolve_media_url()` использует seed, сохраняя status/direct-image/access checks. Ownership writers по-прежнему читают `ag_get_owned_media()` напрямую. Это не HTTP endpoint, не persistent permission cache и не новый upload root. Файлы и thumbnails, квоты и приватность альбомов не меняются.

## ATF, CSS, Canvas и AJAX rendering

`af_aa_mask_preload()` делает один JOIN assignments/posts для всех PID страницы. Использует настоящий MyBB `$pids` (в том числе `pid IN('101','102',...)`), отрицательные cache entries и один Gallery batch для всех snapshot/avatar IDs. Renderer не перечитывает исходные presets. Для одиночного AJAX-post используется та же схема с одним PID.

Public provider `af_aa_get_post_appearance_context(pid,uid)` отдаёт typed owner/PID/source/snapshot/avatar references. ATF добавляет optional `appearance` в существующий post component context; ownership/template/DOM/grid/sticky не меняются. После ATF composition Appearance устанавливает per-post avatar payload и markup в **существующий** `post.before_body` slot. MyBB `useravatar` и `users.avatar` не меняются, равно как APF identity/system avatar.

Снимок использует общий safe compiler мастерской. В маске UID scope token заменён на `af-aa-postbit-mask-PID`, CSS ограничен `#post_PID`; активный живой UID-пресет не подмешивает новые поля в замороженный источник. Стандартные ATF/APUI/ElementTheme стили сохраняются. Unmasked post продолжает использовать обычный `af-aa-postbit-user-UID`. Trusted legacy admin CSS с глобальными selectors/`!important` остаётся прежним и может иметь прежние специфические конфликты; пользовательские маски не получают raw CSS для обхода этого ограничения.

Каждая маска выводит один scoped style и один inert JSON effect config в родном ATF slot, поэтому native Quick Reply fragment работает без ожидания whole-page pre_output. Quick Edit native response сохраняет исходную `atf-post__message-body` обёртку, которую jEditable заменяет. После native успеха adapter читает авторизованный runtime payload и обновляет только этот post: scope, стиль, avatar content и декоративный config. Native message/editedmsg/error contracts сохраняются.

Небольшое расширение `element-effects.js` читает config конкретного root из native slot перед UID override. Оно использует тот же Renderer/Canvas Engine, admin-enabled canonical postbit surface и действующие viewer preferences/reduced motion/sidebar settings. Никаких новых palette/Canvas engines, canonical element keys или обхода принятия анкеты нет. PM, announcement и editable preview hooks не получают маску по случайно совпавшему PID; provider ограничен публичными post routes.

## Lifecycle

Hard delete post очищает ровно соответствующий `post/apui_post_mask` assignment через `class_moderation_delete_post`. Hard delete thread запоминает его PID до удаления и очищает metadata в успешном end hook. Soft delete ничего не удаляет; restore/move/split с сохранением PID сохраняют snapshot. Смена автора делает старый owner snapshot неприменимым; новое собственное назначение повторно проверяет права. Native merge описан выше. Gallery retention остаётся консервативной политикой этапа 2.

## Проверки и установка

Проверено на изолированной MySQL 8.4 / PHP 8.5.0 и Chromium:

- `tests/appearance_post_masks_integration.php`: additive migration и прежние assignments, immutable snapshots/source deletion, разные аватары, set/clear/omit/cancel/success/draft hooks, чужие PID/media/CSRF/edit rights/time/closed status, soft/hard delete/owner transfer; 40 постов — один mask SELECT и один media batch.
- `tests/appearance_post_masks_browser.cjs`: #101 pink/#102 purple/#103 standard, snapshot после source deletion, настоящие MyBB `thread.js` + jEditable и реальный Editor compiler/lifecycle, Quick Edit load/cancel/save/reset/retry, Gallery purpose/IDs, SOURCE/WYSIWYG, Quick Reply, успешные POST всех трёх обычных форм и сохранение параметров после invalid native text, scoped CSS/grid, один Canvas, mobile dialog.

Forum DB/permissions/post-write boundary в fixture контролируемые; это не production MyBB installation. Native scripts взяты из неизменённого `mybb/mybb` tag `mybb_1840`. Gallery UI использует настоящий JS с минимальным HTTP picker fixture; собственный upload pipeline повторно не создаётся. Production-конфигурация, реальные данные и сервер сайта в этих проверках не менялись.

Для повторения: отдельная БД **`appearance_test`**, prefix **`aa_test_`**, `AA_TEST_DB_HOST`; стандартные assets MyBB 1.8.40 разместить в `work/mybb1840/jscripts` (jquery, jquery.plugins, jeditable, general, thread, SCEditor core/BBCode/themes/styles). Запустить integration, затем built-in PHP server `php -S 0.0.0.0:8100 -t . tests/appearance_post_masks_router.php` и browser script. Fixture endpoints нельзя устанавливать на production.

На форуме обновить файлы и выполнить обычный AF upgrade/activate AdvancedAppearance 1.3.0 для четырёх новых assignment fields. Старые данные не перезаписываются; без миграции кнопка не включается. Проверки unrelated suites не запускались. Основные файлы: `advancedappearance/post_masks.php`, `advancedappearance.php`, `personal.php`, `manifest.php`, `assets/post-masks.js`, `assets/post-mask-dialog.js`, `assets/post-masks.css`; узкие provider additions в ATF PHP, Gallery `media_api.php` и ElementTheme `element-effects.js`.
