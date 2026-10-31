# Oblivion Live provenance

- Replaces: laravel/reverb 1.12.0 (with pusher/pusher-php-server 7.3.0, ratchet/rfc6455, react/event-loop, socket, stream, promise, promise-timer, dns, cache, evenement/evenement, clue/redis-protocol, clue/redis-react) and pusher-js 8.6.0 (with tweetnacl and the bundled @stablelib parts). Also covers the Oblivion signer (C2.4), which takes over Chat's ticket signing; Chat's remaining firebase/php-jwt use goes with LiveKit in stage 5.
- Spec: [../specs/oblivion-live.md](../specs/oblivion-live.md), written by the Chat rebuild planning session (branch `claude/chat-module-audit-plan-704fd7`), 23 October 2026
- Built by: builder session on branch `claude/eloquent-shamir-b83b60` (worktree `quirky-haslett-822050`), 23 October 2026
- Sources read while building:
  - RFC 6455 (The WebSocket Protocol): opening handshake (4.1, 4.2, 4.4), base framing (5.1 to 5.8), closing handshake (7.1 to 7.4), status codes (7.4.1) and the IANA close-code registry for 1012 to 1014.
  - RFC 2104 (HMAC) and FIPS 180-4 by name only (SHA-1 for the handshake accept value, SHA-256 for HMAC), both through `node:crypto`.
  - RFC 4648 section 5 (base64url without padding).
  - RFC 3629 (UTF-8) for the validity rules the tests check: overlong forms, surrogate halves, values above U+10FFFF, cut-short sequences.
  - Node.js 22 manual: `node:http` (createServer, the `upgrade` event, request timeouts), `node:net` sockets, `node:crypto` (createHash, createHmac, timingSafeEqual, randomBytes), `node:buffer` (`isUtf8`, base64url encoding), `node:timers`, `node:test` and `node:assert`.
  - PHP manual: `hash_hmac`, `hash_equals`, `random_bytes`, `json_encode` flags. Laravel documentation: HTTP client, queued jobs, `DB::afterCommit`, rate limiting.
  - Oblivion's own code: `ChatRealtime`, `ChatRealtimeController`, `WakeChatClients`, `ChatMessageChanges`, `ChatServiceHealth`, `AddContentSecurityPolicy`, the chat components in `resources/js/components/chat/` (including `chat-realtime-connection.ts`, which is Oblivion's own wrapper), Oblivion's tests and `deploy/chat-calling/` documents.
- Outside code consulted: none. No file in `vendor/laravel/reverb`, `vendor/pusher`, `vendor/ratchet`, `vendor/react`, `node_modules/pusher-js`, `node_modules/laravel-echo` or any other WebSocket library was opened, searched or fetched, and no WebSocket library documentation or example was read. Every search excluded `vendor/` and `node_modules/`.
  - Incident, 23 Oct: a repository-wide search for references to the deploy files (patterns `chat-realtime.env`, `REVERB`, …) printed nine lines of `config/reverb.php`, the config file that followed Reverb's template: its `env('CHAT_REVERB_…')` lookups for bind host, port, key, secret, app ID, allowed origins and maximum connections. Nothing from them was used: the gateway's settings come from the spec's section 10 list, and the file is deleted in the removal commit without being opened.
  - The worktree's `node_modules/` was installed from the existing lockfile (`npm ci`) because the parent checkout's copy was empty; that includes pusher-js until the removal commit. It was never opened or searched.
- New outside packages: none. The gateway uses only Node's built-in modules; the browser client uses the browser's own `WebSocket`; the PHP side uses Laravel's existing HTTP client.
- Similarity check: to be run by the checker session.

## Evidence so far (23 Oct 2026)

- Gateway: 68 `node --test` tests pass (handshake, masking, every length form, fragments, invalid UTF-8, oversize, rate limit, ping timeout, tickets, call grants, revoke, internal API, presence) including a seeded frame fuzz test (3,000 reader rounds plus 4,000 random inputs on 200 live connections; repeated with three seeds).
- Signer: the PHP `CompactToken` and the gateway accept the same fixed token byte for byte (vector in both test suites).
- Capacity: 5,000 idle signed-in connections on one node used 97 MB resident memory, opened in 4.5 s (`node live/tools/idle-connections.mjs 5000`).
- Interop without fakes: a ticket from the PHP signer opened a real gateway connection; `OblivionLive::publish()` reached it in about 33 ms on loopback (three runs, 32–35 ms, from just before the call to receipt); `health()` read the node; `revoke()` closed it with 4403.
- Browser: Vitest connection, sync-loop, typing and row tests, and all 596 existing chat tests.

