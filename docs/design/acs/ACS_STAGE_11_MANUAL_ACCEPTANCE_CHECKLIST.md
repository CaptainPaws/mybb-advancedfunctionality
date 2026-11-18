# ACS Stage 11 — чеклист ручной приёмки ACS 1.0

## Статус и порядок работы

**Все сценарии ниже НЕ ВЫПОЛНЕНЫ.** Это рабочий бланк владельца, не протокол PASS Codex. Source review описан в [ACS-11.4.1](ACS_11_4_1_IMPLEMENTATION.md). Проверять на изолированной установленной копии форума с отдельными БД, аккаунтами, owner-хранилищами и очередями; не проводить fault injection/пробные выдачи на production. Production `publication_enabled=false` сохраняется во всех сценариях. После приёмки требуется отдельное решение администратора; этот документ ничего не включает.

PASS означает выполненные шаги и совпадение видимого результата **и** сохранённого механического состояния. FAIL — расхождение, утечка, лишний эффект или небезопасное продолжение. BLOCKED — отсутствует prerequisite, owner contract, разрешённый UI либо подтверждённое installed binding. Отсутствие данных не означает PASS и не подменяется нулевой статистикой. Без отметки сценарий не проверен. Ожидаемый безопасный отказ можно отметить PASS проверки отказа, одновременно оставив положительную функцию BLOCKED.

Порядок: A → B → подготовка C → проверки с seal без публикации → **A-R2** → разрешённая test-only публикация C → D–J. Если R2 заблокирована, все сценарии, которым нужны новые committed combat events, получают BLOCKED; допускается read-only проверка уже подтверждённых данных. Не обходить gate для демонстрации UI. Ветки с неизвестным результатом сначала проверять по прежнему ключу/ID; не создавать новый пост, key или roll.

## Паспорт прогона

| Поле | Заполнить |
| --- | --- |
| Дата, проверяющий, deployed commit / PR | ___ |
| Staging URL / тема / desktop и mobile браузеры, viewport | ___ |
| MyBB / PHP / MySQL / AF / ATF / AdvancedEditor / ACS версии | ___ |
| KB / CS / Inventory / Balance / Statistic / AAM owner версии | ___ |
| ACS ledger / integrity / отдельные owner upgrades | ___ |
| Роли и UID: G, O, P, D, V, X, ACP | ___ |
| TID / SID / EID / группы / начальные версии | ___ |
| Начальные HP/AP/resources, owner balances/qty, obligations | ___ |
| Ссылка на закрытое хранилище evidence / журнал дефектов | ___ |
| R2: ответственный / решение / ссылка на доказательства | ___ |

Сокращения ролей: **G** — текущий persisted session GM; **O** — владелец персонажа/группы (отдельно отметить, является ли он owner сессии); **P** — другой игрок; **D** — действующий делегированный контроллер с перечисленными полномочиями; **V** — observer; **X** — outsider без session role; **ACP** — администратор AF, не назначенный GM автоматически. Подготовить отдельные учётные записи; не использовать декоративную маску как смену authenticated actor.

Нужны две разрешённые сессии разных TID, бой с двумя сторонами, собственный CS-лист, разрешённый KB NPC, скрытый участник/объект, pinned поддержанные способности, предметы и источники наград. Для optional multiple/prepared/spawn/transform/cover требуются реальные валидные KB definitions. Отсутствующий контракт не заменять ручным SQL, callback, прямым Inventory INSERT или произвольным JSON. Постоянный pet source по умолчанию не подключён; его положительный сценарий допустим только с подтверждённым owner adapter.

Для механических ожиданий использовать существующие разрешённые GM/ACP inspect, журнал, receipts и read-only инструменты владельца. В каждом evidence сохранить до/после версии, статусы, известные submission/PID/operation key, число effects/receipts и hashes, где доступны. Скриншоты без секретов/CSRF/tape чужого scope/персональных данных; hidden data и сырые payloads — только в закрытом авторизованном evidence. Сам браузерный экран не доказывает rollback или exactly-once.

Маршруты (ID заменять своими, ACP directory может отличаться):

- **T**: `showthread.php?tid=TID`; **G**: `T&acs_session=SID&acs_encounter=EID&acs_tab=overview|participants|rounds|events|management` (T уже содержит `?`).
- **W**: `showthread.php?tid=TID&acs_setup=1`, затем ссылки шести шагов мастера.
- **B**: `showthread.php?tid=TID&acs_builder=SID` → выбрать разрешённое столкновение/группу; использовать сгенерированные UI ссылки, не придумывать turn IDs.
- **L**: `misc.php?action=acs_loot&session_id=SID&encounter_id=EID&completion_tab=outcome|loot|rewards|achievements|recovery`.
- **H**: `misc.php?action=acs_archive&session_id=SID&encounter_id=EID`; **R**: `misc.php?action=acs_recovery&session_id=SID` либо G → Управление → recovery.
- **A**: `admin/index.php?module=advancedfunctionality&af_view=advancedcombatsystem&acs_section=main|permissions|sessions|diagnostics|integrations|publication`.
- **F**: существующий `xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem&op=...`; через Network можно сверить реальные UI-запросы. Mutation вручную повторять только в обозначенных отрицательных сценариях на staging.
- **P**: `member.php?action=profile&uid=UID`, вкладка «Боевая статистика», lazy URL с `af_profile_tab=combat`.


## A. Установка и capability readiness


### A-01 — Baseline и read-only ACP

- Страница / роль: A: Основные → Диагностика · **ACP**.
- Предусловия: Развёрнут согласованный commit PHP/JS/CSS; сохранён baseline.
- Действия: Открыть Основные; затем Диагностику; обновить обе страницы; записать версию и весь ledger.
- Видимый результат: Версия аддона, актуальная схема 029_statistics, completed 001–029 и ошибки различимы; публикация закрыта.
- Механическое состояние: GET не запускает migrations/DDL/Clock/выдачу; целостность проверяется, ошибки ledger сохраняют BLOCKED.
- Признаки сбоя: READY при failed/checksum/schema mismatch; изменение ledger или игровых ресурсов от просмотра.
- Связь: A1.67, A1.89–A1.93; 11.0.1, 11.3.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### A-02 — Настройки, права и реестр

- Страница / роль: A: main → native config-settings → permissions → sessions · **ACP, затем G**.
- Предусловия: Есть старая сессия и разрешённое право MyBB config-settings.
- Действия: Изменить default role observer/player штатным редактором; создать новую сессию W; сравнить старую. В реестре применить TID/status/UID фильтры и следующую страницу; открыть GM ссылку.
- Видимый результат: Только поддерживаемые настройки; read-only matrix с RESERVED_DENY; реестр и понятные фильтры. GM ссылка только текущему GM.
- Механическое состояние: Новый default копируется только в новую сессию; старые encounter configs неизменны; реестр не выполняет команды.
- Признаки сбоя: Перезапись старых configs, выдача GM по ACP, игровые формы в глобальном реестре, изменение состояния при пагинации.
- Связь: A1.41, A1.67–A1.68, A1.93; 11.3.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### A-03 — Owner readiness и адресная проверка источника

