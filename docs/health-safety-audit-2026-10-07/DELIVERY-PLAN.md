# Connected incident workspace: complete delivery plan

**7 October 2026 · Option 3 selected by the user · Planning, not implementation**

The selected direction combines a permanent incident page, a named accountable incident owner, contextual access from people/houses/My Day, and Control Room for live response. Keep short capture/action forms where they help. Bring the authorized work together without creating another incident database or replacing specialist decisions with a generic ticket status.

This detailed plan expands the original ten-package [delivery outline](IMPLEMENTATION-AND-ACCEPTANCE.md). Its HS00–HS27 packages are the implementation sequence; the original C01–C30 scenarios and capability checklist remain acceptance requirements. Read the [product specification](SPECIFICATION.md), [ownership contract](OWNERSHIP.md), [integration contracts](INTEGRATIONS.md), [audit](AUDIT.md) and [three-pass review record](PLAN-REVIEWS.md) with it.

The user's subsequent market-research request is addressed in [MARKET-RESEARCH.md](MARKET-RESEARCH.md), including six product families and 26 variables. The separately authorized Security & Devices coordination adds the [incident-reconstruction contract](INCIDENT-RECONSTRUCTION.md), including early safety-signal prerequisites and R01–R10 shared acceptance.

**Development context confirmed by the user:** Oblivion is still being built and nothing is in production. Start with the shared [synthetic development pilot](../security-devices-audit-2026-10-07/DEVELOPMENT-PILOT.md); it is a fixture specification, not created application data. D1 proves the synthetic/internal pipeline, D2 the installable lab components, and D3 selected real hardware. References below to production/operational activation are future acceptance gates, not evidence of current live exposure or a prerequisite to ordinary development work.

**User's delivery requirement, confirmed 7 October 2026:** implement the entire agreed scope end to end. Packages are internal development and review checkpoints; completing a subset does not fulfil the request. No placeholder controls, simulated successful integrations, disconnected pages or silently deferred in-scope workflows count as delivery. Completion includes persistence, permissions, ownership, forward and return navigation, failure/retry recovery, cross-module integration and verified user journeys. Apply the current [Rory design rules](../../DESIGN.md) and their linked guides throughout, including banners, page headers, navigation, record pages, forms and responsive/accessibility states. This requirement is part of acceptance, not a final cosmetic pass.

**Single final release:** the user's later direct clarification in the Devices chat requires one complete release covering the agreed shared programme. Internal development/test milestones remain useful; no separate partial incident-page or reconstruction release is planned. Reuse the Devices [target scope](../security-devices-audit-2026-10-07/TARGET-SCOPE.md), [equipment research](../security-devices-audit-2026-10-07/MARKET-RESEARCH.md) and [Rory conformance mapping](../security-devices-audit-2026-10-07/RORY-CONFORMANCE.md). Broader device work remains in its owning backlog, with shared readiness at the combined release gate.

## 1. Confirmed direction and planning boundaries

Confirmed by the user: option 3, comprehensive scope, retain/improve functionality, a person who owns each incident, ownership can change, and two to three reviews of the plan. The plan deliberately combines the useful elements of all five options: real pages, contextual person/site entry, urgent operational response and bounded dialogs.

Recommended interpretation of the ownership clarification: one accountable owner at a time, with explicit recorded reassignment whenever overall responsibility changes; separate action/response/review assignments do not silently transfer the incident. The user selected the responsible site manager/coordinator as the default, with an unassigned queue if unavailable. OWNERSHIP.md records the eligibility, specialist restrictions and monitored fallback. Other policy decisions are bounded in section 10 and do not prevent building presentation/read-only improvements.

One operating organisation across multiple sites. Roles, permissions, approved sites, canonical ownership, direct-object denial and privacy are the boundary. Preserve legacy organisational fields where existing source models require them; add no tenant selector, second organisation layer or hypothetical tenant workflows.

The source baseline is main `12d5665c47b0367a60ee9df5dcc46917c94b390f`. eMAR coding has merged; production activation is separate. The Workforce chat is still active and, at the latest planning check, was verifying settings saves/reloads/history in its own preview and preserving newer eMAR handover safeguards. This plan does not instruct either chat or overwrite its work. Refresh the baseline before implementation.

## 2. The finished experience

### Reporting

A support worker starts from My Day, a person, shift or the global Report action. Context is prefilled and validated. The worker describes what happened, actual/potential harm and immediate response, saves/recover drafts and submits once. The receipt identifies the real report, owner or routing exception, response state and return destination. Staff-only or technical events use their correct source intake, without a fabricated client.

### Working the incident

`/incidents/{id}` is a real page. Its first screen answers what happened, who owns it, whether urgent help is still needed, the next action and when it is due. Overview, Response, Review, Actions, Evidence and History reveal the same authoritative records. Empty or restricted sections follow approved access rules; restricted data is not downloaded and then hidden in the browser.

The Incident owner coordinates progress. A response operator, H&S reviewer, investigator, action executor and verifier can be different people. Their responsibilities stay explicit. A user sees their own permitted next action alongside the overall outstanding responsibility, without being led into a predictable access denial.

### Navigation and management

The H&S shell has at most six destinations: Overview; Incidents & concerns; Actions & reviews; Risks & prevention; Safety registers; Reports & assurance. The existing 21-item mapping remains binding. Near misses are a view; specialist functions remain addressable. Use current Rory PageHeader, rails, lists, work-record body, dialogs and date/status patterns. Keep My Day the frontline home.

### Response and learning

Control Room Incoming, if adopted as a label, is a view over actionable new alerts. Triage determines the appropriate source record and response; technical faults do not automatically demand client incidents. Response resolution, H&S acceptance, investigation, corrective verification and notifications have independent gates. Learning returns to the person's support plan, site controls, approved procedures, training or maintenance through those modules' real decision paths.

## 3. Scope ledger

