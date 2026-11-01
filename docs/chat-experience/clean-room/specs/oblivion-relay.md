# Oblivion Relay: specification

Plan item C4.3 (stage 5). Written 1 November 2026 from Oblivion's own code and public standards only. Oblivion Relay is Oblivion's own firewall relay for calls: a STUN and TURN server. It replaces coturn, which decision D4 planned on 22 October and which was never installed. When LiveKit goes (C4.10) it also takes over the relay job that the LiveKit server's built-in TURN does today.

Builders follow [../README.md](../README.md). Do not open, search or fetch the source code, examples or built files of coturn, LiveKit, pion/turn or any other STUN or TURN server or client. Two places in this repository lead to such code; leave both closed:

- the coturn link in `docs/native-endpoint-management/REMOTE-RELAY.md`;
- the endpoint agent's Go tests, which use pion/turn as a loopback fixture (its licence is `endpoint-agent/third-party-licenses/github_com_pion_turn_v5-v5.1.2-LICENSE.txt`).

The Settings › Obli remote-support card shows a coturn configuration snippet. It is not needed for this work.

**Spec provenance.** Written by the Oblivion Relay spec-writer session, a side session of the Chat rebuild session (`local_fae95f7b`), on 1 November 2026, on branch `claude/oblivion-relay-spec`. Sources read: RFC 8489, RFC 8656, RFC 5769 and RFC 7983 from rfc-editor.org, RFC 6062 (only to leave it out of scope), draft-uberti-behave-turn-rest-00, and the Oblivion code and documents named in section 1. This session opened, searched and fetched no code, examples or built files from any TURN or STUN implementation. It does not build the relay.

**Updated the same day** with the user's answers to five open questions:

- STUN Binding is on by default.
- Five hours is the longest Chat call.
- The app gets an instant cut-off, through the control channel in 3.7.
- Fuller logs record the client IP of every allocation, with restricted access (section 9).
- That log is kept for 14 days.

The answers are listed in section 13.

## 1. What it is for

Oblivion Calls (D3, stage 5) connects browsers directly with the browser's own WebRTC. Each person in a call has one peer connection to every other person: up to 7 in an 8-person voice call, and up to 3 in a 4-person video call. Most of these connections go direct. Some can't, for example:

- care-home and guest Wi-Fi that isolates devices or blocks outgoing UDP;
- hospital and council networks that allow only web traffic on TCP 443, sometimes through a proxy;
- some 4G and fibre connections behind carrier-grade NAT that gives each destination a different port.

In those cases the browser asks the relay for a relayed address (a TURN allocation), and the media flows through the relay. Media stays encrypted end to end (DTLS-SRTP between the browsers). The relay sees addresses, packet sizes and timing, but never the audio or video.

Two other Oblivion features already issue TURN credentials and expect a TURN service to exist:

- **CCTV live view** (`app/Domain/SecurityDevices/Cctv/Services/CctvSessions.php`, `turn()`; contract in `docs/native-endpoint-management/CCTV-CONTRACT.md`). The browser and the on-site collector both use relay-only ICE. Their relayed addresses must fall inside `CCTV_TURN_RELAY_CIDRS` and the `CCTV_TURN_RELAY_PORT_MIN`–`MAX` range.
- **IT remote support** (`app/Domain/SecurityDevices/Endpoints/Services/EndpointRemoteRelay.php`, settings in `EndpointRemoteSupportSettings.php` and `config/endpoint_management.php`; behaviour in `docs/native-endpoint-management/REMOTE-RELAY.md`). The agent and the technician each get their own credential. It lasts until the session deadline, at most 120 minutes.

Both use the TURN REST format (section 3): the username is `expiry:opaque-id`, and the password is the Base64 HMAC-SHA1 of the username under a shared secret. Oblivion Relay accepts their credentials as they are today.

**What it is:** one long-running Node.js program (Node 22 LTS or later) in `relay/` at the repository root, using Node's built-in modules only. It answers STUN Binding requests (optional) and serves TURN allocations that relay UDP. Clients reach it over UDP, TCP or TLS. It also has a health endpoint on localhost only, and a signed control channel on a private address, which the app uses to end a person's relay use at once (3.7).

**What it is not:**

- a media server or SFU: it never decodes, mixes or records anything;
- a TCP relay (RFC 6062): browsers only ask for UDP relaying;
- a TURN-over-DTLS server: browsers don't use it, and Node has no built-in DTLS;
- a third-party authorisation server (RFC 7635);
- a cluster: each node stands alone, and the app can list up to four relay URLs.

## 2. Requirements

1. It relays UDP media for Chat calls of up to 8 voice or 4 video participants, for CCTV live view and for IT remote support.
2. It works from networks that allow only TCP 443. TURN over TLS on 443 is always available. TURN over plain TCP on 443 is available where the relay owns that port.
3. It accepts only time-limited TURN REST credentials. Chat, CCTV and remote support each have their own secret, and each class can hold a current and a previous secret for rotation. There are no static users, no anonymous use and no unauthenticated allocations.
4. It never opens a way into a private network. It refuses to relay to loopback, private, link-local, multicast, CGNAT, reserved or tunnel addresses, or to its own addresses, unless an explicit allowlist names them (section 6).
5. It never amplifies or reflects attacks on others (section 7).
6. No single person, credential or network can use it up. There are allocation quotas per credential and in total, bandwidth caps, and fixed lifetimes for allocations, permissions and channels.
7. Logs never contain credentials, keys, nonces or message content. They record the client IP of every allocation, so abuse can be traced, and that log is deleted after a set number of days and readable only by named people (section 9). The health and metrics endpoint listens on localhost only.
8. It runs on its own small server or beside nginx on the app server, from the same code and settings (section 10). **Where it runs is not decided. The user chooses before stage 5 network testing.**
9. Rotating a secret or renewing the certificate never drops a call: both reload on SIGHUP.
10. One node with 2 vCPUs and 2 GB of memory carries at least 1,000 allocations and 150 Mbit/s of relayed traffic in each direction (section 12).
11. The app can end a person's relay use at once, for example when someone is offboarded during a call. It does this over a signed control channel that is never internet-facing (3.7). When the relay is reachable, the person's relayed media stops within 5 seconds of the app's decision.

## 3. Credentials

### 3.1 Username and password

| Part | Rule |
|---|---|
| Username | `<expiry>:<opaque-id>`, printable ASCII (0x21–0x7E), at most 128 bytes |
| `expiry` | The time after which the credential can't open new allocations. Unix seconds in decimal, with no sign and no leading zero, 10 or 11 digits |
| `opaque-id` | `obli-<tag>-<64 lowercase hex characters>`. The hex is a keyed hash made by the app, so the relay never learns who a person is |
| Password | Base64, with padding, of HMAC-SHA1(secret, username) (RFC 2104), as the app computes it |
| Long-term key | MD5(username ":" realm ":" password), as RFC 8489 §9.2.2 defines it. The password here is the Base64 text above |

Oblivion's usernames are ASCII only, so the relay implements neither SASLprep nor OpaqueString. A USERNAME containing any byte outside 0x21–0x7E is refused with 401.

**Known-answer vector**, shared by the Node and PHP tests. Both were computed with `node:crypto` and PHP's `hash_hmac`, and they agree:

| Input | Value |
|---|---|
| secret | `oblivion-relay-test-secret-0123456789` |
| username | `1798761600:obli-chat-b0d51c58c8b9c1f458fadf16c7d375630ef51da4df81915893b05c0fa4ed8bc6` |
| realm | `turn.example.org` |
| password | `mnNManMB1KVphfDcfsHwp6Ly9LI=` |
| long-term key (hex) | `77ab678c8fe33bbc924f657e74cf8b14` |

### 3.2 Classes

The tag in the opaque-id selects the class, and the class selects the secrets. A class with no secret set is switched off, and its users get 401.

| Class | Tags | Issued by | Relay secrets | App setting with the same secret | Longest credential | Grace after expiry | Peers |
|---|---|---|---|---|---|---|---|
| `chat` | `chat` | `ChatRelayCredentials` (3.6) | `RELAY_CHAT_SECRET`, `RELAY_CHAT_PREVIOUS_SECRET` | `CHAT_RELAY_SECRET` | 3,600 s | 14,400 s | `public` |
| `cctv` | `cctv` | `CctvSessions::turn()` | `RELAY_CCTV_SECRET`, `RELAY_CCTV_PREVIOUS_SECRET` | `CCTV_TURN_REST_SECRET` | 3,600 s | 0 | `relay-only` |
| `remote` | `agent`, `technician` | `EndpointRemoteRelay::grant()` | `RELAY_REMOTE_SECRET`, `RELAY_REMOTE_PREVIOUS_SECRET` | `ENDPOINT_REMOTE_RELAY_SECRET`, or the encrypted app setting | 7,200 s | 0 | `relay-only` |

- **Longest credential** (`RELAY_<CLASS>_MAX_CREDENTIAL_SECONDS`). An `expiry` further ahead than this, plus the clock allowance, is refused with 401, so a faulty issuer can't hand out year-long credentials. CCTV's 3,600 s matches `CCTV_LIVE_MAX_SESSION_SECONDS`, which is at most 3,600. Remote support's 7,200 s matches its 120-minute ceiling.
- **Grace after expiry** (`RELAY_<CLASS>_GRACE_SECONDS`). This is how long an allocation made before `expiry` may keep refreshing after it. The TURN REST draft lets existing allocations carry on after expiry, because a browser can't change the username of a live allocation. Oblivion puts a limit on that. With a grace of 0, the relay ends the allocation at `expiry`. The CCTV and remote-support contracts already expect this: CCTV's "worker and browser both close by it", and remote support's local deadline. For Chat, a one-hour credential with four hours' grace keeps a call alive for up to five hours, while a leaked credential can open new allocations for at most an hour.
- **Peers**: see 6.3.

Secrets are 32–512 bytes, the same bounds the app already enforces. The relay refuses to start if two classes share a secret, or if a class's previous secret equals its current one.

### 3.3 Checking a request

Every request except Binding goes through these checks in this order, which follows RFC 8489 §9.2.4:

