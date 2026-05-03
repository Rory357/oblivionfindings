# Rostering — Docs / Tests / Playwright Production-Readiness Plan

**Status:** Planning — implementation not started
**Date:** 2026-05-03
**Scope:** documentation truth-up + targeted Playwright coverage + measured Dusk → Playwright migration. **Not** a rewrite of the rostering module, the test stack, or the architecture. The product code is in good shape; this plan tightens the artefacts around it.

> Reference doc only. No code changes ship from this file. The implementation
> handoff in §7 is what GPT-5.5 / Codex will execute later.

---

## TL;DR

Rostering / shifts product code, route surface, and PHPUnit-Pest coverage are in **better shape** than the surface-level artefacts suggest. The two visible "production-ready" failures are:

1. The headline docs (`README.md`, `docs/TEST_SUITE_SUMMARY.md`) are **stale by an order of magnitude** and undersell the actual coverage — a new contributor reading them would form a wrong mental model of the project.
2. Coverage of rostering's manager-side **publish / suggestions / template-apply / frontline-visibility** flows lives almost entirely in Playwright; the parallel Dusk tree under `tests/Browser/Shifts` is **page-load smoke only**. There is no measured plan to retire Dusk.

Both are documentation / test-architecture gaps, not product defects. Neither requires touching production code. Estimated total effort: **1.5–3 dev-days** to take this surface to "production-ready" without redesigning anything.

---

## 1. Current-state evidence

### 1.1 Documentation truth audit

#### Stale docs (must update)

| File | Live state | Evidence | Why it's stale |
|---|---|---|---|
| [README.md](../README.md) | 18 bytes — single line `# oblivionfindings` | `stat README.md` → `Modify: 2026-02-09` | No install / dev / test instructions. Contradicts every readiness doc that exists. A new dev cannot bootstrap the repo from here. |
| [docs/TEST_SUITE_SUMMARY.md](TEST_SUITE_SUMMARY.md) | Dated `2026-02-04`. Header: `Total Test Files: 15`, `Total Tests: 140+`. Lists six controller test files. | `stat docs/TEST_SUITE_SUMMARY.md` → `Modify: 2026-02-09`. Live count is **268 Feature + 65 Unit + 1 Integration = 334 PHP test files**, plus 106 Dusk + 24 Playwright e2e + 1 visual spec. | Lists "Recommended Additional Tests" for medication, asset, fleet, control room, safeguarding, privacy — **all of which now exist** under `tests/Feature/Emar`, `tests/Feature/SecurityDevices`, `tests/Feature/ControlRoom`, `tests/Feature/Safeguarding`, `tests/Feature/Privacy`. Doc never mentions Playwright or Dusk. |

#### Time-anchored docs (accurate as historical snapshots; should be marked as such, not deleted)

| File | What it claims | Verdict |
|---|---|---|
| [docs/rostering-pr-map.md](rostering-pr-map.md) | Reverse-maps commit `46ee7ba0` (rostering production-readiness) to its 9 PR boundaries. "Test results" table reports `Pest backend: ✅ 21/21 pass`. | **Accurate as a snapshot of that commit's verification run.** A reader could mistake "21/21" for the *current* test count. Add a banner clarifying it's a commit-time snapshot. |
| [docs/rostering-test-fixes-plan.md](rostering-test-fixes-plan.md) | 4 PRs (T-1..T-4) to take rostering Playwright suite from 3/10 to 10/10. | **Partially executed** in subsequent commits — see §1.2. Plan should be marked "in-progress" with the applied PRs ticked off. |

#### Live docs (accurate; do not rewrite)

These are well-maintained and were the reference points during this audit. **Do not rewrite.**

- `docs/architecture/shifts-module-map.md` — header `Last verified from code: 2026-04-28`, `mtime 2026-05-01`. Route inventory matches `routes/operations.php` (verified) and `routes/shifts.php` (verified).
- `docs/architecture/shifts-route-deprecation.md` — `Last verified from code: 2026-04-29`. Matches the `LegacyRouteRedirectController` block in `routes/shifts.php`.
- `docs/architecture/shifts-frontend-routes.md`, `shifts-contracts.md`, `shifts-lifecycle.md`, `shifts-lifecycle.mmd`, `reports-permissions.md`.
- `docs/route-ownership.md`.
- Recent module readiness plans — [`rostering-clients-care-readiness-plan.md`](rostering-clients-care-readiness-plan.md), [`rostering-portal-respite-readiness-plan.md`](rostering-portal-respite-readiness-plan.md), [`rostering-hr-staff-training-readiness-plan.md`](rostering-hr-staff-training-readiness-plan.md), [`rostering-sites-coverage-readiness-plan.md`](rostering-sites-coverage-readiness-plan.md), [`rostering-reports-roadmap-readiness-plan.md`](rostering-reports-roadmap-readiness-plan.md).

### 1.2 Confirmed status of the `rostering-test-fixes-plan.md` follow-ups

Verified against the current tree on 2026-05-03 — these are findings, not assertions:

| PR from that plan | Status (live evidence) |
|---|---|
| **T-2** environment-aware perf baseline | **Applied.** [`tests/e2e/performance/rostering-dashboard-baseline.json`](../tests/e2e/performance/rostering-dashboard-baseline.json) is `{"dashboard_p95_ms": {"default": 12000, "php_builtin": 20000}}`. [`tests/e2e/operations-rostering-performance.spec.ts:23-54`](../tests/e2e/operations-rostering-performance.spec.ts) reads `PLAYWRIGHT_BASELINE_ENV`. [`playwright.config.ts:4-8`](../playwright.config.ts) exports `PLAYWRIGHT_BASELINE_ENV = ci ? 'default' : 'php_builtin'`. |
| **T-3** Playwright env injection for feature flags | **Applied.** [`playwright.config.ts:9-10`](../playwright.config.ts) sets `process.env.FEATURE_ROSTERING_PUBLISH ??= 'true'` and `FEATURE_ROSTERING_AUTO_SCHEDULE ??= 'true'`; [`webServer.env: webServerEnv`](../playwright.config.ts:64) propagates them to the spawned `php -S` server. |
| **T-1** sidebar contrast (`oklch(0.40 …)` → darker) | **Not verified by inspection alone.** This plan does not require it; the `operations-rostering-a11y.spec.ts` exit code under the current CSS will tell us. Out of scope here. |
| **T-4** seeder eligibility for suggestion fixture | **Not verified by inspection alone.** Run `operations-rostering-suggestions.spec.ts` to confirm. Out of scope here. |

