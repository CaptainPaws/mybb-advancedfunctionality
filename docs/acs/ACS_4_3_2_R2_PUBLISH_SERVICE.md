# ACS-4.3.2-R2 — PublishService

База: main `a5b8c8de7445c00627806750ee05f9b91758eeb3`, после merge PR #1015. Реализован внутренний серверный coordinator, без HTTP routes/production hooks/редактора. Действует Stage 4 Revision 2. `publication_enabled=false` сохранено. Installed MyBB/MySQL acceptance **BLOCKED**, локальные fixtures не являются production-проверкой.

## API и устойчивость

`Application/PublishService.php` создаётся с native MyBB DB object (read/write mysqli совпадают с ACS writer) и authenticated SessionAccess. Только серверный caller, после полного native bootstrap и с владением whole `newreply.php` request, вправе вызывать publish. Нельзя помещать его внутри уже исполняющегося PostDataHandler или продолжать original INSERT после него. HTTP binding и Quick Reply response renderer остаются задачей frontend/integration; здесь они не регистрируются.

- `issue({draft_id, expected_draft_version, confirmation_hash})`: POST/CSRF, текущий uid, native права и session membership; сервер генерирует ID. Существующий SubmissionStore под тем же session lock находит прежний идентичный confirmed draft request либо регистрирует intent и frozen snapshot. Второй журнал/таблица не создаются. Повтор после потери issuance response, REJECTED или COMMITTED возвращает прежний ID; новый подтверждённый draft version после отказа — отдельный явный запрос. Клиентский submission_id в issue запрещён.
- `publish(id, native_options=[])`: POST/CSRF, автор и доступ проверяются повторно. PID, actor, план, damage, RNG и prose из пользовательской формы не принимаются; prose/actions берутся из frozen snapshot. Native options проходят существующий Gateway validator; default options поддерживаются. Первый успешный dispatch фиксирует hash native request в POSTING. Изменение options на replay после dispatch не создаёт второй пост.
- Prevalidation общим read-only методом writer проверяет explicit combat controller grant, текущий roster/turn, snapshot/pins/tape и dependency/simulation/confirmation hashes. Существующие Builder и Resolver используют прежний tape; новая RandomSource отсутствует. State fence обнаруживает изменения во время подготовки. Перед Gateway сохраняется VALIDATING, сам Gateway сохраняет POSTING и освобождает tx/locks до native callbacks.
- POST_CONFIRMED проверяется Gateway по authoritative writer; writer заново проверяет весь scope, pid/uid/tid/fid/visibility, controls и версии и атомарно записывает механику/COMMITTED/receipt/outbox. Потенциальные изменения между preflight и commit не разрешают применение устаревшего расчёта.
- COMMITTED replay возвращает terminal receipt без Resolver/native hooks/повторной механики. Известный POST_CONFIRMED без receipt может продолжить проверенный writer путь без нового INSERT. POSTING/COMMITTING без receipt идут в карантин через существующий Gateway, никогда не повторяют insert. Внутрипроцессный повтор во время native hook возвращает processing без второго dispatch.
- Native REJECTED и prevalidation rejection до dispatch сохраняют литературу/seals и освобождают draft только штатным journal-переходом. MODERATION_PENDING ждёт без HP/AP/закрытия хода. RECOVERY_REQUIRED не сбрасывается и не исправляется автоматически.
- Ошибка writer: сначала authoritative receipt lookup (commit мог успеть), затем при доказанном POST_CONFIRMED без receipt — CAS quarantine с audit reason `combat_commit_failed`. При недоступной БД/провалившемся journal CAS исключение не считается успехом: сохраняется последняя durable phase, повтор сначала снова проверяет receipt. Чужие tx не откатываются.

Результат — schema_version=1, submission_id, status, state_version, pid, mechanics_confirmed, receipt, code и native validation_errors. Наличие pid не означает применённый урон. Результат передаёт COMMITTED только с проверенным terminal receipt. Runtime exceptions до/при неподтверждённом journal acknowledgement требуют повторного status/receipt lookup тем же ID, а не нового submission.

## Проверки

PHP **8.5.12**, локальная disposable **MariaDB 11.8.8**, внешний upstream `mybb_1841` с SHA из исходного аудита. MyBB posts/threads/users fixtures остаются MyISAM. Никаких production SQL. Реальный upstream PostDataHandler/native DB_MySQLi с bounded bootstrap/helper fixtures, а не полный installed форум и активные аддоны.

```
php tests/acs_publish_service_regression.php /tmp/acs222-socket/r2.sock /absolute/disposable/mybb_1841
php tests/acs_mybb_gateway_regression.php /tmp/acs222-socket/r2.sock /absolute/disposable/mybb_1841
php tests/acs_combat_commit_regression.php /tmp/acs222-socket/r2.sock
```

Пройдены: server-issued ID/reuse, frozen native-to-combat success, повтор/потеря commit response, возобновление известного POST_CONFIRMED без native повторов, pre-insert native validation failure, late hook exception после INSERT, rollback outbox в конце writer, конфликт seal version до INSERT и state conflict после INSERT, moderation wait, POSTING response-loss quarantine, повтор RECOVERY/REJECTED, рекурсивная отправка, CSRF/author и explicit grant refusal. Повтор не вызывает hooks, tape неизменен, до COMMITTED HP/AP не применяются. Отдельные прежние Gateway/writer fixtures подтверждают native merge/draft/upload отказ, atomic rollback и contention одного хода. `php -l` изменённых PHP и `git diff --check` пройдены. Полный suite не запускался.

## Остаток Stage 4 R2

RecoveryService/GM evidence/CAS decisions, native approval/deletion/move observers, редактор/Quick Reply wiring, карточки/GM UI и outbox consumers не реализованы этой задачей. Идентификация потерянного pid по uid/tid/time/text/MAX(pid) запрещена; надёжного identity marker пока нет. Exactly-once MyBB creation не заявляется. Installed source/hooks/настройки, все реальные addon side effects и целевой **MySQL 8.0.46** требуют отдельной приёмки.

Scope writer прежний: direct attack/AP/HP; неподдержанные эффекты/ресурсы могут потребовать recovery и не должны предлагаться будущим UI как поддержанные операции. Подготовительные controller grants=false не меняются и автоматически не повышаются. Migration 001–009, core, table engines, Resolver/Draft/Seal/Gateway, formulas A1.1–A1.95 и masks не переписаны. Внешнего расхода Inventory/начислений Balance нет. Публикация остаётся выключена, перехода к задаче 3 автоматически нет.
