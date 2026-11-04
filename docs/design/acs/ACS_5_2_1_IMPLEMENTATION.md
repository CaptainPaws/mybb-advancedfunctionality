# ACS-5.2.1 — Status ticks and duration boundaries

## Baseline and scope

Base: `main` at `9b26abfbeaf7f2f3a4e182028705e270130c0f50`, including ACS-5.1.1 and ACS-5.1.2 (migration 010). Existing `af_acs_effects`, Clock, Effects, command transactions, CAS and operation journal are reused. No migration or second engine is introduced. No CharacterSheets writes, HTTP view scheduler, narrative-post hook or upkeep implementation is added.

The normative sources are ACS Master Specification S1.4–S1.16/A1.4–A1.6, Technical Architecture, Implementation Roadmap and Integration Contracts in `docs/design/acs/` and `docs/contracts/`, together with the ACS-2.3.1–2.3.3 and ACS-5.1.1–5.1.2 implementation reports.

## Changed files and actual integrations

Paths below the addon refer to `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`.

- `modules/Domain/Clock.php`: pure boundary evaluation through existing Damage and Effects; stable applied-order/ID sorting; individual source/target turn anchors and round roster selection.
- `modules/Domain/Effects.php`: validate and retain pinned literal periodic components, deduplicate tick/countdown markers, decrement/remove instances, expose the explicit ordinary-action control restriction. Existing damage/healing state transitions are reused.
- `modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php`: capture referenced tick definitions with source JSON, exact effect index, version and fingerprint during KB normalization. Unknown or unsupported periodic schemas fail closed.
- `modules/Infrastructure/StatusPersistence.php`: verify the retained graph, load combat HP/defenses, evaluate one boundary and CAS-write HP, HP resource and status instances in the existing transaction. Tick receipts use `effect:<id>:tick:<boundary>` in the existing journal.
- `modules/Infrastructure/ClockPersistence.php`: run mandatory start ticks before recording EntityTurn start/cooldown completion; run end countdown before recording EntityTurn end; process round start with the existing AP boundary.
- `modules/Infrastructure/RoundManagementPersistence.php`: pass/GM skip/forfeit use the same start/end pipeline; round end precedes next-round initialization. Verified supported statuses replace the former blanket active-effects blocker.
- `modules/Application/ClockService.php`, `QueryService.php`: ordinary actions require the actual completed start receipt, open turn, active entity and permitted control state. Read-only availability does not advance time. Missing mandatory starts of other group members cannot be silently fabricated at publication.
- `modules/Domain/Resolver.php`, `Application/SealService.php`, `Infrastructure/CombatCommitWriter.php`, `CombatCommitPersistence.php`: preview virtually evaluates group end boundaries; seal pins the resulting end trace hash; commit persists that same final status projection and end receipts atomically. Commit does not execute a second countdown.
- `tests/acs_status_ticks.php`, `tests/acs_status_ticks_sql.php`: focused domain and real-SQL fixtures. `tests/acs_round_management_regression.php` now relies on actual migrations for drafts instead of parsing obsolete documentation DDL, and removes its own pending draft rather than dropping the table.

## Timing and invariants

Entity boundaries retain `entity_turn:<id>:start/end`; round start retains `round:<id>:ap_refresh`, round end retains `round:<id>:next`. Each parent receipt, child tick receipt, HP update and status change commits or rolls back together. Replays use the existing stored operation result. Existing AP and cooldown boundaries are not repeated.

DoT/HoT run at the corresponding start, including the last tick, before ordinary actions. Application itself produces no periodic damage. A periodic definition must explicitly declare a supported phase and decrement. Turn countdown honors its declared start/end event; round countdown occurs at round end. End countdown of a periodic instance requires its matching start tick, so a status applied after that start does not lose an unexecuted tick. A periodic control must decrement at turn end. Nonperiodic one-turn control remains active throughout the target turn and expires at end. Different sources remain separate.

Damage and healing use the existing Effects HP rules immediately: zero HP becomes incapacitated, never automatically dead; healing preserves dead/recovery-blocked restrictions. Healing does not reopen a closed EntityTurn. Pause rejects advancement. Pass and forfeit still execute mandatory individual boundaries.

Tick execution uses retained definitions and combat snapshots, with no live KB lookup, client roll, new RNG or persistent CharacterSheets mutation. Trace and pinned graph stay server-side; ordinary public availability exposes readiness only.

## Verification

Executed focused checks:

- `php -l` on every changed/new PHP file.
- `php tests/acs_status_ticks.php`: poison duration 3, no immediate/fourth tick, last tick before removal, duplicate boundary, HoT, recovery block, incapacitated signals, independent one-turn controls, Resolver control gate and end projection, own-turn/round anchors, paused boundary and corrupt pinned definition.
- `sh work/runtime/php tests/acs_status_ticks_sql.php /tmp/acs222-socket/status521.sock`: real owner KB pinned graph; HP/resource/status writes and receipts; injected failure after writes rolls all changes back; duplicate start; pause/resume; pass/forfeit; closed-turn healing; next-round AP; three poison ticks; read-only views and no live KB/CharacterSheets mutation. Its existing entity/encounter/roster fixture dependencies also pass.
- Focused existing `acs_combat_commit_regression.php` and `acs_round_management_regression.php` pass, including publication/outbox rollback and existing round/AP/CAS behavior.

Local execution used PHP 8.4.24 and MariaDB 11.8.6 in an isolated disposable database. PHP 8.5.12/MyBB 1.8.41 installed-environment verification remains manual. No full suite or browser E2E was run.

## Supported subset and limitations

Periodic references currently support exact pinned `arpg_ability` damage/heal components with flat numeric values, one tick per matching boundary, HP healing and physical/elemental/true damage. Critical ticks, formulas, arbitrary callbacks, non-HP periodic resources, unknown decrement schemes and unresolved definitions are rejected rather than substituted. Combat layers not supported by the existing retained projection remain blocked; persisted barriers are not silently ignored. The existing persistence model has no general recovery-blocking state producer; its supported projected entities retain the existing recovery rules.

Control uses the explicit validated `mechanical_parameters.blocks_ordinary_actions` flag and target-turn duration; richer control interpretations are not invented. Duration units and source identities remain as pinned by ACS-5.1.2. No upkeep, reactions, cleanse reversal, persistent cross-quest transfer, automatic session termination or ACS-5.2.2 work is included.

## Manual verification

1. On an installed test forum, apply migrations through 010. Create an active encounter with at least two distinct entities; record current HP and effect rows.
2. Through supported owner KB definitions, apply a target-turn poison with three matching start ticks and explicit decrement, a one-turn target control, and a source-turn HoT. Confirm application/preview/seal alone does not change HP or duration.
3. Start the affected individual EntityTurn. Confirm one tick, HP/resource agreement, immediate incapacitated state if HP reaches zero, and start/tick journal receipts. Repeat the same operation key and confirm no additional change.
4. Pass or GM-skip the turn. Confirm control expires only at end. Advance rounds and confirm exactly three poison ticks including the last, followed by removal. Repeat next-round operation and confirm AP/cooldown are unchanged.
5. Pause before a pending start; confirm rejected advancement and unchanged rows. Resume and execute that start once. Forfeit another group and confirm individual boundaries still run.
6. Heal a closed-turn entity and confirm its turn remains closed. Open/reload editor and ordinary narrative posts and confirm no tick or duration change. Check public DTOs do not disclose server-only traces or pinned hidden definitions.
