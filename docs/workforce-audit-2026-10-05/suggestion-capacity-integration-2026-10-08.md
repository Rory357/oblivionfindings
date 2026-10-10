# Current staffing capacity and Suggestions source privacy

8 October 2026, Pacific/Auckland. Integration increment following `0c3a92f19202333e26c7bac98d04af968030f133`; saved locally, not yet delivered to main. This application is single-tenant: existing roles, approved Sites, canonical ownership and privacy remain the boundaries. Control Room remains independent. No schema, grant, Leave policy, qualification policy or independent eMAR/Fleet change is included.

## Changed behaviour

- Suggestions generation and display intersect employee duties with their current canonical Client and run Site. Invalid duty/Client/Site rows are withheld before their suggested workers are loaded. Foreign service-context labels and raw generation-error details are not exposed; stored diagnostics and historical run totals remain intact.
- The screen distinguishes recorded, visible and filtered counts. Rankings and recorded weekly hours are labelled as historical. Server-provided action permissions and URLs govern the buttons, including the existing ability to dismiss expired choices. Missing capabilities and failed reloads have explicit recovery. A run change resets local filters and busy state. Shared headers, status badges, worker-zone dates, search and existing actions are preserved.
- Mutation-time coverage uses uncached current database reads inside the existing physical transaction and Workforce mutex. Demand, current assignments, role evidence, reservations and recurring-series counters are read consistently. A preceding assignment in the same accepted batch therefore consumes capacity before the next assignment is checked.
- QuickFill, automatic planning and assignment reservations use current exact-window hold counts and owned-token deduplication. Current reports-permission evidence and current Site/profile access are used for the existing Site bypass. Default advisory memo behaviour, overstaffing policy, planned/open-duty meaning, role calculations, UTC windows, expiry, ownership and legacy null-rule behaviour are preserved.

Current source availability is not a promise of assignment eligibility. The existing writer still decides whether each assignment is allowed. Reservation action/account requirements remain the existing callers' responsibility; this increment does not certify every entry point's authority.

## Verification

**92 distinct backend cases / 1,915 passing assertions are reconciled across two runs, not a fresh clean run of all 92.** The final source retains 89 unchanged passing cases / 1,876 assertions from `workforce-main-capacity92r2-final`, and three corrected worker-timezone cases / 39 assertions from `workforce-main-suggestionzone3-final`.

| Area | Cases | Passing assertions |
| --- | ---: | ---: |
| Current capacity and reservations | 30 | 810 |
| Suggestions privacy, generation, permissions and timezone | 24 | 339 |
| Existing coverage calculations | 7 | 27 |
| UTC, overnight and reservation boundaries | 12 | 133 |
| Shift save, hold cleanup and rollback | 13 | 420 |
| Accepted workload and saved-warning preservation | 5 | 157 |
| Existing HTTP suggest/accept/apply journey | 1 | 29 |
| Total | 92 | 1,915 |

The capacity cases include a reused service with two distinct workers, current committed supply/demand/hold changes hidden by an earlier repeatable-read snapshot, role evidence, expired/released holds, revoked report access with an allowed profile-access control, and complete accepted-batch rollback when a later assignment has no remaining place.

Suggestions UI behaviour passed 16 tests, full TypeScript and scoped ESLint. The final copy-only correction removed an unsupported promise that the roster stays in draft; assignment can change a draft Shift to scheduled. The final production build passed in 5m29s, entry `assets/app-DMxAeG7-.js`, with the existing chunk-size advisory. No browser journey is attributed to these automated checks.

All five application files received independent backend review. Syntax and scoped formatting checks pass. The Controller's pre-existing multiline empty constructor is retained; its owned methods match the formatter projection rather than changing an unrelated constructor. The unrelated `tests/Feature/FleetAssets/FleetRealtimePrivacyTest.php` modification is excluded.

### Failed attempts retained honestly

1. The first launcher split a literal-space test-name filter on Windows: zero scenarios ran. The equivalent escaped-space filter was enumerated before the next attempt.
2. The 92-case run completed with 91 passing cases and one invalid fixture: both worker and global application timezones were empty. Laravel rejected that global configuration during HTTP termination. That failed identity is not counted as passing.
3. A direct-controller version of that invalid fixture also failed in framework clock use within Site access: 23 cases / 326 passing assertions and two partial failed-case assertions. The partial assertions are excluded.
4. The final fixture restores the ordinary authenticated HTTP request and tests a supported empty worker timezone with a valid UTC application timezone. Configured and null worker-timezone controls also passed. No clock or authority mock was introduced, and invalid global timezone configuration is not certified.

A separate corrective-start admission waited for an unrelated Wayfinder build; it ran zero scenarios and created no schema. Existing missing-isolated-environment warnings remain in raw test output; no error, failed, skipped or risky case is counted in the reconciled acceptance.

The final independent cleanup at `2026-10-07T21:35:50Z` confirmed no owned/native test processes, schema or schema connections remained; all 5,731 frozen entries were unchanged and all five protected previews retained their process identity. Other builds and previews were preserved.

## Evidence and remaining work

Ignored evidence is under integration `test-results/`:

- `workforce-main-capacity92-unique-final-receipt.json`, SHA256 `2554628b3f33de706795d02b5bfedfefe83fe961309e26e654a78655e6e1a928`.
- Raw initial, 92-case, intermediate 24-case and final three-case outputs and independent cleanup records; application and fixture reviews under `current-capacity-implementation-20261008`, `current-capacity-tests-20261008`, and the Suggestions read/fixture-repair folders.
- `suggestion-privacy-capacity-ui-final-vitest.json`, final TypeScript/ESLint logs and `suggestion-privacy-capacity-build-final.log`.

Browser acceptance and main/GitHub-main integration remain outstanding. The owned preview session expired. A specific login choice remains pending; automatic approval review rejected the earlier search for seeded-account credentials because that lookup was not specifically authorised. No retry, role change, authentication bypass or browser record mutation followed it.

Next: current locked Accept/Dismiss decisions and confirmed outcomes for all four suggestion actions. The read-only plan preserves existing status/expiry semantics and current accepted-workload application. It proposes request correlation, comparison with the source the user actually saw, and requester-only confirmation of a physically committed result. It does not promise durable idempotence or automatically retry an uncertain action. Then continue the remaining assignment producers, recurring/date issues and full cross-module/role/browser matrix. The wider programme is not complete.
