# Fleet Full Audit Remediation Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to execute this plan task by task, `superpowers:test-driven-development` for every behavioural change, `oblivionfindings-ui-patterns` for worker-facing UI, and `superpowers:verification-before-completion` before any completion claim. Do not reopen archived task `019f49eb-f69e-7653-980b-af3186be609a`.

**Goal:** Close every confirmed Fleet audit gap without creating duplicate data models, controllers, route families or UI primitives; make workflow/detail modals conform to the Add Client family; harden permission boundaries; and prove the result locally and in a real protected browser session.

**Architecture:** Keep the existing Fleet route/controller/model ownership. Use `WizardShell` as the single workflow/detail modal chrome, `ConfirmDialog` as the single compact confirmation chrome, and `resources/js/lib/datetime.ts` as the single NZ date/time boundary. Preserve existing create URLs as redirects to modal query state rather than deleting deep links. Make permission intent explicit at route middleware and test it negatively. Prefer database aggregates and bounded search endpoints over loading thousands of models to build hero statistics or option lists.

**Tech stack:** Laravel 12, Pest 4, Inertia 2, React 19, TypeScript 5.7, Tailwind 4 semantic tokens, Radix dialogs, Vitest 4, Vite 7.

**Audit input:** `docs/superpowers/plans/2026-07-13-fleet-full-audit.md`

> **Desktop-only scope override (14 July 2026):** The user confirmed that Fleet is a desktop web application. Do not perform mobile or narrow-viewport acceptance testing, and do not implement mobile-specific layout remediation unless the user explicitly reverses this instruction. This override supersedes mobile-specific portions of the audit and plan; desktop responsive behaviour remains in scope.

## New-context recovery block

Use only:

`C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\strange-bhaskara-ae2843`

Expected starting branch/checkpoint before audit documents:

- Branch: `claude/frosty-leavitt-f99798`
- HEAD: `de5d13af323e`
- Feature commit already merged previously: `c705d567`
- The two 13 July audit/plan files may be uncommitted; preserve them.

Start with:

```powershell
git status --short --branch
git rev-parse HEAD
git log -3 --oneline --decorate
```

If HEAD, branch, or existing changes differ, stop and report the exact difference. Do not reset, clean, rebase, switch branches, move worktrees or discard changes. Do not modify the detached Client Profile checkout at `C:\Users\steph\Herd\oblivionfindings`.

## Non-negotiable implementation decisions

1. Do not add a second Fleet model/controller/route family for data already owned by the current FleetAssets controllers.
2. Do not create a second wizard implementation. Extend `resources/js/components/wizard/shell.tsx` only when all converted workflows need the capability.
3. Keep confirmations compact. They must use `resources/js/components/confirm-dialog.tsx` or the shared Radix AlertDialog contract, not `WizardShell`.
4. All workflow/detail dialog bodies must have an accessible title and description, focus containment, Escape dismissal, labelled fields, visible validation, and safe cancel/back behaviour.
5. Extract transport medication forms once and render the same component from both entry pages.
6. Preserve old create/detail URLs as redirects into modal query state so bookmarks do not break.
7. Do not use hard-coded status colour classes when a semantic token exists. Chart/map palettes must be centralized and documented as visualization-only.
8. Do not add speculative APIs. Add a bounded option endpoint only where the current unbounded list is proven by the audit and used by an existing form.
9. Run focused tests after each task. Do not defer all failures to the final gate.
10. Do not push, merge or deploy until the user explicitly approves the verified branch.

---

## Task 1: Lock down mutation permissions before UI work

**Files:**

- Create: `tests/Feature/FleetAssets/FleetPermissionBoundaryTest.php`
- Modify: `routes/fleet-assets.php`
- Modify only if a test demonstrates missing record scoping: `app/Http/Controllers/FleetAssets/GeofenceController.php`
- Modify only if a test demonstrates missing record scoping: `app/Http/Controllers/FleetAssets/WorkOrderController.php`

### Step 1: Write failing negative permission tests

Add tests that create a user with only `fleet.viewAny` and prove:

- `GET /fleet-assets/maintenance/work-orders` is allowed;
- `POST /fleet-assets/maintenance/work-orders` returns 403 and creates no `FleetWorkOrder`;
- `GET /fleet-assets/geofences` is allowed;
- geofence store, update, toggle and delete return 403 and do not mutate the database.

