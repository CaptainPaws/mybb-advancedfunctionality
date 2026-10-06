# AdvancedEditor: исправления lifecycle, BBCode и FIMP

Изменения подготовлены для `main` репозитория `CaptainPaws/mybb-advancedfunctionality`. Базовый commit: `65eb724b90446a2293676adf867c11faa2b98350`. Ядро MyBB не изменялось. Тяжёлый WYSIWYG runtime и toolbar tools сохраняют загрузку по запросу.

**Что показала диагностика исходного кода.** В SCEditor 3.2.1, поставляемом с MyBB 1.8.40, внутренние `iframe` и Source `textarea` не имеют классов, на которые рассчитывал AdvancedEditor. При широком `editorSelector = textarea` shell `MutationObserver → scan → init` принимал внутреннее поле за новый редактор и создавал вокруг него второй Source shell/adapter и toolbar. Дополнительное поле получало собственную KB-кнопку из скопированной toolbar. Lazy-инициализация одновременно временно снимала и возвращала `sceditor-container` на внешнем shell, а флаг `__afAeInited` безусловно сбрасывался.

Важно различать реальные SCEditor instances и вложенные Source shells. В воспроизведённом исходном сценарии было **одно native SCEditor instance**, но **два shell/adapter и три toolbar roots**. Гипотеза о нескольких native SCEditor instances на одном исходном textarea этим snapshot не подтвердилась. Это были функционально вложенные редакторные UI, а не доказанное повторное создание native instance. Текущая защита закрывает и повторный вызов native initialization.

| Исходный сценарий с широким selector | До WYSIWYG | Первый switch | Пятый switch |
| --- | ---: | ---: | ---: |
| Логические shell/adapter | 1 | 2 | 2 |
| Native SCEditor instances | 0 | 1 | 1 |
| `.sceditor-container`, включая ошибочно помеченные shells | 1 | 3 | 3 |
| Toolbar roots | 1 | 3 | 3 |
| KB-кнопки | 1 | 2 | 2 |
| Все textarea | 1 | 2 | 2 |
| Высота внешнего shell, px | 225 | 250 | 250 |

В исходном замере использован реальный MyBB stylesheet `mybb.css`, также предоставленный старому resolver по его fallback-пути `default.min.css`. Selector специально расширен для проверки классификации внутренних полей. Это локальный воспроизводимый сценарий, а не замер production DOM warprift.ru.

**Исправленный lifecycle.** Внешний `.af-ae-shell` больше не является `.sceditor-container`. Внутренние поля исключаются по принадлежности native container и получают `data-af-ae-skip` через API instance до обработки MutationObserver. Редактор проходит состояния `uninitialized → initializing → initialized → destroyed`; повторный init возвращает существующий instance. Source/WYSIWYG используют штатный переключатель этого instance. Удалены искусственные переключения `forceRender()` при initialization. `af:editor-ready` уведомляет о Source shell и единственном lazy-апгрейде до native редактора; переключения режима не запускают bootstrap. Legacy discovery observer WYSIWYG runtime не включается: обнаружением динамических оригинальных полей владеет shell.

Оригинальное textarea остаётся полем отправки формы. SCEditor один раз создаёт собственное Source view и iframe. После activation всего два textarea: исходное поле данных и единственное внутреннее Source view. Их число не растёт при переключениях. В новом browser-сценарии до activation: 1 shell, 0 native widgets, 1 toolbar, 1 KB; после activation и десяти переключений: 1 shell, 1 native widget, 1 toolbar, 1 KB. Высота shell остаётся **260 px** во всех режимах этого fixture. Instance и iframe сохраняют identity. Начальный размер берётся с исходной поверхности; native resize сохраняется. Добавлены совместимые API aliases для SCEditor 3 (`getContainer`, `getSourceEditor`, `resizeTo → dimensions`).

**Цитаты и alignment.**

