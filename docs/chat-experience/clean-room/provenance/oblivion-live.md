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
  - Incident, 23 Oct: a repository-wide search for references to the deploy files (patterns `chat-realtime.env`, `REVERB`, …) printed nine lines of `config/reverb.php`, the config file that followed Reverb's template: its `env('CHAT_REVERB_…')` lookups for bind host, port, key, secret, app ID, allowed origins and maximum connections. Nothing from them was used: the gateway's settings come from the spec's section 10 list, and the file is deleted in the removal commit without being opened. The checker assessed this on 3 November 2026 and found nothing carried over ([Similarity check](#similarity-check)).
  - The worktree's `node_modules/` was installed from the existing lockfile (`npm ci`) because the parent checkout's copy was empty; that includes pusher-js until the removal commit. It was never opened or searched.
- New outside packages: none. The gateway uses only Node's built-in modules; the browser client uses the browser's own `WebSocket`; the PHP side uses Laravel's existing HTTP client.
- Similarity check: the repository's [../tools/token-similarity.mjs](../tools/token-similarity.mjs) (structure 12 tokens, literal 6, comments 6) and the Oblivion Relay checker's four-mode Node.js script (structure 12, literal 8, names and values 8, comment and string words 8) with its shared-identifier check, calibrated with four deliberate ports. Result: clean, no copied code ([details](#similarity-check)). By the Oblivion Live checker session (a side session of `local_fae95f7b`), 3 November 2026.

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

**Removal draft tested (31 Oct 2026).** On `claude/oblivion-live-removal-wip`, with this branch merged in (`105fb52e6`):

- Pest: `ChatRealtimeActorTest`, `OblivionLiveTest`, `SharedChatTest`, `ChatRealtimeCspTest`, `ChatReplacedEnginesGuardTest`, `ChatServiceHealthTest` and `ChatSessionActivityTest`. All 223 passed. Pusher is gone, so nothing needs it any more.
- Vitest: `chat-realtime-connection.test.ts` and the `live/` tests, 35 passed. Gateway: 68 passed.
- The lock files match: no `pusher-js`, `laravel-echo`, `pusher/pusher-php-server` or `laravel/reverb` remain, and `composer validate` passes.
- ESLint: the gateway's import rule refused its own `node:` and relative imports (35 errors). It now uses a regex, and a probe file showed both bans still refuse `ws` in the gateway and `pusher-js` in the browser code.
- Still to do: after `npm ci`, regenerate `public/legal/chat-third-party-notices.txt` (`node scripts/chat-license-notices.mjs`). `composer.lock` must be regenerated, not hand-merged, when the push sender branch lands first.

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

## Similarity check

**Clean: no copied code.** Checked on 3 November 2026 by the Oblivion Live checker session, a side session of `local_fae95f7b` that did not build Oblivion Live. It worked on branch `claude/oblivion-live-check`, cut from `claude/oblivion-live-removal-wip` at `c403aa49a`. The checker was the only session to read the outside source. That source stayed in the checker's scratchpad, never in a worktree, the repository or the main checkout. None of its scripts, builds or tests were run, and it was deleted after the check.

- **New code compared:** 47 files, 47,987 tokens.
  - The gateway: all 20 files in `live/` (`server.mjs`, the 10 `lib/` modules, the 7 test files and both tools).
  - The browser client: the 8 files in `resources/js/components/chat/live/`, plus `chat-realtime-connection.ts`, its test and `chat-update-watch.ts`.
  - The PHP side: `OblivionLive`, `ChatPresence`, `ChatRealtime`, `CompactToken`, `InvalidCompactToken`, `ChatLiveController`, `CloseChatLiveConnections`, `PublishChatUnread` and `PublishChatPresence`, with the tests `OblivionLiveTest`, `CompactTokenTest` and `ChatReplacedEnginesGuardTest`.
  - As prose: `deploy/chat-calling/oblivion-live.env.example`, `oblivion-live.service.example`, `oblivion-live-nginx.conf.example` and `chat-realtime.md`.
- **Outside source compared.** Each was fetched from GitHub with its tests and documentation (pusher-js from npm as well), at the commit the old lock files recorded where they recorded one:
  - laravel/reverb 1.12.0, pusher/pusher-php-server 7.3.0, ratchet/rfc6455 0.4.1, react/socket 1.17.0, react/stream 1.4.0 and react/event-loop 1.6.0;
  - pusher-js 8.6.0: the repository at `v8.6.0` (source, specs and built files), and the npm package the old `package-lock.json` installed, whose code is the same;
  - laravel-echo 2.6.3, the newest release (Oblivion never installed it);
  - common Node.js WebSocket code: ws 8.22.0 (the version the register lists), websocket-driver 0.7.4, faye-websocket 0.11.4 and websocket (WebSocket-Node) 1.0.35. Also reconnecting-websocket 4.4.0, for the browser's reconnect logic;
  - for the signer: firebase/php-jwt 7.0.5, and jose 6.2.12, the JWT library that comes with livekit-client in Oblivion's `node_modules`.

  That is 2,012 files and about 1.18 million code tokens, counting pusher-js once. Reverb's other dependencies were not compared: react/promise, promise-timer, dns and cache, evenement, and clue's Redis packages. Oblivion Live has no promise library, DNS resolver, cache, event emitter or Redis client for them to match. The texts of RFC 6455, 2104, 3629, 4648, 6202, 7515 and 7519, and the spec, were used to tell protocol wording apart from copied wording.
- **Tools:**
  - [../tools/token-similarity.mjs](../tools/token-similarity.mjs), as the README asks: greedy string tiling with thresholds of 12 tokens (structure), 6 (literal) and 6 words (comments). It was run on the closest pairings:
    - the gateway modules against each server library;
    - the browser client against pusher-js's source and built file, laravel-echo and reconnecting-websocket;
    - the signer against php-jwt, jose's JWS and JWT code, and pusher-php-server's signing.
  - The Oblivion Relay checker's Node.js script, written by that session with no outside code. It compares every new file with every source in four modes, with thresholds of 12 tokens (structure), 8 (literal), 8 (names and values) and 8 words (comments and strings). A fifth check lists every shared identifier longer than 3 characters that is neither in the RFC texts and the spec nor in unrelated Oblivion code.
- **Controls:**
  - Four positive controls, written in the scratchpad and deleted after scoring:
    - ws's `sender.js` with every name renamed and every string blanked: 100 % structure.
    - A close JavaScript port of ws's frame-header reader (`getInfo` to `getMask`), with renamed fields:
      - repository tool: 60.4 % structure with 48-token runs, and 27.4 % literal;
      - Relay tool: 73.9 % structure.
    - A line-by-line JavaScript port of Ratchet's PHP `frameCheck` and close-code checker:
      - structure stays low across the language change (2.9–13.5 %);
      - literal reaches 41.8 % and string words 72 %;
      - the identifier check flags 14 Ratchet names (`frameCheck`, `newCloseFrame`, `checkForMask`, `getRsv1` …).
    - A lightly renamed PHP copy of php-jwt's `decode`, `encode` and base64url helpers:
      - 68–77 % in both tools, with runs of 84 to 110 tokens;
      - 10 php-jwt names flagged (`headb64`, `cryptob64`, `urlsafeB64Decode`, `padlen` …).
  - Negative controls: 24 unrelated Oblivion files. They are 7 Oblivion Relay modules and one Relay test, the licence-notice script, 7 Chat browser files and 8 Chat PHP files.
    - Repository tool, 8 of them against the same libraries: 0–10.5 % structure and 0–3.4 % literal.
    - Relay tool, all 24: 14–68 % structure with runs up to 77 tokens, 0–17 % literal with runs up to 19, and 0–3 % names.
- **Scores:**
  - **Repository tool:**
    - Gateway modules: 0–8.3 % structure and 0–10.8 % literal against every server library.
      - The one higher structure score is `base64url.mjs` (83 tokens) at 24 %. It comes from one 20-token guard, `typeof … !== 'string' || !pattern.test(…) ||`.
      - The highest literal score is `connection.mjs` against WebSocket-Node. It is made of 6- to 10-token lines any WebSocket connection class has: `this.socket.end()`, `socket.on('data', (chunk) => …)` and `if (this.state === 'closed') return`.
    - Browser client: 0–3.8 % structure and 0–7.8 % literal. The 113-token `chat-realtime-connection.ts` reaches 21 % from one 12-token run.
    - Signer:
      - `CompactToken.php` against php-jwt: 12.7 % structure, 1.3 % literal;
      - `token.mjs` against jose: 7.9 % structure, 3.1 % literal.
  - **Relay tool:** all inside the negative range.
    - Structure: 0–54 % for engine files and up to 71.5 % for tests.
    - Literal: 0–9.3 % for engine files and 0–14.1 % for tests.
    - Names: 0–2.4 %.
    - The four deploy files score 0 in every mode.
- **Results.** Every match above the threshold was reviewed by hand. Each one is protocol shape or a common idiom:
  - **Structure:** 827 regions. All 147 with a run of 20 tokens or more were read on both sides. They are:
    - `use` and `import` lists at the top of files. The longest, 71 and 89 tokens, are the `use` blocks of `ChatLiveController.php` and `OblivionLiveTest.php`; the unrelated `OblivionRelayTest.php` reaches 73 the same way;
    - opcode, close-code and settings tables, constructor assignments, object literals and `addEventListener` lists;
    - Pest, `node:test` and Vitest scaffolding;
    - three idioms that the RFC or Node fixes:
      - the RFC 6455 5.3 unmask loop, `payload[index] ^= mask[index & 3]` (`frames.mjs` 66–70). ws's `buffer-util.js` has the same five-line shape: it is the RFC's "i MOD 4" written in JavaScript;
      - the close-frame body of RFC 6455 5.5.1: `Buffer.allocUnsafe(2 + text.length)`, `payload.writeUInt16BE(code, 0)`, then `text.copy(payload, 2)` (`frames.mjs` 253–258, the same shape as websocket-driver's `hybi.js` 205–207);
      - the `upgrade` handler's `(request, socket, head)` arguments, from the `node:http` manual.

    The 680 regions of 12–19 tokens follow the same patterns.
  - **Literal:** 328 regions; all 87 of 10 tokens or more were read.
    - The longest, 24 tokens, is the opcode table. It is the RFC 6455 11.8 registry, also in websocket-driver and pusher-js's built file.
    - The rest are:
      - byte arrays in `frames.test.mjs` (17 and 16 tokens);
      - Vitest timer and `mock.calls` lines;
      - `token.split('.')` with a three-part check, RFC 7515's compact form;
      - the close-code check `isValidCloseCode`. It writes RFC 6455 7.4 as two allowed ranges plus 3000–4999, where ws writes one range with three exclusions;
      - Node, Laravel collection and test-framework calls.
  - **Names and values:** 6 runs, the longest 12 tokens: the opcode table again, the `upgrade` and `error` handler pair, and test byte arrays.
  - **Comment and string words:** 4 runs.
    - The longest, 19 words, is the `101 Switching Protocols` response in `handshake.mjs` 77–80: the text RFC 6455 4.2.2 fixes.
    - The other three are `127.0.0.1` and `HTTP/1.1` test strings.
    - No comment prose matches any source. The deploy files share no run of 8 words with any source, Reverb's README and configuration included.
  - **Shared identifiers:** 1 to 20 per source, all reviewed. Leaving out the controls' names, they are:
    - browser and Node names: `onopen`, `readyState`, `setNoDelay`, `allocUnsafe`, `spawn`, `Infinity`;
    - PHPUnit, Pest and Laravel names: `assertSame`, `toHaveKey`, `withHeader`, `swap`, the `HttpFactory` alias;
    - plain words: `handleMessage`, `onEvent`, `sendRaw`, `testConfig`, `forged`, `payloadStart`;
    - the RFC 4648 term, in `decodeBase64url` and `encodeBase64url`;
    - `maxFragments`, which ws 8.22.0 also has as an option. Both cap the number of fragments in one message and close with 1008. The two share the idea, not the code:
      - Oblivion refuses before storing a fragment past 128. The "Extra limits" decision gives the reason: empty fragments cost nothing against the byte limit.
      - ws counts after reading, defaults to 16,384 and treats 0 as no limit.
      - The name follows the gateway's own `max…` settings (`maxMessageBytes`, `maxSignalBytes`, `maxConnectionsPerUser`).
- **Compared by eye**, because token tools can miss a reordered port:
  - **Frame reader** against ws's `Receiver` and Ratchet's `MessageBuffer` and `Frame`. Each is built differently:
    - ws is a state machine over a list of buffers (`GET_INFO` to `GET_DATA`);
    - Ratchet builds `Frame` objects and checks them afterwards;
    - Oblivion parses one whole frame at a time from one buffer, and returns "wait", "fragment stored" or an event.

    Oblivion also refuses a length that is not in its shortest form (RFC 6455 5.2), and a 64-bit length with its top bit set. Neither ws nor Ratchet does.
  - **Handshake** against ws's `handleUpgrade` and Ratchet's `ServerNegotiator`:
    - Oblivion follows RFC 6455 4.2.1's own order: method and HTTP version, `Host`, `Upgrade`, `Connection`, then version and key, then `Origin`. It reads `Upgrade` and `Connection` as comma-separated lists.
    - ws checks the method, then whether `Upgrade` equals `websocket` as a whole, then key and version; it does not check `Host` or `Connection`. Both answer a wrong method with 405, the HTTP status for it.
    - Ratchet answers a missing `Upgrade` header with 426, where Oblivion and ws answer 400.
    - For an unknown version, Oblivion and Ratchet answer 426 with `Sec-WebSocket-Version: 13`, as RFC 6455 4.4 asks. ws answers 400 with `13, 8`.
    - The key pattern (22 base64 characters, then `==`) is the only form a 16-byte value has. Oblivion also decodes the key and checks the 16 bytes.
  - **Gateway against Reverb.** Reverb is a Pusher-protocol server on ReactPHP, with apps, channels, subscriptions and `pusher:` events. Oblivion Live has none of these: tickets replace channel authorisation, and events fan out per person. Nothing lines up.
  - **Internal API signing against pusher-php-server:**
    - Pusher signs the method, path and sorted query (with `body_md5`), and sends the signature as a query parameter.
    - Oblivion signs `timestamp + "\n" + body`, sends it in headers and adds a replay guard (spec 5).
  - **Browser client** against pusher-js, laravel-echo and reconnecting-websocket:
    - `connection.ts` follows Oblivion's own wrapper from before the build (`chat-realtime-connection.ts` on `main`). It keeps that wrapper's `stopped`, `starting`, `blocked` and `attempts` flags, its online, offline and visibility handlers, and its `2 ** Math.min(attempts, …)` backoff, now with the spec's 1–30 s jitter.
    - pusher-js runs connection strategies through a state machine.
    - reconnecting-websocket wraps the socket and grows its delay by a factor of 1.3.
  - **Signer against php-jwt:**
    - php-jwt parses the header and claims, then checks the algorithm and signature, then the times.
    - `CompactToken` checks `alg` and `typ` first, then the key named by `kid`, then the 32-byte signature, and only then decodes the claims.
    - `CompactToken` accepts only canonical unpadded base64url, where php-jwt pads and decodes loosely. It requires `aud` and an integer `exp`; php-jwt never checks `aud` and treats `exp` as optional.

    The gateway's `token.mjs` checks in the same order as `CompactToken`. jose is a general library: it chooses among many algorithms and key types and validates claims in a separate step.
- **The 23 October incident:**
  - **Where the lines came from.** The nine lines printed came from Oblivion's own `config/reverb.php`, as it stood on `main` until this branch deleted it. That file followed Reverb's template, but its `CHAT_REVERB_*` names, its defaults and its comma-separated list of origins were Oblivion's own. Reverb's template uses `REVERB_*` names and `['*']` for origins.
  - **The gateway's settings.** Against both that file and Reverb's own `config/reverb.php`, `live/lib/config.mjs` scores 0 in every mode.
    - The settings are the spec's section 10 list, plus `LIVE_HOST`, `LIVE_PATH`, `LIVE_MAX_CONNECTIONS` and `LIVE_PREVIOUS_KEY_ID`.
    - Their defaults differ from the printed ones: port 8091 instead of 8085, and 10,000 connections instead of 1,000.
  - **The one shared run.** In `config/chat.php`, the `live.allowed_origins` line shares a 15-token run with the printed origins line: `array_values(array_filter(array_map('trim', explode(',', …))))`.
    - The key is the one spec section 7 names.
    - The parsing is Oblivion's own: `push_hosts` in `config/chat.php` used it before the build, and so do `config/cctv.php` and `config/endpoint_management.php`.
    - The new line follows `push_hosts`, including its `(string)` cast, not the printed line.
  - **Verdict:** nothing from the incident reached the new code.
- **Removal, notices and guards:**
  - **Packages.** `composer.lock` no longer holds laravel/reverb, pusher/pusher-php-server, ratchet/rfc6455, any react/ package, evenement or clue's packages. firebase/php-jwt 7.0.5 stays: laravel/socialite needs it, and the LiveKit code uses it until stage 5. `package.json` and `package-lock.json` hold no pusher-js, laravel-echo, tweetnacl or ws.
  - **Licences and notices.** Reverb's notices file and pusher-php-server's licence are gone from `deploy/chat-calling/licenses/`; only the LiveKit and bufbuild files remain. `scripts/chat-license-notices.mjs` no longer names pusher-js or its parts.
  - **Guards:**
    - `ChatReplacedEnginesGuardTest` checks `composer.json` and `composer.lock` for laravel/reverb and pusher/pusher-php-server, and `package.json` for pusher-js, laravel-echo and ws, as spec 12 asks.
    - ESLint `no-restricted-imports` refuses the same browser packages. In `live/` it refuses anything but `node:` and relative imports.
    - The CI lint job runs `npm run lint` over the whole repository, so both rules are checked in CI.
  - **Tests re-run by the checker** at `c403aa49a`: `node --test "live/test/*.test.mjs"` ran 68 tests, and all passed. Pest and Vitest were not re-run, because this worktree has no `vendor/` or `node_modules/`.
- **For the builder (not blocking):**
  1. **Committed notices file.** `public/legal/chat-third-party-notices.txt` was last generated on 22 October, and still lists pusher-js 8.6.0, tweetnacl and the two @stablelib parts. `prebuild` regenerates it on every build, so a built site is correct, but the committed copy is not. Regenerating it after `npm ci` is already on this note's list of things still to do.
  2. **Gateway import rule.** The `live/` pattern allows every relative path, so an import such as `../node_modules/…` passes. As an import rule, it also cannot see `require()` through `createRequire`. Nothing in `live/` does either today. A `node:test` guard like the relay's (`relay/test/allocation-log.test.mjs`) would close both gaps. This was not tried here, because this worktree has no `node_modules`.
  3. **PHP guard.** It names the two packages spec 12 lists. ratchet/rfc6455 and the ReactPHP parts only ever came in through Reverb, so a direct require of one of them would pass. Adding them is optional.
- **Still open, and not the checker's to close:** the two-browser end-to-end test and the other end-to-end checks in spec section 11, and the production build. The end-to-end checks have since passed; see the [delta check](#delta-check-3-november-2026).

### Delta check, 3 November 2026

**Clean: no copied code.** By the same checker session, on `a08af6c55` (branch `claude/oblivion-live-removal-wip`), which sits on top of the signed-off `c2df73fdb`.

- **What changed since the sign-off:**
  - `eb8ec05ae` merges `main` at `a9615729f` (Chat calling settings). It changes none of the 47 compared files, nor the Composer or npm manifests and lock files.
  - `77f410d03` regenerates `public/legal/chat-third-party-notices.txt`. It removes exactly four entries: pusher-js 8.6.0, tweetnacl 1.0.3, and the two @stablelib parts bundled in pusher-js. It rewords the header line to name livekit-client only, and leaves every livekit-client entry unchanged. This closes note 1 for the builder above.
  - `a08af6c55` changes `ChatRealtime.php`. A private `wakeSoon()` now runs `WakeChatClients` and `PublishChatUnread` with `Bus::dispatchSync` inside Laravel's `defer(…, always: true)`, once the transaction commits, instead of putting them on the queue. `SharedChatTest.php` and `OblivionLiveTest.php` add `withoutDefer()` where models change outside a request, and one test now invokes Laravel's `DeferredCallbackCollection` to check that the wake-up waits for the response.
- **Where it comes from:** Laravel's own API (`defer()`, `Bus::dispatchSync`, `DB::afterCommit` and the testing helpers), which is platform. Reverb uses none of them; its only near-match is the name of its own `dispatchSynchronously` method.
- **Compared:** the new `ChatRealtime.php`, `SharedChatTest.php` and `OblivionLiveTest.php`, with the repository tool and the same thresholds. The outside source was laravel/reverb 1.12.0, pusher/pusher-php-server 7.3.0, ratchet/rfc6455 0.4.1 and react/socket, stream and event-loop, fetched again into the scratchpad at the same commits and deleted afterwards.
  - `ChatRealtime.php`: 2.7 % structure and 3.6–4.4 % literal, longest runs 15 and 9 tokens. The file before the change scores the same; the main check reviewed it.
  - Inside the changed lines, the only runs are the `queueConversation` signature with its early return, and a `try … catch (\Throwable $error)` block. The comment in that block has been in the file since 21 October (`faeb9435c`).
  - Tests: 0.2–4.4 % structure and 0.1–2.4 % literal.
  - Unrelated Oblivion PHP files against the same sources: 0.2–2.0 % structure and 0–2.2 % literal.
- **End-to-end checks (spec section 11):** run by the builder session on 3 November 2026, on Oblivion Live with the latest `main`. The checker did not re-run them, and did not re-run Pest, because this worktree has no `vendor/`. All are within the spec:
  - the other tab updated 0.13–0.25 s after a send (spec: under 1 s);
  - typing showed in 0.37 s;
  - with the gateway stopped, polling took over within 11.5 s (spec: within 15 s);
  - both tabs reconnected after the gateway restarted;
  - 5,000 idle connections used 99 MB (spec: under 500 MB).

  The run also found the queue delay that `a08af6c55` removes: an idle worker checks the queue every 3 seconds, which added up to 3 s to each wake-up.

## Open questions

- Presence with more than one gateway node: each node reports its own first-open and last-close, so a person connected to node A and leaving node B is reported offline by B. Fine for one node; a second node needs the app to keep presence per node.
- Ownership of AI-written code (see the README): a lawyer should confirm before commercial reliance.