- Страница / роль: A: integrations/publication · **ACP**.
- Предусловия: Известны валидные sheet ID/KB key и заведомо невалидная versioned reference на тестовой копии.
- Действия: Открыть без source; нажать проверку; выбрать по одному источнику; повторить для невалидного элемента; сверить Inventory/Balance/Statistic/AAM rows.
- Видимый результат: Пустой источник NOT_CHECKED/SOURCE_NOT_SELECTED; реализация, lazy runtime, источник и installed acceptance раздельны. element_version_invalid указывает canonical key/type/reference и безопасное действие.
- Механическое состояние: Никакие KB/CS данные, grants, версии и fingerprint не исправляются; класс/файл не делает installed READY.
- Признаки сбоя: Raw JSON/SQL; весь каталог загружен/изменён; source READY выдан за publication acceptance.
- Связь: A1.67, A1.89–A1.92; 11.0.1, 11.3.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


## A-R2. Обязательная installed acceptance Revision 2


Отдельный обязательный gate по [ACS-4.5-R2](ACS_4_5_R2_INTEGRATION_ACCEPTANCE.md), IC-MYBB-1/IC-ACCEPTANCE-1. Исторические формулировки «consumers не реализованы» уточняются текущими Stage 6/10/11 owner contracts; native side effects всё равно проверяются отдельно. A-R2-01 разрешает только изолированный test-only прогон. Даже PASS всех пунктов не включает production capability автоматически. Неизвестный native PID нельзя искать/привязывать по времени, uid/tid, MAX(pid) или тексту.


### A-R2-01 — Installed source, hooks и staging binding

- Страница / роль: A: publication; native newreply/Quick Reply/moderation · **ACP + администратор runtime**.
- Предусловия: Изолированная копия, отдельные owners/очереди; сохранён baseline A-01.
- Действия: Снять фактические source SHA, engines, writer routing и исполняемые frontend/moderation callbacks. Сравнить с R2 audit; отдельно сохранить ACP snapshot. Настроить существующий test gate только на staging: AF_ACS_ENVIRONMENT=test, test UID allowlist и точный AF_ACS_PUBLICATION_TEST_ORIGIN.
- Видимый результат: Несовпадения видны как blockers; подготовка может работать без publish. Test gate ограничен UID/origin; production false.
- Механическое состояние: Native MyISAM не конвертируется; ACS/owner требования InnoDB проверены отдельно; ACP registry не считается frontend execution proof.
- Признаки сбоя: Разрешение по версии/классу/ACP snapshot; смешанные базы или production test opt-in; отключение сторонних hooks.
- Связь: A1.50, A1.60, A1.90–A1.94; 4.5-R2, 11.1.3, 11.3.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### A-R2-02 — Native lifecycle и один пост

- Страница / роль: newreply.php?tid=TID и Quick Reply из T · **O, разрешённый test UID**.
- Предусловия: A-R2-01 пройден для изолированного прогона; свежий confirmed draft для каждой поверхности.
- Действия: На каждой форме отправить narrative; затем отдельный combat. Повторить double click/две вкладки одного combat intent; сравнить native counters/Balance→CS/AAM с baseline обычного reply.
- Видимый результат: Реальные processing/moderation/committed стадии; один известный PID на intent, карточка только по proof.
- Механическое состояние: Один post_link/result/terminal receipt и один расход; narrative не закрывает ход. Native side effects не дублируются ACS consumer.
- Признаки сбоя: Два INSERT/расхода/уведомления, пропуск flood/permissions/captcha/moderation/Appearance hooks, фиктивный success.
- Связь: A1.49–A1.52, A1.60, A1.89; 4.5-R2, 11.1.3–11.1.4.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### A-R2-03 — Отказы до и после INSERT, ACS rollback

- Страница / роль: Native combat submit → R · **O + G + администратор staging**.
- Предусловия: A-R2-01; согласованный безопасный способ прерывания только тестовой копии.
- Действия: Отдельно вызвать native отказ до INSERT; поздний hook failure/прерывание после INSERT; отказ перед ACS COMMIT/outbox. Зафиксировать фазу и известный PID через разрешённый inspect; открыть R без нового submit.
- Видимый результат: REJECTED только при доказанном pre-insert отказе; unknown — RECOVERY_REQUIRED; нет сообщения об applied damage без receipt.
- Механическое состояние: При ACS rollback все HP/AP/events/turn/results откатились; native MyISAM пост может сохраниться. Tape/prose сохранены; неизвестный PID не присваивается автоматически.
- Признаки сбоя: Blind INSERT retry, частичный ACS commit, утеря claim/tape, выдуманный PID или автооткат native side effects.
- Связь: A1.14–A1.16, A1.60, A1.89–A1.90; 4.5-R2, 6.3.2, 11.1.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### A-R2-04 — Потеря ACK и конкуренция

- Страница / роль: combat form → status/compose lookup → R · **O, второй контроллер при разрешённом shared scope**.
- Предусловия: Известный intent; доступен безопасный контроль ответа в staging.
- Действия: Скрыть ответ после prepare; восстановить lookup. Отдельно скрыть ответ после native/ACS COMMIT; повторить прежний request, перезагрузить; в другой вкладке отправить stale version.
- Видимый результат: Прежние submission/operation key; UNKNOWN отделён от отказа; stale CAS объяснён; отдельный lookup.
- Механическое состояние: Один confirmed effect/receipt/post; rolls/hash/quota неизменны; no new operation identity после lost ACK.
- Признаки сбоя: Новый roll/key/PID, автоматическое повторное списание, успех при неподтверждённом COMMIT.
- Связь: A1.14–A1.16, A1.42–A1.43, A1.59–A1.60; 4.5-R2, 11.1.3, 11.3.2.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### A-R2-05 — Moderation, edit, move и маски

- Страница / роль: native moderation / Quick Edit / full edit; T и R · **O + native moderator + G**.
- Предусловия: Test-only binding подтверждён; есть pending и отдельный committed post.
- Действия: Pending approve: затем author revalidation; отдельно hide/delete/move до и после commit. Проверить поддержанные move/merge/split branches, прочие оставить blocked. Изменить прозу Quick Edit/full edit и маску.
- Видимый результат: Pending не показывает applied mechanics; карточка committed сохраняет историю; редактируется проза, оформление не меняет механический итог.
- Механическое состояние: Approve не исполняет бой за автора; stale/перенесённый post — quarantine. Committed events/tape/receipt не пересчитываются, HP/AP не возвращаются.
- Признаки сбоя: Механика от approve/edit, GM impersonation, повтор native lifecycle, изменение истории после удаления/маски.
- Связь: A1.50–A1.52, A1.60, A1.62; 4.5-R2, 11.1.3–11.1.4.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### A-R2-06 — Решение по installed publication

