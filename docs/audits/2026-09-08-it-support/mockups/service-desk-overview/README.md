# Service Desk Overview — interactive design review

Open `index.html` directly, or run `node serve.mjs` in this directory for an independent localhost preview. The server serves only the generated artifact and chooses an available port. No app preview, backend, database or shared build is involved.

## Recommended design

The Overview leads with three specific actions: draft the overdue first response for IT-2048, assign urgent IT-2053, and review ageing IT-2026 awaiting IT. The first response gets the strongest visual emphasis. Response and resolution clocks remain separate. The ageing action uses recorded age and conversation responsibility; it does not invent an overdue follow-up deadline.

Two other tickets with recorded SLA alerts appear in the supporting list, with featured tickets excluded from this default list. A compact queue selector replaces the second sidebar. Other queues show three rows initially and can expand. Recent activity and measurement limitations follow the work area. The hero remains exactly as before.

## Preserved source and boundaries

- The actual read-only `resources/js/components/it/it-hero.tsx` desk branch and `components/page/page-header.tsx` are bundled directly into the artifact. The actual `PageHeaderRail`, `PageHeaderFilterSelect`, `StatusBadge`, `Button`, `EntityKebab`, `EntityContextMenu`, dialogs and wordmark are also reused. Input hashes are recorded in `source-snapshot.json`; `hero-baseline.json` preserves the previous hero, page header and app stylesheet hashes for comparison.
- Hero title, status logic, both source meter groups (10 blocks), branding ramp, ring, actions, Priority placement, Overview/Tickets rail and Find control are preserved. Natural wrapping remains the source component's behavior. The Overview source supplies no scoped search; none was added.
- The desktop shell is a static recreation of current source chrome. Shell token colours, 58px command bar, 264px sidebar and 20px page gutter are retained. It is not connected to authentication or app navigation.
- Tailwind is compiled only for the isolated artifact from the existing app stylesheet and the imported source components. `build.mjs` never invokes Vite or modifies application files, `public/build`, package files or design guides.
- All 14 tickets, identities, sites, counts and activity entries are synthetic. The rolling resolved sample is empty, average first response is unavailable, and watchdog freshness is unverified. No healthy trend or unsupported measurement is invented.
- Knowledge, Provisioning and Reports retain separate destinations; none is introduced as a Service Desk tab.

## Working interactions

- Choose SLA attention, My work, Unassigned, SLA breached, SLA at risk, Awaiting IT, First reply needed, Waiting on requester, Ageing, All waiting work, SLA unmeasured or All open tickets from the supporting list's queue selector.
- Narrow the worklist with the unchanged hero Priority filter. Local queue counts follow this selection; hero meters and explicitly labelled full-queue links represent the whole synthetic set.
- Switch between all SLA attention, breached and at-risk tickets. Breached and at-risk full-queue links remain distinct.
- Draft first reply opens IT-2048 with a public reply draft. Preview reply confirms the local draft; no message is sent and first-response counts stay unchanged.
- Preview a ticket from its title, View ticket, Review follow-up, activity, kebab or right-click menu.
- Assign an unassigned ticket to the simulated technician. Counts update consistently; no real assignment occurs. The featured assignment card keeps a success state and moves focus to Remaining work instead of disappearing.
- Save an internal note inside the open preview. Notes are temporary and no communication is sent.
- Log ticket opens a clearly labelled simulated draft entry. This is not a replacement design for the production ticket wizard.
- Queue, ticket, shell and cross-workspace navigation open a local simulated destination. Actual source paths are shown for reviewer inspection. No link invokes a production page or endpoint.
- Low-priority SLA attention and zero-count priority queues demonstrate meaningful empty states without claiming compliance.

## Existing destination contracts

Verified in `ticket-view-options.ts`, `ItProvisioningController::applyTicketView`, `it-overview.tsx`, `it-hero.tsx` and `app-sidebar.tsx`:

- My work: `/it?tab=tickets&view=mine` (assigned technician, not accountable ownership).
- Unassigned: `/it?tab=tickets&view=unassigned`; priority links append `&ticket_priority=urgent|high|normal|low`.
- SLA at risk: `/it?tab=tickets&view=breaching`.
- SLA breached: `/it?tab=tickets&view=breached`.
- First response absent: `/it?tab=tickets&view=awaiting_reply`.
- Next public response with IT: `/it?tab=tickets&view=awaiting_it`.
- Waiting status plus requester waiting party: `/it?tab=tickets&view=waiting_requester`. This operational queue is not treated as synonymous with all captured public-conversation responsibility.
- All waiting statuses: `/it?tab=tickets&view=waiting` (requester, vendor and approver examples remain distinct).
- Ageing lane: open over seven days. Its existing full-queue link is `/it?tab=tickets&view=all_open&sort=created&dir=asc`; the destination deliberately shows all open tickets oldest first. No invented `age=7` contract.
- Missing SLA evidence: `/it?tab=tickets&view=unmeasured`.
- Ticket record: `/it/tickets/{id}`.

The mock dataset combines fields already supported across the overview lanes and ticket queue. A later production implementation would need to compose permission-scoped payloads for the combined rows; the mockup is not proof that every lane already supplies every column.

## Focused verification

Visually inspected the action cards, compact supporting list and reply preview in the Codex in-app browser. No viewport override, mobile test or app runtime was used.

Verified the correct featured ticket previews, local reply draft confirmation and unchanged first-response state, assignment totals (unassigned 3 to 2; My work 4 to 5), supporting list expansion, distinct breached and at-risk queue URLs/results, low-priority unmeasured and empty states, and a zero-count High unassigned destination. Escape returns focus to Draft first reply; assignment moves focus to Remaining work. Browser console inspection reported no errors or warnings. The rendered page width matched the current viewport without horizontal page overflow.

Earlier checks also covered waiting distinctions, ageing order and all-open destination, the original Tickets rail, mock ticket entry, internal note simulation and equivalent kebab/right-click actions. These supporting interactions were retained.

The original hero and source primitives remain in the delivered bundle. All changes made for this task are confined to this directory.
