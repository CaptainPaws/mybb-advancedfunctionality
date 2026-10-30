# ACS-2.2.2 — существа и immutable snapshots

Исходная main: `0477971f202bca7040aaa24bf31cde03bd7c23e8` (merge ACS-2.2.1, PR #987).
Версия аддона: `0.5.0`. Схема после явного upgrade: `002_entities`.
Design Baseline, четыре проектных документа, CharacterSheets, KB, Inventory, ATF и ядро MyBB не изменены.

## Поставка

Изменённые существующие файлы в `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

- `advancedcombatsystem.php`: lazy factory сервиса существ, существующий AF AJAX dispatch, безопасные ошибки регистрации.
- `manifest.php`: версия и актуальное описание реализованных возможностей.
- `modules/Administration/Migrations.php`: последовательный реестр 001/002, readback double/tinyint.
- `modules/Application/AuthorizationService.php`: отдельные разрешения регистрации, без разрешения боевых действий.
- `modules/Application/SessionService.php`: серверный флаг разрешения регистрации собственного существа.
- `modules/Infrastructure/Repository.php`: новые разрешённые таблицы, readiness схемы, защищённый общий insert-only примитив snapshot.
- `modules/Infrastructure/SessionRepository.php`: включение результата регистрации и snapshot refs в атомарный audit/result.
- `modules/Infrastructure/SnapshotStore.php`: тот же insert-only примитив с прежней транзакционной границей публичного append.
- `modules/Presentation/Sessions.php`: подключение формы и обработка явных POST регистрации/проверки.

Новые файлы в том же корне:

- `migrations/002_entities.php`.
- `modules/Application/EntityService.php`.
- `modules/Infrastructure/EntityRepository.php`.
- `modules/Infrastructure/Adapters/RegistrationSource.php`.
- `modules/Infrastructure/Adapters/PermanentPetSource.php`.
- `modules/Presentation/Entities.php`.

Проверки: новый `tests/acs_entities_regression.php`; в существующем `tests/acs_sessions_regression.php` расширен маленький stub БД для списка источников. Этот отчёт — единственный изменённый документ.

## Хранение

Миграция `002_entities` добавляет только проектные таблицы Architecture §5:

| Таблица без TABLE_PREFIX | PK | Дополнительные индексы |
|---|---|---|
| af_acs_control_groups | id | session_owner(session_id,owner_uid) |
| af_acs_entities | id | UNIQUE spawn_once(spawn_key); session_group(session_id,control_group_id); owner_sheet(owner_uid,sheet_id) |
| af_acs_entity_states | entity_id | life(life_state,entity_id) |
| af_acs_resources | id | UNIQUE entity_resource(entity_id,resource_key) |
| af_acs_controllers | id | entity_active(entity_id,status); user_control(controller_uid,status,session_id) |

DDL совпадает с Architecture, включая nullable ссылки, типы, PK/UNIQUE/KEY. Как в 001: InnoDB, utf8mb4_unicode_ci, ROW_FORMAT=DYNAMIC, реальный TABLE_PREFIX, без физических FK. Миграция 001 и её checksum не изменены. Существующие sessions/roles/snapshots/operations/events/event_details используются повторно.

Данные экземпляра разделены: source и pinned template snapshot; owner_uid; controller assignment; control_group; life_state; HP/resources; entity/state/resource/assignment versions. Сторон и столкновений ещё нет: participation в registration metadata = null, таблица encounter participants не создаётся. Группа — identity будущего общего бюджета, без AP и ходов. Для самостоятельных NPC/монстров owner_uid = NULL, начальный exclusive controller — авторизованный регистрирующий мастер/администратор. Для персонажа owner/controller — подтверждённый владелец. Повторная регистрация того же листа в той же сессии отдельным ключом отклоняется.

## API и права

Серверная фабрика `af_advancedcombatsystem_entities()` загружается только по явному запросу. `AF_ACS_EntityService` предоставляет:

- `choices(session_id, kind, after)` — ограниченный список кандидатов из существующего источника. Каждый выбор повторно проверяется owner API; наличие в списке не даёт разрешение регистрации.
- `validate(session_id, input)` — POST + CSRF, проверка без записи, ready/diagnostics.
- `register(input)` — POST + CSRF, operation_key, expected_version, reason, kind, source_key, необязательное имя и initial_hp.
- `list(session_id, after)` — максимум 100 строк, без полного snapshot; для обычного участника SQL фильтрует только его собственные существа.
- `snapshot(session_id, entity_id)` — сохранённый payload после проверки сессии, ownership/management и content_hash; live KB/CS не перечитываются.

Существующий AF транспорт `xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem` принимает `op=entity_list`, `entity_snapshot`, `entity_validate`, `entity_register`. Он сохраняет штатный envelope и no-store. Форма находится в ACP ACS → Сессии → выбранная сессия и на существующей странице `misc.php?action=acs_sessions&session_id=…`; отдельного endpoint/framework/assets нет.

| Операция | Проверки |
|---|---|
| Собственный персонаж/будущий питомец | Активная сессия; актуальная membership owner/gm/assistant_gm/player; native MyBB topic access; отдельное подтверждение источником owner UID = текущий пользователь |
| NPC/монстр | Активная сессия; owner/gm либо штатный ACP AF administrator; native topic access; существующий доступный шаблон KB |
| Полный snapshot персонажа/питомца | Только его owner, даже если другой пользователь — GM или ACP admin |
| Полный snapshot NPC/монстра | Только управляющий сессией |
| Боевые действия/смена контроля/делегирование | Не реализованы, существующий deny сохранён |

Проверка роли сама по себе не заменяет проверку источника. Состояния, контроллер, группы и owner не принимаются из клиентского payload.

## Snapshot и идемпотентность

Одна существующая ACS транзакция/lock содержит: проверку прав и CAS; read-only owner API; snapshot; group/entity/state/resource/controller; operation; append-only event/details; увеличение session_version и sequence; committed result. Любая ошибка откатывает весь набор. Внешние системы не изменяются. Существующие storage SQL используют bound parameters.

Operation hash включает исходное намерение, а не меняющийся live DTO. Повтор committed запроса перепроверяет текущий доступ и возвращает прежние IDs/hash/event/version **до** обращения к KB/CS. Другой payload с тем же ключом запрещён. Два разных ключа для монстров создают независимые экземпляры, snapshots, состояния и группы. Результат и audit содержат entity_id, snapshot_id, snapshot_hash и control_group_id.

Payload содержит `schema_version=1`, `IC-ACS.entity-registration.v1`, numeric_policy `binary64-half_up-v1`, engine `acs-registration-1`, полный owner DTO, зафиксированные referenced ability definitions, версии/fingerprints/provenance, equipment breakdown и начальные ресурсы. Сохраняются 17 RES из единого KB API, wind→air provenance, parent/root направления, explicit damage_element. Регистрирующий сервис не пересчитывает характеристики/экипировку. Политика `already_included_do_not_reapply` сохраняется. У snapshot нет update/delete методов; чтение проверяет SHA-256.

CharacterSheets не владеет текущим HP: его read-only контракт возвращает current=null. Поэтому необходимо **явно** ввести initial_hp в форме; это начальное состояние ACS, не обновление постоянного HP. Если источник предоставляет current, используется оно. Максимум обязан совпадать с характеристикой HP. Дробное HP/небезопасный integer не округляется молча, а блокирует регистрацию согласно целочисленному SQL hp/hp_max. Отрицательные, отсутствующие и невалидные характеристики/RES блокируют только выбранную регистрацию, а не форум.

## Питомцы и ограничения

Заказчик уточнил: системы постоянных питомцев пока нет, она планируется отдельным плагином. Поэтому реальной регистрации питомца сейчас нет; UI сообщает `permanent_pet_source_unavailable`, не предлагает объявлять произвольный шаблон питомцем.

Подготовлен серверный интерфейс `AF_ACS_PermanentPetSource::read/choices`. Будущий плагин должен подтвердить постоянную собственность и вернуть read-only DTO (`schema_version=1`, `contract_id=IC-ACS.permanent-pet-preparation.v1`, persistent/executable=false, identity.source_type=permanent_pet, source_key, integer owner_uid, source_fingerprint и definition_version.fingerprint; fingerprint всего DTO по KB helper), собственные полные характеристики/RES/resources/версии/equipment breakdown. Это новый зарезервированный контракт будущего поставщика, **не объявленная реализованная интеграция**. Интерфейс и его validation являются инфраструктурой подключения; готового внешнего provider нет.

Будущий серверный caller может передать реализацию в `af_advancedcombatsystem_entities($provider)`; публичный запрос не может выбрать PHP-класс или назначить владельца. Pet identity защищена стабильным scoped spawn_key; группа совпадает с группой owner, отдельного AP-бюджета нет. Тест использует явный fake provider только для проверки этого соединения, не создаёт игровую систему питомцев. Встроенная форма до подключения будущего плагина остаётся BLOCKED.

Другие границы:

- KB normalizer не обещает рассчитать произвольные template modifiers/equipment в финальные stats. Неподтверждённые такие поля дают `bestiary_modifier_provenance_unavailable`; результат не угадывается. Дополнительные ресурсы без утверждённой семантики дают `resource_semantics_unverified`.
- Неизвестные версии, неполные Accuracy/RES, конфликт Status Resistance и устаревшие ability pins блокируют конкретный источник. Коды и безопасные пути полей выводятся без SQL/stack/приватного DTO.
- Разрешения на источники и password-protected topics сохраняют ограничения существующих owner API и ACS-2.2.1.
- Технический лимит раскрытия pinned ability graph — 128 различных определений; превышение диагностируется. Существующий storage JSON limit 256 KiB и глубина 32 сохраняются.
- Полная production-интеграция MyBB 1.8.40 / PHP 8.5.0 и будущий pet plugin требуют ручной проверки. Локально PHP 8.4.24, MariaDB 10.11.

## Проверки

Пройдены:

- `php -l` всех изменённых и новых PHP-файлов.
- Сверка DDL пяти таблиц/индексов с Architecture; исходная 001 не изменена.
- `tests/acs_entities_regression.php` на изолированной MariaDB с реальными ACS миграциями/транзакциями/репозиториями и реальными KB/CS adapters поверх write-forbidden owner fixture: корректный snapshot, Accuracy +5 ровно один раз, 17 RES/wind/blood, missing Accuracy без частичных записей, повтор операции, разные ID/snapshots/groups двух монстров, pinned template и referenced ability versions после изменения KB, отказ чужому GM, CSRF/GET, отсутствие внешних записей, серверный HTML picker и GET без записей.
- Повтор миграции и возобновление искусственно прерванной 002 после второго DDL шага — только в disposable DB; сохранение snapshot после deactivate/installation check.
- `tests/acs_sessions_regression.php`: существующие создание/роли/CAS/audit/права/CSRF/defaults, реальная deactivate/activate, сохранение общего ATF connection charset/sql_mode, ACP и ATF slot.
- Lazy bootstrap по обычному запросу остаётся без SessionService/EntityService, новых глобальных assets/queries/hooks нет. `git diff --check` чистый.

Полный test suite, браузерные E2E и нагрузочные проверки не запускались. Production БД не использовалась.

## Обновление и ручная приёмка

1. Сделать резервную копию БД/файлов. Установить файлы PR, затем **явно** ACP ACS → Основные → Обновить / продолжить миграции. Убедиться: `002_entities`, completed, пять новых таблиц. Обычная страница миграции не запускает. После upgrade включение/выключение не пересоздаёт таблицы.
2. Открыть тестовую сессию → Добавить существо → Персонаж. Войти подтверждённым владельцем с допустимой ролью, выбрать свой лист, указать начальное HP и причину. Проверить источник, зарегистрировать. Проверить строки Accuracy/RES и equipment provenance через авторизованный snapshot API/fixture, не ожидая полноценного боевого UI.
3. Под owner/gm выбрать NPC/монстра из бестиария, задать HP, добавить два экземпляра одного шаблона. Проверить разные entity_id/snapshot_id/группы. Неполное определение должно показать причину BLOCKED, без entity/snapshot/operation после ошибки.
4. Изменить тестовый шаблон/способность KB. Старый snapshot и его hash/версии должны остаться прежними. Повторить исходный POST с тем же operation_key — должен вернуться тот же результат, без повторного source read.
5. Войти другим GM: регистрация/полный snapshot чужого персонажа запрещены. Обычный игрок видит только собственные существа, observer не может регистрировать. Проверить CSRF и версию сессии.
6. Питомец: сейчас ожидается сообщение об отсутствующем плагине. Реальную регистрацию и ownership проверить после реализации будущего provider, а не обходить эту блокировку.
7. Перезагрузить страницу, деактивировать/активировать ACS после upgrade: существа, snapshots и роли сохраняются. CharacterSheets/Inventory/build_json/HP не меняются. Открыть обычную тему, быстрый ответ и AdvancedEditor; поведение прежнее.

## Восстановление и откат

При прерывании 002 исправить установленную причину и повторить штатный upgrade с теми же файлами. Runner проверит фактические объекты и продолжит; не удалять созданные таблицы/данные, не подделывать checksum/journal. Несовместимый DDL не исправляется скрытно. После неопределённого ответа регистрации повторять **тот же** ключ и исходный payload; не создавать новый запрос вслепую.

Безопасный операционный откат: **отключить ACS**, сохранив таблицы и снимки. Down migration и удаление истории не реализованы. Старый код 001 не совместим с journal 002: нельзя откатить файлы и снова включить старую версию поверх новой схемы. Полный возврат возможен только согласованным восстановлением предустановочной резервной копии; новые registrations при этом нужно сохранить отдельно. Uninstall не предназначен для такого отката и блокирует неизвестные/существующие обязательства.

ACS-2.2.3 не выполнялась. Столкновения, стороны, раунды, AP, Resolver, призывы, Inventory-операции и Combat UI не добавлены.
