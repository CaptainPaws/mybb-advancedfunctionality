# ACS-4.2.3 — seal, random tape, квота и подтверждение

Исходная main: `da43148a8853db858c052aa62cba06a11f91c7e3`. ACS `0.19.0`, схема `007_sealed_actions`, Resolver `acs-resolver-2`, numeric policy `binary64-half_up-v1`, RNG `uniform53-v1`, confirmation `acs-confirm-v1`.

Основание: четыре нормативных документа `docs/acs/`, A1.13–A1.16, A1.49/A1.58–A1.60, Architecture §4–8 и уточнение ACS-4.0. Формулы H1/C1/D1 и порядок Effects/Modifiers не изменены. IC-MYBB-1 по-прежнему **BLOCKED**: нет MybbPostGateway, combat commit, posts INSERT, списания реальных HP/AP, turn resolved или публикационных уведомлений.

## Файлы

Относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

- Новые `modules/Application/SealService.php`, `modules/Infrastructure/SealPersistence.php` (trait существующего EncounterRepository), `modules/Infrastructure/RandomSource.php`, `migrations/007_sealed_actions.php`.
- `modules/Domain/Resolver.php`: чистый режим `seal` на переданном проверенном tape; RNG внутри Domain не создаётся. `preview` по-прежнему отвергает tape, `commit` не поддерживается.
- `modules/Application/{BuilderService,DraftService,QueryService}.php`: повторное использование server-prepared контекста/pinned данных, неизменяемый prefix, собственный статус seal/restore. Нет повторного Resolver или live KB fallback.
- `modules/Infrastructure/{Repository,EncounterRepository,DraftPersistence,RoundManagementPersistence}.php`: registry/allowlist 007, seal unit-of-work, append-only payloads и узкое разрешение **только своего** sealed draft для его симуляции. Обычные round/skip commands сохраняют строгие obligations gates.
- `modules/Administration/Migrations.php`, `advancedcombatsystem.php`, `manifest.php`: schema/version и ленивый dispatch новых операций.
- `modules/Presentation/{ApiController,BuilderController}.php`, `assets/builder.js`: API и кнопки seal/cancel/refresh/confirm, frozen cards, восстановление pending request. Нет второго SCEditor или изменений Appearance/ATF/Editor/MyBB Core.
- Новый `tests/acs_seal_regression.php`; в существующем builder fixture добавлен scoped callback, state-тест дополнен prefix guards, три schema ожидания обновлены до 007, domain regression теперь проверяет отказ отсутствующего tape вместо отсутствующего seal mode. Этот отчёт — новый Markdown.

## DDL и целостность

007 создаёт **только две проектные таблицы** из Architecture §5, с реальным TABLE_PREFIX, InnoDB/utf8mb4_unicode_ci/ROW_FORMAT=DYNAMIC и без физических FK:

| Таблица | Ключи |
|---|---|
| `af_acs_sealed_actions` | PK id; UNIQUE seal_once(seal_operation_key); UNIQUE immutable_position(draft_id,action_index); KEY actor_actions(actor_entity_id,id) |
| `af_acs_rolls` | PK id; UNIQUE check_once(causal_key,check_kind,roll_index); KEY action_rolls(sealed_action_id,id) |

Перечень/типы колонок соответствуют проекту; отдельной выдуманной quota table нет. Квота вычисляется по `SUM(sealed_actions.ap_cost)` через drafts **для всего turn**, независимо от author, текущего controller и cancellation status. Каждый самостоятельный monster turn остаётся отдельным scope. Питомец использует тот же turn и квоту группы.

В sealed row фиксируются action descriptor, pin, immutable definition snapshot ID, history ordinal, AP cost, numeric/engine/RNG versions и hash tape. `sealed_hash` защищает payload, `content_hash` — snapshot определения; raw definition не передаётся браузеру. Сохраняется отдельный immutable snapshot закреплённого ability DTO в существующей snapshots table. Старые CombatSnapshots не меняются. При replay проверяется совпадение текущего зарегистрированного pin с сохранённым, не загружается live KB вместо версии.

