# IT, Security & Devices desktop release acceptance

## Purpose and release boundary

This is the final deployed-browser acceptance contract for IT & Support, Security & Devices, native Oblivion Monitoring, and their canonical cross-module handoffs. It is desktop/web only. Run every matrix row against the deployed release at both `1440 x 900` and `1280 x 800`. Mobile, WebView, a local Vite server, a local PHP server, and a preview build are outside this acceptance.

Oblivion Findings is one application. Access is decided by approved Site access, roles and permissions, canonical ownership, direct-object denial, and privacy rules. Security & Devices owns the canonical Device register. Control Room owns operational alert correlation. IT owns technical work. Site, Client, HR, Fleet, Asset, Finance, and Control Room pages are permission-safe projections and handoffs, not second Device or ticket stores.

This runbook does not close a release from local tests. A release passes only when all of the following are attached to the same deployed release identifier and source revision:

1. the local automated gate is green;
2. deployment and runtime supervision succeeded without an unrecorded skip;
3. the deployed browser matrix below passed with the exact actors and fixtures;
4. the live protocol/provider observation evidence is complete; and
5. the separate restore companion evidence is complete.

The deployment must start from the reviewed Git checkout whose clean `HEAD`
exactly matches `refs/remotes/origin/main`. `deploy-server.sh --skip-git-update`
may skip the network fetch/pull only after proving that same binding; it cannot
authorise an arbitrary clean branch or an artifact-only directory with no Git
provenance. Record the verified source revision before starting D01-D18.

## Acceptance actors

Create these dedicated acceptance users through the approved release-fixture process. Assign only `RELEASE Site Alpha` unless the row says otherwise. Do not use `admin@test.com`, the `admin` role, impersonation, an application-wide permission override, or a permission change during the run.

| Actor | Exact role | Required scope and purpose |
| --- | --- | --- |
| `release-requester@acceptance.invalid` | `support_worker` | Current staff member at `RELEASE Site Alpha`; uses inherited `it.request` for Help Centre, catalogue, own requests, comments, reopen, and CSAT. |
| `release-it-manager@acceptance.invalid` | `it_manager` | Current staff member at `RELEASE Site Alpha`; works IT queues, provisioning, specialist work, canonical Devices, Monitoring, integrations, settings, and governed Device actions. The prepared fixture has explicit denials for `it.organisationWide` and `securityDevices.devices.viewAllSites` so this actor remains Site-scoped. |
| `release-it-reviewer@acceptance.invalid` | `it_manager` | A different current staff member at `RELEASE Site Alpha`, with configured MFA and step-up capability; independently approves or rejects governed Device work. The same two application-wide permissions are explicitly denied in the prepared fixture. |
| `release-control-room@acceptance.invalid` | `coordinator` | Current staff member at `RELEASE Site Alpha`; owns Control Room triage and sees only source data allowed by its independent Security & Devices permissions. |
| `release-auditor@acceptance.invalid` | `auditor` | Current staff member at `RELEASE Site Alpha`; read-only Device, event, report, access-control, and command-evidence acceptance. No mutation control may appear. |
| `release-denied@acceptance.invalid` | `support_worker` | Current staff member at `RELEASE Site Hidden`; proves Site and direct-object concealment against Alpha records. |
| `release-source-denied@acceptance.invalid` | `finance` | Current staff member at `RELEASE Site Alpha`; proves missing Security & Devices and Control Room parent permissions without changing another actor during the run. |

If a required actor, seeded role grant, MFA prerequisite, or Site assignment is absent or incorrect, the release is blocked. Do not repair acceptance by granting `admin`, logging in as an Administrator, or changing permission overrides mid-journey.

## Canonical release fixtures

The fixture pack must use the exact stable labels below so screenshots, route records, and denials can be reconciled without exposing production identities.

### Sites and people

- `RELEASE Site Alpha`: active operational Site reachable over the main SD-WAN.
- `RELEASE Site Hidden`: active operational Site not visible to any Alpha actor.
- `RELEASE Client Alpha`: active Client at Alpha with a consent-governed personal tracker and one technical healthcare Device.
- `RELEASE Client Hidden`: active Client at Hidden with equivalent private records.
- `RELEASE Staff Alpha`: current staff member at Alpha with one canonical staff laptop.
- `RELEASE Staff Hidden`: current staff member at Hidden with one private staff Device.

### Canonical Devices and linked records

