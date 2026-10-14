# Health and Safety delivery and acceptance plan

**7 October 2026 · Proposed implementation programme**

**Planning update:** option 3 is selected. [DELIVERY-PLAN.md](DELIVERY-PLAN.md) now supplies the detailed HS00–HS27 sequence, including the added incident-ownership domain work before the permanent page. This document remains the original ten-package grouping and the C01–C30/register-parity acceptance baseline; use the detailed plan when scheduling implementation. [OWNERSHIP.md](OWNERSHIP.md) adds O01–O16.

**Final-release requirement:** the user's later clarification requires one complete release of the agreed shared programme. Packages are internal build/review checkpoints. The detailed plan and shared reconstruction contract include configurable response, evidence/access and attendance variants; all in-scope dependencies and Rory conformance must pass the same final gate. No partial release or silent scope deferral satisfies acceptance.

The detailed plan also adds X01–X12 from market/workflow review and R01–R10 from the [joint incident-reconstruction contract](INCIDENT-RECONSTRUCTION.md). SD-01/SD-02 shared safety-signal corrections are early prerequisites for reliance on sensor response, rather than late presentation work. The combined set contains 68 named planning scenarios; none is a newly executed test result.

Implement the [specification](SPECIFICATION.md) in small reviewable changes, preserving the current safety services and every capability in the navigation map. The audit itself changes documentation only. No production feature, migration, message, deployment or other chat is changed by this pack.

## 1. Delivery order

### Package 0 — Candidate and contract baseline

Recheck main, the eMAR/Workforce chat status and ownership of shared files. eMAR coding has merged; its production activation remains separate, while Workforce is still active at this audit's conclusion. Refresh the route/permission inventory and the source hashes. Create an isolated development environment with named synthetic fixtures for ordinary workers, site managers, operators, H&S owners/verifiers, clinicians and restricted roles. Use one organisation and multiple approved/restricted sites.

Record the 21-entry capability inventory, all existing menu actions and old deep links. Establish the database/asset identity of the preview before any screenshot or browser test is counted. Keep real records out of fixtures and test exports.

**Likely files:** audit evidence, existing test fixtures, route/navigation tests. **Exit:** baseline is reproducible and shared ownership is clear. No schema redesign is needed to start.

### Package 1 — Truthful state, time and accessible entry points

Fix fixed freshness/compliance claims, zero-session ambiguity, period labels, New Zealand time formatting and keyboard-accessible incident links. Correct menu visibility to the exact current page capability without widening access. Preserve old URLs and current lifecycle.

**Likely files:** H&S command-centre hero and hero kit, lone-worker register, incident/event indexes, `datetime.ts` callers, sidebar and focused tests. Coordinate sidebar changes with Workforce before integrating.

**Exit:** F03, F04, F08, F10 and F11 have focused regression/browser evidence. An unknown state can never look certified/current merely because a list is empty.

### Package 2 — Genuine incident page

Replace the always-open modal show shell with the approved work-record body and PageHeader. Extract reusable read sections from `incident-detail-dialog.tsx`; retain the tested report/action dialogs and command endpoints. Preserve request UUIDs, drafts, error mapping and all actions.

**Likely files:** `pages/incidents/show.tsx`, incident detail components, `IncidentController::detailPayload`, `IncidentJourneyPresenter`, source-link helpers and component/e2e tests.

**Exit:** direct page, reload, new tab, browser Back, old query link, list return and ordinary-role access work. No automatic detail modal is mounted.

### Package 3 — Safety review and corrective-action workspaces

Convert the event show shell to a real page. Add an addressable corrective-action show route/controller payload and body. Reuse existing investigation, recommendation, transfer, evidence, verification and closure services. Make blocker links land on exact sections/actions.

**Likely files:** H&S Events pages, corrective-action index/new show page, event detail/action components, `HsEventController`, `HsCorrectiveActionController`, closure/permissions tests.

**Exit:** response, report and governance lifecycles are distinct; independent verification and controlled exception behavior remain intact; a task opens the exact work item.

### Package 4 — Control Room triage and priority semantics

Implement the approved recording-decision contract and emergency precedence. Extend existing source adapters/read models; do not add a second alert queue. Reconcile desk meters, handover lenses, next-action presentation and task projections. Preserve suppression/provenance rules and actual shift acceptance.

**Likely files:** `ControlRoomIncidentController`, `ControlRoomDeskService`, `AlertPriorityService`, alert workspace/desk/handover components, existing signal processing and lifecycle interfaces as required. Additive schema only if a durable recording decision cannot be represented safely in current domain fields.

