# AdvancedCombatSystem 1.0 — Integration Contracts

## 1. Область и доказательства аудита

Связанные документы: [Master Specification](ACS_MASTER_SPECIFICATION.md), [Technical Architecture](ACS_TECHNICAL_ARCHITECTURE.md), [Roadmap](ACS_IMPLEMENTATION_ROADMAP.md).

Аудит выполнен для `CaptainPaws/mybb-advancedfunctionality`, `main` = **7728cc7f0319ed49b425f2ec3ceed717c60895b4**, подтверждено GitHub `fetch_commit(main)`. Рабочая ревизия = `6cd037694d1eecc47718fe85a7008227bdb6eb80`. GitHub compare вернул `ahead`, ahead_by=1, behind_by=0, единственное добавление — `docs/acs/source/Проектирование боевой системы.docx`. Поэтому проверенные локальные PHP/JS/manifest-файлы соответствуют main на указанной ревизии. Локальная ссылка origin/main устарела; не использовалась как доказательство актуальности. `git fetch` не прошёл из-за недоступного proxy:8080; актуальность установлена доступным GitHub connector. Следующая реализационная задача должна повторить проверку main.

**EXISTING** означает обнаруженный интерфейс с прочитанным телом функции и ограничениями; не означает пригодность для атомарной ACS-операции. **PROPOSED** означает новый интерфейс/адаптер, которого в текущем коде нет. Все `AF_ACS_*` ниже — PROPOSED. Пути аддонов ниже относительно `inc/plugins/advancedfunctionality/addons/`, если не указано иначе. В репозитории не найден AGENTS.md. Полные комплексы тестов не запускались; аудит статический.

