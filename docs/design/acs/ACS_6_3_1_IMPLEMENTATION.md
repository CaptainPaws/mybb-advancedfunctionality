# ACS-6.3.1 — Custody, confiscation и temporary item user

База: main `e9e14493` (ACS-6.2.2). Прочитаны Master Specification, Technical Architecture, ACS Implementation Roadmap, Integration Contracts, IC-INV-1 и отчёты ACS-6.1.2/6.2.1/6.2.2. Применены A1.32/A1.33. Один Backend PR; `docs/ROADMAP.md` не меняется. Перехода к ACS-6.3.2 нет.

## Реализовано

- `Application/CustodyService.php`: GM-команды `confiscate`, `assign`, `temporary_use`, `return`, read-only `view`/`lookup`. API dispatch: `custody_confiscate`, `custody_assign`, `custody_temporary_use`, `custody_return`, `custody_view`, `custody_lookup`. Изменения требуют POST/CSRF, текущей session GM role либо роли owner у фактического владельца сессии, native thread access, причины до 512 байт, operation key, session CAS и custody CAS. Роль ACP не обходит проверку. Владелец предмета и GM могут быть разными UID.
- `Infrastructure/CustodyPersistence.php`: один retained record на `(session_id, inventory_item_id)`, payload hash, исходные ссылки equipment/snapshot/version, исходный собственник, хранитель, один nullable временный пользователь, явное разрешение GM, ограничения возврата и обязательства. Все команды проходят существующий SessionRepository operation journal, события и event_details. Повтор исходного payload возвращает сохранённый результат до повторного применения; другой payload с тем же ключом конфликтует.
- Конфискация выключает все текущие ACS equipment bindings этого экземпляра в сессии (`active → custody`). Не меняет Inventory owner/qty, постоянные equipped/support slots, CharacterSheets build_json, controllers, AP или MyBB posts. Назначение хранителя само по себе не включает бонусы и снимает прежнее разрешение использования.
- Только отдельная GM-команда разрешает temporary use текущему хранителю. Проверяются активная сущность той же сессии и занятость слота обычным комплектом/другим временным предметом. Единственное поле active_user исключает двух пользователей одного экземпляра. Оно не предоставляет управление персонажем либо право owner consume.
- `Domain/Custody.php`: проверка hashed payload и отдельная проекция текущих бонусов через существующий Items/Modifiers. Учтённые CharacterSheets бонусы вычитаются ровно один раз; временные добавляются только разрешённому пользователю. Неизвестное/нелинейное происхождение блокирует операцию с rollback. Исходный механический baseline не переписывается.
- `ItemActionService::prepare/loadoutStats`, `QueryService`, `CombatCommitPersistence`: held item недоступен исходному пользователю, временно занятый mainhand конфликтует с обычным equip/swap, custody входит в зависимости и commit fence. Состояние kit и entity versions меняется с CAS. Обычные атаки получают текущую проекцию через прежний QueryService.
- Return снимает временного пользователя, освобождает глобальную резервацию через owner API и записывает событие. Остаточные bindings `custody → disabled`; прежняя экипировка автоматически не включается. Другая уже выбранная экипировка не перезаписывается. Records не удаляются при завершении боя/архивации; явный возврат допустим из архивированной сессии при сохранённых полномочиях.

## Inventory boundary и recovery

`Infrastructure/Adapters/CustodyInventoryAccess.php` даёт проверенному GM узкое серверное право читать Inventory зарегистрированного владельца и выполнять reserve/release в scope данной сессии. Это не default permission временного пользователя; consume/transfer/grant этим policy запрещены. Проверки persisted roles выполняются повторно внутри write boundary, без plugin callbacks и дополнительных ACS locks после owner guard.

Owner `OwnerProtocol::reservationEvidence()` проверяет действующую reservation, owner, scope и durable исходный reserve receipt. Это не ослабляет principal check обычного operation lookup. Допускается принятие уже существующего резерва **только** той же сессии, qty=1/version=1, связанного с активным pinned ACS equipment snapshot. Резерв другого seal/сессии не присваивается. Иначе создаётся новый IC-INV-1 reserve с inventory version. Reserve расширен на reservation-only arpg_item weapon/armor/gear/artifact qty=1; их consume по-прежнему запрещён.

