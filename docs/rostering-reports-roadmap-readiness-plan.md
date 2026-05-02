# Rostering — Reports / Roadmap — Production Readiness Plan

**Status:** Planning — implementation not started
**Date:** 2026-05-03
**Scope:** Operations Reports (the seven-tile reporting hub under `/operations/reports`) + the Roadmap module (`/roadmap/...`) + the rostering ↔ reports ↔ roadmap handoff.

GPT-5.5 audit flagged this surface as *Partial*, noting that "shift reports appear to exist, but the Roadmap UX/UI feels thin or not easy to find/use." This plan inspects the live repo (no `.claude/worktrees`) and proposes the smallest set of safe, PR-sized changes to take it to production. It explicitly does **not** propose a rewrite — the Reports backend is solid and the Roadmap domain layer is mature; the gaps are in routing, navigation, and a few thin React surfaces.

---

## 1. Current State Map

### 1.1 Operations Reports

**Routes** — [routes/operations.php:800-805](routes/operations.php)

```php
Route::middleware('permission:operations.reports.view')->group(function () {
    Route::get('/reports',                [ReportController::class,      'index'])  ->name('operations.reports.index');
    Route::get('/reports/shifts',         [ShiftReportController::class, 'index'])  ->name('operations.reports.shifts.index');
    Route::get('/reports/shifts/export',  [ShiftReportController::class, 'export']) ->name('operations.reports.shifts.export');
    Route::get('/reports/{type}',         [ReportController::class,      'show'])   ->name('operations.reports.show');
});
```

**Controllers**
- [app/Http/Controllers/Operations/ReportController.php](app/Http/Controllers/Operations/ReportController.php) — exposes seven report types via the `REPORT_TYPES` constant (lines 16-41): `client-summary`, `staff-utilisation`, `shift-analytics`, `billing`, `compliance`, `service-hours`. Each is dispatched through a `match()` to a private method that builds the payload, then rendered via `inertia('operations/reports/Show', …)`.
- [app/Http/Controllers/Operations/ShiftReportController.php](app/Http/Controllers/Operations/ShiftReportController.php) — dedicated decision-grade Shift Operations report (`Shifts.tsx`), backed by the heavier `ShiftReportingService`. Honours site-scope via `UserSiteAccessService`. Streams CSV exports per dataset (`staff-utilisation`, `coverage-gaps`, `reconciliation`, `attendance-variance`, `risk-summary`).

**Services**
- [app/Services/Operations/ReportingService.php](app/Services/Operations/ReportingService.php) — supports `shiftAnalytics` and `complianceReport`. Aggregates shifts, tasks, incidents, custom forms, MAR, handovers, transports, timesheets, billing entries, payroll cost.
- [app/Services/Operations/ShiftReportingService.php](app/Services/Operations/ShiftReportingService.php) (≈1,100 lines) — five sub-reports: `staff_utilisation`, `coverage_gap_report`, `timesheet_reconciliation_report`, `attendance_variance_report`, plus a derived `risk_summary` flag set. Reuses `ShiftCoverageService`, `TimesheetReconciliationService`, `ShiftSignalService`, `ControlRoomAlert`. CSV export contract is stable and exercised by tests.

**React surfaces** — [resources/js/pages/operations/reports/](resources/js/pages/operations/reports/)
- `Index.tsx` — clean grid of seven tiles (Shift Operations + six others). Already polished.
- `Shifts.tsx` — heavy decision-grade page: filter form (date / site / staff), Operational Risk Summary card with severity badges, Staff Utilisation, Coverage / Gap Report, Timesheet Reconciliation, Attendance / Shift Variance — each with a "Export CSV" button.
- `Show.tsx` — generic auto-rendering fallback for the other six report types: walks `data` props, emits a summary card grid for scalar values, then `renderTable()` / `renderObjectTable()` for nested arrays/objects. **Date filter only**; no per-report client/staff filters; no charts; renders an empty-state card when `data` is empty.

**Permissions** — `operations.reports.view` (canonical) and `reports.viewAny` (legacy bypass) both accepted in `ReportController` and `ShiftReportController`. Seeded by [database/seeders/OperationsPermissionsSeeder.php](database/seeders/OperationsPermissionsSeeder.php) and exposed to the front-end as `auth.can.operations.reports.view` ([app/Http/Middleware/HandleInertiaRequests.php:745-750](app/Http/Middleware/HandleInertiaRequests.php:745)).

**Sidebar discoverability** — [resources/js/components/app-sidebar.tsx](resources/js/components/app-sidebar.tsx)
- Operations group (line 954-963): one entry, "Reports & Analytics → `/operations/reports`". Good.
- Top-level Reports sub-panel (lines 1791-1804): two entries — "Operations Reports → `/operations/reports`" **and** "Shift Reports → `/reports/shifts`" (legacy). The second link points at the *old* surface, not the new one.

**Tests** — [tests/Feature/Operations/ShiftReportControllerTest.php](tests/Feature/Operations/ShiftReportControllerTest.php) — 3 tests covering the index page (component + props), site filter, and CSV export contract for `staff-utilisation`. No coverage for the other 4 datasets nor the generic `Show` controller. No browser/Playwright e2e at all.

### 1.2 Legacy `/reports/shifts` Surface (Duplicate)

