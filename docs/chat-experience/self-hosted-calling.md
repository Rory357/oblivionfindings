# Self-hosted voice calling

18 October16:52: ringtone ownership now queues behind a browser Web Lock for owner-tab closure handoff without polling. Silence updates reject stale/out-of-order peer snapshots. Final83 UI tests, focused types/lint and real-browser two-tab ownership/silence/handoff checks passed using silent native Web Audio. Existing desktop/narrow incoming card is unchanged. Authenticated cross-device/media acceptance remains open.

18 October16:45: shared browser recovery now persists only account-bound call references and exact start/join/leave/end intent. Web Locks restrict ownership to one tab; reopened pages require explicit checks and reconnect consent. Restored start retries review current recipients, and stopping never turns into reconnecting. Final81 control tests/six files, earlier94 audio regressions, focused types/lint and desktop/narrow real-browser lock/page-close/reopen checks passed with fictional APIs/audio. Full browser-process restart, true authenticated concurrency and live hardware/network/TURN acceptance remain. No deployment, schema or rollout change. See delivery ledger for evidence.

18 October16:04: actual SDK-reported local connection quality is now presented in the shared expanded call and Audio dialog; weak/interrupted reports appear in the compact dock. Unknown is not treated as Good, remote reports are ignored, and reconnect clears earlier quality until a fresh report arrives. Final76 UI regressions plus9 readability checks, focused types/lint and desktop/narrow fictional-event browser verification passed. Live authenticated device/network/TURN and acoustic acceptance remain outstanding. No backend/schema or rollout change. See delivery ledger for evidence.

18 October15:53: outgoing staff/family setup now requires an explicit local microphone check, provides an actual sampled input meter and selected-output tone test, and releases capture within15seconds or on cancellation/hidden page/teardown/call start. Selected audio stays local and survives uncertain start/join recovery; permissions and current authority are rechecked. Final85 UI tests, focused type/lint checks and desktop/narrow synthetic browser checks passed. Physical hardware/hearing, connection quality and real authenticated network/browser/TURN acceptance remain open. No schema, rollout or deployment changes. See delivery ledger for evidence.

18 October15:38: existing capture/device health now stays visible in compact and expanded call controls while Audio settings is closed. Review audio reuses the device dialog and preserves mute; missing devices cannot be dismissed, healthy changes can be acknowledged. No automatic permission, switching or reconnect. Focused73 UI regressions and desktop/narrow fictional-device checks passed; physical headset/browser/network acceptance, preflight and quality metrics remain. No backend/schema/enablement change. See delivery ledger for type-check status and evidence.

18 October15:23: lost answer responses now have explicit same-invitation recovery: readonly Check, separately chosen Try connecting, and Stop with retained intent. Account/invitation/conversation guards precede media use; stalled requests time out after30 seconds with capture released. Server ordered-request checks passed4 tests/55 assertions, and focused UI62/4files passed. Desktop/narrow fictional-component checks verified no automatic join, keyboard recovery and stop persistence. Recovery remains in page memory; restart/cross-tab and true parallel/network/media acceptance remain open. No controller/schema change, migration or enablement. See the delivery ledger for final type status and evidence.

18 October15:08: shared incoming/outgoing controls retain microphone recovery instructions and offer a visible retry without ringing before permission. Normal confirmation no longer appears interrupted while running. Old results clear before answering later calls. A confirmed join followed by audio failure now keeps audio-off cleanup controls until leaving is confirmed, instead of hiding failed cleanup. Focused UI56/4files passed; desktop/narrow fictional-component checks verify controls, draft preservation and keyboard retry. Lost join responses, restart/cross-tab and real hardware/network acceptance remain outstanding. No backend, schema, migration or enablement change in this slice; see the delivery ledger for evidence.

18 October14:56: server-confirmed call-result cards now provide role-appropriate message, callback, Call again and explicit staff-record actions. Current invitation/history/actor access is checked before exposing results; unavailable results do not guess. Cancelled ringing and all-declined outcomes are distinguished. Eight isolated server scenarios passed143 assertions and cleaned up;100 focused interface checks plus a call-again regression passed. Desktop/narrow fictional-component verification preserved the conversation/work drafts, confirmed44px actions and keyboard focus recovery. Outgoing-start rerun80875 passed7 tests/132 assertions, superseding the pending status below. Full authenticated media, real devices/networks, busy/join failures, restart recovery and remaining approved acceptance are still outstanding. No application migration or enablement. See the delivery ledger for final logs and limits.

