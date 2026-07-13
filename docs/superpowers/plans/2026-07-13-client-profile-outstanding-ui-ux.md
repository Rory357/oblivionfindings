# Client Profile Outstanding UI/UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every evidenced Client Profile production-readiness and desktop UI/UX gap while preserving canonical domain ownership and recording exact proof in the persistent ledger.

**Architecture:** Keep `ClientController` and `ClientProfileSectionAccess` as the read/composition boundary, and retain every write in its canonical controller, policy, and transaction service. Close each ledger row through inventory and executable proof first; write minimal production code only after a focused regression fails for the intended reason.

**Tech Stack:** Laravel 12/PHP 8.4, Inertia React/TypeScript, Pest/PHPUnit, Vitest/Testing Library, Playwright Chromium, Vite/SSR, Wayfinder.

---

## File map

- `docs/client-profile-web-completion-goal.md`: normalized matrix, row evidence, red/green record, final checkpoint.
- `app/Http/Controllers/ClientController.php`: Client Profile Inertia composition and exact capability/prop emission.
- `app/Services/Clients/ClientProfileSectionAccess.php`: sensitive section omission decisions.
- `routes/operations.php`, `routes/clients.php`: exact route middleware and compatibility entries.
- Canonical controllers under `app/Http/Controllers/Operations`, `Clinical`, `HealthSafety`, and the existing client controllers: nested binding and mutation authorization only where a failing test proves a gap.
- `resources/js/pages/operations/clients/show.tsx`: desktop tab registry, exact action wiring, dialog restoration, and legacy inline sections pending focused extraction only when required.
- `resources/js/pages/operations/clients/tabs/*.tsx`: row-specific presentation and lifecycle states.
- `resources/js/pages/operations/clients/dialogs/*.tsx` and existing profile dialog components: modal lifecycle, loading/error/success/focus behavior.
- `tests/Feature/Operations/ClientProfileOutstandingAuthorizationTest.php`: new P0 adversarial matrix not already covered by focused existing suites.
- `tests/Feature/Operations/ClientProfileOutstandingLifecycleTest.php`: new P1/P2 lifecycle contracts not already covered.
- `resources/js/test/client-profile-outstanding-ui.test.tsx`: new exact UI-state and action tests.
- `tests/Feature/Seeders/PlaywrightGlobalSetupSeederTest.php`: narrow duplicate-employee fixture regression if reproduction confirms the collision.
- `tests/e2e/operations-client-profile-outstanding.spec.ts`: local desktop acceptance matrix and cleanup.

### Task 1: Normalize and inventory the persistent ledger

**Files:**

- Modify: `docs/client-profile-web-completion-goal.md`
- Read: `docs/client-hr-live-gap-closeout.md`
- Read: all profile controllers, services, policies, routes, tabs, dialogs, and focused tests named in the matrix

- [x] **Step 1: Normalize matrix columns without changing statuses**

Replace literal permission-cell pipes with words/commas and add missing cells so every matrix row has exactly 29 values.

- [ ] **Step 2: Verify the normalized matrix**

Run:

```powershell
$rows = Get-Content docs/client-profile-web-completion-goal.md | Select-Object -Skip 30 -First 38
$rows | ForEach-Object { if (($_.Split('|').Count - 2) -ne 29) { throw "Bad matrix row: $_" } }
```

Expected: exit 0 with no output.

- [ ] **Step 3: Inventory each row**

For each row, record exact canonical model/controller/service/policy/routes/component, supported lifecycle, intentional read-only actions, and existing tests. Do not promote status from source inspection.

- [ ] **Step 4: Append the inventory checkpoint and commit the design/plan slice**

Run:

```powershell
git add docs/client-profile-web-completion-goal.md docs/superpowers/specs/2026-07-13-client-profile-outstanding-ui-ux-design.md docs/superpowers/plans/2026-07-13-client-profile-outstanding-ui-ux.md
git commit -m "docs: plan Client Profile outstanding closeout"
```

Expected: one commit containing only the three owned documents.

### Task 2: Reproduce and repair the Playwright fixture collision

**Files:**

- Test: `tests/Feature/Seeders/PlaywrightGlobalSetupSeederTest.php`
- Modify only if the collision reproduces: `database/seeders/DuskDatabaseSeeder.php` or the single seeder proven to own the conflicting `EMP0003` write
- Verify: `tests/e2e/global-setup.ts`

- [ ] **Step 1: Reproduce the existing setup failure without editing seeders**

