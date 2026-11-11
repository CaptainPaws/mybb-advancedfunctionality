# ACS-6.2.2 — Combat item commit

База: main `5356025d` (merge ACS-6.2.1). Один Combat Integration PR. Прочитаны четыре нормативных ACS-документа, IC-INV-1, отчёты ACS-6.1.1–6.2.1, ACS-4.2.3, Combat Writer/Publish/Recovery R2 и ACS-5.3.2. Применены A1.1–A1.3, A1.11–A1.13. `docs/ROADMAP.md` не меняется; перехода к ACS-6.3.1 нет.

## Реализованный узкий scope

- `item:equip:ID` / `item:swap:ID`: оружие только в `weapon_mainhand`, 1 AP в бою. Проверяются текущая занятость слота, фактический owner, явный arpg/item KB kind, согласованный с Inventory subtype, KB slot allowlist, ограничения, текущий combat equipment version и глобальные резервы. Нельзя подменить swap посредством equip занятого слота. Исходящее оружие защищается резервом до commit; входящее остаётся зарезервированным как активный комплект после commit. Промежуточное/исходящее оружие освобождается owner API без изменения количества или постоянного владельца.
- `item:consume:ID`: одно использование собственного расходника, qty=1, 1 AP по A1.2. Поддержан **только явный** KB `item.on_use.effects = [{op: add_hp, value: positive_integer}, ...]`, self target, без дополнительной стоимости/кулдауна/условий. A1.3: стоимость оплачивается при выполнении, включая full HP / нулевой фактический эффект; выбор preview-сценария miss не возвращает предмет или AP. Это детерминированное on_use, а не атака, которой приписан выдуманный hit-check.
- Последовательность проходит существующие Builder → Resolver/Effects → Seal → frozen submission → Publish prevalidation → CombatCommitWriter → Recovery. Swap изменяет только виртуальный комплект/нужные характеристики; следующее действие видит изменения. Potion изменяет виртуальный HP через существующий Effects. Недопустимость любого шага блокирует весь пост до owner consume и persistent HP/AP.
- В текущем конструкторе один селектор содержит выбранный instance + допустимую операцию, название/ID и стоимость; разрешённая цель для поддержанного scope — «себя». Существующие draft/preview/seal/confirm, immutable prefix и recovery UI используются повторно. У предметных сводок отдельная подпись без фиктивной вероятности попадания. Read-only confirmed card различает item outcome и не показывает unused tape как применённый hit/crit. Нового редактора, дизайна этапа 11 или дополнительного MyBB-поста нет.
- Вне активного/приостановленного столкновения `item_equip` даёт разрешённую смену **0 AP**. POST/CSRF, session CAS, own actor, server grants, текущий слот и owner reservations обязательны. Команда не меняет постоянные E/S или CharacterSheets. Для боя/паузы этот путь закрыт; он не является обходом seal quota.

## Права и owner boundary

`Infrastructure/Adapters/CombatInventoryAccess.php` — конкретная final server policy: authenticated UID/canview, постоянный own owner, текущая session membership, один active exclusive controller, expiry, active entity, persisted `combat_operations_enabled=true` **и отдельный** `inventory_spending_enabled=true`. Последний не принимается из HTTP и не выдаётся автоматически. Registration defaults=false сохранены. View, GM role и временное управление чужим существом не дают права расхода. Release/lookup перепроверяют scope/полномочия; historical lookup не требует, чтобы актор продолжал действовать в бою.

Read-only selection check проверяет права уже при preview/status и prepublication validation, без CSRF callbacks внутри SQL boundary. Для mutations CSRF/native thread access проверены существующим application pipeline, POST и persisted grants перепроверяются owner policy. Revocation после native dispatch снова проверяется на commit; неподтверждённый результат уходит в существующий recovery, не в повторный consume.

`InventoryAdapter::forCombat()` создаёт owner engine только на том же authoritative mysqli writer. Default constructor без composition остаётся закрыт. Нет HTTP transport owner API, fallback `af_inv_remove_item()`, прямого ACS SQL к I.qty/uid, автоматического DDL или включения публикации.

## Доказанная атомарная граница

Новая **узкая owner-side participation**, не общая MyBB/ACS transaction:

1. `AF_ADVINV_OwnerProtocol::participate()` допускает только reserve/consume/release внутри существующей transaction, autocommit=1/active transaction, native utf8mb4 writer, проверенные InnoDB I/E/S/owner state/reservations/external_operations и их ключи/типы. Не начинает, не коммитит и не откатывает caller transaction. Все SQL/authorization errors передаются outer caller для полного rollback. Standalone шесть API сохраняют прежние BEGIN/COMMIT/lookup semantics.
2. Возвращаемый `AF_ADVINV_ProvisionalOwnerResult` нельзя JSON-сериализовать как durable success. Внутренний caller берёт provisional receipt; наружу результат выходит **только после outer COMMIT**. Owner journal, quantities, reservations и ACS seal/result/receipt входят в ту же фактическую InnoDB transaction на том же соединении. Это не поддержка другого DB handle, другой БД или remote owner; такие composition не разрешены этим протоколом.
3. Lock order: существующий ACS advisory/session/fact locks → общий owner guard (единственный UID authenticated собственника) → current items в стабильном ID order через owner reader → owner operation key/items/reservations/bindings → CAS/receipts. Все участвующие item actions принадлежат одному UID; multiple actors не вводят другой owner set. Owner policy делает только текущие non-locking ACS reads, не берёт ACS locks после owner guard и не вызывает MyBB/plugin callbacks.
4. Seal берёт owner guard **до** полного Inventory read и reserve. Все новые резервы и frozen item definitions подготавливаются до первого нового draw. Business shortage/permission/SQL failure до draw откатывает весь незавершённый seal; после draw действуют прежние durable history/quota semantics. Reservation не уменьшает I.qty.
5. Combat writer сначала получает подтверждённый старый tape/result. Под ACS/owner locks он повторно проверяет inventory version из prepared dependencies и mechanical item fingerprint, включая preparation→transaction gap. Временный result ещё не означает effect/consume. Consume работает по reservation ID/version и исходному owner reserve receipt, key `acs:item:consume:SUBMISSION:SEAL_ID`. Уже существующий owner consume без terminal ACS result считается orphan/conflict, а не поводом применить эффект ещё раз.
6. В **одном** outer COMMIT фиксируются owner consume/release receipts, актуальный комплект с equipment CAS, HP/AP, result, post_link, terminal receipt и outbox. Поздняя SQL ошибка откатывает всё, включая owner changes. В confirmed result/receipt сохранены owner receipts. Нативный MyBB pid принадлежит прежнему независимому R2 протоколу и не откатывается вместе с этой транзакцией.
7. Потеря COMMIT acknowledgement → authoritative ACS terminal receipt/Recovery inspect; provisional owner response не считается успехом. Terminal result содержит те же durable owner receipts; `recover` возвращает найденный COMMITTED без повторного Writer/consume. При недоступной БД/непроверенном результате сохраняется existing recovery barrier. Нет слепого consume, нового submission или дополнительного native post.

Owner reserve дополнен **reservation-only weapon** (KB arpg_item, qty=1). Consume оружия по-прежнему запрещён. Общая available_qty остаётся owned−все active reservations по всем сессиям; обычные Inventory/Shop writers продолжают общую защиту ACS-6.1.3. Отдельной ACS таблицы количества/резервов нет.

## Снимки, бонусы, отмена

`ItemActionService` использует полный owner reader ACS-6.2.1 (`perPage`, не UI export). Descriptor фиксирует instance в ключе; qty=1/операция/slot/mechanical fingerprint/KB pin/параметры/owner receipt сохраняются в immutable item + sealed-definition snapshots. Смена qty/updated_at сама по себе не заменяет KB mechanics; authoritative доступность всегда проверяет owner. Новые item snapshots сохраняют полное выбранное source definition и ограниченный исполняемый spec, а не raw inventory.

`Domain/Items.php` использует существующие `Modifiers::stage()` и `Effects::simulate()`. Разрешены только доказуемые flat bonuses accuracy/speed/crit_rate/crit_dmg/def. Baseline CS totals остаются неизменными; из них снимается ровно прежний включённый weapon contribution и добавляется текущий. Учитывается provenance всех затронутых traces; percent, conditions, clamp/nonlinear ambiguity, HP/max-resource changes и неизвестные weapon/equip effects блокируются. Постоянный CS и Appearance/mask не пересоздаются. Equipment CAS и entity version меняются атомарно; источник будущих расчётов — original baseline + retained combat loadout, без двойного применения.

Cancel освобождает только неиспользованные candidate/outgoing reservations, учитывая оставшийся active prefix. History, immutable tape и SUM(ap_cost) quota не удаляются и не возвращаются. Новая попытка получает новый history ordinal/key; после исчерпания общей 3 AP quota новый reserve/draw запрещён. Прежние generic hit/crit tape candidates для детерминированных item actions сохранены как unused; preview/status/commit/recovery не создают новых samples.

Схема ACS остаётся **013_equipment**: новых tables/migrations не требуется. 001–013 не переписаны, активация не пересоздаёт комплект. Обычные userdata/production DB не использовались.

## Точечные проверки

**PHP 8.5.12** (official CLI image), **MySQL 8.0.46** (official minimal distribution), isolated disposable socket `/tmp/acs222-socket/item-mysql.sock`. MyBB posts/threads/forums — отдельные fixture MyISAM, known pid seed; Gateway вообще не загружается. Это **не installed acceptance MyBB 1.8.41**. Промежуточный запуск PHP 8.4.27 также использовался для диагностики; MariaDB не выдаётся за проверку owner schema gate.

