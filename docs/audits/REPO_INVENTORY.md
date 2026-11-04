# REPO_INVENTORY — реестр репозитория и документации (Задача 0.1)

Дата среза: 2026-11-01. Ветка: `main`. Репозиторий: CaptainPaws/mybb-advancedfunctionality.
Покрытие: корень репозитория и `docs/` (100% файлов). `inc/plugins/advancedfunctionality/addons/` намеренно исключён (обрабатывается в задаче 0.2).

## 1. Дерево репозитория (без addons/)

```
mybb-advancedfunctionality/
├── LICENSE
├── README.md
├── abilities.php                      # публичная точка входа AF (способности)
├── balancemanage.php                  # публичная точка входа AF (баланс)
├── characters.php                     # публичная точка входа AF (персонажи)
├── charactersheets.php                # публичная точка входа AF (листы персонажей)
├── inventories.php                    # публичная точка входа AF (инвентари)
├── inventory.php                      # публичная точка входа AF (инвентарь)
├── kb.php                             # публичная точка входа AF (база знаний)
├── shop.php                           # публичная точка входа AF (магазин)
├── shop_manage.php                    # публичная точка входа AF (управление магазином)
├── wanted.php                         # публичная точка входа AF (wanted-заявки)
├── docs/                              # см. раздел 2 (117 md + 1 json + 2 png)
├── inc/
│   ├── plugins/
│   │   ├── advancedfunctionality.php          # ядро плагина (точка входа)
│   │   └── advancedfunctionality/             # ядро: роутер, ассеты, runtime-файлы
│   │       ├── admin/router.php
│   │       ├── assets/
│   │       │   ├── af-theme-stylesheets-admin.css
│   │       │   ├── af-theme-stylesheets-editor.js
│   │       │   └── index.html
│   │       ├── avatar.php
│   │       ├── gateway_stub.php
│   │       └── cache/                         # runtime-кэш (не документация)
│   │           ├── advancedinventory_schema.md
│   │           ├── af_aam_telegram.log
│   │           ├── installed_at.txt
│   │           ├── last_activation.txt
│   │           ├── needs_refresh.txt
│   │           └── templates_sig.txt
│   └── tasks/
│       ├── af_aam_cleanup.php                 # задача планировщика MyBB
│       └── af_kb_reservation_cleanup.php      # задача планировщика MyBB
└── tests/                             # регрессионные тесты: ~180 .php, ~40 .cjs, фикстуры, 2 md
    └── fixtures/                      # fixture-скрипты для тестов
```

Замечания к дереву:

- **low** — `inc/plugins/advancedfunctionality/cache/` содержит runtime-артефакты (`installed_at.txt`, `last_activation.txt`, `af_aam_telegram.log`), закоммиченные в Git: inc/plugins/advancedfunctionality/cache/af_aam_telegram.log:1. Это не документация и, вероятно, не должно версионироваться.
- **low** — `inc/plugins/advancedfunctionality/cache/advancedinventory_schema.md` — единственный md-файл вне `docs/`; в реестр docs/ не включён, но стоит учитывать при полном аудите документации: inc/plugins/advancedfunctionality/cache/advancedinventory_schema.md:1.
- **low** — `docs/design/editor/advancededitor-stage1-performance.json` — единственный json в docs/ (бенчмарк-выгрузка), не md; в классификацию таблицы не входит.
- `docs/atf-frame-diagnostics/` — 2 png-скриншота (diagnostics), md-файлов нет.

## 2. Реестр docs/ — таблица классификации

Типы: contract / audit / design / implementation / other.
Подсистемы: ACS, KB, ARPG, ATF, appearance, gallery, editor, signature, characters, shop/inventory, wanted, menu, messenger, frontend-assets, theme-stylesheets, misc.

### 2.1. docs/ — корневые документы (92 файла)