- `RELEASE Alpha Gateway`: Alpha Network & IT gateway with current direct-path monitoring, topology, configuration, firmware, and capacity history.
- `RELEASE Alpha Switch`: Alpha switch related to the gateway and linked to one monitoring-created Control Room alert and IT incident.
- `RELEASE Alpha Door`: Alpha UniFi Access Device with a fresh observation and one safe governed command fixture.
- `RELEASE Alpha Camera`: Alpha CCTV Device with media available only to an actor holding `securityDevices.cctv.media.view`.
- `RELEASE Alpha Healthcare`: Alpha healthcare Device assigned to `RELEASE Client Alpha`; technical evidence exists but no clinical reading is copied into Security & Devices.
- `RELEASE Alpha Personal Tracker`: canonical Alpha tracker with active purpose, authority, audience, consent, collection, and retention evidence.
- `RELEASE Alpha Fleet Tracker`: canonical tracker installed in `RELEASE Alpha Vehicle` through the active Device-to-Asset link.
- `RELEASE Alpha Environment Sensor`: Alpha Facilities & IoT Device with current observation and maintenance evidence.
- `RELEASE Hidden Device`: any canonical Device at Hidden used for list, count, search, picker, export, and direct-object denial.
- `RELEASE Alpha Vehicle`, `RELEASE Alpha Asset`, and `RELEASE Alpha Financial Record`: canonical Fleet, Asset, and Finance owners linked to their authorised technology projections.

### IT, Control Room, monitoring, and provider state

- A published `RELEASE Access Request` catalogue item and published `RELEASE Network Recovery` knowledge article.
- One requester-owned service request and one agent-owned incident at Alpha, with SLA, public and internal activity, attachment, watcher, task, approval, and canonical affected-Device context.
- One Alpha joiner/mover/leaver provisioning workflow with account, licence, equipment, network, and access-control tasks plus an HR completion handoff.
- One Problem, Known Error/workaround, Change with validation/backout, and Major Incident with a published update.
- One monitoring-created Control Room alert and one canonical IT incident sharing the same valid sealed incident-time snapshot, while live Device state is visibly newer.
- All eight specialised monitoring workers and the SNMP-trap, syslog, and flow listeners current in the deployed runtime; the external heartbeat current; Alpha direct Site readiness current; no collector required for Alpha.
- A separate remote-Site evidence fixture only when the approved collector rehearsal is being inspected. It must not change Alpha into a collector-dependent Site.
- UniFi and Milesight connections with approved Site mappings, safe capability manifests, fresh supervised execution evidence, and no credential or raw payload in browser data.
- A running Queclink native listener and a claimed safe tracker fixture. No Queclink cloud capability may be shown.
- Retained raw/hourly/daily time-series summaries, one configuration/firmware history chain, and a valid private snapshot download for Alpha.

The fixture pack must contain equivalent Hidden records so absence is proven against real private objects rather than an empty database. Secrets, raw provider payloads, private coordinates, clinical readings, real resident data, and live command targets are prohibited in screenshots and fixture labels.

## Deployed browser proof

Record the deployment URL, release identifier, exact source revision, deployment time, browser version, actor, Site, viewport, route, and result for every row. Use production-built client and SSR assets from that deployed release. Confirm there is no `public/hot` development marker and no request is served from a local preview or another worktree.

Run each row at `1440 x 900` and `1280 x 800`. Start a fresh authenticated browser session when changing actors. Directly enter the listed URL as well as using the visible navigation or handoff. A pass requires the expected route, heading, active navigation state, data, and actions to remain understandable without relying on browser back history.

