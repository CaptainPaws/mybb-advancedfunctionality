# ACS-9.2.2 — Start coordinator foundation (INCOMPLETE)

База: main `956e15a5` (ACS-9.2.1). **ACS-9.2.2 не завершён. Этот draft
не реализует платную pre-round подготовку или стартовую атаку из засады.**
Его нельзя принимать как выполнение A1.77 целиком.

Основания: A1.47–A1.49, A1.75–A1.77 в ACS_MASTER_SPECIFICATION,
ACS_TECHNICAL_ARCHITECTURE, ACS_IMPLEMENTATION_ROADMAP,
ACS_INTEGRATION_CONTRACTS; отчёты 9.1.2, 9.2.1, 7.2.2, 8.3.3.
Исторические отчёты, миграции 001–021 и docs/ROADMAP.md не изменены.
Нового раундового движка, Inventory protocol или Resolver нет.

## Implemented

Добавлен внутренний GM-only AmbushStartService с командами ready/start.
Это backend API; HTTP routing/кнопки GM-панели в этом draft **не подключены**.
Авторизация использует EncounterService.context: текущая активная сессия,
реальные persisted GM/owner roles и native thread access. POST/CSRF проверяются
до чтения команды. Client AP/owner/trigger параметры не принимаются.

ready проверяет существующую readiness и отсутствие незавершённых обязательств,
фиксирует ambush_ready через прежний audited SessionRepository.command с CAS.
Реестр участников и конфигурация после этого закрыты для обычных preparation
команд. Ready ничего не платит, не запускает Clock и не создаёт раунд.

Поддержан только **пустой старт**, без active prepared actions и без prepared
recovery_required. Любой такой контракт блокирует ready/start с
ACS_AMBUSH_EXECUTION_BLOCKED: combat.primary не переименовывается в
encounter.start и неподдержанная подготовка не пропускается молча.
Терминальная историческая подготовка сама по себе не исполняется снова.

start_operation_id = ambush:SESSION:OPERATION_KEY. Максимальная длина укладывается
в существующий encounters.start_operation_id varchar(96). Identity сохраняется
в existing operations с kind=encounter.start_intent, status=prepared, точным
request hash и mechanics_confirmed=false. Этот отдельный metadata commit
не выполняет игровых изменений, RNG, Clock или owner calls. Encounters.start_once
и operations PK — прежние durable UNIQUE. Encounters.start_operation_id закрепляет
один intent; другой ключ после interruption запрещён.

Затем прежняя ACS command transaction выполняет openFirstRound/openRoundProjection,
AP refresh, deadlines, обычный roster и event/audit. В той же InnoDB transaction
intent получает committed, а command — terminal receipt. Исключение откатывает
весь раунд и механические изменения, оставляя только ранее закреплённый intent.
Повтор точного request возвращает прежний command outcome. Изменённый payload
с тем же ключом запрещён. После потерянного ACK вызывается тот же start, без
новой identity; наличие старого durable intent не означает успешную механику.
Отдельного MyBB transaction/post/submission нет.

В outcome перечислены encounter_start → ambush_resolution → active. Для пустого
старта resolution пустое; это **не свидетельство исполнения prepared actions**.
Отдельных промежуточных игровых ходов/раундов эти записи не создают. Единственный
первый раунд строит прежний group roster; дополнительных AP или surprise bonus
координатор не добавляет. Обычный round_start сохраняет прежний путь.

Clock.availableAP теперь вычисляет budget − commitment − spent, запрещает
отрицательные значения и spent > budget − commitment без опасного сложения
целых. Поддержка этой арифметики **не означает**, что durable commitments уже
формируются: в существующей round projection commitment пока остаётся нулём.

## Locally verified

PHP 8.5.12 / disposable MySQL 8.0.46, authoritative mysqli writer существующего
acs_combat_items_sql harness. Socket внутри контейнеров:
/tmp/acs222-socket/mysql8.sock. Случайные fixture базы удаляются в finally;
production не подключалась. Native fixture post 501 не публикуется Gateway.

acs_ambush_start_sql.php проверяет:

- POST/CSRF, отказ outsider и client-reported commitment;
- preparation → ambush_ready и точный replay;
- явный отказ active prepared contract;
- отсутствие раунда на ready;
- позднюю SQL ошибку event_details: нет частичного раунда, intent сохранён;
- запрет другого start key после interruption;
- реальный metadata COMMIT с потерянным ACK и последующий retry;
- один новый обычный roster, неизменное количество control groups;
- duplicate start без второго раунда, AP refresh, RNG, submission или post link;
- отсутствие universal surprise бонуса и автоматического закрытия victim turn;
- только арифметику 2 AP → 1, 3 AP → 0, overcommit и отрицательные значения.

Последние AP проверки являются domain assertions внутри SQL fixture, **не**
сквозным подтверждением pre-round оплаты. Pet/independent NPC/hidden disclosure
новым SQL fixture не проверены. Нельзя записывать их в PASS по факту наличия
существующей roster/awareness реализации.

Первоначальная попытка использовать старый acs_encounters_regression harness
остановилась в EntityService до нового кода: ACS_AUTHORITATIVE_CONNECTION_REQUIRED
(SnapshotReadOnlyDb не привязан к SQL writer). Старый fixture не переписывался;
новый тест использует действующий authoritative Stage-6 harness.

## Remaining BLOCKED / not implemented

- Реальное создание и подтверждение pre-round prepared actions через существующие
  Draft/Seal/PreparedActionService. Сейчас они требуют active encounter и pending
  started EntityTurn, а публикация закрывает обычный ход. Нельзя оплачивать такую
  подготовку обычным ходом и затем переоткрывать его или давать бесплатный раунд.
- Durable group commitments с доказательством конкретной confirmed подготовки,
  их включение в первый immutable roster/AP refresh и отказ overcommit.
- Завершение групп с нулевым остатком через RoundManagement и обязательные
  EntityTurn boundaries ровно один раз. Новый координатор пока получает только
  нулевые commitments, поэтому такой путь не реализован.
- Типизированный encounter-start trigger, его source-event provenance и общий
  Resolver execution без фиктивной combat submission/post. Существующий executor
  поддерживает combat.primary; start execution в него ещё не интегрирован.
- Fixed causal rolls, start-time resource/item consumption, owner authorization
  и same-writer receipt proof для start. Прежний prepared owner contract проверяет
  настоящую parent combat submission; его нельзя обходить GM impersonation.
- Стартовое reveal/awareness audience через existing hidden prepared policy.
  Пустой start не изменяет awareness и не раскрывает сцену автоматически.
- GM-panel preparation/ready/start/resolution/outcome UI, AP commitment/остаток;
  сейчас новый сервис доступен только внутренним callers/test, не через HTTP.
- Сквозные 2/3 AP подготовки, exhausted group, pet/NPC budgets при commitment,
  concurrent launch, lost terminal ACK и полное start recovery. Metadata ACK и
  late rollback выше не подменяют эти отдельные проверки.

Это работа, оставшаяся внутри ACS-9.2.2, а не завершённые возможности и не запрос
на обход owner policy. ACS-9.3.1 не начат.

## Installed NOT_VERIFIED

warprift.ru, реальные KB scenario/start contracts, установленный MyBB GM UI,
Inventory owner grants и production recovery не проверялись. Publication gates
не включены. Полный suite/browser E2E не запускались. JavaScript не менялся.

## Validation result

- `acs_ambush_start_sql.php` — PASS (ограниченная матрица выше), включая отказ
  изменённого payload и нового pending owner obligation между arm и finish.
  Guard исключает только собственный проверенный start intent, а не чужие
  prepared/recovery операции.
- `acs_combat_items_sql.php` — PASS: обычные reserve/swap/consume, rollback,
  duplicate/lookup, AP и permanent ownership.
- `acs_prepared_actions_sql.php` — PASS: обычная платная подготовка, общий AP,
  repeat, closed turn, stale authorization, migration resume, rollback и expiry.
- `php -l` всех семи изменённых/новых PHP — PASS (PHP 8.5.12).
- `git diff --check` — PASS. Изменённых JS нет; `node --check` не применим.

Эти PASS не закрывают перечисленные missing integration cases.