- Страница / роль: A: publication + закрытый протокол приёмки · **ответственный администратор**.
- Предусловия: Evidence A-R2-01–05 и owner side effects собраны; нет открытых blocking conflicts.
- Действия: Сопоставить hashes/посты/receipts/owner effects до→после; записать каждый PASS/FAIL/BLOCKED и ответственное решение. Повторно проверить production gate.
- Видимый результат: Есть подписанный протокол фактических installed результатов и оставшихся ограничений.
- Механическое состояние: Production publication остаётся false; отдельное последующее разрешение не входит в этот PR. При любом блокере публикация запрещена.
- Признаки сбоя: Объявление READY по локальному синтаксису/старым fixtures или автоматическое включение флага.
- Связь: A1.60, A1.89–A1.95; 4.5-R2, 10.4.3, 11.3.3, 11.4.1.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


## B. Интерфейс desktop/mobile


### B-01 — Обычная тема и contextual assets

- Страница / роль: T без ACS; T с чужими acs_* query args · **X, затем O**.
- Предусловия: Обычный TID без сессии; Network/cache disabled для наблюдения.
- Действия: Открыть тему; затем добавить неавторизованные acs_builder/acs_session; сравнить PHP trace владельца и Network.
- Видимый результат: Нет пустой ACS-панели/каталога/GM; creator может иметь только разрешённую лёгкую ссылку.
- Механическое состояние: Нет Resolver/Builder/GM execution, полного KB/equipment/journal preload; нет ACS heavy assets.
- Признаки сбоя: Тяжёлые stacks по одним query args, лишние JS/CSS или скрытые IDs в ответе.
- Связь: A1.54, A1.91–A1.92; 11.1.1B, 11.3.2.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### B-02 — Discovery и сессия без encounter

- Страница / роль: T обычный URL, без acs_session · **O/G/V**.
- Предусловия: Одна доступная сессия без encounter; затем две разрешённые сессии TID.
- Действия: Открыть T без параметров; проверить минимальную панель. При двух сессиях выбрать одну и вернуться; проверить последующие страницы выбора.
- Видимый результат: «Сессия ACS активна / Столкновение ещё не создано»; GM видит управление; несколько сессий требуют понятного выбора без подмены.
- Механическое состояние: GET не создаёт encounter; TID/session binding и выбранный scope сохраняются.
- Признаки сбоя: Пустота при существующей сессии, автосоздание encounter, выбор другой сессии из-за наличия боя.
- Связь: A1.53–A1.54, A1.68; 11.1.1B, 11.1.5.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### B-03 — Одна панель desktop/mobile и sticky

- Страница / роль: T с активным боем · **O, G, V раздельно**.
- Предусловия: ATF установленный showthread обновлён штатно; длинные имена и несколько разрешённых групп.
- Действия: Прокрутить под навигацию; открыть compact; свернуть; поменять side/group filter; сменить viewport portrait/landscape и открыть снова.
- Видимый результат: Один DOM/body и сохранённые фильтры; sticky ниже текущей навигации; mobile без горизонтального переполнения.
- Механическое состояние: Нет повторных API commands, новой панели или потери draft. Countdown только отображает время, не делает deadline_process.
- Признаки сбоя: Перекрытие ATF postbit/meta-line, второй экземпляр, сброс состояния, GET изменение боя.
- Связь: A1.53–A1.54, A1.87; 11.1.1, 11.1.1B, 11.3.2.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### B-04 — Клавиатура, карточка и lazy детали

- Страница / роль: T, B, G, P · **O/G/V**.
- Предусловия: Есть committed post; профиль с разрешённой историей.
- Действия: Tab/Enter/Space открыть summary и вложенные детали, Escape закрыть mobile panel; пройти Builder Назад/Далее; открыть combat profile tab.
- Видимый результат: Фокус виден и возвращается; длинные значения переносятся; пустые секции отсутствуют; профиль грузится только при открытии.
- Механическое состояние: Раскрытие не пересчитывает бой и не инициализирует новый editor; отдельные вкладки не грузят весь архив.
- Признаки сбоя: Недоступные клавиатуре controls, потеря текста/фокуса, eager запросы всех вкладок.
- Связь: A1.52, A1.55, A1.65, A1.87, A1.92; 11.1.2–11.1.4, 11.2.1.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


## C. Создание и проведение обычного боя


### C-01 — Шесть шагов мастера

- Страница / роль: W → шаги 1–6 · **G с canCreate**.
- Предусловия: Подготовлен собственный CS-лист и разрешённый NPC; нет начатого боя.
- Действия: Создать сессию с reason; создать encounter и pre-start end policy; зарегистрировать источник; отдельно включить сущность в состав; выполнить readiness, preview; явно подтвердить запуск.
- Видимый результат: Каждый шаг показывает сохранённый scope; регистрация не выдаётся за участие; preview не выдаётся за старт; ошибки источника адресны.
- Механическое состояние: Session/encounter/entity commands имеют CSRF/CAS/key/audit; round/roster/AP создаются только запуском.
- Признаки сбоя: GET запускает бой, чужой CS регистрируется по роли GM, preview выдаёт AP/EntityTurn.
- Связь: A1.41, A1.47, A1.67–A1.70; 11.1.5, 11.3.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### C-02 — Стороны, контроллеры и readiness

- Страница / роль: G: Участники → Обзор · **G; затем P/D**.
- Предусловия: Состав зарегистрирован, round ещё не запущен.
- Действия: Назначить стороны и hostile/allied relation; NPC exclusive/shared/gm_pool; выбрать разрешённого контроллера. Из второй вкладки повторить stale assignment; выполнить readiness.
- Видимый результат: Понятные стороны/controller modes, текущие версии и отказы; не-управляемые исполнители не появляются у игрока.
- Механическое состояние: Side, ownership, base assignment и overlay различны; controller change не восстанавливает AP/quota. Stale CAS не применён.
- Признаки сбоя: Двойной бюджет, контроль чужого character по GM-флагу или silent overwrite назначения.
- Связь: A1.41–A1.43, A1.69, A1.78–A1.79; 7.3.3, 11.1.5.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### C-03 — Раунды, пауза и сроки

- Страница / роль: G: Раунды; T · **G + O**.
- Предусловия: Активный бой, pending roster; отдельный encounter с deadlines disabled.
- Действия: Проверить roster; поставить паузу/возобновить с подтверждением; задать/продлить срок; разрешённо пропустить ход; перейти в следующий раунд.
- Видимый результат: Свободный порядок ходов, общий AP, причины BLOCKED и замороженное время на паузе; пропуск/новый раунд явны.
- Механическое состояние: Ticks/upkeep/boundaries ровно один раз; skip не создаёт атаку; пауза не продвигает cooldown/AP. Закрытый ход лечением не открывается.
- Признаки сбоя: Auto publication sealed draft, boundary от refresh, двойной tick/AP refill либо обход незавершённых obligations.
- Связь: A1.1, A1.4–A1.5, A1.44–A1.49; 11.1.5, 11.3.2.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### C-04 — Каталог, цели и последовательность

