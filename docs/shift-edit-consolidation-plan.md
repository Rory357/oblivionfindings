# Shift Edit Consolidation Plan

**Status:** Local verification passed; dev-server verification pending
**Recovered from:** `main` commit `19d00cef59d772d670132750a71baa9ccb8a6c2c`
**Current branch:** `codex/ShiftConsildation`

## Goal

Make `CreateShiftDialog` the single shift edit surface and retire the standalone
`/operations/shifts/{shift}/edit` page. Managers should edit shifts inline from
rostering and from the shift detail page without leaving the current workflow.

## Required Outcomes

- Add an authenticated editable JSON endpoint at
  `GET /operations/shifts/{shift}/editable`.
- Open the shared edit popup from the rostering week grid.
- Open the shared edit popup from the shift detail page.
- Preserve the old edit page's eligibility visibility by rendering current
  assignee warnings/blocks in `CreateShiftDialog`.
- Require an override reason before saving edits with overrideable eligibility
  warnings.
- Stop duplicate-shift flows from redirecting to the deleted edit route.
- Delete the old full-page shift edit Inertia page and remove the old route.
- Sweep app, resources, routes, and tests for removed shift edit route usage.
- Verify locally and on `oblivionfindings.com`.

## Implementation Ledger

- Added `ShiftController@editable` and route
  `operations.shifts.editable`.
- Added site-scoped editable payload serialization for dialog hydration.
- Added `serviceContexts` and `defaultServiceContextId` to the rostering page
  props.
- Rewired `WeekGridPane` edit actions to call `onEditShift` with a detail-page
  fallback instead of navigating to `/edit`.
- Added rostering-page fetch/open state for the shared edit dialog.
- Rewired the shift detail page Edit button to open `CreateShiftDialog`.
- Added eligibility preview, blocked/warning banners, and override-confirmation
  handling to `CreateShiftDialog` edit mode.
- Updated duplicate redirect behavior to return to the originating page or the
  shift detail page instead of `operations.shifts.edit`.
- Deleted `resources/js/pages/operations/shifts/edit.tsx`.
- Regenerated Wayfinder route/action helpers after removing the old route.
- Updated docs and inactive backup route inventory to remove the retired shift
  edit route.
- Added and updated backend, frontend, and route regression tests for the new
  editable endpoint and inline edit flow.
- Fixed an adjacent planning safety gap: shifts with approved linked timesheets
  are now blocked from planning edits through `ShiftStateGuardService`.

## Verification Ledger

- `php artisan test tests/Feature/ShiftControllerTest.php --filter=editable`
  passed.
- `php artisan test tests/Feature/Operations/ShiftSiteIsolationTest.php
  --filter=editable` passed.
- `php artisan test tests/Feature/Routing/LegacyShiftNamesRemovedTest.php
  tests/Feature/Routing/ShiftLegacyRedirectTest.php` passed.
- `php artisan test tests/Feature/TimesheetSafetyGuardsTest.php` passed.
- `npx vitest run
  resources/js/pages/operations/shifts/components/create-shift-dialog.test.tsx
  resources/js/components/rostering/rostering-redesign-followups.test.tsx
  resources/js/test/shifts-frontend-routes.test.ts` passed.
- `npm run types` passed.
- `npm run build` passed with existing Vite large-chunk/browser-compatibility
  warnings only.
- `git diff --check` passed.
- Exact route/string sweep for the removed shift edit route passed.