| ID | Actor | Exact routes and workspace | Required action or evidence | Pass criteria |
| --- | --- | --- | --- | --- |
| D01 | `release-requester` | `/it?tab=knowledge`, `/it?tab=catalog`, `/it?tab=my-tickets`, `/it/tickets/{requester-ticket}` | Use knowledge deflection, submit `RELEASE Access Request`, find the new request, add a public comment, then inspect reopen/CSAT only when lifecycle-eligible. | Only own requests and public activity appear. Internal tasks, routing, watchers, affected-Device operations, internal reasons, and private attachments are absent from HTML, page props, and network responses. |
| D02 | `release-it-manager` | `/it`, `/it?tab=tickets`, `/it/tickets/{alpha-ticket}` | Use queue/Site/advanced filters, open the Alpha incident, inspect Site, SLA, thread, attachment, watcher, approval, task, affected Device, live state, and sealed incident snapshot. | Service Desk navigation is clear; the ticket remains one work record; Device and Control Room links open only when source and destination permission both pass; sealed and live evidence are visibly distinct. |
| D03 | `release-it-manager` | `/it?tab=provisioning` | Open the Alpha joiner/mover/leaver workflow and inspect assignment, approval, per-item outcome, reversal, HR handoff, equipment, network, and access-control work. | Every item has owner, state, next action, and failure/recovery evidence. No duplicate HR, Asset, access credential, or Device record is created. |
| D04 | `release-it-manager` | `/it/problems`, `/it/problems/{problem}`, `/it/changes`, `/it/changes/{change}`, `/it/major-incidents`, `/it/major-incidents/{major-incident}` | Follow Problem to workaround/knowledge, Change to validation/backout and linked work, and Major Incident to published updates. | Lifecycle controls match current state, require governed dialogs/reasons, and write into their canonical record. Inert controls, browser-native dialogs, mock panels, and duplicate queues are release failures. |
| D05 | `release-it-manager` | `/it?tab=reports`, `/it/setup` | Follow report deep links back to reproducible ticket filters; inspect teams, queues, services, catalogue, provisioning workflows, API identities, and operations audit. | Reports reconcile to visible scoped work. Setup actions are real and permission-gated. An `it_manager` is not promoted to `admin` to expose SLA policy editing. |
| D06 | `release-it-manager` | `/security-devices`, `/security-devices/sites`, `/security-devices/devices` | Navigate the grouped Overview, Workspaces, Operations, and Setup side navigation; filter Alpha; search both Alpha and Hidden fixture names. | Estate health, findings, coverage, Site impact, change, and required action reconcile. Hidden names and counts never appear. The sidebar remains legible and the active item is unambiguous. |
| D07 | `release-it-manager` | `/security-devices/network-it?tab=map`, `?tab=devices`, `?tab=interfaces`, `?tab=services`, `?tab=traffic-capacity`, `?tab=configuration-firmware` | Follow gateway/switch topology, monitor/service, capacity, configuration diff, firmware, Site, Device, IT, and Control Room handoffs. | Native and provider sources are labelled, history is retained, missing evidence is not fabricated, and every handoff preserves canonical Alpha context. |
| D08 | `release-it-manager`, then `release-auditor` | `/security-devices/security?tab=cctv`, `?tab=alarms`, `?tab=access-control`, `?tab=events` | Inspect CCTV, alarms, Access Control, and event evidence; verify auditor read-only treatment. | CCTV media is absent without the sensitive-media grant; credentials/schedules are provider-evidenced; the auditor sees no mutation control; Control Room remains the operational alert destination. |
| D09 | `release-it-manager` | `/security-devices/healthcare?tab=client-devices`, `?tab=shared-site-devices`, `?tab=data-flow`, `?tab=calibration-maintenance` | Open `RELEASE Alpha Healthcare`, Client Profile, maintenance, and authorised IT handoffs. | Only technical health, connectivity, delivery, calibration, maintenance, and minimum Client identity are present. Clinical readings, diagnoses, medication, thresholds, and notes are absent from DOM, source, page props, and network responses. |
| D10 | `release-control-room` | `/security-devices/tracking?tab=personal-safety`, `?tab=fleet`, `?tab=assets`, `?tab=geofences`, `?tab=history`, `/control-room/map` | Inspect purpose-separated tracking, then withdraw the Alpha personal-tracking consent through the approved fixture transition and refocus/reload the page. | Personal, Fleet, and Asset tracking are not combined. Withdrawal removes current location, history, export, map marker, cached state, and direct access. Fleet/Asset operational positions remain independently governed. |
| D11 | `release-it-manager` | `/security-devices/facilities-iot?tab=environment`, `?tab=building-systems`, `?tab=utilities`, `?tab=automations`, `?tab=history` | Open the Alpha environmental Device, observation history, maintenance, Site, and finding handoffs. | Facilities data stays technical and Site-scoped; supported automation status is truthful; unavailable actions are labelled unavailable rather than rendered inert. |
| D12 | `release-it-manager` | `/security-devices/monitoring`, `?tab=findings`, `?tab=coverage`, `?tab=dependencies`, `?tab=trends`, `?tab=collection`, `/security-devices/runtime-health` | Inspect Alpha direct monitoring, all worker/listener states, current advancing observations, dependency/maintenance/confirmation/stale states, retention and storage health, external heartbeat, and one accurate path/runtime correlation. | Alpha says direct path and no collector required. Runtime timestamps advance, no per-Device storm replaces a path finding, no endpoint/credential/raw metric dimension leaks, and the authenticated runtime response is current rather than cached evidence. |
| D13 | `release-it-manager` | `/security-devices/discovery?tab=scopes`, `?tab=runs`, `?tab=candidates`, `?tab=collectors`, `?tab=paths`, `?tab=limitations` | Inspect direct discovery, candidate review, limitation copy, and the separately approved remote collector rehearsal when present. | Direct-first operation is clear; remote collection is optional; scope/run/candidate/collector counts reconcile; private targets, certificate material, queue bytes, and Hidden Site evidence are absent. |
| D14 | `release-it-manager` | `/security-devices/maintenance?tab=due`, `?tab=planned`, `?tab=in-progress`, `?tab=completed`, `?tab=calibration`, `?tab=firmware-configuration` | Follow due work to the canonical Device, Site, IT, configuration, firmware, and healthcare technical context. | One maintenance record owns the work; finance costs remain informational; state-changing controls use named confirmation dialogs and reflect the resulting state. |
| D15 | `release-it-manager` | `/security-devices/integrations`, `/security-devices/integrations/unifi`, `/security-devices/integrations/milesight`, `/security-devices/integrations/queclink`, `/security-devices/settings` | Inspect manifests, mappings, supervised execution status, credential-reference safe state, rotation/test audit, listener status, monitoring policy, Device groups, reports, and audit. | UniFi/Milesight capabilities match approved contracts. Queclink is native-listener-only. No secret, external reference, lease identifier, raw cursor/frame/payload, hostname, or command is exposed. Browser acceptance observes already approved provider evidence; it does not invent or trigger an undocumented provider action. |
| D16 | `release-it-manager`, `release-it-reviewer` | `/security-devices/devices/{alpha-door}?section=management`, `/security-devices/command-batches/{batch}` | Request a safe fixture command with reason and step-up, independently approve/reject as the reviewer, inspect signed dispatch/reconciliation/audit, and inspect one partial bulk result. | Requester and reviewer differ; current Site, Device, assignment, observation, policy, expiry, and signature are revalidated; every child has an independent outcome; uncertain execution is never blindly retried. No live safety-impacting target is used. |
| D17 | `release-it-manager` | `/security-devices/devices/{alpha-device}`, `/sites/{alpha-site}?tab=technology`, `/operations/clients/{alpha-client}?tab=healthcare_devices`, `/operations/clients/{alpha-client}?tab=location`, `/hr/people/{alpha-employee-profile}?tab=assets`, `/fleet-assets/vehicles/{alpha-vehicle}?tab=technology`, `/fleet-assets/assets/{alpha-asset}?tab=technology`, `/control-room/alerts/{alpha-alert}`, `/it/tickets/{alpha-ticket}` | Traverse each visible canonical handoff in both directions. | Each projection states its owning module, carries only minimum necessary fields, links to the same canonical Device/ticket/alert, and becomes an explicit access-required state when destination permission is absent. |
| D18 | `release-denied`, then `release-source-denied` | `/security-devices/devices/{alpha-device}`, `/it/tickets/{alpha-ticket}`, `/control-room/alerts/{alpha-alert}`, `/fleet-assets/resident-tracking/history/{alpha-client}` | Enter direct URLs, search Alpha labels, and inspect counts, pickers, and exports first as the Hidden-Site actor and then as the Alpha actor that lacks the parent source permission. | A Hidden-Site direct object is concealed as `404`. A missing parent-module permission returns `403` or removes the destination as designed. Alpha names, identifiers, counts, picker choices, export rows, coordinates, media, and source fields never leak. Do not change permissions during the browser run. |

