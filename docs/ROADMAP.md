# ROADMAP AdvancedFunctionality

**Дата актуальности:** 2026-11-04.  
**Основание:** [CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), [ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), [ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md), [CONTRACT_COMPLIANCE](audits/CONTRACT_COMPLIANCE.md).

## Текущее состояние

Ядро предоставляет рабочую инфраструктуру аддонов, а проверка безопасности ядра не выявила подтверждённых замечаний по SQL, XSS, CSRF, правам ACP и загрузке файлов ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строки 7–21). Статический аудит завершён для всех **35 аддонов**: 31 неэталонного и четырёх эталонных; среди неэталонных 6 получили `ok`, 25 — `tech-debt` ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 7–261; [MODULES](MODULES.md), строки 6–8). Статус «эталон» обозначает архитектурную роль и не отменяет техдолг `advancedmenu` и `charactersheets`.

Основной подтверждённый долг сосредоточен в производительности frontend/XMLHTTP, lifecycle standalone-страниц, межаддонных зависимостях, отдельных проблемах безопасности и расхождениях с контрактами. Приоритет исправления: **blocker → high → medium → low**. Динамическая проверка MyBB/БД, HTTP-сценариев и PHP lint в аудитах не выполнялась, поэтому закрытие пунктов требует отдельной runtime-проверки ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строка 46; [ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 5).

Для ACS Stage 4 Revision 2 backend fixtures и локальная интеграционная матрица пройдены, но installed MyBB 1.8.41/MySQL 8.0.46/addons acceptance не выполнена; публикация остаётся `publication_enabled=false`, outbox consumers отключены ([ACS_STAGE_4_REVISION_2_AUDIT](audits/ACS_STAGE_4_REVISION_2_AUDIT.md), строки 9–22; [ACS_4_5_R2_INTEGRATION_ACCEPTANCE](design/acs/ACS_4_5_R2_INTEGRATION_ACCEPTANCE.md), строки 84–102). Следующий gate — installed-приёмка и явное решение администратора, а не автоматический переход к этапу 5.

## 1. Техдолг по направлениям

### 1.1. Безопасность и сохранность файлов/данных

- **high — хранимый HTML AdvancedPostCounter.** `postbit_label_html` выводится после неполной substring-фильтрации; требуется безопасная whitelist-санитизация либо отказ от произвольного HTML ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 113).
- **medium — TLS SmartUrlTitles.** При отсутствии CA bundle проверка сертификата отключается; небезопасный fallback следует устранить или сделать явно запрещённым ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 252).
- **medium — ownership задачи AAM.** Installer перезаписывает отличающийся `inc/tasks/af_aam_cleanup.php` без ownership marker; требуется защита чужого target по образцу owned aliases ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 20).
- **medium — raw image/background URL.** AdvancedAppearance допускает значения вопреки ограничению контракта; необходимо выбрать и последовательно реализовать разрешённую media-модель ([CONTRACT_COMPLIANCE](audits/CONTRACT_COMPLIANCE.md), строки 38 и 59).
- **Долг безопасности ядра не найден.** Подтверждённые механизмы SQL/XSS/CSRF/ACP/upload следует сохранять при рефакторинге ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строки 7–21).

### 1.2. Производительность

- **high — CSS delivery N+1.** Батчить цепочки тем, registry и stylesheets, memoize delivery decisions вместо повторных запросов на каждый CSS-источник ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строка 29).
- **high — повторный финальный CSS-проход.** Передавать уже рассчитанную delivery map либо объединять выборки вместо повторного чтения registry/stylesheets ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строка 30).
- **medium — повторный discovery.** Ввести request-local cache манифестов и явную инвалидацию постоянного кэша при активации/deploy ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строка 31).
- **medium — генерация языков на каждом запросе.** Перенести синхронизацию в install/activate/deploy или запускать по подписи manifest ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строка 32).
- **medium — полный файловый обход templates/CSS.** Перенести sync в activate/deploy либо использовать TTL/общую deploy-подпись ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строка 33).
- **medium — XMLHTTP загружает все аддоны.** Сначала определять action, затем загружать только владельца через route map и устранить повторные discovery ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строка 34).
- **low — многократные HTML-проходы.** Объединять финальные обходы и ограничивать их полноценными HTML-документами ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строка 35).