| Scope ID | Required deliverable | Delivery packages | Acceptance evidence |
| --- | --- | --- | --- |
| S01 | Reproducible baseline, complete capability/route inventory and isolated fixtures | HS00 | Inventory and exact preview identity |
| S02 | Truthful banners, current/unknown state, NZ dates and time bases | HS01–02 | C19–20, C26 |
| S03 | Explicit incident owner, safe routing/reassignment, permissions/history | HS03–04 | O01–O16 |
| S04 | Permanent incident page and readable searchable register | HS05 | C02, C25, owner projection |
| S05 | Recoverable capture, amendments and all lifecycle actions | HS06 | C01, C13–15, C23, C28–29 |
| S06 | Connected response/review/evidence/history and permission-shaped summaries | HS07 | C09, C18, C21 |
| S07 | Safety-review/investigation and corrective-action workspaces | HS08–09 | C07, C10–14, C24 |
| S08 | Correct Control Room recording decisions, urgency and duty handovers | HS10–11 | C03–09, C12, C23 |
| S09 | Six-destination navigation, Rory headers, old-link compatibility | HS12 | 21/21 destination parity, C02/C25 |
| S10 | Hazards, risks, procedures and drills | HS13–14 | Per-register parity and failed-finding outcome |
| S11 | Lone-worker monitoring and responder clarity | HS15 | C19 plus session/check-in/panic paths |
| S12 | First aid, restraint/behaviour support, staff injury/recovery | HS16–17 | C17/C21/C30 and specialist parity |
| S13 | PPE, chemicals and worker participation | HS18–19 | Self-service, evidence and register parity |
| S14 | People/sites/shifts/respite/My Day entry and return | HS20 | Forward, receipt, return, denial and retry |
| S15 | eMAR/clinical and Workforce source boundaries | HS17, HS20–21 | C16–18; source-owner regression |
| S16 | Fleet, maintenance, devices/security and adjacent modules | HS22 | Typed source outcome, no false release/closure |
| S17 | Tasks, search, calendar, notifications and deep links | HS04, HS23 | Same identity/owner/scope; delivery versus action |
| S18 | Person/whānau involvement, notification applicability and assurance | HS24 | C22/C30 and redaction |
| S19 | Meaningful metrics, drill-through/export consistency | HS25 | C26 and definition registry |
| S20 | Migration, legacy reconciliation, rollout and operating support | HS26–27 | C27 and release/rollback evidence |
| S21 | Cross-device incident reconstruction, live CCTV and recording references | Shared prerequisites after HS00; HS07/11/22/26–27 | R01–R10; real hardware/provider witness |

Everything in this ledger is part of the programme. “Later package” does not mean removed scope. New external integrations, clinical policy changes, automatic sending, wholesale IT/HR/Finance rewrites and bulk historical corrections are not smuggled into the UI redesign; each requires its own concrete contract where a gap is found.

## 4. Delivery packages and dependency order

Each package produces a small reviewable change or, where marked a/b, separate changes per domain. Do not combine unrelated backend, specialist-register and shared-shell changes merely to reduce the number of pull requests. A package closes only with its stated evidence. Suggested paths are existing anchors unless described as proposed new files/routes; resolve exact current names at HS00.

### HS00 — Baseline and contracts

**Depends on:** none. **Original package:** 0.

Refresh main and related chat state. Inventory all 21 destinations, actions, role gates, reports/exports, draft recovery, query/action deep links, task providers and source integrations. Use the existing source inventory as a starting point, not proof of coverage. Record existing page and domain acceptance contracts, current ownership behavior and all places legacy investigator assignment is consumed. Establish one isolated development/test environment with synthetic records across at least two sites and restricted roles. Preserve shared uncommitted work.

**Files:** route files, policy/controller/service anchors, existing incident/H&S tests; planning evidence. **Exit:** candidate, fixture database, assets/URL, role matrix and capability checklist reproducible; unresolved design decisions labelled; no implementation based on a stale snapshot.

#### Shared safety prerequisites — SD-01 and SD-02

Jointly with Security & Devices/Control Room, repair fall classification/canonical-device/safe-evidence propagation and the blanket maintenance suppression boundary before relying on the sensor-to-response path. These are early safety repairs, not work to postpone until camera presentation. Keep the correct existing nullable legacy device foreign key; propagate the actual canonical device separately. Critical severity is already retained and must remain so. Technical maintenance and deliberate sensor testing need distinct rules. See INCIDENT-RECONSTRUCTION.md for inspected evidence, ownership and R01–R03. These changes can precede the H&S page migration after baseline/policy alignment; no implementation is authorized by this planning document.

### HS01 — Truthful assurance and periods

**Depends on:** HS00. **Original package:** 1.

Remove fixed “synced just now”, “duty met”, “ACC ready” and zero-alert safe claims. Show measured last-successful refresh, expected/active monitoring and missing/failed states. Separate open-now work from reports occurring in a selected period. Preserve existing first-aid/certification unknown states. Avoid falsely claiming complete coverage when an expected-worker denominator is unavailable.

**Files:** command-centre hero/hero kit, H&S Overview and lone-worker callers. **Exit:** C19/C26, empty/stale/error/populated browser states and correct meter drill-through. Implement and verify independently of the owner schema within the single-release programme.

### HS02 — Dates, scope and accessible entry

**Depends on:** HS00. **Original package:** 1.

Use shared NZ date/instant helpers, correctly bounded date filters and explicit time bases. Align menu capability with actual destination; separately document intentional global versus site-scoped reads. Add real record links/kebab/keyboard behavior without changing closure rules. Reconcile index/show/task/evidence scope differences deliberately, not by broadening grants.

**Files:** incident/event indexes, controllers, shared helper callers, sidebar, policies and task-provider scope. **Exit:** C18/C20/C25, restricted-role menu tests, foreign-browser date tests and direct-ID denial.

### HS03 — Incident ownership domain and compatibility

**Depends on:** HS00 and the ownership/read-authority decisions. **Original package:** added before 2.

Implement the selected [ownership contract](OWNERSHIP.md): canonical current owner, minimal routing configuration, audited provenance and concurrency handling. Reuse existing audit/transaction primitives. Add only the schema needed; preserve legacy investigator/history unchanged. Build nullable compatible reads first. Produce a read-only legacy-candidate inventory; no automatic historical assignment in this package.

