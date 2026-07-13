# HR Deferred Audit Backlog Design

**Status:** Approved in conversation on 2026-07-11

**Goal:** Close the concrete deferred HR audit backlog with durable organisation boundaries, auditable lifecycle transitions, complete workflow seams, and consistent HR web surfaces while preserving the already-approved D-7, D-10, and D-11 no-action decisions.

**Source of truth:** `HR_AUDIT_FIX_PROGRESS.md` and `HR_CLOSEOUT_PROGRESS.md` at release commit `9eaab3a5`, verified against current `main`. Historical rows are evidence leads, not automatic requirements: every row must be rechecked and classified as implemented, stale, intentionally closed, or still requiring work.

## Delivery boundary

The work runs as sequential, test-driven slices on `codex/hr-deferred-backlog`. Each slice begins with a failing regression or contract test, makes the smallest production change that satisfies the approved behavior, runs focused regressions, updates both HR ledgers, and commits independently.

This programme includes the concrete deferred audit items approved in the design review. It does not execute every standalone HR redesign prompt wholesale. It may reuse those prompts as visual or interaction references where they describe one of the named surfaces below.

The original closeout decisions remain authoritative:

- D-7 keeps HR staff performance reviews separate from governance board/CEO reviews.
- D-10 keeps confidential HR wellbeing records out of Control Room.
- D-11 keeps procedure acknowledgements H&S-owned and read-only from HR.
- D-4 protects primary business records but still permits deliberate deletion of child rows, temporary files, pivots, reactions, reminders, reference artefacts, and privacy/moderation content.
- Existing full-page detail and escape-hatch forms remain valid where the audit explicitly accepted them.

## Current-state corrections

Two historical descriptions are stale and must not drive duplicate work:

1. `claude/stoic-dubinsky-94954c` is already an ancestor of current `main`; `main` is 344 commits ahead. The calendar redesign is present. Only the named hardening and archive gaps remain.
2. Operations timesheet approval already calls `TimesheetHrSyncService`. The defect is identity handling: a timesheet already linked to a submitted `HrTimeEntry` can cause `syncToHr()` to create another approved entry keyed by `source_type=timesheet`, leaving the original entry submitted. The fix must update the linked record atomically and avoid duplicates.

## Requirement and disposition matrix

