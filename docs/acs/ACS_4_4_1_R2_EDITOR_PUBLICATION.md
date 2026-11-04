# ACS-4.4.1-R2 — публикация из существующего редактора

База: main `8c3e1d91936f74a247667055deadca73b08dda9a` (merge PR #1018). Реализованы ограниченный frontend полной формы/Quick Reply и единственный native entry binding к PublishService. `publication_enabled=false` сохранён в Builder/Seal; общего production включения нет. Карточка результата и GM UI (задача 6) не реализованы.

## Ограниченная тестовая конфигурация

Без трёх серверных constants интерфейс и публикационный API закрыты. Они предназначены только для отдельного staging с изолированной тестовой БД, через конфигурацию PHP/auto_prepend_file, **не** через правку ядра MyBB:

```php
define('AF_ACS_ENVIRONMENT', 'test');
define('AF_ACS_PUBLICATION_TEST_UIDS', [7]); // реальные тестовые UID
// Origin отдельной тестовой копии; он должен совпасть с bburl и HTTP_HOST.
define('AF_ACS_PUBLICATION_TEST_ORIGIN', 'https://acs-test.example.invalid');
```

Проверяются enabled ACS/AdvancedEditor, uid allowlist, origin/host и Origin header (если есть). Клиентские поля не дают тестовый доступ. Нужен secure browser context для существующего crypto.randomUUID. Контроллер не повышает combat grants: прежние grants=false writer продолжает отклонять. Для тестирования используются явно разрешённые fixture actors, а не новая ACP-команда или прямой production SQL. Публичная capability по-прежнему false; отдельный `test_publication_allowed` разрешает только навигацию тестовому пользователю.

## Один редактор и один протокол

1. Builder сохраняет прежние draft/actions/seal. После подтверждения сводки кнопки ведут в native newreply.php либо в существующий Quick Reply на showthread.php с `acs_draft`. Вторая форма/SCEditor не создаётся. Существующий BBCode draft в Builder остаётся подготовительным полем с data-af-ae-skip, а публикация использует единственный textarea[name=message] native формы.
2. Контекстный publication.js читает активный SCEditor instance либо существующий AdvancedEditor source adapter; не вызывает создание, init или destroy редактора. Уже введённый текст/цитата не заменяются молча. Явная кнопка загрузки draft требует согласия на замену. Quick Edit/full edit не получают config/hooks и не запускают Resolver.
3. Пользователь выбирает combat/narrative. Narrative оставляет обычный native submit и прежние options/побочные действия, без PublishService/механики/закрытия хода. Combat перехватывает submit и main submit click до native Quick Reply handler, используя тот же newreply endpoint, а не второй серверный writer.
4. Перед отправкой проза сохраняется **существующим** draft_save с прежними actions и стабильным operation_key. Prose-only save сохраняет confirmation. Затем prepare выдаёт/reuses server submission_id через PublishService.issue и замораживает текст/actions. Отображается подтверждённая серверная сводка с прежними бросками; отдельный checkbox подтверждает отправку текста с ней. Нет client plan/damage/tape/actor authority.
5. Финальный publish использует прежний ID и frozen prose; изменённый после freeze текст отклоняется без INSERT. Нормализация только CRLF/CR → LF для сравнения текста формы. Опции/косметическая маска проходят обычные native hooks, не входят в механические hashes. MODERATION_PENDING/RECOVERY_REQUIRED/REJECTED/processing/COMMITTED имеют явные сообщения; до receipt урон не показывается применённым. Approved pending использует прежний author approval path задачи 4.
6. При сетевой ошибке сохраняются local prose, pending save/prepare payload, key и известный submission_id в sessionStorage с uid/tid/draft scope; серверные prose/seals остаются в ACS. После неизвестного publish UI блокирует повтор до status lookup, не создаёт новый ID и не переключается молча в narrative. Один и тот же prepare после потери ответа возвращает прежний ID; после COMMITTED возвращается receipt. Новая подготовка после доказанного отказа — явный возврат в Builder, не автоматический reset.

Upload/attachment update/removal, preview, MyBB draft conversion/savedraft и moderator tools **не** являются combat submit. File inputs (включая выбранный пустой файл) блокируются до подготовки. Сервер также отвергает files и pending attachments по posthash, не игнорирует их. В native narrative mode прежние возможности MyBB сохраняются.

## Владение native request и отключение

Выбран global_end (global.php:1269), до входа newreply.php в do_newreply/attachment branches. Binding выполняет проверенный native prelude, затем PublishService/Gateway и **завершает запрос JSON**; original newreply INSERT не продолжается. Gateway единожды вызывает newreply_do_newreply_start/end и PostDataHandler. Timer tail global.php воспроизведён; поздние global_end callbacks не теряются молча: ACS должен быть последним handler, иначе запрос заблокирован.

Минимальный bridge в **плагине AdvancedFunctionality** регистрируется первым global_start (PHP_INT_MIN), независимо от enabled bootstrap ACS. Он не изменяет MyBB Core и блокирует устаревшую combat-форму при отключении ACS/AE или недоступном entry. Проверяется фактическая позиция первого callback; неподтверждённый hook order закрывает публикацию.

Wire action боевой формы — `acs_publication`, которое проверенный MyBB не вставляет как обычный do_newreply при отсутствии ACS. Только ранний guard после gates/source verification переводит его в native do_newreply в MyBB input/POST/REQUEST, **до остальных global_start callbacks**. Поэтому нормальные posting hooks видят штатный action. Narrative восстанавливает do_newreply. Не используется hook newreply_do_newreply_start для рекурсивного вызова Gateway. После отключения всего AF неизвестный wire action не становится native INSERT; frontend получает неподтверждённый ответ и сохраняет прежний ID.

Дополнительно к четырём прежним Gateway SHA проверяются файлы новой точки входа:

| Upstream mybb_1841 | SHA256 |
|---|---|
| global.php | `2d46b1ce6d68e241a0f006a4a2122ddf52a84c012295a5f93c284a4bdc5e795b` |
| inc/class_plugins.php | `fff8dc9fb8f96a034d2f5b4365b58ac1bc4bb079d5c31124ab2587e7ac19a7c3` |
| inc/functions_post.php | `6887dbdc3ea8a1584ecbaed39ab397601906968cdea0c41e27afc39fe3e58bb9` |
| inc/functions_search.php | `29c3a03aa9237b3a91c3f029fb1a97e7bcbb5bab2e2f98b044ff91c3e7b2bcda` |
| inc/functions_user.php | `fba8a7421e4e82d9d907c040bff4775a4a55a5f0f0d241acf72df70a6a3ba03f` |
| inc/functions_upload.php | `e46cffdc1f5cf633c89128e156c687d6572220522d889a8feff806ed03393549` |
| inc/class_parser.php | `f887d425dc5971944facd3f6a03e8e2dc3b62fd706ba94df8ef528984bf38c9e` |

Иной installed source не обходится тестовым флагом. Вложения/merge/conversion и прочие unsupported branches Gateway остаются закрыты. Нельзя обещать exactly-once MyBB INSERT при неизвестном outcome; POSTING остаётся защищённым журналом. Любые native side effects принадлежат MyBB и проходят вне ACS tx.

## Проверки

PHP **8.5.12**, disposable **MariaDB 11.8.8**, MyISAM fixture forum tables; Chromium + Playwright для DOM/transport fixtures. Без production SQL и без полного suite.

```
php tests/acs_editor_publication_regression.php SOCKET UPSTREAM_ROOT
php tests/acs_editor_publication_regression.php SOCKET UPSTREAM_ROOT --entry MARKER_JSON
php tests/acs_editor_entry_boundary.php UPSTREAM_ROOT native-disabled
php tests/acs_editor_entry_boundary.php UPSTREAM_ROOT late
php tests/acs_editor_entry_boundary.php UPSTREAM_ROOT source
php tests/acs_editor_entry_boundary.php UPSTREAM_ROOT narrative
php tests/acs_editor_entry_boundary.php UPSTREAM_ROOT edit
node tests/acs_editor_publication_browser.js
node tests/acs_builder_state.js
```

Сервер: compose/confirmed summary, existing prose save/freeze, prepare replay, CRLF, frozen-prose refusal, ordinary/Quick Reply, moderation/recovery, repeated HTTP/receipt, mask separation, immutable tape, files/pending attachments/savedraft, CSRF/author/host/allowlist gates, narrative passthrough. Owned-entry fixture использует реальный pluginSystem, verified prelude и PostDataHandler: один INSERT, один receipt, по одному start/end/insert hook; cleanup отдельной fixture БД в shutdown. Negative native-disabled исполняет реальный upstream newreply.php с ограниченным fixture global bootstrap и завершает запрос до native continuation. Late/unknown-source не публикуют.

Браузер: две native формы, existing WYSIWYG adapter, сохранение/prepare response loss с прежним payload, double click, publish response loss → status/reload, narrative submit, preview/draft/file refusal, один textarea/editor/form и отсутствие edit wiring. Fetch и editor adapter в browser fixture ограниченные mocks, не установленный AdvancedEditor/тема. php -l изменённых PHP, node --check и git diff --check пройдены.

**Installed acceptance BLOCKED:** полный global bootstrap, реальные hooks приоритеты/side effects активных аддонов, actual AdvancedEditor modes/mobile shell/theme, full Quick Reply renderer и целевой **MySQL 8.0.46** не проверены. Combat Quick Reply сообщает status/link; не имитирует native postbit HTML renderer. Администратор должен проверить это на изолированном staging, включая отключение ACS/AF при открытой форме, чужие hooks, edit/masks, lost response и native narrative attachments. Локальные fixtures этого не заменяют. Unknown-pid/legacy identity blockers, outbox consumers и production enablement остаются прежними. Автоматического перехода к задаче 6 нет.
