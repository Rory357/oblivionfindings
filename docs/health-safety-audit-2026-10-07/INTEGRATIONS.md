# Health and Safety integration contracts

**7 October 2026 · Companion to the [specification](SPECIFICATION.md)**

The selected option 3 plan adds a canonical, changeable Incident owner with site-manager/coordinator default and monitored exceptions; see [OWNERSHIP.md](OWNERSHIP.md). All source projections must distinguish that role from response/review/action assignees. The shared device-evidence extension below is jointly planned with the Security & Devices audit under the user's cross-chat instruction.

One event should not require users to retell the story in several modules. Existing records retain their ownership. A connected screen may display an authorized summary and start a domain action, but cannot bypass that domain's lifecycle.

## 1. Shared integration contract

Every entry point carries a canonical source type and ID, affected site and permitted person/staff/asset references, occurred time, source version, correlation/request identity and a safe return destination. Revalidate that context when reading and again on commit. Browser query parameters are suggestions, not authority.

Every successful action returns the actual resulting record/reference, current state, owner, next action, any remaining blockers and delivery state. “Submitted successfully” alone is insufficient when a downstream response failed to start. A failed downstream projection must be visible and retryable without asking the worker to create another incident.

Bidirectional means that the originating module can see the allowed outcome and reopen the same record later, not just that an H&S page links outward. Count once by canonical source identity and preserve each domain's independent work and evidence.

## 2. Entry and return map

