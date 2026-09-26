# AdvancedStatistic, AdvancedPostCounter и Balance: frontend context matrix

Матрица фиксирует границу до миграции owner-owned injections: получение и вывод
готового серверного значения не являются причиной загружать frontend источника.

| Addon / контекст | Backend / server-rendered output | JS/CSS component | Frontend contract |
|---|---|---|---|
| AdvancedStatistic: `/`, `index.php` | latest replies, online, forum totals и aggregate PostCounter собираются PHP; блок рендерится сервером | собственные tabs/layout блока | route `index.php` |
| AdvancedStatistic: прочие страницы / AJAX | данных/компонента Statistics нет | нет | нет |
| AdvancedPostCounter: `postsactivity.php`, `postsbyuser.php` | запросы и таблицы активности рендерятся PHP | tabs/filters и оформление собственных страниц | routes обеих страниц |
| AdvancedPostCounter: postbit, member profile | готовое число и ссылка рендерятся сервером | нет | нет |
| AdvancedPostCounter: Statistics, Balance | aggregate/значение или расчёт — backend dependency | нет | нет |
| Balance: `balancemanage.php`, `misc.php?action=balance_manage` | значения, история и операции обрабатываются PHP | интерактивные tabs/modal управления; inline config | routes страницы управления |
| Balance: postbit, member profile | готовые balance/EXP/level строки рендерятся сервером (если включены настройками) | нет | нет |
| Balance: CharacterSheets | готовые значения и серверные операции | Balance runtime не требуется; собственный UI принадлежит CharacterSheets | нет |
| Balance: Shop | проверка цены/оплата и готовое значение — backend/server-rendered интеграция | Balance widget отсутствует; UI принадлежит Shop | нет |
| Balance: rewards (`newreply`, quick reply, edit), AJAX | начисления выполняются post datahandler/XMLHTTP hooks | HTML runtime Balance не требуется | нет |

## Owner-owned delivery до/после

| Addon | До | После |
|---|---|---|
| AdvancedStatistic | `pre_output_page` напрямую вставлял CSS/JS на распознанной главной | manifest разрешает только `index.php`; direct injection дополнительно спрашивает permission API |
| AdvancedPostCounter | `pre_output_page` решал по blacklist, route и HTML эвристикам | только собственные page routes разрешают runtime; числа в postbit/profile и потребители данных не наследуют assets |
| Balance | central directory collection + queue API, inline config; blacklist удалял часть уже выданных assets | только manage routes разрешают directory/queue/config; standalone порядок jQuery не изменён |

Существующие blacklist settings сохраняются для совместимости. Они остаются
дополнительным запретом внутри разрешённых contexts, но больше не являются
основной моделью выдачи assets.
