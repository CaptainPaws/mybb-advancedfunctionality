# ACS-10.3.3 — Achievement Progress, Review Corrections и Historical Integrity

База — `main` e2b38b20, merged ACS-10.3.2 (#1072). Требования A1.40, A1.66.
Прочитаны нормативные ACS Master, Architecture, Implementation Roadmap, Integration
Contracts, KB Mechanics Contract и отчёты ACS-10.1.3, ACS-10.3.2, ACS-9.3.4.
`docs/ROADMAP.md` не изменён. ACS-10.4.1 не начат. Full test suite не запускался.

## Реализованный subset

Добавлены AchievementService, CorrectionService, чистый Domain/Achievements и
небольшие persistence traits на прежнем EncounterRepository. Используются прежние
command receipts/events, snapshots, session CAS, OperationStore и RewardService.
Нет второго Resolver, RNG, Balance ledger, owner compensation executor или
профильного/outbox consumer.

Typed KB contract `ic-acs.achievement.v1` закрепляется до старта боя через
achievement_configure. Поддержана строгая condition confirmed_count: primary actors
подтверждённого combat.resolved либо зарегистрированные участники подтверждённого
encounter.outcome_confirm. Формулы kill/damage thresholds, другие события/child
predicates и неподтверждённые owner rewards не исполняются предположениями.
Полный subset описан в [KB Mechanics Contract](../../contracts/ACS_KB_MECHANICS_CONTRACT.md#acs-1033--confirmed-count-achievements-and-derived-correction-review-v1).

one_time выдаёт один ordinal; progressive — один при достижении threshold;
repeatable — каждый новый threshold ordinal. Один source event даёт один вклад
одному subject, независимо от числа его питомцев/действий. Subject actor — ACS entity,
owner — frozen account UID, control_group — существующая group identity (default).
Заведомо unowned/invalid UID не превращается в получателя Balance.

Для opt-in encounters CombatCommitWriter после полного Resolver/causal result
добавляет в новый immutable payload achievement_context: config pin и frozen facts
зарегистрированных участников/групп/владельцев. Он не начисляет achievement и не
вызывает RewardService. EndConditionPersistence использует прежний freeze helper
также для achievements без loot/reward sources. Старые неподписанные источники без
нужных frozen facts не backfill-ятся по сегодняшнему владельцу; earning BLOCKED.

Timing — граница допустимого источника, не новый автоматический observer:
achievement_evaluate обрабатывает конкретный retained confirmed source. Pending
source queue в lifecycle доступна и после close/archive. Preview/seal не создают
прогресс; consumer, profile UI и статистика этапа 10.4 не добавляются.

## Схема и identity

Фактический ledger перед PR — 026_rewards, achievement tables отсутствовали.
Следующая resumable migration **027_achievements_reviews** создаёт:

- af_acs_achievement_progress по Architecture: UNIQUE achievement_key/subject_kind/
  subject_key, current definition pin, progress_version/visibility, JSON/hash;
- af_acs_achievement_events: immutable вклад, source/pin/session refs, result/hash,
  UNIQUE stable progress_key и UNIQUE(progress_id,source_event_id);
- af_acs_correction_reviews: append-only per-source derived revisions,
  UNIQUE(original_event_id,review_version), event refs, result/hash.

Исторические migrations/checksums не изменены, FK cascade/delete нет. Последняя
таблица — review projection/history, не экономический ledger и не полноценная
statistics таблица следующего этапа.

Progress identity не включает KB version. Изменившийся pin блокирует новый вклад
до explicit achievement_migrate. Разрешён только compatible_carry при идентичных
normalized semantics, включая mode/threshold/subject/visibility/reward; перенос
требует CAS, reason, session GM и native moderation permission. Сохраняются pins
и исторические count/earned ordinals. Новый pin не создаёт новую one_time награду.
Несовместимая версия и смена recipient UID не получают reset/bypass; нужны отдельные
будущие identity policies. У одной progress identity хранится до 16 принятых pins.

## Proof источника и выдача

Combat source проверяется по event/details hash, committed submission,
combat_result hash, terminal combat_receipt hash/status, matching submission/event
identity и committed combat.commit operation/receipt. Completed source использует
существующий verified confirmedOutcome и candidate hash. Subject facts/definition
pin берутся только из исходного frozen context, а не текущего roster/KB.
Evaluation command, progress projection, contribution и reward obligation входят
в одну прежнюю ACS transaction; поздний сбой audit откатывает их целиком.
Вклад связан cause_event_id с оригиналом; source replay до CAS не увеличивает
прогресс, не создаёт второй contribution/award. Ограничение — 200 subjects и
128 новых award ordinals за одну обработку; overflow блокируется целиком.

Награды создаются через code-only RewardService::recordAchievementObligation в
существующей af_acs_rewards, а не через прямой Balance add или Inventory INSERT.
reward_key = achievement + subject + earned ordinal, recipient/type UNIQUE;
operation_key и source_ref закреплены как у прежних reward obligations.
Payload содержит source_kind=achievement, immutable contribution ID, ordinal,
confirmed source event, pin/hash и exact minor amount/UID. Confirmation ref явно
achievement-specific, не фиктивный encounter outcome.

Существующие RewardService issue/recovery и IC-BALANCE provider проверяют всю эту
цепочку; CAS dispatch barrier, separate owner COMMIT/ACS ack, unknown lookup и
архивный recovery остаются прежними. Historical earned award не становится
недействительным из-за позднего exclude: pending/issued обязательства не отменяются.
Награды, кроме доказанных experience/currency, в этом KB subset не поддерживаются.

## Visibility

GM backend и player DTO разделены. SQL-фильтр visibility/recipient применяется
**до LIMIT**. hidden_until_unlocked с нулём earned ordinals и gm_only не попадают
в player list, JSON, counts, audit, raw refs или metadata. Нет profile hook или
глобального счётчика, раскрывающего скрытый прогресс.

Player DTO содержит только разрешённые achievement key/name, subject, текущий
review_count, unlocked и earned awards. Нет totals, raw progress IDs, versions,
KB pins, contribution/reward IDs. Player read ограничен 100 видимыми строками;
numeric ID pagination разрешена только GM, чтобы не раскрывать число скрытых
allocated IDs. Публичный полный каталог/pagination UI не реализован этим Backend PR.

Для global owner/subject progress дополнительно проверяются native access и роли
**во всех** contributing sessions (до 200, overflow — omission). GM видит полный
progress только при GM permission во всех этих сессиях. Это не делает чужую
секретную сессию публичной через известный профиль/UID. Те же cross-session GM
проверки выполняются до earning/migration/review/correction и возврата старого
command receipt: replay не раскрывает старый before/after progress утратившему права GM.

## Correction и aggregate review

API correction_event принимает original_event_id, reason, expected session/review
versions, operation_key и типизированный план ic-acs.correction-review.v1.
Помимо session GM требуется отдельное native forum caneditposts. GM без этого
права не может использовать correction/review/definition migration.

Поддержан принимающий capability **acs.derived-review.v1**: include/exclude одного
подтверждённого mechanical source из производного review. Создаются новый
correction.review event с correction_of_event_id и новая immutable review row;
предыдущая revision сохраняется и связывается previous_review_id. Исходные event,
rolls, receipts и подтверждённый winner не редактируются.

Derived aggregate содержит included_event_count, primary_allocated_damage из
исходного plan и outcome inclusion review с сохранённым original outcome.
Это **per-source review revision**, не полный статистический агрегатор, HP damage
calculator или замена официального outcome. Для physical HP/AP/status edits,
свободного назначения победителя и постоянных owner writes capability отсутствует.
Будущий statistics reader должен учитывать explicit review revision отдельно.

Связанный achievement review_count пересчитывается по source inclusions, но
historical count, earned ordinals и immutable contribution rows сохраняются.
Ставится review_required; отдельная achievement_review с CAS/правами/reason
журналирует принятие пересмотра без нового award или owner mutation.

Компенсация none не вызывает владельцев. Для запрошенных balance,
advancedinventory, charactersheets фиксируются compensation_status=blocked и
точная причина отсутствующего compensation owner contract. Positive credit API
не превращён в debit, Inventory transfer/grant — не автоматический clawback,
а CS всё ещё не имеет durable sync gateway. Нет снятия предметов, EXP/валюты,
удаления achievement, нового loot roll или отмены ранее закреплённого recipient.
Blocked compensation сохраняется как explicit lifecycle obligation, даже если
последующая review revision сама не просит компенсацию.

## Backend и lifecycle

Существующий AF transport, без нового UI:

- achievement_status — scoped GET session_id, optional after (GM cursor only);
- achievement_configure — POST session/encounter versions, kb_key, key/reason;
- achievement_evaluate — POST session version, encounter, achievement_key,
  source_event_id, operation_key/reason;
- achievement_migrate — POST progress ID/version, target encounter's pinned
  achievement_key, session version, operation_key/reason;
- achievement_review — POST progress ID/version, encounter/session version,
  operation_key/reason; только derived review acknowledgement;
- correction_event — POST original event, typed plan, session/review versions,
  operation_key/reason; correction_status — privileged read-only GET.

Все writes имеют существующий POST/CSRF/current native-session boundary и audit.
Права перепроверяются перед replay; same key/changed payload конфликтует.
Pending evaluation/review, reward issue/lookup scopes сохраняются после close/archive;
ничего не возвращает archived session в бой и не запускает completed encounter.

## Проверки

PHP 8.4.27, MySQL 8.0.46, 64-bit mysqli/mysqlnd; disposable DB/Unix socket,
DROP DATABASE в finally. Production installed MyBB/schema/permissions, PHP 8.5.12
и profile/Statistics consumers будущих этапов — NOT_VERIFIED. Balance prerequisites
и explicit owner upgrade остаются по ACS-10.3.1; этот PR не запускает production DDL.

```text
php -l <все новые/изменённые PHP-файлы>
php tests/acs_achievements_reviews_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_reward_distribution_sql.php /tmp/acs222-socket/mysql8.sock
git diff --check
```

Новый fixture продолжает прежний узкий real combat → pending → confirmed outcome →
reward/archive harness. Первый combat и owner credits реальные. Для второго
подтверждённого source явно seeded согласованные SQL event/result/receipt/operation
records и frozen context будущего encounter; отдельный временный foreign-scope seed
проверяет запрет terminal replay/view без прав в другой сессии; это fixture, не заявленный второй
native MyBB publish/Resolver прогон. Optional hooks прежнего fixture изолированы.
Throttle timestamps aged между фазами, production rate limit не изменён.

PASS: one_time once; progressive increment; repeatable threshold; duplicate source
и operation replay; group/pet dedupe; actor/owner/group; completed timing; invalid
non-confirmed source; hidden DTO/ID/counter exclusion; changed KB pin blocked,
explicit compatible migration без второго one_time award; actual EXP/currency once;
late achievement rollback; native correction permission; correction append/replay;
aggregate revision chain; late correction rollback; reviewed progress без deletion;
no clawback/reaward; unchanged original event/rolls/snapshots/HP/AP/resources/loot/
Inventory; archive remains archived; repeat migration preserves history.
Regression PASS: standalone ACS-10.3.2 focused reward fixture without achievement
sources, включая owner offline/ACK/recovery/receipt checks. Full suite не запускался.
