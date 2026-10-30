# C2.1/C2.2/C2.4 Oblivion Live: notes for sibling sessions (builder, 23 Oct 2026)

- **Where:** branch `claude/eloquent-shamir-b83b60`, worktree `.claude/worktrees/quirky-haslett-822050`. Merged `claude/chat-module-audit-plan-704fd7` at `ddfc7601b` (the later `016e3e484` is docs only). Nothing pushed.
- **Gateway** committed (`3480f5bda`): `live/` (Node built-ins only), 68 `node --test` tests incl. fuzz. `npm run test:live`.
- **Shared files I touch** (keep edits to your own lines and these merge cleanly):
  - `config/chat.php`: a new `live` section added after `realtime`. The removal commit (spec section 12) deletes the `realtime` section. I don't touch `notifications` or `files`.
  - `ChatServiceHealth`: `realtime()` gains a `live()` branch; the Reverb branch goes in the removal commit. I don't touch `uploads()` or `push()`.
  - `tests/Unit/Chat/ChatServiceHealthTest.php`: only the realtime test changes (in the removal commit).
  - `REGISTER.md` / clean-room `README.md`: only the pusher-js and laravel/reverb rows and the Oblivion Live spec row.
  - Not touched: `ChatBrowserPushTransport`, `WebPushProvider`, `ChatVoiceInspector`, anything under `Voice/`.
- **ChatRealtime API** (if you queue live updates): `queueConversation($id, $onlyUserId = null, $kinds = 'message', $callId = null, $inbox = null)`, plus `inboxChanged()`, `unreadChanged()`, `noticeChanged()`, `revoke()`, `grantCall()`, `revokeCall()`. Old calls with one or two arguments still work.
- **Machine note:** the parent checkout's `node_modules/` (`C:\Users\steph\Herd\oblivionfindings\node_modules`) was empty from about 07:38 on 23 Oct (no install running). Anything relying on upward resolution from a worktree (eslint, tsc, vitest) fails there; `npm ci --no-audit --no-fund` in your own worktree took 55 s. I did not touch the parent.
