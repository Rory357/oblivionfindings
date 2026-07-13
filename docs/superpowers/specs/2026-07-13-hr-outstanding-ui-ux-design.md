# HR Outstanding Production Readiness and UI/UX Design

**Date:** 2026-07-13

**Status:** Approved by the autonomous HR task brief

## Purpose

Close only HR gaps that are still demonstrably present in the release baseline. Preserve every approved ownership boundary and avoid reopening the completed Client/HR live closeout.

## Source-of-truth rules

- `HR_DEFERRED_BACKLOG_PROGRESS.md` is the canonical historical classification, but its approved redesign boundaries may be addressed in this separately approved HR track.
- `HR_AUDIT_FIX_PROGRESS.md` and `HR_CLOSEOUT_PROGRESS.md` are historical evidence, not a reason to restore stale findings.
- `docs/client-hr-live-gap-closeout.md` remains complete and unchanged.
- Native approval queues remain canonical. The generic chain service remains a read/action surface only for its own instances.
- Confidential HR Wellbeing data remains inside HR and never feeds Control Room.
- HR/governance performance domains remain separate. H&S procedure acknowledgements remain H&S-owned and read-only in HR.

## Current-source disposition

### Implement now

1. **E-signature tenancy and lifecycle notification safety.** The request endpoint validates global document and user identifiers, and sender-side nudge/resend/cancel actions do not currently prove that the signature or document belongs to the actor's resolved HR tenant. Signing and declining also produce no outcome notice for the original requester. Enforce tenant membership before writes, then send a minimal mail/database outcome notification only to the same-tenant requester after a successful state transition. Do not include signature image data or document contents.
2. **Approvals clarity without workflow migration.** Keep native approvals and generic-chain instances separate. Replace raw class names such as `HrLeaveRequest #12` with a server-owned human label, send ISO instants, render dates with the shared `en-NZ`/Auckland helper, give the combined surface a useful empty state, and retain owning deep links for native workflows.
3. **Wellbeing interaction clarity.** Preserve immediate actor-scoped undo. Add regression proof that one manager cannot undo another manager's action and that cross-tenant subjects are rejected. Make the toast announce its status accessibly and explain that Undo removes only the current actor's latest triage action. Do not add Control Room integration or expose confidential notes.
4. **Specialised, useful HR heroes.** Add dedicated Analytics, Headcount, and Succession hero components using existing server-derived stats and existing actions. Remove the immediately duplicated KPI-card rows from Analytics and Headcount so the heroes are information-bearing rather than decorative. Keep charts, filters, tabs, cards, and workflows unchanged.

### Retain with evidence

- The Wellbeing duty-of-care/Control Room boundary remains guarded by `WellbeingControlRoomBoundaryTest`.
- Native approvals stay off `ApprovalWorkflowService`; `ApprovalsInboxSeamTest` continues to prove federation and native ownership.
- E-signature signer authorization remains signer-scoped. New requester notices carry only outcome metadata and an HR document link.
- No Client Profile files, Client ledgers, generated route output, package lock, database schema, or shared primitive changes are required.

## Architecture and data flow

### E-signatures

`ESignatureController` resolves the actor's HR tenant before request, nudge, resend, or cancel operations. The service receives already-authorized models and keeps state transitions transactional. After a successful sign or decline transaction, it resolves `requestedBy`, verifies `organization_id === signature.tenant_id`, skips self-notification, and delivers a compact queued notification on mail and database channels. Delivery failure is logged and never rolls back the signature outcome.

### Approvals

`ApprovalController::pending()` remains the sole composer. It emits stable `item_label` values and ISO timestamps for generic and native rows. The React page formats those instants through `resources/js/lib/datetime.ts`, retains two visibly separate sections when work exists, and displays one action-oriented empty state only when both collections are empty.

### Wellbeing

The existing `WellbeingCareService::undoLastFlagAction()` query remains keyed by both staff subject and actor. Controller tenant checks remain mandatory. Tests lock that contract. UI copy and live-region semantics clarify the limited reversible action; no new persistence or data-sharing path is introduced.

### Heroes

Three focused components under `resources/js/components/hr/` wrap `HrHero`. They accept the existing page props and callbacks, calculate only presentation values, and deep-link only to destinations that already exist. Page components retain canonical server data and remove only duplicate summary cards.

## Error, privacy, and accessibility behavior

- Cross-tenant e-signature identifiers return 403 before any model mutation or notification.
- Non-pending sign/decline attempts keep their existing safe failure behavior and send no outcome notification.
- Notification payloads omit document bodies, signature data, IP addresses, user agents, decline reasons, and Wellbeing data.
- Approval and signature dates use `en-NZ` presentation in `Pacific/Auckland`.
- The Wellbeing toast uses `role="status"`, an `aria-live` region, a labelled Undo control, and the existing four-second window.
- Existing empty states and actions remain keyboard reachable; no hover-only workflow is added.

## Verification design

- Every functional change begins with a focused failing Pest or Vitest test and recorded RED/GREEN output in `docs/hr-outstanding-ui-ux-goal.md`.
- Focused backend suites cover E-signature, approval federation, Wellbeing actor/tenant scope, and the existing Control Room boundary.
- Frontend contract tests prove the three specialised heroes, duplicate-KPI removal, approval date helper/empty state, signature date helper, and accessible Wellbeing undo copy.
- Final gates include PHP syntax, scoped Pint, scoped Prettier, zero-warning scoped ESLint, TypeScript, Vitest, focused and aggregate HR Pest, client build, SSR build, `git diff --check`, real local desktop-web browser checks, and process cleanup. Mobile-card and WebView support are explicitly outside this desktop application track.

## Explicit non-goals

- No second approval engine, business-workflow migration, Control Room Wellbeing feed, governance/HR performance merger, H&S acknowledgement ownership change, Client Profile change, production write, deployment, push, or broad formatting pass.
