# ACS-4.2.2 — фронтенд подготовки хода

Исходная main: `355c3d7d832e7f9db87e3da7c9146efa99898f48`. Версия ACS — `0.18.0`, схема остаётся `006_drafts`, новых миграций нет. Основание: четыре нормативных документа ACS и последующее уточнение ACS-4.0 о независимости подготовки от публикации и Appearance. IC-MYBB-1 остаётся **BLOCKED**.

## Что доступно

Существующий ATF provider `advancedcombatsystem::session_link` в `thread.before_posts` показывает членам сессии owner/gm/assistant_gm/player ссылку **«Подготовить ход ACS»**. Явный переход `showthread.php?tid=<tid>&acs_builder=<session_id>` открывает конструктор активного/paused столкновения; можно указать `acs_encounter`. Native forum/session access и связь с текущим tid повторно проверяются сервером. Обычное открытие темы не подключает BuilderService/Resolver/JS/CSS конструктора. GM-панель по прежнему маршруту сохранена.

В конструкторе есть обзор раунда/состояния/группы/участников/доступного AP, выбор собственного контролируемого персонажа или зарегистрированного питомца, способности и разрешённой цели. Очередь незапечатанных действий поддерживает добавление, удаление, перемещение и изменение selectors. Стоимость приходит из pinned KB definitions, клиент не передаёт цену. UI показывает сумму планируемого AP и недостаток общего бюджета. Это не списание и не отдельный AP питомца.

Литературный текст хранится в ограниченном BBCode textarea с действующим `data-af-ae-skip="1"`; SCEditor не создаётся. Не меняются AdvancedEditor, его Source/WYSIWYG, Quick Reply, Quick Edit, постбит или шаблоны ATF. Кнопка **«Отправить боевой пост» всегда disabled**, без обработчика отправки; рядом «Боевая публикация пока не подключена». Нет MybbPostGateway, seal или RNG tape.

## Сохраняемые блокировки и границы приёмки

**Нельзя считать все пользовательские сценарии preview разблокированными.** Текущая main регистрирует участников с `visibility_json={schema_version:1,scope:"gm"}`; нет принятого per-player awareness/full/approximate протокола. Этот PR не превращает такие записи в публичные и не добавляет выдуманную visibility policy.

- Обычный player видит собственные управляемые существа, их pinned каталог/AP и свои drafts; может сохранять/восстанавливать подготовку. Скрытые GM-only цели и их количество/характеристики не передаются. Если нет разрешённых целей, UI объясняет это и не предлагает фиктивную цель. Полноценный player preview против таких целей остаётся **BLOCKED до отдельного visibility-контракта**.
- В существующем GM visibility scope (owner/gm/нативный ACP view при отдельном праве управления actor) доступны hostile GM-visible цели и полный условный preview. GM не получает контроль над чужим персонажем.
- Поддержан текущий ограниченный контракт Resolver: direct-granted pinned standalone damage ability, одна враждебная цель на действие, положительный целый AP, flat damage либо уже поддержанная pinned formula. Нет предположительного `raw=ATK` для «обычной атаки». Неподдержанные effects/costs/cooldowns/reflection/lifesteal/dynamic formula bindings не становятся READY от появления UI.
- Поддержанные определения имеют фиксированные KB-параметры; произвольные editable numeric параметры пока не поддержаны серверным preview. Старые непустые `parameters` сохраняются без потери, UI сообщает причину BLOCKED и даёт **отдельный подтверждаемый** сброс; не заменяет их пустым объектом при открытии. Можно менять actor/ability/target. Ввод raw JSON пользователю не нужен.
- Постоянные питомцы отображаются, если уже зарегистрированы через разрешённый источник. Будущий pet plugin/его ownership API этот PR не реализует; тестовый питомец — изолированный fixture, не автоматически созданная production-сущность.

Механика Appearance в построении существа, каталога, стоимости, dependency token и preview не читается. Любая смена маски не должна менять ACS-подготовку; никакие Appearance hooks/commit paths не вызываются. Техническая блокировка публикации не мешает независимому draft/preview в поддержанном scope.

## Изменённые файлы