**Exit:** technical/no-harm, confirmed harm, source-specialist and uncertain branches work without dummy clients or duplicates. New emergencies are prioritized correctly. Historical lateness is distinguishable from present danger.

### Package 5 — Six-destination navigation and Rory header migration

Build the limited hubs and meaningful rails around existing routes. Migrate the H&S headers to actual PageHeader rather than restyling HeroShell. Keep Report globally/contextually accessible. Preserve exact register filters, exports, links, menus and specialized permissions.

**Likely files:** sidebar/search catalogue, H&S pages/components, optional register/report hub routes, shared-list callers. Do not rewrite PageHeader, WizardShell or Rory's guides without a demonstrated missing contract.

**Exit:** every old item in the 21-row map has a tested destination; no duplicated breadcrumb/filter/tab bars; 1440px, 1280px, zoom and keyboard checks pass.

### Package 6 — Specialist register continuity

Apply the defined improvements to Hazards, Risks, Procedures, Drills, Chemicals, PPE, First Aid, Injuries, Lone workers, Restraints and Participation. Use full pages for enduring work and bounded dialogs for capture/short actions; do not mechanically convert every reference register row into a complex ticket.

Preserve specialized lifecycle, evidence, download/export and self-service endpoints. Deliver per-register batches rather than one broad component rewrite. Do not remove functionality because it is uncommon.

**Exit:** register parity checklist in section 4 passes for each migrated surface, including existing read-only and participant roles.

### Package 7 — Cross-module return receipts

Update link construction, permission-shaped summaries, source outcomes and failure receipts across My Day, shifts, person/site profiles, eMAR, fleet/maintenance, respite, All Tasks and handovers. Use the contracts in [INTEGRATIONS.md](INTEGRATIONS.md).

The eMAR owner controls clinical-source changes. The Workforce owner controls eligibility, capacity, scheduling and policy consumers. Integrate source-supported H&S requirements rather than duplicate either programme's implementation.

**Exit:** each integration family has a forward journey, an outcome visible back at source, a revoked-access scenario and a duplicate/retry scenario where it writes.

### Package 8 — Notification applicability and supported-living learning

Reuse existing compliance/safeguarding external-report capabilities where possible. Add per-destination decision/receipt tracking and due work for applicable DSS, Te Tāhū Hauora, WorkSafe and Privacy tracks. Add person/whānau participation, debrief and learning fields only where the source model has no equivalent.

Version the organisation-approved contract/policy configuration. Preserve current hard gates until the responsible policy owner explicitly changes them. No automated external sending is activated merely by adding a “Send” label or exporting a form.

**Exit:** applicability, uncertainty, manual submission receipt, failed delivery, deadline calculation and restricted disclosure are tested per destination.

### Package 9 — Reports, reconciliation and release

Align metrics, drill-throughs, exports and board summaries to the canonical scoped population. Perform a read-only historical-link and decision-provenance report. Prepare explicit repair batches for evidenced problems; quarantine ambiguous cases. Do not generate retrospective acceptance/notification/verification facts.

**Exit:** fresh full affected-suite results, role-based browser acceptance, reconciliation result, deployment notes and rollback plan identify the exact candidate. A designer screenshot or passing narrow suite alone is insufficient.

## 2. Core acceptance journeys

These are release criteria, not tests claimed to have passed during the audit.