- **Route** — [routes/reports.php:24](routes/reports.php) — `Route::get('/reports/shifts', [ShiftReportsController::class, 'index'])->name('reports.shifts')`.
- **Controller** — [app/Http/Controllers/ShiftReportsController.php](app/Http/Controllers/ShiftReportsController.php) — 60-line read-only "shifts in date range with notes/tasks counts" view, capped at 300 rows.
- **Page** — [resources/js/pages/reports/shifts.tsx](resources/js/pages/reports/shifts.tsx) — minimal date filter + a list of shift cards.
- **Linked from sidebar** at line 1801. **Not** linked from `Index.tsx` (which links to the modern `/operations/reports/shifts`).

This is the same data, reachable from two URLs, with two completely different page treatments. Users entering via the Reports submenu hit the thin legacy page; users entering via Operations Reports tile hit the decision-grade modern page.

### 1.3 Roadmap Module

**Routes** — [routes/roadmap.php](routes/roadmap.php) (~91 lines, all under `prefix('roadmap')`)

| Path | Verb | Controller method | Notes |
|---|---|---|---|
| `/roadmap/dashboard` | GET | `RoadmapDashboardController::index` | Inertia page (`Roadmap/Dashboard`) |
| `/roadmap/initiatives` | GET | `InitiativeController::index` | **JSON-only — redirects browser to dashboard** |
| `/roadmap/initiatives` | POST | `InitiativeController::store` | JSON |
| `/roadmap/initiatives/{initiative}` | GET/PUT | show/update | JSON |
| `/roadmap/initiatives/{initiative}/score` | POST | score | JSON |
| `/roadmap/initiatives/{initiative}/transition` | POST | transition | JSON |
| `/roadmap/suggestions` | GET | `SuggestionController::index` | **JSON-only — redirects browser to dashboard** |
| `/roadmap/suggestions/ingest` | POST | ingest | JSON |
| `/roadmap/suggestions/{suggestion}/triage` | POST | triage | JSON |
| `/roadmap/suggestions/{suggestion}/convert` | POST | convert | JSON |
| `/roadmap/quarterly-plans` | GET | `QuarterlyPlanController::index` | **JSON-only — redirects browser to dashboard** |
| `/roadmap/quarterly-plans/generate` | POST | generate | JSON |
| `/roadmap/quarterly-plans/{plan}` | GET | show | JSON |
| `/roadmap/quarterly-plans/{plan}/approve` | POST | approve | JSON |
| `/roadmap/quarterly-plans/{plan}/publish` | POST | publish | JSON |
| `/roadmap/quarterly-plans/{plan}/revise` | POST | revise | JSON |
| `/roadmap/budget/replan` | POST | `BudgetController::replan` | JSON |
| `/roadmap/budget/governance-envelope` | GET | governanceBudget | JSON |
| `/roadmap/decisions` | GET | `DecisionRequestController::index` | **JSON-only — redirects browser to dashboard** |
| `/roadmap/decisions/{decisionRequest}/resolve` | POST | resolve | JSON |
| `/roadmap/reports/{type}` | POST | `ReportController::generate` | JSON snapshot generation |
| `/roadmap/reports/snapshots/{snapshot}` | GET | show | JSON |

**Domain layer is mature** — [app/Domain/Roadmap/](app/Domain/Roadmap/) contains 35+ files: 9 controllers, 8 services, 18 models, 4 events, 5 jobs, 5 policies, 6 form requests. Tests cover the full lifecycle (initiative quick-add → suggestion triage → suggestion convert → quarterly plan generate → approve → publish → revise → snapshot reports → budget replan → decision resolution).

