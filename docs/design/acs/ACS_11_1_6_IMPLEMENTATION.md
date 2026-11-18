# ACS-11.1.6 — Completion, Loot, Rewards и Recovery UX

База: `main` 3253a135, merged ACS-11.1.5 (#1083). Один ограниченный UI PR.
Требования: A1.27–A1.40, A1.66–A1.68. Использованы нормативные Master
Specification, Technical Architecture, Integration Contracts, Implementation
Roadmap, отчёты ACS-10.1.1–10.4.3 и действующие сервисы. `docs/ROADMAP.md`
не изменён. Следующая задача не начата.

## Маршрут и точка подключения

Существующий `misc.php?action=acs_loot&session_id=…&encounter_id=…` теперь
открывает единый маршрут с нативной навигацией: «Результат», «Добыча»,
«Награды», «Достижения», «Восстановление». Старые ссылки панели темы продолжают
работать. Добавлены переход из GM управления исходом и обратная ссылка из
RecoveryPage. Без encounter GM получает постраничный выбор столкновений
существующим EncounterService::list; новые сессии/столкновения не создаются.

`LootAllocationPage::renderView` сохранён как renderer фильтрованного DTO;
`render` делегирует композицию CompletionPage. Ни второй GM backend, ни новый
Resolver не создаются. Только явно открытая вкладка загружает соответствующие
сервисы. Все команды — отдельные POST через существующие controllers/services,
с прежними CSRF, session/encounter/claim/allocation/operation CAS и permission
guards. GET не генерирует добычу, не начисляет награды и не исполняет границы.
Безопасные ошибки записываются существующей диагностикой с error_id; SQL,
exception text, owner envelopes и raw receipts в HTML не выводятся.

## Результат и добыча

GM видит сохранённый pending_resolution, условие, предполагаемую сторону
победителя и только допустимые сервером варианты исхода. Подтверждение/разрешённое
продолжение вызывают EndConditionController; требуется явное подтверждение
последствий. Подтверждённый исход отображается отдельно от выдачи наград.
Повторного вызова Resolver нет.

Сохранена server-side фильтрация LootAllocationService. Игрок получает только
разрешённые entries и собственные заявки; GM — разрешённые группы и заявки.
Чужие заявки, скрытые победители, eligible counts, receipt/roll/contenders не
добавляются в player HTML. Пустые скрытые карточки и общие скрытые количества
не создаются. Поддержаны существующие gm_assign/free_claim/need_greed, включая
pass только при allow_pass. Для заявок показано закрытие; закрытие need/greed
требует явного подтверждения и закрепляет прежний результат.

| Сохранённое состояние | Отображение и разрешённое продолжение |
|---|---|
| generated | Сгенерировано · распределяется; только доступные сервисом заявки/назначение/закрытие |
| awaiting_issue | Победитель определён · ожидает выдачи; отдельный запрос Inventory |
| pending_issue | Требует восстановления; выдача не подтверждена; lookup прежней операции |
| issued | Выдано; сервис проверяет сохранённый owner receipt |
| issue_rejected | Owner отклонил выдачу; победитель сохранён, новой выдачи/переброса нет |
| allocation_closed | Заявки закрыты, получатель не определён; нового случайного выбора нет |

GM может явно сгенерировать только ранее закреплённый источник после
подтверждённого исхода, пока сессия активна. Существующий pool обозначен как
сохранённый, кнопки повторной генерации нет. При preparation доступны формы
закрепления поддержанного KB-источника через существующие contracts; после
старта источники не подменяются. Полный KB-каталог не загружается.
Inventory grant остаётся ограничен consumable/ammo и точным pinned owner
профилем. Изменённое определение/неподдержанные metadata дают понятный отказ.
Не добавлены прямые INSERT в Inventory, новые kinds или обход authorization.

## Награды и достижения

GM видит распределения закреплённых reward sources и отдельное состояние каждого
получателя: pending, prepared, recovery_required, issued, blocked. Each и
split_equal рассчитывает прежний серверный сервис. Manual UI принимает целые
minor amounts только для серверного списка eligible recipients; сервис повторно
проверяет сумму, права, источник и получателей. HTTP allocations не становится
произвольным редактором наград. Новое распределение блокируется вне active
session; уже сохранённые обязательства остаются доступны.

EXP/валюта отображаются точно, без float, с scale 100 действующего Balance
контракта и исходной величиной в minor units. Pending/prepared не показываются
начисленными. Только issued после verified owner receipt обозначается успехом.
Permanent CharacterSheets sync остаётся BLOCKED: его marker не показывается как
денежная сумма, выдача/компенсация не предлагаются.

**Ограничение существующего контракта:** RewardService::view/inspect сейчас
GM-only. Новый UI не расширяет это право до игрока/observer. Для них вкладка
объясняет ограничение без сумм, recipient IDs или количества чужих наград.
Это не заявление о готовом player reward-read API.

AchievementService::view сохраняет свою visibility/account/cross-session policy.
Отображается разрешённый накопленный прогресс, subject, факт открытия и GM
review_required. Открытие achievement не обозначает начисление связанной награды.
GM получает конкретные необработанные источники из существующей durable очереди,
пересечённой с event IDs выбранного encounter: отдельный POST на каждый источник,
без ручного ввода произвольного event ID и без автоматического earning на GET.
Проверку frozen proof, pin/timing и replay выполняет AchievementService.
Review/migrate/correction editor не добавлен. GM reward/progress lists и выбор
encounters имеют постраничную навигацию в рамках существующих API limits.

## Ключи, неизвестный ответ и восстановление

Разделены клиентский nonce команды подготовки и сохранённый owner operation_key.
При pending_issue/recovery_required отображается прежний owner key, а lookup
принимает только идентификатор обязательства и ожидаемую версию. Существующие
LootIssueService/RewardService сами восстанавливают точный retained owner intent;
UI не создаёт ему новый key, recipient, сумму или owner version.

Особый случай — prepared до первой отправки. Lookup не делает first dispatch.
`Infrastructure/CompletionQuery::retainedIssue` после current native/session GM
authorization и проверки существующих lootIssueIntent/rewardIntentEvidence
читает ровно одну committed подготовительную команду, проверяет request hash,
issuer/session/key binding и возвращает whitelist её исходных native аргументов.
Не возвращает owner envelope/receipt. Отдельная подтверждаемая кнопка продолжает
**тот же** loot_issue/reward_issue с прежним nonce, reason и CAS, не новым intent.
При гонке существующий сервис повторно проверяет состояние; начавшаяся отправка
ведёт к lookup, а не второму grant/credit. Loot сохраняет original issuer-only
guard; для Balance текущий разрешённый GM действует по прежнему контракту,
с сохранённой первоначальной issuer identity в owner intent.

Recovery вкладка показывает bounded obligations всей сессии по категориям:
ожидающие, recovery_required, явно заблокированные. Inventory, Balance, custody,
statistics, loot, achievement/review, публикации и прочие обязательства имеют
понятные пояснения; отсутствие safe scope не превращается в кнопку обхода.
Для Inventory/custody/publication открывается существующая RecoveryPage с её
строгими командами; для Balance/loot — lookup соответствующего обязательства.
Закрытие/архивирование не списывает долг и не запускает бой повторно.

Statistics status читается через существующий RecoveryService. Первая публикация
после confirmed completion — отдельный явный statistics_sync с выбором visibility.
Дальнейший statistics_recover сохраняет producer visibility и его stable keys.
Неудача statistics не скрывает остальные долги и не обозначает отказ боя/наград.
Ни фонового retry, ни автоматической компенсации, ни универсального obligation
executor не добавлено.

## Assets и изменённые файлы

Относительно addon root:

- Новый `modules/Presentation/CompletionPage.php`: композиция вкладок, native
  формы, честные состояния и whitelist команд существующих контроллеров.
- Новый `modules/Infrastructure/CompletionQuery.php`: bounded read-only выбор
  исходных issue arguments и разрешённых achievement source candidates.
- `modules/Presentation/LootAllocationPage.php`: сохранён filtered renderer,
  русские стадии/заявки, закрытие, делегирование маршрута.
- `modules/Presentation/GmIntent.php`, `GmPanel.php`, `RecoveryPage.php`:
  последствия критических операций и переходы к маршруту завершения.
- `advancedcombatsystem.php`: прежний misc route, contextual CSS и безопасный
  error_id при отказе открытия.
- `manifest.php`: response fact has_acs_completion для presentation.
- `modules/Presentation/ThreadComponents.php`: только CSS cache version 1116.
- `assets/presentation.css`: scoped wrapping/flex/native focus/mobile формы.
- Этот отчёт.

JS не добавлен. Нативные формы, details/summary и ссылки доступны с клавиатуры.
CSS запрашивается через существующий contextual asset gate; обычный showthread
не получает новый runtime. ATF/postbit/sticky/editor не меняются. Application и
Domain механика, owner adapters, schema/migrations/ledger и игровые данные не
меняются. Publication_enabled и installed acceptance не изменяются.

## Разрешённые проверки и ручная приёмка

- **PASS:** `git diff --check`.
- **PASS, статически:** real controller/service signatures и строгие fields;
  session/encounter scope, old-key prepared continuation, lookup-only recovery;
  SQL нового query содержит только SELECT с bound parameters и limits;
  отсутствуют новые migrations, тесты, dependencies и изменения docs/ROADMAP.md.
- **NOT_VERIFIED:** PHP syntax (`php -l`) — PHP CLI в среде отсутствует,
  новая среда не устанавливалась.
- Автоматические, regression, integration, SQL-fixtures и browser/UI tests
  не создавались и не запускались согласно AGENTS.md. JS не изменён.
- **NOT_VERIFIED:** установленный MyBB/ATF/KB/Inventory/Balance/Statistics,
  функциональная и визуальная приёмка warprift.ru. Production readiness и
  установленная приёмка публикации не заявляются.

Владельцу требуется ручная проверка на изолированной установленной копии:

1. Pending outcome → confirm/continue, stale CAS и повтор команды: один исход,
   без нового Resolver; outcome/winner только из сохранённого кандидата.
2. Закреплённый source → generation один раз → gm_assign/free_claim/need-greed-pass;
   собственные claims, закрытие/all-pass/tie, скрытый winner и outsider/observer.
3. Awaiting issue → issued/rejected/unknown; winner и rolls неизменны при отказе
   Inventory. Prepared до dispatch продолжается исходной командой; unknown после
   dispatch предлагает lookup. Повтор/две вкладки не создают второй предмет.
4. Each/split/manual, неверная сумма/получатель, partial recipients, owner
   unavailable/version conflict/lost ACK, same-key lookup после close/archive.
   Pending не становится issued; permanent sync остаётся BLOCKED.
5. Achievement queue → одно earning на source, повтор и cross-session отказ,
   visibility/hidden-until-unlocked, награда отдельно от progress.
6. Recovery obligations всех категорий, безопасные custody/Inventory/publication
   переходы; statistics first visibility, retained recovery, offline consumer.
7. Права GM/player/owner/observer/outsider, чужой session/encounter/reward ID,
   CSRF и malformed input, отсутствие raw receipts/скрытых counts в HTML.
8. Mobile/keyboard/zoom, длинные ключи/числа, contextual CSS и обычная тема без ACS.
   Выполнить PHP lint перед установкой. Эта матрица Codex не выполнялась.