1. No MESSAGE-INTEGRITY or MESSAGE-INTEGRITY-SHA256: answer 401 with REALM and a fresh NONCE (4.3).
2. Integrity present, but USERNAME, REALM or NONCE missing: 400.
3. USERHASH, PASSWORD-ALGORITHM or PASSWORD-ALGORITHMS present: 400. The relay offers none of them (4.2).
4. REALM or username not valid: 401 with REALM and a fresh NONCE. They are not valid if:
   - REALM differs from `RELAY_REALM`;
   - the username's format is wrong;
   - the class is unknown or switched off;
   - the opaque-id is on the deny list (3.5);
   - for Allocate only: `expiry` has passed, allowing `RELAY_CLOCK_SKEW_SECONDS` (default 30), or lies beyond the class's longest credential.
5. Integrity. For Allocate, try the class's current secret, then its previous one. For every other request, use the key of the allocation on this 5-tuple. If nothing matches: 401. Compare with `crypto.timingSafeEqual`.
6. Nonce not issued by this process, issued for another client address, or older than `RELAY_NONCE_SECONDS`: 438 with a fresh NONCE.
7. A request other than Allocate on a 5-tuple that has no allocation: 437. On an allocation made under a different username: 441.

Every response to a request that passed step 5, success or error, carries MESSAGE-INTEGRITY made with the same key. RFC 8489 requires MESSAGE-INTEGRITY rather than MESSAGE-INTEGRITY-SHA256 for requests processed as MD5. The response also carries FINGERPRINT if the request had one. Responses with 400, 401 or 438 carry no integrity.

A failure at step 4 or 5 counts towards that client address's failure limit (section 7).

### 3.4 Rotating a secret

1. Put the new secret in `RELAY_<CLASS>_SECRET` and the old one in `RELAY_<CLASS>_PREVIOUS_SECRET`, then send SIGHUP. The relay now accepts both.
2. Switch the app's setting to the new secret. New credentials use it from then on.
3. Wait for the class's longest credential plus its grace (for Chat, five hours). Then clear `RELAY_<CLASS>_PREVIOUS_SECRET` and send SIGHUP.

An allocation keeps the key it was made with, so steps 1 and 2 don't affect it. When a reload removes the secret that an allocation was made with, the relay ends that allocation at once. This is also the emergency switch for a leaked secret: set a new current secret, clear the previous one and reload.

### 3.5 Deny list

A TURN REST credential can't be withdrawn once it has been issued. The normal way to end someone's relay use is the app's control channel (3.7). The deny file is the operator's fallback when the app can't reach the relay. `RELAY_DENY_FILE` is optional. It names a file with one opaque-id, or one `expiry:opaque-id`, per line, where `#` starts a comment. On SIGHUP the relay re-reads the file, ends matching allocations at once and refuses those ids from then on with 401. An operator adds ids by hand, for example when a credential is known to have leaked. The file never holds passwords.

### 3.6 What the app issues for Chat

The CCTV and remote-support issuers stay as they are. Chat gets its own issuer in this item, so that the relay and the app are tested against each other:

- `App\Services\Chat\ChatRelayCredentials::for(User $user, string $callUuid): array` returns `{urls, username, credential, expires_at}`.
- `username` is `<now + CHAT_RELAY_CREDENTIAL_SECONDS>:obli-chat-<hash_hmac('sha256', 'chat|'.$callUuid.'|'.$user->id, app.key)>`. `CHAT_RELAY_CREDENTIAL_SECONDS` defaults to 3,600 and may not exceed it. Each person gets one id per call. The relay can then apply the per-person quota within a call, but can't link one person's calls together.
- `credential` is `base64_encode(hash_hmac('sha1', $username, $secret, true))`, exactly as the two existing issuers compute it.
- `urls` come from `CHAT_RELAY_URLS`: one to four URLs, each passing `EndpointRemoteRelay::isValidRelayUrl()`. List UDP first, then TCP, then TLS. The secret is `CHAT_RELAY_SECRET`, 32–512 bytes. If the settings are missing or invalid, no relay entry is returned and the caller reports the relay as unavailable. There is never a fallback to an outside server.
- The settings go in `config/chat.php` under `relay`: `urls`, `secret` and `credential_seconds`.
- The HTTP route that hands credentials to a browser belongs to Oblivion Calls (C4.2). It checks that the person has been admitted to the call. The browser asks again before every ICE restart.
- Credentials are computed on demand. They are never stored, logged or written to an audit row, as REMOTE-RELAY.md already requires for remote support.
- It never issues an expiry at or before an `expires_up_to` it has sent in a revocation for the same id (3.7). It remembers each one in the cache until that time passes. The access checks normally refuse a revoked person first anyway.

### 3.7 Ending relay use at once: the control channel

On 1 November 2026 the user decided that the app must be able to end a person's relay use straight away, for example when someone is offboarded during a call. Version 1 therefore has a signed control channel from the app to the relay. Chat must use it: the relay refuses to start if the `chat` class is on and there is no control secret.

**Transport.**

- It is its own `node:http` listener, `RELAY_CONTROL_LISTEN` (default `127.0.0.1:9479`), separate from the health endpoint.
- It may bind to a loopback address or a private one: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 (for a private overlay such as WireGuard) or fc00::/7. The relay refuses to start if it is set to a public address, `0.0.0.0` or `::`. It is never internet-facing.
- It answers only sources in `RELAY_CONTROL_ALLOWED_SOURCES` (default `127.0.0.1/32,::1/128`), which may list loopback and private CIDRs only. Connections from anywhere else close without a reply. The host firewall also allows the control port only from the app server.
- Where it listens:
  - Beside nginx (shape B): on loopback.
  - On its own server (shape A): on the relay's address on a private network it shares with the app server, such as a cloud private network or a WireGuard tunnel. Where there is no private network, use an SSH or WireGuard tunnel that ends on loopback. The port is never opened publicly.
- It speaks plain HTTP/1.1. The HMAC gives authenticity and integrity. Bodies hold only opaque ids, times and a reason word, never names or user ids.

**Authentication.**

- The channel has its own secret, separate from every credential secret: `RELAY_CONTROL_SECRET`, with key id `RELAY_CONTROL_KEY_ID`. During rotation it also has `RELAY_CONTROL_PREVIOUS_SECRET` with `RELAY_CONTROL_PREVIOUS_KEY_ID`.
  - Each secret is a value or a `_FILE` path, 32–512 bytes, and is re-read on SIGHUP.
  - The relay refuses to start if the control secret equals any credential secret.
- The app holds the same values in `CHAT_RELAY_CONTROL_SECRET` and `CHAT_RELAY_CONTROL_KEY_ID`, and always signs with the current key.
- Rotation:
  1. On the relay, make the new secret current and the old one previous, then send SIGHUP.
  2. Switch the app to the new secret.
  3. Once the app's queue holds no request signed with the old key, clear the previous one on the relay and send SIGHUP.
- Every request carries four headers:
  - `X-Relay-Key`: the key id;
  - `X-Relay-Timestamp`: Unix seconds;
  - `X-Relay-Nonce`: 32 lowercase hex characters (128 random bits);
  - `X-Relay-Signature`: base64url, without padding, of HMAC-SHA256(secret, method "\n" path "\n" key id "\n" timestamp "\n" nonce "\n" raw body).

  The method and path are signed, so a request can't be replayed on another endpoint. Compare with `crypto.timingSafeEqual`.
- An unknown key id, a missing header or a wrong signature gets 401, with no detail. After 10 failures in a minute from one source, that source is ignored for 60 s.

Known-answer vector, shared by the Node and PHP tests. Both were computed with `node:crypto` and PHP's `hash_hmac`, and they agree:

| Input | Value |
|---|---|
| secret, key id | `oblivion-relay-control-test-0123456789`, `k1` |
| method, path | `POST`, `/control/revoke` |
| timestamp, nonce | `1798761600`, `00112233445566778899aabbccddeeff` |
| body (150 bytes, no trailing newline) | `{"revocations":[{"id":"obli-chat-b0d51c58c8b9c1f458fadf16c7d375630ef51da4df81915893b05c0fa4ed8bc6","expires_up_to":1798765200}],"reason":"offboarded"}` |
| `X-Relay-Signature` | `ORL-3hdG48NXinOxKS55SnorOgyU3fULooysZTgFcOw` |

**Replay protection.**

- The timestamp must be within 30 s of the relay's clock, either way, or the request gets 401.
- The relay remembers every accepted nonce for 60 s and refuses a repeat with 401. It holds at most 10,000 nonces. When that is full, new requests get 503 until space frees.
- A revocation is idempotent, so applying it twice changes nothing.

**Endpoints.** Any other path gets 404 and any other method 405.

| Method and path | Body | Answer |
|---|---|---|
| `POST /control/revoke` | `{"revocations":[{"id":"obli-<tag>-<64 hex>","expires_up_to":<Unix seconds>}],"reason":"offboarded"\|"left_call"\|"call_ended"\|"access_removed"\|"hand"}`. 1–500 entries, at most 64 KiB, `Content-Type: application/json` | 200 `{"ended": <allocations ended>, "held": <revocations now held>}`, sent once the work is done. 400 if any entry is invalid, and then nothing is applied. 413 if the body is too large |
| `GET /control/status` | empty | 200 with the `/health` body, plus `revocations_held` and `control: {key_id, previous_key}` |

**What a revoke names.** The opaque id from the username, `obli-<tag>-<64 hex>`. The tag tells the relay the class. `expires_up_to` is the latest credential expiry the app could have issued for that id so far: the app's clock now, plus its credential lifetime. If `expires_up_to` is further ahead of the relay's clock than the class's longest credential plus the clock allowance, the request gets 400.

**What the relay does** before it answers, within 1 second:

