# ATF compose template audit (MyBB 1.8.40)

## Runtime entry points

The audit was performed against the `mybb_1840` tag. `newthread.php`, `newreply.php`, and `editpost.php` render the roots `newthread`, `newreply`, and `editpost` respectively. The PHP controllers continue to own validation, permissions, preview, draft, moderation, attachment processing, and SCEditor initialization; ATF owns only the templates listed below.

## Proven compose graph

| Concern | MyBB 1.8.40 templates | ATF decision |
| --- | --- | --- |
| Page/form roots | `newthread`, `newreply`, `editpost` | Owned; one semantic `.atf-compose` shell |
| Subject and prefix | root plus `post_prefixselect_prefix`, `post_prefixselect_single` | Root owned; core prefix option data retained |
| Post icons | `posticons`, `posticons_icon` | Owned; labelled fieldset and choices |
| Editor | root plus `codebuttons` | Root provides `.atf-compose__editor`; `codebuttons` remains core/Advanced Editor owned and unchanged |
| Normal post options | `*_postoptions`, `*_signature`, `*_disablesmilies` | Layout wrappers owned; already-labelled leaf controls retained |
| Moderation | `newreply_modoptions`, `newreply_modoptions_close`, `newreply_modoptions_stick`, `global_moderation_notice` | Section owned; labelled leaf controls and notice retained |
| Subscription | `post_subscription_method` | Owned; every radio remains inside a text label |
| Poll | `newthread_postpoll` | Owned; checkbox and option-count label retained |
| Attachments | `post_attachments`, `post_attachments_new`, `post_attachments_attachment`, `post_attachments_attachment_unapproved`; action fragments for add/update/remove/insert/approve | Layout-bearing fragments owned; controller actions and action fragments retained |
| Full-edit extras | `editpost_reason`, `editpost_delete` | Owned; reason is explicitly labelled and deletion is a distinct danger action |
| Submit actions | root plus `post_savedraftbutton` | Root owns primary/secondary actions; the optional core draft action is scoped by compose CSS |
| Preview/errors/rules/review | `previewpost`, error values, `forumdisplay_rules*`, `newreply_threadreview*` | Kept as controller-rendered content outside the compose field structure |
| Authentication/CAPTCHA | `loginbox`, `changeuserbox`, `post_captcha*` | Kept core-owned because these shared templates are not exclusive to the three compose routes |

`codebuttons` is intentionally absent from ATF template ownership. Consequently the SCEditor setup, toolbar, BBCode registry, source/WYSIWYG state, iframe and lifecycle stay byte-for-byte under MyBB/Advanced Editor control.

## Responsive boundary

The compose shell uses the existing `.pun.atf-page-shell` contract and only percentage widths relative to that shell. It does not modify `html`, `body`, `.wrapper`, `#container`, `#content`, or `.post`, and introduces no `100vw` sizing. At the mobile breakpoint, attachment rows and action groups collapse without changing the forum width.
