# ACS-4.1.3 — ACP: совместимость реальной среды публикации

Исходная main: `e1ca61d3e1b51d5f2a514bcd00ad420d55cca821`.

В существующем ACS ACP добавлена вкладка **«Публикация» → «Совместимость»**. Раздел использует существующее разрешение `admin.diagnostics.view` (MyBB ACP + AF index permission), проверяемое до metadata-запросов, и не подключается на обычных страницах. Политика публикации **IC-MYBB-1 остаётся BLOCKED**; ни PASS отдельных наблюдений, ни включённый аддон её не меняют.

Основание: четыре нормативных документа [Master](ACS_MASTER_SPECIFICATION.md), [Architecture](ACS_TECHNICAL_ARCHITECTURE.md), [Contracts](../../contracts/ACS_INTEGRATION_CONTRACTS.md), [Roadmap](ACS_IMPLEMENTATION_ROADMAP.md), [аудит 4.1.1](../../audits/ACS_4_1_1_PUBLICATION_AUDIT.md), [контракт 4.1.2](../../contracts/ACS_4_1_2_GATEWAY_CONTRACT.md). Baseline и разделение масок/механики ACS-4.0 не изменены.

## Изменённые файлы

- `advancedcombatsystem/admin.php`: дополнительная вкладка, делегирование только явного ACP-запроса.
- `advancedcombatsystem/modules/Administration/PublicationDiagnostics.php`: сбор, безопасная проекция, вывод и экспорт. Пути относительно `inc/plugins/advancedfunctionality/addons/`.
- `tests/acs_publication_diagnostics.php`: точечный fixture без production БД/ядра.
- Этот отчёт. Нет migrations, Gateway, hooks регистрации или изменений других аддонов.

## Наблюдения и пределы

| Раздел | Данные | Что не доказано |
|---|---|---|
| MyBB | Текущие версии MyBB/PHP, класс DB adapter, наличие файла PostDataHandler; SHA256 четырёх файлов | Файл не подключается, handler не вызывается. Наличие класса/файла не доказывает исполнение штатного пути |
| Эталон | post.php, newreply.php, db_mysqli.php, class_core.php; точные SHA256 из 4.1.1, upstream commit `bd2a3447939d3084a5926dd66ece04649e0e0d60` | Совпадение этих четырёх файлов не доказывает целостность всего ядра или plugins. Другой DB driver требует отдельного аудита |
| DB | `SELECT VERSION(), CONNECTION_ID()` через обычный read route и writer route; сравнение object/resource identity read_link/write_link | Экспорт не содержит connection IDs. Совпадение текущего handle не доказывает routing вложенных handlers или будущих запросов |
| Engines | Только allowlist известного write set из 4.1.1 + таблицы текущих декларативных migrations ACS 001–007, в выбранной БД и с текущим prefix | Дополнительные неизвестные handlers могут затрагивать другие таблицы. Engines не заменяют проверку lock order/atomicity |
| Triggers | Только metadata allowlist таблиц: количество, logical table, timing, event и hash имени | Тела не читаются. Без подтверждённых TRIGGER privileges нулевой результат **NOT_VERIFIED**, а не доказательство отсутствия |
| Hooks | Зарегистрированные post/PM/newreply/newthread/edit/moderation/xmlhttp/global_end/shutdown callbacks, priority и owner из reflection/registration source | Только текущий **ACP bootstrap**. Не загружаются frontend-плагины или deferred callback files. Требуется отдельный newreply/Quick Edit inventory; регистрация не гарантирует исполнение ветки |
| Интеграции | Appearance, Balance, CS, APC, AAM, SmartURLTitles — detected/enabled и конкретная причина; cache/shutdown и coordinator отдельно | Нет автоматического READY/PASS по наличию файла. Включённый owner получает WARNING до проверки outcome-протокола, не утверждение доказанного бага каждой публикации |

PASS означает только конкретное наблюдение: версия равна 1.8.40, hash равен эталону, engine InnoDB либо текущий handle общий. WARNING: отличие/непроверенный side effect/optional отсутствующая таблица. BLOCKED: недоступный проверяемый файл ядра, нет обязательной текущей таблицы, известный non-InnoDB engine или отсутствующий доказанный coordinator. NOT_VERIFIED: нет достаточных наблюдений/metadata-доступа. Недоступность metadata не запускает repair или migration.

Таблицы будущих этапов не требуются до появления в текущем migration registry. Для optional owner-таблиц отсутствие показано WARNING: применимость определяется фактически используемой веткой, а не догадкой.

## Read-only и экспорт

