# ATF global footer and modal contract

## Composition boundary

When ATF is enabled, final page composition creates exactly two sibling hosts immediately before `</body>`:

* `#atf-footer-composition` renders `footer.components` and is aligned to the page shell;
* `#atf-global-modal-host` renders `footer.modals`, is fixed/out of flow, and is the canonical mount point for lazy provider roots.

The provider remains the owner of authorization, data, requests, loading state, content, validation, submission, and whether backdrop closing is safe. ATF does not reproduce those behaviours. Providers may call `window.AFModalHost.mount(root)`; its body fallback is mandatory for ATF-off operation. A small DOM-ready observer also adopts legacy roots and removes a later duplicate of the same audited provider selector. Thus a trigger rendered before its root does not permanently disable a provider.

## Provider audit

| provider / owner | trigger | canonical root | footer slot | render timing and page contexts | close behaviour | mobile state |
|---|---|---|---|---|---|---|
| AdvancedAccountSwitcher | `#af_aas_trigger` in the authenticated user menu | `#af_aas_modal` | `footer.modals` (legacy markup is adopted) | Server widget; authenticated global header contexts | X, backdrop, Esc; provider restores its trigger | Table remains provider-owned; host prevents footer-flow and viewport overflow |
| AdvancedAlertsAndMentions | `#af_aam_header_link` | `#af_aam_modal` | `footer.modals` (legacy footer variable/fallback is adopted) | Server footer or `pre_output_page`; authenticated normal HTML pages, excluding CharacterSheets standalone surface | X, backdrop and Esc as implemented by AAM | Provider list scrolls inside its existing modal; canonical host is viewport-fixed |
| AdvancedWanted | `.af-wanted-post-chip` | `.af-wanted-modal-backdrop` | `footer.modals` lazy host | Created on first click on showthread/postbit Wanted chips | X, backdrop, Esc | Existing Wanted body is adopted; generic viewport contract prevents host overflow |
| Knowledge Base entry chips | KB chip/ATF catalog controls | `.af-kb-chip-modal` / `.af-atf-kb-modal-backdrop` (one per KB surface) | `footer.modals` lazy host | Created on demand on post/editor/catalog contexts; entry content remains fetched by KB/ATF provider | X, backdrop, Esc where provider currently allows | KB body owns internal content; fixed host and bounded provider shell remain usable at narrow widths |
| Knowledge Base editor/status | editor insert control or status action | `.af-kb-insert` / `[data-af-kb-status-modal]` | `footer.modals` lazy/adopted host | Editor pages and authorized KB character administration only | Provider close button/backdrop policy; status form keeps confirmation semantics | Existing one-column breakpoints and internally scrolling host |
| CharacterSheets | `[data-afcs-open]`, `[data-afcs-sheet]`, profile/postbit sheet actions | `[data-afcs-modal]` | `footer.modals` (server root adopted; lazy fallback mounts here) | Server-injected at page output or lazily created; profile, postbit and character workflow contexts | X, backdrop, Esc; iframe/data logic stays in CharacterSheets | Existing full viewport sheet constraints, bounded by host; no horizontal host flow |
| AdvancedProfileUI profile/postbit modal provider | `[data-af-apui-modal-url]` owned trigger | `[data-af-apui-modal]` | `footer.modals` lazy host | Delegated click initialization at DOM ready; profile, postbit, tabs, application/inventory/sheet/achievement contexts | X and Esc; provider controls backdrop behaviour and restores opener | Existing responsive iframe/content variants; adopted into viewport host |
| Inventory quick-slot support | inventory support-slot action | `.af-inv-support-modal` | `footer.modals` lazy host | Created inside inventory page on demand | Provider-owned explicit/backdrop close; binding logic unchanged | Provider dialog is max-width bounded; host removes footer-flow interaction |
| AdvancedShop | shop item action | `[data-af-shop-modal]` | `footer.modals` lazy host | First interaction on shop/catalog pages | Provider-owned X/backdrop/Esc behaviour | Existing shop responsive layout, canonical viewport host |
| Balance transfer/history | balance action | `[data-af-balance-modal]` | `footer.modals` adopted root | Server-rendered only where balance controls are available | Provider-owned, including validation-sensitive states | Existing provider body scroll; canonical host is out of layout |
| AdvancedThreadFields KB catalog | ATF KB catalog CTA | `.af-atf-kb-modal-backdrop` | `footer.modals` lazy host | Delegated click on forum/showthread ATF fields | X, backdrop, Esc | Provider builds a bounded detail shell; adopted into fixed host |

Transient editor popovers, tooltips, autocomplete lists and destructive in-sheet confirmation dialogs are not global providers: they remain scoped to their editor/sheet owner and are not moved.

## Presentation vocabulary

Providers can opt into `.atf-modal-overlay`, `.atf-modal-dialog`, `.atf-modal-header`, `.atf-modal-title`, `.atf-modal-close`, `.atf-modal-body`, `.atf-modal-loading`, `.atf-modal-footer`, and `.atf-modal-actions`. These classes define only sizing, scrolling, layout and appearance. They do not create a spinner or attach close handlers.

The generic accessibility contract is: an explicit close control, Esc unless a destructive/confirmation state blocks it, optional backdrop close only when the provider permits it, and focus restoration to the opener. Existing providers retain their own close implementation; adoption does not broaden backdrop-close behaviour.

## Stack levels

ATF reserves a single modal stack instead of per-addon escalating values:

1. `1000`: global host and overlay/backdrop;
2. `1010`: dialog shell;
3. `1020`: transient UI belonging to the active dialog (menus, pickers, tooltips).

Nested confirmation UX should remain inside the active provider root and use the transient level rather than create another page-global root. New providers must not introduce arbitrary `9999+` values.

## Compatibility

With ATF disabled no hosts, observer, or ATF modal styles are emitted. Server-rendered legacy roots remain in their original footer/widget locations, and lazy providers fall back to `document.body.appendChild`, preserving their existing operation.
