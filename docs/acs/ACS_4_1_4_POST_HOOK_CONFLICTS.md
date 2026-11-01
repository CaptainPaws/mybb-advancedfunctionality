# ACS-4.1.4 — узкий адаптер AdvancedAppearance и оставшиеся publication gates

Исходная main: `aef975b9824d68490434e2bce82f96880b97fa1a`. Основание — четыре нормативных документа ACS, [4.1.1](ACS_4_1_1_PUBLICATION_AUDIT.md), [4.1.2](ACS_4_1_2_GATEWAY_CONTRACT.md), [4.1.3](ACS_4_1_3_ENVIRONMENT_DIAGNOSTICS.md), уточнение ACS-4.0. Игровой baseline не меняется.

**IC-MYBB-1 остаётся BLOCKED.** Этот PR устраняет безусловный вложенный START/COMMIT AdvancedAppearance и предоставляет узкий owner-side port для будущего проверенного coordinator. Он не делает всю публикационную цепочку атомарной. Условия общей приёмки ACS-4.1.4 по всем интеграциям пока не выполнены.

## Полученные данные установки

Заказчик передал текст фактического ACP отчёта 4.1.3 в этой задаче. Это operator-provided snapshot, не самостоятельное подключение Codex к серверу:

- MyBB **1.8.41**, PHP **8.5.12**, MySQL **8.0.46-0ubuntu0.22.04.4**, DB_MySQLi. Ранний аудит 1.8.40 требует сверки с реальной 1.8.41.
- Hash PostDataHandler и mysqli driver совпадают с эталоном 1.8.40; `newreply.php` и `class_core.php` отличаются. Это само по себе не доказывает ошибку: нужна проверка 1.8.41 и локальных изменений.
- Текущий ACP запрос показывает один read/write handle. Это не доказательство routing вложенных frontend handlers.
- **MyISAM:** posts, threads, forums, users, threadsubscriptions, attachments, mailqueue, privatemessages, moderatorlog, datacache. Эти SQL-записи не откатываются транзакцией ACS. Это самостоятельная подтверждённая по отчёту **BLOCKED** зависимость, которую исправление post hooks не снимает.
- InnoDB: Balance/ledger/post rewards, CS sheets, AAM alerts, Appearance assignments, Gallery media и текущие ACS tables 001–007.
- Appearance, Balance, CS, APC, AAM, SmartURLTitles включены. В текущем ACP bootstrap видны только два AF xmlhttp callback. Нельзя считать, что frontend post hooks отсутствуют/не исполняются.
- Видимых triggers 0; полнота privileges и их фактическое отсутствие не подтверждены. Cache/shutdown backend не трассировался.

В этом PR **нет engine conversion**. Нужна отдельная согласованная миграция MyBB write set с резервной копией, оценкой индексов/нагрузки и staging-проверкой. Ни production ALTER TABLE, ни rollback probes на пользовательских данных не выполнялись.

## Исправленный конфликт AdvancedAppearance

Пути относительно `inc/plugins/advancedfunctionality/addons/advancedappearance/`:

| Файл / функции | Изменение |
|---|---|
| `personal.php`, `af_aa_personal_transaction()` | Существующий standalone API двух аргументов сохранён. Перед START проверяются autocommit и текущая isolation policy, затем `SET TRANSACTION ISOLATION LEVEL <та же policy>`: сервер отказывает внутри активной транзакции **без implicit COMMIT**. ROLLBACK выполняется только если функция сама начала транзакцию |
| `personal.php`, `af_aa_personal_assert_standalone()` | MySQL `transaction_isolation` с fallback MariaDB `tx_isolation`, allowlist isolation и безопасный отказ при недоступности контекста; autocommit=0 не принимается как standalone. Не меняет установленную isolation policy на другой уровень |
| `personal.php`, `af_aa_personal_owner_lock_key()` | Один существующий namespace owner advisory lock, который будущий coordinator может использовать до owner writes; нет второго несовместимого имени lock |
| `post_mask_uow.php` | `AF_AA_PostMaskUnitOfWork`: контракт проверки активного writer/owner lock, rollback-only и invalidation после обоих outcomes. `AF_AA_PostMaskScope::within()` привязывает port к одному handler object, запрещает nested scope и очищается в finally |
| `post_masks.php`, `af_aa_mask_commit()` | В явно переданном UoW читает PID/owner/visible напрямую на writer, без cached get_post; выполняет прежние owner SQL writes без собственных BEGIN/COMMIT/ROLLBACK/GET_LOCK/RELEASE_LOCK. Request caches сбрасываются после внешнего outcome |
| `post_masks.php`, `af_aa_mask_after_handler()` | При явном UoW ошибки не превращаются в warning: помечают rollback-only и выходят coordinator. Без UoW сохраняется обычное warning-поведение ошибки маски после уже сохранённого narrative post. Но обнаруженный неподдержанный ambient transaction выходит наружу, чтобы не продолжать небезопасную публикацию |

