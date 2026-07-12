# Fleet Hero Rollout Continuation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish Claude Code's interrupted Fleet hero rollout without duplicating its two completed commits, then prove the shared hero family is coherent, functional, and build-safe across the Fleet web module.

**Architecture:** Continue exclusively in `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\strange-bhaskara-ae2843` on `claude/frosty-leavitt-f99798`. Preserve commits `810a9968` and `31349a26`, complete the inherited seven-file Wave B/C diff, add backend contract coverage, and use the existing `fleet-hero-kit` / `hs-hero-kit` primitives rather than introducing another hero variant. The mobile-only dashboard and new speculative list-filter APIs are outside this web-hub continuation; every standard web hub/list page already uses `HeroShell`, and existing compact/detail surfaces already use `FleetCompactHero`.

**Tech Stack:** Laravel 12, PHP 8.4, Inertia 2, React 19, TypeScript, Tailwind CSS 4, Pest/PHPUnit, ESLint, Vite, Playwright/browser verification.

---

## Recovered checkpoint and scope boundary

- Completed dashboard redesign: `810a9968`.
- Completed Wave A rollout: `31349a26`.
- Inherited unstaged files: `DailyCheckController.php`, `WorkOrderController.php`, `daily-check.tsx`, `incidents/index.tsx`, `maintenance/work-orders/index.tsx`, `mileage/index.tsx`, and `reports/index.tsx`.
- Recovered red check (resolved): `npm run types` originally failed because `reports/index.tsx` used `FleetHeroAction` without importing it. The final TypeScript run exits 0.
- Targeted ESLint has zero errors. Its three warnings are pre-existing raw filter buttons at `incidents/index.tsx:445`, `:452`, and `:459`, outside the inherited two-line tone change.
- Coverage inventory: 26 standard Fleet hub/list/dashboard pages use `HeroShell`; map/detail/form pages use `FleetCompactHero`. The mobile dashboard is a separate mobile surface and is not part of the supplied desktop-web hero brief.
- The design handoff intentionally links dashboard attention chips to the broad Bookings, Outings, and Alerts worklists. Do not add unapproved filter APIs merely to make those links narrower.
- Do not touch or commit `.claude/launch.json`. Do not push this branch unless the user separately asks.

## File map

- `app/Http/Controllers/FleetAssets/DailyCheckController.php` — supplies real, org-wide roadworthiness and alert counts to the Daily Check hero.
- `app/Http/Controllers/FleetAssets/DashboardController.php` — keeps expired Rego, CoF, and insurance semantics aligned on the dashboard hero.
- `app/Http/Controllers/FleetAssets/VehicleController.php` — keeps the Vehicles hero on the same shared compliance contract.
- `resources/js/pages/fleet-assets/daily-check.tsx` — renders those counts through `FleetComplianceBadges` in the canonical hero footer.
- `resources/js/pages/fleet-assets/dashboard.tsx` — wires the expanded shared compliance contract into the dashboard hero.
- `resources/js/pages/fleet-assets/vehicles/index.tsx` — wires the expanded shared compliance contract into the Vehicles hero.
- `resources/js/pages/fleet-assets/components/fleet-hero-kit.tsx` — gives every roadworthiness document truthful expired precedence and supports shared link/button hero actions.
- `resources/js/pages/fleet-assets/components/fleet-hero-kit.test.tsx` — covers expired/due/current badge semantics and true button behavior.
- `app/Http/Controllers/FleetAssets/WorkOrderController.php` — implements the overdue work-order list filter used by the hero tile.
- `resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx` — deep-links the Overdue tile to `?overdue=1`.
- `resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.ts` — keeps visible Work Order filter changes from retaining a contradictory hidden overdue state.
- `resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.test.ts` — fast regression coverage for status-versus-priority filter merging.
- `resources/js/pages/fleet-assets/mileage/index.tsx` — presents the modal-opening New claim control with the same on-dark action chrome and focus treatment as `FleetHeroAction`.
- `resources/js/pages/fleet-assets/incidents/index.tsx` — makes Investigating and Resolved tones truthful at zero.
- `resources/js/pages/fleet-assets/reports/index.tsx` — uses `FleetHeroAction` for both CSV links and removes the obsolete imperative export helper.
- `app/Http/Controllers/FleetAssets/ReportController.php` — resolves named report periods consistently for the dashboard and CSV exports.
- `tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php` — regression coverage for Daily Check compliance, overdue Work Order filtering, and one-year report exports.
- `tests/Feature/FleetAssets/DashboardHeroContractTest.php` — locks the dashboard side of the expanded compliance payload.
- `tests/Unit/FleetAssets/FleetReportPeriodTest.php` — fast boundary coverage for named and bounded legacy report periods.
- `docs/superpowers/plans/2026-07-10-fleet-hero-rollout-continuation.md` — living execution ledger and final verification record.

