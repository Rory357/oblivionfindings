# Native Obli staff sign-in and personal IT requests

This is implementation evidence for the full Security & Devices programme, not a release-completion claim. Each deployment serves one operating organisation across approved Sites. The later Windows-only computer-helper decision remains authoritative; Windows/Linux site collectors are separate roles in the wider programme.

## Implemented contract

- The helper obtains the deployment origin only from the authenticated local support service. Sign-in is enabled only after a recent accepted check-in. There is no staff-editable server selector.
- The normal system browser handles Oblivion authentication and its existing MFA/verification policies. A browser confirmation displays the account and deployment before allowing or declining access. A shared computer's browser may already have an account: confirmation is always explicit.
- A fixed public client (obli-windows) uses S256 PKCE, a fresh random state, a one-use 90-second authorization code and an ephemeral 127.0.0.1 HTTP callback. The native listener is bounded, validates the exact host/path/state/issuer, refuses unsolicited or duplicate parameters, and closes after completion/cancellation. No password or cookie is copied into the helper.
- Deployment HTTPS origin and exact callback binding are checked on the server. Codes and tokens are hashed at rest. API transport forbids redirects and bounds response size/time. Tokens authorize only /api/obli endpoints, not management commands, machine enrolment or other application APIs.
- Staff approval, verified email, non-external/non-client role, current IT permission and approved HR/Site evidence are checked under locks on every token operation. Account/password/MFA fingerprint changes invalidate the session. The native token defaults to 30 minutes, with deployment configuration bounded to 5–60 minutes; no refresh token or persistent native credential is stored.
- Windows lock/logoff, sign-out, expiry or a changed/lost trusted service connection clears private account/ticket/draft state from the UI. Sign-out attempts server revocation; offline revocation cannot be guaranteed before the bounded token expiry. A browser's own login session remains independent.
- Native support tickets use the canonical IT intake owner and its command receipt. The Obli adapter fixes the requester to the signed-in person, accepts only reviewed title/description/category/approved Site, adds no chat/files/diagnostics automatically, and rejects privileged fields and forged device links.
- Even a technician's personal helper lists only their own requests at their currently approved Sites. It exposes no internal notes, watchers or attachments. The native helper now pages through personal requests and public conversation history, supports reviewed text replies and offers Open in Oblivion for the full browser workspace. Each list page contains at most 30 requests.
- The draft is reviewed before submission. The submitted payload and request identity remain fixed during retries; a lost response cannot silently produce a second ticket. A receipt for a ticket whose requester/Site changed is concealed.
- Successful sign-in and sign-out have minimal audit records attributed to the token holder. Credentials and private support text are excluded from debug/error/report payloads. Expired authentication material is removed after one day by obli:prune-sessions, scheduled daily.
- OBLI_STAFF_HELPER_ENABLED defaults off. The deployment must have the new migration and configured ENDPOINT server origin before enabling it. The computer management credential and future AI provider credentials never enter staff sign-in or its ticket API.