- Страница / роль: B: Исполнитель → Действие → Цели → Последовательность · **O или D**.
- Предусловия: Разрешённые self/single/multiple KB actions, два исполнителя одной группы при наличии provider.
- Действия: Выбрать исполнителя; поиск/категорию/Только доступные; изучить cost/cooldown/reason. Выбрать multiple в порядке B→A; добавить, редактировать, удалить, переставить два действия; дождаться server validation.
- Видимый результат: Только серверные targets; multiple порядок B→A сохранён; queue показывает actor/action/all targets/AP/resources и причину отказа.
- Механическое состояние: Validation проверяет virtual state без durable effects/RNG/quota; add/reorder не seal. Unsupported area/all_* без контракта не имитируются.
- Признаки сбоя: Hidden disabled option, JS рассчитывает range/awareness, reorder обходит нехватку ресурсов, выбор карточки бросает roll.
- Связь: A1.13, A1.55–A1.58, A1.87; 11.1.2.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### C-05 — Preview → seal → отдельное confirm

- Страница / роль: B · **O/D**.
- Предусловия: Сохранён допустимый plan с prose, доступна seal quota.
- Действия: Открыть conditional preview; записать вероятности/costs. Нажать Seal; открыть results/rolls; отдельно подтвердить; изменить только прозу. Затем изменить dependency в другой вкладке и попытаться продолжить.
- Видимый результат: До seal нет rolls; после — retained rolls/итоги/расходы/warnings; confirmation явное; конфликт требует повторной проверки.
- Механическое состояние: Seal сохраняет tape/quota, не списывает окончательные HP/AP и не закрывает ход; prose-only save сохраняет механику; stale conflict не roll заново.
- Признаки сбоя: Новый roll при retry/конфликте, расход от preview, silent retarget или автоматическое confirm.
- Связь: A1.14–A1.16, A1.55, A1.58–A1.59; 11.1.2–11.1.3, 11.3.2.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### C-06 — Autosave, локальная копия и один AdvancedEditor

- Страница / роль: B + native newreply/Quick Reply · **O**.
- Предусловия: Свой draft; storage доступен; две вкладки и source/WYSIWYG редактор.
- Действия: Ввести прозу/actions, дождаться debounce; вводить во время медленного save; переключить literary/combat, скрыть Builder, resize. Во второй вкладке сохранить другую версию; проверить conflict. Reload: отдельно восстановить серверный draft и явно local prose; проверить истёкшую 24h копию/запрещённый storage.
- Видимый результат: Dirty → saving → saved либо error/conflict, новый ввод не теряется. Один редактор; локальный restore только явно и с подтверждением.
- Механическое состояние: CAS не перезаписан; pending save повторяет тот же envelope/key; local tape не источник истины. Отсутствие storage не отменяет server draft.
- Признаки сбоя: Второй SCEditor, auto-overwrite новой версии, автоматическая подстановка локального текста/rolls, потеря ввода после ACK.
- Связь: A1.50–A1.51, A1.55, A1.59, A1.87; 11.1.3, 11.3.2.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### C-07 — Publication UX и immutable card

- Страница / роль: native combat form → T · **O; G/V сравнить**.
- Предусловия: Для отправки — A-R2-01 разрешает test-only прогон; confirmed draft. Без gate проверить только отказ.
- Действия: С выключенным gate нажать переход к публикации; затем в разрешённом staging scope подготовить, явно подтвердить и отправить один combat. Открыть/свернуть карточку и вложенные детали; Quick Edit прозу.
- Видимый результат: Закрытый gate объяснён; стадии честны. Committed card перед прозой, свёрнута: actor/round/AP/подтверждённый итог; секции только непустые и разрешённые viewer.
- Механическое состояние: Один групповой post закрывает ход даже с остатком AP; карточка читает saved events/receipts без Resolver/live KB/CS; edit меняет только прозу.
- Признаки сбоя: Fake COMMITTED, повтор расхода при раскрытии/edit, дублирование литературного текста, чужие формулы/rolls в JSON.
- Связь: A1.49–A1.52, A1.60, A1.62; 11.1.3–11.1.4.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


## D. Статусы, реакции, предметы


### D-01 — Статусы и общие барьеры

- Страница / роль: B → T; G: Раунды · **O + G**.
- Предусловия: Валидные KB status/barrier abilities; разрешённый test-only commit.
- Действия: Применить статус/барьер, выполнить следующий допустимый ход/границу; осмотреть HP, ticks, stacks, cooldown, barrier details в карточке.
- Видимый результат: Стоимость HP, budget damage, actual HP loss, healing и барьер различимы; индивидуальные состояния видимы только по правам.
- Механическое состояние: Обязательные ticks/upkeep не удваиваются при refresh/replay; shared_pool расходуется в sealed target порядке.
- Признаки сбоя: Повторный tick/RNG, отрицательный/сброшенный бюджет или скрытые numeric details.
- Связь: A1.4–A1.5, A1.11–A1.12, A1.22–A1.26, A1.48; 11.1.2, 11.1.4–11.1.5.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### D-02 — Реакции и перехват

- Страница / роль: T: сохранённая реакция; F reaction_view/register/cancel — API, не готовая UI-точка · **O/P, разрешённый контроллер**.
- Предусловия: Положительный UI setup сейчас BLOCKED: в Builder/GM отсутствуют controls регистрации/отмены. Для read-only ветки нужна уже сохранённая разрешённая реакция; при её отсутствии тоже BLOCKED.
- Действия: Зафиксировать отсутствие UI controls как отдельный blocker; не заменять пользовательскую приёмку ручным mutation API/SQL. При наличии прежней реакции выполнить разрешённый trigger, раскрыть original/resolved target/costs и повторить read-only просмотр. Создание/отмену через UI отложить до отдельного исправления.
- Видимый результат: Сохранённые реакции/перехват и causal link видны только разрешённо. Отсутствие setup UI не объявляется successful registration или отсутствием backend.
- Механическое состояние: Reaction expense и redirect один раз в том же confirmed результате; не дополнительный post/AP/roll на refresh.
- Признаки сбоя: Реакция за не-контролируемое существо, повторный расход, client-selected resolved_target.
- Связь: A1.17–A1.21, A1.57, A1.62; 6.3.2, 9.3.4, 11.1.2, 11.1.4.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### D-03 — Предметы, экипировка и custody