Add positive tests proving `fleet.maintenance.manage|fleet.manage` can create a work order and `assets.geofences.manage|fleet.manage` can mutate a geofence.

Run:

```powershell
php artisan test tests/Feature/FleetAssets/FleetPermissionBoundaryTest.php
```

Expected before the fix: the read-only mutation assertions fail.

### Step 2: Split read and write route groups

In `routes/fleet-assets.php`:

- leave Work Order index/create redirect/show in the read group;
- move `POST /maintenance/work-orders` into `permission:fleet.maintenance.manage|fleet.manage`;
- leave Geofence index in `permission:fleet.viewAny|assets.geofences.manage`;
- put Geofence create/store/edit/update/toggle/delete in `permission:assets.geofences.manage|fleet.manage`.

Do not loosen Handover participant checks. Add characterisation tests for Handover show/accept/dispute and Resident Transport complete/pre-check to document their intended self-service permissions; change those routes only if the tests and existing UI permission flags disagree.

### Step 3: Add record-scope assertions if needed

If a manager can mutate a geofence or work order belonging to an inaccessible site, add a controller policy/scope check using the existing site-access pattern from `HandoverController::assertCanAccessSpecificHandover`. Do not invent a parallel tenancy system.

### Step 4: Re-run focused tests

```powershell
php artisan test tests/Feature/FleetAssets/FleetPermissionBoundaryTest.php tests/Feature/FleetAssets/FleetHandoverSiteIsolationTest.php
```

### Step 5: Commit the bounded security fix

```powershell
git add routes/fleet-assets.php app/Http/Controllers/FleetAssets tests/Feature/FleetAssets/FleetPermissionBoundaryTest.php
git commit -m "fix(fleet): enforce mutation permission boundaries"
```

---

## Task 2: Formalize the two-tier Fleet dialog contract

**Files:**

- Modify: `resources/js/components/wizard/shell.tsx`
- Modify: `resources/js/components/confirm-dialog.tsx`
- Create: `resources/js/components/wizard/shell.test.tsx`
- Create: `resources/js/pages/fleet-assets/fleet-modal-contract.test.ts`

### Step 1: Write failing shell tests

Render `WizardShell` and assert:

- accessible dialog name and description exist;
- the rail, header, progress strip, scroll body and footer are present;
- focus starts inside the dialog, stays trapped, and Escape invokes `onClose`;
- a one-step workflow remains coherent and does not announce misleading multi-step copy;
- success content replaces the form without losing the accessible dialog name.

Render `ConfirmDialog` and assert:

- title/description are always wired;
- cancel receives initial focus for destructive actions;
- destructive and non-destructive variants use semantic tokens and visible button labels.

### Step 2: Add only the shared capabilities needed by all conversions

Extend `WizardShell` with a documented one-step/detail mode if necessary. Keep these defaults:

- width `min(94vw, 980px)` unless dense data genuinely needs 1080px;
- 248px rail at `sm` and above;
- sticky header/footer and scroll-contained body;
- semantic tokens only.

Do not duplicate the Add Client component. The goal is family parity, not importing Client-specific fields.

### Step 3: Add a source contract test

`fleet-modal-contract.test.ts` should enumerate the 13 audited legacy render sites. Initially fail while direct `DialogContent` remains, then turn green as Tasks 3 and 4 remove them. Exclude the documented shared confirmation components.

Run:

```powershell
npx vitest run resources/js/components/wizard/shell.test.tsx resources/js/pages/fleet-assets/fleet-modal-contract.test.ts
```

### Step 4: Commit the shared contract

```powershell
git add resources/js/components/wizard resources/js/components/confirm-dialog.tsx resources/js/pages/fleet-assets/fleet-modal-contract.test.ts
git commit -m "test(fleet): define modal family contract"
```

---

## Task 3: Convert the eight non-transport legacy workflow/detail dialogs

**Files:**

- Modify: `resources/js/pages/fleet-assets/alerts/index.tsx`
- Modify: `resources/js/pages/fleet-assets/assets/show.tsx`
- Modify: `resources/js/pages/fleet-assets/fuel/index.tsx`
- Modify: `resources/js/pages/fleet-assets/devices/index.tsx`
- Modify: `resources/js/pages/fleet-assets/maintenance/checklists/index.tsx`
- Modify: `resources/js/pages/fleet-assets/maintenance/schedules/index.tsx`
- Modify: `resources/js/pages/fleet-assets/resident-tracking/index.tsx`
- Create: `resources/js/pages/fleet-assets/components/workflow-review.tsx` only if the same review summary is reused by at least three workflows
- Modify/Create focused tests beside each extracted form component

