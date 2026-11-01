# Технический аудит Advanced Editor

Дата аудита: 2026-10-05. Целевая среда из постановки: MyBB 1.8.40, PHP 8.5.0.

## Область, метод и границы проверки

Аудит выполнен статически по всему репозиторию. В поиск включены `advancededitor`,
`sceditor`/`SCEditor`, `wysiwyg`, `sourceMode`, `source`, `toolbar`, `codebuttons`,
`bbcode`, `textarea`, `iframe`, `af-ccp`, `af-ccp-bar`, `quickreply`, `quickedit`,
`editpost`, `newreply` и `newthread`. Проверены сам addon, ATF, AdvancedJSBandle
(именно так каталог назван в репозитории), Knowledge Base, AdvancedThreadFields,
шаблоны и regression tests.

Это **статический аудит**, а не браузерный E2E-аудит production-темы. В репозитории
нет работающего MyBB, БД, собранной темы и авторизованных fixtures, поэтому ниже
строго разделены подтверждённые источником факты и то, что требует проверки
computed style/DOM на staging. Функциональный код не изменялся.

## 1. Текущая архитектура

Фактическая цепочка редактора:

```text
финальный HTML с поддерживаемым textarea
  -> AdvancedEditor pre_output определяет response facts
  -> удаляет собственные старые tags и, для editor response, MyBB SCEditor assets
  -> загружает SCEditor theme CSS, core, BBCode plugin, MyBB bridge
  -> публикует afAdvancedEditorPayload / afAePayload
  -> загружает WYSIWYG BBCode bridge, затем advancededitor.js
  -> AdvancedEditor регистрирует commands/BBCode rules ДО instance init
  -> AdvancedEditor вызывает $(textarea).sceditor(options)
  -> сам SCEditor создаёт .sceditor-container, .sceditor-toolbar,
     WYSIWYG iframe и source textarea
  -> AdvancedEditor декорирует кнопки, оборачивает native toolbar только для help UI,
     внедряет idempotent styles в iframe и посылает af:editor-ready
  -> charcountandprew.js создаёт .af-ccp-wrap/.af-ccp-bar рядом с editor surface
```

Владелец instance на обычных целевых страницах — **AdvancedEditor**: вызов
`$ta.sceditor({...})` находится в `initOneTextarea()`. MyBB создаёт исходный
`textarea`/`{$codebuttons}`, но его SCEditor assets перед инициализацией удаляются
из готового HTML. Ветка `existing` позволяет принять уже существующий instance,
после чего помечает его `__afAeOwned`; это совместимость, а не второй нормальный
инициализатор.

Toolbar создаёт **SCEditor** из строки `toolbar`, которую AdvancedEditor строит из
ACP layout. Отдельного второго AdvancedEditor toolbar над textarea нет. Есть лишь
DOM-обёртка `.af-ae-toolbar-row`, в которую перемещается уже созданный
`.sceditor-toolbar`, и соседняя кнопка справки. Custom/dropdown buttons являются
SCEditor commands внутри того же toolbar.

## 2. Initialization lifecycle

### Серверная стадия

1. `manifest.php` допускает addon по response facts (`has_supported_editor`,
   `has_atf_editor`, `has_post_content`) и явно оставляет KB route aliases.
2. `af_advancededitor_pre_output()` работает только с полноценным HTML вне ACP и
   ModCP, сначала удаляет ранее вставленные assets addon, затем вычисляет facts по
   `textarea[name=message]`, `.af-kb-editor`, `.af-atf-bbcode-editor` и post content.
3. На content-only response загружаются addon/pack presentation assets; SCEditor
   stack и payload добавляются только при поддерживаемом textarea.
4. На editor response функция отключает clickable editor, удаляет типовые MyBB
   `jscripts/sceditor/*` tags и затем вставляет ровно выбранные существующие theme,
   content, core, BBCode-plugin и MyBB bridge paths. Отсутствующий файл не должен
   попадать в `<script>`; CSS resolver, однако, имеет compatibility fallback URL.
