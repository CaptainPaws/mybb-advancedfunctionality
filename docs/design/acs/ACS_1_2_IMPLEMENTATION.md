# ACS-1.2 — SQL foundation и lifecycle

Проверенная main: `36d446a59554f4823e4898b9f71b969ce963e954` (ACS-1.1 уже включена).
Версия аддона: **0.2.1**. Версия схемы: **001_foundation**.
Design Baseline и четыре исходные спецификации не изменены. Боевой механики нет.

## Миграция и схема

`migrations/001_foundation.php` содержит ровно восемь таблиц из Architecture §5.1/§5.4.
Колонки, nullable-поля, DEFAULT и индексы сохранены. К SQL добавлено только явное
`ROW_FORMAT=DYNAMIC` для проверки современного InnoDB. Все таблицы —
InnoDB / utf8mb4 / utf8mb4_unicode_ci, имена используют фактический TABLE_PREFIX.
Физических FOREIGN KEY нет. Имена ниже без префикса MyBB.

| Таблица | PRIMARY KEY | UNIQUE | Обычные KEY |
|---|---|---|---|
| af_acs_schema_migrations | version | — | — |
| af_acs_sessions | id | — | thread_status(tid,status); owner_status(owner_uid,status) |
| af_acs_session_roles | id | member_role(session_id,uid,role) | user_sessions(uid,session_id) |
| af_acs_snapshots | id | — | source_version(source_system,contract_id,contract_version); session_hash(session_id,content_hash) |
| af_acs_operations | operation_key | — | pending_operations(status,retry_at); external_lookup(external_system,external_ref); session_operations(session_id,status) |
| af_acs_events | id | session_order(session_id,session_sequence); encounter_order(encounter_id,event_sequence); operation_once(operation_key) | entity_events(target_entity_id,id); submission_events(submission_id,id) |
| af_acs_event_details | event_id | — | — |
| af_acs_diagnostics | error_id | — | request_errors(request_id); encounter_errors(encounter_id,created_at) |

Всего **24 индекса**, включая 8 PRIMARY KEY. Другие таблицы не создаются.
Ссылки events/operations на encounters/entities/submissions пока остаются NULL.
Публичные методы stage-1 хранилищ отклоняют их использование до появления
проверяемых родительских сущностей. Сессии не создаются при просмотре страницы;
сервис создания сессий относится к этапу 2.

## Механизм миграций

Runner использует явный последовательный registry. Для миграции записываются
version/status/step/SHA-256 файла/updated_at/error_id. Завершённая миграция
повторно проверяется, но её journal и данные не переписываются. Файл выпущенной
миграции нельзя редактировать: изменения схемы оформляются следующей миграцией.

До DDL проверяются все существующие таблицы. После CREATE выполняется readback
information_schema: состав и порядок колонок, типы, nullable/default/auto_increment,
collation, полные индексы, uniqueness, порядок колонок индекса, отсутствие FK,
engine и row format. Несовпадение блокирует runner; ALTER/REPAIR/DROP при upgrade
не выполняются. Неизвестная версия или иной checksum журнала также блокируют работу.

GET_LOCK на имени, полученном из БД и TABLE_PREFIX, не даёт двум runner запускаться
параллельно. Такой же lock используют записи хранилищ и пустой uninstall. Все
запросы идут по существующему **MyBB write_link**, без отдельного соединения.
При прерывании между CREATE и checkpoint уже созданная таблица проверяется и
сохраняется. Записанный завершённый шаг с потерянным объектом считается повреждением,
а не поводом пересоздать таблицу. DDL rollback не используется как восстановление.

До начала работ проверяются версии СУБД (MySQL >=8.0, MariaDB >=10.6), InnoDB,
поддержка utf8mb4/strict SQL mode для ACS, utf8mb4_unicode_ci и innodb_page_size >=16 KiB.
Начиная с 0.2.1 ACS временно применяет utf8mb4 и strict mode только внутри собственных
SQL-вызовов. Исходные charset client/connection/results (в том числе NULL), collation,
sql_mode и charset клиента mysqlnd восстанавливаются в finally. Соединение MyBB/ATF
может использовать utf8/utf8mb3 и нестрогий режим; глобальные настройки не меняются.
На install/upgrade отдельная TEMPORARY-таблица проверяет полный 3072-byte UNIQUE и
DYNAMIC на фактическом сервере. При неготовности проверки — отказ без обрезки ключей.
Просмотр ACP не меняет таблицы и журнал и не выполняет этот DDL-probe;
временные настройки SQL-сессии восстанавливаются перед возвратом управления.

## Lifecycle и минимальное изменение AF