### Task 1: Restore the interrupted Reports edit to a compiling state

**Files:**
- Modify: `resources/js/pages/fleet-assets/reports/index.tsx:3-13`
- Modify: `resources/js/pages/fleet-assets/reports/index.tsx:175-177`

- [x] **Step 1: Preserve the observed red check**

Run:

```powershell
npm run types
```

Expected: exit 1 with four `TS2304: Cannot find name 'FleetHeroAction'` diagnostics at lines 237, 243, 244, and 250. This red check has already been observed during recovery and must remain recorded here.

- [x] **Step 2: Complete the kit import and delete obsolete export code**

Change the Fleet hero-kit import to include the missing primitive:

```tsx
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
```

Delete the now-unused generic button import:

```tsx
import { Button } from '@/components/ui/button';
```

Delete the obsolete imperative helper because the two actions now have real export `href`s:

```tsx
const handleExport = (type: string) => {
    window.location.href = `/fleet-assets/reports/export?period=${period}&type=${type}`;
};
```

- [x] **Step 3: Verify the Reports edit is green**

Run:

```powershell
npm run types
npx eslint resources/js/pages/fleet-assets/reports/index.tsx
```

Expected: both commands exit 0 with no Reports diagnostics.

### Task 2: Add regression contracts for the inherited backend behavior

**Files:**
- Create: `tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php`
- Verify: `app/Http/Controllers/FleetAssets/DailyCheckController.php`
- Verify: `app/Http/Controllers/FleetAssets/WorkOrderController.php`

The production behavior in this task predates this takeover and must be preserved rather than deleted. Add regression coverage now; any additional behavior discovered during execution must use a fresh red-green cycle before its production edit.

- [x] **Step 1: Create the focused feature contract**

Create the following test file:

```php
<?php

namespace Tests\Feature\FleetAssets;

use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FleetHeroRolloutContractTest extends TestCase
{
    use RefreshDatabase;

    private function makeFleetUser(): User
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $user = User::factory()->create(['approved_at' => now()]);

        foreach (['fleet.viewAny', 'assets.viewAny'] as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                [
                    'description' => str_replace('.', ' ', $permissionKey),
                    'group' => explode('.', $permissionKey)[0],
                ],
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    public function test_daily_check_exposes_live_compliance_badge_counts(): void
    {
        $user = $this->makeFleetUser();

        Asset::factory()->vehicle()->create([
            'wof_expires_at' => now()->addDays(10),
            'registration_expires_at' => now()->addDays(12),
            'cof_expires_at' => now()->addDays(14),
        ]);
        Asset::factory()->vehicle()->create([
            'wof_expires_at' => now()->subDay(),
            'registration_expires_at' => now()->addDays(60),
        ]);

        ControlRoomAlert::factory()->fromFleet()->open()->critical()->create();
        ControlRoomAlert::factory()->fromFleet()->open()->high()->create();
        ControlRoomAlert::factory()->fromFleet()->resolved()->critical()->create();

        $this->actingAs($user)
            ->get('/fleet-assets/daily-check')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/daily-check')
                ->where('compliance.wof_due', 1)
                ->where('compliance.wof_expired', 1)
                ->where('compliance.rego_due', 1)
                ->where('compliance.cof_due', 1)
                ->where('compliance.insurance_expiring', null)
                ->where('compliance.open_alerts', 2)
                ->where('compliance.critical_alerts', 1)
            );
    }

    public function test_overdue_work_order_filter_returns_only_open_past_due_work(): void
    {
        $user = $this->makeFleetUser();

        FleetWorkOrder::factory()->create(['status' => 'open', 'due_at' => now()->subDay()]);
        FleetWorkOrder::factory()->create(['status' => 'in_progress', 'due_at' => now()->subHours(2)]);
        FleetWorkOrder::factory()->create(['status' => 'completed', 'due_at' => now()->subDay()]);
        FleetWorkOrder::factory()->create(['status' => 'open', 'due_at' => now()->addDay()]);
        FleetWorkOrder::factory()->create(['status' => 'open', 'due_at' => null]);

        $this->actingAs($user)
            ->get('/fleet-assets/maintenance/work-orders?overdue=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/maintenance/work-orders/index')
                ->has('work_orders.data', 2)
                ->where('work_orders.meta.total', 2)
                ->where('filters.overdue', '1')
            );
    }
}
```