**Уточнение ACS-DOC-01 (2026-10-21).** Указанный выше аудит и ссылки на строки — исторические доказательства той ревизии, а не повторный аудит runtime текущей main. Действующая классификация — [17 основных стихий и дочерние направления](ACS_MASTER_SPECIFICATION.md#element-taxonomy). Новые element/direction contracts ниже — PROPOSED; наличие прежних readers/JSON-полей не делает их READY. Принятый этап 1 этим уточнением не переоткрывается.

## 2. Владение данными и направления

| Контракт | Владелец постоянных данных | Направление | Этап |
|---|---|---|---|
| IC-CORE | AF: enabled, manifests, templates, assets, ACP | AF → ACS; ACS регистрирует расширения | 1 |
| IC-KB | KB: определения и версии механик | KB → неизменяемые snapshots ACS | 1–3, 5, 8–10 |
| IC-CS | CharacterSheets: build; balance: EXP | CS → снимок; постоянные изменения только отдельной операцией | 2, 10 |
| IC-INV | Inventory: собственность, qty, комплект | чтение → ACS; reserve/consume/transfer/grant → Inventory | 6; блокирует предметные расходы и выдачи |
| IC-SHOP | Shop: каталог, цены, заказы | покупки → Inventory → будущие снимки ACS | 6 |
| IC-FIELDS | AdvancedThreadFields: поля анкеты и связь с KB | анкета → KB/CS → ACS | 2 |
| IC-THEME | Adaptive Theme Framework: shell, slots, sticky | ACS → разрешённые компоненты shell | 1, 4, 11 |
| IC-EDITOR | MyBB: проза; AdvancedEditor: редактор | editor → draft/publish; ACS → независимая карточка | 4, 11 |
| IC-PROFILE | APUI: профильный каркас | ACS → фильтрованная статистика/достижения | 10–11 |
| IC-AAM | AAM: уведомления; ACS: подтверждённые события | ACS outbox → AAM | 11 |
| IC-STATS | ACS: боевая истина; AdvancedStatistic: показ агрегатов | ACS → статистический consumer | 10 |
| IC-BALANCE | balance: exp/credits/ability_tokens | ACS reward → идемпотентное начисление | 10 |
| IC-MYBB | MyBB: posts, темы, форумные права | PublishService ↔ штатная публикация | 4 |

Параллельные сессии имеют независимые боевые состояния. Постоянная выдача не пересчитывает активные снимки других сессий. Данные персонажа, имущество, права собственности и игровые отношения сторон — разные сущности (A1.32–A1.43, A1.78).

## 3. IC-CORE — AdvancedFunctionality

**EXISTING.** `inc/plugins/advancedfunctionality.php`: процедурный core, version `1.1.0` из `advancedfunctionality_info()`, compatibility `18*`; `af_discover_addons()`, `advancedfunctionality_bootstrap_addons()`, `af_is_addon_enabled(string $id): bool`. Manifest задаёт id/name/version/bootstrap/admin/lang/frontend. Bootstrap вызывается по канону `af_{id}_init()`. Универсальный маршрут `xmlhttp.php?action=af_addon_api&addon=<id>` вызывает существующий `af_xmlhttp_call_addon($id)` → `af_{id}_xmlhttp()`, только для enabled аддона. Это транспорт, не готовый combat API.

`af_frontend_asset_allowed($addon,$resource=null,?array $context=null,array $responseFacts=[]): bool` использует manifest и факты ответа. Регистрация stylesheet принадлежит штатному механизму core; ACS не внедряет собственную глобальную доставку всех ассетов. `admin/router.php` — существующий роутер ACP, вызывающий `AF_Admin::dispatch()` из core; новый контроллер ACS должен следовать manifest-контракту и проверкам admin permissions, а не открывать отдельный незащищённый скрипт.

**PROPOSED.** `advancedcombatsystem/manifest.php`, `advancedcombatsystem.php`, `admin.php`; `af_advancedcombatsystem_init()` и `af_advancedcombatsystem_xmlhttp()` (этап 1). Вход — нормализованный op/context и MyBB user; выход — JSON envelope Architecture. Bootstrap только регистрирует хуки и компоненты. Ошибка зависимости блокирует ACS-механику с диагностикой, не обычную тему. Установка/обновление не должны запускаться на каждом showthread. Право глобального enabled не заменяет сессионную роль.

## 4. IC-KB — Knowledge Base

**EXISTING.** `knowledgebase/knowledgebase.php` содержит reader/normalizer:

```php
af_kb_arpg_read_definition(string $type, string $key, ?int $abilityIndex = null): array
af_kb_arpg_normalize_entry(array $entry, ?int $abilityIndex = null): array
af_kb_get_entry(string $type, string $key): ?array
af_kb_arpg_character_stat_registry(): array
```

Типы: arpg_ability/arpg_item/arpg_bestiary/arpg_mechanics/character. Для способности character нужен нулевой abilityIndex. Выход reader:

```json
{
  "schema":"af_kb.arpg.sheet-normalized.v1",
  "source":{"type":"arpg_ability","id":123,"key":"example","projection":"...","ability_index":null,"updated_at":0,"origin":"knowledgebase"},
  "definition_key":"example",
  "definition_version":{"revision":null,"declared_version":null,"fingerprint":"..."},
  "normalized":{},"diagnostics":[],"supported":true,"executable":false,"raw":{}
}
```

`executable=false` выставляется нормализатором даже у supported=true: KB предоставляет данные и проверки структуры, **не резолвер**. fingerprint/declared version/точная formula version должны войти в snapshot. Raw и diagnostics нельзя напрямую пересылать игроку: могут содержать скрытое определение.

`formula_def` — типизированный AST `literal/input/operation`; существующая грамматика: add/subtract/multiply/divide (2 аргумента), negate (1), min/max (2–8), до 256 узлов/16 рёбер, до 64 inputs, output_type number/integer, rounding none/floor/ceil/truncate/half_up/half_even. Нет eval/PHP/динамических вызовов. Formula profile связывает точные formula_def_key и formula_def_version, parameters — конечные JSON-числа. Отсутствующая/двусмысленная/неактивная/несовпавшая версия — diagnostics/supported=false; legacy preview не fallback. Действующие схемы B1/B2/B3 описаны в `docs/ACS_KB_NORMALIZATION_CONTRACT.md`, `ACS_KB_FORMULA_CONTRACT.md`, `ACS_KB_MECHANICS_CONTRACT.md` и подтверждены соответствующими функциями source.

**Расхождения с целевым baseline.** Реестр содержит 13 ключей, `accuracy` отсутствует; есть `status_resist`, а S1.2 называет показатель status_resistance. Большой enum legacy damage_type не доказывает каноническую семантику 17 основных стихий. `af_kb_seed_arpg_elements()` (строка 2414) создаёт только восемь: fire, water, earth, wind, lightning, ice, light, shadow. Это не доказывает отсутствия дополнительных записей в production БД, но seed исходного аудита не покрывает новый канонический набор из 17 основных стихий и использует legacy `wind` вместо `air`. Наличие записей и соответствие ключам D1.4 должны быть проверены/подготовлены как данные; придумывать эквиваленты void/quantum/imaginary по названию запрещено. `af_kb_arpg_acs_resistances()` (строка 8715) передаёт scope/unit и предупреждение resistance_semantics_unspecified; adapter не должен молча трактовать legacy числа как утверждённую RES. Новые mechanics-поля A1.70–A1.86 нельзя считать реализованными только потому, что JSON передаёт произвольные данные. Каждый тип должен пройти capability validation.

**PROPOSED IC-KB-1.** `AF_ACS_KnowledgeBaseAdapter::snapshot(type,key,abilityIndex)` возвращает `{snapshot_id,contract_id,schema_version,definition_version,hash,normalized,capabilities}`; исходные данные только серверные. Adapter проверяет права чтения записи и готовность всей цепочки ссылок. Новый snapshot не обновляет старый. Ошибка не угадывает формулу и блокирует затронутое действие.

**PROPOSED IC-KB-2.** Этап 2, задача [ACS-2.1.3](ACS_IMPLEMENTATION_ROADMAP.md#acs-2-1-3): проверить и обеспечить реестр 17 основных стихий D1.4 с явными ключами/RES units, расширяемые дочерние направления и совместимость `wind` → `air` без destructive migration. Accuracy round trip, политика отсутствующих legacy-значений и status_resist → status_resistance остаются отдельными требованиями 2.1; это не новый показатель поверх прежнего. Этапы 5/8/9/10 расширяют схемы и нормализацию по capability matrix статусов, prepared/spawn/objects/transform/loot/achievements. Место — существующие KB registry/schema/normalizer/editor функции, без глобальной переписи KB.

<a id="element-capabilities"></a>
### 4.1. IC-KB-2: capabilities стихий и направлений — PROPOSED

Канонический набор из 17 ключей: `fire`, `water`, `air`, `earth`, `ice`, `lightning`, `nature`, `light`, `shadow`, `death`, `life`, `chaos`, `mind`, `abyss`, `gravity`, `astral`, `space`. Источник имён и смысла — [D1.4 / ACS-DOC-01](ACS_MASTER_SPECIFICATION.md#element-taxonomy); предлагаемая схема — [Architecture §5.2.1](ACS_TECHNICAL_ARCHITECTURE.md#element-direction-contract).

| Capability | Статус нового контракта | Необходимая проверка |
|---|---|---|
| `canonical_elements_17` | PROPOSED | Ровно 17 основных identities, уникальные канонические keys и version/hash; каждый RES конечный, неотрицательный, с явной единицей; нет отдельного RES направления |
| `magic_direction_hierarchy` | PROPOSED | Стабильные ключи направлений, ровно один типизированный parent_ref, существование pinned родителей, root основной стихии, отсутствие циклов/коллизий; расширение через KB без правки PHP-реестра основных стихий |
| `explicit_damage_element` | PROPOSED | Классификация magic_direction отдельно от effect/damage_kind; elemental-компонент содержит явный основной damage_element; родитель направления не используется как implicit damage type |
| `legacy_element_aliases` | PROPOSED | Версионированное boundary-сопоставление wind→air с сохранением raw provenance, обнаружение конфликтов, без второго RES или изменений старых записей |
| `element_snapshot_provenance` | PROPOSED | Версии schema/contract/registry/alias policy, hashes и pinned direction/parent refs; одинаковая нормализация KB, CS, бестиария и ACS adapter |

Это новые проектные имена capabilities, а не обнаруженные PHP API или уже реализованные результаты. Действующий readiness gate — `canonical_elements_17`; историческое требование о прежнем количестве сохранено только в provenance D1.4 Master. **EXISTING** reader/editor/normalizer может передавать произвольный JSON, но **READY** требует проверки указанной конкретной capability; PROPOSED/отсутствующая обязательная capability даёт BLOCKED только зависимой ACS-операции, не обычному форуму.

Editors/save/read/normalizers должны сохранять primary key, direction refs, явный effect kind/damage_element и версии без потерь. Для бестиария валидируется тот же реестр RES и definitions, что для персонажа; количество направлений не расширяет карту базовых RES. Неизвестный ключ/несовместимая версия/цикл/оборванный родитель/конфликт alias диагностируется; не подставляются RES=0, похожая стихия или направление-урон. Новая схема KB — PROPOSED, примеры blood/metal/crystal/sound и прочие направления не требуют автоматического seed. Кровь не основная стихия и не получает собственный RES; `blood` не означает автоматический life-урон. Не вводятся контрстихии, новые множители или elemental reaction matrix.


## 5. IC-CS — CharacterSheets

**EXISTING.** Bootstrap `charactersheets/charactersheets.php`, loader `af_charactersheets_require_modules(array $modules): void`. На showthread по умолчанию доступны лёгкие metadata/permissions/postbit/frontend; calculator подгружается отдельно. `modules/metadata.php` даёт `af_charactersheets_get_sheet_by_tid(int $tid): array`, `..._by_uid`, `..._by_slug`; отсутствие даёт []. `modules/calculator.php` содержит `cs_build_character_state($uid,$sheet_id): array`, `af_charactersheets_compute_sheet_view(array $sheet): array`. Существующий view объединяет build_json/progress_json, KB, экипировку, balance и расчётные поля. Его нельзя считать стандартизованным ACS CombatSnapshot.

Важные ограничения тела функции: uid mismatch в cs_build_character_state не отклоняется; это не авторизация. compute_sheet_view вызывает `af_charactersheets_sync_fixed_skills`, поэтому комментарий о read-only build не доказывает отсутствие записей во вложенной цепочке. ACS не должен вызывать его на каждом показе темы или объявлять чистой функцией без адаптера. `permissions.php` содержит `af_charactersheets_user_can_edit_sheet`, `af_cs_can_manage_sheet`, но право редактировать лист не тождественно праву GM управлять существом.

**PROPOSED.** `AF_ACS_CharacterSheetAdapter::createSnapshot(sheet_id,actor_uid,context)` (этап 2) возвращает canonical base stats/resources/abilities/equipment source IDs/source breakdown/hash/version. Перед ним проверяются форум/сессия/владелец и capability. На регистрации фиксируется один снимок; повторное открытие панели его не пересобирает. Требуется явное разделение уже включённых equipment modifiers и боевого слоя, чтобы не посчитать предмет дважды. Нужна ограниченная snapshot-функция CS либо проверенный адаптер существующего view с документированными побочными эффектами; новая функция пока отсутствует.

Постоянные изменения разрешаются отдельной операцией этапа 10. ACS меняет только session state при отдыхе и бою; sync в build_json напрямую запрещён. Ошибка snapshot блокирует регистрацию конкретного участника, не создаёт частичного entity. `modules/experience.php::af_charactersheets_grant_exp(...)` существует, но всегда возвращает false; использовать для выдачи EXP нельзя (IC-BALANCE).

**PROPOSED IC-CS-ELEMENTS (этап 2).** CharacterSheets должен передавать 17 основных стихий/RES и отдельную классификацию направлений по IC-KB-2, с сохранением явного damage_element и версий. Лист/бестиарий и ACS adapter используют одну нормализацию ключей и wind→air policy; legacy missing и alias conflict диагностируются, не становятся дополнительным RES или нулём. UI классификация/иконки не определяют боевой урон, equipment modifiers не применяются повторно. Snapshot фиксирует source breakdown и версии всей иерархии; существующий CS view этим контрактом не объявляется безопасным combat snapshot.

## 6. IC-INV — AdvancedInventory

**EXISTING.** `advancedinventory/advancedinventory.php`, процедурные API; таблицы `af_advinv_items`, `af_advinv_equipped`, `af_advinv_support_slots` с TABLE_PREFIX.

| Интерфейс | Вход | Выход и ограничение |
|---|---|---|
| af_inv_get_items(int $uid,array $filters=[]) | entity/subtype/search/page/**perPage**/enrich | items,total,page,perPage; данные владельца; сам по себе не проверяет ACS-control |
| af_inv_get_equipped(int $uid) | uid | slot → equipped row |
| af_advinv_export_charactersheet_equipment_state(int $uid) | uid | items/equipped/groups; UI export, не reservation snapshot |
| af_advinv_export_charactersheet_abilities_state(int $uid) | uid | массив состояния способностей, не боевое право использования |
| af_inv_add_item(int $uid,array $item) | описание inventory item | int item id; нет ACS operation_key |
| af_inv_remove_item(int $uid,int $itemId,int $qty=1) | владелец, instance id, количество | bool; обычный select затем delete/update, без гарантии CAS/lock/idempotency |
| af_inv_user_can_view(int $viewerUid,int $ownerUid) | viewer/owner | bool права просмотра, не расходования |
| af_inv_can_manage_owner(int $viewerUid,int $ownerUid) | viewer/owner | bool owner/staff для управления, не отдельное разрешение ACS delegation |

У экспортёра экипировки есть вызов с `per_page`, но reader ожидает `perPage`. Поэтому нельзя полагаться на получение всех 500 вещей таким экспортом; адаптер должен явно пагинировать реальный reader и проверять total. Сам экспорт не гарантирует атомарности всего комплекта. remove_item может удалить строку при запрошенном количестве больше остатка; не является безопасным боевым списанием. Нет подтверждённого глобального reserve/consume/release/transfer с ключом ACS. Вызов обычного endpoint equip не подтверждает соблюдение боевых AP/custody.

**PROPOSED IC-INV-1**, этап 6, предварительное ограниченное изменение Inventory:

```text
reserve(operation_key, owner_uid, item_id, qty, session_id, expected_inventory_version)
consume(operation_key, reservation_id, qty, expected_version)
release(operation_key, reservation_id, reason)
transfer(operation_key, item_id, qty, from_uid, to_uid, expected_versions)
grant(operation_key, recipient_uid, normalized_item, provenance)
lookup(operation_key)
```

Вход операции обязательно имеет scope, actor_uid/проверенные полномочия, целое qty>0 и hash payload. Ответ `{operation_key,status,external_ref,item_id,qty,inventory_version,result_hash}`; статусы prepared/committed/released/rejected/recovery_required. Повтор с тем же key и payload возвращает прежний результат, другой payload — конфликт. Все глобальные writers (продажа, удаление, transfer, shop-grant, equip/custody) обязаны уважать резервацию. Таблица только в ACS, которую другие writers не проверяют, не решает проблему.

Inventory остаётся владельцем qty/собственности; ACS — боевого комплекта, usage и custody. Смена оружия 1 AP; вне боя бесплатна. Custody не меняет uid, одновременный временный пользователь один в сессии, физическая передача отдельно. Параллельные сессии изолируют custody, но не отменяют глобальный reserve. При сбое неопределённый результат → lookup/recovery, запрещено повторять remove_item. При недоступном безопасном контракте боевой расход, prepared item и выдача loot блокируются, не эмулируются прямым SQL ACS. Проверка прав включает делегирование отдельного расходного разрешения, не только can_view.

## 7. IC-SHOP — AdvancedShop

**EXISTING.** `advancedshop/advancedshop.php` содержит `af_advancedshop_checkout()`, `af_advancedshop_grant_inventory_item(int $uid,array $item,int $orderId=0,int $line=0): void`. Checkout использует собственный checkout_key/order и возвращает JSON success items/spent_minor/currency/order_id/balance_minor/links. Назначение — покупка и доставка в инвентарь, не выполнение боевого действия. Дедупликация заказа не предоставляет ACS reward_id. Специальные appearance slots реализованы в `appearance_slots.php`; косметика не получает боевых свойств автоматически.

ACS читает результат покупки через Inventory, не выполняет checkout ради награды. Уже запечатанная экипировка не заменяется покупкой автоматически. **PROPOSED**: этап 6 расширяет общий reserve-протокол на магазин/продажу/изменение комплектов. Результат ошибки покупки принадлежит Shop; ошибка доступности боевого предмета — безопасный DEPENDENCY_ERROR/STATE_CONFLICT ACS. Цены не используются как формула боевой силы. Право на покупку и право применить предмет в бою проверяются независимо.

## 8. IC-FIELDS — AdvancedThreadFields

**EXISTING.** `advancedthreadfields/advancedthreadfields.php`: `af_atf_get_values_by_tid($tid)` читает fieldid→raw, `af_atf_get_profile_character_payload(int $tid): array` возвращает tid/about_html/fields[key]={key,raw,html}. Это UI/profile контракт, не canonical stats. `af_atf_bridge_sync_character_kb_from_thread(int $tid,array $thread=[],array $context=[]): array` и штатные workflow paths связывают анкету/KB; `af_atf_character_stats_field_keys()` и `af_atf_normalize_character_abilities_json(string $raw): string` участвуют в round trip.

Хуки newthread/editpost/datahandler/showthread/postbit относятся к полям и синхронизации анкет; ACS не должен повторно запускать bridge при каждом боевом посте. **PROPOSED** этап 2: адаптер идентичности tid→sheet/KB/uid, проверка механики arpg/акцепта и прав через существующий workflow; статические ссылки фиксируются в snapshot. Добавление accuracy должно пройти UI→save→KB→CS round trip. Отсутствующая связь блокирует регистрацию с безопасной причиной, не создаёт персонажа по имени из текста. Ни HTML, ни литературное поле не являются источником числового состояния ACS.

**PROPOSED IC-FIELDS-ELEMENTS (этап 2).** Поля ARPG-анкеты и обратный prefill KB сохраняют основную стихию и отдельные direction refs по IC-KB-2/IC-CS-ELEMENTS; проверяется UI→save→KB→CS→read без потери ключей, версии/alias provenance и без изменения старых записей при просмотре. Классификация анкеты не подменяет явный damage_element способности. Обычный showthread не запускает синхронизацию или полный audit иерархии.

## 9. IC-THEME — Adaptive Theme Framework

В этом документе **Fields** означает AdvancedThreadFields, **Theme** означает Adaptive Theme Framework: одинаковое сокращение ATF не даёт общих API.

**EXISTING.** `adaptivethemeframework/adaptivethemeframework.php::af_adaptivethemeframework_register_component(array $component): bool`. Обязательные owner/key/slot и callable renderer либо html; необязательные sortorder/visibility/context/legacy/assets. Уникальная identity owner::key; второй register возвращает false. Слоты включают `thread.before_posts`, `thread.after_posts`, `post.before_body`, `post.after_body`, `profile.navigation`, `profile.main`, `profile.extra`, `footer.modals`, `header.assets`. Renderer получает context, серверная visibility проверяется до вывода.

**PROPOSED** этап 1/4/11: регистрация owner=advancedcombatsystem для панели в thread.before_posts и карточки в post.before_body. Выход — безопасный HTML из QueryService по разрешённым материализованным данным. Не менять postbit_classic/sticky/meta-line и не подменять whole template. В текущем списке нет отдельного гарантированного слота внутри post form непосредственно перед AdvancedEditor; точку конструктора требуется добавить ограниченным расширением Theme/Editor на этапе 4. Нельзя выдумывать слот `combat.builder` как уже существующий.

Сбой необязательной панели не изменяет механику; отказ обязательного конструктора/confirm UI запрещает seal/commit с понятным сообщением. Неактивная сессия не грузит резолвер. CSS ACS ограничивается .af-acs, lazy assets проходят core manifest. Контекст, user и post/session проверяются на сервере: даже скрытый DOM не должен содержать секреты.

## 10. IC-EDITOR и IC-MYBB — AdvancedEditor и публикация

**EXISTING.** `advancededitor/advancededitor.php`, `shell.php`, `assets/advancededitor.js`; обработка превью `misc.php?action=af_ae_postpreview` (также старое af_aqr_postpreview), parse_message_start/end. Shell-функции создают оформление обычного редактора. Эти endpoints возвращают HTML-предпросмотр текста, не seal/commit ACS. Репозиторий содержит корневые entrypoints аддонов, но не полный исходник ядра MyBB с PostDataHandler, newreply.php и всеми storage engines. Поэтому точная транзакционная граница публикации **ещё не EXISTING ACS API**.

**PROPOSED IC-MYBB-1**, этап 4: `AF_ACS_MybbPostGateway::publishWithinTransaction(validated_post,submission_id)` — один пост, вернёт pid. До реализации прочитать именно установленный MyBB 1.8.40 PostDataHandler/хуки post-insert и все активные побочные handlers. Проверить permissions, flood/spam/moderation/attachments/subscriptions, кодировки и обязательность обычной форумной валидации. ACS нельзя вставлять posts прямым SQL в обход ядра или заявлять атомарность по одному позднему hook. Нужна общая транзакция для posts/ACS/Inventory либо предварительно реализованный безопасный coordinator; при невозможности — blocked для combat commit. Narrative publication остаётся штатной.

**PROPOSED IC-EDITOR-1**, этап 4: поля одной формы `acs_mode`, draft_id, draft_version, submission_id, confirmation_hash. Проза остаётся `message` с BBCode. Конструктор не вставляет защищённый HTML в SCEditor. Адаптер использует единственный editor instance и штатные save/submit/quickreply lifecycle. Обычный edit/Quick Edit обновляет только prose; карточка рендерится по pid из ACS. Новые ACS hooks/callbacks в Editor перечислить как новые при отдельном PR, а не выдавать за существующие.

При unknown publish сначала lookup submission_id; existing pid возвращается без replay. Модерационное состояние поста должно быть проверено до применения механики по контракту этапа 4; нельзя применять бой, затем молча оставить непривязанный moderated draft. Согласованную политику видимости для moderated боевых постов фиксирует интеграционный PR до допуска форума; новые игровые правила не вводятся догадкой.

## 11. IC-PROFILE — AdvancedProfileUI

**EXISTING.** `advancedprofileui/modules/profile_providers.php`:

- `af_apui_register_profile_quick_link_provider(string $key,callable $provider): bool`: callback(context) → label/icon/url/available либо null.
- `af_apui_register_profile_reward_provider(string $key,callable $provider): bool`: принимает только achievements или gifts; callback(owner_uid) → HTML.

Произвольная новая вкладка боевой статистики этим reward API не регистрируется. `advancedprofileui.php` и Theme владеют profile/lazy composition. **PROPOSED** этап 10/11: отдельный lazy provider/tab ACS statistics (итоги/история/фильтры, private/participants/public), достижений — допустимый achievements provider с разграничением источников. Вход owner entity/profile uid и viewer; выход фильтрованный view model/HTML. Показ наград не начисляет их. Ошибка статистики не блокирует профиль или бой. Quick link возможен существующим API, но не заменяет требуемую вкладку. Нужны права просмотра форума и секретности каждого encounter, включая агрегированные количества.

## 12. IC-AAM — Advanced Alerts and Mentions

Точное id — `advancedalertsandmentions`, а не advancedalertsmentions. **EXISTING.** `advancedalertsandmentions/advancedalertsandmentions.php::af_aam_add_alert(int $uid,string $code,int $objectId=0,int $fromUid=0,array $extra=[],int $forced=0): void`. Проверяет enabled, type id и preferences; void не различает вставку и пропуск. `AF_AAM_Repo` в repo.php предоставляет poll/list/read operations для уведомлений, не транспорт combat state. Endpoint af_aam_api обслуживает эти операции, не подтверждает существование общего событийного AF bus.

**PROPOSED** этап 11: ACS notification types/formatters и idempotent delivery API с operation_key и результатом delivered/skipped/retryable/recovery_required. Outbox имеет уникальный dedupe_key по событию/получателю/категории и агрегирует один пост. Простого has_recent_alert недостаточно для гонок и crash после insert до outbox ack. Либо единая транзакция AAM insert+ack, либо уникальный внешний operation_key и lookup. Без этого нельзя обещать exactly-once.

Доставка только подтверждённых событий, права/awareness проверяются до формирования payload, скрытые имена/цифры/количества исключаются. Вход — recipient, event ref, safe payload; выход — acknowledgement. Сбой доставки не откатывает бой. При delegation turn/deadline адресуются действующему контроллеру, важное владельцу. ACS state всё равно читается из ACS по версиям, а не из notification text.

## 13. IC-STATS — AdvancedStatistic

**EXISTING.** `advancedstatistic/advancedstatistic.php` строит форумную статистику index.php, online/recent threads и данные AdvancedPostCounter; `af_advancedstatistic_get_board_stats(): array`. Combat ingest API не найден. Наличие слова Statistic не означает хранения боевых агрегатов.

**PROPOSED** этап 10: `AF_ACS_StatisticsAdapter::publishCompletedAggregate(operation_key,encounter_id,aggregate_version,visibility,payload)` и consumer в AdvancedStatistic. Payload содержит actual_hp_damage/barrier_absorption/effective_healing/received_damage/hits/crits/reactions/intercepts/statuses/AP/items, entity/control_group без двойного учёта. Только завершённые подтверждённые encounters. correction_event создаёт новую aggregate_version; upsert одной версии идемпотентен. Ошибка consumer остаётся outbox pending, ACS-архив доступен независимо. Нет рейтинга вклада и неявной связи с наградами.

## 14. IC-BALANCE, опыт и дополнительные аддоны

**EXISTING.** `balance/balance.php`: `af_balance_get(int $uid): array`, `af_balance_add($uid,string $kind,$amount,array $meta=[]): array`, `af_balance_add_exp(...)`, scaled integer storage, kind exp/credits/ability_tokens. Amount major преобразуется через scale/floor; возвращается balance, не подтверждённая транзакция ACS. `af_balance_apply_scaled_delta` читает старое значение, обновляет и пишет ledger при enabled; сам вызов не даёт общего ACS exactly-once. `ref_type/ref_id/meta` — атрибуция, не доказательство дедупликации произвольной награды.

**PROPOSED** этап 10: idempotent reward-credit gateway с operation_key, amount_minor, currency/scale, source_event/reward/recipient, expected_version и lookup. ACS заранее переводит величину в канонические единицы валюты без двойного округления; baseline N1 для боевого результата не переписывает legacy денежную семантику. Награда получает reserved/committed/recovery_required; retries не вызывают повторный af_balance_add. Permanent state sync в CS/KB — отдельный разрешённый adapter с такими же гарантиями, без прямой правки build_json.

Дополнительно проверены manifest/entrypoints `advancedcharacters/advancedcharacters.php` (витрина KB, не владелец персонажа), `advancedjsbandle/advancedjsbandle.php` (точное id `advancedjsbandle`, manifest version 1.0.3; доставка ассетов через `af_ajsb_allowed_assets_for_page()` и `af_advancedjsbandle_pre_output`; ACS не дублирует bundle), AdvancedPostCounter через consumer AdvancedStatistic и `advancedpostcounter/advancedpostcounter.php`: `datahandler_post_insert_post` → `af_advancedpostcounter_increment_post($posthandler)`, проверяет fid/visible и меняет users.af_advancedpostcounter. Этот побочный writer обязательно входит в аудит transaction boundary этапа 4. Они не получают нового обязательного combat API. При дальнейшей реализации нельзя регистрировать двойную инициализацию SCEditor/CS/Theme через их механизмы.

Схемы предлагаемых owner-side reservation/receipt таблиц и Inventory version column приведены в Architecture §5.5. Они имеют статус PROPOSED; их наличие не было обнаружено при аудите.

## 15. Приоритеты недостающих контрактов

| Работа | Статус | Где реализовать | Блокирует |
|---|---|---|---|
| Accuracy, status-resist mapping, capability validation | PROPOSED | KB/CS/Fields + ACS adapters; 1–2 | регистрацию/HitCheck при неполном snapshot |
| 17 основных стихий, направления, RES/aliases/provenance | PROPOSED | IC-KB-2, IC-CS-ELEMENTS, IC-FIELDS-ELEMENTS; ACS-2.1.3 и потребители 2/3/5/7/8/9/12 | только операции, требующие новой element capability |
| Pure combat snapshot, equipment source breakdown | PROPOSED | CS adapter/ограниченный CS API; 2 | воспроизводимые исходные характеристики |
| MyBB publish coordinator, form extension | PROPOSED | ACS gateway + scoped Editor/Theme; 4 | атомарный combat_post |
| Inventory global reservations/idempotent ops | PROPOSED | Inventory writers + ACS adapter; 6 | предметные затраты/custody/prepared items/loot issue |
| Idempotent balance/permanent sync | PROPOSED | balance/CS/KB gateways; 10 | соответствующие постоянные награды |
| Combat aggregate consumer/profile tab | PROPOSED | AdvancedStatistic/APUI; 10–11 | представление статистики, не commit боя |
| AAM types, dedupe delivery ack | PROPOSED | AAM + ACS outbox; 11 | уведомления, не commit боя |
| Общий AF state transport | PROPOSED, необязателен | отдельный транспортный контракт; 11 | не блокирует: fallback A1.88 обязателен |

## 16. Проверяемые ссылки на исходники

Следующая таблица сгенерирована из проверенных файлов: точные сигнатуры и строки привязаны к зафиксированной ревизии main. Она дополняет прочитанные тела функций и позволяет повторить аудит без догадок о названиях API.

| Файл / интерфейс | Сигнатура EXISTING |
|---|---|
| [inc/plugins/advancedfunctionality.php:2988](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality.php#L2988) | `function af_discover_addons(): array` |
| [inc/plugins/advancedfunctionality.php:2309](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality.php#L2309) | `function advancedfunctionality_bootstrap_addons()` |
| [inc/plugins/advancedfunctionality.php:3264](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality.php#L3264) | `function af_frontend_asset_allowed($addon, $resource = null, ?array $context = null, array $responseFacts = []): bool` |
| [inc/plugins/advancedfunctionality.php:6234](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality.php#L6234) | `function af_xmlhttp_router(): void` |
| [inc/plugins/advancedfunctionality.php:6274](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality.php#L6274) | `function af_xmlhttp_call_addon(string $id): void` |
| [inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:149](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php#L149) | `function af_kb_arpg_character_stat_registry(): array` |
| [inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:8187](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php#L8187) | `function af_kb_arpg_math_contract(): array` |
| [inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:7921](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php#L7921) | `function af_kb_arpg_mechanics_contract(): array` |
| [inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:8737](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php#L8737) | `function af_kb_arpg_normalize_entry(array $entry, ?int $abilityIndex = null): array` |
| [inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:8819](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php#L8819) | `function af_kb_arpg_read_definition(string $type, string $key, ?int $abilityIndex = null): array` |
| [inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php:15154](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php#L15154) | `function af_kb_get_entry(string $type, string $key): ?array` |
| [inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php:38](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php#L38) | `function af_charactersheets_require_modules(array $modules): void` |
| [inc/plugins/advancedfunctionality/addons/charactersheets/modules/calculator.php:833](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/charactersheets/modules/calculator.php#L833) | `function cs_build_character_state($uid, $sheet_id): array` |
| [inc/plugins/advancedfunctionality/addons/charactersheets/modules/calculator.php:846](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/charactersheets/modules/calculator.php#L846) | `function af_charactersheets_compute_sheet_view(array $sheet): array` |
| [inc/plugins/advancedfunctionality/addons/charactersheets/modules/metadata.php:18](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/charactersheets/modules/metadata.php#L18) | `function af_charactersheets_get_sheet_by_tid(int $tid): array` |
| [inc/plugins/advancedfunctionality/addons/charactersheets/modules/experience.php:72](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/charactersheets/modules/experience.php#L72) | `function af_charactersheets_grant_exp(int $sheet_id, float $amount, string $event_key, string $event_type, array $meta): bool` |
| [inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:3059](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php#L3059) | `function af_inv_add_item(int $uid, array $item): int` |
| [inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:3207](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php#L3207) | `function af_inv_remove_item(int $uid, int $itemId, int $qty = 1): bool` |
| [inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:3221](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php#L3221) | `function af_inv_get_items(int $uid, array $filters = []): array` |
| [inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:3275](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php#L3275) | `function af_inv_user_can_view(int $viewerUid, int $ownerUid): bool` |
| [inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:3328](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php#L3328) | `function af_inv_can_manage_owner(int $viewerUid, int $ownerUid): bool` |
| [inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:3486](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php#L3486) | `function af_inv_get_equipped(int $uid): array` |
| [inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:3697](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php#L3697) | `function af_advinv_export_charactersheet_equipment_state(int $uid): array` |
| [inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php:2861](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedinventory/advancedinventory.php#L2861) | `function af_advinv_export_charactersheet_abilities_state(int $uid): array` |
| [inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:2152](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php#L2152) | `function af_advancedshop_checkout(): void` |
| [inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php:2598](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedshop/advancedshop.php#L2598) | `function af_advancedshop_grant_inventory_item(int $uid, array $item, int $orderId=0, int $line=0): void` |
| [inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php:4367](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php#L4367) | `function af_atf_get_values_by_tid(int $tid): array` |
| [inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php:5208](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php#L5208) | `function af_atf_get_profile_character_payload(int $tid): array` |
| [inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php:5419](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php#L5419) | `function af_atf_bridge_sync_character_kb_from_thread(int $tid, array $thread = [], array $context = []): array` |
| [inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:2358](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php#L2358) | `function af_adaptivethemeframework_slots(): array` |
| [inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:2401](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php#L2401) | `function af_adaptivethemeframework_register_component(array $component): bool` |
| [inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php:1692](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php#L1692) | `function af_adaptivethemeframework_compose_showthread(): void` |
| [inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php:2180](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php#L2180) | `function af_advancededitor_misc_start(): void` |
| [inc/plugins/advancedfunctionality/addons/advancedprofileui/modules/profile_providers.php:12](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedprofileui/modules/profile_providers.php#L12) | `function af_apui_register_profile_quick_link_provider(string $key, callable $provider): bool` |
| [inc/plugins/advancedfunctionality/addons/advancedprofileui/modules/profile_providers.php:27](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedprofileui/modules/profile_providers.php#L27) | `function af_apui_register_profile_reward_provider(string $key, callable $provider): bool` |
| [inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php:1999](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php#L1999) | `function af_aam_add_alert(int $uid, string $code, int $objectId = 0, int $fromUid = 0, array $extra = [], int $forced = 0): void` |
| [inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php:2040](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/advancedalertsandmentions.php#L2040) | `function af_aam_has_recent_alert(int $uid, string $code, int $fromUid = 0, int $objectId = 0, int $seconds = 86400): bool` |
| [inc/plugins/advancedfunctionality/addons/advancedstatistic/advancedstatistic.php:413](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedstatistic/advancedstatistic.php#L413) | `function af_advancedstatistic_get_board_stats(): array` |
| [inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php:1209](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php#L1209) | `function af_advancedpostcounter_increment_post($posthandler): void` |
| [inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php:93](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php#L93) | `function af_ajsb_allowed_assets_for_page(): array` |
| [inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php:118](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php#L118) | `function af_advancedjsbandle_pre_output(string &$page = ''): void` |
| [inc/plugins/advancedfunctionality/addons/balance/balance.php:791](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/balance/balance.php#L791) | `function af_balance_get(int $uid): array` |
| [inc/plugins/advancedfunctionality/addons/balance/balance.php:813](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/balance/balance.php#L813) | `function af_balance_add($uid, string $kind, $amount, array $meta = []): array` |
| [inc/plugins/advancedfunctionality/addons/balance/balance.php:833](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/balance/balance.php#L833) | `function af_balance_apply_scaled_delta(int $uid, string $kind, int $scaled, array $meta = [], bool $bypassNegativeAwardRule = false): array` |

### Существующие ACP-классы

Frontend API большинства аддонов процедурные; перечисленные ниже классы — ACP-контроллеры, не Combat API.

| Файл | Класс EXISTING |
|---|---|
| [inc/plugins/advancedfunctionality/addons/charactersheets/admin.php:11](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/charactersheets/admin.php#L11) | `class AF_Admin_Charactersheets` |
| [inc/plugins/advancedfunctionality/addons/advancedinventory/admin.php:8](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedinventory/admin.php#L8) | `class AF_Admin_Advancedinventory` |
| [inc/plugins/advancedfunctionality/addons/advancedshop/admin.php:5](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedshop/admin.php#L5) | `class AF_Admin_Advancedshop` |
| [inc/plugins/advancedfunctionality/addons/advancedthreadfields/admin.php:24](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedthreadfields/admin.php#L24) | `class AF_Admin_Advancedthreadfields` |
| [inc/plugins/advancedfunctionality/addons/knowledgebase/admin.php:4](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/knowledgebase/admin.php#L4) | `class AF_Admin_KnowledgeBase` |
| [inc/plugins/advancedfunctionality/addons/adaptivethemeframework/admin.php:5](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/admin.php#L5) | `class AF_Admin_Adaptivethemeframework` |
| [inc/plugins/advancedfunctionality/addons/advancededitor/admin.php:222](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancededitor/admin.php#L222) | `class AF_Admin_AdvancedEditor` |
| [inc/plugins/advancedfunctionality/addons/advancedprofileui/admin.php:11](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedprofileui/admin.php#L11) | `class AF_Admin_Advancedprofileui` |
| [inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/admin.php:11](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/admin.php#L11) | `class AF_Admin_AdvancedAlertsAndMentions` |
| [inc/plugins/advancedfunctionality/addons/balance/admin.php:4](https://github.com/CaptainPaws/mybb-advancedfunctionality/blob/7728cc7f0319ed49b425f2ec3ceed717c60895b4/inc/plugins/advancedfunctionality/addons/balance/admin.php#L4) | `class AF_Admin_Balance` |

### Версии manifests (не runtime-сертификация PHP 8.5)

| Аддон | version |
|---|---|
| charactersheets | 1.0.0 |
| advancedinventory | 1.0.0 |
| advancedshop | 1.2.0 |
| advancedthreadfields | 1.0.0 |
| knowledgebase | 1.0.5 |
| adaptivethemeframework | 0.32.12 |
| advancededitor | 1.1.9 |
| advancedprofileui | 1.3.0 |
| advancedalertsandmentions | 1.1.2 |
| advancedstatistic | 1.0.0 |
| balance | 1.0.0 |
| advancedcharacters | 1.0.0 |
| advancedjsbandle | 1.0.3 |
| advancedpostcounter | 1.0.0 |
