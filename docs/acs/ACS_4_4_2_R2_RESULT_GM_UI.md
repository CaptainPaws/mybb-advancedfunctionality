# ACS-4.4.2-R2 — карточка результата и GM UI

База: main `80b37615` (merge PR #1019). Реализована задача 6; переход к задаче 7 не выполняется. `publication_enabled=false` сохранён.

Карточка регистрируется существующим ATF component API в `post.before_body`. `CombatCardQuery` пакетно читает PID страницы (группы до 100), проверяет native доступ, членство сессии, автора/тему/форум фактического сообщения и подтверждённую связь. Механика отображается только после проверки COMMITTED receipt, post_link и SHA-256 сохранённого результата. Ошибочные/отсутствующие доказательства не дают карточку применённого урона. Неизвестный PID нельзя привязать эвристически.

Рендер использует сохранённые summary/actions Resolver: стоимость, исход, урон, броски, вероятности и доступные компоненты защиты. Пересчёта, RNG и записей при showthread нет. Обычный postbit не загружает Builder, Resolver, writer или RecoveryService. Presentation cache живёт один запрос и включает зрителя/тему/пакет PID. Cosmetic appearance/uid из postbit не используются для механики. Theme templates, ATF и AdvancedProfileUI не изменены; CSS ограничен ACS-классами.

Текущая модель participant visibility — gm-only. Поэтому подробности доступны только действующим persisted owner/GM этой сессии; остальные авторизованные члены видят статус без чисел, имён, количества действий и result hash. Публичные/full/approx политики не изобретены и остаются за будущим согласованным контрактом видимости. Unconfirmed карточка не содержит применённого урона. Deleted/inaccessible posts не получают новую привязку; committed история не откатывается.

GM очередь: `misc.php?action=acs_recovery&session_id=…`, ссылка из существующей навигации сессии/карточки восстановления. Пагинация по 20, состояние, последняя фаза, известный PID, audit/причины и только разрешённые команды. Выбранная отправка повторно проверяется на принадлежность сессии. GET не изменяет ACS. POST требует native доступа, persisted owner/GM, CSRF, CAS и причины; полномочия повторно проверяются под lock. Ошибки отображаются без SQL/секретных деталей.

Все решения идут через RecoveryService: `hold`, `resume_known_post`, доказанный `reject_unsent`; новая `requestReview` переводит незавершённую фазу в quarantine с отдельным audit actor/reason. Она не вызывает `recover()`/writer даже если GM одновременно автор. Resume восстанавливает подтверждение известного поста, не применяет механику. Нет поля нового PID, кнопки повторного INSERT, reset, изменения бросков или исправления committed истории. Неизвестный исход остаётся заблокирован. Publication test gate не снимается: эти GM команды меняют журнал, а не публикуют/коммитят бой.

## Проверки и пределы доказательств

Точечные fixtures: обычный commit/card; stored damage/cost/roll/defense; XSS; cosmetic mask independence; настоящий ATF register/render slot; observer/foreign permissions; moderation без урона; повреждённый result hash; GET без записей; GM reason/actual actor; CSRF/CAS; stale повтор; GM-автор quarantine без механики; unknown PID без unsafe commands; known-post resume без HP/AP изменений. Дополнительно writer/Recovery regression проверяют сохранённый terminal verifier после пакетного чтения. Bootstrap проверяет отсутствие тяжёлого боевого стека в postbit dependencies.

Chromium проверяет готовый серверный HTML на мобильной ширине: отсутствие overflow, keyboard details, экранирование, отсутствие скрытых чисел/редактора, required reason и native POST с CSRF/CAS. `php -l`, `node --check`, `git diff --check`; полный suite не запускается.

SQL fixtures используют временную локальную MariaDB 11.8.8 и PHP 8.5.12; native helpers ограничены fixture. Это не installed MyBB 1.8.41/MySQL 8.0.46 и не production-приёмка. Installed theme/ATF/APUI, реальные permissions/visibility, Quick Reply вставка postbit, addon hooks и поведение браузеров форума ещё требуют задачи 7. Outbox consumers остаются отключены. MyBB core/движки/формулы/Seal/Resolver не менялись, production БД не использовалась.
