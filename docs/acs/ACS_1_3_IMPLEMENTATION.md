# ACS-1.3 — Permissions, capability diagnostics и gates

Исходная main: `459c85d4ef68f7174037d02281b29937e361127d`.
Версия ACS: **0.3.0**; схема по-прежнему **001_foundation**.
Четыре исходных документа и Design Baseline не менялись. Боевой механики нет.

## Изменённые файлы

В `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`:

- Новый `modules/Application/AuthorizationService.php`: enum AF_ACS_Role и
  AF_ACS_AuthorizationService, matrix/decision/requirePermission.
- Новый `modules/Administration/Diagnostics.php`: source evidence, capabilities,
  schema, recentErrors и диагностический gate конкретной будущей операции.
- `admin.php`: пять стандартных вкладок Page/Table и проверка доступа до вывода.
- `advancedcombatsystem.php`: проверка глобального права перед lifecycle/CSRF.
- `manifest.php`: версия, RU/EN подписи и пояснения.
- `modules/Bootstrap/Context.php`: пояснение, что контекст не выдаёт права.
- `modules/Infrastructure/Repository.php`: защищённая bounded projection последних
  ошибок; details_json и произвольные payload не выбираются.

Добавлен `tests/acs_permissions_diagnostics_regression.php`, расширен существующий
`tests/acs_foundation_regression.php`; добавлен этот отчёт. AF Core, router,
внешние аддоны, JS, шаблоны, миграция и её checksum не изменены.

## Permissions matrix

| Идентификаторы | Область | Политика ACS-1.3 |
|---|---|---|
| admin.view, admin.permissions.view, admin.diagnostics.view, admin.integrations.view | global | Реальная MyBB ACP identity + cancp + server-resolved AF index permission |
| admin.schema.manage, admin.lifecycle | global | То же штатное разрешение AF, затем существующий POST/CSRF/confirmation lifecycle |
| session.view, session.manage | session | Без контекста SESSION_CONTEXT_REQUIRED; с ним NOT_IMPLEMENTED, deny |
| entity.control, entity.consume | entity | До проверенного ownership/controller контекста deny при любой роли |
| confirmed.correct | critical | Отдельный зарезервированный ID, deny |
| rewards.adjust | critical | Отдельный зарезервированный ID, deny |
| persistent.modify | critical | Отдельный зарезервированный ID, deny |
| control.transfer | critical | Отдельный зарезервированный ID, deny |
| session.reopen | critical | Отдельный зарезервированный ID, deny |
| admin.recover | critical | Отдельный зарезервированный ID, deny |

Неизвестное полномочие запрещено. Типизированные роли enum:
owner/gm/assistant_gm/player/observer. Роль, sessionId и пользовательские input
не являются доказательством права. Session resolver и назначение ролей появятся
в 2.2; сейчас все сессионные grants закрыты. GM не получает чужую собственность.
Нативный MyBB super administrator сохраняет доступ к существующим ACP facilities,
но не получает через это session/entity/critical grants.

На данном этапе базовые административные ID используют существующий AF `index`,
а не новые независимые настраиваемые флаги MyBB. Критические ID разделены, но не
назначаются: ни формы выдачи grants, ни произвольной матрицы из GET/POST нет.
Вкладка «Права» показывает фундамент, не фиктивный редактор назначений.

Будущий изменяющий endpoint обязан отдельно проверить session/forum/resource
ownership, CSRF, допустимую operation и expected version; critical command также
требует подтверждения, причины и атомарного audit event. Этот PR не открывает
таких endpoints и не предоставляет универсального mutation/repair bypass.

## Capability registry

| Контракт / capability | Проверка / ожидаемая блокировка | Этап |
|---|---|---|
| IC-CORE / core.facilities | Наличие исходных и загруженных стандартных AF функций + ACP controller resolver | 1.3 |
| IC-CORE / core.schema | Readback schema/migrations через существующий status(), без install/probe | 1.2 |
| IC-KB / kb.definition_reader | Проверены reader, normalizer, stat registry, math/mechanics contract declarations; executable definitions не проверены | 2.1 |
| IC-KB / kb.combat_snapshot | PROPOSED: pinned adapter IC-KB-1 отсутствует | 2.2 |
| IC-CS / cs.combat_snapshot | PROPOSED: calculator/view существует, но чистый immutable snapshot отсутствует | 2.2 |
| IC-INV / inv.reserve_consume | PROPOSED: getters/export/add/remove существуют, safe reserve/consume/lookup отсутствует | 6.1 |
| IC-MYBB / mybb.atomic_publish | PROPOSED: установленный PostDataHandler не аудирован как общий ACS publish coordinator | 4.1 |
| IC-THEME / theme.components | EXISTING API slots/register_component; ACS provider/точка конструктора не реализованы | 4.1 |
| IC-EDITOR / editor.combat_form | PROPOSED: обычный editor/preview не является seal/commit form contract | 4.1 |
| IC-AAM / aam.delivery | OPTIONAL_UNAVAILABLE: нет гарантированного idempotent outbox ack | 11 |
| IC-STATS / stats.consumer | OPTIONAL_UNAVAILABLE: нет ACS confirmed-event consumer | 10 |

