# ACS-7.1.1 — NPC controller modes

База: `main` `72ed1584` (ACS-6.3.2, PR #1036). Ограниченный PR по A1.41, A1.42, A1.47, A1.49, A1.69. `docs/ROADMAP.md`, нормативный baseline и Inventory transfer/grant не изменяются. ACS-7.1.2 не начинается.

## Реальная схема

DDL, индексы и миграции **не изменены**; актуальная схема остаётся `014_custody`. Проверены migrations 002/003/004/006/007/008/009 и Repository registry. Используются существующие `controllers`, `entities`, `control_groups`, `session_roles`, `participants`, `turns`, `entity_turns`, `operations`, `events`, `event_details`. `delegations` есть в проектном DDL Architecture, но отсутствует в установленном registry 001–014; таблица и делегирование этим PR не добавляются.

Одна generation назначения — активные строки `controllers` одного session/entity с одинаковыми `mode` и `assignment_version`:

| Режим | Представление / допуск |
| --- | --- |
| exclusive | Одна строка с назначенным UID; действовать может только он при текущих правах |
| shared | По строке на каждый назначенный UID, общая assignment_version; текущая persisted роль owner/gm обязательна |
| gm_pool | Одна строка с NULL controller_uid; допуск получает текущий persisted owner/gm этой сессии с native доступом |

Снять всех в exclusive/shared можно пустым списком: остаётся одна NULL-строка как версия **неназначенного** NPC. Она не выдаёт право действовать и блокирует readiness до нового назначения. NULL в gm_pool имеет другое, явно проверяемое значение. Смешанные режимы/версии, повторяющиеся UID и некорректные интервалы закрывают допуск.

Замена целого списка переводит прежние строки в superseded с valid_until, вставляет новую generation с версией +1. Старые migration checksums, snapshots и история назначений сохраняются. Ограничение до 100 назначенных UID — технический предел одного запроса, не новый игровой бюджет.

## Команды и права

Добавлена `EncounterService::command('controller_set', input)` через существующий AF endpoint `op=encounter_controller_set` и POST существующей GM-панели. Поля: session_id, encounter_id, entity_id, expected_version сессии, encounter_version, assignment_version, mode, uids (список либо строка UID через запятую), operation_key, reason; штатный my_post_key проверяется SessionAccess. Actor берётся из authenticated MyBB, не из input. Назначение действует **в сессии**; указанный encounter служит проверяемым контекстом зарегистрированного участника и аудита, не создаёт параллельного encounter-specific бюджета.

Команда доступна в preparation/active/paused активной сессии только persisted owner/gm с native доступом к связанной теме. Ссылки entity/participant/encounter проверяются на принадлежность и отсутствие другого активного encounter. Назначаемые пользователи должны существовать, иметь актуальную owner/gm роль и native доступ. Персонажи и постоянные питомцы исключены. Административный просмотр ACP сам по себе не предоставляет игрового управления или права назначать NPC.

Сохраняется прежний preparation-only `controller_assign` для совместимости. Он использует ту же замену generation и прежние session/encounter expected versions/CAS; новая панель использует controller_set с явной assignment_version.

Общие проверки расположены в AuthorizationService (`assignment`, `sessionGm`, `controller`) и используются Draft, Query/Builder, Clock/AP, Reaction и CombatCommitPersistence. Персонаж/питомец по-прежнему требует своего owner и exclusive assignment. GM-права, обычный контроль, назначение NPC и critical permissions разделены: `control.transfer`, correction/reward/persistent/reopen остаются RESERVED_DENY. Глобальная GM-группа, assistant_gm, observer/player и чужой session scope не дают gm_pool. Inventory owner/spending access не расширен.

Все замены выполняются в прежней ACS transaction/advisory/session/encounter boundary: current authorization → session/encounter/assignment CAS → строки назначения → append-only operation/event/details → версии. В audit сохраняются actor, reason, entity, прежние/новые mode, UID и assignment_version. Повтор committed key возвращает прежний результат после повторной проверки доступа; сбой аудита откатывает назначение.

## Общий бюджет, параллельные планы и commit

Изменение назначения не пишет entities/states/resources/groups/turns/entity_turns/cooldowns/statuses и не пересоздаёт KB snapshot. Сохраняется прежний combat_operations_enabled gate; назначение не включает production publication. Inventory spending permissions новой generation не выдаются.

JOIN для списка существ, participants и roster выбирает одну representative строку назначения, поэтому shared не размножает участников, AP-группы и EntityTurn. GM DTO дополнительно содержит controller_mode и полный controller_uids. Независимые монстры по-прежнему регистрируются отдельными identity/snapshot/group и получают один обычный ход каждый; поздний вход использует прежний next-round roster.

Для авторизованных NPC допускаются параллельные speculative drafts/seals на одном turn. Это также позволяет новому контроллеру подготовить план при сохранённом старом sealed draft после reassignment. Исключение не применяется к персонажам/питомцам, round management или неизвестным внешним операциям. Competing item seals остаются барьером: их owner reservations не освобождаются этим PR.

- Seal quota остаётся SUM всех sealed_actions одного turn, включая cancelled историю, независимо от автора.
- Query добавляет actor_controller_version в зависимости confirmation. Даже повторное назначение того же UID делает старое confirmation неактуальным. Переподтверждение использует исходный tape.
- Publication сохраняет прежний один active_turn_id claim и session-wide unresolved barrier. Второй GM может подготовить и подтвердить план, но не получить второй native publication claim. Claims/UNIQUE и terminal receipt протокол не заменены.
- Combat writer повторно проверяет current controller/role и состояние участников под locks; assignment rows/session_roles уже входят в SQL facts fence. Изменение назначения между preparation и BEGIN отклоняет commit до механических изменений.
- Успешный terminal commit атомарно переводит остальные обычные draft/sealed планы этого NPC turn в cancelled, повышает draft_version и снимает confirmation. Проза, mechanics/dependencies, sealed payload/hash, snapshots и rolls сохраняются; quota не возвращается. Список `superseded_npc_drafts` включён в подтверждённые changes/audit победителя. Это не исполняет проигравшие действия и не оставляет их блокировкой следующего раунда. Publishing/unknown операции так не отменяются.
- Сохранённые reactions привязаны к UID регистратора и generation; смена назначения инвалидирует их будущий допуск, но не сбрасывает reaction_used и не исполняет реакцию автоматически.

## Минимальный интерфейс

Во вкладке «Участники» видны mode и назначенные UID. Во вкладке «Управление» у каждого NPC есть текущий режим, полный редактируемый список UID, assignment_version, причина и штатные POST/CSRF/session/encounter версии. Пустой список снимает назначения; для gm_pool список должен быть пуст. Вывод экранирован, GET только читает. Новые assets, editor, frontend framework и глобальный редизайн не добавлены.

## Проверки

Фактическая среда целевых проверок: **PHP 8.5.12 / MySQL 8.0.46**, локальный minimal MySQL runtime, socket `/tmp/acs222-socket/mysql8.sock`; случайные disposable DB удаляются existing fixture в finally. MyBB tables fixture остаются MyISAM. Production DB не подключалась.

| Проверка | Результат |
| --- | --- |
| `php -l` всех затронутых PHP и нового fixture; `git diff --check` | PASS |
| `tests/acs_npc_controllers_sql.php SOCKET` | PASS: exclusive deny другому GM; shared 2 GM; pool owner/gm и отказ player/assistant/expired/чужому UID; native topic/posting deny; CSRF/GET; scope/CAS; remove/add/reassign; actor/reason/before-after audit; retry; rollback позднего сбоя audit |
| Independent NPC | PASS: реальная registration двух экземпляров, отдельные группы, реальный round_start даёт 2 turns/2 EntityTurns; shared JOIN не дублирует roster/list; live KB mutation не меняет pin |
| Mechanics / concurrency | PASS: HP 80 не восстанавливается; AP spent, reaction_used, seal quota и остальные mechanical rows не меняются назначением; два draft/seal/confirm используют общую quota; два SQL handles с разными GM конкурируют за publication/ACS lock; один COMMITTED/AP spend; losing prose/tape retained read-only; assignment race в preparation/BEGIN gap отклонена; gm_pool проходит реальный Seal/Writer; resolved turn не открывается reassignment |
| GM UI | PASS: actual server renderer, текущий mode/CAS fields, escaping, GET без SQL mutations |
| `tests/acs_combat_items_sql.php SOCKET` | PASS: прежние item preview/seal/reserve/commit, rollback/retry/AP и permanent ownership |
| `tests/acs_inventory_outage_sql.php SOCKET` | PASS: ordinary combat при outage, rollback, duplicate/recovery и retained projections |
| numeric/formula + `kb_repeaters_dom.cjs` | PASS: существующие небольшие readiness checks |

Точный запуск SQL: `docker exec acs711-php php /repo/tests/acs_npc_controllers_sql.php /tmp/acs222-socket/mysql8.sock` (так же combat_items/outage). Fixture переиспользует `acs_combat_items_sql.php` / InventoryFixtureDatabase, без нового DB framework. Для боевых проверок используется явная reference-only normalized source fixture: legacy inline bestiary defaults не объявляются поддерживаемыми способностями и не меняются ради controller теста. Pet deny проверен общей owner-политикой; реального pet provider по-прежнему нет.

Старый `acs_encounters_regression.php` через `acs_entities_regression.php` в исходном виде останавливается на `ACS_AUTHORITATIVE_CONNECTION_REQUIRED` после этапа 6: его read-only stand-in не является текущим writer. На локальной MariaDB новый Inventory fixture также останавливается на точном MySQL type contract (`INV_SCHEMA_NOT_READY`). Эти ограничения не маскировались правками owner protocol; целевые сценарии выполнены актуальным authoritative fixture на MySQL 8.0.46. Полный suite и установленный MyBB/browser E2E не запускались.

## Ограничения и установка

Делегирование персонажей/питомцев, forced control, spawn, KB inline-profile compatibility, массовая регистрация и ACS-7.1.2 вне этого PR. Unknown/pending publication по-прежнему блокирует смену назначения до существующего recovery. Некорректные/неоднозначные вручную изменённые assignment rows закрывают допуск; отдельного repair/bypass endpoint нет. Новые права на чужое имущество и опасные административные операции не предоставлены.

Достаточно обновления файлов поверх актуальной `014_custody`; новых DDL или автоматической пересборки snapshots нет. Расширение dependency hash требует актуальной сводки/повторного confirm прежних sealed drafts с тем же tape. Уже frozen submission со старым hash может потребовать прежнего recovery; перед развёртыванием следует разрешить pending publication, без blind retry и нового MyBB post. Прежние publication test-only/installed acceptance gates сохраняются. На staging проверить GM-панель в существующем ATF thread context и native MyBB permissions. Для отката отключить ACS и сохранить БД; старый exclusive-only код нельзя использовать с активными shared/gm_pool назначениями без согласованного возврата назначений и проверки сохранённых drafts. Uninstall не является откатом.
