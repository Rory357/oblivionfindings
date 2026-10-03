# P05 Medication reviews — integration contract

Approved design: 22982b1ff81d252e446f543a3494ea918b03a047, docs/emar-design/P05/v1. Single organisation, approved Sites, canonical person access and controlled-medicine concealment remain the boundary.

Owned checkout: emar-p05-completion/oblivionfindings. Branch codex/emar-p05-completion. Base 9747cf7cb654c2ef441e8f60c7ea1b5918081925. Physical vendor copy resolves this checkout; no vendor/node_modules junctions, server or live-record writes.

## Prerequisites, integrated separately by Main

- P09 original 466ce69df138cf20faac4ace81cdc26da1f03b2e, local 0ddf02daa.
- P08a original 8d799fd36, local be1263a76.
- P08a batch audit original a5b12397f, local 44135f57c.

Main integrates each prerequisite once. The P05 commit is separate from these dependency commits.

## Workflow and source records

MedicationReviewWorkflow owns booking, moving, appointments, recording, pending controlled outcomes, prescriber decisions, cadence changes and departure closure. Booking uses already locked owner RBAC and current HR membership from the governing transaction, preserving current permission and house evidence. Exact write key medications.reviews.manage is granted by migration and reflected in fresh seed role definitions. Orders entry/check remains separately authorized.

A completed review never writes a prescription. Items link the canonical current medicine's exact version number, not the highest proposed version. Missing historical version evidence stays null. Hidden controlled rows are omitted by the form and saved as pending_controlled, never Continue. New-medicine recommendations have classification_pending until P04 establishes the canonical medicine classification; non-controlled readers cannot receive an unclassified proposed identity.

Regular completion books the next regular using calendar months without month-end overflow. An explicitly earlier clinician request uses an earlier regular date. For a triggered review it moves the existing regular with append-only original date/reason, or starts a missing regular cycle. Without that request, triggered completion leaves the regular cycle unchanged. Away does not close/postpone; inactive/discharged/deceased closes outstanding reviews.

The existing chart_review_interval_months column is retained. A data-only migration carries valid non-default legacy intervals (1–12, excluding the schema default 3) into an unset medication_review_interval_months, preserving shorter review cycles and all booked dates. An existing canonical override or any recorded interval decision prevents replacement or resurrection on retry. Carried values remain Not reviewed until the latest actual actor interval decision matches the value. Null follows the organisation default; the old default 3 alone cannot establish a reviewed person policy. Main should reconcile the legacy care-level settings form with the new review cadence control before final product acceptance; it currently writes the legacy chart field/date.

Review/source evidence is private; clinical summary and letter require reviews.manage plus the same controlled/classification privacy gate. Generic audit payloads exclude unstructured clinical content. The existing eMAR audit projection uses the recorder (completed_by) and actual happened_at, and gates the summary. Historical legacy recommendations, medication IDs, actions and whānau notes remain available read-only to permitted managers; generic advance is refused.

All mutations return JSON {saved:true,message,review_id} to the scoped form client, or normal success redirects to existing non-JSON callers. Revision conflicts, authorization loss, storage/service failures and uncertain/network responses keep the open draft and selected file.

## P08a watch adapter

MedicationReviewFollowupAdapter calls the real MedicationFollowupService.ensureForSource('review-watch', itemId, canonicalClient, medicine, null, reviewOwner, NZ-watch-until-end-of-day, context), inside the P05 canonical transaction. The type is review_watch; source key review-watch:{itemId}. Context includes what_to_watch, watch_until, review_id, review_item_id and source_url.

Only the canonical ledger owns follow-up transitions. P05 reads visibleQuery to show completion and links /medication-followups?open={id}; Tasks identity remains medication-followup-{id}. No duplicate follow-up Task record or local completion flag is created.

## P04 review recommendation handoff

App\Services\Medication\Reviews\MedicationReviewOrderAdapter:

1. P04 locks/authorizes the canonical Client and calls lockRecommendation(actor, client, itemId) before its order/version locks.
2. It accepts only a completed, permitted, agreed, unlinked same-person recommendation. Controlled/classification concealment and current orders.manage are checked.
3. After P04 creates its canonical immutable version, call linkVersion(actor, lockedItem, version) before P04's final P09 append. It records the version link and review history, without publishing, checking or certifying the order.
4. Phone instructions and independent checker remain P04 rules. P05 displays “Entered in Orders — check status there”, never “Implemented”/“Done” based only on entry.

P05's handoff URL is /emar/prescriptions?client_id={id}&review_item={itemId}. P04/Main must consume this query and call the adapter; no arbitrary version-link route is exposed. Swap recommendations need the old prescription's stop and replacement order to be governed by P04; one review-item link alone is not proof that both clinical steps are complete.

P05's source transactions retry five times and append P09 last after all domain writes/locks. The P04 adapter leaves its caller's P09 append to P04.

## P11 cadence seam

MedicationReviewCadence::settingsGroup adds review_cadence/months under the existing Rules view, storage medications.reviews.interval_months. Current default is 3 months and Not reviewed unless an explicit P11 setting/history exists. Existing booked dates remain unchanged.

resources/js/pages/emar/reviews/settings-fragment.tsx exports ReviewCadenceSettingsFragment({months,reviewed,disabled,onChange}); P11 owns its save/keep/reviewed lifecycle and mounting.

## Shared seams and verification boundary

P05 makes narrow changes to routes/emar.php, MedicationReview, AppServiceProvider's lifecycle observer, TaskAggregator registrations, client ActionsAggregator/profile labels, ClientController's open-review date, medication overview count, calendar's canonical medication read/concealment gate, settings registry, fresh role baseline and the existing review modal entry point. No global UI primitive or guide is modified.

Focused tests are tests/Feature/Emar/MedicationReviewWorkflowTest.php (33 synthetic cases) and MedicationReviewCadenceMigrationTest.php (8 data-only migration/provenance cases), plus 17 pure frontend tests in model.test.ts/_request.test.ts. Functional results and exact source commit are recorded in the final worker handoff. Frontend syntax and scoped lint pass; full type/build and 1440/1280/200% zoom browser verification belong to Main's combined verification slot. P04/P11 source seams and existing legacy settings must be exercised after integration.

## Worker runtime status before final rerun

The repaired 31-case workflow run at PHP source b2d2409e8 ended with 205 assertions and 28 failures, all booking 404s (storage/logs/p05-functional-repaired.log, 11:51.998). The parameter forwarding error was removed; the remaining cause was missing clients.viewAny from the locked actor's bounded permission evidence. Commit 43c1a0bb3 adds that key and the two exact report-read alternatives used by ClientPolicy, with a direct permission/denial regression. Commit 8fbed6ca9 removes inherited ascending event ordering before selecting the latest mutation for P09; the existing completion rollback case checks the required review.completed append. Commit fba9129ed also replaces ordinary owner reloads with the already locked owner permission and HR evidence, with direct denied-owner and other-house coverage. These changes and the cadence migration require runtime rerun. No successful functional result is claimed. Main temporarily holds all further heavy runs while repairing its shared queue.
