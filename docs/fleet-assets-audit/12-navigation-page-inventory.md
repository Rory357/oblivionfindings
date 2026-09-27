# Navigation proposal and page inventory

**Approval checkpoint 2026-09-19 09:53:08 UTC:** Stephan approved the Section 12 scope/navigation/WF-01–WF-10 and first PKG-01 design release; [exact decision](14-scope-workflow-approval.md). Original audit findings and proposal text below are retained as the reviewed artifact. No mockup/implementation approval is implied.

Owner: MAIN ASTRA. Revision: 3. Updated: 2026-09-27.
Authority: Revision 10 and approved amendments, original Section12 approval and the explicit Transport navigation correction below. Historical audit baseline: `19354ecbc70046d12dfdf9c86f888e65fa1879d1`. Original route inventory and observations remain historical source evidence; the revised destination mapping is the current approved design plan, not implemented application navigation.

## Approved People Locations design destination — 27 September

Add **People Locations** to the Operations navigation in the OPS-PL01 mockup. [Plan and scope](handoffs/OPS-PL01-DESIGNER.md). One workspace contains Map, People, Analytics, Alerts, History and a dedicated Settings page, with separately authorised Clients/Staff/Both views. Existing Client Location, staff/lone-worker, Device, consent and Control Room records remain authoritative. Plan contextual links and safe preservation of existing Resident Tracking/Client Location deep links; do not remove routes or broaden permissions. This changes the proposed Operations navigation only; the Fleet & Assets eight destinations below are unchanged. Actual application navigation is not implemented by this design release, and exact mockup approval is still required.

## Approved Transport correction — 26 September

Stephan questioned why Transport planning, approvals and overview were buried within Fleet. Main proposed a direct Transport entry and a complete operational workspace. Stephan approved: “yes please also notifi the sesion”. This approves the following eight destinations, in order: **Overview · Fleet · Transport · Assets · Maintenance · Maps & boundaries · Reports · Settings**. Transport is a sibling of Fleet within the existing Fleet & Assets hub. It is not a new global application module or an extra hub.

The current published seven-entry source remains a historical implementation result. This correction changes the navigation plan and PKG-05 mockup only; actual navigation implementation/publication awaits the appropriate release. Existing/frozen sibling previews may still display seven items and must be reconciled with this approved plan before their later design/integration acceptance. Main is authorised to notify the existing PKG-05 Designer; other sessions are not contacted or interrupted by this decision. Rory's approved design-rule files remain read-only.

Transport must demonstrate **Overview**, **Requests & approvals**, **Planner**, **Journeys**, and **Returns & handovers** as focused views. The overview shows today's departures, unallocated requests, decisions awaiting the authorised approver, overdue returns and owned exceptions with truthful scoped counts and drilldowns. Approvals reuse the canonical request/booking decision, policy and permission boundaries; no duplicate approval store, invented authority or financial approval follows. Allocation, booking confirmation, actual journey progress, return receipt, handover acceptance and vehicle release remain distinct.

The Transport planner organises demand, vehicles, drivers, escorts and equipment through the existing Fleet booking/readiness contracts. Fleet retains the resource register and fleet-wide vehicle calendar; both show the same booking identities. Outings, passenger accountability and permission-limited medication logistics remain discoverable in Transport and their existing contextual modules. My Day, Client, Site and vehicle/booking entry and return paths remain mandatory. Existing deep links, permissions and source records are preserved; this plan does not prescribe new route slugs or advanced route optimisation.

## One hub, distinct workspaces

Retain one **Fleet & Assets** hub. Its focused destinations are Overview, Fleet, Transport, Assets, Maintenance, Maps & boundaries, Reports and Settings. Each uses the current approved grouped navigation; a workspace opens one useful operational view, not all content at once. Fleet contains Vehicles, Calendar/bookings and operating records. Transport owns the connected demand, approvals, planning, actual journey and handover workspace described above. The vehicle Calendar remains on the vehicle profile. Assets starts with site/room inventory. Maintenance starts with owned work, and distinguishes schedules/templates from completed evidence.