- [x] **Step 2: Run the new contract and fix only real failures**

Run:

```powershell
& "$env:USERPROFILE\.config\herd\bin\php84\php.exe" vendor/bin/pest tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php
```

Expected: 2 tests pass. If a test errors because a fixture field or assertion shape differs from the real schema, correct the fixture/assertion without weakening the behavior. If a behavior assertion fails, write the smallest production fix, rerun this file, and keep the new regression.

- [x] **Step 3: Run the neighbouring Fleet contracts**

Run:

```powershell
& "$env:USERPROFILE\.config\herd\bin\php84\php.exe" vendor/bin/pest tests/Feature/FleetAssets/DashboardHeroContractTest.php tests/Feature/FleetAssets/FleetMaintenanceWiringTest.php
```

Expected: both files pass with no new warnings or errors.

### Task 3: Reconcile and review the inherited seven-file UI wave

**Files:**
- Review: `resources/js/pages/fleet-assets/daily-check.tsx`
- Review: `resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx`
- Review: `resources/js/pages/fleet-assets/mileage/index.tsx`
- Review: `resources/js/pages/fleet-assets/incidents/index.tsx`
- Review: `resources/js/pages/fleet-assets/reports/index.tsx`
- Review: `app/Http/Controllers/FleetAssets/DailyCheckController.php`
- Review: `app/Http/Controllers/FleetAssets/WorkOrderController.php`

- [x] **Step 1: Confirm every inherited change has a real job**

Check the diff and retain only these approved behaviors:

```text
Daily Check: FleetComplianceBadges with WOF/Rego/CoF/Insurance/Control Room counts.
Work Orders: Overdue tile -> ?overdue=1 and matching server-side filter.
Mileage: New claim remains a modal opener but matches on-dark hero action chrome and focus state.
Incidents: zero Investigating is success; zero Resolved is neutral.
Reports: both CSV exports are FleetHeroAction links with current period preserved.
```

Run:

```powershell
git diff -- app/Http/Controllers/FleetAssets/DailyCheckController.php app/Http/Controllers/FleetAssets/WorkOrderController.php resources/js/pages/fleet-assets/daily-check.tsx resources/js/pages/fleet-assets/incidents/index.tsx resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx resources/js/pages/fleet-assets/mileage/index.tsx resources/js/pages/fleet-assets/reports/index.tsx
git diff --check
```

Expected: no unrelated page/body refactors, no raw colour additions, and no whitespace errors.

- [x] **Step 2: Confirm the shared-family coverage boundary**

Run:

```powershell
rg -l "HeroShell" resources/js/pages/fleet-assets -g "*.tsx"
rg -l "FleetCompactHero" resources/js/pages/fleet-assets -g "*.tsx"
rg -n "alert\(|console\.(log|warn|error)\(|href=\"#\"|#[0-9A-Fa-f]{3,8}|oklch\(" resources/js/pages/fleet-assets -g "*.tsx"
```

Expected: all standard hub/list/dashboard pages remain on `HeroShell`; compact detail/form/map pages remain on `FleetCompactHero`; no new stub action or raw colour appears in the inherited diff. Existing non-hero chart colours and unrelated historical matches are not part of this task.

- [x] **Step 3: Check formatting without broad rewrites**

Run:

```powershell
npx prettier --check resources/js/pages/fleet-assets/daily-check.tsx resources/js/pages/fleet-assets/incidents/index.tsx resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx resources/js/pages/fleet-assets/mileage/index.tsx resources/js/pages/fleet-assets/reports/index.tsx
```

Expected: exit 0. If only one changed file fails, format that file alone and inspect its diff before continuing.

### Task 3A: Close the two hero action-path defects found in spec review