## Re-run after the fixture fix (31 Oct 2026, Rory's builder session)

On this branch with the Chat base and main `0a66dafe2` merged in (`8720d117e`). No code changed.

- `OblivionLiveTest`, `ChatRealtimeActorTest` and `ChatRealtimeCspTest`: 22 passed. The 1 failure needs the Pusher package that this PC's `vendor` folder is missing (`Class "Pusher\Pusher" not found`); it is the same on the base branch.
- Gateway: all 68 `npm run test:live` tests pass.
- Vitest: the `live/` connection and sync tests, the sound-session and update-watch tests, 57 passed. `chat-calling.test.tsx` can't load here until `npm ci` installs `livekit-client`.
- Still to do: the production build, the two-browser end-to-end test, the removal draft (branch `claude/oblivion-live-removal-wip`), and the checker session.

## Decisions taken while building

- **Rotation key ID.** The spec lists `previous_secret` but no key ID for it. Tickets name their key with `kid`, so the gateway needs to know the old key's ID during a rotation: added `LIVE_PREVIOUS_KEY_ID` / `chat.live.previous_key_id`.
- **Nonce in signed bodies.** Two honest requests with the same body in the same second would have the same signature and look like a replay. Every body the app sends to the gateway, and every presence report the gateway sends to the app, carries a random `nonce` field. Health reads (GET, no body) are signed and time-checked but may repeat.
- **Sign-out closes only that sign-in.** The internal `revoke` also accepts `session_ids` (the ticket's `sid`). Signing out sends both the user ID and that session's hash, so the person's other signed-in devices keep their live connection; losing access, offboarding, role change and suspension send the user ID alone and close every connection. Tickets issued at or before a revocation are refused for the next 66 seconds, so a ticket fetched just before the revocation cannot reopen a connection.
- **Ready after renewal.** `ready` carries `resume: true` on a new connection (the browser catches up) and `resume: false` after a renewal on the same connection (nothing was missed).
- **Keep-alive.** Every 25 seconds the gateway sends both a protocol ping and an application `{"type":"ping"}`; either kind of pong resets the missed count.
- **Extra limits.** At most 128 fragments per message (empty fragments would otherwise cost nothing against the byte limit); a connection whose unsent backlog passes 1 MiB is dropped; the node refuses new connections over `LIVE_MAX_CONNECTIONS` (default 10,000) with 503.
- **Presence grace.** A person's last connection closing is reported as offline only after 10 seconds, so a page reload does not flicker their status.
- **Defence in depth.** The gateway never forwards `presence` to a family connection (`fam: true`), whatever the app publishes.
- **Idle sessions.** A ticket renewal every 13 minutes is background work: `EnforceSessionTimeout` does not count it as activity (as it did not count Reverb's channel signing), so an open tab cannot keep an idle session signed in. Typing does count.
- **Change kinds.** `conversation.changed` names what changed: `message`, `edit`, `removal`, `reaction`, `pin`, `save` (only to the person who saved), `people`, `call`, `callback`, `family_work`, `read` and `preferences` (both only to the person themselves). Only message kinds have their own change ID, so the browser skips repeats only for those.
- **One list row.** `GET /chat/api/conversations?only=<id>` returns just that row (empty when it is no longer visible), so a list refresh after an event costs one row, not the whole list. A row new to the list, list-level changes and catch-ups still fetch the whole list.
- **Numeric cursor position.** `messages` and `changes` also return `sync_change_id`, the change ID the encrypted cursor covers, so the thread can skip events its cursor has passed without decrypting anything.
- **Calls keep their own checks.** While a call is ringing or active the call dock still checks every 3 seconds, and the 15-second availability heartbeat stays (call routing needs it), both on the one sync loop, until Oblivion Calls lands (stage 5).
- **Other watchers.** Watchers with a check of their own (file scans, publication review, notice previews, preferences, callbacks, search, collections, urgent contact, shift handover, follow-ups) keep their interval on the one loop and are also woken by events; only the seven named in spec section 8 stop polling while live.

## Open questions

- Presence with more than one gateway node: each node reports its own first-open and last-close, so a person connected to node A and leaving node B is reported offline by B. Fine for one node; a second node needs the app to keep presence per node.
- Ownership of AI-written code (see the README): a lawyer should confirm before commercial reliance.