- Страница / роль: B: Предметы/Экипировка; R: Inventory/custody · **O + G; D без spending grant**.
- Предусловия: Поддержанный pinned Inventory предмет и owner authorization; qty baseline.
- Действия: Проверить доступность; выбрать equip/consume action; без spending grant получить отказ. С отдельным разрешением выполнить допустимый intent; проверить reserve/consume receipt; пройти разрешённый custody return.
- Видимый результат: Состояния reserve/issue/recovery отличимы; нет универсального Inventory editor; return не обещает auto-restore loadout.
- Механическое состояние: Owner UID/qty меняет только owner API; global reservations соблюдены; actor grant не даёт transfer/grant; duplicate lookup без второго consume.
- Признаки сбоя: Прямой INSERT/remove_item, consume чужого предмета по GM-роли, освобождение hold от GET/деактивации.
- Связь: A1.2–A1.3, A1.32–A1.33, A1.43, A1.60; 6.3.2, 11.1.2, 11.1.5–11.1.6.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### D-04 — Отказ Inventory и независимые действия

- Страница / роль: B и R · **O + G**.
- Предусловия: На staging можно отдельно отключить Inventory; есть обычная непредметная способность.
- Действия: Отключить owner; обновить контекст, попробовать зависимый item action и независимую способность. Вернуть owner; выполнить lookup прежней pending операции.
- Видимый результат: Item reason безопасен; неподтверждённый reserve не назван доступным; независимая способность не глобально заблокирована без зависимости.
- Механическое состояние: Бой не откатывается из-за optional delivery; retained item key/qty/hold сохраняются; retry не создаёт расход.
- Признаки сбоя: Все действия блокируются без причины либо item проходит без owner proof; silent auto-release/reissue.
- Связь: A1.32–A1.33, A1.60, A1.89; 6.3.2, 11.1.6.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


## E. Питомцы, призывы, NPC


### E-01 — Постоянный питомец: provider и группа

- Страница / роль: G: Участники; B · **O/G**.
- Предусловия: Для положительного пути нужен реальный подтверждённый PermanentPetSource; без него функция BLOCKED.
- Действия: Без provider проверить безопасный отказ регистрации. При наличии provider зарегистрировать собственного pet штатно, включить в бой, добавить character+pet actions в один plan.
- Видимый результат: Понятный blocked source; при успехе pet внутри группы и отдельные HP/status/cooldown.
- Механическое состояние: Одна identity и общий AP/quota/turn/post; pet не отдельный аккаунт/AAM recipient. GM не присваивает чужого питомца.
- Признаки сбоя: Произвольный pet ID принят без owner proof, новый AP или фиктивный provider.
- Связь: A1.1, A1.9, A1.43, A1.47–A1.49; 7.3.3, 11.1.2, 11.1.5.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### E-02 — Spawn, lifetime и самостоятельный NPC

- Страница / роль: B → G: Раунды → T · **O + G**.
- Предусловия: Валидный source-group summon и отдельный independent NPC spawn KB subset.
- Действия: Выполнить spawn; обновить страницу/lookup. Проверить оставшийся AP source-group, следующий roster independent NPC, duration/upkeep/despawn.
- Видимый результат: Summon grouped с владельцем бюджета; NPC имеет свой разрешённый ход; метаданные lifetime актуальны.
- Механическое состояние: UNIQUE source event/index не даёт второго spawn; independent обычный ход со следующего раунда; expiry не открывает closed turn.
- Признаки сбоя: Дубликат entity, дополнительный бюджет текущего раунда, отдельные account alerts питомцу.
- Связь: A1.30, A1.47–A1.49, A1.69, A1.84; 7.3.3, 9.3.4, 11.1.2, 11.1.4.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### E-03 — Делегирование, shared NPC и отзыв

- Страница / роль: G: Участники/Делегирование → B · **O + D + два G**.
- Предусловия: Явные grant categories/expiry; NPC shared/exclusive по контракту.
- Действия: Дать D обычные действия без item rights; подготовить seal. Отозвать grant/дождаться expiry; попытаться confirm/publish. Два GM подготовить один NPC turn параллельно.
- Видимый результат: D видит только разрешённых actors/actions; отзыв сообщён без потери прозы; конфликт общего NPC понятен.
- Механическое состояние: Новая публикация повторно проверяет grants; один turn/AP/quota, нет reroll/GM impersonation; ownership не меняется.
- Признаки сбоя: Прежний grant работает после revoke, shared удваивает post/AP, делегат повторно делегирует/меняет CS.
- Связь: A1.41–A1.43, A1.49, A1.78; 7.3.3, 11.1.2–11.1.3, 11.1.5, 11.3.2.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


## F. Окружение, зоны, укрытия


### F-01 — Зоны, движение и cover

- Страница / роль: G: Управление spatial/cover → B: Тактика · **G + O**.
- Предусловия: Подтверждённая optional map с direct connections, pinned move/cover actions.
- Действия: Настроить карту до разрешённой границы; назначить позиции/укрытие; собрать move→attack; проверить preview и сохранённый итог; изменить cover после seal.
- Видимый результат: Только разрешённые зоны/цели; costs/range/LoE причины с сервера; stale cover требует reconfirm.
- Механическое состояние: Sequential virtual state использует новую позицию; forced move не расходует AP цели без контракта; сохранённый tape не меняется.
- Признаки сбоя: JS считает дистанцию или обходит LoE, новые rolls при cover change, карта ломает readiness следующего раунда.
- Связь: A1.57, A1.70, A1.82–A1.83; 8.3.3, 9.3.4, 11.1.2, 11.1.5.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### F-02 — Объект, инструмент и совместный progress

- Страница / роль: B: Тактика/объект → T/архив · **O/P + G**.
- Предусловия: Известный supported object action, pinned tool requires/consumes; cooperative objective.
- Действия: Выполнить search/interaction разрешённым actor; затем move→interaction→attack; другой участник добавляет отдельный вклад. Повторить тот же confirmed lookup.
- Видимый результат: Разрешённые object/progress/contribution сведения; unknown object не раскрывается агрегатом.
- Механическое состояние: Инструмент/reservation привязан к индексу действия; поздний отказ не оставляет частичный progress/HP; contribution считается один раз.
- Признаки сбоя: Второе одинаковое действие использует чужой pin, дублирование вклада, неизвестная цель выбрана автоматически после search.
- Связь: A1.71–A1.74, A1.81; 8.3.3, 11.1.2, 11.1.4.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### F-03 — Hidden окружение и неподдержанная геометрия