18 October14:30: outgoing starts now have immutable request receipts and a shared recovery card: Check call, explicit Join existing call, exact-body Retry same call, and Stop my attempt with durable cancellation. Cancellation before creation prevents late ringing; after creation it denies only the original invitation and retries media cleanup through reconciliation. Initial server set passed7 tests/125 assertions; final history-boundary/no-later-invitation cleanup rerun80875 remains pending. Forty-nine UI tests and desktop/narrow fictional browser checks passed. No browser-restart persistence, true concurrent-request certification or integrated physical/network acceptance is claimed. See the authoritative delivery ledger; no application migration or live enablement.

18 October14:15: shared in-call microphone/speaker selection is implemented with mute preservation, current-room/account guards, explicit retry and unsupported-browser guidance. Device/provider/people UI checks passed42 cases; desktop/narrow fictional-device verification passed. Live-people server rerun96369 passed19 cases/258 assertions and its isolated database was cleaned up. These results supersede earlier pending checks below. Physical devices, global device-loss notices, preflight selection, measured call quality, uncertain-start recovery and full integrated/network acceptance remain outstanding. No application migration or enablement. See the delivery ledger for exact evidence and limits.

18 October live-people update: live invite/remove APIs and shared reviews now include current eligibility, device/actor ownership, capacity reservation, durable exact-command retries and rotating invitation identity. Removal commits reconnect denial before media teardown and remains retryable through service failure. Speaking is derived from SDK events for authorised roster identities. Ninety-six focused UI checks and desktop/narrow fictional-audio verification passed. The full backend rerun96369 is pending after correcting a test fake that masked the outage case; see the latest delivery ledger. No application migration or live enablement. Device selection, complete result/recovery flows, true concurrency and real media/gateway/network acceptance remain.

18 October group/session follow-up: the expanded view shows the authorised participant roster and refreshed host, with91 focused UI tests passing and desktop/narrow fictional-data browser evidence. Shared staff-first host succession, family fallback and per-person invitation expiry passed within15 isolated feature tests/195 assertions. Background Chat traffic no longer extends an idle session; expired Chat JSON requests return401 so the provider can stop capture. Middleware/actor unit checks passed32 cases. No application migration or live calling enablement. Live invitations/removals, speaking/device controls and full application/audio acceptance remain open. See the latest delivery ledger for authoritative results.

18 October care-team update: care-team calls now use a separate first-responder mode, backed by a serialized call claim and explicit answered-elsewhere outcomes. Candidate staff must have current client/site authority, available presence and no active DND or other call. Named/group calls remain explicit selections. The schema addition is saved and isolated-test verified only; application migration/enablement remains gated. Ten feature tests passed141 assertions. Calling and voice notes now share a page-local capture lease to avoid accidental overlapping capture; this is not cross-tab/OS enforcement. Ninety focused UI tests passed. Live group invites/removals, result screens, concurrency and integrated audio acceptance remain open.

18 October expanded-call update: the compact shared card now offers Message and Expand. Both reuse the authorised ChatThread in a role-correct call view without navigation; one composer owns the draft/upload memory at a time, and the composer unmounts before the close animation completes. The original page stays mounted. Compact details, Escape, focus return, host-end confirmation and account teardown have focused UI coverage (74 cases across three files). Seven isolated call/metadata tests passed with102 assertions after correcting an impossible duplicate NextOfKin fixture. The entire authenticated/browser/audio acceptance matrix is still incomplete. See IMPLEMENTATION-STATUS.md for evidence and current process results.

The user selected self-hosted calling only on 16 October 2026. The Oblivion interface, identity, invitations, availability, history, access decisions and reviewed call records stay in this application. LiveKit provides the audio transport on infrastructure controlled by the operating organisation. No managed calling account or external deployment has been created. Local synthetic media verification is recorded below; full application and network acceptance remains open.

## Local media checkpoint — 18 October 2026

M25 presentation follow-up: the shared incoming card and call dock now reserve shell workspace and match the approved identity, audience, ring-state, per-invitation silence and answer/decline structure. Caller family/care-team/group context is populated from current authorised server records; conflicting/deleted family relationship labels and revoked identities are withheld. Actual permitted invitees are named rather than implying the whole care team can listen. The new metadata database suite92817 is pending. Earlier call actor/device/policy/history regression67493 passed four cases/73 assertions and cleaned up; latest UI suite passed40 cases. Fictional-data browser proof covers desktop1440/narrow320, long names, keyboard controls, failed-leave retry and draft preservation. It does not replace integrated role/call acceptance. Active-call Message/Expand, group lifecycle, busy/missed/elsewhere states and modal/notification coordination remain incomplete.