Новых замечаний по производительности дозаполненный аудит аддонов не подтвердил.

### 1.3. PHP 8.5

- **low — динамические свойства `$lang`.** Подтвердить механизм `MyLanguage` на целевой установке либо заменить запись динамических свойств объявленным контейнером; определение `MyLanguage` в репозитории отсутствует, поэтому факт deprecation не подтверждён ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строки 44–50).
- **Проверочный пробел:** выполнить PHP lint и runtime-сценарии на PHP 8.5; статический аудит не заменял эти проверки ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строка 46; [ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 5).

### 1.4. Контракты и зависимости

- **high — собственный Canvas/RAF ElementTheme.** Привести runtime к запрету контракта либо официально пересмотреть контракт; pause/reduced-motion не отменяют расхождение ([CONTRACT_COMPLIANCE](audits/CONTRACT_COMPLIANCE.md), строки 12 и 55).
- **high — Origin Variant `percent`.** Устранить режим, не предусмотренный контрактом, либо изменить контракт с миграционными правилами; сейчас `percent` разрешён и применяется численно ([CONTRACT_COMPLIANCE](audits/CONTRACT_COMPLIANCE.md), строки 50 и 56).
- **medium — неатомарная запись ATF lease/template.** Обеспечить транзакционную границу либо явно пересмотреть требование атомарности ([CONTRACT_COMPLIANCE](audits/CONTRACT_COMPLIANCE.md), строки 33 и 57).
- **medium — неполный ARPG consumer pipeline.** Контрактные helper API не найдены, CharacterSheets использует другие/сырые чтения; требуется единый pipeline либо актуализация контракта ([CONTRACT_COMPLIANCE](audits/CONTRACT_COMPLIANCE.md), строки 46–47 и 58).
- **high — hard dependencies ACS.** Отключение KB/CharacterSheets делает source/snapshot недоступным, ATF component требует ATF; нужна capability-блокировка и явная политика зависимостей ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 49).
- **high — Shop → Inventory.** Shop загружает bootstrap выключенного Inventory и продолжает grant; checkout должен соблюдать capability boundary и блокироваться при недоступном Inventory ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 153).
- **high — CharacterSheets → Inventory.** Equipment AJAX содержит незащищённые вызовы Inventory после Disable; требуется guard и контролируемый отказ операции ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 205).
- **high — Wanted → ThreadFields.** ACP Wanted напрямую загружает bootstrap выключенного ThreadFields; интеграция должна проверять enabled state и не обходить Disable ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 174 и 181).
- **medium — KB-зависимости Characters/ElementTheme.** Characters сохраняет страницу с ошибкой KB, ElementTheme получает пустой каталог; поведение должно быть согласовано с политикой зависимостей ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 42 и 63).
- **medium — font API AdvancedEditor.** Отключение AE лишает Appearance/SignatureBuilder custom fonts; graceful degradation уже защищён условиями, но продуктовая политика не зафиксирована ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 56).
- **medium — зависимости не декларируются.** Manifest не содержит общего поля зависимостей при наличии фактических перекрёстных вызовов; необходимо определить registry/validation policy ([ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md), строки 56–60).
- **medium — language manifests.** Привести к RU/EN front/admin либо задокументировать поддерживаемые исключения для AdvancedEditor, AdvancedFontAwesome, AdvancedJsBandle, AdvancedMenu, AdvancedProfileFields, AdvancedThreadFields, FakeOnline, ForceRefresh, HeaderWelcomeAvatar, IndexRedirect и SmartUrlTitles ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 53, 67, 91, 98, 126, 171, 209, 223, 230, 237 и 251).
- **medium — frontend manifest FakeOnline.** Кроме языковой структуры, frontend в manifest не найден; требуется согласовать декларацию с фактическим runtime ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 209).
- **medium — legacy assets blacklist.** Перевести на маршрутную модель или оформить временную legacy-политику для Gallery, Inventory, PostCounter, ProfileFields, Shop, ForceRefresh и Balance ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 74, 81, 112, 126, 150, 185 и 223).
- **medium — отсутствующий seed APUI.** Manifest объявляет `apstudio.php`, но seed/installer страницы не найден; удалить маршрут или реализовать owned alias ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 133).
- **medium — IndexRedirect в подкаталоге.** Сравнение только с `/index.php` не покрывает заявленный `/forum/index.php`; привести matching к базовому URL форума ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 240).
- **low — версия AdvancedJsBandle.** Синхронизировать bootstrap `1.0.2` и manifest `1.0.3`; также оценить коллизии общих глобальных имён функций ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 91 и 94).