**Files:** `ClientIncident`, proposed `IncidentOwnershipService`, `ClientIncidentPolicy`, request validation, additive migration, current RBAC/settings authorities, site/user scope. **Exit:** O01–09/O14, explicit permissions and no access-through-assignment bypass. Model/configuration tests are necessary before UI activation.

### HS04 — Assignment actions, delivery and work projections

**Depends on:** HS03. **Original package:** added across 2/7.

Add eligible-owner lookup and guarded assign/reassign/request commands; offer appropriate UI actions on the existing surfaces until the full page lands. Update `ClientIncidentProvider`, journey task/search context, owner filters and assignment notification receipts. All Tasks assignment calls the canonical service; do not leave a parallel generic write path. Replace legacy investigator-as-owner presentation after compatible migration is active.

**Files:** incidents routes/controllers/presenter, ownership UI, `ClientIncidentProvider`, `IncidentJourneyTaskContext`, task action contract, existing notification/outbox facilities. **Exit:** all O01–O16; page/list/Tasks show the same owner; failure/retry and deactivated-owner exception work.

### HS05 — Incident register and permanent page

**Depends on:** HS02, HS04. **Original package:** 2.

Build a genuine page body using Rory's work-record pattern and current PageHeader. Reuse extracted read sections; stop mounting `IncidentDetailDialog` automatically. Create readable title/reference, person/site, occurred time, severity, current work, incident owner and next-due columns. Add Owned by me/Needs an owner as clearly scoped filters. Preserve all existing views. Keep optional Quick view explicitly read-only.

**Files:** incidents index/show, incident detail components, controller detail payload, shared EntityTable/EntityCard callers. **Exit:** C02/C25, O10, direct/reload/new-tab/back/old query link; long narrative and owner labels readable at approved sizes and worker breakpoints.

### HS06 — Capture, correction and guarded actions

**Depends on:** HS05. **Original package:** 2.

Keep multi-section report/create/edit in WizardShell, and short commands in small dialogs. Preserve template/type variants, near-miss data, request UUIDs, server drafts, attachments, shift-specific submission, review, allowed corrections, follow-ups, close/reopen and portal visibility controls. Produce accurate submission/partial-delivery receipts; preserve user input and selected evidence across errors. No nested permanent detail dialog.

**Files:** incident report/detail/action components, controller/draft/closure services, attachment and follow-up endpoints. **Exit:** C01/C13–15/C23/C28–29 and complete incident parity checklist, including reporter versus reviewer abilities.

### HS07 — Connected read model, evidence and history

**Depends on:** HS05–06 for the page; HS11's shared urgency/status contract before activating the new cross-domain next-action ordering. **Original package:** 2/7.

Compose Response, Review, Actions, Evidence and History from canonical source presenters. Separate absent optional work, pending work, hidden details and broken links. Derive next actions from current record policy and actual state. Expose attributable assignment/amendment/decision history without creating a duplicate editable timeline. Preserve attachment preview/download and source privacy.

Distinguish the person's permitted next action from urgent outstanding work requiring a different role. The current presenter checks H&S acceptance before active response and its final fallback marks No action available as complete; do not carry either shortcut into the new overall status. Apply the approved urgency contract, show only permitted risk/owner context, and compute completion from actual domain gates. Integrate the shared reconstruction evidence set as an Evidence section/view, retaining source-by-source permissions and uncertainty.

**Files:** `IncidentJourneyPresenter`, linked operational evidence presenters, `HsVisibilityService`, incident sections and scoped source URLs. **Exit:** C09/C18/C21/C27, restricted role and external-clinician cases, no hidden-source data in serialized page payloads or search snippets.

### HS08 — Safety review and investigation pages

**Depends on:** HS07. **Original package:** 3.

Convert Events show into a real Safety review page and provide addressable investigation work using existing records/routes where available. Preserve source categories, acceptance, investigation proportionality, findings, recommendation dispositions, residual risk, notification/preservation requirements, closure gates and controlled exceptions. An incident page opens the same review, not another investigation.

**Files:** H&S event pages/controllers and detail sections; investigation/recommendation/closure services. **Exit:** C07/C13–15; each investigation state and return-for-change path tested; accepted owner not inferred from assignment.

### HS09 — Corrective-action page and ordinary executor access

**Depends on:** HS08 and participant-authority decision. **Original package:** 3.

Add a canonical show page/route if absent. Show accountable action owner, allowed executor, source finding, due date, evidence and verification. Prefer source-task execution, such as Maintenance, when it already owns the work. Add narrow participant actions only where required; preserve independent verification, return-for-rework, transfer and closure.

**Files:** corrective-action controller/service/pages, source-task links/policies, evidence endpoints. **Exit:** C10–14/C24, owner/executor/verifier separation and exact-source navigation. An ordinary worker is not given broad H&S management simply to complete a task.

### HS10 — Control Room recording decision

**Depends on:** HS00/HS02 source/read-authority contracts and recording-decision policy. Uses existing incident/H&S services; does not wait for their new pages. **Original package:** 4.

Classify alert continuation by client incident, existing specialist record, technical work, no formal report required or undecided. Keep provenance, reason/actor/time and current rule. Update desk counts, handover lenses and next actions together. Reuse one alert store and journey links. Uncertain triage stays visible; no-report rationale cannot override a required source report.

**Files:** `ControlRoomIncidentController`, `ControlRoomDeskService`, source adapters, desk/handover pages and task projections. **Exit:** C03–05/C23, repeated/concurrent link actions, no dummy clients and no hidden undecided work.

Reassessing severity/category is versioned, with actor/reason and previous values. Reevaluate required response/review/reporting and show an impact preview; do not reset elapsed deadlines, rewrite signed decisions or silently transfer a manually assigned incident owner. Response, report-review, ownership/triage, action and external-notification clocks remain distinct. Waiting for external information never automatically pauses every clock. Reuse approved playbooks/templates with versioned suggested actions and duplicate-safe human confirmation.

### HS11 — Urgency, operational ownership and handovers

**Depends on:** HS10 and approved emergency priority mapping. **Original package:** 4.

