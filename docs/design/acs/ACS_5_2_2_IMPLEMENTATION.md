# ACS-5.2.2 — Sustained effects and atomic upkeep

## Baseline

Base `main`: `78780513f82dc6d4a201086d20902f2ff404bc4c`, including merged ACS-5.2.1. Normative sources: ACS Master Specification S1.13–S1.16/A1.4–A1.6, Technical Architecture, Implementation Roadmap, Integration Contracts, KB Mechanics Contract and ACS-5.2.1 implementation report, at the current `docs/design/acs/` and `docs/contracts/` paths.

Reuses migration 010, `af_acs_effects`, existing combat resources, shared turn AP, Clock, Effects, SessionRepository command transactions, CAS and event/operation journals. No new schema, engine, resource ledger, Inventory/CharacterSheets writer or automatic post/view scheduler is introduced.

## Supported KB binding

Explicit ability `costs[]` rows with `kind=resource`, `timing=sustain`, period value 1 and unit `own_turn` are retained in the single status component's immutable instance specification. Several simultaneous costs remain separate ordered rows. `hp` uses existing HP cost rules; `ap` uses the existing shared turn budget only when explicitly present in these costs; other resource keys refer to existing integer combat balances. The ability's ordinary `action_cost` remains its separate use cost and never becomes an upkeep fallback.

An ability with several status components and an unqualified ability-level sustain cost is rejected as ambiguous. Use/item/fractional costs, unsupported periods and unknown cost fields are rejected; their meaning is not guessed. Missing combat resources make that effect's entire payment fail without debiting its other resources. No new initial balance is fabricated: current registration still only produces balances guaranteed by its owner contract (normally HP); the SQL fixture supplies EP in the existing resource table explicitly to test multi-resource payment.

Explicit status `mechanical_parameters` used by this bounded projection are `autonomous` (boolean), `interrupt_on_status_keys` (list of exact status keys on the source) and `upkeep_hp_allow_zero` (boolean). Absence means ordinary source-state interruption, no additional control interruption and HP must remain at least 1. An explicit zero-HP exception reuses the existing Effects sacrifice rule; it does not convert cost to damage. These are pinned parameters of the existing KB status definition, not new costs or inferred rules from names. Shared-source identity for sustained is rejected rather than assigning an arbitrary payer. Existing source-separated/independent instances remain independent.

## Execution and persistence

`Clock::advance()` completes mandatory ticks and their immediate state transitions before ordering active sustained of the starting source by stored priority, then FIFO applied order and stable ID. `Effects` reuses one cost implementation for ordinary actions and upkeep. A payment runs on a detached copy: success adopts every cost; expected resource shortfall discards the whole attempt, terminates that instance and continues to the next one. Earlier successful payments remain spent. The result, costs and exact `entity_turn:<id>:start` identity are retained as `last_upkeep`; duplicate execution does not charge twice.

Effects stops ordinary sustained immediately after a mechanical operation makes its source incapacitated/dead, before a later heal can reactivate the entity. Explicit autonomous exceptions and exact KB control interruptions are respected. Terminated instances remain historical removed rows with zero stacks, a reason, causal label and incremented version; healing never recreates them. Confirmed combat publication persists the same interruption through its existing status projection. Seal binds sustained state with an opaque hash and never performs upkeep.

Clock persistence loads verified source snapshots and existing integer resources, CAS-writes changed entity/resource state, shared AP and effect versions inside the existing boundary transaction, and records the complete server trace in the parent operation/event. Pass/forfeit use this same start pipeline. Round AP refresh remains before individual starts. Closing a skipped turn refreshes its CAS row after mandatory upkeep may have spent AP. A persistence error rolls back the entire boundary or combat commit, including receipts and terminations.

Priority is a separate CSRF-protected `clock_upkeep_order` command. It validates source control (or existing GM management authority), active encounter/current EntityTurn, session/encounter CAS and an exact permutation of the source's eligible active instances. Priorities and effect versions are persisted in advance; replay returns the original result. Before an unprocessed start they apply to that start; after a processed start they apply only to the next source start. The UI labels this distinction, including while the current turn is already closed. An arbitrary array cannot replace this order during seal/commit. New unranked effects follow previously ranked effects in FIFO order. Completed payment receipts are never recalculated when the future order changes.

## Changed files and real integrations

Addon paths are relative to `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`.

