# PKG-07 — Main preflight review

Owner: MAIN ASTRA. Revision: 2. Updated: 2026-09-27.

**Successor update:** T07-01 is independently closed on1d3dae3b19b115b0ff3a18f1e1d4e7805a40a91c, reconciled with published06A. Full repository TypeScript exits0 and46 combined frontend tests pass. [Substantive review](PKG-07-main-technical-review.md) now requests correction of independently reproduced T07-02. No integration approval or Main slot. The original preflight evidence below remains historical.

Status: **Changes requested before substantive approval. No Main integration/publication slot.**

Candidate `09976ffb448ab6149412bbe5a6c0c3977846b6a2` on published79ea01a561f7d2fa5affa592dc096f516d663635, existing owner checkout806c. Main independently matched all69 source-manifest hashes and inspected the packet. This preliminary result does not claim completion of the source/privacy review.

## T07-01 — P2: Full repository TypeScript fails in new candidate tests

Main executed the repository compiler directly from the candidate checkout: `node node_modules/typescript/bin/tsc --noEmit`. It exited1 with four diagnostics in candidate-owned files:

- `resources/js/pages/fleet-assets/geofences/workspace/boundaries.test.ts:131` and`:137`: TS2345, the fixture's widened `timezone: string` does not satisfy the ZoneSchedule literal `"Pacific/Auckland"`.
- `resources/js/pages/fleet-assets/geofences/workspace/operational-map.test.tsx:104` and`:148`: TS2769, `exact` is unsupported by the installed Testing Library `ByRoleOptions`.

The custom `typecheck.mjs` packet selected four entry files, used `types: []` and a custom host, and omitted the repository's ambient/test configuration. Its15 separately reported imported-component diagnostics therefore do not establish that the actual repository compiler failure is inherited. The full repository run reported these four candidate-test errors. Preserve the failed result; correct fixtures/query options without weakening compiler rules, excluding tests or broad ambient declarations, then rerun affected tests and the full repository compiler.

Main already returned one consolidated correction/dependency handoff to the same Astra/xhigh owner. No additional worker or routine coordination loop was created. The owner must reconcile now-published `b05702d7208c88d9cffb9c092df23d3b02300b1e`, preserve06A's room/site/client correction and both route families, and return one exact combined packet. Do not import unapproved06B or local-only03. Wider substantive review, migration/activation decisions and final acceptance remain open.
