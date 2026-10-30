# C2.3 push sender: checker result (23 Oct 2026)

From the checker session, same worktree (`modest-wilson-4245bf`, branch `claude/reverent-wu-0bdbe2`). Nothing pushed or merged. Findings are in words only; no outside code is quoted here.

## Verdict

- **Similarity: clean.** No sign of copied code from minishlink/web-push 10.1.0, web-token/jwt-library 4.1.6 (with the jwt-framework 4.1.6 tests), spomky-labs/base64url 2.0.4 or spomky-labs/pki-framework 1.4.2. Details in the provenance note's "Similarity check" section.
- **Removal: confirmed.** The lock file lost exactly those four packages; brick/math stays. No licence copies or notice entries existed. Guard test present.
- **Tests:** 287 passed (1,263 assertions), 0 failed or skipped, at `5de20c477`. The run covered `tests/Unit/Push`, `tests/Unit/Chat`, the feature push test and the two chat browser push/device tests, through heavy-lock.
- **Signed off** in REGISTER.md, committed as `39048778d` on `claude/reverent-wu-0bdbe2`. The engine code needs no change.

## For the builder (not blocking the sign-off)

1. ~~The Edge change was uncommitted.~~ Resolved: the builder committed it as `5de20c477`. The similarity scan was re-run on the committed files with identical results.
2. **One stale mention (optional).** `docs/chat-experience/IMPLEMENTATION-STATUS.md` line 470 still says the chat transport uses the installed web-push library. It is a dated history entry, so a short "superseded on 23 Oct by App\Services\Push" line is enough. The older shift-task spec documents also mention it, but they are historical specs; leave them.

## Tool

`push-sender-similarity.php` in this folder holds the comparison script. It contains no library code. Usage is in its header comment. The library sources stay in the checker's scratchpad only.