**Files:**
- Create: `resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.ts`
- Create: `resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.test.ts`
- Modify: `resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx:215-221`
- Modify: `tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php`
- Modify: `app/Http/Controllers/FleetAssets/ReportController.php:21-34`
- Modify: `app/Http/Controllers/FleetAssets/ReportController.php:401-418`

- [x] **Step 1: Reproduce the hidden overdue-filter failure**

Add a focused Vitest contract that starts with `{ overdue: '1', priority: 'high' }` and proves a status change removes `overdue`, while a priority-only change preserves it. Run the test before the helper exists and record the expected red missing-behaviour/module result.

- [x] **Step 2: Implement the minimal filter merge helper**

Create a typed helper that merges current and incoming Work Order filters, then deletes `overdue` only when the incoming update owns the `status` key. Use it inside `applyFilters` before adding `page: 1`.

Run:

```powershell
npx vitest run resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.test.ts
```

Expected: both status-clearing and priority-preserving cases pass.

- [x] **Step 3: Reproduce the one-year export failure**

Add a feature contract with one uniquely named vehicle/trip six months old and another two years old. Request `/fleet-assets/reports/export?period=1y&type=trips`; assert the streamed CSV contains the within-year vehicle and excludes the older vehicle. Run that single test before changing `ReportController`.

Expected: red because `(int) '1y'` becomes one day and the six-month trip is absent.

- [x] **Step 4: Share the named-period mapping between page and export**

Centralise `7d`, `30d`, `90d`, and `1y` to the same start-date resolver in `ReportController`, with an unknown-token fallback of 30 days, and call it from both `index()` and `export()`. Preserve numeric-day compatibility only as a bounded fallback.

Run the focused export test again.

Expected: green; the six-month trip is exported and the two-year trip is excluded.

- [x] **Step 5: Re-run focused static checks**

Run:

```powershell
npm run types
npx eslint resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.ts resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.test.ts resources/js/pages/fleet-assets/reports/index.tsx
& "$env:USERPROFILE\.config\herd\bin\php84\php.exe" -l app/Http/Controllers/FleetAssets/ReportController.php
git diff --check
```

Expected: no errors or whitespace defects.

### Task 4: Verify the real browser surfaces

**Files:**
- Verify only; do not modify `.claude/launch.json`.

- [x] **Step 1: Attach to the correct worktree preview**

Use the Oblivion Findings browser-verification workflow to confirm the server document root/build comes from `strange-bhaskara-ae2843`, using the existing local port 8767 when it is healthy. If the preview is stale or points at another worktree, start the documented fallback server from this worktree rather than changing tracked launch configuration.

- [ ] **Step 2: Verify the changed page set at desktop width**

Visit and inspect:

```text
/fleet-assets
/fleet-assets/daily-check
/fleet-assets/maintenance/work-orders?overdue=1
/fleet-assets/mileage
/fleet-assets/incidents
/fleet-assets/reports
/fleet-assets/vehicles
/fleet-assets/maintenance/dashboard
```

Expected:

```text
The hero gradient/chrome reads as one family.
Daily Check shows canonical compliance badges.
The Work Orders overdue tile opens the filtered list and the result count matches the tile.
Mileage New claim opens its existing modal and remains keyboard-focusable.
Incident zero/non-zero tones match their labels and are never colour-only.
Reports CSV links include both period and type and trigger the expected download route.
Dashboard, Vehicles, and Maintenance remain visually coherent with no regressions.
No page logs a console error.
```

- [ ] **Step 3: Verify responsive and dark states**

Check `/fleet-assets`, `/fleet-assets/daily-check`, and `/fleet-assets/reports` at a narrow viewport below `md` and at desktop width, in light and dark themes.

Expected: no clipped title, action, badge, or metric text; hero rows wrap without horizontal overflow; focus rings remain visible; status meaning is retained through icon/text/count rather than colour alone.

### Task 5: Run the complete verification matrix

**Files:**
- Verify: all changed files and Fleet hero contracts.

- [x] **Step 1: PHP syntax**

Run:

```powershell
$php = "$env:USERPROFILE\.config\herd\bin\php84\php.exe"
& $php -l app/Http/Controllers/FleetAssets/DailyCheckController.php
& $php -l app/Http/Controllers/FleetAssets/WorkOrderController.php
& $php -l app/Http/Controllers/FleetAssets/ReportController.php
```