Implement consistent current-danger precedence, distinct historical SLA breaches and due checkpoints. Make acknowledgement, assignment, response resolution, H&S transfer and incoming-shift acceptance distinguishable. Preserve prepared-snapshot validity and changed-work checks. Expose delivery failures, muted/suppressed rules and freshness without treating device recovery as person safety.

The user requested internal, external or combined monitoring/response. Configure this by approved site/event/service and effective coverage interval, with a named internal accountable coordinator, primary/backup response destinations and explicit failover rules. Sending to an external monitoring company, their receipt/acceptance, responder attendance and actual safe outcome are separate facts. External delay/failure must return visible work to an authorized internal/on-call route. Do not create a second organisation boundary, assume a live provider integration exists or grant the external recipient full incident/CCTV access. Exercise these paths with labelled development simulators first.

**Files:** `AlertPriorityService`, desk/alert workspaces, shift-handover services, relevant signal-delivery presenters. **Exit:** C06–09/C12/C19/C23, equivalent SQL/in-memory order and outgoing/incoming ownership through failed or delayed acceptance.

### HS12 — Navigation and Rory header consolidation

**Depends on:** HS05, HS08–09; coordinate shared sidebar timing with Workforce. **Original package:** 5.

Create the six destination groups, limited register/report hubs and contextual specialist links. Migrate H&S header callers to actual PageHeader and remove duplicate ribbons/boxed navigation. Keep old URLs/query/action deep links and global search aliases. Migrate each specialist header alongside its package; this package establishes the shell and checks the complete mapping.

**Files:** sidebar, search catalogue, H&S shell/header callers, hub routes/pages where needed. **Exit:** 21/21 homes; exact page capabilities; no unauthorized empty shell; label comprehension with worker/coordinator/H&S roles.

### HS13a/b/c — Hazards, risk assessments and procedures

**Depends on:** HS12; source-backed action links depend on HS09. **Original package:** 6.

Separate changes for each register. Preserve hazard controls, site/person context and inspections; risk activate/review/residual/supersede/archive; procedure versions/approval/audience/read acknowledgement/downloads. Use a permanent page for ongoing assessment/review and appropriate bounded forms for edits. An incident requests a new control or procedure review; it does not overwrite an approved source record.

**Files:** each existing register controller/page/policy and source links. **Exit:** full parity per register, history/version boundary, reader permissions and corrective outcome visible back at source.

### HS14 — Emergency drills

**Depends on:** HS12, HS09. **Original package:** 6.

Real drill page with planning, participants/accommodations, start/complete/cancel, findings, evidence, corrective links and next schedule. Preserve permissions and completion prerequisites. A finished drill does not imply its failed findings were corrected.

**Exit:** plan-to-complete/cancel plus failed finding-to-verified-action and exact back-link; no loss of participant/evidence exports.

### HS15 — Lone-worker safety

**Depends on:** HS01, HS11–12; Workforce-dependent coverage coordinated with HS20. **Original package:** 6.

Keep start/check-in/end/panic on My Day and the existing session paths. Management sees expected coverage where established, active sessions, last received signal, next checkpoint, named responder and actual delivery. Preserve authorized locate/panic acknowledgement, operational escalation and report scope. Unknown expected coverage is visible until its canonical source is configured.

**Exit:** ordinary worker self-service, missed/late signal, panic, zero expected, no monitoring, stale/failed source and unauthorized session tests; acknowledgement never fabricates confirmed safety.

### HS16a/b — First aid and restraint/behaviour support

**Depends on:** HS09, HS12; clinical seam coordinated with HS21. **Original package:** 6.

First aid retains treatment/follow-up/evidence and the correct person/staff source. Restraint retains plan, alternatives, monitoring, specialist review/approval, debrief and oversight. Expose allowed incident/review links, person-centred follow-up and consistent dates. Do not collapse clinical and safeguarding decisions or equate all PRN with restraint.

**Exit:** complete register parity, restricted disclosures, source-specific outcomes and source/incident navigation in both directions.

### HS17 — Staff injury, recovery and Workforce impact

**Depends on:** HS09, HS12; interface agreed against current Workforce implementation. **Original package:** 6/7.

Real injury/recovery workspace where ongoing work needs it. Preserve treatment, claim data, capacity, modified duties, RTW plans, private evidence, wellbeing view and WorkSafe links. Send only approved effective restrictions to canonical Workforce eligibility. Show an impact receipt with affected duties and responsible coordinator. Preserve active-duty relief, published-shift review and historical pay records.

**Exit:** C17/C18/C21, interval/expiry/revocation/correction, no private medical narrative in roster, and no automatic reassignment or cancellation from a free-text allegation.

### HS18a/b — PPE/equipment and chemicals

**Depends on:** HS12, HS09. **Original package:** 6.

PPE: types/inventory, allocation, self-acknowledgement, applicable fit/training, inspection, return, condemnation/disposal, evidence and maintenance. Chemicals: SDS/version/status, storage, exposure and appropriate care/injury links. Preserve exports and authorized attachment access. Show incomplete inventory/unknown currency rather than false compliance.

**Exit:** every lifecycle/action in the parity checklist, failed inspection/exposure outcome, worker acknowledgement on My Day and no accidental inventory release on incident closure.

### HS19 — Worker participation

**Depends on:** HS12. **Original package:** 6.

Group representatives, committees/meetings and consultations within Participation. Preserve attendees, minutes, documents, status, completion/cancel and exports. A raised concern has a responsible responder and visible outcome; sensitive concerns use the appropriate restricted route. Participation is accessible without requiring a worker to manage H&S administration.

**Exit:** raise-to-response-to-outcome, meeting/consultation parity, restricted evidence and source-linked preventive action.

### HS20 — Worker, person, site, shift and respite seams

**Depends on:** HS06–07; relevant register/source package before its changed outcome is activated. **Original package:** 7.

Repair entry and return from global reporting, My Day, people, site profiles, Operations shifts, roster actions, care plans, respite daily notes and evidence/discharge packs. Preserve source identity, occurrence scope, date/view/scroll context and permission. Feed only current permitted support actions into care handovers. Coordinate training/competency, work-capacity and monitoring readiness with Workforce-owned rules; no second roster or fatigue engine.

