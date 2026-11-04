# Аудит frontend-ресурсов AdvancedFunctionality

Дата среза: 2026-09-26. Область: текущее содержимое репозитория, MyBB 1.8.40 / PHP 8.5. Это **описание**, а не изменение контракта: blacklist, hooks, manifests, templates и assets не менялись.

## Краткий вывод

В AF нет одного законченного asset resolver. Есть центральный поздний сборщик manifest/fallback-assets, реестр theme stylesheets и очередь `af_add_*_once()`, но большинство сложных addons имеют собственный `pre_output`-инжектор. Центральный blacklist применяется только перед центральным сборщиком; локальные инжекторы обязаны проверять blacklist сами. Поэтому замена blacklist одним условием в `af_collect_enabled_addon_assets()` не решит задачу и создаст ложное чувство контроля.

Безопасная будущая граница — единый неизменяемый request context и единая функция permission decision, вызываемая (1) центральным collector и (2) каждым локальным owner-инжектором. Перенос самих тегов в ядро должен быть отдельным, более поздним этапом.

## A. Текущий blacklist

### A.1 Центральный контракт

* `af_normalize_script_name()` приводит имя к lower-case basename. Источник — сначала явный аргумент, затем `THIS_SCRIPT`, затем `$_SERVER['SCRIPT_NAME']`.
* `af_parse_assets_blacklist_conditions()` принимает textarea: одно непустое правило на строку, `script.php` либо `script.php?action=value`. Из query учитывается **только** первый параметр `action`; URL-decoding, wildcard и остальные параметры отсутствуют.
* `af_setting_blacklist_disabled_for_current_page()` читает значение из `$mybb->settings`; пустое значение заменяет переданным default. Сопоставление script точное; правило без action блокирует все actions, правило с action — только точное lower-case совпадение с `$mybb->input['action']`.
* `af_is_blacklisted($addonId)` последовательно проверяет `af_{id}_assets_blacklist`, `af_{id}_disable_on` и legacy aliases: APF, ATF, CharacterSheets. В ядре hard-coded default есть только для `af_advancededitor_disable_on`.
* Единственная общая точка применения — `af_collect_enabled_addon_assets()`: blacklisted addon целиком пропускается перед чтением manifest-assets/fallback directory. Это не запрещает addon-owned `pre_output`, `$headerinclude` и template tags.

### A.2 Где хранится и кэшируется

Blacklist не имеет отдельного AF registry/cache. Это обычные MyBB settings в таблице `settings`, материализованные MyBB в `inc/settings.php` и `$mybb->settings`. После создания/изменения настройки addons обычно вызывают `rebuild_settings()` либо центральный `af_rebuild_and_reload_settings()`. Последний также invalidates OPcache для `inc/settings.php`, очищает stat cache, повторно `require`-ит файл и заменяет `$mybb->settings` в текущем request.

Текстовые `.txt` файлы AF cache (`installed_at`, `last_activation`, `needs_refresh`, signatures templates/theme stylesheets) blacklist не содержат. Static memoization имеется только в отдельных parsers (например, Inventory), то есть лишь на время одного PHP request.

### A.3 Реализации и ACP settings