Expected: both files report `No syntax errors detected`.

- [x] **Step 2: Type and lint checks**

Run:

```powershell
npm run types
npx eslint resources/js/pages/fleet-assets/daily-check.tsx resources/js/pages/fleet-assets/incidents/index.tsx resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.ts resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.test.ts resources/js/pages/fleet-assets/mileage/index.tsx resources/js/pages/fleet-assets/reports/index.tsx
```

Expected: TypeScript and ESLint exit 0. The three known Incidents raw-filter-button warnings may remain if unchanged from HEAD; record them accurately rather than claiming a warning-free run.

- [x] **Step 3: Client and SSR builds**

Run:

```powershell
npm run build
npx vite build --ssr
```

Expected: both Vite builds exit 0. This explicitly supersedes Claude's earlier background build because Reports and Incidents changed after that build started.

- [x] **Step 4: Scoped backend suite**

Run:

```powershell
& "$env:USERPROFILE\.config\herd\bin\php84\php.exe" vendor/bin/pest tests/Feature/FleetAssets/DashboardHeroContractTest.php tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php tests/Feature/FleetAssets/FleetMaintenanceWiringTest.php tests/Feature/FleetAssets/FleetIncidentTest.php
```

Expected: every selected Fleet contract passes.

- [x] **Step 5: Final diff integrity**

Run:

```powershell
git diff --check
git status --short --branch
git diff --stat
```

Expected: the five controller files, eight changed Fleet pages/components, four focused test/helper files, two Fleet feature contracts, and this plan ledger are changed; `.claude/launch.json` is absent.

### Task 6: Record evidence and create the remaining local Fleet commit

**Files:**
- Modify: `docs/superpowers/plans/2026-07-10-fleet-hero-rollout-continuation.md`
- Stage: the exact twenty-file controller, shared component, page, focused test/helper, contract, and plan scope listed below.

- [x] **Step 1: Update this ledger**

Tick completed boxes and append a short `## Verification evidence` section containing the exact command results, browser pages/viewports checked, console result, and any truthful remaining warning/boundary.

- [x] **Step 2: Review staged scope**

Run:

```powershell
git add app/Http/Controllers/FleetAssets/DailyCheckController.php app/Http/Controllers/FleetAssets/DashboardController.php app/Http/Controllers/FleetAssets/ReportController.php app/Http/Controllers/FleetAssets/VehicleController.php app/Http/Controllers/FleetAssets/WorkOrderController.php resources/js/pages/fleet-assets/components/fleet-hero-kit.tsx resources/js/pages/fleet-assets/components/fleet-hero-kit.test.tsx resources/js/pages/fleet-assets/daily-check.tsx resources/js/pages/fleet-assets/dashboard.tsx resources/js/pages/fleet-assets/incidents/index.tsx resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.ts resources/js/pages/fleet-assets/maintenance/work-orders/work-order-filters.test.ts resources/js/pages/fleet-assets/mileage/index.tsx resources/js/pages/fleet-assets/reports/index.tsx resources/js/pages/fleet-assets/vehicles/index.tsx tests/Feature/FleetAssets/DashboardHeroContractTest.php tests/Feature/FleetAssets/FleetHeroRolloutContractTest.php tests/Unit/FleetAssets/FleetReportPeriodTest.php docs/superpowers/plans/2026-07-10-fleet-hero-rollout-continuation.md
git diff --cached --check
git diff --cached --stat
```

Expected: the staged set contains exactly twenty files in total and has no whitespace errors.

- [x] **Step 3: Commit locally**

Run:

```powershell
git commit -m "feat(fleet): finish hero rollout consistency pass"
```

Expected: one local commit on `claude/frosty-leavitt-f99798`. Do not push.

- [x] **Step 4: Confirm the final branch state**

Run:

```powershell
git status --short --branch
git log -3 --oneline --decorate
```

Expected: the worktree is clean; the three Fleet rollout commits are visible; the branch remains local-only unless the user separately authorizes a push.

## Verification evidence — 2026-07-11

### Recovery and review

