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
- Outside code consulted: none. No file in `vendor/laravel/reverb`, `vendor/pusher`, `vendor/ratchet`, `vendor/react`, `node_modules/pusher-js`, `node_modules/laravel-echo` or any other WebSocket library was opened, searched or fetched, and no WebSocket library documentation or example was read. `config/reverb.php` (which followed an upstream template) was not opened; it is deleted in the removal commit without being read.
- New outside packages: none. The gateway uses only Node's built-in modules; the browser client uses the browser's own `WebSocket`; the PHP side uses Laravel's existing HTTP client.
- Similarity check: to be run by the checker session.

## Decisions taken while building

- **Rotation key ID.** The spec lists `previous_secret` but no key ID for it. Tickets name their key with `kid`, so the gateway needs to know the old key's ID during a rotation: added `LIVE_PREVIOUS_KEY_ID` / `chat.live.previous_key_id`.
- **Nonce in signed bodies.** Two honest requests with the same body in the same second would have the same signature and look like a replay. Every body the app sends to the gateway, and every presence report the gateway sends to the app, carries a random `nonce` field. Health reads (GET, no body) are signed and time-checked but may repeat.
- **Sign-out closes only that sign-in.** The internal `revoke` also accepts `session_ids` (the ticket's `sid`). Signing out sends both the user ID and that session's hash, so the person's other signed-in devices keep their live connection; losing access, offboarding, role change and suspension send the user ID alone and close every connection. Tickets issued at or before a revocation are refused for the next 66 seconds, so a ticket fetched just before the revocation cannot reopen a connection.
- **Ready after renewal.** `ready` carries `resume: true` on a new connection (the browser catches up) and `resume: false` after a renewal on the same connection (nothing was missed).
- **Keep-alive.** Every 25 seconds the gateway sends both a protocol ping and an application `{"type":"ping"}`; either kind of pong resets the missed count.
- **Extra limits.** At most 128 fragments per message (empty fragments would otherwise cost nothing against the byte limit); a connection whose unsent backlog passes 1 MiB is dropped; the node refuses new connections over `LIVE_MAX_CONNECTIONS` (default 10,000) with 503.
- **Presence grace.** A person's last connection closing is reported as offline only after 10 seconds, so a page reload does not flicker their status.
- **Defence in depth.** The gateway never forwards `presence` to a family connection (`fam: true`), whatever the app publishes.

## Open questions

- Presence with more than one gateway node: each node reports its own first-open and last-close, so a person connected to node A and leaving node B is reported offline by B. Fine for one node; a second node needs the app to keep presence per node.
- Ownership of AI-written code (see the README): a lawyer should confirm before commercial reliance.