| Addon | Setting/default | Реальная проверка |
|---|---|---|
| AdvancedEditor | `af_advancededitor_disable_on`; core default: index/forumdisplay/postsactivity/usercp/userlist/search/gallery | центральный helper и собственный strip/inject |
| AdvancedGallery | addon setting с `AF_AG_ASSETS_BLACKLIST_DEFAULT` | свой parser/matcher и central helper; собственный pre-output |
| AdvancedProfileFields | `af_apf_assets_blacklist` | central legacy alias плюс собственный fallback matcher |
| AdvancedInventory | `af_advancedinventory_assets_blacklist`, default `index.php` | свой exact-script map; собственные header appenders |
| CharacterSheets | `af_cs_assets_blacklist` | central legacy alias; modal-request имеет force exception |
| AdvancedPostCounter | `af_apc_assets_blacklist` плюс compiled default | свой parser/HTML-content decision и central conventional key |
| Balance | `af_balance_blacklist` (нестандартное имя) | явный central call с id `balance`, затем собственный setting parser; central collector сам это имя не знает |
| ForceRefresh | `af_forcerefresh_assets_blacklist`, default `index.php` | собственный matcher/stripper и central conventional key |
| AdvancedShop | `af_shop_assets_blacklist` | собственный parser; id `advancedshop` означает, что conventional central key иной, поэтому контроль локальный |
| ResponsiveLayout | `af_advresponsivelayout_assets_blacklist` | собственный exact/action/wildcard matcher |
| KnowledgeBase | `af_kb_assets_blacklist` | собственный matcher; conventional central key для id `knowledgebase` иной |
| AdvancedThreadFields | `af_atf_assets_blacklist` | central legacy alias и собственный parser; KB endpoints имеют явное исключение |

Упоминания механизма находятся в core и в файлах перечисленных addons, а локализованные ACP labels дополнительно находятся в manifests Gallery, PostCounter, Balance, CharacterSheets и ForceRefresh. Наличие label в manifest не означает, что setting создаётся из manifest: settings создают install/ensure функции bootstrap-файлов.

### A.4 Активация/реактивация

Активация самого AF не пересоздаёт blacklist settings addons. Она создаёт scaffold/languages, заново читает manifests, синхронизирует addon languages, master templates и theme stylesheets, обновляет gateway и cache marker. Включение addon через ACP сначала `require_once` bootstrap и вызывает только `af_{id}_install()` (не `_activate()` и не `_upgrade()`), затем создаёт enabled setting, reload settings и sync theme CSS. Следовательно, addon, который полагается только на `_activate()`/`_upgrade()`, при обычном enable может не мигрировать настройки. Reactivation AF вызывает core `activate`, но не addon installers.

## B. Текущий asset flow

### B.1 Общая цепочка

1. MyBB загружает plugin file. AF регистрирует `global_start` (language, output-buffer fallback, addon bootstrap), `pre_output_page` и `xmlhttp` hooks.
2. `advancedfunctionality_bootstrap_addons()` вызывает `af_discover_addons()`, проверяет `af_{id}_enabled`, синхронизирует/загружает язык, `require_once` bootstrap и вызывает `af_{id}_init()`.
3. Init регистрирует addon hooks; некоторые addons также вызывают frontend registrations прямо при include.
4. На `pre_output_page` core снова гарантирует bootstrap, затем `af_apply_preoutput_filters()` **вручную** вызывает каждую функцию `af_{id}_pre_output()`. Отдельно зарегистрированные MyBB hooks также выполняются: для совпадающих names это потенциально два входа, и безопасность зависит от markers/dedup.
5. Core синхронизирует templates/theme CSS, prune-ит queued file CSS, собирает enabled assets, строит tags, добавляет их и к `$headerinclude`, и непосредственно перед `</head>` готовой страницы; markers и финальные dedup нормализуют результат.
6. Для `usercp.php` и `misc.php` output buffer повторяет pipeline, если страница обошла `output_page()`.
7. AJAX/JSON, ACP, `xmlhttp.php`, redirect HTML и `AF_NO_PRE_OUTPUT` обходят соответствующие части pipeline.

### B.2 Центральные механизмы

