# ACS-4.5-R2 — итоговая интеграционная проверка Stage 4 Revision 2

**База:** `main` = `573c9e4c6370248087cb69cbaf999837762bfe55` (merge PR #1020). Задачи 1–6 находятся в main. Итог: **локальные точечные проверки PASS; installed-приёмка NOT_VERIFIED; включение публикации BLOCKED**. `publication_enabled=false` сохранён. Этап 5 не начинается автоматически.

## Что проверено и где

Среда Codex содержит checkout аддонов и отдельный upstream `mybb_1841`, PHP **8.5.12**, временную **MariaDB 11.8.8** через Unix socket без TCP и Chromium. Fixtures создают/удаляют собственные `acs222_*` базы. Пять upstream SHA (newreply, PostDataHandler, DB_MySQLi, class_core, class_moderation) совпали с [проверенными reference](ACS_STAGE_4_REVISION_2_AUDIT.md) и [lifecycle reference](ACS_4_3_4_R2_POST_LIFECYCLE.md); это не SHA установленного форума.

Installed source warprift.ru, inventory реально активных plugins/hooks, установленная конфигурация и MySQL 8.0.46 недоступны. В текущей managed environment нет настроенного VPN, secrets или outbound identities для форума. Production БД не использовалась, тестовые сообщения на форуме не создавались. Локальная MariaDB не доказывает совместимость именно с MySQL 8.0.46. Fixtures ограничивают native helpers/counters/cache; они не являются полной загрузкой установленного форума или внешних аддонов.

| Обязательный сценарий | Фактическое локальное доказательство | Installed |
|---|---|---|
| Native validation/flood/permissions, подтверждённый новый PID | Gateway + upstream PostDataHandler: PASS; uid/tid/fid writer readback, один lifecycle | NOT_VERIFIED |
| Двойной submit / потеря HTTP-ответа | Publish/editor replay + Chromium double-click/status/reload: один submission/post/receipt | NOT_VERIFIED |
| Отказ до INSERT | Native validation/permissions/attachment/branch rejection, prose и tape сохранены; окончательных HP/AP нет | NOT_VERIFIED |
| Ошибка/прерывание после INSERT | Late hook exception и отдельный процесс `exit(86)` в post-end: один MyISAM reply, RECOVERY_REQUIRED, last_phase=posting, pid NULL; observed_pid не становится link | NOT_VERIFIED |
| ACS rollback после создания поста | Publish/writer SQL fault fixtures, в том числе ошибка outbox: изменения HP/AP/events/turn/result/receipt целиком откатываются | NOT_VERIFIED |
| Потеря ответа после COMMITTED | Независимый receipt lookup/replay не повторяет HP/AP, result/post_link/outbox | NOT_VERIFIED |
| Конкуренция / конфликт версий | Два DB handles, contender при удерживаемом ACS lock, UNIQUE turn/post, CAS, gap dependency conflict и session barrier: PASS | NOT_VERIFIED |
| Sealed RNG | Неизменные tape/actions при retry/recovery/mask; повреждённая tape отклоняется без нового RNG | NOT_VERIFIED |
| Модерация / удаление / скрытие / перемещение | Реальный upstream class_moderation для выбранных branches; author revalidation, pending без списаний, pre-move quarantine/deferred readback, post-COMMITTED receipt неизменен | NOT_VERIFIED; полные move/merge/split routes требуют ручной проверки |
| Narrative / Quick Edit / full edit | Narrative/edit boundary passthrough, editor JS не подключается к edit, postbit SQL-only bootstrap; полного installed edit request нет | NOT_VERIFIED |
| Маски / карточки / GM | Cosmetic hash separation, реальный ATF component/slot API и Chromium DOM; receipt/hash/post_link proof, статус без фиктивного урона, CSRF/CAS/audit/actual GM actor | NOT_VERIFIED для installed Appearance/ATF/APUI |
| MyISAM / граница транзакций | Native fixture posts остаётся MyISAM; handler запрещён внутри BEGIN/ACS lock; ACS storage InnoDB | NOT_VERIFIED для installed inventory; миграции MyBB не требуются |
| EXP / alerts / counters | Native fixture postnum не повторяется при replay; outbox остаётся единственной pending записью, consumers не исполняются | Реальные owner side effects NOT_VERIFIED; отсутствие повторных EXP/уведомлений не заявляется по stub counters |

Прерывание `exit(86)` проверяет исполнившийся shutdown, а не SIGKILL/OOM. Без shutdown durable POSTING всё равно запрещает blind retry; installed аварийное восстановление требует отдельного сценария. Два handles и hook-interleaving fixtures не заменяют конкурентные HTTP-запросы установленного форума.

## Hooks и owner API: проверенные границы

Это **исходные регистрации текущего репозитория**, не перечень реально включённых callbacks production. Никакой внешний handler не отключён и не перенесён в ACS transaction.

| Владелец | Исходные extension points / опасная граница | Остаток |
|---|---|---|
| AdvancedAppearance | mask validate на datahandler_post_validate_post/thread (80), after_handler на insert/update end (80), postbit (4/1110), Quick Edit xmlhttp_update_post (80) | Полный installed newreply/edit/postbit; декоративные данные не входят в mechanical dependency/tape |
| Balance | datahandler_post_insert_post_end/thread_end (default 10), update/update_end, post_do_newpost_end | Native rewards остаются owner responsibility вне ACS; сверить фактически вызываемые hooks, включая несуществующие upstream/добавленные extension points |
| CharacterSheets | af_charactersheets_on_exp_changed из Balance, thread move hooks | Проверить EXP→progress и move side effects, отсутствие lost updates |
| AAM | datahandler_post_insert_post_end/thread_end (default 10) | Проверить mention alerts/cache и число доставок при recovery; не переисполнять весь native lifecycle |
| AdvancedPostCounter | datahandler_post_insert_post (до INSERT), moderation hooks и shutdown rebuild | Сбой до/после INSERT может иметь native side effects; общего rollback не обещаем |
| ACS | ранний global_start/global_end ownership bridge, 17 source-gated class_moderation hooks (100), ATF post.before_body | Проверить реальную регистрацию, порядок и отсутствие второго native INSERT во всех поддержанных поверхностях |

ACP «Публикация → Совместимость» обновлена под R2: schema отчёта v2, SHA class_moderation, hooks global_start/postbit/class_moderation/xmlhttp, отдельные acceptance/outbox blockers. SQL только SELECT; авторизация до чтения; paths/credentials/connection IDs не экспортируются. Иной PHP/MySQL version — WARNING; MariaDB не помечается PASS целевого MySQL. Совпадение версии/SHA означает лишь совпадение metadata, не installed READY. ACP registry не доказывает frontend callback execution.

Снято устаревшее требование joinable Appearance writer в общей ACS/MyBB transaction. Старый machine key capability `mybb.atomic_publish` сохранён для совместимости diagnostics, его readiness остаётся BLOCKED до installed приёмки; он не обозначает общую SQL-атомарность.

## Outbox: безопасно отключённый scope

Writer атомарно сохраняет `combat.committed` с UNIQUE message_key/submission_kind; повторный commit возвращает прежний receipt и не создаёт вторую pending запись. **Dispatcher и ACS consumers не реализованы/не зарегистрированы.** Это не успешная проверка доставки и не обещание exactly-once внешнего эффекта.

| Owner API в текущем source | Почему не подключён ACS consumer |
|---|---|
| Balance::af_balance_add / af_balance_apply_scaled_delta | Читает баланс, обновляет его, опционально пишет tx и вызывает CS. meta ref_type/ref_id — данные журнала, не доказанный unique durable idempotency receipt/lookup. Слепой повтор способен начислить снова |
| CharacterSheets::af_charactersheets_on_exp_changed | Обновляет progress из нового EXP; нет отдельного подтверждённого ACS operation receipt. Нельзя вызывать повторно независимо от Balance |
| AAM::af_aam_add_alert | Прямое создание owner alert без submission-key receipt/lookup; extra/object_id сами по себе не доказательство идемпотентности |
| Native post counters/rewards | Уже принадлежат MyBB lifecycle; ACS consumer не должен начислять их второй раз |

Поиск в ACS source обнаруживает только writer вставку outbox и схему, без delivery/attempt/status worker. Перед добавлением consumer нужны owner-approved key→effect→receipt, lookup после неизвестного результата, конкурентные retry fixtures и installed проверка. Пока обязательный gameplay outcome зависит от недоступного consumer, такой outcome остаётся BLOCKED; optional notifications можно оставлять отключёнными с явным решением администратора. Внешний расход Inventory и новые Balance rewards в эту поставку не входят.

## Повторить локально

Нужны disposable socket `/tmp/acs222-socket/...` и отдельный upstream tree; команды нельзя направлять на production. Полный test suite не запускался.

```sh
php tests/acs_publication_diagnostics.php
php tests/acs_permissions_diagnostics_regression.php
php tests/acs_publication_status_bootstrap.php
php tests/acs_presentation_bootstrap.php
php tests/acs_publication_journal_regression.php SOCKET
php tests/acs_mybb_gateway_regression.php SOCKET UPSTREAM_ROOT
php tests/acs_combat_commit_regression.php SOCKET
php tests/acs_publish_service_regression.php SOCKET UPSTREAM_ROOT
php tests/acs_recovery_service_regression.php SOCKET
php tests/acs_post_lifecycle_regression.php SOCKET UPSTREAM_ROOT
php tests/acs_editor_publication_regression.php SOCKET UPSTREAM_ROOT
ACS_PRESENTATION_DOM_FIXTURE=1 php tests/acs_result_gm_ui_regression.php SOCKET
node tests/acs_editor_publication_browser.js
node tests/acs_result_gm_ui_browser.js
```

Все перечисленные команды выполнены с exit 0. Также выполнены entry boundary modes `native-disabled`, `late`, `source`, `narrative`, `edit`; отдельный editor `--entry MARKER_JSON` подтвердил один start/insert/end и один terminal receipt. Gateway `--interrupt MARKER_JSON` завершился ожидаемым exit 86, companion interruption_check подтвердил quarantine/один пост. `php -l` изменённых PHP и `git diff --check` — PASS. Upstream core, миграции 001–009, Resolver, Draft/Seal, MyBB engines и A1.1–A1.95 этой поставкой не меняются.

## Короткая installed-приёмка для администратора

Все сценарии выполнять **на изолированной копии установленного форума с отдельной БД**, теми же версиями/аддонами/темой. Не выполнять fault injection или тестовые записи на production. Публичная publication capability остаётся false; тестовый opt-in ограничить origin/UID по [контракту задачи 5](ACS_4_4_1_R2_EDITOR_PUBLICATION.md). Native/controller grants должны быть подтверждены штатным разрешённым путём; отсутствие такого пути — blocker, а не повод подменять production permissions прямым SQL.

1. Снять baseline: версии, deployed commit, пять core SHA, migrations/ACS InnoDB, MyBB engines и writer routing. Экспортировать ACP compatibility report; отличия от upstream разобрать до отправки тестового поста. Ядро и движки не исправлять ради прохождения проверки.
2. На native newreply, Quick Reply, edit/Quick Edit, moderation и showthread снять фактический hook registry/порядок через серверный debugger/logging в тестовой копии; сравнить с таблицей выше. ACP snapshot сохранить отдельно. Не записывать credentials/CSRF/prose/содержимое snapshot в публичный отчёт.
3. Подготовить один sealed ход разрешённого scope, записать hashes/tape/версии/HP/AP. Отправить обычный narrative и combat из полной и быстрой формы; double-click/повтор формы/две вкладки дают один post/link/receipt и один механический эффект. Narrative не закрывает ход.
4. Отключить сетевой ответ после успешного запроса, проверить status/reload с тем же submission. Повтор не даёт второй INSERT/HP/AP/EXP/alert. Отдельно сравнить число native Balance/CS/AAM/counter side effects с baseline обычного reply.
5. Инъецировать в тестовой копии отказ до native INSERT, поздний hook failure/прерывание после INSERT и SQL failure перед завершением ACS/outbox. Проверить соответствующий REJECTED/quarantine/полный ACS rollback, сохранённые prose/seals/tape и блокировку зависимых действий. Неизвестный PID не выбирать автоматически по uid/tid/time/text; recovery не создаёт новый пост.
6. Одновременно отправить два действия одного хода, изменить dependency version между проверкой и commit, повторить recovery с устаревшим CAS и от не-GM. Допустим один verified receipt либо понятная блокировка; не stale mechanics и не новые броски.
7. Проверить pending→approve→author revalidation, конфликт после approve, hide/delete/move до commit и после COMMITTED. До подтверждения нет applied damage/закрытия хода; после commit история не откатывается. Move/merge/split routes без доказанного безопасного поведения остаются недоступными для combat.
8. Quick Edit/full edit меняют только прозу; сменить маску до/после отправки. Сравнить hashes/tape/receipt и отображение ATF/APUI. GM видит подробности/причины, остальные члены — разрешённый статус, outsiders — ничего. GM решение имеет actor/reason/CSRF/CAS audit и не применяет механику само по себе.
9. Сверить engines/core SHA с baseline, pending outbox без consumers и отсутствие повторных EXP/уведомлений. Записать все сбои и evidence. При отсутствии обязательного owner API оставить consumer отключённым и соответствующую функциональность BLOCKED.

Минимальная запись приёмки: deployed SHA; версии/SHA; поверхность и active hooks; actor roles; submission_id/известный pid; ожидаемый/фактический статус; hashes до/после; количество post/receipt/result/outbox и owner effects; итог PASS/FAIL/NOT_VERIFIED; ответственный администратор. Данные хранить приватно; в PR — обезличенный итог.

## Решение о включении

**Сейчас: НЕ РАЗРЕШЕНО / BLOCKED.** Installed evidence отсутствует. Unknown PID binding по эвристике, attachments/merge/draft conversion, неподдержанные эффекты/resources, legacy incomplete identity и произвольные engine overrides остаются закрыты. Detailed visibility текущей карточки — только persisted owner/GM, не новая публичная политика.

Включение возможно только после всех обязательных installed PASS, разрешения блокирующих конфликтов и **явного решения администратора** с принятым scope/ограничениями. Этот PR не добавляет автопереключатель и не снимает false/test-only guards. Простая замена capability флага не является процедурой поставки: нужен отдельно проверенный deployment/configuration change. Общая SQL-атомарность MyBB+ACS и exactly-once MyBB INSERT без identity proof не заявляются.