**React surface — only one page**
- [resources/js/pages/Roadmap/Dashboard.tsx](resources/js/pages/Roadmap/Dashboard.tsx) (≈1,400 lines) is the **single** Roadmap UI. It's a kitchen-sink workbench: 4 KPI cards, "How To Run The Quarterly Workflow" copy block, Quarterly Planning Control Center (generate plan), inline plan list (`per_page=5`, dialog detail), Triage Inbox (`per_page=8`, dialog detail with assign / notes / convert), Initiative Quick-Add card, Decisions queue. Refresh button re-fires every backing axios call.
- All other browser navigation is dead — visiting `/roadmap/initiatives`, `/roadmap/suggestions`, `/roadmap/quarterly-plans`, `/roadmap/decisions` redirects to `/roadmap/dashboard` (controllers' `shouldReturnJson` short-circuit).

**Sidebar discoverability — zero entries.** `grep -E "[Rr]oadmap" resources/js/components/app-sidebar.tsx` returns no matches. The Roadmap module is invisible in the main navigation; users can only find it by typing the URL or following the `Governance > Roadmap` breadcrumb embedded in the dashboard itself ([resources/js/pages/Roadmap/Dashboard.tsx:1112-1116](resources/js/pages/Roadmap/Dashboard.tsx:1112)). The Governance pages do not link to Roadmap either.

**Permissions** — `roadmap.view`, `roadmap.manage`, `roadmap.approve`, `roadmap.budget.manage`, `roadmap.decisions.view`, `roadmap.decisions.manage`, `roadmap.reports.export`. Seeded by [database/seeders/RoadmapPermissionsSeeder.php](database/seeders/RoadmapPermissionsSeeder.php) and exposed to the front-end as `auth.can.roadmap.{view,manage,approve,budgetManage,decisionsView,decisionsManage,reportsExport}` ([app/Http/Middleware/HandleInertiaRequests.php:733-741](app/Http/Middleware/HandleInertiaRequests.php:733)).

**Tests**
- [tests/Feature/Roadmap/RoadmapDashboardPageTest.php](tests/Feature/Roadmap/RoadmapDashboardPageTest.php) — solid: auth gate, board-member access, JSON contract, support-worker forbidden, manager assignment options.
- [tests/Feature/Roadmap/RoadmapWorkflowTest.php](tests/Feature/Roadmap/RoadmapWorkflowTest.php) — initiative quick-add, suggestion triage / convert / owner assign / notes carry, **`test_quarterly_plan_can_publish_and_revise_with_immutable_snapshot` calls `/quarterly-plans/{id}/submit-manager` and `/submit-executive` and asserts 200** (lines 278-279). These URIs **do not exist** in any routes file (verified by grep) — only the controller methods `submitManagerReview` / `submitExecutiveReview` exist. **This test must be failing in CI**, or the failure is silently tolerated.
- [tests/Feature/Roadmap/RoadmapReportsApiTest.php](tests/Feature/Roadmap/RoadmapReportsApiTest.php) — same `submit-manager` / `submit-executive` calls (lines 35-36); same failure mode.
- [tests/Feature/Roadmap/RoadmapDecisionAndBudgetApiTest.php](tests/Feature/Roadmap/RoadmapDecisionAndBudgetApiTest.php) — covers decisions list/resolve and budget replan API.
- [tests/Feature/Roadmap/RoadmapPermissionsTest.php](tests/Feature/Roadmap/RoadmapPermissionsTest.php) — RBAC seeding, denials.
- [tests/Browser/Roadmap/RoadmapTest.php](tests/Browser/Roadmap/RoadmapTest.php) — 5 Dusk tests that visit `/roadmap/dashboard`, `/roadmap/initiatives`, `/roadmap/decisions`, `/roadmap/suggestions`, `/roadmap/quarterly-plans` and assert `Initiative` / `Decision` / `Suggestion` / `Quarterly` is on the page. Because every non-dashboard URL **redirects to the dashboard**, and the dashboard already contains those words (KPI card titles, control-center copy), all four "page loads" tests are **false positives** — they pass without any of the targeted pages actually existing.

### 1.4 Rostering ↔ Reports ↔ Roadmap Handoff

- Rostering scheduler ([resources/js/pages/operations/rostering/index.tsx](resources/js/pages/operations/rostering/index.tsx)) does **not** link to `/operations/reports` or `/operations/reports/shifts`. After a manager publishes a roster period, the only way to inspect the resulting Coverage Gap / Reconciliation / Variance report is to navigate manually.
- The publish review page ([resources/js/pages/operations/rostering/publish/Review.tsx](resources/js/pages/operations/rostering/publish/Review.tsx)) likewise does not advertise the downstream Operations Reports.
- Coverage gaps surfaced in `ShiftReportingService::buildCoverageGapReport` are *exactly* the kind of recurring operational pain that should feed `app/Domain/Roadmap/Services/RoadmapSuggestionService.php` (which already ingests from `incidents` and `assets` sources) — but no rostering source ingester exists today.
- E2E coverage: [tests/e2e/operations-rostering-publish.spec.ts](tests/e2e/operations-rostering-publish.spec.ts) and friends — no test follows the manager into Reports after publish; no test exists for the Reports surface at all.

---

## 2. What Works vs What's Genuinely Partial

| Area | Status | Why |
|---|---|---|
| **Operations Reports tile index** (`Index.tsx`) | ✅ Works | Polished, role-gated, links correct. |
| **Shift Operations report** (`Shifts.tsx` + `ShiftReportingService`) | ✅ Works | Decision-grade content, CSV per-dataset, tested for index/filter/export. |
| **Generic `Show.tsx` for the other 6 reports** | 🟡 Partial | Renders correctly but no per-report filters (only date), no charts, weak empty states. Acceptable for early use; thin for production. |
| **Roadmap dashboard executive overview** | ✅ Works | KPIs, plan generation, plan dialog, triage dialog, decisions inline — all functional. |
| **Roadmap deep-link pages** (`/initiatives`, `/suggestions`, `/quarterly-plans`, `/decisions`) | ❌ Broken | Browser visits silently redirect to dashboard. No standalone listing UI. |
| **Roadmap discoverability** | ❌ Missing | Zero sidebar entries; no Governance link. |
| **Roadmap quarterly plan submission flow** | ❌ Broken | Controller methods `submitManagerReview` / `submitExecutiveReview` exist; routes do not. Tests calling these URIs are red. |
| **Roadmap Dusk browser tests** | ❌ False positive | 4 of 5 tests pass only because the redirect target happens to contain the asserted text. |
| **Legacy `/reports/shifts`** | 🟡 Partial / duplicate | Still wired, sidebar still points at it, but superseded by `/operations/reports/shifts`. |
| **Rostering → Reports handoff** | 🟡 Missing | No link from scheduler / publish review to reports. |
| **Reports → Roadmap escalation** | 🟡 Missing | No "raise as initiative" affordance from coverage gaps or chronic shortages. |
| **E2E browser coverage for Reports** | ❌ Missing | Zero specs. |
| **Permission key drift** | 🟡 Cosmetic | `operations.reports.view` vs legacy `reports.viewAny` both accepted. |

---

## 3. Issue Classification (Maps to Audit Categories)

Per the audit's framing:

- **Missing report coverage** — None. The Shift Operations service covers all five operational risk axes; the six secondary reports cover the standard slices (client, staff, billing, compliance, service hours).
- **Weak report navigation / discoverability** — Yes, but only mildly. Tile index is good; the duplicate legacy `/reports/shifts` link in the sidebar is the main offender.
- **Thin Roadmap dashboard UX** — *Inverted of the audit's wording*. The dashboard is actually rich; what's thin is **everything else** — every detail surface lives only inside dashboard dialogs.
- **Roadmap API routes without proper user-facing pages** — Yes, this is the single largest gap (P1-1 below).
- **Missing browser coverage** — Yes, both for Reports (zero specs) and Roadmap (false-positive specs).
- **Stale / fragile tests** — Yes (P0-1, P0-2): `submit-manager` / `submit-executive` orphaned tests; Dusk tests asserting redirect-target text.
- **Permission or navigation gaps** — Yes, navigation; permissions are well-seeded.
- **Unclear handoff between rostering, reports, and roadmap** — Yes (P1-3).

---

## 4. P0 / P1 / P2 Implementation Plan

### P0 — Production blockers (correctness, broken contracts)

#### P0-1. Register the missing quarterly-plan workflow routes

**Why:** The `submitManagerReview` and `submitExecutiveReview` controller methods exist and tests POST to `/roadmap/quarterly-plans/{id}/submit-manager` and `/submit-executive` (see [tests/Feature/Roadmap/RoadmapWorkflowTest.php:278-279](tests/Feature/Roadmap/RoadmapWorkflowTest.php:278) and [tests/Feature/Roadmap/RoadmapReportsApiTest.php:35-36](tests/Feature/Roadmap/RoadmapReportsApiTest.php:35)) but no route declares them. Test suite is red on these lines until fixed.

**Files to change:**
- [routes/roadmap.php](routes/roadmap.php) — add two `Route::post` entries between the existing `quarterly-plans/{plan}` group (lines 58-69):

```php
Route::post('/quarterly-plans/{plan}/submit-manager', [QuarterlyPlanController::class, 'submitManagerReview'])
    ->name('plans.submit_manager')
    ->middleware('permission:roadmap.manage');
Route::post('/quarterly-plans/{plan}/submit-executive', [QuarterlyPlanController::class, 'submitExecutiveReview'])
    ->name('plans.submit_executive')
    ->middleware('permission:roadmap.manage');
```

**Acceptance criteria:**
- `php artisan route:list --path=roadmap` lists the two new routes.
- `php artisan test --filter=RoadmapWorkflowTest::test_quarterly_plan_can_publish_and_revise_with_immutable_snapshot` passes.
- `php artisan test --filter=RoadmapReportsApiTest::test_admin_can_generate_and_view_all_report_types` passes.

#### P0-2. Repair the false-positive Roadmap Dusk tests

**Why:** [tests/Browser/Roadmap/RoadmapTest.php](tests/Browser/Roadmap/RoadmapTest.php) appears to assert that 4 detail pages load; in reality every URL redirects to `/roadmap/dashboard` and the dashboard contains the asserted strings. Tests claim coverage they don't have.

Two acceptable resolutions; pick **(b)** if P1-1 lands first, otherwise **(a)**:

- **(a) Convert to dashboard-only smoke tests.** Reduce the file to a single `roadmap dashboard page loads` test. Delete the four redirect-pretending tests and add a comment explaining they were removed because the deep-link pages are not yet built.
- **(b) Strengthen the four detail tests** (preferred, after P1-1) to assert on a unique element only present on the new page (e.g. `data-testid="initiative-register-table"`, `data-testid="suggestion-backlog-table"`, etc.).

**Files to change:**
- [tests/Browser/Roadmap/RoadmapTest.php](tests/Browser/Roadmap/RoadmapTest.php)

**Acceptance criteria:**
- Dusk file under 30 lines OR each test asserts on a `data-testid` that is unique to its page.
- `php artisan dusk --filter=RoadmapTest` passes.

#### P0-3. Surface Roadmap in the sidebar

**Why:** Module is invisible. Permissioned users have no in-app path to `/roadmap/dashboard`.

**Files to change:**
- [resources/js/components/app-sidebar.tsx](resources/js/components/app-sidebar.tsx) — add a top-level group **or** a Governance sub-panel entry guarded by `can?.roadmap?.view`.

Suggested placement (Governance sub-panel, parallel with existing governance items):

```tsx
if (can?.roadmap?.view) {
    governance.push({
        title: 'Roadmap',
        href: '/roadmap/dashboard',
        icon: ClipboardList, // or Map, Compass — matches dashboard icons
    });
}
```

**Acceptance criteria:**
- A user with role `roadmap_manager`, `board_member`, `ceo`, or `cfo` sees a "Roadmap" entry under Governance in the left sidebar.
- A `support_worker` does not see the entry.
- Add a Pest assertion in [tests/Feature/Roadmap/RoadmapDashboardPageTest.php](tests/Feature/Roadmap/RoadmapDashboardPageTest.php) verifying the `auth.can.roadmap.view` prop is `true` for permitted roles (already covered indirectly).

---

### P1 — UX / handoff polish (production-readiness)

#### P1-1. Convert the four Roadmap detail routes to dual-mode (Inertia + JSON)

**Why:** This is the single largest "thin Roadmap UX" issue. Every detail surface is API-only; users can only manipulate them through dashboard dialogs limited to 5–8 rows.

**Approach:** Keep the existing JSON contracts (used by the Dashboard's axios calls) intact. Add an **Inertia render branch** before each `shouldReturnJson` check, and create four small React pages backed by the same controller actions.

**Files to change:**
- [app/Domain/Roadmap/Http/Controllers/InitiativeController.php](app/Domain/Roadmap/Http/Controllers/InitiativeController.php) — replace the `if (! $this->shouldReturnJson($request)) { redirect(...) }` shortcut in `index()` with an `Inertia::render('Roadmap/Initiatives/Index', [...])` branch that returns the same shape as the JSON payload (paginator + filters).
- [app/Domain/Roadmap/Http/Controllers/SuggestionController.php](app/Domain/Roadmap/Http/Controllers/SuggestionController.php) — same pattern → `Roadmap/Suggestions/Index`.
- [app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php](app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php) — same for `index()` (`Roadmap/QuarterlyPlans/Index`) and `show()` (`Roadmap/QuarterlyPlans/Show`).
- [app/Domain/Roadmap/Http/Controllers/DecisionRequestController.php](app/Domain/Roadmap/Http/Controllers/DecisionRequestController.php) — same → `Roadmap/Decisions/Index`.
- New React pages (each ~150-250 lines, modelled on `operations/reports/Shifts.tsx` table-driven pattern):
  - `resources/js/pages/Roadmap/Initiatives/Index.tsx` — filterable table (status, stream, fiscal year/quarter), pagination, link to dashboard for KPI overview, `data-testid="initiative-register-table"`.
  - `resources/js/pages/Roadmap/Suggestions/Index.tsx` — full triage backlog with status filter (default `triage_pending`), source filter, columns for assignee/notes; reuses the dashboard's `triageSuggestion`/`assignSuggestionOwner`/`saveSuggestionNotes` axios calls.
  - `resources/js/pages/Roadmap/QuarterlyPlans/Index.tsx` — table of plans by FY/quarter/revision/status with deep-link to Show page.
  - `resources/js/pages/Roadmap/QuarterlyPlans/Show.tsx` — promoted from the dashboard's `PlanDetailTable` dialog into a full page; expose `submit-manager` / `submit-executive` / approve / publish / revise buttons gated by `can.approveRoadmap` / `can.manageRoadmap`.
  - `resources/js/pages/Roadmap/Decisions/Index.tsx` — full pending decisions queue with resolve action.

**Dashboard reuse:** Update [resources/js/pages/Roadmap/Dashboard.tsx](resources/js/pages/Roadmap/Dashboard.tsx) — keep the inline tables for executive overview but add "View all initiatives →", "Open triage backlog →", "Quarterly plan history →", "All pending decisions →" Inertia `<Link>`s into the per-card headers. Lower the dashboard's `per_page` axios calls to ~5 (it already loads 5–8) and let the new pages handle the rest.

**Acceptance criteria:**
- Visiting `/roadmap/initiatives` in a browser renders `Roadmap/Initiatives/Index` (Inertia page), not a redirect.
- Existing AJAX calls from the dashboard (`Accept: application/json`) still receive the unchanged JSON payload — verified by re-running `RoadmapDashboardPageTest::test_dashboard_still_returns_json_for_api_calls`.
- All four new pages have a unique `data-testid` so the rebuilt Dusk tests in P0-2(b) can target them.
- A new Pest test per page asserting Inertia component name + key props (modelled on [tests/Feature/Roadmap/RoadmapDashboardPageTest.php](tests/Feature/Roadmap/RoadmapDashboardPageTest.php)).

#### P1-2. Resolve the duplicate `/reports/shifts` legacy surface

**Why:** Sidebar exposes both surfaces; users following the Reports menu hit the thin legacy view.

**Approach:** Treat the legacy URL as a permanent compatibility redirect to the modern surface; remove the orphan page + sidebar entry.

**Files to change:**
- [routes/reports.php:24](routes/reports.php) — replace the controller call with `Route::redirect('/reports/shifts', '/operations/reports/shifts', 301)->name('reports.shifts');` (preserves any external bookmarks).
- [resources/js/components/app-sidebar.tsx:1798-1803](resources/js/components/app-sidebar.tsx) — remove the "Shift Reports → /reports/shifts" entry from the Reports sub-panel; keep "Operations Reports → /operations/reports".
- [app/Http/Controllers/ShiftReportsController.php](app/Http/Controllers/ShiftReportsController.php) — delete the file (no other route uses it; verified by grep).
- [resources/js/pages/reports/shifts.tsx](resources/js/pages/reports/shifts.tsx) — delete the file (no other reference).

**Acceptance criteria:**
- `curl -I /reports/shifts` returns `301` to `/operations/reports/shifts`.
- The sidebar Reports sub-panel shows exactly one shift-related entry.
- `grep -rn ShiftReportsController` returns no matches outside `routes/web.php.backup` (acceptable — backup file).
- Build passes (no broken imports for the deleted page).

#### P1-3. Add cross-area handoff: rostering ↔ reports ↔ roadmap

**Why:** Rostering managers cannot see the downstream operational risk story without manually navigating. Coverage gaps and chronic shortages are exactly the recurring pain Roadmap should ingest.

**A) Surface Reports from the rostering scheduler**

- [resources/js/pages/operations/rostering/index.tsx](resources/js/pages/operations/rostering/index.tsx) — add a header action button "View Operations Reports" → `/operations/reports/shifts` (visible when `can?.operations?.reports?.view`).
- [resources/js/pages/operations/rostering/publish/Review.tsx](resources/js/pages/operations/rostering/publish/Review.tsx) — after publish-confirm success, append a toast or success card linking to `/operations/reports/shifts?date_from=<period_start>&date_to=<period_end>`.

**B) Surface Roadmap escalation from reports**