- `defaultattr=undefined` появлялся в парсере SCEditor: обработка `=""` перед именованным `pid` использовала `unquote(empty) || fallback`, записывая `undefined`. Native serializer затем выводил этот `defaultattr` как именованный атрибут. Исправлены token boundary и структурная сериализация внутри addon. Реальное отсутствие значения пропускается; явный пустой автор MyBB сохраняется. Очистки готового BBCode регуляркой нет.
- Старый универсальный bridge обращался к `plugins.bbcode.bbcode`, тогда как MyBB 1.8.40 использует `formats.bbcode`. Поэтому требуемое сохранение quote metadata не подключалось. Штатный quote converter сохранял только автора из `cite`/`data-author` и терял `pid`/`dateline`. Новый converter хранит исходные атрибуты в `data-af-quote-attrs`, отображает отдельный header и сериализует metadata обратно. Нативные форматы обычного BBCode больше не перезаписываются универсальными definitions.
- В quote stylesheet копировался вычисленный `text-align` темы. Alignment converter принимал оформление за содержимое документа; mappings через `styles` и `tags` могли обработать один элемент дважды. Старые regex-normalizers пытались сворачивать последствия. Теперь alignment определяется явной DOM metadata и сериализуется одним mapping; исходная форма `[left]`, `[center]`, `[right]`, `[justify]` сохраняется. Копирование theme `text-align` и regex-сворачивание BBCode удалены.
- В исходном воспроизведении quote терял `pid`; при CSS `.mycode_quote{text-align:left}` самопроизвольно добавлялся `[align=left]`. Точную строку `[left][left]` этот snapshot не воспроизвёл: его существующий normalizer уже сворачивал часть вложенности. Исправлена причина появления неявного alignment, а не конкретная строка.
- Native SCEditor кеширует DOM converters при создании format. После загрузки lazy pack этот кеш обновляется без создания нового editor и без конвертации документа. Отдельно проверена таблица, впервые загруженная после WYSIWYG activation.
- Quick Quote не находил автора в текущем ATF `.atf-post__name`; добавлен этот selector и актуальный message body. Для пустого автора в preview сервер получает имя и мини-аватар по `pid`, проверяя видимость поста/темы, forum permissions, own-thread restrictions и пароли форумов. Кодовые примеры не изменяются. Автор доступного поста не хардкодится. HTML получает `data-pid`/`data-author`, мини-аватар и корректное имя через штатный MyBB parser.

**Popup, KB, fullscreen и опубликованный контент.**

- Formatting Help полностью исключён из toolbar registry rendering. Отдельный trigger расположен в нижней `af-ccp-bar`. Popup имеет собственные ограниченную ширину, padding, фон, border, radius, shadow и прокрутку.
- Popup обрезались `overflow:hidden` на вложенном native container и оставались в редакторном stacking context. Единственный принадлежащий shell popup root теперь находится в `body`, позиционируется от актуального trigger и использует browser top layer. Он закрывается при смене режима и cleanup; scroll/resize перепозиционируют его. Extra menu использует тот же механизм, а действие из меню получает видимый toolbar trigger после закрытия меню.
- KB дублировался как часть второго ошибочно созданного shell; кроме того, KB integration имела собственный wrapper initialization и механизмы добавления toolbar buttons. При управлении AdvancedEditor этот дополнительный SCEditor wrapper отключён; кнопкой владеет compiled shell registry. Native toolbar создаётся вне live DOM через `toolbarContainer`, а не скрывается CSS.
- Fullscreen зависел от native container CSS на внешнем shell, ошибочной вложенности, selectors отсутствующих классов внутренних поверхностей и вызова отсутствующего в SCEditor 3 `resizeTo`. Новый fullscreen поднимает существующий shell в top layer без переноса iframe/потери его document. `position:fixed; inset:0`, flex editing area, toolbar и нижняя панель сохраняются. Body scroll блокируется, а выход сохраняет форму, место, размер, режим и текст.
- Spoiler view runtime прежде подключался только по markup первоначального ответа и привязывал listeners к уже существовавшим nodes. Первый spoiler из AJAX не получал runtime; обновлённый post не получал listeners. Добавлен небольшой content capability observer, загружающий только `_view` assets при появлении опубликованного markup. Spoiler использует event delegation, включая несколько/nested spoilers, клавиатуру и preview. Тяжёлый editor pack для опубликованного spoiler не загружается.

**FIMP.** Сам FIMP не содержал `currentAction = buttons[0]`, но скрытый native `select[name=action]` сохранял автоматически выбранный первый option — `multimergeposts`. `disabled` на созданной кнопке не изменял значение select. Теперь select получает нейтральный option, `currentAction` начинается с `null`, сбрасывается при изменении selection, disabled/zero-selection кнопки игнорируются. Только явный допустимый click устанавливает точный native action и вызывает `requestSubmit`. Submit без выбранного action блокируется. Проверены delete, merge, approve/unapprove, move/split и reset. Дублированные native forms по-прежнему не выбираются произвольно.