Все четыре вида DB-запросов — SELECT metadata; writer routing здесь означает **выбор соединения для SELECT**, а не запись. Нет START/COMMIT/ROLLBACK, GET_LOCK, тестовых постов, runtime hook вызовов, cache update, lifecycle, audit writes или DDL. Ошибки DB скрываются штатным hide_errors и заменяются безопасным NOT_VERIFIED без SQL/exception/stack в ответе.

Кнопка экспорта скачивает JSON через `download`/data URL уже собранного отчёта, без дополнительного endpoint, внешнего сервиса или серверного временного файла. Тот же ACP permission защищает и HTML, и отчёт. Для read-only операции CSRF не требуется. Отключённый JavaScript не препятствует экспорту.

Структура отчёта — whitelist. Не сериализуются `$mybb`, `$db`, `$plugins`, settings/config, credentials, host/database names, prefix, токены, UID, тексты постов/PM, raw definitions или closure captures. Callback — только безопасное PHP-имя; абсолютные/произвольные source paths не выводятся. Имя trigger заменяется hash; тело не запрашивается. Имена стандартных таблиц — логические без prefix. Данные HTML экранируются, содержимое JSON base64-кодируется для download. Отчёт остаётся технической информацией администратора: перед публичным размещением проверить его применимость и состав на целевой установке.

## Подтверждённые блокировки и недостающие доказательства

**В этом PR подтверждена программная доступность диагностики, а не фактическое состояние warprift.ru.** Установленная production-среда недоступна из рабочего репозитория; реальные версии/engines/hooks должен собрать администратор через новый раздел.

1. **BLOCKED: отсутствует доказанный ACS publication coordinator/Gateway**. Нужны единый MyBB writer/UoW, PID/submission receipts, lock order, recovery unknown outcome и изолированный rollback после PID. Никакой обходной Gateway не добавлен.
2. **Сохраняется blocker аудита 4.1.1:** Appearance standalone START/COMMIT несовместим с ambient ACS transaction. Нужен отдельно проверенный joinable writer, сохранение standalone поведения и outcome-aware cache. Новый отчёт не исполняет маски и не доказывает, что installed код совпадает с прежним аудитом.
3. **NOT_VERIFIED до production-отчёта:** installed core hashes, DB engines, topology/routing, triggers и фактические frontend registrations. Для missing/non-InnoDB обязательных таблиц целевая диагностика выдаст конкретный BLOCKED.
4. **NOT_VERIFIED:** Balance→CS lost updates/locks, APC shutdown, AAM delivery/autoclean, SmartURLTitles network transformations, MyBB cache/PM/subscriptions/attachments/moderation/merge. Нужны branch-specific staging traces, приватный аудит trigger bodies и outcome-протокол; наличие callbacks эти условия не закрывает.
5. Ни metadata, ни upstream совпадение не заменяют staging concurrency/rollback/response-loss проверки всех SQL и необратимых side effects.

## Проверки

- `php -l` трёх изменённых/новых PHP-файлов.
- `tests/acs_publication_diagnostics.php`: отказ без cancp и без AF index grant до SQL; native hook array/priority/deferred owner; InnoDB/MyISAM/отсутствующая current ACS table; split read/write; отказ metadata; только SELECT; экспорт не содержит canary credentials, prefix, paths, trigger name, исключений или unrelated hooks.
- Проверка хешей эталона по локальной временной upstream-копии аудита, `git diff --check`.
- PHP CLI 8.4.24. Production PHP 8.5.0/MyBB ACP требует ручной приёмки; настоящая БД не опрашивалась. Полный suite/E2E/rollback probes не запускались.

## Ручная приёмка и откат

1. Развернуть файлы, войти в ACP с MyBB/AF правами, открыть AdvancedCombatSystem → Публикация → Совместимость.
2. Сверить версии, четыре hashes, driver и read/write observation. Отличия исследовать; не заменять автоматически файлы ядра.
3. Сверить engines, missing tables и triggers. Никаких кнопок «исправить» или rollback-тестов нет.
4. Сопоставить callbacks с текущим ACP bootstrap, отдельно запланировать inventory frontend на staging. Не считать пустой ACP список доказательством отсутствия handlers.
5. Скачать JSON, проверить обезличивание. Передать отчёт для следующего аудита; passwords/config.php не прикладывать.
6. Проверить отказ без AF ACP permissions. Открыть обычную тему/ответ/Quick Edit: новых hooks и публикационных команд нет.

Откат — revert этого PR/файлов; БД и настройки не меняются. Переход к следующей задаче не выполнялся.