`action_index` — монотонный индекс истории попыток draft. После cancel индекс не переиспользуется: active prefix выбирается по сохранённому порядку, отменённые строки остаются на старых позициях. Меняется только status sealed→cancelled; payload, pin, cost, indices, snapshots и rolls не переписываются и не удаляются публичными методами. Историю смены статуса подтверждает durable operation receipt.

## RNG и Resolver

Только seal новой попытки вызывает инфраструктурный `random_int(0, 2^53-1)` для зарезервированных hit/crit значений. Сохраняются точное целое `sample_n` и binary64 `u=sample_n/2^53`, `u∈[0,1)`. Это не d100: успех определяется утверждённым `100*u < P`, без потери дробных шансов. Domain проверяет версию, диапазон/тип, точное соответствие sample_n/u и количество записей; сравнение использует Numeric. P=0 не проходит, P=100 проходит всегда.

На один поддержанный удар сохраняются два отдельных roll record с causal_key `sealed-action:<id>`, check_kind hit/crit и roll_index 0. Crit candidate резервируется даже при промахе/запрете крита; `crit_used` показывает, был ли check реально применён. Неиспользованный кандидат не превращается в новый roll после изменения зависимостей. Атаки/компоненты не получают лишних crit-checks.

RNG отсутствует в preview, status/restore, cancel и confirm/reconfirm. Тестовый injectable sampler доступен только доверенному PHP-коду тестов; HTTP не принимает seed/rolls/исходы или вычисленную цену. Production controller создаёт обычный RandomSource без sampler. `mode=commit` не реализован.

До первого draw выполняется детерминированный preflight: CSRF/author/current controller/owner, MyBB/session access/posting rights, current pending turn/EntityTurn start, active encounter (не paused), структура/цели/видимость, поддержка KB и pinned versions, суммарная стоимость и оставшаяся quota. Все операции используют существующий ACS lock/transaction и CAS draft_version.

После draw **все** новые попытки, определения, tape, quota evidence, draft projection и receipt сохраняются атомарно. Если итоговая virtual simulation блокируется, seal всё равно сохраняет попытки/квоту и возвращает blocked summary: нельзя использовать неудачу симуляции как бесплатный переброс. Можно восстановить зависимости и пересчитать на прежнем tape либо отменить tail без возврата квоты. При SQL/transaction failure до подтверждённого commit результат не выдаётся; неизвестный исход требует retry/lookup тем же key, не новой попытки вслепую.

## Immutable prefix, квота, cancel и confirm

DraftService по-прежнему позволяет менять прозу и append-only незапечатанный хвост. Перед CAS update сервер сравнивает весь активный sealed prefix: actor, ability, targets, parameters и порядок должны точно совпадать. Удаление/перестановка/изменение prefix через draft_save отклоняется `ACS_SEAL_IMMUTABLE`. Статус draft — `sealed`, когда все его текущие действия запечатаны; при незапечатанном хвосте — `draft`. Это не публикация и не закрытие turn.

Seal обрабатывает весь новый хвост; повтор того же operation_key/payload возвращает прежний receipt до CAS, после повторной авторизации, без draw. Другой payload того же key — conflict. Append всегда получает новые history IDs и оплачивает quota. Её предел — текущий доступный AP-бюджет turn с существующей pre-round commitment/spent политикой; отмена/новый draft/controller её не обнуляют. Реальные ap_spent/HP/resource projections не меняются. История/квота сохраняются при деактивации.

