# ACS-11.3.3 — ACP: Settings, Permissions, Sessions, Diagnostics и Integrations

## Область и источники

Один ограниченный PR поверх main `fd2bbdb8` (ACS-11.3.2). Использованы ACS_MASTER_SPECIFICATION, ACS_TECHNICAL_ARCHITECTURE, ACS_INTEGRATION_CONTRACTS, ACS_4_5_R2_INTEGRATION_ACCEPTANCE и отчёты Stage 10/11. `docs/ROADMAP.md` не изменён.

Installed acceptance: **NOT_VERIFIED**. В checkout нет установленного MyBB/БД форума; ниже описаны проверенные связи исходного кода, а не результаты работы установленного ACP.

## Существующий маршрут

Сохранена цепочка `admin/router.php → AF_Admin::dispatch → findAdminController → manifest.admin → AF_Admin_Advancedcombatsystem::dispatch`. Все вкладки используют `index.php?module=advancedfunctionality&af_view=advancedcombatsystem&acs_section=...`. Проверки `admin.view`, `admin.permissions.view`, `admin.diagnostics.view`, `admin.integrations.view` остаются в AuthorizationService. Новый административный endpoint не создан, AF core не изменён.

Обнаруженные пробелы:

- ACP «Сессии» делегировал Presentation/Sessions::render(true), включая игровые POST-команды, вместо отдельного реестра.
- В capability matrix отсутствовал действующий Balance reward owner contract.
- Диагностика автоматически загружала механический стек и исполняла синтетический Effects::attempt при каждом открытии.
- Матрица прав показывала технические policy codes без описания; «Основные» не объясняли действующие глобальные настройки и их область.

## Основные и настройки

Добавлена сводка фактически поддерживаемых параметров: группы создания сессий, роль новой сессии (observer/player), группы расширенного экспорта. Редактирование открывает существующий штатный `config-settings` по группе `af_acs_sessions`, как у других AF addons. Новый saver не добавлен; право AF ACP не обходит право MyBB config-settings, его CSRF и административный audit.

`SessionAccess::defaults()` читается `SessionService::command(create)` и сохраняется в config_json новой сессии. Изменение глобального default не обновляет существующие session/encounter configs. Право создания не назначает GM существующим сессиям; расширенный экспорт дополнительно проверяет GM/native guards.

Archive cursor secret в сводке не выводится, на GET не создаётся и не ротируется. Указано последствие его изменения через штатный редактор: инвалидирование прежних курсоров/version tokens. Включение аддона оставлено существующему AF lifecycle. Сохранены сводка версии/ledger/ошибок/публикации и отдельные явные POST-команды install/upgrade/uninstall с прежними permission/CSRF/typed uninstall guards. Ложное сообщение успеха при возвращённом false исключено. Открытие вкладок не выполняет миграции.

## Права

Матрица остаётся read-only и строится из AuthorizationService::matrix(). Добавлены русские описания каждой текущей policy и ролей. Нет универсального редактора grants. RESERVED_DENY, native/thread/membership/owner/controller checks, неизменяемость, журнал и идемпотентность не менялись.

## Сессии

AcpReadModel читает только metadata сессий; фильтры: ID, TID, owner UID, status. Keyset pagination: до 50 записей, запрос LIMIT 51, непрозрачный AES-GCM cursor через существующий Archive, привязанный к текущему UID и фильтрам. Нет общего количества или номеров скрытых записей. Роли текущего пользователя читаются одной batch-выборкой на страницу; native доступ проверяется по каждой уникальной теме с request cache.

Ссылки на тему/GM используют существующий showthread и acs_session. GM-ссылка появляется только при текущей сохранённой owner/GM роли; global AF ACP не назначает session GM. Native-недоступные темы не выводятся. Допускается страница без доступных строк с переходом к следующей партии. Отсутствие установленного cursor key блокирует продолжение безопасной ошибкой; ключ не создаётся на GET.

POST в реестр отклоняется, Presentation/Sessions и SessionService command не вызываются. Создание/изменение сессий и игровые операции выполняются на существующих разрешённых frontend-поверхностях.

## Диагностика и recovery

Сохранены подробный migration ledger, целостность актуальной схемы и безопасный lifecycle error. Добавлен точный поиск 32-hex error_id и последние 20 диагностических метаданных без details_json, SQL и raw exception. Uppercase безопасные phase codes не теряются при проекции.

Inspect требует явный ID одной сессии, административное право диагностики и native доступ к теме. Показывает:

- до 50 операций с key/kind/status/version/time, точный поиск operation_key и следующую страницу;
- до 20 незавершённых публикаций с submission/status/phase/version/time;
- количества pending/recovery_required AAM/statistics outbox records выбранной сессии. Учтены root session_id, вложенный aggregate payload.session_id и statistics.refresh через encounter.

Не читаются игровые request/result/sealed JSON или литературный текст. Pending/recovery_required не названы успешными. Ошибки выводятся с безопасным уведомлением и error_id через существующую диагностику (best effort, если само хранилище недоступно).