**Exit:** C01/C18/C23/C28, five-part seam check in section 7 for each integration family, including changed assignment/site while editing.

### HS21 — Medication and other clinical seams

**Depends on:** HS07, relevant specialist packages; current eMAR baseline confirmed. **Original package:** 7.

Reuse medication-error/controlled-drug/PRN source reporters and canonical links. Show authorized source summary and exact return to error/person/chart; preserve clinical review, stock, order revision, competency and administration authority. Check observations/protocols/support-plan entry points for actual existing incident paths before adding any new intake. Preserve dedicated external clinician isolation. H&S reporting does not activate pharmacy or prescriber connections.

**Exit:** C16/C18/C21/C23 plus current eMAR seam suites; no double reporting, unexpected prescription/stock changes or broadened external access.

### HS22a/b — Fleet/maintenance and devices/adjacent modules

**Depends on:** HS09–11; relevant source workflow already exists. **Original package:** 7.

Fleet/maintenance: retain claims/police/driver/vehicle facts, client cascades, work orders and source restrictions. H&S verifies corrective outcome; Fleet retains safety release/custody, Finance retains spending/claims approval. Devices/security: preserve approved site/device/capability, signal provenance, correlation, suppression, recovery and evidence access. IT faults stay technical unless triage establishes a separate incident need. HR/complaint/privacy/governance summaries must use their real restricted source policies.

**Exit:** C03–05/C12/C21/C23, source completion versus safety release/financial approval, device recovery versus person safety, protected HR/safeguarding summaries and retry-safe links.

#### HS22c — Shared reconstruction evidence and provider references

Devices owns immutable source observations, historical device assignments and supported camera live/recording capabilities. Extend or extract compatible evidence primitives without making the existing IT-ticket evidence model the care-incident authority. Control Room collects an evidence window and confirms/links the canonical incident through the existing bridge. H&S reads the same versioned set, adds attributed notes and requests a justified window expansion. Include relevant sensors, camera/recording references, door/access events, permitted vehicle/staff/person tracking and response history; never infer person identity or exact order from proximity alone. Show missing/stale/offline sources and time uncertainty. Gate live access and recording launch at the source and audit them. See INCIDENT-RECONSTRUCTION.md.

Evidence collection is configurable inside Oblivion: per approved event/source/site, define pre/post-event duration, permitted source types, collection trigger, bounded maximum and authorized override. Store applied configuration/version on the evidence set; a later settings change does not rewrite existing collections. Privacy and source capability limits always constrain collection. A five-minute-before/fifteen-minute-after window is only a proposed synthetic fixture, not operational policy. Simulated automatic collection and manual expansion must both reveal the actual time window, coverage and purpose.

#### HS22d — Footage preservation and witnessed fall journey

Live CCTV is inside Oblivion through a supported authorized provider session; recordings may open the vendor. Record availability/expiry and preservation-request versus confirmed preservation/export. Unsupported or failed preservation is visible, assigned work. Do not promise a retained clip from a bookmark. Test the selected supported Milesight model, camera/NVR and configuration with the Devices owner. Hardware/API gaps do not block independent development work, but unresolved in-scope capabilities block the complete final release. R01–R10 must pass for the declared supported device/provider combination before claiming reconstruction readiness.

Use configurable action/source permissions for live, playback, audio, location history and export/preservation, with effective-access preview and audited changes. Recheck current source authority at every entry and operation. Attendance integrations use the shared assisted default, optional manual/app and designated automatic-reader modes: HR owns canonical time and approval; My Day confirms/corrects. Verify those variants and their failure/exception paths under R07/R10. Endpoint platform/provider variation stays in the Devices deployment profile, with no cross-organisation switching.

### HS23 — Tasks, search, calendar and notifications

**Depends on:** HS04–11 and each source package whose projection changes. **Original package:** 7.

Project real owner, relevant due time, source status and permanent page. Keep incident ownership distinct from tasks assigned to a worker. Preserve filters/search and old deep links. Notifications use minimum permitted content and reauthorize on open; queued/delivered/read/actioned differ. Reuse existing notification preferences, deduplication and retry. Calendar dates remain source-owned and NZ-consistent.

**Exit:** O10/O16, C18/C20/C21/C23/C26; no generic task completion bypass, stale recipient leak or duplicate deadline work item.

### HS24 — Person/whānau learning and reporting obligations

**Depends on:** HS08–09/HS23 and approved applicability/disclosure decisions. **Original package:** 8.

Fill proven gaps in accessible account/preferences, advocacy, actual contact outcomes, recovery/debrief and systems learning. Reuse existing fields when equivalent. Track destination-specific decision/owner/deadline/provenance/delivery receipt for applicable notification duties through the owning modules. Preserve manual reporting and signed existing decisions. Recheck current authoritative guidance and provider contracts at implementation; the audit's links are a starting point.

**Exit:** C22/C30, unknown applicability versus not required, timezone/deadline boundaries, authorized representative disclosure and manual export versus actual send. No automatic external sending is activated by this package without a separately configured authorized transport.

Support proportionate learning, authorized related-event themes and effectiveness review through existing investigation/improvement records. Do not merge different events merely because they share a person or description. Multiple affected people retain appropriately scoped source/person reports; use existing originating-event links where supported, and propose only a narrow relationship extension if a gap is proven. Death/discharge/placement changes do not silently finish outstanding reporting or review work.

### HS25 — Overview, reports and assurance

**Depends on:** HS04, HS10–11, HS23–24. **Original package:** 9.

Prioritize actionable queues: unowned/replacement-needed, urgent response, acceptance, review, actions/verification and notifications due. Define each metric's numerator, denominator, time basis, scope, unavailable state and drill-through. Replace site league framing with neutral comparison and reporting-culture context; preserve LTIFR/TRIFR in appropriate reports. Count an underlying incident once; count branch work only when explicitly measuring tasks. Board summaries remain redacted.

**Exit:** C26 with counts/filters/exports matching, accessible charts, no hidden-source inference and useful incomplete-data labels.

State the counting unit explicitly: source occurrence, person incident, operational alert or action. Deduplicating incident/alert/review projections is not permission to collapse several legitimate person reports from a shared occurrence. Only offer occurrence-level aggregation where a trustworthy canonical source identity exists.