### Step 1: Convert one workflow at a time under a failing test

Use these step designs:

- Resolve Alert: `Resolution` → `Review`.
- Upload Asset Document: `File & type` → `Review`.
- Log Fuel: `Vehicle & purchase` → `Review`.
- Pair Device: `Device & asset` → `Review`.
- Device Detail: section rail using `stepLabelOverride`; keep actions in the footer.
- Checklist Template: `Template details` → `Items` → `Review`.
- Service Schedule: `Asset & interval` → `Review`.
- Assign Tracker: `Resident & device` → `Consent check` → `Review`.

For each conversion:

1. Write a focused test for title, description, labels, required validation, step navigation and cancel.
2. Run that test and see it fail.
3. Replace direct `DialogContent` with `WizardShell`.
4. Keep the existing request URL and payload contract.
5. Add a review step for record-changing submissions.
6. Re-run the focused test before moving to the next file.

### Step 2: Resolve the Device Pairing scope/copy mismatch

The canonical domain already links trackers to any active Asset. Rename `Vehicle Asset` to `Asset` and change the description to “Link an existing tracking device to an active Fleet & Assets record.” Keep the backend option query broad unless product requirements explicitly narrow it. Add a controller/page test proving a non-vehicle active asset is represented as an Asset, not mislabeled as a vehicle.

### Step 3: Prove accessibility warnings are gone

The Checklist and Service Schedule tests must fail if `DialogDescription` is absent. Verify there are no direct `DialogContent` instances left in these eight workflow sites.

Run:

```powershell
npx vitest run resources/js/pages/fleet-assets resources/js/components/wizard/shell.test.tsx
npx eslint resources/js/pages/fleet-assets/alerts/index.tsx resources/js/pages/fleet-assets/assets/show.tsx resources/js/pages/fleet-assets/fuel/index.tsx resources/js/pages/fleet-assets/devices/index.tsx resources/js/pages/fleet-assets/maintenance/checklists/index.tsx resources/js/pages/fleet-assets/maintenance/schedules/index.tsx resources/js/pages/fleet-assets/resident-tracking/index.tsx
```

### Step 4: Commit the non-transport modal sweep

```powershell
git add resources/js/pages/fleet-assets resources/js/components/wizard
git commit -m "feat(fleet): align operational dialogs with wizard family"
```

---

## Task 4: Deduplicate and convert all five transport medication dialog sites

**Files:**

- Create: `resources/js/pages/fleet-assets/transports/components/transport-medication-dialogs.tsx`
- Create: `resources/js/pages/fleet-assets/transports/components/transport-medication-dialogs.test.tsx`
- Modify: `resources/js/pages/fleet-assets/transports/show.tsx`
- Modify: `resources/js/pages/fleet-assets/transports/medications.tsx`
- Modify if payload tests expose drift: `app/Http/Controllers/FleetAssets/ResidentTransportController.php`
- Modify: `tests/Feature/FleetAssets/ResidentTransportMedicationTransitTest.php`

### Step 1: Characterize both entry pages

Write tests proving the transport detail page and medication-transit index submit identical payload shapes and display the same validation for:

- pack medication;
- record administration;
- record return.

The existing Feature test must continue proving stock/administration consequences. Add negative tests for missing witness/reason fields where the current rules require them.

### Step 2: Extract one canonical component per workflow

In `transport-medication-dialogs.tsx`, export:

- `PackMedicationWizard`;
- `AdministerTransportMedicationWizard`;
- `ReturnTransportMedicationWizard`.

Each uses `WizardShell`, has explicit descriptions, review step, safe cancel and unchanged endpoint/payload ownership. Both pages import the same components. Delete the duplicated form markup.

### Step 3: Run focused tests

```powershell
npx vitest run resources/js/pages/fleet-assets/transports/components/transport-medication-dialogs.test.tsx resources/js/pages/fleet-assets/fleet-modal-contract.test.ts
php artisan test tests/Feature/FleetAssets/ResidentTransportMedicationTransitTest.php
```

Expected: all 13 legacy workflow/detail `DialogContent` render sites are gone; confirmation dialogs remain.

### Step 4: Commit

