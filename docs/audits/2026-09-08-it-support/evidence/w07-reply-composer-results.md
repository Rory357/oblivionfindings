# W07 reply composer integration

9 September 2026. Implemented source, not yet verified in a current browser build. Extends canonical ticket comments, protected files, command receipts, draft generations and the existing public/internal interaction service. No provider call, deployment or production draft-policy activation.

Root-owned source: `ticket-reply-composer.tsx`, `ticket-thread.tsx`, `ticket-version-conflict.tsx`, `ticket-drawer.tsx`, `use-it-ticket-peek.ts`, `pages/it/tickets/show.tsx` and focused UI tests. Separate public/internal composer instances keep text/files/versions and command identities independent. The show/drawer hosts pass current actor, ticket version, schema readiness and effective draft flag. The new composer remains mounted across Conversation/Activity navigation; prior assets/schema stay compatible. Speaker labels use captured provenance, with unknown history explicitly unrecorded.

Submission freezes the original body, files, audience, ticket version and optional exact draft reference. Controls preserve uncertain input, recover the original receipt and expose explicit server-serialized cancellation; they never infer failure from404. Committed messages and email delivery states have distinct copy. A second reply starts only after the confirmed matching first reply has been handled. Fresh ticket/version review uses comment permission and additionally internal-work permission for notes. Source also guards account/ticket changes, session recovery, confirmed access loss across both audience panels and exact RAM handoff with newer local text preserved.

Actual root test checkpoints:

- Initial group4files:26tests passed,4.81s,exit0 (`w07-reply-composer-ui-initial.txt`). Eight composer cases plus existing thread/version/peek coverage.
- Expanded group6files:54tests passed,8.40s,exit0 (`w07-reply-composer-ui-second.txt`). Added explicit cancellation confirmation, requester version review without manage permission, denied internal review, drawer and ticket-page integration coverage.
- Root explicit-file ESLint passed with zero warnings after the latest source edits. Further independent review found and prompted scope-key, cross-audience revocation, session-recovery and recovered-newer-text changes. The above test counts predate those final review changes; expanded final tests are still running and must replace this checkpoint before a build claim.
- Root first full TypeScript check found test fixture typing issues (Testing Library exact option, newly required canonical ticket id, shared draft mock method and readonly fixture). Corrected by owners; W00's subsequent full checkpoint passed. Coordinated current-source TypeScript remains a final gate.

Final source verification, 9 September 2026, 19:51 NZST:

- Shared command/draft/composer regression: **158 passed across 8 files, 5.23s, exit0**, including 20 composer cases (`w07-composer-and-command-final-tests.txt`). This supersedes the earlier review checkpoint. It covers independently scoped public/internal work, sticky permission denial, exact command recovery/cancellation, retained newer text and original selected files, and consumed-draft recovery through explicit Start New.
- Root host integration: **46 passed across 5 files, 6.81s, exit0** (`w07-composer-integration-final-tests.txt`): thread, version review, peek, drawer and full ticket page. The final added regression removes already-loaded internal comments and their file links when internal-work permission disappears in place. The same presentation boundary suppresses internal knowledge suggestions.
- Coordinated full-repository `tsc --noEmit --pretty false`: **exit0** (`w07-coordinated-types-final.txt`). Explicit-file ESLint: **exit0, zero warnings** for the final root and shared sources.
- Build7 started after the source freeze. Compilation and browser acceptance are not claimed by these tests.

Related evidence: `w07-comment-command-client-results.md` records the shared command/RAM results; `w07-comment-command-contract.md` records backend source and actual PHP outcomes. `w06-isolated-resolution-browser.md` is Build6 resolution-draft evidence, not proof of this newer composer.

Next: finish Build7, create a fresh isolated schema including finalized000008/000009, then perform actual browser public/note/file/retry/cancel/second-reply, role/session and desktop keyboard journeys. Watcher management, scanner/quarantine/outage handling and remaining W07 lifecycle acceptance are still open. Full W00–W27/E01–E23/release gate remains active.
