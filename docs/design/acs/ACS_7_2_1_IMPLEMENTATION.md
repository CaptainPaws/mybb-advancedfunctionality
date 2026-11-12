# ACS-7.2.1 — Spawn identity и idempotent creation

База: main `8f5c75c1` (merge ACS-7.1.2, PR #1038). Требования A1.47,
A1.69, A1.84; четыре нормативных документа ACS, KB B1/B3 contracts и отчёт
5.3.2. Один Backend PR. `docs/ROADMAP.md` не изменён; ACS-7.2.2 не реализован.

## Реальная схема

Новых таблиц, колонок и миграций **нет**. Readiness остаётся
`015_delegations`. В существующей 002_entities уже есть nullable group,
source_entity_id, template_snapshot_id, spawn_key и UNIQUE spawn_once(spawn_key).
Они используются напрямую; второго реестра существ/объектов нет.

Общий Repository::insertEntityIdentity используется прежней регистрацией
и новым commit projection. `kind=summon|object`, owner_uid/sheet_id=NULL.
Definition JSON хранит source_event/index, encounter/source/side, zone=NULL,
точный spawn spec, исходный turn и admission. Отдельный insert-only snapshot
`ic-acs.spawn-snapshot.v1` содержит полный ранее закреплённый IC-KB DTO,
характеристики/object definition и только достижимый из шаблона pinned ability graph
(без посторонних способностей призывателя).

Существо получает собственный active HP/state, ресурс hp, пустые layers и
пустые cooldown/status collections (отсутствие строк). Другие ресурсы и
неподдержанные механические поля шаблона блокируются. Object получает только
identity/snapshot/properties, без фиктивного HP=0, группы, AP и ordinary turn.

## Исполнение и границы

Domain/Spawn — чистая проверка opt-in B1 summon binding, компиляция и intent;
используются прежние Resolver/Effects и AP источника. Контракт с полным
примером и допустимыми полями добавлен в ACS_KB_MECHANICS_CONTRACT.md.
Неполные legacy summon не превращаются в NPC. Object — явный KB
combat_template, а не эвристика по декоративному имени.

RegistrationSource закрепляет шаблоны и зависимые ability refs при
регистрации. Builder/Seal/Writer используют retained graph; доступность
живой KB записи после регистрации не меняет версию и HP призыва.
Preview и Seal не пишут identities. Self-target ability используется через
существующий backend Draft/Seal/Publish протокол с прежними native,
controller/delegation, CSRF, expected-version и confirmation проверками.
Frontend для выбора summon не добавлен: этот PR ограничен backend.

SpawnPersistence вызывается только из приватного finish CombatCommitWriter
после создания combat.resolved, внутри прежней транзакции с session lock,
locked facts fence, проверкой controller grants и проекцией AP.
Source event — это существующее событие механического commit; дополнительного
поста/событийного реестра нет. Плоский spawn_index стабилен по
порядку action/effect/ordinal. Ключ `spawn:event:index` имеет durable UNIQUE,
а повтор подтверждённого commit возвращает прежний terminal receipt с
массивом spawns (entity ID, source event/index, snapshot ID/hash, group).
Идентичности также входят в hashed event details/result changes.

Любая поздняя ошибка откатывает event, identities, snapshots, state/resources,
controllers/groups, AP projection и result/receipt. Существующий native post
R2 не переписывается. Actor хранится в parent event; причинность — retained
ability/effect и подтверждённый submission, без отдельной административной
команды создания.

Source/source сохраняет группу и базовое назначение источника; не копирует
Inventory/административные grants и не делает делегата owner. Независимая
NPC group допускается только явной парой independent/gm_pool, получает
next_round_pending. До ACS-7.2.2 summon не входит в participant/roster,
не получает EntityTurn/AP/post и не становится доступным обычному controller
API. Это создание identity, а не преждевременная реализация roster admission.

## Проверки

Disposable MySQL **8.0.46**, PHP **8.5.12**, существующая authoritative
ACS/Inventory SQL fixture. Новая `tests/acs_spawn_sql.php` проверяет:

- один и несколько экземпляров одного шаблона с разными ID/snapshots;
- отсутствие записей preview/Seal и расход одного общего AP при commit;
- физический отказ UNIQUE spawn_once на повторной паре event/index;
- повтор commit с теми же идентичностями без новых записей;
- два SQL handle: contention под lock, затем прежний receipt;
- пассивный объект без group/state/resources/controller/participant/turn;
- late failure на outbox после создания: полный rollback и успешный retry;
- pinned DTO/HP после удаления доступности живого KB template;
- накопительный limit в последовательности и durable counts;
- неверный fingerprint, отсутствующий KB template, неизвестные fields и
  spatial policy — отказ;
- independent/gm_pool policy разрешена NPC и запрещена игроку (Domain);
  object cover properties без stage-8 capability запрещены.

Выполнены `php -l` для изменённых PHP и `git diff --check`.
Точечные регрессии: NPC controllers, delegation и combat items SQL fixtures.
Полный test suite не запускался.

## Ограничения

Binding сейчас поддерживает фиксированный count, отдельную self способность
и положительную AP cost, только HP ресурс шаблона. Не поддерживает смешанные
spawn+damage/status способности, upkeep, on_spawn эффекты или spatial условия.
Lifetime сохранён и привязан к исходному turn; expiry/despawn и использование
в roster требуют следующего этапа. Лимиты консервативно считают все созданные
identity type/key в encounter, включая истёкшие и прежние версии шаблона;
автоматического освобождения лимита нет.

Не реализованы stage-8 object interactions/укрытия/разрушение/зоны, transform,
loot, CharacterSheets creation, Inventory pet management и новые owner rights.
Ни один spawn не восстанавливает ресурсы источника и не даёт второй AP budget.
