# ACS-11.2.1 — Journal, Archive, Achievements и Profile Combat Tab UX

База: `main` 9841fa32, merged ACS-11.1.6 (#1084). Один ограниченный UI PR.
Основания: A1.62–A1.66, A1.87, A1.92; нормативные Master Specification,
Technical Architecture, Implementation Roadmap, Integration Contracts и отчёты
ACS-10.4.1, ACS-10.4.2, ACS-10.4.3. Исторические отчёты и `docs/ROADMAP.md`
не изменены. Следующая задача не начата.

## Обнаруженные разрывы

- ArchivePage выводил весь уже фильтрованный event DTO как JSON в `<pre>`.
  Структура чтения и пагинация существовали, но пользовательского раскрытия
  действий/расходов/corrections не было.
- Current overlays rewards/loot/obligations были доступны manifest API,
  но не имели отдельных разделов HTML архива.
- StatisticsService возвращал полную историю до существующего лимита 1000
  источников одним ответом. Фильтр категории также выводил нулевые поля других
  метрик вместо выбранной категории.
- Lazy combat shell и navigation уже существовали в APUI/ATF. Однако submit
  фильтров уходил на standalone fragment, а навигации по страницам и отдельного
  чтения достижений в боевой вкладке не было.

## Журнал и архив

Сохранён существующий маршрут
`misc.php?action=acs_archive&session_id=…&encounter_id=…`.
История использует ArchiveService::page, прежний high-water encrypted cursor,
keyset SQL и whitelist projection. На страницу — 20 событий по умолчанию,
до 200 просмотренных сервером записей. Разреженный поиск может дать пустую
страницу с продолжением; это не объявляется отсутствием истории.

Фильтры раунда, исполнителя, участника, группы, категории, типа, PID и поиска
передаются прежнему валидатору. Identity filters показываются только при
разрешённом full view; серверный запрет для partial/hidden не изменён. Category
выбирается из существующего namespace списка. Изменение фильтра начинает новую
пагинацию, continuation сохраняет фильтры. Ссылка «Сбросить» убирает cursor.
Поиск по-прежнему работает только по разрешённым type/category/PID/ability key
и GM reason, не по сырому JSON, прозе или скрытым определениям.

В ленте — тип подтверждённой записи, доступный раунд, проверенная ссылка на
исходный MyBB-пост, сведения о его недоступности без выдуманного URL,
раскрываемые действия по порядку и вложенные разрешённые расходы, компоненты,
rolls/probabilities/causal calculations. Сохранённый damage budget явно не
обозначается фактической потерей HP. Административный audit не изображается
повторно исполненным боевым действием. Hidden запись не получает механических
подробностей; approximate явно обозначен как частичный отчёт.

ArchiveService::page дополнен outcome summary из verified confirmedOutcome:
keep_hidden не раскрывает его игроку, reveal_results даёт только kind,
full — существующий outcome whitelist. После чтения повторно проверяется
context stamp. Старые events и receipts не меняются.

Corrections имеют отдельный переход-фильтр и показывают original_event_id,
revision/disposition, current revision/disposition. Source остаётся самостоятельным
событием; correction не переписывает прошлое и не запускает Resolver.

В архиве добавлены ленивые отдельные разделы:

- **Участники (GM):** новая узкая read-only roster проекция через тот же
  ArchiveService context/native/session GM. SQL выбирает максимум 21 строку,
  возвращает 20 и continuation. Только имя/kind/entity/side/participation, без
  HP/AP/ресурсов/controller/owner authority. Это явно *текущие* имена и участие,
  не подмена исторических состояний. Для non-GM полный roster не раскрывается:
  в истории доступны только IDs участников разрешённых событий данной страницы.
- **Награды и добыча:** существующий extended manifest, отдельная страница из
  20 записей и next_after. Pending/prepared/recovery_required не названы выдачей;
  issued отображается отдельно. Это current status overlay, не immutable receipt.
- **Обязательства:** прежний current_session_obligations manifest и ссылка на
  ACS-11.1.6 recovery. Сохранены complete=false и явное предупреждение об
  исключённых achievement progress/rewards. Нельзя сделать вывод «все долги
  исполнены» по пустому частичному списку.

Для overlays сохранён current session GM **и** отдельный archiveExtended grant;
одного GM флага недостаточно. Участники используют прежнее право GM на roster,
не расширяют member visibility. Ручной ввод section не обходит service guards.

JSON/CSV ссылки вызывают прежний export API с текущими фильтрами. Обычный export
даже для GM остаётся member projection; расширенный требует прежний allowlist.
В UI явно сказано, что скачивается один chunk, а X-ACS-Next-Cursor продолжает
экспорт через API. UI cursor журнала не переиспользуется для другого export
binding. Не обещается один полный файл для неограниченной истории. Existing CSV
escaping, attachment/no-store/nosniff и ограничения размера сохранены.

## Профиль: итоги, история и достижения

`af_apui_combat_statistics_contract` и `StatisticsService::profile` принимают
дополнительный optional cursor. История выдаётся серверными окнами по 20,
с максимум 21 сохранённым history DTO в памяти. Cursor AES-GCM связан с viewer,
owner, filters и отдельным scope; числа пропущенных/скрытых источников клиенту
не отправляются. Каждый запрос заново проверяет текущие права всех источников.
Новая история требует отдельного явного перехода, автоматической загрузки всех
страниц нет. Изменение filters сбрасывает cursor.

Точные итоги продолжают охватывать **все разрешённые** completed источники по
выбранным filters, а не только текущую страницу истории. Для них сохранён прежний
bounded scan до 1000 consumer candidates; превышение даёт отказ, не частичный
выдуманный total. На каждой странице итогов этот ограниченный scan повторяется:
нового materialized store/cache или unlimited profile aggregation нет. История
больше не сериализуется целиком. При выбранной категории выводится только она.

Все прежние secrecy checks сохранены: verified consumer payload/hash/source
version, secrecy_safe, ordinary member archive access даже для GM, completed,
reveal_all, private/participants/public audience, безопасный participant scope.
Keep_hidden/reveal_results и hidden participant/stealth источники не становятся
точными profile totals, history membership или filter choices. Нет доступных
итогов — текст сообщает отсутствие разрешённых данных, не нулевую статистику.
Entity/character/pet/summon/control_group — отдельные представления, не общий
суммируемый рейтинг. Фильтры вида субъекта, существа, группы, категории и типа
источника сохранены. История ведёт на разрешённые архивы.

Достижения — отдельный lazy подраздел той же combat вкладки. Новый read-only
AchievementService::profile сужает existing progress policy до owner профиля:
читается существующий achievement_progress и его achievement_events, не второй
store. SQL до пагинации исключает неоткрытые/GM-only записи владельца и для чужого
профиля требует current GM/owner roles во всех связанных сессиях; service
повторяет native и role проверки. До 100 candidate rows сканируется на запрос,
выдаётся до 20 DTO;
continuation зашифрован и связан с owner/viewer/отдельным scope. До 200 distinct
origins на progress; превышение не выдаёт неполный progress. Проверяются progress
hash, recipient UID, definition pin и native/session доступ к каждому origin.
Для чужого профиля требуются GM полномочия во всех связанных сессиях; владелец
не получает gm_only или hidden_until_unlocked до открытия. Все origins должны
иметь completed и разрешённый member reveal_all: активный/скрытый источник
исключает *весь* накопленный progress, а не вычитает неизвестный вклад.
Повторные origin permissions в одном запросе переиспользуются локально, без
межпользовательского кэша. Ни earning, ни review, ни grant на GET не исполняется.
Открытие achievement не обозначает начисление reward.

AF read API дополнен `achievement_profile` GET (owner_uid, optional cursor)
с native profile gate; `statistics_profile` GET принимает optional cursor.
Новый `archive_roster` GET — sid/eid/after, current GM only. Остальные mutation
contracts и endpoints не меняются.

## APUI/ATF и установленный шаблон

Статически прослежена цепочка: APUI lazy dispatcher допускает combat, требует
native canview/canviewprofiles и существующий account; ATF navigation provider
`af_apui_render_atf_profile_navigation` создаёт combat при enabled ACS;
ATF `templates/member_profile.html` содержит matching `data-panel=combat` и lazy
URL. APUI собственный шаблон также содержит matching button/panel. Добавлены
role=tab/aria-controls в ATF navigation и role=tabpanel/label в ATF combat shell.
Основной postbit и его шаблоны не менялись.

Существующий APUI JS теперь перехватывает только специально помеченные combat
GET forms/pagination links. Данные остаются в том же lazy panel, filters и cursor
устанавливаются URL параметрами. Same-origin check, один запрос за раз,
aria-busy, focus на заголовке ответа, явная ссылка повторной загрузки после
ошибки; бесконечного retry/polling нет. Модификаторы клика сохраняют обычное
открытие ссылки. Другие profile tabs сохраняют прежний workflow.

**Installed NOT_VERIFIED:** установленного MyBB/DB/шаблона в рабочей среде нет.
Наличие вкладки *после обновления установленного шаблона* нельзя подтвердить
чтением seed-файла. Владелец должен применить обновление через штатный механизм
владельца шаблонов и проверить lease/template cache. Для ATF это существующие
acquire_templates/acquire_template и refresh_template_cache; они здесь не
исполнялись. Не следует вручную заменять template SQL или обходить ownership.
После штатного обновления проверить navigation и matching combat panel на
установленном member_profile, затем ленивый ответ и фильтры. До этого installed
пункт остаётся NOT_VERIFIED, а не accepted.

## Изменённые файлы

В `advancedcombatsystem/`:

- Application: ArchiveService, StatisticsService, AchievementService — узкие
  read projections/пагинация, без механики/нового statistics store.
- Infrastructure: ArchiveQuery, AchievementPersistence — bounded SELECT helpers.
- Presentation: ArchivePage, ArchiveController, StatisticsController,
  AchievementController — UI и strict read routing; ThreadComponents только CSS
  cache version 1121.
- advancedcombatsystem.php, manifest.php — lazy routes и contextual archive CSS.
- assets/presentation.css — scoped archive/mobile/native details/focus.

В `advancedprofileui/`: modules/combat_statistics.php, assets/advancedprofileui.js,
assets/advancedprofileui.css, advancedprofileui.php (только ARIA navigation).
В `adaptivethemeframework/`: templates/member_profile.html (только combat ARIA).
Этот отчёт: `docs/design/acs/ACS_11_2_1_IMPLEMENTATION.md`.

Нет schema/DDL/migrations, новых dependencies, owner writers, фоновых worker,
Resolver/Clock вызовов, publication gate/installed acceptance изменений. CSS
архива подключается только на явном маршруте; APUI использует существующий
profile bundle с filemtime version, новых showthread assets нет.

## Проверки и ручная приёмка

- **PASS:** `node --check` только изменённого advancedprofileui.js (syntax only).
- **PASS:** `git diff --check`.
- **PASS, статически:** реальные controller/service signatures, routes/allowed
  fields, encrypted cursor scopes, completed/reveal_all guards, bounded SELECT,
  template shell/navigation/lazy dispatcher, contextual CSS manifest.
- **NOT_VERIFIED:** `php -l` — PHP CLI отсутствует, runtime не устанавливался.
- Автоматические, regression, integration, SQL-fixtures, browser/UI tests не
  создавались и не запускались согласно AGENTS.md. Installed функциональная
  приёмка warprift.ru/MyBB/APUI/ATF/owner addons — NOT_VERIFIED.

Ручная матрица для владельца:

1. Journal filters/search/reset, несколько страниц и пустая страница с cursor;
   новые события/изменение policy, stale/tampered/cross-viewer cursor.
2. Действия/costs/details клавиатурой, source PID moved/deleted/unreadable,
   corrections рядом с исходной историей, отсутствие повторной механики.
3. GM/player/owner/observer/outsider: keep_hidden/reveal_results/reveal_all,
   запрет identity filters/скрытых IDs/сумм; отдельный extended allowlist.
4. Roster GM, member видимые события; overlays rewards/loot/obligations и их
   pagination/scope/исключения; pending никогда не показан выданным.
5. JSON/CSV ordinary/extended chunks, X-ACS-Next-Cursor и размер; фильтры не
   меняются между export страницами; файл не заявлен полным при continuation.
6. Штатное обновление установленного member_profile/cache, APUI и ATF shell,
   отсутствие combat queries до открытия вкладки и неизменный postbit.
7. Profile filters/page/reset внутри вкладки, totals независимы от history page,
   нет лишних метрик при category; private/participants/public, hidden actors,
   stealth, pending correction consumer и active боёв в totals нет.
8. Achievements own/other GM scopes, hidden-until-unlocked, mixed active/hidden
   origins и cross-session denial; pager не исполняет earning/награды.
9. Mobile/zoom/длинные числа, клавиатура/focus, потеря ответа/retry без polling.
   Выполнить PHP lint перед установкой. Codex эту матрицу не выполнял.
