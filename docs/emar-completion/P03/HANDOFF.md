# P03 implementation handoff

Approved design: 9822d78b4, docs/emar-design/P03/v1, APPROVAL.md. Single organisation, approved sites, roles, canonical ownership and per-person privacy govern every mutation. No new tenant boundary.

Owned checkout: C:/Users/steph/.codex/worktrees/emar-p03-support/oblivionfindings. Branch codex/emar-p03-support; base 9747cf7cb654c2ef441e8f60c7ea1b5918081925. No push, merge or deployment.

## Review commits and prerequisites

Owned: 5212bb330 initial assessment/agreement/support/consent implementation; fe9d1a525 history menus and draft protection; next integration correction commit follows this note.

Dependencies (Main integrates original sources once): P09 466ce69df (local 390632233); P08a 8d799fd36 (local a831bce09) and a5b12397f (local 14744f5b8). Local P08a scheduler conflict retained both its minute follow-up schedule and P03's daily review schedule. P04 c38250d91 supplies MedicationReconciliationApplied; listener uses its exact event name and clientId/reconciliationId contract. P04 source apply is tested in Main's combined checkout, not copied here.

## Canonical contracts

- MedicationSupport::summary(Client,User) and batched summaries(Collection,User) return permission-filtered current assessment, versioned agreement, effective per-medicine mode, desired mode awaiting agreement, concealed count, review state/reasons and role actions. Effective mode is authoritative: self_managed / prompted / assisted / staff_given. Score outcome is only the cap. New orders default Administer.
- P02 imports MedicationSupportPanel from resources/js/pages/emar/support/support-panel.tsx; omitted callbacks make it read-only. AssessmentDialog, AgreementDialog, MedicineSupportDialog and ConsentDialog are exported from support/_dialogs.tsx. Staff picker is current, canonical site staff, excluding actor; server locks independent witness evidence.
- P02 reading.tsx currently uses the older RecordSupportPlan shape (administer/assist/prompt/independent, legacy signed agreement fields). Main/P02 must map effective mode from MedicationSupport, and use the versioned agreement. Never present a desired Self-managed/Prompt choice as effective before an agreement. P03 did not edit P02-owned record/read-service files. The existing /self-admin?client_id link now resolves the authorised person instead of the whole register. Register uses EmarHubRail and common breadcrumbs; detail uses common record breadcrumbs and Inertia-preserving navigation.
- P01 DoseSlotProjection/ScheduledDoseStates expose support_mode; historical recorded outcomes retain their support context, today's unrecorded owed doses read immediate withdrawal, Self-managed stays informational. The model saving hook follows the existing order lock and prevents fabricated Self-managed outcomes across recording paths. P01 owns taken-with-prompting/assistance recorder presentation.
- P08a ensureForSource('support-reassessment', stable sourceId, ...) creates sole canonical work identity. Reassessment prelocks its owned workflows in id order, calls completeFromSource(sourceKey, User, 'reassessed', facts, auditEvents), collects its own event, and appendMany runs once LAST in the same transaction after domain writes and durable replay receipts. No second follow-up lifecycle ships.
- Automatic order/error/refusal/date/reconciliation triggers persist a source outbox receipt before source commit. Every-minute emar:support-review-delivery retries P08a ensure without changing the source's successful outcome. Due time stays seven NZ days after the original trigger. Pending delivery is visible truthfully on the plan; newer reassessment covers the predecessor's delayed receipt. Failed receipt persistence rolls back canonical source transactions. This is the existing outbox delivery pattern, not a Task/workflow copy.
- Consent uses the existing actor-bound secure IndexedDB queue; queued means saved on this device and waiting to send. Server returns explicit processed/duplicate sync acknowledgements, rejects stale or unauthorised replays, and durably binds UUIDs to canonical actor/person/action/details. Online withdrawal immediately Administer; a request for independence preserves support pending reassessment. Exact NZ minutes require explicit repeated-hour occurrence and reject gaps/future times.
- Legacy DELETE retains soft archive semantics and creates restrictive support events so current independence cannot outlive the archived assessment. Earlier records remain available. Agreement staged attachments are removed when its governing transaction fails.

## Verification

Initial 5212bb330 PHP snapshot: 15 tests, one failed assertion, 14 warning-marked tests, 129 assertions, 331.26 seconds. The controlled-order fixture changed classification through the normal order edit hook, invalidating its verified state; the fixture is corrected for the next snapshot. Warnings will be exposed on the focused rerun. Log storage/logs/p03-workflow-20261003-1.log.

Pure frontend checks: 8 tests passed across support/types.test.ts and support/offline.test.ts (policy caps/CD boundary, NZ gap/repeated minute, earliest invalid step, durable queue restart/replay, stale rejection, actor mismatch, storage failure). Seven prior source files parse without syntax diagnostics; targeted formatting passes. Added validation and queue sources are included in Main's combined frontend verification. Full frontend types/build/browser are reserved for Main.

Next focused PHP snapshot includes MedicationSupportWorkflowTest and updated SelfAdminTest. It tests real P08a batch closure/rollback, durable replay, staged attachment cleanup, archive safety, canonical/privacy/stale boundaries, trigger receipt rollback/retry and the P04 named event seam. No readiness claim until its results and Main's integration/browser checks are recorded.

Hospital/health-ability changes use the explicit approved trigger bridge; this repository's DoseAwaySources states actual hospital admission/discharge event types are deferred. No hospital event is inferred from free text or planned leave. Main must tie the bridge to attributable canonical hospital/ability evidence when those sources are available.

## Dependency paths and cleanup

Physical vendor copy: primary/vendor -> owned checkout/vendor. PHP Reflection of MedicationSelfAdminAssessment and Application::inferBasePath resolve this exact owned checkout. No live .env or medication data copied.

Only dependency junction: owned checkout/node_modules -> C:/Users/steph/Herd/oblivionfindings/node_modules. Shared target is read-only: no install/update/delete. Vite @ alias and inputs, Vitest alias/setup/include, and TS baseUrl/include resolve under the owned checkout; default build output is its public/build. Cleanup must verify the literal link path and remove only the junction itself, never recurse through the target.

All heavy tests use C:/Users/steph/.claude/heavy-lock.sh, one queued/running command per session and the machine-wide memory gate. Do not modify/bypass it or touch another worker's process.
