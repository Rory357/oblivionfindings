# Combined candidate: Main technical review

**Final publication update:** Reviewed consolidationa3cb72895 and subsequent exact Settings focus correction29a83522d are now published and independently verified on local/GitHub main. [Publication evidence](MAIN-publication-verification-20260928.md) records A8, preserved source, actual125% coverage and unchanged verification limits. Earlier candidate/preparation states below remain historical.

**Current decision, 28 September 2026:** Stephan has now explicitly approved A7, permitting publication with actual **125% browser zoom Unverified**. The former hold below is historical. Main reverified the unchanged frozen source: 569 source paths, 2,758 protected paths and 75 packet hashes, with no discrepancies. Exact serial source/base approvals and publication receipts are recorded in [MAIN-publication-approval-20260928.md](MAIN-publication-approval-20260928.md). Existing scoped QA remains applicable; no whole-repository CI pass or final user acceptance is claimed.

MAIN ASTRA, 28 September 2026. This record is an integration preparation review, not publication approval or a completion claim. Published local/GitHub main remains `bb0eea61abe61a14d9b65af59f8bf1add88e8bb5`.

## Source and custody

The existing Reports Designer is the sole application/test writer in its existing checkout on `codex/fleet-consolidation-qa`. The isolated candidate combines the frozen PKG-03, PKG-08, People Locations and Reports sources with published Main. No new session or parallel application writer was created. Original package refs and Rory's design references must remain intact.

Main inspected the actual merge-resolution diff of `f3fd2f77657c7fe09022ff69562e8c3487c9668b`, including the parent `19ec8b3d3814ee323900d4352ccecb23d1627104`. This source is provisional until the Designer freezes the final QA packet; subsequent changes require bounded review.

## Shared code review

- Both history entry points remain. The private readers retain optional watermark/observer arguments in positions six/seven, followed by the by-reference saturation flag. Callers use named optional arguments.
- Interactive reads scan 501 candidates, assess saturation before mapping invalid observations, and expose at most 500 positions. Report reads retain `lazyById(500)` and the captured ID watermark, including an empty result for watermark zero. Original models reach the contributor observer before public projection.
- The legacy identity check still considers all devices, including soft-deleted records outside the permitted set. The merged implementation batches that global ambiguity lookup; dedicated regression coverage must verify both denial and query count.
- The exclusive `before` boundary, source IDs, `received_at` and approved accuracy precedence are retained.
- Lone Worker imports and delegates to both the canonical list scope and direct-session access service. Intrinsic ownership/Site integrity remains ahead of bypass handling.
- The client location workspace retains both contextual report links and the existing export condition. Both route includes occur once. Finance review schedules and operational report schedules are retained with their execution controls.

Main also inspected the new real-reader integration regressions and the bounded Fleet Overview architecture-test correction. The latter follows the controller's existing service delegation and preserves permission, Site, single-organisation and raw-payload exclusions; it changes no production authorization.

## Verification in progress

Main independently ran three unchanged Reports assertions through `PKGConsolidationMainHistoryReviewTest.php`: complete watermark-bound history, consent-clipped client export with download reauthorization, and session-bound staff history. The first two pass. The staff test reaches its expected-ready assertion with `queued`, because Main's blanket queue fake prevented the local generation job. The original run is retained: three tests, 17 assertions, one failure, 391.776 seconds. This is an evidenced wrapper issue, not a claimed application pass. Main corrected only the wrapper to allow `GenerateOperationalReport` through the configured synchronous queue and is rerunning only that affected test. HTTP remains blocked and all other jobs remain faked. No production assertion changed.

The corrected staff rerun passes: one test, ten assertions, 282.002 seconds. The three distinct independent cases therefore pass with 19 assertions across the retained runs. The initial harness failure is not erased or counted as a pass. Metadata verification confirms both runs removed their own disposable databases. Raw log and JUnit files are retained separately.

The wrapper otherwise isolates database/schema loading and optional SSR. Database prefix: `of_main_combined_history_20260928_`. Main's source binding confirms all four approved package commits and published Main are ancestors of the reviewed merge; no dirty affected application/configuration/route/source-test files were present. The candidate changes 564 paths, all inside the union of approved package sources; none changes the 2,758 published programme paths or protected design-guide paths.