5. Pack manifests и таблица `af_ae_buttons` формируют `customDefs`; ACP JSON layout
   формирует toolbar. Payload содержит selectors, режим, exclude/whitelist,
   stylesheet URL, размеры forum scopes, fonts и preview data.

### Клиентская стадия до init

`advancededitor_wysiwyg_bbcodes.js` загружается перед `advancededitor.js`.
`boot()` ждёт доступности jQuery SCEditor до 80 раз с интервалом 50 ms. Затем
`initGlobalEditorEnvironment()` один раз:

* строит и кеширует layout/toolbar;
* регистрирует dropdown, custom, toggle и MyBB alias commands;
* регистрирует passthrough и full/partial WYSIWYG BBCode rules;
* связывает глобальную Escape-обработку help modal.

### Создание и post-init

Для ещё не инициализированного eligible textarea перед init добавляется post key,
вычисляется startup mode, затем вызывается SCEditor с `format: bbcode`, собранным
toolbar, единственным content stylesheet URL, `height: 180`, `width: 100%`, resize,
`autoExpand: false` и `startInSourceMode`.

После init AdvancedEditor:

* ставит ownership/signature flags и submit synchronization;
* по стабильным `id` один раз добавляет iframe CSS для code/quote, ATF theme и fonts;
* применяет instance BBCode rules;
* оборачивает методы переключения только для сохранения выбранного режима;
* повторно приводит instance к startup mode (см. проблему P4);
* декорирует native buttons и, при настройке help, переносит native toolbar в row;
* отправляет один `af:editor-ready` на textarea.

## 3. WYSIWYG lifecycle

SCEditor создаёт iframe при создании instance. Его document получает stylesheet из
option `style`; это URL, возвращённый content resolver (предпочтительно
`jquery.sceditor.mybb.css`). В тот же document AdvancedEditor добавляет три
раздельных `<style>` с id:

* `af-ae-local-fonts-iframe` — копия host local-font rules;
* `af-ae-atf-iframe-theme` — только при `body.atf-active`;
* `af-ae-wysiwyg-codequote` — code/quote presentation.

Каждая функция проверяет `doc.getElementById(...)`, поэтому **в одном iframe
дублирование этих style nodes не происходит**. CSS variables не передаются как
variables: четыре computed host values считываются один раз и встраиваются как
literal colors. Последующее переключение host theme их не обновляет.

ATF host CSS влияет на сам iframe element (`width`, border, background,
`box-sizing`), но не может стилизовать iframe body. Body меняют SCEditor content
stylesheet и динамический `af-ae-atf-iframe-theme`.

## 4. Source lifecycle

В source mode SCEditor показывает свой `textarea` с классом, который код ищет как
`textarea.sceditor-textarea`, с fallback на первый textarea в container. В CSS ATF
он адресован как `textarea.sceditor-source`; оба имени отражают зависимость от
конкретной SCEditor build/DOM conventions и должны быть сверены на staging.

Custom insertion сначала проверяет `sourceMode()`. В source mode BBCode вставляется
непосредственно по `selectionStart/selectionEnd`, textarea фокусируется, caret
ставится после вставки и отправляется bubbling `input`. Для submit на `editpost`
есть дополнительный capture-phase sync: `updateOriginal()`, затем fallback из
instance в исходный textarea и BBCode normalizers.

Source textarea получает host ATF rules: `box-sizing: border-box`, `width: 100%`,
нулевой border, background/text/caret и нижние radii. В AdvancedEditor CSS нет
правила, добавляющего source textarea padding/margin.

## 5. Mode switching

Фактический цикл `textarea -> WYSIWYG -> Source -> WYSIWYG -> Source` проходит
внутри **того же SCEditor instance**:

1. Исходный MyBB textarea скрывается SCEditor и остаётся backing field.
2. В WYSIWYG видим iframe; source editor скрыт.
3. `toggleSourceMode()`/`sourceMode(true)` сериализует WYSIWYG через BBCode plugin и
   показывает source textarea.
4. `sourceMode(false)` парсит source в iframe и снова показывает уже созданный
   iframe.