| Entry point | Current evidence | Required destination and return behavior |
| --- | --- | --- |
| Global Report incident | `app-header` links to `/incidents/create` | Type-aware report launcher, context-safe capture, exact saved-record receipt and return to original page |
| My Day active shift/person | `my-day/index.tsx`, `my-day-hero.tsx` pass shift/client context | Preserve shift/person/site; submit once; show assigned follow-ups and response state on My Day without administrative navigation |
| Operations shift detail | `operations/shifts/show.tsx`; shift incident endpoint | Same canonical incident; validate actual shift/person/site; return to exact shift and preserve existing shift-linked submit rules |
| Rostering actions/calendar | `operations/rostering/index.tsx` incident links | Prefill the selected shift; reporting must not change roster assignment or attendance; return to original date/view |
| Person profile safety/incident tab | `operations/clients/tabs/incidents-tab.tsx`, client incidents page | Dedicated incident page; exact person context and authorized record visibility; return to person section |
| Person first-aid tab | `operations/clients/tabs/first-aid-tab.tsx` | Open the same first-aid record and any linked incident, retain relevant follow-up status; no duplicate record |
| Person risks/behaviour/support plan | Client risk-management and shared risk/procedure components | Source-owned plan/risk review, version and effective date; incident suggests an action, never silently alters an approved plan |
| Site safety registers | Site hazards, risk assessments, first-aid and safety tabs | Same site-scoped records; exact site return path; site summary reflects authorized current state |
| Site inspection failure | `SiteInspectionRecordObserver` → H&S event | Preserve failed item/evidence and inspection identity; correction/verification return to inspection, no invented incident requirement |
| Hazard identified or changed | `SiteHazardObserver`, hazard components | Hazard owns its controls; H&S event owns assurance; related incidents remain references; review/maintenance outcome appears back at hazard |
| eMAR medication error | `MedicationErrorReporter`, medication error detail links | One medication error with one canonical linked incident when required; restricted medication detail stays in eMAR; return to the same error/person/chart context |
| eMAR controlled-drug discrepancy/loss | Incident-link migrations and medication journey services/tests | Exact count/discrepancy identity; do not infer that incident closure releases a stock restriction or reconciles a controlled-drug balance |
| PRN concern and medication handover | eMAR PRN incident links and shared handover paths | Retain administration/order identity and access rules; no automatic change to administration outcome, medication authority or restraint classification |
| Respite daily note | `RespiteDailyNoteController`, `RespiteStayScope`, daily-note link | Same person/site/stay and occurrence; allowed summary returns to daily note and stay evidence pack; preserve admission/discharge rules |
| Respite evidence/discharge pack | `RespiteStayScope` validates included records | Include authorized references and current closure caveats; export must not silently include restricted or unrelated incidents |
| Manual Control Room alert | Existing create/triage/incident endpoints | Acknowledge and triage first; create/link appropriate report only when required; return recording decision and live-response responsibility |
| Device/sensor/monitoring signal | `SignalProcessingService`, source-provenance services | Validate approved site/device/capability, deduplicate and correlate; show last observation and delivery state; device recovery is not proof the person is safe |
| Fleet incident | `FleetIncidentObserver`, Fleet incident task provider | Fleet record retains vehicle/driver/claims/police data; H&S review links back; maintenance and vehicle safety release remain separate |
| Fleet/maintenance equipment fault | `FleetWorkOrderObserver`, maintenance workflows | One job executes the repair; H&S corrective action verifies safety outcome; repair complete does not release custody, finance approval or an operational safety hold |
| Staff injury and recovery | `WorkplaceInjuryJourneyService`, injury routes | Injury/RTW is canonical; H&S review and WorkSafe records link once; roster receives minimal effective work-capacity restrictions, not medical narrative |
| HR case/complaint/debrief | Existing HR case incident seam test and source services | Keep employment/private case authority; expose only allowed relationship and operational follow-up; do not turn incident review into a disciplinary conclusion |
| Workforce training/competency | `HsTrainingComplianceService`, `HsTrainingRule` | Effective competence affects eligibility through Workforce-owned rules; expiry/renewal triggers a scoped recheck and affected-duty receipt |
| Lone-worker session/check-in | My Day endpoints, `LoneWorkerSignalService` | Worker ownership check; session/site/shift context; last received check-in and expected coverage; alert resolution does not fabricate a missed check-in |
| Lone-worker emergency/panic | Lone-worker lifecycle and Control Room bridge | Urgent current response, duplicate-safe escalation and clear on-call owner; source shows acknowledgement versus actual contact/safety outcome |
| First-aid treatment | `FirstAidObserver`, first-aid follow-up provider | Keep first-aid record; create/link H&S review where existing policy calls for it; person follow-up and outcome return to the care record |
| Restraint event | `RestraintEventObserver`, review provider | Preserve specialist review, alternatives/debrief and plan links; injury/safeguarding concerns remain explicit linked branches, not automatic equivalence |
| Safeguarding concern | `SafeguardingConcernObserver`, sensitivity/lifecycle/terminal services | Restricted case workspace; sanitized general-system projection; controlled disclosure and external reports; never inherit visibility from incident navigation alone |
| Chemical exposure | `SubstanceExposureRecordObserver` | Chemical/SDS/location and exposure record stay authoritative; first aid, injury and H&S links reflect the actual affected party |
| Emergency drill/finding | `EmergencyDrillObserver`, drill lifecycle routes | Failed finding leads to a source-linked action/review; drill completion does not verify remedial action; next drill plans retain source history |
| PPE allocation/inspection | PPE routes and My Day self-acknowledgement | Allocation, receipt, inspection and disposal stay distinct; failed inspection can create corrective work without losing inventory state |
| Worker consultation/meeting | Worker Participation controller and routes | Concern, response owner, meeting/minutes and follow-through; maintain participation history and document downloads after navigation consolidation |
| Procedure review/acknowledgement | Procedure routes and applicability panels | Approved version remains canonical; training/acknowledgement records state which version; an incident action requests review instead of overwriting content |
| All Tasks | Incident, follow-up, H&S review/investigation/action and specialist providers | Show same owner/status/due date and canonical address; never allow generic task completion to bypass domain verification or closure |
| Control Room shift handover | Prepared snapshot and shift-handover services | Outgoing owner remains accountable until eligible incoming lead accepts; changed material facts require updated/reviewed snapshot per existing contract |
| Care handover/attendance | Existing handover links and My Day projections | Share minimum current protective action and responsible person; care shift acceptance is not Control Room duty acceptance or H&S review acceptance |
| Governance board summary | `HsGovernanceService`, H&S report routes | Aggregated/redacted measures and traceability; board decisions produce source-linked actions, not silent safety closures |
| Compliance/notifiable record | Notifiable models, WorkSafe decision and injury synchronization | One authoritative signed decision/provenance; no competing status checkbox; source shows notification receipt and open obligations |
| Privacy breach | Privacy dashboard/report/attachment controllers | Privacy assesses disclosure/reporting; general safety view reveals only allowed relationship/status; evidence and access decisions stay private |
| Family/representative portal | Incident attachment portal-management permission | Only explicitly approved disclosure, current relationship/authority and allowed fields/files; case ownership never implies automatic family access |
| Messages/broadcasts/notifications | Control Room communications and incident notification paths | Minimum safe message with authorized deep link; queued, delivered, read and actioned are distinct; sensitivity checked when sending and opening |
| Reports/search/calendar | Existing report routes, source providers and shared calendar design | Same scopes and NZ time boundaries; due items link to exact source; no extra editable lifecycle in a report or calendar |
| Finance/procurement | Adjacent maintenance/asset/claims boundary | Link authorized cost/work references when needed; approving spending does not verify a corrective action or release a safety restriction |

