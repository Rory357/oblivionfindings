# W08 task action focus return

Source checkpoint, 10 September 2026. Root's frozen Build10 browser established that opening task B's Reopen action by click or keyboard, validating an empty reason and pressing Escape left focus on HEADER, with zero mutation and no remaining dialog. The following source changes are unbuilt and are not part of that browser acceptance.

The installed React DOM `commitMount` applies textarea `autoFocus` before Radix FocusScope's mount effect. FocusScope skips its mount autofocus event when the focused element is already inside the dialog, so shared `DialogContent` never captures the outside opener. The two native `autoFocus` props were removed from `resources/js/components/it/ticket-work-task-action-dialog.tsx`. Existing Radix first-field focus now follows opener capture. Shared Dialog/WizardShell and all PHP/migrations are unchanged.

New regression file: `resources/js/components/it/__tests__/ticket-work-task-focus.test.tsx`, using the actual task host and mocked HTTP. It covers blank-reason validation then Escape, Reopen/Complete footer Close, nested keep/discard confirmation, removed permission trigger, and actor/ticket replacement.

Intermediate results, retained for provenance:

- Before removal: **6 failed**, including the exact returned-HEADER defect. Log `w08-task-action-focus-before.txt`.
- After removal: **5 passed, 1 failed, 4.67 s**. Opener/permission/scope cases pass. Log `w08-task-action-focus-after.txt`.
- The remaining failed nested confirmation case was isolated: cancelling the Keep-work confirmation left settled focus on **BODY** while the main dialog/text remained. Log `w08-task-action-focus-nested-diagnosis.txt`. The installed Radix modal close handler prevents default then focuses its trigger ref; ConfirmDialog has no Trigger. At this intermediate checkpoint, the task-scoped optional close-focus callback had been proposed but not implemented.
- Scoped ESLint with `--max-warnings=0`: **exit 0**, log `w08-task-action-focus-eslint.txt`.

Root subsequently authorized the optional `ConfirmDialog.onCloseAutoFocus` passthrough. Existing callers retain their default behavior. The task action dialog captures only an element inside its own dialog before opening confirmation and restores it through the existing `restoreOverlayFocus` helper. A layout-effect guard invalidates restoration after actor/ticket/action changes, unmount or access concealment. Removed, hidden or disabled targets fall back to the still-authorized dialog; a fieldset-disabled control is explicitly rejected. The main shared Dialog still owns restoration when the action dialog itself closes.

Final source/test files:

- `resources/js/components/confirm-dialog.tsx`: optional typed callback only.
- `resources/js/components/it/ticket-work-task-action-dialog.tsx`: two native autofocus removals and scoped confirmation restoration.
- `resources/js/components/it/__tests__/ticket-work-task-focus.test.tsx`: nine actual-host cases with mocked HTTP, including nested Cancel, disabled-fieldset fallback and actor/access changes during confirmation.
- `resources/js/components/__tests__/confirm-dialog-focus.test.tsx`: three callback/default-behavior cases.

Final verification:

- Those two new test files plus unchanged `resources/js/test/dialog-focus-return.test.tsx`: **17 passed / 3 files / 4.42 s**, log [w08-task-action-focus-final.txt](w08-task-action-focus-final.txt). All cases ran; none were skipped.
- Scoped ESLint on all four owned source/test files, `--max-warnings=0`: **exit 0, zero warnings**, log [w08-task-action-focus-eslint-final.txt](w08-task-action-focus-eslint-final.txt).
- Final source SHA256 snapshot: [w08-task-action-focus-source-hashes.json](w08-task-action-focus-source-hashes.json).

Tests invoke the actual click handler after focusing its opener; the original Enter/click reproduction is root's real Build10 browser evidence. This source fix remains **unbuilt and browser verification pending**. No database/provider calls, PHP tests, whole-tree types or build ran in this slice. These focused results do not mark W08 complete.
