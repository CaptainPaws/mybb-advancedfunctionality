# ACS-11.1.5 — Единая панель GM на showthread

## База и источники

База — `origin/main` **2c22fee1**, merge ACS-11.1.4 (#1082), после `git fetch origin`. Изучены применимые A1.67–A1.68/A1.87/A1.89 Master Specification, Presentation/R2/typed GM части Technical Architecture, этап 11 Implementation Roadmap, IC-THEME/IC-EDITOR/owner/recovery Integration Contracts, интеграционные отчёты 7.3.3/8.3.3/9.3.4/10.4.3, контракт завершения 10.1.1 и предыдущие UI-отчёты.

Проверены существующие `ThreadComponents`, `Encounters`, `Sessions`, `RoundManagement`, `Delegations`, `Ambush`, `AmbushController`, `EndConditionController`, `RecoveryPage` и непосредственные Session/Encounter/Entity/Round/Clock/Delegation/EndCondition/Recovery контракты. Исторические отчёты и `docs/ROADMAP.md` не изменены. Следующая задача этапа 11 не начата.

## Найденные разрывы

- В GM-панели уже были вкладки, но регистрация существ и переходы сессии оставались на misc/ACP странице. Подготовительные side/participant формы находились в ветке «Управление», а не рядом с таблицей участников.
- «Обзор» говорил о завершении как о будущем этапе, не показывал session obligations. Состояния pending_resolution/start не имели понятной подписи.
- Шестишагового создания не было. Новый encounter без настроенных end_conditions не мог получить серверного кандидата завершения, хотя typed backend настройки уже существовал.
- GM-навигация зависела от active can_manage read-only панели; это мешало доступу к разрешённому reopening закрытой сессии.
- Round confirmation уже проверял backend, но ряду старых native форм удаления доступа/режимов/recovery не хватало подтверждения с последствиями. Ambush имел durable pending key, но не явное подтверждение перед готовностью/запуском/продолжением.

## Пять вкладок

Используется прежний ATF component `advancedcombatsystem::session_link`, slot `thread.before_posts`. Новых hooks, страниц MyBB или GM command services нет.

| Вкладка | Содержимое / существующие источники |
|---|---|
| Обзор | session/encounter status и версии, роли участников сессии, текущая страница состава, отдельная readiness-проверка и категории obligations из `SessionService::view(..., true)` |
| Участники | таблица состава с participation/life state, стороны/отношения, добавление в подготовку либо следующий roster, NPC controller modes, visibility, overlays, `EntitiesPage` и `DelegationsPage`; отдельная inspection текущей страницы |
| Раунды | сохранённый round roster/EntityTurns, AP/commitments, Clock controls, pause/resume/deadline/skip/next round; deadline policy доступна и до первого раунда; существующая AmbushPage |
| События | пагинированные заголовки существующего журнала; ссылка на полный разрешённый immutable archive с результатами, source posts и correction reviews; ошибки не выданы за applied mechanics |
| Управление | pre-start typed end-condition settings, серверный pending outcome и разрешённое continuation, session lifecycle/roles, lazy existing RecoveryPage, spatial/cover и существующий rest/reset |

Нативные ссылки вкладок имеют aria-current; CSS ограничен `.af-acs-gm`, переносит labels/числа и складывает навигацию на узком экране. Нового framework/tab JS нет. Неизменённые postbit/meta-line/sticky/AdvancedEditor не подменяются.

GM-формы выполняются до построения read-only ThreadPanel на явном GM request, поэтому его сводка читает состояние после подтверждённой команды. Ошибка GM renderer имеет безопасный error_id и не должна уничтожать доступную read-only панель. На закрытой сессии persisted owner/GM имеет отдельную ссылку в GM-панель; backend всё равно повторно проверяет разрешённость close/reopen/archive.

## Мастер создания

Явный scope `showthread.php?tid=…&acs_setup=1` использует тот же provider. Обычная тема не загружает мастер. Если сессии ещё нет, пользователю с существующим `SessionAccess::canCreate()` доступна лёгкая ссылка создания; гостю/остальным посетителям пустая GM-панель не показывается.

1. **Сессия:** список `SessionService::list(..., tid)` с фильтрацией доступного GM context. Новая сессия создаётся прежним `SessionService::command('create')`, с native TID, владельцем по умолчанию текущим пользователем, CSRF/reason/operation_key. Произвольные права не выдаются.
2. **Столкновение:** прежний create encounter, выбор существующего, закреплённая конфигурация; typed форма `ic-acs.end-conditions.v1` через существующий `EndConditionController::handle` → `EncounterService::command('end_conditions_set')`. Нет raw JSON editor, настройки доступны только до старта по session GM правам.
3. **Участники:** существующие source choices/validate/register, затем отдельное добавление зарегистрированной сущности, стороны, NPC assignments и разрешённое делегирование. Регистрация не означает включение в encounter автоматически.
4. **Проверка:** explicit readiness POST проверяет полный состав существующим EncounterService. Inspection показывает hash integrity snapshot, group ID, текущие HP/resources только для выбранной GM-страницы; это не доказательство installed readiness всех интеграций.
5. **Предпросмотр:** текущий состав и порядок страницы, группы/стороны/состояние, пагинация и отдельная повторная проверка полного состава. Нового round roster нет: preview не создаёт Round/EntityTurn/AP и не выдаётся за snapshot будущего старта.
6. **Запуск:** прежний round_start с checkbox/reason/key и повторной серверной readiness; либо существующая ambush ready/start с сохранённым intent. Если есть прежний start_operation_id, новая обычная команда запуска не предлагается.

Назад/Далее — только GET-навигация по сохранённым session/encounter IDs. Она не сохраняет незавершённые поля и не исполняет mutation; каждое изменение сохраняется отдельной явной формой. URL не подменяет выбранную сессию автоматически.

Постоянный pet source по умолчанию остаётся **BLOCKED**: реальный owner adapter не подключён. UI не обходит это вводом произвольного ID. GM видит регистрацию NPC/monster из разрешённого KB subset; character registration продолжает требовать собственный CS source. Нельзя зарегистрировать чужой лист только по GM-роли. Самостоятельные NPC сохраняют свои группы/ходы; питомцы — группу владельца по существующему контракту.

## Backend, права и повторы

Новые wire aliases `session_*`, `entity_request`, `delegation_request` в **существующем Presentation dispatcher** только адаптируют native формы к прежним SessionService/EntityService/DelegationService. Это не новые backend commands или вторые endpoints применения механики. End-condition requests собираются из типизированных controls и передаются строгому существующему EndConditionController. Legacy misc/ACP формы остаются доступны и совместимы с прежними service APIs.

Перед выводом и перед командой сохраняются native thread/session/GM проверки; POST session/TID/encounter сверяются с выбранным scope. CSRF, expected session/encounter/assignment/delegation/turn versions, operation_key, reason, audit и owner authorization остаются за существующими сервисами. Global ACP не заменяет дополнительные persisted session GM требования outcome/recovery/lifecycle.

`GmIntent` добавляет Presentation-подтверждение последствий для удаления/изменения доступа, controller/delegation/visibility/side modes, запуска, end conditions/outcome, lifecycle, recovery и разрешённого rest/custody. Native формы имеют required checkbox; Presentation проверяет **POST** confirmation, совместимую с существующими Round `confirm` и full-rest `confirm_full`. Это не grant права и не обход service guards. Для Ambush применяется явный диалог перед созданием pending key и перед продолжением прежнего запроса, без расширения строгого backend input schema.

Автоматических mutation retries нет. Native формы сохраняют свой operation_key в отправленном запросе; повтор исходной формы проходит старый receipt/CAS protocol. После INTERNAL_ERROR/RECOVERY_REQUIRED dispatcher не предлагает новые mutation forms в этом ответе и даёт отдельный read-only переход для проверки состояния. После неизвестного create session новый create key не предлагается; если receipt уже получен, а renderer упал, показывается ссылка на **созданную** сессию, а не новая форма создания.

Ambush по-прежнему сохраняет pending request/key и сначала проверяет status; unknown не становится разрешением нового start. При недоступном Ambush read остальные допустимые round controls не скрываются целиком, но альтернативный запуск при сохранённом intent не открывается. Полной crash/retry гарантии для arbitrary native HTTP/UI этим PR не доказывается: её service protocol сохранён, runtime acceptance не выполнена.

## Завершение, recovery и ограничения

End-condition UI поддерживает только действующие last_active_side/no_active_sides, draw/inconclusive и закреплённое continuation. Пока policy не сохранена, интерфейс предупреждает, что автоматического кандидата не будет. При pending_resolution GM выбирает **только outcome из server candidate**; winner_side_id клиентом не передаётся. Completion и session closure разделены. Нет кнопки принудительно назначить победителя/изменить HP или переоткрыть закрытый turn в обход контракта.

RecoveryPage загружается на вкладке «Управление» только по `gm_recovery=1` и своим существующим правам. Optional base URL позволяет прежним publication/custody/lookup формам оставаться на showthread; старый misc route сохранён. Lookup не получает лишнего checkbox и не превращается в повтор owner mutation. Запись решения/изменение custody требует отдельного подтверждения. Unknown PID не вводится/не связывается по uid/time/text; resolve не исполняет бой от имени автора.

Панель **не** добавляет универсальный Inventory/Balance editor, новые grants, compensation, immutable event editor или второй Resolver. Custody controls — те же ограниченные existing owner primitives с прежними available_commands и owner reserve/authorization; изменена лишь композиция/подтверждение/return URL. Награды, добыча, статистика и permanent sync остаются прежними отдельными contract-gated workflows. Publication flags/installed acceptance не менялись.

## Read model и диагностика

Новый `Infrastructure/GmPanelQuery::inspection` — только SELECT после текущего EncounterService GM context, до 100 разрешённых IDs страницы. Одна выборка state/snapshot hashes и одна resources; нет source calculators, KB catalog load, Resolver/Clock/RNG или SQL mutation. Readiness остаётся отдельной серверной проверкой всех участников, а не результатом этого короткого inspection.

Безопасные BLOCKED/RECOVERY_REQUIRED пояснения используют реальные service results/obligations; fallback недоступности не объявляет завершённый бой. Ambush errors дополнены category/error_id через существующую session failure диагностику. SQL/raw exceptions/owner envelopes не выводятся. `ACS_UI_CONFIRMATION_REQUIRED` добавлен только в validation error classification. Новый diagnostics backend не создан.

## Изменённые файлы

Относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

- Новые `modules/Presentation/GmPanel.php`, `GmIntent.php` — композиция пяти вкладок/мастера, typed forms и последствия.
- Новый `modules/Infrastructure/GmPanelQuery.php` — bounded read-only inspection.
- `modules/Presentation/Encounters.php`, `ThreadComponents.php` — маршрутизация/вкладки/шаги, закрытые сессии, свежая сводка, безопасные отказы.
- `modules/Presentation/Entities.php`, `Delegations.php` — optional embedded form routing, без смены owner permissions.
- `modules/Presentation/Sessions.php`, `RoundManagement.php`, `Clock.php` — согласованные подтверждения существующих native controls.
- `modules/Presentation/RecoveryPage.php`, `InventoryObligations.php` — optional showthread return URL и явные confirmation для mutation, сохранённый lookup.
- `modules/Presentation/Ambush.php`, `AmbushController.php`, `assets/ambush.js` — confirmation и безопасный error_id, прежний pending intent.
- `advancedcombatsystem.php` — только классификация UI confirmation error.
- `assets/presentation.css` — scoped GM/mobile styles; CSS/ambush asset version `1115`.
- Этот отчёт: `docs/design/acs/ACS_11_1_5_IMPLEMENTATION.md`.

Manifest не изменён: existing contextual presentation/ambush response facts достаточны. Application/Domain механика, migrations/DDL/ledger, права, postbit/ATF templates и editor не изменены. PHP/CSS/JS следует обновлять вместе.

## Проверки и ручная приёмка

- **PASS:** `node --check assets/ambush.js` (syntax only, без исполнения).
- **PASS:** `git diff --check`.
- **PASS, статически:** существующие service signatures/fields, строгий EndConditionController, native form scope, component/slot, lazy branches и confirmation routing. Inspection SQL не содержит mutations.
- **NOT_VERIFIED:** `php -l` 15 изменённых/новых PHP — PHP CLI отсутствует; runtime не устанавливался.
- Автоматические/regression/integration/SQL/browser tests не создавались и не запускались согласно AGENTS.md. Installed MyBB/ATF/owner addons/warprift.ru и функциональные сценарии не проверялись. Production readiness не заявляется.

Владельцу требуется ручная проверка на изолированной установленной копии:

1. Ordinary thread/outsider/player/observer/delegate: тяжёлого GM UI/ресурсных данных нет. Creator без сессии имеет только разрешённую точку входа; GM нескольких сессий выбирает нужную без подмены TID.
2. Все шесть шагов: create/select session, encounter/end policy, own character/NPC registration, blocked pet/foreign sheet, стороны/controller modes, paginated inspection/readiness/preview и явный обычный/ambush start. Навигация не мутирует данные.
3. Две вкладки и stale versions: CAS отказ, источник изменён/отключён, пустые/неверные source fields, без partial registration/AP refill/нового RNG. Правильное сохранение scope в forms и pagination.
4. Pause/resume/deadline/skip/next round, AP/roster, explicit boundaries/upkeep: required confirmations, backend reasons и audit; GET не запускает границы. Ambush double click/lost ACK/status/resume — только прежний intent/key.
5. Pending outcome → permitted confirm/continue, запрещённый winner/client outcome; completion отдельно от session close. Pending obligations/closed/archive/reopen сохраняют состояние и не рестартуют бой.
6. Remove/revoke/change controller/visibility/role: последствия, подтверждение и повторная серверная авторизация; самоудаление не открывает скрытые данные. Global ACP без session GM не получает outcome/recovery автоматически.
7. Inline и legacy misc/ACP recovery/custody: тот же old ID/key/CAS, confirmation mutations, доступный lookup, unknown PID не подтверждается и owner authorization не ослабляется. Unknown create/command не предлагает новую автоматическую отправку.
8. Mobile/zoom/keyboard/длинные имена/числа, native validation, ATF navigation/sticky и независимый AdvancedEditor. PHP lint выполнить до установки.

Матрица выше не выполнена Codex. PR подготовлен как draft для ручной приёмки, не как подтверждённая installed поставка.