* **Manifest assets.** Только manifests AccountSwitcher и ResponsiveLayout объявляют `assets.front/admin/both.{css,js}`. Они перечисляются в заданном порядке.
* **Directory fallback.** Если ключ `assets` отсутствует, core сканирует только непосредственные файлы `assets/`, сортирует имена и автоматически берёт все `.css`/`.js`. Вложенные directories не берутся. Это главный источник «почти глобальной» загрузки.
* **Queue API.** `af_add_css_once()`/`af_add_js_once()` нормализуют URL, применяют scope/admin gate, CSS theme-delivery gate, cache-buster и request-global dedup. Balance и CharacterSheets используют API; другие owner injectors — в основном нет.
* **Theme stylesheets.** `theme_stylesheets` manifest entries обнаруживаются отдельно, регистрируются в DB `af_theme_stylesheets`, собираются в `advancedstyles.css` на тему и отдаются MyBB через `$stylesheets`, если attachment подходит request. В режиме theme/auto central file CSS подавляется; при нерабочем cache/attachment resolver fail-open возвращает file CSS.
* **Late HTML injection.** `af_assets_build_tags()` всегда ставит CSS, затем JS (`defer`) в head. `af_inject_enabled_addon_assets()` одновременно обновляет `$headerinclude` и уже отрендеренный `$page`.
* **Owner pre-output injection.** Addons анализируют script/action либо уже готовый HTML, вырезают дубли и вставляют tags/config payloads в head/body. Это отдельный полноценный механизм.
* **Templates.** `templates/*.html` объединяются/синхронизируются в MyBB master templates. Шаблоны могут содержать addon variables, inline JS и/или modal markup; они не проходят asset permission как отдельные ресурсы.
* **Direct page render.** Alias entry points (`wanted.php`, shop/inventory/charactersheets и др.) строят `$headerinclude/$header/$footer` и `output_page()`. Некоторые перед выводом явно дополняют `$headerinclude`.
* **Footer/modal.** Отдельной центральной footer queue нет. Modal HTML обычно добавляется template variable либо перед `</body>`; modal JS может находиться в head или быть late-injected по наличию markup/chip.

### B.3 Фактический порядок и зависимости

MyBB theme `headerinclude` создаёт базовый порядок (jQuery/MyBB scripts и `$stylesheets`), но AF не владеет им. Затем owner addons могут вставлять tags относительно уже найденных core scripts, central collector добавляет в head `CSS -> deferred JS`, а footer templates/modal markup идут в body. AdvancedJSBundle ещё позднее удаляет известные source tags и подставляет bundle, поэтому итоговый порядок зависит от включённого bundler.

Нельзя переставлять без совместного теста:

* jQuery перед MyBB plugins, SCEditor, Editor/Giphy/Gallery integrations и scripts, использующими `$`;
* SCEditor core перед BBCode mode/MyBB bridge, addon commands и payload/init;
* inline `window.*Config`/payload перед соответствующим runtime (FontAwesome, Gallery, Editor, Shop, ForceRefresh, KB, Balance);
* AdvancedMenu runtime до/совместно с modal owners Alerts и AccountSwitcher: menu создаёт triggers, owners создают modal markup/data/runtime;
* KB base runtime перед chips/insert runtime; CharacterSheets config/modal markup перед its runtime;
* AdvancedJSBundle должен оставаться late transformation, иначе он не увидит теги, которые должен заменить.

`defer` сохраняет document order только между deferred external scripts; многие owner tags не используют `defer`, а inline payload исполняется немедленно. Поэтому простая сортировка addon ids несовместима с текущими неявными зависимостями.

## C. Manifest flow

### C.1 Discovery и cache

`af_discover_addons()` на **каждом вызове** делает `scandir(AF_ADDONS)`, `include manifest.php`, требует непустые `id` и `name`, дополняет runtime `path`, превращает relative `bootstrap` в absolute path и сортирует по display name. Static/request cache и persisted addon registry отсутствуют. За один обычный request discovery вызывается bootstrap, pre-output, template/theme sync checks и asset collection; следовательно manifest может читаться многократно. XMLHTTP имеет отдельный `glob + require` discovery и fallback id/bootstrap, то есть контракт там немного отличается.

Registry включённости — не manifest и не отдельная таблица: `af_{id}_enabled` в MyBB settings. Registry theme CSS — таблица `af_theme_stylesheets`. Runtime metadata — массивы, возвращённые manifests.

### C.2 Фактическая schema

Общими практически для всех являются `id`, `name`, `version`, `author`, `bootstrap`; часто есть `description`, `authorsite`/`website`, `lang`; опционально `type`, `compatibility`, `admin`. Формального `schema`, `schema_version`, validator или migration версии manifest нет.

