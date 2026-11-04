# ACS-4.1.1 — аудит публикации MyBB и транзакционной границы

## 1. Итог и границы доказательства

**IC-MYBB-1: BLOCKED. IC-EDITOR-1: PROPOSED, не подключён.** Аудит репозитория выполнен; безопасность атомарной публикации на установленном warprift.ru не доказана. Gateway, combat publication, seal, миграции и новые hooks в этом PR не создаются. Обычная публикация MyBB не изменяется. Это технический отчёт, не изменение Design Baseline и не приёмка этапа 4.

Исходная `main`: **`66efb3796a9edb04f899f3875cffd1b906203e7a`**, получена `git fetch origin main`. Основание: [Master](../design/acs/ACS_MASTER_SPECIFICATION.md) A1.13–A1.16, A1.49–A1.52, A1.60, A1.89–A1.95; [Architecture](../design/acs/ACS_TECHNICAL_ARCHITECTURE.md) §3, §5, §7–8; [Contracts](../contracts/ACS_INTEGRATION_CONTRACTS.md) IC-CORE, IC-MYBB-1, IC-EDITOR-1; [Roadmap](../design/acs/ACS_IMPLEMENTATION_ROADMAP.md) этап 4.1. Утверждённые четыре документа прочитаны, их нормативный текст не меняется.

| Источник | Что фактически доступно | Что это доказывает |
|---|---|---|
| Репозиторий AF, SHA выше | Тела функций, регистрация hooks, существующий ACS TransactionManager | Статические пути и конкретные опасные участки данной ревизии |
| Установленный MyBB warprift.ru | **Недоступен**: нет установленного ядра, конфигурации соединений/кэша, списка активных plugins/hooks и доступа к БД | Ни engines, ни runtime-порядок handlers, ни соответствие ядра upstream не подтверждены |
| Официальный `mybb/mybb`, tag `mybb_1840` | Commit **`bd2a3447939d3084a5926dd66ece04649e0e0d60`**, `class_core.php`: version 1.8.40 / 1840 | Эталонный путь этой версии; **не подмена аудита установленной копии** |
| Изолированная MariaDB 10.11.19 | Локальный одноразовый контейнер без сети; только две probe-таблицы | Семантика rollback InnoDB/MyISAM и implicit commit вложенного START; не интеграционная проверка PostDataHandler |

Нет готовых credentials/VPN к форуму. Ядро отсутствует в рабочем репозитории: `inc/datahandlers/post.php`, `newreply.php`, `inc/class_core.php`, `inc/db_mysqli.php` не входят в AF. Эталон скачан отдельно во временную директорию, в PR не включён. Production-запросы и production rollback не выполнялись.

Хеши SHA-256 **эталонных**, не production-файлов:

| Файл MyBB | SHA-256 |
|---|---|
| `inc/datahandlers/post.php` | `9d7a5aee7cf100bff0cbd918934b16524f3d556e94ced4719bef97622c6a5d13` |
| `newreply.php` | `2c4bf98727f0a85142b8f988ef94a830053db1fa8014774f67eee71f8c040035` |
| `inc/db_mysqli.php` | `99f7077ef3dd5c2032e9a690dfd50f4b707147d7ec43621ed5bc875cb8edde4a` |
| `inc/class_core.php` | `8b7228b6918becbdea1d3d51cd8081604e988e72f898308e68998603491c06fc` |

## 2. Эталонный reply pipeline 1.8.40

