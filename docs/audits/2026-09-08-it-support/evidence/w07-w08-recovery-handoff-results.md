# W07/W08 — reply handoff and approval-history keyboard repairs

10 September 2026. Follow-ups confirmed by Build28; W07/W08 and E05–E07 remain in progress.

## Implemented

- `use-it-ticket-draft-memory.ts`: a successful save can be batched directly into a pending comment render. The old frozen save previously changed storage generation without being settled, leaving an abandoned “earlier result unconfirmed” copy after a successful comment. Settle only the owned save with matching scope, generation, acknowledged next revision, saved/current snapshot and next comment draft reference. Keep mismatched revisions, newer text, selected files and genuinely uncertain commands.
- `ticket-approval-history.tsx`: keep the load and Older requests controls mounted while loading, after failures and on the final page. Guard their unavailable actions with `aria-disabled`; label the final page explicitly. Removing a focused Cancel control returns focus to the primary history control; it does not move focus from another control.
- `ticket-approval-record.tsx`: historical rejected/expired/cancelled records explain their preserved evidence without instructing read-only viewers to request another approval.
- Regression files: `ticket-reply-draft-integration.test.tsx` uses the real composer, draft, command and RAM hooks with transport-only synthetic responses; `use-it-ticket-draft-memory.test.tsx` covers matched and nonmatching handoffs; `ticket-approval-history.test.tsx` covers final page, failure, cancellation and focus moved elsewhere.

## Actual test evidence

- Original regression handle10840 is terminal/missing. Its log has one failure at starting a new draft: the synthetic response incorrectly reused the old UUID. Corrected the fixture to match the canonical server generation change before evaluating production behavior.
- `w07-reply-draft-integration-red-2.txt`: one failed test, exit1. Adding a pending network interval reproduces the actual missing next-note action. `w07-reply-draft-timing-diagnostic.txt` traces only synthetic recovery metadata: the acknowledged save is released without discard as the pending comment takes ownership. Temporary diagnostic logging was removed.
- `w07-reply-draft-integration-green.txt`: one test passed, exit0 after the production fix, including two distinct notes and request UUIDs.
- `w07-save-comment-handoff-regression.txt`: 110 tests / 6 files passed, exit0, 4.71 seconds. Includes draft storage, real browser recovery hooks, comment command and integration coverage.
- `w08-history-focus-regression.txt`: 23 tests / 3 files passed, exit0, 5.88 seconds, including approval controls and the canonical history reader.
- First TypeScript run50459 failed on four unsupported `exact` options in the new test queries. Corrected those query options; final run pending below. No product type errors reported by that run.
- Final scoped six-file ESLint passed, exit0. Scoped diff whitespace check passed; protected DESIGN.md/design_styles diff remains empty.
- Exact six-file source hashes: `w07-w08-recovery-source-hashes.json`.

## Browser follow-up completed

- Final TypeScript25202 passed, exit0. Build29 94707 passed, exit0/4m58s.
- Actual restricted-technician first note → explicit blank focused next-note editor → second note passed. Requester privacy and final-page Older requests focus passed at unchanged1235×856. Eight persisted reconciliation checks true. Exact report: `w07-w08-browser-build29-results.md`.
- Cleanup51326/postflight exit0; exact tokena1cbdd192f9a4df5 schema/directory absent. No active build/bootstrap/runtime remains.
- Next extend the real composer regression to selected-file handoff before accepting that interaction: the conservative workingFiles guard preserves an earlier save as well as the pending comment. Preserve File identity and newer work while settling only a proven completed save. Then resume W09 canonical merge preview/lifecycle and relationships. Whole-plan release gates remain open.
