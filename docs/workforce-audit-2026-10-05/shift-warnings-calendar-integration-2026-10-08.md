# Saved staffing warnings and coverage time boundaries

8 October 2026. This follow-up to local checkpoint 94f39dd8d9aac05a8f7a22f4331c35af53c4abdb has passed its focused automated checks. Browser persistence, main integration and push remain outstanding.

## Result

A newly saved single shift now keeps the current staffing warnings visible until Done. The server includes warnings from that committed assignment in its save receipt, including an explicit empty list when there are none. The form ignores old session warnings. Missing or malformed evidence retains the uncertain draft and prevents a duplicate submission. Existing update and recurring flows retain their contracts.

Weekly coverage rules now use the configured worker timezone, including overnight demand carrying into the first displayed day. Shift and reservation database comparisons use the same UTC instants, preserving caller offsets without mutating supplied dates. Reservation creation, exact-window capacity, token validation and release share that conversion. Capacity, expiry, ownership, approved-Site and lifecycle rules remain intact.

The delivery contains 11 application/test files: three backend files, two UI files, two UI test files, three existing backend test files and one new boundary test file. It adds no schema, qualification or modified-duty policy, and does not change Control Room.

## Verification and limits

- Current focused backend acceptance: 61 distinct cases / 1,271 assertions. This is 59 passing cases / 1,176 assertions from the actual 61-case run plus two corrected cases / 95 assertions. The first run's 59 failed partial assertions are excluded.
- The accepted classes contain 24 new coverage boundary cases, seven existing coverage service cases, 29 selected command-integrity cases and one existing controller reservation case.
- Applied frontend verification: 38 distinct cases across the form and save-command hook; full TypeScript and scoped ESLint pass.
- The normal production build passes in 4m20s, producing app-Crn-Z_Lo.js. The existing chunk-size advisory remains.
- All owned backend processes, schemas and connections are gone. Final cleanup verified 5,645 frozen files and all five protected previews unchanged. Missing-root-environment-file warnings were recorded; they are not hidden.

The initial backend attempt failed during setup because its disposable database name exceeded the MySQL limit and produced zero application assertions. The shorter, guarded attempt exercised all 61 cases and exposed two incorrect fixture expectations about Laravel flash ageing. The repair explicitly verifies the stale warning before the next request, verifies its old-flash marker, then expects expiry after that request. It retains every current receipt, actor, hash, privacy and persisted-state assertion. Only that test method changed between the retained 59 passing results and the corrective two; application dependencies are identical. Original failure artifacts remain available.

Historical checkpoint 94f has separate acceptance of 146 backend cases / 3,053 assertions and 50 UI cases. Those results are not presented as a fresh full-suite run on these changed files.

## Evidence

Ignored integration artifacts retain the raw runs and exact source transitions:

- workforce-main-shiftwc61r2-final*: actual 61-case run, failed fixture observations and owned cleanup.
- workforce-main-shiftwarn2-final*: corrective two-case run and final owned cleanup.
- workforce-main-shiftwarningcalendar61-unique-final-receipt.json: exact 61-identity reconciliation, source transition and exclusions.
- shift-warning-calendar-integrated-vitest.json and its log: actual applied 38-case UI run.
- shift-warning-calendar-types.log, shift-warning-calendar-eslint.log and shift-warning-calendar-build.log.
- shift-warning-calendar-applied-20261008.json: guarded initial application.
- shift-create-warning-flash-age-fixture-repair-20261008/parent-applied.json: reviewed one-method fixture correction.

The saved-warning pane has not yet been checked in the browser. The verification login expired, and automatic approval review rejected searching for login credentials without specific permission. The user can authorise the seeded development account or sign in manually. No browser Shift write, account change or authentication bypass has occurred. The browser verification helper must validate the new source and actual acceptance before any database baseline or create/edit journey.

## Remaining work

Finish desktop/mobile saved-warning and single-shift create/edit/readback verification; integrate the scoped commits into current main while preserving newer eMAR and CI changes; then push. Exclude the unrelated FleetRealtimePrivacyTest.php change.

Recurring civil-date alignment, current-capacity/concurrent-read and memo freshness, historical malformed holds, wider accepted-assignment workload and the connected-module/role acceptance matrix remain separate outstanding work. No qualification, modified-duty, cross-site respite or adjacent HR stay-purpose decision is inferred from this correction.