Shared provider lifecycle verification also passed separately using the actual UI with fictional signalling/RTC: stale reads/account responses cannot reopen calls, capture stops synchronously on leave/access loss, incoming permission refusal remains retryable, autoplay can be enabled in place, and failed server leave retains a microphone-off retry card. Desktop/narrow controls and keyboard retry preserved the page draft; final browser console checks were clear. These are component lifecycle checks, not end-to-end application audio certification.

Browser call requests now include the actor from the rendered page. `EnsureChatActor` checks it against the authenticated session on all nine call/availability/history/record routes before controller work, returning a private no-store403 on mismatch or omission. The header does not grant permission; normal authorization still applies. Actor changes reset history/summary ownership. Middleware/route tests passed12 cases/79 assertions and focused UI tests passed30 cases; isolated call integration run67493 is pending. The approved M25 family identity/audience presentation and workspace reservation, cancellable permission/device preflight and uncertain-start recovery remain open.

Follow-up: permission-wait cancellation is now implemented and browser-verified. Incoming Decline and outgoing Cancel invalidate preflight; late microphone streams are stopped without call creation/join. Once signalling has begun, that work stays guarded until its outcome is known. Keyboard cancellation and44px targets passed a256px narrow stress viewport. Final focused lifecycle/history/summary/callback suite passed34 tests; call-summary acknowledgements are also ignored after account-owned review teardown. Device selection, M25 fidelity, uncertain-start recovery and integrated/network acceptance remain outstanding. The actual browser permission prompt itself is controlled by the browser and may remain visible after application preflight is cancelled.

The installed browser SDK and pinned Windows server were exercised through the in-app browser at `http://127.0.0.1:8883/audio-loopback.html`, using three fictional identities and Web Audio tones. The engine listened only on loopback (TCP7880, UDP7882). No microphone, speakers, application records, external network or recording was used. This checks actual encoded/decoded media through the engine, not mocked signalling.

The SDK's default single-peer-connection path failed at `RTCPeerConnection.setConfiguration` before joining. Its supported dual-peer-connection mode connected all three participants. The shared `CHAT_VOICE_ROOM_OPTIONS` now selects that mode explicitly, and the fixture imports the same application options. With those options, all six receive paths carried increasing audio bytes and decoded RMS above0.02. Muting A produced zero RMS at B and C while the other four paths remained active; unmute restored all six. When C left, A and B retained bidirectional audio. Final browser warnings/errors were empty.

Measurement snapshots and a screenshot are saved under `storage/app/chat-review/audio-loopback-*.json`, `audio-shared-options-result.json`, and `audio-shared-options.png`. The fixture's receiver attaches a media element, matching the application's attachment path, but mutes physical output; without attachment, transport bytes arrived but decoding did not advance. Energy counters alone were not used as proof of sound. Temporary browser tab, fixture server and the engine process started for this test were stopped afterwards.

This evidence does not certify physical microphones, audible speaker quality, separate browser instances, supported-browser coverage, TURN/TCP fallback, reconnect, authenticated call invitations/gateway/webhooks, or application lifecycle. No calling/rollout flags, application database, DNS, firewall or production settings changed. Keep activation gated on the matrix below.

## Required deployment

