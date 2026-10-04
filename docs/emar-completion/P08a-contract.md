# P08a integration contract

Owned checkout: emar-p08a-followups/oblivionfindings. Branch codex/emar-p08a-followups, base 9747cf7cb. No dependency junctions created.

MedicationFollowupService owns one durable workflow identity (medication_followups.id, unique source_key). Existing doses, refusals, effect reviews, handovers and orders remain authoritative clinical evidence; the ledger owns outstanding work, due times, ownership and append-only transition history. Tasks are projections, never persisted copies.

Consumers: visibleQuery(User), present(MedicationFollowup, User), forClient(User, clientId). Mutations: transition(User, id, payload), request_uuid and expected revision. JSON routes: /medication-followups. Composable MedicationFollowupList and MedicationFollowupDialog for worker, lead and person surfaces. P01 owns initial Meds today/My Day/Tasks/calendar wiring; P02 owns its record shell.

Source adapters: syncAdministration(administration), ensure(sourceKey, type, client, medication, administration, ownerId, dueAt). P04 reconciliation and phone instructions / P05 watch items call ensure inside their canonical transaction. PIN-2 uses type confirm with the named colleague as owner. Only that colleague answers; a negative answer creates one lead dispute item.

No default PRN due time. Worker work: current covering workers. Lead work: medications.followups.manage. Reassignment: owner or lead, to a current covering worker. Original owner is immutable. Couldn't check: reason and explicit time at/before shift end; never closes. Handover acknowledgement: exact incoming assignee, carried worker work transfers without closing. One lead heads-up after 1 hour without acknowledgement, independently of Delivery.

Shared seams: EnhancedMarService source hook, legacy refusal/PRN adapters, TaskAggregator registration, ShiftHandoverService acknowledgement hook, routes/emar.php, scheduler, RbacSeeder grant. Main integrates serially. Focused verification is queued under the existing Main shared heavy lock.

## Typed source contract (foundation candidate)

Namespace: App\Services\Medication\Followups\MedicationFollowupService. ensureForSource(source, sourceId, client, medication, administration, ownerId, dueAt, context) supplies the stable source key and approved type:

| source | type | owner package |
|---|---|---|
| support-reassessment | reassess_support | P03 |
| order-check | order_check | P04 |
| phone-written-confirmation | written_confirmation | P04 |
| second-check | second_check | P04 |
| reconciliation-query | reconciliation_query | P04 |
| review-watch | review_watch | P05 |
| stock-discrepancy | stock_discrepancy | P06 |
| confirm | confirm | PIN-2 |
| witness-override | override | P07a |

ensure and ensureForSource are domain-only and require an active transaction. Source callers must authorize the current actor, canonical person/medicine/source ownership and clinical evidence, and lock the canonical Client aggregate before invoking them. Context may include a canonical local source_url. Existing identities never change original_owner_id or silently reassign/update a due time on retry. Source records remain authoritative; no duplicate Tasks table or records are introduced.

completeFromSource(string sourceKey, ?User actor, string outcome, array facts = []) is domain-only, requires that same authorized source transaction and is called LAST after domain locks/writes/idempotency receipts. It resolves the ledger once, increments revision, appends immutable history and appends a neutral P09 event. Duplicate completion returns the existing record only when actor, outcome and recursively normalized evidence facts match the immutable receipt. Conflicting reuse is rejected. The facts.outcome key is reserved and rejected. No subsequent domain locks are allowed after P09 append. The public transition endpoint cannot complete source-owned assessment/order/stock/override/confirmation work.

PIN-2 alone validates nomination identity, named colleague, expiry, and actual presence/attestation evidence. yes/no require the ledger's named owner; expired may use a null system actor. no/expired creates one disputed lead item. The generic user confirmation action is blocked, so it cannot certify a nomination. updateSourceContext(string sourceKey, array context) publishes source-owned evidence, increments revision and appends history inside the owning transaction; public JSON accepts no context.

Worker completion is allowed for the current owner or a current covering worker. Reassignment still requires the owner or a lead and a current covering recipient. Couldn't check also requires a current shift for the end-of-shift limit. No PRN time is defaulted. Historic rows without a check time show Time not set.

Dependency: P09 466ce69df is cherry-picked locally as a434bf1d6; Main integrates the dependency once. Foundation PHP syntax passed and physical vendor resolves the owned appbase. Functional tests are pending under Main's granted heavy-lock slot; this is a reviewable foundation candidate, not a readiness claim. UI and legacy PRN adapter work follows separately. No node_modules or vendor junctions exist here.