### 1.5. Жизненный цикл

- **blocker — AccountSwitcher Disable.** Удалять или безопасно отключать `userlist.php`: сейчас остаётся публичный seed, вызывающий незагруженную функцию ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 13).
- **blocker — BuddyList Disable.** Устранить оставшийся `buddy.php` либо обеспечить самостоятельный безопасный response без bootstrap ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 34).
- **blocker — Gallery Disable.** Устранить оставшийся `gallery.php` либо исключить вызов функции отключённого bootstrap ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 76).
- **blocker — PostCounter Disable.** Добавить deactivate и удалять owned `postsactivity.php`/`postsbyuser.php`, которые сейчас удаляются только uninstall ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 114).
- **blocker — Wanted Disable.** Добавить deactivate и удалять owned `wanted.php`, чтобы seed не оставался без bootstrap ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 180).
- **blocker — Balance Disable.** Удалять `balancemanage.php` при Disable, а не только при uninstall ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 187).
- **high — Shop изменяет schema Appearance.** Убрать создание/миграцию `af_aa_active` из Shop и DB migration из runtime init; владелец схемы должен изменять её только в своём lifecycle ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 152).
- **medium — Inventory migration при include.** Перенести DB migration в install/repair, чтобы Disable не запускал миграцию через bootstrap ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 83).
- **medium — PosterAvatar Disable.** Добавить deactivate и откат template markers, сейчас выполняемый только uninstall ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 121).
- **medium — деструктивные uninstall.** Явно подтвердить и документировать удаление Appearance presets/assignments/slot grants, FontAwesome forum icons, Gallery media/albums/logs, ProfileFields values, APUI backup table и FakeOnline state table ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 27, 69, 77, 128, 135 и 211).

## 2. Приоритетный план

### P0 — blocker

1. Исправить Disable standalone aliases AccountSwitcher, BuddyList, Gallery, PostCounter, Wanted и Balance; проверить enable → disable → HTTP request alias → повторный enable для каждого владельца ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 13, 34, 76, 114, 180 и 187).

### P1 — high

1. Устранить небезопасный хранимый HTML AdvancedPostCounter ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 113).
2. Устранить N+1 и повторные SQL-проходы CSS delivery ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строки 29–30).
3. Добавить capability guards для ACS → KB/CS/ATF, CharacterSheets → Inventory и Wanted → ThreadFields; блокировать Shop checkout без Inventory ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 49, 153, 174, 181 и 205).
4. Вернуть Shop в границы собственной schema и убрать runtime migration чужой таблицы ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 152).
5. Разрешить контрактные конфликты ElementTheme Canvas/RAF и Origin Variant `percent` ([CONTRACT_COMPLIANCE](audits/CONTRACT_COMPLIANCE.md), строки 55–56).
6. Определить судьбу `advancedjsbandle`: рассинхрон имени кода, тестов и документации имеет критичность high ([ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md), строки 28 и 58).

### P2 — medium