Net: the perf/flag plumbing is in place. Whether the spec suite is currently 10/10 green is a state we **do not assert** in this plan — verifying it is part of §6.

### 1.3 Live test inventory (commands shown)

All counts are from the current working tree. Commands provided in §6 so any reviewer can re-run them.

#### Backend PHP tests (PHPUnit / Pest)

```
tests/Feature      — 268 *.php files
tests/Integration  — 1   *.php file (Infrastructure/)
tests/Unit         — 65  *.php files
                     ───
                     334 PHP test files total
```

`phpunit.xml` declares three suites: `Unit`, `Integration`, `Feature` (lines 8–16). Test DB is **MySQL** (`oblivion_findings_codex_test`), not SQLite.

Notable Rostering-specific PHP coverage that contradicts the stale `TEST_SUITE_SUMMARY.md`:

- [tests/Feature/RosterControllerTest.php](../tests/Feature/RosterControllerTest.php) — 5 tests covering frontline visibility (publish flag on/off, per-org override, `my-roster.data` JSON, `my-calendar.events` JSON).
- `tests/Feature/Rostering/`:
  - `AutoScheduleQueueTest.php`, `RosterPublishingTest.php`, `SuggestionApplierTest.php`, `TemplateApplyTest.php`.
  - `Integration/`: `ArchivePeriodCronTest.php`, `PublishedShiftCancellationTest.php`, `PublishedShiftCompletionTest.php`, `PublishedShiftPayrollLockTest.php`, `RepublishWithApprovedTimesheetTest.php` — these lock in the contract guardrails (Guardrail 7 etc.) listed in [`docs/rostering-pr-map.md`](rostering-pr-map.md).
- `tests/Unit/Rostering/EligibilityScoringStrategyTest.php`, `RosterSuggestionContextTest.php`.
- `tests/Unit/Shifts/Lifecycle/ShiftLifecycleServiceTest.php`, `tests/Unit/Shifts/Timesheets/TimesheetApprovalServiceTest.php`.
- `tests/Feature/Routing/RosteringRouteOwnershipTest.php`, `LegacyShiftNamesRemovedTest.php`, `LegacyShiftWriteRedirectsTest.php`, `ShiftLegacyRedirectTest.php`, `AttendanceCanonicalTest.php`, `AllNamedRoutesResolveTest.php`, `CanonicalPermissionMatrixTest.php` — 9 routing/contract tests.
- `tests/Feature/ShiftCancellationCascadeTest.php`, `ShiftLifecycleHardeningTest.php`, `ShiftSafetyNetTest.php`, `ShiftControlRoomSignalPipelineTest.php`, `ShiftControllerTest.php`.
- `tests/Feature/Hr/AttendanceClockWorkflowTest.php` and 4 attendance-domain tests at the root of `tests/Feature/`.
- `tests/Feature/Operations/ShiftReportControllerTest.php`, `ShiftHandoverWorkflowTest.php`, `ShiftSiteIsolationTest.php`, `ClientCareControllerTest.php`, `ReportControllerShowTest.php`.

#### Browser (Dusk) tests

```
tests/Browser — 106 *.php files (Pages/, Auth/, Assets/, Calendar/, Careers/, Clients/,
  Compliance/, Emar/, Finance/, Fleet/, Frontline/, Governance/, HealthSafety/, Hr/,
  Incidents/, Medications/, Misc/, Notifications/, Operations/, Portal/, Privacy/,
  Reports/, Roadmap/, Respite/, Safeguarding/, Settings/, Shifts/, Sites/, Staff/,
  Summaries/, System/, Training/, plus DashboardTest, ExampleTest, HomeTest at root)
```

The `tests/Browser/Shifts/` directory contains **3 files / 7 tests**:

- `ShiftIndexTest.php` — `'shifts index page loads'`, `'shifts create page loads'`, `'rostering page loads'` — all 3 just `loginAs(admin@test.com) → visit() → waitForText() → assertPathIs(canonical)`. **No state changes, no assertions on shift content.**
- `ShiftTimesheetsTest.php` — same shape, 3 tests for `/timesheets`, `/timesheets/approvals`, `/timesheets/create`.
- `AttendanceTest.php` — 1 test, just visits `/attendance` and asserts the page renders.

`tests/Browser/Frontline/FrontlineStaffUxTest.php` is the only Frontline file and is similarly thin (70 lines).

The non-Shifts Dusk dirs (Hr/, Settings/, Finance/, Governance/, etc.) contain richer interaction tests and are **not** the topic of this plan.

#### Playwright tests

```
tests/e2e/                       — 24 *.spec.ts files (~2,608 lines total)
tests/visual/app-shell.spec.ts   — 1 file, parameterised over 12 pages × 2 viewports
tests/__screenshots__/           — 24 PNG baselines (chromium-desktop + chromium-mobile)
tests/visual/__screenshots__/    — 22 PNG baselines (one fewer page per project)
tests/e2e/helpers.ts             — login helpers, console-error collector,
                                   resetMedicationReadinessFixtures(),
                                   seedGovernancePrivacyConsentsReadinessFixtures()
tests/e2e/global-setup.ts        — moves public/hot to .hot.playwright.bak before tests
tests/e2e/global-teardown.ts     — restores public/hot after tests
tests/e2e/rostering-flags.ts     — rosteringFlagsEnabled / rosteringFlagSkipReason
tests/e2e/performance/rostering-dashboard-baseline.json — env-aware perf budget
tests/fixtures/payroll/2026-04-baseline.csv             — payroll fixture
tests/fixtures/routing/shift-permission-matrix.php      — routing fixture
```

