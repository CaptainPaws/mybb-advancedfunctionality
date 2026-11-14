# ACS-9.2.2C — Ambush GM UI, AF API and integration acceptance

База main `ae4228b4` (ACS-9.2.2B, PR #1059). Основания: четыре нормативных
ACS документа, A1.75–A1.77, отчёты 9.2.1/2/2A/2B. Один Integration/UI PR.
`docs/ROADMAP.md`, исторические отчёты, MyBB Core, AdvancedAppearance,
CharacterSheets, postbit и общие стили ATF не изменены. Новых миграций нет.

## Implemented

В штатной GM-панели showthread, вкладка «Раунды», добавлена компактная секция
«Засада». Она показывает preparation, ambush_ready, encounter_start,
ambush_resolution, активный первый раунд либо подтверждённый исторический старт.
Есть ссылка в существующий Builder, число разрешённых стартовых preparations,
число неподдержанных preparations, aggregate readiness/блокировки, таблица
фактически подтверждённых AP commitments по группам и остатка AP на старте.
Новый обычный ход/редактор/SCEditor не создаётся.

Кнопки: «Перейти к готовности», «Начать засаду», «Проверить результат запуска».
После read-only сверки сохранённого pending request возможно явное «Продолжить
прежний запрос» с теми же key, versions и reason. Recovery_required не имеет
кнопки повторного исполнения. Во время запроса кнопки блокируются до исхода.
Неподтверждённый response не показывается как успешный старт.

В `AmbushPanelService` используется allowlist DTO. Raw AmbushStartService
receipt/contract/manifest никогда не сериализуется в ответ или HTML config.
DTO не содержит targets, ability pins, participant positions, source identity,
item bindings, reserve receipts, sealed rolls или GM reasons. Вся панель/API
требуют действующего persisted GM/owner session permission и native thread
access. Player, combat delegate, observer и outsider не получают даже counts
этого GM DTO. Разрешённый собственный Builder/awareness продолжают использовать
прежние controller/AudienceService gates; скрытые чужие contracts не добавляются
в player choices, disabled choices или notices.

Readonly `ambushStartConfirmed` сверяет committed start intent с существующими
terminal operation + immutable event/details/hash, request/issuer/start ID,
round ID и mechanics_confirmed. Одного metadata intent недостаточно для success.
Отсутствующий terminal proof проецируется как recovery_required без SQL write.
На дальнейших раундах UI обозначает исторический подтверждённый старт, не обещает
новый бесплатный первый раунд.

### AF API wiring

Используется существующий
`xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem` bootstrap:

| op | Метод | Назначение |
|---|---|---|
| ambush_status | GET | Авторизованный read-only snapshot/terminal lookup |
| ambush_ready | POST + CSRF | Прежний AmbushStartService.ready |
| ambush_start | POST + CSRF | Прежний durable start/causal seal/Resolver/atomic commit |

Mutation input ограничен session_id, encounter_id, expected_version,
encounter_version, operation_key, reason. Scalar string types и exact fields
проверяются до service call; authority, native permissions, CAS и reason/key
повторно проверяет прежний сервис. Клиент не передаёт AP totals, item owner/qty,
RNG, hit outcome, prepared result или mechanical status. GET mutations запрещены.
Ошибки возвращают безопасные allowlisted codes/messages без raw SQL/contract.
Cache-Control private,no-store и nosniff выставляются на API.

Browser сохраняет request/key **до отправки** в sessionStorage с partition
viewer UID + session + encounter. При storage failure mutation не отправляется.
Double-click блокируется локально; повтор/вторая вкладка дополнительно ограничены
прежними durable identities/session lock. Network failure сохраняет исходный
request. После reload сначала доступна проверка результата; она не вызывает
Resolver/RNG/owner consume. Явное продолжение использует ровно прежний запрос,
без обновления его версий или причины. Для подтверждённого stale/invalid отказа
refresh разрешает новый запрос только после чтения состояния без start intent.
Unknown outcome не превращается в reject и не создаёт новый key автоматически.

### Pre-round Builder wiring

Backend 9.2.2A возвращал mode=pre_round без обычных turn.members и pending status;
старый Builder JS ожидал эти поля. Теперь тот же Builder допускает редактирование
только в server-issued preparation mode, отображает available_ap и commitment
выбранного actor/group, скрывает несуществующий turn selector/upkeep и разрешает
одну source/fixed-target preparation. Draft ID восстанавливается существующим
builderDraft с owner + encounter scope, опубликованные drafts туда не попадают.
После подтверждённой регистрации UI очищает текущий рабочий draft и обновляет
оплаченные AP; старые seals/history остаются на сервере.

Используются прежние draft_save/seal/seal_confirm/prepared_publish endpoints,
explicit item selection, reserve receipts и deferred tape. Trigger label для
start descriptor показывает запуск засады. До trigger нет сценария будущего
hit/crit и перехода к native post editor. Ordinary active Builder сохраняет
прежние режим, pending turn и publication gates. Общая publication_enabled
остаётся false; start/paid-preparation API не включает general combat posting.

### Lazy loading

Новый contextual resource `ambush` допускается только с server fact
has_acs_ambush в явно открытой авторизованной GM rounds panel. Обычный showthread
не получает ambush.js. Builder использует прежний contextual resource и его JS,
нового глобального runtime/editor нет. Не добавлены общие CSS или ATF изменения.
Существующий ordinary encounter start сохранён и явно подписан «без засады»;
backend guard по-прежнему не позволяет обойти active prepared obligations.

## Locally verified

PHP **8.5.12**, disposable authoritative MySQL **8.0.46**, действующий SQL fixture
harness. Native MyBB post fixture существует заранее; Gateway не используется.
Подготовка реально проходит Draft/Seal/confirmation/paid registration до API.
Owner UID 7 и launcher GM UID 8 — отдельные authenticated контексты; observer
UID 9, player с настоящим combat delegation UID 10, outsider UID 11 проверены
отдельно. Native deny проверяется отдельным SessionAccess boundary fixture.

`acs_ambush_api_sql.php` normal/multiple/revoked/orphan:

- Рендер штатной EncountersPage rounds-вкладки включает новую секцию при native
  thread authorization и не меняет SQL. GM snapshot GET не меняет SQL; player/delegate/observer/outsider запрещены
  для status/ready/start; observer awareness hidden preparation остаётся unknown.
- Exact field allowlist отвергает client paid_ap/item_owner/qty/result/RNG/hit/
  mechanics fields. GET mutation, неверный CSRF и stale session version не пишут.
- Ready принимает подтверждённые preparations. Real start вызывает существующий
  Resolver, две retained item reservations расходуются ровно один раз при текущем
  owner grant; GM UID и отсутствие body/Inventory permissions сохраняются.
- Реальные 2 paid AP дают 1; character + pet 2+1 дают 0. DTO сравнивается с
  фактическими turns.pre_round_ap_commitment, AP budget/spent и Clock remainder;
  zero-AP group имеет passed, не новый pending ход.
- Реальный terminal COMMIT с потерей ACK возвращает прежний confirmed outcome.
  Последовательный duplicate start и status lookup не меняют SQL/rolls/seals,
  не создают второй round или MyBB post.
- Удаление terminal event_details как fault injection запрещает fake success
  от одного committed intent; read остаётся без writes. После восстановления
  evidence прежний подтверждённый outcome снова доступен.
- Отозванный owner inventory grant запрещает consume, сохраняя исходные
  reservations/armed seal. Orphan owner outcome даёт recovery_required;
  lookup/retry не создают blind consume/release, урона или roster.
- Реальные deactivate/activate lifecycle callbacks с populated start data,
  повторный activate и schema assertInstalled сохраняют данные/outcome. ACP
  principal и diagnostic sink заданы fixture; это не installed ACP acceptance.
- Publication flag проверяется false; asset manifest остаётся contextual.

`acs_ambush_legacy_api_sql.php`: реальная paid legacy preparation без start opt-in
принимается ready; DTO показывает unsupported_count=1, can_start=false. API start
отклонён до intent/roll/round и не меняет AP commitments/Inventory reservations.

`acs_pre_round_sql.php`: regression paid resources/items, shared group cap,
independent NPC, no fake round/EntityTurn, authored draft discovery, stale/revoked
control, late rollback/replay, exhausted-group status/boundaries и next round.

Node fixtures без браузера:

- `acs_ambush_ui.js`: double-click отправляет один request; lost ACK/reload,
  read-only lookup, точный resume payload, terminal no-retry, recovery barrier,
  storage failure и stale refresh.
- `acs_pre_round_builder_ui.js`: mount без round/turn.members, pre-round edit/save,
  shared AP remainder, stable draft key и запрет native publication/future rolls.
- `acs_builder_state.js`: прежний ordered draft, parameters, immutable sealed
  prefix, retry/restore — regression PASS.

PHP lint всех 13 изменённых/новых PHP — PASS; node --check четырёх изменённых/новых
JS — PASS; git diff --check — PASS. Перечисленные SQL/API и Node fixtures — PASS.
Browser E2E и полный suite не запускались.

## Фактическая матрица A1.77

| Требование | Реализация / доказательство | Граница |
|---|---|---|
| preparation → ready | Paid Builder/Seal из A, GM ready API | Только approved KB pre_round subset |
| encounter_start | Durable intent + start_operation_id из B; API lookup | Нет нового client start descriptor |
| ambush_resolution | Реальный Resolver и fixed causal tape из B | Fixed-target ordered damage actions |
| Стоимость до раунда | Durable ledger + actual turn commitment 2→1 / 3→0 | Обычный integer budget=3 из A |
| Shared pet AP | Character + pet commitment=3, zero group passed | Новых pet item grants нет |
| Independent NPC | Прежняя отдельная group/budget; A SQL regression | Нет нового бюджета от засадной роли |
| Первый обычный roster | Один round, без нового MyBB post; duplicate/ACK lookup | Не дополнительный ambush round |
| Item owner policy | Две точные reservations, current grant, revoked отказ | Issuer GM не становится owner |
| Hidden disclosure | Независимый awareness + закрытый GM allowlist DTO | Без identity reveal и raw contract |
| Surprise | Используется только прежний explicit KB subset | Нет универсального bonus/skip victim |
| Recovery | Read-only terminal proof; same-key continuation; barrier | Нет force success/release unknown |
| GM UI | Штатная rounds panel + lazy resource | Installed/browser acceptance NOT_VERIFIED |

## BLOCKED / UNSUPPORTED

Все ограничения A/B сохраняются: нет generic prepared chains, startup active
reactions, multi-target start, direct prepared status/removal abilities,
произвольных surprise/AP overrides или расширения owner spending rights.
Невалидный KB descriptor не становится доступным от кнопки панели.

Recovery_required/orphan/integrity outcomes нельзя исправить универсальной GM
кнопкой. UI предоставляет read-only проверку и сохраняет identity; доказанное
prepared intent можно продолжить только прежним request. Takeover другим GM,
reseal stale frozen dependencies, автоматические refund/release unknown owner
outcome и ручное объявление mechanics_confirmed не добавлены. Если sessionStorage
утрачен, UI читает persisted start status, но не реконструирует секретную reason
или исходный request из JSON и не создаёт новый start key для обхода intent.

## Installed NOT_VERIFIED / будущая ручная приёмка

warprift.ru/MyBB **1.8.41**, установленная тема/ATF, реальная KB, native permissions,
owner providers/grants и production schema/browser не проверялись. Local PASS
не разрешает включение general publication и не заменяет installed acceptance.

Будущая ручная приёмка на разрешённом staging:

1. Открыть обычный showthread и затем GM rounds panel: ambush.js только во втором
   случае; проверить keyboard/focus/status labels и узкий экран.
2. Owner подготовить допустимую hidden ability в прежнем Builder: save, reload,
   seal, confirm, paid publication; увидеть 2→1 и shared 3→0 без fake turn.
3. GM ready/start, проверить actual HP/resources, две owner reservations/receipts,
   один roster, exhausted boundaries и отсутствие нового литературного поста.
4. Player/delegate/observer/outsider проверить UI/API/JSON/disabled choices,
   knowledge и counts; закрытые цели/positions/rolls/item bindings не раскрываются.
5. Double-click, несколько вкладок, network disconnect/lost ACK и reload:
   read-only проверка, прежний key/payload, один confirmed outcome.
6. Stale session/encounter, revoked owner grant, unsupported KB и pending Inventory:
   безопасный отказ/barrier без обхода прав и без автоматического нового ключа.
7. Проверить actual ACP disable/re-enable с populated ambush history и корректными
   grants, HTTP cache headers и сохранёнными publication_enabled=false gates.

Deployment/merge и ACS-9.3.1 не выполнялись.
