# Oblivion Live: specification

Plan items C2.1, C2.2 and C2.4. Written 23 October 2026 from Oblivion's own code and public standards only. It replaces laravel/reverb (with pusher/pusher-php-server, ratchet/rfc6455 and the ReactPHP parts) and pusher-js. Builders follow [../README.md](../README.md): do not open those packages' source, repositories or documentation examples.

## 1. What it is for

Chat needs to tell open browser tabs, within a second, that something changed for them, and to carry short-lived signals (typing, presence, call signalling) that are never stored. Today a change sends a bare "refresh" to the person's private channel, and every open tab then re-fetches the list, the open conversation, call status and alerts (`app/Services/Chat/ChatRealtime.php`, `resources/js/components/chat/chat-realtime.tsx`). About eight timers also poll in the background.

Oblivion Live is three parts, all Oblivion's code:

1. **The gateway**: a small long-running Node.js program (Node 22 LTS or later) that holds browser WebSocket connections and fans out events. It uses only Node's built-in modules (`node:http`, `node:crypto`, `node:net`, `node:timers`). No npm packages.
2. **The publisher**: PHP in the app that signs connection tickets and sends events to the gateway.
3. **The browser client**: TypeScript using the browser's own `WebSocket`, with no packages, plus one sync loop that replaces the separate pollers.

The database stays the source of truth. The gateway keeps no history: after any gap the client catches up through the app's existing endpoints, using the sync cursors it already holds.

## 2. Requirements

1. A signed-in person who may use Chat receives events for themselves only.
2. Each event names what changed (the conversation and the newest change ID), so a tab fetches only that conversation's changes and that list row. A bare "refresh everything" is never sent.
3. Events carry no message text, file names, client names or other care information. They carry IDs, counts and kinds only. The app re-checks access on every fetch.
4. After a reconnect, sleep or network change the client catches up with no gap and no duplicate effect. Applying an event twice is harmless.
5. Typing and presence are never stored in the database and never reach anyone who may not see the conversation. Family see "Care team is typing" and never a staff name or online state (decision D8).
6. Call signalling (offers, answers, network candidates) passes only between people the app has admitted to the same call.
7. Signing out, losing access or being offboarded closes that person's connections within 5 seconds.
8. If the gateway is unavailable, Chat keeps working through polling, as today. A failed publish never fails the person's action.
9. One node handles at least 5,000 connections; a second node can be added without changing the browser client.

## 3. Connection and tickets

**Ticket request.** The browser calls `POST /chat/api/live/ticket` (same origin, session cookie, the existing `X-Chat-Actor` check). The app answers 403 if the person is not eligible (`ChatRealtime::eligible()`), otherwise:

```
{ "url": "/chat-live", "ticket": "<token>", "expires_in": 60 }
```

**Ticket format.** A compact signed token built by the Oblivion signer (section 9): three base64url parts, `header.payload.signature`, signature HMAC-SHA256 over `header.payload` (RFC 2104). Header `{"alg":"HS256","typ":"OL","kid":"<key id>"}`. Payload:

| Field | Meaning |
|---|---|
| `v` | 1 |
| `sub` | the user ID |
| `aud` | `oblivion-live` |
| `sid` | SHA-256 of the session ID, so a ticket is tied to one sign-in |
| `iat`, `exp` | issued and expiry times, `exp = iat + 60` |
| `jti` | a random 128-bit ID; the gateway accepts each only once |
| `fam` | true when the person is a family member (changes what typing and presence show) |

**Connecting.** The browser opens `wss://<same host>/chat-live`. The gateway accepts the upgrade only for an `Origin` on its allow-list (`LIVE_ALLOWED_ORIGINS`). Within 5 seconds the browser must send `{"type":"hello","ticket":"…"}`. The ticket never goes in the URL, so it can't land in access logs. The gateway checks the signature with the key named by `kid`, then `aud`, `exp` (allowing 5 seconds of clock difference) and the unused `jti`. It answers `{"type":"ready","resume":true}` or closes with code 4401.

**Renewal.** A connection lives for at most 15 minutes on one ticket. Two minutes before the end the gateway sends `{"type":"renew"}`; the client fetches a new ticket and sends `{"type":"hello",…}` again on the same connection. With no renewal, the gateway closes with 4401 and the client reconnects with a new ticket.

**Revocation.** On sign-out, lost access, offboarding, role change or account suspension the app calls the gateway's `revoke` (section 5), which closes every connection for that user with 4403. The client then stops reconnecting until the page reloads or the person signs in again.

## 4. The WebSocket protocol (RFC 6455)

The gateway implements the server side of RFC 6455 itself:

- the opening handshake: GET with `Upgrade: websocket`, `Connection: Upgrade`, `Sec-WebSocket-Version: 13`, a 16-byte base64 `Sec-WebSocket-Key`; the `Sec-WebSocket-Accept` answer as the RFC defines;
- frames: FIN and opcode, the 7-, 16- and 64-bit length forms, required client masking (an unmasked client frame closes the connection with 1002), continuation frames, text and binary, ping, pong and close with status codes;
- UTF-8 validation of text messages (invalid → 1007);
- no extensions and no subprotocols in version 1.

Limits per connection: one message at most 64 KiB (larger → 1009); at most 40 messages in any 10 seconds (more → 1008); a ping every 25 seconds, closed after two missed pongs. Per user at most 10 connections (the oldest is closed with 4409). Every message is JSON with a `type`.

**Browser to gateway**

| Type | Fields | What happens |
|---|---|---|
| `hello` | `ticket` | Authenticate or renew |
| `call.signal` | `call_id`, `to` (user ID), `payload` (object, at most 48 KiB) | Forwarded only if the gateway holds a call grant for both people on that call (section 6) |
| `pong` | — | Answer to the application-level ping, for browsers that hide WebSocket pings |

Typing is not sent on this connection (section 6).

**Gateway to browser**

| Type | Fields | Meaning |
|---|---|---|
| `ready` | `resume` | Authenticated |
| `renew` | — | Send a new ticket |
| `conversation.changed` | `conversation_id`, `change_id`, `kinds` (e.g. `["message","reaction"]`) | Fetch changes for this conversation if its cursor is behind `change_id`; refresh its list row |
| `inbox.changed` | `reason` (`added`, `removed`, `archived`, `renamed`) | Re-fetch the conversation list |
| `unread.changed` | `total` | The person's unread count |
| `typing` | `conversation_id`, `who` (a name for staff; `"Care team"` for family viewers), `state` (`start`/`stop`) | Show or hide the typing line for 6 seconds unless refreshed |
| `presence` | `user_id`, `state` (`on_shift`, `available`, `away`, `offline`) | Staff only |
| `call.changed` | `call_id`, `conversation_id` | Fetch call status |
| `call.signal` | `call_id`, `from`, `payload` | Hand to Oblivion Calls |
| `notice` | `kind` | A bell entry was added or cleared |
| `ping` | — | Answer with `pong` |

Close codes: 4401 ticket missing, invalid or expired; 4403 access revoked; 4409 too many connections; 1008 rate limit; 1009 too big; 1011 gateway error.

## 5. The gateway's internal API

The gateway also listens on loopback only (`127.0.0.1:LIVE_INTERNAL_PORT`) for the app. Every request carries `X-Live-Timestamp` (Unix seconds) and `X-Live-Signature`: HMAC-SHA256 over `timestamp + "\n" + raw body` with the shared secret. Requests more than 30 seconds old, or replayed, are refused.

| Endpoint | Body | Effect |
|---|---|---|
| `POST /internal/publish` | `{"events":[{"to":[user IDs, at most 500],"type":"…","data":{…}}]}` | Send to every open connection of those users |
| `POST /internal/revoke` | `{"user_ids":[…]}` | Close their connections with 4403 |
| `POST /internal/call-grant` | `{"call_id":"…","user_ids":[…],"until":<unix>}` | Allow signalling between these people on this call |
| `POST /internal/call-revoke` | `{"call_id":"…","user_ids":[…]}` | Stop it (left, removed or call ended) |
| `GET /internal/health` | — | Connection count, uptime, version; used by Settings › Chat › Service health |

The gateway reports presence back to the app: when a user's first connection opens or last one closes it sends `POST <LIVE_APP_URL>/chat/api/live/presence` with `{"user_id":…,"state":"online"|"offline"}`, signed the same way. The app stores `last_seen_at` (it already has the column) and publishes `presence` to staff who share a conversation with that person, at most once a minute per person.

## 6. Typing, presence and calls in the app

- **Typing.** `POST /chat/api/conversations/{id}/typing` with `{"state":"start"|"stop"}`, at most once every 3 seconds per person and conversation. The app checks write access (`ChatAccess::allows(…, true)`), then publishes `typing` to the other people who may read the conversation. Staff see the name; family see "Care team". Nothing is stored.
- **Presence.** From the gateway's presence reports and the person's chosen availability (`chat_preferences.availability`), and on-shift state from the roster when available (plan C5.10). Replaces the 15-second availability heartbeat for display; calls keep their own checks until Oblivion Calls lands.
- **Calls.** When the app admits a person to a call it calls `call-grant`; on leave, removal, timeout or end it calls `call-revoke`. The gateway forwards `call.signal` only between two people who both hold a current grant for that `call_id`, and never reads or stores the payload.

## 7. The publisher (PHP)

A new `App\Services\Chat\OblivionLive` with:

- `ticket(User $user): array` → section 3;
- `publish(array $userIds, string $type, array $data): void` → section 5, chunked by 500, sent after the database transaction commits (as `ChatRealtime::queueConversation()` does today), failures reported and never thrown into the user's action;
- `revoke(array $userIds)`, `grantCall(...)`, `revokeCall(...)`, `health(): array`.

`ChatRealtime::publish()` keeps its recipient rules (members plus care-team cover staff, each passing `allows()`) and sends `conversation.changed` with the conversation's newest `chat_message_changes` ID and the change kinds, instead of `chat.changed {refresh:true}`. List-level changes send `inbox.changed`. The unread count goes out as `unread.changed` after the badge cache is cleared.

Configuration in `config/chat.php` under `live`: `enabled`, `path` (`/chat-live`), `internal_url`, `secret`, `key_id`, `previous_secret` (accepted during rotation), `allowed_origins`.

## 8. The browser client and one sync loop

`resources/js/components/chat/live/` (new):

- `connection.ts`: fetch ticket, open the socket, send `hello`, handle `renew`, reconnect with jittered backoff (1 s, 2 s, 4 s … capped at 30 s; reset after 60 s connected), stop on 4403, pause while offline and resume on `online` and when the tab becomes visible.
- `sync.ts`: **one** loop for the whole app, replacing the separate timers in the workspace, thread, calling, message sounds, family inbox, family work and family home. While connected it fetches only what an event names. While disconnected it polls: every 10 s with the tab visible, 60 s hidden. After any reconnect it fetches the list once and runs `changes` for the open conversation with its saved cursor.
- Events go to subscribers by type, so the thread, list, call dock and badge each listen only to what they need. Duplicate or out-of-order `conversation.changed` events are ignored when the cursor is already at or past `change_id`.

## 9. The Oblivion signer

`App\Support\Signing\CompactToken` (C2.4) issues and checks the tokens in section 3: base64url without padding (RFC 4648 §5), HMAC-SHA256, constant-time comparison (`hash_equals`), required `exp`, `aud` and `kid`, a clock allowance in seconds, and refusal of any `alg` other than `HS256`. The gateway has the same checks in Node (`node:crypto` `createHmac` and `timingSafeEqual`). Chat stops using `firebase/php-jwt` once LiveKit is removed (stage 5).

## 10. Deployment

- The gateway lives in `live/` at the repository root (`live/server.mjs` and modules) and runs as a service: `node live/server.mjs`. Environment: `LIVE_PORT`, `LIVE_INTERNAL_PORT`, `LIVE_SECRET`, `LIVE_KEY_ID`, `LIVE_PREVIOUS_SECRET`, `LIVE_ALLOWED_ORIGINS`, `LIVE_APP_URL`.
- nginx keeps the existing same-origin `/chat-live` proxy (WebSocket upgrade headers, 75 s read timeout). The content security policy allows `wss://<own host>` only.
- Settings › Chat › Service health reads `GET /internal/health` through the app.

## 11. Tests and acceptance

- **Gateway** (`node --test`, built in): handshake accept value, masking rules, every length form, fragmented messages, invalid UTF-8, oversize, rate limit, ping timeout, tickets (expired, wrong audience, reused `jti`, wrong `kid`, bad signature), call grants, revoke, and a randomised frame fuzz test that must never crash the process.
- **App** (Pest): ticket issuance and refusal; `publish` recipients for staff, family and cover, matching `allows()`; typing permissions and the family "Care team" wording; presence rate limit; revoke on sign-out and offboarding; failure of the gateway never fails a send.
- **Browser** (Vitest): reconnect and backoff, renew, 4403 stop, cursor de-duplication, the single sync loop replacing the old timers.
- **End to end**: two browsers, one message: the other tab updates in under 1 second; gateway stopped: polling takes over within 15 seconds; 5,000 idle connections on one node stay under 500 MB.
- Old behaviour kept: every existing chat test passes; `SharedChatTest`'s Pusher-format assertion is replaced by an Oblivion Live one.

## 12. Removal when it lands

Remove `laravel/reverb` (and with it pusher/pusher-php-server, ratchet/rfc6455, the ReactPHP parts, evenement and clue), `pusher-js`, `config/reverb.php`, the `chat` broadcasting connection, the Reverb notices in `deploy/chat-calling/licenses/`, and their entries in `scripts/chat-license-notices.mjs`. Update the content security policy, the nginx template, `ChatServiceHealth`, `docs/chat-experience/browser-push.md` and `deploy/chat-calling/chat-realtime.md`. Add guards: ESLint `no-restricted-imports` for `pusher-js`, `laravel-echo` and `ws`; a test that fails if `composer.json` requires `laravel/reverb` or `pusher/pusher-php-server`. Mark the register entries "signed off".
