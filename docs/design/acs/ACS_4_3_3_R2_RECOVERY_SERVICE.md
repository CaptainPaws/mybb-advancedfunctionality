# ACS-4.3.3-R2 — RecoveryService

База: main `eeac2898ce169283042663936179a3b167504f0d` (merge PR #1016). Реализованы внутренний RecoveryService и серверные команды на существующем SubmissionStore/publication_audit. Второго журнала, новой миграции, HTTP routes/CLI/GM frontend нет. `publication_enabled=false` сохранено. Installed source/hooks/MySQL acceptance остаётся **BLOCKED**.

## Команды

`AF_ACS_RecoveryService(native_database, access?)` требует тот же authoritative mysqli writer/read handle, что ACS; все native permission callbacks выполняются вне ACS tx/locks. Перед каждым обращением проверяются текущий actor, native доступ к исходной теме и актуальное session membership. Глобальный ACP-флаг или переданный role не дают права разрешать публикацию.

- `inspect(submission_id)` — read-only авторизованный status/receipt lookup для автора либо session owner/GM. Возвращает статус, last_phase/state_version, известный pid, фактическое состояние поста, mechanics_confirmed и прежний terminal receipt. GM также видит последние 30 audit-событий, diagnostic candidates и доступные безопасные команды. Текст постов и скрытые snapshots игроку не возвращаются. Audit ID/version/time нормализованы как decimal strings.
- `recover(submission_id, expected_state_version)` — POST/CSRF. Сначала использует общий terminal verifier writer: COMMITTED возвращает прежний receipt, даже если пост позже удалён/скрыт/перемещён. При отсутствии receipt требует точную CAS-версию. REGISTERED/VALIDATING/REJECTED не публикуются заново. POSTING/COMMITTING без receipt — quarantine, без INSERT. RECOVERY_REQUIRED автоматически не разрешается.
- POST_CONFIRMED: проверяется известный pid по фактическим posts/threads/forums на writer, автор/тема/исходный форум/visibility. Только исходный authenticated автор может вызвать существующий CombatCommitWriter. Writer заново проверяет control grants, turn, версии, snapshot/hashes и прежний tape. GM не подменяет uid автора. Ошибка writer сначала проверяет receipt, затем сохраняет RECOVERY_REQUIRED без частичных механических изменений. Недоступная БД/неподтверждённый journal acknowledgement остаётся исключением, а не фиктивным успешным recovery; повтор — lookup тем же ID.
- MODERATION_PENDING с фактическим visible=0 остаётся ожидающим без HP/AP. Изменившийся approved/hidden/deleted/moved post переводится при recover в quarantine; native approval observers и автоматическое продолжение относятся к следующей задаче. Одного approve недостаточно для механики.
- `resolve(id, expected_state_version, strategy, reason)` — только POST/CSRF + текущий session owner/GM; причина обязательна (1–1024 байта). SQL membership/expiry проверяются повторно под session lock. Решение и реальный actor_uid записываются в publication_audit в одной CAS-транзакции со сменой состояния. Stale/repeated manual request не пишет второе решение; caller перечитывает inspect после потерянного ответа.

| strategy | Доказательство и результат |
|---|---|
| `resume_known_post` | Только ранее подтверждённый pid, исходный native audit и свежий authoritative readback. RECOVERY_REQUIRED → POST_CONFIRMED либо MODERATION_PENDING. Это восстановление подтверждения поста, а не одобрение устаревшего расчёта; финальный writer запускает исходный автор отдельным recover/publish и всё перепроверяет |
| `reject_unsent` | Только last_phase REGISTERED/VALIDATING, pid=NULL и отсутствие любой опасной стадии в durable audit. RECOVERY_REQUIRED → REJECTED, освобождение claim и восстановление sealed draft. Отсутствие найденного поста/receipt после POSTING не является таким доказательством |
| `hold` | Сохранить RECOVERY_REQUIRED/last_phase/claim с новой CAS-версией и GM audit reason. Не сбрасывает операцию и не меняет tape, snapshot или бой |

Перехода RECOVERY_REQUIRED → REGISTERED нет. Нерешённые операции продолжают блокировать session commands/зависимые ходы существующими barriers. Восстановление не правит prose, MyBB visibility, sealed actions/rolls, формулы или controller grants. Команды не импортируют и не вызывают Gateway, PostDataHandler или внешние owner APIs.

## Identity evidence и ограничения

Gateway уже передаёт проверенный expected fid в SubmissionStore.confirmPost. Теперь fid сохраняется вместе с native pid readback в существующий evidence/audit, чтобы recovery мог обнаружить перенос всей темы в другой форум. Writer также сверяет этот fid перед механическим commit: недостаточно сравнить пост с уже перемещённой темой. Core/движки/старые миграции не менялись.

**Неизвестный pid остаётся BLOCKED:** observed_pid из late-hook diagnostics — кандидат, а не подтверждение новой поддерживаемой native вставки. GM видит записанное наблюдение и контекст, но команда не принимает произвольный pid и не связывает его по uid/tid/time/text/MAX(pid). Надёжного identity marker сейчас нет; поле «я проверил» не вводится как замена доказательству. Для такого случая поддержаны понятный quarantine и audited hold; доказательное разрешение требует будущего проверенного identity provider/протокола и отдельной реализации. Exactly-once создания MyBB-поста не заявляется.

Legacy confirmation без исходного fid нельзя дополнить текущим fid темы задним числом. Активный recovery для такой записи остаётся blocked (`identity_evidence_missing`); существующий COMMITTED receipt сохраняется. Удалённый/изменённый known pid не заменяется другим постом. MyISAM может измениться после последней проверки: общей MyBB/ACS атомарности нет; native lifecycle observers и installed ручная приёмка — последующие задачи.

## Точечные проверки

PHP **8.5.12**, disposable **MariaDB 11.8.8**, MyISAM fixture posts/threads/forums; без production SQL. Команды:

```
php tests/acs_recovery_service_regression.php /tmp/acs222-socket/r2.sock
php tests/acs_combat_commit_regression.php /tmp/acs222-socket/r2.sock
php tests/acs_publish_service_regression.php /tmp/acs222-socket/r2.sock /absolute/disposable/mybb_1841
php tests/acs_publication_journal_regression.php /tmp/acs222-socket/r2.sock
```

Проверены receipt/replay после потерянного ответа; rollback в конце writer с сохранённым постом и последующий GM resume → author commit; pre-dispatch reject; неизвестный pid с реально существующим matching candidate без привязки; POSTING quarantine; hold/CAS/audit/реальный GM actor; известный pid, удаление/скрытие/перенос/смена автора и форума темы; pending/approved moderation без расходов; stale seal без новых бросков; legacy evidence refusal; CSRF/foreign actor/player/expired GM и отзыв роли между preflight и SQL lock; конкурирующие команды на двух реальных соединениях; повторная отправка того же хода; зависимые barriers; неизменность RNG tape и отсутствие загрузки Gateway/новых постов. Старые writer/PublishService/journal fixtures проверены отдельно. Также php -l изменённых PHP и git diff --check. Полный suite не запускался.

Локальная MariaDB и upstream native fixture не сертифицируют установленный MyBB 1.8.41, активные аддоны или целевой MySQL **8.0.46**. Модерационные hooks/lifecycle observers (задача 4), frontend/карточки/GM UI, outbox consumers и installed acceptance не выполнялись. Публикация остаётся выключена; переход к задаче 4 автоматически не выполняется.
