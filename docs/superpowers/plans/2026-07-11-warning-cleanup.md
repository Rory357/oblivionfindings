# Zero-Warning Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. This plan is approved for inline execution; do not dispatch subagents.

**Goal:** Remove all 7 npm advisories and all 737 ESLint warnings while preserving application behaviour and keeping the lint guardrails enabled.

**Architecture:** Treat `npm audit` and ESLint `--max-warnings=0` as executable acceptance tests. Remediate the dependency graph first, then fix hook stability, semantic-colour findings, and component-guardrail findings in isolated module batches. Rebase current `main` before the final full-suite gate so unrelated concurrent work is preserved.

**Tech Stack:** npm 10, Vite 7, React 19, TypeScript, ESLint 9 flat config, Vitest, Laravel Wayfinder, Laravel deployment scripts.

---

## Verified starting state

- Branch: `codex/complete-warning-cleanup`, based on `main` at `1027c5ed`.
- Design commit: `41d1b4c0`.
- `npm ci`: succeeds and reports 7 advisories.
- ESLint: 0 errors, 737 warnings in 172 files.
  - 452 raw-button guardrail findings.
  - 163 card-panel guardrail findings.
  - 93 semantic-colour guardrail findings.
  - 29 `react-hooks/exhaustive-deps` findings.
- After `php artisan wayfinder:generate`, `npm run types` passes.
- The baseline Vitest run has 8 pre-existing failures in 4 suites. Three are stale fixtures/mocks; the sidebar failure overlaps the separate `codex/move-billing-nav-finance` worktree and must not be overwritten.

### Task 1: Make warning-free output an enforced gate

**Files:**
- Modify: `package.json`

- [ ] **Step 1: Verify the gate is red**

Run:

```powershell
npm audit --package-lock-only
npm run lint -- --max-warnings=0
```

Expected: audit exits non-zero with 7 vulnerabilities; lint exits non-zero with 737 warnings.

- [ ] **Step 2: Add the zero-warning lint contract**

Change the lint script to:

```json
"lint": "eslint . --fix --max-warnings=0"
```

This retains autofix behaviour while making any remaining warning fail locally and in CI.

- [ ] **Step 3: Verify the script still fails for the existing warnings**

Run `npm run lint`.

Expected: exit 1 with warnings, proving the new gate is active.

- [ ] **Step 4: Commit the gate**

```powershell
git add package.json
git commit -m "chore: enforce zero-warning lint"
```

### Task 2: Remediate all dependency advisories without major upgrades

**Files:**
- Modify: `package.json`
- Modify: `package-lock.json`

- [ ] **Step 1: Capture the vulnerable paths**

Run:

```powershell
npm ls @babel/core form-data js-yaml shell-quote undici vite concurrently --all
```

Expected paths: plugin-react → Babel, Inertia → axios → form-data, ESLint → js-yaml, concurrently → shell-quote, jsdom → undici, and direct Vite.

- [ ] **Step 2: Pin compatible safe direct releases and transitive floors**

Keep the existing majors and set:

```json
"concurrently": "^9.2.3",
"vite": "^7.3.6",
"overrides": {
    "@babel/core": "^7.29.7",
    "form-data": "^4.0.6",
    "js-yaml": "^4.3.0",
    "shell-quote": "^1.10.0",
    "undici": "^7.28.0"
}
```

Run `npm install` to regenerate the lockfile.

- [ ] **Step 3: Verify the audit turns green**

Run:

```powershell
npm audit --package-lock-only
npm ls @babel/core form-data js-yaml shell-quote undici vite concurrently --all
```

Expected: 0 vulnerabilities and only compatible major versions.

- [ ] **Step 4: Verify a clean install**

Run `npm ci`.

Expected: exit 0 and `found 0 vulnerabilities`.

- [ ] **Step 5: Commit the dependency slice**

```powershell
git add package.json package-lock.json
git commit -m "chore: remediate frontend dependency advisories"
```

### Task 3: Fix all React hook dependency warnings

