# ACS-8.1.2 — Awareness, detection, stealth и audience filtering

База main `15af48e1` (ACS-8.1.1, PR #1044). Требования A1.57, A1.72, A1.81.
Один backend/UI PR. `docs/ROADMAP.md` не изменён. ACS-8.2.1 не начат.

## Состояние и migration

Resumable `017_awareness` добавляет предусмотренную Architecture af_acs_awareness:
encounter, subject_kind/id, audience_kind/id, level, awareness_version,
whitelisted details_json и UNIQUE audience_subject. Старые checksums 001–016
не менялись. Как в других ACS projections, scope проверяется приложением под
session/encounter lock; второго entity/object registry и physical FK не добавлено.

Subject kinds — entity/object, subject_id — существующий entity_id. Отсутствие
записи означает unknown. Механическое состояние objects/entity_states/statuses
не определяет уровень знания и не меняется выдачей awareness. Существуют
suspected/detected/identified и явная unknown correction. Audience: actor,
control_group (default detection scope), side, encounter. Принадлежность к группе
даёт только доступ к явно записанному знанию этой аудитории.

AwarenessPersistence делает CAS и проверяет source snapshots/tuple scope.
Повторяемые check receipts находятся в существующей operations с kind
awareness.check, hashed operation key, payload hash и неизменяемым результатом;
новой таблицы RNG/попыток нет. Before/after knowledge и causal effects входят в
существующий parent combat/Clock/GM event. Поздняя ошибка откатывает awareness,
attempts, AP, statuses, spawn, result/outbox и parent receipt одной транзакцией.

## KB subset и реальные bindings

Полный opt-in binding добавлен в ACS_KB_MECHANICS_CONTRACT.md, раздел ACS-8.1.2.

- Pinned object-template.v2/bestiary perception: tags, whitelisted disclosure
  на каждом уровне, optional base stealth и fixed once passive source_turn_end.
  Нет чтения latest KB при search/seal/commit.
- Active self search/share ability: positive AP cost, empty effects, явный
  awareness_action. Scope — subjects с pinned perception и KB tag в encounter,
  без передачи скрытого subject ID клиентом и без пространственной эвристики.
- Search использует одну проверку для всего tag scope независимо от числа/no-match
  subjects. Chance — явный KB integer 0..100. Preview не делает бросок; Seal
  сохраняет существующие uniform53 samples. Detection использует hit slot этой
  отдельной action, crit не используется. Commit/reconfirmation берут прежний
  sample по sealed-action:<id>/hit/0; нового RNG нет.
- Durable attempt identity: sealed-action:<id>:<subject_kind>:<subject_id>.
  One-shot passive identity: passive:<subject_kind>:<subject_id>, с сохранённой
  реальной entity_turn:<id>:end cause. Повтор commit/boundary не повторяет check.
- Share — отдельное KB действие с AP cost. Source actor должен знать subject на
  передаваемом уровне; details копируются из его знания, без добавления private
  KB name/других сведений. Actor/group/side берутся с сервера. Проза не раскрывает
  информацию. Повтор той же publication не создаёт нового знания/расхода.
- Creature stealth — typed mechanical_parameters.stealth существующего combat
  status. Все invisible/hidden/concealed поддержаны как независимые состояния;
  targeting forbid/identified задан KB. Обычные Status/Effects/Clock управляют
  сроком/снятием. Detection не удаляет эти эффекты, не меняет AP/ход/cooldown.

Query/Builder/Resolver/Effects/Clock и Writer расширены; второй resolver,
публикационный протокол или scheduler не создавался. Порядок optional summary
hashes в Seal/Writer совпадает, включая действия с предметами. Resolver повторяет
mechanical stealth gate перед атакой/после redirect. Внутренний Effects способен
затронуть server-authorized скрытый subject без implicit reveal. Добавлен явный fixed hidden_mass_effect self action: non-spatial tag scope
нетерминальных destructible objects, разрешённый damage_type, AP исполнителя.
Он достигает unknown recipients через Effects и не раскрывает identity. Нет
public recipient IDs/counts и implicit knowledge changes. Creature/геометрические
area selectors и неизвестные KB predicates остаются UNSUPPORTED.

## Единая server policy и читатели

AudienceService строит текущие аудитории по native/session access, действующим
controller/delegation grants и сторонам. Owner/delegate не получают знание всех
objects encounter. Temporary control не расширяет приватную group knowledge.

Participant visibility остаётся отдельным authorization ceiling. Старое
visibility=participants без awareness row теперь не раскрывает чужую identity;
при обновлении нужны явное GM disclosure или поддержанный KB detection event.
Migration не угадывает и не создаёт знание из membership/старого visibility flag.
Visibility=gm нельзя обойти identified записью. Owner knowledge своей identity
является отдельным действующим authorization grant, не новым ledger default.

Фильтруются до DTO:

- Query selectors, Builder actors/targets/legal choices и knowledge notices;
- Draft save/restore и отменённая seal history: unknown references не отражаются
  клиенту; серверные prose/actions/tape не переписываются;
- Preview/sealed summaries: нет найденных hidden subjects/количеств/raw pins,
  player hidden-state hashes не возвращаются; spawn summary содержит только hash;
- CombatCardQuery: прежний persisted GM detail gate сверяется с текущей session
  authority; player cards не содержат hidden names/чисел/идентичностей;
- Publish/Recovery public receipt projection: unknown spawns отсутствуют, raw
  snapshot IDs/hashes не передаются игроку. Stored receipt и verifier не менялись.

Suspected/detected notices содержат только разрешённый hint и level, без
subject_kind/id/name. Identified добавляет identity и только разрешённые details.
Ни identified, ни GM knowledge не обходят mechanical targeting=forbid. Неизвестная
цель и несуществующий ID дают одинаковый безопасный refusal до RNG.

Существующий journal/management UI доступен только GM; raw Repository event API
остаётся внутренним. Существующий outbox combat.committed содержит event/submission/
result refs без нового subject payload; AAM/export/statistics consumers в репозитории
не включены. Их доставку/публичную фильтрацию не заявляем реализованной: будущие
consumers обязаны вызывать ту же audience policy, а не публиковать raw event_details.

Request cache карточек включает viewer, session/encounter versions, native admin
flag, role/assignment/delegation rows, audience/awareness versions и текущую секунду
для expiry. Native thread access проверяется перед cache lookup. Builder/Audience
DTO читаются заново; общий GM/player cache не введён.

## Минимальный интерфейс и GM

Builder показывает только whitelisted knowledge hints/levels, действие «Поиск»
из разрешённой KB ability и GM-only audience/version details. Динамический текст
пишется через textContent. JS cache key обновлён на 0812; редизайна нет.

EncounterService::awareness()->set — узкий GM backend с POST/CSRF/native/session
rights, expected session/encounter/awareness versions, reason, operation key и
append-only audit. Unknown/untyped JSON details отвергаются. Новой общей JSON
панели, скрытых target entries или автоматической проверки на GET нет.

## Проверки

PHP **8.5.12**, disposable MySQL **8.0.46**, authoritative
InventoryFixtureDatabase + прежний stage-7/8 initial setup. Production не
подключалась; MyBB fixture posts/users остаются MyISAM.

Новая `tests/acs_awareness_sql.php`:

- migration install/repeat/resume; unknown trap отсутствует в Builder JSON/HTML,
  selectors, notices и counts; GET/preview не пишут и не делают detection;
- suspected/detected без identity/name; identified — только разрешённые details;
- default control_group, actor passive и side disclosure, другой audience без знания;
- GM permission/CSRF, stale awareness CAS, whitelist details и late audit rollback;
- реальная cloak+search sequence в Draft/Seal/Publish/Writer, цена 1 AP каждого,
  отдельный сохранённый sample; знание не меняется до commit;
- изменение knowledge после Seal требует reconfirm без новых rolls;
- поздний outbox failure откатывает knowledge/attempts/AP/statuses; retry/replay
  возвращают прежний итог;
- once passive + hidden object trigger исполняются через реальный Clock end;
- invisible/hidden/concealed combat status остаётся после обнаружения; identified
  owner/delegate/GM knowledge не открывает forbid attack;
- отдельные share + failed new search + unknown spawn в следующем group turn:
  общие 3 AP, отсутствие implicit reveal из prose/failed check;
- передача не дополняет hint отправителя private KB name;
- player cards и Publish/Recovery receipt не раскрывают unknown spawn, stored
  receipt сохраняет identity; cache изолирован по viewer/GM и awareness changes;
- внутренний Effects damage скрытому объекту не изменяет unknown knowledge;
- реальный KB mass pulse в следующем turn наносит fixed durability damage двум
  hidden objects, включая новый unknown spawn; 1 AP, неизменная identity secrecy
  и duplicate commit без второго урона. Отдельный passive может дать suspected
  hint на parent boundary, но mass effect не создаёт identified row.

Целевые регрессии: acs_environment_sql.php, acs_stage7_integration_sql.php,
acs_side_control_sql.php и acs_delegation_sql.php. Старый delegation fixture теперь
явно создаёт baseline knowledge через GM command; его visibility flag больше не
считается discovery. Stage-7 renderer assertion обновлён только на JS cache 0812.
Выполнены php -l изменённых/новых PHP, node --check builder.js и git diff --check.
Полный suite и browser E2E не запускались; HTML проверен серверным renderer fixture.

## Ограничения

Нет spatial/zone detection, arbitrary callbacks, contested/formula detection,
random passive checks, stat penalties/bonuses, reveal_on_action, автоматических
creature/geometric area target selectors или новых AAM/export/statistics consumers. Неподдержанные
контракты блокируются. Нет нового Inventory/CharacterSheets owner behavior или
MyBB INSERT. R2/Recovery механика и installed publication gates сохранены;
локальный PASS не означает installed warprift.ru acceptance. ACS-8.2.1 не начат.