- [resources/js/pages/operations/reports/Shifts.tsx](resources/js/pages/operations/reports/Shifts.tsx) — when `coverage.chronic_shortage_count > 0` and `can?.roadmap?.manage`, add a "Raise Roadmap Initiative" link in the Coverage / Gap Report card that opens `/roadmap/dashboard#quick-add` (or, after P1-1, `/roadmap/initiatives?source=rostering`). No backend wiring required initially — the Quick-Add card on the dashboard already accepts free-form titles.

**C) (Optional, defer to P2) Add a `rostering` source to RoadmapSuggestionService**

Not required for production-readiness, but called out so it's not invented later: `RoadmapSuggestionService::ingestAll` already supports `incidents` and `assets`. A `rostering` ingester would convert chronic-shortage rows from `ShiftReportingService::buildChronicShortageRows` into deduped `InitiativeSuggestion` rows. **Out of scope for this plan.**

**Files to change:**
- `resources/js/pages/operations/rostering/index.tsx`
- `resources/js/pages/operations/rostering/publish/Review.tsx`
- `resources/js/pages/operations/reports/Shifts.tsx`

**Acceptance criteria:**
- Manager navigates rostering → clicks header link → lands on Operations Reports filtered to current week.
- Manager publishes a roster period → success toast offers a link to the just-published period's Shift Operations report.
- Roadmap Manager opens Operations Reports with chronic shortages > 0 → sees a "Raise Roadmap Initiative" call-to-action; Reports user without `roadmap.manage` does not.
- A Playwright spec extending the rostering publish e2e to follow the toast link asserts the Shifts report page renders.