Ссылки на строки ниже закреплены на [официальном commit](https://github.com/mybb/mybb/tree/bd2a3447939d3084a5926dd66ece04649e0e0d60). Последовательность относится к обычному ответу в существующую тему, не к созданию темы, native draft или merge.

1. [`newreply.php`](https://github.com/mybb/mybb/blob/bd2a3447939d3084a5926dd66ece04649e0e0d60/newreply.php): загрузка темы/форума, форумные права, видимость, закрытие темы, ограничения пользователя и пароль форума проверяются **в entry script**, не целиком внутри DataHandler. Upload/remove attachments выполняются в районе 194–278 ещё до insert: CSRF есть, но файловые изменения не откатываются SQL.
2. В POST `do_newreply`: CSRF (311), `newreply_do_newreply_start` (313), guest/captcha/StopForumSpam и дополнительные проверки. StopForumSpam может делать сетевой запрос. Gateway не может считать один вызов `validate_post()` заменой этих проверок; повторно исполнять весь entry script внутри transaction также небезопасно.
3. `new PostDataHandler('insert')`, `set_data($post)`, `validate_post()` (388–448). [`validate_post()`](https://github.com/mybb/mybb/blob/bd2a3447939d3084a5926dd66ece04649e0e0d60/inc/datahandlers/post.php#L798) проверяет flooding для нового не-draft, автора, subject/message, image/video limits, dateline, replyto, icon/options, затем `datahandler_post_validate_post` (878). `false`/friendly errors блокируют insert. `set_validated(true)` сам по себе не доказательство успешной проверки: handler отдельно проверяет errors.
4. Entry script дополнительно обрабатывает CAPTCHA и ошибки; `mark_thread_read()` вызывается до insert (459). Это ещё одна причина не считать всё до `insert_post()` чистой валидацией. `insert_post()` вызывается только при отсутствии ошибок (519).
5. [`insert_post()`](https://github.com/mybb/mybb/blob/bd2a3447939d3084a5926dd66ece04649e0e0d60/inc/datahandlers/post.php#L897) требует validated/no errors. **До PID** изменяет авторскую подписку (`threadsubscriptions`, 927+), может выполнить modoptions/log, определяет `visible` по forum permissions и `moderateposts` (1004+).
6. `verify_post_merge()` (1040) может объединить с предыдущим постом: изменяет старый message, использует **старый PID**, вызывает `datahandler_post_insert_merge` и возвращается рано (1093). Это несовместимо с предположением «одна submission создаёт новый post». Native draft conversion также обновляет существующий PID (1137–1140).
7. Для обычного visible reply обновляются `users.lastpost/postnum` (1102+), затем формируется `post_insert_data`, вызывается **pre-insert** `datahandler_post_insert_post` (1161). Имя hook не означает, что пост уже создан. **Новый PID присваивается только** `$this->pid = $db->insert_query('posts', ...)` (1163).
8. Далее назначаются attachments по posthash (1166+), обрабатываются threadsubscriptions: email ставится в `mailqueue`, PM идёт через `send_pm()` → PMDataHandler, запускаются parser/PM hooks, `datahandler_post_insert_subscribed` (1324), обновляется mailqueue cache. Прямого SMTP в этой ветке PostDataHandler не найдено; это **не** доказательство отсутствия I/O во вложенных hooks/кэше или mail worker.
9. Обновляются lastpost, forum/thread counters, attachmentcount (1333–1372); `return_values` получает pid/visible/closed; вызывается **`datahandler_post_insert_post_end` (1381)**; только затем `insert_post()` возвращается.
10. `newreply.php` продолжает invalidate captcha/cookies, `newreply_do_newreply_end` (601), AJAX postbit/render либо redirect. Shutdown work может выполняться позже. Успех insert-end ещё не равен общему commit MyBB+ACS.

### Вложенные hooks, edit и небоевая публикация

Помимо четырёх DataHandler hooks выше учитывать `newreply_start/end`, parser hooks, PM validation/insert/commit/end hooks, moderation/log hooks и shutdown callbacks. `datahandler_pm_insert_commit` — название callback после вставки PM, не доказательство SQL COMMIT. Полный runtime-граф зависит от загруженных плагинов и branch-specific настроек.

Эталонный `update_post()` использует `datahandler_post_update` перед изменениями и `datahandler_post_update_end` после. Полный edit идёт через `editpost.php`; Quick Edit — через `xmlhttp.php?action=edit_post&do=update_post`. Для ACS это будущий **prose-only** путь: нельзя исполнять Resolver, менять tape/event history или закрывать turn. Карточка должна рендериться по PID/post_link отдельно от редактируемого message. Удаление/soft-delete/unapprove/restore/merge уже опубликованного combat post требуют отдельного согласованного lifecycle и аудита; молча удалять механическую историю нельзя.

## 3. AF handlers на проверенной main

Все пути в таблице относительно `inc/plugins/advancedfunctionality/addons/`. Это **обнаруженные кандидаты**, не утверждение, что каждый включён на warprift.ru. Статический поиск выполнен по всем `inc/plugins`, включая регистрации через foreach. Настоящий список callbacks с priorities и исходными файлами следует снять после bootstrap в изолированной копии установленного форума, отдельно для newreply и Quick Edit.

| Компонент / файл и функция | Вызов / побочное действие | Вывод для coordinator |
|---|---|---|
| AF Core `inc/plugins/advancedfunctionality.php:2322`, `advancedfunctionality_bootstrap_addons()` | На global_start перебирает discovery, settings enabled, bootstrap/init; есть языковая синхронизация и identity migration paths | Запускать стандартный bootstrap **до** combat transaction; manifest не перечисляет все post side effects. Core не предоставляет готовый atomic post gateway |
| `advancedpostcounter/advancedpostcounter.php:1209`, `af_advancedpostcounter_increment_post()`; регистрация 1624 | **Pre-PID** hook; `af_advancedpostcounter_update_user_count()` (724) делает SQL `users.<AF_APC_COL> = GREATEST(...)` | Участвует в общей tx только при InnoDB users/том же writer. После отдельного автокоммитного insert нельзя «откатить ACS и оставить счётчик». Нет submission receipt |
| Тот же APC, `af_advancedpostcounter_schedule_rebuild()` (744) | moderation hooks регистрируют shutdown rebuild | Shutdown не считается выполненным внутри общей tx; нужна отдельная политика invalidation/rebuild после outcome |
| `balance/balance.php:988`, `af_balance_datahandler_post_insert()` | insert-post/thread-end: читает созданный posts, вызывает `af_balance_recalc_post_awards()` (1460) | Читает через MyBB wrapper, требует read-your-writes; участвуют `af_balance`, `af_balance_tx`, `af_post_rewards`. Не считать эту логику безопасным ACS reward API |
| Balance `af_balance_apply_scaled_delta()` (833), `af_balance_datahandler_post_update()` (1049) | read→absolute UPDATE balance, tx ledger/prune; EXP callback CS; edit и Quick Edit пересчитывают дельты по тексту | Нет совместимого row-lock/receipt протокола для конкурентных writers. Hash prose в af_post_rewards предотвращает часть повторных recalc, но не дедуплицирует два новых PID. Изменение прозы может менять **форумные** награды, но не боевые события |
| `charactersheets/modules/experience.php:251`, `af_charactersheets_on_exp_changed()` → `modules/sheets_crud.php:355` | Обновляет level/EXP в progress и записывает base/build/progress_json целиком в `af_cs_sheets` | Это реальная запись CS от публикации, даже если ACS не вызывает CS напрямую. Нужны engine/connection, свежесть cached sheet, lock order и аудит lost updates. Read-only snapshot API это не устраняет |
| `advancedalertsandmentions/advancedalertsandmentions.php:2205`, `af_aam_post_insert_end()` | Упоминания/цитаты/@all/автор/подписки → `af_aam_add_alert()` (1999), inserts `aam_alerts`, autoclean/delete/max-limit | Нет durable operation receipt. SQL может откатиться при доказанных engines/connection; autoclean cache `af_aam_last_autoclean` не гарантированно откатится. Для ACS уведомлений нужен post-commit идемпотентный outbox adapter |
| `advancedappearance/post_masks.php:129/150/113` | validate → pending mask; insert-end/update-end → `af_aa_mask_commit()` → `af_aa_personal_transaction()` | **Конкретный atomicity blocker**, см. ниже. Условен: нужен pending mask; это не означает implicit commit при каждом ответе |
| `advancedgallery/media_api.php:148`, `ag_retain_owned_media()` | В маске помечает `af_gallery_media.appearance_retained=1` | Ещё одна SQL-таблица в write set; необходимо учитывать ownership, engine и порядок блокировок. Эта функция сама START/COMMIT не вызывает |
| `advancedthreadfields/advancedthreadfields.php:6003`, `af_atf_dh_validate()` | Для нового reply с tid сразу return; для edit только first post; insert-thread/update hooks сохраняют поля/KB-link | Обычный reply не должен синхронизировать анкету. Creation thread/first-post edit — другой branch, нельзя включать их в gateway без аудита цепочки KB/CS |
| `smarturltitles/smarturltitles.php:176`, `af_sut_insert_post()` | newreply/editpost start, меняет message; `af_sut_fetch_title()` (727) использует curl_exec (814) | Сетевое преобразование должно завершиться **до** tx и payload/confirmation hash, с зафиксированным итоговым prose. Нельзя держать locks при fetch; перенос entry hook внутрь tx небезопасен |
| `advancedwanted/advancedwanted.php`, `af_wanted_thread_created()` | insert-thread; newreply_start prefill; init также нормализует expired reservations | Не обычный reply DataHandler hook; bootstrap и page-start нельзя слепо повторять под tx |
| `characterworkflow/characterworkflow.php:756`, `af_cwf_create_acceptance_greeting_post()` | Отдельный producer штатного PostDataHandler при приёмке анкеты; moderation/delete paths | Не является автоматическим обработчиком обычного reply. Должен остаться narrative, не стать повторным combat submit |

`post_do_newpost_end` у Balance и `datahandler_post_validate` у ATF — compatibility registrations: наличие регистрации не доказывает вызов в эталонном reply path. `datahandler_post_insert_thread[_post/_end]` относятся к новой теме; таблица не приписывает их обычному ответу.

### Доказанный опасный обработчик: AdvancedAppearance

[`personal.php:160–178`](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/66efb3796a9edb04f899f3875cffd1b906203e7a/inc/plugins/advancedfunctionality/addons/advancedappearance/personal.php#L160), `af_aa_personal_transaction()` берёт owner GET_LOCK, затем **без проверки ambient transaction** выполняет `START TRANSACTION`, `$work()`, `COMMIT`, а при ошибке `ROLLBACK`. MySQL/MariaDB START TRANSACTION не создаёт nested tx: уже сделанные записи внешней транзакции фиксируются. Таким образом активная маска может зафиксировать posts/ACS до конечной проверки ACS. `post_masks.php:150` ещё и ловит Throwable, возвращая warning вместо ошибки всей публикации. Это не безопасная publish boundary.

Минимальное требуемое отдельное исправление: явный owner-owned writer, умеющий участвовать в **переданном доверенном unit-of-work** на том же connection, без собственного BEGIN/COMMIT/ROLLBACK; сохранить обычный standalone путь и проверить порядок owner locks. Ошибки обязательных записей должны выходить координатору; cache invalidation — после commit/rollback. Простое удаление START, игнорирование ошибки или глобальное отключение hook недопустимы. До такого scoped API маска блокирует combat publication; наличие собственного GET_LOCK не исправляет atomicity.

## 4. AdvancedEditor / IC-EDITOR-1

Проверены `advancededitor/advancededitor.php`, `shell.php`, `assets/advancededitor.js`. PHP регистрирует BBCode parse hooks и `misc_start` preview (`af_advancededitor_misc_start()`, 2180); `shell.php` компилирует метаданные интерфейса, не владеет PostDataHandler. JS `bindEditpostFormGuards()`/submit handlers (около 1799–1878) синхронизируют единственный SCEditor с textarea и блокируют неготовую/пустую форму. Обычный редакторский HTML-preview не является combat preview или publish receipt.

Точки расширения будущего adapter: штатная форма и её submit после синхронизации message; server-side маршрут, принимающий `acs_mode`, draft_id/version, submission_id, confirmation_hash; отдельный response/lookup protocol. Поля и ACS editor callback **не реализованы этим аудитом**. Нельзя заменять штатный handler кнопкой, которая сначала отправляет narrative reply, а затем применяет ACS. Нельзя считать client-side disable/double-click guard серверной идемпотентностью. Quick Reply/AJAX, полная форма, native draft и Quick Edit должны быть проверены отдельно на фактически установленном frontend/runtime. Второй SCEditor не нужен.

## 5. Соединения, engines, ошибки и необратимые эффекты

### Transaction API

Эталонный `inc/class_core.php` — MyBB request/config core, не transaction manager. В `DB_MySQLi` нет общих `begin_transaction()/commit()/rollback()` методов wrapper. Можно использовать нативный `$db->write_link` (mysqli) или доказанный write_query protocol. `DB_MySQLi::query()` (325–365) маршрутизирует запросы по write_query/last_query_type на read_link или write_link. При простой конфигурации links алиасны (`connect()`, 254+), но split read/write поддерживается. Чередующиеся simple_select могут попасть на replica/другое соединение и не увидеть незакоммиченный PID/баланс.

У ACS уже есть `modules/Infrastructure/TransactionManager.php`: native writer, begin/commit/rollback, advisory lock, guard autocommit/SET TRANSACTION, запрет ambient transaction; это **не** готовый joinable UoW для PostDataHandler. Coordinator должен владеть внешней tx; вложенный вызов существующего `run()` не является способом присоединения. Нужны доказательство единственного writer для **всех** зависимых чтений/записей и строгая обработка SQL failures. MyBB query error path может вызвать error renderer/exit вместо исключения; нельзя рассчитывать только на catch Throwable. При обрыве соединения состояние COMMIT может быть неизвестно.

### Engines / write set

Официальный `install/resources/mysql_db_tables.php` содержит `ENGINE=MyISAM`, включая стандартные таблицы публикации. Это **не утверждение engines существующего форума**. DDL аддона с InnoDB также не доказывает engine давно установленной таблицы.

Минимальный условный write set (имена **без TABLE_PREFIX**, на установке применять настоящий prefix):

- Core: `posts`, `users`, `threads`, `forums`, `threadsubscriptions`, `attachments`, `mailqueue`, `privatemessages`, `datacache`; `moderatorlog` при modoptions; индикаторы чтения (`threadsread`/`forumsread`) и captcha — если исполняется соответствующий entry branch.
- AF: `af_balance`, `af_balance_tx`, `af_post_rewards`, `af_cs_sheets`, `aam_alerts`; `af_aa_assignments`, `af_gallery_media` при маске. Дополнительные owner tables зависят от реально вызванных hooks; `aam_alert_types`/Appearance catalog/settings обычно читаются, но lazy setup/repair запрещены в tx.
- ACS: текущие session/encounter/round/turn/entity/resource/operation/event/snapshot projections и **проектные, пока не установленные для публикации** drafts/actions/rolls/submissions/post_links/outbox из Architecture §5. Нельзя создавать их в рамках аудита.

Для каждой фактически затронутой таблицы нужны engine/readback, triggers, collation и состояние соединения; внешние table triggers/functions тоже входят в аудит. MyISAM, другой connection/schema без доказанного протокола, DDL/implicit commit или неизвестный handler → BLOCKED.

### Side effects вне SQL rollback

- `class_datacache::update()` (254) меняет request cache, `datacache` и внешний CacheHandler `put()`; `update_mailqueue()` (976) вызывает этот путь до возврата PostDataHandler. Redis/Memcached/files cache не откатываются SQL. Нельзя считать cache безвредным: worker может увидеть несуществующую очередь/состояние.
- `functions.php::update_forum_counters()` → `update_stats()` (2833) накапливает static delta и регистрирует shutdown callback. После rollback он не исчезает сам; `run_shutdown()` может применить дельту уже вне tx. Сброс одного `$shutdown_functions` недостаточен для скрытого static state; нужен изолированный/proven publish scope или проверенное outcome-aware решение без правки ядра.
- Письма в upstream reply ставятся в SQL queue, PM также вызывает другие handlers. Отправленное письмо/HTTP push не компенсируется удалением SQL-строки. Worker не должен читать незакоммиченные queue rows; внешняя доставка допустима после commit и durable receipt.
- Attachment uploads/removals меняют файловую систему до insert. Cookies/headers, SmartURLTitles HTTP, внешние alerts/webhooks неизвестных plugins, file/debug logs (`balance::af_balance_debug_log()`, 1268) не откатываются. Debug log может быть допустимым техническим следом, но не подтверждением commit; он не должен раскрывать приватное prose/definition.
- Request caches (thread/sheet/appearance/award dedup) после rollback могут оставаться stale. Повтор внутри того же PHP request не разрешён без доказанного сброса; recovery lookup должен читать authoritative writer.

## 6. Кандидат IC-MYBB-1 — PROPOSED, не исполнять до снятия gates

### Preflight и условия отказа

Разрешать только авторизованный POST/CSRF для доступных session/encounter/turn, контролируемых существ и подтверждённого pinned плана. Preview не регистрирует публикацию. Нарратив остаётся обычным MyBB-постом и никогда не изменяет ACS.

Combat baseline scope кандидата: новый visible reply в visible существующую тему. До отдельных доказанных adapters **отказать** при queued moderation/moderateposts, native savedraft/PID conversion, возможном auto-merge, uploads/removals/posthash binding, modoptions, неизвестных post/parser/PM hooks или опасных masks. Не обходить штатную модерацию повышением visible=1; если `insert_post()` возвращает visible≠1/merge=true/не новый PID — rollback и dependency failure, не resolved. Это ограничения шлюза, не изменение правил обычного форума. Отсутствие подписок не снимает shutdown stats blocker.

Прочие обязательные gates: установленное ядро и callbacks проверены; все write-set tables InnoDB; один доказанный DB writer/read-your-writes; нет внутренних commits/DDL/необратимого I/O; уведомления/cache/shutdown защищены outcome protocol; migrations заранее завершены; Inventory costs запрещены без IC-INV. Engine/schema inspection выполняется read-only заранее, не ALTER на публикации.

### Возможная граница и lock order

1. После разрешений/CSRF подготовить **один** immutable request hash: actor, draft/version, ordered actions/tape, prose, confirmation/dependency versions, topic. Идемпотентно сохранить registered submission отдельной короткой транзакцией; повтор того же key с другим hash — conflict. Это будущая таблица, не операция этого PR.
2. Штатные entry permissions, flood/spam/message validation и все разрешённые preflight transformations выполнить до общей tx/необратимых commit effects. Read-indicators/uploads/network не включать слепо в повторную validation. Под locks перепроверять изменяемые права/лимиты/состояние; предварительная валидация не заменяет recheck. Validation hooks тоже должны быть аудированы.
3. Coordinator начинает **одну** tx на native MyBB writer **до первого write insert_post()**, а не в pre-insert/insert-end hook. Нельзя разрешать PostDataHandler автоматически повторять stateful validation/merge вне проверенного контекста.
4. Порядок Architecture §7.1: session → encounter → round → turns по id → entities/states/resources по id → effects/shared pools по id → draft/sealed/submission → owner Inventory/balance rows по `(system,item/uid,id)`. Затем согласованные MyBB thread/forum/users/subscription rows и Appearance owner locks. Это **предложенное расширение**, не доказанная совместимость: обычные MyBB/Balance writers сейчас не соблюдают такую дисциплину, поэтому необходимы scoped adapters/конкурентная проверка, иначе BLOCKED. ACS global advisory lock не сериализует обычные ответы MyBB.
5. Под locks: actual dependencies, scope/control/pending turn, quota, confirmation, прежняя tape → единый validated plan. Затем **штатный** PostDataHandler создаёт один prose post. PID брать из handler/return_values и сверять на writer: uid/tid/visible/newness. Никогда `MAX(pid)`, последний пост автора/темы или поиск по похожему message.
6. До commit в той же tx: ACS costs/projections, immutable result/events, post_link, turn resolved, draft/submission published, durable outbox. В `post_links`: PK pid, UNIQUE submission_id, UNIQUE turn_id; в submissions: PK submission_id, UNIQUE pid, payload_hash. Эти ограничения уже спроектированы Architecture §5; не добавлять ACS-маркеры в posts.message и не менять ядро/таблицу posts без необходимости.
7. Только после успешного **общего COMMIT** вернуть PID/receipt и выполнить разрешённые post-commit cache/notification tasks с recovery. Если transaction outcome неизвестен, не посылать подтверждение и не создавать второй пост.

### Rollback, retries и response loss

Доказанный rollback должен оставить ноль нового posts/post_links/events/deltas, turn pending, первоначальные HP/AP/barriers, неизменённые immutable snapshots. Зарегистрированный submission, draft/prose и sealed tape/quota сохраняются в соответствии с A1.14–A1.16/A1.60. Auto-increment gap допустим и не означает существование поста.

Ошибка ACS после insert безопасна **только** при полном rollback всех участвующих SQL и outcome-aware обработке внешних effects. Текущий late hook этого не обеспечивает. Если вложенная mask tx уже сделала implicit commit или таблица MyISAM, удалять post как «компенсацию» нельзя: награды/подписки/внешняя доставка могли уже произойти. Это recovery_required, запрет blind retry, диагностика и reconciliation.

На повтор/потерю HTTP response: authoritative submission_status с авторизацией → published возвращает прежний PID; тот же key/hash не создаёт повторный post; чужой key не раскрывает существование записи. Registered/committing требует lease/ownership check и доказанного outcome. Unknown COMMIT → новое соединение/authoritative lookup/reconciliation, не новый RNG/key. Flood/posthash/auto-merge не являются заменой submission idempotency.

## 7. Что требуется отдельно перед реализацией gateway

| Gate / adapter | Минимальное действие | Текущее состояние |
|---|---|---|
| Установленный core | Получить безопасную копию файлов и hashes, проверить diff upstream, DB driver/config без паролей | BLOCKED: доступа нет |
| Runtime hooks | Снять enabled AF + сторонние plugins, полный список callbacks/priority/file по entry paths и вложенным PM/parser/shutdown | BLOCKED: статический candidate inventory не runtime snapshot |
| Engines/connection | Read-only metadata, triggers и сравнение read_link/write_link; исключить replica reads в tx | BLOCKED: production БД недоступна |
| Appearance UoW | Joinable owner writer без nested commit, единый failure protocol, locks/cache lifecycle | BLOCKED: конкретный код найден |
| MyBB cache/stats/subscriptions | Outcome-aware scope или доказанное безопасное выполнение с post-commit durable dispatch; проверить worker/cache backend | BLOCKED: upstream меняет cache/static shutdown state |
| Balance/CS | Согласованный owner row-lock protocol, read-your-writes, atomic rewards+receipt, stale sheet защита | BLOCKED: нет доказанной координации |
| AAM | Post-commit идемпотентный consumer, receipt, безопасный autoclean/cache; не терять ordinary mentions | PROPOSED; текущий add_alert не ACS receipt API |
| Editor form | Минимальный IC-EDITOR-1 adapter в одной форме; narrative/edit isolation, lookup при потерянном ответе | PROPOSED, этот PR не подключает |
| MybbPostGateway | Только после предыдущих gates, штатный handler, без direct posts SQL | PROPOSED / BLOCKED |

Ни один из этих статусов не блокирует обычный форум или существующий read-only preview ACS. Не реализовывать «публикацию без атомарности на первое время».

## 8. Выполненные минимальные проверки

- Статически прочитаны четыре актуальных ACS-документа, AF bootstrap/router, Editor PHP/shell/JS, найденные post handlers и их указанные callees, существующий ACS TransactionManager, официальный эталон MyBB post/newreply/core/DB, datacache/functions/PM dependencies. Поиск по `inc/plugins` покрывает registrations, включая foreach; неизвестные внешние production plugins остаются gate.
- Проверены upstream tag/commit/version и hashes четырёх файлов, source main SHA. Это не checksum установленного сайта.
- Проверен engine **только изолированных probe-таблиц** через information_schema; inspected upstream install DDL, не production.
- Один ограниченный rollback probe на MariaDB **10.11.19-MariaDB-ubu2204**, одно соединение: InnoDB и MyISAM вставка → ROLLBACK: `innodb_rows=0`, `myisam_rows=1`. Затем outer START/insert id2 → nested START/insert id3/COMMIT → outer ROLLBACK: **оба id2,id3 остались**. Это воспроизводит SQL-паттерн Appearance и опровергает достаточность внешнего rollback; не исполняет маску или PostDataHandler.
- Probe не использует posts/пользовательские данные/боевые состояния. Две таблицы и их БД удалены, контейнер остановлен. Никаких production mutations, полного suite, E2E, PHP tests или UI проверки. PHP не менялся, `php -l` не требуется. Документационные ссылки и `git diff --check` проверены.

Воспроизведение probe **только в одноразовой локальной БД**, не в production:

```sql
CREATE DATABASE acs411_probe;
USE acs411_probe;
CREATE TABLE tx_probe (id INT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE legacy_probe (id INT PRIMARY KEY) ENGINE=MyISAM;
START TRANSACTION;
INSERT INTO tx_probe VALUES (1);
INSERT INTO legacy_probe VALUES (1);
ROLLBACK;
SELECT (SELECT COUNT(*) FROM tx_probe) AS innodb_rows,
       (SELECT COUNT(*) FROM legacy_probe) AS myisam_rows;
START TRANSACTION;
INSERT INTO tx_probe VALUES (2);
START TRANSACTION;
INSERT INTO tx_probe VALUES (3);
COMMIT;
ROLLBACK;
SELECT id FROM tx_probe ORDER BY id;
DROP DATABASE acs411_probe;
```

## 9. Ручная приёмка и оставшиеся доказательства

1. Сопоставить отчёт с **фактически активными** интеграциями warprift.ru. Передавать безопасные hashes/source diffs и список enabled/callbacks; не включать inc/config.php с секретами, личные posts или приватные definitions в публичный PR.
2. В изолированной копии установки сохранить версии PHP/MySQL/MyBB, driver, engines всех реально затрагиваемых таблиц, cache/mail backend и режим split read/write. Пример read-only metadata (показывает таблицы текущей выбранной БД; prefix сверять по безопасной конфигурации):

   ```sql
   SELECT VERSION(), @@session.autocommit, @@session.sql_mode;
   SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
   FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
   ORDER BY TABLE_NAME;
   SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
   FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE();
   ```

   Это предлагаемые запросы для администратора, **не выполненные на форуме**. Тела triggers дополнительно просмотреть приватно. Метаданные не преобразуют engines и не разрешают автоматически combat publish.
3. На staging проследить полный reply и Quick Edit с включёнными реальными hooks; проверить подписки email/PM/AAM, ordinary rewards, маску/галерею, moderator queue, merge, attachment paths. Не отключать плагины ради успешного результата без анализа.
4. Только после scoped исправлений gates провести rollback на стадии после PID и после каждого обязательного ACS/owner write; проверить **все** SQL counters/rewards/progress/queues, cache, shutdown и отсутствие отправленных уведомлений. Это будущая интеграционная проверка, не результат данного PR.
5. Проверить два параллельных submit одного key и разные combat/narrative requests, response-loss/unknown COMMIT; один PID/turn/result, прежняя tape, права/hidden data. При неизвестном outcome — recovery_required. Пока эти проверки не выполнены, IC-MYBB-1 остаётся BLOCKED.
6. Обычные темы/ответы/Editor/preview ACS должны работать как до PR: программных изменений нет.

Изменён только этот Markdown-отчёт. Откат — revert документационного commit; SQL, пользовательские данные, настройки и шаблоны не затрагиваются. К ACS-4.1.2 автоматически не переходить.