```powershell
git add resources/js/pages/fleet-assets/transports tests/Feature/FleetAssets/ResidentTransportMedicationTransitTest.php app/Http/Controllers/FleetAssets/ResidentTransportController.php
git commit -m "refactor(fleet): unify transport medication modals"
```

---

## Task 5: Convert the remaining full-page create/edit escapes to modal-first flows

**Files:**

- Modify: `resources/js/pages/fleet-assets/geofences/index.tsx`
- Refactor into components, then retire page rendering from: `resources/js/pages/fleet-assets/geofences/create.tsx`, `resources/js/pages/fleet-assets/geofences/edit.tsx`
- Modify: `resources/js/pages/fleet-assets/outings/index.tsx`
- Refactor from: `resources/js/pages/fleet-assets/outings/create.tsx`
- Modify: `resources/js/pages/fleet-assets/transports/index.tsx`
- Refactor from: `resources/js/pages/fleet-assets/transports/create.tsx`
- Modify: `resources/js/components/fleet/fleet-incident-dialog.tsx`
- Modify: `routes/fleet-assets.php`
- Modify: `app/Http/Controllers/FleetAssets/GeofenceController.php`
- Modify: `app/Http/Controllers/FleetAssets/OutingController.php`
- Modify: `app/Http/Controllers/FleetAssets/ResidentTransportController.php`
- Create: `tests/Feature/FleetAssets/FleetModalRouteShimTest.php`

### Step 1: Test deep-link shims first

Assert:

- `/fleet-assets/geofences/create` redirects to `/fleet-assets/geofences?new=1`;
- `/fleet-assets/geofences/{id}/edit` redirects to `/fleet-assets/geofences?edit={id}`;
- `/fleet-assets/outings/create` redirects to `/fleet-assets/outings?new=1`;
- `/fleet-assets/transports/create` redirects to `/fleet-assets/transports?new=1`;
- `/fleet-assets/incidents/{id}` redirects to `/fleet-assets/incidents?incident={id}` or otherwise opens the index detail modal without a second full-page UI.

### Step 2: Move form bodies into `WizardShell`

Reuse existing validation and endpoints. Keep map drawing inside the Geofence wizard body, with a large scroll-safe step. Suggested steps:

- Geofence: `Scope & name` → `Draw area` → `Alerts & schedule` → `Review`.
- Outing: `People & purpose` → `Transport & timing` → `Safety checks` → `Review`.
- Transport: `Resident & destination` → `Vehicle & staff` → `Medication & accessibility` → `Review`.

### Step 3: Remove the incident full-page escape

Replace `Open full page` with a deep-link/copy-link action that preserves the modal query URL, or remove it if no valid use remains. Do not leave two different detail experiences.

### Step 4: Run focused tests

```powershell
php artisan test tests/Feature/FleetAssets/FleetModalRouteShimTest.php
npx vitest run resources/js/pages/fleet-assets/geofences resources/js/pages/fleet-assets/outings resources/js/pages/fleet-assets/transports resources/js/components/fleet
```

### Step 5: Commit

```powershell
git add routes/fleet-assets.php app/Http/Controllers/FleetAssets resources/js/pages/fleet-assets/geofences resources/js/pages/fleet-assets/outings resources/js/pages/fleet-assets/transports resources/js/components/fleet tests/Feature/FleetAssets/FleetModalRouteShimTest.php
git commit -m "feat(fleet): complete modal-first create workflows"
```

---

## Task 6: Fix confirmed interaction and semantic-tone defects

**Files:**

- Modify: `resources/js/pages/fleet-assets/maintenance/dashboard.tsx`
- Modify: `resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.test.ts`
- Modify: `resources/js/pages/fleet-assets/daily-check.tsx`
- Modify: `resources/js/pages/fleet-assets/incidents/index.tsx`
- Modify: `resources/js/pages/fleet-assets/components/fleet-hero-kit.tsx` only if a missing semantic tone is shared
- Modify: `resources/css/app.css` or the existing token source only if the dark `status-warning-bg` token itself lacks contrast
- Create: `resources/js/pages/fleet-assets/fleet-status-tones.test.ts`

### Step 1: Write failing contract tests

Assert:

- Maintenance dashboard Overdue tile href is exactly `/fleet-assets/maintenance/work-orders?overdue=1`.
- Daily Check unchecked/due rows use warning or neutral tokens, never critical tokens.
- Failed checks can still use critical treatment.
- Incident Minor is neutral/info, Moderate warning, Major/Critical critical.
- Incident statuses always include visible text/icon in addition to colour.