| ID | Scenario | Required outcome |
| --- | --- | --- |
| C01 | Worker reports from My Day during a shift | Correct person/site/shift, safe draft recovery, one saved incident, exact receipt, worker can return to My Day |
| C02 | Incident row, old query URL and direct URL | Same permanent page; share/reload/back/new-tab works; register context restored |
| C03 | Technical alert with no person harmed | Appropriate source work and recorded triage outcome; no forced client incident or fabricated client |
| C04 | Alert confirms harm with an existing report | Reuse one incident/review/alert relationship; no duplicate on retry or concurrent operators |
| C05 | Fleet, safeguarding and staff-injury alert | Recognize specialist source and its own access; correct review and response links |
| C06 | New critical response versus old low-severity breach | Emergency precedence under approved policy, equal order in database and compact worklists |
| C07 | H&S handover assigned but unaccepted | Assignment and acceptance distinguishable; sending team still sees unresolved transfer; acceptance recorded once |
| C08 | Control Room shift handover | Prepared snapshot does not activate incoming shift; eligible lead acceptance preserves required responsibility and changed-work checks |
| C09 | Operational response complete, governance open | Exact partial state shown everywhere; no “Journey closed” and no lost tasks |
| C10 | Corrective action completed by owner | Evidence/notes retained; awaiting independent verification; completer cannot impersonate verifier |
| C11 | Verification fails | Reason and next work visible; no false closed state; prior evidence preserved |
| C12 | Transfer operational task to corrective action | One sender and one receiver with linked receipt; duplicate/concurrent retry does not create extra responsibility |
| C13 | Attempt closure with missing hard prerequisite | Server blocks, names requirement and destination; typed notes survive; generic menu/API cannot bypass gate |
| C14 | Controlled closure exception | Existing scope, approver, reason, evidence, review/expiry and revocation rules enforced; hard gates remain non-exceptional |
| C15 | Reopen after new information | Reasoned history, exact branch reopened, dependent work rechecked; previous closure evidence preserved |
| C16 | Medication error/controlled-drug source | Existing clinical record retained, one incident when required, no relaxation of medication or stock restrictions |
| C17 | Staff injury changes approved capacity | Minimal restriction reaches Workforce; affected duties and review owner returned; private health narrative absent |
| C18 | Person moves site or loses assigned worker | Historical scope preserved; fresh access enforced on read/write/search/export/download |
| C19 | Zero sessions / stale signal / monitor failure | Distinct states; no “duty met”, “all checked in” or current assurance without evidence |
| C20 | Non-NZ browser and DST/midnight incident | All surfaces/exports show correct NZ instant and local date filters include the right records |
| C21 | Restricted safeguarding/HR/medication source | General case access does not expose narrative, filenames, counts or search fragments beyond approved projection |
| C22 | Required/uncertain/not-required notification | Destination-specific decision, due rule, owner and evidence; export versus send versus receipt distinguishable |
| C23 | Source save succeeds but downstream dispatch fails | Source remains saved; failed/pending delivery visible; safe retry creates no duplicate |
| C24 | Ordinary action executor with no broad H&S grant | Can do only the authorized assigned task/participant action; cannot review/verify unrelated events |
| C25 | Keyboard and zoom | Every row, menu, tab and action reachable; focus restored; no trapped reading/modal flow or clipped core actions |
| C26 | Filter/count/report/export consistency | Same scope and time basis; unique incident counted once across linked records; unknown denominators remain unavailable |
| C27 | Legacy missing/conflicting relationship | Explicit repair status, no successful closure or arbitrary relinking on read; evidence preserved |
| C28 | Case/page permission changes while editing | Current server authority wins; safe failure and recovery; no stale-session mutation privilege |
| C29 | Simultaneous save/close/assignment | Version/lock protects decisions; useful stale-state message; input/evidence retained |
| C30 | Person/whānau follow-up | Accessible preferences and actual contact outcome recorded; disclosure uses current authority, not family relationship alone |

For C20, test NZ summer/winter offsets, repeated/missing DST hour, NZ midnight in a foreign browser, date-only due dates, and occurred versus reported date. Do not compare formatted strings alone when validating query boundaries.

## 3. Roles and denied actions

Minimum actors: ordinary support worker assigned to a person; unassigned worker; site coordinator; H&S investigator/owner; independent verifier; operator; incoming operator lead; clinician; safeguarding officer; HR wellbeing reader; board reader; explicit organisation-wide reader; external prescriber; authorized representative.

At least two sites are required, with one inaccessible to each restricted role. Include an unrelated person, worker, asset and attachment and forged parent-child URLs. Count/search/export tests must use hidden records as well as visible ones. Do not use fictional tenants as the acceptance boundary.

Test `incidents.viewAny` intentionally and document its chosen meaning before changing grants. Test `compliance.view`-only, `reports.viewAny`-only, `clinical.dashboard`-only and `procedures.view`-only navigation. A menu item may be absent or open a correctly scoped projection; it must not promise a page that always denies access.

## 4. Feature parity checklist

| Surface | Must be demonstrated after migration |
| --- | --- |
| Incident | Type/template selection, draft, resume/discard, submit, named owner assign/reassign/history and owner filters, review, correction rules, follow-up create/complete, attachment upload/download, portal control, close/reopen, report/export |
| Safety review | Source evidence, acceptance, owner, investigation create/findings/submit/return/complete, recommendation disposition, risk, notification and preservation decisions, guarded closure/exception |
| Corrective action | Create, start, owner/due/priority, task transfer, evidence, complete, independently verify, return, close and exact task link |
| Hazard/risk | Site/person access, controls, risk evidence, create/edit, activate/review/residual/supersede/archive, permitted exports |
| Procedure | Create/edit, version/approve, applicable audience, attachment download, read acknowledgement and superseded history |
| Drill | Create/edit/start, participants, findings, complete/cancel, finding resolution, evidence and next schedule |
| Chemicals | Create/update/status, SDS download/version, storage, exposures, evidence and source links |
| PPE | Type lifecycle, inventory, allocate/self-acknowledge/return, inspections, condemn/dispose, evidence/downloads and export |
| First aid | Existing create/detail/follow-up and evidence behavior, source links and permitted register/export access |
| Injury/RTW | Create/update, lifecycle transitions, plan, capacity, modified duty, evidence/export and read-only wellbeing view |
| Lone worker | Start/edit/end/session access, self-check-in, emergency/locate/panic acknowledgement, operational alert acknowledgement/resolution and scope |
| Restraint | Capture, specialist review, approval/management authority, plan/debrief/evidence and permitted summary |
| Participation | Representatives, committees, meetings/attendees/minutes/complete/cancel, consultation status/documents and downloads/export |
| Reports | Chart filters/drill-down, board summary, WorkSafe register, investigation outcomes, corrective traceability and risk register |

