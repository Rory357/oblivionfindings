# W06 draft client and intake evidence

Status: **Implemented and focused-test verified; browser verification pending.** This is a W06 vertical slice, not completion of W06 or the release gate. Production/Herd draft recovery remains disabled unless the canonical effective option explicitly enables it; no retention policy, database migration or provider settings were changed by this client work.

## Implemented contract

- `resources/js/hooks/it-ticket-draft-contract.ts` validates exact purpose, audience, context, generation, metadata, allowlisted fields, attachment routes and recovery URLs. PHP empty-field arrays and Laravel numeric/boolean values normalize into typed client fields. Unexpected private fields are not propagated.
- `use-it-ticket-draft.ts` serializes revision commands, retains the exact frozen save or File/upload UUID on an unknown result, distinguishes permission/session/validation/stale/terminal failures, and only adopts a newer revision after explicit review. Actor/context epochs reject late responses. A file acknowledgement does not mark newer text saved. A consumed acknowledgement can clear only the matching submitted generation and unchanged local snapshot.
- `ticket-draft-recovery.tsx` provides explicit Resume, Review, revision adoption, exact retry, cancel-wait, discard and start-new controls using approved components. Session expiry conceals a previously reviewed server payload. Cleanup failures remain visible, including old-generation cleanup after start-new.
- Pending upload review retains the original File and upload UUID when its canonical row is reserved/failed or absent. Explicit release is required before abandoning those bytes and choosing a replacement. A reviewed matching ready row can confirm the upload.
- Both requester and technician intake use the canonical create command UUID as the immutable draft context. `use-it-intake-draft.ts` provides debounced draft saves, full snapshot normalization, explicit resume, exact saved-generation references before create, and acknowledged save/discard close handling. Technician automatic priority remains omitted from final explicit override input.
- `ticket-intake-draft.tsx` stages files using canonical draft attachment endpoints, exposes their real status and removal, and never copies staged file bytes into the final create request. File selection is one recoverable upload at a time; multiple-file drops are explicitly rejected, not silently truncated.
- `it-intake-draft-locator.ts` stores only actor/purpose-scoped opaque UUID references. Each context has a separate key; simultaneous contexts cannot overwrite one another, and old-tab clears cannot remove a newer context. W02 per-tab pending-command recovery takes precedence over draft lookup. Storage failure is reported, and save-and-close retains its locator before unmount.
- Existing `use-it-ticket-command.ts` now freezes the original `actor_user_id` and requires matching `viewer_user_id` on create/recovery when an actor is supplied. Legacy actor-less consumers remain compatible. The canonical backend remains responsible for current authorization.

## Actual verification

- `w06-draft-client-initial-tests.txt`: 13 hook/contract cases passed, 2.14s.
- `w06-draft-client-controls-tests.txt`: 18 hook/control cases passed, 2.27s.
- `w06-draft-client-foundation-tests.txt`: 46 command/draft/control cases passed, 2.75s; focused ESLint exit 0.
- `w06-intake-draft-initial-tests.txt`: 61 passed and 3 failed. The three older command recovery fixtures omitted the newly required viewer identity. Their matching actor fixture was corrected; the production actor boundary was retained.
- `w06-intake-draft-lifecycle-tests.txt`: 15 cases across actual requester form, command exit and locator tests passed, 5.14s.
- `w06-intake-draft-verified-tests.txt`: 76 cases across seven focused suites passed, 9.55s, process exit 0. This includes pending-upload review/retry, permission/session isolation, true staged File transfer, create acknowledgement, saved draft resume, debounced saves and uncertain-result exit.
- `w06-intake-draft-checkpoint-types.txt`: full repository TypeScript check exit 0 for this checkpoint.
- `w06-intake-draft-both-forms-tests.txt`: requester and technician form cases passed after adding technician dimensional/automatic-priority coverage; exact run totals are in the log.

The UI tests use isolated mocked transport fixtures. They do not constitute browser, database or provider verification. The root owns the separate isolated browser environment and coordinated current-asset build.

## Remaining acceptance and precise resumption

Native Back/Forward can bypass Inertia leave callbacks in the current in-app browser. No claim is made that those browser actions are cancelled. The bounded RAM-only latest-work recovery extension is now integrated and focused-test verified for intake: 20 buffers/64 MiB, no TTL, no eviction and no private browser storage. W00 owns the shared memory helper/adapter; the main hook and both intake hosts continuously provide their latest snapshots. The permission-only local candidate endpoint permits fresh authorization while persisted drafts remain disabled, without inventing draft rows or retention metadata. Fresh canonical candidate authorization checks prior and current selected bindings before a private candidate can be adopted. Pending save/upload intent and original ticket versions remain exact. Real traversal browser journeys are still required.

Next: root finishes the remaining property/Waiting/Routing/raw classification host checkpoint, runs current whole-checkout types/build, then verifies actual desktop journeys in the working persistence-disabled environment and the separate isolated draft-enabled environment. W07 public/internal composer commit integration and whole-package/release review remain outstanding. Do not mark W06 Verified from this evidence alone.

## Native browser recovery checkpoint — 2026-09-09