### Step 2: Implement semantic fixes

- Correct the Maintenance dashboard link.
- Change unchecked Daily Check cards from `status-critical` to a calm warning/due treatment.
- Use `reported=info`, `investigating=warning`, `resolved=success`, `closed=neutral`; use `minor=neutral/info`, `moderate=warning`, `major/critical=critical`.
- Centralize any status palette used by charts; leave map geometry palettes separate and documented.

### Step 3: Run focused tests

```powershell
npx vitest run resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.test.ts resources/js/pages/fleet-assets/fleet-status-tones.test.ts
php artisan test tests/Feature/FleetAssets/FleetIncidentTest.php tests/Feature/FleetAssets/FleetMaintenanceWiringTest.php
```

### Step 4: Commit

```powershell
git add resources/js/pages/fleet-assets resources/css/app.css
git commit -m "fix(fleet): correct filters and status semantics"
```

---

## Task 7: Make Fleet dates New Zealand-correct

**Files:**

- Modify: `resources/js/lib/datetime.ts`
- Modify/Create: `resources/js/lib/datetime.test.ts`
- Modify all 27 audited Fleet files that directly format user-visible dates, starting with:
  - `resources/js/pages/fleet-assets/bookings/index.tsx`
  - `resources/js/pages/fleet-assets/bookings/book-vehicle-wizard.tsx`
  - `resources/js/pages/fleet-assets/fuel/index.tsx`
  - `resources/js/pages/fleet-assets/mileage/index.tsx`
  - `resources/js/pages/fleet-assets/daily-check.tsx`
  - `resources/js/pages/fleet-assets/vehicles/show.tsx`
  - `resources/js/pages/fleet-assets/trips/index.tsx`
  - `resources/js/pages/fleet-assets/maintenance/work-orders/create-wizard.tsx`
  - `resources/js/pages/fleet-assets/reports/reimbursement.tsx`

### Step 1: Add failing boundary tests

Add helpers for HTML date values and filenames, then test instants around Auckland midnight and daylight-saving transitions. Required case: an Auckland morning instant must produce the Auckland calendar date rather than the previous UTC date.

### Step 2: Add canonical helpers

Add to `datetime.ts`:

- `toDateInput(value)` returning `YYYY-MM-DD` in `Pacific/Auckland`;
- `formatMonthYear(value)` for calendars;
- `formatDateForFilename(value)` if filenames need ISO-like dates.

Use `en-NZ` and `Pacific/Auckland` explicitly. Do not use `new Date().toISOString().slice/split` for local form defaults.

### Step 3: Replace direct Fleet formatting

Migrate user-visible date/time output and form defaults to the shared helpers. Remove `en-US` from Booking. Preserve intentional raw timestamps only in machine payloads, never visible copy.

### Step 4: Prove the sweep

```powershell
npx vitest run resources/js/lib/datetime.test.ts resources/js/pages/fleet-assets
rg -n "en-US|new Date\(\)\.toISOString\(\)\.(slice|split)|toLocale(Date|Time)String\(\)" resources/js/pages/fleet-assets
```

Expected: no unapproved matches. Document any machine-only exception inline.

### Step 5: Commit

```powershell
git add resources/js/lib/datetime.ts resources/js/lib/datetime.test.ts resources/js/pages/fleet-assets
git commit -m "fix(fleet): standardize NZ date and time handling"
```

---

## Task 8: Finish responsive table and hero-family coverage

**Files:**

- Create shared primitives only if reused: `resources/js/pages/fleet-assets/components/fleet-responsive-list.tsx`
- Modify: `resources/js/pages/fleet-assets/drivers/show.tsx`
- Modify: `resources/js/pages/fleet-assets/mobile/dashboard.tsx`
- Modify the 25 table files listed in `docs/superpowers/plans/2026-07-13-fleet-full-audit.md`
- Create: `resources/js/pages/fleet-assets/fleet-responsive-contract.test.ts`
- Modify: `tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php`

### Step 1: Add failing source/render contracts

Assert:

- every titled Fleet page uses `HeroShell`, `FleetCompactHero`, or an explicitly documented mobile Fleet hero;
- worker-facing tables have a mobile card/list branch and a desktop table branch;
- every mobile card carries the row's primary identity, status text, next action and essential timestamp;
- manager/report tables declare an intentional narrow strategy, not accidental document overflow.