Standalone путь по-прежнему сериализует владельца прежним advisory lock, сохраняет обычные presets/assignments/Gallery retention и инвалидирует cache после своего commit. JOIN branch не подключается автоматически из-за наличия ACS, UID, hidden field, `submission_id`, request/settings флага или маски. `personal.php` не ищет ACS-классы и не вызывает боевые сервисы.

Третий аргумент `af_aa_personal_transaction()` — доверенный PHP owner port; существующие личные CRUD-вызовы его не получают и **не присоединяются автоматически** к чужому UoW. Scope используется только post-mask hook. Нет нового frontend endpoint, SCEditor, запрета смены маски или изменений оформления.

### Обязательства будущего coordinator — PROPOSED, не готовый publisher

В production **нет реализации** `AF_AA_PostMaskUnitOfWork` и нет его регистрации в ACS. Рабочий объект в тесте — локальный test harness. Это не обход BLOCKED и не доказательство готовности Gateway.

Будущий проверенный coordinator обязан:

1. До вызова handler проверить полный actual write set/engines, поддержанную ветку MyBB, права и CSRF, pinned submission/PID, таблицы Appearance и Gallery, единое соединение. Owner port дополнительно отвергает разные MyBB read_link/write_link; split routing здесь не поддержан.
2. До owner writes взять прежний lock через `af_aa_personal_owner_lock_key()` в **общем согласованном lock order** и держать его до commit/rollback. Сам адаптер не приобретает поздний lock внутри publish transaction. Глобальный порядок между ACS, user/Balance/CS и owner locks ещё не доказан.
3. Реализовать `assertActiveWriterAndOwnerLock()` реальной проверкой активного outer transaction/совпадения writer/owner lock и всех обязательных engines. Булевое `transaction_safe=true` или no-op implementation не является доказательством.
4. Вызвать handler в `AF_AA_PostMaskScope::within($unit,$handler,$callback)`. Не проглатывать ошибки; `markRollbackOnly()` должен запрещать outer commit, даже если caller поймал исключение. Другой handler не может наследовать scope.
5. На **обоих** outcomes выполнить зарегистрированные invalidation callbacks. Это только request-local cache, не уведомления и не outbox. Внешняя доставка запрещена до durable outcome.
6. Обеспечить durable submission receipt/idempotency/recovery. Assignment upsert сам по себе не защищает весь post/rewards от повторной публикации. Гарантия одного PID относится к будущему coordinator, не к этому owner port.

Маска остаётся вне CombatSnapshot, mechanical dependencies, simulation/confirmation hashes, RNG tape и event hashes. Смена/ошибка оформления не запускает Resolver, не пересобирает снимки и не меняет HP/AP/ходы. В joined protocol ошибка обязательного owner write отклоняет ещё **не подтверждённую** общую публикацию; подтверждённые бои не пересчитываются. Сам выбор стратегии обязательного join или отдельного durable post-commit оформления принадлежит будущему доказанному coordinator; в этом PR второй outbox не выдумывается.

## Остальные реальные writers: отдельные небольшие scope, пока BLOCKED