Cancel выбирает на сервере **последнюю активную** sealed row. Нельзя передать произвольный индекс для отмены середины. Соответствующий descriptor удаляется из active draft prefix, незапечатанный хвост сохраняется; история/tape/квота остаются. Новый cancel key после успешной отмены — уже новая явная операция над следующим tail; сетевой повтор должен использовать прежний key. UI требует подтверждение и сохраняет key/payload неизвестного запроса. Receipt содержит author/op и причину `author_cancel_tail`.

Подтверждение относится к актуальной серверной сводке полного sealed draft, не к прозе. `dependency_hash` включает релевантные actor/target/turn/group/entity-turn versions и source fingerprints; общие session/encounter counters, не влияющие на выбранные зависимости, исключены. `simulation_hash` включает ordered actions, сохранённый tape, версии политики и механическую проекцию результата, **без UI label**. Confirmation hash связывает оба. Appearance/mask refs, аватары/декоративные имена и prose не включаются.

`seal_status` заново вычисляет сводку на сохранённом tape и перечитывает versions. Изменение HP/других поддержанных зависимостей даёт новое summary/hash и `confirmed:false`; старый confirm hash отклоняется. Новый confirm требует отдельного POST с показанным hash и текущим draft_version; RNG не вызывается. Изменившийся pin/неподдерживаемая версия/новая неподдержанная механика блокирует операцию, не заменяется guessed формулой. Чисто литературный save не снимает существующее подтверждение, если механическая очередь не менялась.

## API и UI

Через прежний AF endpoint `xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem`:

| op | Метод / payload | Поведение |
|---|---|---|
| `seal` | POST + my_post_key, draft_id, expected_draft_version, operation_key | Запечатать весь новый хвост; сводка, IDs, quota, новая версия |
| `seal_cancel_tail` | POST + те же поля | Однократно отменить последний active seal без refund |
| `seal_confirm` | POST + те же поля + confirmation_hash | Подтвердить свежую сводку на прежнем tape |
| `seal_status` | GET + draft_id | Собственная история/tape/актуальная safe summary/flags, без write/RNG |

Типы/правила operation_key как у draft API: lowercase 16–96 символов; HTTP не принимает чужого автора, seed, arbitrary roll или готовый result. Мутирующие команды запрещены через GET. CAS/key/hash conflicts — 409; права/CSRF — safe refusal; детали SQL/PHP не возвращаются. Errors не создают combat events или SQL diagnostics. Новый receipt namespace `seal:<uid>:<key>`, kind `seal.seal|seal.cancel_tail|seal.confirm`; 60 новых команд/минуту/author, exact replay не расходует лимит.

Конструктор показывает:

- preview до seal без RNG;
- «Запечатать действия», «Отменить последнее запечатанное действие»;
- «Пересчитать сводку на прежних бросках», историю (включая отменённые попытки), использованную квоту, сохранённые значения и исходы;
- «Подтвердить сводку», changed/blocked/confirmed состояния;
- frozen prefix cards; новые строки только в хвосте, кнопки не позволяют перенос через prefix;
- всегда disabled «Отправить боевой пост» и прежнее пояснение.

После успешной команды UI восстанавливает authoritative draft/version и читает status. Неопределённый запрос хранится в sessionStorage вкладки с прежним key/body; остальные изменения блокируются до retry/recovery. При изменившейся сводке UI обновляет её и требует новый confirm. Никакой механики не извлекается из маски или художественного текста.

## Сохраняемые ограничения

Работает только поддержанный базовый damage subset ACS-3.3.2/4.2.2: одиночная hostile цель, фиксированные параметры, положительная integer AP cost, проверенные pinned definitions. **0 AP с обязательными KB лимитами пока BLOCKED**: текущий owner/preview контракт не предоставляет проверенного лимита; бесплатное запечатывание не допускается, отказ происходит до RNG. Zero-AP limit keys не выдумываются.

По-прежнему нет per-player visibility contract для текущих GM-only targets. Seal/числовая summary доступны в существующем разрешённом GM scope с отдельным контролем своего actor. Player drafts сохраняются, скрытые targets/definitions не раскрываются ради seal. Новые статусы/реакции/предметные расходы/публикация не добавлены. При появлении неподдержанного capability результат BLOCKED, не silently ignored.

