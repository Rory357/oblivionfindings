# Revision 10 full read-only audit

**Approval checkpoint 2026-09-19 09:53:08 UTC:** Stephan approved the Section 12 scope/navigation/WF-01–WF-10 and first PKG-01 design release; [exact decision](14-scope-workflow-approval.md). Original audit findings and proposal text below are retained as the reviewed artifact. No mockup/implementation approval is implied.

Owner: MAIN ASTRA. Revision: 1. Date: 2026-09-19.
Authority: Revision 10 + approved A1/A2. Status: **audit delivered; scope/navigation/workflow approval required; no design or implementation authorised**.
Source baseline: local `main@19354ecbc70046d12dfdf9c86f888e65fa1879d1`. This is not a review of the different remote main revision.

## Overall assessment

The application has substantial reusable foundations: one Asset identity for vehicles and equipment; canonical Security & Devices links; scoped, transaction-locked booking creation; guarded key custody; Client consent evidence and withdrawal enforcement; medication transport safeguards; Control Room signals; Finance events; and shared task/calendar providers. A wholesale rewrite would discard useful work.

Completion is nevertheless unsafe to claim. The rendered compliance register calls missing dates compliant, the tracking empty state claims residents are safe, and source inspection finds inconsistent object/site boundaries, incomplete booking readiness, mutable inspection evidence, a missing independent portal location-sharing decision, and inconsistent financial effects of work-order completion. These are proposed P0 priorities. Runtime restricted-role, concurrency and write-path tests were deliberately not executed in this read-only audit.

The recommendation is one Fleet & Assets hub with focused Fleet, Assets and Maintenance workspaces, contextual Site/Client entry points, and existing specialist owners retained. Details are in the [navigation and page inventory](12-navigation-page-inventory.md), [workflow blueprints](02-approved-workflows.md), [requirement coverage](11-coverage-register.md), [integration matrix](13-integration-matrix.md), and [sequence](03-dependency-map.md).

Evidence levels: **High/source** = specific implementation path inspected statically, not executed; **High/rendered** = observed local UI plus source where stated; **Medium/gap** = target not located in the bounded route/model/service/page searches, not proof that no other implementation exists. No finding is an end-to-end PASS. Rough effort is relative: S = contained change, M = several cooperating surfaces, L = cross-module contract/data lifecycle. It is not a time/cost commitment. Proposed additive history/version fields require a reviewed migration; none was made. All acceptance checks below are **future tests**, unless explicitly recorded as rendered observations.

## Fleet

### 1. FA-F01 — Vehicle calendar is missing from the profile; wider calendar also fails at runtime

