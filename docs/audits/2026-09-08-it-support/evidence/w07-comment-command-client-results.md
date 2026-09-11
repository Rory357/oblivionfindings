# W07 reply command client and W06 RAM handoff

2026-09-09 source implementation checkpoint. This is focused client verification, not complete W07 or browser/provider acceptance. The parent task owns composer integration and its browser build.

## Implemented contract

- The transport freezes the original actor, ticket, audience, body, original ticket version, exact File objects, optional saved-draft reference and secure request UUID before submission. Session storage contains only opaque UUID references in an actor/ticket/audience key. It contains no message, filename, file, credential, or draft payload.
- Only exact HTTP 201 first-commit or HTTP 200 replay acknowledgement can confirm a reply. The DTO requires original ticket ID, positive current canonical ticket ID, original request UUID/audience, current viewer actor, positive comment ID, strictly advanced original committed version, and validated delivery/draft metadata. A governed merge changes only the returned canonical location; it never creates a new submission identity.
- Timeout, aborted wait, malformed/partial acknowledgement, generic recovery 404 and server failure retain the original command. Stop waiting aborts only HTTP waiting and suppresses late callbacks. It never asserts server rollback. Session/access concealment remains sticky until fresh canonical access or a strict result is proved. Confirmed access denial purges the command payload and calls the host purge boundary. The host must purge its own audience form/RAM too.
- A first known 422/stale-version 409 requires explicit current-ticket review before releasing its reference. An idempotency-conflict 409 cannot be released as a different submission. `checkCurrentAccess()` returns boolean, exposes `reviewedVersion` and `accessBlocker`, and never replaces the original frozen version. `releaseKnownRejection(version)` requires that exact fresh reviewed version. Same-UUID listed recovery preserves the original frozen identity.
- Explicit `cancelReference(uuid?)` serializes against the canonical server command. Only a strict HTTP 200 cancelled receipt releases its opaque marker; a winning commit is acknowledged as committed. A cancellation retains the saved draft and requires fresh review before another intent. Recovery and a delayed original POST may also return the same cancelled receipt. Cancellation failure/stop preserves uncertainty.
- A host callback failure cannot turn a confirmed commit into an uncertain write. `prepareNext()` is explicit and available only after commit; parent UI acknowledges/clears only matching work first.

## RAM handoff

`workingPendingComment` on `useItTicketDraft` retains the immutable reply alongside the latest host snapshot. A pending canonical reply uses the permission-only local candidate endpoint even when saved drafts are enabled: its original saved draft may already have been consumed. The original draft reference remains inside the frozen command, without fabricating active draft metadata.

Metadata-only notices expose `hasPendingComment`, never content. Existing 20-buffer/64-MiB ceilings apply; shared original File references are counted once. Unknown command intent cannot change while newer unsent host text is retained separately. Actor/ticket/audience mismatches fail closed.

`resumeMemory()` returns `pendingComment` only after nonce-bound current canonical authorization. The adapter synchronously retains a handoff copy until the separate reply client accepts matching `workingPendingComment`. Failure of that second access check followed by native Back therefore retains the original copy. Explicit current-form clearing removes only its owned or just-authorized handoff copy. A definitive rejection releases its frozen local copy before transitioning back to an active saved-draft generation.

`registerRecoveredSubmission(snapshot, reference)` accepts only the original body/version and exact saved-draft reference from fresh-authorized recovered pending intent. `acknowledgeConsumed()` still refuses to clear newer text or mismatched revisions. Parent integration must distinguish the original submitted snapshot from the latest restored text.

## Actual verification

- Seven focused Vitest files: **135 passed, 3.08 seconds, exit 0**, 19:28 NZ. Mocked HTTP only; no database, provider, production fixture, or real communication. Log: `w07-comment-command-client-final-tests.txt`.
- Changed source/test ESLint: **exit 0, zero warnings**. Log: `w07-comment-command-client-lint-final.txt`.
- Full TypeScript check at the prior structural checkpoint: **exit 0**. Log: `w07-comment-command-client-types.txt`. Subsequent changes add same-UUID recovery support, reorder a definitive local-buffer release, and add their passing tests; coordinated final parent TypeScript/build remains appropriate.
- Coverage includes strict/partial/wrong-actor/audience/merge DTOs; explicit cancelled/write-won races; late callbacks after stopping wait; sticky session/recovery404 concealment; immutable exact retry; idempotency conflicts; callback errors/second replies; original File bytes; fresh nonce RAM adoption; failed handoff plus unmount/recovery; draft-reference mismatch/newer-text preservation; definitive-rejection generation transition.

