# W15 closure slice — 14 September 2026 (Claude worktree session)

Continuation of the Codex task "Complete provisioning and staff onboarding/offboarding" in worktree
`provisioning-onboarding-offboarding-9d5b4a`, sole implementer for this slice. Baseline: main `203bd1b32`
(all prior W15 work committed in `cca1a84b0`). User direction for this session: close the remaining W15 gaps
towards E14/E07, verify with tests plus a local browser pass, ship manual-evidence fulfilment only (no external
account adapter), and push to main.

## Gaps found by the pre-implementation audit (code, not notes)

Three read-only audits of the current source (backend lifecycle, HR joiner/mover/leaver bridge, frontend)
confirmed the previous "remaining work" list was largely delivered and found these concrete gaps instead:

1. No notifications anywhere in provisioning: approvers, requesters, owners never heard about launch, approval,
   decision, completion, failure or cancellation (pull-only tracking).
2. A catalogue submission that needs approval fanned `approval_required` onto every task but nobody was routed:
   each task waited for an agent to hand-pick approvers.
3. Approval expiry was only detected lazily on read; nothing swept overdue approvals or notified anyone.
4. Reversal existed only at workflow level; a cancelled workflow was terminal (no resume); HR-driven
   cancellation never produced corrective work for completed grants.
5. A catalogue item could keep launching new work through a template version that had been withdrawn.
6. The two bulk paths exposed disjoint operation sets; register rows had no lifecycle actions; no approvals
   inbox; requester tracking had no per-task breakdown; the workflow page had no dependency ordering view;
   catalogue authoring had no approver configuration; dead legacy cancel dialog.

## Implemented

Backend
- `ItProvisioningApprovalRoutingService` (new): auto-requests approval for a gated task from the catalogue's
  configured approver pair, else the active service desk fallback queue; excludes requester/creator/beneficiary;
  NZ end-of-day deadline from `approval_window_days` (default 5); records `approval_requested` with
  `routing_basis` or `approval_routing_unavailable`. Called from catalogue submission (workflow and single-task
  paths) and for every new reversal task.
- Catalogue contract fields `approver_user_id`, `cover_approver_user_id`, `approval_window_days`
  (migration `2026_09_14_000010`); `CONTRACT_DEFAULTS` keeps older immutable versions readable; publish refuses a
  half-configured or ineligible approver pair.
- `ItProvisioningNotifier` + `ProvisioningUpdateNotification` (database + mail through the existing tracked
  IT delivery outbox, with send-time recheck per audience in `ItEmailDeliveryService` and retry reconstruction).
  Events: approval_requested (approvers), approved/rejected/approval_expired/fulfilled/failed/retried/cancelled
  (requester audience, never the acting user), workflow_completed (requester + beneficiary), reversal_requested
  (owner/cover). Requester subjects use the retained public catalogue title, never internal task names.
- `it:expire-provisioning-approvals` (`ExpireItProvisioningApprovals`, scheduled every 10 minutes in
  `ItAutomationScheduleCatalog`): marks overdue pending approvals `expired`, records event/audit, notifies.
  Readiness, tracking and lifecycle treat `expired` explicitly (fresh request allowed, decision refused).
- `ItProvisioningReversalService` (new, extracted): one reversal per completed original, reverse dependency
  order, inherits assignee, auto-routes approval. Per-task `reverse` request action; workflow `resume`
  (refused while any non-cancelled corrective task exists or the HR source is still cancelled); workflow-level
  `request_approval`, `approve`, `reject`; `ItProvisioningWorkflowLifecycleService::actions()` server verdict.
- HR rule: cancelling an onboarding/offboarding checklist now raises approval-gated corrective tasks for every
  completed original (`source_reversal_requested`); resuming the checklist reopens cancelled IT work in the same
  transaction when no corrective work started, otherwise records the explicit next step. Joiner/mover originals
  for an inactive employee are blocked.
- Submit-time check: a catalogue item whose pinned template version is no longer the template's published version
  rejects new submissions (existing requests keep their contract).
- Register `view=approvals` (own decisions first; `mine`/`waiting`/`unrequested` filters) and `my_decisions`
  meter; task rows carry `dependency_request_ids`/`approval_expires_at`; requester detail carries a per-task
  breakdown (stage/type/status/approval/due only).

Frontend
- Register: Approvals rail view and meter; per-row kebab/context-menu lifecycle actions opening the shared
  `ProvisioningCommandDialog` inline (fulfil routes to the task page); bulk operations extended to
  request_approval/approve/reject/fail with the same receipt recovery; `SpecialistRecordList.extraActions`.
- Shared `provisioning-command-fields.ts` (task page + rows + workflow approval request).
- Workflow page: server-driven actions (resume, request approval, approve, reject), "Task order and
  prerequisites" panel with blocked-by badges and reverse-order corrective work.
- Requester tracking: approval shown via `StatusBadge` with plain-language labels; "Work steps" list.
- Catalogue editor: "Approval routing" (default/cover approver pickers, approval window) with review/publish
  summaries; removed the unreachable legacy `provisioning-cancel-dialog`.

## Verification (this worktree, current source)

- PHP (Pest, isolated per-pid MySQL): lifecycle acceptance 16, workflow 20, provisioning 13, bulk 6, catalogue
  40, drafts 4 — **98 passed / 1564 assertions** after the backend changes. New
  `ItProvisioningApprovalRoutingTest` (7 cases: configured routing + notifications + workflow-level decision,
  fallback queue + routing gap, scheduled expiry + re-request, per-task reversal once + resume refusal, clean
  resume, HR cancel → corrective work / clean HR resume, withdrawn template) — **7 passed / 114 assertions**.
  After merging `origin/main` (`1755a41fe`, W18/W20 macros + Governance hubs): operations + lifecycle +
  workflow + routing + catalogue suites **120 passed / 1929 assertions**; the only failures are the two
  Knowledge article lifecycle cases in `ItServiceOperationsTest`, which fail identically on main before this
  slice (verified by running them in the parent checkout) and belong to the Knowledge revision workspace
  owner. `ItServiceOperationsTest` schedule expectations were realigned (the W20 recurrence and the merged
  Knowledge upload retention definitions had left the count stale).
- Frontend: `tsc --noEmit` clean; ESLint clean on changed files; vitest `components/it` + `pages/it`
  **910 passed** (one expectation updated for the new approval summary text); production build green.
- Browser (worktree dev server, ordinary sign-in as Demo Admin, seeded joiner workflow of 11 tasks):
  Approvals view lists the 4 gated tasks; row kebab exposes Assign/Cancel/Fail/Request approval; the inline
  request-approval dialog loads eligible agents, and the server correctly refused the acting user as cover with
  the eligibility message (422 shown in the dialog with recover/cancel controls); workflow page shows
  server-driven actions and the task-order panel with prerequisite blocking; catalogue editor shows the
  Approval routing section with a populated approver picker. Console clean apart from the expected 422.

## Not done / still open

- No external account adapter (by decision); fulfilment remains labelled manual evidence.
- Template version compare/restore UI; requested-for permission configuration in catalogue authoring; the
  eligible-agent picker does not pre-filter the acting user for approver fields (server refuses correctly).
- Automatic leaver launch from termination without an offboarding checklist; mover triggers beyond
  position/site/employment type.
- Legacy `/it/provisioning/{id}/…` and `/bulk` endpoints remain (tested, gated); UI no longer calls them.
