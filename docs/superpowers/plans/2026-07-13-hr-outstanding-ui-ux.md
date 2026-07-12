# HR Outstanding UI/UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the proven HR e-signature, approvals, Wellbeing clarity, and specialised-hero gaps without changing canonical workflow ownership.

**Architecture:** Strengthen tenant guards and lifecycle notices at the existing e-signature controller/service boundary, enrich the existing approvals presenter, clarify the existing actor-scoped Wellbeing undo, and move three dashboards onto small dedicated `HrHero` components. Existing models, routes, state machines, and shared UI primitives remain authoritative.

**Tech Stack:** Laravel 12, Pest, Inertia, React 19, TypeScript, Vitest, Tailwind CSS, Playwright/browser verification.

---

### Task 1: Establish the durable audit ledger

**Files:**
- Create: `docs/hr-outstanding-ui-ux-goal.md`
- Modify: `HR_DEFERRED_BACKLOG_PROGRESS.md`

- [ ] **Step 1: Record exact base, branch, worktree, canonical classifications, and non-goals in the goal ledger.**
- [ ] **Step 2: Add an append-only programme row to the canonical deferred ledger only after a candidate's status materially changes.**
- [ ] **Step 3: Run `git diff --check` and expect exit 0.**
- [ ] **Step 4: Commit the design, plan, and initial ledger as `docs(hr): plan outstanding UI UX closeout`.**

### Task 2: Secure e-signature tenancy and notify the requester

**Files:**
- Create: `app/Domain/Hr/Notifications/SignatureOutcomeNotification.php`
- Modify: `app/Http/Controllers/Hr/ESignatureController.php`
- Modify: `app/Domain/Hr/Services/ESignatureService.php`
- Modify: `resources/js/pages/hr/signatures/pending.tsx`
- Modify: `resources/js/pages/hr/signatures/sign.tsx`
- Test: `tests/Feature/Hr/ESignatureAuthorizationTest.php`
- Test: `tests/Feature/Hr/ESignatureRequestTest.php`
- Test: `resources/js/test/hr-outstanding-ui-ux.test.tsx`

- [ ] **Step 1: Add Pest cases proving a manager cannot request a foreign-tenant document/signer or nudge, resend, or cancel foreign-tenant signature records.**
- [ ] **Step 2: Run `php artisan test tests/Feature/Hr/ESignatureAuthorizationTest.php tests/Feature/Hr/ESignatureRequestTest.php` and confirm the new authorization cases fail because current global lookups allow the writes.**
- [ ] **Step 3: Add Pest cases asserting the same-tenant requester receives exactly one signed/declined database notification, self-requesters receive none, and failed repeat transitions notify nobody.**
- [ ] **Step 4: Run the focused tests and confirm the outcome-notification cases fail because no notification exists.**
- [ ] **Step 5: Resolve the HR tenant in the controller, reject cross-tenant documents/users/signatures, and add a privacy-minimal `SignatureOutcomeNotification` sent after successful sign/decline transitions.**
- [ ] **Step 6: Add a Vitest source contract for shared `formatDateTimeLong` use on signature list/detail pages; confirm RED, then replace raw timestamp rendering and pass ISO timestamps from the controller.**
- [ ] **Step 7: Re-run focused Pest/Vitest, PHP syntax, scoped Pint/Prettier/ESLint, and `git diff --check`; expect all green with zero ESLint warnings.**
- [ ] **Step 8: Commit as `fix(hr): secure signature outcomes and notify requesters`.**

### Task 3: Clarify the federated approvals surface

**Files:**
- Modify: `app/Http/Controllers/Hr/ApprovalController.php`
- Modify: `resources/js/pages/hr/approvals/pending.tsx`
- Modify: `tests/Feature/Hr/ApprovalsInboxSeamTest.php`
- Test: `resources/js/test/hr-outstanding-ui-ux.test.tsx`