| Интеграция / конкретный код | Риск и требуемое отдельное изменение |
|---|---|
| Balance `af_balance_apply_scaled_delta()` | read→absolute UPDATE без согласованной row-lock дисциплины; нужен отдельный атомарный writer, покрывающий **всех** writers, clamp и unchanged reward formula. Не менять одну post-функцию в обход остальных |
| Balance `af_balance_recalc_post_awards()`, `af_balance_schedule_post_recalc_after_request()` | Post rewards/hash и shutdown recalc не равны durable submission dedupe. Нужны согласованные receipts/outcome и защита повторного PID, без отключения EXP/валюты |
| CS `modules/experience.php::af_charactersheets_on_exp_changed()` → `sheets_crud.php::af_charactersheets_update_sheet_json()` | Progress и base/build пишутся из прочитанного sheet; нужен свежий writer/lock или scoped update/CAS, согласованный с другими sheet writers. Обычные начисления не должны переписывать immutable ACS snapshots; этот PR snapshots не трогает |
| APC `af_advancedpostcounter_increment_post()`, `af_advancedpostcounter_schedule_rebuild()/run_deferred_rebuild()` | Сейчас users MyISAM; SQL counter не rollback-safe. Deferred whole-count rebuild надо привязать к outcome. Статистика не отключается |
| AAM `af_aam_post_insert_end()/af_aam_add_alert()` | SQL alerts InnoDB недостаточно: autoclean/cache и доставка требуют post-commit outbox с ack/dedupe submission/event и recovery. Без реального coordinator отдельная queue не доказывает atomicity |
| SmartURLTitles `af_sut_insert_post()/af_sut_fetch_title()` | Внешний HTTP и изменение prose должны завершиться до transaction и фиксации окончательного payload. Не держать publication locks при network fetch |
| MyBB PM/mail/cache/shutdown, attachments/moderation/merge | MyISAM queues и внешние side effects требуют engine plan, actual branch trace и outcome-aware dispatch; upstream PostDataHandler не заменяет установленную проверку |

Эти файлы не изменены: нет доказанного coordinator/lock order и нет staging установленной 1.8.41. Безопасная общая правка потребовала бы существенных независимых owner изменений; согласно заданию они отделены от маленького Appearance PR. Начисления, их формулы, уведомления и статистика **не отключались**. Сохранность реальных Balance/EXP при atomic publish пока не объявляется доказанной; обычный путь этих интеграций сохранён побайтно.

## Проверки

Изолированная одноразовая MariaDB **10.11.19**, PHP CLI **8.4.24**; контейнер без сети, отдельная БД `appearance_test` с prefix `aa_test_`, не warprift.ru.

- `php -l` изменённых/new PHP; `git diff --check`.
- `tests/appearance_post_mask_uow.php` повторно использует узкий existing personal/mask fixture: обычные presets, Gallery/ownership, standalone маска и отсутствие маски; не полный test suite.
- Обычный handler без pending mask не вызывает AA transaction; нормальная маска сохраняется; отказ валидации settings сохраняет старую маску и обычный warning.
- Внешний START + изменение fixture post + неподключённый hook: исключение контекста, **нет внутреннего START/COMMIT/ROLLBACK**, outer ROLLBACK возвращает исходный текст.
- Явный joined test UoW: нет внутренних boundaries или новых advisory locks; внешний rollback откатывает assignment, внешний commit сохраняет его. Ошибка маски выходит из hook и помечает rollback-only, post fixture изменение откатывается.
- Invalidation request-кэшей после rollback; отсутствие owner lock, другой handler и split read/write отклоняются. Scope очищается после ошибки/успеха.
- Последующая standalone смена/снятие маски работает; Resolver/SealService не подключаются.

Это **не настоящий MyBB reply**: fixture использует существующий test handler, не установленный PostDataHandler. MySQL 8.0.46/PHP 8.5.12 на warprift.ru, реальная валидация reply, Balance/EXP, frontend hook order, concurrent publish, notifications/unknown COMMIT требуют отдельной staging-проверки. Нет production запросов/записи, MyBB Core changes, SQL INSERT posts в production-коде, migrations, reward formula changes, полного suite/E2E или опасного production rollback.

## Ручная приёмка и откат

1. На staging с фактическими MyBB 1.8.41/PHP 8.5.12 проверить обычный reply без маски и с маской, редактирование/Quick Edit, смену маски во время ACS боя. Не должно быть пересчёта/переброса механики.
2. Проверить обычные Balance/EXP/статистику и уведомления до/после; формулы и handlers не менялись. Ошибку маски воспроизводить только контролируемым staging-сценарием, не повреждая production media/schema.
3. Экспортировать свежий 4.1.3, сохранить MyISAM BLOCKED. В рамках этого PR не менять engines и не пробовать combat publication.
4. Joined protocol проверять только с будущим реально аудированным coordinator; отсутствие реализации сейчас ожидаемо. Не включать его через HTTP/ACP флаги или произвольные callback заглушки.
5. После отдельной engine migration, owner writer/outbox scopes и аудита установленного frontend повторить полный ограниченный atomic rollback/concurrency/outcome сценарий. До этого IC-MYBB-1 не READY.

Откат: revert файлов PR, без DDL или удаления пользовательских данных. Возврат старого кода возвращает исходный риск nested transaction, поэтому боевую публикацию оставить отключённой. Ни одна последующая задача автоматически не начата.
