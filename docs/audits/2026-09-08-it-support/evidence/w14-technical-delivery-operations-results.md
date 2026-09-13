# W14 technical delivery operations — 12 September 2026

Mappings: W14/F06/B04/E13; extends W13 Operations audit. This slice remains In progress. Operator retry is implemented and covered by focused automated tests; browser verification and complete package acceptance remain open.

## Latest retry UI and search follow-up

The detail dialog now fetches a current actor/source/record-bound review, requires explicit consent and posts the reviewed version to the canonical retry endpoint. Stop waiting and closing abort only the browser wait; they do not claim cancellation of a server transaction. Unknown outcomes require refresh, stale reviews require new review/consent, and malformed responses never produce success. Live access loss conceals source counts, history and dialog content and restores focus to the unavailable-state explanation. Shared response parsing only accepts supported states, valid counters/dates and the current actor/source/record; raw outcome properties are discarded.

History search uses only displayed classifications and exact IDs inside the same current source authorization scope. It cannot search private diagnostics. Filtered pagination preserves the encoded query and canonical history anchor; headline facts continue to describe the entire authorized source scope. Review and history now share the same safe row projection.

- Backend session75935/token it_bc9e11843da14232: **59 passed,553 assertions,274.85s**, terminal0; all14 postflight checks passed and exact schema absent. Log `w14-technical-search-tests.txt`. Includes17 operations tests,14 Device recovery tests and28 Fleet delivery tests.
- Final UI selection: **61 passed,4 files,4.67s**, terminal0, log `w14-technical-search-ui-tests.txt`. Includes18 recovery cases,9 health/history cases and34 existing operations/setup cases. The preceding59-case run is retained in `w14-technical-retry-ui-tests.txt`.
- Full TypeScript33879 terminal0 with no diagnostics. Final scoped ESLint terminal0 with no diagnostics after correcting the effect-cleanup ref warning recorded in the preceding lint run. These empty-output checks may not produce Tee files; tool terminal results are the evidence, not an invented log.
- Independent worker session58530/token it_7148012e23134dd4: **1 passed,62 assertions,262.37s**, terminal0/all14 postflight checks/exact schema absent. Both Device and Fleet cases force two workers to wait on the same outbox lock: exactly one retry allowance commits; when permission is revoked during that wait, both requests deny without allowance or retry audit. Exact log `w14-technical-retry-worker-tests.txt`.
- Current build44922 terminal0,3m38s, app-3uQjQ9dl.js, manifest72cb583b2a5ac87d6a8b4cd2fc93ab1f3e77ad0a7097c1a1d5c6af9ae132479c. Build log and identity saved. Sixteen current source hashes are in `w14-technical-retry-validation-source-hashes.json`; all10 protected-design files remain unchanged. Build success does not establish browser acceptance. No live owned test/build/browser runtime remains.

Additional application files: `ItTechnicalDeliveryRecoveryService.php`, `ItTechnicalDeliveryController.php`, `it-technical-delivery-contract.ts`, `it-technical-delivery-recovery.tsx`; updated `ItTechnicalDeliveryService.php`, `ProtectItDraftResponses.php`, `routes/web.php`. Additional tests: `it-technical-delivery-recovery.test.tsx`, `ItTechnicalDeliveryRetryConcurrencyTest.php` and its isolated worker helper. Earlier chronological notes below remain historical evidence.

## Implementation

`ItTechnicalDeliveryOperationsPresenter` reads the existing DeviceEventSignalOutbox and FleetSignalOutbox IT outcomes. It does not infer IT success from a source acknowledgement, copy provider diagnostics, add a scheduler, or create tickets. Counts and bounded history use the same SQL scope: current approved IT Sites, current canonical Device visibility, and canonical Asset visibility for Fleet. IT management and device-read permissions are required; missing schema/permissions return unavailable and null counts. Old acknowledgements without a proven original IT Site/outcome are outside the measured scope and remain explicitly unverified.

Setup Operations audit now mounts typed technical delivery health, separate Device/Fleet histories, attempt counts, recorded outcomes, oldest pending/failed delivery and latest completed IT delivery. It uses the approved EntityTable, shared context/kebab menu, simple detail dialog, LaravelPagination, status tokens and date helpers. Account mismatch or lost source access conceals history and open detail content. Only allowlisted diagnostic codes are displayed. Acknowledgement, IT outcome and actual device health remain distinct.