Run the current Client Profile Playwright scenario with reseeding enabled and capture the complete seeder exception. Query the local test database to identify the row and lookup key that conflict.

- [ ] **Step 2: Write the failing idempotency regression**

The test must create an HR profile that already owns `EMP0003` under the historical conflicting identity, run the exact seeder twice, and assert one canonical profile per fixture user plus unique employee numbers.

- [ ] **Step 3: Run RED**

Run:

```powershell
vendor\bin\pest.bat tests/Feature/Seeders/PlaywrightGlobalSetupSeederTest.php
```

Expected: fail at the reproduced unique `employee_number` collision, not at unrelated setup.

- [ ] **Step 4: Implement the narrow canonical lookup/update**

Resolve the fixture by both canonical fixture identity and unique employee number before assigning `EMP0003`; update only the deterministic fixture row. Do not rewrite unrelated seeding.

- [ ] **Step 5: Run GREEN and commit**

Run the focused test twice and the Playwright global setup once. Expected: both test runs pass; setup emits no duplicate employee exception.

### Task 3: Close P0 sensitive authorization and integrity gaps

**Files:**

- Create: `tests/Feature/Operations/ClientProfileOutstandingAuthorizationTest.php`
- Modify as proven: `app/Http/Controllers/ClientController.php`, `app/Services/Clients/ClientProfileSectionAccess.php`, canonical consent/request/privacy/calendar/finance/medical/incident/document/portal/risk/First Aid controllers and policies, `routes/operations.php`, `routes/clients.php`
- Regress: existing Foundation, SensitivePayload, DirectRouteAuthorization, ClinicalDirectRoute, CalendarMutation, FundIntegrity, PortalPayload, and consent suites

- [ ] **Step 1: Write one failing adversarial case per evidenced gap**

Use distinct organizations and same-organization foreign parents. Assert 403/404 before side effects and assert restricted Inertia props are absent, including partial reloads.

- [ ] **Step 2: Run RED in focused groups**

Run only the new named tests for the current domain. Expected: each new case fails at the intended authorization, binding, omission, lock, or idempotency assertion.

- [ ] **Step 3: Implement the minimal canonical fix**

Enforce exact permission/policy, client access, organization match, and nested ownership before invoking the existing service transaction. Add no profile-owned domain model or endpoint.

- [ ] **Step 4: Run GREEN plus the owning regression suite**

Expected: focused new tests and existing domain tests pass with zero failures.

- [ ] **Step 5: Update P0 ledger evidence and commit by logical domain**

Use separate commits for regulated consent/privacy, finance/calendar integrity, and clinical/document/portal binding if all three require code.

### Task 4: Complete P1 personal, onboarding, notes, timeline, plans, and family workflows

**Files:**

- Create: `tests/Feature/Operations/ClientProfileOutstandingLifecycleTest.php`
- Create: `resources/js/test/client-profile-outstanding-ui.test.tsx`
- Modify as proven: `resources/js/pages/operations/clients/show.tsx`, `tabs/personal-details.tsx`, `tabs/daily-notes.tsx`, `tabs/communication-notes.tsx`, `tabs/timeline-tab.tsx`, `tabs/care-support-plan.tsx`, `tabs/goals-path.tsx`, existing dialog components, and corresponding canonical controllers/resources

- [ ] **Step 1: Add server RED tests for missing lifecycle contracts**

Cover Personal Details full round trip, author-only draft detail/resume/edit/submit, communication transitions supported by policy/state, timeline source resolution, working care-plan/goal lifecycle, and immutable family-authored content with staff-owned response/status actions.

- [ ] **Step 2: Add frontend RED tests**

Render real tab/dialog components. Assert exact capability actions, loading disablement, empty/restricted copy, inline server error, success/close behavior, restored URL fail-closed behavior, and keyboard-visible focus/return.

- [ ] **Step 3: Run RED**

Run the named Pest method or Vitest test after each addition. Expected: fail because the asserted behavior/control is absent, not because fixtures or imports are broken.

- [ ] **Step 4: Implement minimal composition/UI changes**

Reuse canonical endpoints and dialog primitives. Do not add lifecycle actions the domain does not support; label those states intentionally read-only.

- [ ] **Step 5: Run GREEN and commit each self-contained workflow slice**

Expected: new focused tests plus relevant existing Batch One/Care Plan suites pass.

### Task 5: Inventory and close P2 parity rows

**Files:**

