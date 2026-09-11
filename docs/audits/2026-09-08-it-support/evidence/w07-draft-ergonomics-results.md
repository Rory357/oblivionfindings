# W06/W07 draft control follow-up

Source implemented and focused tests passed on 9 September 2026. Browser acceptance awaits the next coordinated build. The existing Build7 assets are unchanged by this slice; neither W06 nor W07 is complete.

## Confirmed browser defects and changes

Root's Build7 browser journey found an untouched ticket displaying a generic saved-draft panel with an unsaved-changes claim and enabled Save/Discard. The untouched composer did the same. The shared control used `isSaved=false` as evidence of editing, although a newly initialized empty generation has no acknowledged snapshot. It now hides the ready/pristine panel only when canonical metadata confirms no saved content/files and there are no local changes, prior acknowledged snapshot, error, blocker, review or browser recovery notice. Save remains available when previously saved text is cleared back to its initial empty value. Discard is offered for actual saved content or files, rather than an empty initialized row. Recovery and failure states remain visible.

After an ordinary matching consumed-draft acknowledgement, the composer previously required Check saved draft and then Start a new draft before another message. The missing capability metadata was intentional: the commit acknowledgement is not permission to rotate a generation. The composer now offers one explicit **Write another reply** or **Add another internal note** action. It checks canonical metadata, requires the exact consumed UUID/revision and original purpose/audience/ticket context with current start-new capability and no blocker, then starts the new generation and focuses the existing textarea. It does not submit a message.

Only an exact locally matched commit, with its work cleared and no newer text/files, uncertain command, memory recovery blocker or concealment, gets this shortcut. Held/unmatched acknowledgements and newer proposals retain their existing explicit recovery. Changed, active, blocked or inaccessible generations are never rotated by the shortcut. Late checks after unmount/session/access loss do not start a generation. Starting a new generation still uses the canonical hook's strict response validation, cancellation, conflict and exact retry lifecycle.

## Changed files

- [Shared draft controls](../../../../resources/js/components/it/ticket-draft-recovery.tsx)
- [Existing reply composer](../../../../resources/js/components/it/ticket-reply-composer.tsx)
- [Shared control tests](../../../../resources/js/components/it/__tests__/ticket-draft-recovery.test.tsx)
- [Composer lifecycle tests](../../../../resources/js/components/it/__tests__/ticket-reply-composer.test.tsx)

No PHP, migrations, runtime configuration, provider calls, communications or build assets changed. Protected design sources were not edited. The UI-pattern skill and approved button guide were applied; desktop-only scope is unchanged.

## Actual checks

- [Final affected regression group](w07-draft-ergonomics-tests-final.txt): **116 passed / 6 files / 7.22s**, exit0, at 20:11 NZ. Files: shared control and composer tests above, `ticket-property-mutation.test.tsx`, `use-it-ticket-draft.test.tsx`, `use-it-ticket-draft-browser.test.tsx`, `use-it-ticket-comment-command.test.tsx`.
- [Scoped ESLint](w07-draft-ergonomics-lint.txt): exit0, zero warnings, all four edited source/test files.
- Scoped Prettier completed; the only formatting after the final test run was the newly added second-message assertions. No behavioral edit followed the passing run.
- The [initial attempt](w07-draft-ergonomics-tests.txt) did not start tests because sandboxed esbuild could not resolve the repository config. The authorized installed-runtime retry then reported [43 passed / 1 failed](w07-draft-ergonomics-tests-retry.txt): the new internal-audience test incorrectly queried a tab instead of the existing button. Its locator was corrected; the final group above is passing. These earlier attempts are not represented as passes.

Full TypeScript is left for root's coordinated checkpoint. Real browser proof must use the next verified asset build, covering pristine page/composer, saved text cleared to empty, one-action second reply/note, and failure/recovery. This report does not claim those new browser checks have run.