The 24 e2e specs by surface:

| Surface | Specs |
|---|---|
| Rostering / publish / suggestions | `operations-rostering-publish`, `operations-rostering-suggestions`, `operations-rostering-a11y`, `operations-rostering-performance`, `template-apply-conflict`, `frontline-published-visibility` |
| My Day / Frontline | `my-day-a11y`, `my-day-end-of-shift`, `my-day-lifecycle-smoke`, `my-day-pre-shift-briefing`, `my-day-returned-timesheet`, `my-roster-week-grid` |
| Operations clients / care | `operations-clients-care`, `operations-clients-consent-requests`, `operations-reports`, `operations-route-canonicalization` |
| Control Room | `control-room-smoke`, `control-room-dashboard`, `control-room-alert-lifecycle` |
| Other readiness | `attendance-readiness`, `incident-create`, `meds-readiness`, `job-board-readiness`, `privacy-dsr-and-breach-lifecycle` |

#### Test infrastructure (separately listed per request)

- **PHPUnit/Pest support**: `tests/TestCase.php`, `tests/Pest.php`, `tests/Support/` (helpers + datasets).
- **Dusk support**: `tests/DuskTestCase.php`, `database/seeders/DuskDatabaseSeeder.php`.
- **Playwright support**: `tests/e2e/helpers.ts`, `global-setup.ts`, `global-teardown.ts`, `rostering-flags.ts`, `server.php` at repo root (PHP built-in static-file router used by `webServer.command`).
- **CI**: [`.github/workflows/tests.yml`](../.github/workflows/tests.yml) runs `./vendor/bin/pest` only. [`.github/workflows/visual.yml`](../.github/workflows/visual.yml) runs Playwright (`npm run visual:test`) with `FEATURE_ROSTERING_PUBLISH=true` and `FEATURE_ROSTERING_AUTO_SCHEDULE=true`.

### 1.4 Dusk vs Playwright coverage map (rostering / shifts / frontline)

| Flow | Dusk coverage | Playwright coverage | Net |
|---|---|---|---|
| `/operations/shifts` index/create/edit/show | `ShiftIndexTest` smokes only (page-loads + redirect-from-`/shifts`) | None directly; covered indirectly by `my-day-lifecycle-smoke`, `operations-clients-care` | **Mostly Playwright**. Dusk only proves the page renders; no assertions on shift content, lifecycle, or assign/unassign. |
| Shift lifecycle (start, complete, cancel, reopen) | None | `my-day-end-of-shift`, `my-day-lifecycle-smoke` | **Playwright-only.** Backend covered by `tests/Feature/ShiftLifecycleHardeningTest.php`, `tests/Unit/Shifts/Lifecycle/ShiftLifecycleServiceTest.php`. |
| Rostering grid (manager `/operations/rostering`) | None | `operations-rostering-publish`, `operations-rostering-a11y`, `operations-rostering-performance` | **Playwright-only.** |
| Roster publish: review → confirm → republish → unpublish → diff | None | `operations-rostering-publish` | **Playwright-only**, gated by `rosteringFlagsEnabled`. Backend: `RosterPublishingTest`, `Integration/RepublishWithApprovedTimesheetTest`. |
| Roster suggestions / auto-schedule (apply, dismiss, applyAccepted) | None | `operations-rostering-suggestions` | **Playwright-only.** Backend: `AutoScheduleQueueTest`, `SuggestionApplierTest`, Unit `EligibilityScoringStrategyTest`, `RosterSuggestionContextTest`. |
| Template apply with conflict pre-flight | None | `template-apply-conflict` | **Playwright-only.** Backend: `TemplateApplyTest`. |
| Frontline published-only visibility | None | `frontline-published-visibility` | **Playwright-only.** Backend: `RosterControllerTest` (5 tests). |
| `/my-day` frontline | `tests/Browser/Pages/TodayTest.php`, `Frontline/FrontlineStaffUxTest.php` (thin) | `my-day-a11y`, `my-day-end-of-shift`, `my-day-lifecycle-smoke`, `my-day-pre-shift-briefing`, `my-day-returned-timesheet` | **Playwright is canonical.** Dusk equivalents are page-loads. |
| `/my-roster` week grid | None | `my-roster-week-grid` (desktop-only) | **Playwright-only.** |
| Attendance / clock-in / clock-out / break / handover | `AttendanceTest` (page-loads only) | `attendance-readiness` | Playwright richer; backend covered heavily by `AttendanceClock*Test`, `AttendanceBreakTest`, `AttendanceCrossUserGuardTest`, `Hr/AttendanceClockWorkflowTest`. |
| Operations dashboard (admin/scheduler) | `Operations/OperationsTest.php` (584 lines — actually substantive) | `operations-route-canonicalization` (44 lines, smokes legacy URL → canonical 301/308) | **Mixed.** OperationsTest.php is the main rich Dusk coverage in this surface; do **not** retire without parity. |

**Conclusion:** for the rostering / shifts / frontline surfaces, **Playwright is already the canonical e2e harness**. Dusk's only meaningful coverage in these surfaces is `Operations/OperationsTest.php` and a small Frontline smoke. The rest of `tests/Browser/Shifts/` is thin redirect verification that PHPUnit `Routing/ShiftLegacyRedirectTest.php` already covers more rigorously.

### 1.5 Routes / controllers / Inertia pages — sanity check

Code paths name-checked against this plan exist (verified):