| ID | Area | Approved outcome |
|---|---|---|
| A1 | HR audit viewer | Add organisation scope to canonical `audit_logs`; derive it for new events; safely backfill resolvable rows; switch the HR viewer and record-detail endpoint to the canonical store; retire the unused `HrAuditLog` service/model path without dual-writing. |
| A2 | Audit visibility | Emit an explicit canonical event when a payroll export profile is promoted and other profiles are demoted in bulk, including affected profile identifiers. |
| E1 | HR CSV exports | Apply one shared cell-neutralisation contract to `EmployeeImportExportService` and `ReportBuilderService`; prove all other HR CSV writers are already protected or add the same guard where source inspection contradicts that. Preserve numeric machine-import fields. |
| R1 | Calendar retention | Add a real archive lifecycle to HR calendar events. Normal removal archives the row and retains attendees, attachments, reminders, and files; active queries exclude archived events; authorised history reads can include them. |
| R2 | Salary-band retention | Add `is_active` lifecycle support. Replace destructive or ambiguous removal with deactivate/reactivate behavior while retaining historical compensation references. |
| O1 | Exit-interview linkage | Add an explicit nullable relationship between an offboarding task and its exit interview; new flows use the relationship and no new code depends on task-title matching. The backfill attaches a legacy interview only when the employee has exactly one open unmatched exit-interview task; zero or multiple candidates remain unlinked. |
| O2 | Exit-interview scheduling | Notify the selected interviewer when a future interview is scheduled and when it is materially rescheduled; do not notify for post-hoc completed records. |
| O3 | Late-issued assets | When an asset is assigned to a worker with an open offboarding checklist, reconcile a missing asset-return task exactly once and notify its assignee. Returned/reassigned assets must not create duplicate tasks. |
| O4 | Offboarding assignees | Resolve configured role assignees first, then the employee manager, then the initiating HR actor. Every required default task must have an assignee or fail creation with a clear validation error before partial writes. |
| X1 | Timesheet-to-HR identity | On approval, lock and update the linked `HrTimeEntry` when present; otherwise reuse an existing canonical source row or create one. The transaction must end with one approved entry and one stable `timesheets.hr_time_entry_id`. Repeated approval is idempotent. |
| X2 | Benefit tenancy | `BenefitsService::enrollEmployee()` must reject plan/profile organisation mismatch before any write or notification, regardless of caller. |
| Q1 | Offer expiry | Add a manager action that immediately expires an unanswered sent offer by setting an explicit expiry timestamp, invalidating portal use, recording actor/reason, preserving resend as the intentional revival path, and leaving accepted/declined offers immutable. |
| Q2 | Scorecard quorum | A candidate cannot advance beyond the interview stage until every assigned interviewer on the relevant completed interview has submitted a scorecard. HR managers may override only with a non-empty reason; the override is canonically audited. |
| W1 | Employee invitations | Replace the generic password-reset presentation with an HR-branded invitation notification while retaining the secure reset-link mechanism. Refuse reinviting an already-active user and give the manager an actionable validation message. |
| W2 | Leave approval-chain administration | Add tenant-scoped manager CRUD/reorder/activate controls for `HrLeaveApprovalChain` using the existing chain model and existing leave approval behavior; do not migrate leave onto a different workflow engine. |
| W3 | Supervision | Expose the existing session-type taxonomy in the create wizard, notify an employee when a visible supervision note is added, and remove the confirmed orphaned supervision dialog after route/import scans prove it unused. |
| W4 | OKR completion | Wire `HrNotificationService::notifyGoalCompleted()` to the single transition into completed state. Repeated progress updates at 100 percent must not notify twice. |
| W5 | Announcement replies | Notify the announcement author for a reply when the replier is someone else and the author still belongs to the same organisation. Do not create reaction notifications. |
| W6 | Compliance expiry | Send worker-facing vetting/licence expiry nudges using the existing scheduled reminder boundary, deduplicated by persisted reminder stamps. Manager reminders remain unchanged. |
| W7 | Driver requirement seam | Add optional required licence class and endorsement requirements to shifts, expose them on shift create/edit/read surfaces, and enforce them through the existing compliance/rostering assignment and publish guards. Shifts without requirements behave exactly as before. |
| U1 | Payroll surface | Replace the generic payroll hero with a specialised HR-kit hero whose counts are server-derived and deep-linked. Replace the named runs/payslips hand-built tables with the established responsive HR table/mobile-card and row-context action pattern without changing routes or lifecycle rules. |
| U2 | Training surface | Refactor the bespoke catalog hero onto the HR hero kit and remove the raw `oklch()` fallback in favor of repository tokens while preserving actions, filters, and counts. |
| U3 | Feedback surface | Add a specialised feedback hero using server-derived deep-linked counts and the existing HR tab/filter conventions. |
| U4 | Shared dialog | Move `TextPromptDialog` from recruitment ownership to the neutral HR component layer and update every import without changing behavior. |
| U5 | Time refresh | Make the existing soft refresh update both KPI data and the visible entries collection without resetting user filters or selection. |
| C1 | Calendar resilience | Guard optional layer queries with `Schema::hasTable`; add route-level view defence while retaining controller-level manage and tenant checks; support the dormant attendee `team` enum through the existing tenant-scoped `HrEmployeeProfile.team` taxonomy and a required team audience reference. |
| L1 | Ledger truth | For every historical open observation, record one of: implemented by a requirement above, stale with source evidence, or closed by an approved boundary. No unclassified `open`, `deferred`, or partial row remains in the final ledger. |

