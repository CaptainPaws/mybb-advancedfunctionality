# ACS-2.3.1 — Round Roster и групповые ходы

Исходная main: `49589b76ec5c617b0ab39e48d7bbb0c0ce83bab4` (merge PR #989).
Аддон `0.7.0`, схема `004_round_roster`. Design Baseline и четыре проектных документа не изменены.

## Файлы и схема

Новые файлы в `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

- `migrations/004_round_roster.php` — rounds, turns, entity_turns строго по Architecture §5.
- `modules/Domain/Clock.php` — чистые идентификаторы границ, версия Clock, граф состояний Turn и детерминированный hash roster.

Расширены:

- `modules/Application/EncounterService.php`: авторизованный `round_start`, readiness gate, read-only `roundView`, добавление поздних участников.
- `modules/Infrastructure/EncounterRepository.php`: атомарная фиксация первого roster, pending turns/EntityTurn, материализованные чтения.
- `modules/Infrastructure/Repository.php`, `modules/Administration/Migrations.php`: явный registry/readiness 001/002/003/004.
- `modules/Presentation/Encounters.php`: текущий раунд/группы/состояния/существа, кнопка первого запуска, позднее добавление без текущего хода.
- `advancedcombatsystem.php`: существующий AF route для чтения раунда и безопасная классификация ошибок.
- `manifest.php`: версия и актуальное описание.

Новый focused test `tests/acs_round_roster_regression.php`; небольшие изменения shared fixture в `tests/acs_encounters_regression.php` и `tests/acs_entities_regression.php` (актуальная schema version/новый roster readiness/переиспользование fixture). Этот отчёт — единственное изменение документации.

Предыдущие миграции уже приняты, поэтому добавлена **004**, а не изменена 002. DDL/индексы соответствуют Architecture, с прежним явным ROW_FORMAT=DYNAMIC, InnoDB, utf8mb4_unicode_ci, настоящим TABLE_PREFIX, без физических FK:

| Таблица | PK | UNIQUE / KEY |
|---|---|---|
| af_acs_rounds | id | UNIQUE encounter_round(encounter_id,round_number) |
| af_acs_turns | id | UNIQUE group_round(round_id,control_group_id); due_turn(status,deadline_at); group_turns(control_group_id,round_id) |
| af_acs_entity_turns | id | UNIQUE individual_boundary(turn_id,entity_id) |

Существующие control_groups и остальные таблицы не переделаны. Для round используется внутренний status `open`; encounter становится `active` только вместе с успешной фиксацией всех строк.

## Запуск и целостность

`EncounterService::command('round_start', input)` доступен только owner/gm (либо штатному AF ACP administrator в ACP) с native thread access. Использует прежние POST/CSRF, reason, operation_key, session expected_version и encounter_version. В теме дополнительно сверяется session.tid с ATF context/POST. Через штатный AF transport команда называется `encounter_round_start`; read-only запрос — `encounter_round_get` (session_id, encounter_id, after).

Под прежним session/encounter lock и общей SQL transaction:

1. Проверяются preparation, отсутствие первого раунда/start marker, CAS, права, snapshots, стороны/отношения/контроллеры/группы.
2. Формируется roster из существующих `engaged` участников той же сессии. UID не является идентичностью группы.
3. Вставляются round_number=1, один pending turn на control_group и отдельный EntityTurn для каждого существа в зафиксированном составе.
4. Encounter получает active/round_number=1, bounded start marker `encounter:<id>:first-round`; версии и sequences увеличиваются. Этот marker не переполняется длинным пользовательским operation_key.
5. Operation, confirmed event/details и результат коммитятся вместе. Event result содержит round_id, количества, round_roster_hash, clock_version и AP refresh boundary.

Повтор **того же** запроса возвращает тот же результат до повторного построения roster. Другой ключ не создаёт второй первый раунд: status/marker и UNIQUE защищают от повторения. Ошибка откатывает весь набор, включая частичные turns. Нет autocommit по отдельным сущностям.

## Immutable roster

Каждый `turns.roster_json` содержит schema/contract/clock/numeric-policy versions, round/group IDs и group_version, initial AP, pre-round commitment, waiting policy, массив участников: entity/participant IDs и версии, side, owner/controller, assignment/state versions, исходное life_state, имя, pinned template_snapshot_id/content_hash. Он не копирует заново KB/CS stats и не применяет экипировку.

У каждого payload есть fingerprint; итоговый round_roster_hash по всем группам фиксируется в append-only audit. Порядок сериализации — стабильный ID, **не инициатива**. Read-only DTO проверяет версию/hash и соответствие индивидуальных строк roster. Публичного update/delete roster нет; последующие изменения состояния существа, темы, KB или состава не пересобирают эти JSON.

Один turn персонажа и постоянных питомцев получается из сохранённого control_group_id. У самостоятельных монстров разные groups/turns даже при одном GM. Life state и participation не объединяются: roster фиксирует engaged участников и не переводит pending в terminal из-за HP/недееспособности. Это не разрешение совершать действие; проверка способности действовать и корректное завершение обязательств подключаются последующими задачами. Публикация, pass/timeout/forfeit и завершение раунда не имитируются здесь.

Mandatory DDL AP-поля инициализируются утверждённой базой **3**, spent/seal/pre-round commitment=0; deadlines по умолчанию NULL. Это начальный foundation группы, не реализация AP spending/reservations/sealing из 2.3.2. HP/ресурсы не восстанавливаются. Новые раунды/перенос остатка AP не создаются этой задачей.

## Clock и границы

Clock не имеет SQL, таймера, публикации или обработчика эффектов. Устойчивые ключи:

- `round:<round_id>:ap_refresh` — начальная инициализация группы в атомарной операции открытия.
- `entity_turn:<entity_turn_id>:start`.
- `entity_turn:<entity_turn_id>:end`.

Идентичность EntityTurn защищена UNIQUE(turn_id,entity_id). `start_done=0`, `end_done=0`, `reaction_used=0`: обработка ticks/upkeep/cooldowns не объявляется совершённой без соответствующих сервисов. GET панели/редактора/темы не меняет эти флаги или AP и не создаёт boundary events. Clock хранит версию `acs-clock-1`.

`AF_ACS_TurnState` типизирует pending/resolved/passed/forfeited; чистый граф разрешает только исходящие рёбра из pending. Это **не** полномочие закрыть ход. Ни одного endpoint/mutator для resolved/passed/forfeited в PR нет. Закрытый ход не возвращается в pending при лечении, смене HP или просмотре.

Чтобы не игнорировать обязательства S1/A1.4–A1.6, readiness принимает только текущий подтверждённый пустой group config и пустые entity layers. Непустые/неизвестные слои или параметры AP дают `round_boundary_contract_unavailable`: необходимо подключить настоящий обработчик, а не бесплатно пропустить modifiers/ticks/sustained. Определение способности само по себе не считается активным эффектом.

## Позднее добавление и UI

Во время active разрешено только добавление ранее зарегистрированного участника с теми же scope/source/control проверками. Стороны, runtime transfer и произвольные изменения во время боя по-прежнему не открыты. Результат/audit помечает admission=`next_round`.

Поздний участник не добавляется в `roster_json`, turns или entity_turns текущего раунда. Это относится и к новому участнику существующей группы: без реализованного явного KB допуска нет бесплатной немедленной активации. Следующий roster сможет включить его при отдельном разрешённом открытии следующего раунда; такая операция сейчас не предоставляется, поскольку текущие pending turns нельзя закрывать фиктивно.

Вкладка «Раунды» существующей ATF GM-панели показывает round_number, groups, terminal/pending statuses и имена существ из сохранённого roster, индивидуальные IDs. Есть серверный cursor для групп, индивидуальные строки читаются batch. Кнопка первого запуска — только preparation/active session после серверной GM авторизации. Start всегда повторяет readiness под транзакцией, не доверяет старому preview. В «Управлении» active encounter доступна отдельная форма позднего добавления с предупреждением о следующем раунде.

Thread provider, чужие templates, AF Core, MyBB, AdvancedEditor и другие аддоны не изменены. Clock/roster не загружаются на обычной теме: используются прежние lazy ATF route и AF transport. Реального pet-плагина всё ещё нет; связь проверена через явный fake owner provider предыдущих fixture. Ручная регистрация питомца ожидает будущий плагин.

## Проверки

- `php -l` всех затронутых PHP; сверка трёх DDL/PK/UNIQUE/KEY с Architecture; 001/002/003 и четыре baseline-документа неизменны; `git diff --check`.
- `tests/acs_round_roster_regression.php` на disposable MariaDB: один первый раунд, точный retry, отказ другому ключу; character+pet один turn, два независимых монстра; четыре EntityTurn и стабильные boundary IDs; initial 3/0 AP, deadlines off; непустой слой блокирует запуск без частичного round; GET/панель без events/ticks; реальное late entity registration+participant_add без изменений текущего roster; terminal turn не открывается после simulated healing; 004 resume/repeat; сохранение данных после disable/installation check.
- Связанные focused entities/encounter/readonly-source fixtures проходят; sessions regression отдельно подтверждает native permissions/CSRF/CAS, настоящую deactivate/activate, сохранение ATF connection charset/sql_mode, ACP/slot и отсутствие GM stack на обычной теме.

Тестовые изменения hash/status/HP/journal выполняются только в собственной случайной БД fixture, не через production команды. После сценария БД удаляется. Full suite, stress и E2E не запускались. Локально PHP **8.4.24**, MariaDB **10.11**; MyBB 1.8.40/PHP 8.5.0 — ручная приёмка.

## Установка и ручная приёмка

1. Резервная копия; установить файлы; **ACP ACS → Основные → Обновить / продолжить миграции** до `004_round_roster`, completed. Сначала upgrade, затем включение; не заменять upgrade реактивацией AF/ATF.
2. Под owner/gm открыть подготовленное столкновение с персонажем и двумя монстрами; питомец — только после реального provider. Проверить readiness. Открыть «Раунды», указать причину, запустить первый раунд.
3. Проверить active encounter/round 1, один pending ход общей группы owner/pet и отдельные ходы монстров, индивидуальные EntityTurn. Повтор исходного POST должен вернуть тот же round_id/result; новый запуск не создаёт round 2.
4. Перезагрузить страницу несколько раз. IDs, roster, AP-поля и флаги границ должны остаться прежними; никаких тиков/кулдаунов/лечения от просмотра.
5. Зарегистрировать нового монстра в сессии, затем в active GM-панели добавить его в столкновение. В списке участников он есть; в текущем roster/turns/EntityTurn его нет. Текущие группы не получают AP или новый ход.
6. Проверить доступ игрока/постороннего, stale версии и CSRF. Проверить обычную тему, быстрый ответ, редактор и повторное включение ACS после upgrade.

При прерванной 004 устранить причину и повторить штатный upgrade с теми же файлами: runner проверит фактический DDL и продолжит. Не править checksum/journal и не удалять таблицы. При потерянном ответе запуска повторять исходный key/payload, не новый запрос вслепую.

Безопасный операционный откат — отключить ACS и сохранить данные. Down migration нет; старый код 003 нельзя снова включать поверх journal 004. Полный возврат возможен только из согласованной совместимой резервной копии с сохранением новых данных. Uninstall не является откатом.

ACS-2.3.2 не выполнялась. Расход AP, автоматические границы/эффекты, следующие раунды и публикация не добавлены.