**Files:**
- Modify: `resources/js/components/checklists/run-modal.tsx`
- Modify: `resources/js/components/client-profile/recent-clients-strip.tsx`
- Modify: `resources/js/components/credential-totp-display.tsx`
- Modify: `resources/js/components/governance/GovernanceCalendar.tsx`
- Modify: `resources/js/components/leaflet-map.tsx`
- Modify: `resources/js/components/medications/RecordAdministrationDialog.tsx`
- Modify: `resources/js/components/rostering/series-pane.tsx`
- Modify: `resources/js/components/rostering/templates-pane.tsx`
- Modify: `resources/js/pages/fleet-assets/assets/components/asset-wizard-dialog.tsx`
- Modify: `resources/js/pages/fleet-assets/resident-tracking/history.tsx`
- Modify: `resources/js/pages/fleet-assets/resident-tracking/index.tsx`
- Modify: `resources/js/pages/operations/clients/show.tsx`
- Modify: `resources/js/pages/operations/shifts/components/create-shift-dialog.tsx`
- Modify: `resources/js/pages/sites/meal-planner/_dialogs.tsx`
- Modify: `resources/js/pages/sites/meal-planner/_shopping-list-panel.tsx`
- Modify: `resources/js/pages/sites/plan/_builder-dialog.tsx`
- Modify: `resources/js/pages/sites/plan/_canvas.tsx`

- [ ] **Step 1: Run the focused lint gate and confirm 29 warnings**

Run ESLint with the file list above and `--max-warnings=0`.

- [ ] **Step 2: Stabilize derived fallback arrays/objects**

Replace render-time fallbacks such as:

```tsx
const items = value ?? [];
```

when consumed by hooks with:

```tsx
const items = useMemo(() => value ?? [], [value]);
```

Apply this to checklist items, rostering lists, asset clients, resident locations/alerts, shopping-list items, emergency kinds, and pending references.

- [ ] **Step 3: Correct actual effect and memo dependencies**

Include the referenced stable values in each dependency list. Wrap `syncClusterZoomListener` in `useCallback` before including it. Extract `form.data.client_ids` to a stable named value in the meal-planner dialog. Remove the unnecessary `needsReason` dependency from the medication memo.

- [ ] **Step 4: Run focused behavioural tests**

Run the existing Vitest files adjacent to the modified modules where present, then `npm run types`.

- [ ] **Step 5: Verify the hook warning count is zero**

Run focused ESLint again with `--max-warnings=0`.

- [ ] **Step 6: Commit the hook slice**

```powershell
git add resources/js
git commit -m "fix: stabilize React hook dependencies"
```

### Task 4: Resolve semantic-colour guardrails

**Files:**
- Modify: `resources/js/components/job-board/job-board-hero.tsx`
- Modify: `resources/js/components/job-board/job-card.tsx`
- Modify: `resources/js/components/operations/dashboard/hover-popover.tsx`
- Modify: `resources/js/components/operations/dashboard/kpi-cards.tsx`
- Modify: `resources/js/components/rostering/week-picker.tsx`
- Modify: `resources/js/pages/catering/recipes/index.tsx`
- Modify: `resources/js/pages/catering/recipes/show.tsx`
- Modify: `resources/js/pages/operations/timesheets/index.tsx`
- Modify: `resources/js/pages/settings/calendar-sync.tsx`
- Modify: `resources/js/pages/sites/emergency-plan/index.tsx`
- Modify: `resources/js/pages/sites/meal-planner/_dialogs.tsx`
- Modify: `resources/js/pages/sites/meal-planner/_hero.tsx`
- Modify: `resources/js/pages/sites/plan/_builder-dialog.tsx`
- Modify: `resources/js/pages/sites/plan/_canvas.tsx`
- Modify: `resources/js/pages/sites/plan/_emergency-checklist.tsx`
- Modify: `resources/js/pages/sites/plan/_inspector.tsx`
- Modify: `resources/js/pages/sites/plan/_thumbnail.tsx`
- Modify: `resources/js/pages/sites/plan/_tool-palette.tsx`

- [ ] **Step 1: Verify 93 focused colour warnings**

Run focused ESLint with `--max-warnings=0`.

- [ ] **Step 2: Replace semantic statuses with token classes**

Use the mappings in `docs/DESIGN_TOKENS.md`, for example:

```tsx
text-red-* / bg-red-*       -> text-status-danger / bg-status-danger-bg
text-amber-* / bg-amber-*   -> text-status-warning / bg-status-warning-bg
text-emerald-* / bg-green-* -> text-status-success / bg-status-success-bg
text-blue-* / bg-blue-*     -> text-status-info / bg-status-info-bg
```

- [ ] **Step 3: Document intentional visualization colours narrowly**

For meal palettes, recipe nutrition, floor-plan layers, and emergency-plan categorical colours that are not statuses, keep the visual colour and add a same-line or immediately preceding disable with a concrete reason:

```tsx
// eslint-disable-next-line no-restricted-syntax -- Categorical floor-plan layer colour, not a semantic status.
```

- [ ] **Step 4: Verify focused ESLint is warning-free and commit**

```powershell
git add resources/js
git commit -m "fix: resolve semantic colour guardrails"
```

### Task 5: Resolve shared-component, checklist, safety, and control-room guardrails