### HS26 — Historical reconciliation and operational readiness

**Depends on:** HS03–04, HS10, HS24–25. **Original package:** 9.

Dry-run old query links, missing/conflicting incident/alert/review links, legacy owner candidates, orphaned attachments/tasks and missing notification/acceptance provenance. Produce bounded proposed repair batches with source evidence and review status. Apply only approved unambiguous changes, preserving actual historical facts. Prepare routing configuration, named triage support, role grants, job/queue checks, runbooks, staff guidance and support escalation.

**Exit:** C27/O14 and reconciliation accounting; every unresolved record is visible to an authorized queue; no inferred acceptance/verification or silent bulk rewrite.

### HS27 — Integrated acceptance, pilot and release

**Depends on:** all in-scope packages and shared programme dependencies ready for the one complete final release. **Original package:** 9.

Run the full affected suites against one identified candidate, then real browser journeys with ordinary and restricted roles. Test desktop and changed worker phone surfaces, keyboard, zoom, long content, stale/error states and interruption recovery. Conduct representative worker/coordinator/H&S/operator usability sessions using realistic synthetic scenarios. Use internal capability checkpoints with compatible old links and preserved data. Deliver the full agreed programme; an internal checkpoint or pending external dependency is not a completed user delivery.

**Exit:** all applicable C01–C30, O01–O16, X01–X12 and R01–R10, plus every in-scope register capability and integration row evidenced; operational monitors, rollback rehearsal, migration checks and user guidance ready. A not-applicable decision requires a recorded reason grounded in the agreed scope. No in-scope capability may be omitted to pass this gate. No production-ready claim from document checks alone.

## 5. Milestones and sequencing

| Milestone | Visible outcome | Packages | Gate |
| --- | --- | --- | --- |
| M0 | Reliable scope, environment and policy choices | HS00; shared safety fixes on their own early track | Source/role baseline; sensor-response reliance separately gated by R01–R03 |
| M1 | Truthful status and consistent navigation/date behavior | HS01–02 | Focused source/browser checks |
| M2 | Named owner and genuine incident workspace | HS03–07 | Ownership + incident vertical slice end to end |
| M3 | Connected safety review, actions and proportionate response | HS08–11 | Full report/response/review journey |
| M4 | Six-destination shell and retained specialist depth | HS12–19 | Every old destination/action has a home |
| M5 | Whole-application return paths and supported incident reconstruction | HS20–23, including HS22c/d | Source-to-outcome verification; R01–R10 for supported hardware |
| M6 | Supported-living assurance and reporting | HS24–25 | Applicability, learning and metric evidence |
| M7 | Reconciled, supported release | HS26–27 | Integrated acceptance and rollout |

Milestones describe internally verifiable user outcomes rather than a strictly numeric coding order or separate customer releases. After HS00/02, HS03–06 (ownership/page) and HS10–11 (Control Room decisions/urgency) can progress against the existing source contracts. They meet at HS07; HS08/09 then use the connected page. This removes a dependency loop where response ordering would otherwise wait for a page that itself needed the ordering contract. HS01 is independently implementable/testable. Different specialist registers can progress once shared contracts stabilize, while shared-file integration is serialized. No delegation or additional chats are started by this plan. Do not expose an incomplete owner UI or count a partial milestone as delivery.

This is substantial programme work, not one broad UI pull request. Estimate each concrete package after HS00 and the ownership choices; no calendar promise is made without the current test/runtime and source-owner availability. Small reviewed changes make M1 and M2 available for internal verification while the complete programme is built; they do not replace full delivery.

## 6. Data, permissions and compatibility

- **Canonical records:** keep incident, alert, review, investigation, corrective action and specialist IDs. No second SafetyCase table, case number, mirrored evidence store or generic editable lifecycle.
- **Additive schema:** ownership/routing/recording decisions/notification provenance only where equivalent fields do not exist. Catalogue migration size, indexes and backfill behavior before shipping; old code must tolerate new nullable values.
- **Historical truth:** effective site/person/source at occurrence must not be silently replaced with today's placement. Backfill only evidenced relationships; current corrections show their actual timestamp and author.
- **Access:** test actor ability, record/site scope, sensitivity and exact parent-child on reads and writes. Assignment does not grant access. External clinicians remain in their dedicated portal boundary. Redact server-side.
- **Commands:** expected revision, current authorization, transaction/lock and request identity as appropriate; one authoritative service for every domain transition. Retry returns the committed result; post-commit notification failure remains distinct.
- **URLs:** keep numeric show routes, safe old query/action routes and per-section deep links. Validate return URLs to internal allowlisted destinations; reauthorize the destination and do not echo sensitive query data into logs or broadcasts.
- **Performance:** paginated lists/search, bounded detail sections/evidence/history and aggregate definitions shared with drill-throughs. No full-record history in each table row. Measure representative query/payload performance before choosing numeric thresholds.
- **Documents/evidence:** keep existing attachment storage, validation, access and immutable references; record version/source/author. Draft recovery must not leak narrative into a shared device or another account. Reuse established mechanisms.

## 7. Whole-application coverage contract

Every row in [INTEGRATIONS.md](INTEGRATIONS.md) must receive an implementation disposition: verified unchanged; changed and verified; required gap implemented; or explicitly deferred from activation with reason, responsible owner and remaining test. A static reference or reachable page is insufficient.

For each active seam verify five things: (1) correct authorized entry/context, (2) canonical save/link and duplicate-safe recovery, (3) relevant outcome visible back at source, (4) return to the exact useful source context, (5) changed/revoked access and failure behavior. Read-only seams use the equivalent read/refresh tests without inventing mutations.

