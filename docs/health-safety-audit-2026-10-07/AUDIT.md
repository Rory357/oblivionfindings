# Health and Safety audit findings

**7 October 2026 · Source and read-only browser audit**

The application has a substantial safety foundation, but the experience exposes its internal module structure. The biggest problems are long navigation, permanent work presented in dialogs, unclear distinctions between report/response/review, and some status labels that claim more than the evidence supports. Improve the existing structure rather than replace it with a generic ticket system.

The recommendation and complete proposed behavior are in [the specification](SPECIFICATION.md). This document distinguishes verified observations, source-supported findings and items requiring runtime validation.

## Evidence boundary

**Later user clarification during planning:** Oblivion is still in development and nothing is in production. The hosted observations below remain valid observations of that build, but no current production exposure, missed real incident or operational-readiness claim is made. Future production gates in the delivery plan are acceptance requirements; the next step is a synthetic development pilot.

- Repository inspected: `C:/Users/steph/Herd/oblivionfindings`, branch `main`. The audit began at `231e57ab90900d9b53d722fa8e00215d2106e9fa`; main advanced during the audit to `12d5665c47b0367a60ee9df5dcc46917c94b390f` (connected eMAR merge). The final source inventory uses the latter commit. The principal H&S finding paths were unchanged by that merge; shared table/layout and external-clinician boundaries were rechecked. Other authorised chats may advance this checkout again. Recheck the candidate before implementation.
- Supplied screenshot: 21 Health & Safety sidebar links, with Incidents selected. The second screenshot identifies the two active audits; it is not an instruction to execute their work.
- Browser: read-only inspection of the existing signed-in Demo Admin session at `https://oblivionfindings.com`. Checked Incidents, an explicitly labelled synthetic incident, its “Open full page” destination, the Control Room desk, H&S Overview, Events, Corrective actions and Lone workers. No record was created, edited, acknowledged, resolved, sent or deleted.
- The hosted build's commit was not established. Browser observations describe that build; source findings describe the inspected checkout. Agreement between them is corroboration, not proof of identical deployment.
- The existing IT ticket address `/it/tickets/57` returned a 500 Server Error during the comparison. The recommended record layout therefore follows the approved local work-record guide and inspected source, not a claim that the hosted IT ticket page was working. Its server failure was outside this H&S audit's repair scope.
- No ordinary-worker, restricted-site, safeguarding-officer or external-prescriber browser session was exercised. This is not authorization or clinical release certification.
- Existing focused frontend tests were attempted. The first invocation was rejected by the local npm wrapper; direct invocation then found no `node_modules/vitest/vitest.mjs` in this checkout. No tests started and no passing automated result is claimed. No dependencies were installed or another chat's test database used.
- The repository contains extensive existing feature/e2e tests. Their presence identifies regression contracts, not a fresh pass. The delivery plan names the required runs.

## Findings ranked by impact

### F01 — High: every active alert is treated as needing a client incident

**Evidence:** source plus browser. `ControlRoomIncidentController::canonicalJourneyQuery()` begins with visible alerts, without a domain-required-incident classification. `applyHandoverLens()` labels actionable unsnoozed alerts without `clientIncident` as `needs_incident`; `presentJourney()` makes the same assumption. `ControlRoomDeskService` repeats it in its aggregate. The desk displayed operational staffing alerts with “Incident record not started / H&S not started”.

**Why it matters:** a device fault, staffing exception, staff-only injury or already-governed fleet event can appear to demand an unrelated client incident. This increases noise and can encourage incorrect linkage. Large demo counts are not proof of production failure volume.

**Fix:** use an explicit triage/recording decision, recognize the canonical domain source, and reserve “Create incident” for applicable work. Undecided stays visible. No-incident-required requires a recorded rationale and cannot bypass a domain's reporting rule. Apply the same decision to the desk, handover list, counts and task projections.