**Files:**
- Modify all listed files under `resources/js/components/checklists/`: `context-menu.tsx`, `embedded-header.tsx`, `hero-footer.tsx`, `panes/assignments.tsx`, `panes/due-now.tsx`, `panes/library.tsx`, `panes/overview.tsx`, `panes/reports.tsx`, `panes/runs.tsx`, `panes/schedule.tsx`, `primitives.tsx`, `run-cards.tsx`, `run-modal.tsx`, `template-builder.tsx`.
- Modify: `resources/js/components/break-control.tsx`
- Modify: `resources/js/components/clients/profile/restraints-bsp-panel.tsx`
- Modify: `resources/js/components/control-room/alert-workspace-dialog.tsx`
- Modify: `resources/js/components/control-room/playbook-wizard.tsx`
- Modify: `resources/js/components/fleet/fleet-incident-dialog.tsx`
- Modify: `resources/js/components/fleet/fleet-telematics-storyboard.tsx`
- Modify: `resources/js/components/governance/FinancialGovernancePanel.tsx`
- Modify: `resources/js/components/governance/GovernanceAttachmentsPanel.tsx`
- Modify: `resources/js/components/governance/OperationalSignalsAccordion.tsx`
- Modify: `resources/js/components/governance/RiskComplianceWatchlist.tsx`
- Modify all warning-bearing files under `resources/js/components/health-safety/`: `bsp-wizard.tsx`, `lone-worker-action-form.tsx`, `lone-worker-detail-dialog.tsx`, `lone-worker-wizard.tsx`, `procedure-detail-dialog.tsx`, `procedure-wizard-dialog.tsx`, `restraint-event-wizard.tsx`.
- Modify: `resources/js/components/pre-shift-briefing-card.tsx`
- Modify: `resources/js/components/previous-shift-card.tsx`

- [ ] **Step 1: Confirm the focused warnings are red**

Run ESLint over the exact files above with `--max-warnings=0`.

- [ ] **Step 2: Convert standard actions and panels**

Use existing imports and preserve classes:

```tsx
<button type="button" onClick={onClick} className={classes}>…</button>
```

becomes:

```tsx
<Button type="button" variant="ghost" onClick={onClick} className={classes}>…</Button>
```

Plain rounded bordered panels become `<Card className={classes}>…</Card>` or the appropriate `CardContent` composition.

- [ ] **Step 3: Keep custom interaction surfaces explicit**

Selector tiles, context-menu rows, and drag/drop surfaces retain their native markup only with a per-node explanatory disable. Do not add file-level disables.

- [ ] **Step 4: Run focused lint, adjacent Vitest tests, and types; then commit**

```powershell
git add resources/js
git commit -m "fix: resolve shared UI guardrails"
```

### Task 6: Resolve HR, rostering, respite, fleet, and job-board guardrails

**Files:**
- Modify warning-bearing files in `resources/js/components/hr/`: `asset-wizards.tsx`, `onboarding/email-dialog.tsx`, `onboarding/provision-asset-dialog.tsx`, `onboarding/reassign-dialog.tsx`, `training/training-wizard-dialog.tsx`.
- Modify warning-bearing files in `resources/js/components/rostering/`: `analytics-pane.tsx`, `edit-availability-dialog.tsx`, `entity-filter.tsx`, `make-recurring-dialog.tsx`, `multi-entity-filter.tsx`, `open-shifts-pane.tsx`, `series-dialogs.tsx`, `series-pane.tsx`, `signal-rail.tsx`, `site-filter.tsx`, `tab-strip.tsx`, `templates-pane.tsx`, `week-picker.tsx`.
- Modify warning-bearing files in `resources/js/components/respite/`: `modals/referral-intake.tsx`, `modals/request-intake.tsx`, `pane-kit.tsx`, `panes/overview.tsx`, `panes/referrals.tsx`, `panes/requests.tsx`.
- Modify: `resources/js/components/job-board/job-board-hero.tsx`, `job-card.tsx`, `scope-tabs.tsx`, `smart-matches-strip.tsx`.
- Modify: `resources/js/components/resident-tracking/resident-map.tsx`, `resident-sidebar.tsx`.
- Modify: `resources/js/components/roster/shift-detail-sheet.tsx`, `upcoming-list.tsx`, `week-grid-overview.tsx`.
- Modify warning-bearing pages under `resources/js/pages/fleet-assets/`: `bookings/book-vehicle-wizard.tsx`, `devices/index.tsx`, `incidents/index.tsx`, `resident-tracking/history.tsx`, `resident-tracking/index.tsx`.
- Modify: `resources/js/pages/careers/reference-questionnaire.tsx`.
- Modify warning-bearing catering pages: `products/index.tsx`, `recipes/edit.tsx`, `recipes/index.tsx`, `recipes/show.tsx`.

