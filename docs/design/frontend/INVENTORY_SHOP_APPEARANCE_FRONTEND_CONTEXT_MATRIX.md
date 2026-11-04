# Inventory / Shop / Appearance: frontend context matrix

Матрица составлена по фактическим owner-owned точкам выдачи и вызовам интеграций.
Backend-чтение таблиц или PHP API не считается frontend-зависимостью.

## AdvancedInventory

### Контексты и доставка

* Full page: `inventory.php`, `inventories.php`, `abilities.php` и их реальные
  `misc.php` actions `inventory`, `inventories`, `abilities`, `tab`, `entity`.
* Component/tab: `af_advancedinventory_build_inventory_fragment()` и
  `af_advancedinventory_build_abilities_fragment()` регистрируют факт
  `has_inventory_component`. CharacterSheets действительно вызывает первый
  renderer, поэтому получает runtime только когда вкладка реально построена.
* AJAX actions возвращают fragment/JSON и сами не являются контейнером assets.
* Owner delivery остаётся в `append_runtime_assets()` / `append_embedded_assets()`;
  оба проходят permission API. Legacy blacklist сохранён как дополнительное
  ограничение, но больше не является разрешающей моделью.
* Backend dependencies: Shop tables/price lookup, Appearance assignment API.
  Frontend dependency: только реально отрендеренный Inventory component; ссылка
  Shop на страницу Inventory frontend-зависимостью не является.

| URL | Inventory UI | JS | CSS |
|---|---:|---:|---:|
| `inventory.php` | да | да | да |
| `shop.php` | нет (только ссылка) | нет | нет |
| `charactersheets.php` | условно: отрендеренная вкладка | условно | условно |
| `showthread.php` | нет | нет | нет |
| `member.php` | нет | нет | нет |
| `index.php` | нет | нет | нет |

## AdvancedShop

### Контексты и доставка

* Full page: `shop.php`, `shop_manage.php` и соответствующие `misc.php` actions.
* Component: HTML с `af-shop`/`data-af-shop-modal` даёт факт
  `has_shop_component`; route `af_charactersheet` намеренно не разрешает assets
  сам по себе.
* Owner delivery: `af_advancedshop_assets_html()` и `pre_output_page`. Inline
  `window.AFSHOP.endpointScript` и runtime выдаются одним permission unit и в
  порядке config → runtime.
* Backend dependencies: Inventory после checkout, Balance, KB item data и
  Appearance preset data. Ни KB, ни Inventory, ни Appearance runtime на Shop
  автоматически не подключаются. Frontend dependency существует только для
  фактически выведенного Shop component.

| URL | Shop UI | JS | CSS |
|---|---:|---:|---:|
| `shop.php` | да | да | да |
| `inventory.php` | нет | нет | нет |
| `kb.php` | нет | нет | нет |
| `index.php` | нет | нет | нет |
| `showthread.php` | нет | нет | нет |

## AdvancedAppearance

### Контексты и доставка

* Собственные full pages: `apstudio.php` и `fittingroom.php` (примерочная).
* Server-rendered integrations: scoped CSS для реально найденных назначений в
  profile/postbit/thread/supported surfaces. Факт `has_appearance_runtime`
  разрешает inline CSS и modal-scope helper только когда CSS непустой.
* Owner delivery: `af_aa_page_asset_tags()`, runtime CSS и modal-scope JS проходят
  permission API. Примерочная сейчас отдельная route, не внешний component.
* Backend dependencies: Shop читает presets и Inventory применяет assignments.
  Эти операции не требуют Appearance JS/CSS. Frontend dependencies: собственные
  страницы и server-rendered scoped appearance с фактом ответа.

| URL/context | Appearance UI/runtime | JS | CSS |
|---|---:|---:|---:|
| `apstudio.php` | да | да | да |
| `fittingroom.php` | да | да | да |
| `shop.php` | нет; только backend preset data | нет | нет |
| `index.php` | нет | нет | нет |
| `member.php` | условно: есть назначенный preset | modal helper условно | scoped inline условно |
| `showthread.php` | условно: postbit/thread preset | modal helper условно | scoped inline условно |

## Общий contract и ограничения

Все три manifest используют `mode=contextual`, явные routes/response facts и
`directory_fallback=false`. Поэтому central directory fallback не возвращает их
файловые assets на посторонних страницах. Существующие theme stylesheet,
`advancedstyles.css`, DB attachment/compiled stylesheet pipeline не изменялись:
если тема уже физически скомпилировала правила, permission API не может удалить
их из готового общего файла. Это отдельная ось доставки CSS.