- [ ] **Step 1: Extend `ApprovalsInboxSeamTest` to require friendly `item_label` values and ISO timestamps while retaining the four native queue links and unsupported recruitment service boundary.**
- [ ] **Step 2: Run the focused Pest test and confirm RED on missing `item_label`/non-ISO dates.**
- [ ] **Step 3: Add Vitest source assertions for the shared date helper, a combined actionable empty state, and preserved separate native/chain section copy; confirm RED.**
- [ ] **Step 4: Emit friendly server labels and ISO instants, format them with `formatDateTimeLong`, and show one combined empty card when both collections are empty.**
- [ ] **Step 5: Re-run focused Pest/Vitest, PHP syntax, scoped Pint/Prettier/ESLint, and `git diff --check`; expect green.**
- [ ] **Step 6: Commit as `feat(hr): clarify federated approvals`.**

### Task 4: Prove and explain actor-scoped Wellbeing undo

**Files:**
- Modify: `resources/js/pages/hr/wellbeing/index.tsx`
- Modify: `tests/Feature/Hr/WellbeingCareWorkflowTest.php`
- Test: `resources/js/test/hr-outstanding-ui-ux.test.tsx`

- [ ] **Step 1: Add Pest cases where manager A's undo removes only manager A's latest action, manager B's action remains, and a foreign-tenant subject returns 403 with no deletion.**
- [ ] **Step 2: Run the focused cases and verify they expose any actor/tenant defect or, if existing behavior already passes, retain them as characterization proof before UI-only work.**
- [ ] **Step 3: Add a failing Vitest contract requiring `role="status"`, `aria-live="polite"`, and copy that says Undo removes only the acting manager's latest triage action.**
- [ ] **Step 4: Implement the accessible status region and bounded undo explanation without changing the service or adding confidential data paths.**
- [ ] **Step 5: Run `WellbeingCareWorkflowTest`, `WellbeingControlRoomBoundaryTest`, focused Vitest, scoped formatting/lint, TypeScript, and `git diff --check`; expect green.**
- [ ] **Step 6: Commit as `fix(hr): clarify wellbeing triage undo`.**

### Task 5: Add specialised Analytics, Headcount, and Succession heroes

**Files:**
- Create: `resources/js/components/hr/analytics-hero.tsx`
- Create: `resources/js/components/hr/headcount-hero.tsx`
- Create: `resources/js/components/hr/succession-hero.tsx`
- Modify: `resources/js/pages/hr/analytics/index.tsx`
- Modify: `resources/js/pages/hr/headcount/index.tsx`
- Modify: `resources/js/pages/hr/succession/index.tsx`
- Test: `resources/js/test/hr-outstanding-ui-ux.test.tsx`

- [ ] **Step 1: Add Vitest source contracts requiring `AnalyticsHero`, `HeadcountHero`, and `SuccessionHero`, existing server-derived stats/actions, and removal of the duplicate Analytics/Headcount KPI grids.**
- [ ] **Step 2: Run the focused Vitest file and confirm RED because the specialised components do not exist.**
- [ ] **Step 3: Implement the three focused hero components over `HrHero`; wire existing props/actions and remove only duplicated summary-card markup.**
- [ ] **Step 4: Run focused Vitest, `HeadcountDashboardTest`, `AnalyticsDashboardTenantTest`, succession tests, TypeScript, scoped Prettier, zero-warning scoped ESLint, client build, SSR build, and `git diff --check`.**
- [ ] **Step 5: Commit as `feat(hr): specialise workforce planning heroes`.**

### Task 6: Aggregate verification and closeout

**Files:**
- Modify: `docs/hr-outstanding-ui-ux-goal.md`
- Modify: `HR_DEFERRED_BACKLOG_PROGRESS.md`

- [ ] **Step 1: Run the focused backend bundle for e-signatures, approvals, Wellbeing, Analytics, Headcount, and Succession and record exact counts.**
- [ ] **Step 2: Run proportional aggregate HR tests, full relevant Vitest, PHP syntax, scoped Pint, scoped Prettier, zero-warning ESLint, TypeScript, client build, SSR build, and `git diff --check`; record exit codes and any baseline boundary.**
- [ ] **Step 3: Start a worktree-bound local server/asset host, verify exact changed URLs at desktop and mobile widths with a non-production test actor, record visible results plus console/network state, and terminate every started process.**
- [ ] **Step 4: Review `git diff 4d3948c1...HEAD`, confirm no Client/generated/package-lock/broad formatting churn, and append factual final evidence to both ledgers.**
- [ ] **Step 5: Commit final documentation, run a fresh clean-status and HEAD verification, and report exact branch/worktree/commits/tests/boundaries/processes.**