#### P1-4. Tighten the generic `Show.tsx` for the six secondary reports

**Why:** `Show.tsx` works but is unloved — date filter only, no charts, no per-report context, no column ordering. For production, the polish gap relative to `Shifts.tsx` is conspicuous.

**Approach:** Minimal targeted improvements; do not rewrite.

- Add per-type optional filters driven from `report_meta`:
  - `client-summary`, `service-hours`, `compliance`: client picker.
  - `staff-utilisation`, `shift-analytics`: staff picker.
  - Reuse the same fetch pattern as `Shifts.tsx` (server-rendered options, `router.get` on apply).
- Add a single `recharts` bar/line chart (the package is already installed at `recharts ^3.7.0`) for the obvious chart: `billing.by_status` (bar), `staff-utilisation.by_staff` (bar), `shift-analytics.by_day_of_week` (bar). Skip charts when data is empty.
- Improve the empty-state copy (current generic empty message is fine but can name the report).
- Add aria labels and table captions for screen readers (the plan area has an a11y push elsewhere; keep this minimal).

**Files to change:**
- [resources/js/pages/operations/reports/Show.tsx](resources/js/pages/operations/reports/Show.tsx) — add optional `clients` / `staff` props, conditional filter rendering, a small `<ReportChart>` helper.
- [app/Http/Controllers/Operations/ReportController.php](app/Http/Controllers/Operations/ReportController.php) — pass `clients` / `staff` props (site-scoped via `UserSiteAccessService` like `ShiftReportController` does) only for report types that use them; switch the existing date defaults to honour the same `now()->startOfMonth()`/`endOfMonth()` ISO format `Show.tsx` already accepts.