| Application area | H&S boundary and package |
| --- | --- |
| My Day, Overview, Calendar, All Tasks | Worker reporting/assigned work and canonical summaries; HS04/20/23/25 |
| People, care/support plans, Operations, respite | Context, continuity and permitted preventive follow-up; HS16/20/24 |
| Sites, inspections, controls and procedures | Historical scope, findings and approved controls; HS13–14/20 |
| Workforce/Rostering, attendance and training | Structured effective eligibility/cover needs; Workforce owns changes; HS17/20 |
| Medication and Health & Clinical | Source clinical decisions, error/protocol links and restricted evidence; HS16/21 |
| Fleet, Assets, Maintenance and procurement | Repair/transport effects without false release or financial approval; HS18/22 |
| Control Room and Security & Devices | Operational signals, response, duty and scoped evidence; HS10–11/15/22 |
| HR and staff wellbeing | Confidential employment/recovery evidence; minimal work restrictions; HS17/22 |
| IT & Support | Technical source work, useful ticket layout pattern and outage consequences; HS22; no unrelated ticket-system rewrite |
| Compliance, Privacy, Governance and Reporting | Destination-specific decisions, minimal summaries and assurance; HS24–25 |
| Settings and role administration | Real eligible owners/routing, scope, current permissions and policy configuration; HS02–04/24/26 |
| Portals, messaging and notifications | Explicit permitted disclosure and secure source links; HS06–07/21/23–24 |
| Finance | Costs/claims references only where authorized; source approvals stay in Finance; HS22 |

Do not add a direct dependency between every pair of modules. Use the actual canonical seam and a narrow projection/receipt. Unconnected capabilities receive an explicit no-direct-integration disposition after inspection, rather than a fabricated workflow.

## 8. Test and evidence plan

Use the existing suites listed in IMPLEMENTATION-AND-ACCEPTANCE.md. Extend only meaningful behavior tests for the new owner contract, genuine pages, triage decisions, privacy and changed integration paths. Do not replace safety tests with snapshot-only component assertions or perform destructive testing in another chat's database.

Each implementation package retains: candidate commit, changed capability IDs, database/fixture identity, environment/asset identity, exact tests and results, role/browser evidence, query/performance observations where affected, known limitations and migration/rollback notes. Record skipped or blocked checks explicitly. The previous audit could not run Vitest because the local dependency was unavailable; implementation must establish a functioning isolated runtime rather than reuse a nonexistent pass.

Minimum actors: reporter/support worker, unassigned worker, site coordinator, explicit global reader, incident owner, investigator, H&S owner, verifier, action participant, operator, incoming lead, clinician, safeguarding officer, private staff-health reader, board reader, representative and dedicated external clinician. Include restricted sites, unrelated sources, inactive users and stale authority.

Browser matrix: 1440×900 and 1280×800 desktop, 200% zoom, keyboard, long titles/narratives, light/dark and alternate branding. Changed frontline My Day/reporting/action surfaces also need below-md phone widths and touch/focus/recovery checks. Do not expand eMAR's approved desktop scope into a separate mobile redesign.

### Additional scenarios from the second plan review and market research

| ID | Scenario | Required result |
| --- | --- | --- |
| X01 | Low actual harm, high potential harm, category-specific reporting | Distinct assessment and applicable reporting decision; no automatic regulatory mapping from a UI severity |
| X02 | Critical response and pending H&S acceptance; viewer cannot act | Approved urgency presentation; permitted escalation/context; no No action = complete shortcut |
| X03 | Late report after move/discharge/death | Correct occurrence/service scope and outstanding work; no current-house rerouting or discharge closure |
| X04 | One transport/site occurrence affects several people and a worker | Authorized linked reports and private staff branch; explicit metric units; no blanket sharing |
| X05 | New evidence changes category/severity | Versioned reassessment and obligation impact; no reset of elapsed clock or signed decision history |
| X06 | Duplicate retry versus two legitimate accounts | Retry idempotent; distinct submissions retained pending human duplicate review; no automatic text merge |
| X07 | Repeated incidents suggest a systemic theme | Authorized learning link and follow-through; underlying events retained and individually scoped |
| X08 | Awaiting external information with several task/notification deadlines | Specific waiting reason/review time; independent clocks; no blanket deadline suspension |
| X09 | Routing/template/policy changes during editing and after manual assignment | Current commit validation/version provenance; impact preview; no silent owner override or duplicated protocol tasks |
| X10 | Notification queued before recipient loses authority | Authorization/redaction rechecked at delivery; old recipient gets no sensitive stale content |
| X11 | Connectivity/upload failure or shared-device interruption | Honest unsaved/pending state; secure recoverable input; no cross-user draft leak or false offline promise |
| X12 | Plan/procedure changes and delayed effectiveness review | Source-owned approval/version and evidence; individual closure does not fabricate later effectiveness |

The total planning acceptance set is C01–C30, O01–O16, X01–X12 and shared R01–R10, plus register parity and integration coverage. These are 68 named scenarios, not 68 tests executed or a substitute for per-source regression suites.

Use the Devices D1 pilot's two synthetic sites and representative logical devices as a shared fixture base; add incident-owner, independent-reviewer and restricted-role cases without creating a second conflicting estate. R01 includes internal/external/hybrid response and failed/unaccepted dispatch variants; R04 includes configurable, bounded collection windows and changed-configuration history. D1 passing permits an honest development milestone; D2/D3 and future operational commissioning remain separately identified.

## 9. Release and support

There is one complete final release of the agreed programme. Internal previews, migrations, synthetic pilots and lab/hardware checks are preparation stages. Prefer existing deployment/configuration facilities; do not invent a large feature-flag system. Keep owner/read compatibility and old routes through transition. If a switch is used, both routes and writes must remain authorized and preserve new ownership/evidence.

Before the final release: complete all agreed scope and shared dependencies; review migrations and dry-run reconciliation where applicable; verify effective role/routing configuration, working queue jobs and observable failures, backup/recovery readiness, the exact candidate, brief role-specific guidance and a responsible support contact. Exercise routine and urgent scenarios across distinct site scopes in the development/pilot environment before operational activation.

Rollback must preserve submitted reports, ownership history, evidence, notification receipts and independently completed work. A prior UI that cannot display or enforce ownership is not a safe fallback for the owner feature; retain compatible backend/reader behavior or suspend affected mutation surfaces with explicit operational support. Do not down-migrate away audit history.