Provision a maintained, explicitly pinned LiveKit server release using its [self-hosting deployment guide](https://docs.livekit.io/transport/self-hosting/deployment/). Provide TLS, supported UDP/TCP media paths and a TURN relay for restrictive networks. Protect the administrative API and secrets; do not reuse CCTV credentials or expose the raw signalling listener to the internet.

The public signalling address must pass **every** `/rtc` handshake and reconnect through the Oblivion authorization gateway below. Firewall the upstream listener so clients cannot bypass this gateway. This is required because self-hosted LiveKit does not invalidate an issued token merely by removing a participant; token expiry alone also does not terminate an existing connection. See [LiveKit access-token lifecycle](https://docs.livekit.io/frontends/reference/tokens-grants/).

Configure in deployment secrets (never in a committed environment file):

```dotenv
CHAT_SHARED_EXPERIENCE=false
CHAT_LIVEKIT_URL=wss://YOUR_SELF_HOSTED_VOICE_HOST
CHAT_LIVEKIT_API_URL=https://YOUR_PROTECTED_MEDIA_API_HOST
CHAT_LIVEKIT_API_KEY=YOUR_GENERATED_KEY
CHAT_LIVEKIT_API_SECRET=YOUR_GENERATED_SECRET_AT_LEAST_32_CHARACTERS
CHAT_CALLING_VERIFIED=false
CHAT_CALLING_AUTH_GATEWAY_VERIFIED=false
```

`SelfHostedCalling` refuses insecure endpoint schemes and managed `*.livekit.cloud` endpoints. Participant credentials last 30 seconds, contain opaque identities and room identifiers, and grant microphone publishing only: no camera, screen share, data publishing, recording or administrative rights.

## Gateway contract

The reverse proxy extracts the original connection's `access_token` and supplies it as `X-Chat-Access-Token` to `GET /api/chat/calling/authorize` on the private application connection. HTTP 204 permits the handshake; any other status denies it. The application validates the signature, call identity, participant state, current conversation rights, enabled settings and ended-call state. Never cache the authorization response. Never log the token, original query string or this header.

Illustrative Nginx fragment; replace the upstreams and integrate with the deployment's TLS, trusted-proxy and rate-limit configuration before use:

```nginx
location /rtc {
    set $oblivion_chat_token $arg_access_token;
    auth_request /_oblivion_chat_authorize;
    access_log off;
    proxy_pass http://PRIVATE_LIVEKIT_UPSTREAM;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600s;
}

location = /_oblivion_chat_authorize {
    internal;
    access_log off;
    proxy_pass http://PRIVATE_OBLIVION_UPSTREAM/api/chat/calling/authorize;
    proxy_pass_request_body off;
    proxy_set_header Content-Length "";
    proxy_set_header X-Chat-Access-Token $oblivion_chat_token;
}
```

Verify the pinned browser/server combination uses the covered signalling path and token transport. Adapt the proxy deliberately if that protocol changes. The upstream admin API must remain separately protected; do not route administrative requests through a public participant gateway.

Run the Laravel scheduler continuously. `chat:reconcile-calls` is scheduled every ten seconds and closes ringing calls after their deadline, removes participants whose current access is revoked, and cleans up connections whose authenticated device heartbeat has been absent for 45 seconds. It also closes calls after the rollout or business settings are disabled. Monitor failures; do not silence failed server-side teardown.

## Activation evidence required

Before setting either verification flag, record actual browser/server evidence for:

- Two browsers on separate networks with two eligible staff identities; bidirectional audio and microphone mute.
- Group calling, joining/leaving, host succession, and ending for everyone.
- Approved family participants and care-team cover; unrelated families and other-client identities denied.
- Microphone denied/unavailable, autoplay blocked, disconnected network, reconnect and restrictive-network TURN use.
- Concurrent answers from two devices, busy/unavailable/DND, ring expiry and abandoned-tab cleanup.
- Removed/revoked identities denied fresh tokens **and** gateway handshakes; existing media disconnected; the raw upstream cannot be reached from a client network.
- Calls disabled and rollout disabled: new connections denied and existing sessions cleaned up.
- No video, recording, transcript, automatic note or external notification payload containing client details.
- Successful reviewed staff record and separate Family Note with audience confirmation; retries do not duplicate records.

A successful API health probe or token test is not sufficient activation evidence. Keep calls disabled until the deployment checks and remaining implementation acceptance criteria are satisfied. Native locked-phone/background calling is deferred to the future iOS/Android app.

## Signed media events

Configure the self-hosted server to deliver events to `POST /api/chat/calling/events`, signed with the configured API key. The receiver verifies the JWT and the exact raw-body SHA-256 hash, deduplicates event identifiers, and records media-connected timestamps from `participant_joined`. Browser acknowledgements are heartbeats only. An ended call cannot be reopened by a delayed event. Confirm signed events arrive in deployment; a connected browser without a verified media event must not be treated as authoritative duration evidence. See the [provider webhook contract](https://docs.livekit.io/intro/basics/rooms-participants-tracks/webhooks-events/).

## Current limitations to resolve before activation

Optional incoming sound with single-tab ownership is implemented, but live autoplay/multiple-tab testing remains. Notification dispatch, call transfers, multi-device recovery after reload and the full approved call-state matrix remain subject to implementation and verification in the main ledger. No live deployment evidence has yet been collected.

The [deployment templates](../../deploy/chat-calling/README.md) prepare a pinned self-hosted server and protected signalling gateway. They are unactivated examples requiring the operator's hostnames, addresses, certificates and secrets. Docker/Nginx runtime validation and live network testing remain outstanding.