Относительно `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

- Новый `modules/Application/BuilderService.php`: безопасный UI context и единый ordered preview.
- `modules/Application/QueryService.php`: собственные controls/catalog queries без GM-target disclosure; выделен прежний pinned ability parser для повторного использования, без нового расчёта механик. Каталог возвращает AP/поддержку и безопасные причины.
- `modules/Presentation/BuilderController.php`: новый UI и отдельный builder API dispatch, прежний conditional GM preview сохранён.
- `modules/Presentation/ThreadComponents.php`: ссылка и явное открытие конструктора через существующий ATF slot; players включены в кандидаты собственных сессий.
- `modules/Infrastructure/DraftPersistence.php`: read-only поиск актуального active/paused encounter и собственного открытого draft по turn, чтобы восстановить ID после перезагрузки без браузерной догадки.
- `modules/Infrastructure/RoundManagementPersistence.php`: узкий read-only preview mode проверки обязательств; plain unsealed draft без PID/confirmation больше не блокирует preview. Sealed/submission/effects/unknown obligations по-прежнему проверяются. Команды skip/round transitions используют прежний строгий режим и не игнорируют drafts.
- `modules/Bootstrap/Context.php`: явный builder surface остаётся read view, не выдаёт права.
- `advancedcombatsystem.php`: ленивый dispatch `builder_context`/`builder_preview`; manifest: версия и contextual resource policy.
- Новые `assets/builder.js`, `assets/builder.css`: scoped controls, state/serialization, no global CSS rules; ресурс разрешён AF manifest только при явном `has_acs_builder` факте рендера. Directory fallback выключен.

Проверки: новые `tests/acs_builder_regression.php`, `tests/acs_builder_state.js`, `tests/acs_builder_asset_scope.php`; в существующем `tests/acs_preview_regression.php` добавлен callback для повторного использования реального pinned fixture. Этот отчёт — новый Markdown. AF Core/ATF/Editor/Appearance/другие аддоны не изменяются.

## API и доверенная граница

Оба новых op используют существующий `xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem`:

- `builder_context` — GET `session_id`, `encounter_id`, необязательный `turn_id`. Возвращает только разрешённые группы/существа/abilities/hostile targets, собственный draft_id, состояние/round/AP и непрозрачный `scope_token`. Raw KB definitions/AST/source snapshots в JSON отсутствуют. Закрытый собственный turn показывается read-only.
- `builder_preview` — POST/CSRF `session_id`, `encounter_id`, `turn_id`, `scope_token`, `actions` (JSON-массив формата draft_save), `scenario=hit|crit|miss`. Cost/permission/result поля и неизвестные верхнеуровневые selectors отклоняются. Технически ≤20 действий и 64 KiB JSON за preview, не игровая квота; draft сохраняет прежние технические пределы.

Для каждого action сервер проверяет actor в выбранной собственной группе, видимую разрешённую цель, grant, точные pinned definitions, turn/EntityTurn start и зависимости через существующий QueryService. Готовые контексты объединяются в одно виртуальное состояние, затем вызывается **один** существующий Resolver для всей упорядоченной очереди. Поэтому AP персонажа/питомца и повторный урон одной цели учитываются последовательно, не отдельными независимыми previews. Стихийные бонусы, для которых нет поддержанного контракта, остаются BLOCKED как в старом preview. После расчёта права/версии перечитываются; drift отклоняет результат.

Ответ содержит `conditional:true`, `persistent:false`, явно условный label/scenario, per-action probability/cost/component allocation и разрешённое virtual HP/AP состояние в GM scope. Формулы H1/C1/D1, порядок, Numeric/Modifiers/Effects не меняются. Крит/попадание не выбираются случайно: UI просит сценарий «все попадания без крита / критические / промахи». Невозможный сценарий отклоняется существующим Resolver. Ни HP/AP, ни часы, ни events/snapshots/диагностика не записываются.

`draft_save`/`draft_restore` остаются API ACS-4.2.1. UI автоматически обнаруживает/восстанавливает собственный серверный draft при свежем открытии. CAS конфликт оставляет локальные очередь и прозу; замена серверной версией требует отдельной кнопки/подтверждения при dirty state. Старые asynchronous preview responses игнорируются после редактирования/смены контекста. Нет фонового polling или вызовов на каждый пост.

Неопределённый результат save удерживает **тот же** operation_key/payload; поля блокируются до retry/recovery. Pending request и несохранённая копия сохраняются в sessionStorage этой вкладки с ключом author/session/encounter/turn; storage failure не отключает серверный save. Случайный UUID применяется только как transport operation identifier, не как боевой бросок. Восстановление server draft не запускает публикацию. Локальные данные не являются доверенными ни для цены, ни для права или результата.

## Выполненные проверки

PHP CLI 8.4.24 и изолированная MariaDB 10.11.19; установленный warprift.ru/PHP 8.5.0 не использовались.

- `php -l` 12 затронутых PHP-файлов, `node --check` JS; чистый `git diff --check`, уникальность нового класса.
- `node tests/acs_builder_state.js`: add/edit/remove/reorder, параметры не теряются, сериализация draft/preview, exact operation retry/rehydration и restore.
- `php tests/acs_builder_asset_scope.php`: настоящий AF frontend resolver допускает builder resource только с explicit component fact, глобальная доставка/fallback запрещены.
- `tests/acs_builder_regression.php` повторно использует существующий узкий real DB round/preview fixture. Проверены две сущности группы/питомец и AP 3→1 только виртуально, ordered result replay, server cost/injected parameters/targets/versions/AP overrun denial, GET без мутаций, normal player hidden-target gate, draft save/discovery/restore и preview после save, строгий GM round gate и confirmation obligation. Изменённый Appearance input не меняет context/token. Производственных pets/боёв не создаётся.
- Сравнение ACS tables/owner fixture до/после read-only requests: без изменений. После draft_save допустимы только draft/receipt; preview/рендер не меняют эти записи. Real immutable snapshots меняются **только явным fixture seed перед baseline**, не приложением.
- Ограниченная DOM-проверка на **фактическом HTML рендера** в jsdom (временная зависимость вне репозитория, не E2E): кнопки add/reorder/edit, selectors, поля prose, conditional output без undefined, потеря response → exact retry, CAS conflict не стирает локальный текст, server restore, disabled publish и отсутствие SCEditor.
- Обычный showthread bootstrap не загружает BuilderService/QueryService/Resolver. Полный suite, браузерный E2E и массовые визуальные проверки не запускались.

Изолированные DB fixture удаляются в finally; контейнер после проверки остановлен. Программная проверка не означает ручную приёмку на целевом PHP/теме форума.

## Ручная приёмка

1. Обновить файлы ACS; схема остаётся 006, ACP upgrade нужен только если ACS-4.2.1 ещё не установлен. Открыть тестовую связанную тему, нажать «Подготовить ход ACS». В обычной теме без сессии конструктор не появляется.
2. Войти владельцем/GM с **отдельным** контролем тестового существа. Нужен current open pending turn и явно обработанный EntityTurn start, как у предыдущего preview. Выбрать группу, проверить round/status/участников/3 AP. Если начало хода не обработано, preview должен объяснить отказ, не выполнить boundary автоматически.
3. Выбрать закреплённую поддержанную KB ability и hostile цель, добавить действие, затем действие зарегистрированного разрешённого питомца. Переместить/изменить/удалить строку. При неподдержанной способности/параметре проверить явный отказ, а не приблизительный расчёт.
4. Выбрать условный сценарий и рассчитать очередь. Проверить P_hit/P_crit, DEF/RES allocation и virtual HP/AP; повторить — нет нового броска. Сравнить реальные ресурсы до/после.
5. Написать прозу, сохранить, перезагрузить — server draft восстанавливается. В двух вкладках сохранить разные версии: stale вкладка сохраняет локальную копию, предлагает отдельное restore. При сетевом сбое повторить тот же save request.
6. У обычного player проверить собственные существа/draft и отсутствие GM-only целей/их данных. Этот сценарий не может подтвердить полный player preview до принятия visibility contract. Не менять скрытые данные на public ради теста.
7. Сменить маску и повторить: actor identity, очередь, scope token и расчёт не должны измениться. Проверить обычный editor/Quick Reply/Quick Edit и отсутствие второго SCEditor.
8. Кнопка combat publish недоступна при любых сценариях. Боевой post/seal не отправлять.

Откат — revert PR, оставив schema 006/drafts/receipts. Нового DDL нет. ACS-4.2.1 API и данные сохраняются; браузерные sessionStorage копии этой вкладки можно оставить до восстановления или удалить осознанно. Публикация не разблокирована. Следующая задача автоматически не начата.