- Continued only in `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\strange-bhaskara-ae2843` on `claude/frosty-leavitt-f99798`; preserved dashboard commit `810a9968` and Wave A commit `31349a26`.
- Independent spec review passed after the Overdue status option was made visible.
- Independent final code review reported no blocking findings after the report-window, lazy CSV, expired-compliance, Work Order completion-transition, and filter-state fixes.
- The proposed cross-tenant rewrite was rejected after repository evidence showed the current app is explicitly single-tenant and the suggested nullable legacy `fleet_work_orders.tenant_id` filter would hide valid records. The all-Control-Room badge requirement is now locked by a non-Fleet compliance-alert fixture.

### Red-green evidence

- Work Order filter: missing/incorrect visible overdue state failed first; final focused Vitest is 6/6 for the filter helper.
- Report period: the six-month trip was absent from `period=1y` before the shared resolver; the final contract includes it and excludes the two-year trip. Hostile `period=-1` now falls back to 30 days.
- CSV streaming: the trip export contract observed two `fleet_trips` queries before the callback before the fix; final trip and fuel contracts prove the queries occur only when `streamedContent()` runs and assert exact joined/cast CSV rows.
- Compliance: backend contracts first failed because `rego_expired`/`cof_expired` were absent; the shared badges now give expired WOF, Rego, CoF, and insurance critical precedence over due-soon counts.
- Work Order completion: the request-level PUT regression first completed successfully but left `completed_at` null. The server now stamps completion, clears it on reopen, and re-stamps on recompletion; the hero metric follows those transitions.

### Terminal verification

- `php vendor/phpunit/phpunit/phpunit tests/Unit/FleetAssets/FleetReportPeriodTest.php`: exit 0, 3 tests, 30 assertions.
- `npx vitest run ...fleet-hero-kit.test.tsx ...work-order-filters.test.ts`: exit 0, 2 files, 10 tests.
- Scoped Pest matrix (`DashboardHeroContractTest`, `FleetHeroRolloutContractTest`, `FleetMaintenanceWiringTest`, `FleetIncidentTest`): exit 0, 31 tests, 312 assertions. One non-failing schema-bootstrap `fwrite()` notice was emitted; every selected test passed.
- `npm run types`: exit 0.
- Targeted ESLint across the eleven changed Fleet TS/TSX files: exit 0, 0 errors. Three existing `no-restricted-syntax` warnings remain at `incidents/index.tsx:445`, `:452`, and `:459`; those filter buttons are outside this two-line incidents tone change.
- PHP lint: all five changed controllers plus the three changed/new PHP test files report no syntax errors.
- `npm run build`: exit 0, 4,939 modules transformed, built in 4m 14s.
- `npx vite build --ssr`: exit 0, 1,591 modules transformed, built in 46.83s.
- `git diff --check`: exit 0 throughout the final review cycle.

### Formatting boundary

- The three new TypeScript files pass Prettier after being formatted in isolation.
- The plan's five inherited changed-page Prettier check exits 1. The exact `HEAD` versions of all five files also exit 1, proving this is inherited whole-file formatting debt rather than a regression. No broad page rewrite was performed merely to churn unrelated formatting.

### Browser boundary

- The fresh client build was served from this worktree with PHP's local server on `127.0.0.1:8001`; requested ports 8766/8767 refused PHP's bind despite having no listener, so the documented fallback was used.
- `/fleet-assets` consistently redirects to `/login`. The in-app browser had no reusable signed-in local tab, and no credential was supplied or entered. Consequently the protected Fleet desktop, responsive, dark-mode, interaction, and console checks remain unverified rather than being overstated.
- `.claude/launch.json` was not modified or staged. Nothing was pushed.

## Recovery checkpoint — 2026-07-12

- Task isolation was rechecked before repository commands: Client Profile, HR, and IT were unloaded; Fleet was the only active Oblivion Findings task.
- Verified the exact existing worktree `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\strange-bhaskara-ae2843` on branch `claude/frosty-leavitt-f99798` at `c705d567239fffa7a2da326b32d379ac3f7ef477` (`feat(fleet): finish hero rollout consistency pass`). The worktree was clean before this ledger-only update.
- Per the user's recovery instruction, no Chrome session, local preview, browser route, interaction, theme, viewport, console check, automated test, static check, or build was run in this checkpoint. Task 4 Steps 2 and 3 therefore remain unverified; no Fleet URL was visited and no new browser evidence is claimed.
- No source code or preview configuration was changed. The only change from the verified checkpoint is this uncommitted ledger entry.
- Port `8001` was checked after the deferred browser pass and was not listening. No temporary preview process required shutdown.