| Файл | Тип | Подсистема | Заметка |
|---|---|---|---|
| ACS_BALANCE_DECISIONS.md | design | ACS | Решения по балансу и блокеры, этап C; сопровождает ACS_COMBAT_RESOLVER_DESIGN.md. Не утверждены автоматически. |
| ACS_COMBAT_RESOLVER_DESIGN.md | design | ACS | Архитектурное предложение Combat Resolver (D1–D12). Явно предложение, не существующий код. |
| ACS_KB_FORMULA_CONTRACT.md | contract | KB / ACS | Контракт типизированных формул/модификаторов (этап B2). |
| ACS_KB_MECHANICS_AUDIT.md | audit | KB / ACS | Аудит механического ядра KB для ACS, 20.10.2026. |
| ACS_KB_MECHANICS_CONTRACT.md | contract | KB / ACS | Механические определения для ACS (этап B3). |
| ACS_KB_NORMALIZATION_CONTRACT.md | contract | KB / ACS | Read-only нормализация ARPG (этап B1). |
| ADAPTIVE_THEME_FRAMEWORK.md | design | ATF | Обзорная спецификация владения презентацией ATF. |
| ADVANCEDMENU_NAVIGATION_REGISTRY_AUDIT.md | audit | menu | **Устаревший**: «Historical inventory», регистрация навигации заменена политикой в FRONTEND_ASSET_MANIFEST_CONTRACT.md. |
| ADVANCEDMESSENGER.md | other | messenger | Описание аддона AdvancedMessenger (включение, границы). |
| ADVANCED_APPEARANCE_PERSONAL_PRESETS.md | contract | appearance | Личные postbit-пресеты, этап 3. |
| ADVANCED_APPEARANCE_POST_MASKS.md | contract | appearance | Маски сообщений, этап 5. |
| ADVANCED_APPEARANCE_SHOP_SLOTS.md | contract | appearance / shop | Постоянные слоты Shop → Appearance, этап 6. |
| ADVANCED_APPEARANCE_STUDIO_CONTRACT.md | contract | appearance | Архитектурный контракт Appearance Studio (этап 2). |
| ADVANCED_APPEARANCE_SURFACES.md | contract | appearance | Мастерская profile/sheet/theme, этап 7. |
| ADVANCED_APPEARANCE_WORKSHOP.md | contract | appearance | Мастерская постбита, этап 4. |
| ADVANCED_EDITOR_AUDIT.md | audit | editor | Технический аудит AdvancedEditor, 05.10.2026. |
| ADVANCED_GALLERY_MEDIA_API.md | contract | gallery / appearance | Gallery 1.1 Media API (альбомы Appearance). |
| ADVANCED_INVENTORY_SHOP_CHARACTERSHEETS_AUDIT.md | audit | shop / inventory / characters | Аудит трёх аддонов, 25.09.2026. |
| ADVANCED_JS_BUNDLE_FRONTEND_AUDIT.md | audit | frontend-assets | Аудит frontend-прав AdvancedJSBundle (до миграции). |
| ADVANCED_WANTED_AUDIT.md | audit | wanted | Pre-implementation аудит AdvancedWanted. |
| AF_THEME_STYLESHEETS_AUDIT.md | audit | theme-stylesheets | Аудит и миграция единого theme stylesheet bundle. |
| ARPG_INTEGRATION_CONTRACT.md | contract | ARPG / KB | Integration contract единого Meta JSON canon. |
| ARPG_KB_SCHEMA_DESIGN.md | design | ARPG / KB | Канонический дизайн single Meta JSON для KB. |
| ARPG_ORIGIN_VARIANT_JSON_CONTRACT.md | contract | ARPG / KB | Контракт Origin / Origin Variant JSON. |
| ATF_CARD_LIST_COMPONENT_AUDIT.md | audit | ATF | Структурный контракт карточек/списков/сеток. |
| ATF_CHARACTERSHEETS_WORKFLOW_FRONTEND_CONTEXT_MATRIX.md | other | ATF / characters | Frontend context matrix (границы владения ассетами). |
| ATF_COMPOSE_MYBB_1840_AUDIT.md | audit | ATF | Аудит compose-шаблонов против тега mybb_1840. |
| ATF_FORM_COMPONENT_AUDIT.md | audit | ATF | Task 57: аудит form-компонентов. |
| ATF_GLOBAL_MODAL_CONTRACT.md | contract | ATF | Контракт глобального футера и модалок. |
| ATF_INDEX_FORUMDISPLAY_STRUCTURE_AUDIT.md | audit | ATF | Структурный аудит index + forumdisplay. |
| ATF_MODCP_ARCHITECTURE_NAVIGATION_CONTRACT.md | contract | ATF | ModCP архитектура/навигация (Task 43, audit only). |
| ATF_MODCP_FINAL_AUDIT.md | audit | ATF | Финальный аудит ModCP Phase 1 (Task 51). |
| ATF_ORNATE_FRAME.md | other | ATF | Bugfix-диагностика рамки 0.29.6 → 0.29.7. |
| ATF_PAGE_SHELL_CONTRACT.md | contract | ATF | Актуальный контракт page shell. |
| ATF_PAGE_SHELL_FINAL_AUDIT.md | audit | ATF | **Частично устаревший**: «Historical audit»; геометрия перенесена в ATF_PAGE_SHELL_CONTRACT.md. |
| ATF_PAGE_SHELL_IMPLEMENTATION_SUMMARY.md | other | ATF | Итоги реализации page shell (ATF 0.30.0). |
| ATF_PRIVATE_MESSAGING_MIGRATION_CONTRACT.md | contract | ATF / messenger | Контракт миграции ЛС + интеграция AdvancedMessenger. |
| ATF_TEMPLATE_COMPATIBILITY_AUDIT.md | audit | ATF | Аудит совместимости шаблонов/плагинов. |
| ATF_TEMPLATE_COMPATIBILITY_CONTRACT.md | contract | ATF | Контракт владения шаблонами и совместимости. |
| ATF_UCP_ARCHITECTURE_NAVIGATION_CONTRACT.md | contract | ATF | Подготовительный контракт UCP (Task 43). |
| ATF_UCP_FINAL_AUDIT.md | audit | ATF | Финальный аудит UCP Phase 1, 02.10.2026. |
| ATF_USERLIST_MIGRATION_CONTRACT.md | contract | ATF | Контракт миграции member/user list. |
| CANON_CHARACTER_LIFECYCLE_AUDIT.md | audit | characters / KB / ATF | Аудит lifecycle канонических персонажей (до реализации). |
| CHARACTER_ABILITY_EFFECTS_CONTRACT.md | contract | characters / KB | Расширение эффектов способностей (backward-compatible). |
| CHARACTER_SHEET_RULES_PIPELINE_AUDIT.md | audit | characters | Аудит rules pipeline листов персонажей. |
| CONTEXTUAL_PROFILE_ADDONS_MIGRATION.md | other | frontend-assets | Миграция контекстных/профильных аддонов (frontend). |
| D15989D_PRODUCTION_RUNTIME_AUDIT.md | audit | misc | Read-only forensic чеклист продакшена (commit d15989d). |
| ELEMENT_THEME_CONTRACT.md | contract | KB / ARPG | Element theme: identity registry `type=arpg_element`. |
| FRONTEND_ASSET_ARCHITECTURE_AUDIT.md | audit | frontend-assets | Аудит frontend-ресурсов, 26.09.2026 (описание, не контракт). |
| FRONTEND_ASSET_MANIFEST_CONTRACT.md | contract | frontend-assets | Актуальная обязательная спецификация frontend-интеграции. |
| INVENTORY_SHOP_APPEARANCE_FRONTEND_CONTEXT_MATRIX.md | other | shop / inventory / appearance | Frontend context matrix. |
| KB_CHARACTER_DESCRIPTION_PREFILL.md | other | KB / ATF | Prefill описания канонического персонажа KB → ATF. |
| KB_EDIT_SCHEMA_ROUNDTRIP_AUDIT.md | audit | KB | Аудит round-trip JSON schema → kb_edit. |
| KB_META_V2_MAPPING.md | other | KB / characters | Таблица маппинга KB Meta v2 → CharacterSheets. |
| LEGACY_FRONTEND_BLACKLIST_FINAL_AUDIT.md | audit | frontend-assets | Финальный аудит legacy blacklist, 26.09.2026. |
| STATISTIC_POSTCOUNTER_BALANCE_FRONTEND_CONTEXT_MATRIX.md | other | misc | Frontend context matrix (Statistic/PostCounter/Balance). |
| advanced-signature-builder.md | other | signature | Точка входа ASB: версии 1.0.x, индекс изменений со ссылками. |
| advancedappearance-background-layers.md | other | appearance | Фоновые слои и подпись (диагностика + реализация). |
| advancedappearance-postbit-studio.md | other | appearance | Appearance 1.6.0 — редактор постбита. |
| advancededitor-feature-lazy.md | other | editor | Feature-lazy toolbar (описание доставки и метаданных). |
| advancededitor-lifecycle-fixes.md | other | editor | Исправления lifecycle/BBCode/FIMP (диагностика SCEditor). |
| advancededitor-regression-fixes.md | other | editor | Регрессионные исправления после bb68efbc. |
| advancededitor-stage1-summary.md | other | editor | Stage 1 summary (shell редактора). Сопровождается json-выгрузкой. |
| apstudio-avatar-scaling.md | other | appearance / ATF | Исправление масштаба аватаров (transform vs scale). |
| apstudio-mask-quick-edit-avatar.md | other | appearance | Аватар sidebar после Quick Edit. |
| apstudio-postbit-overlay.md | other | appearance | Затенение Topbar/Sidebar постбита. |
| apstudio-postbit-preview-fields.md | other | appearance | Поля и иконки предпросмотра постбита. |
| atf-gothic-frame-regression.md | other | ATF | ATF 0.32.3: восстановление готических рамок (CSS-регрессия). |
| avatar-save-formats-fix.md | other | gallery / usercp | Исправление сохранения аватаров, Gallery, форматов. |
| character-workflow-forensic-and-boundary.md | audit | characters | Forensic-анализ модерации заявок персонажей. |
| editor-external-stickers.md | other | editor | AdvancedEditor 1.1.5: внешние пользовательские стикеры. |
| editor-gallery-media-picker.md | other | editor / gallery | 1.1.6/1.2.2: общий медиапикер. |
| editor-sticker-quotas.md | other | editor | Пользовательские стикеры и квоты. |
| gallery-acp-user-media.md | other | gallery | Медиа пользователей в ACP. |
| gallery-album-owners.md | other | gallery | Владельцы на карточках альбомов. |
| gallery-imgbb-providers.md | other | gallery | ImgBB и провайдеры хранения (1.2). |
| gallery-postimages-research.md | other | gallery | Исследование Postimages, 18.10.2026. |
| gallery-storage-filesystem.md | other | gallery | Статистика тома в ACP Gallery. |
| gallery-storage-quotas.md | other | gallery | Локальные квоты хранилища. |
| gallery-unified-sources.md | other | gallery | Единый интерфейс источников (1.2.1). |
| gallery-usercp-avatar.md | other | gallery / usercp | **Устаревший**: первая версия UI заменена usercp-avatar-editor.md. |
| knowledgebase_frontend_integration_matrix.md | other | KB | Frontend integration matrix. |
| postbit-signature-editor.md | other | signature | **Устаревший**: «Историческое описание», заменён advanced-signature-builder.md. |
| showthread-forensic-audit.md | audit | ATF / misc | Showthread forensic audit, 05.10.2026. |
| signature-appearance-only-background.md | other | signature | ASB 1.0.4 / Appearance 1.6.5: фон только в Appearance. |
| signature-builder-css-delivery-fix.md | other | signature | ASB 1.0.2: исправление доставки UI CSS. |
| signature-content-builder.md | other | signature | **Устаревший**: «Историческое описание», заменён advanced-signature-builder.md. |
| signature-editor-fields.md | other | signature | ASB 1.0.5: поля и AdvancedEditor. |
| signature-gallery-profile-fix.md | other | signature | ASB 1.0.3: Gallery, предпросмотр и профиль. |
| stage5-character-modal-form-layout.md | other | characters / ATF | Stage 5: CharacterSheets/AdvancedThreadFields (baseline edb9b2a8). |
| stage6-atf-surfaces-hot-path.md | other | ATF | Stage 6: доставка ATF-поверхностей и hot path (ATF 0.28.0). |
| usercp-avatar-editor.md | other | gallery / usercp | Единый интерфейс двух аватаров (актуальная версия). |