Технические пределы подготовки: прежние ≤20 actions/preview/поддержанная seal simulation, ≤200 history rows/draft и существующие JSON/transport bounds; это не новые игровые AP/0AP правила. В стандартном бюджете 3 AP история ограничена прежде всего безвозвратной quota.

## Проверки и ручная приёмка

Ограниченные проверки: PHP CLI 8.4.24, изолированная MariaDB 10.11.19, Node; PHP 8.5.0/реальный форум требуют ручной приёмки.

- `php -l` всех 23 изменённых/new PHP, `node --check`, JS prefix/state serialization, related asset-scope test, `git diff --check`.
- DDL сопоставлен с Architecture, 007 interruption/resume/repeat и readback; schema expectations существующего узкого fixture обновлены до 007.
- `tests/acs_seal_regression.php` использует existing scoped builder/round fixtures, не большой симулятор: batch seal, exact replay, conflicting operation_key, immutable prefix, tail cancel/retry, quota 2→3 без refund и отказ следующего draw; history/tape/definition payloads неизменны.
- Conflict/reconfirm: явное test-only изменение HP/resource projections цели, пересчёт на тех же rolls, отказ старого hash, новый confirm без RNG. Prose-only save/Appearance input не меняют confirmation.
- Test-only конфликт источника после draw сохраняет попытку и quota, subsequent восстановление источника использует прежний tape. Такой callback не существует в production RandomSource.
- CSRF/права/GET/paused denial; сравнение реальных combat tables/events/resources до/после и неизменность старых CombatSnapshots. Тестовые конфликты явным образом восстанавливают fixture data перед сравнением. Допускаются только preparation rows/definition snapshots/receipts.
- Ограниченный DOM check фактического HTML (jsdom вне repo, не E2E): exact retry, disabled prefix edit, saved results, conflict/reconfirm, cancellation/no refund, disabled publication/no SCEditor. Полный suite/E2E не запускались.

На warprift.ru:

1. Резервная копия ACS БД, обновление файлов и штатный ACP upgrade до `007_sealed_actions`; обычные GET не запускают миграции.
2. Подготовленный активный encounter/current pending turn, обработанный EntityTurn start, собственный управляемый actor и поддержанная pinned ability/разрешённая цель. Сохранить draft, проверить исходный AP/HP.
3. Нажать seal, записать значения/исход/квоту. Повторить запрос тем же key и обновить страницу: тот же tape/history, реальные AP/HP прежние.
4. Попробовать изменить sealed row: отказ. Append unsealed row в конец и save разрешены; seal — только при оставшейся quota. Prose редактируется отдельно.
5. Подтвердить сводку. В изолированном тестовом сценарии разрешённо изменить зависимость, обновить summary: прежние значения, новое подтверждение. Не повреждать production данные искусственным SQL.
6. Отменить последнее sealed действие с подтверждением, проверить историю и прежнюю quota; сетевой retry тем же key не отменяет следующую строку. После исчерпания квоты новые rolls не выдаются.
7. Проверить pause, чужого пользователя, смену маски, Quick Reply/Quick Edit и обычную тему. Боевая публикация всё ещё отключена.

Откат/восстановление: при interrupted DDL повторить штатный runner после устранения причины, не менять checksum старых migrations. Деактивация ACS сохраняет tape/history/quota. Не DROP таблицы, не удалять отменённые attempts и не исправлять quota обнулением. Старый runtime схемы 006 нельзя слепо развернуть поверх ledger 007: аварийно отключить ACS либо подготовить отдельный compatibility rollback, сохраняющий знание 007 и данные. Неизвестный результат команды сначала lookup/retry **тем же key**, не новый seal. Следующая задача автоматически не выполняется.