Use stable fixture identifiers in the evidence manifest to replace each `{placeholder}`. Never place a production identifier into this runbook.

## Viewport, interaction, privacy, and console criteria

Every D01-D18 row must pass all of these criteria at both required viewports:

- `document.documentElement.scrollWidth <= document.documentElement.clientWidth`; any horizontal page overflow fails.
- Side navigation, local tabs, filters, data tables/cards, right rails, maps, and governed dialogs remain reachable without clipped primary actions.
- Long labels, references, timestamps, status text, and empty/restricted/error states wrap without covering another control.
- Keyboard focus is visible; tab order reaches navigation, filters, rows/cards, dialogs, and close/cancel/submit controls; Escape closes only the active governed dialog.
- Icon-only actions have an accessible name. Primary actions and compact icon actions retain their established accessible target size.
- There is no uncaught exception, `console.error`, severe framework error, failed production asset, unexpected 4xx/5xx request, hydration failure, or infinite retry. Record any expected denial request separately; it must match D18 exactly.
- Browser source, Inertia page props, Fetch/XHR bodies, downloaded evidence, and screenshots contain no prohibited Hidden, clinical, personal-location, credential, raw-provider, or private runtime value.
- Loading, empty, restricted, unavailable, degraded, stale, failure, recovery, and success states use plain text plus an icon or equivalent semantic cue; colour alone is insufficient.
- Every visible control changes a supported state, opens a governed dialog, downloads real governed evidence, or navigates to a valid permission-safe route. `#` actions, browser-native confirm/alert/prompt calls, coming-soon controls, and silent no-ops fail the release.