5. Повторение не вызывает `initOneTextarea()`: guards `$ta.data('sceditor')` и
   `__afAeInited` прекращают повторный init.

AdvancedEditor не создаёт wrappers, toolbar или iframe на mode switch. Он monkey-
patches `toggleSourceMode()` и `sourceMode()` только для записи mode в localStorage;
вызовы делегируются оригиналам. `afAeSafeToggleMode()` также только вызывает native
`toggleSourceMode()`.

Следствие: ATF iframe style не накапливается при switch. Он остаётся в том же
document и снова становится видимым. Поэтому визуально padding «возвращается» при
Source -> WYSIWYG, но не складывается арифметически на каждом переключении.

Selection/caret не сохраняется самим AdvancedEditor вокруг mode switch: это
оставлено native SCEditor. Custom source insertion сохраняет только source caret.
Ни один repository test не доказывает round-trip selection, dimensions или complex
custom BBCode в реальном браузере — это обязательная staging-проверка.

## 6. Toolbar architecture

| Категория | Источник | Реализация |
|---|---|---|
| Native SCEditor | список `available`, ACP layout | имена native commands в option `toolbar` |
| MyBB aliases | `ensureMybbTagAliases()` | SCEditor commands для `horizontalrule`, sub/sup |
| AdvancedEditor DB button | `af_ae_buttons` | `customDefs`, SCEditor `command.set`, open/close/handler |
| Pack button | рекурсивные pack manifests | объединяется в `customDefs`/available и тот же toolbar |
| Dropdown | ACP section type `dropdown` | синтетический `af_menu_dropdownN`, SCEditor dropdown |
| Mode toggle | `source` native либо optional `af_togglemode` | native command либо thin wrapper над native toggle |
| Help edge | format-help setting | единственный не-SCEditor button рядом с перемещённым native toolbar |

Следов отдельного полноразмерного AE toolbar поверх textarea нет. Однако MyBB
templates всё ещё могут выводить `{$codebuttons}`: AdvancedEditor подавляет его
runtime через setting/assets manipulation, а не удаляет template placeholder.
Поэтому production DOM следует проверить на остаточный legacy markup, особенно при
нестандартной теме; по репозиторию второй активный instance не подтверждён.

## 7. Custom BBCode architecture

Есть две серверные коллекции: legacy/root pack scanner и фактически используемый
recursive discovery (`assets/bbcodes/<pack>` и `assets/bbcodes/bbcodes/<pack>`), плюс
активные DB rows. Manifest задаёт assets, tags и buttons. PHP install/uninstall
регистрирует MyCodes для server parser; client payload несёт определения buttons.

До instance init `advancededitor_wysiwyg_bbcodes.js` собирает tags из customDefs,
pack metadata и available commands. В full mode создаются WYSIWYG conversion rules;
в partial mode whitelist визуализируется, остальные сложные tags сохраняются как
placeholder/text representation; excluded tags не преобразуются. Второй слой
`afAeEnsureMycodePassthroughBbcode()` добавляет placeholder conversion только если
правило для tag ещё отсутствует. Guards предотвращают перезапись существующих rules.

Pack handler вызывается первым, если объявлен. Иначе source получает настоящий
MyCode, WYSIWYG — `insert()`/placeholder conversion. Именно round-trip неизвестных,
вложенных, attribute-heavy и self-closing tags является главным migration gate.

## 8. Character counter

`charcountandprew.js` создаёт `.af-ccp-wrap`, `.af-ccp-bar` и preview **перед**
`.sceditor-container` либо textarea. Guard ищет непосредственный `.af-ccp-wrap` у
того же parent, поэтому повторные ready-time вызовы обычно не дублируют UI.

Источник текста — `inst.val()` независимо от текущего режима, с fallback на
исходный textarea. Это даёт BBCode serialization и для WYSIWYG. При выключенном
`countBbcode` простая regexp удаляет tags (не полноценный parser). Обновление идёт
от input/keyup/paste/cut исходного textarea и polling каждые 600 ms; polling —
фактический механизм обновления в WYSIWYG, где исходный textarea не получает все
редакторские события.