### Step 2: Fix the two hero outliers

- Move Driver detail to `FleetCompactHero` with driver identity, compliance/score state and primary action.
- Restyle Mobile dashboard with Fleet semantic tokens and action hierarchy without making it desktop-heavy.

### Step 3: Convert tables in risk order

Worker/operational first:

- Alerts, Keys, Fuel, Mileage, Handovers, Inspections, Outings, Transports, medication transit, Incidents, Resident Tracking, Schedules and Work Orders.

Manager/reporting second:

- Compliance, Dashboard, Trips, Asset detail, Devices, Driver index/detail, Reports index/by-house/reimbursement/cost-allocation/community-access.

Use one source collection and render it twice; do not duplicate filtering or action logic. Keep touch targets at least 44px and expose row actions without hover.

### Step 4: Run tests and static checks

```powershell
npx vitest run resources/js/pages/fleet-assets/fleet-responsive-contract.test.ts resources/js/pages/fleet-assets
php artisan test tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php tests/Feature/FleetAssets/DashboardHeroContractTest.php
```

### Step 5: Commit

```powershell
git add resources/js/pages/fleet-assets tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php
git commit -m "feat(fleet): complete responsive and hero family coverage"
```

---

## Task 9: Remove high-volume in-memory aggregation and unbounded form options

**Files:**

- Modify: `app/Http/Controllers/FleetAssets/AssetController.php`
- Modify: `app/Http/Controllers/FleetAssets/MileageController.php`
- Modify: `app/Http/Controllers/FleetAssets/IncidentController.php`
- Modify: `app/Http/Controllers/FleetAssets/DriverController.php`
- Modify: `app/Http/Controllers/FleetAssets/ResidentTransportController.php`
- Modify: `app/Http/Controllers/FleetAssets/VehicleBookingController.php`
- Modify: `app/Http/Controllers/FleetAssets/WorkOrderController.php`
- Modify: `app/Http/Controllers/FleetAssets/VehicleController.php`
- Modify: `app/Http/Controllers/FleetAssets/DeviceController.php`
- Add focused Feature tests beside each existing controller test; create a new controller test file only where none exists

### Step 1: Characterize current aggregate values

For each controller that uses `limit(5000)->get()` to calculate hero/export totals, create records beyond the paginated first page and assert the current expected total/count/sum. The new query must return the same values.

### Step 2: Replace model loading with SQL aggregates

Use `count`, `sum`, grouped selects and cloned scoped builders. Preserve current filters and site visibility. Do not change response prop names.

### Step 3: Bound form option lists

For Work Order, Incident and Device Pairing:

- return a small initial option set plus selected values;
- add a bounded authenticated search route only for the existing asset/user/device selector;
- cap results and preserve the same permission/site scope as the parent form;
- add request tests for minimum query length, result cap and forbidden access.

### Step 4: Run controller tests

```powershell
php artisan test tests/Feature/FleetAssets tests/Unit/FleetAssets/FleetReportPeriodTest.php
```

### Step 5: Commit

```powershell
git add app/Http/Controllers/FleetAssets routes/fleet-assets.php tests/Feature/FleetAssets tests/Unit/FleetAssets
git commit -m "perf(fleet): bound queries and aggregate in database"
```

---

## Task 10: Full verification and protected-browser acceptance

### Step 1: Run the complete focused backend matrix

```powershell
php artisan test tests/Feature/FleetAssets tests/Unit/FleetAssets/FleetReportPeriodTest.php
```

### Step 2: Run the complete frontend matrix

```powershell
npx vitest run resources/js/pages/fleet-assets resources/js/components/wizard/shell.test.tsx resources/js/lib/datetime.test.ts
npm run types
npx eslint resources/js/pages/fleet-assets resources/js/components/fleet resources/js/components/wizard resources/js/lib/datetime.ts
```

### Step 3: Run syntax and production builds

```powershell
Get-ChildItem app/Http/Controllers/FleetAssets -Filter *.php | ForEach-Object { php -l $_.FullName }
php -l routes/fleet-assets.php
npm run build
npx vite build --ssr
```

### Step 4: Start a temporary preview from the exact Fleet worktree

Use the established `127.0.0.1:8001` fallback and browse it as `http://oblivionfindings.test:8001`. Confirm the process working directory, document root and built asset paths point to the exact Fleet worktree. Do not modify `.claude/launch.json`, `public/hot`, tracked preview configuration, or the live server.

