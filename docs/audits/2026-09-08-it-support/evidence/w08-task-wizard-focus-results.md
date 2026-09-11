# W08 task wizard focus return

10 September 2026, source verification only. Root's frozen Build10 desktop browser found that untouched Edit task, with Task title focused, closed on Escape but left focus on HEADER. No task mutation occurred. These changes remain unbuilt and need the coordinated Build11 browser retest.

The task title used native `autoFocus`, which runs before Radix's mount autofocus event. Shared Dialog therefore missed its external-opener capture. WizardShell now accepts an optional typed `onOpenAutoFocus`, passed to DialogContent, whose existing handler captures the opener before invoking the caller. The task wizard removes native autofocus and focuses the first enabled control in its body: normally Task title, or an explicit recovery action when retained work exists. Other WizardShell consumers keep their previous behavior.

Task keep/discard confirmation uses the existing optional ConfirmDialog close-focus callback and shared restoration helper. Its target is bound to the current actor/ticket/task and authorized dialog. Nested Cancel preserves entered work and returns to the captured control; removed/fieldset-disabled targets fall back to that authorized dialog. Unmount, permission loss or actor/ticket replacement invalidates private focus restoration. Root's task success wording and all task command behavior are preserved.

Changed files:

- `resources/js/components/it/ticket-work-task-wizard.tsx`
- `resources/js/components/wizard/shell.tsx`
- `resources/js/components/it/__tests__/ticket-work-task-wizard-focus.test.tsx` (new)
- `resources/js/components/wizard/shell-focus.test.tsx` (new)
- `resources/js/components/wizard/shell.test.tsx` (test teardown only, plus approved Button/Input harness controls)

Verification provenance:

- Initial actual-host suite: 8 failed, 10.74s (`w08-task-wizard-focus-before.txt`). It reproduced the returned HEADER defect; one expected validation string was corrected to the actual existing `Enter a task title.` copy afterward.
- First combined run: 28 passed/5 failed, 7.87s (`w08-task-wizard-focus-after.txt`). Tests exposed deferred Radix unmount callbacks crossing test boundaries and an incorrect test assumption about the default rail's accessibility. New tests now settle deferred cleanup and assert existing default containment rather than inventing a rail focus contract.
- Existing shell suite alone: 5 passed/1 failed (`w08-task-wizard-shell-existing-recheck.txt`); its unchanged focus-trap case alone passed (`w08-task-wizard-shell-trap-isolated.txt`, other five filtered). The final existing suite awaits the same explicit teardown tick; assertions remain intact.
- Final combined task wizard, optional shell seam, existing shell, task action, ConfirmDialog and shared dialog focus: **33 passed / 6 files / 5.09s**, no skipped cases, [w08-task-wizard-focus-final.txt](w08-task-wizard-focus-final.txt).
- ESLint on all five changed files, `--max-warnings=0`: **exit0, zero warnings**, [w08-task-wizard-focus-eslint-final.txt](w08-task-wizard-focus-eslint-final.txt).

Tests use the actual task host and mocked HTTP; Add/Edit opener focus is set before its click handler. Root owns actual desktop keyboard/browser acceptance. No PHP, database, provider, migration, browser helper, build or whole-tree type check ran for this fix. Protected design files remain unchanged. W08 as a whole is still in progress.
