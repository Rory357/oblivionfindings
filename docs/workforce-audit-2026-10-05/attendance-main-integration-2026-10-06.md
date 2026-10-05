# Attendance integration - 6 October 2026

Attendance clock-in, clock-out and correction now retain worker entries after validation failure, prevent accidental repeat writes, and confirm a saved result only from the exact committed action and session. Unknown or contradictory outcomes remain held for explicit record review. Success reports the actual recorded time, breaks, Timesheet synchronization and Handover outcome.

The three existing wizards retain their workflow and use the shared calendar/clock controls with the configured worker timezone. Overnight end dates and daylight-saving gaps/folds are explicit. Saved tracked breaks are retained; zero extra minutes leaves the canonical writer to calculate their floor. A sub-minute end asks the worker to check the end time.

The Attendance page uses the shared header and mobile session cards. Period totals cover the complete authorized result set, independent of pagination and list caps. Open-session estimates explain missing boundary evidence. Current employee/Site authority, clinical and task completion checks, audit behavior and payroll approval remain canonical.

## Included

- Additive committed Attendance result and transient Handover outcome.
- Three wizard recovery journeys and shared date/time helpers.
- Attendance summary/evidence/pagination presentation and backend projection.
- Preservation of the latest main branch's shared eMAR picker accessibility.
- Corrected task fixtures including genuine same-Shift stale-version rollback.
- Three extracted Attendance summary regressions.
- Opt-in native test-import fail-closed safeguard.

## Verification

- Main integration frontend: 80 tests across 9 files passed.
- Main integration whole-application TypeScript passed after generating its own routes.
- Main integration production build passed (4m50s); changed frontend lint and whitespace checks passed.
- Main integration backend: 52 cases / 727 assertions passed with exit 0 (39 receipt/preservation, 10 index payload and 3 summary cases). All owned test processes exited; both previews were retained. The isolated checkout reports a suppressed phpdotenv missing-.env warning for each case; a read-only exact-root probe reproduced that infrastructure warning. No assertion failures or errors occurred, and no preview environment file was copied.
- Workforce source backend: 39 distinct Attendance cases /647 assertions passed.
- Real development browser on port8766: Demo clock-in, calendar/time review, clock-out, actual draft Timesheet3 creation and closed-session link verified. Console had no errors. Narrow viewport confirmed dialog fit and the mobile card branch. This is the shiftless self-service positive journey; selected-duty and manager cases have automated rather than new browser-identity proof.

Build and native-test evidence is retained under ignored test-results in the integration checkout. Native testing uses an isolated database and must not target either development preview database.

## Boundaries and remaining work

This batch excludes the separate Leave reservation repair, template apply, suggestions, asynchronous eligibility refresh and earlier Respite purpose changes. Control Room stays independent. The protected eMAR worktree/preview/database are unchanged. Administrative end-session, break and handover actions retain their preexisting UI handling and remain part of the broader recovery audit.

The full Workforce programme remains active. This integration is not whole-programme completion or a claim that all GitHub checks pass. The previous Availability-only commit99814bb20 encountered wider backend CI failures in modules whose source it did not modify; its two Attendance task-fixture failures are addressed here, while the other CI failures remain separately tracked.
