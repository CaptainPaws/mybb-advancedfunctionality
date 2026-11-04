# Мастерская: профиль, лист персонажа, персональная тема

Этап 7, AdvancedAppearance 1.5.0 / AdvancedShop 1.2.0. Общая мастерская поддерживает `postbit`, `profile`, `sheet`, `theme` через один Preset API, валидатор, компилятор, draft manager и Gallery picker. Новых таблиц и миграции существующих определений не требуется.

## Обновление

1. Обновить файлы; выполнить upgrade/activate Appearance 1.5.0, затем Shop 1.2.0 для обновления шаблонов и каталога слотов.
2. В ACP Appearance разрешить нужные `personal_types`: `postbit,profile,sheet,theme`. Старое значение администратора **не расширяется автоматически**. Новая установка получает все четыре типа.
3. Указать независимые бесплатные/максимальные лимиты. Существующие значения сохраняются; если для нового target бесплатная ёмкость равна нулю, право приобретается в Shop либо администратор устанавливает бесплатную ёмкость.
4. Shop создаёт недостающие выключенные slot products с ref 2/3/4 в «Слотах оформления». Администратор задаёт цены и включает продажу. Цена и состояние существующего postbit-товара не меняются.

Профилю требуются активные ATF и AdvancedProfileUI, листу — CharacterSheets, теме интерфейса — ATF. Сервер отклоняет запись и продажу слотов при отключённом необходимом провайдере или запрещённом target.

## Target contract

| Target | Назначение | Владелец оформления | Gallery purpose |
| --- | --- | --- | --- |
| postbit | user / `apui_postbit_pack` | Автор | `appearance_postbit_bg` |
| profile | user / `apui_profile_pack` | Владелец просматриваемого профиля | `appearance_profile_bg` |
| sheet | user / `apui_sheet_pack` | Владелец листа | `appearance_sheet_bg` |
| theme | user / `appearance_ui_theme` | Текущий посетитель страницы | `appearance_theme_bg` |

`theme` — персональное оформление UI посетителя. Оно **не использует** старое author/thread назначение `apui_thread_pack` и не подменяет ACP themes или AdvancedMenu preferences. Старые thread presets/assignments остаются самостоятельными. Все четыре бесплатные/купленные ёмкости независимы; виртуальное «По умолчанию» не расходует слот.

## Общая схема и компилятор

`surfaces.php` определяет registry областей, подписей, фиксированных selectors, Gallery purposes и allow-list полей для каждого компонента. `studio.php` валидирует их и компилирует через один существующий pipeline: bounded CSS variables + фиксированные свойства. Пользователь не передаёт selectors, HTML, CSS, element key, grid/sticky/position/z-index. Font families, размеры, прозрачности и media IDs проходят прежние серверные проверки. Неизвестная область или поле другого target отклоняются.

Для новых target разрешены `components`, `display_title`, `description` и Canvas (только profile/sheet). Поля аватара и старые postbit-specific ключи не принимаются. У области «Фон страницы» отдельный узкий allow-list только фона: она не задаёт типографику/отступы родителя, которые могли бы затронуть вложенную поверхность.

Профиль: фон компонента страницы, hero, баннер, карточки, заголовки, навигация, инфобокс. CSS ограничен `.atf-profile.af-aa-profile-user-UID`; карточки исключают sheet panel, дочерние selectors исключают `.af-cs-page`. APUI сохраняет вкладки, providers и approval gate; новые стили не создают скрытых разделов и не раскрывают character data.

Лист: фон, summary, декоративные панели, текст, заголовки и эффекты. Scope — `.af-cs-page[data-af-apui-owner-uid="UID"]`, общий для существующих DnD/ARPG шаблонов. CharacterSheets после своего permission-checked renderer вызывает опциональный `af_aa_surface_fragment(html,uid,'sheet')`: CSS и inert effect config идут вместе с полным листом, iframe, modal/AJAX/lazy profile tab. Частичные обновления характеристик продолжают использовать стили существующего корня. Значения calculator, ARPG, equipment и структура листа не изменены.