На старте функция запускается сразу, через 300 и 900 ms. Для AJAX quick edit она
также слушает `af:editor-ready`. Здесь есть дефект жизненного цикла: если UI уже
существует, повторный вызов восстанавливает его глобальным
`document.querySelector('.af-ccp-wrap')`, а не относительно текущего textarea.
При нескольких editor surfaces counter может связаться с первым UI. Каждый
успешный повторный init также создаёт новый `setInterval`; marker «counter already
bound to this textarea» отсутствует. Для одного обычного editor UI не дублируется,
но handlers/pollers могут дублироваться.

Published-post legacy counter отдельно добавляется под `.post_body`, но пропускает
ATF posts с `.atf-post__meta-line` и уже существующий `.af-ccp-postcount`.

## 9. CSS ownership

### Host document

* SCEditor theme CSS владеет базовым container/toolbar/button/iframe/source box
  model и native inline dimensions.
* `advancededitor.css` владеет dropdowns, custom icons, toolbar row/help UI. Он
  задаёт dropdown/menu padding и margin, но не padding iframe/body/editor surface.
* ATF задаёт card padding формы, container border/radius, toolbar `.4rem` padding,
  group/button padding, а iframe/source — full width, border zero и box sizing.
* Общие ATF form rules также охватывают `textarea`, но более специфичные SCEditor
  selectors управляют generated source surface.
* SCEditor init задаёт `height: 180`, `width: 100%`; библиотека превращает это в
  inline geometry container/iframe/source и переключает `display` internally.

### Iframe document

Подтверждённый источник текущего внутреннего отступа — конкретная операция в
`afAeApplyWysiwygAtfTheme()`:

```js
style.textContent = '...body{box-sizing:border-box;padding:.75rem}...';
```

Этот rule вставляется как `<style id="af-ae-atf-iframe-theme">` после получения
iframe document и потому участвует в cascade вместе с `jquery.sceditor.mybb.css`.
Он появился в commit `8c1fd395` вместе с ATF iframe theme sync. Source textarea
такого rule не получает. Это полностью объясняет именно разницу «лишний внутренний
отступ есть/снова виден в WYSIWYG, но отсутствует в Source».

Не подтверждено без runtime computed style, имеется ли дополнительный native body
padding в фактически установленном `jquery.sceditor.mybb.css`: сам файл MyBB не
входит в репозиторий. Но даже если он равен нулю, динамический `.75rem` безусловно
даёт 12 px при root font-size 16 px. CSS padding не «суммируется» от повторной
инъекции: id guard допускает один ATF style на iframe document.

## 10. Asset pipeline

Порядок на editor response:

1. local fonts link;
2. AdvancedEditor CSS и pack CSS;
3. deferred pack JS;
4. inline basic cfg;
5. SCEditor theme CSS;
6. synchronous SCEditor core, BBCode plugin, MyBB bridge;
7. final full payload aliases;
8. deferred WYSIWYG bridge;
9. deferred AdvancedEditor runtime.

`af_advancededitor_strip_own_assets()` делает reinjection idempotent в одном final
HTML. `af_advancededitor_strip_mybb_sceditor_assets()` удаляет типовые дубликаты
core SCEditor. Content stylesheet **не добавляется в host**: его URL передаётся
SCEditor в `style`, что предотвращает прежнюю ошибку comma-joined CSS URL.
Resolver проверяет `jquery.sceditor.mybb.css`, затем default variants; fallback
сохраняется для совместимости, но отсутствующий JS path не эмитится.

Theme stylesheet delivery может вернуть пустой URL для pack CSS, когда тот уже
включён в собранный `advancedstyles.css`; комментарий прямо предотвращает повторную
эмиссию bundle на каждый pack. AdvancedJSBandle исполняется последним transformer,
но преобразует только собственное namespace: inline config, SCEditor и
AdvancedEditor assets он не переносит и не bundle-ит. Подтверждённого duplicate
load текущим pipeline не найдено.

