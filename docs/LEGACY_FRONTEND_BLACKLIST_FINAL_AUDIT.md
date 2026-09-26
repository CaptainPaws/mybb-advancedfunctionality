# Финальный аудит legacy frontend blacklist

Дата: 2026-09-26. Целевая среда: MyBB 1.8.40, PHP 8.5.0.

## Решение

Удаление legacy blacklist **заблокировано**. Repository-wide проверка обнаружила
frontend owner без `frontend` metadata: **AdvancedGiphy**. Его
`af_advancedgiphy_pre_output()` напрямую добавляет CSS, CSS variables, JS и inline
configuration, тогда как manifest не объявляет frontend contract. В соответствии
с правилом остановки blacklist runtime, helpers и ACP settings в этой задаче не
удалялись.

Перед повторным финальным cleanup AdvancedGiphy необходимо мигрировать на
contextual manifest contract и пропустить каждый owner injection через
`af_frontend_asset_allowed()`. CSS следует разрешать только в подтверждённом
контексте Giphy output, а JS/config — только при наличии editor component.

## Найденные settings и planned replacements

| Addon | Setting key | Создание | Чтение | Используется сейчас | Frontend replacement | Можно удалить сейчас |
|---|---|---|---|---|---|---|
| AdvancedEditor | `af_advancededitor_disable_on` | install/ensure | owner pre-output через core resolver | да | contextual editor manifest | нет, общий cleanup остановлен |
| AdvancedGallery | `af_gallery_assets_blacklist` | install/activate ensure | gallery matcher/pre-output | да | contextual gallery routes/components | нет |
| AdvancedProfileFields (APF) | `af_apf_assets_blacklist` | install ensure | APF matcher/pre-output | да | response fact `has_apf_output` | нет |
| AdvancedInventory | `af_advancedinventory_assets_blacklist` | install/activate ensure | inventory owner injector | да | inventory page/component resources | нет |
| CharacterSheets | `af_cs_assets_blacklist` | settings bootstrap | CharacterSheets owner injectors | да | page/modal/profile-chip resources | нет |
| AdvancedPostCounter | `af_apc_assets_blacklist` | install settings | postcounter pre-output | да | page routes and component facts | нет |
| Balance | `af_balance_blacklist` | settings ensure | Balance queue/config owner | да | manage routes/resources | нет |
| ForceRefresh | `af_forcerefresh_assets_blacklist` | settings ensure | force-refresh pre-output | да | showthread quick-reply context | нет |
| AdvancedShop | `af_shop_assets_blacklist` | install/activate ensure | shop page/component owner | да | shop routes and component resource | нет |
| ResponsiveLayout | `af_advresponsivelayout_assets_blacklist` | install ensure | responsive owner injector | да | global manifest with excluded technical contexts | нет |
| KnowledgeBase (KB) | `af_kb_assets_blacklist` | install/upgrade ensure | KB owner injector | да | explicit KB routes/resources | нет |
| AdvancedThreadFields (ATF) | `af_atf_assets_blacklist` | install settings | ATF owner injector | да | editor/display/KB component resources | нет |

Conventional aliases `af_{addon}_assets_blacklist` and
`af_{addon}_disable_on`, plus APF/ATF/CharacterSheets aliases, are resolved by
`af_is_blacklisted()`. Нестандартные keys из таблицы также имеют локальных
consumers, поэтому ни один из них нельзя удалять отдельно до устранения blocker.

## Owner/fallback audit

Все ранее мигрированные owners используют permission API перед выдачей ресурсов,
но AdvancedGiphy остаётся исключением. Его manifest не имеет `frontend` metadata,
поэтому central collector рассматривает addon в legacy mode и directory fallback
не может быть гарантированно отсечён новым permission contract до owner injection.
Это единственный найденный addon без metadata, который реально выдаёт frontend
assets.

Addons без frontend metadata, не требующие миграции assets:

- Fake Online — `pre_output` является no-op;
- Fast News — HTML injection явно отключён;
- Index Redirect — frontend assets отсутствуют;
- Smart URL Titles — `pre_output` является no-op.

## Lifecycle и DB rows

ACP controls и lifecycle creation намеренно сохранены, поскольку runtime cleanup
не выполнен. Existing rows не требуют миграции на этой стадии. После миграции
AdvancedGiphy безопасная стратегия — прекратить чтение и создание deprecated
settings; физическое удаление существующих rows не должно быть обязательным для
работы и может выполняться только отдельной lifecycle migration.

## Условия повторного запуска cleanup

1. Добавить AdvancedGiphy `frontend` metadata с `directory_fallback: false`.
2. Защитить его CSS и editor JS/config permission API.
3. Проверить editor routes/component fact и activation/reactivation.
4. Повторить repository-wide owner и fallback audit.
5. Только затем удалить consumers, resolver helpers и ACP controls отдельными commits.