Порядок: existing ACS advisory lock → session lock/pending-publication barrier → custody rows ORDER BY id → один owner UID guard → полный authoritative Inventory read/items → owner operation/items/reservations → custody/equipment/entity CAS → operation receipt/audit → outer COMMIT. Custody запрещена при любом активном sealed action сессии; pending публикации проверяет прежняя command boundary. Owner пишет только через IC-INV-1 participate, с прежней InnoDB schema gate, на том же mysqli writer. Разные read/write handles запрещены. Общей транзакции MyBB/ACS либо remote owner нет.

Owner reserve/release и ACS изменения фиксируются одной доказанной same-writer transaction. Поздняя ошибка откатывает обе стороны. Потерянный COMMIT acknowledgement разрешается `custody_lookup(session_id, operation_key)` под исходным действующим principal; найденный committed результат возвращается без повторного reserve/release. При недоступной БД/неподтверждённом receipt — existing recovery_required, новая команда не заменяет неизвестную. Lookup не изменяет состояние.

Глобальное available_qty по-прежнему считает AdvancedInventory: owned минус **все** active reservations, включая другие сессии. Custody не хранит альтернативное количество. Обычные writers сохраняют защиту ACS-6.1.3.

## Схема

Подтверждённый ledger main — `013_equipment`. Добавлена только `014_custody`, с проектной таблицей `af_acs_custody` и ключами PRIMARY(id), UNIQUE session_item, UNIQUE custody_once, custody_obligations(session_id,status). Версии 001–013 не изменены. Повтор миграции не пересоздаёт записи.

## Подтверждённые проверки

PHP **8.5.12**, MySQL **8.0.46**, локальный socket, случайные disposable базы с DROP в finally. Пользовательские данные форума не подключались.

- `php -l` всех изменённых и новых PHP; `git diff --check`.
- `tests/acs_custody_sql.php SOCKET` и `... SOCKET adopt`: настоящие Inventory/KB/CS регистрации, независимый GM UID8 и owner UID7; исходный и сменённый ACS weapon; confiscation, unchanged owner/qty, withdrawal modifiers, explicit temporary user, reassignment removes former user, duplicate custody/return, session/custody CAS conflict, permission denied, held equip denied, return without auto-restore, unchanged controls/AP/permanent equip/build_json.
- Вторая реальная ACS session не может зарезервировать held единицу: durable INSUFFICIENT_AVAILABLE. Это проверка общего учёта, не имитация двух параллельных потоков.
- SQL trigger failure при audit INSERT после owner/ACS writes проверяет полный rollback confiscate и return. Injected loss COMMIT response проверяет authoritative lookup и безопасный повтор без повторного применения.
- Повтор migration runner сохраняет custody; архивирование сохраняет обязательства и позволяет явный возврат.
- `acs_inventory_owner_sql.php`: reserve race двумя independent handles, concurrent consume, over-request, duplicate release/lookup, payload conflict, spending permissions, unknown COMMIT и rollback.
- Точечные прежние `acs_combat_items_sql.php` normal/free/cancel: combat swap+consume, late rollback, duplicate submission, zero-AP free equip и отмена seal с сохранением quota. Полный suite не запускался.

## Ограничения / BLOCKED

Поддержаны только qty=1, уже pinned и активно экипированные ACS экземпляры с доказанными плоскими бонусами accuracy/speed/crit_rate/crit_dmg/def. Loose items, stacks, неподдержанные mechanical properties, HP/nonlinear эффекты и недостоверное provenance закрыты ошибкой, а не догадкой. Автоматическое KB-разрешение temporary use пока не интерпретируется: требуется явное GM-разрешение. Боевой action confiscate, чужой consume, передача собственности, spatial pass, автоматический возврат при освобождении пленника, custody UI и административный force обход не реализованы. Для owner transfer остаётся отдельный API. Повторная конфискация returned записи допустима только после явной актуальной экипировки и при том же постоянном владельце; смена owner требует отдельного согласования provenance.

Production combat publication flags не включаются. Проверка полноценной установки MyBB 1.8.41 остаётся deployment acceptance, а не утверждается локальными fixtures. ACS-6.3.2 не начат.