This is a deliberately fixed-client handoff, not a general OAuth/OIDC identity-provider implementation. The native pattern follows the external-browser and loopback guidance in [RFC 8252](https://www.rfc-editor.org/rfc/rfc8252) and S256 proof-key guidance in [RFC 7636](https://www.rfc-editor.org/rfc/rfc7636).

## Current development evidence

- Backend feature suite covers consent validation, code exchange/replay/expiry, token expiry/revocation, account/password/permission/Site changes, request idempotency, own-ticket isolation, wrong deployment origin, transferred-ticket receipt denial and audit attribution.
- Native check groups cover strict status framing, private fields, callback validation, RFC proof-key vector, incorrect issuer, credential routing, bounded responses, identical submission retries, a real temporary loopback callback and native UI clearing/review.
- Actual WPF ticket form rendered from the same application source with fictional test data. Corrected the Site selector's object-name rendering during review.
- Targeted frontend lint passed. Full browser login/MFA-to-installed-helper acceptance remains pending.
- All builds/tests use ignored development outputs and isolated test data. Nothing installs/enrols the host or opens a real remote support connection.

## Still required for the agreed release

- Remaining AI support: broader diagnostic coverage, previewed attachments, voice and wider non-IT knowledge sources. Explicit approved IT guide selection now exists; see approved-knowledge.md. Central settings, private text chat and selected system/software/service checks are implemented as described below.
- Private persistent/offline ticket drafts, user-selected chat summaries and evidence. In-helper public replies/history/pagination now exist; see obli-ticket-conversation.md. Current drafts are memory-only and are cleared on sign-out/Windows lock; recovery across app closure is not implemented.
- Signed installed acceptance for the new reviewed ticket/computer association. Its current source uses separate staff and endpoint credentials and never accepts an arbitrary device ID; see ticket-computer-association.md.
- Configurable session/access policy UI and broader device-compliance controls. Current token lifetime is deployment configuration.
- Actual attended/unattended/emergency remote-session consent and control transport, governed technician actions and technician AI.
- Signed installer/update/repair/uninstall/download and installed multi-user/lock/logoff/reboot acceptance. The binaries are unsigned development builds.
- All network, topology, alarms, CCTV, access/HR, care equipment, tracking and H&S reconstruction requirements in PROGRAMME.md remain in the one final-release gate.

## Private AI chat and central settings — 8 October 2026

The Windows helper now implements private, signed-in text chat in source. This supersedes the earlier "AI unavailable" statements for this specific path; release gates below still apply.

### Administrator configuration
- Settings > Integrations > Obli requires both current settings.access.manage and integrations.manage_secrets, approved/verified non-external account, and HTTPS. Navigation uses the same effective permission combination.
- The existing encrypted AppSetting pattern stores the provider secret using Laravel Crypt; it is not an external Vault integration. The browser gets only key_saved, never the key or its encrypted representation. A replacement key is a password input held only in component state, cleared on save attempt/discard, and never included in Inertia remembered state.
- Shared PageHeader, SettingsLayout and WizardShell provide provider/model/key, limits, review and success. A changed browser actor or configuration revision cannot overwrite a different editor's state. An uncertain save requires reloading saved settings; it is not silently repeated.
- Provider choices are OpenAI Responses and Anthropic Messages. Exact model IDs and current prices are administrator-supplied; no model availability or pricing is invented. A provider change cannot reuse the other provider's key.
- Daily/monthly limits are configured **estimated USD**, using UTC periods, exact integer microdollar arithmetic, input/output prices per million tokens, and conservative reservations before dispatch. They are not an invoice guarantee or a substitute for provider-account limits. Completed replies settle reported usage; unconfirmed outcomes retain the reservation. All staff share the deployment's budget.
- Reply length is configurable (256–8192 tokens). Encrypted private reply recovery retention is 1–90 days, default 7. Changes apply to new requests. Submitted prompts are not stored by this gateway. Budget rows contain aggregates, not conversations, and are retained approximately 14 months. The scheduled prune command removes expired private reply/request records daily; expired replies are denied immediately. Pending requests older than two minutes become unconfirmed when checked, as well as during pruning.

### Staff chat boundary
- The existing staff token and current account/HR/Site checks protect all chat calls. Basic anonymous help remains local quick guides; anonymous cloud AI is not enabled.
- The native window loads the current provider/model/retention disclosure, requires explicit consent, and rechecks settings before each send. A changed configuration requires renewed consent. Only the displayed conversation and explicitly selected computer checks or approved IT guide excerpts are submitted. Screenshots, files and tickets are not appended.
- Provider requests go to fixed HTTPS endpoints with no redirects, bounded headers/body/read times and reply sizes. OpenAI uses store:false. This does not promise zero retention at the provider; each provider's account terms and data controls apply separately.
- No tool definitions or execution capability are exposed. Instructions identify Obli as a read-only computer-support assistant. Returned text is rendered as text, never evaluated as a command, HTML or remote action.
- The gateway commits the budget/request reservation before provider IO and holds no application-owned DB locks across that IO. UUID + content digest prevent an uncertain request from generating a second provider call within the request retention window. The digest is keyed; the prompt is not persisted. Replies are encrypted and recoverable only by the current authorised owner. There is no administrator conversation-history endpoint.
- After any provider call, access is checked again before returning private text. Server errors do not expose prompt/key/provider bodies; the adapter uses a dedicated Guzzle client rather than globally logged Laravel HTTP events.
- The helper holds conversation/context in memory, limits a send to 15 alternating messages / 12 KB, and clears it on lock/logoff/sign-out/expiry or loss/change of the trusted service connection. It does not persist chat or credentials to disk.
- Stop waiting cancels the helper's wait; the provider may still finish and charge. Check for reply performs only recovery, not another send. Definitive server rejections restore the draft; unknown outcomes retain the request identity. New chat warns before discarding a conversation and does not erase an already-sent provider request.
- Staff can choose one message to add to an existing IT intake draft; existing nonempty drafts are preserved. AI suggestions are explicitly labelled unverified. Ticket review and submission remain separate, with no automatic transcript sharing.

### Evidence and remaining acceptance
- Backend isolated feature run 8209: 18 passed / 162 assertions in 376.06 seconds (AI plus staff authentication/ticket regression).
- Provider protocol/math units: 4 passed / 31 assertions. All replies were simulated; no billable provider call or real credential was used.
- Native harness: 16 check groups pass, including provider disclosure changes, recovery identity, reply validation, definitive rejection, selected sharing and private-state clearing. x64/ARM64 development publishes pass; actual WPF chat rendered with fictional data.
- Full TypeScript check passes after generating the worktree's Wayfinder route/action files. Targeted ESLint passes.
- Actual shared settings page/wizard rendered in a browser using fictional Inertia data and intercepted local save responses. Validation, hidden key, review, expected actor/revision, successful save, provider change, lost-response recovery, discard and 390px fit were exercised. Stable light/dark screenshots were visually inspected. This is component/browser evidence, not deployed HTTPS session-to-provider acceptance.
- No host enrolment, real account, actual ticket submission, external AI call, live remote control, service installation, production migration or signed binary publication occurred.
- Still required: signed installed Windows pilot; real HTTPS login/MFA and private-chat journey with approved provider account; broader network/update/log diagnostic coverage; approved organisation/Oblivion knowledge; explicit files/screenshots preview; voice; offline drafts and ticket evidence; technician AI and governed actions; real consent/session/control transport; combined collector installer/update/download lifecycle; every other network/alarm/CCTV/access/HR/care/tracking/H&S programme requirement.

Provider contract references checked during this work: [OpenAI Responses](https://developers.openai.com/api/reference/typescript/resources/responses/methods/create), [OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data), [Anthropic Messages](https://platform.claude.com/docs/en/api/messages/create).

The settings status also reports when deployment helper access is off; enabling the AI configuration alone does not bypass the disabled-by-default endpoint/helper transport gates.

## Approved computer-check sharing — 8 October 2026

- Central Obli settings now allow three optional groups: system health, recognised software versions and Windows service states. All are off by default. The current server policy is enforced before reserving budget or contacting the provider, and policy changes reset native sharing/provider consent.
- The service projects the last durably accepted heartbeat into an explicit allowlist. System checks include numeric Windows version/architecture, bounded memory/uptime/CPU and up to eight drive-letter capacities. Software is restricted to recognised machine-wide Edge, Chrome and Teams entries with numeric versions. Services are limited to Print Spooler, Windows Update, BITS, Defender and Firewall, with fixed running/stopped/unknown states.
- Hostnames, user details, IP/MAC/SSID, paths, publisher names, arbitrary software, raw logs and all credentials are excluded from this local diagnostic operation. Missing data is unknown, not proof of absence; a stopped service is not by itself a fault.
- The existing authenticated local Windows pipe now accepts exactly status or diagnostics, with no command arguments. Diagnostic responses are bounded and tied to the helper's signed-in deployment origin. Snapshots expire after six minutes; disconnected/unconfigured service states expose no snapshot. Reading the cache runs no extra privileged host probe.
- Staff open Review computer checks, choose groups, inspect the actual values, and select Use these checks. That only attaches to the draft. Sending is separate and still requires the named provider consent. Removing/replacing/cancelling a preview does not send anything.
- The gateway strictly validates and normalizes each selected snapshot. It permits fresh checks on the latest question, up to one-day-old context in earlier questions, and a total 12 KB rendered conversation including diagnostic JSON. The provider receives a labelled informational snapshot, not remotely attested device identity or executable instructions.
- The gateway does not persist the submitted diagnostic snapshot. It participates in the keyed request digest and estimated input reservation; encrypted reply/retention policy remains unchanged. Private helper state stays in memory. Sharing a selected chat message into a reviewed ticket draft includes only that message's selected diagnostic groups; submitted tickets then follow IT policy.
- New native checks cover unknown/private/duplicate fields, wrong origins, stale/future checks, invalid counters, group selection/reset, draft removal, no automatic send, policy clearing and selected ticket sharing. Go checks cover projection, freshness, copied snapshots and the real temporary Windows pipe for both operations.
- Additional diagnostic support remains required: network summaries, update failures and selected redacted event logs. Wider knowledge sources, files/screenshots, voice, offline helpdesk workflows, technician AI and real remote consent/control also remain incomplete. This is source-level progress, not a final release or installed-service acceptance claim.


## Approved organisation guides — 8 October 2026

The signed-in helper now searches and previews current reviewer-approved IT knowledge, including when AI is disabled. Staff may explicitly attach the displayed excerpt to a question. The server resolves source text from its approved publication and rechecks current access/version on send and recovery. The central settings switch is independent of AI and guides-only setup requires no provider key. See approved-knowledge.md for contracts, privacy and acceptance limits. This development increment does not complete the final-release gate.


## Reviewed computer association — 8 October 2026

The optional Include this computer review now uses the authenticated local service plus current server authority to attach the canonical Device to an own-ticket request. Staff see its confirmed name/Site before sending; existing technician ticket context owns the remote-support entry. Unknown delivery retains the same immutable request and offers read-only receipt recovery. See ticket-computer-association.md and CHECKPOINT.md. This does not enable live remote control or complete signed installed acceptance.


## Separate technician investigation — 8 October 2026

The IT Remote support workspace now has a separate Obli investigation panel; see TECHNICIAN-OBLI.md. It shares central provider/budget infrastructure while enforcing its own operator, computer, ticket and source permissions. It does not read the staff member's private conversation. Earlier notes that technician AI is absent are superseded only for this read/suggest diagnostic workflow; governed execution and signed installed acceptance remain required.