**Acceptance:** technical-only, false-positive, existing-incident, safeguarding, fleet, injury and newly confirmed person-harm examples each receive the correct next action without duplicate records. See C03–C05 in the delivery plan.

### F02 — High: missed deadlines rank ahead of current severity

**Evidence:** source plus browser. `AlertPriorityService::apply()` orders by breached SLA before severity/priority; `sortKey()` also puts breach first. `describe()` assigns critical rank to breached items. The desk explicitly describes breached deadlines as its first ordering criterion.

**Why it matters:** a new critical response can sit below an old lower-severity breach. This is a supported safety risk inferred from deterministic ordering, not evidence that a real emergency was missed. Database and in-memory ordering also need equivalence testing because the latter obtains rank from `describe()`.

**Fix:** add an approved emergency precedence policy, distinguish current risk from historical lateness, and use one ordering contract in every view. Keep overdue work visible without promoting all historical failures into critical harm.

**Acceptance:** a fresh critical alert outranks a low-severity overdue item under the approved policy; equivalent fixtures sort identically in all desk/compact views.

### F03 — High: safety and freshness assurances are not evidence-based

**Evidence:** source plus browser. `command-centre-hero.tsx:292` renders “Safety system · synced just now” as fixed text. At lines 280–282, zero active lone-worker alerts becomes “lone-workers all checked in”. `lone-workers/index.tsx:514` displays “HSWA 2015 · lone/remote duty met”; lines 520–521 include “ACC ready”. The browser showed these assurances with zero active sessions.

**Why it matters:** no alert and no monitored workers do not establish coverage, freshness or legal compliance. The H&S hero kit also derives some “current” labels from zero counts; missing source data needs separate treatment.

**Fix:** actual last-successful refresh, expected-versus-monitored coverage, unknown/stale/failed states, and measured operational facts. Remove blanket compliance assertions. Preserve the existing truthful “Evidence unknown” certification and first-aid-cover states.

**Acceptance:** missing integration, failed refresh, no sessions, expected-but-unmonitored worker and genuinely current evidence all render distinct outcomes.

### F04 — High: visible dates can disagree across the same journey

**Evidence:** source plus browser. Incident rows use the shared New Zealand formatter. `health-safety/events/index.tsx:971–1008` uses browser-local calendar calculations and `Intl.DateTimeFormat` without an explicit time zone for its main text, while its tooltip uses the shared helper. In the inspected browser, one linked synthetic incident appeared on 18 July in Incidents and 17 July in Events. The raw instants were not independently compared, so the observed mismatch may also contain source-data differences; the unpinned formatting is independently confirmed.

Incident and Events date shortcuts also derive days from the browser zone. Controller `whereDate` filters require a corresponding New Zealand-boundary review.

**Fix:** canonical NZ instant and date-only helpers throughout labels, tooltips, filters, exports and relative-day logic. Store/compare instants consistently and convert local selected dates into correct query boundaries.

**Acceptance:** the same underlying instant displays the same local day/time from Incident, Events, Control Room, Tasks and exports in Auckland and non-NZ browsers, including DST changes.

### F05 — High: important read authority is inconsistent and needs explicit policy review

**Evidence:** source. `ClientIncidentPolicy::view()` returns true for `incidents.viewAny`; index filtering narrows only `incidents.viewAssigned` users. Review/close/reopen use an additional site-access function. The current permission may intentionally mean organisation-wide incident reading, but it must not be confused with an ordinary site-manager list permission.

**Risk:** the planned consolidated page must not import a broad read permission into sensitive linked sources. `IncidentJourneyPresenter` and `HsVisibilityService` need field-level checks as well as link visibility; the audit has not established an exploitable leak.

**Fix:** document whether `viewAny` is explicitly global; provide site-scoped everyday reading separately if needed. Reuse canonical source policies for every linked summary, narrative, filename, count, download and export. Do not silently tighten an intentional global role or widen a restricted role during layout work.