The Designer's combined frontend log records 77 passing tests across 18 files, and the production build succeeds. The Designer also reports passing TypeScript and scoped ESLint. These are owner-run results; final source binding, backend QA, types/lint exit evidence and authenticated combined browser evidence remain to be checked.

The first combined backend run completes 468 cases: 460 pass, eight fail, 5,682 assertions, 732.47 seconds. Main inspects the JUnit failure identities. Six newly added integration-history cases fail during raw fixture insertion because the existing model's legacy required storage context was bypassed. The existing model/storage-context mechanism must supply that data; no tenant authorization or schema change is needed. Two older Fleet map tests expect a 200 legacy page where the published controller now redirects Fleet readers to Maps & boundaries. Main authorizes bounded fixture/test corrections while retaining canonical Site/privacy regression assertions.

Main additionally raises `MAIN-CONSOL-01` for a focused reproduction: the legacy map controller still accepts an assets-only role through route middleware and can fall through to direct fleet-state coordinates/open-alert projection. Consent/private-position handling on that path needs an explicit check. This is currently a source concern, not yet a confirmed runtime finding. Designer must report the reproduction and minimal correction before changing production behavior. It cannot be hidden as an unrelated stale test.

The supplement run passes all 32 cases/152 assertions in 307.52 seconds: ten combined history cases, 13 handover mutation-integrity cases and nine handover Site-isolation cases. Main inspects the JUnit identities and corrected fixture. It uses the existing `LegacyStorageContext::attributes()` write-only compatibility helper, matching the model concern; no schema/authorization change is introduced. This closes the six history fixture failures and verifies real 500/501/601-row boundaries, contributor observation, watermark zero/late arrivals, accuracy/before handling and global legacy ambiguity/query-count checks. The two stale map tests and the legacy-route reproduction remain pending.

## Confirmed legacy-map correction authorization

`MAIN-CONSOL-01` is now confirmed. Main inspects the Designer's retained probe source, `map-checks.xml` and `legacy-map-probe-observations.jsonl`. Both `assets.viewAny` and `assets.viewAssigned` actors, without Fleet/map/alert capabilities, receive HTTP 200 with two vehicle positions (consent-blocked and active personal trip) plus an open-alert count of two. The isolated four-case map run has one pass and three failures: the two reproduced privacy denials, plus a stale assumption that `fleet.manage` alone opens every Site in the canonical boundary workspace.

Main authorizes the existing sole Designer to make `LiveMapController` a compatibility redirect only, requiring the existing canonical `fleet.viewAny OR assets.geofences.manage` capability and denying assets-only callers. Retain URL/name, remove the obsolete raw projection, and add permanent positive/negative regressions. No new product/design requirement, permission grant, schema change or operating action is authorized. This correction authorization is not “Approved for integration.”

Main independently verifies that `BoundaryService` uses `siteScopedVehiclesForFleet` and canonical Site access. Its broad Site permission is `securityDevices.devices.viewAllSites`; `fleet.manage` alone does not supply it. Updated tests must retain the unassigned-Site negative and demonstrate an explicitly assigned second Site or the actual explicit all-Sites permission. Production Site scope must not be widened to satisfy the retired test.

The correction is applied to `LiveMapController.php` and the corresponding route middleware. Main reviews both diffs: the controller now only checks the canonical map capabilities and redirects; the obsolete raw projection is removed. The route is moved out of the broader dashboard/asset permission group and uses the same capabilities. Existing route URL/name and canonical Site/privacy services remain intact.

Main independently replays the unchanged original failing probe via `PKGConsolidationMainMapReviewTest.php`: two tests/eight assertions PASS in 421.978 seconds. Both assets-only roles now receive 403. Start/end SHA256 values match for the controller, route and original probe; no source change affected this run. The disposable `of_main_combined_map_20260928_` database is removed. Raw log, JUnit, source hashes and cleanup metadata are retained. `MAIN-CONSOL-01` is technically closed on this corrected source, subject to final commit binding.

