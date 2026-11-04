# ACS-5.3.1 — Reaction registry, phases and affordability

## Baseline and schema evidence

Base `main`: `389a34aafb7e33be52da8c0f90bc3eb6f44ef822` (merged ACS-5.2.2). The specification, architecture, implementation roadmap, Integration/KB Mechanics Contracts and ACS-4.2.3, ACS-4.3.2-R2 and ACS-4.3.3-R2 reports were reviewed at their current documentation paths. Applicable baseline includes A1.17–A1.24, C1.3/C1.4 and S1.1–S1.16.

Before adding DDL, an isolated database was migrated with the unchanged baseline registry: ledger `010_status_instances`, no `af_acs_reactions` table, and the existing integer `entity_turns.reaction_used` column. Architecture's reactions DDL was only proposed. Migration **011_reaction_registry** therefore adds that table, retaining the proposed fields and phase-queue index, plus a unique encounter registration-order index. Migrations 001–010 are unchanged. The focused SQL fixture verifies actual schema through the existing migrator and exercises 011 resume/repeat without changing old ledger records. This is local schema evidence, not an inspection of the production forum database.

No second Effects/Clock engine, reaction counter, transaction system or MyBB publication protocol is introduced. `ReactionService` supplies controller registration authorization, which cannot be delegated to an ordinary-turn Seal endpoint because it must work after resolved. `ReactionPersistence` isolates the new registry's SQL projection and immutable causal-check records; execution remains in Resolver and Effects.

## Definition and registration contract

A controller selects an existing typed KB `trigger_template` by key. Its `event` must be exactly `on_targeted`, `before_hit`, `after_hit` or `after_damage`; `priority` is a signed integer. Its effect_ref must select a supported component of an **exact direct owner-granted, pinned arpg_ability**. Cached dependency definitions alone are not grants. The adapter acquires and authorizes the trigger, optional condition_template and referenced ability, then retains their canonical JSON, versions, fingerprints and exact effect index. Runtime verifies the retained graph and never fetches the latest KB definition to execute it.

The bounded component subset is self-targeted literal HP heal or single-instance cleanse/dispel, including existing categories, partial stacks and operation probability. Effects performs the actual heal/removal. Parent ability costs are explicit integer resource `timing=use` rows; several costs are paid atomically with the existing HP/resource cost routine. No default AP cost is added, and AP cost rows are rejected for this separate reaction budget. Positive explicit cooldown/charge/duration/action-cost rules, unhandled overrides, item/sustain/fractional costs, critical healing, formulas, redirects, barriers, multi-target components and unknown schemas are rejected rather than ignored or guessed. No new KB mechanics or automatic cost is created.

Supported typed conditions retain the KB all/any/not/comparison grammar. The closed input vocabulary is actor.hp, actor.max_hp, actor.life_state, actor.kind, event.phase, event.hit_success and event.hp_loss. Hit facts are available only after_hit/after_damage; HP loss only after_damage. A condition requesting a future phase's fact is rejected, rather than receiving an invented value. Unknown paths, including masks/display identities, are rejected. Source/event values are server projections. Limits support the baseline one-per-round rule (empty limits or explicit per_round=1); other KB limits are unsupported.

Registration checks native session access, current controller, owner constraints, explicit combat grant, source active/engaged state, current round roster, exact owner ability grant, definition support and session/encounter CAS. It consumes no resource or reaction limit. Several candidates may be configured, but **execution is limited to one per creature/round** by EntityTurn.reaction_used. Ordinary turn status resolved does not revoke the reaction right. The existing round roster creates fresh EntityTurns with reaction_used=0 for the next round; the current resolved turn is never reopened or reset. Registration and cancellation use existing command keys, session lock and event journal; exact request replay returns the original receipt. Duplicate active registration of the same compiled definition is rejected. Cancellation keeps historical rows.

## Execution and fixed checks

For each ordinary action, Resolver visits the four phases in baseline order. after_hit is before damage application. Within a phase: ascending KB priority, registered_order, then stable numeric ID. This follows the existing ascending priority convention used by Effects. Only the actual targeted creature's self reaction candidates are eligible in this task; redirect/protection of other targets belongs to ACS-5.3.2.