- [ ] **Step 1: Run the focused lint gate**
- [ ] **Step 2: Convert standard actions/panels and document only genuine custom surfaces**
- [ ] **Step 3: Run focused lint, module tests, and types**
- [ ] **Step 4: Commit as `fix: resolve workforce UI guardrails`**

### Task 7: Resolve HR pages and operations workflow guardrails

**Files:**
- Modify warning-bearing HR pages: `resources/js/pages/hr/candidates/show.tsx`, `compliance/calendar.tsx`, `compliance/components/wizard-fields.tsx`, `compliance/index.tsx`, `compliance/matrix.tsx`, `compliance/staff-detail.tsx`, `drivers/index.tsx`, `drivers/show.tsx`, `performance/competencies/profile.tsx`, `training/catalog.tsx`, `vetting/index.tsx`, `wellbeing/index.tsx`.
- Modify operations client files: `resources/js/pages/operations/clients/_food-meal-preferences.tsx`, `dialogs/_note-category-picker.tsx`, `tabs/actions-reviews.tsx`, `tabs/communication-notes.tsx`, `tabs/rhythms-routines.tsx`.
- Modify handover files: `resources/js/pages/operations/handovers/components/board-view.tsx`, `cards-view.tsx`, `handover-detail-dialog.tsx`, `handover-rail.tsx`, `list-view.tsx`, `toolbar.tsx`.
- Modify shift-note files: `resources/js/pages/operations/shift-notes/components/cards-view.tsx`, `list-view.tsx`, `note-detail-dialog.tsx`, `note-rail.tsx`, `toolbar.tsx`, and `resources/js/pages/operations/shift-notes/Index.tsx`.
- Modify shift files: `resources/js/pages/operations/shifts/components/create-shift-dialog.tsx`, `shift-calendar-view.tsx`, `shift-context-menu.tsx`, `shift-detail-dialog.tsx`, `shift-list-view.tsx`, and `resources/js/pages/operations/shifts/index.tsx`.
- Modify: `resources/js/pages/operations/timesheets/index.tsx`.
- Modify: `resources/js/pages/operations/job-board/Index.tsx`.
- Modify: `resources/js/pages/incidents/index.tsx`.

- [ ] **Step 1: Run the focused lint gate**
- [ ] **Step 2: Convert standard actions/panels and document genuine row/tile controls**
- [ ] **Step 3: Run focused lint, operations Vitest tests, and types**
- [ ] **Step 4: Commit as `fix: resolve operations UI guardrails`**

### Task 8: Resolve site-management, meal-planner, floor-plan, and remaining page guardrails

**Files:**
- Modify site calendar files: `resources/js/pages/sites/calendar/_parts.tsx`, `SiteCalendar.tsx`.
- Modify site dialog/pages: `resources/js/pages/sites/_wizard.tsx`, `clients/_dialogs.tsx`, `contacts/_dialogs.tsx`, `rooms/_asset-dialogs.tsx`, `rooms/_dialogs.tsx`, `rooms/index.tsx`, `show.tsx`.
- Modify every warning-bearing meal-planner file: `_calendar-grid.tsx`, `_dialogs.tsx`, `_hero.tsx`, `_inventory-table.tsx`, `_library-dialogs.tsx`, `_recipe-edit-dialog.tsx`, `_recipes-panel.tsx`, `_shopping-list-panel.tsx`, `_templates-panel.tsx`, `index.tsx`.
- Modify every warning-bearing plan file: `_builder-dialog.tsx`, `_canvas.tsx`, `_emergency-checklist.tsx`, `_inspector.tsx`, `_thumbnail.tsx`, `_tool-palette.tsx`.
- Modify: `resources/js/pages/sites/emergency-plan/index.tsx`.
- Modify: `resources/js/pages/tasks/index.tsx`, `task-detail-dialog.tsx`.
- Modify: `resources/js/pages/my-roster/index.tsx`.
- Modify: `resources/js/pages/security-devices/integrations/queclink-hub.tsx`.
- Modify: `resources/js/pages/settings/calendar-sync.tsx`.
- Modify Governance dialog/pages: `CeoReports/_dialogs.tsx`, `CeoReports/Index.tsx`, `Packs/_dialogs.tsx`, `Resolutions/_dialogs.tsx`.
- Modify remaining control/safety pages: `resources/js/pages/control-room/index.tsx`, `my-tasks.tsx`, `health-safety/dashboard.tsx`, `health-safety/lone-workers/index.tsx`, `health-safety/restraints/index.tsx`.
- Modify compliance wizard pages: `resources/js/pages/compliance/wizards/complete-obligation-dialog.tsx`, `record-evidence-dialog.tsx`.

