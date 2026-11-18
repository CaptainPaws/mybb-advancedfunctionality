# ACS-11.1.4 — Компактные боевые карточки и неизменяемая история

## База и границы

База — актуальный `origin/main` **8d887771**, merge ACS-11.1.3 (#1081), после `git fetch origin`. Прочитаны применимые A1.51–A1.52/A1.62/A1.87 Master Specification, R2/Presentation Technical Architecture, этап 11 Implementation Roadmap, IC-THEME/IC-EDITOR Integration Contracts, отчёты ACS-9.3.4, ACS-10.4.1, ACS-10.4.3 и текущие CombatCard/CombatCardQuery/ThreadComponents, сохранённые trace contracts их непосредственных producers.

Один read-only UI PR. `docs/ROADMAP.md`, исторические отчёты, миграции, Domain/Resolver/Effects/Clock, publication services/gates и редактор не изменены. Общая боевая публикация не включается. Installed acceptance **NOT_VERIFIED**.

## Что исправлено

Прежняя карточка была постоянно раскрытым aside. Детали зависели от ранних `continue`: item, cover, spatial, cooperation и environment могли завершать вывод до связанных событий. Урон после защиты смешивался с фактической потерей HP; лечение и barrier events не имели полноценной секции. Имена читались из текущей таблицы entities, что не давало исторической подписи независимо от её позднейших изменений.

Теперь outer `<details>` без `open` свёрнут по умолчанию. Разрешённый подробный заголовок содержит исполнителей, сохранённый номер раунда, AP основных действий и краткие подтверждённые исходы. Status-only viewer получает только прежнюю безопасную метку, без замаскированных пустых значений/счётчиков скрытых действий.

При раскрытии:

- ordered primary actions, сохранённый ability key, цели в исходном порядке;
- outcomes отдельных strikes, включая hit/crit/miss и original/resolved target при перехвате;
- фактический HP loss, absorption/overkill, effective healing и overheal из effects events;
- создание/восстановление/поглощение/длительность барьеров;
- применённые/обновлённые/снятые статусы, upkeep, формы, copied/granted abilities и перенос/рассеивание;
- item consume/equipment operation, совместные задачи и пространственные/cover outcomes;
- выполненные и пропущенные реакции, redirect result и их фактические эффекты;
- подготовленные causal executions по сохранённому action_index, их ordered child plans и отдельные ссылки на source/parent execution;
- spawn intents/подтверждённые spawned identities, summon lifecycle и реально участвовавшие object passive/trigger/change events;
- отдельный контекст initial/start/end boundary effects, без приписывания их каждому primary action.

Пустые группы не выводятся. Нулевой результат реально состоявшегося эффекта не считается отсутствием события. Если legacy trace не содержит actual damage events, положительный allocation показывается как «Сохранённый урон после защиты», а не как доказанная потеря HP. Для deterministic use/spawn с unused rolls не рисуется фиктивное попадание. Статусы из final state используются для описания действительно затронутых effects; несвязанные активные статусы не выдаются за новые события этого действия.

Вложенный закрытый раздел содержит только разрешённые сохранённые rolls, probabilities, damage components, formula references/versions, числовые inputs, сохранённые шаги вычислений/terminal output, causal references и reaction roll IDs. Формула **не компилируется и не вычисляется**. Применяется whitelist, raw KB definitions, source/owner authority envelopes и sealed_json не попадают в DTO/HTML. Публичного JSON dump не добавлено.

## Источники, proof и имена

`CombatCardQuery` сохраняет прежние native thread/session membership checks и проверку видимости native post. `TerminalReceiptPersistence` по-прежнему проверяет committed submission, sealed/request/receipt hashes, result payload hash, post_link и turn/post identities. Без verified terminal receipt нет applied result. MODERATION_PENDING/RECOVERY_REQUIRED/прочие промежуточные состояния не отображаются как списанные HP/AP.

Дополнительные JSON_EXTRACT в existing detailed SELECT читают только сохранённые `plan.entity_end_boundaries`, `plan.initial_effects_trace` и `changes.spawns`. Actions, summary, status instances, prepared executions и boundary history уже были источниками прежней карточки. Номер раунда берётся по историческому turn → round link, как в существующем ArchiveQuery; текущий round encounter не используется.

Новый чистый `CombatCardProjection` отделяет whitelist от HTML. Он обходит только известные trace containers, без запуска Resolver, Clock boundary, detection, RNG или owner adapters. Вложенный prepared plan также проецируется как сохранённые данные, без повторного исполнения. Итоговые status descriptions явно подписаны как состояние после хода; это не реконструкция intermediate state.

Имена читаются одним batched SELECT из `entity.register` event_details для **уже разрешённых** идентичностей. Проверяется content_hash(payload + visibility); используется только непустое сохранённое `request.name`, без приватного reason/source payload в DTO. Ни текущие entities.name, ни Appearance masks, KB или CharacterSheets для исторической подписи не читаются.

Для старых регистраций с пустым explicit name, призывов и других identities без такого события используется `Участник #ID`. Историческое красивое имя/alias в этих случаях нельзя достоверно восстановить из текущего immutable combat payload; оно не изобретается и не заполняется сегодняшним названием. Название способности при отсутствии исторического display label также остаётся сохранённым key. Это явное ограничение существующих receipts, не новая миграция/перезапись истории.

AP в заголовке подписан **«AP действий»**: это сумма сохранённых primary costs. Реакции/подготовленные children/upkeep показывают свои сохранённые расходы отдельно; заголовок не объявлен новым общим расходом всех групп. Cost aggregation — presentation arithmetic, не новый механический расчёт.

## Видимость и история

Действующая restrictive card policy сохранена: подробности только `SessionService::can_manage` (текущий persisted owner/GM в рамках существующего контракта). Остальные члены сессии, включая player/delegate/observer, получают прежний status-only DTO; outsider — ничего. Сам author поста не получает новых GM-полномочий. Скрытые actions/targets/HP/AP/rolls/числа участников не передаются через placeholders, counters, CSS или JSON. Раскрытие `<details>` не является авторизацией: данные отфильтрованы сервером заранее.

Archive reveal_results/reveal_all не включаются автоматически этим PR. Новая approximate политика для post cards не изобретается. Разрешённой detailed карточке добавлена ссылка на существующий архив, отфильтрованный по PID, для чтения исходного события и связанных correction reviews. Архив повторно проверяет свои native/session/policy права.

Карточка продолжает показывать исходный verified result; correction_event не переписывает receipt или старый результат. Текущие KB/CS изменения, cosmetic mask и редактирование прозы не пересчитывают историю. Пересборка игрового состояния по initial/final snapshots для отображения не выполняется.

## Точка вывода и UX

Сохранены registration identity `advancedcombatsystem::combat_result`, slot `post.before_body`, текущий PID/TID provider и batched page cache с audience generation. В reference ATF `templates/postbit_classic.html` slot находится перед `post_body`/`post.message`. ATF-шаблоны/postbit/meta-line/sticky не менялись. Литературный `posts.message` не выбирается и не дублируется карточкой; Quick Edit hooks/DOM и editor runtime не затронуты.

Native summary/details обеспечивают Tab/Enter/Space и default closed без JS. Нет дополнительного JS, animation, decorative frame или фиксированного layout offset. Scoped CSS убирает рамку карточки, задаёт readable spacing, перенос длинных значений/названий, focus outline и narrow-screen layout. Expanded state не зависит от JS resize/remount; reload использует default collapsed.

Сохранён page limit 100 native PIDs. Новые detail extracts выполняются только при необходимости detailed audience; server DTO status-only остаётся без details. Имена читаются batch по разрешённым IDs, а не N+1 по каждой цели. Существующий audienceCacheKey/authorization stack не переработан; отсутствие всех N+1, строгая latency/memory bound или performance benchmark не заявляются. Браузерное раскрытие не делает network requests: разрешённые bounded-page details уже в HTML; отдельный lazy endpoint не вводится.

## Изменённые файлы

Относительно addon root:

- `modules/Infrastructure/CombatCardProjection.php` — новый чистый whitelist для сохранённых traces/causal children и исторических подписей.
- `modules/Infrastructure/CombatCardQuery.php` — projection, historical round/AP metadata, immutable registration labels; live name SELECT удалён.
- `modules/Infrastructure/TerminalReceiptPersistence.php` — дополнительные optional detailed JSON extracts; verifier/receipt protocol без изменений.
- `modules/Presentation/CombatCard.php` — компактное раскрытие, ordered actions, непустые группы и technical details.
- `modules/Presentation/ThreadComponents.php` — только cache version CSS `1114`.
- `assets/presentation.css` — изолированное оформление карточки; recovery/panel behavior сохранено.
- Этот отчёт: `docs/design/acs/ACS_11_1_4_IMPLEMENTATION.md`.

PHP/CSS следует обновлять вместе. Manifest resource `presentation`/response fact `has_acs_card` уже поддерживает этот scope, manifest не изменён.

## Проверки и ручная приёмка

- **PASS:** `git diff --check`.
- **PASS, статически:** реальные component registration/slot/template, Query → terminal verifier → whitelist → renderer; используемые JSON paths и trace/event fields сверены с текущими producers. Нет вызова Resolver/Clock/RNG, SQL mutation, raw prose или client-side visibility filter в новой проекции.
- **NOT_VERIFIED:** `php -l` пяти изменённых/новых PHP — PHP CLI отсутствует (`command -v php` не находит binary). Runtime не устанавливался.
- Автоматические/regression/integration/SQL/browser tests не создавались и не запускались согласно AGENTS.md. Установленный warprift.ru/MyBB/ATF и фактическая доставка CSS не проверялись. Installed функциональность/production readiness не заявляется.

Ручная матрица владельцу на установленной изолированной копии:

1. Owner/GM/player/delegate/observer/outsider, native hidden/pending post: проверить одинаково HTML/DTO и header, отсутствие скрытых numeric/IDs/counts у status-only viewers.
2. Обычная тема/narrative post без receipt: карточки нет; промежуточный статус не выглядит applied. COMMITTED требует реального terminal proof.
3. Primary sequence из нескольких actors, ordered multiple targets и intercept: сохранённые порядок/исходы, отдельные costs, original/resolved target, AP primary без двойного учёта reaction/child.
4. Damage + barrier absorption + healing/overheal; item/equipment; statuses/forms/copy/transfer/dispel; reactions/prepared child; summon/object effects и boundaries. Сверить с immutable журналом; пустые разделы отсутствуют.
5. Раскрыть nested formulas/rolls/causal links: только persisted values и разрешённая audience. Изменить текущую KB/CS/alias/mask — результат не пересчитывается; explicit historical name остаётся прежним, отсутствующее остаётся fallback.
6. Quick Edit/full edit прозы, correction review, hide/delete/move: исходная механика/receipt не меняются; journal link открывает разрешённые связанные записи, а не переписанную карточку.
7. Keyboard Tab/Enter/Space, screen reader labels, narrow mobile/zoom/long keys/numbers: default closed, читаемость, отсутствие overflow/frame/animation и вмешательства в ATF sticky.

Эти сценарии **не выполнены Codex**. Следующая задача этапа 11 не начата.