- `w06-browser-memory-final-tests.txt`: **93 tests across nine focused files passed**, 13.24s, process exit 0. Includes actual requester form unmount/remount with unsaved text and the original `File` object, no metadata initialization while persistence is disabled, explicit authorization/Resume before hydration, definitive create rejection followed by editable recovery, unknown canonical outcome requiring deliberate current-record review, and exact frozen draft save retry preserving newer local text and the original ticket version.
- `w06-browser-memory-final-eslint.txt`: focused ESLint exit 0, no warnings.
- `w06-memory-integrated-types.txt`: whole-checkout TypeScript exit 0 at the integration checkpoint. Root must run it once more after the final other-host and command-settlement additions; this is not a claim about later moving sources.
- `w06-browser-memory-final-types.txt`: final whole-checkout TypeScript exit 0 after the other-host additions and test-only mocked-proof narrowing (session 92999). Application sources remained frozen for Build6.
- Earlier `w06-native-recovery-tests.txt` identified two real gaps: pre-initialization local copies were left alongside initialized persisted copies, and explicit discard followed by command identity reset could re-retain stale form values before unmount. The adapter now performs an atomic owned initialization handoff; intake clears the current form together with its explicitly discarded owned buffer. Both regressions pass in the final group.
- `w06-browser-memory-regression-tests.txt` had 87 passing and four outdated wizard assertions expecting no command marker before explicit exit. Those fixtures now reflect the approved before-send UUID-only marker; confirmed result and definitive validation clearing assertions remain intact.

The original actor-scoped W02 UUID is retained immediately before send. Native Back/remount resumes the unresolved command first, without a blind resubmit. Only a confirmed result, definitive first-attempt 422, access loss or the existing explicit abandonment flow can clear that marker. Session expiry followed by a later 422 does not disprove the earlier command. A monotonically increasing settled token releases only the mounted RAM intent on a definitive result, while rejected text remains recoverable.

Shared recovery now supports `acceptedFields` and an after-authorization `acceptsSnapshot` predicate before adoption. Narrow editors cannot consume an incompatible copy and ignore its fields. Owned-copy clearing preserves independent abandoned work in the same actor/purpose/ticket scope; context-wide clearing is reserved for authorization loss or a proven whole intake context result. A false submission capability remains a visible submission block even when the backend supplies no explanatory blocker.

One explicit mode-compatibility dependency remains: if a direct-file local copy was created with persisted drafts disabled and the effective option later changes to enabled in the same open application, the staged-only intake host rejects that copy **before adoption**, retaining its original bytes. It does not pretend that raw files were uploaded or silently omit them. The ordinary enabled form does not accept direct files before canonical draft metadata exists. A future deliberate mode handoff requires an explicit staged transfer path.

## Requester picker correction

The technician requester control formerly used IT assignee IDs. It now consumes nullable `employeeOptions[].requester = {user_id, site_ids}` projected by canonical `ItTicketIntakeService::requesterOption`; the existing employee profile `id` remains unchanged for provisioning. Only approved staff satisfying current shared Site intake scope become requester options. The UI sends `user_id`, preserves an unavailable previous choice for correction, and never silently switches that choice to the current actor. The final frontend group proves ordinary employee selection, no profile/user ID substitution, and exclusion of out-of-Site/missing-scope options.

Backend behavior is **Verified in isolated tests**: root's serial requester/local/persisted draft group passed **47 tests, 469 assertions, 233.90s**, wrapper exit 0 and postflight confirmed schema `it_454facc536d74784` absent. The earlier requester runs exited without results because a duplicated `requesterOption` declaration caused PHP `E_COMPILE_ERROR` type 64. This was an implementation defect, not evidence of a memory crash or a broken database harness. The duplicate was removed; PHP84 syntax lint passes and a declaration search confirms exactly one method. Root/W01 completed reviewed cleanup of the failed runs; the working database was not reset. The captured first failed run remains `w06-requester-options-tests.txt` for traceability. Browser acceptance is separate from these test results.

## Setup native traversal handoff

Read-only inspection confirms Team/Service `_dialogs.tsx` and Queue `index.tsx` use unkeyed `useForm` plus local modal/step state. Explicit modal close/Escape, validation, stale review and retry are covered while mounted; no native Back/Forward recovery or Inertia remembered form exists. Unmount only aborts the review read. This is a remaining W06 acceptance gap, not covered by the ticket recovery tests above.

Root authorized a subsequent bounded Setup recovery slice after the current ticket source freeze. Proposed context is actor + teams/queues/services + existing record ID or opaque create UUID, retaining the whole editable entity, original configuration hash, step and unknown outcome in RAM only. Fresh canonical Setup authorization and selected-binding validation must precede disclosure; the existing JSON current-review boundary and explicit configuration adoption remain authoritative. Do not add ticket draft purposes, persisted Setup draft records, or private browser storage. Unknown create outcomes cannot be confirmed from a matching name/key alone. Coordinate the typed server proof before implementation.

## Related bounded visible follow-up

Setup Team/Queue/Service EntityCard titles now use the same IT-scoped wrapping override as ticket cards. It addresses the root's actual long-title truncation observation. This source change awaits the next built browser check; it does not change shared CSS or protected design guides.