Риск порядка остаётся для стороннего plugin/theme, который вставляет нестандартный
SCEditor tag, не совпадающий с strip regex, либо inline `$(...).sceditor()` после
AdvancedEditor: статический repository search такого frontend конкурента на
целевых страницах не обнаружил. `advancedrules/admin.php` создаёт независимые ACP
instances и не попадает в frontend scope.

## 11. Quick Reply

ATF `showthread_quickreply` сохраняет `#quick_reply_form` и `textarea#message`, но
не выводит `{$codebuttons}`. Финальный showthread содержит и post content, и
поддерживаемый textarea, поэтому получает полный stack. ATF стилизует form/card,
toolbar и обе mode surfaces; counter вставляется перед editor. Multi-quote работает
через исходные MyBB id. Это отдельный контекст, а не доказательство full forms.

## 12. Quick Edit

Quick Edit появляется AJAX-динамически внутри `.atf-post__content`. Global
MutationObserver замечает added textarea и запускает шесть scans по 90 ms; click
hint параллельно запускает шесть document scans по 110 ms. Instance guard обычно
предотвращает второе создание. После ready form получает `.atf-quick-edit`, а
counter получает событие с `quickEdit: true`.

При удалении subtree observer вызывает destroy для найденных textarea. Риск:
SCEditor переносит/скрывает исходный textarea относительно generated container;
следует браузерно доказать, что removed subtree всегда содержит backing textarea и
destroy успевает до потери ссылки. Counter intervals не останавливаются при
destroy/removal. Quick-edit submit также не получает специальный `editpost` guard;
он полагается на SCEditor/MyBB synchronization.

## 13. Full editors

* **New Reply / New Thread / Full Edit:** `textarea[name=message]` проходит response
  fact и selector. Full Edit дополнительно получает capture sync/empty guards.
* **Preview -> возврат:** server-rendered preview page снова проходит full
  `pre_output`; старые injected tags удаляются из строки ответа и создаётся новый
  browser document/instance. Содержимое приходит из server-rendered textarea.
* **KB / AdvancedThreadFields:** opt-in classes добавлены в selector. ATF fields не
  запускают конкурирующий MyBB editor. KB может динамически добавлять marked
  textarea; observer поддерживает это.
* **PM message textarea:** response detection допускает `name=message`, хотя это не
  перечислено в исходном минимальном matrix; recipient textareas не выбираются,
  потому что payload selector ограничен message/marked editors.
* **ModCP:** AdvancedEditor сам исключает `IN_MODCP`; SCEditor там остаётся вне этого
  lifecycle.

Ни одна из страниц не была интерактивно исполнена в рамках static audit. Обязательная
acceptance matrix: каждый из Quick Reply, New Reply, New Thread, Full Edit, Quick
Edit и Preview return × startup Source/WYSIWYG × два полных switch round-trip.

## 14. Найденные проблемы

| ID | Статус | Проблема | Влияние |
|---|---|---|---|
| P1 | подтверждено | iframe ATF style явно задаёт `body padding:.75rem` | WYSIWYG имеет дополнительный внутренний отступ; Source — нет |
| P2 | подтверждено | counter init не имеет per-textarea bound guard и создаёт interval при повторном вызове | лишние handlers/polling; multi-editor cross-binding |
| P3 | подтверждено | counter fallback ищет первый `.af-ccp-wrap` глобально | неверный counter/preview при нескольких editors |
| P4 | подтверждено | mode задаётся и `startInSourceMode`, и сразу post-init через `sourceMode(...)` | избыточный initial transition; риск selection/serialization до interaction |
| P5 | подтверждено | remember hooks monkey-patch одновременно `toggleSourceMode` и `sourceMode` | одно переключение может записать mode несколько раз; не меняет DOM само по себе |
| P6 | подтверждено | два retry-контура quick edit плюс MutationObserver | много scans, хотя instance guards предотвращают обычный double init |
| P7 | подтверждено | removed editor уничтожается, counter interval не уничтожается | утечка polling после Quick Edit replacement |
| P8 | подтверждено | iframe colors — snapshot computed values, не live variables | смена ATF theme после init не синхронизируется |
| P9 | требует staging | CSS использует `sceditor-source`, JS сначала ищет `sceditor-textarea` | возможная build-specific несогласованность selector |
| P10 | требует staging | нестандартные theme/plugin inline initializers могут пережить asset stripping | потенциальный второй instance/legacy toolbar только вне видимого repo contract |

