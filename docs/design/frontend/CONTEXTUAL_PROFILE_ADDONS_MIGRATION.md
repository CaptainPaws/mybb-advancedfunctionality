# Contextual/profile addons frontend migration

The audit used the actual hooks, manifests, templates, and asset files in this
repository. `Privacy Shield` is not present as an addon (there is no manifest,
bootstrap, asset directory, hook, or response marker), so no speculative route
or error-state permission was created.

| Addon | Frontend migration | Mode and contexts | Owner delivery | Before / after |
|---|---|---|---|---|
| Advanced Rules | yes | contextual: `misc.php?action=advancedrules` | Inline behavior remains in `rules_index`; ACP editor assets are separate | directory scan could not add files / explicit page permission and fallback off |
| AdvancedCharacters | yes | contextual: `characters.php` | CSS remains a theme stylesheet attached to `characters.php` | stylesheet attachment existed / permission now limits eligibility to the alias |
| AdvancedAvatar (`advancedposteravatar`) | yes | contextual: `index.php`, `forumdisplay.php`, `online.php` | `pre_output_page` asset injection is permission-gated | fallback could load CSS/JS everywhere plus owner injection / owner injection only on actual renderer pages |
| AdvancedProfileFields | yes | response fact `has_apf_output` | `pre_output_page` detects `af-apf-` markup, retains the legacy blacklist, and emits CSS only | directory fallback loaded CSS plus no-op JS broadly / CSS only when output exists |
| AdvancedProfileUI | yes | response fact `has_apui_output` | existing postbit/profile/component detection now passes through permission before CSS/JS injection | directory fallback and global stylesheet attachment were broad / component-owned injection and member/showthread stylesheet attachment |
| Privacy Shield | no (addon absent) | none | none | no code or assets found; no route whitelist invented |
| Smart URL Titles | no (server-side) | none | message submission, preview, and XMLHTTP hooks only | placeholder no-op CSS/JS could be discovered / placeholders removed, no frontend metadata |
| Index redirect | no (server-side) | none | `global_start` redirect logic only | no assets / unchanged |

The route matrix is covered programmatically for `/`/`index.php`,
`forumdisplay.php`, `showthread.php`, `member.php?action=profile`, `online.php`,
`memberlist.php`, `misc.php?action=advancedrules`, and `characters.php`.
Access-denied testing cannot be assigned to Privacy Shield because that addon and
its response marker are absent. No permission, redirect, URL, character data, or
other business logic was changed. Theme CSS is still delivered by the existing
theme stylesheet system; `advancedstyles.css` was not split or edited.