## Explicitly closed observations

The following audit observations are not implementation gaps and will be marked closed rather than changed:

- `setActive` remains an HR visibility control; offboarding remains the login-revocation owner.
- Private profile uploads continue to use extension allowlists and authorised downloads; client-supplied MIME metadata is not trusted for access control.
- Per-task onboarding completion stays quiet to avoid notification spam; checklist completion remains the aggregate signal.
- Child/template/reference deletion stays within the C4 boundary unless a row is reclassified as a primary business record by evidence.
- Declined leave is resubmitted as a new request; no appeal state is added.
- Break compliance remains warn-not-block so a worker cannot be trapped in an open shift.
- Permissive pay-type validation remains compatible with integration callers even when the current UI offers fewer values.
- The multi-item expense full page remains an escape hatch; the dialog remains the primary guided flow, so no browser-storage draft system is added.
- Reactions remain ambient and notification-free.
- The separate performance, wellbeing/Control Room, and procedure/H&S ownership decisions remain closed under D-7, D-10, and D-11.

## Architecture

### Canonical organisation-scoped auditing

`audit_logs` becomes the only HR viewer source. A nullable indexed `organization_id` is added so existing non-organisational/system events remain representable. `AuditLogger` resolves scope in deterministic order: explicit metadata, auditable model `organization_id`, auditable model `tenant_id`, related client organisation, then actor `organization_id`. HR code must pass explicit organisation metadata when the auditable object is a global `User` or when no model is supplied.

The migration backfills only rows whose organisation can be established from the actor or client relationship. It does not guess across organisations. The HR viewer filters `organization_id` using the same HR tenant resolver used by the rest of the module, derives action/model filters from real canonical rows, and never falls back to unscoped results. The old `hr_audit_log` table and its creation migration remain physically present for rollback/history. The unused service/model application path is removed, and no current application read or write depends on that table.

### Archive and deactivation contracts

Calendar-event removal becomes an archive command, not a fake UI rename. The schema gains `archived_at`, `archived_by`, and optional `archive_reason`; the model exposes active/archived scopes; mutation routes reject archived rows except restore; and attachment storage is retained. A separate explicit purge is not introduced.

Salary bands gain `is_active` and deactivation attribution. Active selectors exclude inactive bands, while historical placements and reports can still resolve their band. Reactivation is manager-only and audited.

### Offboarding identity and reconciliation

The explicit relation is stored on `hr_offboarding_tasks.exit_interview_id`, because the task is the workflow step that may or may not be backed by an interview. Creating an interview from a checklist writes both records in one transaction. Standalone interview creation may attach to a single open exit-interview task for the same employee; if zero or multiple candidates exist, it leaves the relation empty and reports no false completion.

Asset-task reconciliation is a dedicated idempotent service method keyed by checklist plus asset identity. It is called from asset assignment after the assignment succeeds and from offboarding creation for the initial snapshot. Required-task assignee resolution is completed before task inserts, so an unresolved required task aborts the transaction cleanly.

### Cross-module identity

`TimesheetHrSyncService` owns the timesheet-to-HR identity rule. It first locks the `Timesheet`, then locks the currently linked `HrTimeEntry` if one exists and belongs to the same worker/organisation. If no link exists, it looks up the canonical timesheet source row. Conflicting links or cross-organisation rows raise validation errors instead of silently re-pointing. The service updates payroll fields and approval state in place, then saves the stable link quietly inside the approval transaction.

Benefit tenancy is enforced at the domain-service boundary before opening the enrolment transaction. Controller checks remain defense in depth.

### Recruitment gates

Force-expiry is an explicit manager command with a required reason and actor stamp. Candidate portal endpoints treat `portal_expires_at <= now()` as unavailable regardless of token. Resend rotates the token, sets a new expiry, and records revival through the existing audit trait.