P2–P8 — технический долг, но не причина текущего padding. В рамках задачи они не
исправлялись.

## 15. Root causes

### Подтверждённая причина регрессии отступа

Commit `8c1fd395` добавил `afAeApplyWysiwygAtfTheme()`. Функция инъецирует в iframe
rule `body{box-sizing:border-box;padding:.75rem}`. Она вызывается post-init и для
нового, и для принятого existing instance. На switch iframe не пересоздаётся и
style не удаляется: Source скрывает iframe, а возврат в WYSIWYG снова показывает
body с тем же padding. Это локальная регрессия, не накопление wrappers/styles.

### Предлагаемый patch (не применён)

```diff
--- a/inc/plugins/advancedfunctionality/addons/advancededitor/assets/advancededitor.js
+++ b/inc/plugins/advancedfunctionality/addons/advancededitor/assets/advancededitor.js
@@
-        + ';caret-color:' + accent + ';font:inherit}body{box-sizing:border-box;padding:.75rem}'
+        + ';caret-color:' + accent + ';font:inherit}body{box-sizing:border-box}'
```

Patch удаляет только presentation declaration из iframe. Он не трогает instance,
`sourceMode()`, serializers, selection, toolbar, custom rules, counter, dimensions,
handlers или init order. Поэтому он не меняет Source/WYSIWYG lifecycle. Перед
применением всё равно нужно снять staging computed styles iframe `html/body` и
сравнить ожидаемый native SCEditor inset: если product design требует один inset,
безопаснее явно выбрать единственного owner вместо слепого удаления.

## 16. Технический долг

* Клиентский runtime совмещает discovery, commands, conversion, lifecycle, submit
  guards, DOM decoration и theme injection в одном файле.
* Есть два механизма discovery pack metadata в PHP, что затрудняет доказательство
  единственного source of truth.
* Lifecycle динамических editors построен на observer + polling/retries, без общего
  dispose contract для dependent features.
* Mode persistence достигается monkey-patch публичных методов, а не документированным
  событием SCEditor.
* CSS ownership разделён между SCEditor stylesheet, AE, ATF host и iframe injection;
  формальной property matrix до этого документа не было.
* Static string regression tests подтверждают наличие кода, но не round-trip
  content/caret, actual display/inline geometry или computed cascade.

## 17. Варианты дальнейшей оптимизации

### Вариант A — локальная стабилизация без смены lifecycle

**Меняется:** после staging snapshot удалить/заменить только конфликтующий iframe
padding; добавить browser matrix и diagnostics/assertions на один instance/style/UI.

**Остаётся:** AdvancedEditor owner, текущий init order, native toolbar generation,
observer/retries, source/WYSIWYG switching, pack handlers, counter architecture.

**Риски:** низкие; сохраняются P2–P8. **Switching:** не затрагивается. **Quick
Edit:** не затрагивается. **Custom BBCode:** не затрагивается. **Миграция:** малая.
**Вероятность регрессии:** низкая после visual/E2E matrix. **Удалять после
миграции:** ничего, кроме конкретного `padding` declaration, если snapshot это
подтвердит.

### Вариант B — единый lifecycle coordinator при сохранении SCEditor

**Меняется:** в отдельной миграции ввести per-textarea state (`init`, `ready`,
`dispose`), один scheduler и hooks mode/ready/destroy; counter/theme/decorators
подключить как idempotent features к coordinator.

**Остаётся:** SCEditor instance и native mode switch, toolbar layout/commands,
conversion rules, PHP payload и server MyCodes.

**Риски:** средние: ошибка dispose или event ordering особенно опасна для Quick
Edit. **Switching:** должен оставаться native; coordinator только наблюдает.
**Quick Edit:** улучшается, но это главный migration risk. **Custom BBCode:** API
оставить прежним. **Миграция:** средняя. **Вероятность регрессии:** средняя.
**Удалять только после parity:** duplicate scheduleScan loops, counter intervals,
method monkey-patches и scattered `__af*` flags.

