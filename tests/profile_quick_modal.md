Profile quick-content contract
==============================
APUI owns one shared modal. Built-in application/arsenal/talents/resources/pets
links fetch `member.php?action=profile&uid=…&af_profile_tab=quick&af_profile_quick=…`.
Responses are `{html, assets:[{type,url}]}` with no-store caching. The route checks
profile permissions, forum/thread visibility/ownership/password for applications,
and Inventory owner/entity availability before calling the existing renderers.
Applications use `af_atf_build_display_block_for_tid_fid`, never fetched showthread
HTML. ElementTheme's existing delivery retains palette/effect styles and metadata.
Asset URLs are loaded lazily, same-origin, once per document. Content is fetched
again on each opening; closing aborts requests, clears nodes/Canvas and returns focus.

Sheet uses the existing CharacterSheets embed URL in a lazily-created iframe,
initially on its default main tab. Closing clears it to about:blank. The legacy
profile sheet fragment endpoint remains available, but neither template contains a
sheet panel. Custom providers may declare `modal_kind` and `modal_url`; plain/external
providers keep navigation. Rewards load their Inventory assets with the lazy tab
response, not during initial profile composition.

MyBB's already-filtered native actions appear once in `extra` (ATF `profile.extra`
slot and APUI fallback), retaining form methods, URLs, IDs and CSRF values. Empty
native groups are omitted. Both template seeds must be applied to live database
templates via their existing activation/ownership lifecycle; manually overridden
sites retain the existing fail-closed ownership policy. This change does not write
to a production database.

Checks: `php tests/profile_quick_modal_regression.php`,
`node tests/profile_quick_modal_browser.cjs`, and the same browser check with
`AF_TEST_BROWSER=firefox`. Tests use fixture permissions/renderers and simulated
content endpoints with real APUI JS/CSS and Canvas engine; they are not production
MyBB/CharacterSheets calculator/CRUD tests. Guest geometry remains covered by
`tests/advancedmenu_game_ui_browser.cjs`, including inherited asymmetric borders.