- Страница / роль: T/B/H · **O/V/X отдельно**.
- Предусловия: Есть unknown trap/entity, known hint и unsupported KB spatial/area вариант.
- Действия: Сравнить DOM/JSON/selectors/errors для ролей; попробовать unsupported action только разрешённым UI.
- Видимый результат: Нет hidden IDs, counts, пустых карточек, disabled hidden targets; понятный blocked для неподдержанного контракта.
- Механическое состояние: Awareness не выдаётся простым открытием панели; нет guessed area/grid/pathfinding.
- Признаки сбоя: Утечка через подсказку/счётчик/экспорт, client geometry выдаёт действие как executable.
- Связь: A1.57, A1.72, A1.81–A1.83, A1.89; 8.3.3, 11.1.2, 11.2.1.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


## G. Подготовленные действия, засады, превращения


### G-01 — Оплаченная подготовка и causal trigger

- Страница / роль: B: Особые/prepared → T → R · **O + другой actor trigger**.
- Предусловия: Валидный fixed-target prepared contract и при необходимости разрешённые item reservations.
- Действия: Подготовить, отдельно опубликовать разрешённую подготовку; выполнить trigger другим actor. Проверить costs, causal child и разрешённый release неиспользованного резерва. Отмена active prepared через UI — BLOCKED: prepared_cancel есть в API, control не найден.
- Видимый результат: Prepared status/стоимость и причинная связь видны; скрытые targets/source остаются закрыты.
- Механическое состояние: Подготовка оплачена один раз, trigger без второго post/AP preparation; owner расход по сохранённому intent; retry не второй roll.
- Признаки сбоя: Двойной AP/item consume, произвольный prepared callback или автоматическая подмена fixed target.
- Связь: A1.60, A1.75, A1.84; 9.3.4, 11.1.2, 11.1.4.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### G-02 — Засада и запуск с прежним intent

- Страница / роль: G: Раунды → Ambush · **G + O**.
- Предусловия: Preparation encounter, утверждённые pre-round contracts; записан budget/commitment.
- Действия: Подготовить разрешённые pre-round actions, подтвердить ready/start; скрыть ACK; открыть status и продолжить прежний intent. Сравнить первый обычный roster.
- Видимый результат: Pending start/recovery чётко видны; нет альтернативной кнопки нового start вместо прежнего.
- Механическое состояние: Paid commitment вычтен из первого общего AP один раз; pre-round не создаёт лишний turn; скрытая засада не раскрыта.
- Признаки сбоя: Double start, второй key/RNG после lost ACK, двойной вычет commitment или новый бюджет питомцу.
- Связь: A1.1, A1.47, A1.75–A1.77; 9.3.4, 11.1.5.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### G-03 — Превращение, temporary grants, reflect/absorb

- Страница / роль: B: разрешённые advanced actions → T · **O/D + G**.
- Предусловия: Валидные transform/copy/transfer/reflect/absorb KB subset; unsupported сочетания отдельно.
- Действия: Применить форму, затем разрешённое использование temporary grant/transfer; пройти expiry/обратное превращение. Открыть advanced metadata и карточку.
- Видимый результат: Только текущая доступная форма/grant/uses/duration; нет исходных hidden definitions; неподдержанное сочетание blocked.
- Механическое состояние: Та же entity, absolute HP clamp, cooldown/resources не сбрасываются; linked transfer/ticks не повторяются; primary form уникальна.
- Признаки сбоя: Heal/AP refill от смены формы, expired grant executable, новый Resolver/roll при чтении истории.
- Связь: A1.25–A1.26, A1.78–A1.80, A1.85–A1.86; 9.3.4, 11.1.2, 11.1.4.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


## H. Завершение, loot и награды


### H-01 — Pending resolution → подтверждение

- Страница / роль: L: Результат; G: Управление · **G**.
- Предусловия: Закреплены end conditions; возник server candidate.
- Действия: Открыть pending_resolution; изучить допустимые outcomes; явно подтвердить либо разрешённо продолжить; повторить прежнюю команду/stale версию.
- Видимый результат: Предполагаемый итог отделён от confirmed outcome; winner берётся из candidate, не произвольного ввода.
- Механическое состояние: Нет повторного Resolver; один outcome/CAS/audit. Completion не означает session close или issued rewards.
- Признаки сбоя: GM может произвольно назначить winner, pending выдан за confirmed, повтор применяет бой.
- Связь: A1.27–A1.31, A1.68; 10.4.3, 11.1.5–11.1.6.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### H-02 — Отступление, захват и возврат

- Страница / роль: B: participation actions; G: Управление · **O + G**.
- Предусловия: Подтверждённые participation policy/escape/capture contracts.
- Действия: Исполнить разрешённое surrender/escape; проверить остальных питомцев/группу; выполнить допустимый release/rejoin.
- Видимый результат: Life state, участие и закрытие хода различимы; неподдержанное изменение blocked.
- Механическое состояние: Rejoin не refill AP/quota и не открывает resolved turn; custody/обязательные эффекты сохраняются.
- Признаки сбоя: Побег создаёт новый бюджет или снимает owner obligations, captured сменяет owner без Inventory contract.
- Связь: A1.27–A1.33, A1.48; 10.4.3, 11.1.2, 11.1.5–11.1.6.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### H-03 — Generation и три режима allocation

- Страница / роль: L: Добыча · **G, затем eligible O/P**.
- Предусловия: Confirmed outcome; pinned loot sources; отдельные entries gm_assign/free_claim/need_greed и allow_pass.
- Действия: G явно генерирует pool один раз; назначить gm entry; P заявляет free claim; O/P выбирают need/greed/pass; G закрывает allocation с подтверждением.
- Видимый результат: Generated → распределяется → winner/awaiting_issue; видны только разрешённые entries/свои заявки; закрытие/победитель закреплены.
- Механическое состояние: Повтор generation не новый pool; need/greed не reroll при refresh/recovery; all-pass/без winner не выдаёт предмет.
- Признаки сбоя: Скрытые contenders/rolls/counts, новый победитель при повторе, generation на GET.
- Связь: A1.34–A1.36, A1.38; 10.4.3, 11.1.6.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### H-04 — Owner issue, outage и lost ACK

- Страница / роль: L: Добыча → Восстановление · **исходный разрешённый issuer/G**.
- Предусловия: Awaiting issue consumable/ammo; Inventory installed grant contract; отдельный prepared intent.
- Действия: Подтвердить issue; отдельно вызвать outage/потерю ACK. Lookup прежней операции; prepared до dispatch продолжить только сохранённой исходной командой; повторить после close/archive где контракт разрешает.
- Видимый результат: Awaiting_issue/pending_issue/issued/issue_rejected различимы; winner сохранён; новая выдача после unknown не предлагается.
- Механическое состояние: Один owner grant/receipt, прежний operation_key/hash; lookup не first dispatch; issuer guards сохранены.
- Признаки сбоя: Повторный предмет, удалённый winner/reroll, обход unsupported kind прямым INSERT.
- Связь: A1.34–A1.36, A1.60, A1.89; 6.3.2, 10.4.3, 11.1.6.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### H-05 — EXP, валюта и отдельные recipient states

