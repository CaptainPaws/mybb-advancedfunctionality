# AGENTS.md — AdvancedFunctionality

## Project

- Repository: `CaptainPaws/mybb-advancedfunctionality`
- Platform: MyBB 1.8.40
- Production runtime: PHP 8.5.x
- Development runtime may differ from production.
- The repository contains a modular plugin system with independently developed addons.
- Primary addon directory: `inc/plugins/advancedfunctionality/addons/`.

## 1. Scope and architecture

- Work within the addon or subsystem specified by the task.
- Do not modify unrelated addons, templates, stylesheets or dependencies without a demonstrated need.
- Preserve existing integration contracts and backward compatibility.
- Prefer addon-level extensions and existing hooks over modifications to MyBB core files.
- Follow established project conventions rather than introducing parallel implementations.
- Do not duplicate existing functionality when a suitable shared component or integration already exists.
- Do not perform unrelated refactoring or architectural redesign.

## 2. Documentation and context

- The task specification defines the immediate scope and acceptance criteria.
- Use the approved specifications, technical documentation and implementation reports relevant to the task.
- Read explicitly referenced documents first.
- Inspect the current implementation of affected files and direct dependencies before editing.
- Do not scan or read the entire repository by default.
- Expand investigation only when a concrete dependency, conflict or missing interface requires it.
- Reuse documented findings rather than repeating completed audits.
- Do not treat historical reports as more authoritative than subsequently approved decisions or current source code.
- If documentation and implementation disagree, report the discrepancy and resolve it only within the authorized task scope.

## 3. Implementation

- Make minimal, targeted and maintainable changes.
- Preserve public APIs, hooks, database contracts, event names and existing integrations unless the task explicitly authorizes changing them.
- Maintain compatibility with the declared MyBB and PHP targets.
- Follow existing PHP, JavaScript, CSS and SQL conventions.
- Reuse existing styling systems and shared UI components where applicable.
- Keep unrelated formatting and structural changes out of the patch.
- Do not introduce new dependencies without a clear requirement.
- Respect existing user changes and avoid destructive Git operations.

## 4. Testing and verification

Functional testing and manual acceptance are performed by the project owner.

Do not run:
- Automated or regression test suites.
- Integration or database tests.
- UI or browser tests.
- Test fixtures or test runners.
- Repository-wide linting or full-project checks.
- Unrequested performance benchmarks.

Do not create or modify tests unless explicitly requested.

Allowed lightweight integrity checks:
- `git diff --check`
- `git status --short`
- `git diff --stat`
- PHP syntax checks (`php -l`) limited to modified PHP files.
- Minimal non-executing integrity checks directly required by the change.

Do not interpret the absence of automated testing as successful functional verification.

## 5. Database and data safety

- Preserve existing data and schema compatibility.
- Do not perform destructive migrations or database resets.
- Do not modify live data.
- Do not introduce schema migrations unless explicitly required.
- Clearly document new migrations, dependencies and manual installation requirements.
- Never claim a migration or integration was verified on a live forum without actual evidence.

## 6. Environment

- Use the existing repository checkout.
- Do not create Git worktrees unless requested.
- Do not reinstall an already functioning runtime.
- Do not run tests during environment preparation.
- Do not attempt repeated infrastructure repairs when the cloud environment is unavailable.
- Report infrastructure failures separately from source-code failures.
- Do not modify project code to compensate for a temporary cloud environment problem.

## 7. Documentation and reporting

- Update documentation only when needed to reflect the requested implementation or an explicitly required contract change.
- Do not rewrite historical reports.
- Do not modify `docs/ROADMAP.md` unless explicitly authorized.
- Do not create redundant reports or duplicate existing documentation.
- Preserve established documentation structure and naming conventions.

At completion, report concisely:
1. Implemented changes.
2. Modified files.
3. Relevant integration or compatibility considerations.
4. Integrity checks performed.
5. Items requiring manual testing.
6. Remaining limitations or blockers.

Do not claim production readiness, installed integration readiness or successful functional testing without supporting evidence.

## 8. Efficiency

- Minimize unnecessary file reads, tool calls and repeated analysis.
- Do not perform repository-wide audits unless explicitly requested.
- Do not repeatedly reread unchanged documents.
- Avoid excessive command output.
- Do not add speculative improvements outside the task.
- Do not replace implementation with extensive planning when requirements are already clear.
- Prioritize correct implementation and essential integrity checks over exhaustive investigation.

## 9. Task-specific instructions

- Individual task specifications may define additional scope, documentation, compatibility and acceptance requirements.
- Approved subsystem specifications remain authoritative for the behavior they define.
- If a task requires actions outside these standing rules, obtain explicit authorization unless already clearly granted in the task.
