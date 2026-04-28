# Shifts Route Deprecation Checklist

Last verified from code: 2026-04-28.

Status: completed for the development application.

The legacy route names `shifts.*` and `timesheets.*` have been removed. Scheduler/admin Shift and Timesheet writes now resolve only through the canonical `operations.*` route names. The remaining `/shifts/*` and `/timesheets/*` legacy URLs are unnamed GET-only redirects for deep links.

The production telemetry waiting period from the original plan does not apply to this repository state because the application is still in development.

## Remaining Legacy URL Redirects

| Legacy URL | Canonical successor | Behavior |
| --- | --- | --- |
| `GET /shifts` | `operations.shifts.index` | 301 redirect |
| `GET /shifts/create` | `operations.shifts.create` | 301 redirect |
| `GET /shifts/{shift}` | `operations.shifts.show` | 301 redirect |
| `GET /shifts/{shift}/edit` | `operations.shifts.edit` | 301 redirect |
| `GET /timesheets` | `operations.timesheets.index` | 301 redirect |
| `GET /timesheets/approvals` | `operations.timesheets.approvals` | 301 redirect |
| `GET /timesheets/create` | `operations.timesheets.create` | 301 redirect |
| `GET /timesheets/{timesheet}` | `operations.timesheets.show` | 301 redirect |
| `GET /timesheets/{timesheet}/edit` | `operations.timesheets.edit` | 301 redirect |

## Removed Route Names

The following legacy route names should remain absent:

- `shifts.index`, `shifts.show`, `shifts.create`, `shifts.store`, `shifts.edit`, `shifts.update`
- `shifts.start`, `shifts.complete`, `shifts.cancel`, `shifts.reopen`
- `shifts.assign`, `shifts.unassign`, `shifts.replacement.request`, `shifts.replacement.cancel`
- `shifts.tasks.update`, `shifts.series.store`
- `timesheets.index`, `timesheets.approvals`, `timesheets.create`, `timesheets.store`, `timesheets.show`, `timesheets.edit`, `timesheets.update`
- `timesheets.submit`, `timesheets.resubmit`, `timesheets.approve`, `timesheets.reject`, `timesheets.return`
- `timesheets.bulkApprove`, `timesheets.bulkReturn`, `timesheets.bulkReject`

## Not Deprecated

The `attendance.*` routes are canonical frontline routes and remain in `routes/shifts.php`.