Immediately before each candidate, Resolver rechecks current controller/participation availability, source life state, current EntityTurn slot, phase facts, condition and affordability. An unavailable candidate is recorded as skipped without spending anything or consuming the slot; the next candidate is checked. Each executed reaction adopts one atomic Effects result and consumes the slot even when healing has zero effective recovery or a probability check fails. Child components call Effects directly and never re-enter the active reaction queue. Existing HP transitions and sustained interruption remain in that same pipeline.

The original hit/crit/status tape is preserved. Self healing/cleanse never gains an implicit crit roll (C1.3/C1.4). Probabilistic cleanse/dispel uses the existing rolls table with sealed_action_id=NULL, check_kind=reaction, roll_index=0 and key:

`reaction:<reaction_id>:sealed-action:<sealed_action_id>:<phase>`

The existing server RandomSource creates these prepared causal checks only during an explicit authorized seal command. Normal seal reserves checks for current active eligible registry entries targeting its immutable action target. If a reaction is registered after that attack was sealed, explicit `seal_prepare_reactions` reserves only missing keys, preserving all previous attack and reaction values. It uses the existing seal transaction/key/CAS/receipt path and does not spend combat resources or reaction slots. Repeated requests, including a new preparation key when all checks already exist, do not redraw existing values.

seal_status, ordinary preview, writer and recovery only read these records. Missing affordable probabilistic checks block the calculation until that explicit preparation event; no implicit RNG is run from a view or recovery. An unaffordable candidate is skipped before requiring its check, so missing resources cannot block a foreign attack. Preview leaves uncertain probabilistic reactions pending without real draws or writes.

## Publication, visibility and persistence

Query/Builder add server-only registry/EntityTurn projections and dependency hashes. Seal and CombatCommitWriter compute the same opaque reaction evidence hash, including causal samples, effects, resource results and slot updates. Registry/permission/limit/resource changes invalidate stale confirmation and require recalculation on retained attack rolls. Existing sealed drafts may require reconfirmation after rollout because the new registry dependency is included; no quota or tape is reset.

The writer's existing facts fence includes registry rows, definition snapshots and causal checks. Under its current lock/transaction it CAS-writes HP/resources, reaction row version/last causal execution and the existing EntityTurn.reaction_used/version, together with the original combat result, event, receipt and outbox. No independent reaction post or native insert occurs. A late persistence failure rolls all those changes back; COMMITTED replay uses the existing receipt. PublishService and RecoveryService continue through that same writer and are not given RNG or a new publication path.

Own-controller reaction views expose only that source's registration IDs, phases/order, status/version, costs, enabled flag and current used slot. Hidden reaction definitions, predicates, fingerprints, samples and server traces are not added to public DTOs. Other controllers cannot enumerate them, including GM users who do not control that source. Numerical preview/seal remains within the existing supported GM target-visibility scope; this task does not broaden player visibility. Ordinary narrative posts/page reloads do not execute reactions or change the registry.

## Changed files

Addon paths below are relative to `inc/plugins/advancedfunctionality/addons/advancedcombatsystem/`.

- `migrations/011_reaction_registry.php`; `modules/Administration/Migrations.php`; `modules/Infrastructure/Repository.php`: additive table and explicit registry/whitelist.
- `modules/Application/ReactionService.php`; `modules/Infrastructure/ReactionPersistence.php`: authorized register/cancel/read, pinned graph projection, existing causal rolls and slot provenance.
- `modules/Infrastructure/Adapters/KnowledgeBaseAdapter.php`: owner-authorized trigger/condition/effect graph acquisition.
- `modules/Domain/Effects.php`: closed reaction/condition contract and atomic existing Effects attempt; `modules/Domain/Resolver.php`: deterministic phase queue, skip/slot logic and reaction evidence hash.
- `modules/Application/QueryService.php`, `BuilderService.php`, `SealService.php`: exact direct grants, server registry/slot dependencies, fixed causal checks and confirmation.
- `modules/Infrastructure/CombatCommitPersistence.php`, `CombatCommitWriter.php`: resource/slot/registry CAS, facts fence and existing publication/recovery integration.
- `modules/Infrastructure/EncounterRepository.php`, `modules/Application/EncounterService.php`: expose the existing repository/service stack to registration.
- `modules/Infrastructure/RandomSource.php`: one shared server draw implementation for action samples and independent reaction samples.
- `advancedcombatsystem.php`, `modules/Presentation/ApiController.php`: minimal existing-router endpoints, no execution or reaction-post endpoint.
- `tests/acs_reaction_registry.php`, `tests/acs_reaction_registry_sql.php`: focused domain and real SQL fixtures using the existing disposable harness. The existing `tests/acs_status_instances_sql.php` latest-schema assertion now uses Migrations::VERSION so its 010 resume check remains valid after adding 011.

