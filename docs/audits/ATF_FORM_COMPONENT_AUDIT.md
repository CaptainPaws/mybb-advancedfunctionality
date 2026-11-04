# ATF form component structural audit

## Scope and invariant

This is the Task 57 consistency pass for forms already leased by Adaptive Theme Framework (ATF). It changes presentation and responsive containment only. Form actions, methods, hidden fields, control `name`/`value`/`id` attributes, parser formats, permission checks, JavaScript target classes, and server validation remain MyBB's authority. Every shared CSS rule is gated by `body.atf-active`; an ATF-off request is therefore unaffected.

The canonical structure is `.atf-form-section` containing `.atf-form-row`, with `.atf-form-row__label`, `.atf-form-row__controls`, and `.atf-form-row__hint` where those elements add meaning. Existing semantic `<label>` and `<fieldset>/<legend>` markup is valid and was not mechanically rewritten merely to add classes.

## Surface matrix

| Surface | Ownership/result |
| --- | --- |
| Login and registration | Not leased by ATF in the current template manifest. Core markup and behavior are explicitly out of scope. |
| UCP profile, credentials, preferences, signature, avatar/upload, notepad, buddy/ignore lists | ATF-owned. Native controls, upload encoding, errors, editor placeholders, autocomplete IDs, and submission contracts retained. |
| PM compose, advanced search, folders, archive, tracking and quick reply | ATF-owned. Compose errors/preview stay before fields; recipients retain core Select2 setup; folder destructive action stays distinct. |
| ModCP find user, warning logs, IP/log filters, queue, reports, suspensions, edit profile, bans and announcements | ATF-owned. Queue/report selection names and JS hooks are untouched. Date/suspension fragments keep their parser contract and wrap on narrow screens. |
| Member search/user list | ATF-owned. Search inputs and sort controls use the shared containment rules. |
| Forum display/show thread | ATF owns the migrated shell and controls. New thread, new reply, edit post and their full forms are not leased, so their core templates were not replaced. |
| Announcement editor | ATF-owned. Message textarea, `{$codebuttons}`, start/end date controls and allow-option radio groups remain native. |

## Controls and labels

Text, email, password, URL, number, date, search, file, select and textarea controls inside an ATF page now share `box-sizing: border-box`, `min-width: 0`, and `max-width: 100%`. Multiselects and textareas occupy their available row width. Textareas retain a native vertical resize affordance. Disabled and readonly fields are visibly muted without being treated as an authorization mechanism.

Explicit labels use their existing matching IDs where IDs exist; wrapping labels remain valid for the many native fragments that do not expose IDs. Radio/checkbox option sets in PM search, signature preferences, UCP options, announcement options and date-ending choice use native labels and, for groups, `fieldset`/`legend`. The shared choice rules do not alter control names, values, IDs, or event handlers. Provider-produced profile fields are opaque because their permission-sensitive markup is not safe to parse and rebuild.

Buttons keep their native elements and submit semantics. The presentation roles are:

- `.atf-button` — primary submit/action;
- `.atf-button--secondary` — preview, draft, or other non-primary action;
- `.atf-button--danger` — destructive action;
- `.atf-button--link` — contextual button that must behave visually like a link.

Links remain links and submit inputs remain submits.

## Validation and files

Server-rendered error variables remain adjacent to or immediately above their owning forms (`$errors`, `$error`, `$avatar_error`, `$send_errors`). No required attribute or client-only validation was introduced. Error containers are only bounded and allowed to wrap.

The UCP avatar form retains `multipart/form-data` and the native file input. The consistency CSS does not hide or replace file controls.

## SCEditor / AdvancedEditor

The audited ATF editor hosts are PM compose/quick reply, UCP signature, ModCP edit-profile signature, and announcement add/edit. Reply/new-thread/edit-post remain core-owned but continue to receive the existing AdvancedEditor/MyBB pipeline when enabled.

ATF does not initialize SCEditor. It retains each textarea and `{$codebuttons}` insertion point, so MyBB and AdvancedEditor remain the only initialization owners and no duplicate instance is created. Existing MyBB `jscripts/sceditor` and `jquery.sceditor.mybb.css`/AdvancedEditor stylesheet delivery paths are not renamed or replaced. The new rules apply only after an editor exists: the generated container, iframe and source textarea are width-bounded, while the toolbar wraps and may scroll internally rather than forcing page overflow. With JavaScript or the editor disabled, the original textarea remains visible and usable.

## Post icons

PM compose retains the permission-filtered `$posticons` output and its exact input names and values. ATF removes only legacy table presentation in its existing composition hook; the dedicated `.atf-pm-compose__posticons` wrapper now provides a wrapping, width-bounded control group. Post icons are not removed.

## Select2 and autocomplete

The audited integrations are ModCP Find User, Warning Logs and Ban User; PM sender/recipient fields; and UCP buddy/ignore search. Existing `MyBB.select2()` calls, per-field initializers, XMLHTTP endpoint (`xmlhttp.php?action=get_users`), IDs, selection limits and callbacks are unchanged. ATF only constrains generated Select2 containers and long tokens to the available width. No new initializer or JavaScript framework was added.

## Date and duration controls

Announcement start/end fields, UCP/ModCP birthday groups, PM archive dates, and ModCP suspension durations retain their separate native values; they are not converted to HTML date inputs because that would change MyBB's parser contract. Their control groups use minmax tracks and collapse/wrap on mobile. Suspension selectors and their existing `toggleSuspend` hooks are untouched.

## Exceptions and follow-up boundary

1. Core login/registration and core new-thread/reply/edit-post templates are not ATF-owned; changing them here would violate ATF-off isolation and the component-pass boundary.
2. MyBB/provider fragments such as custom profile fields, timezone selects, ban-group selects and post icons may not expose ideal IDs. They remain opaque to avoid changing extension contracts; wrapping labels or group legends provide semantics where ATF owns the parent.
3. SCEditor, Select2 and autocomplete lifecycle code stays with MyBB/AdvancedEditor. This audit provides containment, not reinitialization.
4. Legacy core error HTML can vary by language/theme. ATF preserves it verbatim and supplies wrapping/width constraints instead of replacing server messages.
5. Visual redesign, parser changes, endpoint changes and business-logic changes are intentionally excluded.
