# ACS-10.4.1 — Immutable Combat Archive, Journal и Secure JSON/CSV Export

База — `main` 0340d871, merged ACS-10.3.3 (#1073). A1.62–A1.63.
Использованы нормативные Master Specification, Technical Architecture,
Implementation Roadmap, Integration Contracts, KB Mechanics Contract и отчёты
ACS-10.1.3, ACS-10.3.3, ACS-9.3.4, ACS-4.3.4-R2 Post Lifecycle.
`docs/ROADMAP.md` не изменён. ACS-10.4.2 не начат. Полный test suite не запускался.

## Реализация и источники

ArchiveQuery/ArchiveService читают immutable ACS events/event_details и применяют
одну серверную whitelist-проекцию для JSON, CSV и минимальной lazy HTML page.
Нового Resolver, повторного боя, RNG, ledger, outbox consumer или автоматической
смены lifecycle нет. EffectSummary используется как существующий чистый formatter
сохранённых causal calculations, не как механический engine.

Combat source проверяется прежним TerminalReceiptPersistence: committed submission,
request/sealed/receipt hashes, result hash, post_link и turn identity. Нет JOIN,
требующего существования native post для сохранности механики. Native post читается
только как optional navigation/permission metadata (pid/tid/fid/uid/visible),
**posts.message не выбирается**. Outcome — через verified confirmedOutcome;
correction — через сохранённый linked event и verified latestCorrection.
Остальные durable audit records не объявляются applied damage: показывается их
собственный тип, а private request/result JSON не отправляется клиенту.

Journal включает encounter-scoped события, а также известные session-scoped reward
и loot-issue Inventory audit events, связанные с encounter через сохранённый reward
ID либо issue operation identity. Связь не угадывается по uid/time/prose.
Сохранены event IDs, source PID links, round context, primary actions, components,
rolls/probabilities, permitted costs и nested reaction/reflect/absorb/prepared/strike
summary. Для full view participation берётся из сохранённого final state.
Исторические значения не читаются из текущих CharacterSheets/KB/Inventory calculators.

## Порядок, фильтры и курсоры

У sequenced events сохраняется event_sequence. В актуальной схеме значительная
часть старых command/owner audit events имеет NULL event_sequence. Они не получают
задним числом номер: остаются causal companions в immutable session_sequence order
после предшествующего sequenced события. Reader проверяет монотонность исходного
event_sequence; повреждённая последовательность блокирует чтение.

SQL keyset использует индексный session_order, без OFFSET и без сортировки всего
payload журнала по вычисляемому полю. Сначала выбирается окно до 100 event headers,
затем details/result/navigation joins для этого окна. Нативный sequence anchor
вычисляется для выбранных строк. До 200 inspected records на request и до 50
выданных DTO; default 20. Разреженный поиск может вернуть пустую страницу с cursor
для продолжения — сервер не загружает весь журнал ради поиска.

Поддержаны round number, actor, entity, control_group, category, event_type, source
PID и search. Actor/entity/group фильтры работают только при разрешённом full view;
в hidden/partial view они отклоняются независимо от существования ID. Поиск идёт
только по уже разрешённым type/category/PID/ability label и GM reason, не по raw JSON,
скрытым определениям, прозе или owner payloads. Category — фиксированный namespace
prefix bucket, неизвестные namespaces относятся к other. Round metadata берётся из
сохранённых combat turn/round links, не сегодняшнего current round.

Первый request фиксирует high-water session sequence. Cursor содержит upper bound
и last key, связан с viewer/roles, фильтрами, limit, export audience и policy version.
AES-256-GCM с random IV скрывает служебные IDs/границы от viewer и запрещает подмену.
Новые audit append после начала пагинации не вклиниваются в snapshot. Изменение
прав/policy требует нового cursor. Нет total/scanned/hidden counters в DTO.
Opaque cursor bytes случайны; replay invariance относится к events/file bytes,
а не к случайному IV курсора или operation_key HTML-формы.

## Visibility и export permission

Native access к session thread и действующая session role нужны любому reader,
включая observer. Outsider не получает архив по известному encounter/PID даже при
reveal_all. Глобальный ACP статус без session GM не заменяет эти проверки.
До выдачи любой страницы проверяются исходные forum/password scopes всех
сохранённых post_links этого encounter; отсутствие доступа блокирует весь reader,
включая outcome/aggregate, а не только отдельную ссылку.
Deleted/moved/unreadable post не создаёт выдуманного URL; неавторизованные PID не
передаются. Если сама native thread недоступна, reader fail closed; отдельный
orphan-thread permission protocol в этот PR не добавлен.

Default keep_hidden не расширяет прежнюю non-GM карточку: только confirmed status,
доступный source link и identity самой разрешённой записи, без actor/target/group
IDs, damage, rolls, definitions или GM notes.

- reveal_results: разрешённая частичная/approximate проекция боевого результата
  (outcome/damage), без actor/target/group/ability identities и owner data.
  Approximate означает ограниченный набор полей; нового денежного/боевого
  округления или расчёта нет.
- reveal_all: full **combat** whitelist — IDs, сохранённые mechanical calculations,
  rolls/probabilities/costs/components и participation, outcome/correction links.
  Это не raw dump всей сессии и не право на account/Inventory authority envelopes.
- GM journal: full combat view плюс headers/proofs/reason административного audit.
  Ни raw achievement before/after/global progress, ни owner request/receipt
  envelopes, authorization_ref, sealed_json не возвращаются.

Non-GM SQL заранее исключает административные/achievement/owner audit types;
keep_hidden выбирает только combat status records. Итоговые filters/search
применяются к permitted DTO до записи файла. Hidden fields не прячутся HTML/CSS.

Обычный export даже для GM использует **member policy projection**.
Расширенный export требует current session GM + отдельный allowlist setting
`af_acs_archive_export_groups`; пустое значение запрещает расширение. Никакого
client-reported can_manage/admin bypass нет. Expanded JSON дополнительно содержит
первую bounded snapshot page с snapshots_next_after; expanded CSV содержит full
whitelisted event rows. Нужные последующие snapshot pages читаются manifest API.

## Политика и schema prerequisites

Новая resumable migration **028_archive_policies** — следующая после фактической
027_achievements_reviews. Создаётся только отдельная archive_policies projection:
encounter/session, mode, policy_version, event ref. Исторические migrations,
events, snapshots и result/receipt JSON не изменяются. Непроинициализированный
encounter читается как keep_hidden/version 0 без INSERT на GET.

POST archive_policy требует CSRF, current native/session GM, session + encounter
versions, expected_policy_version, reason и operation_key. Только completed
encounter с verified confirmed outcome. Policy row CAS и archive.policy event/receipt
атомарны в прежнем SessionRepository command. Операция не открывает encounter,
не пересчитывает damage и не меняет источник истории. Same-key replay возвращает
старый receipt; другой payload/stale version отвергается. Изменение policy
инвалидирует старые cursors, в том числе после возврата в keep_hidden.

Контролируемые install/activate session settings дополнены:

- af_acs_archive_cursor_key: серверный random 256-bit key; существующее значение
  сохраняется при повторной активации. Не публиковать/не включать в frontend config;
- af_acs_archive_export_groups: отдельный allowlist, default empty/deny, также
  сохраняется при повторной активации.

Для installed использования нужны ACS migration 028, контролируемое обновление
settings и PHP OpenSSL AES-256-GCM. При отсутствии/невалидном key cursor read
блокируется; GET не генерирует и не сохраняет секрет. Ротация ключа контролируемая
и инвалидирует старые cursors, без изменения событий. Production DDL/settings
в этом task не выполнялись.

## Архивные источники и current overlays

Extended manifest отдельными pages (20 records + continuation) предоставляет:
verified pinned encounter config/participant template snapshots; ordinary
reward status/version metadata; loot status/qty/winner allocation metadata;
current session custody и existing SessionObligationsQuery. Последние явно
помечены session scopes, не притворяются историческим состоянием encounter.
Snapshot bundle требует native доступа ко всем original post-link forum scopes;
для session custody/obligations проверяются source forums всей сессии.

Статусы rewards/loot/custody и latest correction revision — current projections,
отдельные от immutable mechanical events. Achievement-source rewards/global
achievement progress не экспортируются через общий manifest: остаются их прежние
типизированные permission boundaries. Общий obligations manifest также исключает
achievement progress/reward refs и reward aggregate counters; всегда помечен
complete=false и фиксированным excluded_categories, а не сообщает фиктивное
отсутствие оставшихся обязательств. Pending Inventory/reward/recovery/compensation
не удаляются, не объявляются выполненными и не вызывают owner API на GET.

Correction отображается отдельной записью: original event ID, собственная revision
и актуальная derived revision/disposition. Source остаётся рядом с прежними
значениями; correction не заменяет её и не запускает новый loot roll/award/clawback.
Изменение текущих overlays или native navigation может изменить эти metadata;
при неизменных данных replay events и bytes JSON/CSV одинаковы.

## Lazy surfaces и файловый формат

AF transport: archive_events / archive_export / archive_manifest — GET;
archive_policy — POST. Все arguments строгие; session/encounter IDs обязательны.
Фильтры: round, actor, entity, control_group, category, event_type, pid, search;
opaque cursor переносится без изменения фильтров/limit. HTTP export chunks default
20 events, JSON или RFC4180 CSV, максимум 8 MiB; continuation — X-ACS-Next-Cursor.
Cursor не включён в file bytes, что даёт детерминированный повтор chunk.
Полный бесконечный export всех страниц в одном request не реализован.

Lazy native page: `misc.php?action=acs_archive&session_id=…&encounter_id=…`.
Есть GET filters, nested event/action blocks, source links, continuation, download
links и GM policy POST form с CSRF/CAS. Ссылка из существующего GM encounter view.
Обычный showthread не запускает ArchiveService/manifest и не загружает журнал.
Нет новых JS assets, polling или второго редактора; финальная стилизация — этап 11.

CSV использует fputcsv с явным empty escape и quotes. Все клетки, начинающиеся
с formula-like =/+/-/@ после whitespace/control/BOM, получают text apostrophe.
Вложенные данные сериализуются в JSON cell; raw fields не добавляются после
permission projection. Content-Type/attachment filename/nosniff/cache headers
задаются сервером, файл не пишется в публичный каталог.

## Проверки

PHP 8.4.27, MySQL 8.0.46, 64-bit mysqli/mysqlnd + mbstring/OpenSSL, disposable DB,
Unix socket, DROP DATABASE в finally. Native browser/MyBB installed lifecycle,
PHP 8.5.12 и большой production benchmark — NOT_VERIFIED.

```text
php -l <все новые/изменённые PHP-файлы>
php tests/acs_archive_sql.php /tmp/acs222-socket/mysql8.sock
git diff --check
```

Изменённого JS нет; node --check не применим. Полный suite не запускался.
Fixture продолжает узкий реальный combat → outcome → loot/reward/review harness;
optional archive hook не вызывается вне нового fixture. SQL seeds и capability
mocks остаются явными, production source/permissions не заменяются.

PASS: stable native sequence; keyset pages без duplicate/skip; frozen horizon при
новом audit append; search/filter по round/actor/entity/group/category/type/PID;
known source URL; prose edit не меняет архив; native DELETE сохраняет mechanics;
linked correction и current revision; keep_hidden/reveal_results/reveal_all;
policy POST/CSRF/GM/CAS, stale/replay и late rollback; GM/player/observer/outsider
JSON/CSV; отдельный expanded permission; hidden DTO/owner/achievement fields;
CSV formula/control/BOM injection; cursor tamper/visibility invalidation; snapshot/
loot/reward/custody/obligation scopes; no persistent GET mutations; одинаковые
replayed JSON/CSV bytes; lazy HTML без script runtime.