Frontend-похожие поля уже существуют:

* `assets` — только AccountSwitcher и ResponsiveLayout; секции `front/admin/both`, типы `css/js`;
* `theme_stylesheets` — AdvancedCharacters, AdvancedEditor, AdvancedJSBundle, AdvancedProfileUI, AdvancedWanted, KnowledgeBase; entries содержат logical id/file/name/attach metadata;
* `admin` — ACP route metadata (`slug`, `controller`, title/icon/order), но это не frontend route;
* dependencies/frontend/routes общего назначения отсутствуют.

PHP arrays допускают backward-compatible optional key, а consumers уже игнорируют незнакомые keys. Поэтому расширить manifest безопаснее, чем вводить второй файл, при условиях: новый key опционален; отсутствие сохраняет legacy поведение; resolver валидирует типы; XMLHTTP discovery не пытается исполнять frontend resolver; manifest остаётся side-effect-free. Schema version полезна позже, но не нужна для первого additive key.

## D. Addons, обходящие центральный asset collector

«Обходит» ниже означает, что хотя бы один frontend/ACP tag, inline config или asset decision принадлежит addon, а не `af_collect_enabled_addon_assets()`.

| Addon / файл, точка | Ресурс/способ | Контроль core сейчас | Изменение addon для нового permission gate |
|---|---|---|---|
| AdvancedWanted `advancedwanted.php`: page renderer, dependency helper, `af_wanted_ensure_chip_runtime` | catalog JS, inline dependent-select JS, CSS + modal JS по chip | нет; только final dedup видит tags | да, late chip gate должен вызвать общий decision; page assets можно декларативно описать позднее |
| AdvancedInventory `advancedinventory.php`: append runtime/embedded assets | прямой `$headerinclude .=` CSS/JS | нет, локальный blacklist | да |
| AdvancedAppearance `advancedappearance.php`: page tags/direct page render/pre-output | CSS, main JS, modal-scope JS, runtime CSS | лишь theme CSS normalization | да |
| CharacterSheets `modules/bootstrap.php`, `modules/render.php` | queue API плюс direct header markers/tags, modal assets | частично queue/theme gate; modal force локален | да, сохранив modal exception |
| KnowledgeBase `knowledgebase.php`: header ensure и pre-output | CSS/theme href, base/chips/insert JS, SCEditor stack, inline endpoints/lang/mode | CSS частично; JS/config нет | да |
| AdvancedShop `advancedshop.php`: page/pre-output | CSS, JS, inline endpoint | нет, локальный blacklist | да |
| AdvancedEditor `advancededitor.php`, `admin.php`, nested BBCode modules | fonts/SCEditor/theme, addon JS/CSS, command modules, inline payload; ACP echo | CSS частично theme gate, остальное нет | да; editor page classifier должен остаться owner-owned сначала |
| AdvancedFontAwesome `advancedfontawesome.php`, `admin.php` | FA vendor CSS, addon CSS/JS, inline config, ACP `extra_header` | нет | да для frontend resolver; ACP оставить отдельным scope |
| AdvancedGallery `advancedgallery.php` | picker CSS/JS + inline config via pre-output (старые header lines закомментированы) | нет, local blacklist | да |
| AdvancedGiphy `advancedgiphy.php` | CSS/JS + inline config inserted around SCEditor | нет | да |
| AdvancedMenu `advancedmenu.php`, `admin.php` | frontend CSS/JS direct page injection; ACP icon picker CSS/inline/JS | нет | да для frontend; ACP separately |
| AlertsAndMentions `advancedalertsandmentions.php` + template | CSS/main/mentions JS through generated template variables; modal/footer HTML | нет | да, но это intentionally global logged-in UI |
| AccountSwitcher `manifest.php` + `advancedaccountswitcher.php` | assets central via manifest; modal markup pre-output/template | assets да; markup нет | permission change обычно только core; modal must follow same decision |
| AdvancedProfileUI `advancedprofileui.php` | theme/file CSS and JS pre-output | CSS частично; JS нет | да |
| AdvancedPostCounter `advancedpostcounter.php` | conditional CSS head + JS body | нет, local matcher | да |
| AdvancedPosterAvatar `advancedposteravatar.php` | CSS/JS returned and injected by pre-output | нет | да |
| AdvancedStatistic `advancedstatistic.php` | CSS/JS pre-output | нет | да |
| AdvancedThreadFields `advancedthreadfields.php` | CSS/JS pre-output; JSON data script in rendered controls | нет, local blacklist | да for external assets; data script stays component output |
| ResponsiveLayout `manifest.php` + pre-output | manifest assets **and** direct conditional CSS/JS append/strip | central plus local, potential duplicate normalized later | да; choose one owner before enforcing |
| Balance `balance.php` | queue API, inline config; standalone output also inserts jQuery | external assets partly core queue | да for decision/config; do not reorder standalone jQuery |
| ForceRefresh `forcerefresh.php` | body JS + inline config | нет, local blacklist | да |
| HeaderWelcomeAvatar `headerwelcomeavatar.php` | CSS and external/inline JS late injection | нет | да |
| AdvancedJSBundle `advancedjsbandle.php` | removes component tags, emits JS bundle and theme/file CSS | transformation after other owners | да, but integrate last |
| AdvancedRules `advancedrules.php`, `admin.php` | inline frontend behavior in template; ACP jQuery/SCEditor/inline JS | нет | frontend inline behavior requires context decision only if page itself becomes conditional |

