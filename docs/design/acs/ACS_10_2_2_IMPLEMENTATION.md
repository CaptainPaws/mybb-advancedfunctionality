# ACS-10.2.2 — Loot Allocation: GM Assign, Free Claim и Need/Greed

## Реализовано и база

База — `main` 45945810, merge ACS-10.2.1 (#1068). Требования A1.34, A1.36,
A1.38. Прочитаны ACS Master Specification, Technical Architecture,
Implementation Roadmap, Integration Contracts, KB Mechanics Contract и отчёты
ACS-10.2.1, ACS-10.1.3, ACS-7.3.3.

Один Backend PR: allocation уже сгенерированных entries, без Inventory issue.
`docs/ROADMAP.md` не изменён. ACS-10.2.3 не начат. Нет grant/checkout,
EXP/currency, owner ledger, outbox consumer или нового Resolver.

## Схема

Актуальный ledger — 024_loot_pools. Claims table отсутствовала. Добавлена
следующая resumable migration **025_loot_claims**, создающая только
`af_acs_loot_claims` архитектурной формы: entry/group, claim, claim_version,
closed_at и UNIQUE(entry,control_group). Historical migrations/checksums сохранены.
Нового дублирующего реестра заявок/распределения нет; ALTER pools/entries не нужен.

Generation JSON/hash, item snapshots, qty и RNG предыдущего этапа неизменяемы.
Allocation меняет только существующие mutable entry columns: status,
recipient_group_id, recipient_uid, allocation_version; pool status/pool_version.
Immutable allocation receipts находятся в существующих operations/events/details.

## Режим и правила KB

[KB Mechanics Contract](../../contracts/ACS_KB_MECHANICS_CONTRACT.md#acs-1022--loot-allocation-policy-v1)
дополнен optional `loot_source.allocation`, закрепляемым прежним source/table
snapshot до боя. Default для старых и новых sources без policy — gm_assign,
добыча/победитель gm_only. Self-service требует явной KB policy с eligible
visibility. Ни клиент, ни новая анкета участника после исхода не меняют policy.

| Режим | Подтверждённое поведение |
| --- | --- |
| gm_assign | Текущий native/session GM назначает eligible control_group; UID берётся из frozen/current group owner |
| free_claim | Атомарная заявка допустимого владельца сразу резервирует весь entry/qty; второй получатель запрещён |
| need_greed | Одна редактируемая claim row на группу до GM close; need выше greed; pass только при allow_pass |

Среди нескольких top claimants — одна uniform53-v1 жеребьёвка по численно
упорядоченным group IDs. При одном top claimant RNG нет. Ключ сохранённого roll —
`loot-allocation:<entry>:<closed_allocation_version>`. Luck не используется.
Пустой/all-pass набор закрывается без winner как allocation_closed и сохраняется
как outstanding obligation; автоматического GM fallback/нового roll нет.

Единица участия — frozen eligible group из ACS-10.2.1. Damage contribution не
добавлен. Self-service требует совпадения authenticated actor с historical owner
и нынешним owner той же группы, текущей membership role и native thread access.
Combat controller, temporary overlay, delegate или pet/summon не получают отдельную
долю/claim. Новый group/member после завершения не получает старую eligibility.
Группы без установленного recipient owner или с изменившимся owner блокируются.

## Command boundary, CAS и reservation

LootAllocationService использует прежний SessionRepository::command, существующий
session lock, CAS, source-level replay, receipts и append-only event details.
Новых coordinator/transaction/owner gateways нет. Source pool проверяется прежним
lootReplay/confirmedOutcome и pinned policy, без latest KB.

Все изменения требуют POST/CSRF, operation_key, reason и expected session version.
Дополнительно required expected_allocation_version и для claims
expected_claim_version (0 — первая заявка). Claim/GM/native права проверяются
повторно под lock, также перед возвратом прежнего receipt. У GM нет global ACP
bypass или права объявить client winner/recipient_uid доказательством.

Claim edit использует claim_version CAS и closed_at IS NULL. Закрытие entry
использует status=generated + allocation_version CAS, фиксирует closed_at всех
claims и увеличивает allocation_version. Pool version меняется атомарно.
Session/encounter audit sequences и версии продвигаются существующим механизмом;
completed status и вся боевая механика не возобновляются.

Winner reservation — status **awaiting_issue**, recipient_group/UID и immutable
allocation receipt с allocation identity/version, mode, закрытыми claims,
contenders, roll/sample/ref и event ref. Это не Inventory ownership или issued.
Чтение проверяет operation/event hashes, закрытые claims, winner/version и
сохранённый lottery result; RNG на чтении/replay не вызывается.

Поздний audit fault откатывает claim/entry/pool/roll/CAS целиком. При потерянном
COMMIT ACK повтор исходного ключа возвращает прежний receipt. Новый ключ close
либо то же назначение того же winner также возвращает прежний receipt; другое
назначение закрытого entry запрещено. Ordinary allocation не является reallocation.
Отдельная correction/reallocation command в этом ограниченном PR не реализована.

В pending_close/closed/archived allocation доступен как отдельный reward-obligation
scope, с теми же native/session/ownership gates. Pool statuses open,
awaiting_issue, allocation_closed остаются pending в SessionObligationsQuery.
Future issue failure не освобождает winner и не открывает claims автоматически.
Generation hash/result остаются прежними и после allocation/reopen/archive.

## Backend API и минимальный view

Через существующий AF transport:

| op | Метод и вход |
| --- | --- |
| loot_allocation_view | GET: session_id, encounter_id |
| loot_gm_assign | POST: общие поля + control_group_id |
| loot_free_claim | POST: общие поля + control_group_id, expected_claim_version |
| loot_claim | POST: общие поля + control_group_id, expected_claim_version, claim |
| loot_allocation_close | POST: общие поля, без client winner |

Общие POST fields: session_id, encounter_id, entry_id, expected_version,
expected_allocation_version, operation_key, reason; native my_post_key проверяется
SessionAccess. Неизвестные поля и client actor/recipient/roll/mode не принимаются.

Новый небольшой server-rendered `misc.php?action=acs_loot` view доступен по ссылкам
Builder и GM panel, а также лёгкой ссылке сессии в теме. Нужны session_id и
encounter_id; без encounter selection отображается только форма ввода ID.
Показываются разрешённая добыча, qty, allocation state, собственная claim,
разрешённый winner и допустимые формы. Скрытые entries не дают placeholders,
IDs, quantities, raw pins, other claims или samples игроку. Winner visibility —
gm_only/eligible/recipient. Write receipts фильтруются так же, как read DTO.
Unknown и hidden entry commands для non-GM имеют одинаковый отказ eligibility.

GM видит eligible groups и audit; player — только собственные group claims.
HTML экранирован; формы POST/CSRF/version/key/reason. Обычная страница темы не
загружает allocation stack, пока пользователь явно не открыл view. JS не менялся;
нового polling, editor или интерфейса выдачи этапа 11 нет. Backend read ограничен
архитектурными 16 pools × 128 entries на encounter; действует прежний JSON limit.

## Проверки

PHP 8.4.27, 64-bit mysqli/mysqlnd + mbstring, disposable MySQL 8.0.46.
PHP 8.5.12 и installed browser/MyBB route отдельно NOT_VERIFIED. Production DB
не подключалась. Полный test suite не запускался.

```text
php -l <все новые/изменённые PHP-файлы>
git diff --check
php tests/acs_loot_allocation_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_loot_sql.php /tmp/acs222-socket/mysql8.sock
php tests/acs_session_lifecycle_sql.php /tmp/acs222-socket/mysql8.sock
```

`node --check` — не применяется: изменённых JS нет. Минимальный UI проверен через
server DTO/HTML, без заявления визуальной/installed приёмки.

Allocation fixture использует реальный Resolver → confirmed outcome → generation.
Две дополнительные группы с exact registration-shaped snapshots seeded SQL до
end boundary в withdrawn state, явно разрешённом KB eligibility. Участники не
получают новых turns/AP и не обязаны действовать. Это не runtime join/backfill.
Существующий generation fixture получил только optional setup/continuation hooks;
его обычный запуск сохранён. Между setup и allocation fixture ages operations'
throttle metadata на 120 секунд, без изменения runtime rate limit или sleep.

PASS: GM assign; wrong eligibility; stale allocation version; free claim race
двумя реальными SQL handles/разными authenticated actors; need beats greed;
equal need tie; claim edit/CAS before close; pass; denied edit after close;
duplicate/source allocation; lost ACK/no new roll; late group/member и pet/group
dedupe; native/GM/CSRF и expired membership; hidden DTO/HTML и winner visibility;
rollback, включая уже записанный tie roll; awaiting_issue reservation после
close/archive; прежний generation hash, snapshots, rolls и Inventory unchanged.

Future issuer failure проверяется только seeded entry status в fixture: winner,
allocation receipt/version и sealed tie остаются прежними. Реальный issue executor
не добавлен и не заявляется проверенным. Повтор миграции на заполненном archive
сохраняет claims и reservations. Generation и session lifecycle fixtures прошли
отдельно как точечные регрессии.