## Files

- `resources/js/hooks/it-ticket-comment-contract.ts` and `.test.ts`
- `resources/js/hooks/use-it-ticket-comment-command.ts` and `.test.tsx`
- `resources/js/hooks/it-ticket-draft-memory.ts` and `.test.ts`
- `resources/js/hooks/use-it-ticket-draft-memory.ts` and `.test.tsx`
- `resources/js/hooks/use-it-ticket-draft.ts`
- `resources/js/hooks/use-it-ticket-draft-browser.test.tsx`
- `resources/js/components/it/__tests__/ticket-draft-recovery.test.tsx` (new method in typed test mock only)

## Remaining acceptance

Parent composer fixes and integration tests, fresh asset build, real browser reply/notes/cancellation/session/audience journeys and W07 backend test results are separate evidence. Scanner/attachment operational policy and actual provider acceptance are not established by these tests. The isolated browser continues serving its reviewed Build6 assets; these client changes have not been built into that environment.

The one read-only database-thread check at 19:23 NZ found no active lock wait: only the diagnostic query at 0 seconds and one isolated test connection sleeping 0 seconds. It does not explain the earlier 30-second browser request timeout. No timeout/configuration change was made. `w07-owned-browser-db-threads.php` and `.json` contain only safe exact-owned schema/process metadata, with no SQL text or private payloads.

## Independent composer review follow-up

At 19:39 NZ, **71 tests passed across three files, 4.41 seconds, exit 0**: 18 composer lifecycle cases and 53 reply contract/transport cases. Log: `w07-composer-independent-final-tests.txt`. Focused ESLint passed with zero warnings (`w07-composer-independent-final-lint.txt`). Only `ticket-reply-composer.test.tsx` was changed by this reviewer in the composer; the parent owns its implementation.

The composer tests exercise actor/ticket remount boundaries, separate public/internal text, generic403 concealment/purge across both audiences, strict cancellation confirmation, original frozen body/base registration separately from newer restored text/files, preserved newer work plus explicit current-version review, failed second RAM proof/retry, saved-draft419 recovery outside the concealed form, and current-ticket-review419 recovery without a command UUID. A cancellation which discovers a committed reply cannot bypass a mismatched saved-draft acknowledgement hold.

The reviewed cross-audience fix required `denyCurrentAccess()` on the reply transport. It invalidates/aborts pending callbacks, clears private frozen intent, keeps only projected receipt identity and opaque journal references, and does not recursively call the host denial callback. Receipt identity is explicitly projected so structural TypeScript assignment cannot accidentally retain body/File properties. Fresh permission checking for an empty denied composer uses an ephemeral nonce-bound candidate and returns to editing without creating a submission identity or journal marker. Two focused hook cases verify both pending-command and empty-composer denial.

At that checkpoint the remaining observation was the held acknowledgement recovery action. The subsequent source/test checkpoint below closes its focused client lifecycle; no current browser/build verification of the new composer is implied.

## Final focused client checkpoint, 19:47 NZ

**158 tests passed across eight files, 5.23 seconds, exit 0.** Log: `w07-composer-and-command-final-tests.txt`. This includes the seven shared reply/draft files above and the independently extended `resources/js/components/it/__tests__/ticket-reply-composer.test.tsx` with **20 composer cases**. Final scoped ESLint passed with zero warnings (`w07-composer-independent-final-lint.txt`). Parent owns the coordinated full TypeScript/build and browser acceptance.

The strict committed-but-unmatched-draft hold now requires explicit current-ticket review and deliberate keep-entered-work action. When persisted drafts are enabled, a strict current metadata check may prove either an active readable draft or a terminal consumed/discarded/expired draft with `start_new` capability. The test models consumed metadata as terminal (read false): entered body/files remain intact and Add Reply stays blocked until explicit Start New succeeds. Cancellation which loses to an already committed reply leaves this hold intact; only a rendered strict cancelled receipt triggers cancellation-specific cleanup.

The host also compares original submitted direct File objects, length and order before clearing. Newer files with unchanged body/base remain local. The backwards-compatible third argument `acknowledgeConsumed(value, snapshot, { preserveLocal: true })` validates the original consumed receipt but skips local buffer acknowledgement/clearing and returns false. A real shared-hook/RAM test verifies that, after unmount and fresh canonical recovery, the newer File object and unchanged text remain available without the completed pending command. No second storage system, browser payload persistence, notification, provider call or policy change was introduced.