## Checks

Local PHP **8.4.24**, isolated MariaDB **11.8.6**. Installed MyBB 1.8.41/PHP 8.5.12 verification remains manual. No full suite or browser E2E.

- `php -l` on all changed/new PHP files; `git diff --check`.
- `php tests/acs_reaction_registry.php`: phase/priority/FIFO/ID ordering, multi-resource atomic shortfall and next candidate, separate AP/slot, already used limit, resolved turn, inactive source, hit gating, conditions and future-fact refusal, corrupt pin, failed probability still paid, fixed causal tape/replay and unaffordable reaction without a check. Includes existing focused status/tick/upkeep domain dependencies.
- `sh work/runtime/php tests/acs_reaction_registry_sql.php /tmp/acs222-socket/reactions531.sock`: actual migration resume/readback, owner graph/direct grant, player/controller registration after resolved, unauthorized/duplicate refusal, safe read-only DTO, late separate causal-check preparation/replay, unchanged original tape, retained KB pins after live definition change with no runtime KB reads, insufficient resource skip, slot+EP+row-version writes, injected outbox rollback and committed replay.
- Existing focused `acs_status_instances_sql.php`, `acs_combat_commit_regression.php`, `acs_sustained_upkeep_sql.php`, `acs_round_management_regression.php` and `acs_recovery_service_regression.php` pass, including their entity/encounter/roster/preview/builder/seal dependencies. Recovery checks retain the existing known/unknown PID, quarantine, CAS and immutable-RNG behavior.

## API and manual verification

Existing endpoint: `xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem`. Commands require POST, my_post_key, operation_key and relevant CAS versions. No client samples, phase, priority or arbitrary effect operations are accepted.

- `reaction_register`: session_id, encounter_id, entity_id, trigger_key, expected_version, encounter_version, reason. Returns reaction_id/version and registration metadata, not the pinned graph.
- `reaction_cancel`: same scope/CAS plus reaction_id/reaction_version; preserves history.
- `reaction_view`: authenticated own-source read with session_id/encounter_id/entity_id; no writes/RNG.
- `seal_prepare_reactions`: existing seal command payload draft_id, expected_draft_version, operation_key and CSRF. This is explicit preparation of missing causal checks, not execution. Reconfirm the resulting summary before publication.

On staging:

1. Apply migration 011 through the normal migrator; confirm old ledger/checksums remain and EntityTurn has the same reaction_used column. Configure an owner-granted self ability with supported use costs and a typed trigger_template pointing at its effect index.
2. Register it as the actual controller. Repeat the exact key, try a foreign controller and a stale CAS. Confirm one registration, no resource/limit spend, no exposure through another user's view. Repeat while the ordinary turn is resolved.
3. Register an earlier candidate whose required resource is insufficient and a later affordable candidate. Seal an incoming action. If registered after seal and probabilistic, inspect without writes, explicitly prepare its missing checks, then confirm the fresh summary. Verify the original action tape remains unchanged.
4. Publish through the existing supported test binding. Verify phase order, one successful execution/payment, skipped candidates without costs/limit, one reaction_used increment, stable last causal execution and audit trace, and no extra MyBB post.
5. Replay publication/recovery and reload pages: no second payment/limit increment/RNG. In a disposable environment inject a late writer failure and verify full rollback before retrying the same submission.

No full intercept, mass defense, critical reactive healing, general condition/formula interpreter, complex passive/active chains, player visibility redesign or ACS-5.3.2 work is included. The API is minimal; a complete reaction configuration UI is outside this PR. `docs/ROADMAP.md` is unchanged, and old documentation paths are not created.