- Страница / роль: L: Награды / Восстановление · **G; затем O/P/V**.
- Предусловия: Pinned each/split_equal/manual sources, eligible recipients, Balance owner schema.
- Действия: Распределить каждый разрешённый режим; для manual проверить неверную сумму/recipient. Подтвердить issue; вызвать owner version conflict/потерю ACK одного получателя; lookup прежнего key.
- Видимый результат: Minor units/scale корректны; pending/prepared/recovery_required/issued на каждого получателя. Не-GM видит ограничение без сумм/чужих IDs.
- Механическое состояние: Один exact credit/receipt на recipient; partial успехи сохранены; CS permanent sync и compensation blocked, не положительный fake credit.
- Признаки сбоя: Pending назван начислением, float округление, credit повторён, owner version/recipient подменены recovery.
- Связь: A1.37–A1.40, A1.60; 10.4.3, 11.1.6, 11.3.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### H-06 — Achievement earning и связанные награды

- Страница / роль: L: Достижения; P: достижения · **G + O**.
- Предусловия: Разрешённый source в durable achievement queue, definition pin; часть hidden_until_unlocked.
- Действия: Открыть страницу без команд; G явно обработать один source; повторить его; проверить progress/unlock; отдельно проверить состояние связанной награды.
- Видимый результат: Разрешённый progress и unlock; GM review_required; unlock не означает issued reward.
- Механическое состояние: Earning один раз на source; cross-session/foreign event отказ; history/award receipts не удаляются при correction.
- Признаки сбоя: Прогресс от GET, два earning, скрытое achievement раскрыто заранее, auto-clawback.
- Связь: A1.40, A1.66; 10.4.3, 11.1.6, 11.2.1.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


## I. Архив, профиль и статистика


### I-01 — Закрытие, reopen и archive

- Страница / роль: G: Управление; L: Recovery; A: реестр · **G**.
- Предусловия: Completed encounter и отдельно pending/held obligations.
- Действия: Запросить close, изучить blocker/debt summary; допустимо закрыть, reopen, archive отдельными подтверждёнными командами; открыть retained recovery.
- Видимый результат: Lifecycle status и категории долгов видны; пустой частичный список не означает «всё исполнено».
- Механическое состояние: Reopen не открывает completed encounter/turn и не refill ресурсы; pending issue/statistics/custody/receipts сохранены; recovery только контрактный.
- Признаки сбоя: Автовыдача/компенсация/сброс истории от close/reopen; универсальная «починить всё».
- Связь: A1.39–A1.40, A1.60, A1.68; 10.4.3, 11.1.5–11.1.6, 11.3.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### I-02 — Журнал, corrections и chunk exports

- Страница / роль: H · **G, O/P/V/X раздельно**.
- Предусловия: Есть несколько страниц confirmed events, correction и reveal policy; extended allowlist отдельно.
- Действия: Фильтровать round/participant/type/category, искать разрешённый ability key; следующая страница/сброс фильтра; открыть source post и correction. Скачать JSON/CSV chunk; продолжить по X-ACS-Next-Cursor существующим API.
- Видимый результат: Пагинированная разрешённая история, source post link только при доступности; approximate помечен; current overlays отделены от immutable history.
- Механическое состояние: GET без Resolver; correction не переписывает source event. Ordinary export даже GM не становится extended; нет hidden aggregated sums.
- Признаки сбоя: Весь журнал preload, hidden IDs в filters/CSV, пустая sparse страница названа концом, UI cursor неверно переиспользован export.
- Связь: A1.62–A1.63, A1.89, A1.92; 10.4.3, 11.2.1.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### I-03 — Statistics sync и профиль после обновления шаблона

- Страница / роль: L: Recovery → P combat tab · **G + O + V/X**.
- Предусловия: Штатно обновлены owner-managed ATF/APUI templates/cache; completed reveal_all source и active/hidden контрольные источники.
- Действия: G выполнить explicit first statistics_sync с visibility; при ошибке — retained statistics_recover. Открыть профиль, combat tab, категории/историю/achievements, пагинацию и архивы.
- Видимый результат: Вкладка существует в установленном шаблоне; lazy/filter responses остаются внутри неё; exact totals только из разрешённых completed reveal_all источников. Нет данных — понятный текст, не придуманный ноль.
- Механическое состояние: Не второй store; один aggregate version/receipt; active/hidden source исключён целиком; GM не получает профильный secret sum автоматически.
- Признаки сбоя: Вкладка есть только в seed, eager история, двойной pet+group total, новые visibility/key при recover, pending consumer назван synced.
- Связь: A1.64–A1.66, A1.87, A1.92; 10.4.3, 11.2.1.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


## J. Права, секретность, ошибки и восстановление


### J-01 — Ролевая матрица и DTO до HTML

- Страница / роль: T/B/G/L/H/P/F · **G, O, P, D, V, X по очереди**.
- Предусловия: Одинаковые SID/EID, hidden entity/ability/resource и отдельный чужой session/TID.
- Действия: Для каждой роли открыть все поверхности и Network DTO; проверить targets/disabled options/counts/errors/exports/alerts. X отдельно без native доступа. D сравнить с owner без расширения numeric rights.
- Видимый результат: Только разрешённые имена/суммы/действия; V read-only, X без session data; чужой scope недоступен даже по известному ID.
- Механическое состояние: Server policy фильтрует до HTML/JSON/CSV/AAM; роли актуальны на каждом запросе/commit; masks не меняют actor.
- Признаки сбоя: Hidden IDs/HP/AP/formulas/количество через CSS/JSON/errors; GM роль действует за чужого персонажа; delegated control даёт лишний numeric reveal.
- Связь: A1.41–A1.43, A1.53, A1.57, A1.61–A1.68, A1.72, A1.81; 11.1.1–11.3.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### J-02 — GET mutation, CSRF и session scope

- Страница / роль: G/F/R/A · **G/O, затем X**.
- Предусловия: Только staging; baseline версии/ресурсов записан; сохранён легальный исходный request.
- Действия: Вручную повторить одну mutation как GET, затем POST без/с неверным CSRF; в копии формы подменить SID/TID/EID/actor чужим. Проверить native password/закрытую тему.
- Видимый результат: Безопасный отказ, никакого raw SQL; обычный permitted read по GET работает.
- Механическое состояние: Нет игровых изменений/owner side effects, commands требуют authenticated native/session scope и CSRF; диагностика может отдельно сохранить error_id.
- Признаки сбоя: Изменение состояния от URL, bypass native permissions, client role/uid/pid принят как authorization.
- Связь: A1.41, A1.60, A1.67–A1.68, A1.89–A1.90; 11.1.5–11.1.6, 11.3.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### J-03 — Version fallback без polling