This map covers the identified UI, service and projection families. `source-entry-points.json` provides the broader static references. Some rows describe a required return contract that is not yet proven end to end; they must not be read as completed integration claims.

## 3. Dedicated contracts with the eMAR and Workforce programmes

### eMAR

The medication error remains the source for dose/order/chart, harm review and clinical response. Reuse `MedicationErrorReporter::ensureIncident()` and the established journey link rather than reporting the same error through the generic H&S wizard.

Incident pages show the permitted medication summary and an “Open medication error” link. A response operator can receive the information needed for safety without receiving the entire chart. An authorized clinician can return to the exact source record. Refresh link authorization when the person changes house or the user's role changes.

Preserve medication restrictions, controlled-drug balances, current order revisions, prescriber access and privacy rules. Report review, clinical error resolution, incident closure and H&S verification must be visibly separate. A historical report cannot overwrite a later medication decision.

The eMAR chat's connected pharmacy, prescriber, transfer, catalogue and backup implementation has merged to main. Production activation has separate dependencies recorded in `docs/emar-connected-care/ACCEPTANCE.md`. Reuse that implementation. A failed external delivery should use the appropriate source's exception path; it should not become a clinical harm incident automatically.

`EnsureExternalClinicalIsolation` denies dedicated external clinician accounts entry to the internal application, with specific clinical-portal/account-security paths. Preserve that boundary. An internal safety-case link must not grant external prescribers incident, safeguarding, staff-health or Control Room access. Any clinically necessary portal projection needs its own explicit source authorization; an internal clinical role and a dedicated external clinician account are different cases.

### Workforce and Rostering

Keep canonical shift eligibility in Workforce. H&S provides approved structured requirements and effective work-capacity restrictions with provenance, interval and minimal reason. Workforce evaluates the impact on future and active duties and returns checked-at, affected assignments, unresolved decisions and responsible coordinator.

Events requiring evaluation: training expiry/revocation/renewal; approved modified duty; a new site control or skill requirement; lone-worker monitoring readiness; loss of safe transport/equipment; person support-plan requirements when formally approved. A free-text incident allegation must not directly suspend a worker or cancel a shift.

Draft schedules can be recalculated. Published work needs a reviewed change. In-progress duties require explicit relief/handover. Completed work and approved pay remain historical. Source closure does not itself prove future eligibility.

Control Room operator scheduling remains independent of care rostering and actual desk-duty activation. Reuse the active programme's committed-duty/availability work; do not create a second roster or a second fatigue engine.