Client tracking remains a permission-scoped specialist entry through Clients and authorised tracking; Device, HR, Finance and Control Room ownership remains visible by contextual links. A shared navigation hub never grants access to personal tracking. No second Fleet hub or separate asset identity is introduced. Final presentation is a future Designer task, not a mockup in this audit.

## Every current Fleet sidebar item

Current source: [app-sidebar.tsx](../../resources/js/components/app-sidebar.tsx#L1595); 33 displayed destinations verified as Demo Admin. Paths below start at `/fleet-assets` unless shown otherwise. Future entries are conceptual destinations, not approved new route slugs. **Merge/Relocate means preserve functionality and compatible URL/filter/back-navigation, not delete a workflow.** No capability-removing `Remove` is recommended.

| # | Current item / route | Decision | Proposed entry; preserved capability and reason |
|---|---|---|---|
| 1 | Dashboard `/fleet-assets` | Keep | Hub Overview; concise owned exceptions and scoped totals, not another navigation directory. |
| 2 | Live Map `/map` | Merge | Maps & boundaries → Map; same authorised markers, list alternative and freshness. |
| 3 | Daily Checks `/daily-check` | Relocate | My Day/Site/vehicle pre-use action and Maintenance → Checks; preserve daily submissions and history. |
| 4 | Vehicles `/vehicles` | Keep | Fleet → Vehicles; trackerless management, contextual calendar/book/report problem. |
| 5 | Trips `/trips` | Merge | Fleet → Activity → Trips; history/playback/personal-use controls retained with approved privacy. |
| 6 | Fuel Logs `/fuel` | Merge | Fleet → Operating records → Fuel/charge; preserve entry, evidence, expense link and export. |
| 7 | Compliance `/compliance` | Merge | Fleet → Compliance queue plus vehicle/Maintenance context; applicability, due work and outcome evidence. |
| 8 | All Assets `/assets` | Keep | Assets → Inventory; site/room/category, ownership, assignment and lifecycle. |
| 9 | HR Asset Register `/hr/assets` | Relocate | HR remains owner; contextual employee-issue projection/link in Assets, no second Fleet sidebar inventory. |
| 10 | Alerts `/alerts` | Relocate | Hub actionable alerts link to canonical Control Room; Fleet-filtered view remains deep-linkable. |
| 11 | Geofences `/geofences` | Merge | Maps & boundaries → Boundaries and Site overview editor; reuse geometry and distinct use rules. |
| 12 | Maintenance Overview `/maintenance/dashboard` | Merge | Maintenance landing/work queue with useful summary, keeping cost and due-service drilldowns. |
| 13 | Work Orders `/maintenance/work-orders` | Keep | Maintenance → Work; report/triage/repair/release, global and contextual filtered views. |
| 14 | Service Schedules `/maintenance/schedules` | Merge | Maintenance → Schedules; date/km/hours where applicable; reminders distinct from appointments/completions. |
| 15 | Checklists `/maintenance/checklists` | Merge | Maintenance → Templates & checks; approved versions separate from completed submissions. |
| 16 | Inspections `/inspections` | Merge | Maintenance → Inspections/history; contextual pre/post-use actions and immutable evidence. |
| 17 | Drivers `/drivers` | Relocate | Fleet → People/eligibility projection; HR remains source, minimal disclosure and actionable links. |
| 18 | Vehicle Bookings `/bookings` | Merge | Fleet → Calendar/bookings; same records as vehicle/Site calendar, approvals and operational checkout/return. |
| 19 | Key Management `/keys` | Relocate | Vehicle/booking custody and Transport returns/handover context; preserve canonical key ledger, Fleet contextual access and recovery, no separate module. |
| 20 | Resident Tracking `/resident-tracking` | Relocate | Authorised Client Location/tracking workspace; contextual specialist link only for permitted users. |
| 21 | Transport Logs `/transports` | Merge | Transport → Journeys; distinct from requests/approvals and planning, preserve actual trip and passenger records and existing deep links. |
| 22 | Medication Transit `/transports/medications` | Relocate | Journey detail and authorised medication logistics queue; preserve exact logistics/eMAR permission boundaries. |
| 23 | Outings `/outings` | Merge | Transport → discoverable Outings view/context, also Client/Site; preserve approved outing and passenger accountability. |
| 24 | Shift Handovers `/handovers` | Relocate | My Day/Site and vehicle custody; scoped pending/disputed queue in Transport → Returns & handovers, recipient acceptance retained. |
| 25 | Tracking Devices `/devices` | Relocate | Security & Devices registry; Fleet filtered pairing/health/consent projection and contextual deep links preserved. |
| 26 | Fleet Incidents `/incidents` | Relocate | Existing H&S investigation/Control Room response; contextual Fleet/Asset report and filtered worklist retained. |
| 27 | Reports & Analytics `/reports` | Keep | Reports landing; task questions and permission-aware drilldowns. |
| 28 | Usage by House `/reports/by-house` | Merge | Reports → Site resource use; preserve comparison with defined site/home/allocation lens. |
| 29 | Mileage Reimbursement `/reports/reimbursement` | Merge | Reports → Approved staff mileage/Finance export; policy/effective rates and approval provenance. |
| 30 | Mileage Claims `/mileage` | Relocate | Staff claim entry plus Fleet/Finance approval view; preserve submit/approve/reject/paid evidence. |
| 31 | Cost Allocation `/reports/cost-allocation` | Merge | Reports → Costs; distinguish estimates/posted actuals and permission-restricted Client allocations. |
| 32 | Community Access `/reports/community-access` | Merge | Reports → Transport demand and participation; preserve factual activity, remove unsupported universal conclusions. |
| 33 | Notifications `/settings/notifications` | Keep | Settings → Notifications beside Maps and approved operational setup; persisted capability-aware preferences. |

## Page, tab, dialog and action inventory

The [static inventory](evidence/source-inventory.md) records **every Route:: declaration** in fleet-assets.php, fleet.php and assets.php, all non-test Fleet TSX page/component paths, selected modal/tab/action declarations and supplementary control anchors, and related job/command paths. It is source evidence, not an executed-route report. The families below explain user work and hidden equivalents. Full URI/method/controller references remain in the linked route sources; never infer write permission from a GET form's visibility.

| Surface family | Pages / secondary surfaces / actions | Audit disposition |
|---|---|---|
| Hub / live map | Dashboard, cluster scope, map/list/filter/layers, quick booking/check/fuel/incident/work-order actions, alert drilldowns | Rendered index/map; FA-I04/M01/N01/R03; source projections inspected. |
| Vehicles | Register/card/filter/export/bulk; detail Operations/Technology; alert-config; home-site/driver/inspection-date/accessibility updates; recent trips/fuel/bookings/documents/incidents | Register and populated profile rendered; update controls inventoried, not submitted. FA-F01/F02/F03/F07/I02. |
| Bookings | Register list/custom weekly calendar, `create` compatibility redirect to wizard; detail; approve/reject/checkout/return/cancel; conflict/status helpers; CSV | Source lifecycle traced; list and initial wizard rendered, wider calendar became blank with a JavaScript error; no transaction executed. Target edit/extend and per-vehicle calendar remain gaps. FA-F01/F02/F05/T01. |
| Trips / fuel | Trip register/playback/data, edit/close/delete legacy write URLs, mark personal; fuel log form/store and CSV | Registers rendered; detail/data/mutation coverage remains source-level. FA-I01/I02/I04/T03. |
| Compliance | Register filters, due/expired summaries, vehicle/work-order links | All-null false-compliant state rendered. FA-F03. |
| Operational assets | Register/create/edit wizard and profile; assignment/release, inspection, maintenance, ownership, documents/download/delete, QR generation/lookup, scan logging, geofence subresource, retirement | Register rendered; canonical service/policy boundaries inspected; mutating actions not exercised. FA-A01–A04/F07/T01. |
| Maintenance | Overview; work-order index/create wizard/detail/update/bulk/export/search; service schedule create/edit/mark-complete; checklist index/create/run; inspection index/create wizard/detail | All landing pages rendered, one historical completed work order visible; source lifecycle inspected. FA-F06/T01/T02/I01. |
| Keys | Current holder/history, checkout/return/transfer dialogs | Rendered unknown custody; atomic guards inspected; no custody mutation. FA-F05. |
| Drivers | Register, profile, scorecard, eligible status/export | Empty register rendered; HR ownership/source inspected; no eligibility edit. FA-I02/I04. |
| Geofences | Register/create/edit wizard, active toggle/delete, asset/Site/Operations compatibility editors | Register and Site overview entry rendered; geometry evaluator and editor contract inspected. FA-S01/S02/T01. |
| Devices | Devices and Consent tabs, detail/pair search/pair/unpair/grant/revoke; legacy consent redirect | Register rendered; canonical device and assignment references traced. FA-R01/R02/R03/M01. |
| Tracking | Tracking/Wandering tabs; assign modal; history/privacy-status; history export; locate-now; unassign; acknowledge panic; wandering-alert legacy page | Empty tracking rendered; source consent/ingest/outbox boundaries and existing tests inspected. Commands/exports deliberately not triggered. FA-R01–R03/S02. |
| Transport | Logs/create wizard/detail/precheck/complete; medication register/pack/return; Client request create/update/delete | Empty logs/medication pages rendered; idempotency, custody and roster service source inspected. FA-F04/F05/I03. |
| Outings | Register/create wizard/detail; start/complete/cancel; resident return/all return | Empty register rendered; routes/actions inventoried; end-to-end passenger journey not executed. FA-F04/R03/I03. |
| Handovers | Register/create/detail; recipient accept/dispute | Empty register rendered; source tests/policies present, not executed. FA-F05/I03. |
| Incidents | Modal-first worklist/detail; report/edit; status/followups; private attachment lifecycle; police report/claim; off-road/back-in-service; CSV and preview | Empty worklist rendered after load; source off-road, privacy and bridge traced. No preview or regulatory action executed. FA-F06/T01/T03. |
| Reports | Hub, by-house, reimbursement/data, mileage submit/approve/reject/paid/export, cost allocation, community access, maps usage | All Fleet report landings rendered; source rates/metrics inspected. Exports/generate/payment actions not invoked. FA-I01/I04/T01. |
| Settings | Notification switches/save; proposed maps/setup unavailable as Fleet settings routes | Controls rendered; local-state-only Save traced. FA-M01/M02/A04. |
| Sites | Existing overview Location/access/geofence, Operations Calendar/Assets/Fleet/Hardware/Technology, rooms/zones, inspection/maintenance/emergency context | Test Site overview/calendar/Fleet rendered; shared calendar day/week/month/agenda verified, no edits. FA-S03/F01/A01. |
| Clients | Profile consent/requests, Location/privacy status, transport and outing context, portal current/history | Synthetic Client Consents and blocked Location rendered; portal sharing source inspected, no portal impersonation. FA-R01/R02/F04. |
| My Day / shared tasks | Existing task, equipment/handover acknowledgements; FleetMaintenance/FleetIncident sources; Site obligations | Source-level gap assessment; no complete frontline Fleet journey certified. FA-I03. |
| HR / Finance / Devices / Control Room | Canonical linked identity, eligibility, posting, technical projection, signal response and task providers | HR register rendered; other integrations source-traced; [matrix](13-integration-matrix.md) states limits. |

## Legacy and background behaviour to preserve

- `/fleet-management`, `/fleet/fuel`, `/fleet/reports`, old vehicle/trip reads, `/assets` and asset detail/alerts redirect into current surfaces. `/fleet-management/maps-usage` and legacy trip write endpoints are active exceptions, not safe deletion candidates. `/fleet-assets/mobile/dashboard` is a desktop compatibility redirect; no mobile work is proposed.
- Create-page routes often open index wizards through `?new=1`; incident detail/report and geofence edit use query state. Retain query context and browser back/close behaviour during consolidation.
- Authenticated QR lookup and QR images remain separate from public telemetry ingest. Token-based ingest has its own authentication/lineage boundary; QR values must never become access credentials.
- Preserve Fleet signal outbox, monitoring ticket dispatch, offline-device detection, auto-alert jobs, reverse geocoding, summarisation and telemetry retention. Personal tracking withdrawal/retention must be checked both at enqueue and consumption where relevant. Scheduled execution, replay and failures are **not verified** by this route inventory.
- Existing historical July PLAN and C/V findings remain history. New issue IDs in [10](10-full-audit.md) do not close or reset those records.