- `routes/operations.php` — rostering surfaces present at lines 577–649: `index`, `conflicts`, `coverage.{ack,dismiss,clear}`, `templates.*`, `auto_schedule`, `time_off.*`, `periods.{review,publish,republish,unpublish,diff}`, `suggestions.{show,accept,dismiss,apply,applyAccepted}`. Matches `docs/architecture/shifts-module-map.md`.
- `routes/shifts.php` — only legacy redirects + canonical `/attendance/*`. Matches `docs/architecture/shifts-route-deprecation.md`.
- `app/Domain/Rostering/` — `RosterPeriodService`, `RosterPublishingService`, `RosterPublishValidator`, `RosteringFeatureFlags`, `PeriodSnapshotter`, `AutoSchedule/{RosterSuggestionService,RosterSuggestionApplier,RosterSuggestionContext,RosterSuggestionStrategy,Strategies/}` — all present.
- `app/Domain/Shifts/Lifecycle/ShiftLifecycleService.php`, `app/Domain/Shifts/Timesheets/{TimesheetApprovalService,Drafts/}`.
- `resources/js/pages/operations/rostering/{index.tsx, conflicts.tsx, publish/{Review,Diff}.tsx, suggestions/Show.tsx, templates/{Index,Show,Create,Edit,TemplateForm}.tsx}` — all present.
- `database/seeders/RosteringProductionDemoSeeder.php`, `FrontlineLifecycleDemoSeeder.php` — both present (used by Playwright's `helpers.ts`).

No "architectural drift" was uncovered. The rostering implementation matches its docs.

---

## 2. Production-readiness gaps

P0 = blocks calling this surface "ready". P1 = should be done in the next sprint. P2 = nice-to-have.

### P0 — must do before declaring ready

| ID | Gap | Evidence | Why it's P0 |
|---|---|---|---|
| **P0-1** | `README.md` is empty | 18 bytes, single line | A repo with this much surface area cannot ship to a new contributor with no README. Onboarding cost is unbounded. |
| **P0-2** | `docs/TEST_SUITE_SUMMARY.md` understates real coverage by **~22×** (15 vs 334 PHP files; missing all Dusk and Playwright surfaces) | §1.1, §1.3 | Anyone using this doc to assess coverage will think the project is undertested. They will then propose redundant tests, or worse, agree to ship gaps that are already covered. |
| **P0-3** | `tests/Browser/Shifts/{ShiftIndex,ShiftTimesheets,Attendance}Test.php` are thin smokes that overlap with PHP `Routing/ShiftLegacyRedirectTest.php` | §1.4 | These tests currently provide false confidence — green Dusk does not mean the rostering UI works. Do **not** delete them yet (Guardrail 5). Just stop counting them as e2e coverage for the publish/suggestions/frontline flows. |

### P1 — should do in the same workstream

| ID | Gap | Evidence | Why P1 |
|---|---|---|---|
| **P1-1** | No spec asserts `/operations/rostering/conflicts` renders correctly when conflicts exist | `tests/e2e/` has `template-apply-conflict.spec.ts` but no spec for the standalone conflicts page | The conflicts page is a manager safety net documented in `rostering-sites-coverage-readiness-plan.md`. Worth one targeted spec. |
| **P1-2** | No spec covers republish or unpublish (only the first publish) | `operations-rostering-publish.spec.ts:33-46` only confirms one publish | Republish must lock when approved timesheets exist (`RosterPublishingService::unpublish` refuses; backend covered by `RepublishWithApprovedTimesheetTest`). A Playwright spec would close the manager-side loop. |
| **P1-3** | Shift detail page (`/operations/shifts/{shift}`) has zero direct e2e coverage of edit/assign/unassign actions | grep — `tests/e2e/` does not reference `/operations/shifts/` show/edit; `OperationsTest.php` references mainly the rostering grid | Backend is well-tested but the UX path managers actually use (open shift → assign someone → mark as start) is unobserved in e2e. |
| **P1-4** | `docs/rostering-pr-map.md` is read as a current-state doc but is a commit-time snapshot | §1.1 | One-paragraph header that says "this captures verification of commit `46ee7ba0`. For current state see [TEST_SUITE_SUMMARY.md]" fixes it. |
| **P1-5** | `docs/rostering-test-fixes-plan.md` doesn't reflect that PR T-2 and T-3 are applied | §1.2 | Add a "Status" column or a checkbox per PR. Five-minute change. |

### P2 — nice to have

| ID | Gap | Evidence | Why P2 |
|---|---|---|---|
| **P2-1** | Test-running quick-reference is missing | No `docs/testing.md`; commands are scattered across `composer.json`, `package.json`, plan files | Centralising the commands speeds onboarding but isn't blocking. |
| **P2-2** | Visual regression baselines (`tests/__screenshots__/`, `tests/visual/__screenshots__/`) duplicate 22 of the same images in two trees | §1.3 | Both trees are referenced by `playwright.config.ts:37` (`{testDir}/__screenshots__/...`). Worth investigating whether one tree can be consolidated; not urgent. |
| **P2-3** | No measured plan for retiring the page-load-only Dusk shifts tests | §1.4 | Plan in §3 phase 3 closes this. P2 because the tests are harmless. |

### What was specifically NOT identified as a gap

The product-side gaps that *do* exist for the rostering surface are **already** documented and tracked in their own plans:

- Coverage gap operational loop — `docs/rostering-sites-coverage-readiness-plan.md`.
- Shift → care-view workflow seam — `docs/rostering-clients-care-readiness-plan.md`.
- Respite ↔ rostering integration — `docs/rostering-portal-respite-readiness-plan.md`.
- Reports / Roadmap discoverability — `docs/rostering-reports-roadmap-readiness-plan.md`.
- HR / staff / training overlap — `docs/rostering-hr-staff-training-readiness-plan.md`.

This plan does **not** re-list those gaps. They are separate readiness plans and should be tracked there.

---

## 3. Minimal implementation plan

### Phase 1 — Documentation truth update (highest priority, lowest risk)

Goal: any new contributor reading the headline docs gets an accurate picture in under 10 minutes.

1. **Rewrite [README.md](../README.md)** so it covers:
   - One-paragraph project summary (NZ supported-living CRM; Laravel + Inertia + React + TypeScript + MySQL).
   - Local setup: `composer setup` (already in `composer.json:54-61`); `php artisan migrate:fresh --seed`; `npm run dev`.
   - Test commands (the four canonical commands from §6 below — `vendor/bin/pest`, `npm run test`, `npm run visual:test`, `npx tsc --noEmit`).
   - Pointers to `docs/architecture/`, `docs/route-ownership.md`, and the readiness plans index.
   - Windows / Herd-specific note pointing at `tests/e2e/helpers.ts:resolvePhpBinary()` and `server.php` (so the next person to hit blank Playwright pages knows why `server.php` exists).
   - Keep it under ~150 lines. The repo already has a forest of docs; the README is a directory, not a manual.

2. **Replace [docs/TEST_SUITE_SUMMARY.md](TEST_SUITE_SUMMARY.md)** with a fresh, evidence-grounded version:
   - Drop the "Total Tests: 140+" headline. Replace with table of file counts per type (the table from §1.3).
   - Note that counts are derived from commands (and list those commands inline).
   - Replace the "Recommended Additional Tests" backlog with a pointer to per-module readiness plans; the items in the current list are mostly done.
   - Add a "How to run a single suite" cheat sheet (re-using the §6 commands).
   - Add a Dusk vs Playwright section noting the canonical-harness decision (§4.4 Decision below).

3. **Add a status header to [docs/rostering-pr-map.md](rostering-pr-map.md)**:
   ```markdown
   > **Status: historical reference.** This document maps the file-level
   > content of commit `46ee7ba0` ("Implement rostering production readiness
   > plan") to its 9 PR boundaries for revert/audit purposes. The
   > "Test results" table in §10 is the post-commit verification snapshot,
   > not the current test count. For current counts see
   > [TEST_SUITE_SUMMARY.md](TEST_SUITE_SUMMARY.md).
   ```
   No content changes inside.

4. **Add status checkboxes to [docs/rostering-test-fixes-plan.md](rostering-test-fixes-plan.md)** — mark T-2 and T-3 as applied with the file refs from §1.2; leave T-1 and T-4 unchecked until a current Playwright run confirms.

5. **Light pass on [docs/architecture/shifts-module-map.md](architecture/shifts-module-map.md)** — bump the "Last verified from code" date to today **only** if a fresh route-list inspection confirms it (§6 verification commands). Otherwise leave alone.

**Phase 1 deliverable:** four markdown edits, no code changes, ~1 hour of writing.

### Phase 2 — Targeted Playwright coverage for highest-risk rostering flows

Goal: close the three P1 e2e coverage gaps without rewriting any existing spec.

1. **`tests/e2e/operations-rostering-conflicts.spec.ts` (new)** — one test, desktop-only, gated by `rosteringFlagsEnabled`. Loads `/operations/rostering/conflicts?week=...` against the `RosteringProductionDemoSeeder` data and asserts:
   - The conflicts page renders without a console error.
   - At least one conflict row is visible (the seeder must produce at least one — verify with the seeder before writing the spec).
   - Clicking a conflict deep-links into the relevant shift edit page.
   See spec scaffolding in §4.1.

2. **`tests/e2e/operations-rostering-republish.spec.ts` (new)** — extends the publish flow:
   - Manager publishes a period (reuse the existing publish flow from `operations-rostering-publish.spec.ts`).
   - Manager edits one shift, returns to the rostering grid, sees the "changed_after_publish" indicator.
   - Manager opens diff, sees the changed-shift row.
   - Manager clicks "Republish", confirms, returns to grid, indicator clears.
   See spec scaffolding in §4.2.

3. **`tests/e2e/operations-shifts-detail.spec.ts` (new)** — covers the shift-detail UX seam called out in `rostering-clients-care-readiness-plan.md`:
   - Manager opens a draft shift.
   - Assigns a worker (asserting eligibility preview rendered).
   - Marks the shift as scheduled.
   - Asserts the timeline panel shows the assignment event.
   See spec scaffolding in §4.3.

**Phase 2 deliverable:** three new Playwright specs (~50–100 lines each), no production code changes. Total ~3–5 hrs including running them locally.

### Phase 3 — Dusk retirement / parity plan (incremental, only when proven)

> **Hard rule:** no Dusk file is deleted in this phase. We *catalogue* parity, mark files as superseded with a comment, and let a future maintenance pass remove them once we have telemetry / multiple green runs of the parity Playwright suite.

1. **For each Dusk file in `tests/Browser/Shifts/`, `tests/Browser/Frontline/`:**
   - Confirm the equivalent Playwright spec exists (§1.4 map).
   - Add a one-line PHP comment at the top of the Dusk file:
     ```php
     // Superseded by tests/e2e/<spec-name>.spec.ts and tests/Feature/Routing/ShiftLegacyRedirectTest.php.
     // Kept until 2026-08-01 for parity; safe to delete once Playwright suite has 30 consecutive green runs in CI.
     ```
   - Do **not** edit the test bodies.

2. **For `tests/Browser/Operations/OperationsTest.php` (584 lines):**
   - Read it in full and produce a *spec inventory* in `docs/dusk-operations-test-coverage.md` — one row per `test('...')` call with what it asserts.
   - For each row, mark whether the assertion is *already* covered by a Playwright spec or PHP feature test. **Do not delete any Dusk test row before its Playwright/PHP equivalent is confirmed green.**
   - This becomes the work-list for a later "Dusk retirement PR".

3. **Decide and document the canonical e2e harness** in the new `TEST_SUITE_SUMMARY.md`:
   > **Canonical e2e harness:** Playwright (`tests/e2e/`, `tests/visual/`).
   > Dusk (`tests/Browser/`) remains for legacy interaction surfaces and is being incrementally migrated. New e2e tests must be Playwright unless there is a specific reason Dusk is more suitable (raise this in code review).

**Phase 3 deliverable:** comments added, one inventory doc created. Zero deletions. ~2 hours.

### Phase 4 — Stale-config / test-doc cleanup (low priority, small)

Only do these if Phase 1–3 land cleanly.

1. **Investigate the duplicate `__screenshots__` trees** (P2-2). If `tests/__screenshots__/` is genuinely orphaned (Playwright config writes to `{testDir}/__screenshots__/`, where `testDir` is `./tests`), the duplicate is from the `app-shell.spec.ts` legacy. Decision-only: do not delete without running `npm run visual:update` and re-comparing.
2. **Add a `docs/testing.md`** quick-reference (§6 commands, common failure modes, where baselines live, how to regenerate). ~30 minutes.

---

## 4. Playwright coverage recommendations

### 4.1 `operations-rostering-conflicts.spec.ts`

**Priority:** P1. Closes the conflicts-page coverage gap.

**Acceptance criteria:**
- Spec is desktop-only (`viewport.width >= 1024`); no mobile run.
- Gated by `rosteringFlagsEnabled` (skip-with-reason if either flag is `false`).
- Logs in as a manager (`loginAsStaff`).
- Navigates to `/operations/rostering/conflicts?week=2026-05-04&site_id=9001` (matches the demo seeder's window).
- Asserts the page heading `/Conflicts/i` is visible.
- Asserts at least one row in the conflicts table is visible **OR** an explicit "no conflicts" empty state — the spec should branch on what the seeder produces and fail loudly if neither renders.
- Asserts no console errors (`expectNoConsoleErrors`).
- A11y check: import `@axe-core/playwright`, run on the conflicts page, assert no `serious` or `critical` violations except a documented allowlist.

### 4.2 `operations-rostering-republish.spec.ts`

**Priority:** P1. Closes the republish loop.

**Acceptance criteria:**
- Desktop-only.
- Gated by `rosteringFlagsEnabled`.
- Reuses the seed period from `operations-rostering-publish.spec.ts` (week 2026-05-04, site 9001).
- Step 1: publish the period (paste the same flow from the publish spec or extract a `publishPeriod()` helper into `helpers.ts`).
- Step 2: open one shift in that period, change its start time by 30 minutes, save.
- Step 3: navigate back to `/operations/rostering?week=...&site_id=...`. Assert the publish panel shows a "changed since publish" indicator (the state is `publish_dirty_at` set; the UI label is whatever `Review.tsx` / the panel renders).
- Step 4: click "View diff" → assert the diff page renders with at least one changed row, including the edited shift.
- Step 5: click "Republish" → confirm dialog → submit → URL returns to rostering grid.
- Step 6: assert the panel state has cleared.
- Asserts no console errors throughout.

### 4.3 `operations-shifts-detail.spec.ts`

**Priority:** P1. Covers the operator's actual workflow seam.

**Acceptance criteria:**
- Desktop-only.
- Logs in as a manager.
- Step 1: navigate to a known draft shift via `/operations/shifts?week=...&site_id=...` and click into one that is unassigned.
- Step 2: open the assign dialog, select an eligible worker.
- Step 3: assert the eligibility preview card renders (it's a known UX guardrail per `ShiftStaffEligibilityService`).
- Step 4: confirm assignment.
- Step 5: assert the shift detail page now shows the worker's name in the header, status is `scheduled`, and the timeline panel has an "Assigned" event.
- Step 6: optionally also test unassign (would close the loop on `RosterControllerTest` but is a separate spec; defer).

### 4.4 Mobile / desktop expectations

The current Playwright config has two projects: `chromium-desktop` (1440×1000) and `chromium-mobile` (Pixel 7). The rostering manager flows are explicitly desktop-only by design (see `operations-rostering-publish.spec.ts:15-18`). Continue this pattern:
- **Manager / scheduler / publish / template / suggestions specs** → desktop-only.
- **Frontline `/my-day` / `/my-roster` specs** → run on **both** projects (current behaviour for `my-day-*.spec.ts`).
- **Visual baselines** → both projects (current `app-shell.spec.ts` behaviour).

### 4.5 Accessibility / console-error checks

All new specs must:
- Use `collectConsoleErrors(page)` + `expectNoConsoleErrors(consoleErrors)` (already in `helpers.ts:181-202`).
- For pages with measurable a11y stakes (manager grids, conflicts page, frontline My Day), add an `@axe-core/playwright` pass with an explicit allowlist — copy the pattern from `tests/e2e/operations-rostering-a11y.spec.ts` and `my-day-a11y.spec.ts`.

### 4.6 Helpers we will add

To avoid copy-paste between the new specs, add to `tests/e2e/helpers.ts`:

```ts
// Publishes the current week's period for a site. Assumes login already done.
export async function publishCurrentWeek(
    page: Page,
    options: { week: string; siteId: number },
) { ... }

// Returns the site/week that the rostering demo seeder publishes-ready.
export const ROSTERING_DEMO_PUBLISH_TARGET = {
    week: '2026-05-04',
    siteId: 9001,
} as const;

export const ROSTERING_DEMO_FRONTLINE_TARGET = {
    week: '2026-05-04',
    siteId: 9002,
} as const;
```

These are the constants the existing publish/visibility specs already hard-code; centralising stops drift.

---

## 5. What not to change

1. **Do not redesign rostering.** Domain services, lifecycle service, publishing service, eligibility service, suggestion engine, validators are all in good shape and well-tested. This plan adds tests around them, not new abstractions.
2. **Do not replace all tests at once.** Specifically, do not delete any `tests/Browser/` file in this plan's PRs. Deletions are a follow-up after parity is *measured*, not assumed.
3. **Do not flatten admin / manager / frontline flows.** Keep `/operations/*` (manager), `/my-day` / `/my-roster` (frontline), `/control-room/*` (control room), `/portal/*` (family) as separate surfaces. The plans already in `docs/rostering-*` rely on this separation.
4. **Do not rename backend concepts to improve docs.** `RosterPeriod`, `RosterSuggestion`, `RosterPublishingService`, `ShiftLifecycleService`, `AttendanceService` keep their current names. The docs are wrong about the count, not about the model.
5. **Do not remove Dusk tests until Playwright parity is verified** by green CI runs **and** the parity inventory in §3-Phase-3 is filled in. Phase 3 produces the inventory; deletions happen in a *later* PR with a paper trail.
6. **Do not change test runner config to bypass failures.** If a new spec is flaky on first run, fix the seeder or the helper, do not `test.skip` it without filing the gap in this plan's update.
7. **Do not add new feature flags or add to `RosteringFeatureFlags`.** Flag plumbing is sufficient — Playwright config injects them, `RosterControllerTest` covers per-org override.
8. **Do not modify `phpunit.xml`, `playwright.config.ts`, or `composer.json` test scripts** during Phase 1–3. The current config is working (CI green via `tests.yml` + `visual.yml`).

---

## 6. Verification plan

All commands assume Windows / Herd setup. Bash syntax — use the same commands in PowerShell prefixed by `&` for explicit binary paths (see `tests/e2e/helpers.ts:resolvePhpBinary()` for the canonical Herd PHP location).

### 6.1 Verify the test inventory counts (Phase 1, P0-2)

```bash
# PHP test files by suite
find tests/Feature -name "*.php" | wc -l       # expect 268
find tests/Unit -name "*.php" | wc -l          # expect 65
find tests/Integration -name "*.php" | wc -l   # expect 1

# Dusk test files
find tests/Browser -name "*.php" | wc -l       # expect 106

# Playwright spec files
find tests/e2e -name "*.spec.ts" | wc -l       # expect 24
find tests/visual -name "*.spec.ts" | wc -l    # expect 1

# Visual baselines
find tests/__screenshots__ -type f | wc -l     # expect 24
find tests/visual/__screenshots__ -type f | wc -l  # expect 22

# Fixtures
ls tests/fixtures/payroll/                     # 2026-04-baseline.csv
ls tests/fixtures/routing/                     # shift-permission-matrix.php
```

If any of these numbers diverge by more than ±5, update `docs/TEST_SUITE_SUMMARY.md` accordingly (counts move as work lands; ±5 is normal noise).

### 6.2 Targeted PHP test commands

```bash
# Full backend suite (used by CI)
./vendor/bin/pest

# Rostering-specific subset
./vendor/bin/pest --filter=Rostering

# Roster controller (frontline visibility tests)
./vendor/bin/pest tests/Feature/RosterControllerTest.php

# Routing / contract tests (catch regressions in legacy redirects)
./vendor/bin/pest tests/Feature/Routing/

# Shift lifecycle / safety net
./vendor/bin/pest --filter='ShiftLifecycle|ShiftSafetyNet|ShiftCancellationCascade'

# Approve a pre-existing snapshot when migrations change
composer test:prepare
```

### 6.3 Targeted Playwright commands

```bash
# Full suite (matches CI)
npm run visual:test

# Single rostering spec
npx playwright test tests/e2e/operations-rostering-publish.spec.ts \
  --project=chromium-desktop

# All rostering specs at once
npx playwright test \
  tests/e2e/operations-rostering-publish.spec.ts \
  tests/e2e/operations-rostering-suggestions.spec.ts \
  tests/e2e/operations-rostering-a11y.spec.ts \
  tests/e2e/operations-rostering-performance.spec.ts \
  tests/e2e/template-apply-conflict.spec.ts \
  tests/e2e/frontline-published-visibility.spec.ts \
  --project=chromium-desktop --reporter=list

# Update visual baselines (only when intentionally changing UI)
npm run visual:update

# Re-capture rostering perf baseline
PLAYWRIGHT_UPDATE_ROSTERING_BASELINE=true \
  npx playwright test tests/e2e/operations-rostering-performance.spec.ts \
  --project=chromium-desktop
```

### 6.4 Build / typecheck commands (only if implementation later touches the frontend)

```bash
npm run build         # production asset build (CI runs this before Playwright)
npm run types         # tsc --noEmit
npm run lint          # eslint --fix
```

### 6.5 Windows / Herd-specific notes

- **PHP binary discovery:** `tests/e2e/helpers.ts:7-26` searches `%USERPROFILE%\.config\herd\bin\php.bat` first. Set `PHP_BINARY=...` env var only if Herd isn't on the default path.
- **`server.php` is required at the repo root** for Playwright runs. It's checked in. If a test fails with blank pages and `Content-Type: text/html` for `/build/assets/*.css`, `server.php` is missing or `webServer.command` was changed back to `public/index.php`. Do not change.
- **`public/hot` is moved** by `tests/e2e/global-setup.ts`. If a test run is killed mid-flight (Ctrl+C), restore manually: `mv public/.hot.playwright.bak public/hot`.
- **Schema dump**: `database/schema/mysql-schema.sql` is checked in (1.0 MB). Regenerate after structural migration changes with `php artisan rostering:dump-schema-portable`.
- **Test DB**: MySQL `oblivion_findings_codex_test` with user `testUser` / `test101`. Per-process DB isolation is created by `tests/TestCase.php` — `testUser` needs `CREATE` privilege globally (CI grants this; local Herd needs `GRANT ALL PRIVILEGES ON *.* TO 'testUser'@'%';` once).

### 6.6 What "verified" means for this plan

After implementation:

- §6.1 counts must match the new `TEST_SUITE_SUMMARY.md` ±5.
- `./vendor/bin/pest --filter=Rostering` returns green.
- `npx playwright test --project=chromium-desktop` for the six rostering specs (publish, suggestions, a11y, performance, template-apply-conflict, frontline-published-visibility) **plus the three new specs** returns green.
- The new `README.md` is renderable on GitHub and contains the four bootstrap commands.
- No file under `tests/Browser/` was deleted.

---

## 7. Implementation handoff

A clear checklist for GPT-5.5 / Codex to execute. Tick boxes when complete. **Do not** skip a phase or merge multiple phases into one PR.

### Files likely to be edited

**Phase 1 (docs only):**
- `README.md` — full rewrite
- `docs/TEST_SUITE_SUMMARY.md` — full rewrite
- `docs/rostering-pr-map.md` — add status header banner only
- `docs/rostering-test-fixes-plan.md` — add status checkboxes per PR
- `docs/architecture/shifts-module-map.md` — bump verification date (only if confirmed)

**Phase 2 (new specs only, no source code):**
- `tests/e2e/operations-rostering-conflicts.spec.ts` — new
- `tests/e2e/operations-rostering-republish.spec.ts` — new
- `tests/e2e/operations-shifts-detail.spec.ts` — new
- `tests/e2e/helpers.ts` — add `publishCurrentWeek()` helper + demo target constants

**Phase 3 (comments only, no logic):**
- `tests/Browser/Shifts/ShiftIndexTest.php` — add header comment
- `tests/Browser/Shifts/ShiftTimesheetsTest.php` — add header comment
- `tests/Browser/Shifts/AttendanceTest.php` — add header comment
- `tests/Browser/Frontline/FrontlineStaffUxTest.php` — add header comment
- `docs/dusk-operations-test-coverage.md` — new inventory doc

**Phase 4 (optional):**
- `docs/testing.md` — new quick-reference

### Suggested PR sequence

| PR | Title | Risk | Effort |
|---|---|---|---|
| PR-A | docs(rostering): truth-up README, test-suite summary, plan banners | LOW | 1–2 hrs |
| PR-B | test(rostering): add conflicts / republish / shift-detail Playwright specs + helpers | MED | 3–5 hrs |
| PR-C | docs(tests): mark superseded Dusk shifts/frontline tests; inventory OperationsTest.php | LOW | 2 hrs |
| PR-D *(optional)* | docs(testing): add testing quick-reference | LOW | 30 min |

PR-A and PR-C can ship in parallel (no shared files). PR-B depends on neither but should land after PR-A so the new specs are referenced in `TEST_SUITE_SUMMARY.md`.

### Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Phase 2 specs flake on the first run because the demo seeder doesn't produce the expected fixture | MED | Run the seeder + verify fixture state manually before writing each spec. If a fixture gap is found, **stop the spec PR**, file a follow-up against `RosteringProductionDemoSeeder.php`, do not paper over with `test.skip`. |
| Phase 3 comment additions accidentally retrigger Dusk tests in CI that were previously dormant | LOW | `tests.yml` runs Pest only; visual.yml runs Playwright only. Dusk is not in CI today (verified — no `dusk` invocation in either workflow). Comments alone change nothing. |
| README.md rewrite drifts again within a quarter | MED | Add a `docs/CONTRIBUTING.md` pointer explicitly stating "if you change `tests/` structure, also update `docs/TEST_SUITE_SUMMARY.md`". P2. |
| Phase 1 doc rewrite undercounts a test type and confuses a future reader again | LOW | Use the §6.1 commands as the *source of truth* in the new doc; counts change but the **commands** stay correct. |

### Rollback notes

- **Phase 1**: pure markdown — `git revert` is safe. No data, no schema, no code.
- **Phase 2**: pure new files in `tests/e2e/`. To roll back, delete the new spec files; helpers additions are append-only and harmless to leave.
- **Phase 3**: comment-only. `git revert` is safe.
- **Phase 4**: pure markdown.

No phase touches application code. No phase touches `phpunit.xml`, `playwright.config.ts`, or seeders. The blast radius is bounded to `docs/`, `README.md`, `tests/e2e/`, and comment headers in `tests/Browser/{Shifts,Frontline}/`.

### Final recommendation

**Size:** **small-to-medium**. ~1.5–3 dev-days end-to-end, ~3 PRs, no production code change. The product is in good shape; the docs and the e2e harness coverage map need a tightening pass, not a rebuild.

**Why not "small":** because Phase 2 requires running specs against demo seeders and dealing with whatever fixture gaps surface — that's where the unpredictability lives.

**Why not "large":** because nothing in this plan rewrites a service, changes a route, or touches a migration. If Phase 2 surfaces a real product bug (rather than a spec/fixture gap), file it as a separate readiness plan and do not expand this PR's scope.

---

## Appendix A — Files inspected for this plan

To make the evidence trail reproducible:

- `README.md` (size 18, mtime 2026-02-09)
- `docs/TEST_SUITE_SUMMARY.md` (size 5,747, mtime 2026-02-09)
- `docs/rostering-pr-map.md`, `docs/rostering-test-fixes-plan.md`
- `docs/rostering-clients-care-readiness-plan.md`, `docs/rostering-portal-respite-readiness-plan.md`
- `docs/rostering-hr-staff-training-readiness-plan.md`, `docs/rostering-sites-coverage-readiness-plan.md`
- `docs/rostering-reports-roadmap-readiness-plan.md`
- `docs/architecture/shifts-module-map.md`, `shifts-route-deprecation.md`, `shifts-frontend-routes.md`
- `docs/route-ownership.md`
- `phpunit.xml`, `playwright.config.ts`, `composer.json`, `package.json`
- `routes/operations.php`, `routes/shifts.php`, `routes/web.php` (legacy redirect)
- `tests/DuskTestCase.php`, `tests/Pest.php` (presence only), `tests/TestCase.php` (presence only)
- `tests/e2e/helpers.ts`, `tests/e2e/global-setup.ts`, `tests/e2e/global-teardown.ts`, `tests/e2e/rostering-flags.ts`
- `tests/e2e/operations-rostering-publish.spec.ts`, `operations-rostering-performance.spec.ts`, `frontline-published-visibility.spec.ts`, `my-roster-week-grid.spec.ts`
- `tests/e2e/performance/rostering-dashboard-baseline.json`
- `tests/visual/app-shell.spec.ts`
- `tests/Browser/Shifts/{ShiftIndex,ShiftTimesheets,Attendance}Test.php`
- `tests/Browser/Frontline/FrontlineStaffUxTest.php`
- Directory listings of `app/Http/Controllers/Operations/`, `app/Domain/Rostering/`, `app/Domain/Shifts/`, `database/seeders/`, `resources/js/pages/operations/rostering/`, `tests/Feature/`, `tests/Unit/`, `tests/Browser/`, `tests/e2e/`, `tests/visual/`
- `.github/workflows/tests.yml`, `.github/workflows/visual.yml`
- `git log --oneline -30`

Counts in this plan are accurate as of 2026-05-03.
