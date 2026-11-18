# ACS-11.3.2 — Versioned Updates, Safe Errors и Lazy Runtime

База: `main` 1e891e0a, merged ACS-11.3.1 (#1086). A1.59, A1.88–A1.89,
A1.91–A1.92. Один ограниченный PR. Нормативные основания: ACS Master
Specification, Technical Architecture, Integration Contracts, Stage 4 Revision 2
и результаты предыдущих задач этапа 11, в особенности ACS-11.1.1B,
ACS-11.1.2/11.1.3 (CAS/autosave/retained requests), ACS-11.2.1 и ACS-11.3.1.
`docs/ROADMAP.md` не изменён. Следующая задача не начата.

## Найденные разрывы

QueryService/BuilderService уже проверяют актуальные versions, audience,
controller grants и scope_token перед preview/validation. Draft/Seal/Publish
имеют свои CAS и retained operation identities. Однако:

- panel не проверял изменения после возврата на вкладку;
- Builder обновлял контекст по отдельным командам, без общего version fallback;
- часть ACS AF endpoints возвращала только code, иногда без category/error_id;
- Bootstrap Context называл interactive зарезервированным и мог маркировать
  showthread view только по неподтверждённым query parameters;
- для дешёвой проверки изменений требовался тяжёлый Builder context;
- общий подтверждённый AF state bus в изученной цепочке не найден. AAM poll/list
  доставляет alerts, а не authoritative version stream, поэтому не используется
  как источник боевого состояния.

## Лёгкий version endpoint

Добавлен GET на существующем AF transport:

`xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem&op=versions&session_id=…&tid=…`

Опциональны encounter_id, собственный draft_id и GM-only profile=1. Аргументы
строгие строки/канонические IDs. Требуются enabled ACS, native thread/password
access, совпадение session/TID и текущая сохранённая session role. Глобальный
ACP/Appearance context не заменяет membership. Чужой draft недоступен.

`Infrastructure/VersionQuery` не загружает Repository, QueryService, Resolver,
Builder, GM stack, KB, оборудование или журнал. Используются bounded SELECT:
roles/session, выбранный/current encounter, round, одна batch выборка entity/state
versions, опционально свой draft, затем общий session/encounter/draft read fence.
Максимум **6 собственных prepared SELECT** независимо от количества entity rows;
native MyBB permission helpers могут выполнять свои запросы и в эту цифру не
включены. Entity batch ограничен 1000 разрешённых rows; overflow даёт отказ,
не частичный snapshot. Нет SELECT внутри цикла по участникам.

Snapshot содержит encounter_version, round_version, entity_version,
draft_version, event_sequence. Encounter/round/event/entity metadata выдаётся
как viewer/session/permission-bound HMAC equality tokens на существующем archive
key; числовые счётчики скрытых событий и entity IDs/counts не раскрываются.
Draft version — существующий CAS number только собственного draft. В equality
binding входит session_version, поэтому session-scoped delegation/controller
изменение также инвалидирует snapshot. Никакие tokens не подставляются вместо
реальных CAS write versions: сервисы по-прежнему проверяют их самостоятельно.
Read fence отклоняет concurrent смену session/encounter/draft вместо выдачи
смешанного снимка. Headers private/no-store/nosniff сохранены.

## Fallback refresh без постоянного polling

Новый маленький `assets/runtime.js` предоставляет один watcher для уже открытой
авторизованной поверхности. Поддержаны:

- initial/open panel и Builder, повторное раскрытие details/dialog;
- visibility return и window focus с coalescing короткого focus/visibility burst;
- перед условным preview;
- перед новым seal/confirm/cancel/prepared command;
- перед новой подготовкой публикации и перед отправкой уже зарегистрированной
  операции; при UNKNOWN/RECOVERY используется прежний lookup/status путь.

Нет setInterval, рекурсивного GET таймера, long-poll, автоподключения AAM poll или
фонового получения всего состояния. Один inflight request на watcher;
15-секундный timeout завершает только текущий network request, не запускает retry.
Смена scope во время ответа не применяет устаревший результат. Смена draft/turn
сбрасывает scope baseline; известный успешный save/seal сбрасывает собственный
baseline, не выдавая свою запись за конфликт другой вкладки.

В панели metadata change помечает сведения устаревшими и предлагает явное
обновление страницы **после сохранения текста**. HTML/participants/filter values
не заменяются фоновым фрагментом, второй экземпляр панели не создаётся. Проверка
смотрит current encounter policy, поэтому обнаруживает и появление нового боя.
Сессия без encounter также поддерживает лёгкую проверку. ATF sticky/postbit и
расчёт отступов не меняются.

В Builder change инвалидирует conditional validation/confirmation, сообщает
о смене состояния и перед следующим новым действием обновляет разрешённый
Builder context. Sequence/prose и pending request journals сохраняются; цель,
исполнитель и порядок не подменяются автоматически в сохранённых actions.
Смена собственного draft_version относительно модели блокирует autosave как
конфликт другой вкладки. Обновление серверного draft остаётся отдельной явной
командой восстановления; version check не вызывает model.restore.

Publication получает уведомление об изменении и снимает checkbox подтверждения.
Литературный текст сохраняется прежним bridge/backup. Synchronous busy lock
установлен **до** первого await version preflight; отказ до POST не становится
UNKNOWN. После отправки неопределённый ответ остаётся UNKNOWN с прежними
submission_id/operation_key. При возврате на вкладку уже известной операции
выполняется только status lookup. Для pending prepare/seal сохранённый exact
request не заменяется свежей командой и не получает новый key/roll.

Если runtime asset запрещён loader-ом, панель сообщает об отсутствии проверки,
а UI нового preview/seal/publication preflight отказывает с сохранением данных.
Backend CAS и capability gates этим не ослабляются.

## Safe error DTO

Новый SafeError задаёт один whitelist публичных code/category/message/error_id:
VALIDATION_ERROR, STATE_CONFLICT, PERMISSION_DENIED, DEPENDENCY_ERROR,
INTERNAL_ERROR, RECOVERY_REQUIRED. Человеческие сообщения отдельно описывают:
смену боя, недоступную цель, отозванное управление, недостаток AP/ресурсов,
недоступный предмет/резерв, другую вкладку, повторное подтверждение и recovery.
Сохранены значимые Draft/Seal codes, которые existing frontend использует для
различения доказанного отказа и неизвестного исхода. Publication capability/
unsupported MyBB branch сообщения также остаются явными и безопасными.

Builder и EditorPublication используют фактический Throwable до потери
диагностического machine code. Остальные ACS AF JSON errors проходят узкую
normalization buffer только внутри `af_advancedcombatsystem_xmlhttp`. Она
обрабатывает только короткий ok=false error DTO, не success payload/HTML/чужие
аддоны. Legacy message и технические extras заменяются whitelist DTO; raw
exception/SQL/KB JSON/hidden IDs не публикуются. Unknown code получает безопасный
INTERNAL_ERROR. Archive exports полностью обходят buffer, чтобы не копировать
и не менять file bytes; их error branch использует SafeError напрямую.

Safe diagnostics записываются существующим recordDiagnostic не более одного
раза на error request: category, sanitized operation/phase и request/error ID.
Повторная normalization не создаёт вторую запись. При недоступности writer
логирование best effort; случайный client error_id сам по себе не доказывает
успешную запись в Diagnostics. У network error без серверного ответа нет
выдуманного подтверждённого server error_id. Логи не содержат прозы, tape,
owner envelope, SQL или скрытых полей. Application rollback/receipt logic не
изменён; error formatting не выполняет повторную механику.

## Lazy modes и assets

Context теперь умеет маркировать уже разрешённую surface как view/interactive.
Авторизованные panel → view; Builder/GM/setup → interactive; interactive не
понижается последующим panel render. Неподтверждённый setup запрос также отсекается лёгким canCreate guard до
подключения GM stack. Одни acs_builder/acs_session query args
больше не делают обычный showthread активной surface. Явные существующие misc
read pages остаются view. Context не является разрешением операции.

Новый runtime resource зарегистрирован в existing contextual manifest:
has_acs_runtime, routes=[], directory_fallback=false. RuntimeAssets вызывает
существующий AF asset gate и выводит script один раз до Builder/panel scripts.
Обычная тема без разрешённой ACS-сессии не получает этот script, Resolver,
Builder или GM stack. Новых global routes для assets нет. Cache version
изменённого JS — 1132. Publication использует существующий единственный editor;
нового editor/runtime bus или frontend framework нет.

QueryService/BuilderService числовые/target/version checks не заменяются
version endpoint. Full abilities/equipment/snapshot loading остаётся на явных
interactive endpoints, а ordinary showthread и fallback version check их не
вызывают. Existing panel batch/audience read cache сохранён. Общий рефакторинг
Resolver/старых interactive query paths не выполнялся.

## Ограниченное профилирование

Для current session GM `versions&profile=1` возвращает:

- version_query_count — только собственные prepared SELECT VersionQuery;
- server_ms и Server-Timing — время этого PHP endpoint до сериализации;
- memory_delta_bytes, peak_memory_bytes — память текущего PHP request;
- X-ACS-Payload-Bytes — фактическая длина сформированного JSON ответа.

Обычный игрок/observer эти поля не получает. Это инструментация конкретного
read path, не второй metrics store и не общий benchmark. Server-Timing не
выдаётся за сетевой TTFB и не учитывает весь native bootstrap.

**Фактическое профилирование SQL count, TTFB, memory и payload — NOT_VERIFIED:**
в рабочей среде отсутствуют PHP CLI и установленный MyBB/DB/runtime. Новая среда,
fixtures и сервер не устанавливались. Числа TTFB/памяти/размера на production не
выдуманы. Статически подтверждён только ceiling собственных SQL и SELECT shapes.
Владельцу: на изолированной установленной копии записать DevTools/curl TTFB,
Server-Timing, profiling JSON и payload header для одного и нескольких участников,
затем ordinary showthread/view/interactive с существующим SQL profiler. Отдельно
учесть native helper queries/общий bootstrap; отсутствие этих измерений не PASS.

## Файлы и проверки

Новые: Infrastructure/VersionQuery.php, Presentation/VersionController.php,
Presentation/SafeError.php, Presentation/RuntimeAssets.php, assets/runtime.js.
Изменены: advancedcombatsystem.php, manifest.php, Bootstrap/Context.php,
Presentation/{BuilderController,EditorPublicationController,ArchiveController,
ThreadComponents,ThreadPanel}.php, assets/{builder,publication,thread-panel}.js.
Этот отчёт: docs/design/acs/ACS_11_3_2_IMPLEMENTATION.md.

- **PASS:** node --check четырёх изменённых/нового JS (syntax only).
- **PASS:** git diff --check.
- **Статически проверено:** реальные routes/manifest/script order; request-bound
  scope and fence; equality tokens/own draft policy; CAS/version conflict paths,
  retained pending journals, synchronous double-click lock, rollback/lost ACK
  existing service boundaries и отсутствие automatic retry/new operation key;
  bounded batch SQL и отсутствие KB/Resolver/журнала на version path.
- **NOT_VERIFIED:** php -l — PHP CLI отсутствует; installed функциональные и
  performance проверки. Тесты/SQL-fixtures/browser visual suite не создавались
  и не запускались согласно AGENTS.md. Runtime assertions не выдаются за тесты.

Ручная матрица владельца: возврат focus/visibility/open без polling; concurrent
round/entity/control/delegation/target change; две вкладки draft/prose; AP/item
отказ; seal summary change без нового roll; timeout/lost ACK на save/seal/prepare/
publish; повтор прежней операции; двойной клик; revoke/outsider/hidden version
metadata; asset deny; ordinary thread inactivity; version read fence и GM-only
profiling. Проверить, что текст, действия, filters и existing sticky/editor не
теряются. Publication_enabled, installed acceptance, owner adapters, DDL,
миграции и immutable история не менялись. Приёмка остаётся NOT_VERIFIED.
