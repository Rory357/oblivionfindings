# Remote-support policy and session authority

8 October 2026. Development source; the full programme and its one final-release gate remain active.

## Implemented boundary

IT & Support > Remote support uses canonical computers, current approved Sites, active assignments and IT permissions. An authorised policy editor can configure each computer through the shared Access / Review wizard. Defaults are disabled, attended approval, view only and a 30-minute maximum. The permitted duration is 5–120 minutes. A reason and the current actor/policy revision are required; changing policy ends existing requests and sessions. An uncertain save requires reloading authoritative settings.

Separate permissions govern remote viewing, control, unattended access and policy changes. Existing permission-catalogue seeding is required before deployment. Listing a computer does not grant remote access. A linked IT ticket must remain accessible, unmerged and explicitly linked to that computer. Credentials, actor security state, current Site/assignment access and policy are rechecked throughout.

The session broker uses the enrolled machine credential and pinned Ed25519 signing authority. Offers bind the exact device-agent incarnation, session, policy, technician, purpose, mode, consent challenge and expiry. Re-enrolment cannot inherit a previous agent's session. Challenges are encrypted at rest and absent from returned technician records and transition events.

## State and time limits

- Requested: one open request per device; 120-second offer window; the initiating technician must refresh within 30 seconds.
- Allowed: a matching challenge and desktop-session identifier have accepted the request. This does not mean a connection exists.
- Active: the enrolled service reports activation while holding a valid lease. This is not yet independently observed screen-transport evidence.
- Declined, stopped and expired are terminal. Retries cannot revive them or change the desktop binding.
- Signed leases last at most 15 seconds and cannot exceed the configured total session deadline. Total duration starts at request creation.
- Policy changes, lost grants, account security changes, device revocation/re-enrolment, changed ticket access/linkage and stale participants prevent renewal.
- The minute scheduler records abandoned-session expiry. It is not a substitute for the future Windows worker enforcing the 15-second lease locally.
- The initiating technician can stop their own session after a grant or feature is withdrawn. Local Stop must remain immediately effective even without a server response.

Requests are retry-stable only for the same actor, device and exact payload. Changing a payload under a used request identifier fails. HTTPS and a configured canonical deployment origin are mandatory; private responses and errors are not cached and do not flash support text or credentials.

## Protocol validation

The Go agent source validates signed typed offers and leases, exact required fields, duplicate/unknown/null rejection, bounded payloads, strict signatures and device/session/desktop/access binding. It rejects unsupported recording, unexpected consent modes, expired or excessive lifetimes, control escalation and one protocol message being used as another. These validators are not yet connected to the Windows runner or tray.

The desktop-session UUID is currently an opaque value supplied by the authenticated machine. It is not proof of a real Windows interactive desktop. The next service/tray bridge must derive and verify the actual OS user, logon and desktop session; browser or tray input cannot simply assert another identity.

## What is not working yet

The technician Connect and technician Obli actions remain unavailable. The remote-support feature switch remains false. There is no operational screen capture, media relay, mouse/keyboard injection, remote terminal or file transfer in this broker. No source-only flag or simulated activation is evidence of remote control.

Required next work:
1. Poll and verify offers in the privileged service, then deliver only to the authenticated intended interactive desktop. Bind Allow/Decline/Stop to the offer and actual desktop; protect against replay and cross-user acceptance.
2. Implement the native prompt, persistent visible session notice and local Stop. Disconnect on lease expiry, lock/logoff, changed origin/identity and lost authority. Resolve unattended equipment handling without bypassing its separate grant and notice policy.
3. Implement and verify the screen/input transport and technician viewer, with no capture before authorised consent/lease. Private Obli chat is not automatically shared as diagnostic or ticket evidence; capture privacy needs explicit implementation and acceptance.
4. Add separately governed technician tools and AI suggestions/approved actions. Session recording remains off and unsupported here; configurable recording and controlled emergency access still belong to the final requirement set.
5. Complete retention/operational visibility, combined installer/update/download lifecycle, signing and real isolated installed-Windows acceptance.

## Evidence

- Final broker/workspace feature suite: 14 tests, 214 assertions, isolated MySQL; session 70308 exit 0.
- Origin-normalisation transport unit: 1 test, 7 assertions; session 16044 exit 0. The repository test bootstrap still uses isolated MySQL for this application test.
- All eight Go packages, vet, and Windows amd64/arm64 service/setup builds passed. No native WPF application code changed in this increment.
- Full TypeScript and targeted ESLint passed. Scoped PHP syntax/formatting and diff whitespace checks passed.
- Actual shared React policy components were exercised in a local browser harness using fictional device/actor data and intercepted responses: defaults, validation, review, save, changed actor, uncertain-save recovery, discard and light/dark/390px layouts. Screenshots are in output/playwright/remote-policy-*.png.
- No service installation, real enrolment, actual remote connection, external AI request, production migration or customer release occurred.