- Страница / роль: T/B/native publication · **O + G во второй вкладке**.
- Предусловия: Разрешённая сессия, own draft; Network открыт.
- Действия: Оставить вкладку без действий; затем G изменить бой/управление/цель; вернуться focus/visibility, открыть panel/Builder, preview/seal/pre-publish. Серия быстрых focus/open отдельно.
- Видимый результат: Нет постоянных запросов каждые несколько секунд; изменения помечены, confirmation снимается; цель не подменена; свежий read требуется перед новой командой.
- Механическое состояние: Tokens viewer-bound, write CAS остаётся серверным; own draft conflict не auto-restore/overwrite; один inflight на watcher, текст/actions сохраняются.
- Признаки сбоя: Бесконечный retry/poll, silent retarget или новый roll; ответ прежнего scope применён к новому. Разные panel/Builder watchers записать как отдельный performance finding, не принять за одну гарантию всей страницы.
- Связь: A1.59, A1.88–A1.89, A1.91–A1.92; 11.3.2.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### J-04 — Безопасные ошибки и error_id lookup

- Страница / роль: B/F → A: Diagnostics; G → R · **O + ACP/G**.
- Предусловия: Валидные baseline; можно вызвать validation/state/permission/dependency и контролируемый internal/recovery fault на staging.
- Действия: Вызвать каждый класс отдельно: invalid input, stale CAS, revoked control, owner unavailable, internal failure, unknown operation. Скопировать только error_id в ACP поиск; проверить scope GM recovery.
- Видимый результат: VALIDATION_ERROR/STATE_CONFLICT/PERMISSION_DENIED/DEPENDENCY_ERROR/INTERNAL_ERROR/RECOVERY_REQUIRED с понятным текстом; технические детали только authorized inspect.
- Механическое состояние: Rollback/receipt не меняются error formatting; максимум одна server diagnostic на error request best effort. При сети без ответа нет выдуманного server error_id.
- Признаки сбоя: SQL/stack/секрет/hidden target в ответе, error_id выдан за confirmed receipt, автоисправление данных.
- Связь: A1.59–A1.60, A1.67–A1.68, A1.89; 11.1.1B, 11.3.2–11.3.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### J-05 — GM Recovery и границы inspect

- Страница / роль: G: Управление → recovery; R; A: inspect · **G, автор O, ACP без GM**.
- Предусловия: Есть известный незавершённый submission, retained Inventory/Balance intent и held custody.
- Действия: Открыть inspect, lookup старого результата; отдельно выполнить разрешённое решение с reason/confirmation/current version. Повторить stale CAS и не-GM. Проверить unknown PID.
- Видимый результат: Inspect и изменяющие commands разделены; pending obligations по категориям; unknown identity удерживается; ACP-only не получает GM command.
- Механическое состояние: Recovery использует прежний key/envelope; terminal receipt без повторной механики; GM не публикует за автора; нет универсального admin.recover.
- Признаки сбоя: Новый ключ/получатель/amount/PID, повтор owner effect, операция без reason/version/audit там, где контракт требует критическое решение.
- Связь: A1.32–A1.33, A1.60, A1.67–A1.68, A1.89; 4.5-R2, 10.4.3, 11.1.5–11.1.6, 11.3.3.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### J-06 — AAM: получатели, aggregation и durable retry

- Страница / роль: G/R: alerts_status/retry; обычная панель AAM · **O + D + G + V/X**.
- Предусловия: Controlled AAM receipt schema/types установлены; ACS notification task зарегистрирован/работает; изолированные recipients.
- Действия: Создать turn/deadline/missed/round/combat/outcome/reward/publication/GM events в разрешённых сценариях. Отозвать delegation до delivery; отключить AAM, затем восстановить; скрыть ACK после owner commit и повторить прежний outbox delivery.
- Видимый результат: Turn идёт действующему контроллеру; критические сведения владельцу по policy; summary по post/account, не pet/trace row. Outage pending/recovery, не failed combat.
- Механическое состояние: Stable key/hash/event/recipient и durable receipt/lookup: один alert либо подтверждённый skipped preference; task не запускает Clock. Delete/read alert не разрешает redelivery.
- Признаки сбоя: Дубликат при lost ACK/replay/cleanup, hidden source/target в extra/тексте, forced preference bypass, откат боя из-за AAM.
- Связь: A1.43–A1.44, A1.61, A1.88–A1.89; 11.3.1, 11.3.2.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


### J-07 — Performance и совместимость без полного benchmark

- Страница / роль: T ordinary / panel / B / G / H / P; F versions · **G + O + администратор staging**.
- Предусловия: Один согласованный dataset; штатное read-only профилирование владельца; никаких production fault checks.
- Действия: Снять SQL count/TTFB/memory/payload для перечисленных поверхностей; versions&profile=1 только GM. Сравнить два размера разрешённого состава, повторное раскрытие/resize и Network initiators; проверить один AdvancedEditor и соседние ATF providers.
- Видимый результат: Contextual assets без дублей, нет eager всех вкладок/KB/equipment/history; изолированные CSS; существующие nav/postbit/meta-line работают.
- Механическое состояние: VersionQuery максимум 6 собственных SELECT, native helpers считаются отдельно; panel resources batch. Фиксировать реальные дополнительные audience/interactive reads и duplicate preflights, не объявлять всю систему constant-query.
- Признаки сбоя: Линейный SQL без объяснения, дубль mutation, повтор editor init, asset denied/404; превышение не маскируется synthetic PASS.
- Связь: A1.54–A1.55, A1.87–A1.88, A1.91–A1.92; 11.1.1B, 11.2.1, 11.3.2.
- Итог: [ ] PASS · [ ] FAIL · [ ] BLOCKED. Проверяющий / дата: ___
- Фактическое состояние / замечания / defect ID: ___
- Evidence / скриншоты / до→после: ___


## Сводка и передача дефектов

| Раздел | PASS | FAIL | BLOCKED | Не выполнено | Evidence / ответственный |
| --- | --- | --- | --- | --- | --- |
| A / A-R2 (отдельный обязательный gate) | ___ | ___ | ___ | ___ | ___ |
| B / C | ___ | ___ | ___ | ___ | ___ |
| D / E / F / G | ___ | ___ | ___ | ___ | ___ |
| H / I / J | ___ | ___ | ___ | ___ | ___ |

Для каждого FAIL/BLOCKED: scenario ID, deployed SHA, роль и страница, шаг воспроизведения, ожидаемое/фактическое, error_id/прежний operation key (в закрытом evidence), версии до→после, ссылка на безопасный скриншот, блокирует ли публикацию, отдельная задача исправления. Не удалять pending intents/receipts ради повторного прогона; новый независимый тестовый бой создавать только после установления результата прежнего.

Решение владельца: ___; ограничения owner contracts/KB subset: ___; R2 publication acceptance: ___; production capability **остаётся false**: ___. Следующий шаг — ручная проверка и отдельные исправления/стилизация по утверждённому макету. Этап 12 этим документом не начинается.