**Acceptance criteria:**
- Visiting `/operations/reports/billing` shows a stacked-bar chart of `by_status` plus the existing tables.
- Visiting `/operations/reports/client-summary?client_id=N` filters all sections to client N.
- New Pest tests in [tests/Feature/Operations/](tests/Feature/Operations/) (`ReportControllerShowTest.php` — new file) cover at least: each of the six report types renders 200 + correct Inertia component, client/staff filters apply, unauthorised user gets 403, unknown type 404.

---

### P2 — Deferred polish (nice-to-have, not blocking production)

#### P2-1. Playwright e2e for Operations Reports

- New spec [tests/e2e/operations-reports.spec.ts](tests/e2e/operations-reports.spec.ts):
  - Login as scheduler manager.
  - Visit `/operations/reports`, click "Shift Operations" tile, assert filters render, change date range, click "Apply Filters", assert summary cards update, click "Export Risk Summary CSV", assert response 200 + content-type.
  - Visit `/operations/reports/billing`, assert chart + table render.
- Reuse the rostering e2e helpers (`tests/e2e/helpers.ts`).
- Skip on mobile viewports (reports are scheduler/manager surfaces, same as the rostering e2e suite).

#### P2-2. Dashboard refresh cadence + caching

The Dashboard's `useEffect` runs `refreshAll()` (3 axios calls) on mount, on `selectedPlanId` change, and on every Refresh click. For tenants with hundreds of suggestions/plans, consider:
- Adding `staleTime` if Tanstack Query is used elsewhere (verify).
- Server-side caching the `RoadmapDashboardService::governanceWidget` payload for 60s scoped per tenant.

Out of scope unless dashboard load times degrade in production.

#### P2-3. Permission key consolidation

Decide on `operations.reports.view` as the canonical key for new code; keep `reports.viewAny` as a documented "global reports bypass" for super-users (used in `ShiftReportController::$bypassPermissions`). Update [docs/architecture](docs/architecture) (or whatever the perm doc is) to note the distinction. No code change beyond docs unless a discrepancy is found.