### Shared files and integration order

High-collision areas include `app-sidebar.tsx`, global search, My Day, shared page/header/wizard components, task providers, medication permissions and Workforce eligibility. Implement H&S-specific pages first. Recheck the other chats and latest main before shared edits. Move only scoped reviewed differences; never copy entire snapshots or stage unrelated work.

## 4. Workflow examples with real boundaries

### Fall during support

The worker provides immediate assistance and reports from the active shift. Person/site/time are prefilled; actual immediate actions and uncertainty are recorded. A serious event starts or links a live response. H&S accepts review when required, assesses notification duties and investigates proportionately. A loose rail creates a linked maintenance action; the maintenance team records repair evidence. H&S verifies the safety outcome and ensures the support plan/house briefing is updated where approved. The incident remains readable from the person, shift, site, task and review pages throughout.

### Medication near miss

The worker records the medication error in eMAR. Medication policy determines whether a linked formal incident is required. The safety page shows the allowed summary, actual versus potential harm and the clinical owner's next action. Medication and controlled-drug decisions remain in eMAR. H&S may request a process review or training action. The system does not call every near miss a WorkSafe-notifiable event.

### Staff injury with modified duties

An injury record captures the event and necessary care/notification data. The confidential recovery plan records approved capacity. Workforce receives only applicable restrictions and dates; it identifies affected duties and asks the coordinator to resolve cover. The incident narrative and medical attachments are not copied to the roster. Ending an injury record does not silently remove an independently current restriction.

### Device alert without harm

A device signal is validated and grouped with its existing operational episode. The operator confirms a technical fault, links the IT/maintenance work and records the incident-report decision. If the failure leaves a person without required monitoring, explicit compensating support and a safety review are considered. Reconnection closes the technical episode only through its source rules; it does not claim the person was assessed safe.

### Safeguarding disclosure

A visible “Report safeguarding concern” route opens the restricted intake. Immediate protective action is shared with the correct responders. Allegations, witness accounts and sensitive evidence remain permission-scoped. A general dashboard can show minimal allowed work without revealing the narrative or involved worker. Communications and statutory/contractual reporting follow their own authorized decisions. Person/whānau preferences and support are recorded safely.

## 5. Failure and correction behavior

- **Repeated submission:** request identity returns the same saved record and receipt. No duplicate alert, action or notification.
- **Source saved, downstream failed:** keep the source; show that handoff/delivery is pending or failed and its retry owner. Do not erase the report or claim response received.
- **Concurrent assignment/closure:** lock or version-check current state, reject stale decisions with a useful message and retain entered notes.
- **Moved person or changed site:** preserve historical occurrence scope; separately reauthorize current viewers and future work. Do not relabel old incidents to the person's new house.
- **Missing or conflicting link:** show a repair state to authorized staff and keep the source intact. No arbitrary “first match” linking or creation on a read request.
- **Access revoked:** deny fresh reads/mutations/downloads, remove sensitive client state and preserve only safely recoverable private draft data under the approved policy.
- **Notification failure:** retry the same delivery identity; distinguish rejected, uncertain and confirmed delivery. Reconcile unknown outcome before resending.
- **Evidence error:** preserve files already referenced by committed records; never delete another request's evidence after a retry or post-commit failure.
- **Reopen:** append a reasoned decision, re-evaluate dependent work and communicate the exact changed responsibility. Do not erase previous verification.

## 6. Register-specific improvements

**Hazards and risk assessments:** show current controls, owner, review date, affected place/person, initial versus residual risk and evidence of effectiveness. A risk-score change records rationale and source version. Archive/supersede preserves history. Expose affected duties or maintenance work without automatically changing them.

**Procedures:** distinguish draft, approved and superseded versions; record applicability and staff acknowledgement by version. Link an incident's requested procedure review to the resulting approved version. Downloads and read acknowledgement remain available to legitimate readers.