**Acceptance:** an explicit global reader, site manager, assigned worker, unassigned worker and restricted-source viewer have tested, distinct results, including forged IDs and changed access after page load.

### F06 — Medium: “Open full page” is another modal

**Evidence:** source plus browser. `resources/js/pages/incidents/show.tsx` is a thin shell with an always-open `IncidentDetailDialog`; closing returns to `/incidents`. Clicking “Open full page” changed the URL but left the same dialog experience. H&S Events and Emergency Drill show pages use the same architecture.

**Impact:** weak reading space, nested workflow actions, poor overview and return context, and confusing page terminology. There is a valid route; the missing feature is a genuine page body.

**Fix:** permanent record workspace with separately invoked short action dialogs. Reuse current detail payloads and command endpoints; do not rewrite lifecycle logic merely to remove a modal.

### F07 — Medium: 21 navigation choices mix different types of destination

**Evidence:** supplied screenshot, browser and `app-sidebar.tsx:1344` onward. Near Misses is already the same incident register with a query. Fleet, Compliance and Privacy are other modules. Events is a governance layer, while PPE and Chemicals are specialist registers.

**Impact:** users must choose a database/domain category before finding the work. Multiple sidebar entries, workflow ribbon, large hero metrics and register tabs repeat navigation at the same time.

**Fix:** six destinations with the full 21-entry mapping in the specification. Keep specialist reporting visible and all old links compatible.

### F08 — Medium: menu access does not match route access

**Evidence:** source. The sidebar exposes H&S Dashboard, Events and several registers to `compliance.view`, whereas their routes require `hazards.view`. Analytics is also offered with `reports.viewAny`, but its page route requires `hazards.view`. First Aid is offered with `clinical.dashboard`, while its route requires `hazards.view`. Procedures is offered on hazard/compliance permissions, but its route requires `procedures.view`.

**Impact:** limited roles can receive dead-end navigation; a procedures-only reader can also miss an authorized destination. This is a source-level mismatch; a limited-role browser reproduction remains required.

**Fix:** generate link capability from the exact destination permission/projection. Do not fix this by granting all users broader H&S permissions.

### F09 — Medium: banners and list navigation predate Rory's current contract

**Evidence:** source plus browser. H&S re-exports `HeroShell` from `components/command-centre/hero-kit.tsx:29`, which builds its own gradient, padding and large shadow. Incident and H&S pages combine that shell, workflow ribbon and a separately boxed tab strip. Importing `PageHeaderPrimaryButton` alone does not make a page use `PageHeader`. The browser incident view also lacked the Home breadcrumb and linked meter-row anatomy.

**Fix:** use the actual `PageHeader`, connected rail, shared list and work-record components. Preserve semantic status meaning and compact desktop dimensions. Do not edit the approved design guides.

### F10 — Medium: incident rows are mouse-oriented and bury ownership

**Evidence:** source plus browser. `incidents/index.tsx:1017` opens rows with `onClick`/`onContextMenu`; the row has no equivalent link, `tabIndex` or keyboard handler. The main reference is text. Columns emphasize date/type/client/severity/status/source/flags, with no accountable owner or next action. A follow-up row has the same click pattern.

**Fix:** real reference/title link, shared kebab/context-menu parity, explicit owner and next due/action, purposeful status text, and concise factual summaries.

### F11 — Medium: different time bases are presented as one period

**Evidence:** browser plus source. The incident hero says “This period · last 30 days” but combines period report/closed measures with open workload, while the list can show all-time results when no from/to is selected. The H&S summary strip is literally labelled “This week” independently of the broader range control.

**Fix:** label “Open now” versus “Occurred in selected period”, bind period captions to state, and ensure every meter's drill-through matches its count. Preserve the ability to see long-running open work.

### F12 — Medium: corrective action execution is governance-heavy