---

## 5. Test & Verification Commands

Run these (in order) after each task lands.

**PHP feature tests (fast):**
```bash
php artisan test --filter=ShiftReportControllerTest                      # P1-2 verification
php artisan test --filter=RoadmapDashboardPageTest                       # P0-3 verification
php artisan test --filter=RoadmapPermissionsTest                         # P0-3 verification
php artisan test --filter=RoadmapWorkflowTest                            # P0-1 verification
php artisan test --filter=RoadmapReportsApiTest                          # P0-1 verification
php artisan test --filter=RoadmapDecisionAndBudgetApiTest
php artisan test --testsuite=Feature --group=roadmap                     # whole module
php artisan test --filter=ReportControllerShowTest                       # NEW, after P1-4
```

**Route smoke:**
```bash
php artisan route:list --path=roadmap | grep -E "submit-manager|submit-executive|initiatives|suggestions|quarterly-plans|decisions"
php artisan route:list --path=operations/reports
php artisan route:list --path=reports/shifts                             # should show 301 redirect after P1-2
```

**Dusk browser tests:**
```bash
php artisan dusk --filter=RoadmapTest                                    # P0-2 verification
```

**Playwright e2e (after P2-1):**
```bash
npx playwright test tests/e2e/operations-reports.spec.ts
npx playwright test tests/e2e/operations-rostering-publish.spec.ts       # extended in P1-3
```

**Type / lint / build (always):**
```bash
npm run lint
npm run typecheck
npm run build
```

**Manual smoke (after each P0/P1 lands):**
- As `roadmap_manager`: sidebar shows "Roadmap" → click → Dashboard renders.
- As `roadmap_manager`: visit `/roadmap/initiatives` directly → renders the Initiatives Index page (after P1-1), not a redirect.
- As `manager_with_operations.reports.view`: sidebar Reports sub-panel shows only "Operations Reports".
- As `manager_with_operations.reports.view`: rostering header has "View Operations Reports" link; publish review success toast deep-links into Shifts report.
- As `support_worker`: cannot see Roadmap entry; `/roadmap/dashboard` returns 403; `/operations/reports` returns 403.

---

## 6. What NOT to Change

These surfaces are working and any changes here are out of scope for this plan:

- **`app/Services/Operations/ShiftReportingService.php`** — heavy decision-grade backend; CSV export contract is stable and tested. Don't restructure.
- **`app/Services/Operations/ReportingService.php`** — covers `shiftAnalytics` and `complianceReport` cleanly. Don't refactor.
- **`app/Http/Controllers/Operations/ReportController.php` `REPORT_TYPES` constant + per-type method dispatch** — the design is fine. P1-4 only adds props to existing methods.
- **Roadmap domain layer** (`app/Domain/Roadmap/{Models,Services,Jobs,Events,Policies}/*`) — mature; only the controller's `shouldReturnJson` short-circuit is touched (P1-1).
- **Roadmap Dashboard.tsx** as the executive overview shell — keep KPI cards, control center, "How To Run" copy, and the Quick-Add forms. Just relieve it of being the *only* surface (P1-1 adds peer pages and inserts "view all" links into card headers).
- **Existing CSV dataset enums** (`staff-utilisation`, `coverage-gaps`, `reconciliation`, `attendance-variance`, `risk-summary`) — used by tests; do not rename.
- **Permission keys** (`operations.reports.view`, `roadmap.*`) — seeded and exposed via Inertia; do not rename. P2-3 only consolidates docs.
- **`auth.can` shape** in `HandleInertiaRequests.php` — the Roadmap Dashboard tests assert `auth.can.roadmap` exists; do not rename.
- **`routes/web.php.backup`** — leave as-is (legacy reference file, not loaded).
- **No new modules**, no new Roadmap "v2", no new reports engine. The audit confirmed this is "Partial", not "Broken-by-design".
- **No new charts library** — `recharts ^3.7.0` is installed; use it for P1-4 if needed.
- **No data-model migrations** — this is a routing / UX / test plan.

---

## 7. Risks & Unknowns (Need Fresh Verification)

