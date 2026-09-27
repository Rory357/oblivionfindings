# Overview — current-main audit and process gap

MAIN ASTRA, 28 September 2026. Published source is present; this is not a retrospective pre-integration approval or final programme acceptance.

## Publication and authority

Main independently confirms ancestry through `2d78059526cb65245cb329bb1d8d8ef6a6ee38e1` (implementation), `d5eff35df770fd2dce582d853d43f2baa4a2de53` (evidence), and `ba5bff2e8b6c22796369443f1cdac918039950dd` (two-file visual correction). These are included in local/GitHub main `926b4981b0289da08a20baca0995117fb53e413e`.

The existing Designer's read-only packet identifies actual user implementation, completion and explicit local-and-GitHub publication instructions, superseding the stale Main design-only status. It found **no separate recorded Main pre-integration technical approval**. Record this as `MAIN-09A-PROCESS-01`, an open evidence/process gap. Neither the parent's self-review nor this later audit establishes that the original required sequence happened.

The v9 archive remains the frozen reference, SHA256 `E1962146DEF71CD4BDCF7F7878276FBF39275A0BE35850FFFC269E001B094F1F`. Historical source and visual QA are in `PKG-09A/implementation/verification.md` and `visual-parity.md`. The original Overview screenshot matches the committed reference according to the owner recheck. These are retained references, not permission to edit Rory's guides.

## Current shared-source audit

The owner identifies23 application/test paths atba5bff2e8:17 are unchanged at current main and six were later modified (`AssetController`, `AssetAssignmentService`, `VehicleLocationService`, Assets show, Assets routes and `Tests/TestCase`). Main inspected the current Overview projection, dashboard controller, receipt presentation, assignment confirmation service and Asset Profile route-state handling.

The Overview uses canonical scoped Fleet/Asset/booking/location services, distinguishes unknown availability, and presents registered Asset Site locations as approximate. Receipt rows retain their canonical Asset link. The newer Asset Profile explicitly maps the legacy `?tab=assignments` link to its Custody view, preserving the Overview handoff. Receipt confirmation still rechecks record, capability, Site and recipient authority, locks the current assignment, rejects future/released assignments, and preserves the first attestation on retry; newer code also increments the Asset Profile version.

Main independently reran the14 current `FleetOverviewContractTest` cases using `PKG09AMainCurrentOverviewTest.php`, its own disposable schema and the actual current main application. The first run completed14 cases/61 assertions with8 failures: the local optional Inertia SSR service at127.0.0.1:13714 was blocked by the explicit stray-request guard, preventing Inertia responses. Retained `PKG-09A-main-current-overview-initial-environment.log` and XML identify this harness dependency rather than a product regression. The wrapper disables optional SSR in test configuration only, preserving every assertion and the external-request guard. It also disables unrelated schema pruning/maintenance-file deletion. **The corrected run passes all14 tests/210 assertions in459.826 seconds.** Evidence: `PKG-09A-main-current-overview.log` and XML. This backend run does not certify SSR or replace rendered browser verification. Process exit and disposable-schema cleanup are recorded separately after shutdown.

## Retained verification limits

The corrected test process exited0. Main independently confirms zero remaining schemas/processes for both audit prefixes in `MAIN-consolidation-test-database-cleanup.json`. No Main application/test source changed. This closes the current backend contract rerun, not the historical approval gap or browser-zoom gate.

Owner historical evidence is47 unique backend tests/951 assertions across corrected runs, not a single clean combined47-case run. Its11 frontend cases and production build passed. The rendered checks exercised saved views, receipts, map/keyboard details and source navigation with synthetic data. Actual browser zoom was not verified and remains open, distinct from recorded window sizes.

This audit does not certify production load, operating providers, complete accessibility, device continuity or final user acceptance. Current main CI remains terminal red in tests/lint/visual; bootstrap passes. Earlier “checks running” and unqualified “all final checks passed” statements must not be used as current repository-wide success.