### Step 5: Use one Chrome tab for acceptance

Verify at minimum:

- all eight core protected URLs from the audit;
- every one of the eleven distinct converted workflow modals;
- representative destructive and non-destructive confirmations;
- Geofence, Outing and Transport deep-link shims;
- Work Order Overdue from both the Maintenance dashboard and Work Orders page;
- light and dark on `/fleet-assets`, `/fleet-assets/daily-check`, `/fleet-assets/reports`, Checklist and Service Schedule modals;
- the normal desktop browser viewport only; mobile and narrow-viewport acceptance are explicitly out of scope under the 14 July desktop-only override;
- no new console error or missing-description warning;
- keyboard Tab/Shift+Tab/Escape and visible focus on every modal family.

Do not submit destructive forms during browser verification. Use local seeded data for creation tests only when the test database/preview is isolated.

### Step 6: Stop preview and prove cleanup

Stop the temporary process and confirm port 8001 is no longer listening.

### Step 7: Update evidence documents

Append exact test counts, URLs, interactions, theme/viewport coverage, console results and remaining boundaries to:

- `docs/superpowers/plans/2026-07-13-fleet-full-audit.md`
- `docs/superpowers/plans/2026-07-13-fleet-full-audit-fix-plan.md`

Do not rewrite the historical 10 July hero rollout evidence. Do not commit these audit/plan updates without asking the user first.

### Step 8: Request review before integration

Review the branch diff and report:

- exact commits;
- exact modal count after remediation (expected: 0 legacy workflow/detail sites, confirmations still compact);
- backend/frontend/build results;
- browser proof and any deferred item;
- exact worktree and HEAD.

Ask before merge, push or deployment.

## Definition of done

- Read-only Fleet permissions cannot mutate Work Orders or Geofences.
- All eleven distinct workflow/detail modal workflows use the Add Client-derived family; all 13 legacy render sites are removed.
- All twelve confirmations use the compact shared confirmation family with accessible descriptions.
- No Fleet dialog produces a missing title/description warning.
- Geofence, Outing, Transport and Incident detail remain modal-first while legacy URLs deep-link safely.
- Maintenance Overdue links preserve `overdue=1` and displayed counts agree with results.
- Fleet user-visible dates and form defaults are New Zealand-correct.
- Daily Check and Incident tones match meaning and remain non-colour-only in light/dark themes.
- Every Fleet table remains usable and intentional at supported desktop browser widths; there is no mobile-card acceptance requirement.
- Driver detail and legacy alternate dashboard surfaces rejoin the desktop Fleet family.
- High-volume aggregates do not load 5,000 models into PHP, and large selectors are bounded/searchable.
- Focused PHP/Vitest, types, lint, PHP syntax, client build, SSR build and protected-browser verification all pass with exact recorded evidence.

## Execution closeout — 14 July 2026

### Scope applied

- Fleet was treated as a desktop web-only application for final acceptance.
- Mobile and narrow-viewport checks were stopped, an in-progress mobile-only adjustment was removed before commit, and Chrome was restored to its normal desktop viewport.
- Tasks 1 through 10 were executed sequentially in the mandated Fleet worktree. The audit and this plan remain untracked and were not committed.

### Commits and integration

1. `a26e96d3` — `fix(fleet): enforce mutation permission boundaries`
2. `13b209b7` — `test(fleet): define modal family contract`
3. `f97f3760` — `feat(fleet): align operational dialogs with wizard family`
4. `e44f466c` — `refactor(fleet): unify transport medication modals`
5. `a41376cc` — `feat(fleet): complete modal-first create workflows`
6. `7f012ae7` — `fix(fleet): correct filters and status semantics`
7. `3e4437ff` — `fix(fleet): standardize NZ date and time handling`
8. `eba95444` — `feat(fleet): complete responsive and hero family coverage`
9. `03a5e755` — `perf(fleet): bound queries and aggregate in database`
10. `6e6cefcb` — merge commit integrating current `origin/main`
11. `3d0d7b86` — `fix(fleet): format vehicle freshness for workers`, found during protected-browser acceptance and completed red-green before redeploy

`origin/main`, the deployed server, and the Fleet worktree all resolve to `3d0d7b862e8bb1da5647e7185bb657f47f4ba698`.