**Drills:** dedicated drill page with scenario, location, participants, accommodations, findings and linked corrective actions. Completion and verification of findings stay separate. Due/overdue applies to the actual schedule, not a generic expiry label.

**Chemicals:** retain SDS version/expiry, storage location, exposure record and approved controls. “SDS current” requires complete applicable inventory and successful check, not an empty expiring list. An exposure opens the appropriate care/injury route without inventing a client.

**PPE:** distinguish allocation, receipt, fit/training where applicable, inspection, return, failed equipment and disposal. Put worker acknowledgement on My Day. Keep equipment fault/maintenance relationships and inventory availability truthful.

**First aid:** keep the factual treatment record, person/staff context, time, provider, outcome and follow-up. Link formal reports only where required. Do not infer staff competence merely because they recorded treatment.

**Lone workers:** show expected coverage, active sessions, last received check-in, next due and named responder. Zero expected, zero monitored and failed monitoring are different. Location and panic evidence use the existing access/audit contract.

**Injuries and recovery:** real page for ongoing case/recovery work; versioned capacity and duties, confidential evidence and a visible receipt of Workforce impact. Keep ACC/claim records distinct from any assertion of acceptance or readiness.

**Restraint and support:** preserve event details, approved plan, alternatives, monitoring, review and debrief. Show person-centred learning and reduction trends without equating every medication event with restraint.

**Participation:** show the worker concern, response, responsible owner and outcome, linked to consultation/meeting minutes. Group representatives, committees and consultation rather than adding sidebar entries. Keep the worker's ability to raise concerns visible.

## 7. Shared device evidence and incident reconstruction

The [H&S reconstruction response](INCIDENT-RECONSTRUCTION.md) adopts the Security & Devices handoff's R01–R10. Devices supplies safe source observations, canonical identity, historical room/person/asset assignments and supported camera/provider capabilities. Control Room collects a bounded evidence window and confirms/links the same incident. H&S consumes the same versioned, permission-filtered evidence set and retains investigation authority.

Relevant sensors, camera references, door events, permitted tracking and response history appear on a common timeline with occurrence/receipt times, uncertainty and missing coverage. Current authorization applies to each source. Live-now video is distinct from incident-time recordings. Vendor recording links require availability/expiry and preservation outcome; a bookmark is not an archived clip. HR alone accepts attendance effects under its current rules, with My Day confirmation/correction where supported.

The user's completed Devices clarifications require configurable response, evidence triggers/windows/sources and access controls. Apply the common [target scope](../security-devices-audit-2026-10-07/TARGET-SCOPE.md): effective-policy/access previews, separate live/playback/location/export actions, versioned audited changes, and actual source authorization. Recommend assisted attendance by default, with manual/app and designated automatic-reader variants; HR owns canonical time, corrections, approval and payroll outcomes, while My Day is the worker confirmation/correction surface. Preserve offsite/missing-swipe/overnight/clock-change exceptions and original evidence. An external monitoring delivery receipt is not human response acceptance, and device recovery is not incident closure.

These contracts belong to one complete final release of the agreed shared programme. Internal source-service/UI/hardware tests can proceed separately; an unfinished in-scope dependency cannot be labelled out of scope to release the rest. Rory conformance is required at both ends of every changed cross-module journey. Broader endpoint/provider implementation stays with Devices and the real attendance implementation stays with HR/Workforce.

SD-01 fall classification/device/evidence propagation and SD-02 blanket maintenance suppression are early joint prerequisites before relying on care-sensor response. H&S independently inspected the core source behavior and agrees, preserving the nuance that critical severity remains critical. See the shared response for refinement of legacy versus canonical device IDs, test mode and hardware verification limits.

Related occurrence references must not expose other-person or employee data. Several legitimate person reports from a shared event remain separate where the domain requires them; deduplication applies to retries/exact identity, not automatic narrative similarity. Source reassignment, recipient permission changes and recording expiry are tested at both entry and return.
