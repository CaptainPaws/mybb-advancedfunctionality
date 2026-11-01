# ACS-4.1.2 — контракт MybbPostGateway и AdvancedEditor: BLOCKED

## Статус

Проверенная main: **`98ce4cafbb2857ea68fa231125ed89fe5ccfa340`**. Она включает [ACS-4.1.1](ACS_4_1_1_PUBLICATION_AUDIT.md), принятый PR #1000. Принятие отчёта не означает подтверждение транзакционной безопасности: его явный результат — **IC-MYBB-1 BLOCKED**, IC-EDITOR-1 PROPOSED.

Зависимость задания «ACS-4.1.1 с доказанной безопасной границей» **не выполнена**. Согласно последнему условию ACS-4.1.2 и Architecture §7.2 публикация остаётся BLOCKED. В этом PR подготовлен только документ контракта; `MybbPostGateway.php`, coordinator, endpoints, editor hooks/fields и миграции **не реализованы**. Критерии готовности исполняемого Gateway не объявляются выполненными.

Источники: [Master](ACS_MASTER_SPECIFICATION.md) A1.49–A1.52, A1.58–A1.60, A1.89–A1.95; [Architecture](ACS_TECHNICAL_ARCHITECTURE.md) §5, §7–8; [Integration Contracts](ACS_INTEGRATION_CONTRACTS.md) IC-MYBB-1, IC-EDITOR-1, IC-THEME; [Roadmap](ACS_IMPLEMENTATION_ROADMAP.md) этап 4. Нормативные документы и игровая механика не меняются.

## Повторная проверка блокировок

| Условие | Доказательство на текущей main | Состояние |
|---|---|---|
| Установленный MyBB 1.8.40 | В репозитории нет установленного PostDataHandler/newreply/core/DB driver; среда не предоставляет доступ к серверу и БД форума | BLOCKED: нельзя заменить установленный код upstream-эталоном |
| Единственная транзакция | `advancedappearance/personal.php`, `af_aa_personal_transaction()` по-прежнему выполняет безусловные START TRANSACTION/COMMIT; `post_masks.php`, `af_aa_mask_after_handler()` ловит ошибки как warning | BLOCKED: маска может разорвать внешнюю tx; безопасный joinable owner writer отсутствует |
| Engines и read-your-writes | Нет metadata установленной БД, данных о read_link/write_link и triggers | BLOCKED: InnoDB ACS не гарантирует rollback posts/users/rewards |
| Все побочные handlers | В отчёте 4.1.1 зафиксированы Balance→CS, AAM, cache/PM/subscriptions и shutdown stats; runtime inventory и outcome-aware protocol не подтверждены | BLOCKED: поздний post-insert hook недостаточен |
| Доверенный coordinator | Publication coordinator и durable submission/post_links protocol ещё не реализованы | PROPOSED: один client submission_id не обеспечивает идемпотентность |

Повторный SQL probe не выполнялся: программные файлы относительно аудита не изменены, прежние доказательства SQL-семантики приведены в отчёте 4.1.1. Новых данных, снимающих эти gates, нет. Нельзя обойти блокировку флагом `transaction_safe=true`, настройкой ACP, наличием функции PostDataHandler или отключением чужих hooks.

## Серверная граница Gateway — PROPOSED

Архитектурное имя остаётся `AF_ACS_MybbPostGateway::publishWithinTransaction(validated_post, submission_id)`. Это **не существующий callable API**. Конкретные PHP-типы определяются вместе с проверенным UoW/coordinator; пока создавать публичный метод, который умеет вставлять пост, небезопасно.

- `validated_post` — результат доверенного серверного preflight, связанный с конкретными actor/topic/prose/request hash и scope coordinator. Не произвольный клиентский массив и не флаг validated из HTTP. Обычные права форума, flood, moderation, attachments и ветки entry script должны быть проверены по установленному ядру; один `validate_post()` не заменяет их.
- Coordinator владеет outer transaction на доказанном MyBB writer, блокировками, durable submission receipt и outcome. Gateway не начинает/не фиксирует отдельную tx, не вызывает вложенный ACS TransactionManager::run(), не отдаётся напрямую AF API dispatcher.
- Gateway вызывает только штатный валидированный PostDataHandler. SQL INSERT posts, MAX(pid), угадывание PID по автору/теме и отдельный narrative POST перед ACS запрещены.
- Полученный PID/visible/merge/newness сверяются на writer. Неподдержанные moderation/draft conversion/merge/attachment branches дают отказ до механического применения; нельзя принудительно повысить видимость или обойти проверки MyBB.
- Same submission_id + same server payload hash возвращает прежний подтверждённый PID через coordinator; иной hash — conflict. Нужны durable UNIQUE constraints из Architecture §5 и transaction ownership, а не request-local cache Gateway.
- Commit receipt возвращается клиенту только после общего commit. Ошибка/неизвестный COMMIT сохраняют draft/prose/tape; unknown outcome требует authoritative lookup/recovery, без второго post. Ошибки Gateway остаются серверными структурированными результатами, не HTML/SQL/stack клиенту.
- Любые notifications, cache updates и shutdown work должны иметь доказанную outcome-aware границу. Нельзя считать их автоматически rollback-safe или молча отключать.