Observe after activation: unowned/replacement-needed age, failed assignment/delivery, duplicate/conflicting links, inaccessible task links, response acknowledgement and overdue verification, stale monitoring and failed jobs. Operational thresholds come from approved policy and measured baseline, not invented pass percentages. Monitoring configuration is planned here; no automation or outbound messages are started by this document.

### Activation ledger

Maintain one ledger per candidate/release with: capability/package ID, exact source and migration version, required roles/configuration, applicable acceptance IDs and evidence, named operational responsibility, dependencies, known limitations, activation state and rollback method. Use Planned, Implemented/unverified, Verified in isolated environment, Pilot verified, Activated, or Held with reason; do not equate an implemented screen with a commissioned workflow.

Each capability below has its own evidence requirements within the same final gate. An in-scope row held for an unresolved dependency keeps the final release incomplete; the ledger does not permit partial releases.

| Capability | Mandatory readiness | Required truthful behavior |
| --- | --- | --- |
| Ordinary incident page and ownership | Canonical assignment service, eligible real users/fallback, current permissions, compatible Tasks/old links, draft and closure tests | Visible pending/restricted branches; no fabricated overall completion |
| New operational triage/priority | Approved classification/priority rules, consistent projections, duty/response gates and failure visibility | Do not enable new ordering in one view while leaving conflicting active worklists |
| Care-sensor response | SD-01/02 corrections, real pipeline tests, configured source/device identity and witnessed supported-model path | Ordinary UI availability does not certify sensor response |
| Reconstruction/live/recording | Supported provider capability, source authorization, historical assignment/time evidence, R01–R09 and actual preservation states | Show unsupported/partial collection or playback; no claims that footage is retained |
| Attendance evidence | HR-approved assisted/manual/designated-reader modes, confirmation/correction/approval paths and R10 | Generic door/location data remains evidence; designated reader capture uses explicit HR policy and does not bypass approval |
| Changed notifications/retention | Actual contract/rule versions, responsibility, supported authorized delivery and verified retention/hold behavior | Manual preparation/export is not a sent notification or a retention guarantee |

Owner-required closure is an explicit policy decision within final readiness. Nullable compatibility and legacy review support development/migration without manufacturing historical assignments. Resolve the affected active backlog/configuration before applying that gate; closed records stay historically intact. This does not create an independent partial-release exception.

## 10. Decision register

| ID | Decision | Recommended planning position | Needed before |
| --- | --- | --- | --- |
| D01 | End-to-end incident accountability | User confirms a named owner who can change; recommend one at a time with recorded reassignment and separate task roles | Direction settled; detail in OWNERSHIP.md |
| D02 | Default recipient | User selected responsible site manager/coordinator; specialist restrictions and monitored unassigned fallback | Product choice settled; configure real recipients before activation |
| D03 | Assignment takes effect | Authorized ordinary assignment immediate with audit; no new acceptance ceremony | HS03/04 command behavior |
| D04 | Scope of global/site incident reading and ownership roles | Explicit existing-policy mapping; no accidental widening | HS02/03 role grants |
| D05 | Restricted/conflict routing | Independent eligible specialist recipient; minimal notifications | HS03 automatic routing |
| D06 | Owner/action/response due and escalation rules | Reuse approved configured rules; keep unrelated clocks distinct | Deadline/escalation activation |
| D07 | Emergency precedence and triage/reporting rules | Current danger first; domain-required reports preserved | HS10/11 activation |
| D08 | Ordinary participant execution authority | Narrow assigned-source/participant action; separate verifier | HS09 activation |
| D09 | Service-contract notification/retention/disclosure applicability | Confirm from organisation's real services/contracts and current policy | HS24/26 activation |
| D10 | Historical owner assignment and repair batches | Evidence-backed current assignment; no fabricated history | HS26 write batches |
| D11 | Monitoring and response arrangement | User wants internal, external or combined; configure approved scope/coverage with internal accountability | HS11 development fixtures; actual provider/failover configuration before operational use |
| D12 | Evidence collection window | User wants customization within Oblivion; bounded per-scope pre/post window and audited override | HS22c; fixture defaults do not establish operational policy |
| D13 | CCTV/location/evidence permissions | User requests configurable access; separate actions and approved source scope, editable templates, effective-access preview, version/audit and current source reauthorization | HS02/07/22 and R07; actual grants before activation |
| D14 | Device-assisted attendance | Recommend assisted default with manual/app and designated automatic-reader modes; dedicated HR authority, My Day confirmation/correction, retained provenance and normal approval | HS20/22 and R10; reconcile with current Workforce implementation |
| D15 | Deployment variability | Separate installations may use different OS/ownership/provider/license profiles; each is one organisation with approved sites | Devices-supported model/action matrix and shared integration tests |
| D16 | Release and design conformance | User requires one complete final release and Rory's current rules throughout; internal stages are not partial customer releases | HS27 and the combined programme gate |

Only D01/D02 were asked of the user now because they directly shape the requested product. D01 was clarified as changeable ownership and D02 selected the site-manager/coordinator default. Market research and the variable matrix refine those choices. For other decisions, inspect existing approved configuration/policy first; escalate a concrete unresolved choice only when it cannot be settled from that evidence. No answer is assumed to authorize a policy change or production deployment.

## 11. Completion definition

The programme is complete when every agreed scope row and existing in-scope capability is implemented and verified, all in-scope pages/workflows and cross-module returns work for the intended roles, ownership is real and consistent, status assurances are truthful, Rory's current patterns govern the UI, and integrated acceptance/release checks pass on the exact candidate. The user can then see one coherent incident journey while specialist teams retain their correct authority. Any proposed scope removal needs an explicit user decision; recording a deferral does not satisfy this completion definition.

No further broad product questions are required before development begins. Inspect existing configuration, source behavior and the other programmes first; use clearly labelled synthetic fixtures where appropriate. If actual provider access, hardware, named operational recipients or an unresolved organisational policy is needed for a specific gate, request that concrete information at that point. Keep the affected gate visibly incomplete until genuine evidence is available; synthetic success must never be represented as a verified live integration.

The planning deliverable is complete when this scope, ownership contract, dependency order, decisions, acceptance and three documented review passes agree. Planning completion does not claim implementation or production readiness.
