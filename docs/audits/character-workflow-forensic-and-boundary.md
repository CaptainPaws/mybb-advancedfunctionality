# Character Application Moderation Workflow — Forensic Analysis & Boundary Plan

## Scope

Проект: `mybb-advancedfunctionality`.

Цель этапа: выделить orchestration layer для модерационного workflow анкеты, не ломая текущий процесс и начав вынос действий из CharacterSheets/ATF/KB.

## 1) Forensic: текущие точки входа и кнопки

### 1.1 Где рендерятся кнопки

`CharacterSheets` добавляет кнопки на `showthread` в `af_charactersheets_showthread_start_impl()`:

- «Принять анкету» (`action=af_charactersheets_accept`)
- «Создать лист персонажа» (`action=af_charactersheets_create_sheet`)
- Кнопка KB подтягивается вызовом `af_atf_render_character_kb_moderation_button()` из ATF.

Файл: `inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php`.

### 1.2 Какие handlers обслуживают кнопки

#### CharacterSheets (`misc.php?action=...`)

`af_charactersheets_misc_start_impl()`:

- `af_charactersheets_accept` → `af_charactersheets_handle_accept_action()`
- `af_charactersheets_transfer` → `af_charactersheets_handle_transfer_action()` (добавлено на этом этапе)
- `af_charactersheets_create_sheet` → `af_charactersheets_handle_create_sheet_action()`

#### ATF bridge (`misc.php?action=...`)

`af_atf_misc_start()` / `af_atf_early_ajax_router()`:

- `af_atf_character_kb_create` → `af_atf_handle_character_kb_bridge_action(false)`
- `af_atf_character_kb_sync` → `af_atf_handle_character_kb_bridge_action(true)`

### 1.3 Что сейчас делает CharacterSheets

До этого этапа CharacterSheets совмещал в accept-handler:

- публикацию acceptance/greeting поста,
- установку accepted-флагов,
- перенос темы,
- закрытие темы,
- запуск начисления EXP.

На текущем этапе перенос и закрытие вынесены в отдельный transfer handler.

### 1.4 Что сейчас делает ATF

ATF выполняет bridge между thread/ATF-полями и KB:

- создание/синхронизация character KB entry (`af_atf_bridge_sync_character_kb_from_thread()`),
- рендер moderation-кнопки для KB (`af_atf_render_character_kb_moderation_button()`).

### 1.5 Что сейчас делает KB

KB предоставляет CRUD и view-роутинг (`kb`, `kb_edit`, `kb_get`, ...), а также статус персонажа в KB (`kb_character_status_save`).

В рамках модерации анкеты KB не является центральным orchestrator: он выступает как data-catalog/data-store.

### 1.6 Как сейчас выдаются группы

Выдача групп реализована в CharacterWorkflow через `af_cwf_assign_transfer_groups()`, которая вызывается из:

- `af_cwf_accept_character_application()`;
- `af_cwf_transfer_character_application()`.

Policy: значения из `af_characterworkflow_transfer_group_ids` добавляются в `users.additionalgroups` автора анкеты без изменения `users.usergroup`.

Логика идемпотентна: существующие additional groups сохраняются, повторный accept/transfer не создаёт дублей gid.

### 1.7 Как сейчас постится приветственное сообщение

В `af_charactersheets_handle_accept_action()`:

- строится сообщение `af_charactersheets_build_accept_message()`;
- создаётся reply через `PostDataHandler('insert')`;
- `accepted_pid` сохраняется в `af_charactersheets_accept`.

### 1.8 Почему после возврата на редактирование «Принять» исчезает

Причина в UI-условии: кнопка accept ранее показывалась только при `!$was_accepted`. Если `accepted=1` в `af_charactersheets_accept`, но тема снова в pending (вернули на доработку), кнопка не рендерилась.

На этом этапе исправлено: accept-кнопка теперь показывается для pending-форумов независимо от исторического accepted-флага.

---

## 2) Новый plugin boundary (orchestration layer)

### Предложенное имя

`CharacterWorkflow` (`id=characterworkflow`).

### Responsibility boundary

`CharacterWorkflow` отвечает за:

- состояние workflow анкеты (state machine + техполя);
- фиксацию этапов «принято», «перенесено», «нужна доработка»;
- связывание tid с kb/sheet/greeting метаданными.

`CharacterWorkflow` НЕ должен дублировать бизнес-логику:

- ATF-парсинга полей,
- KB CRUD,
- рендеринга листа.

Он оркестрирует их вызовы и хранит факт выполнения шагов.

### Новая таблица состояния

`af_character_workflow`:

- `tid`
- `state`
- `kb_entry_id`
- `sheet_id`
- `sheet_slug`
- `greeting_post_id`
- `reviewed_by`
- `accepted_by_uid`
- `transferred_by_uid`
- `accepted_at`
- `transferred_at`
- `revision_requested_at`
- `updated_at`

---

## 3) Разделение «Принять» и «Перенести»

Реализовано начальное разделение:

- **Accept**: публикует greeting (если нужно), ставит accepted в `af_charactersheets_accept`, фиксирует workflow-state через `af_cwf_accept_character_application()`.
- **Transfer**: отдельный action `af_charactersheets_transfer`, выполняет move/close и фиксирует `af_cwf_transfer_character_application()`.

Так remove-нута связка «accept всегда move+close» из одного handler.

---

## 4) Отдельные действия (roadmap)

На текущем этапе заложены API-функции оркестратора:

- `af_cwf_bind_kb_entry()`
- `af_cwf_bind_sheet()`
- `af_cwf_accept_character_application()`
- `af_cwf_transfer_character_application()`
- `af_cwf_mark_needs_revision()`

Интеграция уже начата:

- ATF при create/sync KB вызывает `af_cwf_bind_kb_entry()`.
- CharacterSheets при create sheet вызывает `af_cwf_bind_sheet()`.

---

## 5) State model (простая версия)

Базовая модель:

- `draft`
- `submitted`
- `under_review`
- `needs_revision`
- `approved`
- `transferred`
- `accepted`

Текущая реализация использует `approved` после accept и `transferred/accepted` после transfer.

---

## 6) Точки переноса на следующем шаге

1. Полностью убрать из CharacterSheets прямой вызов moderation move/close и оставить только делегирование в orchestrator service.
2. Добавить явный endpoint «Вернуть на доработку» (из UI и state: `needs_revision`).
3. Расширить group assignment policy (например mapping по forum/profile mechanics), сохраняя базовое правило добавления только в additional groups.
4. Добавить журнал событий workflow (event log) для аудита.
5. Перенести рендер всех workflow-кнопок в отдельный provider, чтобы CharacterSheets не управлял lifecycle модерации.