Deployment: migration adds only workflow/history and grants. emar:workflow-followups --import is an explicit bounded idempotent source-identity import; it does not change doses. It has not been run on live data. The every-minute command only creates missed-acknowledgement heads-up work and sends no messages.

Legacy readiness correction: `emar:workflow-followups --preview` is a read-only batch preview and `--import` prepares at most 100 administration sources by default (maximum `--limit=1000`). `--after` and the printed fixed `--through` boundary permit reviewable resumption; `--site` and `--client` narrow the source set. Source preparation returns before the separate scheduled handover pass. Given PRN sources without a chosen check time are included, retain `due_at=null`, and record preparation provenance in their immutable creation event. Both preview and import leave clinical dose/effect evidence untouched. These options have not been run on production data.

Before preparation, `/medication-followups` returns scoped, read-only `legacy_effect_checks` counts and at most 25 rows. Existing source, person, current site and controlled privacy rules apply before counts/search. The explicit administration prepare POST supplies the durable identity without recording an outcome. The `medication-effect-source` Tasks provider projects the same missing source, with an administration-specific namespace, until its canonical workflow exists; it persists no second task or lifecycle. Worker effect queues read the canonical workflow's current owner, due time and completion when prepared. Existing clinical effect evidence conceals stale outstanding work in both worker and oversight projections until the explicit import reconciles its history. Person and handover workflow consumers acquire missing legacy work after this explicit preparation/import; they do not silently create workflow identities during reads.

An explicit `administration` link also returns nullable `selected_followup_id`, resolved through the authorized canonical effect query independently of list pagination and filters. Existing work opens through the detail GET, including completed history. Missing work prepares only when its selected legacy source has `can_prepare=true`; view-only links never initiate preparation. Hidden or invalid canonical sources return no selected identity.

## Batch audit correction (supersedes the single-item-only completion wording)

completeFromSource(sourceKey, actor, outcome, facts, ?array &$auditEvents = null) now accepts a caller-owned audit collection. With an array passed, it resolves the ledger/history only and adds MedicationEventData to the array; it does not acquire a P09 head. The source owner must appendMany($auditEvents) inside the SAME transaction after ALL domain writes/locks/receipts. Never discard the collection or publish after commit.

completeSources(array $completions) accepts [{source_key, actor, outcome, facts?}, ...], prelocks the workflow set in id order, resolves every item, then appends its audit events last using appendMany. It requires the owner's already authorized transaction and canonical clinical/source locks. Duplicate source entries in a batch are rejected. The method returns workflow rows. Single-item completeFromSource without a collection still appends immediately, so it must be the LAST domain operation.

Source creation ensure remains ledger/history only and never acquires P09 heads. This supports P03/P04/P05/P06/PIN-2 composing multiple work identities and closures safely. Public actions still cannot bypass source evidence.

## Screen and adapter implementation

The canonical page is /medication-followups (Safety & oversight > Follow-ups). Four header meters select real server filters: open, overdue, lead, and time not set. EntityTable and mobile cards share actions and the same DTO. Controlled details are excluded before list/Tasks/search; only a neutral open-item concealment count is supplied, independent of concealed-name searches.

MedicationFollowupDialog owns effect/refusal, explicit reschedule, reassignment, immutable history, and per-actor draft/rejected-queue recovery. Queued is shown as Saved on device and never as complete. A pending mutation disables another save for that identity. Server rejection retains the indexed entry until reviewed or explicitly dismissed. Dismissal is restricted to one definitively rejected entry belonging to the current actor. Draft revision drift is shown; entries survive validation, conflicts and reload. Time selection has no initial value, rejects the spring gap and requires selecting an offset for the repeated NZ hour.

AdministrationFollowupDialog is the thin PRN/person adapter. POST /medication-followups/administrations/{administration}/prepare authorizes the source and prepares its stable work without recording an outcome; then it opens the canonical dialog. The existing worker and PRN-register effect components now use this adapter. Existing POST effect aliases require request_uuid and revision and delegate to the same workflow after their established clinical scope check. Refusal completion aliases use canonical assessment and owner/covering authority; the lead GP-notification action uses medications.followups.manage.

For a scheduled refusal with a real source slot, reoffer_target is a P01 DoseTarget DTO. The canonical page opens the existing RecordDoseDialog with entry follow-up and mode reoffer. The first follow-up action and its replay return next_action=record_reoffer, preserving the exact source administration. Cancelling or queued/unaccepted dose recording cannot complete refusal work. P01 rechecks same-day and exact-slot clinical eligibility. No guessed slot or time is synthesized for legacy sources lacking a scheduled target.

