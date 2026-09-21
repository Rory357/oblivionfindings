# PKG-02A — Main first-slice interim review

Owner MAIN ASTRA. Revision 1, 2026-09-21. **First-slice corrections and QA continue; no next-slice or integration release.** This is a review of mutable implementation source, not approval of a frozen candidate.

The Designer delivered its substantive 45-minute progress checkpoint in task 01a0be31-19ef-7d10-86a9-cbe968989a76. Its exact source manifest and written browser/checkpoint evidence are still being prepared. Main independently reverified the same active turn 01a0c0b2-6f35-75e0-93c5-03dd65d8e2d9 at 2026-09-20T22:18:57.035Z: gpt-6-astra/xhigh in both turn and collaboration fields. Sole Designer frontend/backend ownership, PKG-01 custody hold, frozen previews and protected references remain in force.

## Independently inspected source

Main read the new access/history/draft services, request/controller/models/migration, changed ClientController and operations routes, shared privacy hook and its regression tests, workspace/history/map/dialog source, and new backend tests. Main also inspected the canonical privacy, consent, assignment, geofence, history, authorization-evidence and profile-access dependencies. No application files were edited; no tests, application bootstrap, database writes, build or browser requests were run by Main during this review.

The candidate stores draft shapes in private immutable revisions, with no activation endpoint or explicit downstream dispatch in the inspected save service. History now intersects current assignment/collection/consent/retention and calendar bounds and rechecks access after reading. The shared hook adds request cancellation, generation/context checks and terminal access clearing. These are source observations, not proof that all races, regressions or browser acceptance checks pass.

## Corrections sent to the existing Designer

1. **Initial profile boundary disclosure.** ClientLocationZoneDraftService excludes boundaries associated with another client through asset or assignedAssets. ClientController::buildLocationData still selects explicit/fallback active same-site house/resident boundaries without those exclusions. The new map receives this separate payload through location.geofences. Use a single exact eligibility resolver across both touched consumers and cover initial profile payload plus zones lookup for another client's same-site personal boundary. A passing lookup test alone does not protect the profile/map.
2. **Silent canonical-to-custom conversion.** ZoneDraftDialog resolves its initial linked boundary only when both stored ID and hash match the lookup. A missing/changed match becomes null; save then chooses geometry_source=custom, even after a name/schedule-only edit. Retain the stored source identity and require an explicit stale/unavailable-boundary review path. Do not silently sever canonical provenance. Cover stale hash and ineligible/missing source behaviour.
3. **Current-observation provenance and time bounds require proof/correction.** The current presenter still reads Device/meta coordinates without validating their observation time against the current assignment/collection/retention window. latestAddressForDevice independently selects the latest non-null address, rather than the same accepted coordinate/time observation. History filtering does not protect these fields. Demonstrate exclusion of prior/out-of-window observations and that displayed address/accuracy/time describe the same accepted observation; otherwise correct this narrow presenter. This is a concrete source concern requiring regression evidence, not a claimed executed disclosure reproduction.

These are in-scope first-slice corrections. They do not add product requirements, reopen mockup/model/timing approval, authorize another writer, or release later functionality. The Designer has been told to include exact resolution evidence in its checkpoint.

Post-inspection identities captured at 2026-09-20T22:27:20Z (mutable source, not a freeze manifest):

- app/Http/Controllers/ClientController.php: 908B019623CEABA98C3CC7B7C593F4E307A4AAAD462036AB019AEF81D6B9EF87.
- app/Services/Tracking/ClientLocationZoneDraftService.php: A57A8E6EB902EA2C1551EBC4CAA1D50E2094CF3A7C3CECF995ABD048075EDD2A.
- resources/js/components/client-location/zone-draft-dialog.tsx: E198EC2B85987E8A31DDE772AE1FFF35D86A8A954C343F5EA068EA412195DD5E.
- resources/js/components/client-location/location-workspace.tsx: 350E8A027B8766879AD513EE58B0DE2E23EE7AC5D7D6CE495CFEC88ACB27E0AF.
- app/Services/Tracking/ClientLocationHistoryService.php: 7F24E9A2230A92278ED867979255C15A3E0C9DCA274B9AE0B862DAC98E211A49.

## Test and browser evidence status

Designer reports a successful production Vite build, full TypeScript, targeted ESLint, 17 frontend tests, latest zone suite 10 tests/65 assertions, earlier history tests and empty/populated migration rollback checks. These have not been independently rerun by Main. Earlier build identity 75FA3BDAC5B8251C35B41DB4B51F1EB37301215D473217B0E423016EDD9D1281 must not be treated as the final identity after subsequent corrections.

Main read the broad regression log totals: 41 tests, 508 assertions, two failures and 39 warnings; a smaller repeated candidate run records 16 tests, 93 assertions, two failures. The failed assertions are FleetRealtimePrivacyTest.php lines 77 and 120. The test's positive cases use Fleet Tracking consent, while the inspected resident-location allowlist contains only Personal Tracker (Wandering Risk). The Designer explicitly clarified that repeated execution used unchanged HEAD bytes for the relevant tests/services/fixtures inside the changed candidate, **not a pristine whole-HEAD execution**. Treat these as unresolved regression failures until the exact dependency/execution evidence supports a bounded classification. No privacy relaxation or pass waiver is approved. Passing assertions before each failure do not prove later unreached assertions.

Designer reports isolated synthetic browser schema and port 4335 proof, real polygon/schedule/exception persistence through reload, revision 2, circle radius/keyboard editing, undo/redo and context-menu parity. Main has not independently inspected that browser proof or rendered this implementation. The full checkpoint must identify exact source/build/schema, coverage, failures/warnings and remaining requirements. Compact 640×400 checks are desktop/accessibility evidence; they are neither mobile scope nor actual 200% browser zoom proof.

## Next gate

Finish first-slice corrections and freeze the exact review candidate. Main then verifies manifest, relevant source/transaction races, test/failure classification and browser isolation/fidelity before deciding any subsequent scoped engineering release. No whole-package PASS, integration, merge/push, new command endpoint, outing mutation, operational evaluator/activation/promotion or recipient grant is released. Existing actual policy prerequisites and final publication/user-acceptance gates remain.
