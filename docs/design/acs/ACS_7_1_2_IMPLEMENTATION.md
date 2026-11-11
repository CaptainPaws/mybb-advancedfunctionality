# ACS-7.1.2 — Control delegation, revocation и общие права группы

База: `main` `41eea5fe` (merge ACS-7.1.1, PR #1037). Основание: A1.41–A1.43, A1.49, A1.60; действующие R2/IC-INV-1 и отчёты 6.3.2/7.1.1/PublishService. Один ограниченный PR. `docs/ROADMAP.md`, MyBB Core, CharacterSheets, Inventory owner protocol, формулы и RNG не изменяются. ACS-7.2.1 не начинается.

## Реальная схема и данные

В main таблица delegations отсутствовала: она была только в Architecture §5. Добавлена **015_delegations**, с теми же проектными колонками и индексами:

- `id`, `session_id`, `owner_uid`, `delegate_uid`, `status`, `delegation_version`, `valid_until`, `scope_json`;
- PRIMARY(id), delegate_scope(session_id,delegate_uid,status), owner_scope(owner_uid,status);
- штатный prefix, InnoDB, utf8mb4_unicode_ci, ROW_FORMAT=DYNAMIC, логические связи без новых FK.

Registry/readiness теперь `015_delegations`. Миграции 001–014 и их checksums не переписаны. Точного совпадения уже существующей таблицы runner требует штатным readback; несовместимые таблицы не исправляет и не очищает скрытно.

`scope_json` — типизированный объект schema_version=1: scope=session, **либо** точные entity_ids **либо** control_group_id, permitted_actions, valid_from, reason, authority и audit_refs. Owner/delegate/session/version/expiry/status — отдельные колонки. JSON IDs/versions/timestamps сериализуются строками. Ссылки аудита — grant_operation_key и revoke_operation_key, связанные с существующими operations/events/event_details. Revoke сохраняет actor/reason/time и прежний grant, меняет status и повышает delegation_version.

Exact scope не распространяется на остальных питомцев/существ владельца. Явно выбранный group scope распространяется на принадлежащие тому же owner существа этой player group, включая её питомцев; смена owner или session не переносит разрешение. На выдачу — максимум 100 entity IDs/участников группы, как технический предел запроса. Делегирование NPC не заменяет режимы 7.1.1: owner-consent delegation здесь относится к character/pet с подтверждённым owner и player group.

## API, выдача, отзыв и замена GM

`Application/DelegationService.php` / `Infrastructure/DelegationPersistence.php` используют прежний SessionRepository command ledger/transaction/advisory/session lock. Нет второго журнала или стороннего transaction manager.

Штатный AF transport:

- GET `delegation_list(session_id,after)` — разрешённые metadata без snapshots/prose/raw Inventory/reason/quest internals. Обычный участник видит только свои issued/received grants; GM — сессионные metadata. SQL фильтрует права **до** pagination.
- POST `delegation_grant`: session_id, expected_version, operation_key, reason, owner_uid (по умолчанию authenticated actor), delegate_uid, entity_ids либо control_group_id, permitted_actions, valid_from, valid_until либо duration_seconds.
- POST `delegation_revoke`: session_id, expected_version, delegation_id, delegation_version, operation_key, reason.

Actor и CSRF берутся из MyBB. Указанные пользователи существуют, имеют текущую playable session membership и native доступ к связанной теме. Каждое существо/группа проверяется на точный owner/session/group kind; данные клиента не становятся разрешением. Создание имеет initial version=1 и session CAS; отзыв — дополнительно row CAS. Повтор committed key возвращает прежний результат после повторной авторизации без новой выдачи/аудита. Изменённый payload с тем же key запрещён. Future start и expiry проверяются на каждой новой операции, без фоновой мутации GET.

Владелец выдаёт свои разрешения. Отозвать может владелец, сам делегат (отказ от полученного права) либо субъект с отдельным доказанным replacement authority. Делегат не может переделегировать чужих существ. Grant требует active session; revoke доступен и для сохранённого cleanup-контекста закрытой сессии при действующем доступе.

Обычная GM role **не** разрешает замену за другого owner. `SessionAccess::delegationReplacement` допускает только:

1. В ACP — отдельный native permission `advancedfunctionality.acs_delegation_override` вместе с native ACP/session/thread context. `cancp`, super-admin или AF index сами по себе этот ключ не заменяют.
2. Для persisted session owner/gm — заранее записанное доверенным quest composition правило в `session.config_json.delegation_replacement_rules`: schema_version, rule_id, approved_by_uid, точные entity_ids либо control_group_id, permitted_actions, valid_from/valid_until. approved_by_uid должен совпадать с владельцем существ; scope/categories должны покрывать выдачу; expiry grant не может выйти за expiry правила.

Authority/ref сохраняются в audit. HTTP-флага «правило одобрено» и endpoint для создания такого одобрения нет. Эта поставка потребляет заранее утверждённую конфигурацию/native ACL; не выдаёт их по запросу GM и не добавляет общий bypass.

## Гранулярные действия и Inventory

Обязательная базовая категория — `ordinary`. Дополнительные категории: `item_consume`, `equipment_change`, `escape`, `surrender`. Неизвестные категории, transfer/grant/redelegate/permanent-owner/CS/GM grants отвергаются. Escape/surrender — отдельные проверяемые категории допуска, **не** реализация отсутствующих механических команд побега/капитуляции; наличие флага не включает неподдержанный Resolver capability.

AuthorizationService разрешает owned entity делегату только при действующем точном/group consent, текущей роли/интервале и сохранённом исходном owner assignment. Совпадение controller_uid не используется как delegation grant. Исходная controller assignment, owner_uid, sheet_id, group и pinned snapshots не переписываются.

Предметные операции дополнительно проходят **реальный** IC-INV-1 OwnerProtocol на прежнем authoritative writer:

- scoped `CombatInventoryAccess` требует текущую item/equipment category **и** независимые исходные `combat_operations_enabled`/`inventory_spending_enabled` owner controller;
- actor остаётся UID делегата, owner_uid предмета остаётся исходным владельцем. Internal InventoryRead получает доверенный entity/category scope; raw Inventory не отдаётся участникам через новый endpoint;
- authorization_ref для делегата связывает session/entity/category; OwnerProtocol сохраняет прежний actor/context samePrincipal и durable receipt checks. Lookup missing-key учитывает штатный actor placeholder; существующие чужие receipts этим не присваиваются;
- допустимы только lookup/reserve/consume/release. Transfer/grant не разрешены этим owner composition даже при item grant. Permanent Inventory bindings и CS не изменяются;
- custody не создаёт delegation или spending rights. Его прежние quantity/reservation/temporary-use barriers продолжают действовать;
- прежние чужие author reservations нельзя присвоить через новую роль: samePrincipal остаётся обязательным. Expired/revoked item seal не приводит к автоматическому release, новому расходу или обходу recovery. Сохранённые holds требуют разрешённого existing owner/recovery workflow; неизвестный исход не повторяется вслепую.

## Общая группа и публикация R2

Draft/Builder/Query/Clock/Reaction, Seal, Publish.issue и Combat writer проверяют один текущий grant, включая конкретную категорию действия. Confirmation dependency содержит delegation ID/version плюс controller version; item actions имеют отдельный proof своей категории. Regrant с новым ID не оживляет прежнее confirmation. SQL facts fence включает delegation rows и перепроверяет role/control/category под locks; перед projection выполняется ещё одна проверка срока, который мог истечь во время расчёта.

Делегат и владелец используют исходные control_group, turn, deadline, AP, seal quota, cooldowns и EntityTurn/reaction limit. Новый round_turn, бюджет или слот поста не создаются. Владелец и делегат могут подготовить свои планы, но штатные active_turn_id claim, unresolved barrier, UNIQUE turn/result и terminal receipt допускают один успешный боевой пост этой группы. Native автором остаётся действующий пользователь; это не impersonation владельца персонажа.

Обычные competing планы после успешного terminal commit сохраняются как cancelled read-only история с prose/mechanics/tape и без возврата quota; список `superseded_delegated_drafts` аудируется вместе с победителем. Competing item seals остаются барьером для их owner-managed reservations. Нет автоматической отмены publishing/unknown submissions.

**Revoke намеренно разрешён при unresolved publication**: его команда исключена только из предварительного publication barrier, но сохраняет тот же session lock/CAS/audit. Поэтому владелец может отозвать право после Seal, issue или native post confirmation; последующий writer не применяет механику. Если commit выиграл lock раньше revoke, сохраняется прежний COMMITTED receipt, а отзыв относится к будущим действиям. Доказанный terminal replay не требует оживлять истёкший grant и не вызывает новый RNG/INSERT.

R2 Gateway и native publication flow не заменены. После отзыва с уже созданным постом сохраняются journal/tape/prose и прежний recovery path. Новый MyBB post, повторный consume или переброс ради исправления конфликта не создаются. Publication production gates остаются выключенными до прежней installed acceptance.

## Минимальный UI и приватность

В существующих Sessions/entity и GM management панелях показаны owner/delegate, entity IDs либо group, категории, срок и status, выдача и кнопка отзыва с CSRF/session/row versions/reason. GM форма не превращает запрос replacement в право на него. GET только читает; вывод экранирован. Отдельный frontend/editor не создаётся.

Делегирование не делает участника GM и не открывает полный snapshot персонажа. Чтобы обычный делегат мог выбрать разрешённую цель без утечки GM-only данных, в той же GM-панели добавлена маленькая команда `encounter_participant_visibility`: только native-authorized GM, session/encounter/participant CAS, reason и audit; scope `gm` либо `participants`. Default остаётся gm. Она открывает metadata цели/обычный combat preview, а не entity snapshot API. Закрытые targets/их имена не предлагаются делегату; сам делегат изменить доступность не может.

## Фактические проверки

**PHP 8.5.12 / MySQL 8.0.46**, isolated minimal runtime, `/tmp/acs222-socket/mysql8.sock`. Случайные disposable DB удаляются fixture в finally; MyBB fixture tables остаются MyISAM. Production DB и полный установленный форум не подключались.

| Проверка | Результат |
| --- | --- |
| `php -l` изменённых/новых PHP; `git diff --check` | PASS |
| `tests/acs_delegation_sql.php SOCKET` | PASS: 015 interruption/resume/repeat и сохранение старых migration records; exact/group consent; default item deny; future start/expiry; active role/native scope; запрет re-delegation/обычному GM; owner-approved quest replacement и запрет расширения категорий; реальная чужая session с membership |
| Audit/CAS/idempotency | PASS: повтор grant/revoke, stale row version, поздний grant/revoke audit failure с полным rollback |
| Shared group rights | PASS: обычный delegated preview → Draft/Seal/confirm → PublishService.issue → Writer; общий turn/AP и seal quota; два SQL handles owner/delegate, один claim/COMMITTED/AP spend, replay и сохранение terminal receipt после revoke |
| Revocation | PASS: до issue, после post_confirmed, expired grant и injected revoke в preparation/BEGIN gap; никаких новых rolls/механических изменений; сохраняются prose/seals |
| Item/equipment grants | PASS: отдельный расходник grant проходит реальный reserve/consume; equipment grant — ACS swap без изменения permanent Inventory binding; без категории или owner spending gate отказ; transfer/grant от этого authorizer запрещены; item owner UID остаётся прежним |
| UI/privacy/targets | PASS: actual renderer GET без writes, metadata/escaping; snapshot API остаётся owner-only; закрытая цель не видна; делегат не может выдать target visibility; явная GM команда разрешает одну цель |
| `tests/acs_npc_controllers_sql.php SOCKET` | PASS: режимы и concurrency ACS-7.1.1 сохранены |
| `tests/acs_combat_items_sql.php SOCKET` | PASS: прежние item costs, atomic rollback/replay и permanent ownership |
| `tests/acs_inventory_outage_sql.php SOCKET` | PASS: ordinary non-item combat при outage и прежний recovery/rollback |

Запуск: `docker exec acs711-php php /repo/tests/acs_delegation_sql.php /tmp/acs222-socket/mysql8.sock`; так же три существующих SQL fixtures. Новый fixture переиспользует `acs_combat_items_sql.php`, InventoryFixtureDatabase и прежнюю explicit reference-only normalized ability preparation. Он использует известный preconfirmed native PID, не запускает Gateway/новый MyBB INSERT. Group/pet scope дополнительно проверен policy fixture; реального pet provider по-прежнему нет. Полный suite, installed MyBB E2E и native ACL edit screen не запускались.

## Установка и ограничения

1. Сохранить backup и разрешить прежние pending publication перед обновлением, используя их прежние IDs/receipts.
2. Обновить файлы; явно выполнить ACP ACS → Обновить/продолжить миграции до completed `015_delegations`. Reactivation не заменяет upgrade.
3. На staging выдать согласованное ordinary разрешение, при необходимости явно открыть NPC-цель участникам; проверить deny предметов без категории/owner gate, revoke и expired state. Для quest replacement/native admin override нужна отдельная заранее утверждённая server/ACL конфигурация.

Расширение dependency proof требует обновлённой сводки/reconfirm существующих sealed планов на прежнем tape. Уже frozen stale submissions остаются в прежнем recovery, без нового post. Некорректные/legacy untyped delegation JSON не конвертируются в grants. Не добавлены owner transfer, Inventory property APIs, CS writes, forced-control overlays, pet plugin, новые escape/surrender mechanics или approval editor правил квеста. Безопасный откат — отключение ACS с сохранением данных; старый код не должен обходить новые grants/revocations. Down migration/uninstall для отката не применять. ACS-7.2.1 не начат.