## Direct-object and privacy denial sequence

Do not infer denial from a missing navigation item. For D18, record the actual HTTP result and verify response-source absence:

1. With the correct parent permission but only Hidden Site access, enter an Alpha Device, ticket, alert, Client tracking history, document, snapshot, command evidence, and export URL. Require concealed `404` wherever object concealment is the contract.
2. With the exact Site but without the parent source permission, enter the source route. Require `403` or the documented access-required destination state; never use an elevated role to continue.
3. Search Alpha and Hidden fixture labels from lists, groups, counts, map, reports, picker dialogs, and exports. Only the actor's approved Site may appear.
4. Withdraw personal-tracking consent in the prepared fixture transition, return focus to the open page, and require cached map/history/export data to disappear before another navigation.
5. Confirm the requester never receives internal IT activity and that healthcare/CCTV sensitive fields remain absent without their independent permission.

## Runtime, provider, and restore companions

The browser matrix inspects evidence surfaces; it does not manufacture operational proof.

- Complete the live protocol/provider observation contract in [Protocol and policy release acceptance](monitoring/protocol-policy-release-acceptance.md) before D12 and D15. Attach its supervised observation identifiers and time window, not secrets or raw payloads.
- Complete central runtime/outage and independent-heartbeat evidence in [Runtime or regional outage](monitoring/runtime-and-regional-outage.md). D12 must reconcile to that same deployed observation window.
- Complete remote collector evidence only through [Collector outage and revocation](monitoring/collector-outage-and-revocation.md). A local or same-instance replay sample is not deployed collector proof.
- Complete the isolated MySQL, Redis, time-series, private object-store, and secret-manager rehearsal in [Monitoring storage restore](monitoring/storage-restore.md). Record its value-free zero-gap report, RPO/RTO, load/soak/latency/outage results, credential rotation/containment result, and backup generation.

After the restore companion is green, run D07 configuration/firmware history, D12 trends/collection/runtime health, D15 credential-reference safe state, and D18 allowed/denied Site reads against the isolated restored application. Record them as **restored-environment browser evidence**, separate from the primary deployed release evidence. A rendered trend, snapshot row, or green UI badge does not by itself prove that all stores were restored or that RPO/RTO was achieved.

## Local automated evidence

Local Unit, Feature, Architecture, React, type, lint/format, client-build, SSR-build, and local Dusk results are prerequisite regression evidence only. They do not prove that the deployed URL is running the reviewed source revision, production assets, supervised workers/listeners/SSR, real provider protocols, or restored stores.

Run the dedicated IT/Security Playwright regression at both approved desktop viewports with:

```bash
npm run visual:test:it-security
```

This command runs only `it-security-desktop-1440` and `it-security-desktop-1280`. The retained legacy mobile project remains outside IT/Security acceptance.

Record the local gate by source revision and attach its summaries without copying test databases, `.env.dusk.local`, Playwright state, screenshots, logs, SQLite files, or command-output files into the release. Never relabel a local Dusk result as deployed browser proof.

## Completion record

V10 remains open unless D01-D18 pass at both viewports against the deployed release, all denial probes pass, the evidence package contains no protected values, and the runtime/provider/restore companions are complete. Record any failed row with actor, route, viewport, expected result, actual result, screenshot or safe console reference, owner, and retest status. Do not mark a partial matrix, an Administrator-only rerun, or a local-only run complete.