Каждая строка содержит contract_id, owner, detected/enabled, version и её evidence,
interface_status, объявления/loaded/callable_now для конкретных API, readiness,
reason, implementation_blocker, roadmap_stage и remediation. Версия manifest —
наблюдаемая версия, а не сертификат safe API. Runtime MyBB version не доказывает
готовность установленной публикации.

EXISTING/PROPOSED описывают интерфейс, READY/BLOCKED/OPTIONAL_UNAVAILABLE — готовность
конкретной capability. Пропавший EXISTING-интерфейс получает BLOCKED, а не ложное
подтверждение наличия. Disabled/missing owner имеет конкретную причину независимо
от загруженных ранее функций. Нереализованные safe adapters никогда не становятся
READY от одного function_exists. Только проверенные AF facilities и согласованная
схема могут быть READY в текущей реализации. Здоровая схема может отображаться
READY и при disabled ACS; команда всё равно требует enabled.

Gate предназначен для административной проверки зависимостей будущих команд:
participant.register требует KB/CS snapshots; combat.preview — KB definition/pinned
adapter; item.consume — Inventory gateway; combat.publish — MyBB/Theme/Editor.
Он не исполняет эти команды, не заменяет авторизацию и не служит новым endpoint.
Неизвестная operation запрещена. AAM/Statistics не входят в обязательные gates.
Изменение одной зависимости не блокирует всё ACS и тем более обычный форум.

## Read-only и производительность

Проверки вызываются только из ACP или явного diagnostic gate. Discovery использует
штатные manifests. Объявления проверяются token_get_all по ограниченному списку
реальных source files, с кэшем источников только на время объекта запроса.
Ни внешний bootstrap, ни KB reader, ни calculator/view, ни Inventory API не
вызываются. Source declaration, loaded и callable различаются. Никакого runtime
сертифицирования по одному имени функции нет.

Диагностика не запускает миграции, DDL probe, skill sync, Inventory mutations,
snapshot updates и не пишет новые ошибки при просмотре. Существующий ACS SQL scope
временно устанавливает свои настройки соединения и восстанавливает их для
MyBB/ATF в finally. Запрос к diagnostics ограничен 20 строками метаданных; raw
payload, details_json, SQL, stack traces и чужое состояние не показываются.
Последняя AF lifecycle error показывается только при безопасном ACS-коде.
Отсутствие БД/схемы даёт состояние unavailable, без Fatal Error и автопочинки.

На обычном showthread bootstrap по-прежнему подключает только Context; новые
AuthorizationService/Diagnostics, источники интеграций и дополнительные SQL не
подключаются. Новых hooks, assets и второго SCEditor нет. Реактивация сохраняет
все строки, в том числе diagnostics; AF last-failure cache очищается при успехе
по прежнему lifecycle-контракту. Неизменяемость, idempotency, authorization, secrecy
и audit нельзя отключить через ACP.

## Проверки и ручная приёмка

- PHP lint всех новых/изменённых PHP — PASS.
- `tests/acs_permissions_diagnostics_regression.php`: 82 точечные проверки — PASS:
  typed roles, deny/default, native AF access, запрет без ACP прав, отсутствие
  implicit GM/super-admin character control, mandatory/optional gates, source
  evidence и отсутствие загрузки интеграций на showthread/при диагностике.
- `tests/acs_foundation_regression.php`: 65 проверок на реальной изолированной
  MariaDB — PASS; все пять разделов доступны, содержимое восьми таблиц до/после
  чтения идентично, приватный diagnostic payload не попадает в HTML.
- Существующие AF router и ATF lifecycle diagnostic regressions — PASS.
- Уникальность новых production symbols, diff whitespace и неизменность migration
  checksum — PASS. Полный suite, массовые E2E/визуальные проверки не запускались.

Среда: PHP 8.4.24 / MariaDB 11.8.6, MyBB auth/page helpers в DB fixture — stubs;
permissions fixture использует настоящий AF discovery/core. Production PHP 8.5.0,
установленный MyBB и пользовательские ACL проверяются вручную на warprift.ru.

1. Обновить файлы PR, открыть ACP → AF → ACS и все пять вкладок. Проверить версию
   0.3.0 / schema 001_foundation; Sessions — только сообщение об этапе 2.
2. Проверить evidence KB/CS/Inventory и причины BLOCKED/PROPOSED. Наличие обычных
   методов не должно делать safe snapshots/reservations/publish READY.
3. Войти пользователем ACP без AF index permission: доступ запрещён, сведения
   о схеме и зависимости не выведены. Проверить штатного разрешённого администратора.
4. На тестовом форуме отключить KB либо Inventory: увидеть OWNER_DISABLED и
   конкретную зависимую capability; обычная тема, профиль, ATF/AdvancedEditor работают.
5. Убедиться, что просмотр вкладок не создаёт/изменяет строки ACS/KB/CS/Inventory,
   а реальная diagnostics history остаётся после disable/enable ACS.
6. Вернуть зависимость, реактивировать ACS; проверить настройки, схему и редактор.
   Искусственные сбои проводить только в отдельной тестовой БД.

Откат: отключить ACS и вернуть прежние файлы. Новых таблиц/миграций/настроек нет,
данные удалять не требуется. Этап 2 автоматически не начинается. ACS-1.3 закрывает
инфраструктурную задачу только после ручной приёмки, не означает готовность боя.