Owner's updated canonical-map tests pass two cases/57 assertions. Its wider map/privacy/Overview run passes76 cases and flags one architecture failure in the new history fixture's direct storage-context helper call. Main retains that failed result. Designer replaces only fixture construction with the existing IntegrationEvent model creating hook and reruns the full architecture file plus affected history cases; no architecture rule is relaxed. Final packet remains pending.

## Final frozen source review and publication hold

The Designer is now frozen/idle on `codex/fleet-consolidation-qa`. Tested application source is `9782faab62efa0cabe518b0a799a3ad6cd1ffbb5`; documentation-only packet commit is `a3cb72895ce6682bcf39fe6276ce38955987bd7d`, based on published Main `bb0eea61abe61a14d9b65af59f8bf1add88e8bb5`. Main independently verifies all569 changed-path blob identities, all five input ancestors, all2,758 protected Main paths and all75 packet file hashes against both the working files and committed blobs. There are no discrepancies or dirty application/test changes. The final packet adds only its new evidence directory. See `MAIN-combined-final-source-verification.json` and the reproducible verifier.

Main independently reconciles the final JUnit class/row evidence: 35 classes,494 cases,5,905 assertions, no remaining failures/skips. This is the latest complete run of each class across retained attempts, not a single monolithic run. The final history/architecture run passes55 cases/651 assertions. Owner frontend77 cases/18 files, full TypeScript, scoped ESLint, scoped PHP formatting and production build pass with the recorded whole-file Main formatting exception. Main's separately executed five high-risk cases pass27 assertions. The original failed probes/harness attempts remain retained.

Main inspects the authenticated browser evidence and actual builder screenshot. The served app entry matches the hashed combined build; shared Finance/Settings/People/client/staff/legacy-map routes were exercised. Browser download capture timed out, so the latest combined export evidence is application confirmation plus separate server-byte/rendering/privacy tests, not a newly inspected downloaded file. No frontend source changed after the successful build. Actual125% remains unverified. The original privacy probe is now retained byte-for-byte in the committed packet; Main's wrapper references that copy for reproducibility without changing its assertions.

`MAIN-CONSOL-01` and the combined compatibility/test corrections are closed on this frozen source. Main also fixes the eight newly introduced audit-script lint errors with explicit Node imports; these six documentation-tool edits pass scoped lint and await the final documentation publication. Existing whole-repository CI failures and `MAIN-TELEM-01` remain in their separate records; neither a green repository suite nor operating/alert-policy readiness is claimed.

**Decision: substantive source review complete; publication held.** This is not “Approved for integration.” The remaining acceptance decision is the existing request to publish with genuine125% recorded as unverified, or to obtain actual browser-zoom evidence. Revision10 section15C requires Stephan's approval for that acceptance change. Merge/push destinations are already authorized. Local and GitHub main were independently observed atbb0eea61a; none of the four pending application packages has been published by this preparation. No additional destination permission, new worker or repeated package implementation is needed.

When that decision is resolved, retain same-owner serial publication and source/base mapping: PKG03 prepared stage `a4f869fe5e07b2fc5ecb87526596077f9700a584`, PKG08 `abc70200dc232317273b028e28181af6191f87b8`, People `19ec8b3d3814ee323900d4352ccecb23d1627104`, then Reports/final candidatea3cb72895. These are prepared isolated commits, not already published stages. Recheck current main before issuing each exact integration approval; preserve Main's working documentation corrections and unrelated untracked files. Main's final audit/context checkpoint follows the approved application publication.

## Remaining publication decision

Actual browser zoom at 125% remains unverified. Revision 10 sections 15 and 15C require browser-zoom coverage and Stephan's approval for an acceptance change. The existing explicit exception question remains unanswered. Preparation continues independently of this gate; no missing check is labelled passed. Local/remote publication destinations are already authorized and need no further permission.

Current main CI classification and the separate telemetry-accuracy finding remain in their existing records. Scoped candidate passes must not be presented as a green whole-repository suite or operating rollout approval.