**Evidence:** source. `HsCorrectiveActionService::eligibleOwner()` requires `hazards.manage`. The task provider requires `hazards.view`; lifecycle write routes use `hazards.manage`, while evidence endpoints have narrower participant authorization. The register opens governance dialogs for lifecycle work.

**Impact:** ordinary support or maintenance workers cannot necessarily execute the work through their usual task surface, even though they may do the physical task. Legacy/demo rows with unusual assignees do not prove the current assignment validator accepts them.

**Fix:** distinguish accountable H&S ownership from the executor. Prefer canonical linked work; add narrowly authorized participant execution only where needed. Keep independent verification and avoid bulk permission expansion.

### F13 — Medium: source links exist, but not every operational consequence is proven

**Evidence:** source plus the current Workforce audit. Return-to-work records carry capacity/modified-duty data; no corresponding consumer was identified in the inspected primary eligibility path. H&S training already has an eligibility rule, but its blocks are marked overrideable. The Workforce programme is actively developing related policy consumers and must own their integration.

**Fix:** an effective-dated, minimal work-capacity projection, source-change rechecks, impacted-duty receipts, and policy-specific override rules. A safety event alone must not silently change a roster or medical eligibility.

**Status:** integration requirement to coordinate; absence of a matched consumer in this audit is not proof that another active worktree lacks it.

### F14 — Medium: WorkSafe dominates the H&S workflow

**Evidence:** current H&S route/model/UI inspection centers notification decisions on WorkSafe. A focused search did not identify a corresponding DSS/Te Tāhū Hauora notification workflow in the inspected H&S paths. Safeguarding has an external-report path, so the app must be checked for reusable reporting capability before adding another.

**Fix:** a per-destination notification checklist with applicability and source-owned delivery evidence; retain WorkSafe decisions separately. Account for current disability-provider contract requirements and policy updates. This is a product gap requiring broader schema/policy validation, not a declaration that the organisation is noncompliant.

### F15 — Medium: outcome metrics need a supported-living interpretation

**Evidence:** browser. The overview foregrounds LTIFR/TRIFR, a near-miss ratio and a “Site safety league”; some denominators correctly render unavailable. This is a product-design issue, not evidence of incorrect arithmetic.

**Fix:** managers first see current response/review work, person follow-through and prevention. Keep specialist metrics in Reports, with exposure denominators, definitions and data quality. Avoid treating fewer reports as inherently better.

### F16 — Medium: legacy state inconsistencies need reconciliation, not cosmetic hiding

**Evidence:** browser. The H&S overview offered acceptance actions on records whose source description included “closed”; Events showed some closed rows with a qualified-decision warning. Source-report closure can legitimately precede governance closure, and seed/legacy state is present, so these are not proven transition bugs.

**Fix:** distinguish source report, operational response and governance state explicitly. Run a read-only reconciliation of missing/conflicting links and historical decision provenance. Repair only with evidence and authorization; never fabricate historical acceptance or regulatory notification.

## What is already good

- `IncidentJourneyService` already connects incident, alert and H&S records, with locks and exact-link idempotency. This is a strong basis for one readable workspace.
- Draft endpoints and request UUIDs support recovery and duplicate-safe submission.
- `HsEventClosureService` has explicit prerequisites and distinguishes hard gates from controlled exceptions, with accountable decisions and expiry.
- Corrective action completion and independent verification are separate. Recommendation dispositions and one-for-one transfer from Control Room already have dedicated service logic.
- Source observers/adapters exist for hazards, fleet, equipment faults, first aid, restraint, inspection/drill failures, safeguarding, exposure and staff injury.
- My Day already offers incident reporting, lone-worker check-in and PPE acknowledgement. Keep those worker entry points.
- All Tasks has source providers for incidents, follow-ups, H&S reviews/investigations/actions and other safety work. Reuse those projections.
- Scoped evidence presenters, specialized safeguarding services and existing site-access services give useful privacy boundaries to extend.
- Certification and first-aid coverage can already say “unknown”; retain this honesty elsewhere.
- Current tests cover many critical contracts, including H&S acceptance, closure, site scope, signal provenance/recovery and incident handover. Re-run and extend them against the redesign rather than replacing them with screenshot-only checks.