Без этих свойств даже внутренний «неподключённый пока» insertion helper создаёт незащищённый альтернативный путь. В этом PR он не добавляется.

## Контракт полей одной формы AdvancedEditor — PROPOSED

| Поле | Семантика | Обязательная серверная проверка будущего adapter |
|---|---|---|
| `acs_mode` | `narrative_post` или `combat_post` по A1.50 | Неизвестный/противоречивый режим отклоняется. Обычная форма без ACS сохраняет существующий narrative workflow; combat не становится разрешённым от наличия поля |
| `draft_id` | ID серверного ACS draft | Принадлежность actor/session/encounter/turn; формат и диапазон соответствуют Architecture DDL, ID не доказывает доступ |
| `draft_version` | Ожидаемая версия draft | CAS на актуальной записи; устаревшая вкладка не перезаписывает draft |
| `submission_id` | Серверный идентификатор публикации | Durable receipt, привязка к actor/draft/turn/payload_hash, exact replay и UNIQUE; не новый ключ после потери ответа |
| `confirmation_hash` | Подтверждение показанной серверной сводки и её зависимостей | Сервер заново строит/проверяет hash и актуальные dependencies; клиентский hash не разрешает commit |
| `message` | Литературный текст с обычным BBCode | Штатная валидация MyBB; фиксируется итоговое prose после допустимых transformations. Защищённые результаты ACS в message не помещаются |

Это описание семантики, не новая JSON-схема и не реализованный validator. Конкретные форматы/hash canonicalization и выдача IDs должны принадлежать будущим draft/submission services; фиктивные IDs или подтверждения в hidden fields не генерируются. CSRF MyBB и серверная авторизация обязательны отдельно от полей таблицы.

В будущем adapter использует существующий SCEditor и штатную синхронизацию textarea перед submit. `advancededitor.php`, `shell.php` и JS сейчас не меняются: доказанного publish extension point пока нет. Source/WYSIWYG, обычный ответ, Quick Reply, edit/Quick Edit сохраняют существующий путь. Edit изменяет только prose, без повторного Resolver, seal, AP/HP или переписывания history. IC-THEME рендерит карточку по сохранённому PID/post_link вне редактируемого message; в этом PR компонент не регистрируется.

## Что снимает блокировку и как проверять

1. Сопоставить installed core hashes/diff и полный runtime hook inventory с отчётом 4.1.1; получить безопасные metadata engines/triggers/driver/cache/mail без секретов и личных данных.
2. Отдельно обеспечить joinable owner writer Appearance и согласованные Balance/CS locks; доказать cache/subscriptions/shutdown outcome protocol на установленном коде. Не исправлять все зависимости случайными правками внутри Gateway.
3. На изолированной копии установки проверить реальную validation, отказ в правах и forced failure после PID: rollback всех SQL/owner counters/resources/queues, отсутствие внешней доставки и отложенных неверных stats. Проверить конкурентный duplicate submission и потерю ответа. До этого Gateway не READY.
4. После появления проверенного coordinator реализовать узкий Gateway и форму; команды combat commit всё ещё относятся к своей последующей задаче.

В этом PR: статическая повторная проверка gates, ссылки на четыре документа/аудит, `git diff --check`; программных изменений нет, поэтому `php -l` не требуется. Изолированный сценарий штатной MyBB validation/отказа/rollback **не выполнен**: установленный runtime/coordinator недоступен; результаты прежнего SQL probe не выдаются за такой сценарий. Полные suites/E2E не запускались.

Ручная приёмка текущего PR — проверить документ и сохранение BLOCKED, при желании обычный reply/Quick Reply/edit/Quick Edit как регрессию развёртывания. Боевой post не отправлять. Единственное изменение — этот Markdown. Откат — revert документационного commit, без SQL и lifecycle операций. ACS-4.2.1 не начата.