### 2.2. docs/acs/ — документы AdvancedCombatSystem (25 файлов)

| Файл | Тип | Подсистема | Заметка |
|---|---|---|---|
| acs/ACS_1_2_IMPLEMENTATION.md | implementation | ACS | ACS-1.2 SQL foundation и lifecycle (addon 0.2.1). |
| acs/ACS_1_3_IMPLEMENTATION.md | implementation | ACS | ACS-1.3 Permissions/capability diagnostics (0.3.0). |
| acs/ACS_2_1_3_IMPLEMENTATION.md | implementation | ACS / KB | 17 стихий и направления (D1.4). |
| acs/ACS_2_1_4_IMPLEMENTATION.md | implementation | ACS / KB | Status Resistance и бестиарий. |
| acs/ACS_2_1_5_IMPLEMENTATION.md | implementation | ACS | Read-only CombatSnapshot preparation. |
| acs/ACS_2_1_6_IMPLEMENTATION.md | implementation | ACS | Integration adapters и capability diagnostics. |
| acs/ACS_2_2_1_IMPLEMENTATION.md | implementation | ACS | Сессии, темы и роли. |
| acs/ACS_2_2_2_IMPLEMENTATION.md | implementation | ACS | Существа и immutable snapshots (0.5.0, схема 002_entities). |
| acs/ACS_2_2_3_IMPLEMENTATION.md | implementation | ACS | Encounter, стороны, GM-панель. |
| acs/ACS_2_3_1_IMPLEMENTATION.md | implementation | ACS | Round Roster и групповые ходы. |
| acs/ACS_2_3_2_IMPLEMENTATION.md | implementation | ACS | AP, cooldown boundaries, отдых. |
| acs/ACS_2_3_3_IMPLEMENTATION.md | implementation | ACS | Управление раундами. |
| acs/ACS_3_1_1_IMPLEMENTATION.md | implementation | ACS | Numeric Engine. |
| acs/ACS_3_1_2_IMPLEMENTATION.md | implementation | ACS | Formula Compiler / AST Evaluator. |
| acs/ACS_3_1_3_IMPLEMENTATION.md | implementation | ACS | Modifier Pipeline. |
| acs/ACS_3_2_1_IMPLEMENTATION.md | implementation | ACS | Hit Check и Critical Damage. |
| acs/ACS_3_2_2_IMPLEMENTATION.md | implementation | ACS | DEF/RES и Mixed Damage Allocation. |
| acs/ACS_3_3_1_IMPLEMENTATION.md | implementation | ACS | Barrier Pools, HP, Resource Costs. |
| acs/ACS_3_3_2_IMPLEMENTATION.md | implementation | ACS | Base Resolver / read-only preview. |
| acs/ACS_4_1_1_PUBLICATION_AUDIT.md | audit | ACS | Аудит публикации MyBB и транзакционной границы. |
| acs/ACS_4_1_2_GATEWAY_CONTRACT.md | contract | ACS / editor | Контракт MybbPostGateway + AdvancedEditor. **Статус: BLOCKED** (заголовок файла). |
| acs/ACS_IMPLEMENTATION_ROADMAP.md | design | ACS | Roadmap реализации ACS 1.0 со статусами. |
| acs/ACS_INTEGRATION_CONTRACTS.md | contract | ACS | Integration contracts ACS 1.0. |
| acs/ACS_MASTER_SPECIFICATION.md | design | ACS | Master Specification ACS 1.0. |
| acs/ACS_TECHNICAL_ARCHITECTURE.md | design | ACS | Technical Architecture ACS 1.0. |