## Other active work reviewed

**Audit eMAR module and navigation** (`01a0e70d-f675-77b2-bbaf-2394094c898c`) completed its coding work during this audit. Its final inspected message reports a merge to GitHub and local main, all 18 checks passing including 717 eMAR tests, and all 17 review findings resolved. Local main's merge commit was independently inspected; the test/review counts are attributed chat results, not rerun by this audit. Production activation remains separate: database updates, pharmacy credentials, real prescriber access, licensed medicine pictures and protected email-backup setup/verification. See `docs/emar-connected-care/ACCEPTANCE.md`. Preserve its connected-care work and external-clinician isolation when implementing H&S.

**Workforce and Rostering audit** (`01a108fc-c419-7861-a22e-e5d4b7f26fd1`) remains active. Availability changes were already in the initial inspected main commit. Its latest inspected message reports 104 combined settings/navigation/roster UI cases passing and a pending repair for delayed requests relying on outdated site permissions before the settings batch goes to main. These are attributed chat results. Workforce settings and policy consumers remain work in progress; its cross-module plan covers H&S training, work capacity, lone-worker monitoring and independent Control Room staffing.

The older pause handoffs in the repository are historical evidence, not instructions to stop this requested audit. This chat did not message, restart, take over or change either chat. Future implementation must recheck their state and current main and coordinate any still-active shared-file work; do not reapply old snapshots over their work.

## Source anchors

Key evidence is in these existing paths; line numbers refer to the inspected source and may move:

- `resources/js/components/app-sidebar.tsx:1344` — H&S navigation and permissions.
- `resources/js/pages/incidents/show.tsx:1` — modal-only deep-link shell.
- `resources/js/pages/incidents/index.tsx:244` and `:1017` — local date shortcuts and row activation.
- `resources/js/pages/health-safety/events/show.tsx:1` — modal-only governance route and action query parameters.
- `resources/js/pages/health-safety/events/index.tsx:971` — visible browser-local event dates.
- `resources/js/pages/health-safety/components/command-centre-hero.tsx:260` — summary time label, zero-alert assurance and fixed freshness text.
- `resources/js/pages/health-safety/lone-workers/index.tsx:494` — monitoring/assurance badges.
- `resources/js/components/command-centre/hero-kit.tsx:29` — legacy hero shell.
- `routes/incidents.php`, `routes/health-safety.php`, `routes/control-room.php` — endpoint capability boundaries.
- `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:140` and `:182` — visible-alert population and handover classification.
- `app/Services/ControlRoom/ControlRoomDeskService.php:303` — needs-incident aggregation.
- `app/Services/ControlRoom/AlertPriorityService.php:25` — deterministic order and compact-view counterpart.
- `app/Services/Incidents/IncidentJourneyService.php:79`, `:128`, `:177` — creation and escalation boundaries.
- `app/Services/Incidents/IncidentJourneyPresenter.php:17` — connected read model and next action.
- `app/Services/HealthSafety/HsEventClosureService.php:33` — closure readiness.
- `app/Services/HealthSafety/HsCorrectiveActionService.php:419`, `:671` — completion/verification and owner eligibility.
- `app/Policies/ClientIncidentPolicy.php:20` — current reading and transition permissions.
- `app/Services/Medication/MedicationErrorReporter.php:30` — linked medication error workflow.
- `app/Services/Tasks/Providers/` — canonical task links and capability checks.
- `docs/workforce-audit-2026-10-05/cross-module-architecture.md` — active programme's integration requirements, not proof of merged delivery.

The generated [source entry-point inventory](source-entry-points.json) records matching source references for implementation review. It is a static candidate-edge inventory; dynamically generated routes and successful runtime behavior require the explicit acceptance journeys.
