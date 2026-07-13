# Client Profile Outstanding UI/UX Design

**Date:** 13 July 2026
**Status:** Approved for autonomous execution by the Client Profile task brief
**Surface:** `GET /operations/clients/{client}` at desktop `1440x900`

## Outcome

Close every genuinely outstanding production-readiness and desktop UI/UX gap in `docs/client-profile-web-completion-goal.md` without reopening the completed Client/HR release, duplicating a canonical domain, changing HR-owned workflows, or inventing a mobile experience.

“Genuinely outstanding” is evidence-based. A matrix row may be closed by proving that current canonical code already supplies the required behavior, or by adding the smallest missing composition, authorization, integrity, lifecycle, or UI-state behavior. A stale `Partial` or `Not inspected` label alone is not evidence that new product code is required.

## Constraints and ownership

- Client Profile is a composition boundary over canonical Client, Care Plan, eMAR, Finance, Incidents, Documents, HR, Fleet, Privacy, Portal, and related domains.
- No profile-specific copies of canonical schemas, models, controllers, services, status vocabularies, or records will be introduced.
- HR projections are read-only. This task will not edit HR pages, HR workflows, or HR ledgers.
- Existing global workspaces remain available where cross-client work belongs. Client Profile supplies client-scoped detail and safe actions only where canonical endpoints support them.
- The acceptance target is desktop web at `1440x900`. Existing responsive behavior may be preserved, but no mobile redesign or mobile acceptance program is included.
- `docs/client-hr-live-gap-closeout.md` remains historical release evidence and will not be relabelled or reopened.

## Selected approach

### Evidence-led row closure

For every matrix row:

1. Identify canonical ownership, route/controller/service/policy, profile prop, and UI consumer.
2. Classify each lifecycle action as supported, intentionally read-only/non-applicable, or genuinely missing.
3. Verify parent client, organization, and nested-record binding at the direct write boundary.
4. Verify restricted props are omitted rather than merely hidden in the browser.
5. Verify loading, empty, error, and success feedback where the profile performs an asynchronous or modal action.
6. Add a failing regression only for behavior that is absent or unsafe; then implement the minimum canonical composition fix.
7. Record exact evidence in the matrix. Mark `Verified` only when the row has server/frontend/browser evidence proportional to its behavior.

This is preferred over a tab-by-tab rewrite because the repository already contains mature canonical controllers and substantial profile composition. A rewrite would increase duplicate ownership and regression risk.

### Alternatives rejected

- **Treat every Partial row as a rebuild.** Rejected because Partial often records missing proof rather than missing behavior, and would duplicate established domains.
- **Create a generic profile CRUD API.** Rejected because it would bypass domain policies, transaction services, and lifecycle rules.
- **Close rows from source inspection alone.** Rejected because production-readiness claims require executable and browser evidence.

## Architecture

### Server boundary

`ClientController` remains the Inertia composition controller. `ClientProfileSectionAccess` remains the section-level omission gate. Mutations stay in their canonical controllers and policies. When a profile action needs metadata, the profile receives an exact capability or canonical URL; it does not infer authority from broad edit access.

Nested operations must establish this chain before side effects:

`authenticated actor → exact capability/policy → profile client access → organization match → nested record belongs to profile client → canonical service transaction`

Finance operations additionally retain row locks, idempotency, balanced posting, and reconciliation invariants. Regulated consent/privacy actions retain terminal-state and audit constraints.

### UI boundary

`show.tsx` remains the profile shell and tab registry. Existing focused tab components and `ProfileDialogs` remain the integration points. Complex writes use the established wizard/dialog pattern; direct file downloads and cross-client governance work may remain links.

Actions are rendered from exact server capabilities. A missing capability omits the action and prevents restored `?dialog=` state from opening it. Modal actions provide:

- a visible in-progress state that prevents duplicate submission;
- plain-language validation or server-error feedback;
- a clear empty state when no records exist or access is restricted;
- a success state through the existing Inertia/toast patterns;
- keyboard-visible focus and a predictable return target when the dialog closes.

### Ledger boundary

The completion matrix is normalized to exactly 29 columns before status work. Evidence fields distinguish server tests, frontend tests, and desktop browser proof. Existing release checkpoints remain append-only history. A final checkpoint records new commits, exact commands/counts, browser URLs/scenarios, and any honest remaining external boundary.

## Priority design

### P0 authorization and integrity

Audit and, where needed, harden:

- Consents and Consent Requests: exact granular permissions, parent/client/org binding, terminal-state concurrency, auditable transitions, and client-scoped in-profile actions.
- Privacy: capability-gated payload, client-bound DSR detail/actions, no leakage through partial Inertia requests.
- Appointments: create/manage split, parent/client/org binding, and canonical timeline emission only once.
- Finance: prop omission, exact capabilities, nested client/org validation, transaction locks, idempotency, balanced entries, and reconciliation safety.
- Medical, Incidents, Documents, Family Portal, Risk, and First Aid: sensitive payload omission plus direct-route and nested-record binding.

### P1 daily work

- Personal Details: canonical Add Client edit hydration and full round trip.
- Onboarding: exact Client capabilities with read-only canonical HR preparation projection.
- Daily Notes: author-private draft resume, detail, edit, submit, and error recovery in profile.
- Communication: correction/review/visibility lifecycle only where supported by `ClientNote` policy/state.
- Timeline: in-profile source detail that routes to the owning tab/dialog without inventing source mutations.
- Care Plans and Goals: working-review version, exact per-record capabilities, fresh sign-off, detail/error states, and canonical lifecycle transitions.
- Family Notes: immutable family-authored body with canonical staff response/assignment/status actions.

### P2 inventory and parity

Inventory Location, routines, meals, assessments, health monitoring, MAR, transport, leave/excursions, respite, personal inventory, agreements, photos, audit, and remaining tabs. Existing supported behavior is documented and tested; only missing safe client-scoped parity is implemented. Unsupported actions are explicitly marked read-only/non-applicable instead of simulated.

## Playwright fixture collision

The reported duplicate `EMP0003` collision is treated as an independent test-harness defect only if reproduced. The likely risk is an employee-number uniqueness conflict when a seeder looks up an HR profile by `user_id` while an existing row owns `EMP0003`. The fix must be narrow, idempotent, and covered by a red/green seeder test; no broad seeder rewrite or HR workflow change is allowed.

## Verification

Each functional slice follows strict red/green TDD. Final verification includes:

- focused and aggregate Client Profile Laravel suites;
- focused Client Profile Vitest suites;
- the relevant desktop Playwright scenarios at `1440x900` using local data only;
- PHP syntax and scoped Pint;
- scoped Prettier and zero-warning ESLint;
- Wayfinder generation when routes/actions require it, with generated churn reviewed;
- TypeScript, client build, and SSR build;
- `git diff --check` and an independent diff/status review;
- cleanup of every browser, preview, PHP server, or helper process started by this task.

## Completion rule

The branch is complete only when every row is either:

- `Verified`, with exact server/frontend/browser evidence; or
- explicitly and accurately classified as intentionally read-only/non-applicable or blocked by an external boundary that cannot be resolved inside the authorized Client Profile scope.

No merge, push, deployment, production write, or change to another checkout is part of this design.
