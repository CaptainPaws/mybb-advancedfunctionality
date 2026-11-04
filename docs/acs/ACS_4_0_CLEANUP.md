# ACS-4.0-CLEANUP — ACP MyISAM Migration Wizard retired

По архитектурному решению **ACS Stage 4 Revision 2** ACS не требует перевода существующих таблиц MyBB в InnoDB. Собственные боевые таблицы ACS остаются InnoDB. Публикация через MyBB требует отдельного координируемого процесса с восстановлением; новый PublishService в этом изменении не реализован, готовность публикации не объявляется.

Из ACP удалены вкладка «Инструменты» с мастером миграции БД, команды проверки/запуска/продолжения/восстановления и разрешение `admin.migration.manage`. Удалены неиспользуемые ACP-обёртки: `Wizard.php`, `AcpExecution.php`, `MigrationService.php`, `MigrationJournal.php`, `BackgroundWorker.php`, `RuntimeAssessment.php` из `modules/Administration/Migration/`, а также два теста этих удалённых обёрток. Старый `acs_section=tools` обрабатывается стандартным fallback в «Основные»; миграционные команды больше не исполняются.

Обычная диагностика, интеграционные проверки и диагностика публикации сохранены. Движки существующих таблиц MyBB отображаются как сведения, без требования конвертации. Собственные таблицы ACS по-прежнему проверяются на InnoDB и полноту схемы. Install/activate/upgrade используют только прежние версионированные миграции схемы ACS (`modules/Administration/Migrations.php`, `migrations/*.php`); они не подключают EngineMigration.

Сохранены автономные `tools/acs-engine-migration.php`, `tools/acs/EngineMigration.php` и `tests/acs_engine_migration.php`. Они не подключаются runtime ACS. Их наличие не означает рекомендацию запускать миграцию при обновлении.

На установленном форуме **не удалять автоматически** `cache/acs-migration-<hash>/` и его `journal.php`, `lock.php`, `engine-state.php`, временные journal/state файлы, а также отдельный CLI state directory с `state.json`, intent, snapshots и evidence. До ручной очистки специалист должен установить, нет ли незавершённого SQL/intent и не нужны ли эти файлы для восстановления. CLI state и ACP state не взаимозаменяемы: не переносить ACP-журнал в CLI и не повторять ALTER вслепую. Удаление исходников обёрток не удаляет эти файлы. Пользовательские данные и существующие таблицы не изменяются.

Отчёты `ACS_4_1_4A_*` и `ACS_4_1_4B_*` сохранены как история прежней архитектуры. Их требования миграции, привилегий, staging и backup evidence больше не являются инструкциями установки или обязательными зависимостями боевой публикации.

Проверки cleanup: PHP 8.4.24 — `php -l` всех изменённых PHP-файлов; целевые `acs_publication_diagnostics.php` и `acs_permissions_diagnostics_regression.php`; статическая проверка ACP includes/routes и отсутствия EngineMigration в runtime ACS. Полный test suite не запускался. DB foundation fixture требует отдельного isolated MariaDB socket и в этом окружении не выполнялся. Боевая механика, миграции схемы ACS, ядро MyBB и другие аддоны не изменены.