These checks establish the broker and policy source behaviour, not the unfinished end-to-end remote-support capability.

## Windows consent bridge — 8 October continuation

The earlier statement that protocol validators are unwired is superseded by this increment. The installed-service runner now starts a bounded remote-request loop after loading its protected identity. It polls the configured deployment using the machine credential, verifies signed offers/leases and serves a separate, credential-free local projection to Obli. Browser Connect and the deployment remote feature switch remain disabled; there is still no screen/input worker.

The local pipe accepts only remote_poll, remote_decide and remote_stop in addition to its existing read operations. JSON cannot supply the Windows user or session identity. The server obtains the real interactive SID, logon SID and session from its existing identification token; remote access additionally requires a unique active, unlocked Windows session whose WTS user/logon token matches that peer. Ambiguous multi-session hosts are denied until explicit desktop selection is implemented. Token handles are closed and never exposed. Platform basis: [Microsoft WTSINFOEX session state](https://learn.microsoft.com/en-us/windows/win32/api/wtsapi32/ns-wtsapi32-wtsinfoex_level1_w) and [WTSQueryUserToken](https://learn.microsoft.com/en-us/windows/win32/api/wtsapi32/nf-wtsapi32-wtsqueryusertoken).

The service creates its own per-request desktop UUID and local consent nonce bound to that OS logon. The tray cannot choose the desktop UUID or see the server challenge, machine credential or signing state. Offer changes invalidate a displayed prompt. Allow queues an exact decision, then requires a signed allowed lease; it never requests active status. Decline removes local permission immediately and records a decline upstream, falling back to Stop if that reply cannot be confirmed. Stop invalidates local consent before contacting the server; revisions reject late offer, decision or lease results. Recently stopped requests cannot be reoffered as fresh local consent.

The controller withdraws permission on stale helper presence (six seconds), invalid/locked/disconnected Windows session, stale/lost acknowledged machine connection, lease expiry, failed authority confirmation or shutdown. Signed lease wall-clock expiry is converted to a monotonic local deadline. No consent survives service restart; there is no automatic session resume. Network requests have independent three-second deadlines and bounded, strict JSON responses. The future media worker must enforce these limits independently for capture/input, not infer them from a UI label.

The native WPF window now parses the exact origin-bound local projection, opens a new attended prompt, displays technician/purpose/view-or-control access and presents Allow/Decline. The notice stays above navigation. Waiting/allowed states expose Stop and explicitly say that a screen connection has not been confirmed. Closing, minimising, Windows lock/logoff and origin changes clear the local view and request Stop. Stop/close also suspend renewing polls for 20 monotonic seconds, exceeding the local presence and in-flight pipe windows; a lost Stop acknowledgement cannot keep approval alive through background polling.

Unattended offers are not activated by this attended-only bridge. Explicit equipment/desktop worker handling, concurrent desktop selection, emergency policy, recording, screen/media/input transport, technician tools and technician AI remain required. Private Obli chat exclusion from capture needs implementation before real screen support. Real signed SYSTEM-to-tray/WTS/lock/unlock/logoff/reboot pilot acceptance is still missing. The WTS prefix/parser and injected identity tests are not proof of installed OS lifecycle behaviour.

Evidence: full Go tests across eight packages plus vet and Windows amd64/arm64 service/setup builds pass (3d4f35). New tests cover logon/nonce binding, changed/withdrawn offers, decline, replay, local Stop during an in-flight decision, lock/helper/connection/lease loss, strict TLS reply validation, no media activation, forged pipe identity fields and Windows state decoding. Native build 7a51dc finished exit 0 with 22 check groups and x64/ARM64 publishes. The actual WPF consent view was rendered and inspected; .endpoint-tools/obli-remote-preview.png is a fictional development example. No actual service install, enrolment, screen capture/control, production write or customer release took place.

## Media signalling and transport core — 8 October continuation

The broker now has one ephemeral encrypted offer/answer record per approved session. Only the initiating technician can fetch the offer or submit its immutable answer. Agent routes additionally require the current enrolled identity, accepted desktop and live allowed/active session. Exact retries are supported; replacing an offer, answer or viewer is denied. Terminal transitions delete the record. Media descriptions have explicit size/shape limits and never appear in audit event payloads. Only the two SDP submission routes skip generic string trimming so required CRLF bytes survive validation.

The agent has separate signed media-answer validation and bounded publish/fetch methods. Signed answers bind agent, session, desktop, offer, viewer and access mode and expire within the current lease (at most 15 seconds). These methods are not yet invoked by the consent controller.

The new internal/rtc library uses [Pion WebRTC](https://github.com/pion/webrtc) v4.2.23 under its MIT licence. Production construction requires centrally supplied TURN/TURNS credentials and relay-only candidates; there is no automatic public STUN or direct fallback. Deployment relay provisioning and short-lived credentials are not implemented yet. One ordered DTLS/SCTP channel binds the session, accepted desktop and offer identifiers. Current-authority callbacks gate negotiation, frames and input; a watchdog closes withdrawn authority. The future runtime must supply callbacks derived from the real OS-bound consent controller, not UI state.

Frames are JPEG, at most 512 KiB, 4096 pixels per dimension and 8 Mi pixels total. A 20-byte OBL1 header carries frame number, total bytes, width, height, chunk index and count; chunks carry at most 4096 image bytes. Bounded buffering prevents an unbounded slow-viewer queue. Input accepts only explicitly typed mouse/key events, exact fields, increasing sequence numbers and bounded frequency. View-only mode rejects input. These limits describe the transport protocol, not measured interactive-screen performance.

The library does not capture Windows desktops or inject input. No browser viewer exists yet. The service runner has not been connected to this library, and its negotiation must run independently of lease renewal. Before activation, the user-desktop worker must enforce Stop/expiry/OS state, protect private Obli content, release held input and prove browser interoperability and real installed operation. Connect and the remote feature switch remain disabled.

Agent API polling now has a per-computer budget (ENDPOINT_AGENT_REQUESTS_PER_MINUTE, default 180), plus bounded site ingress (ENDPOINT_INGRESS_REQUESTS_PER_MINUTE, default 12000). Stop uses separate per-computer and ingress keys so polling cannot consume its capacity. Keys hash credentials rather than retain raw secrets. The distinct enrolment budget is unchanged; bulk installation acceptance is still required.

Evidence for this increment: full feature run 19 tests/321 assertions, then the final changed rate-limit case 1 test/7 assertions; all nine Go packages, vet and Windows amd64/arm64 builds pass. Two real encrypted peers exchanged generated image bytes and simulated input through a loopback-only fixture TURN server; replay, view-only input and withdrawn authority were denied. This does not prove a Windows screenshot, real pointer control, browser compatibility, production relay or NAT performance.

The dependency scan found [GO-2026-5942](https://pkg.go.dev/vuln/GO-2026-5942) in the initial graph. Final pins x/net v0.57.0 and x/crypto v0.56.0 produce no reachable or imported-package findings; one module-only advisory remains in unused legacy OpenPGP. Third-party notices for the 23 imported modules are in endpoint-agent/third-party-licenses and must accompany distribution. No signed release has been published.

## Runtime integration — expiring relay grants and asynchronous negotiation

The service now invokes the media client and RTC library during an approved attended session. This supersedes the earlier statements that those parts are unwired. Explicit deployment relay configuration produces separate expiring participant credentials; the agent accepts only signed grants bound to its current session/desktop/access. Negotiation runs independently of lease renewal and is cancelled immediately on local Stop, with current revision checks preventing late replies from restoring authority.

A ready data channel still does not activate a desktop. The Windows capture/input worker and browser technician viewer remain missing, input is rejected, and Connect stays disabled. See [REMOTE-RELAY.md](REMOTE-RELAY.md) for exact credential lifetimes, service orchestration, verification and production acceptance boundaries. CHECKPOINT.md records the completed test/build handles for this increment.

## Windows screen/input worker — 8 October continuation

The ordinary-user Windows helper now has capture/input source connected to the service's authenticated desktop pipe. A ready RTC channel plus a currently present, OS-bound worker is required before the service requests a signed active lease. The earlier statement that no Windows worker exists is superseded; the technician browser viewer and real installed end-to-end evidence remain missing.

The helper provides primary-screen JPEG frames, separately governed view/control input, a two-second local worker lease/watchdog, Stop cleanup, a persistent active notice and private-page hiding/masking. See [REMOTE-WINDOWS.md](REMOTE-WINDOWS.md) for exact limits and unresolved multi-monitor, held-input crash recovery, OS and installed acceptance. Connect remains disabled.

## Technician browser continuation — 8 October 2026

The browser request/viewer is now implemented and wired behind the existing release availability gate. See REMOTE-BROWSER.md for exact transport/input/privacy behaviour and real Chromium-to-Go loopback interoperability evidence. Earlier statements that the browser viewer is absent are superseded; installed end-to-end acceptance remains outstanding, so normal Connect is still disabled.


## Technician Obli investigation — 8 October 2026

The workspace now has an independently gated, permission-controlled Obli diagnostic investigation panel; see TECHNICIAN-OBLI.md. Reviewed server-derived Windows observations and explicit provider consent feed the shared central AI gateway. It suggests next steps without executing commands or granting screen access. Normal Connect and installed remote-control acceptance remain unchanged.
