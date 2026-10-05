# Showthread forensic audit (2026-10-05)

## Scope and measurement boundary

This repository is an addon source tree, not a runnable warprift.ru/MyBB
installation: it contains neither the production database nor a web-server
entry point for `showthread.php`. Consequently production PHP wall time, SQL
time, TTFB, DOMContentLoaded and `load` timings cannot be truthfully measured
here. Those values must be captured on the deployment with MyBB debug output
or an APM trace. This audit reports reproducible call/query and timer counts;
it does not invent millisecond figures.

The regression workload is 20 postbits, once with one repeated uid and once
with 20 distinct uids. It covers the providers for which source inspection
found repeated work and protects the already-correct APF and ATF caches.

## Findings

### Server

`af_balance_get_postbit_data()` called `af_balance_get()` unconditionally.
Balance is rendered through an ATF postbit slot, so the same author's balance
row and level formatting were loaded and calculated again on every post. For
20 posts by one author the Balance part of the postbit pipeline therefore did
20 identical selects. It now uses a showthread request cache and performs one
select/calculation per distinct uid: **20 -> 1** for the repeated-author
fixture and **20 -> 20** for 20 distinct authors.

APF already caches `uid:field_key`; its secondary-avatar lookup remains **1**
query for 20 posts by one author and **20** for 20 authors. ATF already builds
the thread-field block only for the first post and caches by tid; it remains
**1** build in both fixtures. CharacterWorkflow, sheet slug/application data,
and the ATF thread-field value helpers also have request caches. Character
Sheets' `showthread_start` path is permission/forum gated and prepares only
button state; it does not render an inventory or a complete sheet.

The AdvancedShop and AdvancedInventory manifests remain contextual. Their
route lists do not include `showthread.php`, and their runtime response rules
require an explicit component fact. Loading backend integration code therefore
does not grant frontend-runtime permission.

### Browser

PR #959 left two Quick Edit discovery loops. A click scheduled six scans rooted
at the whole document, while insertion of the textarea scheduled another six
scans rooted at the inserted subtree. One open could therefore cause **12
discovery scans and 10 retry timers**, in addition to the single boot scan.
The MutationObserver now examines only a newly added subtree once: **1 scoped
discovery scan and 0 retry timers** per insertion. Idempotence flags still
ensure one SCEditor instance and one ready event.

The published-post counter observer also called a full-document post scan for
every mutation. It now visits only added `.post` roots/descendants. The
600-ms interval remains one per live editor binding, is reused on duplicate
ready events, and clears when its textarea is disconnected.

## Quick Edit root cause

MyBB 1.8.40's `Thread.quickEdit()` enumerates `.post_body`, immediately reads
that node's id to derive the pid, and installs jEditable on `#pid_PID`. PR #963
moved `id="pid_PID"` from `.post_body` onto an inner ATF message node. The
first failure was therefore before AJAX: initialization attempted to call
`replace()` on the missing `.post_body` id, so the click lifecycle was never
bound. The source-string regression test checked the newly assumed inner-host
shape and could not exercise native `thread.js`.

The template again exposes native `.post_body#pid_PID`. The stable ATF metadata
line is a sibling immediately before that replaceable host, so jEditable
replaces only message content and Cancel/Save cannot move or discard metadata.
Advanced Editor recognizes the actual stable contract: numeric
`quickedit_PID[name=value]`, a form inside matching `#pid_PID`, and that host
inside the post. It does not require incidental ATF wrappers.