- Modify as proven: existing Location, routines, meals, assessments, health monitoring, MAR, transport, leave/excursions, respite, personal inventory, agreements, photos, audit, and related tab/controller files
- Extend: `tests/Feature/Operations/ClientProfileOutstandingLifecycleTest.php`
- Extend: `resources/js/test/client-profile-outstanding-ui.test.tsx`

- [ ] **Step 1: Build the P2 capability/lifecycle table in the ledger**

For each row list supported create/detail/edit/transitions and intentional read-only actions. Link direct routes to canonical policy and client/org binding evidence.

- [ ] **Step 2: Add RED only for a concrete missing or unsafe behavior**

Prefer a focused test in the existing domain suite when it already owns that behavior. Expected: a precise failure for the gap.

- [ ] **Step 3: Implement minimal parity**

Expose the canonical client-scoped action/detail in the existing tab/dialog, or record the action as intentionally external/read-only when cross-client/global by design.

- [ ] **Step 4: Verify row-level GREEN evidence**

Run the exact server and frontend tests named in the row. Do not mark browser proof from component tests.

- [ ] **Step 5: Commit in small domain groups**

Keep generated files and `package-lock.json` unstaged unless a reviewed, necessary change exists.

### Task 6: Run proportional aggregate server/frontend gates

**Files:**

- Modify: `docs/client-profile-web-completion-goal.md`

- [ ] **Step 1: Run aggregate Client Profile server suites**

Run all `ClientProfile*`, canonical consent/calendar/fund/note/care-plan/location/portal security suites touched by this work against the isolated Client Profile test database. Expected: zero failures.

- [ ] **Step 2: Run aggregate Client Profile frontend suites**

Run all `client-profile*`, location, care-plan, goals, and new outstanding UI Vitest files. Expected: zero failures and no warnings.

- [ ] **Step 3: Record exact commands, counts, assertions, durations, and boundaries**

Append a checkpoint; never replace prior release evidence.

### Task 7: Verify local desktop behavior at 1440x900

**Files:**

- Create: `tests/e2e/operations-client-profile-outstanding.spec.ts`
- Modify: `docs/client-profile-web-completion-goal.md`

- [ ] **Step 1: Confirm checkout/host pair and process baseline**

Verify the local server serves this worktree, inspect `public/hot`, record occupied ports, and use a dedicated port. Do not use production credentials or production writes.

- [ ] **Step 2: Create deterministic local fixtures with exact cleanup**

Use unique markers and delete only rows/files created by this spec. Preserve sentinels and prove cleanup.

- [ ] **Step 3: Run desktop Chromium scenarios**

At `1440x900`, cover every changed tab/dialog, loading/error/success state that can be induced safely, keyboard focus, canonical URL/dialog state, failed requests, and console errors.

- [ ] **Step 4: Record row-specific browser proof**

Name the exact URL/scenario in each row. A row without direct browser evidence remains Partial unless its actions are intentionally non-visual.

- [ ] **Step 5: Stop every process started by this task**

Verify the dedicated port is no longer listening and no helper browser/server process remains.

### Task 8: Run static, build, and final review gates

**Files:**

- Modify: `docs/client-profile-web-completion-goal.md`

- [ ] **Step 1: Run PHP and formatter gates**

Run `php -l` on every changed PHP file, scoped Pint, scoped Prettier, and scoped ESLint with `--max-warnings=0`.

- [ ] **Step 2: Run generation/types/builds**

Run Wayfinder generation when needed and review generated diff; then `npm run types`, `npm run build`, and `npx vite build --ssr` sequentially. Expected: exit 0.

- [ ] **Step 3: Run repository integrity gates**

Run `git diff --check`, inspect `git diff --stat`, inspect the full diff, and confirm no HR UI/ledger, `package-lock.json`, generated churn, or unrelated checkout files entered the branch.

- [ ] **Step 4: Complete the matrix honestly**

Every row must be Verified with exact evidence or retain a precise in-scope/external boundary. Append the final checkpoint with commands and counts.

- [ ] **Step 5: Commit the final evidence slice and verify clean status**

Run final focused/aggregate verification on committed HEAD, then `git status --short --branch`. Expected: clean branch.

### Task 9: Report the integration handoff

- [ ] **Step 1: Report exact branch, worktree, HEAD, commits, tests, boundaries, and processes**

Do not merge, push, deploy, open a PR, or edit another checkout.

- [ ] **Step 2: Mark the autonomous goal complete only if no required work remains**

Call `update_goal` with `status: complete` only after the verified, committed, clean branch and honest ledger satisfy the objective.