1. Оптимизировать discovery, languages, runtime sync и XMLHTTP bootstrap ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строки 31–34).
2. Исправить ownership AAM task, include-time migration Inventory и Disable PosterAvatar ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 20, 83 и 121).
3. Унифицировать dependency policy, language manifests и legacy blacklist по полному списку аддонов из раздела 1.4 ([ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md), строка 59; [ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 53–261).
4. Устранить отключение TLS verification SmartUrlTitles и подтвердить SSRF/redirect-сценарии runtime-тестами ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 252–254).
5. Согласовать APUI `apstudio.php`, FakeOnline frontend manifest и IndexRedirect для установки в подкаталоге ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 133, 209 и 240).
6. Закрыть либо пересмотреть требования ATF transactions, ARPG helpers и Appearance raw URL ([CONTRACT_COMPLIANCE](audits/CONTRACT_COMPLIANCE.md), строки 57–59).

### P3 — low

1. Объединить финальные HTML-проходы после стабилизации высоких приоритетов ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строка 35).
2. Проверить и устранить риск динамических свойств `$lang` на PHP 8.5 ([CORE_AUDIT_2026](audits/CORE_AUDIT_2026.md), строка 50).
3. Синхронизировать версии AdvancedJsBandle и сократить риск коллизий глобальных имён ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 91 и 94).
4. Решить судьбу нестандартной структуры `fastnews_admin.php`, runtime-артефактов в `cache/` и единственного schema-md вне `docs/` ([ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md), строки 45 и 61; [REPO_INVENTORY](audits/REPO_INVENTORY.md), строки 48–53).

## 3. Требует решения автора

1. **`advancedjsbandle`:** сохранить фактическое имя навсегда как public identity или выполнить миграцию в `advancedjsbundle` с алиасами для settings/functions/tests/docs? Аудит фиксирует рассинхрон, но не может выбрать обратно совместимую стратегию ([ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md), строки 28 и 58).
2. **Политика uninstall:** какие пользовательские данные должны сохраняться по умолчанию, а для Appearance, FontAwesome, Gallery, ProfileFields, APUI и FakeOnline — удаляться? Текущие операции подтверждены, но продуктовая целесообразность аудитом не определяется ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 27, 69, 77, 128, 135 и 211).
3. **Контракт или код:** что является каноном для Canvas/RAF, Origin Variant `flat|percent`, ATF transaction, ARPG helper API и Appearance raw URL? Для каждого конфликта нужно выбрать исправление кода или новую редакцию контракта ([CONTRACT_COMPLIANCE](audits/CONTRACT_COMPLIANCE.md), строки 53–59).
4. **Модель зависимостей:** требуется ли декларативное поле manifest с проверкой при Enable, достаточно capability guards или нужны оба механизма? Реестр подтверждает фактические связи, но не задаёт целевую модель ([ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md), строки 56–60).
5. **Hard dependency UX:** при отключении KB/CS/ATF/Inventory/AE/ThreadFields следует блокировать зависимый аддон, конкретную операцию или показывать ограниченный интерфейс? Аудит фиксирует последствия, но не выбирает поведение ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 42, 49, 56, 63, 153, 174 и 205).
6. **Legacy blacklist:** удалять blacklist после миграции routes/response rules или сохранять как поддерживаемый compatibility mode? Семь аддонов используют разные места хранения ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 74, 81, 112, 126, 150, 185 и 223).
7. **Общие таблицы:** закрепить владельцев `af_shop_orders`, `af_aa_slot_grants` и `af_aa_active` в manifest/contract и определить версионирование схемы между потребителями ([ADDONS_REGISTRY](audits/ADDONS_REGISTRY.md), строка 60; [ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строка 152).
8. **Политика языковых исключений:** считать ли `lang.front/admin`, плоский `lang` и `ru/en` поддерживаемыми форматами либо обязательно мигрировать их к canonical RU/EN ([ADDONS_AUDIT_2026](audits/ADDONS_AUDIT_2026.md), строки 53, 67, 91, 98, 126, 171, 209, 223, 230, 237 и 251).
