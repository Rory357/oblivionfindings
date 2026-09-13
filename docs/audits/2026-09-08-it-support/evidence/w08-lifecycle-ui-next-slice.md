# W08 lifecycle UI integration proposal

Read-only source review, 2026-09-10. This is a dependency plan, not an implemented or tested feature. Existing compiler and attachment-accessibility sources remain frozen. Root owns the graph evaluator/read projection; W00 owns completion history and task wizard focus; W01 owns approval command reliability. No runtime, migration, test or operational policy changed during this review.

## Confirmed frontend gaps and reuse

`ticket-work-tasks.tsx` currently derives prerequisite readiness from `status !== completed` and displays only mutable current completion fields. It therefore cannot represent a completed task whose prerequisite generation is no longer valid, or show a preserved completion after reopening. Replace that derivation with root's canonical read verdict once supplied; retain the existing task register and authorized task anchors.

Add one small task-history projection component using approved `Collapsible`/`Accordion`, `StatusBadge`, existing text/evidence rows and `formatDateTime`. Show the actual immutable completion generation, actor/time, note/evidence, definition snapshot and prerequisite/approval references supplied by the backend. Distinguish current completion from historical fact and known-empty bindings from unknown legacy provenance. A legacy captured definition must say it was observed at capture, not claim it was the definition originally completed. Do not add history edit/delete actions, expand private history into the participant summary, or expose evidence through receipt/error metadata.

Extend `ItWorkTaskRecord` and its strict reader in `hooks/it-work-task-command.ts` only after the actual history/readiness DTO is frozen. Schema/readiness absence must remain explicit; it must not become a fabricated empty history or green readiness. Current-record recovery should receive the same canonical projection as the register.

## Smallest reasoned transition UI

Reuse `task.update`, `use-it-work-task-command.ts`, `use-it-work-task-editor.ts` and the existing four-step `ticket-work-task-wizard.tsx`. Add explicit Cancel task and Restore task shortcuts in the register, with the full update wizard as their editor. No new command operation or narrower recovery form is needed.

- Cancel proposes `status=cancelled`; required work stays required unless the person explicitly changes that flag and reviews the consequences. A completed task must first reopen.
- Restore proposes cancelled → pending and requires a reason. It must not restore a prior completed state or skip prerequisites.
- Show the reason field when the approved backend contract requires cancellation/restoration, required → optional, or dependency replacement/removal. Reuse the existing reason field if that is the frozen contract; do not invent additional payload fields.
- Keep the original task as the edit baseline. A shortcut's proposed status must be a dirty working change, not the baseline itself; otherwise dirty-fields-only submission would omit that status. Preserve the original version through stale review, RAM recovery and retry.
- The existing review step must show old/new requirement and dependency sets plus the authorized affected descendants/current blocker consequences supplied by root. Do not predict that changing a prerequisite automatically repairs descendants. Final server validation remains authoritative.

`ticket-work-task-action-dialog.tsx` keeps complete/reopen/reorder transport and recovery. Once the graph DTO is concrete, complete/reopen review can show canonical blockers/affected work without changing receipt identity. `ticket-work-task-recovery.tsx` must display the same fresh verdict while preserving explicit review/adoption and the original unknown command.

## Approval integration boundary

Current `ticket-approval-controls.tsx` uses legacy `router.post`, one mutable reason, immediate success toast and only the latest request summary. It has no typed acknowledgement, uncertain-command recovery or retained reason lifetime. Replace that transport through a thin approval-specific adapter over the canonical endpoints once W01's source/read DTO is frozen; keep the existing approved dialog for one-step decisions, and use a wizard only when actual assignment/lifetime fields warrant it.

W01's current command contract preserves request/decide endpoints and adds a nested decision route. Identity is original actor/ticket/request UUID/version plus exact approval ID for a decision. Receipts identify `approval.request` or `approval.decide` and retain original committed approval status/version on replay. Receipt cancellation fences that UUID; it does not cancel the business approval. Request reason is optional, rejection reason required, maximum 1000 characters. Actual request/decision reasons have distinct recorded markers; old values must not be relabelled as two known historical reasons.

Approval reason recovery needs its own actor/ticket/operation/approval-generation context in the existing bounded RAM inventory, with fresh canonical authorization before disclosure and pending UUID precedence. It must not misuse `task_work`/`ticket_edit` or add another cache/persisted draft purpose. The additive candidate/read proof contract requires root/W01 coordination before implementation. No cover, expiry, reminder or legacy ownership default is chosen here.

## Concrete dependencies before editing

1. W00: exact completion list/detail DTO, source/recorded/actual-time semantics, current pointer and private history gate; source handoff after task focus freeze.
2. Root: graph readiness marker, current effective verdict, safe blockers/corrective links, allowed transitions and version-bound affected descendants for explicit review. Current status alone is insufficient.
3. W01: final approval read/history/capability DTO and candidate authorization seam alongside the supplied command/receipt contract. Keep current public summary and private expansion distinct.

Focused verification after implementation should cover history after reopen/recomplete, invalid transitive prerequisite generations, reasoned cancel/restore and required/dependency repair, rejected/expired approval generations, no-op/replay versus actual change, stale review, unknown receipt recovery, original-author identity, restricted history denial, keyboard/focus and retained drafts. Compiler-enabled register discovery remains part of the gate. Root owns current-assets desktop journeys and full W08/E06/E07 acceptance; the W15 catalogue-generation unlock journey remains a separate cross-package criterion.
