# ACS-3.3.2 — Base Resolver / read-only preview

Исходная main: `204095fccb8c4a3aa5aef77e8fe1660fedc49c21`.
ACS 0.16.0; Resolver `acs-resolver-preview-1`; Numeric `binary64-half_up-v1`.
Статус: ready_for_manual_test, с ограничениями интеграции ниже. Design Baseline
не изменён. Миграций нет (005_clock_resources). Этап4 не начат.

## Файлы

Новые в `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:
- modules/Domain/Resolver.php;
- modules/Application/QueryService.php;
- modules/Presentation/BuilderController.php.

Изменены там же:
- advancedcombatsystem.php — отдельный read-only AF op combat_preview;
- modules/Presentation/Encounters.php — вкладка «Предпросмотр» GM-панели ATF;
- modules/Infrastructure/EncounterRepository.php — SELECT stats_json и read-only
  guard неизвестных persistent effects/barrier projections;
- modules/Domain/Damage.php — retained registry projection из проверенного
  registration JSON, без повторной математики Damage и live KB lookup;
- modules/Domain/HitCrit.php — rate100/false не является конфликтом при полном
  запрете крита (критическая проверка тогда не выполняется);
- admin.php, manifest.php — версии.

Также: tests/acs_resolver_regression.php, tests/acs_preview_regression.php,
этот отчёт. AF Core, MyBB, KB, CS, Inventory, Editor и шаблоны не изменены.

## Единственный Domain-путь

`AF_ACS_Resolver::resolve(context, ordered_actions, fixed_random_tape=[], mode='preview')`
принимает подготовленный доверенным сервером context и ordered selectors
`{actor_id,ability_key,targets:[id],scenario:hit|crit|miss}`. Поддерживается одна
цель на действие, последовательность действий сохраняется. Scenario — явное
условие, не выпавший результат. Непустой tape и mode seal/commit отвергаются.
RNG, tape, submission и persistent spending не создаются.

Context содержит checked virtual state, effective stats, defenses/catalog,
current pending/started turns, server-approved abilities и allowed target IDs.
Неизвестные context/action/ability extensions не исполняются автоматически.
Actor/group/turn/state/AP/source eligibility проверяются; ошибка любого действия
прерывает весь расчёт без частичного результата. Context не принимается endpoint
из POST; его строит QueryService по сохранённым данным.

Используются **существующие** реализации:
- Formula для exact pinned AST/parameters, без live lookup;
- Modifiers для явно подготовленных стадий, с equipment provenance;
- HitCrit для вероятностей и заданного условного исхода;
- Damage для DEF/RES/penetration/immunity и одного integer mixed budget;
- Effects для атомарных virtual costs, барьеров, HP/overkill/overheal.

Raw damage formula требует output number/rounding none/unit damage: нельзя
округлить компонент раньше D1.13. Lifesteal resource formula требует integer
output с собственной явной политикой, после которой Effects не округляет heal.
Нет собственного fallback raw=ATK: такой исходной формулы baseline не задаёт.
Стоимость платится в виртуальной копии и при условном промахе. Невозможные
сценарии (crit при0%, noncrit при100% с can_crit, miss при auto_hit) отвергаются.
P_crit явно относится к криту при попадании, не к отдельному броску на промахе.

## Reflection и lifesteal

Domain поддерживает **подготовленные** rules с rule_id, source_ref
(key/version/fingerprint), pinned formula/pin/parameters, basis_input, unit и
requires_active. Дубликат rule_id — ошибка, а не двойное применение. Источник
и само разрешение правила подтверждает caller/adapter до Domain; формульные
version/hash проверяются Compiler. Новая KB schema/editor здесь не создаётся.

Порядок одного действия:
1. Primary costs и primary hit/crit → Damage → barriers → actual HP loss;
2. Immediate life/sustained-stop signals;
3. Reflection;
4. Lifesteal;
5. Единственный запрос full end-condition check, без смены статуса encounter.

Reflection выбирает явную basis reflect_absorbed / reflect_hp_loss /
reflect_incoming. Последняя требует явного incoming_stage=
post_defense_integer_budget; другие/неуказанные стадии BLOCKED вместо догадки.
Размер отражения задаёт **формула KB**, не коэффициент engine. У правила свои
penetration parameters, не унаследованные от исходного атакующего. Далее Damage
применяет DEF/RES/иммунитет получателя и Effects — его барьеры/HP. True сохраняет
обычный bypass. Бесплатного crit/on_hit нет; can_crit=true пока unsupported.

Reflection исполняется через прямой Damage/Effects, а не рекурсивный Resolver:
не порождает новое reflection/lifesteal исходной атаки. В trace есть causal link
к action:index:primary и отдельный производный ID, source ref и антициклические flags.

Lifesteal получает **только сумму фактической primary hp_loss**. Не raw, не budget,
не absorbed и не reflection hp_loss. При0 потери heal0. Overkill не увеличивает
базу. Размер восстановления задаёт pinned formula, без встроенного процента.
Восстановление идёт после reflection, с overheal clamp. Уже созданный lifesteal
допустим после incapacitated, если requires_active=false; при dead не восстанавливает.
Sustained не возобновляется автоматически. Каждая промежуточная Effects trace
помечена end-check-deferred; full check запрашивается после всех стадий.

## Серверный QueryService / безопасность

`select/inspect` — серверные read-only helpers; `preview(input)` требует POST/CSRF.
Применяются существующие native forum/session checks, GM visibility scope и
отдельно exclusive controller/owner check через ClockService.available. GM или
ACP admin не становится владельцем/контроллером чужого персонажа.

Источник — session-scoped entity/template snapshot с проверкой content_hash,
registration/schema/numeric versions, owner/sheet/group identity, stats_json,
empty supported layers, HP projection и immutable frozen roster hash/member pins.
Pending actor turn должен быть текущим, open и started, не closed. Граница начала
не выполняется из preview. Target должен быть engaged, известен GM visibility
scope и иметь hostile side relation. Неизвестная visibility не раскрывается.

Нужны включённый KB и, для character, CS; тяжёлые модули этих аддонов при preview
не подключаются. Нет re-snapshot или сохраняющей синхронизации. Сравниваются
session/encounter/round, entity/state, group/turn/entity-turn versions и snapshot
hashes; после расчёта выполняется readback прав/версий. Присланные damage/AP/stats,
policy/KB JSON, modes seal/commit не принимаются транспортом.

Кэш pinned_abilities — не разрешение действия. Используются только **прямые**
owner-normalized ability refs из sourceSnapshot abilities / bestiary ability_keys,
с совпадающими key/version/fingerprint. Транзитивная dependency-only запись
недоступна как действие. Конфликтующие inline overrides BLOCKED; явный0 не теряется.

## Поддерживаемый публичный срез и BLOCKED

Public preview пока доступен только GM/owner/native ACP view scope и только
существующему exclusive контроллеру атакующего. Per-player awareness/visibility
policy отсутствует, поэтому обычный player не получает target stats или P_hit,
выдающую скрытую Speed. Это ограничение интерфейса, не повышение GM до владельца.

Поддерживается standalone active single_enemy ability, прямо закреплённая в source,
с положительной целой AP cost (при отсутствии — утверждённый A1.2 default1),
structured damage effects с explicit numeric flat value либо retained B2 formula.
Для public formula поддержаны только полные pinned fixed parameters / constant AST,
output_unit=damage, number/none; несуществующие dynamic bindings не угадываются.
17 стихий канонизированы владельцем; направление не подставляется как damage_element.

Zero-valued legacy optional controls не объявляют новые потребляемые механики.
Ненулевые max_charges/range/cast_time/cooldowns/duration/resource costs, 0AP repeat
limits, conditions/triggers/status/stacking/upgrades и неизвестные opt-in modifiers
дают BLOCKED. Ненулевой elemental bonus без подготовленного execution contract
также BLOCKED. Нет автоматического обхода этих условий даже для miss.

**В текущем KB отсутствуют typed reflection/lifesteal contracts/adapters**, поэтому
их production hydration в QueryService не заявляется READY. Domain-контракты
реализованы и проверены на явно подготовленных pinned formula fixtures. Если
данные содержат такую неподдержанную механику, public preview отказывает, а не
пропускает её. Persistent barrier/effect tables ещё отсутствуют: public получает
empty supported state; при обнаружении новых таблиц/layers — BLOCKED до hydration.
Чистый Resolver уже обрабатывает подготовленные virtual barriers/derived rules.

Старые snapshots не переписываются для добавления каталогов. Damage может брать
registry identity из verified registration с CS registry/alias metadata либо
KB IC-KB.read.v1 READY canonical_elements_17/legacy_element_aliases capabilities.
Математика Damage не менялась. Individual element-definition hash/version здесь
**null**, когда их нет в captured owner payload; сохраняется настоящий parent
registration hash и известная версия registry contract. Хеши не выдумываются.
При отсутствии такого доказательства preview_retained_registry_unavailable.

## Endpoint / UI / DTO

POST `xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem&op=combat_preview`.
Body: my_post_key, session_id, encounter_id, actor_id, target_id, ability_key,
scenario и preview_versions (JSON объекта, полученного серверной формой).
Версии — CAS/readback guard, не доказательство прав. Размер body ограничен16KiB,
versions4KiB. Никаких URL формул/произвольного SQL/PHP/JSON plan.

В существующей ATF GM-панели темы вкладка «Предпросмотр»: GET выбирает участников,
POST считает явно подписанный условный hit/crit/miss. Никаких новых hooks/assets,
второго редактора или глобального builder runtime.

Success DTO: conditional/label/scenario, actor/target/ability IDs, server costs,
P_hit/P_evade/P_crit/crit_rate/can_crit, components с DEF/RES/allocation, прогнозные
consequences, versions, persistent=false, visibility_scope=gm. Только для указанного
GM scope; raw KB definitions/AST/owner context не отправляются. Обычная сериализация
Domain result ещё строже: только engine/mode/conditional/persistent metadata.

serverPlan дополнительно содержит full ordered formula/modifier/effects traces,
causal derived events и final end-check request; serverState — detached итог,
serverInitial — исходная копия. Эти данные не являются commit/tape.
Ошибки безопасны, с code/error_id и русским пояснением. Transport обходит
session_command diagnostic writer: **даже ошибки preview не пишут SQL diagnostics**.
Технический error_id использует случайный request identifier; это не combat roll,
не попадает в Domain plan и не выбирает исход сценария.

## Минимальные проверки

PHP lint затронутых PHP, git diff --check — успешно.
- `php tests/acs_resolver_regression.php`: Formula/Modifiers integration, baseline
  physical preview, conditional crit/miss, Space, barrier, derived causal order,
  reflection anti-cycle/собственное penetration, lifesteal по HP loss/overkill,
  zero loss, после incapacitated и requires_active, duplicate rule, target/AP errors,
  atomic failure, запрет tape и seal, детерминизм/закрытая JSON trace.
- `php tests/acs_hit_crit_regression.php`: существующий affected primitive regression.
- `php tests/acs_preview_regression.php <disposable socket>`: настоящие repositories,
  native role/control/CSRF, saved refs и direct grant vs dependency cache, pending
  boundary, version conflict, invalid target/client damage, UI form и repeat preview.
  Число live KB/CS owner reads не растёт; весь набор ACS state/resources/snapshots/
  operations/events/diagnostics и owner fixture tables сравнивается до/после.

MariaDB10.11 запускалась только изолированно, network none, random fixture DB
удаляется в finally. Записи fixtures/миграции нужны только для подготовки теста;
preview их не вызывает. Production не использовался. Полный suite/E2E/стресс-тесты
не выполнялись. Локально PHP8.4.24; PHP8.5.0 требует ручной приёмки.

## Ручная приёмка и откат

1. Использовать активную тестовую сессию с hostile sides и правильно зарегистрированным
   actor, которого текущий GM действительно контролирует. Native forum access обязателен.
2. В KB/CS источник должен прямо ссылаться на поддерживаемую standalone damage ability.
   Она должна быть уже закреплена при регистрации; редактирование live KB старый snapshot
   не обновляет. Legacy inline previews/заряды/отсутствующая формула дадут понятный BLOCKED.
3. Запустить раунд и явно выполнить существующую границу начала EntityTurn, если она
   ещё не выполнена. Preview не запускает её автоматически.
4. В теме → GM-панель → Предпросмотр выбрать actor/target, действие и условный сценарий.
   Проверить P_hit/P_crit, DEF/RES и последствия, повторить запрос.
5. Сравнить реальные HP/AP, events и CS до/после; перезагрузка не меняет состояния.
6. Проверить отказ обычному player, чужому actor, stale version и invalid CSRF;
   открыть обычную тему без ACS — тяжёлые Domain modules не загружаются.

Откат — revert PR. Нет миграций/записанных последствий/tape, освобождать расходы
или пересчитывать историю не требуется. Seal/commit/combat_post/награды/Inventory
и этап4 не реализованы.
