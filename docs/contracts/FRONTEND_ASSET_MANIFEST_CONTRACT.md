# Frontend asset manifest contract AF

Этот документ — актуальная обязательная спецификация frontend-интеграции addons
AdvancedFunctionality. Исторические причины и риски старой схемы описаны отдельно
в `FRONTEND_ASSET_ARCHITECTURE_AUDIT.md`.

## Базовый принцип

Новый addon не должен требовать изменений в `advancedfunctionality.php`. Addon сам
объявляет frontend-поведение секцией `frontend` в `manifest.php` и проверяет все
собственные точки выдачи через единый API:

```php
af_frontend_asset_allowed($addonId, $resource, $context, $responseFacts);
```

API только принимает решение и не выводит HTML. Обычно `$context` передаётся как
`null`, чтобы AF использовал текущий request context. Для совместимости с ядром,
которое ещё не содержит API, addon может использовать fail-open проверку через
`function_exists()`, как это делает `AdvancedWanted`.

## Реализованные режимы manifest

Глобальный runtime явно объявляется так:

```php
'frontend' => [
    'mode' => 'global',
],
```

Контекстный runtime объявляет список routes:

```php
'frontend' => [
    'mode' => 'contextual',
    'routes' => [
        [
            'script' => 'example.php',
        ],
    ],
],
```

В реализованном resolver route может одновременно сопоставлять `script`,
`action`, `fid` и `tid`; `fid`/`tid` принимают одно значение или список. Для
response-aware случая реализован список `response_rules`, а для contextual mode
также существует boolean `directory_fallback`. Неизвестный/невалидный mode
намеренно откатывается в legacy fail-open режим. `legacy` нужен для совместимости,
но не является контрактом проектирования нового addon.

## Request context и response/component facts

**Request context** — неизменяемые факты, известные до рендера: frontend/ACP,
имя script, action, `fid`, `tid`, AJAX-флаг и пользователь. Route страницы обычно
можно определить по ним заранее.

**Response/component facts** становятся известны только в ходе построения ответа:
например, был ли реально отрендерен chip, modal trigger или другой компонент.
Такие факты передаются четвёртым аргументом permission API и сопоставляются с
`response_rules`. Их нельзя подменять догадкой только по route.

## Owner-owned assets

Manifest управляет central collector, но не может автоматически остановить код
addon, который сам добавляет `<script>`, `<link>`, inline config, изменяет
`$headerinclude` или вставляет assets в `pre_output_page`. **Каждая** такая owner-
owned точка обязана вызвать `af_frontend_asset_allowed()` до выдачи. Одной секции
manifest недостаточно, если addon обходит collector. Проверка должна охватывать и
config, и соответствующий runtime, сохраняя config перед runtime и не создавая
двойное подключение.

## Permission и theme stylesheets — разные оси

```text
frontend permission = НУЖЕН ЛИ addon в текущем frontend-контексте
theme_stylesheets    = КАК доставляется его CSS (theme cache или файл)
```

Permission применяется первой. Решение theme stylesheet не является разрешением
загрузить addon и не должно использоваться как замена frontend context.

## Зависимости

Backend dependency не равна frontend dependency. То, что Shop читает KB,
Statistic читает PostCounter или Balance используется CharacterSheets, само по
себе не разрешает JS/CSS зависимого addon. Его assets нужны только при реальной
frontend UI/runtime зависимости на текущей странице.

## AJAX и modal

AJAX JSON endpoint не должен пытаться загрузить runtime вызывающей страницы:
JSON не является HTML-контейнером для `<script>`/`<link>`. Caller page обязана
заранее получить assets, необходимые trigger, modal и обработчику ответа.

## Directory fallback

Нельзя проектировать новый addon по правилу «положить все JS/CSS в `assets/`, и AF
сам загрузит их везде». Directory fallback остаётся механизмом совместимости.
Новый addon должен явно выбрать frontend mode, contexts и delivery; для
contextual addon следует осознанно задать `directory_fallback`.

## Никаких новых blacklist

Новый addon не создаёт blacklist страниц. Модель разрешающая и начинается с
«addon по умолчанию не нужен»: manifest route или component/response fact явно
сообщает, где он нужен. Запрещена обратная модель «загрузить везде, затем исключать
список страниц». Существующие legacy blacklist мигрируются отдельными задачами.

## Обязательный checklist нового addon

- [ ] frontend mode указан в manifest
- [ ] routes/component contexts определены
- [ ] owner injections используют permission API
- [ ] backend dependencies отделены от frontend dependencies
- [ ] AJAX проверен
- [ ] нет лишнего global loading
- [ ] проверен JS/CSS order
- [ ] проверен directory fallback
- [ ] activation/reactivation проходит

## Standalone frontend page contract

All new AF standalone pages must follow [ATF_PAGE_SHELL_CONTRACT.md](ATF_PAGE_SHELL_CONTRACT.md): one `.pun.atf-page-shell`, ATF-owned exterior geometry, preserved fallback, no embedded shells, and manifest-based frontend permissions.


## AdvancedMenu: public routes are opt-in custom navigation

New AF addons must not automatically register ordinary page links in AdvancedMenu,
whether through a manifest `menu_provider`, a callback or a direct registry call.
Provide a working public route and let the administrator add its URL as a CUSTOM
item in ACP, choose its section/container and order, and configure its visibility.
Route permissions remain the responsibility of the route owner; adding a menu
link does not grant access to the destination.

Provider registration is reserved for specialized interactive integrations such
as AdvancedAlertsMentions notifications and AdvancedAccountSwitcher account
switching. Their modal triggers, badges, handlers and provider identity remain
system-owned. AdvancedMenu rejects automatic `type=link` registrations except
its fixed MyBB account/access controls (`profile`, `usercp`, `private_messages`,
`modcp`, `admincp`). Logout, guest login/registration and the theme widget remain
system controls. New-post and daily-post search links are optional CUSTOM links.

AdvancedAppearance (`presets`, `fitting_room`) and AdvancedPostCounter
(`post_activity`) no longer expose menu-provider contracts or callbacks. Routes
`apstudio.php`, `studio.php` (compatibility alias), `fittingroom.php` and
`postsactivity.php` remain owned and served by the original addons. To show them,
add ordinary CUSTOM links; no old provider container restriction is inherited.

Existing provider overrides for retired links are deliberately retained as
inactive records. The active catalogue is built from permitted registrations,
so these rows cannot enter ACP/frontend trees, constrain sections or reappear on
addon reactivation. No row is removed from the custom-items table, including
same-URL links, manually converted items and records of ambiguous provenance.
The historical migration that adopted/deleted lookalike CUSTOM links is removed.
Section metadata and administrator ordering are not reset. If an installed addon
still ships an old callback/manifest, the registry gate also rejects its ordinary
links; deploy the complete update to remove the old declarations themselves.