### 2.3. Прочие файлы docs/ (не md)

| Файл | Тип | Подсистема | Заметка |
|---|---|---|---|
| docs/design/editor/advancededitor-stage1-performance.json | other | editor | Бенчмарк-выгрузка Stage 1 (единственный json в docs/). |
| docs/design/atf/frame-diagnostics/index-deployed-0.29.6.png | other | ATF | Скриншот диагностики рамки (deployed 0.29.6). |
| docs/design/atf/frame-diagnostics/index-local-css-0.29.7.png | other | ATF | Скриншот диагностики рамки (local css 0.29.7). |

## 3. Дубликаты и устаревшие документы

- **Устаревшие (помечены в самих документах):**
  - docs/archive/ADVANCEDMENU_NAVIGATION_REGISTRY_AUDIT.md:3 — «Historical inventory», политика заменена FRONTEND_ASSET_MANIFEST_CONTRACT.md.
  - docs/archive/ATF_PAGE_SHELL_FINAL_AUDIT.md:3 — «Historical audit», актуальная геометрия в ATF_PAGE_SHELL_CONTRACT.md.
  - docs/archive/postbit-signature-editor.md:1 и docs/archive/signature-content-builder.md:1 — «Историческое описание», заменены AdvancedSignatureBuilder (advanced-signature-builder.md).
  - docs/archive/gallery-usercp-avatar.md:1 — первая версия UI заменена usercp-avatar-editor.md.
- **Перекрытие тем (не дубликаты, но пересекаются):**
  - Рамка ATF описана в трёх документах: ATF_ORNATE_FRAME.md (0.29.7), atf-gothic-frame-regression.md (0.32.3), ATF_PAGE_SHELL_IMPLEMENTATION_SUMMARY.md — это последовательные версии одной эволюции, не дубликаты.
  - Signature-цепочка advanced-signature-builder.md ↔ signature-editor-fields.md ↔ signature-appearance-only-background.md — индекс версий 1.0.x, взаимно ссылаются.
- **Точных дубликатов (одинаковое содержимое под разными именами): не найдено.**

## 4. Сводка

- docs/: 117 md (92 корневых + 25 в acs/) + 1 json + 2 png — классифицированы все.
- Корень репозитория: 12 публичных PHP-точек входа, ядро плагина, 2 задачи планировщика, tests/ (~220 файлов) — отражены в дереве раздела 1.
- addons/ (37 папок) в данный реестр не входит — задача 0.2.
