# W08 task command and recovery UI checkpoint

9 September 2026. **Implemented and focused-test verified; not yet built or browser verified. W08 remains incomplete.** This is the task-command UI slice against W08/E06/E07 and backlog B06. The root owns release gates, full TypeScript, assets and real desktop browser verification. Protected design sources were not edited; no mobile branch, database migration, provider call or retention activation was introduced by this slice.

## Implemented

- The existing task register uses the canonical create/update/complete/reopen/reorder services. Add/edit now use approved `WizardShell` with Details, Ownership, Prerequisites and explicit Review; bounded completion, reasoned reopen and order changes use existing dialog components. Continue and Submit have distinct keyed native buttons. Field errors navigate to the correct step and focus its field. Actual ACK renders the completion pane; rejection never reports success.
- Every new task command freezes original actor, ticket, nested task/operation, UUID and ticket version. JSON ACK validation checks all of them, changed/no-op semantics, original committed version and response status. Retry uses the exact original body. Current-ticket review and receipt recovery remain separate. A missing receipt never proves the command did not execute; explicit cancellation uses the canonical tombstone endpoint before another command can replace it.
- The existing bounded RAM inventory adds a **memory-only** `task_work` context, scoped to actor/ticket/task/operation. Persisted draft metadata rejects this purpose. Continuous retention includes latest fields, original base, historical team/person/dependency/order selections and any exact pending task command. Explicit Resume requires the canonical nonce-bound task candidate proof before fields are revealed. No private fields are stored in browser storage; only validated actor/context-bound command UUIDs enter session storage.
- Same-context recovery supports multiple unsent/pending copies without treating them as a new task inventory. A matching opaque UUID can recover its original RAM body after an inconclusive receipt GET. An incompatible active command cannot atomically adopt another pending intent. A committed older receipt does not consume a separately resumed unsent proposal; the completion pane explains that the other proposal remains recoverable.
- Stale update review preserves changed fields while adopting fresh unchanged task values only after deliberate review. Adoption sends no mutation. The later explicit PATCH contains only the user's changed fields. Reorder always sends the complete current register; a changed register requires explicit reconciliation and review before submission, including native form submission.
- Pickers use canonical eligible assignees and teams. An unchanged ineligible assignee remains visibly historical; a selected cancelled/unavailable prerequisite remains removable. Completion requires meaningful evidence when required, bounds references and trims submitted references. Existing prerequisites, ownership, current completion evidence and due timestamps remain visible in the register.
- Dirty close can retain work or deliberately discard the current unsent copy; an uncertain command cannot be discarded as if cancelled. Native traversal preserves RAM for authorized Resume. Hard reload/tab close warns while unsent/unknown work remains. Confirmed access loss purges private task work and hides the register; session expiry conceals form fields while retaining recoverable work. Optional `onSessionExpired` delegates to the host's existing canonical refresh, distinct from denial.
- `canViewWork` distinguishes a redacted task array from a real empty register. Current permission loss unmounts an open private task dialog and clears its memory rather than displaying a fabricated zero. The root wires corresponding header/work-navigation visibility.

## Changed frontend files

- New command contract and hook: `resources/js/hooks/it-work-task-command.ts`, `use-it-work-task-command.ts`.
- New task host adapter: `resources/js/hooks/use-it-work-task-editor.ts`.
- Additive shared recovery extension: `resources/js/hooks/it-ticket-draft-contract.ts`, `it-ticket-draft-memory.ts`, `use-it-ticket-draft-memory.ts`.
- Existing register adapter: `resources/js/components/it/ticket-work-tasks.tsx`.
- New approved UI: `resources/js/components/it/ticket-work-task-wizard.tsx`, `ticket-work-task-action-dialog.tsx`, `ticket-work-task-recovery.tsx`.
- Focused tests: `resources/js/hooks/use-it-work-task-command.test.tsx`, `it-work-task-memory.test.tsx`, `resources/js/components/it/__tests__/ticket-work-tasks.test.tsx`. Existing shared memory test files were rerun without weakening their assertions.

Final register props preserve `ticketId`, `tasks`, `canManage`, `assignees`, `teams`; add `actorId: number`, `version: number`, `canViewWork: boolean`, `onCommitted(result)`, optional `onAccessLost()` and `onSessionExpired()`. The root owns the show/header integration. Those refresh callbacks do not imply a successful mutation; only a validated command ACK does.

## Actual verification

Final command:

```text
vitest run resources/js/components/it/__tests__/ticket-work-tasks.test.tsx resources/js/hooks/it-work-task-memory.test.tsx resources/js/hooks/it-ticket-draft-memory.test.ts resources/js/hooks/use-it-ticket-draft-memory.test.tsx resources/js/hooks/use-it-work-task-command.test.tsx --reporter=dot
```

