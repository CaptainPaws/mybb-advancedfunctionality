# ACS-11.4.1 — Functional UI Integration и Manual Acceptance Preparation

## База, объём, результат

База — синхронизированный `origin/main` **92af7d2f**, merge ACS-11.3.3 (#1088). Все отчёты ACS-11.1.1–11.3.3, включая исправление 11.1.1B, присутствуют. ACS-10.4.3 также присутствует: историческое замечание об отсутствующей зависимости в 11.1.1 не относится к текущему checkout.

Это один Integration/Documentation PR. Подготовлен [непосредственно заполняемый чеклист](ACS_STAGE_11_MANUAL_ACCEPTANCE_CHECKLIST.md) с разделами A–J и отдельным обязательным A-R2. Все 49 сценариев содержат страницу, роль, условия, действия, видимый результат, механическое ожидание, признаки сбоя, A1/этап, PASS/FAIL/BLOCKED и место для evidence. Все ручные сценарии изначально **не выполнены**.

Статически подтверждены существующие связи основных поверхностей с серверными сервисами и contextual assets. **Полная функциональная UI-целостность не объявляется:** обнаружены недостающие controls реакций и отмены подготовленного действия; имеются прежние owner/KB/installed blockers. Наличие backend API не выдано за доступный пользовательский маршрут. Для этих веток чеклист явно требует BLOCKED и отдельную задачу, а не прямой SQL/API обход. Новые игровые интерфейсы/контракты не добавлялись.

## Источники и приоритет

Рассмотрены применимые положения:

- [Master Specification](ACS_MASTER_SPECIFICATION.md): A1.1–A1.95, особенно UI A1.50–A1.68, permissions/control A1.41–A1.49, environment/advanced subset A1.70–A1.86 и A1.87–A1.95.
- [Technical Architecture](ACS_TECHNICAL_ARCHITECTURE.md): серверные DTO/API, актуальная R2 граница публикации, recovery, projection policy, Presentation/lifecycle/performance. Историческая общая MyBB/ACS transaction не является действующим контрактом.
- [Implementation Roadmap](ACS_IMPLEMENTATION_ROADMAP.md): Stage 11 и implemented/accepted граница; этап 12 не начинается этим PR.
- [Integration Contracts](../../contracts/ACS_INTEGRATION_CONTRACTS.md): R2/IC-MYBB-1/IC-EDITOR-1/IC-ACCEPTANCE-1, owner contracts и implementation amendments.
- [Revision 2 acceptance](ACS_4_5_R2_INTEGRATION_ACCEPTANCE.md), [6.3.2](ACS_6_3_2_IMPLEMENTATION.md), [7.3.3](ACS_7_3_3_IMPLEMENTATION.md), [8.3.3](ACS_8_3_3_IMPLEMENTATION.md), [9.3.4](ACS_9_3_4_IMPLEMENTATION.md), [10.4.3](ACS_10_4_3_IMPLEMENTATION.md).
- Все UI-отчёты: [11.1.1](ACS_11_1_1_IMPLEMENTATION.md), [11.1.1B](ACS_11_1_1B_IMPLEMENTATION.md), [11.1.2](ACS_11_1_2_IMPLEMENTATION.md), [11.1.3](ACS_11_1_3_IMPLEMENTATION.md), [11.1.4](ACS_11_1_4_IMPLEMENTATION.md), [11.1.5](ACS_11_1_5_IMPLEMENTATION.md), [11.1.6](ACS_11_1_6_IMPLEMENTATION.md), [11.2.1](ACS_11_2_1_IMPLEMENTATION.md), [11.3.1](ACS_11_3_1_IMPLEMENTATION.md), [11.3.2](ACS_11_3_2_IMPLEMENTATION.md), [11.3.3](ACS_11_3_3_IMPLEMENTATION.md); для ACP также 11.0.1.

Ограничения старых отчётов читаются по текущим source/amendments: например, фразы Stage 4 о несуществовавших consumers не отменяют позднейшие Inventory/Balance/Statistic/AAM реализации, но их installed acceptance по-прежнему не доказана. Утверждённые требования шире реализованных KB subsets; чеклист не объявляет все произвольные сочетания поддержанными.

## Подключение и фактические маршруты

Пути в следующей таблице относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`. Основной AF JSON endpoint: `xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem&op=...`. Не все native формы используют JSON: showthread/misc dispatchers вызывают те же services непосредственно.

| Пользовательский маршрут | Существующая цепочка UI → API / service | Статический вывод / ручной сценарий |
| --- | --- | --- |
| 1. Сессия/encounter | showthread `acs_setup=1` → ThreadComponents → GmPanel::setup / EncountersPage → SessionService::command(create), EncounterService::command(create) | Шесть шагов, сохранённые IDs, отдельные mutations; C-01 |
| 2. Регистрация | EntitiesPage / `entity_validate`, `entity_register` → EntityService → RegistrationSource/CS/KB/PermanentPetSource | Validation не регистрация и не admission; собственный CS/owner proof; C-01, E-01 |
| 3. Стороны/контроллеры | GmPanel/Encounters/Delegations → EncounterService/DelegationService; `encounter_*`, `delegation_*` | Side/relationship отдельно от ownership/assignment/overlay; C-02, E-03 |
| 4. Раунд | RoundManagement/Clock/Ambush → EncounterService round_start, RoundManagementService и ClockService; `round_manage_*`, `clock_*` | GET overview не выполняет boundary; отдельные POST/CAS/reason; C-03 |
| 5. Builder | `acs_builder=SID`, optional `acs_encounter=EID` → BuilderController → BuilderService; `builder_context`, `builder_validate`, `builder_preview` | Per-action server targets, ordered multiple, virtual sequence validation; C-04 |
| 6. Draft/seal/publication | `draft_save/restore`, `seal`, `seal_confirm`, `seal_status` → DraftService/SealService; native newreply compose/status/prepare/publish → EditorPublicationController → PublishService/SubmissionStore/Gateway/CombatCommitWriter | Один editor, CAS/retained identities; production gate закрыт; C-05–07, A-R2, J-05 |
| 7. Result/prose | ATF post.before_body → CombatCardQuery → CombatCard; native AdvancedEditor edit/Quick Edit | Read-only receipt/events, не новый Resolve; C-07, A-R2-05 |
| 8. Реакции/статусы/pets | `reaction_view/register/cancel` → ReactionService; SealService prepareReactionRolls; saved CombatCard reactions; actor state + ThreadPanelQuery | Backend и отображение существуют; регистрация/отмена реакции через текущий UI не найдены, см. R-01; D-01–02, E |
| 9. Items/environment/prepared | BuilderService::prepare → ItemActionService / ObjectInteractionService / SpatialService / CoverService / CooperationService; `prepared_publish/release/cancel` → PreparedActionService/RecoveryService | UI publish/release есть; active prepared cancel control не найден, см. R-02; D, F, G-01 |
| 10. Ambush/transform | AmbushPage/js → `ambush_status/ready/start` → AmbushPanelService/AmbushStartService; pinned transform/grant actions через существующий Builder/Resolver | Нет второго механического движка; fixed start intent/KB subset; G-02–03 |
| 11. Подтверждение исхода | CompletionPage outcome / GmPanel → EndConditionController → EncounterService confirmed resolution | Только server candidate; подтверждение не запускает новый Resolver; H-01–02 |
| 12. Loot/rewards/achievements | `misc?action=acs_loot` → CompletionPage → Loot/Allocation/Issue/Reward/Achievement controllers/services | Generation/allocation/owner issue разделены; права/receipts/retained key; H-03–06 |
| 13. Close/reopen/archive | GmPanel session controls / SessionsPage → SessionService lifecycle → obligations policy | Состояние/долги сохраняются, completed encounter не открывается; I-01 |
| 14. Archive/profile/statistics | ArchivePage → ArchiveService (`archive_events/roster/manifest/export`); APUI combat module → StatisticsService/AchievementService (`statistics_profile`, `achievement_profile`) | Lazy server pagination/reveal gates; seed template не installed proof; I-02–03 |
| 15. Diagnostics/debts | GmPanel management/recovery → RecoveryPage/CompletionPage; AF ACP manifest → admin.php → Diagnostics/AcpReadModel | Inspect отдельно от commands; global ACP не session GM; J-04–05, A-01–03 |

На showthread сохранена цепочка: AF enabled init → один `global_end` registration → ATF identity `advancedcombatsystem::session_link` в `thread.before_posts` → ATF `showthread_end` composer priority 100 → `{$atf_thread_before_posts}` в `templates/showthread.html`. Context содержит native TID; viewer берётся из authenticated MyBB, не из cosmetic context uid. Result provider использует отдельный `post.before_body`. Другие identities этого slot не заменяются. Installed callback execution/lease/template cache не проверялись.

Session discovery не требует `acs_session` в URL. Несколько разрешённых сессий не подменяют выбор автоматически; без encounter есть минимальная read-only панель. Разрешённые Builder/GM entry points загружаются явно. Read-side queries не создают encounter и не исполняют Resolver/Clock.

## Найденные UI-пробелы и реальные ограничения

| ID | Доказательство в текущем source | Последствие / disposition |
| --- | --- | --- |
| R-01 | advancedcombatsystem.php маршрутизирует reaction_view/register/cancel в ReactionService. В scoped Presentation/assets доступны вывод saved reactions и ApiController seal_prepare_reactions, но в BuilderController markup, builder.js command map и GM controls не найдено вызовов reaction_register/reaction_cancel | **UI setup/cancel реакций BLOCKED**. Это не отсутствие backend и не доказательство, что обычный seal не готовит reaction rolls (SealService делает это). Чтение сохранённых реакций проверяется отдельно. Нужна отдельная ограниченная UI-задача, не ручной mutation API вместо пользовательского маршрута |
| R-02 | ApiController поддерживает prepared_cancel, но builder.js routes содержит publish-prepared/release-prepared, без prepared_cancel control | **Отмена active prepared через UI BLOCKED**. Подготовка, отдельный publish и release unused reserve имеют существующие controls; не подменять cancel операцией release |
| R-03 | CombatCard provider в ThreadComponents catch возвращает пустую строку; в отличие от panel failure не создаёт уведомление/error_id в этой ветке | Ошибка card query/render может выглядеть как отсутствие карточки. Не заявляется полное покрытие safe errors всех surfaces. Проверять C-07/J-04; отдельное исправление диагностики карточки при воспроизведении, без раскрытия деталей игрокам |
| O-01 | Default composition `af_advancedcombatsystem_entities()` не предоставляет permanent pet owner adapter | Positive permanent-pet registration BLOCKED без подтверждённого provider. Не создавать фиктивного питомца через source ID/SQL |
| O-02 | RewardService view/inspect GM-only; CharacterSheets permanent sync не имеет нужного owner contract | Player reward-read и permanent sync не объявлены реализованными. Вкладка не-GM объясняет ограничение; никаких произвольных credit/compensation |
| O-03 | Loot owner grant ограничен pinned consumable/ammo; KB predicates/targets/spawn/spatial/transform имеют подтверждённые subsets | Неподдержанные сочетания не становятся capabilities из-за UI. Отсутствие категории/disabled reason не заменяется выдуманным действием |
| I-01 | Нет установленного MyBB/БД/темы/owner runtime в checkout | Appearance, ATF slot execution, one-editor lifecycle, installed engines/routing, browser/owner exactly-once — **NOT_VERIFIED** |

Обнаруженные пробелы перечислены явно, не «закрыты» документационным PASS. Это подготовка дальнейшей ручной приёмки, а не признание всего Stage 11 accepted.

## Безопасность: проверенные связи исходников

- SessionAccess::thread проверяет native forum/thread permissions, visibility и password ancestors; authenticated UID, role expiry и session/TID повторно проверяются services. Global ACP не заменяет session ownership/GM. Owner персонажа и owner сессии — разные понятия.
- AuthorizationService связывает current assignment/delegation/categories/overlay с сущностью; делегат не получает автоматически item spending, transfer/grant, CS write или GM права. NPC exclusive/shared/gm_pool не создаёт дополнительного бюджета.
- QueryService/BuilderService/AudienceService формируют разрешённые actors/targets и whitelist numeric projections. ThreadPanel скрытые строки отбрасывает до HTML/filters; awareness identity не даёт чужие HP/AP. CombatCardQuery читает сохранённые proof/events; partial/status-only audience не получает все подробности. Archive/export/profile и AAM сохраняют отдельные source/recipient policy gates.
- Draft/Seal/Session/Encounter/Reaction/Prepared/owner issue mutations идут через POST/SessionAccess::csrf или соответствующий controller guard, с прежними CAS/idempotency protocol. Read APIs context/status/versions/archive/profile не исполняют игровой command. Diagnostic error_id может сохраняться как служебная запись при ошибке; это не игровая GET mutation.
- Version equality tokens не являются grants или заменой write CAS. Scope/version конфликт не разрешает retarget, новые rolls либо silent server restore поверх другой вкладки.
- PublicationTestGate требует test environment, exact origin и UID allowlist, enabled dependencies; native bridge/Gateway сохраняет source/hooks gates. `publication_enabled=false` не изменён. Ни localhost source review, ни R2 checklist не подтверждает installed lifecycle.
- Outbox NotificationService вызывает отдельный owner receipt/lookup и не повторяет native posting hooks. Balance/Inventory issue/recovery сохраняют exact intent; один UI success без verified receipt недостаточен. AAM outage не откатывает бой.

Это статическая проверка вызовов/guards, не динамическая negative-role/CSRF/rollback certification. Полная матрица G/O/P/D/V/X, чужие SID/TID/EID и все шесть error categories вынесены в J.

## Производительность, assets и совместимость

| Область | Статическое свидетельство | Граница вывода |
| --- | --- | --- |
| inactive/view/interactive | Bootstrap/Context; ordinary showthread выполняет лёгкое membership discovery до heavy queries; Builder/GM require только после разрешённого scope | Нет утверждения «нулевой ACS PHP»: лёгкий bootstrap/lifecycle observers остаются |
| Assets | manifest contextual, routes=[], directory_fallback=false; runtime, thread_panel, presentation, builder, publication, ambush получают отдельные response facts | Resource names совпадают с renderer calls; фактические installed URL/loader/cache/404 требуют B/J |
| Dedup | ThreadComponents once/render cache; RuntimeAssets once; builder/publication per-root init guards; existing editor instance adapter вместо create/destroy SCEditor | Нет гарантии одного запроса на всю страницу: panel и Builder имеют разные watchers, каждый coalesces свой inflight. Проверить Network initiators J-03/J-07 |
| Updates | runtime.js focus/visibility/open/preflight, bounded single request timeout; builder validation/save debounce | Нет бесконечного network polling. setInterval thread-panel используется для local countdown, не API; AAM backend task — отдельная очередь |
| Batch reads | ThreadPanelQuery пакетно ресурсы/effects/barriers; EncounterRepository::cacheThreadPanelReads кэширует entity/controller/status reads; VersionQuery ≤6 собственных SELECT | Native helper queries отдельно. controlContext при temporary overlays может добавлять per-entity participant reads; вся audience/interactive цепочка не объявлена N+1-free |
| Lazy surfaces | Completion/Archive tabs, explicit GM recovery, APUI combat lazy fragment; server cursors | Профиль точных totals всё ещё bounded scan ≤1000 candidates, а не constant-query projection; archive/achievement sparse scans имеют caps |
| ATF/Editor | Существующие slots, scoped CSS, shared DOM panel; publication.js переносит существующий Builder к native textarea/editor instance; edit routes не decorate ACS | Installed geometry, hook order, duplicate-init и шаблоны проверяет владелец; основный postbit не меняется |

Широкого performance benchmark и SQL profiling в этом PR нет: runtime/БД отсутствуют, измерений TTFB/memory/count/payload не выдумано. Для непосредственного ручного замера J-07 содержит поверхности и отдельное GM versions&profile=1 с оговоркой о native queries. Кодовые batching/loader checks не заменяют эти измерения.

## Manual acceptance пакет

Чеклист содержит A (readiness), отдельный A-R2 (installed source/hooks, native full/Quick Reply, lost ACK/concurrency, pre/post INSERT/ACS rollback, moderation/edit/move, финальное решение), B (desktop/mobile/keyboard), C (ordinary combat), D (status/reaction/items), E (pet/spawn/NPC/delegation), F (environment/spatial/cover), G (prepared/ambush/transform), H (completion/loot/rewards), I (archive/profile/lifecycle), J (roles/secrecy/CSRF/errors/recovery/AAM/performance).

Есть паспорт версии/ролей/IDs/baseline и место для evidence каждого сценария. R2 fault scenarios допускаются только на изолированной копии владельцем, не в этом Codex-прогоне. Нет команд запуска fixtures или автоматических тестов. При отсутствии UI control/backend contract/installed grant положительный сценарий получает BLOCKED; успешный безопасный отказ не означает полную приёмку функции.

## Изменения и проверки

Изменены только два документа:

1. `docs/design/acs/ACS_11_4_1_IMPLEMENTATION.md` — этот source integration review, реальные blockers и пределы доказательства.
2. `docs/design/acs/ACS_STAGE_11_MANUAL_ACCEPTANCE_CHECKLIST.md` — сценарии и заполняемый протокол.

Runtime PHP/JS/CSS/manifest, Core/ATF/AdvancedEditor, schema/migrations, owner APIs, publication flags, acceptance state и `docs/ROADMAP.md` не изменены. Новых зависимостей, Resolver, команд, данных или тестов нет.

- `git diff --check`: PASS.
- Статически сверены реальные registrations/routes, controllers/services и их guards, response facts/resource names, batch/cache paths и отсутствие нового frontend editor.
- Проверена целостность относительных ссылок двух новых документов и наличие обязательных разделов/полей чеклиста (неисполняющая документная проверка).
- PHP/JS не изменены: syntax checks не требуются; PHP CLI отсутствует. Ничего не устанавливалось.
- Автоматические/regression/integration/SQL fixtures/browser tests и visual suite не создавались и не запускались согласно AGENTS.md. Runtime/server API запросов к форуму не выполнялось.

Итог: пакет готов для пользовательской ручной проверки с явно зафиксированными BLOCKED/NOT_VERIFIED ветками. Production readiness не заявлена; R2 остаётся обязательной, production publication закрыта. После пользовательской приёмки — отдельные задачи исправления и стилизации по утверждённому макету. Автоматический переход к этапу 12 не выполняется.