**Изменённые файлы** (пути внутри `inc/plugins/advancedfunctionality/addons/`, если не указано иначе):

- `advancededitor/advancededitor.php`, `advancededitor/shell.php`, новый `advancededitor/quote_metadata.php`.
- `advancededitor/assets/advancededitor.js`, `advancededitor/assets/advancededitor_shell.js`, `advancededitor/assets/advancededitor_shell.css`, `advancededitor/assets/advancededitor_wysiwyg_bbcodes.js`, новый `advancededitor/assets/advancededitor_content.js`.
- `advancededitor/assets/bbcodes/bbcodes/align/align.js`, `advancededitor/assets/bbcodes/bbcodes/charcountandprew/charcountandprew.js`, `advancededitor/assets/bbcodes/bbcodes/spoiler/spoiler_view.js`.
- `advancedjsbandle/assets/af_quickquote.js`, `advancedjsbandle/assets/quote-avatars.js`, `advancedjsbandle/assets/fimp.js`, `advancedjsbandle/manifest.php`.
- `knowledgebase/assets/knowledgebase_insert.js`.
- `tests/fixtures/advancededitor_showthread.php`, `tests/advancededitor_feature_lazy_regression.php`, `tests/advancededitor_shell_single_toolbar_drafts_regression.php`, `tests/atf_fimp_inline_moderation_regression.php`.
- Новые `tests/advancededitor_lifecycle_browser.cjs`, `tests/advancededitor_quote_metadata_regression.php`.
- `docs/advancededitor-feature-lazy.md`, этот Summary.

**Проверки и границы результата.**

Chromium использовал настоящие JS/CSS MyBB 1.8.40 и production addon code с локальной MyBB/DB/HTTP boundary. Проверены A–F, H–J из задания: десять переключений, popup/fonts/help, одна KB, визуальная toolbar alignment action, lossless quote/quote+alignment, real content edit и отправляемый payload, fullscreen/выход/mode switch, опубликованный AJAX spoiler, extra menu и его lazy action. Проверены Full Reply original node, Quick Edit Save/Cancel/reopen и cleanup WYSIWYG/fullscreen/popup. Проверены resize и исходное поле со старым классом `sceditor-textarea`.

G проверен двумя способами: browser AJAX preview с HTTP fixture и actual MyBB `postParser` в PHP-тесте с DB/permission boundary. I дополнительно проверен actual MyBB quote parsing вместе с production spoiler server parser: несколько spoilers внутри quote выводят свёрнутый HTML. K проверен на native-shaped moderation form, включая точный action отправляемого submit. Авторизация модератора и реальные destructive server actions не имитируются как production E2E.

Оба browser suites прошли без JS page errors. Исходный feature-lazy suite проверяет также таблицы, colors, fonts, stickers, KB insertion, drafts, preview, Unicode/caret, capability failure/retry, dependency cycles и отсутствие eager SCEditor/tool runtime. На холодной странице остаются четыре JS-запроса: jQuery, shell, маленький content loader и post-count view; тяжёлые runtime загружаются после actions.

PHP suite: baseline **114 passed / 14 failed**, исправленный код **117 passed / 12 failed**, новых failures нет. Исправлены также исходные padding regression и интерполированная строка существующего FIMP contract test. Остальные failures относятся к прежним ATF/buddylist/menu/responsive-layout/theme-helper проблемам. Syntax checks изменённых PHP/JS прошли.

Доступная PHP среда — **8.4.24**, а не 8.5.0. Проверка PHP 8.5 на warprift.ru, production theme parity, настоящий authenticated preview/publish и выполнение moderation actions на сервере не проведены: в среде нет установленного форума с DB и авторизованной production-сессии. Commit в `main` не является deployment форума.

Запуск browser regressions после получения MyBB `jscripts/` и, для native parser проверки, `inc/class_parser.php`:

```sh
php tests/fixtures/advancededitor_showthread.php > /tmp/advancededitor.html
node tests/advancededitor_lifecycle_browser.cjs --html /tmp/advancededitor.html --mybb-assets /path/to/mybb
node tests/advancededitor_feature_lazy_browser.cjs --html /tmp/advancededitor.html --mybb-assets /path/to/mybb
php tests/advancededitor_quote_metadata_regression.php /path/to/mybb
```
