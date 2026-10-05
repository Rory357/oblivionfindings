# Leave integration - 6 October 2026

Concurrent Leave submissions now reserve pending hours against a locked current balance and check overlapping requests within the existing application lock. This prevents lost reservations and duplicate overlapping requests while retaining current entitlement, inclusive date boundaries, escalation and worker-calendar charging rules.

The self-service, HR management and Availability entries recheck their current actor, employee and approved-Site evidence before commit. Automatic hours are rechecked against the current employment and holiday evidence after waits. Availability leave remains pending unless the actor has the existing HR review authority. The saved message reflects that actual result. Review, cancellation and SLA changes recheck their existing authority after waits; pending and adjustment notifications wait for the outer transaction to commit.

This is a single-tenant application: roles, exact permissions, approved Sites and canonical record ownership are the boundary. No additional permission, grant, route, schema, queue or service provider is introduced.

## Included

- Complete reviewed LeaveService, HrLeaveAccessService and new HrLeaveEntryCommandAccessService.
- Existing application-mutex helper, using the migration-owned payroll mutex row.
- Only the explicit management actor and final SLA-review check in LeaveController.
- Exact owner comparison and truthful pending/approved message in StaffTimeOffController.
- Concurrent reservation, balance-year and command-freshness regression suites and guarded child-process helper; affected projection/timezone preservation fixtures.

## Verification

- All twelve PHP source/test files passed syntax and Pint checks; the adapted freshness test separately passed syntax, Pint and whitespace checks.
- Both independent saved-source reviews are clear. The exact main-integration native gate passed 118 cases / 833 assertions with exit 0 and no failures, errors or skipped cases. It includes all 41 freshness cases; the earlier 117 estimate was incorrect. All owned test processes and children exited, and both previews were retained. The deliberately empty checkout reports the same suppressed phpdotenv missing-.env warning as the Attendance gate; no preview environment was copied.
- Workforce source proof: approved reservation and preservation selection passed 72 cases / 628 assertions. This is distinct from the expanded main-integration gate.

The guarded native child now validates the actual application cache paths before bootstrap, including Windows path handling. The main-integration test snapshot includes optional eligibility-refresh stores only when they are installed. Main does not yet have that separate system; no production feature is imported to satisfy a test. All installed Leave, balance, projection, cover, ledger and audit records retain no-side-effect assertions.

## Boundaries

The user explicitly approved the Leave reservation/entry repair. This integration includes its earlier reviewed authority/year prerequisites absent from main. Separate coverage-read presentation, adjacent stay-purpose HR cover, broader quiet/accrual writers, supporting-document cleanup, synchronous decline notices and unrelated asynchronous eligibility work remain outside this increment. Control Room and the protected eMAR worktree, preview and database are unchanged.

No new browser identity, permission or positive manager journey is claimed. The existing HR/Availability frontend is unchanged; the changed HTTP outcomes are covered by the native integration selection. The full Workforce improvement goal and wider CI findings remain open.