- `tests/acs_combat_items_domain.php`: реальный Resolver/Effects/Modifiers, swap=1 AP, следующий attack видит delta, armor denied, insufficient/repeated instance, consume при miss/нулевом эффекте, nonlinear provenance refusal. Подключаемые существующие focused regressions подтверждают прежние damage/status/effect contracts, без полного suite.
- `tests/acs_combat_items_sql.php SOCKET`: реальные CharacterSheets snapshot/registration, Inventory, Builder/Seal и Writer. Preview/Seal не меняют qty/HP/AP; durable global reserves; отсутствие dependency/spending grant и его отзыв до публикации; repeated seal без draw; neighboring session denied; конфликт owner version до подготовки и **в gap между preparation и BEGIN с другого handle**; late outbox fault откатывает owner и ACS; успешный COMMIT + потеря acknowledgement → Recovery lookup; одна consume/один post_link/прежний pid; permanent equipped не меняется.
- `... SOCKET cancel`: duplicate cancel, release, outgoing protection cleanup, quota без refund, новая попытка оплачивает оставшуюся квоту, следующий reseal denied без RNG.
- `... SOCKET shortage`: два использования последней единицы; отказ preview и атомарный отказ seal до RNG, без частичных резервов/quota.
- `... SOCKET free`: 0 AP вне боя, replay/CAS, retained modifier delta ровно один раз, постоянный binding прежний.
- `ACS_INV_FIXTURE_SOCKET=SOCKET php tests/acs_inventory_owner_sql.php`: прежний standalone protocol, actual two-handle reserve/concurrent consume, over-request, permission/revocation, scope, payload conflict, rollback, unknown COMMIT/lookup и ambient transaction refusal; PHP 8.5.12 / MySQL 8.0.46 — PASS. Узкий DB facade вынесен без изменения поведения для повторного использования fixtures.
- `php -l` изменённых/новых PHP, `node --check` изменённого JS, `git diff --check`. Полный test suite не запускался.

## UNSUPPORTED / BLOCKED

**Pass — UNSUPPORTED**: отсутствует утверждённая spatial/custody policy этапов 8/6.3. Нет выдуманной дистанции, передачи ownership, автоматического transfer или незащищённого temporary control. Возвращается `ACS_ITEM_PASS_SPATIAL_UNSUPPORTED` до reserve/RNG. Это явная граница данной реализации.

Броня, аксессуары с ещё не связанными ограничениями, offhand/многоячеечное оружие, смена на уже занятый экземпляр в том же post, weapon damage/use-ability bindings, percent/nonlinear/conditional modifiers, иные on_use effects/ресурсы/цели, KB override стоимости, pet/чужой Inventory и remote/multi-connection owner commit остаются UNSUPPORTED/BLOCKED. Наличие неподдержанного определения не превращает его в боевое свойство. Не реализуются custody, loot, финальный UI этапа 11 или ACS-6.3.1.

Для установленного форума остаются BLOCKED: фактическая owner schema/engines/upgrade acceptance, source/native hooks, безопасная выдача server grants и общая publication acceptance. `publication_enabled=false`, preparation defaults=false и существующий test gate не повышаются автоматически. На поддержанной same-writer composition critical owner cost уже подключён к commit; это не разрешение включить все предметы или production публикацию.

## Где находится код / ограниченная ручная приёмка

ACS root: `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`. Новые `Application/ItemActionService.php`, `Domain/Items.php`, `Infrastructure/ItemPersistence.php`, `Infrastructure/ItemCommitPersistence.php`, `Infrastructure/Adapters/CombatInventoryAccess.php`. Интеграция: Builder/Query/Seal, Resolver (Effects/Modifiers используются без копирования), SealPersistence, InventoryAdapter, CombatCommitWriter/Persistence и существующие builder/card presentation. Owner primitive — `advancedinventory/modules/OwnerProtocol.php`.

На staging-копии с проверенным ledger 013 и owner schema/grants проверить selector → draft → preview → seal/confirm → разрешённый существующий native publication → card/recovery. Сверить AP, owner receipts/количество, неизменность постоянных E/S/CS и mask; повторить потерю ответа и отмену. Общую публикацию этим PR не включать.

Для бесплатной смены существующий AF endpoint принимает POST `op=item_equip`, `session_id`, `entity_id`, `ability_key=item:swap:ID` (либо `item:equip:ID` для пустого слота), `expected_version` текущей сессии, уникальный `operation_key`, `my_post_key`. UID/grants/qty/cost/results клиент не передаёт. Этот минимальный путь не является новым custody или equipment editor этапа 11.
