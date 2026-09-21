# PKG-02A revision 2 — care-assignment race and battery presentation

Status: frozen for Main's exact-code review; no integration, operational activation or next-feature release. Same worktree, branch, baseline and sole Astra implementer as the first checkpoint. Main released the narrow care-assignment correction; Stephan directly requested the battery/charging refinement and supplied an illustrative battery image. No additional user approval is required for these changes.

Candidate `source-build-manifest.json`: **104C266C439E20974CDB509EC543118E29273C3268C02483C9E867B5B48AA6C9**, 30 application/test files and 4,558 local preview build files. Build manifest: **C0B789FF1FE718A9A0B5FD9576489B74688B8A017A207294E4E4F1DFF7465BAD**. The original source manifest is retained byte-for-byte as `source-build-manifest-r1.json` (E81F1842A90B57EE47C4E026E2C1F2D2A565ADE88096575BCC1C0D643AC5C911). No application edits follow this new freeze.

## Care assignment

The prior save transaction's parent Client/User locks do not stop removal of an existing `client_user` pivot. The before-fix two-connection test demonstrated both failures under MySQL REPEATABLE READ: removal committed while the ordinary snapshot still contained the relationship, and removal also completed during a save that had already checked access. Both tests failed, with 10 assertions.

Inside the existing locked save path, `ClientLocationAccessService` now requires a transaction, reads the already-locked Client using a current locking read, then locks the exact `client_user` row for this Client and actor. It hydrates `supportWorkers` with only that current evidence for the existing ClientPolicy and ClientProfileSectionAccess checks. Lock ordering remains consent, Device, Client/Site, device assignment, RBAC/HR, care pivot, geometry/rule. Neither permission semantics nor the client assignment writer was changed.

The permanent tests use an assigned-only actor with `clients.viewAssigned`, `assets.viewAssigned`, telemetry viewing and tracker management. They perform the same exact pivot DELETE as worker removal on another PDO connection. Removal-first confirms the stale ordinary snapshot, then current access denial and zero draft/version writes. Save-first confirms MySQL lock-timeout 1205 while the transaction holds the care relationship, a successful draft revision, successful removal after commit, and denied reading afterwards. Outbound HTTP is prevented and queue/notification paths are faked.

Committed fixtures are necessary for two connections. Each test runs in its own process with `PreserveGlobalState(false)` and the existing actual-PID schema/shutdown cleanup. The test file supplies PHPUnit's local Composer entry constant when Pest has not defined it; it does not change vendor, the shared TestCase, XML profile or global test bootstrap. Installed PHPUnit reapplies forced XML environment before child TestCase setup. Observed child schemas had the exact package base and one actual numeric PID suffix. Final read-only isolation proof found no remaining package test schemas.

Executed ledger (each backend command had the immediately preceding reviewed isolation proof and `--configuration phpunit.pkg02a.xml`):

- Before correction: two interleavings failed, 10 assertions, exit 1.
- Corrected application, broad selection: 37 protected-location tests passed with 538 assertions. The two isolated tests failed before application bootstrap because Pest had not supplied the child Composer entry; total command exit 2. No green 39-test result is claimed.
- After the local child-bootstrap correction: save-first passed all nine assertions. Removal-first exercised the current policy denial but the child had loaded the older catch that expected only HttpException, so this two-test command exited 2 (15 assertions total). The test now handles Laravel AuthorizationException as the same forbidden result; application code did not change.
- Final filtered removal-first command: **one test, nine assertions, exit 0**, 4m49s. Both interleaving scenarios therefore have passing executed results, but a single combined green command is not claimed. The already-passing save-first case was not redundantly rerun after the catch/format-only test correction.

The 37-test selection covers zone draft saving, history/current observation boundaries, consent disclosure, asset security and empty draft migration rollback. Missing `.env` warnings are the previously documented intentional isolated-profile condition, not assertion failures. Raw local logs are excluded: `.pkg02a-care-race-before.log`, `.pkg02a-care-race-after.log`, `.pkg02a-care-race-final.log`, `.pkg02a-care-removal-final.log`.

## Battery and charging

Tracking source now includes a battery-shaped measured fill, large percentage and an adjacent lightning indicator. Explicit `charging_status=charging` enables a bounded sheen and pulsing bolt; percentage/fill do not artificially increase. Full, stopped/not-charging, external-power-only, and missing/invalid readings have distinct readable states. External power alone does not imply charging. Battery measurement time remains separate. The charging label is explicitly last reported; no charging timestamp or live connection is invented. Existing threshold data is used unchanged. Dark mode has a brighter green/amber presentation.

Reduced motion keeps the indicator and Charging text static, including when the OS preference changes while mounted. Animation is gated by both the component preference and CSS media query. The backend's existing protected battery and charging fields are reused; no ingest behavior, schema, hardware commands, polling, disclosure or thresholds changed.

Verification: **28 tests across five frontend files**, exit 0; includes charging/full/stopped/external-power/unknown/invalid/zero states and live reduced-motion switching. Full TypeScript and scoped ESLint with zero warnings passed. Final production build passed in 3m, with the existing >500 kB app chunk advisory. It was built in a worktree-local staging directory, copied by hashed asset names and installed by replacing the manifest last. Previous unused hashed assets remain in this local preview, explaining the larger file count; runtime uses the final manifest. No operational deployment occurred.

Real browser at `http://127.0.0.1:4335/operations/clients/1?tab=location`: existing synthetic 82% was retained (the supplied reference's 64% was not copied). Explicit synthetic charging produced an 82% fill and both named animations; normal and unknown states had no bolt or fill animation. The final contrast-only rebuild was reloaded and confirmed with #37d391 in dark mode, named animations, no horizontal overflow and no console warnings/errors. Reduced-motion preference changes were verified in the component test; real browser media emulation was unavailable and is not claimed. `browser/battery-charging.png` and `browser/battery-verification.json` record the result. The preview is left on the synthetic charging example; no real unit was polled.

## Remaining boundaries

The executed actual-fixture realtime diagnostic passed one test/11 assertions; see `realtime-regression.md`. It explains the general-tracking versus resident-location consent mismatch without altering the allowlist or declaring a pristine whole-HEAD baseline. The two original realtime failures remain unwaived for Main's disposition.

All 103 frozen v1/v2/v3 artifacts remain unchanged. The earlier slice's operational activation, evaluator, outings, new command lifecycle and recipient-sharing exclusions still apply. No broader concurrency proof is claimed beyond the exact care relationship tested here. No commit, staging, push, merge, operational migration or deployment occurred. Local `.pkg02a-*`, profiles, credentials, generated dependencies/builds and raw logs remain excluded from publication.
