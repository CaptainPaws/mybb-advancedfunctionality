# ACS-11.1.2 — Пошаговый Builder: исполнители, каталог, цели и последовательность

## База и объём

Один ограниченный PR от актуального `origin/main` **1a2fecdf** (включает ACS-11.1.1 и исправление ACS-11.1.1B, #1079). Зависимость ACS-10.4.3 присутствует в базе. Изменён существующий Builder; нового Resolver, редактора, publication coordinator, migrations или owner API нет. `docs/ROADMAP.md` не изменён.

Основания: A1.55–A1.57/A1.87 и применимые visibility/модульные положения ACS_MASTER_SPECIFICATION; Presentation/API/R2 границы ACS_TECHNICAL_ARCHITECTURE, ACS_IMPLEMENTATION_ROADMAP, ACS_INTEGRATION_CONTRACTS; integration reports **7.3.3, 8.3.3, 9.3.4, 10.4.3**, ACS_11_1_1_IMPLEMENTATION и актуальная цепочка showthread после 11.1.1B. Исторические отчёты не переписывались. Их installed NOT_VERIFIED и unsupported механические subsets не заменены утверждением готовности UI.

## Реализовано

### 1. Исполнитель

В одном Builder остаётся выбор разрешённой группы; внутри неё — персонаж, питомец, source-group summon либо разрешённый NPC. Метаданные происходят из прежнего builderScope/контроллеров/roster. Ни dropdown, ни фильтр каталога не добавляют право управления или новый AP.

Шаг показывает общий доступный AP группы, тип исполнителя, текущую доступность, ограничения, управление и summon/prepared metadata. Добавлен `actor.state` с HP, ресурсами и активными статусами **только** для владельца control group или GM. Права управления чужим существом сами по себе не превращаются в право читать новые числовые сведения: для такого исполнителя новые поля остаются ограниченными. Данные ресурсов проходят прежний `statusResources`, без чтения live CharacterSheets/KB или догадок о значениях.

### 2. Каталог

Вместо основного выбора из длинного dropdown используются карточки. Есть поиск по разрешённым названиям/описаниям, категории **Атака / Способности / Предметы / Экипировка / Тактика / Особые**, отдельный фильтр исполнителя и «Только доступные». Выбор карточки синхронизирует исполнителя и действие одного staging-состояния, не добавляет другой draft или editor.

Карточка показывает название, описание, AP/другие предоставленные затраты, объявленные требования, cooldown metadata и серверную причину недоступности. Описание ограничено 1000 символами; costs ограничены полями kind/resource/amount, без raw definitions/AST/receipts. Если описание или дополнительные требования не предоставлены, это явно обозначается без выдумывания механики.

Категории — presentation metadata текущих compiled/actions: damaging abilities относятся к «Атака», остальные обычные способности — к «Способности», typed item actions — к предметам/экипировке, spatial/cover/object/cooperation/participation/awareness — к тактике, prepared/spawn/effect operations — к особым. Категория не меняет KB schema или способ исполнения.

Известное действие, не допускаемое текущей формой, теперь остаётся в каталоге как **disabled** с `ACS_FORM_REQUIREMENT_UNAVAILABLE`; compiled executable entry по-прежнему удаляется. Известные active cooldown/actor unavailable/no legal targets дают серверные причины. Owner adapters, которые намеренно не возвращают неподдержанные Inventory candidates, не обходятся ради заполнения пустой категории. «Доступно» относится к текущему каталогу/контексту, не гарантирует допустимость любых комбинаций: весь порядок, ресурсы и пространственные зависимости проверяются отдельно.

Временный control overlay сохраняет прежнюю анонимизацию названия действия; новые description/requirements не раскрывают скрытое определение, дополнительные сведения о чужих ресурсах/cooldown ограничены.

### 3. Цели

`BuilderService` возвращает для каждого действия:

- `target_ids` — пересечение уже разрешённых target DTO, known_to/actor access, behavior и существующей target policy;
- `target_mode` — self, single либо подтверждённый compiled multiple;
- `target_count` и человекочитаемое `target_help`.

Исправлено затирание catalog-specific bindings при behavior filtering: первоначальные legal targets (например, цель, закреплённая в spatial action key) теперь пересекаются с результатом Behavior, а не заменяются общим списком.

JS больше не воспроизводит отношения сторон, custom target policy, range, zones, cover или awareness для вычисления доступных целей. Он отображает только переданные сервером ID и проверяет базовую целостность выбора (membership списка, отсутствие дублей, min/max). Все фактические gates остаются в существующих prepare/Query/Spatial/Cover/Behavior/Resolver сервисах.

| Подтверждённый режим | UI / серверная граница |
|---|---|
| Self / item / source-group spawn / соответствующие typed actions | Выбор серверной привязки к исполнителю; нет клиентской подстановки произвольного target |
| Single / hostile/allied/custom policy | Одна цель из per-action server intersection; relation rules не рассчитываются JS |
| Multiple | Checkbox cards и отдельный упорядоченный список с «Выше / Ниже / Убрать»; targets array сохраняется в порядке выбора, без сортировки |
| Поддерживаемые массовые/environment/spatial эффекты | Сохраняется существующая typed серверная обработка и её self/explicit binding; скрытые получатели не перечисляются в UI |
| Произвольные all_allies/all_enemies/all_entities/area без реализованного KB-контракта | Не добавлены как фиктивные UI capabilities; остаются в прежнем unsupported scope |

Невидимые существа не попадают в disabled options, карточки или placeholders. При устаревшем ранее сделанном выборе staging не переносит действие автоматически на другую цель: отображается общий запрос выбрать цели заново. Сам draft с прежними IDs/порядком не переписывается без явного применения изменения.

### 4. Последовательность

Очередь показывает исполнителя, действие, **все цели по порядку**, AP, дополнительные затраты и сохранённые prepared item bindings. Есть редактирование, удаление, перестановка действий и отдельное добавление следующего действия. Запечатанный prefix и его порядок остаются неизменными; стабильные pending save/seal keys, восстановление черновика, сохранённая проза и неизвестные результаты не заменяются новым запросом.

Добавлен read-only **`builder_validate` POST** в существующий AF route/BuilderController. Он использует прежний `BuilderService::preview → prepare → Resolver::resolve` и существующие повторные dependency/version проверки. Возвращает только valid/conditional label, scope token, стоимости действий и прежнее friendly-fire предупреждение; не возвращает дополнительные HP, броски, raw snapshots или новые target identities. CSRF, native reply access, session/actor authorization, payload bounds и текущий scope проверяются прежним путём.

После add/edit/remove/reorder (и изменения условного сценария) клиент запускает **одну debounced проверку через 350 мс**. Это не polling. Ответы устаревших версий очереди/группы/контекста игнорируются; проверяются scope token и соответствие длины результата. Ошибка сети или отказ сохраняют локальный draft и показываются без автоматического retry loop. Есть ручная кнопка проверки.

После успешной проверки очередь показывает серверные стоимости, до неё — заявленные metadata с соответствующей подписью. Локальная сумма AP служит отображению, не заменяет виртуальное состояние. Для обычной незапечатанной последовательности seal UI требует актуального успешного результата, сохранённого draft и прежних условий. **Seal остаётся отдельной явной кнопкой** и повторно проверяет фактические условия; выбор действия, переход по шагам и validation не расходуют seal quota.

Предраундовая подготовка (turn=0) остаётся отдельным прежним scope с одним действием. Новый последовательный validate её не принимает. Уже sealed prefix не пересчитывается новым автоматическим route: используются прежние seal status/refresh/append проверки. Эти ограничения обозначены в UI, вместо имитации неподдержанной механики.

## Mobile, доступность и состояние

Одна DOM-панель и один DraftModel для всех размеров. Четыре шага переключаются через `hidden`, навигацию и кнопки **Назад / Далее**; на третьем шаге «Добавить и далее» явно применяет выбранное действие. Resize не создаёт другой экземпляр и не сбрасывает выборы. Переключение шага не сохраняет, не запечатывает и не удаляет действия.

Фильтры, исполнитель, staged action, порядок целей, prepared item selection, queue и текст сохраняются при переходах. После server refresh недоступные цели не переназначаются молча. При редактировании очередь/проза остаются на месте; серверное сохранение заблокировано до применения либо отмены edit, как и раньше.

Навигация имеет `aria-current=step`, headings получают фокус; target controls имеют native labels/group, catalog actions — aria-pressed, notices — live status. Сохраняется фокус при обновлении карточек/очереди/упорядоченных целей. Mobile layout — одна колонка, перенос длинных значений и touch controls; фиксированные sticky offsets/слои ATF не добавлены. Литературный textarea остаётся прежним plain BBCode draft с `data-af-ae-skip=1`, второго SCEditor нет. Native форма публикации открывается прежним отдельно разрешённым маршрутом.

## Загрузка и безопасность

Builder по-прежнему вызывается только явным `acs_builder`/существующим авторизованным входом. Обычный showthread не загружает полный KB catalog или Builder assets. Поиск/фильтры работают с уже разрешёнными pinned/actions выбранной группы, не обращаются ко всему KB. Новые metadata не содержат скрытых source definitions или GM-only причин.

Все пользовательские строки рендерятся через textContent; JSON config сохраняет прежние HEX-флаги экранирования. Non-JSON ответ API даёт общее сообщение, без вывода фрагментов server HTML/SQL. Недоступная категория не получает выдуманную базовую атаку. JS не меняет цели после seal и не считает range/cover/relations/awareness самостоятельно.

`publication_enabled=false`, test-only publication gate, владельцы Inventory, расход AP/ресурсов, квоты, RNG/tape, immutable receipts, права, ATF/postbit и engine contracts не изменены. Нет новой миграции, фонового worker, polling или отправки поста из validation.

## Изменённые файлы

Относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

1. `modules/Presentation/BuilderController.php` — markup четырёх шагов, фильтры/cards/ordered targets, route projection `builder_validate`, версии CSS/JS **1112**.
2. `modules/Application/BuilderService.php` — actor state projection, per-action target intersection/mode/metadata, read-only validation projection и серверные сообщения; сохраняется specific catalog target binding.
3. `modules/Application/QueryService.php` — bounded presentation metadata pinned choices, disabled form-unavailable choice, анонимизация temporary control; compile/Resolver грамматика не расширена.
4. `advancedcombatsystem.php` — маршрутизация дополнительного read-only Builder op.
5. `assets/builder.js` — wizard, поиск/категории/actor filter, ordered target selection, queue display/edit, debounced server validation и защита от stale replies.
6. `assets/builder.css` — scoped responsive cards/steps/targets, `[hidden]`, focus/touch/overflow.
7. Этот отчёт.

PHP/JS/CSS следует обновлять вместе: старый JS не является интерфейсом новых metadata/markup. Manifest resource `builder` и response fact `has_acs_builder` уже поддерживают этот scope и не изменялись. Лимиты не расширены: обычная последовательность для preview/validation — прежние 20 действий; pre-round — одно; draft storage сохраняет свой прежний технический контракт.

## Проверки и ограничения

- **PASS:** `node --check assets/builder.js`.
- **PASS:** `git diff --check`.
- **PASS (статически):** все прямые field/part/command references JS имеют markup, `builder_validate` зарегистрирован в текущем router/controller; проверены пути current target validation и API cost metadata.
- **NOT_VERIFIED:** `php -l` четырёх изменённых PHP — PHP CLI отсутствует (`command -v php` не находит binary). Новая среда не устанавливалась.
- Автоматические, regression, integration/SQL и browser/UI tests **не создавались и не запускались**, согласно AGENTS.md. Production и установленный warprift.ru не проверялись. Успешная функциональная/installed приёмка **не заявляется**.

Текущие unsupported KB schemas/actions, произвольные условия/массовые selectors, unknown owner outcomes и publication acceptance остаются прежними. Значения стоимости/цели каталога — текущий разрешённый snapshot, не гарантия любой будущей последовательности или независимого от исходов результата. Validation использует выбранный условный hit/crit/miss сценарий; фактическое seal/commit остаётся окончательным mechanical gate. Performance benchmark и количественные пределы latency/memory не измерялись; новый validate использует существующие scoped reads/preview и не является облегчённым отдельным вычислителем.

## Ручная приёмка владельцем

1. Выполнить `php -l` четырёх изменённых PHP и установить согласованные PHP/CSS/JS.
2. Открыть разрешённый Builder: character + pet + source-group summon одной группы, отдельно разрешённый NPC; проверить общий AP и разрешённые HP/resources/statuses. Delegate/temporary control/observer/outsider не должны получать новые скрытые данные или права.
3. Проверить все шесть категорий, поиск, actor filter, «Только доступные», известную form/cooldown недоступность и серверную причину. Пустая категория не создаёт неподдержанного действия.
4. Self, single и multiple: выбрать несколько целей в определённом порядке, переставить их, изменить/отменить edit, сохранить/восстановить draft — targets array и queue order сохраняются. Hidden entity не должна появиться даже в disabled controls.
5. Смешать действия разных существ одной группы; добавить/удалить/переставить действия, проверить debounced server validation, общий AP, дополнительные ресурсы и actual costs. Для move/cover/object/awareness проверить отказ/успех через существующее виртуальное состояние, без клиентской замены целей.
6. Быстро изменить порядок во время запроса; переключить группу/контекст; отключить сеть. Stale reply не должен подтвердить новую очередь, draft/проза сохраняются, автоматического повторного seal/save нет.
7. Mobile: пройти все шаги «Назад / Далее», сменить ширину/ориентацию, вернуться к целям/параметрам/тексту; проверить отсутствие reset/overflow и клавиатурный фокус.
8. Отдельно проверить save, lost-ACK retry со старым key, seal/append/cancel-tail/confirm, prefix immutability, pre-round и existing test publication guard. Выбор карточки и validation не должны расходовать квоту или реальные HP/AP; production publication остаётся выключенной.

Следующая задача этапа 11 не начата. PR подготовлен для ручной проверки, не объявлен accepted/production ready.