### Verification evidence

- Backend: `82 passed`, `686 assertions`, `377.44s`.
- Frontend final deployed-HEAD rerun: `18` test files passed, `48` tests passed, `6.29s`.
- TypeScript: `npm run types` passed.
- ESLint: `0 errors`, `3 warnings`; the warnings are existing Card-primitive advisories in Alerts, Checklist Templates, and Resident Tracking.
- PHP syntax: all `29` Fleet controllers plus `routes/fleet-assets.php` passed (`30` files total).
- Client production build: `4,948` modules, `3m55s`; server build: `2m51s`; standalone SSR build: `1,600` modules, `45.42s`.
- Final source comparison is clean at HEAD. The only worktree entries are the two intentionally untracked evidence documents.

### Modal inventory after remediation

- `0` legacy direct `DialogContent` workflow/detail render sites remain.
- All `13` audited legacy render sites, representing `11` distinct workflows, now use the Add Client-derived `WizardShell` family.
- Fleet now has `21` WizardShell workflow/detail surfaces and `12` intentionally compact confirmations.
- Confirmations remain `9` shared `ConfirmDialog` uses plus `3` accessible direct `AlertDialog` uses.
- Browser checks confirmed visible title/description structure and no missing-description warning.

### Protected desktop-browser acceptance

One installed Chrome-control tab was used. It finished at `https://oblivionfindings.com/fleet-assets`, on Light theme at the normal desktop viewport, and was finalized after the checks.

Core protected URLs verified:

- `/fleet-assets`
- `/fleet-assets/daily-check`
- `/fleet-assets/maintenance/work-orders?overdue=1`
- `/fleet-assets/mileage`
- `/fleet-assets/incidents`
- `/fleet-assets/reports`
- `/fleet-assets/vehicles`
- `/fleet-assets/maintenance/dashboard`

Workflow/modal proof:

- Opened Resolve Alert, Upload Asset Document, Log Fuel, Pair Device, Device Detail, Create Checklist Template, Create Service Schedule, and Assign Tracker.
- Confirmed the transport-medication trio is owned by the single shared WizardShell implementation. It could not be opened live because the dev dataset has no medication-transit or resident-transport fixtures.
- Verified the Geofence create/edit, Outing create, and Resident Transport create legacy URLs redirect to their modal-first query-string shims.
- Verified Overdue links from both Maintenance Overview and Work Orders preserve `?overdue=1`; both displayed `0` and the filtered result was empty.
- Opened compact non-destructive Close Trip and destructive Delete Trip confirmations. Cancel held initial focus and both were cancelled without mutation.
- Mileage WizardShell browser keyboard proof covered Tab, Shift+Tab, visible focus outline, and Escape dismissal.
- Light and Dark were checked on Fleet dashboard, Daily Checks, Reports, Checklist Template, and Service Schedule. Theme was restored to Light.
- Final browser console result: `0` warnings and `0` errors.
- Vehicle freshness now renders `Last seen: Sat 2 May, 4:30 pm`; the raw ISO timestamp is absent.

The tracker was inadvertently unassigned when its live `Unassign` action mutated immediately rather than presenting confirmation. Acceptance stopped, the user explicitly authorized repair, and the three-step assignment WizardShell restored tracker `867963069916998` to Amelia Wilson with active consent confirmed. The page then reported `Tracker assigned to resident`, `1` resident tracked, and `1` online.

No destructive confirmation was submitted. No other create/edit workflow was submitted against live data.

### Deployment and cleanup evidence

- Server root: `/var/www/oblivionfindings`, clean `main` at exact SHA `3d0d7b862e8bb1da5647e7185bb657f47f4ba698`.
- Build manifest: `2026-07-13 21:30:30.709054157 +0000`, `3,239,017` bytes.
- Pending migrations: `0`; Fleet routes: `144`.
- Queue worker: PID `375990`, Redis worker with `--sleep=3 --tries=3 --timeout=90`.
- `/login`: HTTP `200`; unauthenticated `/fleet-assets`: HTTP `302` to `/login`.
- Local port `8001`: not listening.

### Deferred fixture-dependent browser proof

- Pack Medication for Transit, Record Transport Administration, and Record Medication Return could not be opened live because there are no transport-medication fixtures.
- Outing detail could not be opened live because there are no outing records.
- These surfaces are covered by focused component/source tests; no synthetic live data was created solely for acceptance.