- [ ] **Step 1: Run the focused lint gate**
- [ ] **Step 2: Convert ordinary controls/panels; document calendar cells, floor-plan tools, drag surfaces, and selector tiles narrowly**
- [ ] **Step 3: Run focused lint, site/meal-planner tests, and types**
- [ ] **Step 4: Commit as `fix: resolve site UI guardrails`**

### Task 9: Repair stale baseline test fixtures without changing product behaviour

**Files:**
- Modify: `resources/js/pages/my-day/index-audit-fixes.test.tsx`
- Modify: `resources/js/test/behaviour-abc-tab.test.tsx`
- Modify: `resources/js/test/resident-tracking.test.tsx`
- Inspect only: `resources/js/components/app-sidebar.test.ts`
- Inspect only: `resources/js/components/app-sidebar.tsx`

- [ ] **Step 1: Preserve the red baseline evidence**

Run the four failing suites and confirm 8 failures.

- [ ] **Step 2: Complete the My Day mock contract**

Add this export to the existing `./_dialogs` mock:

```tsx
MealLogDialog: () => null,
```

- [ ] **Step 3: Make the behaviour test fetch fixture endpoint-aware**

Return `{ active_plan: null, recent_events: [], total_events: 0 }` for the restraint-summary URL, and return the existing ABC payload for the ABC endpoint. This prevents one mocked endpoint from returning the wrong schema to another component.

- [ ] **Step 4: Align resident-tracking assertions to current visible copy**

Assert `Residents tracked`, the visible online caption, `Low Battery`, `Battery not reported`, `Low battery`, and `Charging` rather than stale shortened hero labels.

- [ ] **Step 5: Rebase current main before touching the sidebar seam**

Fetch and rebase onto `origin/main`. If the separate billing-navigation change has landed, rerun the sidebar test. Do not recreate, overwrite, or cherry-pick unmerged work from `codex/move-billing-nav-finance` without explicit authorization.

- [ ] **Step 6: Verify all four suites and commit test-only fixture repairs**

```powershell
git add resources/js/pages/my-day/index-audit-fixes.test.tsx resources/js/test/behaviour-abc-tab.test.tsx resources/js/test/resident-tracking.test.tsx
git commit -m "test: refresh frontend fixture contracts"
```

### Task 10: Run the complete zero-warning release gates

**Files:**
- Verify: `package.json`
- Verify: `package-lock.json`
- Verify: all modified `resources/js/**/*.{ts,tsx}` files

- [ ] **Step 1: Regenerate local route artifacts**

Run `php artisan wayfinder:generate`.

- [ ] **Step 2: Run all proof commands from fresh state**

```powershell
npm ci
npm audit --package-lock-only
npm run lint
npm run types
npm test
npm run build
npx vite build --ssr
git diff --check
```

Expected: audit 0, lint 0 errors/0 warnings, types 0 errors, Vitest 0 failed, both builds exit 0, diff check clean.

- [ ] **Step 3: Run the ticketing regression suite**

Run `php artisan test tests/Feature/It --compact`.

Expected: all ticketing tests pass.

- [ ] **Step 4: Update the design/plan ledger with exact counts and command evidence**

Record final dependency versions, warning counts, test counts, and build outcomes in this plan.

- [ ] **Step 5: Commit the verification ledger**

```powershell
git add docs/superpowers/plans/2026-07-11-warning-cleanup.md
git commit -m "docs: close zero-warning cleanup ledger"
```

### Task 11: Integrate, push, deploy, and verify staging

**Files:**
- Merge: `codex/complete-warning-cleanup` into `main`
- Deploy root: `/var/www/oblivionfindings`

- [ ] **Step 1: Fetch and confirm the branch contains current `origin/main`**
- [ ] **Step 2: Fast-forward or merge into the isolated main checkout**
- [ ] **Step 3: Re-run `npm audit`, zero-warning lint, types, tests, client build, and SSR build on the merged SHA**
- [ ] **Step 4: Push `main` and verify the remote ref**
- [ ] **Step 5: Deploy with `scripts/deploy-server.sh --skip-nominatim --skip-queclink`**
- [ ] **Step 6: Verify server HEAD, clean status, deployment exit 0, manifest presence, staging health, and server-side `npm audit --package-lock-only` = 0**
- [ ] **Step 7: Record the final server evidence and push the ledger-only closeout commit**