- `modules/Domain/Effects.php`: pinned upkeep contract, shared atomic cost routine, per-effect payment result and immediate interruption.
- `modules/Domain/Clock.php`: source-only upkeep after ticks, stored priority/FIFO, mandatory-start guard includes sustained.
- `modules/Domain/Resolver.php`: preserves the pre-interruption dependency state and server trace so commit can persist an initial interruption as well as action-caused ones. No upkeep call is added to Resolver.
- `modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php`: unambiguous binding from retained typed costs to the existing status spec.
- `modules/Infrastructure/StatusPersistence.php`: exact integer resource projection, HP/resource/AP/status CAS writes, retained-graph verification and priority persistence.
- `modules/Infrastructure/RoundManagementPersistence.php`: reuse the updated turn CAS after start payments when closing a skipped/forfeited turn.
- `modules/Application/QueryService.php`, `Infrastructure/CombatCommitPersistence.php`: retain existing non-HP resource balances and dependency hashes through preview/seal/commit; ordinary publication preserves these balances.
- `modules/Application/SealService.php`, `Infrastructure/CombatCommitWriter.php`: identical sustained-state confirmation hashes.
- `modules/Application/ClockService.php`: authorized read-only sustained DTO and persisted priority command, reusing the existing transaction and journal.
- `advancedcombatsystem.php`, `modules/Presentation/Encounters.php`, `Clock.php`, `BuilderController.php`, `assets/builder.js`: existing ACS routes and panels show source, current order, costs, last payment and termination reason, with a small priority form. Builder loads these data explicitly; there is no polling or timer mutation on GET. Public DTOs omit target identities, hidden definitions, fingerprints and server traces; order responses contain IDs/versions only.
- `tests/acs_sustained_upkeep.php`, `acs_sustained_upkeep_sql.php`, `acs_sustained_commit_sql.php`: focused domain, boundary SQL and confirmed combat publication fixtures using the existing disposable fixture harness.

## Verification

Executed with PHP 8.4.24 and isolated MariaDB 11.8.6; installed MyBB 1.8.41/PHP 8.5.12 verification remains manual.

- `php -l` on every changed/new PHP file; `node --check` on changed builder JavaScript; `git diff --check`.
- `php tests/acs_sustained_upkeep.php`: successful HP/EP/AP, two-resource all-or-nothing failure, independent later payments/no refunds, FIFO/custom priority, strict HP and explicit exception, incapacitated/no revival, autonomous/control exceptions, ticks before upkeep, duplicate and wrong-source boundary.
- `sh work/runtime/php tests/acs_sustained_upkeep_sql.php /tmp/acs222-socket/upkeep522.sock`: real owner KB cost/pin normalization and Query projection; player/controller order without GM privileges; denied foreign controller/read filtering; CAS/replay/stale/invalid order; successful HP/EP/AP and independent shortfalls; late status/read-only/pause; skip/next round/0 HP; injected parent-receipt failure after resource writes restores all tables.
- `sh work/runtime/php tests/acs_sustained_commit_sql.php /tmp/acs222-socket/upkeep522.sock`: retained EP through actual sealed publication, no upkeep at seal, sustained state binding, immediate interruption from damage, injected outbox failure rolls back termination/resources, terminal replay.
- Focused existing `acs_status_ticks_sql.php`, `acs_combat_commit_regression.php` and `acs_round_management_regression.php` pass, including their entity/encounter/roster/preview/builder/seal dependencies. This is not a full test-suite run; no browser E2E was run.

## Limits and manual check

No general condition evaluator, fractional resource policy, arbitrary status graph/callback execution, item upkeep, cross-source shared payer, new resource initialization UI, upkeep refunds, new reactions or ACS-5.3.1 work is included. Existing unsupported persisted combat layers/barriers remain blocked. This change consumes existing combat balances only and never modifies permanent owner data.

1. On staging, apply existing migrations through 010. Create an active encounter with supported pinned status abilities and explicit sustain costs; use only resources already supplied by a verified combat-state producer.
2. Before a source's first unprocessed EntityTurn start, open the ACS sustained panel. Confirm names/keys, costs, FIFO order and no resource change on reload. Save a different order as the controlling player; attempt a stale CAS and a foreign source and confirm rejection without mutation.
3. Process that individual start once. Confirm ticks precede upkeep; one insufficient resource prevents all costs of that effect; later effects still pay; AP is spent only if explicitly listed. Inspect HP/resource agreement, effect versions, last payment, boundary result and event trace.
4. Replay the same boundary and reload the page: balances and versions must stay unchanged. Pause before another pending start: advancing is blocked. Resume and pass/forfeit the turn: its required upkeep still executes once.
5. Cause source HP to reach zero through a supported confirmed action. Confirm ordinary sustained terminates in that same commit, and later healing restores neither its stacks nor active state. Verify an explicit autonomous/control exception separately.
6. Preview and seal a normal action while sustained exists; confirm no upkeep or live-state mutation. Publish through the supported test binding and verify resource preservation/termination; test an injected persistence failure only in a disposable environment and verify rollback.

`docs/ROADMAP.md` is unchanged. No old documentation paths are created.