### Вариант C — MyBB/SCEditor как единственный bootstrap owner

**Меняется:** MyBB создаёт instance стандартным `codebuttons`; AdvancedEditor до
init регистрирует extensions и после documented ready только расширяет instance.
Server asset stripping/ручной bootstrap постепенно удаляется.

**Остаётся:** SCEditor, native toolbar DOM, custom commands/BBCode semantics,
counter как feature.

**Риски:** высокие: MyBB page contexts и Quick Edit инициализируются неодинаково;
toolbar options должны попасть до core init. **Switching:** native, но startup mode
и content stylesheet требуют нового контракта. **Quick Edit:** высокий риск.
**Custom BBCode:** rules обязаны регистрироваться до parse первого value.
**Миграция:** высокая. **Вероятность регрессии:** высокая. **Удалять только после
полной parity:** `strip_mybb_sceditor_assets`, ручную загрузку SCEditor, `$ta.sceditor`
owner path, compatibility existing-instance branch и retry bootstrap.

## 18. Рекомендуемый вариант

Сейчас безопасен **Вариант A**: подтвердить iframe computed styles на staging,
применить отдельным изменением минимальный diff из раздела 15 и прогнать полный
matrix. Он устраняет доказанный regression source, не касается критического mode
switching и не смешивает bug fix с оптимизацией.

После стабилизации разумный целевой вариант — **B**, но только как отдельная
поэтапная миграция с browser tests и feature flags. Вариант C не рекомендуется как
первый шаг: он меняет ownership/init order, то есть именно области, которые задача
запрещает менять без отдельной миграции.

## 19. Что категорически нельзя менять без отдельной миграции

1. Владельца и момент создания SCEditor instance.
2. Порядок core -> BBCode plugin -> MyBB bridge -> WYSIWYG rules -> AE runtime.
3. Native `toggleSourceMode()`/`sourceMode()` и SCEditor conversion path.
4. Формат ACP toolbar layout, имена commands и `af_menu_dropdownN`.
5. Pack/DB `customDefs`, server MyCode installation и handler API.
6. `textarea[name=message]`, KB/ATF opt-in selectors и Quick Edit observer contract.
7. Backing textarea submit synchronization, особенно Full Edit.
8. Startup/remembered mode semantics до тестов первого render и двух round trips.
9. Размеры `180 × 100%`, resize и generated inline styles без page-by-page matrix.
10. Counter source (`inst.val()`), preview interception и published counter guard.
11. CSS ownership iframe body/host surface без снятых computed-style snapshots.
12. Asset strip/load order и AdvancedJSBandle-last contract.

## Итоговая классификация

### Подтверждённые факты

* AdvancedEditor является фактическим owner SCEditor instance и конфигурации
  единственного native toolbar.
* Mode switch делегирован SCEditor; AE не пересоздаёт instance при переключении.
* Динамические iframe styles имеют стабильные id и не дублируются в одном document.
* Текущий WYSIWYG padding непосредственно задан `body{padding:.75rem}` в ATF iframe
  style, добавленном последним изменением editor theme sync.
* MutationObserver, retries, polling counter и прямые post-init DOM/CSS изменения
  существуют; обычный repeated scan защищён instance flags.

### Предположения / требующие runtime-проверки

* Итоговое числовое значение всех computed paddings/margins с production theme и
  установленным MyBB content CSS.
* Сохранение caret/selection native SCEditor для всех custom tags на двух round
  trips.
* Отсутствие нестандартного legacy inline initializer в production template/DB.
* Точная teardown-последовательность AJAX Quick Edit и отсутствие detached polling.

### Варианты оптимизации

* A: локально убрать единственный конфликтующий presentation rule после snapshot.
* B: затем мигрировать к coordinator, сохранив native SCEditor lifecycle.
* C: передать bootstrap MyBB — только как наиболее рискованный отдельный проект.
