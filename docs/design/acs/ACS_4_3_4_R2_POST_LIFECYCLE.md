# ACS-4.3.4-R2 — модерация и жизненный цикл поста

База: main `e1459af15afbd9fb6a9b6b437566bc51dbfe860b` (merge PR #1017). Реализованы native observers, серверный author approval path и read-only статус для будущего postbit. Публикация остаётся `publication_enabled=false`; frontend, карточки/GM UI, consumers и installed acceptance не включены.

## Native extension points

Дополнительно проверены только необходимые upstream файлы `mybb_1841`; прежний полный Gateway-аудит и его SHA не повторяются:

| Файл | SHA256 |
|---|---|
| inc/class_moderation.php | `891913789e5a20adceff7bbebcb68ae660fe04dc9653afe81e3cc58bf70fd4cc` |
| inc/class_plugins.php | `fff8dc9fb8f96a034d2f5b4365b58ac1bc4bb079d5c31124ab2587e7ac19a7c3` |

`Bootstrap/PostLifecycleHooks.php` регистрируется существующим addon init только при включённом ACS, MyBB 1.8.41 и совпадении SHA class_moderation.php. Иной installed source не обходится флагом: passive binding не регистрируется, author approval revalidation блокируется до проверки. Наличие upstream fixture не подтверждает конфигурацию warprift.ru.

Подключены 17 class_moderation hooks: approve/unapprove/soft_delete/restore posts и threads, delete_post, delete_thread_start/end, move_simple/move_thread_redirect/move_threads, merge_threads, split_posts/merge_posts. Copy thread не создаёт post_link для копий. Hook callbacks не меняют аргументы, не подменяют автора, не вызывают PostDataHandler/Gateway/Resolver и не повторяют native side effects.

- approve_posts: hook после visible=1 (2560), но до части counters; unapprove_posts — 2728; delete_post — после DELETE (746); soft_delete_posts/restore_posts — после UPDATE (3331/3482).
- move_simple (1285) и move_thread_redirect (1078) — **до** соответствующих UPDATE. merge_threads (1558) — промежуточная стадия, перед удалением source thread. Поэтому move/merge/split и delete_thread_start немедленно карантинируют ещё не committed известную операцию; shutdown reread уточняет конечное состояние. Нельзя считать любой поздний hook полной атомарной границей MyBB.
- IDs выбирают только уже подтверждённые ACS pid/tid. Неизвестный pid не устанавливается по кандидату/uid/time/text. Исходный fid берётся из native confirmation audit; изменения автора/темы/форума, hidden/deleted и недоступная тема обнаруживаются на authoritative writer.
- Наблюдения, CAS и audit записываются короткой ACS-only tx. К моменту следующего стороннего callback tx/locks освобождены. Shutdown — best effort; ошибки boundary/DB не откатывают чужие tx и не останавливают native moderation, сохраняют безопасную диагностику в error_log. Последующая author/writer проверка снова читает реальный пост. SIGKILL или сторонний SQL в обход hooks не обещают мгновенного наблюдения.
- Callback хранит собственное имя события: upstream pluginSystem.current_hook не восстанавливается после вложенного run_hooks другого аддона. Это проверено fixture с nested callback до ACS и boundary callback после ACS.

## Публикационный протокол

MODERATION_PENDING не расходует HP/AP, не закрывает ход и не содержит applied damage. Native approve сохраняет **ожидание авторского подтверждения**, а не выполняет бой от uid модератора. Такая политика поддерживается без изменения текста/visible MyBB.

`ModerationService::resumeApproved(id, expected_state_version)` требует исходного authenticated автора, POST/CSRF и native доступ. До продолжения проверяются известный pid/author/tid/исходный fid/visibility, проверенный source binding, frozen snapshot/pins/tape, актуальные права/control grants/roster/turn и dependency/simulation/confirmation hashes через общий read-only verifier writer. Короткая CAS-транзакция подтверждает одобренный пост, затем обычный writer заново проверяет всё перед mechanical commit. Прежний Resolver использует исходный tape, новые броски отсутствуют.

PublishService и author RecoveryService используют этот путь для одобренного MODERATION_PENDING, без повторного Gateway/INSERT. Неодобренный пост остаётся pending. GM recovery может показать одобрение, но не коммитит от имени автора. Ошибка/конфликт → RECOVERY_REQUIRED, sealed данные и проза сохраняются. После COMMITTED повтор возвращает прежний receipt.

Для ещё не committed операции удаление/скрытие/перенос блокирует подтверждение; restore не снимает карантин автоматически. COMMITTED никогда не меняется на rejected/recovery из-за MyBB lifecycle и не компенсирует старый урон. Observer может увеличить **публикационную** state_version и добавить same-status audit-наблюдение, сохранив last_phase, result_json, post_link, механический event, terminal receipt и outbox. Receipt содержит исходную версию combat commit и остаётся неизменным. Одинаковое фактическое состояние не создаёт повторного audit-события; поздняя сверка не применяется как ещё один боевой результат.

Новой схемы/второго журнала нет: observations используют существующие submissions.evidence_json и publication_audit. Core, MyBB engines, migrations 001–009, Resolver, Draft/Seal, Gateway, игровые формулы и маски не изменены.

## Read-only status

`PublicationStatusQuery::forPost(pid, access?)` — внутренний авторизованный SQL-only API, без HTTP route/Theme wiring. Проверяет native доступ, session membership и скрытый/pending post scope. Обычный narrative pid без связи возвращает null. mechanics_confirmed=true возможен только по общему terminal verifier с проверенными receipt/result/post_link hashes, не по одному pid или visible=1. Для одобренного ожидающего поста возвращается approval_ready и соответствующая метка, без applied damage. Builder/Resolver/Writer/Gateway не загружаются; общий SQL-only terminal verifier выделен в trait без изменения его алгоритма.

## Проверки и остаток

PHP **8.5.12**, disposable **MariaDB 11.8.8** и внешний upstream mybb_1841. Реальные class_moderation approve/unapprove/delete/soft_delete/restore и pluginSystem работают с bounded helper/counter fixtures и MyISAM постами. Для move/split/thread-delete проверены реальный dispatcher, аргументы, pre-invalidation и последующий SQL readback; это **не** полный installed execution этих методов.

```
php tests/acs_post_lifecycle_regression.php /tmp/acs222-socket/r2.sock /absolute/disposable/mybb_1841
php tests/acs_publication_status_bootstrap.php
php tests/acs_recovery_service_regression.php /tmp/acs222-socket/r2.sock
php tests/acs_combat_commit_regression.php /tmp/acs222-socket/r2.sock
php tests/acs_publish_service_regression.php /tmp/acs222-socket/r2.sock /absolute/disposable/mybb_1841
```

Пройдены moderation без расходов, успешное author approval через PublishService/RecoveryService, повтор observer, конфликт seal после approve, delete до commit, delete/restore после commit без изменения receipt/механики, unapprove, move/split/thread deletion hooks, CSRF/author/control grant refusal, nested addon hooks/освобождение locks, неизменный tape, статус narrative/confirmed и лёгкий bootstrap. php -l изменённых PHP и git diff --check пройдены; полный suite не запускался.

**Installed acceptance BLOCKED:** не проверены установленный source, дополнительные hooks/их фактическая регистрация, полные native move/merge/ACP/modcp маршруты, реальные Appearance/Balance/CS/AAM/PostCounter effects или MySQL **8.0.46**. До включения администратор должен на staging проверить source/17 hooks, approve→author confirmation, conflict/delete/move/restore и повтор, соседние addon callbacks и отсутствие повторных HP/AP/EXP/notifications. Локальная MariaDB эту проверку не заменяет. Unknown-pid/legacy incomplete evidence blockers сохраняются. Следующая задача — отдельная интеграция AdvancedEditor; автоматически не начинается.