- `install`: проверка среды и последовательный runner, безопасное продолжение.
- `activate`: проверка установленной схемы без CREATE, сброса настроек и миграций.
- `deactivate`: остановка собственного runtime без очистки; не требует исправных
  SQL-зависимостей. Persisted enabled по-прежнему принадлежит AF.
- `upgrade`: только отсутствующие шаги с readback и проверкой checksum.
- `uninstall`: отдельный ACP POST, корректный CSRF, disabled и точное подтверждение
  `DELETE EMPTY ACS FOUNDATION`. Любые строки в таблицах данных/диагностики или
  неизвестные таблицы af_acs_* блокируют удаление. Даже завершённая история не
  удаляется: общего протокола проверки обязательств следующих этапов пока нет.

В `inc/plugins/advancedfunctionality.php` добавлена узкая ветка ACS:
на enable вызываются is_installed → при необходимости install → activate.
Общий install-on-every-enable контракт других аддонов сохранён. ACS-контроллер
доступен также при disabled через тот же permission-protected AF router, чтобы
обновление/просмотр ошибки/явное удаление были доступны после отключения.
Отдельной административной авторизации, публичных endpoints и нового manifest-поля нет.

Настройки и шаблоны на данном этапе не добавляются. Ordinary bootstrap загружает
только bootstrap/Context, SQL-модули подключаются административным lifecycle или
явным использованием внутренних хранилищ. Resolver/KB/CS/Inventory не подключаются.

## Хранилища

Новые классы: AF_ACS_Migrations, AF_ACS_Repository, AF_ACS_OperationStore,
AF_ACS_SnapshotStore, AF_ACS_TransactionManager. Procedural lifecycle:
af_advancedcombatsystem_is_installed/install/activate/deactivate/upgrade/uninstall,
с общими внутренними helpers migrations/lifecycle.

Repository предоставляет session, appendEvent, event и recordDiagnostic;
SnapshotStore — append/get; OperationStore — register/get/transition.
Нет публичного update/delete исторических событий или snapshots.
Значения связаны native MySQLi prepared parameters; идентификаторы таблиц берутся
из whitelist, TABLE_PREFIX проверяется отдельно. Требуются 64-bit PHP >=8.4,
mysqli с mysqlnd и mbstring; целевая среда проекта остаётся PHP 8.5.0/MyBB 1.8.40.

Записи сериализуются, parent session блокируется перед дочерними строками.
Event/details/session sequence/version и локальный committed operation записываются
в одной транзакции. Перед записью проверяются completed journal, checksum и
транзакционность всех восьми таблиц. Повторный operation_key возвращает существующую
операцию только с теми же session/kind/payload hash; не запускает side effect.
CAS transitions увеличивают operation_version, terminal нельзя вернуть в prepared.
External operations в этом этапе не выполняются. Состояние recovery_required не
переисполняется автоматически. Неизвестный результат commit требует lookup,
не повторного применения.

JSON требует schema_version, конечные числа и round-trip binary64 encoding.
Большие идентификаторы передаются decimal strings; небезопасные JSON integer
выше 2^53-1 отклоняются. Snapshot сохраняет numeric_policy/engine_version,
contract/schema versions и SHA-256; event details сохраняют numeric_policy и hash.
Проверяются диапазоны SQL integer, parent/same-session event и snapshot links.
Нативная ошибка SQL не передаётся в ACP: используются безопасные коды/error_id.
Diagnostics принимает только категорию и корреляционные идентификаторы, без SQL,
stack traces или произвольных секретных payload.

TransactionManager не присоединяется к чужой транзакции: проверка autocommit и
SET TRANSACTION до START не допускает неявного commit чужой работы. Это не будущий
MyBB publish coordinator; интеграция публикации остаётся отдельным gate этапа 4.

## Файлы и проверки

Изменены: AF Core; ACS manifest.php, advancedcombatsystem.php, admin.php.
Context.php не изменён. Добавлены: migrations/001_foundation.php,
modules/Administration/Migrations.php, modules/Infrastructure/{Repository,
OperationStore,SnapshotStore,TransactionManager}.php, один самостоятельный
tests/acs_foundation_regression.php и этот отчёт. Остальные аддоны не изменены.

Проверено на **PHP 8.4.24 + отдельной MariaDB 11.8.6**, без production credentials.
На production DB и PHP 8.5.0 проверка ещё не выполнена; runtime preflight проверит
её конкретные возможности. MySQL 8.0 допускается preflight, но отдельный MySQL-сервер
в этом проходе не использовался.

- php -l всех затронутых PHP и Context — PASS.
- 54 целевых проверки в tests/acs_foundation_regression.php — PASS, в том числе
  при MYSQLI_REPORT_OFF. Чистая установка/повтор/DDL-сверка, checksum conflict,
  interruption/resume после реального auto-commit, параллельный lock, CAS, immutable
  history, integer/JSON limits, lifecycle, CSRF/GET, пустой и запрещённый uninstall,
  readonly ACP. MyBB окружение и auth helpers — минимальные stubs, БД — реальная.
