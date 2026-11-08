# ACS-6.1.2 — Inventory reserve / consume / release / lookup

База: main `440c69af` (merge ACS-6.1.1 / #1029). Один Backend PR, ACS-6.1.3 не начат. Прочитаны Master Specification, Technical Architecture, ACS Implementation Roadmap, Integration Contracts, ACS-6.1.1, owner protocol/schema, ACS-4.3.1B-R2 Combat Writer и ACS-4.3.3-R2 Recovery Service. R2 boundary сохранена: общей MyBB+ACS+Inventory transaction нет.

## Реализовано

Владелец — `inc/plugins/advancedfunctionality/addons/advancedinventory/modules/`:

- `OwnerProtocol.php`, `AF_ADVINV_OwnerProtocol`: внутренние reserve/consume/release/lookup. Принимает проверяемый v1 envelope из ACS-6.1.1, пересчитывает canonical payload_hash; rejects unknown fields, подменённый actor, bool/string/float qty, неверные versions и scope. Это typed PHP API, не parser сырого HTTP JSON. Strict qty 1..2147483647, versions 1..2^53−1, uid/item_id UINT, reservation IDs decimal BIGINT strings. Text limits ограничены байтами UTF-8, NUL/пустая причина запрещены.
- `OwnerAccess.php`: отдельный серверный интерфейс авторизации. Default MyBB policy разрешает **только собственнику**, с authenticated runtime uid, неблокированным canview, Inventory scope `owner:UID`, authorization_ref `inventory-owner-v1`, POST и CSRF для mutation. Staff/view permissions не разрешают чужой расход. ACS scope, GM impersonation и delegation default policy отвергает. Policy проверяется до tx, под owner guard и на replay; request authorization_ref не является разрешением сам по себе. Server policy должна читать свежие grants и не выполнять owner mutations/внешнее I/O из authorize.
- `OwnerSchema.php`: явный owner DDL plan для трёх InnoDB таблиц из ACS-6.1.1. Не создаёт копию qty, не меняет I.uid, не содержит ACS tables. Не выполняется автоматически. Существующие I/E/S проверяются на InnoDB, критические типы, PK/unique/index и case-sensitive operation key; schema mismatch блокирует engine. Пустая/частичная schema не объявляется готовой.

`advancedcombatsystem/modules/Infrastructure/Adapters/InventoryAdapter.php`, `AF_ACS_InventoryAdapter` — тонкий delegate для четырёх операций. Не содержит SQL, add/remove, схемы, нового Inventory или боевой механики. Без Inventory dependency возвращает `dependency_unavailable / ACS_INVENTORY_UNAVAILABLE`. Без server-supplied owner composition возвращает `ACS_INVENTORY_WRITERS_NOT_INTEGRATED`. Client flags/настройки readiness отсутствуют. Trusted server composition может передать конкретный owner engine; это внутренний seam, используемый SQL fixture, а не production factory или публичный endpoint.

### Граница готовности

**Реализован и проверен внутренний owner engine; штатное включение глобальных резерваций остаётся BLOCKED.** Все старые writers из ACS-6.1.1 ещё не переведены на общий протокол. Не существует безопасного способа включить этот engine рядом с их unguarded sell/delete/reset/grant/equip. Поэтому Inventory bootstrap/install/activate не подключают новый модуль/DDL, production gateway не зарегистрирован, штатный ACS adapter закрыт. Прямое server construction — низкоуровневая интеграция, требующая изоляции от legacy writers; запрещено объявлять её доказательством безопасности production. Произвольный privileged SQL также вне гарантий engine.

Это сознательно сохраняет запрет обхода общего резерва: текущие обычные пользовательские данные и пользовательские потоки не затронуты, а штатный путь не создаёт активных резервов, которые старый магазин мог бы игнорировать. Полный перевод writers — следующая отдельная задача, не скрытое расширение этого PR. Production schema/engine/ACL acceptance и безопасная регистрация gateway ещё требуются.

На этой стадии engine принимает **equipment / consumable либо ammo**, исключает Appearance и прочие item kinds с непроверенными побочными эффектами (`ITEM_KIND_UNSUPPORTED`). Это owner quantity protocol, не KB normalization или разрешение боевого использования. Gameplay definition/пины/условия остаются за KB и будущим combat coordinator. Transfer/grant/custody/equipment actions здесь не реализованы.

## SQL protocol и durable result

Engine использует native mysqli либо MyBB `write_link` на том же authoritative writer; не создаёт другое соединение и не читает replica. Требует 64-bit PHP и utf8mb4. До BEGIN проверяет autocommit и выполняет некоммитящий SET TRANSACTION READ COMMITTED: ambient BEGIN/autocommit=0 отвергается без rollback/commit чужой работы. Нет nested/joinable tx, callbacks Appearance/Balance и MyBB hooks.

Порядок: read-only receipt identity hint, затем reservation discovery только для новой key → authorize → BEGIN → owner guard upsert/lock → existing operation key locking read/replay → свежая authorize → item row lock → active reservations id ASC → target reservation → при полном consume E затем S, bindings по slot → owner CAS → receipt insert → COMMIT. Qty/availability читаются current reads после locks, сумма **всех** active reservations item по всем sessions/systems. Owner guard сериализует новые запросы одного uid; item lock дополняет его. Operation journal insert выполняется последним в этой же tx, без отдельно committed executing marker. Unique key конфликт при гонке разных intents откатывает всю tx и перечитывает исходный receipt; никакая частичная mutation не коммитится.

Reserve сравнивает owner-wide inventory_version, qty <= owned−SUM(active.remaining), создаёт reservation version=1 и receipt status=prepared, но не уменьшает owned. Consume/release принимают owner/item/scope только из сохранённого reservation и повторно проверяют их под locks. Для каждой используемой reservation требуется исходный durable reserve receipt с prepared outcome, совпадающим owner/item/context/external_ref/initial_qty и правильным result_hash; одной строки reservation недостаточно.

Consume требует active status, exact reservation version и qty <= remaining; уменьшает I.qty и reservation.remaining на одинаковое количество. При owned=0 удаляет I только при отсутствии чужого активного остатка, атомарно очищает E/S; origin reservation и receipts сохраняются. Partial consume оставляет active. Release освобождает весь **текущий неиспользованный** remaining, не увеличивает I.qty и не отменяет consume; причина/права обязательны. Новая release key для terminal reservation возвращает released/ALREADY_TERMINAL/qty=0 без повышения версии. Реальное изменение повышает owner version и reservation version ровно один раз. Excess/stale/not-owned outcomes сохраняются как rejected без изменения qty/reservation/version.

Receipt содержит schema_version, operation_key/type, payload_hash, authenticated actor_uid, scope, item_id, qty, version, external_ref, inventory_versions как список `{uid,before,after}`, reservation `{id,remaining_qty,version,status}` и result_hash. Top-level version — reservation version, если reservation существует, иначе observed owner version. Это уточнение формы owner-wide version data из ACS-6.1.1. Receipt terminal для **операции**; reserve prepared не означает consume committed. Повтор key+того же canonical intent возвращает immutable receipt **до** stale version checks; другой intent/hash → `INV_IDEMPOTENCY_CONFLICT`. Перестановка object keys не меняет hash/intent. Replay использует owner из сохранённого receipt, даже если исходный запрос был rejected для ещё не существовавшего reservation ID, позднее выданного другому владельцу. Replay снова требует действующие полномочия, закрытые права не восстанавливаются старым receipt.

Lookup не создаёт guard/receipt, не вызывает mutation, читает writer и проверяет actor/scope/result_hash и привязку receipt к journal. Возвращает прежний prepared/committed/released/rejected outcome; prepared сохранён для reserve согласно v1, не переименован в committed. Достоверное отсутствие — not_found. Неизвестный COMMIT/SQL outcome или недоступный writer — recovery_required, без выдуманного qty/version/external_ref/result_hash. Identity/CSRF/schema/integrity errors остаются безопасными именованными отказами, не успехом и не доказанным not_found.

COMMIT exception не означает rollback: engine возвращает recovery_required, не удаляет повторно и не выполняет release. После потери ack — lookup прежней key на доступном writer; found возвращает receipt, доказанный not_found разрешает повтор только прежнего intent/key. При продолжающейся tx guards/unique key сериализуют повтор. SQL ошибка до COMMIT откатывает всю owner tx; даже при поздней ошибке receipt qty/reservation/version не расходятся. Автоматического retry/deadlock loop нет; caller сохраняет identity и сначала выполняет lookup. Никакого нового RNG или нового payload на retry.

## Проверки

Только disposable Docker MySQL **8.0.46**, network=none; PHP **8.4.27** CLI из af-dev:local, не installed MyBB/PHP 8.5.12. Fixture создаёт новую случайную `acs612_fixture_*` БД и удаляет её в finally; MyBB users fixture остаётся MyISAM. Фактические I/E/S воспроизведены отдельно от owner DDL. Ни production SQL, ни полный suite не запускались.

```
# Отдельный пустой MySQL container без доступа к сети форума:
docker run -d --name acs-612-mysql --network none -e MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0.46
# После readiness; PHP image должна содержать mysqli + proc_open:
docker run --rm --network container:acs-612-mysql -v "$PWD:/app:ro" --entrypoint php af-dev:local /app/tests/acs_inventory_owner_sql.php
python tests/acs_inventory_owner_contract.py
git diff --check
```

`php -l` всех новых/изменённых PHP — PASS. SQL fixture — PASS:

- Два независимых child processes/DB handles **одновременно ждут owner guard**, что подтверждено performance_schema.data_lock_waits до освобождения parent lock. Две ACS sessions/одна единица → один prepared, второй rejected, общий reserved=1.
- Over-reserve и excessive consume не удаляют item; строгий qty и hash validation; owner и reservation version conflicts сохраняют rejected receipts.
- Partial consume, duplicate reserve/consume/release, **конкурентный duplicate consume** → один immutable receipt и одно списание; terminal release не возвращает consumed qty.
- Full consume очищает bindings; lookup/receipts/reservations переживают удаление item.
- Другой payload той же key конфликтует; чужой item/actor/scope, отсутствие spending right и отзыв policy между preflight и owner lock не позволяют consume/release/replay.
- В mysqli test subclass имитируется **реальный успешный COMMIT, затем потеря ack**. Production engine возвращает recovery_required, lookup на другом доступном handle возвращает committed, retry не тратит повторно.
- Ошибка INSERT receipt после изменения item/reservation → полный rollback qty, version, reservation, отсутствие receipt. Faults живут только в test subclass; production flags/callbacks не добавлены.
- Ambient BEGIN и autocommit=0 не коммитятся и не откатываются engine; missing dependency/default adapter gate и MyISAM binding schema дают безопасный отказ; недоступный writer lookup не возвращает not_found.
- Default MyBB policy: чужой owner/ACS scope и mutation без POST/CSRF запрещены. Test server policy не является production ACS grant provider.

Старый контрактный test ACS-6.1.1 обновлён только в утверждении об adapter: теперь проверяет наличие закрытого gate и отсутствие SQL/remove_item; legacy writer matrix не объявлена safe. Новый engine проверяется отдельным реальным SQL fixture, не прежним regex scanner. SQL fixture проверяет стандартный mysqli exception mode и MYSQLI_REPORT_OFF: реальный trigger отклоняет INSERT receipt, false execute распознаётся и qty/version откатываются. Engine также проверяет false-return prepare/bind/commit.

## BLOCKED / вне scope

Глобальная production writer integration, actual installed schema/upgrade/rollback policy, deployment factory, server ACS spending/delegation provider, combat commit→owner acknowledgement/reconciliation, publication settings и installed acceptance — BLOCKED. Прямой вызов owner engine из preview/seal/Resolver/Draft/PublishService/RecoveryService не добавлен. Боевой расход остаётся выключенным до согласованного durable coordinator; receipt owner не означает подтверждённый ACS combat result. `docs/ROADMAP.md` не изменён, ACS-6.1.3 не начинается автоматически.
