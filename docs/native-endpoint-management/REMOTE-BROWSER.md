# Technician browser viewer — development implementation

The single operating organisation and approved Site/device/role boundaries remain authoritative. This is an implementation increment in the full Security & Devices programme, not a supported remote-control release.

## Source and entry

IT & Support > Remote support now composes the actual request/viewer dialog when its server-projected availability allows it. The normal controller deliberately still returns remote_support=false: installed end-to-end acceptance is not yet complete, and toggling the deployment switch alone must not advertise a supported capability.

The connection-options endpoint independently authorizes the current technician for the canonical computer/Site assignment. A viewer does not need the policy-management grant. It projects the policy version, consent mode and separately permitted control; request submission then rechecks actor, policy, device, permissions and optional affected-ticket context. No organisation/tenant selector or cross-deployment transport was added.

A fresh request defaults to viewing, requires a support reason and asks for attended approval. An uncertain response can only be checked using the same request UUID and immutable body. Closing during a delayed success sends Stop when the identity becomes known; requests with an entirely lost response expire through existing server liveness. There is no automatic reconnect or replacement request.

## Transport and display

- The authorized media offer now includes the approved desktop UUID. The browser binds the ordered reliable obli.remote.v1 channel to exact session, desktop and immutable offer identifiers.
- Current short-lived technician relay grants supply explicit TURN/TURNS URLs and distinct expiring credentials. The browser uses relay-only ICE; it has no public STUN or direct fallback.
- One application-only DTLS/SCTP offer/answer is accepted, with bounded SDP and relay candidates. Setup has a 30-second deadline. JSON requests are same-origin, redirect-refusing, no-store, bounded to 64 KiB and four seconds, with CSRF on writes.
- Only the initiating technician's current observation keeps the browser alive. An independent monotonic watchdog expires stale authority within eight seconds; server and endpoint authority remain independent.
- The bounded OBL1 frame assembler accepts a maximum 512 KiB JPEG, 4096-pixel axes and 8 Mi pixels. Ordered chunks, sequence, header consistency and assembly timeout are checked before decoding.
- JPEG dimensions are checked before decoder allocation and checked again on the decoded bitmap. One decode is in flight. A generation/closed/authority check prevents delayed decoding from painting after Stop; decoded bitmaps are released.
- The screen stays blank until actual active permission and a successfully decoded frame. Eight seconds without a valid displayed frame ends the session.
- Stop, current-permission loss, transport loss, hidden tab, page departure and component unmount close the channel and clear the canvas locally. Server Stop is best-effort; its existing lease/heartbeat expiry is also required.

## Input

The technician must choose Control screen within an approved control session. View-only sessions cannot emit input. The viewer normalizes mouse coordinates against the actual rendered screen, maps common physical Windows key codes, sequences all events and bounds rate/buffering. It coalesces pointer moves; it ends a congested connection instead of silently dropping key/button releases.

Escape, focus loss and window blur release tracked input and leave control mode. Browser-reserved shortcuts, IME/keyboard layouts, secure Windows desktops and multi-monitor selection are not claimed complete. The ordinary-user worker still enforces its own OS/consent/active-lease/private-Obli restrictions. Browser code is not the authority for host actions.

The shared dialog, Button, StatusBadge and semantic tokens follow DESIGN.md. Recording remains off and unavailable. The Obli technician AI/tool panels are separate outstanding work.

## Verified evidence, 8 October 2026

- 26 protocol/lifetime/dialog checks passed across three Vitest files. Includes malformed/oversized/replayed frames, JPEG mismatch, asynchronous decode after Stop, stale authority during blocked requests, mismatched desktop channel, no control from readiness alone, input-release congestion, immutable request retry, changed actor, late request success after close, and hidden-tab cleanup.
- The expanded backend remote-session/workspace run had 22 passing cases and one new assertion failure: the expected Cache-Control string omitted the existing max-age=0 directive. The assertion was corrected, and the focused new options case passed with 11 assertions, including separated view/control/policy permissions and withdrawn Site assignment.
- Full TypeScript verification passed after correcting typed CSRF headers and a test mock signature. Final all-source check is recorded in CHECKPOINT.md.
- Actual Chromium rendered the real IT workspace/dialog from this isolated worktree at port 52214. API calls were explicitly proxied to a loopback fixture, not a live Laravel login or enrolled computer.
- The opt-in Go TestBrowserViewerInteroperabilityFixture used the real RTC library with a loopback-only TURN server, generated JPEG pixels and an in-memory input receiver. Browser/Go peers completed real encrypted relay negotiation, decoded the expected 640×360 image, exchanged mouse/key events and releases, and closed on Disconnect. Final fixture passed in 110.16 seconds; earlier successful run was 41.71 seconds.
- This fixture uses the test loopback network settings, not a production relay deployment or installed Windows capture worker. It must never be represented as installed-system acceptance.
- Light/dark and 780-pixel dialog screenshots were inspected. One-pixel horizontal overflow was corrected locally; theme screenshots now wait for finite transitions. Frame contents are artificial drawing primitives.
- Normal RTC tests and vet passed; the opt-in browser fixture is skipped unless explicitly enabled. No new third-party dependency.
- No real desktop pixels, real input injection, installed service/enrolment, provider request, production migration, production relay change or signed release occurred.

## Remaining acceptance and programme work

1. Prove signed installed Windows SYSTEM-service/ordinary-user helper and the real authenticated backend/browser/relay together. Exercise stop/revocation/lock/logout/crash and private-window masking with actual supported hardware/DPI/network conditions.
2. Complete unattended selection, emergency/recording policy, multi-monitor/cursor/IME/layout support and abrupt-process held-input recovery.
3. Finish governed terminal/scripts/process/service/update/software/file/restart tools and technician AI, plus the remaining staff knowledge/attachments/voice/offline features and signed installer/update/download lifecycle.
4. Complete relay quotas/peer restrictions/expiry/cleanup/NAT/performance acceptance; TURN bearer credentials already issued are not individually erased by app Stop.
5. Preserve every network/topology/alarm/CCTV/access/HR/care/tracking/Control Room/H&S requirement in PROGRAMME.md and the authorised H&S reconstruction ownership. Browser progress does not shrink the one-final-release gate.

Browser API references: [RTCDataChannel binary messages](https://developer.mozilla.org/en-US/docs/Web/API/RTCDataChannel/binaryType), [local session descriptions](https://developer.mozilla.org/en-US/docs/Web/API/RTCPeerConnection/setLocalDescription), [bitmap decoding](https://developer.mozilla.org/en-US/docs/Web/API/Window/createImageBitmap).