Addons FastNews, FakeOnline and SmartUrlTitles have pre-output hooks but the inspected paths primarily transform markup/content rather than own a normal CSS/JS pair. They still must be regression-tested because inserting a new gate into the shared pre-output dispatcher can change their execution accidentally.

## E. AdvancedWanted

### E.1 `/wanted.php`

Root `wanted.php` (and packaged alias copy) defines `THIS_SCRIPT='wanted.php'`, loads `global.php`, checks that bootstrap/function exists and calls `af_wanted_page()`. The addon is loaded earlier by AF `global_start`. `af_wanted_page()`:

* returns JSON and exits for `action=modal&ajax=1`, so core pre-output/assets are deliberately skipped;
* handles lifecycle POST/redirect actions;
* evaluates normal MyBB `$headerinclude`, `$header`, `$footer` and sends a complete document through `output_page()`;
* appends `advancedwanted_catalog.js` directly only on the catalog branch;
* appends inline dependency JS only on create/edit forms that contain dependent dynamic fields;
* detail pages need markup CSS but no catalog JS.

Wanted CSS is declared as a `theme_stylesheets` source attached to both `global` and `wanted.php`. Thus on a healthy integrated theme it is already global through the MyBB stylesheet bundle/attachment. There is no manifest `assets` entry for Wanted JS. Directory fallback may nevertheless enqueue every top-level `.js` in Wanted `assets/` globally (catalog and modal) because absence of `assets` triggers scan; final dedup only removes exact duplicates. This is precisely the legacy over-loading problem.

### E.2 Chips и modal

`parse_message_end` looks for literal `[wanted=id]`, fetches entry/fields/values and replaces it with semantic `.af-wanted-post-chip`; no JS is needed to **render the server-side chip HTML**. CSS is needed before paint to avoid an unstyled chip/layout shift. Interaction requires `advancedwanted_modal.js`, which requests `wanted.php?action=modal&id=…&ajax=1` and displays returned `detail_html`.

The addon registers `pre_output_page` priority 20. `af_wanted_ensure_chip_runtime()` scans the final HTML for `af-wanted-post-chip`; only then it directly inserts Wanted CSS and modal JS before `</head>`. Therefore a `showthread.php` with a successfully expanded chip is distinguishable from an ordinary showthread **without refactoring the data model**: use the existing rendered marker. It is a response-content context, available late, not a pure request context.

Consequences by page under current code:

| Context | PHP hooks | Wanted resources today |
|---|---|---|
| `showthread.php` with chip | `parse_message_end`, then chip pre-output | late CSS/modal JS; additionally central fallback can have already queued all Wanted JS/CSS |
| ordinary `showthread.php` | parse hook runs but fast-returns; chip pre-output returns | central fallback/theme-global CSS may still load resources |
| `index.php`, `member.php`, `usercp.php`, `forumdisplay.php` without rendered chip | init and expiry normalization; parse hook only where messages are parsed | central fallback/theme-global CSS means resources can load despite no Wanted UI |
| any of those pages containing parsed chip markup | same marker-based late runtime | modal runtime is correctly detectable from final HTML |
| `wanted.php` catalog | normal page hooks | CSS plus direct catalog JS; central fallback may add modal/catalog scripts too |
| `wanted.php` create/edit | normal hooks | CSS plus conditional inline dependency JS |
| modal AJAX | AF AJAX/pre-output skipped; endpoint returns JSON payload | caller page must already own CSS/modal runtime |

Recommended first pilot distinction: request rules allow `wanted.php`; response predicate `contains .af-wanted-post-chip` allows chip CSS/modal runtime. Do **not** require modal JS merely to display the chip. Do not move CSS to lazy modal-open loading.

## F. Глобальные addons

* **AdvancedMenu** is site navigation and owns global menu/drawer triggers and the generic menu runtime. Its CSS/JS are legitimately global when `af_advancedmenu_assets` is enabled.
* **AlertsAndMentions** supplies an authenticated global header/menu notification affordance, modal, badges and mention behavior. Main alert CSS/runtime and modal are global for logged-in users; editor-only mentions integration can eventually be a narrower sub-resource, but must not be split in the first migration.
* **AccountSwitcher** supplies an authenticated global trigger/modal registered into AdvancedMenu. Its manifest assets are deliberately `front`; global authenticated visibility is the real dependency. Today core cannot express user-state condition, so tags are broader than UI.
* **Font Awesome** is shared icon infrastructure used by menu and multiple addons/templates. Vendor CSS is truly global while enabled. Its picker/config/runtime may be context-dependent, but separating them in the same change risks broken icons/editors.
* **AF global buttons/arrows** are not a distinct core asset bundle in this tree. They are implemented by AdvancedMenu CSS/JS and individual addon markup/styles; there is no safe standalone “arrow” resource to gate.

The practical load relation is Font Awesome CSS → icon-bearing markup; AdvancedMenu CSS/runtime → navigation triggers/drawer; AAS/AAM assets and modal markup → registered trigger functionality. Preserve their current relative behavior during the Wanted pilot.

## G. Safe implementation points

### Phase 0 — tests and observability only

Record snapshots for ordinary and chip `showthread`, Wanted catalog/detail/edit/modal JSON, index/member/usercp/forumdisplay, logged-in/out global menu, and activate/reactivate. Assert both presence and order of tags. Add diagnostics returning decisions; do not log every production request by default.

### Phase 1 — Frontend Context Resolver

Add one pure function adjacent to `af_assets_init_context()` in `advancedfunctionality.php`, reusing rather than replacing it. Context should normalize `THIS_SCRIPT`, `action`, integer `fid/tid`, AJAX (both input and XHR/Accept, currently split between two helpers), request method, admin/xmlhttp, and response capabilities. Cache only per request. Keep response marker predicates separate because chip presence is unknowable at `global_start`.

Do not read manifests or call addon code from the context function. Do not change existing `af_is_ajax_request()` semantics in the same patch.

### Phase 2 — Frontend Manifest Resolver

Extend existing `manifest.php` with one optional frontend key and parse it in a new pure resolver next to `af_discover_addons()`. Absence must return legacy allow. Validate unknown/malformed values fail-open and produce diagnostics. Initially cache normalized manifests in-request only; persistent cache would introduce activation invalidation risk and is unnecessary for correctness.

Keep `theme_stylesheets` as the CSS source/delivery registry; frontend permission answers **whether**, theme delivery answers **how**. Do not combine their schemas or DB tables.

### Phase 3 — Asset permission check

