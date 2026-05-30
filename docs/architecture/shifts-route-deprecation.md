# Shifts Route Deprecation Checklist

Last verified from code: 2026-04-29.

Status: legacy URLs are kept as compatibility redirects only; legacy route names are removed.

## Policy

- **Canonical surface:** `operations.shifts.*` and `operations.timesheets.*` (defined in `routes/operations.php`).
- **Legacy URL surface (`routes/shifts.php`):** kept as redirect-only mounts via `LegacyRouteRedirectController`, no controller logic, no route names.
  - **GET URLs** redirect with **301 Moved Permanently** (deep links from emails/bookmarks).
  - **POST/PATCH/PUT URLs** redirect with **308 Permanent Redirect** so HTTP clients re-issue the same method+body against the canonical URL transparently.
- **Legacy route names** (e.g. `shifts.start`, `timesheets.approve`) are removed; callers must use `operations.*` names.
- **Attendance routes** (`/attendance/*`) remain canonical in `routes/shifts.php` per PR 4.5.

## Why 308 instead of just deleting POST routes

A blanket deletion would 404 any external integration, mobile app, or automated script still posting to a legacy URL. 308 honours the original "no breaking changes without redirects" constraint while keeping the canonical surface clean: the redirect mounts are unnamed, controller-less, and one line each. They can be deleted in a future cleanup once telemetry confirms zero callers.

## Compatibility Redirects (GET, 301)

| Legacy URL | Canonical successor |
| --- | --- |
| `GET /shifts` | `operations.shifts.index` |
| `GET /shifts/create` | `operations.shifts.create` |
| `GET /shifts/{shift}` | `operations.shifts.show` |
| `GET /timesheets` | `operations.timesheets.index` |
| `GET /timesheets/approvals` | `operations.timesheets.approvals` |
| `GET /timesheets/create` | `operations.timesheets.create` |
| `GET /timesheets/{timesheet}` | `operations.timesheets.show` |
| `GET /timesheets/{timesheet}/edit` | `operations.timesheets.edit` |
| `GET /rostering`, `GET /rostering/{any}` | `/operations/rostering...` (in `routes/web.php`) |

## Compatibility Redirects (POST/PATCH/PUT, 308)

| Legacy URL | Canonical successor |
| --- | --- |
| `POST /shifts` | `operations.shifts.store` |
| `POST /shifts/series` | `operations.shifts.series.store` |
| `PUT /shifts/{shift}` | `operations.shifts.update` |
| `POST /shifts/{shift}/assign` | `operations.shifts.assign` |
| `POST /shifts/{shift}/unassign` | `operations.shifts.unassign` |
| `PATCH /shifts/{shift}/start` | `operations.shifts.start` |
| `PATCH /shifts/{shift}/complete` | `operations.shifts.complete` |
| `PATCH /shifts/{shift}/cancel` | `operations.shifts.cancel` |
| `PATCH /shifts/{shift}/reopen` | `operations.shifts.reopen` |
| `POST /shifts/{shift}/replacement-request` | `operations.shifts.replacement.request` |
| `PATCH /shifts/{shift}/replacement-request/cancel` | `operations.shifts.replacement.cancel` |
| `PATCH /shifts/{shift}/tasks/{task}` | `operations.shifts.tasks.update` |
| `POST /timesheets` | `operations.timesheets.store` |
| `PUT /timesheets/{timesheet}` | `operations.timesheets.update` |
| `POST /timesheets/{timesheet}/submit` | `operations.timesheets.submit` |
| `POST /timesheets/{timesheet}/resubmit` | `operations.timesheets.resubmit` |
| `POST /timesheets/{timesheet}/approve` | `operations.timesheets.approve` |
| `POST /timesheets/{timesheet}/reject` | `operations.timesheets.reject` |
| `POST /timesheets/{timesheet}/return` | `operations.timesheets.return` |
| `POST /timesheets/bulk-approve` | `operations.timesheets.bulkApprove` |
| `POST /timesheets/bulk-return` | `operations.timesheets.bulkReturn` |
| `POST /timesheets/bulk-reject` | `operations.timesheets.bulkReject` |

## Removed Route Names

The following legacy route names are absent and asserted-absent by `LegacyShiftNamesRemovedTest`:

- `shifts.index`, `shifts.show`, `shifts.create`, `shifts.store`, `shifts.edit`, `shifts.update`
- `shifts.start`, `shifts.complete`, `shifts.cancel`, `shifts.reopen`
- `shifts.assign`, `shifts.unassign`, `shifts.replacement.request`, `shifts.replacement.cancel`
- `shifts.tasks.update`, `shifts.series.store`
- `timesheets.index`, `timesheets.approvals`, `timesheets.create`, `timesheets.store`, `timesheets.show`, `timesheets.edit`, `timesheets.update`
- `timesheets.submit`, `timesheets.resubmit`, `timesheets.approve`, `timesheets.reject`, `timesheets.return`
- `timesheets.bulkApprove`, `timesheets.bulkReturn`, `timesheets.bulkReject`

## Not Deprecated

The `attendance.*` routes are canonical frontline routes and remain in `routes/shifts.php`. They are not redirected and must keep their URI and route name (per PR 4.5).

## Cleanup criteria for a future PR

The legacy URL mounts can be safely deleted from `routes/shifts.php` once all of the following hold:

1. Production traffic to legacy URLs is zero for a verification window (recommended: 30+ days).
2. No external integration (mobile app, HRIS sync, automation) is documented to use legacy URLs.
3. The Inertia frontend has no remaining hardcoded legacy URL strings (verified by `LegacyShiftNamesRemovedTest::test_legacy_shift_file_only_keeps_redirects_and_attendance_routes`).

When the criteria are met, delete the redirect mounts, retire `LegacyRouteRedirectController` (or trim its responsibilities), and update this document.
