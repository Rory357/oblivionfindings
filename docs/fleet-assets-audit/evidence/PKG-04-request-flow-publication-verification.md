# PKG-04 request-flow correction — Main publication verification

Owner: MAIN ASTRA. Revision: 1. Updated: 2026-09-27.

Publication independently verified at `554425e8dddd1b71bca0e81b6d5ddaf9d8f6459f`, tree `44862014b573237e3ba9738e0c95461150785c4f`. Main read local HEAD and GitHub `refs/heads/main` directly after the owner's publication receipt; both match the approved candidate. The sandboxed remote read initially failed to connect; the authorised read-only retry succeeded. The exact delta from published `64995efca` is the seven approved correction files, with no staged changes.

Main independently rehashed all 126 protected dirty/untracked paths against the owner's pre-publication snapshot, all seven files against Main's approved source manifest, and all 1,674 compiled assets against the publication inventory: zero mismatches. The live manifest is `73365F8C322D20F20612BA060E3BB5CA36895A33620AEFB955466A61F2987ED6`, matching the reviewed final graph. Root/public hot files are absent. The canonical Revision 10 master retains SHA256 `C4837AB675F9DFFDB6A8597636F49D5761DA114E6C155DC08E6BB8A209D63FD0`. The owner reports dependency-first/manifest-last copying and retention of old hashed assets; these ordering claims are attributed to the owner, not inferred from final hashes.

The actual-Main test log records 13 passing cases across three files in 2.53 seconds at 20:25:56. This is independently inspected owner-run test evidence; no fresh Main-controlled browser run or additional test execution is claimed. Prior source and rendered acceptance is in [review revision 4](PKG-04-request-flow-main-review.md). Hosted CI has not been independently verified green. No operational migration, external deployment or monitoring activation was included.

The packet is in `C:/Users/steph/.codex/worktrees/1eb2/oblivionfindings/docs/fleet-assets-audit/evidence/PKG-04/implementation/`: `request-wizard-publication-receipt.json`, `request-wizard-main-preservation-before.json`, `request-wizard-main-integration.json`, `request-wizard-main-assets.json` and `request-wizard-main-tests.log`.

Independent preservation verification is complete. The PKG-04 publication slot and Main documentation freeze are released; no subsequent package has received Main-write authority. PKG-06B must reconcile the actually published successor and return its final packet. PKG-05's broader modal correction remains under its existing owner and awaits final evidence; PKG-03 remains local-only. The two final pinned mockup sessions continue design-only and stop at user approval.