Call a single `af_frontend_asset_allowed(addon, resource/group, context, optional responseFacts)`:

1. in `af_collect_enabled_addon_assets()` immediately after enabled check and before legacy blacklist;
2. at `af_add_asset_once()` only when addon/resource ownership is supplied in metadata (otherwise legacy callers must remain allowed);
3. at each direct owner injector listed in D, initially as an additional guard while preserving blacklist and tag generation;
4. at late response-aware Wanted chip injection with `has_wanted_chip=true`.

During migration evaluate `manifest permission AND NOT legacy blacklist`; do not delete settings. Migrate one addon at a time, Wanted first. Only after every direct path is covered should directory fallback be disabled for migrated manifests.

### Minimal first change-set (future task)

1. `inc/plugins/advancedfunctionality.php`: context, normalized optional manifest frontend metadata, decision API, collector gate.
2. `inc/plugins/advancedfunctionality/addons/advancedwanted/manifest.php`: declare Wanted page/resource contexts.
3. `inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php`: route direct catalog/dependency/chip paths through decision API.
4. Tests only for the context matrix and activation smoke path.

No other addon should be edited in the first pilot. In particular leave globals, theme stylesheet sync and AdvancedJSBundle unchanged.

## H. Risks and prohibited combined changes

1. **Discovery/redeclare:** manifests are included repeatedly and XMLHTTP uses `require`, not cached metadata. Keep manifests declarative; any function/class declaration there can fatal. Bootstrap must remain `require_once`.
2. **Activation symbol timing:** addon install runs immediately after bootstrap include, before its enabled setting/reload. A resolver used by install must already be declared in core and must not assume frontend globals/header/template state.
3. **Lifecycle mismatch:** ACP enable calls `_install`, not `_activate`/`_upgrade`; AF activate calls none of them. Do not change lifecycle dispatch together with frontend routing.
4. **PHP 8.5 strictness:** duplicated constant/function declarations, invalid callback signatures passed by reference, dynamic properties and null-to-string assumptions are sensitive. Avoid new includes and keep resolver typed, side-effect-free, and tolerant of missing globals.
5. **Double pre-output:** core manually calls convention functions while addons may also register MyBB `pre_output_page`. Existing markers/dedup hide this. Do not rationalize hooks during resolver rollout.
6. **CSS has two axes:** permission and file-vs-theme delivery. Changing both together can remove all CSS or duplicate file and bundle.
7. **Fallback scan:** it indiscriminately enrolls top-level JS/CSS. Turning it off globally will break manifests that do not yet declare assets.
8. **HTML-dependent contexts:** Wanted chips, KB chips and some editor/widget cases can only be known after rendering. A request-only resolver cannot replace response predicates.
9. **AJAX/modal:** JSON requests deliberately skip pre-output. Never require the AJAX response itself to enqueue the caller runtime.
10. **Config order:** separating inline config from runtime can create race/order bugs, especially with non-deferred scripts.
11. **Global UI dependency:** Menu, AAS, AAM and Font Awesome should not be migrated alongside Wanted; a failure removes navigation/account/alert controls site-wide.
12. **Bundler last:** AdvancedJSBundle rewrites other addons' tags. Migrate it only after source ownership/permission is stable.
13. **Output buffer:** usercp/misc have shutdown fallback. A decision API must be idempotent and must not consume mutable state on first invocation.
14. **Blacklist compatibility:** several actual setting keys do not follow `af_{manifest-id}_assets_blacklist`; removal before explicit mapping silently changes behavior.
15. **No simultaneous cleanup:** do not rename ids/settings, consolidate hooks, convert templates, reorder JS, change stylesheet attachments, or add lazy loading in the resolver patch.

## Verification inventory

The audit used repository-wide searches for blacklist tokens, manifest fields, hooks, `$headerinclude`, `<script>/<link>`, queue calls, lifecycle functions and request variables, followed by direct reading of every matching core/Wanted path. The resulting architectural recommendation intentionally retains all current fallback and blacklist behavior until an addon-specific permission path is proven.