1. It ends every allocation whose opaque id matches and whose credential expiry is at or before `expires_up_to`, including allocations in their grace period. It closes their relayed sockets, drops their permissions and channels, and closes any TCP or TLS connection that carried them. Datagrams for those allocations are dropped from then on, and a later Refresh gets 437.
2. It holds the revocation until `expires_up_to` plus the clock allowance. While it is held, an Allocate with a credential for that id whose expiry is at or before `expires_up_to` gets 401. Credentials issued after the revoke carry a later expiry and work, so someone re-admitted to a call can rejoin.
3. A second revocation of the same id keeps the later `expires_up_to`.
4. It writes the held revocations to `RELAY_STATE_DIR/revocations.json`, writing a temporary file and renaming it, so that a restart doesn't forget them. Expired entries are pruned. If the file exists but can't be read, the relay refuses to start and names the file. The operator moves it aside to start without it.
5. It logs `revoked` in the allocation log (section 9) with the id hash, class, reason and the number of allocations ended.

**The app side.**

- `App\Services\Chat\OblivionRelay` provides `revoke(array $opaqueIds, string $reason)`, `revokePerson(User $user, string $reason)`, `revokeCallMember(ChatCall $call, User $user, string $reason)` and `status()`.
- A person's ids are their Chat ids (3.6) for every call they were admitted to whose credentials could still be in use: calls that haven't ended, and calls that ended within the credential lifetime plus grace (five hours).
- `CHAT_RELAY_CONTROL_URLS` lists one URL per relay node, one to four, such as `http://127.0.0.1:9479` or `http://10.0.0.5:9479`. Each must be `http`, a literal loopback or private IP with a port, and no path or query; anything else is refused when the configuration is read. The settings go in `config/chat.php` under `relay.control`: `urls`, `secret` and `key_id`.
- Hooks:
  - **Chat offboarding:** `ChatOffboarding::complete()` calls `revokePerson($person, 'offboarded')` after its transaction commits.
  - **The leaver rule:** when `ChatMembershipGaps::sync()` opens a gap because someone is no longer current staff (hourly through `chat:sync-membership-gaps`, and at offboarding), it calls `revokePerson(…, 'offboarded')`.
  - **Leaving or losing a call:** `ChatCallReconciler`, or its stage 5 successor in Oblivion Calls, calls `revokeCallMember(…)` when a participant becomes `left`, `removed`, `revoked` or `expired`, with `left_call` or `access_removed` as the reason. When a call ends, it revokes every participant's id for that call with `call_ended`, so nothing outlives the call.
  - **Suspension or deactivation:** wherever the app suspends or deactivates an account (where Oblivion Live's revoke also hooks in), it calls `revokePerson(…, 'access_removed')`.
  - **By hand:** `php artisan chat:relay-revoke {user} {--call=} {--reason=hand}` revokes one call or all of the person's live calls. A button for this in Settings › Chat needs a board first, so it isn't in version 1.
- Every revoke, by hand or automatic, writes an audit row: `chat.relay.revoked`, with the actor (or `system`), the person's id, the call ids, the reason, and the nodes that confirmed.

**When the app can't reach the relay.**

- Each revoke is sent to every node straight after the database commit, after the browser has had its response, with a 3-second timeout. A failed node gets a queued retry job: at 5 s, 15 s and 30 s, then every minute. Retries stop once `expires_up_to` plus the class's grace has passed, because by then nothing the revoke covers can still be relaying.
- The person's action never fails or waits because a relay is unreachable, as with Oblivion Live.
- Settings › Chat › Service health shows "Relay: N revocations waiting" with the oldest one's age. The scheduler logs an error for each revocation that has waited more than 5 minutes.
- Meanwhile, the app's access checks stop the person getting new credentials, and Oblivion Live's revoke ends their call signalling. Media through an allocation they already hold can continue until the revoke lands, or at worst until the credential's expiry plus grace (five hours for Chat). An operator who can reach the relay host can end it at once with the deny file (3.5).
- After a relay restart, held revocations come back from the state file.

## 4. STUN (RFC 8489)

### 4.1 Messages

- A message is accepted only if all of these hold: the first two bits are 0; the magic cookie is 0x2112A442; the length is a multiple of 4 and matches the bytes received (UDP) or framed (TCP); the attributes fit exactly; and the class and method are ones the relay knows. Anything else is dropped without an answer, including messages without the magic cookie (the old RFC 3489 form).
- A request may be at most 2,048 bytes on any transport.
- Unknown comprehension-optional attributes (0x8000–0xFFFF) are ignored. Unknown comprehension-required attributes (0x0000–0x7FFF) get a 420 with UNKNOWN-ATTRIBUTES in a request, and cause an indication to be dropped.
- MESSAGE-INTEGRITY (HMAC-SHA1, 20 bytes) and MESSAGE-INTEGRITY-SHA256 (32 bytes) are computed and checked exactly as §14.5 and §14.6 describe, including the length adjustment. Attributes after them are ignored, except FINGERPRINT, and MESSAGE-INTEGRITY-SHA256 after MESSAGE-INTEGRITY.
- FINGERPRINT is the CRC-32 of the message up to that attribute, XOR 0x5354554E (§14.7). Use `node:zlib`'s `crc32()`, or a table written from the CRC-32 definition. A message with a wrong FINGERPRINT is dropped.
- Padding bytes may hold any value when read. They are written as zero.
- XOR-MAPPED-ADDRESS, XOR-PEER-ADDRESS and XOR-RELAYED-ADDRESS follow the XOR rules of §14.2. IPv6 addresses are also XORed with the transaction ID.
- The relay never sends SOFTWARE. That keeps its version private and its unauthenticated replies small, and it departs from RFC 8656's "SHOULD" on purpose. SOFTWARE received from clients is ignored and never logged.
- Error reason phrases are the short standard ones. They never say why a check failed.

### 4.2 Authentication features

The relay uses the long-term credential mechanism with MD5 keys, the form every browser supports. It never sends PASSWORD-ALGORITHMS, never accepts USERHASH and never offers anonymous usernames. Its NONCE never starts with the RFC 8489 "nonce cookie" (`obMatJos2`), so clients take the backwards-compatible path. Requests may carry MESSAGE-INTEGRITY or MESSAGE-INTEGRITY-SHA256, and responses carry MESSAGE-INTEGRITY.

### 4.3 Nonces

- A nonce is the base64url, without padding, of 20 bytes: the 4-byte issue time (Unix seconds, big-endian), then the first 16 bytes of HMAC-SHA256(nonce key, issue time ‖ listener transport ‖ client IP ‖ client port). That makes 27 characters.
- The nonce key is 32 random bytes (`crypto.randomBytes`) made at start-up and never written anywhere. A restart makes every nonce stale, and each client recovers with one 438.
- A nonce is valid only from the same client IP and port on the same transport, and for `RELAY_NONCE_SECONDS` (default 600). RFC 8489 forbids giving two sources the same nonce. Binding the nonce to the address also stops an attacker who holds valid credentials from authenticating from a spoofed address, because the 401 that carries the nonce goes to the real owner of that address.
- Behind the PROXY protocol, the client address is the one in the PROXY header (section 8).

### 4.4 Binding

- With `RELAY_STUN_BINDING=on` (the default), Binding requests on every listener get XOR-MAPPED-ADDRESS only, plus FINGERPRINT if the request had one. The relay sends no MAPPED-ADDRESS, OTHER-ADDRESS or RESPONSE-ORIGIN, and Binding needs no authentication. It lets browsers learn their public address, so more calls go direct and fewer need relaying. Calls must be self-hosted, so no outside STUN server takes this role.
- With `off`, Binding requests are dropped without an answer.
- Binding indications (keep-alives) are ignored.
- Binding answers count towards the unauthenticated-reply limits (section 7).

### 4.5 Error codes the relay sends

| Code | When |
|---|---|
| 400 Bad Request | Malformed request or missing required attribute; CHANNEL-NUMBER out of range or already in use; RESERVATION-TOKEN combined with EVEN-PORT or an address-family attribute; more than 16 peer addresses |
| 401 Unauthenticated | No integrity, invalid realm or username, or wrong integrity |
| 403 Forbidden | A peer address or port that section 6 refuses |
| 420 Unknown Attribute | An unknown comprehension-required attribute, including DONT-FRAGMENT |
| 437 Allocation Mismatch | Allocate on a 5-tuple that already has an allocation; any other request on one that has none |
| 438 Stale Nonce | Nonce expired, from another source, or not one of ours |
| 440 Address Family not Supported | IPv6 requested while the relay has no IPv6 |
| 441 Wrong Credentials | Username differs from the allocation's |
| 442 Unsupported Transport Protocol | REQUESTED-TRANSPORT is not UDP (17) |
| 443 Peer Address Family Mismatch | Peer, or Refresh REQUESTED-ADDRESS-FAMILY, differs from the allocation's address family |
| 486 Allocation Quota Reached | The credential's quota is used up (section 7) |
| 508 Insufficient Capacity | Relay full; no free port; permission or channel limit reached; per-address cap; RESERVATION-TOKEN (never valid); EVEN-PORT with the R bit |

The relay never sends 300 Try Alternate.

## 5. TURN (RFC 8656)

### 5.1 Transports between the client and the relay

| Listener | What arrives | Framing |
|---|---|---|
| `udp` | STUN, TURN and ChannelData in datagrams | One message per datagram; ChannelData needs no padding |
| `tcp` | Plain TURN over TCP | Messages back to back; ChannelData is padded to 4 bytes (§12.5) |
| `tls` | TURN over TLS (section 8) | As `tcp`, inside TLS |
| `tcp+tls` | Either, on one port | The first byte decides, using the ranges in RFC 7983: 22 (a TLS handshake record) means TLS; 0–3 means plain TURN. Anything else closes the connection |

On TCP and TLS a framing error, such as an impossible length or wrong first bits, closes the connection, because the stream can't be resynchronised. A ChannelData message with an oversized payload (section 7) is skipped by its length and not relayed. Each TCP or TLS connection holds at most one allocation, which ends when the connection closes.

### 5.2 Allocate

Follow RFC 8656 §7.2 in its order, with these choices:

1. Authenticate (3.3). This is where expiry and the longest credential are checked.
2. If the 5-tuple already has an allocation: 437, unless the request is a retransmission (5.7).
3. REQUESTED-TRANSPORT missing or malformed: 400. Not 17 (UDP): 442.
4. DONT-FRAGMENT present: Node's `dgram` can't set the DF bit, so answer 420 listing DONT-FRAGMENT. RFC 8656 lets the client retry without it. Acceptance check 12.2 confirms that browsers do.
5. RESERVATION-TOKEN: the relay never issues tokens, so any token is invalid: 508 (or 400 when combined with EVEN-PORT or an address-family attribute). EVEN-PORT with the R bit set: 508, since the relay doesn't reserve ports. EVEN-PORT without it: give an even port.
6. Both REQUESTED-ADDRESS-FAMILY and ADDITIONAL-ADDRESS-FAMILY: 400. ADDITIONAL-ADDRESS-FAMILY with the IPv4 value: 400.
7. Address family. IPv4 by default. IPv6 is available only when `RELAY_EXTERNAL_IPV6` is set. Without it, REQUESTED-ADDRESS-FAMILY for IPv6 gets 440, and ADDITIONAL-ADDRESS-FAMILY gets an IPv4-only success response with ADDRESS-ERROR-CODE 440.
8. Quotas (section 7). The credential's quota is used up: 486. The per-address cap or the total is reached, or no relayed port is free: 508.
9. Pick a random free port between `RELAY_PORT_MIN` and `RELAY_PORT_MAX` (random, not in sequence: RFC 8656 §21.1.7). Bind a `dgram` socket to it on `RELAY_LISTEN_IP`, and on `RELAY_LISTEN_IPV6` too for a dual allocation.
10. Lifetime: the lesser of the LIFETIME asked for and `RELAY_MAX_LIFETIME_SECONDS` (default and ceiling 3,600). If that is under 600, use 600. Then cut it to the time left before the credential's `expiry` plus grace, and report that value.
11. Success response: XOR-RELAYED-ADDRESS (`RELAY_EXTERNAL_IP` and the port, plus the IPv6 address for a dual allocation), XOR-MAPPED-ADDRESS (the client's address, taken from the PROXY header when there is one), LIFETIME and MESSAGE-INTEGRITY, plus FINGERPRINT if the request had one.

The allocation remembers its 5-tuple, username, class, opaque-id, key, secret slot, credential expiry, relayed socket or sockets, time to expiry, permissions, channels, and byte and packet counters.

### 5.3 Refresh

- Authenticated as in 3.3. A different username gets 441.
- REQUESTED-ADDRESS-FAMILY different from the allocation's family: 443.
- LIFETIME 0 deletes the allocation at once, and the answer carries LIFETIME 0.
- Any other LIFETIME sets the new time to expiry as in 5.2 step 10, counted from now.
- Once the credential's expiry plus grace has passed, the allocation is gone, so the answer is 437.

### 5.4 CreatePermission

- One to 16 XOR-PEER-ADDRESS attributes; none, or more than 16: 400.
- Any address of the wrong family: 443. Any address refused by section 6: 403. Either way, none of the request's permissions is installed or refreshed.
- Ports in the request are ignored, because permissions are per IP (RFC 8656 §9).
- A permission lasts 300 s. A later CreatePermission or ChannelBind for the same IP refreshes it. Send indications and relayed traffic never do.
- More than `RELAY_MAX_PERMISSIONS` (default 64) IPs on one allocation: 508.

### 5.5 ChannelBind

- CHANNEL-NUMBER from 0x4000 to 0x4FFF and one XOR-PEER-ADDRESS are required, otherwise 400.
- A number already bound to another peer, or a peer already bound to another number: 400. After a binding expires, its number and its peer stay reserved for each other for 300 s, since RFC 8656 §12 has the client wait 5 minutes before reusing them.
- A peer address or port refused by section 6: 403. A family mismatch: 443.
- A binding lasts 600 s and is refreshed by the same ChannelBind. ChannelBind also installs or refreshes the permission for the peer's IP.
- More than `RELAY_MAX_CHANNELS` (default 64) channels: 508.

### 5.6 Data

- **Client to peer.** A Send indication (XOR-PEER-ADDRESS and DATA) or ChannelData on a bound channel is relayed only if all of these hold: the allocation exists; a current permission covers the peer IP; section 6 still allows the peer address and port (the settings can change on reload); the payload is at most 1,500 bytes; and the bandwidth buckets allow it (section 7). Otherwise it is dropped without an answer, since indications never get one. A Send indication carrying DONT-FRAGMENT is dropped, because that attribute is unknown and comprehension-required for this relay.
- **Peer to client.** A datagram arriving on a relayed port is relayed only if all of these hold: section 6 allows its source address and port; a current permission covers the source IP; and the buckets allow it. It goes to the client as ChannelData when a channel is bound to that exact source address and port, and otherwise as a Data indication (XOR-PEER-ADDRESS and DATA). Otherwise it is dropped.
- **Relay to itself.** Sometimes the peer address is one of this relay's own relayed addresses (6.3). The datagram is then handed straight to the allocation that owns that port, inside the process, as if it had arrived from the sender's relayed address. Nothing goes through the network. If no allocation owns the port, or the receiving allocation has no permission for the sender's relayed IP, the datagram is dropped. This is the usual path when both callers are on strict networks, and the only path for CCTV and remote support. It also avoids hairpin problems behind a cloud's 1:1 NAT.
- **TCP and TLS back-pressure.** When a client connection already has more than 256 KiB waiting to be written, relayed data for it is dropped rather than queued, as UDP would lose it. Responses to requests are always queued; if they can't be, the connection closes.
- Node can't receive ICMP, so the relay never sends the ICMP attribute. It doesn't change the TTL or DSCP of relayed packets.

### 5.7 Retransmissions

Over UDP, clients resend requests that seem lost. The relay keeps its response to each authenticated request for 40 s, keyed by the 5-tuple and the transaction ID (RFC 8489 §6.3.1), and sends the same response again for a repeat. This matters most for Allocate, which must keep the same relayed port, and for Refresh with LIFETIME 0. The cache holds at most `RELAY_MAX_ALLOCATIONS` × 4 entries, dropping the oldest first. Unauthenticated 401 replies aren't cached; a repeat gets an equivalent 401.

### 5.8 Lifetimes

| Item | Lifetime | Refreshed by | Ends early when |
|---|---|---|---|
| Allocation | 600 s to `RELAY_MAX_LIFETIME_SECONDS` (at most 3,600) | Refresh | Refresh with LIFETIME 0; the TCP or TLS connection closes; the credential's expiry plus grace passes; its secret is removed; its id is denied; shutdown |
| Permission | 300 s (fixed by RFC 8656) | CreatePermission, ChannelBind | The allocation ends |
| Channel | 600 s, then reserved for 300 s | ChannelBind | The allocation ends |
| Nonce | `RELAY_NONCE_SECONDS` (600 s) | — | Restart |
| Response cache entry | 40 s | — | The cache is full |
| Wait for the PROXY header | 5 s | — | — |
| TLS handshake | 10 s | — | — |
| TCP or TLS connection without an allocation | 30 s from connecting | — | — |

## 6. Where the relay may send (peer rules)

One module, `relay/lib/peers.mjs`, makes one decision: may this allocation, of this class, exchange datagrams with address A and port P? Every CreatePermission, ChannelBind, Send indication, ChannelData message and incoming datagram goes through it. It may use `net.BlockList` or its own CIDR code. IPv4-mapped IPv6 addresses are refused outright, so no address is ever checked in two forms.

### 6.1 Always refused

These can never be relayed to or from, whatever any allowlist says. The relay refuses to start if an allowlist overlaps them.

| Range | What it is |
|---|---|
| 0.0.0.0/8, ::/128 | Unspecified, "this network" |
| 127.0.0.0/8, ::1/128 | Loopback |
| 169.254.0.0/16, fe80::/10 | Link-local, including the cloud metadata address 169.254.169.254 |
| 224.0.0.0/4, ff00::/8 | Multicast |
| 240.0.0.0/4, 255.255.255.255/32 | Reserved and broadcast |
| ::ffff:0:0/96 | IPv4-mapped IPv6 |
| 2001::/32, 2002::/16, 192.88.99.0/24 | Teredo and 6to4 (RFC 8656 §21.4: MUST NOT) |
| 64:ff9b::/96, 64:ff9b:1::/48 | NAT64, which carries IPv4 addresses that could dodge these rules |
| The relay's own addresses, at any port outside the relayed range | Its listeners (3478, 443, 5349), the health port, SSH and anything else on the host |
| Ports 0–1023, 3478 and 5349, plus `RELAY_DENIED_PEER_PORTS` (default 1900, 3702, 5353, 5355, 11211) | System services; other STUN and TURN servers, which would allow TURN loops (RFC 8656 §21.1.7); and well-known UDP amplifiers |

Port rules apply to the destinations of ChannelBind, Send and ChannelData, and to the sources of incoming datagrams. CreatePermission ignores ports.

### 6.2 Refused unless an allowlist names them

| Range | What it is |
|---|---|
| 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 | Private (RFC 1918) |
| 100.64.0.0/10 | Carrier-grade NAT shared space |
| fc00::/7, fec0::/10 | Unique-local and old site-local IPv6 |
| 192.0.0.0/24, 2001::/23 (outside Teredo) | IETF protocol assignments |
| 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32, 3fff::/20 | Documentation |
| 198.18.0.0/15, 100::/64 | Benchmarking, discard |

### 6.3 Peer policy per class

- **The own relayed pool** is this relay's external and bound addresses (`RELAY_EXTERNAL_IP`, `RELAY_LISTEN_IP` and the IPv6 pair), with a port inside `RELAY_PORT_MIN`–`MAX`. Every class may use it, and the relay delivers to it internally (5.6). It is what `CCTV_TURN_RELAY_CIDRS` and the CCTV port range describe, so those settings must cover the relay's external address and equal its port range.
- **`public`** (the Chat default) allows global unicast addresses that aren't in 6.1 or 6.2, the own relayed pool, and `RELAY_CHAT_ALLOWED_PEER_CIDRS`.
- **`relay-only`** (the CCTV and remote-support default) allows the own relayed pool and `RELAY_<CLASS>_ALLOWED_PEER_CIDRS` only. When there is more than one Oblivion Relay node, use the allowlist for the other nodes' pools.
- The setting is `RELAY_<CLASS>_PEERS=public|relay-only`.
- **Allowlists** (`RELAY_<CLASS>_ALLOWED_PEER_CIDRS`) are comma-separated literal CIDRs, at most 8 of them. IPv4 prefixes must be /24 or narrower and IPv6 prefixes /120 or narrower, the same bounds as `CCTV_TURN_RELAY_CIDRS`. None may overlap 6.1. They are empty by default. An allowlist opens a range only for the class that names it.

Every allocation on one relay shares the same external IP. Because permissions are per IP, a permission for that IP admits datagrams from any allocation on the relay. That is how TURN works whenever two callers share a relay, and it is safe: relayed ports are random, media is DTLS-SRTP encrypted, and each browser checks its peer's DTLS fingerprint.

### 6.4 Client addresses

Requests from unspecified, multicast, broadcast, Teredo, 6to4 or IPv4-mapped addresses are dropped, as are loopback addresses except on a loopback listener (RFC 8656 §21.4). Private and CGNAT client addresses are accepted.

## 7. Limits

| Limit | Setting | Default | When exceeded |
|---|---|---|---|
| Allocations per opaque-id | `RELAY_<CLASS>_MAX_ALLOCATIONS` | chat 48, cctv 16, remote 8 | 486 |
| Allocations per client IP | `RELAY_MAX_ALLOCATIONS_PER_IP` | 500 | 508 |
| Allocations in total | `RELAY_MAX_ALLOCATIONS` | 4,000, and no more than the relayed port range holds | 508 |
| Permissions per allocation | `RELAY_MAX_PERMISSIONS` | 64 | 508 |
| Channels per allocation | `RELAY_MAX_CHANNELS` | 64 | 508 |
| Peer addresses per CreatePermission | fixed | 16 | 400 |
| Relayed payload | fixed | 1,500 bytes | Dropped |
| Bandwidth per allocation, each direction | `RELAY_<CLASS>_ALLOCATION_KBPS` | chat 4,000, cctv 6,000, remote 8,000 | Excess dropped |
| Bandwidth per opaque-id, each direction | `RELAY_<CLASS>_ID_KBPS` | chat 16,000, cctv 12,000, remote 16,000 | Excess dropped |
| Bandwidth in total, each direction | `RELAY_TOTAL_MBPS` | 200 | Excess dropped |
| Requests per allocation | fixed | 50 a second | Dropped |
| Unauthenticated replies (401, Binding, and 400 or 420 before authentication) per client IP | `RELAY_UNAUTH_PER_IP_PER_SECOND` | 10 a second, burst 20 | Dropped without an answer |
| Unauthenticated replies in total | `RELAY_UNAUTH_PER_SECOND` | 2,000 a second | Dropped without an answer |
| Failed authentications per client IP | `RELAY_AUTH_FAILURES_PER_MINUTE` | 20 | Everything from that IP dropped for 60 s |
| TCP and TLS connections per client IP | `RELAY_MAX_CONNECTIONS_PER_IP` | 256 | New connection closed |
| TCP and TLS connections in total | `RELAY_MAX_CONNECTIONS` | 8,000 | New connection closed |
| Data waiting to be written, per TCP or TLS connection | fixed | 256 KiB | Relayed data dropped |
| Data received but not yet parsed, per TCP or TLS connection | fixed | 64 KiB | Connection closed |

**Why these numbers.** A person in an 8-person voice call holds 7 peer connections. With three relay URLs (UDP, TCP and TLS) and up to two network interfaces, the browser may make 42 allocations, so the Chat quota is 48. In a 4-person video call each peer connection carries roughly 0.5–2.5 Mbit/s in each direction, so 4 Mbit/s per allocation leaves room for screen share. Care-home and hospital networks put many staff behind one public address, so the per-address caps are high and the per-credential quota does the real work, as RFC 8656 recommends ("based on the username ... and not on the client's transport address"). Bandwidth is counted on relayed payload bytes, using token buckets that hold half a second of traffic.

**Reflection and amplification.**

- An unauthenticated request gets at most one reply. That reply is never more than 4 times the size of the request; when it would be, the request is dropped. With a realm of 32 characters or fewer, a minimal Allocate (28 bytes) gets a 401 of at most 112 bytes, and a 20-byte Binding request gets a 32-byte answer (44 bytes for IPv6).
- Relayed data only ever goes to an allocation's own 5-tuple, which proved itself by answering the nonce challenge (4.3), or to a peer that the authenticated client asked for and section 6 allows.
- Peer ports exclude the usual UDP amplifiers and other STUN and TURN servers (6.1).
- Requests from a source that has failed authentication too often are dropped before any work is done.

## 8. TLS and the PROXY protocol

**TLS** (RFC 8446 for 1.3, RFC 5246 for 1.2):

- Uses `node:tls` with `minVersion: 'TLSv1.2'`, and TLS 1.3 when the client offers it. TLS 1.2 is limited to ECDHE key exchange with AES-GCM or ChaCha20-Poly1305. SSL 3, TLS 1.0 and TLS 1.1 are refused, and renegotiation is off.
- The certificate chain and key come from `RELAY_TLS_CERT_FILE` and `RELAY_TLS_KEY_FILE` (PEM), for the relay's DNS name, for example `turn.<domain>`. SIGHUP re-reads them through `server.setSecureContext()`, and existing connections carry on. Start-up fails if a TLS listener has no readable certificate.
- The relay ends TLS itself in both deployment shapes. nginx only reads the SNI name to route the connection, as `deploy/chat-calling/gateway.nginx.conf.example` does today.
- No client certificates. The handshake must finish within 10 s.

**PROXY protocol.** nginx's stream module sends version 1 when `proxy_protocol on;` is set.

- A listener marked `?proxy=required` expects one line before any other byte: `PROXY TCP4 <source IP> <destination IP> <source port> <destination port>\r\n`, or `TCP6` for IPv6. The line is at most 107 bytes and must arrive within 5 s. The addresses are literal IPs and the ports are decimal numbers from 1 to 65535.
- The header is accepted only from a TCP peer listed in `RELAY_TRUSTED_PROXIES` (default `127.0.0.1/32,::1/128`). The connection closes without a reply when there is no header, when the header is malformed, says `PROXY UNKNOWN` or uses the binary version 2 form, when it comes from an untrusted address, or when a second header follows.
- The source address and port in the header become the client's address for XOR-MAPPED-ADDRESS, nonces, the client address rules (6.4), the per-address limits and the logs. The destination fields are ignored. The allocation's 5-tuple is still the TCP connection itself.
- On a `tcp+tls` listener the PROXY header comes first, and the first-byte check applies to what follows it.
- On a listener without `?proxy=required`, a connection that starts with `PROXY` is closed, since it is neither STUN nor TLS. The relay never accepts a PROXY header over UDP.

## 9. Logs, health and metrics

**Logs.** Each entry is one JSON object per line, with the fields `time`, `level` and `event`, and where they apply:

- `class`;
- `id`: the first 12 hex characters of SHA-256 of the opaque-id, so lines about one credential can be grouped without logging the id itself;
- `client_ip` and `client_port`;
- `transport` and `relay_port`;
- `lifetime` and `duration_s`;
- `bytes_in`, `bytes_out`, `packets_in` and `packets_out`;
- `reason`.

There are two streams:

- **The operations log** goes to stdout, which journald stores. It holds `start`, `reload`, `listener_error`, `config_error`, `capacity`, the per-minute refusal summary and control-channel failures. It never carries an IP address, an id or an id hash, so it can follow the host's usual journald retention.
- **The allocation log** goes to the relay's own files in `RELAY_LOG_DIR` (default `/var/log/oblivion-relay`), one file per UTC day, named `allocations-YYYY-MM-DD.jsonl`. RFC 8656 §21.3.2 suggests operators may log "the actual source IP and port of the client" so that abuse relayed through a TURN server can be traced back to its source. The user chose that on 1 November 2026. Every `allocation_start` and `allocation_end` line carries the client IP and port, taken from the PROXY header behind nginx. Security events (`auth_limit`, `rate_limit`, deny-list hits, connection limits, and control-channel authentication failures) and `revoked` lines go here too, with the client or source IP where there is one.

**Retention.**

- At start and every hour, the relay deletes allocation-log files older than `RELAY_LOG_RETENTION_DAYS`.
- The default is 14 days, which the user chose on 1 November 2026. The allowed range is 1–90; any other value stops start-up.
- Exclude `RELAY_LOG_DIR` from host backups, or keep backups of it no longer than the same limit.
- The files aren't forwarded to other systems unless the destination keeps the same retention and access limits.

**Who can read the allocation log.**

- The directory is owned by `oblivion-relay:oblivion-relay-logs` with mode 2750, so new files inherit the group. The relay writes files with mode 0640, under `UMask=0027`.
- Only root, the relay itself and members of the `oblivion-relay-logs` group can read it. The group is limited to the people who investigate security incidents. They are named in `oblivion-relay.md`, and their membership is agreed with the organisation's privacy officer. The app's user (`www-data`) is not a member.
- The log is read only to investigate abuse or a security incident.
- Someone with access to the app can recompute a person's opaque id for a call and match it to the id hash. The log is therefore personal information, which is why access is kept this tight.

**Peers aren't logged.** Peer addresses are only counted. RFC 8656 §21.3.2 also mentions logging every permission a client installs. Oblivion doesn't, because a list of peers records who called whom, and the client's own address is what the RFC says tracing needs.

The following are never logged in either stream, at any level:

- secrets, passwords and long-term keys;
- MESSAGE-INTEGRITY and FINGERPRINT values, and nonces;
- full usernames and opaque-ids;
- SOFTWARE values;
- payloads, or any part of a relayed packet;
- the raw bytes of a message that failed to parse (log its kind and length only).

The events are `start`, `reload`, `listener_error`, `allocation_start`, `allocation_end`, `auth_limit`, `rate_limit`, `capacity`, `config_error` and `revoked`. `allocation_end` gives a reason: `refresh_zero`, `lifetime`, `connection_closed`, `credential_grace`, `secret_removed`, `denied`, `revoked` or `shutdown`. Some refusals are normal ICE behaviour: 403 for private peers that browsers try, 438, and single 401s. They are counted in the metrics, with at most one summary line a minute in the log.

**Health and metrics.** The endpoint listens on `RELAY_HEALTH_LISTEN` (default `127.0.0.1:9478`) using `node:http`. The relay refuses to start if that address isn't loopback. It has no authentication, because it shows nothing secret. It answers GET only, and 404 for any other path.

- `GET /health` returns 200 with `{status, version, uptime_s, listeners: [{transport, port, ok}], classes: {chat: {enabled, previous_secret}, cctv: {…}, remote: {…}}, allocations: {chat, cctv, remote}, capacity: {allocations_max, ports_free}, tls: {not_after}}`.
  - `status` is `ok`, or `degraded` when the certificate expires within 14 days or fewer than 10% of relayed ports are free.
  - It returns 503 with `status: "down"` when a listener has failed.
- `GET /metrics` returns JSON counters since start:
  - allocations created and ended, by reason;
  - requests by method and result code;
  - relayed packets and bytes, by class and direction;
  - drops by reason (`no_permission`, `peer_refused`, `bandwidth`, `size`, `back_pressure`, `rate_limit`);
  - unauthenticated replies sent and dropped.

  It holds no addresses, ids or usernames.

**In the app.** Settings › Chat › Service health (`ChatServiceHealth`) gains a relay row. When the relay runs on the app server, the app reads `/health` on localhost. When the relay has its own server, its health port stays on that server's localhost, and the app reads the signed `GET /control/status` over the control channel (3.7) instead. In both shapes the app also checks the public side:

- it sends one unauthenticated Allocate over UDP to the first `turn:` URL and expects a 401, which works whether Binding is on or off;
- it opens a TLS connection to the `turns:` URL and checks the certificate's expiry.

Both probes are written in PHP with its built-in sockets. The same row shows how many revocations are waiting (3.7).

## 10. Runtime, settings and deployment

### 10.1 The program

- `relay/server.mjs` is the entry point: it reads the settings, starts the listeners and handles signals. The modules are in `relay/lib/`, and the tests in `relay/test/`. Suggested module names: `stun.mjs`, `integrity.mjs`, `credentials.mjs`, `nonce.mjs`, `allocations.mjs`, `peers.mjs`, `limits.mjs`, `listeners.mjs`, `proxy.mjs`, `health.mjs`, `log.mjs` and `config.mjs`. The builder may change these.
- Built-in modules only: `node:dgram`, `node:net`, `node:tls`, `node:crypto`, `node:http` (health and the control channel only), `node:fs`, `node:zlib` (CRC-32 only), `node:os` (interface addresses), `node:timers` and `node:events`. No npm packages. No imports from outside `relay/`, so `relay/` can be copied on its own to a small server.
- `package.json` gets `"test:relay": "node --test \"relay/test/*.test.mjs\""`, next to `test:live`.
- At start the relay checks that MD5 and HMAC-SHA1 are available. A FIPS-only OpenSSL build lacks MD5, and the relay refuses to start on one.
- Every setting is checked at start. A wrong value stops start-up with a message that names the setting. For secrets, the message never includes the value.
- Signals:
  - SIGHUP re-reads secret files (including the control secrets), the certificate and the deny list without dropping allocations.
  - SIGTERM stops accepting connections, ends allocations and exits within 5 s.
- Each secret can be given as a value (`RELAY_CHAT_SECRET`) or as a file path (`RELAY_CHAT_SECRET_FILE`). Files are preferred, owned `root:oblivion-relay` with mode 0640. Setting both forms is a start-up error. Only secrets given as files change on SIGHUP. Changing a value in the environment needs a restart, and a restart drops calls.

### 10.2 Settings

| Setting | Default | Meaning |
|---|---|---|
| `RELAY_REALM` | required | The REALM. Use the relay's DNS name, for example `turn.example.org`. At most 32 characters, to keep 401 replies small |
| `RELAY_LISTEN` | `udp://0.0.0.0:3478,tcp://0.0.0.0:3478,tcp+tls://0.0.0.0:443` | Comma-separated listeners: `udp`, `tcp`, `tls` or `tcp+tls`, then `://host:port`, then optionally `?proxy=required` |
| `RELAY_LISTEN_IP` | `0.0.0.0` | The IPv4 address relayed sockets bind to |
| `RELAY_EXTERNAL_IP` | required | The public IPv4 address given in XOR-RELAYED-ADDRESS. It is the bound address when the host's public address is on an interface, or the 1:1 NAT address in a cloud |
| `RELAY_LISTEN_IPV6`, `RELAY_EXTERNAL_IPV6` | empty | IPv6 relaying is on when both are set |
| `RELAY_PORT_MIN`, `RELAY_PORT_MAX` | 49152, 65535 | The relayed port range, starting at 1024 or above. It must equal `CCTV_TURN_RELAY_PORT_MIN`/`MAX` when CCTV uses the relay |
| `RELAY_TLS_CERT_FILE`, `RELAY_TLS_KEY_FILE` | required with a TLS listener | PEM full chain and key |
| `RELAY_TRUSTED_PROXIES` | `127.0.0.1/32,::1/128` | Who may send a PROXY header |
| `RELAY_CHAT_SECRET[_FILE]`, `RELAY_CHAT_PREVIOUS_SECRET[_FILE]` | empty, so the class is off | Chat secrets (3.2) |
| `RELAY_CCTV_…`, `RELAY_REMOTE_…` | the same | CCTV and remote-support secrets |
| `RELAY_<CLASS>_MAX_CREDENTIAL_SECONDS` | chat 3600, cctv 3600, remote 7200 | 3.2 |
| `RELAY_<CLASS>_GRACE_SECONDS` | chat 14400, cctv 0, remote 0 | 3.2 |
| `RELAY_<CLASS>_PEERS` | chat `public`, cctv `relay-only`, remote `relay-only` | 6.3 |
| `RELAY_<CLASS>_ALLOWED_PEER_CIDRS` | empty | 6.3 |
| `RELAY_<CLASS>_MAX_ALLOCATIONS`, `RELAY_<CLASS>_ALLOCATION_KBPS`, `RELAY_<CLASS>_ID_KBPS` | section 7 | Per-class limits |
| `RELAY_MAX_ALLOCATIONS`, `RELAY_MAX_ALLOCATIONS_PER_IP`, `RELAY_MAX_PERMISSIONS`, `RELAY_MAX_CHANNELS`, `RELAY_TOTAL_MBPS`, `RELAY_MAX_CONNECTIONS`, `RELAY_MAX_CONNECTIONS_PER_IP`, `RELAY_UNAUTH_PER_IP_PER_SECOND`, `RELAY_UNAUTH_PER_SECOND`, `RELAY_AUTH_FAILURES_PER_MINUTE` | section 7 | Relay-wide limits |
| `RELAY_DENIED_PEER_PORTS` | `1900,3702,5353,5355,11211` | Added to the ports in 6.1 that are always refused |
| `RELAY_MAX_LIFETIME_SECONDS` | 3600 | The longest allocation lifetime per Refresh, from 600 to 3600 |
| `RELAY_NONCE_SECONDS` | 600 | 4.3 |
| `RELAY_CLOCK_SKEW_SECONDS` | 30 | 3.3 |
| `RELAY_STUN_BINDING` | `on` | 4.4 |
| `RELAY_HEALTH_LISTEN` | `127.0.0.1:9478` | Loopback only |
| `RELAY_LOG_LEVEL` | `info` | `error`, `warn`, `info` or `debug`. Debug adds nothing secret |
| `RELAY_LOG_DIR` | `/var/log/oblivion-relay` | The allocation log (section 9) |
| `RELAY_LOG_RETENTION_DAYS` | 14 | Days the allocation log is kept, 1–90 |
| `RELAY_DENY_FILE` | empty | 3.5 |
| `RELAY_CONTROL_LISTEN` | `127.0.0.1:9479` | The control channel (3.7). Loopback or private address only |
| `RELAY_CONTROL_ALLOWED_SOURCES` | `127.0.0.1/32,::1/128` | Who may call the control channel. Loopback and private CIDRs only |
| `RELAY_CONTROL_SECRET[_FILE]`, `RELAY_CONTROL_KEY_ID` | required when `chat` is on | The control secret and its key id |
| `RELAY_CONTROL_PREVIOUS_SECRET[_FILE]`, `RELAY_CONTROL_PREVIOUS_KEY_ID` | empty | Set only during rotation |
| `RELAY_STATE_DIR` | `/var/lib/oblivion-relay` | Holds `revocations.json` (3.7) |

### 10.3 Shape A: its own small server

- A small virtual machine with a public IPv4 address, and optionally IPv6. DNS points `turn.<domain>` at it. It runs no app code: copy only `relay/`.
- The default listeners are UDP 3478, TCP 3478 and `tcp+tls` on 443. Port 443 carries both TURN over TLS and plain TURN over TCP (the first-byte check), so `turn:turn.<domain>:443?transport=tcp` also works on networks that allow port 443 without checking for TLS.
- **Open inbound:** UDP 3478, TCP 3478, TCP 443 and UDP `RELAY_PORT_MIN`–`RELAY_PORT_MAX`. Open SSH from the admin addresses only, and nothing else. The health port stays on localhost.
- **The control channel** listens on the relay's private address (a cloud private network or a WireGuard tunnel to the app server), and the firewall allows TCP 9479 from the app server's private address only. Where there's no private network, use an SSH or WireGuard tunnel to loopback.
- **Outbound:** UDP from the relayed range to anywhere (the peers), plus DNS, NTP and package updates as usual.
- To bind 443 as a non-root user, the service needs `CAP_NET_BIND_SERVICE`.
- **Certificate:** Let's Encrypt, with an HTTP-01 challenge (TCP 80 open briefly) or a DNS-01 challenge. The renewal hook sends SIGHUP.
- **App URLs:** `turn:turn.<domain>:3478?transport=udp`, `turn:turn.<domain>:3478?transport=tcp` and `turns:turn.<domain>:443?transport=tcp`.

### 10.4 Shape B: beside nginx on the app server

- nginx keeps TCP 443. It routes the `turn.<domain>` SNI name to the relay with the PROXY protocol, as `gateway.nginx.conf.example` already does (`turn.example.invalid 127.0.0.1:5349;` with `proxy_protocol on;`).
- Set `RELAY_LISTEN=udp://0.0.0.0:3478,tls://127.0.0.1:5349?proxy=required`, and add `tcp://0.0.0.0:3478` if TCP 3478 is opened.
- **Open inbound, in addition:** UDP 3478, TCP 3478 if used, and the relayed UDP range. Choose a narrower range here, for example 50000–53999, so that less of the app server is open. `RELAY_MAX_ALLOCATIONS` must fit inside it.
- Plain TURN over TCP on 443 isn't possible in this shape, because nginx needs TLS's SNI name to route. TURN over TLS on 443 covers strict networks.
- The peer rules refuse the app server's own addresses and private networks, so the relay can't reach MySQL, Redis or anything else on the host or its private network.
- The control channel stays on the default `127.0.0.1:9479`, and the app uses `CHAT_RELAY_CONTROL_URLS=http://127.0.0.1:9479`.
- **App URLs:** `turn:turn.<domain>:3478?transport=udp` and `turns:turn.<domain>:443?transport=tcp`, plus TCP 3478 if opened.
- **While LiveKit still runs** (until C4.10), LiveKit's built-in TURN owns `turn.<domain>`, `127.0.0.1:5349` and UDP 3478. Until LiveKit is removed, give the relay its own name (for example `relay.<domain>`), loopback port (`127.0.0.1:5350`) and UDP port (3479), with its own SNI line in nginx. Move it over afterwards.

### 10.5 Files the builder writes in `deploy/chat-calling/`

- **`oblivion-relay.env.example`**: every setting from 10.2 with a one-line comment. Secrets are left empty and the `_FILE` form is shown. It includes both shapes as commented blocks, and the matching app `.env` keys: the new `CHAT_RELAY_URLS`, `CHAT_RELAY_SECRET`, `CHAT_RELAY_CREDENTIAL_SECONDS`, `CHAT_RELAY_CONTROL_URLS`, `CHAT_RELAY_CONTROL_SECRET` and `CHAT_RELAY_CONTROL_KEY_ID`, and the existing `CCTV_TURN_*` and `ENDPOINT_REMOTE_RELAY_*`.
- **`oblivion-relay.service.example`**: shaped like `oblivion-live.service.example`, with these additions:
  - its own user, `oblivion-relay`, not `www-data`, because it may share the app server;
  - `EnvironmentFile=/etc/oblivion/relay.env` and `ExecStart=/usr/bin/node relay/server.mjs`;
  - `ExecReload=/bin/kill -HUP $MAINPID`;
  - `AmbientCapabilities=CAP_NET_BIND_SERVICE` and `CapabilityBoundingSet=CAP_NET_BIND_SERVICE`, needed only for 443 in shape A;
  - `RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX AF_NETLINK` (`AF_NETLINK` is needed to read interface addresses);
  - `PrivateDevices=true`, `MemoryMax=1G` and `LimitNOFILE=65536`, alongside Oblivion Live's hardening lines;
  - `StateDirectory=oblivion-relay` for the revocation state, `ReadWritePaths=/var/log/oblivion-relay` and `UMask=0027`. The install steps create the log directory as `oblivion-relay:oblivion-relay-logs` with mode 2750 (section 9).
- **`oblivion-relay.md`** covers:
  - the two shapes, the ports and certificates;
  - the rotation steps for credential secrets (3.4) and the control secret (3.7);
  - setting up the control channel's private network or tunnel;
  - the allocation log's retention, who is in `oblivion-relay-logs` and how membership is agreed;
  - the checks in section 12.

## 11. Tests the builder writes

The tests use `node --test` in `relay/test/`. They use a fake clock for lifetimes and buckets, and real sockets on 127.0.0.1 for the flows.

1. **STUN codec and RFC 5769.**
   - All four vectors decode to the values the RFC lists: the request (2.1), the IPv4 response with 192.0.2.1 port 32853 (2.2), the IPv6 response (2.3), and the long-term request (2.4).
   - The encoder reproduces each one byte for byte when given the same attributes, the same order and the vector's padding bytes.
   - MESSAGE-INTEGRITY checks out with the short-term password `VOkJxbRl1RmTxUk/WvJxBt` for 2.1–2.3. For 2.4 it checks out with the long-term key built from the RFC's username, its post-SASLprep password `TheMatrIX` and realm `example.org`. The relay itself doesn't implement SASLprep (3.1).
   - FINGERPRINT checks out for 2.1–2.3.
2. **Integrity and fingerprint.**
   - A wrong key fails, and so does a single flipped bit anywhere before the attribute.
   - Attributes after MESSAGE-INTEGRITY, other than FINGERPRINT, are ignored.
   - MESSAGE-INTEGRITY-SHA256 is accepted.
   - A wrong FINGERPRINT means the message is dropped.
   - The known-answer vector in 3.1 gives the listed password and key.
3. **Malformed input.**
   - Each of these is dropped without crashing: a truncated header, a wrong cookie, an odd length, an overrunning attribute, a duplicate integrity attribute, an oversized request, a non-STUN datagram, and a TCP stream split at every byte boundary.
   - A randomised fuzz test of datagrams and TCP streams never crashes the process, never creates an allocation without valid credentials and never breaks the 4× reply rule.
4. **Credentials.**
   - Accepted: a valid credential for each class, using the formats the existing CCTV and remote-support issuers produce. Generate one fixture by running each PHP formula once, and paste the results in.
   - Refused with 401:
     - an expired credential (skew allowed at the boundary);
     - one beyond the longest credential;
     - an unknown tag;
     - a class that is switched off;
     - uppercase hex, a missing colon, a leading zero, a non-ASCII byte or more than 128 bytes;
     - a chat username signed with the CCTV secret;
     - a denied id.
5. **Nonces.**
   - The first request gets 401 with REALM and NONCE.
   - A nonce from another address or port gets 438. So does an expired nonce, and a tampered one.
   - Two sources are never given the same nonce.
   - After a restart, old nonces get 438.
6. **Allocate.**
   - A success response carries the right attributes, with the relayed port in range and the external IP.
   - XOR-MAPPED-ADDRESS equals the client's address, or the PROXY address behind nginx.
   - A retransmission within 40 s gets the same response and the same port.
   - A second Allocate on the same 5-tuple gets 437.
   - Missing REQUESTED-TRANSPORT gets 400, and TCP gets 442.
   - DONT-FRAGMENT gets 420 with UNKNOWN-ATTRIBUTES.
   - RESERVATION-TOKEN and EVEN-PORT with R get 508. EVEN-PORT without R gives an even port.
   - IPv6 without IPv6 configured gets 440. A dual request gets ADDRESS-ERROR-CODE.
   - An unknown comprehension-required attribute gets 420.
7. **Refresh.**
   - The lifetime is clamped between 600 and the maximum, and cut to the credential's expiry plus grace.
   - LIFETIME 0 deletes the allocation, and its retransmission gets the same success.
   - Another username gets 441, an address-family mismatch 443, and a request after grace 437.
8. **CreatePermission.**
   - One address works, and so do 16. Seventeen get 400.
   - When one address in a request is forbidden, the request gets 403 and none of its addresses is installed.
   - A wrong family gets 443.
   - With the fake clock, a permission expires at 300 s. A repeated CreatePermission refreshes it, and a Send indication doesn't.
   - Data from a peer without a permission is dropped.
9. **ChannelBind and ChannelData.**
   - Range edges: 0x3FFF and 0x5000 are refused, 0x4000 and 0x4FFF accepted.
   - Each rebinding conflict gets 400.
   - A binding expires at 600 s, and stays reserved for 300 s afterwards.
   - ChannelData over UDP needs no padding. Over TCP it must be padded, and an unpadded stream fails as a framing error.
   - An oversized ChannelData payload over TCP is skipped, and the stream continues.
10. **Peer rules.**
    - One address from every range in 6.1 and 6.2 is refused for CreatePermission and ChannelBind (403), and dropped as a Send destination and as an incoming source.
    - IPv4-mapped, NAT64, Teredo and 6to4 addresses are refused.
    - Each denied port is refused.
    - The own pool inside the range is delivered internally. The relay's own address outside the range (3478, the health port) is refused.
    - An allowlisted CIDR is accepted for its class only.
    - An allowlist overlapping 6.1, or wider than /24 or /120, stops start-up.
    - `relay-only` refuses a public peer.
11. **Limits.**
    - Each row in section 7 is reached and gives the stated result. The per-opaque-id quota gives 486; the per-address cap and the total give 508.
    - With the fake clock, the bandwidth buckets drop the excess and pass traffic under the cap. Drops are counted in the metrics.
    - The unauthenticated-reply limits drop replies without an answer, per address and in total.
    - The failure limit silences an address for 60 s.
12. **Expiry and cleanup.**
    - An allocation ends at its lifetime.
    - With grace 0 an allocation ends at the credential's expiry; with chat grace it ends at expiry plus grace.
    - Closing a TCP or TLS connection deletes its allocation at once.
    - A connection with no allocation closes after 30 s.
    - Ended allocations free their port and socket.
13. **Rotation and reload.**
    - A credential made with the previous secret works.
    - A SIGHUP that swaps in a new current secret keeps existing allocations.
    - A SIGHUP that removes the secret an allocation was made with ends that allocation.
    - A new certificate is served after SIGHUP, while existing TLS connections continue.
    - The deny file takes effect on SIGHUP.
14. **Transports, TLS and PROXY.**
    - TLS 1.2 and 1.3 connect; clients limited to TLS 1.0 or 1.1 fail the handshake.
    - `tcp+tls` serves plain TURN and TLS on one port.
    - PROXY version 1 from a trusted address sets the client address. The connection closes when the header is missing, comes from an untrusted address, is malformed, is over 107 bytes, arrives after 5 s, says `UNKNOWN`, uses version 2, or is repeated.
    - A PROXY line on a listener that doesn't expect one closes the connection.
15. **Health and logs.**
    - The health endpoint binds only to loopback, and a non-loopback setting stops start-up.
    - `/health` and `/metrics` hold the expected fields.
    - A full flow runs with a marker secret, a control secret, a password and a payload marker. The test then searches both log streams, `/health`, `/metrics` and `/control/status`, and finds none of them, and no username or opaque-id.
    - Every `allocation_start` and `allocation_end` line in the allocation log carries the client IP and port (the PROXY address behind nginx).
    - No peer address appears anywhere, and the operations log (stdout) carries no IP address or id hash.
16. **Two peers through the relay on loopback.**
    - Client A (UDP) and client B (TCP, and in a second run TLS through a PROXY-sending test proxy) each allocate.
    - Each creates a permission for the other's relayed address, which is the own pool and so goes by internal delivery.
    - A sends Send indications and B receives Data indications whose XOR-PEER-ADDRESS is A's relayed address. Then both bind channels and exchange ChannelData.
    - 1,000 packets go each way, in order and with every byte intact.
    - When the permission expires the flow stops. When A refreshes with LIFETIME 0, B receives nothing more.
    - A second test covers the network path. It injects a peer policy that allows 127.0.0.1 for that test only, and checks that a plain UDP socket outside the relayed range gets and answers datagrams. `relay/server.mjs` never accepts an injected policy.
17. **Settings.** Every invalid value in 10.2 stops start-up with the setting's name, and never the value of a secret. Some examples:
    - a missing realm, or a range smaller than the allocation total;
    - two classes sharing a secret, or both a secret and its `_FILE` set;
    - a TLS listener without a certificate, or a non-loopback health address;
    - `chat` switched on without a control secret, or a control secret equal to a credential secret;
    - a control listener on a public address, `0.0.0.0` or `::`, or a public CIDR in the allowed sources;
    - a log retention outside 1–90 days, or an unreadable `revocations.json`.
18. **The control channel.**
    - **Signing.** The known-answer vector in 3.7 gives the listed signature. A correct request gets 200, and so does one signed with the previous key. These get 401: a removed previous key, an unknown key id, a missing header, one flipped bit in the body, and a signature for `GET /control/status` replayed on `POST /control/revoke`. Ten failures in a minute silence that source for 60 s.
    - **Replay.** A timestamp 31 s early or late gets 401. The same nonce within 60 s gets 401. The same body with a new nonce gets 200. A full nonce store gets 503.
    - **Sources and limits.** A source outside `RELAY_CONTROL_ALLOWED_SOURCES` is closed without a reply. A body over 64 KiB gets 413. 501 entries get 400. One malformed id among valid ones gets 400 and nothing is applied. An `expires_up_to` too far ahead gets 400.
    - **Effect.** A revoke ends the matching allocations within 1 s, before it answers:
      - for UDP, relayed data stops at once and the next Refresh gets 437;
      - for TCP and TLS, the connection closes;
      - permissions and channels are gone;
      - an allocation in its grace period is ended too;
      - other ids, including the same person's id for another call, carry on.
    - **Refusals while held.** Allocate with a credential whose expiry is at or before `expires_up_to` gets 401. A credential with a later expiry works. With the fake clock, the hold lapses at `expires_up_to` plus the clock allowance. A repeated revoke keeps the later time.
    - **Restart.** Held revocations survive a restart through `revocations.json`.
    - **Logging.** `revoked` is logged with the id hash and count.
19. **The allocation log.**
    - Lines go to one file per UTC day.
    - With the fake clock, files older than `RELAY_LOG_RETENTION_DAYS` are deleted at start and on the hourly sweep, and newer ones are kept.
    - Files are created with mode 0640 and the directory's group.

**App tests (Pest):**

- `ChatRelayCredentials`: the username format, the expiry bounds, one id per person per call, and the known-answer vector in 3.1.
- Missing or invalid settings return no relay entry, and never an outside server.
- The secret never appears in the output, the logs or audit rows.
- The `ChatServiceHealth` relay row covers both the localhost and the control-channel status, the public probes, and the waiting-revocations count.
- `OblivionRelay` signs the control vector in 3.7 exactly. Control URLs that are public, hostnames, `https` or have a path are refused.
- **Hooks.** Each of these sends the person's ids for their live and recent calls, and no one else's:
  - `ChatOffboarding::complete()` and the leaver rule's `ChatMembershipGaps::sync()`, after the commit;
  - a participant becoming `left`, `removed`, `revoked` or `expired` in `ChatCallReconciler`;
  - a call ending, which sends every participant's id;
  - account suspension;
  - `chat:relay-revoke` by hand.
- Each revoke writes one `chat.relay.revoked` audit row.
- **When the relay can't be reached.** The person's action still succeeds without waiting. The queued retry follows 5 s, 15 s, 30 s and then every minute, and stops after `expires_up_to` plus grace. Service health shows the waiting count, and an error is logged after 5 minutes.
- After a revoke, `ChatRelayCredentials` never issues an expiry at or before the sent `expires_up_to`.

**Load test** (`relay/tools/load.mjs`, run by hand, not in CI): many allocations and packet rates through one node, reporting packets a second, loss, added latency and memory, for checking against requirement 10.

## 12. Acceptance checks for later

These run during stage 5 network testing, on the server the user chooses.

1. **Two browsers, relay forced.** Chrome and Firefox, then Safari and Edge, each with `iceTransportPolicy: "relay"`. One is on home broadband and the other on 4G. Run a 10-minute voice call, then a video call. Audio and video flow both ways, `chrome://webrtc-internals` (or the equivalent) shows relay candidates selected, and the call's quality signal stays good.
2. **Strict network.** Repeat check 1 with one laptop's firewall allowing only outgoing TCP 443. The call connects over `turns:` on 443 within 5 seconds. This also confirms that each browser's Allocate succeeds after the 420 for DONT-FRAGMENT, if the browser sends it.
3. **Four-browser video and eight-browser voice.** A full mesh with two people on 4G and two on home networks (eight for voice), relay forced. Every pair connects, no allocation hits a quota, and the relay's metrics show no bandwidth drops under normal use.
4. **Without forcing.** The same calls with the default ICE policy. Most pairs go direct (Binding on), and only strict-network pairs use the relay.
5. **Credential expiry.** A call stays up past its credential's expiry, and ends at expiry plus grace. New allocations with the expired credential are refused, and an ICE restart with fresh credentials works.
6. **nginx shape**, if chosen. `turns:` through nginx's SNI route on 443 with PROXY. XOR-MAPPED-ADDRESS shows the browser's real public address, and the per-address limits use it.
7. **Reload during a call.** A SIGHUP for secret rotation and certificate renewal during check 1 doesn't interrupt it.
8. **Probe from outside.**
   - An unauthenticated Allocate gets 401 and nothing more.
   - With valid credentials, peers at 127.0.0.1, 10.0.0.1, 169.254.169.254, a CGNAT address and the relay's own 3478 and SSH ports are all refused.
   - Measured reply-to-request size stays at or under 4×, and the rate limits hold under a flood from one address.
9. **CCTV and remote support**, when those features are switched on. Relay-only sessions work with their existing credentials, and the relayed addresses fall inside `CCTV_TURN_RELAY_CIDRS`.
10. **Capacity.** On a 2 vCPU, 2 GB node: 1,000 allocations, 150 Mbit/s each way and 30,000 packets a second, with under 0.5% loss, under 5 ms added p99 latency and under 600 MB of memory.
11. **Offboarded during a call.** A test staff member is in a relayed call (relay forced) and is offboarded through Chat offboarding. Their relayed media stops within 5 seconds, they can't rejoin, and the others' call carries on. The allocation log shows `revoked`, and the audit log shows `chat.relay.revoked`.
12. **Control channel down.** Block the control port, then offboard a test person mid-call. Service health shows the waiting revocation and the call continues through the relay. Unblock the port, and the revoke lands within a minute and the media stops.
13. **Log handling.** The allocation log holds the client IPs from checks 1–3, and no peer addresses or credentials. Only `oblivion-relay-logs` members and root can read it. Files older than the retention limit are gone after the hourly sweep.

## 13. Open questions for the user

1. **Where does it run?** Shape A (its own small server) or shape B (beside nginx on the app server). Recommendation: shape A. It keeps an internet-facing UDP service with a wide port range off the host that holds care records, it allows plain TCP on 443, and it can grow on its own. The cost is one more small server. This is needed before stage 5 network testing.
2. **CCTV and remote support.** Should they move to Oblivion Relay at the same time as Chat, or later? Both features are off today and have no relay installed. Moving them also means rewording the Settings › Obli card, which shows a coturn snippet. If they move, their own stop flows (CCTV stop, the remote-support Stop that REMOTE-RELAY.md says "does not erase a previously issued bearer credential") could use the control channel's revoke too.

**Answered by the user on 1 November 2026:**

- **STUN Binding:** on by default, with the limits in section 7 (4.4).
- **Longest Chat call:** five hours (one-hour credentials plus four hours' grace) is fine. There is no extra cap in the app (3.2).
- **Ending relay use at once:** yes, an instant cut-off. Version 1 has the signed control channel and the app hooks (3.7).
- **IP addresses in logs:** keep fuller logs, with the client IP of every allocation, as RFC 8656 suggests operators may. The log has restricted access, and still never holds credentials or content. Peers stay unlogged (section 9).
- **How long to keep the allocation log:** 14 days (`RELAY_LOG_RETENTION_DAYS=14`; the relay allows 1–90). That is long enough for abuse reports, which usually arrive within days, and no longer than needed for the client IPs the log holds (section 9).

## 14. When it lands

- The checker compares `relay/` with the source of coturn, pion/turn and other TURN servers, as README "Checking" describes, keeping that source outside the repository. Once satisfied, the checker marks Oblivion Relay "signed off" in REGISTER.md with the provenance note.
- Add a guard: a test that fails if any file in `relay/` imports anything other than `node:` modules or relative paths inside `relay/`.
- The LiveKit server's TURN role ends with C4.10. The nginx template's `turn.` line then points at the relay.
- No licence notice is needed, since the relay contains no outside code.
- Follow-ups outside Chat, each needing the user's yes:
  - reword `docs/native-endpoint-management/REMOTE-RELAY.md` and the Settings › Obli card (its coturn snippet becomes an Oblivion Relay settings snippet);
  - update the "Coturn" wording in `EndpointRemoteSupportSettings`.