- Существующий tests/admin_router_refresh_regression.php — PASS.
- Уникальность ACS публичных символов и git diff --check — PASS.

Запуск regression: `php tests/acs_foundation_regression.php /absolute/isolated-mariadb.sock`.
Только на отдельном локальном тестовом сервере: тест создаёт и удаляет собственные
случайные БД acs12_fixture_*. Для режима без исключений: ACS_TEST_REPORT_OFF=1.
Стресс-тесты, полный suite, E2E и визуальная автоматизация не запускались.

## Ручная приёмка и восстановление

1. Сделать резервную копию БД и файлов, установить PR. На PHP 8.5.0 выполнить lint.
2. Открыть ACS ACP: GET показывает installed/not-installed и не создаёт таблицы.
   Нажать install или включить ACS штатной кнопкой AF. Проверить восемь таблиц,
   24 индекса, schema 001_foundation, completed/step=8/checksum, engine/collation.
3. Отключить и снова включить ACS: таблицы, строки, версии, настройки, шаблоны
   ATF/AdvancedEditor сохранены. Повторить install/upgrade — изменений данных нет.
4. Открыть обычную тему и профиль; проверить редактор и отсутствие migration SQL
   на этих запросах. На ACP GET нет DDL. Проверить штатный запрет доступа к AF
   для администратора без прав на модуль.
5. Проверки искусственного сбоя проводить только regression на изолированной БД.

При ошибке миграции сохранить backup, error_id и SHOW CREATE TABLE затронутых
таблиц. Исправить причину совместимости/доступности и нажать upgrade/resume.
Не удалять журнал, не подменять checksum и не удалять созданные таблицы для
«повторной установки». При расхождении схемы runner остановится: требуется
разобрать отличие и подготовить отдельную проверенную коррекцию/миграцию либо
восстановить полную согласованную резервную копию. Не использовать rollback SQL
transaction как откат уже выполненного CREATE.

Для отката к ACS-1.1 отключить ACS и вернуть файлы предыдущей версии; ACS-таблицы
сохранить. Автоматического down migration и удаления истории нет. Только пустую
инфраструктуру можно удалить явной подтверждённой командой. Если удаление пустой
схемы прервалось, повторить uninstall с тем же подтверждением; upgrade не должен
«чинить» потерянные объекты завершённой миграции.

ACS-1.3 не начинается до ручной приёмки ACS-1.2.

## Исправление реактивации 0.2.1

Сообщение `ATF activation failed; stage: acs_lifecycle` объединяло две проблемы:
общий форматтер AF ошибочно всегда писал ATF, а preflight ACS отвергал допустимые
настройки общего MyBB-соединения. На отдельной MariaDB воспроизведён отказ
ACS_DB_INCOMPATIBLE с charset=utf8 и sql_mode=''. Production-параметры не считывались;
по исходному общему коду нельзя определить, какой именно guard сработал на форуме.

Исправление не переводит общее соединение MyBB/ATF на новые постоянные настройки.
ACS использует ограниченную область SQL-настроек с полным восстановлением после
успеха и ошибки; nested SQL переиспользует область транзакции/runner. Core-форматтер
показывает фактические addon и operation, прежняя подпись ATF сохранена для ATF.
Вместо общего ACS_DB_INCOMPATIBLE выдаются отдельные безопасные коды VERSION,
PAGE_SIZE, UTF8MB4, STRICT_MODE, INNODB и COLLATION. Ограничения на реальную поддержку
схемы не ослаблены. Миграция 001_foundation, её checksum и таблицы не изменены.

Проверки исправления: 58 целевых проверок, включая восстановление общего соединения,
ошибочный путь и четырёхбайтовый Unicode; штатный вариант и вариант
ACS_TEST_MYBB_DEFAULTS=1 (utf8 + пустой sql_mode), в том числе MYSQLI_REPORT_OFF.
Проверены общий diagnostic formatter для ACS и ATF, AF Admin Router и PHP lint.
Среда — PHP 8.4.24 / MariaDB 11.8.6; production PHP 8.5.0 по-прежнему проверяется вручную.

Установка исправления: обновить AF Core и файлы ACS из PR, затем включить ACS
штатной кнопкой AF. Переустановка всего AF, удаление таблиц и сброс journal не нужны.
Проверить schema 001_foundation в ACP, обычную тему и ATF. Если реальная СУБД не
поддерживает нужную возможность, будет показан конкретный код вместо ошибочной
подписи ATF. Откат к 0.2.0 — только файлы; схема и сохранённые данные не меняются.