Inspect не вызывает owner lookup, retry, dispatch, credit, Resolver либо компенсацию. Ссылки на существующие RecoveryPage и completion recovery показываются только текущему session GM. Это навигация, а не новый recovery contract: права, CSRF, expected versions, reason/confirmation/audit критических решений остаются у прежних сервисов. Универсальная admin.recover по-прежнему RESERVED_DENY. Реестр и inspect не изменяют игровое состояние; аварийная запись error_id остаётся служебным диагностическим действием.

Синтетический Effects sample больше не исполняется при открытии диагностики. Загрузка констант версий Domain, включая Resolver, доступна отдельной read-only ссылкой и не вызывает механические методы.

## Интеграции и доказательства

Сохранено разделение owner detection/enabled/version, деклараций реализации, lazy runtime, выбранного источника, установленной схемы и installed acceptance. READY не выводится из одного файла/класса/callable. Lazy-loadable не означает отсутствие реализации. Сохранены точечные read-only KB/CS probes и NOT_CHECKED / SOURCE_NOT_SELECTED при пустом source input; нет сканирования всех листов/KB.

| Поверхность | Доказательство в исходниках | Граница installed readiness |
| --- | --- | --- |
| MyBB Gateway | MybbPostGateway + PublishService, native PostDataHandler | BLOCKED, IC-MYBB-1 и installed audit; publication_enabled=false |
| Inventory | OwnerProtocol reserve/consume/lookup/participate + CombatInventoryAccess | NOT_VERIFIED без owner schema/writer/authorization acceptance; отсутствующий owner/API — BLOCKED |
| Balance | RewardCredit credit/lookup/assertStorage + BalanceRewardAuthority и RewardService issue/recover | NOT_VERIFIED / INSTALLED_BALANCE_ACCEPTANCE_REQUIRED; ACP не вызывает credit |
| KB | Definition reader, RegistrationSource, SnapshotStore | Точечная проверка источника отдельно; installed acceptance NOT_VERIFIED |
| CharacterSheets | Owner prepare/create snapshot + CharacterSheetAdapter | То же; пустой sheet ID не является повреждением owner |
| AdvancedStatistic | completed aggregate, durable receipt/lookup, StatisticsRepository | NOT_VERIFIED без установленного owner/consumer acceptance |
| AAM | CombatDelivery deliver/lookup + NotificationOutbox/NotificationService | NOT_VERIFIED без roles/hidden/replay/lost ACK/outage acceptance |
| ATF / Editor | Действующие providers / native form integration | NOT_VERIFIED до ручной installed UI-приёмки |
| ACS schema | MigrationManager::status, ledger/checksum/schema integrity | READY допустим только после успешного полного readback текущей версии |

Balance добавлен в общий renderer capabilities с отдельными реализацией, owner/runtime, source check, acceptance, blocker и действием. Владелец должен подтвердить схему, точный reward intent, minor units, version conflict и durable lookup прежней выдачи. Наличие методов не подтверждает эти свойства установленной среды. Никакие acceptance flags не записываются.

## Изменённые файлы

- `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/admin.php` — wiring вкладок, описания policies, явная загрузка engine versions, корректный результат lifecycle.
- `modules/Administration/AcpSections.php` внутри аддона — native settings navigation, read-only реестр/inspect, безопасные ошибки.
- `modules/Administration/AcpReadModel.php` — bounded parameterized metadata queries и cursor.
- `modules/Administration/Diagnostics.php` — Balance capability.
- Этот отчёт.

Новых settings, migrations, DDL, таблиц, frontend assets, маршрутов, Resolver, owner adapters или тестов нет. AF core, игровые сервисы, publication gate, engines MyBB и immutable события не изменены.

## Разрешённые проверки

- Статически прослежены существующий AF router/manifest/dispatch, permission matrix, native settings link, SessionAccess defaults → создание сессии, GM/recovery ссылки и owner Balance APIs.
- Поля metadata запросов сопоставлены с существующими migration definitions и repository APIs; SQL parameter binding сохранён. Никакой запрос к установленной БД не выполнялся.
- `git diff --check`: PASS.
- PHP CLI отсутствует: `php -l` **NOT_VERIFIED**, новая среда не устанавливалась.
- Автоматические, интеграционные, browser-тесты и fixtures не создавались и не запускались согласно AGENTS.md.

## Ручная приёмка владельца — NOT_VERIFIED

Проверить native config-settings право и сохранение supported values, неизменность старых configs; readonly матрицу/RESERVED_DENY; фильтры и переходы реестра, cursor после изменения фильтров и недоступные форумы; owner/GM/не-GM ссылки; error_id lookup, выбранную сессию/операцию, пустые и pending/recovery состояния; соответствие реальной установке Balance/остальных owners; отказ POST в реестр и отсутствие миграций/выдачи на GET. Подтвердить readback ledger и installed acceptance отдельно. Подсчёт outbox использует существующий JSON payload без нового индекса; SQL profiling и installed performance в этой задаче не выполнялись.

Публикация остаётся отключённой. К следующей задаче этапа 11 переход не выполняется.
