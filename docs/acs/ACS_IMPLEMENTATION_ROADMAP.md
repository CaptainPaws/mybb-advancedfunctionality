# AdvancedCombatSystem 1.0 — Implementation Roadmap

## 1. Порядок работы и статусы

Основание: [Master Specification](ACS_MASTER_SPECIFICATION.md), [Technical Architecture](ACS_TECHNICAL_ARCHITECTURE.md), [Integration Contracts](ACS_INTEGRATION_CONTRACTS.md). Проект warprift.ru, MyBB 1.8.40, PHP 8.5.0. Это план разработки; исходные пути/доказательства аудита зафиксированы в Contracts. Новые проектные пути и контракты ниже **PROPOSED**. ACS-DOC-01 не присваивает новые статусы реализации или приёмки и не переоткрывает принятый этап 1.

Ровно 12 функциональных этапов; внутри — несколько ограниченных задач и PR. Зависимости технические, а не разрешение пропустить игровую механику. Минимальный UI для ручной проверки развивается с этапов 2–4, этап 11 завершает UX. Нельзя ждать этапа 11, чтобы впервые проверить бой на форуме.

| Статус | Смысл / кто переводит |
|---|---|
| implemented | код этапа реализован, ограниченные технические проверки пройдены; отчёт Codex со ссылкой на commit/PR |
| ready_for_manual_test | поставка подготовлена на форуме, описаны точные сценарии/предусловия/известные ограничения |
| accepted | заказчик подтвердил результат после ручной форумной проверки |
| blocked | конкретное препятствие: отсутствующий безопасный контракт, конфликт зависимости либо неподтверждённая публикационная граница; указаны owner задачи и способ разблокировки |

До начала реализации статус этапа пустой (—). Это отсутствие результата, не пятое состояние приёмки. Переход implemented→ready_for_manual_test→accepted требует доказательств; PR merge не является финальной приёмкой. В blocked можно перейти из любого незавершённого состояния с сохранением выполненного. Разблокировка возвращает проверяемую работу, не автоматически accepted.

Общие инварианты для каждого PR: нет правок ядра MyBB, глобального рефакторинга, смены механики или второго SCEditor; immutable tape/events, общий AP и один group combat_post/round, idempotency, целостность Inventory, server secrecy и pinned KB versions. Не вводить неподтверждённые default accuracy/формулы/награды. Отсутствующее safe API сначала отдельной интеграционной задачей. **Полные тестовые комплексы не запускать**: проверки минимальны и связаны с изменением, финальная игровая/визуальная проверка ручная. A1.94–A1.95.

## 2. Шаблон ограниченной задачи Codex

Каждое задание переносится в отдельную задачу с полями:

1. Контекст: версии, репозиторий, проверенный SHA main, baseline revision, номер этапа/задачи.
2. Одна цель и ожидаемое наблюдаемое поведение.
3. Реальные existing paths после повторного аудита + явно помеченные proposed new paths.
4. Идентификаторы требований **и необходимая выдержка из Master**, а не только «сделать A1.N».
5. Предварительные зависимости и точные IC contracts/capabilities.
6. Разрешённый scope и общие запреты; неподходящие соседние компоненты не рефакторить.
7. Критерии готовности и минимальные проверки данного PR.
8. Ручные сценарии на форуме с ролями/исходными данными/ожидаемым результатом.
9. Отчёт: изменённые файлы, commit/PR, выполненные проверки, ограничения/blocked, инструкция отката и восстановления.

Любая новая техническая подробность отличается от утверждённого игрового правила. Изменение baseline: исходный ID, старое/новое поведение, явное согласование, влияние на данные/контракты/миграции, обновление всех четырёх документов. Не переписывать прошлые events для приведения к новым правилам.