Тема UI: отдельный viewer class на реальном ATF body. Фон применяется к body, остальные параметры — только к фиксированным forum cards, navigation и headings. Selectors исключают canonical `[data-element-surface]` и их содержимое: профиль, лист и postbit не являются editable UI-компонентами этой темы. Незаполненные значения наследуют текущую ATF/AdvancedMenu палитру и типографику; пользовательские настройки меню не перезаписываются.

## Preview, drafts, media

Мастерская открывает `apstudio.php?target=...`, использует общий JS и server seed registry. Для профиля берётся фактический ATF member_profile component и существующие APUI hero/navigation + ATF native-info renderers. Для листа берётся фактический CharacterSheets inner template, используемый интеграцией ATF/APUI; для темы — фактические ATF index/forumbit templates. Фиктивные безопасные данные заменяют реальные поля, управляющие элементы отключены, submit/navigation не выполняются. Отдельного renderer или статичных preview-картинок нет.

Preview и frontend вызывают один компилятор с одним JSON; preview добавляет только контейнерный scope. Черновики остаются в `draft_json`, опубликованное определение не меняется до save. Локальный резерв разделён по UID + target + preset ID; старый postbit storage key сохранён. Mobile переключает controls/preview, существующий layout форума не меняется.

Media picker получает purpose из registry. Сохраняются `media_id`, ownership/approval/retention проверяет существующий Gallery API. Загрузка/пути/альбомы/thumbnail pipeline не копируются и не меняются. Один ID разрешено повторно использовать; приватный чужой ID отклоняется.

## ElementTheme

Канонический key поступает из существующего ATF/approval resolver. Appearance может передать ограниченную палитру и проверенный эффект, но не создать элемент/approval или включить поверхность, запрещённую ACP. Общий ElementTheme renderer читает direct-child inert surface config и сохраняет `enabled/surfaces` базового эффекта. Переданный config дополнительно привязан к canonical element key; несовпадение игнорируется. Уважение AdvancedMenu animation preferences и reduced motion остаётся в существующем Canvas Engine.

Добавлен метод существующего renderer `setSurfaceAppearanceEffects(root,config)` для изолированного preview; старый `setAppearanceEffects(uid,config)` сохранён. Второго Canvas Engine нет. UI `theme` не является Canvas surface ElementTheme, поэтому конструктор темы не предлагает несуществующий renderer анимаций.

## Совместимость и проверки

Старые signatures сохранены: новые аргументы target у decode/compiler/preview/components/active-row необязательны и по умолчанию означают postbit. Старые магазинные определения, PID snapshots, API, таблицы и назначения не конвертируются. Reset снимает только назначения нужной поверхности и её legacy fragments; ничего не удаляет из каталога или Gallery.

Целевые проверки на PHP 8.5.0 / MySQL 8.4: `tests/appearance_surfaces_integration.php` проверяет независимые CRUD/slots/assignments, опубликованный JSON и draft, system reset, реальные шаблоны, preview/compiler, media ownership, чужого владельца, allow-lists, запрет raw CSS/чужих областей и approval gate. `tests/appearance_surfaces_browser.cjs` проверяет actual template components, live color/font, Gallery purposes, save/apply, мобильную ширину, Canvas preference и отсутствие влияния profile/sheet CSS на соседнего владельца. Используются реальные модули; внешние workflow/forum данные заданы изолированным fixture. Production/MyBB installation и полная экономика форума не затронуты, общий suite не запускался.

Основные файлы: Appearance `surfaces.php`, `personal.php`, `studio.php`, общий JS/CSS/workshop template; опциональный bridge в CharacterSheets `modules/render.php`; существующий ElementTheme `assets/element-effects.js`; Shop slot registry seeding/manager options и manifests.