Quorum is derived from the assigned interviewer user IDs on the latest relevant interview. Each unique assigned interviewer must have one submitted `HrInterviewScore`. If no interviewers were assigned, advancement is blocked rather than silently treating zero-of-zero as complete. The override endpoint accepts a reason, records missing interviewer IDs/counts, and then performs the existing stage transition in the same transaction.

### Licence requirements

Shifts gain nullable `required_licence_class` and JSON `required_licence_endorsements`. The fields use the same licence taxonomy already used by HR driver records. The existing assignment/publish compliance validator receives one new evaluator that checks an active, unexpired worker licence and required endorsements. It returns plain-language blockers and canonical HR links for authorised managers; it does not copy or mutate driver records.

### UI composition

New heroes and tables reuse the current HR design system rather than introduce new primitives. Counts remain server-derived, links retain current routes and query parameters, desktop tables have an equivalent mobile-card presentation, and row actions use existing confirmation/dialog patterns. The shared prompt dialog moves without API changes.

Calendar team audiences use the existing team names already returned by the calendar controller. Creating or editing a team-audience event requires one of those tenant-scoped names, stores it in `audience_ref`, and resolves visibility against active employee profiles in the same organisation. Named-person RSVP behavior remains limited to `person` rows.

## Error handling and permissions

- Every new mutation uses existing HR permissions and the resolved organisation boundary before loading or writing the target record.
- Domain invariant failures use validation exceptions or controlled logic exceptions that controllers convert to visible form/flash errors; they do not become HTTP 500 responses.
- Notification delivery is best effort after durable writes, logged on failure, and never rolls back the business transaction.
- Migrations are additive before code depends on them. Backfills are deterministic and idempotent.
- Bulk operations retain per-record validation and auditability.
- The work uses existing HR manage/view permissions; it does not introduce new global permission names.

## Testing strategy

Every behavioral slice follows red-green-refactor and records the deliberate red output in the ledger.

Backend coverage includes:

- canonical audit organisation isolation, backfill behavior, action/model filtering, and global-User audit attribution;
- CSV formula neutralisation for `=`, `+`, `-`, `@`, tabs, and carriage returns while numeric values remain machine-readable;
- archive/deactivate/restore retention of dependent records and files;
- offboarding relationship, ambiguous legacy behavior, late asset idempotency, assignee fallback, and notification rules;
- linked time-entry identity, duplicate prevention, cross-organisation rejection, and repeated approval idempotency;
- benefits mismatch rejection with zero rows/notifications;
- offer expiry/revival and portal denial;
- scorecard quorum, override reason, tenant isolation, and audit evidence;
- approval-chain CRUD/reorder/activation and continued native leave approval behavior;
- notification trigger and deduplication contracts;
- licence requirement assignment and publish guards.

Frontend coverage includes hero count/link contracts, responsive table/card parity, action permissions, session-type submission, time-refresh state preservation, and calendar archive/restore interactions.

Slice gates are focused Pest/Vitest tests, PHP syntax for touched PHP files, TypeScript, focused zero-warning ESLint, and `git diff --check`. Final gates are the complete HR Pest suite plus directly affected cross-module suites, complete Vitest, PHP syntax over every changed PHP file, TypeScript, zero-warning ESLint over every changed TS/TSX file, client production build, SSR build, migration fresh/rollback checks where supported, and real browser verification of each changed HR surface.

## Ledger and release evidence

`HR_DEFERRED_BACKLOG_PROGRESS.md` will be created in the implementation worktree as the execution ledger. `HR_AUDIT_FIX_PROGRESS.md` and `HR_CLOSEOUT_PROGRESS.md` will receive final append-only closeout sections rather than rewriting historical evidence. Each requirement ID will link to its commit, tests, assertions, build evidence, migration state, and any explicit no-action classification.

The goal is complete only when every requirement in the matrix is green, every historical open observation is classified, the final gates pass from the integrated branch, and browser proof covers the changed web surfaces. Push, merge, deploy, and live verification are not implied unless separately authorised.