Accepted corrections retire superseded administration-derived work with a source_retired event and preserve history, then synchronize the accepted replacement inside the correction aggregate. Pending/rejected corrections do not create effect work. Lists and Tasks conceal ineffective open work even before synchronization; retired history remains readable with no clinical action. Workflow migration rollback now refuses destructive loss of evidence; deployment reversals require a forward migration.

Additional shared seams for serial Main integration: MedicationAdministrationCorrectionController approval/rejection; WorkerMedsController recordPrnEffect; EmarController storePrnEffectiveness; RefusalFollowUpController complete/notify authority; the two existing effect component wrappers; emar-navigation.ts; offline-queue.ts and its actor-bound dismissal regression; shift-med-snapshot.tsx read-only canonical carry-over lens. Main retains initial Meds today/My Day/Tasks/calendar assembly and joined P02/P03/P04/P05/P06/P07a/PIN/P09/P10 acceptance. Source owners retain canonical authorization and append-last audit responsibility.

Verification so far: PHP syntax and Pint; ten new/changed UI files transpiled/formatted with no full build; five pure NZ-time checks passed. The first Pest wrapper stopped before executing tests due to a conflicting helper named post; it was renamed postFollowup. The recovery run tested frozen commit 9d22f9375 and finished with 4 failures, 35 warnings and 165 assertions (39 cases, 720.82 seconds). Three failures were incomplete synthetic fixtures (administered_by and handover staff/notes); one exposed unloaded database defaults on a newly created workflow. Commit 75d189252 sets state/revision explicitly and repairs all three fixtures. A rerun is pending Main's repaired queue release; the machine-wide heavy hold remains in force. These results do not certify functional correctness, concurrency, visual fidelity or full frontend types. Main performs combined browser/type/build checks. No live import/migration, server, push, merge, deployment or PR has been performed.

## P10 emergency-access and PIN-2 consumer completion

MedicationFollowupClinicalScope resolves the current grant after Client/medicine/source/Shift locks and before the sorted actor/target/co-signer User set. The transition rechecks current actor, direct approved Site membership, grant identity, minimum-necessary/incident acknowledgements, retained policy, co-signer authority and running status. Normal owner/covering authority still requires current per-person access independent of the legacy read-grant fallback. A valid current grant supplies the existing emergency clinical boundary; it never grants lead signing, reassigning another owner's work or source-owned completion. Current PRN medication activity checks run inside the actual transition. The legacy aliases no longer release a clinical guard before the write.

Ended/expired grants return P10's typed 409 emergency_access_ended with no source/workflow/history change. The expired offline exception remains dose-only; a follow-up cannot use a captured-offline claim. Successful grant use writes the retained BreakGlassAccessEvent once and appends the matching emergency-access P09 event together with the follow-up event, last in the same transaction. Identical replays add no use evidence; an audit failure rolls everything back.

Local dependency-only commit 91499252e retains seven exact P10 files from e5cad7de0 (grant/policy models, typed exception, two evidence models and two migrations) for isolated regression verification. Main already integrated P10 and must not cherry-pick this dependency-only commit. The following owned P08a commit is the consumer change.

PIN-2 confirm rows use context.nomination_id to mount the source-owned SecondPersonConfirmationDialog in the named colleague's own session. Terminal answers refresh the parent canonical list/meters/dose and reload the canonical row; the source reload displays its retained confirmed/disputed/expired result. The affordance requires exact owner, current approved staff, medication view and controlled.witness, with no administer.record or manager override. The generic transition completion guard is unchanged. Main's integrated PIN-2 component/controller/scheduler owns all source checks and actual dose verification; P08a does not fabricate them.

The follow-up dialog scopes private loads to actor and workflow identity, aborts stale requests, clears on read-access loss and renders otherwise unmapped server validation errors. Typed grant expiry keeps the draft open with a contextual emergency-access link in another tab; after starting access the same entries may be retried. The expired grant itself never authorizes that retry.

Candidate verification: four changed PHP files pass syntax and Pint; diff whitespace passes. Nine new synthetic cases bring the feature file to 45 cases, plus three pure time cases. These have not been run after the consumer changes. Main explicitly retained database-suite ownership for one final integrated snapshot. The formerly shared TypeScript/Prettier package paths are currently absent/empty, so the latest PIN-2 consumer edit has no local transpile/full-type/browser verification. Main must run the integrated checks with its restored dependencies. No new heavy/database launch was made after the hold.