- **Where/current:** `/fleet-assets/vehicles/{asset}`; rendered test vehicle offers Operations/Technology, Upcoming Bookings and inspection links, with no Calendar or Book this vehicle action. [Vehicle profile](../../resources/js/pages/fleet-assets/vehicles/show.tsx), [weekly BookingCalendar](../../resources/js/pages/fleet-assets/bookings/index.tsx#L144), [calendar controller](../../app/Http/Controllers/FleetAssets/VehicleBookingController.php#L165). **High/rendered + source.** A wider weekly calendar and preselected booking wizard already exist.
- **Additional rendered failure:** clicking Calendar at 09:26 UTC briefly showed its weekly grid, then a blank page at `?page=1&view=calendar&week_start=2026-09-14`. Browser error: `TypeError: m is not iterable` in served `index-D0eIKDoB.js`; the local bundle identifies `m` as vehicles. The controller sorts/maps vehicles without resetting collection keys; non-sequential keys serialising as an object are a plausible cause, **not a verified response-level diagnosis**. The list recovered by navigation; the booking wizard loaded its initial fields and was closed without input/submission. Verify the actual served revision before fixing the runtime failure.
- **Proposal/UI/reuse:** add the Calendar profile view and contextual list/Site actions, using an adapter to Rory's shared SiteCalendar; day/week/month/agenda, Today, legend, accessible form/list, slot-prefilled vehicle/site/time, event details and authorised changes. Keep canonical FleetVehicleBooking and source-owned maintenance events.
- **Behind the page/impact:** consume FA-F02's availability contract; separate busy-only payloads from personal details; unify timezone conversion and stale-save rechecks. Do not create a second reservation store or depend on Google/external calendar subscriptions.
- **Priority/effort/dependencies/risk:** P1/L; FA-F02, FA-T01 and approved calendar rules; conflict/privacy regressions.
- **Acceptance:** list-to-calendar and direct reload work with sorted/non-sequential vehicle IDs, empty and populated weeks; same vehicle remains selected across profile/Fleet/Site views; demonstrate populated, pending, maintenance, conflict, restricted busy, detail/edit and error states; keyboard booking works; overnight and Auckland DST boundaries agree across views.

### 2. FA-F02 — Availability and readiness do not protect every transition

- **Where/current:** booking create/approve/checkout/cancel/return. [Controller](../../app/Http/Controllers/FleetAssets/VehicleBookingController.php#L355) serialises creation on Asset and blocks overlapping pending/approved/checked-out periods. Eligibility uses the requester and licence expiry at `now()`. Approval/checkout primarily validate status; cancellation accepts checked-out bookings; overlap ends at scheduled end even if unreturned. [Incident off-road methods](../../app/Http/Controllers/FleetAssets/IncidentController.php#L413) change incident fields, not the inspected booking decision. **High/source.** No live unsafe checkout was attempted.
- **Proposal/UI/reuse:** one server availability/readiness assessment at create, allocate, approve, edit/extend and checkout; visible blocker/warning/unknown list with source, freshness, responsible resolver and next action. Distinguish requester/driver. Pending holds expire explicitly; unreturned vehicles remain unavailable beyond scheduled end. Cancellation of in-use work requires a custody recovery outcome.
- **Behind the page/impact:** reuse Asset locks and access service; integrate active restrictions, compliance, maintenance/manual intervals, capacity/equipment, full-period HR eligibility and approved buffers. Add durable hold/expiry/revalidation evidence only after the contract is approved. No generic safety/consent override.
- **Priority/effort/dependencies/risk:** P0/L; FA-F03/F05/F06, HR policy and restricted-detail contract; safety and concurrency.
- **Acceptance:** concurrent empty-slot requests cannot double-book; a new defect, expired licence, retired vehicle or overdue return blocks approval/checkout; pending expiry frees only its own hold; reschedule/extension rechecks atomically; future bookings receive owned resolution tasks without silent moves.

### 3. FA-F03 — Missing compliance dates render as compliant

- **Where/current:** `/fleet-assets/compliance` displayed five vehicles with blank expiry fields, each `OK`, and `100% of fleet compliant`. [ComplianceController](../../app/Http/Controllers/FleetAssets/ComplianceController.php#L46) starts at `ok` and ignores absent dates; [page](../../resources/js/pages/fleet-assets/compliance/index.tsx#L157) derives the percentage. Dashboard/vehicle strips also show Current. **High/rendered + source.**
- **Proposal/UI/reuse:** show Unknown/incomplete, Not applicable with recorded basis, Due and Expired separately; display applicability, evidence and next action per vehicle. Add a compliance case journey from due item to internal appointment, provider confirmation, downtime, pass/failure, repair/retest and next due date within Maintenance.
- **Behind the page/impact:** one vehicle-specific compliance/readiness projection; authoritative NZ applicability review, organisational reminder policy kept separate. Do not infer WoF and CoF are both required for every vehicle or manufacture missing historic dates. Preserve existing documents and history.
- **Priority/effort/dependencies/risk:** P0/M-L; FA-F02/F06, [official-source validation notes](evidence/full-audit-verification.md); false safety assurance.
- **Acceptance:** all-null vehicle is never compliant; a reminder does not itself block a day; an applicable restriction does block use; recording an appointment cannot complete compliance; failed outcome remains restricted until approved evidence/release.

### 4. FA-F04 — Transport demand is not allocated into the booking lifecycle

- **Where/current:** Client Transport request uses [ClientTransportBookingController](../../app/Http/Controllers/Operations/ClientTransportBookingController.php#L20), nullable free-text vehicle and driver, status `requested`, but success says `Transport booked.` Actual [ResidentTransportJourneyService](../../app/Services/Fleet/ResidentTransportJourneyService.php#L228) is a separate guarded journey requiring a vehicle. **High/source.**
- **Proposal/UI/reuse:** Client/Site `Request transport` and Fleet allocation queue: Requested → assessed → awaiting allocation → allocated → completed/cancelled/not fulfilled, with client choice distinct from unavailable suitable transport. Link the request to the existing booking and outing/journey; preserve medication and passenger safeguards.
- **Behind the page/impact:** minimal accessibility/support requirements and canonical driver/asset references; capacity, HR/roster and FA-F02 rechecked at allocation. Add explicit provenance links without copying the care record. Restrict requester/detail visibility.
- **Priority/effort/dependencies/risk:** P1/L; FA-F01/F02, Clients, rostering and medication contracts; false confirmation, duplicated care data.
- **Acceptance:** a request needs no vehicle; confirmation appears only after allocation/approval; one demand record produces one reservation and linked journey; cancellations and unfulfilled reasons remain reportable and permission-scoped.

### 5. FA-F05 — Booking return, keys and equipment handover are separate decisions

- **Where/current:** `/bookings/{booking}`, `/keys`, `/handovers`. Booking return accepts optional odometers and notes; no comparison to checkout odometer or key receipt. [KeyController](../../app/Http/Controllers/FleetAssets/KeyController.php#L175) already locks custody and validates staff/site provenance, and correctly renders unknown key custody. **High/source + rendered.**
- **Proposal/UI/reuse:** contextual checkout/return checklist reusing key ledger and handover records; identify giver/receiver, actual location/time, fuel/charge, condition and required equipment. Link pre-use evidence once. Returned vehicle/missing keys or kit remains an explicit readiness exception.
- **Behind the page/impact:** coordinate booking and custody transitions idempotently; reconcile offboarded key holders through an authorised recovery path rather than weakening existing eligibility guards; additive linkage/history, not ledger replacement.
- **Priority/effort/dependencies/risk:** P1/L; FA-F02, FA-A01 and HR offboarding; false receipt and lost responsibility.
- **Acceptance:** scheduled end/trip completion never proves return; duplicate retry produces one receipt; decreasing odometer is rejected or attributable correction; missing key/kit blocks the next required use and has an owner.

### 6. FA-F06 — Fault, work completion and safe release need one connected journey

- **Where/current:** daily checks, inspections, incidents and `/maintenance/*` are separate entry points. Work orders support `open/in_progress/on_hold/completed/cancelled`; schedules' [markComplete](../../app/Http/Controllers/FleetAssets/ServiceScheduleController.php#L190) advances dates/km without a required completed inspection/repair outcome. [WorkOrderController](../../app/Http/Controllers/FleetAssets/WorkOrderController.php) and [FleetWorkOrder](../../app/Models/FleetWorkOrder.php) retain useful source records. **High/source.**
- **Proposal/UI/reuse:** Maintenance work queue and contextual `Report a problem`: report → triage → category-appropriate hold → owner → approval/provider appointment → work → completion evidence → authorised release → reporter feedback. Distinguish awaiting assessment/approval/parts/repair/release, due reminder, internal appointment and externally confirmed booking.
- **Behind the page/impact:** link original report/check/incident/photos, supplier, cost and release; deduplicate related faults; feed FA-F02 and shared tasks/calendar. Keep work completion and safety release separate. Preserve existing IDs and evidence; add versioned transitions where absent.
- **Priority/effort/dependencies/risk:** P1/L with P0 restrictions/evidence dependencies FA-F02/T02/I01; approvals and safe release.
- **Acceptance:** failed check creates or joins an owned investigation; completing paid work cannot independently release a vehicle; provider cancellation/overrun updates blocked time and affected bookings; failure/retest history survives.

### 7. FA-F07 — Vehicle onboarding and retirement need dependency review

- **Where/current:** shared Asset supports core registration/accessibility/document fields, HR and Finance projections. [AssetLifecycleService::retire](../../app/Services/Assets/AssetLifecycleService.php#L20) locks identity and requires assignment/device release, but does not inspect active/future bookings or key custody. **High/source; profile completeness Medium/gap.**
- **Proposal/UI/reuse:** category-aware vehicle onboarding and retirement review on canonical Asset profile: applicable identity/VIN, ownership/lease, supplier, responsible person, insurance/warranty, capacity/equipment and evidence; retire only after active use/custody and future commitments receive explicit outcomes.
- **Behind the page/impact:** retain trips/costs/bookings/history, reconcile linked Finance disposal through Finance, never hard-delete canonical identity; audit existing column coverage before any additive fields.
- **Priority/effort/dependencies/risk:** P1/M-L; FA-F02/F05/I01; orphaned obligations or duplicate accounting disposal.
- **Acceptance:** trackerless vehicle remains manageable; retirement cannot leave a checked-out booking or key silently active; cancelled/reallocated future bookings and historical financial links remain traceable.

## Assets

### 8. FA-A01 — Static-asset custody and location provenance need completion

- **Where/current:** `/assets/{asset}` and Site Assets share Asset. [AssetAssignmentService](../../app/Services/Assets/AssetAssignmentService.php) atomically assigns/releases with canonical site checks, but does not constitute dispatch/receipt/loan return reconciliation. Asset has both SiteHouseRoom `room_id` and SiteRoom `site_room_id`; scan events are separate. **High/source; complete transfer gap Medium.**
- **Proposal/UI/reuse:** default site/room inventory and contextual assign/transfer/loan actions; separate assigned location, last manual verification, tracker position and reader observation, each with timestamp/source. Issue → expected receipt → confirmed receipt → return/check, with responsibility and exceptions.
- **Behind the page/impact:** reuse assignments/scans/ownership/documents; review room compatibility before additive transfer records, retain client-owned versus communal ownership and Finance subset. Do not make tracking mandatory or automatically treat scans as custody transfer.
- **Priority/effort/dependencies/risk:** P1/L; FA-S03/T01, HR offboarding; location ambiguity and lost equipment.
- **Acceptance:** manual trackerless asset can be received, found, inspected and loaned; cross-site receipt requires authorised parties; missed receipt stays outstanding; old scans never display as GPS/live location.

### 9. FA-A02 — Stocktake needs reconciliation beyond scans

- **Where/current:** authenticated QR lookup and [AssetScanEventController](../../app/Http/Controllers/AssetScanEventController.php) exist; a complete Fleet/Assets stocktake register, discrepancy/sign-off lifecycle was not found in scoped routes/models/services/pages. **Medium/gap.**
- **Proposal/UI/reuse:** Assets → Stocktake: freeze expected scoped inventory → observe by desktop lookup/scanner/manual entry → discrepancy queue → investigate → authorised correction/transfer → sign-off with unresolved items visible.
- **Behind the page/impact:** reuse Asset and scan evidence; add stocktake/observation/disposition records if no approved shared equivalent is found during contract design. Bind recorder to authenticated actor; validate tags, canonical targets and duplicate observation keys. No auto-location rewrite.
- **Priority/effort/dependencies/risk:** P1/L; FA-A01/T01; false inventory sign-off.
- **Acceptance:** wrong/duplicate tag, unmatched asset, missing asset and observation at another Site all produce explicit, attributable outcomes; signed history is immutable and corrections link to actual changes.

### 10. FA-A03 — Components, removable kits and recalls lack a complete operational contract

- **Where/current:** no parent/component, kit completeness or recall-case lifecycle located in scoped operational Asset models/routes; wizard mentions recalls as a reason to capture serials. **Medium/gap.**
- **Proposal/UI/reuse:** relevant Asset profile Components/required kit section and Maintenance recall case scoped to models/serials. Only independently serviced or responsibility-bearing items need records; trivial parts remain work-order detail.
- **Behind the page/impact:** approved parent/component/kit relationships and replacement history; parent movement must distinguish installed and removable items; Finance avoids double capitalisation; category-specific recall holds feed FA-F02/F06.
- **Priority/effort/dependencies/risk:** P2/L essential assessment, conditional implementation; FA-A01/F06/I01; duplicated valuations or lost service history.
- **Acceptance:** replacing a lift preserves its own inspections; moving a vehicle does not silently confirm custody of a removable kit; one recall creates owned affected-resource cases with recorded resolution.

### 11. FA-A04 — Import and setup are incomplete first-use journeys

- **Where/current:** asset creation wizard/category/site fields exist; no operational import preview/mapping/duplicate reconciliation flow found in Fleet/Assets routes. **Medium/gap.**
- **Proposal/UI/reuse:** Assets setup/import within authorised Settings and register: manual-first onboarding, optional tracking later; preview mapping, row errors, duplicate review and reconciliation result, with resumable non-sensitive draft context.
- **Behind the page/impact:** reuse canonical Asset/Site/category and approved policies; idempotent import batches with provenance and dry-run validation; unknown compliance remains unknown. No generic workflow builder or automatic migration of legacy organisation fields.
- **Priority/effort/dependencies/risk:** P2/L; FA-A01/T01/F03; duplicate inventory or false compliance defaults.
- **Acceptance:** repeated import does not duplicate records; invalid rows do not partially apply hidden changes; effective policy/version and reconciliation are visible; optional tracking uses the same existing asset later.

## Sites and geofencing

### 12. FA-S01 — Shared geometry exists, but editing conflates rules and can reset them

- **Where/current:** Site and Fleet use AssetGeofence; retired GeofenceZone rejects writes. [SiteGeofenceController](../../app/Http/Controllers/Sites/SiteGeofenceController.php) fills `alert_config` and `time_rules` with null on save; Fleet editor validates `scope` as vehicle/resident although Site creates house/asset/site. **High/source.** Site overview has a visible Edit Site Geofence action.
- **Proposal/UI/reuse:** Site-led boundary editor with address/position, radius/custom polygon, name, usage and impact preview; distinct policy/rule assignments for vehicles, equipment and consent-authorised people. Use existing geometry identity and editor primitives.
- **Behind the page/impact:** reconcile stored scope vocabulary and rule configuration without erasing history; version geometry and affected rules; canonical Site/object policy on every surface. No parallel boundary store or resident access through ordinary asset permissions.
- **Priority/effort/dependencies/risk:** P1/L; FA-T01/R02 and rule-owner approval; accidental rule loss.
- **Acceptance:** editing the same Site boundary from either entry preserves permitted rule settings and identity; shows affected uses before save; incompatible/unauthorised rule references fail without mutation.

### 13. FA-S02 — Geofence event semantics do not match editor controls

- **Where/current:** [FleetGeofenceService::evaluate](../../app/Services/Fleet/FleetGeofenceService.php#L17) evaluates server-side, keeps state and emits enter/breach/dwell. It emits enter and exit regardless of selected `enter/exit/both`, uses `breach_type === hard` for severity despite editor vocabulary, and contains no occurred-at monotonic guard in the inspected method. Dwell has an idempotency key. **High/source; end-to-end upstream ordering protection requires test.**
- **Proposal/UI/reuse:** rule preview explains actual enter/exit/dwell behaviour; preserve service and signal outbox, implement approved direction/time windows, accuracy/hysteresis and stale-event policy consistently.
- **Behind the page/impact:** test ingest ordering, first observation and simultaneous initial-state creation; versioned boundary evaluation, bounded duplicate suppression and recovery; resident policies remain consent-dependent and Control Room-owned.
- **Priority/effort/dependencies/risk:** P1/L, safety-sensitive; FA-S01/T03; spurious/missed alerts.
- **Acceptance:** exit-only rule never causes an enter response; duplicates/out-of-order points do not roll state backward; jitter does not cause alert storms; recovery and edited geometry are explicit; works with every map closed.

### 14. FA-S03 — Site Fleet and Asset location lenses disagree

- **Where/current:** rendered Fleet by Site shows two vehicles at the test geofence Site; its Site → Operations → Fleet says none. [SiteProfileOperationsPresenter::fleet](../../app/Services/Sites/Profile/SiteProfileOperationsPresenter.php#L117) uses `home_site_id`; Assets uses `site_id`, while other canonical access resolves site/home/client provenance. **High/rendered + source.** Different concepts are valid, but the UI presents an unexplained contradiction.
- **Proposal/UI/reuse:** Site Fleet labels Home fleet versus currently allocated/pickup resources; use approved ownership/location projections consistently, linked calendar and maintenance actions. Site Assets shows room, condition, outstanding transfer and repair context.
- **Behind the page/impact:** distinguish legitimate cross-site use from inconsistent historical data; reconcile through reviewed records, not blanket field synchronisation. Apply booking busy/detail permissions in Site payloads too.
- **Priority/effort/dependencies/risk:** P1/M; FA-A01/F01/T01; hidden resources and indirect privacy leakage.
- **Acceptance:** known site-only/home-only/cross-site vehicles appear under correctly labelled lenses; same resource resolves to the same record and booking calendar; no unrelated booking purpose leaks through a Site view.

## Resident tracking and consent

### 15. FA-R01 — Connect existing consent and tracking entry points

- **Where/current:** rendered Client profile has Relationships & governance → Consents → Manage Consents/Record consent; Snapshot → Location checks consent/assignment before disclosure. Fleet Devices has a Consent tab; tracking assignment and [PersonalTrackingPrivacyService](../../app/Domain/SecurityDevices/Services/PersonalTrackingPrivacyService.php) already bind purpose, authority, audience and retention. **High/rendered + source.** Consent is not absent or wholly hidden.
- **Proposal/UI/reuse:** within existing Client Location and consent sections, show tracking/consent status, review/expiry, applicable restrictions and authorised next step, with reciprocal contextual links. Reuse canonical consent types/versions, authority evidence and DeviceAssignment; no extra confusing profile tab or separate consent store.
- **Behind the page/impact:** keep collection, viewing and sharing decisions distinct; review legal/policy applicability using current sources; pause/withdraw/reassign must invalidate relevant future actions without deleting retained evidence.
- **Priority/effort/dependencies/risk:** P1/M; FA-R02/T01; consent interpretation and discoverability.
- **Acceptance:** user reaches correct consent decision from blocked tracking state; expiry, withdrawal, no device, wrong purpose and reassignment explain different states; ordinary Fleet users receive no client location or sensitive consent evidence.

### 16. FA-R02 — Portal location sharing needs an independent authorisation decision

- **Where/current:** [PortalLocationController](../../app/Http/Controllers/Portal/PortalLocationController.php#L21) requires `canAccessClientPortal` plus an authorised tracking assignment for current/history. [User::canAccessClientPortal](../../app/Models/User.php#L197) checks client/next_of_kin role and the portal relationship; assignment audience requires `authorised_client_care`. A separate recipient/location-sharing grant is not checked in this path. **High/source; no portal impersonation or disclosure test executed.** Existing no-store headers and active-consent checks are valuable.
- **Proposal/UI/reuse:** Client privacy/consent workflow records independently authorised location sharing, named audience, purpose, time limits and review; portal current/history/privacy-status and family event delivery consume that same decision. Tracking permission or kinship alone is insufficient.
- **Behind the page/impact:** reuse consent evidence/authority scopes and portal identities; minimal new grant contract only after policy/legal review. Preserve self-access distinction. Check jobs, exports, real-time/cache invalidation and retention separately; do not backfill a sharing grant from relationships.
- **Priority/effort/dependencies/risk:** P0/L; approved privacy policy, FA-R01/T01; sensitive-location disclosure.
- **Acceptance:** valid tracking + portal link + no sharing decision denies family current/history; specific valid grant allows only its audience/scope; withdrawal/expiry/reassignment removes access in already-open views and queued delivery.

### 17. FA-R03 — Tracking UI confuses visibility/registry status with safety and freshness

- **Where/current:** rendered `/resident-tracking` with zero residents says `All residents safe`, `Safety Score 0%`, `Updated just now`; Devices counts five `active` devices as Online despite August last-seen dates. [Resident tracking page](../../resources/js/pages/fleet-assets/resident-tracking/index.tsx#L1432), [DeviceController](../../app/Http/Controllers/FleetAssets/DeviceController.php#L99). **High/rendered + source.**
- **Proposal/UI/reuse:** replace inferred wellbeing/rankings with factual coverage, last observation, stale/unavailable state, accuracy/battery unknown and actionable alerts. Registration state is not online health. Separate view refresh from telemetry time; preserve Control Room acknowledge → triage → resolve and individual response plans.
- **Behind the page/impact:** shared freshness projection from canonical health/observation records; approved response rules distinguish outing context, overdue return, signal loss and panic. No moving marker or empty alert list proves wellbeing.
- **Priority/effort/dependencies/risk:** P0/M; FA-S02/R01 and Control Room rules; false safety assurance.
- **Acceptance:** zero visible residents says no authorised tracking data; stale devices never count as online merely because active; no battery sample shows Unknown; approved outing provides context without blanket alert suppression; responder/owner/escalation/resolution are traceable.

## Navigation and shared design

### 18. FA-N01 — Consolidate destinations around work, preserving every capability

- **Where/current:** [Fleet sidebar](../../resources/js/components/app-sidebar.tsx#L1595) exposes 33 destinations across many groups; maintenance templates, schedules and work are separate, with keys and HR assets also separate destinations. Browser confirms this menu. **High/rendered + source.**
- **Proposal/UI/reuse:** one hub; focused Fleet, Assets, Maintenance, Maps & boundaries, Reports, Settings; permission-scoped links to Client tracking, Devices, HR and Control Room. Bookings/calendar and transport demand remain distinct views inside Fleet, not another hub. Full Keep/Merge/Relocate decisions in [12](12-navigation-page-inventory.md).
- **Behind the page/impact:** retain deep links, query filters, breadcrumbs/back stack and permission gates; adapt canonical grouped navigation, never twenty flat tabs or a giant form. Remove menu duplication, not working records/actions.
- **Priority/effort/dependencies/risk:** P2/M; scope decision and bounded page rollout; lost discoverability.
- **Acceptance:** every current item maps to a visible permitted entry; old URLs land in the same context; a manager can find global queues and a worker can act through My Day/Site without knowing module structure.

### 19. FA-N02 — Fleet pages still use retired hero patterns and inconsistent states

- **Where/current:** FleetCompactHero/FleetHeroKit imports and rendered banner/card-heavy vehicle profile conflict with current PageHeader references. Site profile already demonstrates grouped sections and shared calendar. [Read-only design rules](04-design-rules.md). **High/rendered + source.**
- **Proposal/UI/reuse:** per approved package use current PageHeader/PageHeaderRail, canonical calendar, list tokens, semantic badges, WizardShell and popup rules; focus density and primary action on the task. Inventory populated/empty/loading/saving/error/denied/stale/destructive states for each package.
- **Behind the page/impact:** change product components only after design approval; **Rory's DESIGN.md and linked approved guides remain untouched**. Existing mobile styles do not expand desktop scope. Do not apply a global style migration in this audit.
- **Priority/effort/dependencies/risk:** P2/M per package; A2/reference rules, FA-N01; focus/contrast/unsaved-state regressions.
- **Acceptance:** approved desktop widths, keyboard focus/return, long names/tables, validation and retry states reviewed in real browser; no success before server confirmation; status not colour-only.

## Maps and settings

### 20. FA-M01 — Map display has no approved Google toggle/fallback contract

- **Where/current:** shared [leaflet-map](../../resources/js/components/leaflet-map.tsx#L54) and drawing map use OSM standard tiles plus Esri imagery; rendered base is coloured. Backend [ReverseGeocodeService](../../app/Services/Fleet/ReverseGeocodeService.php) selects Google/Nominatim separately via configuration. No user-facing unified Maps configuration found in Fleet settings. **High/source + rendered; new settings gap Medium.**
- **Proposal/UI/reuse:** administrator Maps settings: Use Google Maps, usable configuration/status/test, OSM-based fallback; shared interface preserves application markers/geometry/selection. Capability matrix separates tiles/display, address search, reverse geocoding, routing and editing. Greyscale base only, semantic overlays remain accessible.
- **Behind the page/impact:** reuse map primitives, secrets/configuration owner and quota monitoring; define outage/disabled API/key/quota states and bounded retry. Provider terms, attribution, retention/caching/privacy and production capacity must be verified before integration. Internal bookings work with imagery unavailable.
- **Priority/effort/dependencies/risk:** P1/L; provider policy/configuration approval, FA-S01/R02; cost and location disclosure.
- **Acceptance:** off/unconfigured Google uses usable OSM implementation; bad provider state leaves list and booking usable; no secret disclosure, no resident identities sent to providers; geometry is unchanged across provider switch.

### 21. FA-M02 — Notification Save Preferences does not persist

- **Where/current:** `/settings/notifications` has client defaults and `handleSave` that only sets a temporary saved flag. The page says settings are stored locally, but inspected code has only React state; route is GET-only. [notifications.tsx](../../resources/js/pages/fleet-assets/settings/notifications.tsx), [route](../../routes/fleet-assets.php#L97). **High/source + rendered controls; Save was not clicked.**
- **Proposal/UI/reuse:** persist authorised channel preferences through existing notification/Control Room policy, show delivery capability and effective scope, confirmed saving/error states. Family sharing remains controlled by FA-R02, not a channel switch.
- **Behind the page/impact:** durable preference contract, role gate, actual event-to-channel binding, idempotent delivery/outbox and visible failures. Do not silently advertise unavailable channels or suppress required safety response through a personal setting.
- **Priority/effort/dependencies/risk:** P1/M; FA-R02/I03/T03; false assurance about notification delivery.
- **Acceptance:** save/reload retains authorised preferences; network failure leaves unsaved state; one event produces one permitted notification; disabled/unavailable channel clearly explains its effect; receipt is not acknowledgement.

## Integrations

### 22. FA-I01 — Work-order completion has inconsistent and insufficiently approved financial effects

- **Where/current:** [FleetWorkOrderObserver](../../app/Observers/FleetWorkOrderObserver.php#L47) dispatches a financial event at completion using actual cost or estimated cost; no separate financial approval is checked in that path. [FinancialEventService::record](../../app/Domain/Finance/Services/FinancialEventService.php#L67) posts the journal. [bulkAction](../../app/Http/Controllers/FleetAssets/WorkOrderController.php#L304) uses query update, bypassing model observers, unlike individual update. **High/source; no journal was created or accounting test run.** Existing idempotency/retry and journal links must be retained.
- **Proposal/UI/reuse:** Maintenance cost panel shows estimate, approved expense/invoice, posting status/failure and Finance link. Use Finance-owned approval/event contract; operational completion alone must not post an unapproved estimate. Individual/bulk transitions share the same service and per-record audit/outbox.
- **Behind the page/impact:** audit fuel/maintenance/mileage observers, retries, source changes and reconciliation; preserve existing financial events/journals and legacy organisation compatibility. Correct historical postings only through separately approved Finance actions, not automatic deletion/reposting.
- **Priority/effort/dependencies/risk:** P0/L; Finance approval/accounting owner, FA-F06/T03; duplicate, missing or premature journals.
- **Acceptance:** unapproved completion creates no posted journal; approved individual and bulk outcomes match; replay/retry creates one valid posting; failure is visible and recoverable; corrections are linked reversals/amendments with no silent double expense.

### 23. FA-I02 — Driver eligibility and employee journey privacy require separate contracts

- **Where/current:** HR eligibility/assignments exist; booking checks requester eligibility at now. Trip index/playback are Site-scoped and include personal-trip state, but the inspected playback gate is Fleet read plus Site scope, without a distinct private-journey disclosure decision. [VehicleController](../../app/Http/Controllers/FleetAssets/VehicleController.php#L551), [FleetTripController](../../app/Http/Controllers/Fleet/FleetTripController.php#L27). **High/source; policy applicability Medium.**
- **Proposal/UI/reuse:** driver/readiness projection shows only needed HR eligibility/class/full-period validity and shift conflicts; vehicle location, trip detail, personal-use history and exports have explicitly approved audiences/retention, with truthful restricted states.
- **Behind the page/impact:** HR owns employment/licensing and offboarding; Fleet owns booking. Resolve privacy policy before expanding access; do not expose licence/HR detail just to explain a busy slot or create another driver record.
- **Priority/effort/dependencies/risk:** P1/L; FA-F02/T01 and HR/privacy policy; staff-location exposure and incorrect eligibility.
- **Acceptance:** non-driver requester can request for authorised eligible driver; expiry during booking fails where applicable; offboarding reassigns owned work/custody; a general vehicle viewer cannot automatically retrieve private journey detail/export.

### 24. FA-I03 — Bring owned actions into existing My Day, tasks and handover

- **Where/current:** FleetMaintenanceProvider/FleetIncidentProvider and Site schedule obligations already exist. Dedicated Fleet handover accepts/disputes a vehicle condition record. Full upcoming booking → precheck → checkout → return/equipment receipt workflow was not found in desktop My Day source search. **High/source foundations; Medium/gap.**
- **Proposal/UI/reuse:** contextual My Day/Site actions into the same records; every actionable item exposes owner/team, next action, due target, blocker, escalation and acknowledged handover. No competing inbox.
- **Behind the page/impact:** extend shared task/approval/calendar providers and canonical handover; safe leave/offboarding reassignment, grouped notifications and retries. Desktop unsent/submitting/confirmed/failed states; no new offline queue or persistent sensitive browser cache.
- **Priority/effort/dependencies/risk:** P1/L; FA-F04/F05/F06/A01/M02; unowned safety work and duplicate submission.
- **Acceptance:** frontline worker completes authorised own journey without fleet.manage blanket access; rejected save stays visibly failed; shift recipient acknowledges responsibility; sent email alone never closes work.

### 25. FA-I04 — Reports need governed definitions and honest missing-data handling

- **Where/current:** rendered dashboard count/chart disagree; vehicle battery defaults to 0; Community Access uses universal `2+ outings` target, and [ReportController](../../app/Http/Controllers/FleetAssets/ReportController.php#L220) computes staff risk from raw incident counts. [MileageController](../../app/Http/Controllers/FleetAssets/MileageController.php#L224) fixes `0.95` as IRD; reimbursement screen labels 2024/25. Current IRD guidance is period/vehicle-dependent. **High/rendered + source.**
- **Proposal/UI/reuse:** report questions and metric dictionary: period/timezone, source, denominator, authorised scope, freshness, nulls and drilldown. Demand not fulfilled, shortage reasons, maintenance waiting stages, borrowing/readiness and replacement costs. Remove unsupported compliance/individual-risk conclusions; rates are approved Finance policy with source/effective dates, not a universal constant.
- **Behind the page/impact:** reuse reporting and Finance data; reconcile observed trip total/count mismatch and asset/site lenses; distinguish no activity from missing observation/cost. Historical claims retain their applied rate; no retrospective recalculation in this programme without approval.
- **Priority/effort/dependencies/risk:** P1/L; FA-F04/F06/I01/T01; misleading operational/tax decisions.
- **Acceptance:** null cost/distance never masquerades as measured zero; totals match scoped drilldowns; client choice is distinct from unmet resources; a newer rate never silently changes old claims; no automatic person risk ranking from sparse counts.

### 26. FA-I05 — Contractor evidence can start with a staff-mediated workflow

- **Where/current:** work-order estimate/actual cost and notes, asset documents, supplier/vendor and Finance ownership exist; a scoped repairer quote/status/completion portal was not established in the inspected Fleet routes. **Medium/gap.**
- **Proposal/UI/reuse:** Maintenance provider panel with staff-recorded confirmation, quote and completion attachments using existing private document/vendor records; explicitly label externally confirmed versus internal appointment.
- **Behind the page/impact:** internal Finance approval and resource release remain separate. Any future external access requires resource/time scope, audit and revocation; no resident/location/history access by default.
- **Priority/effort/dependencies/risk:** P2/M staff-mediated; external portal P3/L; FA-F06/I01 and document access; unjustified external exposure.
- **Acceptance:** uploaded provider evidence is attributable and private; it cannot approve its own expense or release equipment; expired/revoked external grants, if later approved, deny all access.

## Technical, security and data integrity

### 27. FA-T01 — Canonical object/site boundaries are inconsistent across sibling pages

- **Where/current:** [DailyCheckController::store](../../app/Http/Controllers/FleetAssets/DailyCheckController.php#L134) accepts globally existing Asset under a view-permission route; no scoped re-resolution. Checklist runs, Inspection show/create, ServiceSchedule queries/mutations and Fleet Geofence queries/mutations likewise use global queries/bindings. Incident register/detail/mutations use FleetIncident queries without a Site policy in inspected paths. Compliance is global. [EnsurePermission](../../app/Http/Middleware/EnsurePermission.php) only checks permission keys; these models do not supply an implicit access scope. Work orders have partial Site checks that skip missing site_id and independent `exists` checks for assignee/checklist. **High/source; restricted-account exploit tests not run.** Robust booking/device/asset/key scope code disproves any claim that the entire module lacks access control.
- **Proposal/UI/reuse:** shared canonical resolvers for each read/list/count/picker/export/direct-ID and mutation, with explicit broad-management authority distinguished from action permissions. Use existing UserSiteAccess/SecurityDevicesAccess patterns and source-record policies. Denied/missing states must not reveal foreign metadata.
- **Behind the page/impact:** cover nested incident attachments/followups, geofence targets, schedule assets, inspection booking/asset agreement, unproven site provenance and Client privacy. No tenant boundary or automatic removal of legacy fields. No migration needed solely for policy enforcement; history repair separate.
- **Priority/effort/dependencies/risk:** P0/L, first dependency package; canonical boundary contract; cross-site read/write and sensitive incident exposure.
- **Acceptance:** own/other Site, no Site, archived/conflicting provenance, view-only and manager matrices across list/detail/picker/count/export/write; missing and forbidden numeric IDs have equivalent concealment; invalid request has zero writes/jobs/notifications. Verify full effective middleware and policies on reconciled implementation baseline.

### 28. FA-T02 — Inspection evidence can be overwritten or marked passed without governed answers

- **Where/current:** daily check updates same-day run's author/time/responses. [FleetChecklistRun](../../app/Models/FleetChecklistRun.php) stores template_id and answers, not exact template/rule snapshot. [InspectionController::store](../../app/Http/Controllers/FleetAssets/InspectionController.php#L188) computes pass from absence of any submitted `fail`, without validating supplied question keys against all required template questions. Generic checklist required non-empty `fail` can satisfy presence logic. **High/source.**
- **Proposal/UI/reuse:** immutable submitted run with exact template/version/questions/rules/evidence/author/time; explicit draft/submitted/reviewed states and attributable corrections. Required questions and allowed answer types validated server-side; outcomes follow approved operational templates.
- **Behind the page/impact:** additive version/snapshot and amendment lineage; historical records marked legacy where original template cannot be recovered, never fabricated. Failed outcomes link to FA-F06 restrictions; reconcile duplicate daily submissions transactionally.
- **Priority/effort/dependencies/risk:** P0/L; FA-T01, approved templates and FA-F06; falsified/lost safety evidence.
- **Acceptance:** omitted required question or malformed response cannot yield Passed; editing template cannot alter old completed evidence; second check creates a new attributable record/amendment; concurrent submit/retry does not overwrite another author; failed check cannot silently release resource.

### 29. FA-T03 — Bound scale, retries and partial-failure behaviour

- **Where/current:** several schedules/templates/vehicle selectors use whole-table get; inspections cap at 100 rather than navigable pagination. Some UI metrics catch exceptions into empty/zero. Source outbox/idempotency exists for Fleet signals and medication journeys; incident bridge/Finance dispatch paths catch/log failures, not uniformly visible operational recovery. **High/source; production scale unknown.**
- **Proposal/UI/reuse:** bounded server search/pagination and counts, windowed calendars/maps, visible unavailable/partial states; source-owned idempotency/outbox/retry status and responsible recovery action. Keep shared services; no additional background mobile sync.
- **Behind the page/impact:** query plans/indexes at approved representative organisation/per-site scale; test outbox dispatch interruption, telemetry replay, jobs after consent withdrawal, financial rollback and stale UI retry. Backfills/migrations separately reviewed for retention/locks and reversible rollout.
- **Priority/effort/dependencies/risk:** P1/L; affected package plus owner contracts; hidden failed deliveries, load and duplicate operations.
- **Acceptance:** bounded payload/query counts at agreed fixture scale; old records remain reachable; failed integration differs from empty success; safe retry creates one effect; withdrawal revalidated before delayed personal-location processing/disclosure.

### 30. FA-T04 — Release evidence is incomplete and tied to a divergent baseline

- **Where/current:** local source differs from remote main; local browser bundle matches local manifest filename but no commit metadata proves its source. Demo Admin rendered read-only views, not restricted roles. Test source exists for booking/site privacy, keys, devices, consent, telemetry and medication; none executed in this audit. Branch protection reported false; webhook inspection lacked permission. **High/observed limitation.**
- **Proposal/UI/reuse:** before each approved package, deliberately reconcile baseline, verify served code, define role/scale/failure fixtures and required tests, and carry exact commits plus evidence through existing Main/Designer technical/publication/page-acceptance gates.
- **Behind the page/impact:** no pull/reset, CI execution, deployment or scope broadening implied now. Run write tests only in an approved isolated disposable environment. No credentials, wider GitHub scopes or paid benchmarks requested.
- **Priority/effort/dependencies/risk:** P1/M recurring verification; approval gates and host evidence; reviewing/publishing the wrong code.
- **Acceptance:** test results name revision, environment and fixtures; restricted/concurrency/rollback/desktop accessibility checks actually execute; publication consequences and exact integrated revision verified; failures remain open instead of converting source presence into PASS.

## Future enhancements (not included by default)

### 31. FA-E01 — External calendar sync and advanced recurrence

`/bookings` and vehicle calendar remain internal first. External sync/feeds/advanced recurrence are **P3/L**, optional after FA-F01/F02 privacy and conflict contracts. Reuse canonical reservations and approved calendar adapters; scoped feeds, revocation, recurrence exceptions, timezones, conflict/retry ownership and provider terms require separate approval. Acceptance would require no duplicate reservation on replay, safe revocation and truthful external failure. **Medium/gap; no integration claimed.**

### 32. FA-E02 — RF/RFID observations and contractor self-service

Asset scan and vendor/document foundations can support later hardware observations or a scoped repairer portal; neither is approved implementation. **P3/L** after FA-A01/A02/I05. Reuse observation/custody and private evidence contracts; external identities/reader authentication and duplicate/confidence handling need separate review. Acceptance would prove a reader observation never silently transfers custody and a repairer sees only their authorised job. No speculative hardware integration, new risk-ranking engine or mobile application is proposed. **Medium/gap.**

## Decisions required at the Section 12 gate

1. **Scope:** approve the proposed essential completion work (P0 and P1 findings), with P2 improvements assessed in the stated packages; approve assessment of kits/recalls and import/setup without automatically approving speculative integrations. P3 remains deferred. No existing Revision 10 requirement is deleted by prioritisation.
2. **Navigation:** approve one Fleet & Assets hub and the complete Keep/Merge/Relocate map in [12](12-navigation-page-inventory.md), with specialist records remaining under their canonical owners and existing deep links preserved.
3. **Workflows:** approve the proposed blueprints WF-01–WF-10 in [02](02-approved-workflows.md), including unknown versus passed, request versus reservation, work completion versus release, custody receipt, and independent family sharing. Named operational-policy decisions remain explicit pre-implementation blockers; this is not permission to invent legal authority, safety rules or rates.
4. **First bounded design package:** recommend **PKG-01 — Maintenance: report a problem, immutable check evidence and hold-to-release**, preceded within that package by its FA-T01 access and FA-T02 evidence contracts. Define the shared availability/restriction interface with this journey; P0 privacy, truthful-state and financial safeguards follow before broader calendar/navigation work. The [dependency sequence](03-dependency-map.md) distinguishes bounded sequential packages from programme-wide contracts; no dependent end-to-end workflow is complete early.

**STOP:** Main awaits Stephan's scope/navigation/workflow decision and package release before launching a Designer. No mockup, worker, implementation, product mutation, commit or publication has occurred. A1/A2 approval does not approve these proposals. Rory's approved design-rule files remain read-only.
