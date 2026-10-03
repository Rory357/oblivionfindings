# P10 downtime and paper reconciliation handoff — 3 October 2026 (NZ)

Assigned checkout: C:/Users/steph/.codex/worktrees/emar-p10-breakglass/oblivionfindings, shared branch codex/emar-p10-completion. Worker owns only new downtime files; parent owns integration, commits and Main handoff. No live medication data, operational register rebalance, deployment or push has run.

Approved design reference: exact P10 v1.1 commit 1c758eabc27c3e7844912432ba7e5e7ac4dbaab7, docs/emar-design/P10/v1 (read-only). New UI uses current DESIGN.md, PageHeader, EntityTable/EntityCard and shared menus, WizardShell, DateTimeField, WitnessPinInput, FileDropzone, NZ datetime helpers and semantic tokens.

## Implemented boundary

- Canonical Site downtime, actual UTC instants parsed from NZ times, private paper sheets and immutable scheduled-dose starting-point snapshots.
- Explicit immutable paper facts: actual outcome/time/giver, entering staff/time, dose on paper, observations and named second person. Outcome/time/dose/giver start blank. No given outcome is invented; an acknowledgement is required again for each reviewed version.
- Read-only previews, recomputed under client/order locks on submission; tokens bind facts, order snapshot, conflicts and confirmations.
- Global unique dose identity protects overlapping downtimes and retries. Existing effective eMAR evidence conflicts with the paper entry; no automatic replacement, re-offer or correction.
- Appended actual-giver confirmation; another giver confirms in their own account. Required second person confirms with their own PIN and existing witness eligibility policy.
- A separate unique posting link is the only source of Entered from paper. Signed evidence stays To reconcile until the canonical writer succeeds.
- The ordinary historical posting path is safely held: an unchanged order and completed assignment do not establish the physical stock disposition or closing-count coverage of the paper dose. PaperAdministrationWriter has no direct live EnhancedMarService call. Its retained canonical actual-time authority orchestration is the integration seam for the shared historical recorder, which must own clinical and stock writes.
- Controlled, historical second-person, changed-order and PRN posting have precise safe unavailable reasons. Temporal PRN checking alone does not resolve historical recording authority or physical disposition. Refused/withheld maps deterministically to NotGivenReason::Refused/Withheld, retaining original notes and omitting dose/amount/readings/stock fields; it remains held until P01 accepts evidenced historical non-given reasons. No personal-choice cause is inferred. No register movement, balance or closing count is rewritten.
- Finish means paper collection finished. Outstanding confirmations and reconciliation remain due. When existing eMAR evidence syncs or another downtime already holds the dose, the lead explicitly reviews and links that evidence with a required reason. An immutable duplicate-resolution link accounts for collection only; no outcome is inferred and no dose is posted/replaced.
- Today/tomorrow NZ pack uses DoseSlotProjection. DoseSlotRules proves coverage; missing projected slots stop printing. Shared medication rules supply second-person/reading boxes. Includes allergies, PRN limits, scheduled round sheets, instructions, print attribution/purpose, and controlled pages only with controlled view.
- Internal declaration targets include controlled doses, so a concealed row cannot vanish from collection completion. DoseSlotRules also verifies declaration coverage; an incomplete projection stops declaration. Cross-person controlled entries are omitted with a caption. Saved and current controlled flags gate direct objects, tasks and lists. Pack concealment checks both the slot and current order to prevent reclassification leaks. Raw downtime text and unstructured scans need controlled view because they cannot be safely redacted.
- P09 MedicationEventRecorder appends last inside the retryable transaction. Event failure rolls back the paper write.

## Integration APIs

Parent includes routes/emar-downtime.php once from its owned route seam. Worker did not edit routes/emar.php, existing medication controllers, global primitives or P11 registry.

Routes under /emar/downtime:

| Method | Suffix | Purpose |
| --- | --- | --- |
| GET / POST | / | List / declare |
| GET | /{downtime} | Scoped facts / paper payload |
| POST | /{downtime}/finish | Finish paper collection |
| POST | /{downtime}/doses/{dose}/resolve | Explicit reviewed link to existing clinical/paper evidence |
| GET | /{downtime}/sheets/{sheet} | Private gated scan |
| POST | /{downtime}/paper/preview | Validate facts and duplicate/conflict preview |
| POST | /{downtime}/paper | Collect accountable evidence |
| POST | /{downtime}/paper/{entry}/confirm | Actual giver or PIN witness attestation |
| GET | /{downtime}/paper/{entry}/reconciliation | Current posting preview |
| POST | /{downtime}/paper/{entry}/reconcile | Explicit canonical posting |
| POST | /pack/preview | First-page preview under reports.view + reports.export |
| POST | /pack | Logged PDF download under reports.view + reports.export |

Pack fields: site_id, nz_date; both medications.reports.view and medications.reports.export; fixed recorded purpose: Downtime — a paper copy in case the system is down. P09 owns the production report-view grant foundation; older P10 synthetic fixtures create its key explicitly without a live grant migration. P09's finance-only exclusion applies even with explicit report overrides. Private/no-store PDF. PackDialog is exported from resources/js/pages/emar/downtime/_dialogs.tsx for P09 Print & exports. P09 can replace DowntimePackPdf; domain does not duplicate an export subsystem.