Changed application files: `app/Domain/It/Services/ItTechnicalDeliveryOperationsPresenter.php`, `app/Http/Controllers/It/ItServiceManagementSetupController.php`, `resources/js/components/it/it-technical-delivery-health.tsx`, `resources/js/components/it/it-service-operations.tsx`. New Feature and React regression files accompany the presenter and component. No migrations, provider requests, real communications or protected-design edits.

## Actual checks

- Initial PHP run32507 / token it_eb7c89d60ff64a93: 4 failed,1 passed,2 pending,15 assertions,181.97s, terminal1. The synthetic role attached keys from RbacSeeder, which did not define the required device permission. Corrected the fixture to explicitly create and attach the canonical source permission keys, matching existing source-access tests. No production authorization was relaxed. All14 postflight isolation checks passed; exact schema absent.
- Corrected PHP run23214 / token it_f0485481558d4d53:8 passed/112 assertions/238.84s, terminal0/all14 postflight checks/schema absent. Exact log `w14-technical-delivery-operations-retest.txt`. Also covers the real setup HTTP projection and page validation. This precedes the retry-service extension below.
- Initial UI run: existing34 tests passed; new suite failed to load because this repository does not install testing-library/user-event. Switched the keyboard test to the repository's existing fireEvent utilities; no dependency installation or application correction required.
- Final UI selection:41 passed /3 files /5.04s /terminal0. Covers the new7 cases plus the existing service-operations and setup-workspace tests. Log `w14-technical-delivery-ui-retest.txt`. New cases exercise truthful outcomes, role/account concealment, schema unavailable state, keyboard opening/focus return, context menu, pagination and unknown diagnostic concealment.
- Full TypeScript42889 terminal0 with no diagnostics. Scoped ESLint terminal0 with no diagnostics. Explicit outcome notes are saved because empty output did not create a tee log. Source whitespace check passed. Formatting logs retained. All10 protected design files match the previous verified baseline.

## Guarded retry follow-up

The existing ItTechnicalDeliveryService retry method now accepts a guard executed under its own outbox lock. ItTechnicalDeliveryRecoveryService rechecks the current actor, original actor identity, source access and reviewed version before granting one bounded attempt. The version includes current outcome, lifetime counters and canonical source binding; repeat/stale requests cannot silently grant another allowance. The service records retry intent independently of dispatch/delivery success and keeps existing queue-outage recovery and mandatory audit behavior.

GET review and POST retry endpoints under `/it/setup/technical-deliveries/{device|fleet}/{id}` use existing IT management authorization and the private-response guard. The browser retry button is not yet implemented. No new ticketing, outbox or scheduler record was added.

PHP run90709/token it_dde2344e43f24b51 completed56 passed/519 assertions/268.93s, terminal0/all14 postflight checks/exact schema absent; log `w14-technical-delivery-retry-tests.txt`. It selects the new operations tests plus existing Device delivery recovery and Fleet delivery contract suites. Cases cover guarded retry for both sources, repeated/stale requests, queue failure, forged actor/direct source denial, site loss and audit failure/debug redaction. The repeated-request cases are sequential HTTP requests; no independent competing-worker proof is claimed for this new retry guard. No build or browser runtime is active. Current11 application/test hashes are captured in `w14-technical-delivery-source-hashes.json`.

Independent changes appeared in `tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php` and `tests/Feature/Governance/GovernanceSpendDashboardScopeTest.php` during this work. They were not made or reverted by this slice; preserve them.

## Next work

Complete browser retry/recovery integration against the guarded endpoints, including permission concealment and uncertain outcomes. Preserve lifetime attempts and distinguish a recorded retry intent from a completed delivery. Integrate search/filter behavior with the existing operations header and prove the retry guard under independent competing workers. Then build once for current source and verify desktop permitted/restricted roles, history/detail keyboard focus and failure/recovery in a fresh owned browser runtime, without resizing. Full W14 source coverage and the release gate remain open.