“Retained” requires action and result verification, not merely finding a button with the old label.

## 5. Existing regression suites to reuse

Backend candidates include `IncidentControllerTest`, `IncidentReportDraftRecoveryTest`, `IncidentJourneyServiceTest`, `IncidentJourneyPresenterTest`, `IncidentReviewSiteScopeTest`, `HsEventWorkflowTest`, `HsHandoverAcceptanceTest`, `HsEventClosureTest`, `HsRecommendationDispositionTest`, `HsCorrectiveActionTest`, `HsCorrectiveActionEvidenceTest`, `HsWorksafeDecisionAuthorizationTest`, `HsWorksafeConsistencyTest`, `HsLinkedOperationalEvidenceTest`, `ControlRoomSafetyHandoverTest`, `ControlRoomIncidentReopenGateTest`, `SensorIncidentJourneyTest`, `SafetySignalDeliveryRecoveryTest` and the existing register/site-privacy files.

Cross-module candidates include `MedicationIncidentJourneyTest`, `AllTasksIncidentJourneyTest`, `FleetIncidentSiteAccessTest`, safeguarding sensitivity/nested-authorization/projection tests, and the current Workforce eligibility/shift-change suites owned by that programme.

Frontend candidates include incident detail/report/journey components, event deep-link actions, H&S dashboard/hero, Control Room row actions and handover lenses, All Tasks incident journey, and sidebar capability tests.

Existing browser journeys include `incident-report-recovery.spec.ts`, `incident-handover.spec.ts`, `control-room-incident-hs-golden-journey.spec.ts`, `control-room-incident-hs-alternate-branches.spec.ts`, `control-room-alert-lifecycle.spec.ts` and corrective-action responsive tests. Update assertions around the approved permanent-page behavior while preserving existing business checks.

Use the repository's documented isolated test database guard. Do not run destructive migrations, seeds or cleanup against the shared application/another audit environment. Run complete relevant files and record skipped/unexecuted tests as such. Broader application checks follow once the candidate is stable, especially for shared sidebar/header/task changes.

## 6. Review and release evidence

For each package, retain the candidate commit, fixture/database identity, preview URL, built asset identity, exact checks/results and material limitations. Desktop checks cover 1440×900, 1280×800, keyboard, long content, dark mode, alternate branding, loading/empty/error/stale/denied states and 200% zoom. Existing worker phone flows receive regression checks where changed; this does not expand the eMAR programme's approved desktop scope.

Perform usability checks with a support worker, coordinator and H&S reviewer: ask them to find ownership/next action, resume an interrupted report, distinguish response from review completion, and locate the source evidence. Measure successful completion and confusion rather than relying on aesthetic approval alone.

Proposed success measures: six or fewer applicable sidebar destinations; zero modal-only incident deep links; complete route/menu parity; no unlabelled timezone discrepancy; no false current/compliant state; no duplicate source report on retry; ordinary-role journey completion; no new restricted-data exposure; and source-to-outcome traceability for every integration family. Set time-to-report targets after measuring the current workflow with representative users; do not invent a numerical baseline.

Build and migrate additively in the development environment, then release the complete agreed programme once all readiness gates pass. Preserve old URLs and data, review changed triage/policy behavior before activation, and keep a rollback path that preserves newly recorded evidence. Data repairs have their own reviewed batch and audit log. No automatic rollback may delete submitted incidents, notifications or verification evidence.

## 7. Decisions to confirm before activating policy changes

The audit and page/navigation design can proceed with the proposed defaults. Before activating new operational rules, the organisation's designated owner must settle: exact notification applicability for its contracts/services; emergency priority and response targets; explicit global versus site-scoped incident-reading roles; participant execution authority; and record-specific retention/holds.

These are bounded implementation policy decisions, not a reason to delay the requested specification. Existing stricter rules remain in force until changed deliberately. Reuse current configuration and owning services rather than inventing a new general-purpose policy engine.