| Risk | Why uncertain | Mitigation |
|---|---|---|
| **`submit-manager` / `submit-executive` tests may already be skipped or marked as expected-fail** in CI. | Plan inferred from grep alone; haven't run the suite. | Run `php artisan test --filter=RoadmapWorkflowTest` first; if green, drop P0-1 to a P1. |
| **Roadmap Dusk tests might require a `dusk` env or seed not present** in the dev box. | Not all repos run Dusk reliably on every developer's machine. | Run `php artisan dusk --filter=RoadmapTest` and capture the actual current pass/fail. The plan's "false positive" diagnosis assumes they currently pass. |
| **The Dashboard axios calls to `/roadmap/initiatives?per_page=8` etc. assume JSON path.** After P1-1 introduces an Inertia branch, the request must keep its `Accept: application/json` header. | Existing test [RoadmapDashboardPageTest::test_dashboard_still_returns_json_for_api_calls](tests/Feature/Roadmap/RoadmapDashboardPageTest.php) covers this for the dashboard endpoint, but not for the four detail endpoints. | Add equivalent JSON-contract tests to each detail endpoint as part of P1-1; the controllers' existing `expectsJson()` check is intact. |
| **Sidebar placement (Governance vs new top-level Roadmap entry)** — depends on internal IA. | The Dashboard breadcrumbs Governance > Roadmap, suggesting Roadmap "belongs to" Governance. But CEO/CFO users may expect it under a top-level "Strategy" or "Planning" group. | P0-3 proposes Governance sub-panel as the safe default; if product feedback wants top-level, it's a one-line move. |
| **`recharts ^3.7.0` major version compatibility.** | Other pages may use a v2 import shape. | Before P1-4, `grep -rn "from 'recharts'" resources/js/pages` to confirm at least one example exists; mirror its import style. |
| **Legacy bookmarks for `/reports/shifts`.** | Unknown if any external links / saved searches use it. | P1-2 keeps the URL alive as a 301 redirect, so bookmarks survive. |
| **Whether any background jobs reference `/reports/shifts` or `ShiftReportsController`.** | Unlikely but worth a grep. | Re-run `grep -rn "ShiftReportsController\|reports.shifts" app/ database/` before deleting the controller in P1-2. |
| **Dashboard "How To Run The Quarterly Workflow" copy lists 6 steps**, two of which (`submit-manager` / `submit-executive`) are not currently bound to anything in the UI. After P0-1, the dashboard should expose buttons to call them. | UX gap, not a bug. | Add a "Submit for manager review" / "Submit for executive review" button to the Plan dialog (and the new Show page from P1-1) gated by status `draft`/`manager_review`. |

---

## 8. Production-Readiness Checklist

The area is production-ready when **all** of the following are true:

### Reports

- [ ] `/operations/reports` tile index renders for users with `operations.reports.view`; shows seven tiles; each tile is clickable and lands on its target page (`Shift Operations`, `Client Summary`, `Staff Utilisation`, `Shift Analytics`, `Billing`, `Compliance`, `Service Hours`).
- [ ] `/operations/reports/shifts` renders the decision-grade Shift Operations page with Risk Summary, Staff Utilisation, Coverage / Gap, Reconciliation, Attendance Variance.
- [ ] Each of the five Shift Operations CSV exports (`risk-summary`, `staff-utilisation`, `coverage-gaps`, `reconciliation`, `attendance-variance`) downloads with correct headers and a non-empty body when data exists.
- [ ] Each of the six secondary reports (after P1-4) supports its relevant filters (date always; client_id / staff_id where applicable) and renders at least one chart when data exists.
- [ ] Site scoping via `UserSiteAccessService` is honoured for both index and CSV export — verified by `ShiftReportControllerTest::test_shift_report_filters_by_date_and_site` and a new equivalent for `Show.tsx`.
- [ ] Legacy `/reports/shifts` redirects 301 to `/operations/reports/shifts`; the old controller and React page are deleted; sidebar references the modern URL only.
- [ ] One Playwright e2e exercises the full Reports flow end-to-end.

### Roadmap

- [ ] Sidebar exposes "Roadmap" for users with `roadmap.view`; hidden for users without.
- [ ] `/roadmap/dashboard` renders the executive overview with KPIs, Quarterly Planning Control Center, plan list, triage inbox, decisions queue, and "View all →" links into the four new detail pages.
- [ ] `/roadmap/initiatives` renders an Inertia Initiatives Register page (table + filters + pagination), **not** a dashboard redirect.
- [ ] `/roadmap/suggestions` renders the full triage backlog, with assign / notes / convert actions parity with the dashboard dialog.
- [ ] `/roadmap/quarterly-plans` renders the plan history; `/roadmap/quarterly-plans/{plan}` renders the plan detail page with submit-manager / submit-executive / approve / publish / revise actions gated by permission and current `status`.
- [ ] `/roadmap/decisions` renders the full pending decisions queue with resolve action.
- [ ] `submit-manager` and `submit-executive` URIs are routed and return 200 in the workflow tests.
- [ ] All Dusk browser tests target unique `data-testid`s, not redirect-target text.
- [ ] All existing JSON contracts (`Accept: application/json`) on the four detail endpoints are preserved (verified by new Pest tests).

### Cross-area

- [ ] Rostering scheduler header and publish-review success exposes Operations Reports.
- [ ] Operations Reports Coverage / Gap card exposes "Raise Roadmap Initiative" when chronic shortages exist and the user has `roadmap.manage`.

### Tests & Quality

- [ ] `php artisan test --testsuite=Feature` is green for the Operations\Report*, Roadmap*, and any new tests added under P1-1 / P1-4.
- [ ] `php artisan dusk --filter=RoadmapTest` is green and the assertions are non-trivial.
- [ ] At least one Playwright e2e covers the Reports flow (P2-1).
- [ ] `npm run typecheck` and `npm run lint` are green.
- [ ] No console warnings on `/operations/reports`, `/operations/reports/shifts`, or any of the four new Roadmap pages (verified manually or via `expectNoConsoleErrors` in the new spec).

### Permissions & Discoverability

- [ ] Support workers cannot reach `/operations/reports` or `/roadmap/*`; both redirect or 403.
- [ ] Board members can reach `/roadmap/dashboard` and `/roadmap/decisions` but not the manage-only mutating actions.
- [ ] Roadmap managers can quick-add initiatives, generate plans, triage suggestions, and submit plans for review through the new pages.
- [ ] CEO / CFO can approve and publish plans through the new Show page actions.

When this checklist is complete, the Rostering → Reports → Roadmap surface meets production-readiness for an early-stage NZ Supported Living deployment.