DowntimePackService::build takes bounded current source locks before ordinary clinical reads and releases them before PDF rendering. Its internal source evidence is never included in preview payloads or PDF markup. The PDF uses a generic concealment notice without hidden counts. DowntimePackService::release(User,array,Closure) locks fresh RBAC, approval and approved Site evidence; compares current raw person/order/stock/rule/allergy/profile/slot/history/timing/away evidence with the rendered snapshot; rechecks the canonical per-person gate; and invokes the P09 export callback last. It does not rebuild through cached projection/rule/allergy readers. Any revocation, classification change, person move, evidence change or final event failure keeps the buffered bytes private and creates no export event. Limits are 100 people, 1,000 orders/rules/stock rows, and 2,000 slots/history/allergy/away rows; complete scopes beyond these bounds are held, never truncated.

Order/execution preview fingerprints now include schedule, date range, PRN limits, instructions and matching administration-rule revisions. Facts are immutable; a stale preview requires a new review.

P08a adapter: DowntimeService::pendingConfirmations(User), neutral label/deep link, stable identity medication.paper_confirmation:{entry}:{actor}. Payload includes paper_entry_id, kind (giver/witness), client_id/name, site_id/name, actual given_at, entered_at and controlled flag after canonical access. Parent owns concrete TaskProvider registration; ?paper_entry={id} opens the scoped paper evidence. P08a's PIN-2 confirm nomination type is not reused. There is no second Tasks store. Confirmation changes its canonical paper evidence, so tasks disappear from the projection.

Duplicate resolution fields: kind (clinical/paper), record_id, reason, accountable_confirmation accepted. Controller and service recheck current evidence belongs to the same canonical order/person and scheduled target. An invalidated or unrelated evidence ID cannot close collection.

P01 offline adapter: PDF currently downloads to saved files. No claim of an encrypted in-app PDF cache. Offline owner may consume PDF endpoint via approved envelope. Worker did not edit queue/envelope or Meds today.

## Safe unavailable mechanism

Normal recording requires canonical covering authority at the actual time, current recording permission/profile, temporal safety, qualified second-person authentication and the exact historical physical disposition/count coverage. No complete historical paper writer exists at this base. Saved attestation cannot substitute for a live PIN. PaperAdministrationWriter retains signed evidence and makes no clinical or stock call. P01/P06 must wire postAuthorized to their canonical historical recorder, passing the locked decision and immutable paper identity/actual time/giver confirmation plus evidenced physical disposition: already allocated, exact original allocation, absorbed by a later count, or unknown held. Refused/withheld must accept only the evidenced enum and original explanation without given-only stock or observations. P07 owns historical controlled/second-person/count projection. The existing posting-link transaction and final P09 append must stay atomic with the shared writer.

Automatic approval review rejected an attempted historical live-writer patch because the required physical disposition/authority adapter was unavailable. The safe replacement removes that call entirely. No mock seam re-enables it or claims clinical success.

## Verification evidence

Small pure runs: no database or application boot, distinct from gated Pest verification. The first temporary bootstrap required the read-only primary vendor/autoload.php and this checkout's PaperReconciliationRules.php. It passed 9 tests/19 assertions. The final expanded run used the physical vendor directory provided by the parent in this checkout, with no junction.

Final exact command (PowerShell, p10Bootstrap was storage/framework/testing/p10-pure-bootstrap.php in this checkout; it required this checkout's vendor/autoload.php and PaperReconciliationRules.php):

    & 'C:/Users/steph/.config/herd/bin/php84/php.exe' 'vendor/bin/phpunit' --no-configuration --bootstrap $p10Bootstrap tests/Unit/Medication/Downtime/PaperReconciliationRulesTest.php | Tee-Object -FilePath storage/logs/p10-pure-rules-2026-10-03.log

Result: PHP 8.4.16, PHPUnit 12.5.23; **10 tests, 20 assertions, pass**, 0.562 s test time / 36 MB. Log: storage/logs/p10-pure-rules-2026-10-03.log. Temporary bootstrap removed by exact path.

All 23 worker PHP files passed PHP syntax checks; Pint formatted only owned new files. All 4 new UI files passed TypeScript transpileModule syntax checks using the read-only primary runtime. UI formatted with primary Prettier/absolute plugin paths and focused ESLint passed. Parent independently reported 10 P10 UI syntax passes.

MedicationDowntimeTest now contains 40 synthetic cases. Added cases cover an actual unmocked PDF download, render-time account/report/Site/CD revocations, classification and person moves, changed instructions/rules/projection, export event failure, explicit finance overrides, historical stock holds despite a completed assignment, exact refused/withheld mapping without given-only fields, missing paper explanation and stale schedule/date/PRN/rule previews. Prior ordinary clinical-success assertions were replaced by the required production hold; clinical success/rollback acceptance belongs to the future shared historical adapter.

**Not run:** gated DB/Pest suite, real browser/1440/1280/200% zoom, PDF visual inspection, full TypeScript type checking. No heavy command launched by this worker.

Follow-up source checks: all six changed PHP sources/tests pass PHP 8.4 syntax checks and focused Pint. Git whitespace validation passed. The two form transforms now call transform and post separately, resolving Main's reported Inertia void-return type errors. These source checks do not establish the 40 synthetic database/PDF tests; Main will validate the final integrated snapshot.

## Current verification boundary

Backend/schema/tests were released to the parent for its combined frozen snapshot after these refinements. This worker has not run DB/Pest and will preserve the snapshot while it is queued/running. Parent owns runtime evidence, the P09 prerequisite integration, route inclusion, task provider registration and commit/push. Browser and PDF visual checks remain outstanding until Main grants the heavy slot. No live-data rehearsal or release claim is made.
