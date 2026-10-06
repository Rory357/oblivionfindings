# Remaining eMAR recovery contracts — 4 October 2026

This is a bounded implementation proposal for Main's review. It does not authorize a new clinical or stock writer, a historical authority exception, an operational import, or a production-readiness claim. The application serves one organisation across approved sites; current account approval, exact permissions, canonical person/order ownership, approved sites and privacy remain the authorization boundary.

## Implemented recovery boundary

An ordinary scheduled paper **refused** or **withheld** entry may be posted only by its actual giver after that giver has confirmed the immutable paper evidence. Reconciliation binds the exact downtime dose/slot, actual time, original explanation, unchanged order fingerprint and current rule revisions. It enters the shared locked recording path in Client → Order → Shift → Rule → User order, then rechecks the paper preview under the canonical decision. Current own recording authority must still cover the actual paper time through a valid actual assignment or a currently usable canonical emergency grant.

The canonical recorder keeps the paper outcome and explanation without inventing a refusal cause, observation, dose quantity or follow-up deadline. Paper evidence is unchanged. Clinical outcome, slot, replay receipt, paper posting, emergency-grant use when applicable and final P09 event share one transaction. Replay rechecks current authority and adds no duplicate effects. Given, controlled, PRN and named/required second-person paper entries remain held.

## Given paper needs physical and clinical evidence

`PaperAdministrationWriter` deliberately holds a historical given outcome. Existing `dose_on_paper` plain text, order dosage and a current FEFO stock balance do not identify the pack used, prove a physical quantity, or establish whether a later closing count already incorporated the paper dose. A normal stock deduction at reconciliation time could deduct the same physical dose twice or deduct the wrong pack.

Before enabling a historical given adapter, review and implement a typed, immutable evidence contract with at least these facts:

| Required evidence                                                                                                                     | Decision it must support                                                                                                                                 |
| ------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Actual quantity and unit administered, with order/source version and any variation explanation                                        | Validate the clinical dose without assuming that free-text dosage equals pack stock quantity.                                                            |
| Actual pack/lot identity, quantity in that pack's unit, and physical stock disposition                                                | Establish what left stock at the original instant; resolve unknown or incompatible evidence through review rather than automatic current FEFO selection. |
| Reviewed closing-count identity, count instant, cupboard/stock identity and explicit coverage of the paper interval                   | Establish whether the paper use is already included, what historical movement is needed, and how current balance will remain consistent.                 |
| Actual required readings, safety checks, source/order validity, canonical giver and competency evidence at the original clinical time | Run the shared clinical and competency checks against evidenced facts. A signed collection confirmation alone supplies none of these facts.              |
| Historical second-person attestation where required, retaining named actor, qualifying role, time and source                          | Validate required historical confirmation without reusing a current PIN or treating collection as a witnessed clinical act.                              |

Keep controlled given reconciliation separate until the canonical witnessed historical register writer and reviewed closing-count coverage exist. PRN recovery additionally needs historical interval/day-limit and reason/effect-work evidence. Any adapter must retain the shared stock/register writer, atomic P09 append, exact identity and fingerprint replay, correction lineage and rollback guarantees. Missing or contradictory evidence must preserve the signed paper and hold posting without changing clinical stock or register records.

Suggested review sequence: agree the evidence fields and closing-count coverage rules; add read-only preview and immutable evidence capture; review clinical/stock decisions and direct-object denial; then wire a narrowly typed canonical adapter with concurrency, rollback, duplicate, already-counted and quantity/pack mismatch regressions. This document does not select or implement that adapter.

## Expired emergency grant on queued offline doses remains held

`EmergencyAccess/OfflineGrantEvidence.php` contains a pure eligibility predicate and unit coverage. Production ordinary administration and guided-round recording do not call it. `MedicationScopeDecisionService::activeBreakGlass` still requires the canonical grant to remain usable now, as well as covering the actual clinical instant. Client-supplied queued/offline timestamps therefore do not currently extend an expired grant. Follow-up clinical scope explicitly excludes an expired offline-grant exception.

Do not wire the helper into current administration scope without Main's review. A bounded proposal would be dose-only, ordinary scheduled medication only, and require a stable actor/person/order/slot/request identity plus strict retained capture and clinical instants inside the actual canonical grant window. Review whether retained server-issued evidence is needed: the helper alone reads submitted timestamps and proves neither capture provenance nor current authorization. The helper excludes a second-person requirement; controlled, PRN, paper and follow-up expansion must not follow by implication.

Review must decide natural expiry versus manual revocation/ending, later account/permission/site loss, current person/order/source validity, co-signer authority, incident/minimum-necessary acknowledgements and duplicate replay behavior. The final server decision must retain current canonical approval and approved-site checks, use the shared authority lock order, and atomically write one grant-use event with the accepted dose and final audit append. Grant ending/expiry at capture boundaries, forged/future/invalid capture claims, changed clinical payload, current authority loss, second-person/controlled requests, concurrent grant revocation and final-audit failure require explicit no-effects regressions. Until approved and implemented, the expired-grant queue entry remains held for a supported recovery route.

## Synthetic acceptance fixtures

Local/testing acceptance setup is restricted to the exact five named Playwright medication orders. Scheduled fixture publication selects future times of current time +5/+10/+15 minutes and performs real order entry plus an independent check. It refuses a same-day rollover or a configured early recording window shorter than 15 minutes before publication.

Only obsolete unrecorded projection rows for those exact orders on the current NZ day may be superseded after publication. Clinical/paper references, reconstructed rows, other days, recorded outcomes, immutable versions/sources, prior P09 evidence and other people's records are retained. This synthetic operation establishes browser/test readiness; it supplies no operational clinical evidence.

## Verification

On 4 October 2026, the final repaired paper/readiness run passed **109 tests / 1,329 assertions**, with no failures, errors or skipped cases and process exit 0. Its authoritative logs are `storage/logs/emar-paper-readiness-finish-tests.log` and `.xml`: paper downtime passed 63 / 516, readiness acceptance passed 5 / 132, and the six surrounding clinical/scope/audit/timesheet/offline/recovery regression files passed 41 / 681. These are one combined run, not totals added across overlapping runs.

The run used the explicit Herd PHP 8.4 executable, `APP_ENV=testing`, localhost MySQL, the exact isolated database `oblivion_findings_codex_test_paper_resume_20261004_01`, and `MYSQL_TEST_SCHEMA_TIMEOUT=1200`. The test harness removed that schema at process shutdown. All six changed paper/readiness PHP files passed syntax checks and their scoped whitespace check was clean. No additional source or seeder repair was needed on resume. The earlier pre-repair paper batch (99 passes / 5 failures) and interrupted cold import are superseded by this completed runtime evidence.

This focused evidence does not certify production readiness or the remaining held recovery paths. Given, controlled, PRN and named/required second-person paper recovery, plus queued doses after emergency-grant expiry, remain held under the contracts above. No production clinical records were written.
