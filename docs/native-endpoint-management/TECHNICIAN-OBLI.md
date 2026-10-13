# Technician Obli investigation workspace

Development source added on 8 October 2026 within the active, single-release Security & Devices programme. This is not installed-service or production-provider acceptance.

## Technician workflow

IT & Support → Remote support → choose a computer → Open Obli. The existing canonical ticket/device shortcut supplies an optional fixed ticket context. Selecting a computer or opening the workspace does not contact an AI provider or connect to its screen.

Authorised technicians can preview the latest accepted Windows agent observations, inspect the actual values and select system health, recognised software versions or approved service states. Missing observations and incomplete lists are labelled. New selected observations must still be fresh when the question is sent. The provider receives only the chosen server-resolved groups and their completeness metadata.

Ask Obli sends a reviewed question, visible conversation and selected diagnostic references through the existing central OpenAI/Anthropic provider, key, spending and retention policy. The default technician mode reads and suggests. It can explain a proposed diagnostic command or repair with prerequisites and verification, but has no tool to execute it, grant access, create a ticket or start remote control. Governed repair execution remains separate work.

The panel uses the approved Obli Orbit/NZ mark, shared app tokens and controls, optional activity motion, reduced-motion support and light/dark styles. It holds private conversation state in memory. Account, computer or ticket changes unmount that state; late responses are discarded. Leaving an active conversation uses the existing shared confirmation.

## Deployment and authority

- Global endpoint deployment and the new disabled-by-default `endpoint_management.obli.technician_enabled` gate are required.
- Central Obli AI and `technician_enabled` settings plus `securityDevices.obli.assist` authorise provider chat. The settings wizard reviews this independently from approved staff guides; provider secrets remain server-side.
- `securityDevices.endpoints.diagnostics` separately authorises diagnostic preview and attached/historical source use. Approved preview can remain available while AI chat is disabled. Plain questions can work with assist permission without diagnostic permission. Global AI off stops new spending; read-only recovery of an already committed answer still requires the technician gate and current authority.
- Every web request requires the current expected actor, approved/verified staff and IT/device authority, approved Site and current device assignment, live enrolled Windows agent identity and the exact optional linked ticket. Computer-list access alone grants no assistance or control.
- A re-enrolment changes the agent UUID even if its database row is reused. Source bindings retain that UUID and the assignment fingerprint. Routine new heartbeats do not invalidate already accepted conversation context.
- Normal Connect remains behind the incomplete signed installed remote-support acceptance gate.

This remains one operating organisation per independent deployment across its approved Sites. There is no tenant switch, cross-customer management session or tenant-authenticated collector.

## API and private evidence

Four authenticated, CSRF-protected web routes use `/it/remote-support/devices/{device}/obli`: options, diagnostic preview, chat POST and chat receipt GET. GETs bind expected_actor_id and optional ticket_id. POST carries an immutable request UUID, actor/configuration/ticket context and bounded alternating plain-text messages. A selected source reference contains snapshot_version, canonical groups and, for a historical source, its prior source_request_uuid.

Browser-supplied diagnostic bodies, screenshots, arbitrary files, private staff conversations and automatic ticket text are rejected or excluded. The source is the accepted EndpointAgent heartbeat, not the Device last-seen timestamp. The closed projection excludes hostnames, people, IP/MAC/SSID, paths, publishers, arbitrary software, raw errors and secrets.

Fresh observation limits are six minutes since observation and three minutes since receipt, with 30 seconds allowed future skew. The review version binds scope, agent incarnation, assignment, heartbeat revision, projected values, timestamps and completeness. Changed reviewed source returns source_changed before a new provider call.

Captured sources are sealed typed bindings, encrypted within the existing private AI receipt. They share its expiry/pruning policy. Current authority and source identity are checked again on post-provider return, replay, history and read-only recovery. Personal helper receipts and technician receipts cannot be recovered through each other's API.

## Billing and lost replies

The typed personal/technician scopes share one gateway, provider executor and day/month budget ledger. Personal request hashes remain compatible. New technician request hashes additionally bind their actor/device/ticket context. The existing UUID is resolved before fresh source capture or budget reservation. Provider I/O occurs outside application database transactions.

The browser retains an unresolved command exactly. Check reply retrieves its existing receipt with GET; Retry same request retains the same UUID and payload. Stop waiting aborts the browser wait, not the provider. Missing, busy, expired and uncertain outcomes are never treated as proof that a provider call did not occur. Current-authority denial clears private content and removes source-bearing retries, retaining only the unresolved UUID for a later authorised check.

Explicit pre-provider source_changed, invalid_request and budget_exceeded responses restore an editable draft and clear the selected snapshot/consent. Other failures preserve uncertainty. A recovered answer whose prior conversation was cleared is displayed alone and requires a new conversation before another question.

The gateway reserves against the actual server-selected instruction profile and all rendered question/source bytes. Diagnostic JSON and selected completeness fit within the shared 12KB conversation budget; provider answers are bounded plain text. Input, scopes, encrypted records and provider results have independent limits.

Lock ordering uses current User/Site authority before nonblocking Device/Agent/Ticket acquisition. Only recognised MySQL lock busy/timeout/deadlock codes become a safe context_busy response; a busy result can occur after the provider, so the browser still recovers the same request. Existing blocking User/Site acquisition can await database deadlock detection. Shared reply recovery now takes settings before receipt locks, avoiding the reviewed settings/budget/receipt cycle. No automatic provider retry is introduced.

## Verification and remaining work

See CHECKPOINT.md for final process evidence, actual exported PHP DTO checks and browser results. Fake-provider tests and fictional heartbeat fixtures do not prove a signed installed Windows service, live provider billing, hardware state or operational remote control.

Still required: installed acceptance, governed technician execution, wider diagnostic families, remote-support recording/policy acceptance, lifecycle/signing/publication and all remaining network/topology/alarms/CCTV/access+HR/care/tracking/Control Room/H&S requirements in PROGRAMME.md. This increment has no new H&S reconstruction ownership transfer.