**Действующая редакция D1.4:** [ACS-DOC-01 от 2026-10-21](ACS_MASTER_SPECIFICATION.md#element-taxonomy), 17 основных стихий и дочерние направления без собственного RES. Она меняет только перечень/классификацию; математика и порядок H1/D1/S1 прежние. Канонические keys: `fire`, `water`, `air`, `earth`, `ice`, `lightning`, `nature`, `light`, `shadow`, `death`, `life`, `chaos`, `mind`, `abyss`, `gravity`, `astral`, `space`. Новые element capabilities — PROPOSED до реализации и приёмки; обычные форумные операции от их отсутствия не блокируются.

## 3. Этапы

<a id="stage-1"></a>
### Этап 1. Фундамент аддона

**Цель:** Зарегистрировать безопасный модуль AF, lifecycle, права и диагностику контрактов без вмешательства в обычные темы.

**Требования:** A1.41, A1.67, A1.89–A1.95; N1/N2 как политика версий.

**Зависимости:** Нет предыдущих этапов; актуальный audit main, AF manifest/router.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: manifest.php; advancedcombatsystem.php; admin.php; modules/Bootstrap/Context.php; modules/Administration/{Diagnostics,Migrations}.php; modules/Infrastructure/{Repository,OperationStore,SnapshotStore}.php; migrations/001_*.php. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 1.1: Реализовать discovery/bootstrap и режим inactive; подключить только собственный manifest/lang и ACP router. Не подключать Resolver на обычном showthread.
- 1.2: Создать resumable migration runner и фундамент sessions/roles/snapshots/operations/events/event_details/diagnostics. Разделить install/activate/deactivate/upgrade/uninstall; не создавать всю функциональность заранее.
- 1.3: Добавить capability report IC-CORE/KB/CS/INV/MYBB и granular permissions; установить явные blocked причины для отсутствующих контрактов, без небезопасных fallback.

**Ожидаемый результат:** Устанавливаемый пустой ACS, сохраняющий данные при деактивации и показывающий реальную готовность зависимостей.

**Критерии готовности:**

- Пустая обычная тема не получает heavy runtime, новую панель или лишний SCEditor.
- Реактивация не перезаписывает данные/внешние шаблоны. Все новые символы и таблицы документированы.
- ACP Diagnostics отделяет чтение от исправляющих команд. Нет возможности отключить event immutability и права.

**Минимальные технические проверки:** php -l новых файлов; manifest/dispatch/lifecycle contract; перечень созданных таблиц и индексов, один interrupted migration fixture. Полные suites не запускать.

**Ручная приёмка на форуме:**

1. Установить и включить ACS в ACP, открыть обычную тему: штатные функции AF работают.
2. Деактивировать/активировать повторно: сохранённые настройки и таблицы не сброшены.
3. Отключить необходимый аддон: Diagnostics показывает конкретный контракт; обычная тема открывается.

<a id="stage-2"></a>
### Этап 2. Сессии, участники, снимки, раунды и AP

**Цель:** Дать GM создать сессию/столкновение и начать проверяемый раунд с корректными группами и clocks.

**Требования:** A1.1–A1.10, A1.39–A1.48, A1.67–A1.70; S1.4/S1.11–S1.16 (границы); H1/S1.2 (данные).

**Зависимости:** 1; IC-KB/IC-CS/IC-FIELDS; owner content policy для отсутствующей accuracy.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: modules/Application/{SessionService,EncounterService,AuthorizationService}.php; modules/Domain/{Clock,Control}.php; Adapters/{KnowledgeBaseAdapter,CharacterSheetAdapter}.php; migrations/002_*.php; scoped изменения существующих KB/CS/Fields. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 2.1: Ограниченные integration PR: accuracy registry/CS/Fields/bestiary round trip (ACS-2.1.1–2.1.2), реестр 17 основных стихий и направления (ACS-2.1.3 ниже), явное сопоставление status_resist/status_resistance, snapshot source breakdown. Не назначать legacy accuracy догадкой. Исторический seed исходного аудита содержит восемь записей и не доказывает полноту нового набора.
- 2.2: Сессии/роли/стороны/существа/NPC registration, immutable snapshots. Разделить владельца/контроллера/группу/жизненное состояние; минимальная GM-форма через разрешённый Theme provider.
- 2.3: Round roster, один group turn, refresh 3 AP, индивидуальные boundaries/cooldowns/rest, pause/deadline freeze и idempotent skip. Минимальные интерфейсы нужны сразу.

**Ожидаемый результат:** GM регистрирует группу персонажа с питомцем и отдельных монстров, получает первый раунд и отдельные/общие бюджеты.

**Критерии готовности:**

- Один refresh/tick/decrement на boundary даже при повторе; новые группы обычно входят со следующего раунда.
- Снимок не меняется после редактирования KB/CS, equipment не считается дважды.
- Rest/paused/skip не выводятся из количества литературных постов; inactive/dead и pending turn независимы.

**Минимальные технические проверки:** Точечные fixtures snapshot provenance, duplicate boundary, cooldown=2, AP refresh, pause/resume дедлайна; php -l и JSON round trip в затронутых редакторах.

**Ручная приёмка на форуме:**

1. Создать сессию, добавить персонажа/питомца и двух монстров: общий AP 3 у игрока, отдельный у NPC.
2. Перезагрузить панель: AP/таймеры не обновляются повторно; новый NPC ждёт следующего roster.
3. Поставить паузу и возобновить: дедлайн сохраняет остаток; короткий отдых выполняется один раз и не меняет исходный лист.

<a id="acs-2-1-3"></a>
#### ACS-2.1.3 — Реестр 17 основных стихий и дочерние направления

**Основание/зависимости:** действующее [D1.4 / ACS-DOC-01](ACS_MASTER_SPECIFICATION.md#d1-4), [Architecture §5.2.1](ACS_TECHNICAL_ARCHITECTURE.md#element-direction-contract), [IC-KB-2 capabilities](ACS_INTEGRATION_CONTRACTS.md#element-capabilities), IC-CS-ELEMENTS и IC-FIELDS-ELEMENTS. Принятый этап 1 не переоткрывается; задачи Accuracy 2.1.1–2.1.2 не заменяются новой элементальной механикой. ACS-DOC-01 — только документация, реализация 2.1.3 не начинается автоматически.

**Scope будущей задачи:** KB registry/schema/editors/normalizers, необходимые ограниченные изменения CS/Fields и контракт ACS adapters. Схема направлений PROPOSED; использовать расширяемые KB definitions и существующие versioned payloads без 17 фиксированных SQL-полей или предварительного создания всей схемы будущих этапов.

**Критерии приёмки:**

1. Реестр содержит ровно 17 основных identities с ключами D1.4. У каждой собственный неотрицательный числовой RES; у дочерних направлений, включая blood, отдельного базового RES нет.
2. Направление имеет стабильный ключ, ровно одного родителя и единственный основной root; self-parent/циклы/оборванные ссылки/коллизии диагностируются. Новое направление добавляется через KB без изменения PHP-реестра основных стихий. Нет искусственного игрового лимита числа направлений, технические пределы валидируются явно.
3. Классификация направления, фактический effect/damage_kind и damage_element разделены. Blood может иметь physical, явный elemental/life, status либо heal по определению KB; parent не назначает damage_element автоматически. Бездна/Тьма, Жизнь/Смерть, Пространство/Гравитация, Астрал/Разум остаются различными.
4. Wind→air совместимость работает на boundary с сохранением исходных keys/версий; конфликт wind/air диагностируется без суммирования RES, destructive migration или переписывания legacy-записей. Прочие legacy aliases не угадываются. Отсутствующие значения и несовместимые версии не становятся нулями.
5. Save/read/normalize и передача KB→CS/бестиарий→ACS snapshot сохраняют registry/definition/schema/contract/alias-policy versions, hashes, direction/parent refs и явный damage_element. Изменение KB создаёт новую версию, прежний snapshot/результат неизменен.
6. `canonical_elements_17`, `magic_direction_hierarchy`, `explicit_damage_element`, `legacy_element_aliases`, `element_snapshot_provenance` проходят отдельную capability validation. Наличие функции/JSON не означает READY; неподдержанное блокирует только зависимую ACS-операцию.
7. Формула D1.4, порядок RES/уязвимостей/иммунитетов и H1/S1 не меняются; нет дополнительных бонусов дерева, контрстихий или elemental reaction matrix. Примеры направлений не создаются автоматически как данные.

**Минимальные проверки будущего PR:** точечный round trip 17 keys/RES и нового направления, nested parent и отрицательная проверка цикла, legacy wind/air conflict, missing-value и pinned version/hash mismatch. Проверить отсутствие лишнего RES и implicit damage, сохранность старого snapshot после изменения KB. Ручная приёмка — KB editor/CS/бестиарий/ACS gate; без полного suite. Эти проверки реализации не выполняются в документационном ACS-DOC-01.

**Связанные задания без изменения последовательности этапов:**

| Этап / задания | Зависимость от нового контракта |
|---|---|
| 2.1.3, 2.2 | Подготовка definitions и snapshots персонажей/NPC/бестиария, canonical normalization, версии и source breakdown |
| 3.1–3.2 | Проверить pinned capability и явный damage_element до прежнего RES pipeline; классификация направления не меняет формулу |
| 5.1–5.3 | Status/heal/damage/reactions используют явный effect contract; направление не определяет эффект или RES |
| 7.2 | Призывы/NPC получают тот же реестр и snapshot provenance; направление не создаёт дополнительную защиту |
| 8.1–8.3 | Объекты/окружение сохраняют собственные явные damage_element/RES definitions; название направления не создаёт взаимодействий |
| 9.1–9.3 | Prepared/transform/copy/steal фиксируют версии направлений/родителей и фактического воздействия, не перенормализуют запечатанное текущей KB |
| 12.1–12.3 | Проверить совместимость wind/air и старых snapshot versions, отсутствие лишних RES/implicit damage, неизменность формул и истории |

<a id="stage-3"></a>
### Этап 3. Базовый резолвер и числовая модель

**Цель:** Реализовать единственную детерминированную математику ударов и базовых последствий по pinned KB.

**Требования:** N1/N2, M1, H1, C1/C1.2–C1.4, D1–D1.16; A1.11–A1.13, A1.22–A1.27; S1.12.

**Зависимости:** 1–2; исполнимые pinned formula_def и capability validation; минимальный UI preview.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: modules/Domain/{Numeric,Formula,Modifiers,Resolver,Effects}.php; modules/Application/QueryService.php; migrations/003_*.php (минимальные effects + barrier_pools/targets); KB adapter. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 3.1: AST compiler/evaluator, единицы, binary64, терминальное rounding/clamp, fixed phase modifiers/set conflicts; без eval и legacy формул-догадок.
- 3.2: Hit K=30, crit per hit, DEF/RES/vulnerability/penetration и mixed integer budget largest remainder. Сначала чистые virtual-state effects, затем общий trace DTO.
- 3.3: FIFO/shared_pool barriers, HP states, HP costs, reflection/lifesteal и end-condition signal. Не публиковать посты отдельным путём и не выдавать награды на preview.

**Ожидаемый результат:** Серверный preview объясняет допустимость и математику базового боя без случайных бросков и без мутаций.

**Критерии готовности:**

- Математика буквально соответствует ранним решениям; 0/0 hit=75, иммунитеты и zero damage корректны.
- D1.13 одна граница до барьеров, сумма allocation=B, нет повторного rounding HP.
- Трасса различает raw/rounded/clamped/absorbed/hp_loss; snapshot и engine version достаточны для воспроизведения.

**Минимальные технические проверки:** Небольшие таблицы числовых примеров: hit 0/0,0/N,N/0; mixed 0.4+0.4 и 2.7+1.8+0.6; filter/FIFO/shared pool; конфликт set; div0/NaN/overflow. Не создавать общий игровой симулятор.

**Ручная приёмка на форуме:**

1. В минимальном конструкторе выбрать базовую атаку: preview показывает шанс, но не создаёт roll и не расходует AP.
2. Сравнить trace физического и элементального удара с иммунитетом/пробиванием.
3. Проверить mixed damage и два барьера; нанесение 0 урона остаётся попаданием.

<a id="stage-4"></a>
### Этап 4. Черновики, запечатывание и атомарная публикация

**Цель:** Связать один combat_post с неизменяемой механикой и редактируемой прозой; обеспечить защиту повторов.

**Требования:** A1.13–A1.16, A1.46, A1.49–A1.52, A1.55–A1.60, A1.62, A1.89–A1.91.

**Зависимости:** 1–3; **IC-MYBB-1 и IC-EDITOR-1 должны быть доказаны** на установленном MyBB 1.8.40. До 6 допускаются только действия без внешнего inventory expense.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: modules/Application/{DraftService,SealService,PublishService,RecoveryService}.php; Adapters/MybbPostGateway.php; modules/Presentation/{ApiController,BuilderController,ThreadComponents}.php; migrations/004_*.php; scoped Editor/Theme form extension. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 4.1: Сначала отдельный integration audit/PR: штатный PostDataHandler, hooks/engines/moderation/attachments/flood и побочные начисления AF; доказать transaction coordinator без правок ядра. Если не доказано — blocked, не прямой SQL posts.
- 4.2: Server draft/CAS/autosave, append-only sealed actions, immutable random tape и общая AP quota, отмена только хвоста. Confirm привязать к версии simulation; preview не RNG.
- 4.3: Idempotent submission registration→common transaction→pid/events/projections/post_links/turn resolved/outbox; unknown outcome→lookup/recovery. Карточка в Theme post.before_body, prose отдельно.
- 4.4: Поддержать narrative mode и штатное редактирование/Quick Edit без resolver. Проверить две вкладки и потерю HTTP response.

**Ожидаемый результат:** Один атомарный боевой пост за ход группы; механическая карточка и литература в одном MyBB-сообщении.

**Критерии готовности:**

- Дубликат submission возвращает тот же pid, AP/HP не применяются дважды; один post_link на turn защищён UNIQUE.
- Cancel не возвращает seal quota и не даёт нового roll; invalid action блокирует весь пост.
- Изменившийся результат требует подтверждения с тем же tape; проза и draft сохраняются при ошибке.
- Реальная MyBB validation и side effects участвуют в безопасном протоколе.

**Минимальные технические проверки:** php -l; точечные transaction rollback/duplicate key/response loss/CAS fixtures; gate review engines/hooks; отсутствие resolver на text edit. Не запускать полноценные комплексы.

**Ручная приёмка на форуме:**

1. Seal атаку, написать отыгрыш, отправить дважды: один пост и одно списание.
2. Отменить последнее действие, вновь открыть editor: прежние броски/квота сохранены.
3. Изменить цель/ресурс другим разрешённым событием: commit показывает конфликт, не новый roll.
4. Quick Edit меняет только прозу, карточка/HP/AP прежние; narrative post не закрывает боевой ход.

<a id="stage-5"></a>
### Этап 5. Статусы, реакции и способности

**Цель:** Завершить жизненный цикл эффектов и реакций через общий резолвер.

**Требования:** S1.1–S1.16; A1.4–A1.6, A1.17–A1.26; C1.3/C1.4.

**Зависимости:** 1–4; KB status/trigger/condition/modifier contracts и базовые barrier pools этапа 3.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: modules/Domain/{Effects,Clock,Resolver}.php; KnowledgeBaseAdapter; migrations/005_*.php (effects/reactions/cooldowns при отсутствии foundation); minimal UI details. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 5.1: Status probability, source instances, refresh/max/stack limits, own/target/round timing, cleanse/dispel. Добавить KB capability tests только для исполняемых типов.
- 5.2: Sustained priority/FIFO, atomic all-resource upkeep, zero-HP interruptions, no repeat tick. Использовать boundary keys этапа 2.
- 5.3: Phased reactions, one per creature/round, affordability skip, intercept original/resolved, mass defense, anti-cycle guards. Не делать независимую реакционную публикацию.

**Ожидаемый результат:** Способности имеют завершённые серверные статусы/реакции, понятные детали в карточке и журнале.

**Критерии готовности:**

- Три тика дают три применения, контроль 1 ход не снимается до его окончания.
- Source separation/stack_max/refresh/partial cleanse работают по KB.
- Неплатёжная реакция не ломает чужую атаку; цикл откатывает всю публикацию без reroll.

**Минимальные технические проверки:** Точечные fixtures status 0/0/immune, duration last tick, stack cap, partial cleanse, upkeep insufficient, intercept fixed tape и reaction duplicate/cycle.

**Ручная приёмка на форуме:**

1. Наложить яд на 3 хода и контроль на 1: наблюдать начало/конец и один тик на boundary.
2. Повторно наложить от двух источников, снять один stack/старый экземпляр.
3. Перехватить атаку существом с иной Speed/DEF: те же roll values, актуальный resolved_target.
4. У реакции нет ресурса: чужой пост проходит, лимит реакции не списан.

<a id="stage-6"></a>
### Этап 6. Инвентарь, экипировка и custody

**Цель:** Обеспечить безопасные предметные действия и глобальные резервации без дублирования имущества.

**Требования:** A1.2–A1.3, A1.11–A1.13, A1.32–A1.33, A1.60; N2; подготовка A1.75.

**Зависимости:** 1–5; **сначала IC-INV-1**, аудит всех Inventory/Shop writers; общий publish coordinator.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: существующие advancedinventory/advancedinventory.php, advancedshop/advancedshop.php (scoped); Adapters/InventoryAdapter.php; modules/Domain/Effects.php; migrations/006_*.php; equipment UI. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 6.1: Отдельный Inventory PR reserve/consume/release/transfer/grant/lookup с operation_key, qty/version и глобальным запретом обхода резервов через sell/delete/equip. Обычный remove_item не использовать как safe API.
- 6.2: Пагинируемый read/snapshot equipment, AP actions equip/swap/consume/pass; source provenance предотвращает двойные бонусы. Расход с combat commit, не с preview.
- 6.3: Custody и временное использование без смены uid, один активный пользователь в сессии; parallel-session isolation и сохранение обязательств при close. Recovery неизвестного external результата.

**Ожидаемый результат:** Предмет расходуется/передаётся один раз; боевой комплект и владение согласованы, незавершённые резервации восстанавливаются.

**Критерии готовности:**

- Две сессии не тратят одну и ту же глобально зарезервированную единицу.
- Qty не уходит в минус/не удаляется от excessive requested amount; owner API различает отказ и успех.
- Swap 1 AP в бою, вне боя бесплатно; custody не передаёт собственность автоматически.

**Минимальные технические проверки:** Контрактные fixtures reserve races/duplicate consume/release/over-request/unknown result; полный список writers с reservation checks; item snapshot pagination; php -l затронутых файлов.

**Ручная приёмка на форуме:**

1. Зарезервировать зелье в ACS, попытаться продать/удалить его из Inventory: корректный отказ.
2. Промахнуться предметным действием: ресурс/предмет оплачены ровно один раз при публикации.
3. Конфисковать оружие и временно выдать хранителю: бонус только у одного пользователя, uid не меняется.
4. Отключить зависимость/оборвать ответ: recovery сохраняет резерв, не повторяет расход.

<a id="stage-7"></a>
### Этап 7. Питомцы, призывы и управление

**Цель:** Покрыть все модели управления без новых AP и повторных ходов.

**Требования:** A1.1, A1.9, A1.30, A1.41–A1.43, A1.47–A1.49, A1.69, A1.78–A1.80, A1.84.

**Зависимости:** 1–6; KB spawn/control contracts, owner/delegation rights.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: modules/Domain/{Control,AdvancedEffects,Resolver}.php; AuthorizationService; entities/controllers/delegations/effects extensions; GM/builder actor selector. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 7.1: Exclusive/shared/gm_pool NPC и granular delegation/revocation. GM не получает чужие consumables/ownership rights; review current permission при commit.
- 7.2: spawn_entity/spawn_object identity и source_event+spawn_index dedupe, group AP/roster integration, lifetime/sustained/despawn limits KB.
- 7.3: side_change/control_effect overlay/alliances/behavior constraints; unchanged AP/closed turns; relations-dependent targets revalidate с прежними rolls.

**Ожидаемый результат:** Персонажи, питомцы, NPC и временно управляемые существа используют одну модель прав и бюджета.

**Критерии готовности:**

- Делегат и владелец разделяют AP/quota/deadline, отзыв действует на будущий commit.
- Spawn не даёт второй пост/бюджет; независимый NPC обычно со следующего раунда.
- Сторона, контроллер, собственник и принудительное поведение не смешиваются.

**Минимальные технические проверки:** Точечная permission matrix/overlay restore/revocation race; duplicate spawn; roster mid-round; one turn per group; fixtures constraints priority/no legal target.

**Ручная приёмка на форуме:**

1. Чередовать действия персонажа и питомца в одном посте с AP 3.
2. Делегировать обычные действия без расходников: делегат видит запрет расхода; отзыв блокирует позднюю отправку.
3. Призвать существо после части расходов: только оставшийся AP, без второго поста.
4. Сменить сторону/временного контроллера: закрытый ход не открывается и закрытые сведения не раскрываются.

<a id="stage-8"></a>
### Этап 8. Окружение и пространственная механика

**Цель:** Добавить объекты, обнаружение, зоны, укрытия и коллективные задачи без координатной карты.

**Требования:** A1.57, A1.70–A1.74, A1.79, A1.81–A1.83.

**Зависимости:** 1–7; KB object/spatial/interaction/awareness contracts; Inventory tools.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: modules/Domain/{Environment,Spatial,Resolver}.php; QueryService; migrations/008_*.php (objects/zones/connections/positions/engagements/cover/awareness/objectives/contributions); lazy GM/detail UI. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 8.1: Object instance passive/triggered/interactive/destructible, object_version, detection levels и audience scopes; server filtering до DTO.
- 8.2: object_interaction outcomes/progress/tools/retries, assist и cooperative contributions только подтверждёнными событиями.
- 8.3: Условные зоны/engagement/range/line_of_effect и cover; переход по умолчанию 1 AP; forced move отдельный KB effect. Не добавлять grid/pathfinder.

**Ожидаемый результат:** Окружение участвует в общем резолвере, а скрытые ловушки и позиции не утекут через UI/агрегаты.

**Критерии готовности:**

- Unknown объекты не попадают в JSON/disabled choices/totals; mechanics не равна awareness.
- Общий progress идемпотентен по contribution event, литературная помощь ничего не начисляет.
- Ignores_cover не означает penetrates_obstacles; перехватчик не наследует укрытие прежней цели.

**Минимальные технические проверки:** Контракты hidden DTO/aggregation, object_version conflict, duplicate contribution, tools present vs consume, zone edge/LOE и cover destruction.

**Ручная приёмка на форуме:**

1. GM создаёт скрытую ловушку; игроки не видят её ни в карточках, ни в JSON.
2. Выполнить разрешённый поиск/передачу информации: уровень знаний меняется только нужной аудитории.
3. Разрушить препятствие: изменяется переход/укрытие по KB, запечатанный зависимый пост требует проверки.
4. Два игрока вносят помощь последовательно: прогресс без повторного применения при retry.

<a id="stage-9"></a>
### Этап 9. Продвинутые способности и засады

**Цель:** Реализовать prepared actions, стартовые засады, формы и переносы эффектов через общий pipeline.

**Требования:** A1.75–A1.77, A1.80–A1.86; A1.17–A1.26; S1.13–S1.16.

**Зависимости:** 1–8; pinned contracts prepared/ambush/transform/copy/reflect; safe Inventory reservations.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: modules/Domain/{AdvancedEffects,Effects,Resolver}.php; SealService; EncounterService; migrations/009_*.php (prepared_actions/executions); KB capability extensions. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 9.1: prepared_action paid contract, reserved items, until_next_turn default, trigger-time random tape one per prepared/trigger key. Нет нового поста/хода или повторного AP.
- 9.2: Hidden ambush и preparation→ambush_ready→encounter_start→ambush_resolution, pre_round_ap_commitment и start_operation_id. Первый раунд не получает бесплатные AP.
- 9.3: transform same entity/absolute HP/equipment retain-suppress-adapt/status policies; copy/steal/dispel/reflect/absorb causal child effects без повторных ticks/стоимости.

**Ожидаемый результат:** Специальные механики работают как типизированные эффекты с общей историей и защитой циклов.

**Критерии готовности:**

- Detection не отменяет prepared без KB; hidden action awareness отдельно entity awareness.
- Paid AP подготовки учитывается в первом бюджете; старт однократен, без бесплатного раунда.
- Один primary form, никаких AP/cooldown reset; перенос не повторяет уже исполненные тики.

**Минимальные технические проверки:** Точечные causal trigger duplicate/cycle/depth fixtures, pre_round budget, interrupted start, transform HP clamp и cooldown retain, effect transfer provenance.

**Ручная приёмка на форуме:**

1. Подготовить засаду, перезагрузить/повторить trigger: один результат и одна стоимость.
2. Стартовать с потраченными подготовкой AP: нет новых бесплатных действий.
3. Превратиться с меньшим max HP: абсолютное HP ограничено максимумом, предметы не исчезают из Inventory.
4. Перенести статус и отразить эффект: прежние тики не повторяются, стандартная бесконечная цепочка невозможна.

<a id="stage-10"></a>
### Этап 10. Завершение, архив, добыча, награды и статистика

**Цель:** Завершать столкновения с сохранением обязательств и выдавать подтверждённые награды ровно один раз.

**Требования:** A1.27–A1.40, A1.62–A1.66; A1.69/A1.78 (история участия).

**Зависимости:** 1–9; IC-INV grant и **IC-BALANCE idempotent gateway**, отдельные постоянные sync handlers.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: modules/Domain/Rewards.php; Application/{RewardService,CorrectionService}.php; Adapters/{BalanceAdapter,StatisticsAdapter,InventoryAdapter}.php; migrations/010_*.php; archive/profile query endpoints; scoped balance/AdvancedStatistic/APUI extensions. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 10.1: End conditions/pending_resolution/GM confirmation, surrender/escape/capture/rejoin, close/reopen/archive без refill и потери custody/loot/ops.
- 10.2: Immutable loot generation guaranteed/independent_roll/weighted_pool, gm_assign/free_claim/need_greed и reserved winners. Отдельно выдача через safe owner API.
- 10.3: Currency/EXP/permanent sync gateways с operation_key+lookup; достижения и review correction без автоматического изъятия наград.
- 10.4: Архив/JSON/CSV/статистика из confirmed completed events, aggregate versions/visibility, отдельный AdvancedStatistic consumer и profile tab contract.

**Ожидаемый результат:** Полный жизненный цикл боя и сессии, воспроизводимый архив, безопасные награды и агрегаты.

**Критерии готовности:**

- Luck не меняет loot глобально; питомцы не умножают group rewards.
- Ошибка выдачи сохраняет победителя; duplicate confirmation не генерирует loot/EXP повторно.
- Completed only в постоянных итогах; correction создаёт новую производную версию без удаления истории.
- Close/archive сохраняет невыполненные обязательства; reopen не возобновляет completed encounter.

**Минимальные технические проверки:** Fixtures repeated outcome/loot roll/winner/issue unknown/reward retry, archived obligation recovery, group aggregate no double count, filtered export, achievement event dedupe.

**Ручная приёмка на форуме:**

1. Завершить бой и подтвердить GM дважды: один loot pool и один набор наград.
2. Распределить need/greed, вызвать ошибку Inventory и повторить: победитель и roll прежние.
3. Проверить EXP/currency и питомцев: нет повторного/множенного начисления.
4. Закрыть и переоткрыть сессию с невыполненной выдачей: архив/резервы сохранены, AP и бой не сброшены.

<a id="stage-11"></a>
### Этап 11. Полноценный интерфейс, уведомления и синхронизация

**Цель:** Завершить адаптивное UX поверх уже работающих серверных механизмов.

**Требования:** A1.50–A1.59, A1.61–A1.68, A1.87–A1.92.

**Зависимости:** 1–10; минимальный UI предыдущих этапов уже существует; IC-AAM dedupe/type/ack и IC-PROFILE tab.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: modules/Presentation/{ThreadComponents,BuilderController,ProfileController}.php; QueryService; Adapters/{AlertsAdapter,StatisticsAdapter}.php; assets/acs-*.js/acs.css; templates/; scoped Theme/Editor/APUI/AAM consumers. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 11.1: Панель/карточки/каталог/target selection/details/GM tabs, desktop и mobile из одного server draft. Keyboard/touch/focus и sticky offsets без изменений постбита.
- 11.2: Profile combat tab/achievements/history и paginated journal/exports с одним policy layer. Lazy загрузка тяжёлых форм и batch reads.
- 11.3: AAM types/formatters/outbox acknowledgement с operation dedupe, aggregate notifications и delegation recipients. Versioned changes API + optional transport, обязательный fallback без агрессивного polling.

**Ожидаемый результат:** Удобный форумный интерфейс без второго редактора, потери текста и раскрытия секретов.

**Критерии готовности:**

- Desktop/mobile share draft и sealed tape, resize не теряет prose/actions.
- Query/notifications/export/aggregates одинаково соблюдают secrecy; нет N+1 и eager heavy stack.
- Потеря связи не теряет draft и не вызывает blind publish; outbox retry не дублирует уведомления.

**Минимальные технические проверки:** Статические manifest/style isolation/init checks, query batching и filtered DTO fixtures, dedupe consumer ack; ограниченное профилирование TTFB/memory/queries/payload, без облачного visual suite.

**Ручная приёмка на форуме:**

1. Проверить desktop/mobile и клавиатуру: sticky не перекрывает навигацию, meta-line и editor.
2. Переключать размер/вкладки/автосохранение: draft и проза сохраняются, конфликт версий понятен.
3. Сравнить GM/player/observer для панели, details, notifications и профиля: скрытое не передаётся.
4. Отключить AAM: бой публикуется, очередь доступна Diagnostics; после восстановления нет дубликатов.

<a id="stage-12"></a>
### Этап 12. Стабилизация, миграции и ручная приёмка

**Цель:** Подготовить ACS 1.0 к окончательной проверке заказчиком, закрыв доказанные технические риски.

**Требования:** Все решения baseline; особенно N1/N2, A1.60, A1.89–A1.95.

**Зависимости:** 1–11, реальные IC gates закрыты; форумная среда MyBB 1.8.40/PHP 8.5.0.

**Компоненты и файлы:** пути относительно нового `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`, если не указано иначе: Только доказанно затронутые ACS modules/migrations/adapters; scoped AF fixes по отдельным задачам; четыре документа docs/acs/. `Adapters/` означает `modules/Infrastructure/Adapters/`.

**Ограниченные задания Codex:**

- 12.1: Lifecycle matrix и resumable migrations на существующих данных, reactivation без reset, upgrade старых snapshots/engine refs без пересчёта истории.
- 12.2: Целевые recovery/race/security/performance проверки по реальным изменениям; исправить конкретные дефекты отдельными PR, без глобального рефакторинга и новой тестовой платформы.
- 12.3: Сверить весь requirement ledger и integration status, подготовить инструкцию ручной приёмки/известные проблемы, передать заказчику. accepted устанавливает только заказчик после форума.

**Ожидаемый результат:** Проверенный кандидат ACS 1.0 с явным состоянием каждого этапа и каждого оставшегося ограничения.

**Критерии готовности:**

- Нет открытых критических инвариантов/небезопасных bypass; blocked зависимости явно названы.
- Документация соответствует итоговым interfaces/DDL/capabilities и commit refs.
- Результаты ручного тестирования записаны; implemented/ready_for_manual_test не названы accepted без решения заказчика.

**Минимальные технические проверки:** Только синтаксис/контракты/migrations/transactions/permissions затронутой области; сверка traceability/links; повторять тесты лишь по новому дефекту. Полные комплексы не требуются.

**Ручная приёмка на форуме:**

1. Install→activate→deactivate→reactivate→upgrade сохраняет активную сессию, черновики и rolls.
2. Пройти один полный бой с питомцем/NPC/предметом/статусом/реакцией/наградами и text edits.
3. Воспроизвести response loss/recovery и отказ зависимостей без дублей и частичной механики.
4. Заказчик принимает этапы либо записывает конкретные дефекты/blocked причины.

## 4. Матрица трассировки каждого решения

Тематические формулировки приведены в Master, соответствующие SQL/модули — Architecture §2/§5/§9, реальность внешних интерфейсов — Contracts IC-*.

| Требование | Основной этап | Дополнительная проверка / приёмка |
|---|---|---|
| [N1](ACS_MASTER_SPECIFICATION.md#n1) | 3 | 4 (публикация), 12 (регрессии) |
| [N2](ACS_MASTER_SPECIFICATION.md#n2) | 3 | 4 (публикация), 12 (регрессии) |
| [M1](ACS_MASTER_SPECIFICATION.md#m1) | 3 | 4 (публикация), 12 (регрессии) |
| [H1](ACS_MASTER_SPECIFICATION.md#h1) | 3 | 4 (публикация), 12 (регрессии) |
| [C1](ACS_MASTER_SPECIFICATION.md#c1) | 3 | 4 (публикация), 12 (регрессии) |
| [C1.2](ACS_MASTER_SPECIFICATION.md#c1-2) | 3 | 4 (публикация), 12 (регрессии) |
| [C1.3](ACS_MASTER_SPECIFICATION.md#c1-3) | 3 | 4 (публикация), 12 (регрессии) |
| [C1.4](ACS_MASTER_SPECIFICATION.md#c1-4) | 3 | 4 (публикация), 12 (регрессии) |
| [D1](ACS_MASTER_SPECIFICATION.md#d1) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.3](ACS_MASTER_SPECIFICATION.md#d1-3) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.4](ACS_MASTER_SPECIFICATION.md#d1-4), ACS-DOC-01 | 2.1.3 (данные), 3 (прежняя формула) | 2/5/7/8/9 (контракты), 4 (публикация), 12 (совместимость) |
| [D1.5](ACS_MASTER_SPECIFICATION.md#d1-5) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.6](ACS_MASTER_SPECIFICATION.md#d1-6) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.7](ACS_MASTER_SPECIFICATION.md#d1-7) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.8](ACS_MASTER_SPECIFICATION.md#d1-8) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.9](ACS_MASTER_SPECIFICATION.md#d1-9) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.10](ACS_MASTER_SPECIFICATION.md#d1-10) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.11](ACS_MASTER_SPECIFICATION.md#d1-11) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.12](ACS_MASTER_SPECIFICATION.md#d1-13) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.13](ACS_MASTER_SPECIFICATION.md#d1-13) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.14](ACS_MASTER_SPECIFICATION.md#d1-14) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.15](ACS_MASTER_SPECIFICATION.md#d1-15) | 3 | 4 (публикация), 12 (регрессии) |
| [D1.16](ACS_MASTER_SPECIFICATION.md#d1-16) | 3 | 4 (публикация), 12 (регрессии) |
| [S1.1](ACS_MASTER_SPECIFICATION.md#s1-1) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.2](ACS_MASTER_SPECIFICATION.md#s1-2) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.3](ACS_MASTER_SPECIFICATION.md#s1-3) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.4](ACS_MASTER_SPECIFICATION.md#s1-4) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.5](ACS_MASTER_SPECIFICATION.md#s1-5) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.6](ACS_MASTER_SPECIFICATION.md#s1-6) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.7](ACS_MASTER_SPECIFICATION.md#s1-7) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.8](ACS_MASTER_SPECIFICATION.md#s1-8) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.9](ACS_MASTER_SPECIFICATION.md#s1-9) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.10](ACS_MASTER_SPECIFICATION.md#s1-10) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.11](ACS_MASTER_SPECIFICATION.md#s1-11) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.12](ACS_MASTER_SPECIFICATION.md#s1-12) | 3 | 4 (публикация), 12 (регрессии) |
| [S1.13](ACS_MASTER_SPECIFICATION.md#s1-13) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.14](ACS_MASTER_SPECIFICATION.md#s1-14) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.15](ACS_MASTER_SPECIFICATION.md#s1-15) | 5 | 4 (публикация), 12 (регрессии) |
| [S1.16](ACS_MASTER_SPECIFICATION.md#s1-16) | 5 | 4 (публикация), 12 (регрессии) |
| [A1.1](ACS_MASTER_SPECIFICATION.md#a1-1) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.2](ACS_MASTER_SPECIFICATION.md#a1-2) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.3](ACS_MASTER_SPECIFICATION.md#a1-3) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.4](ACS_MASTER_SPECIFICATION.md#a1-4) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.5](ACS_MASTER_SPECIFICATION.md#a1-5) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.6](ACS_MASTER_SPECIFICATION.md#a1-6) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.7](ACS_MASTER_SPECIFICATION.md#a1-7) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.8](ACS_MASTER_SPECIFICATION.md#a1-8) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.9](ACS_MASTER_SPECIFICATION.md#a1-9) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.10](ACS_MASTER_SPECIFICATION.md#a1-10) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.11](ACS_MASTER_SPECIFICATION.md#a1-11) | 3 | 12; применимые IC gates и UI этапа 11 |
| [A1.12](ACS_MASTER_SPECIFICATION.md#a1-12) | 3 | 12; применимые IC gates и UI этапа 11 |
| [A1.13](ACS_MASTER_SPECIFICATION.md#a1-13) | 3 | 12; применимые IC gates и UI этапа 11 |
| [A1.14](ACS_MASTER_SPECIFICATION.md#a1-14) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.15](ACS_MASTER_SPECIFICATION.md#a1-15) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.16](ACS_MASTER_SPECIFICATION.md#a1-16) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.17](ACS_MASTER_SPECIFICATION.md#a1-17) | 5 | 12; применимые IC gates и UI этапа 11 |
| [A1.18](ACS_MASTER_SPECIFICATION.md#a1-18) | 5 | 12; применимые IC gates и UI этапа 11 |
| [A1.19](ACS_MASTER_SPECIFICATION.md#a1-19) | 5 | 12; применимые IC gates и UI этапа 11 |
| [A1.20](ACS_MASTER_SPECIFICATION.md#a1-20) | 5 | 12; применимые IC gates и UI этапа 11 |
| [A1.21](ACS_MASTER_SPECIFICATION.md#a1-21) | 5 | 12; применимые IC gates и UI этапа 11 |
| [A1.22](ACS_MASTER_SPECIFICATION.md#a1-22) | 5 | 12; применимые IC gates и UI этапа 11 |
| [A1.23](ACS_MASTER_SPECIFICATION.md#a1-23) | 5 | 12; применимые IC gates и UI этапа 11 |
| [A1.24](ACS_MASTER_SPECIFICATION.md#a1-24) | 5 | 12; применимые IC gates и UI этапа 11 |
| [A1.25](ACS_MASTER_SPECIFICATION.md#a1-25) | 5 | 12; применимые IC gates и UI этапа 11 |
| [A1.26](ACS_MASTER_SPECIFICATION.md#a1-26) | 5 | 12; применимые IC gates и UI этапа 11 |
| [A1.27](ACS_MASTER_SPECIFICATION.md#a1-27) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.28](ACS_MASTER_SPECIFICATION.md#a1-28) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.29](ACS_MASTER_SPECIFICATION.md#a1-29) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.30](ACS_MASTER_SPECIFICATION.md#a1-30) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.31](ACS_MASTER_SPECIFICATION.md#a1-31) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.32](ACS_MASTER_SPECIFICATION.md#a1-32) | 6 | 12; применимые IC gates и UI этапа 11 |
| [A1.33](ACS_MASTER_SPECIFICATION.md#a1-33) | 6 | 12; применимые IC gates и UI этапа 11 |
| [A1.34](ACS_MASTER_SPECIFICATION.md#a1-34) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.35](ACS_MASTER_SPECIFICATION.md#a1-35) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.36](ACS_MASTER_SPECIFICATION.md#a1-36) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.37](ACS_MASTER_SPECIFICATION.md#a1-37) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.38](ACS_MASTER_SPECIFICATION.md#a1-38) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.39](ACS_MASTER_SPECIFICATION.md#a1-39) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.40](ACS_MASTER_SPECIFICATION.md#a1-40) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.41](ACS_MASTER_SPECIFICATION.md#a1-41) | 1 | 12; применимые IC gates и UI этапа 11 |
| [A1.42](ACS_MASTER_SPECIFICATION.md#a1-42) | 7 | 12; применимые IC gates и UI этапа 11 |
| [A1.43](ACS_MASTER_SPECIFICATION.md#a1-43) | 7 | 12; применимые IC gates и UI этапа 11 |
| [A1.44](ACS_MASTER_SPECIFICATION.md#a1-44) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.45](ACS_MASTER_SPECIFICATION.md#a1-45) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.46](ACS_MASTER_SPECIFICATION.md#a1-46) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.47](ACS_MASTER_SPECIFICATION.md#a1-47) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.48](ACS_MASTER_SPECIFICATION.md#a1-48) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.49](ACS_MASTER_SPECIFICATION.md#a1-49) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.50](ACS_MASTER_SPECIFICATION.md#a1-50) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.51](ACS_MASTER_SPECIFICATION.md#a1-51) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.52](ACS_MASTER_SPECIFICATION.md#a1-52) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.53](ACS_MASTER_SPECIFICATION.md#a1-53) | 11 | 12; применимые IC gates и UI этапа 11 |
| [A1.54](ACS_MASTER_SPECIFICATION.md#a1-54) | 11 | 12; применимые IC gates и UI этапа 11 |
| [A1.55](ACS_MASTER_SPECIFICATION.md#a1-55) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.56](ACS_MASTER_SPECIFICATION.md#a1-56) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.57](ACS_MASTER_SPECIFICATION.md#a1-57) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.58](ACS_MASTER_SPECIFICATION.md#a1-58) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.59](ACS_MASTER_SPECIFICATION.md#a1-59) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.60](ACS_MASTER_SPECIFICATION.md#a1-60) | 4 | 12; применимые IC gates и UI этапа 11 |
| [A1.61](ACS_MASTER_SPECIFICATION.md#a1-61) | 11 | 12; применимые IC gates и UI этапа 11 |
| [A1.62](ACS_MASTER_SPECIFICATION.md#a1-62) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.63](ACS_MASTER_SPECIFICATION.md#a1-63) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.64](ACS_MASTER_SPECIFICATION.md#a1-64) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.65](ACS_MASTER_SPECIFICATION.md#a1-65) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.66](ACS_MASTER_SPECIFICATION.md#a1-66) | 10 | 12; применимые IC gates и UI этапа 11 |
| [A1.67](ACS_MASTER_SPECIFICATION.md#a1-67) | 1 | 12; применимые IC gates и UI этапа 11 |
| [A1.68](ACS_MASTER_SPECIFICATION.md#a1-68) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.69](ACS_MASTER_SPECIFICATION.md#a1-69) | 2 | 12; применимые IC gates и UI этапа 11 |
| [A1.70](ACS_MASTER_SPECIFICATION.md#a1-70) | 8 | 12; применимые IC gates и UI этапа 11 |
| [A1.71](ACS_MASTER_SPECIFICATION.md#a1-71) | 8 | 12; применимые IC gates и UI этапа 11 |
| [A1.72](ACS_MASTER_SPECIFICATION.md#a1-72) | 8 | 12; применимые IC gates и UI этапа 11 |
| [A1.73](ACS_MASTER_SPECIFICATION.md#a1-73) | 8 | 12; применимые IC gates и UI этапа 11 |
| [A1.74](ACS_MASTER_SPECIFICATION.md#a1-74) | 8 | 12; применимые IC gates и UI этапа 11 |
| [A1.75](ACS_MASTER_SPECIFICATION.md#a1-75) | 9 | 12; применимые IC gates и UI этапа 11 |
| [A1.76](ACS_MASTER_SPECIFICATION.md#a1-76) | 9 | 12; применимые IC gates и UI этапа 11 |
| [A1.77](ACS_MASTER_SPECIFICATION.md#a1-77) | 9 | 12; применимые IC gates и UI этапа 11 |
| [A1.78](ACS_MASTER_SPECIFICATION.md#a1-78) | 7 | 12; применимые IC gates и UI этапа 11 |
| [A1.79](ACS_MASTER_SPECIFICATION.md#a1-79) | 7 | 12; применимые IC gates и UI этапа 11 |
| [A1.80](ACS_MASTER_SPECIFICATION.md#a1-80) | 7 | 12; применимые IC gates и UI этапа 11 |
| [A1.81](ACS_MASTER_SPECIFICATION.md#a1-81) | 8 | 12; применимые IC gates и UI этапа 11 |
| [A1.82](ACS_MASTER_SPECIFICATION.md#a1-82) | 8 | 12; применимые IC gates и UI этапа 11 |
| [A1.83](ACS_MASTER_SPECIFICATION.md#a1-83) | 8 | 12; применимые IC gates и UI этапа 11 |
| [A1.84](ACS_MASTER_SPECIFICATION.md#a1-84) | 7 | 12; применимые IC gates и UI этапа 11 |
| [A1.85](ACS_MASTER_SPECIFICATION.md#a1-85) | 9 | 12; применимые IC gates и UI этапа 11 |
| [A1.86](ACS_MASTER_SPECIFICATION.md#a1-86) | 9 | 12; применимые IC gates и UI этапа 11 |
| [A1.87](ACS_MASTER_SPECIFICATION.md#a1-87) | 11 | 12; применимые IC gates и UI этапа 11 |
| [A1.88](ACS_MASTER_SPECIFICATION.md#a1-88) | 11 | 12; применимые IC gates и UI этапа 11 |
| [A1.89](ACS_MASTER_SPECIFICATION.md#a1-89) | 1 | 12; применимые IC gates и UI этапа 11 |
| [A1.90](ACS_MASTER_SPECIFICATION.md#a1-90) | 1 | 12; применимые IC gates и UI этапа 11 |
| [A1.91](ACS_MASTER_SPECIFICATION.md#a1-91) | 1 | 12; применимые IC gates и UI этапа 11 |
| [A1.92](ACS_MASTER_SPECIFICATION.md#a1-92) | 1 | 12; применимые IC gates и UI этапа 11 |
| [A1.93](ACS_MASTER_SPECIFICATION.md#a1-93) | 1 | 12; применимые IC gates и UI этапа 11 |
| [A1.94](ACS_MASTER_SPECIFICATION.md#a1-94) | 12 | 12; применимые IC gates и UI этапа 11 |
| [A1.95](ACS_MASTER_SPECIFICATION.md#a1-95) | 12 | 12; применимые IC gates и UI этапа 11 |

D1.12 в матрице относится к окончательной редакции D1.13. D1.2 как самостоятельный финальный ID в источнике не выделен; C=100 покрыт D1. N1/M1 состоят из нескольких утверждений, в Master они сохранены совокупно.

## 5. Реестр состояния поставки

Таблица ниже — исторический снимок исходного плана, не актуальная переоценка приёмки. По предпосылкам ACS-DOC-01 этап 1 принят; изменение D1.4 не отменяет эту приёмку. Новые element contracts/ACS-2.1.3 остаются PROPOSED и не объявляются реализованными этой документационной задачей.

| Этап | Статус в исходном снимке | Commit/PR | Ручной результат | Известные предварительные gates |
|---|---|---|---|---|
| 1 | — (реализация не начиналась) | — | — | проверить целевую БД и runtime dependencies |
| 2 | — (реализация не начиналась) | — | — | accuracy legacy content policy и snapshot API |
| 3 | — (реализация не начиналась) | — | — | завершить предыдущие этапы и relevant KB capabilities |
| 4 | — (реализация не начиналась) | — | — | IC-MYBB-1/IC-EDITOR-1: общая транзакционная граница |
| 5 | — (реализация не начиналась) | — | — | завершить предыдущие этапы и relevant KB capabilities |
| 6 | — (реализация не начиналась) | — | — | IC-INV-1: глобальный reservation protocol |
| 7 | — (реализация не начиналась) | — | — | завершить предыдущие этапы и relevant KB capabilities |
| 8 | — (реализация не начиналась) | — | — | завершить предыдущие этапы и relevant KB capabilities |
| 9 | — (реализация не начиналась) | — | — | KB prepared/transform/advanced capabilities |
| 10 | — (реализация не начиналась) | — | — | IC-BALANCE/Inventory grant и permanent sync |
| 11 | — (реализация не начиналась) | — | — | IC-AAM dedupe acknowledgement; профильная вкладка |
| 12 | — (реализация не начиналась) | — | — | завершить предыдущие этапы и relevant KB capabilities |

## 6. Cross-document review и ограничения комплекта

| Проверка | Результат документационного прохода |
|---|---|
| Ранние числовые/боевые решения | Master содержит N1/N2/M1/H1/C1/D1/S1, а не только A1 |
| A1.1–A1.95 | Все 95 имеют каноническую формулировку, anchor и основной этап |
| Редакции | set, H1, D1.12→D1.13, отмена хвоста, barrier_priority разобраны без объявления старых правил потерянными |
| Архитектура | Каждая функциональная подсистема имеет Domain/Application/Infrastructure/Presentation responsibility и этап; полный проект DDL отделён от existing tables |
| Интеграции | main SHA подтверждён connector compare; функции/пути проверены; proposed gateways не объявлены существующими |
| Восстановление | Seal/publish/owner operations/outbox/migration имеют distinct failure paths; нет blind retry и автоматического reroll |
| Приёмка | 12 этапов с отдельными задачами/DoD/checks/manual; accepted только заказчиком |
| Программный код | ACS и существующие PHP/JS/MyBB не изменялись этой задачей; добавляются только четыре Markdown-файла |

Ограничения: установленное ядро MyBB 1.8.40, engine реальных таблиц, production hooks и PHP 8.5.0 runtime не входят в этот статический репозиторный аудит; их проверка включена в gates этапов 1/4/12. Нет безопасных существующих ACS reservation/commit/идемпотентного наградного API: новые интерфейсы явно PROPOSED. Это технические зависимости реализации, не отсутствие согласованных игровых правил. Значения accuracy для legacy content не назначены догадкой. Локальная неоднозначность пояснения S1.3 о B=0 зафиксирована в Master §7; прямо утверждённая формула сохранена.

Документационная проверка не запускает SQL DDL, боевой runtime, большие тестовые комплексы или визуальную автоматизацию. Не подтверждает готовность отсутствующей реализации и не переводит этапы в implemented/accepted.