**98 tests passed across 5 files, 7.20 seconds, exit 0**, start 23:16:21 local tool clock. Log: `w08-task-ui-regression-tests.txt`. These are mocked transport/component and real in-process RAM tests, not HTTP/browser evidence. The task component file has 21 cases, including both independent review findings above. The remaining cases exercise strict command identities, denial, cancellation/late responses, original payload retry and existing shared RAM privacy/capacity/lifetime regressions.

Scoped Prettier write and ESLint over the 13 changed/new frontend files completed **exit 0 with no lint warnings/errors**. Logs: `w08-task-ui-format.txt`, `w08-task-ui-eslint.txt`. Actual display baselines/order now use React state; callback refs update in layout effects. No rule was disabled to silence those findings.

W01 independently reviewed the UI/backend field and recovery contracts. Its two confirmed lifecycle findings were fixed and covered by component regressions. Backend verification remains separately recorded in `w08-task-command-foundation-results.md` (35 Feature tests/419 assertions plus 3 standalone tests/131 assertions); this frontend note does not substitute for that evidence.

## Remaining work and precise resumption

Source is frozen after the final focused run. Root next runs full TypeScript and combined UI checks, builds the current source once, then verifies real desktop keyboard create/edit/complete/reopen/order, stale two-editor review, unknown retry/cancel/Resume, restricted/private work, session recovery and current assets against the isolated browser database. No task UI browser acceptance is claimed here.

W08's next canonical slices remain: immutable completion history (reopening currently clears current completion fields), governed dependency/start/reopen consequences, approval assignment/lifetime/recovery/notification intent, approval-task/catalogue links, actionable settlement blockers and the existing personal-work provider integration. This slice does not claim those are complete. W19 scheduling remains a later dependency. Real scan-engine/evidence release policies remain separately unresolved W07 work; the task form accepts existing governed evidence references and does not invent attachment security verdicts.

## Final pre-build corrections and caller regression

The independent close review confirmed a real gap: an older retained copy and a visible capacity warning did not prevent “Keep draft and close” from unmounting the newest unretained proposal. The shared adapter now exposes synchronous `ensureLatestRetained()` returning `retained`, `not_needed`, or a structured blocked reason. Only the exact current generation/signature/File identities, or genuinely unnecessary/already deliberately cleared work, satisfy the close check. Capacity, historical-binding limits, frozen mismatch, incomplete command handoff and invalid scope do not. New pending save/upload/comment/task work or an unknown command cannot use an earlier cleared-snapshot shortcut.

Task wizard/action Keep and success-Done paths now require that exact-current proof before closing. Blocked retention keeps the form and latest values visible; it does not discard the older copy, clear the original command reference or claim that unsent work is saved. Explicit discard retains its separate command-uncertainty rules. Actual 20-entry capacity regressions cover create and reopen, deliberate removal of one older copy, successful latest retention afterward, and beforeunload protection. Historical scope-limit coverage proves the older exact copy survives while the newest unretained proposal remains blocked from a claimed safe close.

Server errors now normalize indexed task fields for inline presentation and focus. Status, prerequisites, required/evidence flags and indexed completion evidence have focus targets and associated error descriptions. Due review uses the canonical `formatDateTime` with an explicit New Zealand display label; input continues to state its actual browser timezone. No permission, deadline policy or underlying timestamp changed.

The root's full TypeScript pass identified two task-memory **test-fixture** issues, now corrected: the intentionally invalid persisted fixture omits local-only `selectedFiles`, and asynchronously assigned recovery data uses a definite-assignment declaration instead of an incorrectly narrowed initialized-null closure variable. Full application TypeScript remains the root's subsequent gate.

Final expanded run: **174 tests passed / 10 files / 10.67 seconds / exit 0**, start 23:41:38 local tool clock. Log: `w08-task-close-regression-tests.txt`. Selected files were the five earlier task/shared-memory files plus `ticket-work-task-review.test.tsx` (W00's current-value projection tests), `use-it-ticket-draft.test.tsx`, `use-it-ticket-draft-browser.test.tsx`, `resolve-ticket-dialog.test.tsx` and `ticket-intake-draft.test.tsx`. This includes 28 task register/dialog component cases and 9 task-specific RAM cases. The intake disabled-draft selected-File case emitted existing React warnings about an `id` prop on `React.Fragment`; all assertions passed and that caller was not changed in this correction. The warning was reported to the root rather than omitted from evidence.

Final seven-file changed-source/test Prettier completed exit 0, and **ESLint `--max-warnings=0` completed exit 0 with no warnings/errors**. Logs: `w08-task-close-format.txt`, `w08-task-close-eslint.txt`. W00 separately verified its recovery projection and test file. Source is frozen for the root's combined TypeScript, asset build and real desktop journeys; no browser claim is added by this note.
