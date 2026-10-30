# Workflow approvals and recovery

Owner: MAIN ASTRA. Revision: 10. Updated: 2026-09-22.

Current PKG-02B decision: Stephan's “ok please start the build…” approves the reviewed exact v13 design and authorises its implementation. B01–B03 are resolved per Main's v13 review. [Build release](handoffs/PKG-02B-BUILD.md) preserves the expanded approved workspace, canonical ownership, permission/privacy, readiness/release/Finance separation and genuine operating prerequisites. Prototype thresholds/content are not live policy. This supersedes the earlier v12/unapproved wording below; no other workflow requirement is changed. Routine implementation and QA remain in the build task with one final consolidated Main review.

## Current PKG-02B design requirements and gate

[Main v12 scope/review](evidence/PKG-02B-main-v12-review.md) records verified user-requested vehicle-calendar, checklist, catalogue, geofence, telemetry/Control Room, evidence and Finance workflows. They extend the design brief; exact v12 design/implementation approval has not occurred. B01 unresolved compliance wrongly permitting Ready/Confirmed, B02 inconsistent coverage gates and B03 date-error routing are returned for correction. Existing WF ownership and independent restriction release, booking/readiness/driver/custody checks, privacy and Finance authority persist. Reminder completion is not obligation completion; selected geometry is not monitoring activation; impact is not a confirmed accident; illustrative response/scoring rules are not operational approval. No blanket extension of A4/A6 timing or new production framework follows from these design requests.
Status: **WF-01–WF-10 approved as presented in revision 2, with verified page decisions and Finance reuse clarification below. PKG-01 staged development, Coordinator ownership and independent safety-release review are approved; read 05 for live execution.** Source: Revision 10 + A1/A2/A3/A4; local audit code baseline `19354ecbc70046d12dfdf9c86f888e65fa1879d1`.

Stephan's “approved” reply to the Section 12 decision request approves WF-01–WF-10 as presented in revision 2; [approval evidence](14-scope-workflow-approval.md). Revision 3 updated approval/status wording; revisions 4/5 separately record Stephan's R1/R2 design feedback without rewriting that reviewed blueprint. Exactv7 and proceeding with Sol High were subsequently approved; the requested v8 correction and remaining operating rules retain their gates in05. Revision7 clarifies the existing Finance-owned invoice record/posting route from the actual design baseline without creating new approval authority or accounting policy.

- Historical Section 0 found no approved Revision 10 blueprint. WF-01–WF-10, corrected v8 and Sol High are now approved. Designer's released implementation brief and A4 govern the started assignment; no page acceptance exists.
- [Historical July PLAN](PLAN.md), [readiness plan](../fleet-assets-security-devices-production-readiness-plan.md) and [incident progress](../fleet-incidents-redesign/PROGRESS.md) retain their original history. Existing implementation and past approval claims must be checked in their proper scope before reuse.
- Do not treat historical pending items as current new assignments or erase any historical correction budget.
- Designer's page/issue ledger owns the implementation and correction history. The new worker has no delivered correction outcome at this checkpoint; preserve the same assignment and per-issue limits.
- Desired journeys remain those in master §§3–11 and 15. Source validation is indexed in [08](08-prompt-codebase-validation.md).
- The [full audit](10-full-audit.md) supplied the approved blueprint. One PKG-01 Designer now supervises one Sol High Implementer under the approved v8 and A4 boundary; sibling packages remain queued.

## PKG-01 — adopted operating decisions and staged development

Main independently read Stephan's actual answers in Designer task `01a0b91c-8ddd-7e83-af84-725da96ca56a`, turn `01a0bc21-a268-70c1-a3bd-a0ad634a3a6c`, request `call_yiJOTYzhr4fzLhRt2tMLSjcq`, on 2026-09-20. These decisions supersede the earlier launch questions; do not ask them again.

- **P1 / A4 development timing:** “Yes—start development now; require approved rules before live activation (Recommended)”. Implement versioned configurable checklist, evidence, hold and retest mechanisms and clearly synthetic tests now. Actual approved rule matrices and applicability remain required before affected live activation and accepted operational closure. Missing/unmapped rules remain Needs assessment; no automatic Passed/Ready or invented safety default. Synthetic fixtures cannot become operational configuration.
- **P3 report ownership:** “The site's Coordinator, with a nominated backup (Recommended)”. New reports route to and remain owned by that site's Coordinator/backup until a named worker accepts. Frontline capability is narrowly report-only; targets are set manually; the current owner remains responsible until the recipient acknowledges handover. Real person/site/backup mappings and permission assignments must be configured and verified before dependent activation. Missing mappings show an actionable unresolved state rather than silently choosing a user.
- **P2 safety-related release:** “Yes—require an independent authorised release reviewer (Recommended)”. The final reviewer must hold specific applicable release authority and be different from the repair attestor. Required retest, evidence and applicable custody must also pass; repair completion cannot release the hold. Actual release grants, site/category scope and custody applicability remain explicit activation prerequisites. An organisational role label or synthetic actor does not itself grant release authority.
- **P4 Finance:** resolved by the existing-source reconciliation in WF-09 below. Reuse Finance's canonical invoice approval/journal; no second expense event for the same invoice, and no financial approval from completion/release/estimates.

A4 changes the timing of these missing configuration values for this bounded development assignment only. Technical isolation, canonical ownership, approved scope, migration/rollback review, access denial and safe verification still apply before affected actions. A disabled live action is not evidence of completed operational acceptance. No production database action, deployment, sibling package or later approval gate is authorised here.

## Approved common blueprint contracts

Canonical source records and permissions survive every entry point. A free slot, a sent notification, a completed job, an ended trip and received custody are different facts. Unknown is never Passed/Ready/Live. Each transition records actor, effective time, source/version, outcome and next owner; retries return the existing result or a clear conflict. No successful UI state precedes authoritative server confirmation.

Existing reference numbers and relationships are reused. Cross-site work requires approved-site and object access plus the specific action. Managers' explicit broad-site authority must not silently grant care, financial or personal-location permissions. Existing privacy-safe concealment and transaction locks are retained.

## WF-01 — Report a problem to authorised release

**Entry/actor:** authenticated desktop vehicle, asset or Site profile; My Day or manual/QR lookup; frontline reporter with scoped report authority. Maintenance queue serves responsible team across authorised Sites.

**Proposed path:** identify resource → report condition and suitable evidence → assess/triage → apply category-appropriate restriction → nominate owner/target → approve expenditure/provider work where needed → internal appointment → record external confirmation if obtained → repair → completed evidence → authorised release decision → reporter feedback and next schedule. Link a related report to the existing investigation instead of producing another job by default.

**Ownership:** original inspection/report/incident remains its source; FleetWorkOrder owns work; shared restriction/readiness contract owns operational effect; Finance owns financial approval/posting; internal authorised staff own release. Scope/evidence fixes FA-T01/T02 precede any new release state. Quotes and supplier documents use existing private evidence services.

**Exceptions/recovery:** awaiting assessment/approval/parts/provider/retest/release have named owner and next action. Completion may leave hold active. Unscheduled breakdown/overrun flags affected bookings for resolution; never moves them silently. Recalls/cleaning/inspection restrictions apply only under approved category policy. Reassignment and shift acceptance remain auditable.

**Policy decisions required before implementation:** approved inspection templates, which outcomes create hard holds, who may assess/release, expenditure approval authority, response targets and any narrow permitted exception. No universal safety override. **Acceptance:** FA-F06, FA-F02, FA-T02, FA-I01; create failure, duplicate report, provider cancellation, failed retest, successful authorised release and notification failure tests.

### R1 — verified maintenance/profile design feedback

Main read Stephan's actual message in Designer task `01a0b91c-8ddd-7e83-af84-725da96ca56a`, turn `01a0bb6a-b4e6-74e2-9edd-1eb8c2e610c3`, on 20 September NZST / 19 September UTC. He explicitly asked Main to add discoverable maintenance/issues/history to asset/vehicle profiles, collapsible notes, a Progress dropdown immediately below Next action in the right-hand Work details area, and an obvious Resolve/Complete action. His named classifications were Closed, Hold, Awaiting internal feedback, Awaiting approval and Awaiting third party/vendor, with further useful classifications requested. This is page/workflow design direction under the master's ordinary-page-decision rule, not a governing-rule amendment, v3 approval or implementation permission.

Main also read his subsequent actual message in turn `01a0bb6c-3623-7f40-bc0c-763dd960a365`: “why are you not adding this in the mockup?” followed by the three work-detail changes and the complete requested Progress list below. That explicitly directs the current mockup revision, superseding any interpretation that these were only future handoff notes. Profile-history acceptance remains separately recorded across packages.

- **Profile maintenance and history:** show the same canonical linked issues, work, inspection/evidence references and lifecycle outcomes on the relevant vehicle/asset profile, with discoverable open/current and historical views and links to original work references (also used by All Tasks). Preserve parent-record context, return navigation, roles/sites/privacy and immutable source evidence. Do not duplicate histories or invent another task identifier. PKG-01 demonstrates the necessary contextual link/history projection; complete profile coverage is explicitly carried into the appropriate vehicle/asset profile packages. A limited destination mockup is not proof of full history delivery. Trace: master §§4/5, R10-005/009/029, WF-01/05 and FA-F06/F07/A01/I05; package acceptance in [03](03-dependency-map.md#r1--profile-maintenance-and-history-acceptance).
- **Collapsible notes:** compact Notes & updates header, count and visible Add note; a short latest-note summary may remain when collapsed. Expansion exposes attributable history and search. Collapsing, expanding, switching views and recoverable failure retain an unsent draft within the supported lifetime. Keyboard activation, expanded state and focus remain clear. Notes remain work context, not changes to original checks or a release/approval command.
- **Structured progress:** retain free-text Next action for the specific next step and add the requested Progress control immediately below it. Stephan's latest explicit mockup list is Open, In progress, Awaiting internal feedback, Awaiting approval, Awaiting vendor, Awaiting parts, Awaiting scheduling, On hold, Completed/Closed and Cancelled. Include these classifications in the preview; this does not approve production enum/schema changes. Inspect current lifecycle/waiting-reason sources and explain their mapping. Completed/Closed expresses one guarded completion intent unless separately established domain semantics require a distinction; do not silently invent two terminal transitions. Work-progress On hold is distinct from vehicle/asset safety or availability restrictions. Show save/failed-save feedback and retain the prior authoritative state on failure.
- **Visible completion:** provide an obvious state-appropriate Complete work / Resolve action leading to requirements, validation and confirmation, with a clear explanation and next step when blocked. Terminal progress choices must route through the same authorised transition and evidence rules, never directly bypass them. Repair completion, operational release, custody and Finance approval/posting remain separate facts. Do not invent organisational release or closure authority to make a preview succeed.

The SAME Designer may produce the next isolated PKG-01 desktop revision, addressing this feedback and the four D1 conformance gaps while preserving frozen v1/v2/v3. The exact labels, state mapping, interactions and bounded implementation scope return to Main and Stephan for mockup approval. No sibling package, production status schema or Implementer is released by R1.

### R2 — estimated maintenance window and calendar projection

During the same active v4 turn `01a0bb6c-3623-7f40-bc0c-763dd960a365`, Stephan directly requested a calendar after asset/vehicle selection in Report a problem, similar to Submit leave, to capture an estimated maintenance window and reflect those dates in the vehicle calendar. Main read the actual steered user message and inspected his supplied date-range screenshot. This is additional mockup direction within WF-01 and its WF-02 calendar interface, not approval of a live calendar write or the full sibling calendar package.

- Place an **Estimated maintenance window** date-range control after resource selection. Reuse the Submit leave calendar interaction/appearance where appropriate; [LeaveCalendarRange](../../resources/js/components/hr/leave-calendar-range.tsx) and its request-dialog caller are inspected references. The leave component has HR-specific required-date/holiday wording: do not copy leave-hours, entitlement, paid-day or holiday policy into maintenance. Keep the estimate optional with a clearly labelled Not known yet state as the proposed design default; do not require invented dates to report an urgent issue.
- Make start/end dates and the selected range clear, including a single day and ranges crossing months. A partial range cannot silently become a complete estimate. Preserve the intended local calendar dates without UTC day shifting, and use the owning calendar's established date/time conventions. Review, Back, draft/resume, close/discard and failed-save/retry retain or discard the range consistently with the report. State the retained-date behavior if the selected resource is changed; never attach the estimate to an old parent silently.
- After successful synthetic report creation, show one source-linked **estimated maintenance** event on the selected resource's bounded context calendar. It derives from the canonical report/work record and asset identity, with the same range in report review, work detail and calendar detail. Opening it returns to the original work/reference. Unknown dates create no fabricated event. Future changes/cancellation use the same source identity, with no duplicate appointment or event introduced on retry.
- Estimated window, internal appointment, externally confirmed provider booking, safety/availability restriction and completed repair/release remain distinct. An estimate does not by itself authorise a provider booking, create a new hard booking block or release the resource at its end. Actual restrictions continue to govern readiness under existing policy; the full calendar must make confirmed busy/hold periods distinct from advisory estimates. If a future policy makes a planning window reserving, that requires an explicit decision rather than a colour or dropdown implying it.
- Use the approved shared SiteCalendar pattern and a source-owned Maintenance adapter for the linked calendar projection. This iteration demonstrates only the necessary date-entry/projection/detail/return journey. Full vehicle calendar/direct booking remains PKG-04, with the same projection contract; asset context/history remains appropriately scoped under PKG-06. Record required backend fields/source ownership and any missing integration rather than claiming an illustrative event is persisted or an actual booking.

Same Designer incorporates R2 into isolated v4 and returns keyboard/range/recovery/source-link evidence at the approved desktop sizes. Frozen v1/v2/v3, source guides, application code and operational data remain untouched. Exact mockup/scope approval and the later implementation gate still apply.

### Appointment picker refinement after frozen v4

Main verified Stephan's subsequent request in the same Designer turn: Plan appointment should use Report a problem's Choose dates interaction, and Main should add the reusable pattern to Rory's design rules. The separate reference permission is [D2](09-amendments.md#d2--separately-authorised-date-picker-reference-addition). Same Designer prepares isolated v5 because v4 has already been frozen and delivered. Preserve that exact artifact.

Plan appointment uses the calendar date/range control with a clear chosen range and separate start/end times below, retaining the existing Pacific/Auckland context. Its required dates/times must validate the complete interval; optional Not known yet belongs only to workflows allowing an unknown estimate. Review/Back, dirty close and failed save retain entries. This still records an internal appointment, with provider confirmation separate; date selection does not grant booking eligibility or release authority. No HR entitlement/hours policy, real calendar mutation, production schema change or implementation approval is introduced. Existing R1/R2, searchable handover and consolidated-release requirements remain in the same package.

## WF-02 — Vehicle calendar, reservation and readiness

**Entry/actor:** vehicle profile Calendar/Book this vehicle, Fleet comparison calendar or prefiltered Site view. Requester and driver are separate roles; booking on another person's behalf is specifically authorised.

**Proposed path:** choose visible vehicle/time → inspect available/busy/hold context → minimally prefilled request → server evaluates asset state, active/future bookings, manual/maintenance intervals, applicable compliance, buffers, driver eligibility over full period, capacity/accessibility/equipment/roster requirements → pending or confirmed according to approved policy → approve/recheck → checkout/recheck. Accessible form/list alternatives exist for every pointer action; no required dragging.

**Pending recommendation for decision:** use a finite, explicitly displayed hold ending at the earlier of the approved hold duration or booking start. An expired request may remain awaiting assessment but reserves no slot; approval must acquire a fresh valid reservation. Exact duration and near-start approval handling are operating-policy decisions, not invented implementation defaults. A manager cannot approve an already-lost slot without reallocation/conflict resolution.

**States:** available, pending hold with expiry, confirmed, in use, overdue/unreturned, maintenance/unavailable, cancelled/rejected/returned. Informational due reminders do not block whole days. Early return can release only after actual required custody/readiness decisions; overdue return remains unavailable beyond scheduled end. Edit/reschedule/extend and authorised manual blocks use the same atomic service. Conflicts show permitted reason and alternatives; do not reveal residents/destinations or silently switch vehicle.

**Ownership/recovery:** FleetVehicleBooking with Asset serialization lock; source-owned maintenance/restrictions and shared SiteCalendar adapter. Busy-only, details, request, approve and manage permissions are independent. Store instants consistently; display configured Site/organisation timezone, including Auckland DST. Stale save explicitly rechecks. External calendar/map subscriptions are unnecessary.

**Policy decisions:** pending duration, buffers, required equipment/checks, full-period eligibility applicability and narrow exceptions. **Acceptance:** FA-F01/F02/F03/I02/T01; concurrent empty slot, DST ambiguity, overnight/multi-day, late return, withdrawn driver eligibility, busy privacy and duplicated save tests. Required mockup states remain a later gate, not delivered by this blueprint.

## WF-03 — Transport demand before resource allocation

**Entry:** Client or Site Request transport and authorised Fleet demand queue. Existing ClientTransportBooking owns the request; outing/Client remain context owners.

**Path:** requested → assessed → awaiting allocation → suitable vehicle/driver/escort identified → WF-02 reservation/approval → allocated → linked journey/prechecks/passengers/medication custody → actual completion. Minimal purpose, window, pickup/return and authorised accessibility/support needs; no full care-plan copy.

**Exceptions:** client declines, requester cancels, no suitable transport, failed allocation and actual journey cancellation have distinct reasons. Allocating after another user takes the resource returns conflict with no partial linked reservation. Cancellation cannot silently discard unresolved medication/custody work. Preserve ResidentTransportJourneyService idempotency, presence and eMAR boundaries.

**Owner/next action:** allocator/team with due target; unresolved demand escalates through shared tasks. **Acceptance:** FA-F04/F02/I03/I04; request without resource, allocation race, equipment shortage, client choice, duplicate retry and reconciliation to one booking/actual journey.

## WF-04 — Frontline checkout, return and shift custody

**Entry:** desktop My Day/Site upcoming booking, vehicle profile or equipment assignment.

**Path:** review readiness → pre-use check → identify actual giver/receiver/site/time → keys and required equipment → record odometer/fuel/charge/condition as required → authoritative checkout → return observations → key/equipment receipt → post-use check/defect → operational release or owned exception. Existing FleetKeyLog, handover and booking evidence are reused; don't re-enter the same check in three modules.

**Exceptions:** wrong Site, missing keys/kit, damage, odometer correction, incomplete return or offboarded holder enters reconciliation. End time is not return evidence. Shift handover has recipient acknowledgement/dispute and accountable reassignment; unresolved exceptions remain visible to the next shift.

**Acceptance:** FA-F05/I03/A01; repeated click/retry has one custody effect, outgoing/incoming histories persist, missing equipment affects readiness, non-manager frontline authority is narrowly scoped. No mobile/PWA/offline background queue.

## WF-05 — Static-asset receipt, assignment, transfer, loan and retirement

**Entry:** Assets inventory or Site Assets. Register receipt/tag/category/site/room/ownership and meaningful documentation; optional tracker can be added later without recreating identity.

**Path:** received → assigned/in use → issue/dispatch → awaiting receipt → acknowledged at permitted destination → loan return/check or ongoing placement → maintain → retire/dispose with dependency review. Assigned location, physical custody and four observation sources remain distinct. AssetAssignmentService's existing target/locking guards remain.

**Exceptions:** unconfirmed receipt, unknown/wrong location, borrower departure, loss/damage and inconsistent SiteRoom/SiteHouseRoom references require owned resolution. Parent movement does not attest removable-kit receipt; component replacement preserves independent service history. Finance decides fixed-asset disposal, not a Fleet delete button.

**Acceptance:** FA-A01/A03/F07/S03; trackerless lifecycle, mixed ownership, cross-site permission denial, duplicate receipt, retired resource with open obligations and preserved historical links.

## WF-06 — Stocktake and import reconciliation

**Stocktake path:** authorised scope and expected inventory snapshot → dated observations via desktop search/manual ID or supported scanner → classify missing/extra/wrong tag/duplicate/moved → investigation → approved corrective register/transfer action → sign-off with unresolved exceptions explicit. Observation alone never changes canonical location/custody.

**Import path:** map columns → preview/validate/duplicates → approve selected valid rows → idempotent batch → row-level results/reconciliation. Preserve unknowns and existing history; no invented compliance dates. Set up Sites, categories, owners and constrained policy context; enable optional tracking later.

**Ownership/recovery:** Asset/scan identities retained; additive batch/stocktake evidence if needed after shared-capability review. Retry failed rows without duplicating completed rows. **Acceptance:** FA-A02/A04/T01/T03; wrong tags, repeated files, partial failure, concurrent transfer during count and immutable sign-off.

## WF-07 — Shared Site boundary and authorised response rule

**Path:** choose Site/address → verify position → radius or custom boundary → name → choose permitted uses → preview affected rules/records → authorised versioned save. Geometry is shared, rule audiences/purposes are separate. Vehicle/asset rules do not grant resident tracking access.

**Runtime:** canonical telemetry → validated order/accuracy/consent context → server evaluator → deduplicated signal/outbox → authorised Control Room acknowledgement/triage/escalation → resolution evidence. Show expected outing and individual plan context without automatic blanket suppression. Stale/no signal is not automatically an emergency.

**Exceptions:** overlap, jitter, first observation, delayed/out-of-order points, boundary edit, repeated crossings and stale device produce approved predictable outcomes and bounded delivery. **Policy decisions:** direction/dwell/hysteresis/time windows and named response/escalation authority. **Acceptance:** FA-S01/S02/R03/T03; same boundary identity in Sites/Fleet, preserved rules on edit, map-closed processing and replay/queue recovery.

## WF-08 — Purpose-bound tracking, independent viewing/sharing and withdrawal

**Entry:** existing Client Location/Consents plus authorised tracking workspace. Explain purpose → record applicable consent/authority with current type/version/evidence → verify decision-maker authority where needed → link canonical device assignment → approve zones/rules/viewers → enable collection → periodic review/pause/withdraw/expiry/reassignment.

**Separate decisions:** authority to collect/process, authority for a staff member to view, and authority to disclose to a named family/whānau recipient. Relationship alone is not a sharing grant; no generic emergency bypass. No legal basis or retention duration is invented here. Review employee vehicle/journey privacy separately.

**Recovery:** withdrawal/expiry invalidates future collection/processing/disclosure and stale browser/queued payloads as applicable; retained history follows approved policy rather than deletion-by-default. Evidence remains attributable. UI shows denied/unavailable/stale/unknown with next authorised action, never wellbeing assurance from a marker.

**Acceptance:** FA-R01/R02/R03/I02/T01; current/history/export/jobs/realtime/cache/family channels tested separately, including exact assignment change and expired authority evidence. All legal/policy decisions require validation before implementation.

## WF-09 — Operational costs to Finance and useful reporting

**Path:** record actual operational expense/evidence → distinct financial approval → canonical FinancialEvent → posting/result → reconciliation/correction. Estimate, approved invoice and paid/posted outcome are not interchangeable. Work-order bulk/single completion must share transition effects; duplicate retries use original event identity.

**PKG-01 invoice source reconciliation — Main, 2026-09-19:** the FinancialEvent wording above describes the audited event-backed expense route; it must not force a second event/journal for an invoice already owned by Finance Accounts Payable. At the actual e62b569 design baseline, FinBillPolicy::approve uses the existing finance.ap.manage capability, and AccountsPayableService::approveBill creates the FinBill-owned journal, approval actor/time and site/asset cost allocation. For invoice-backed Maintenance costs, reuse that canonical FinBill approval/journal and project its permitted status into Maintenance. Link the actual-cost evidence/source identity; do not dispatch the work-order-completion FinancialEvent path for the same invoice. Completion, release and estimates grant no financial approval and must not post.

This is Main's source-of-truth/implementation reconciliation within the approved Finance-owned approval and no-duplicate-expense requirements, not a new operational-policy decision for Stephan. Existing Finance permissions, approval checks, configured spend/tax rules, corrections and allocations remain authoritative. No second invoice/payment UI, new threshold, permission grant or historical reposting is authorised. Amendments follow Finance's existing correction/approval rules; Maintenance cannot overwrite approved invoice facts. Non-invoice expenses remain on their existing approved Finance-owned contracts and cannot bypass approval through Maintenance completion. Any genuinely new expense type or material accounting/workflow change must be escalated before expansion. Tests must prove one approval/posting source, correct site/asset allocation, denied access, stale/retry behavior and no duplicate expense across both routes.

**Recovery:** failed/unposted status stays visible with Finance owner and safe retry; corrected source uses approved reversal/amendment, not a second unlinked expense. Fixed-asset recognition/depreciation/disposal remains Finance-owned. HR mileage uses approved effective rate policy and preserves historical applied rate.

**Reports:** define question, period/timezone, numerator/denominator, source, scope, freshness, null handling and drilldown; report unmet demand/circumstances and waiting stages rather than invented support/safety rankings. **Acceptance:** FA-I01/I04/T03, estimate-only completion, bulk parity, replay, rollback and null-distance/cost cases.

## WF-10 — Maps/settings, responsible work and controlled rollout

**Maps:** administrator enables optional Google with capability/status verification; absent/unusable configuration uses approved OSM-based fallback where supported; imagery failure leaves list and core operations usable. Provider switch preserves app-owned geometry and selection; grey base does not desaturate semantic overlays. Booking remains independent.

**Settings/work:** persist notification and policy decisions with role, version/effective behaviour and actual delivery capabilities. Owners/targets/next actions and acknowledged reassignment live in existing tasks/approvals/Control Room, including consent review, unconfirmed transfer and overdue return. Contractor evidence starts staff-mediated; any portal is a separate future decision.

**Rollout:** one bounded package at a time; approved desktop mockup → worker → complete QA → Main exact-code technical approval → authorised integration/publication verification → user acceptance. Test populated/empty/loading/error/denied/stale/unsaved/retry states with approved restricted roles and realistic scale in an isolated test environment. No future work released early.

**Acceptance:** FA-M01/M02/N01/N02/I03/I05/T03/T04. No external provider configuration, payment, map tests that incur cost, new permissions or production publication performed in this audit.

## Approval ledger

- A1/A2: approved and applied; [exact record](09-amendments.md).
- WF-01–WF-10: **APPROVED — revision 2 blueprint content**, Stephan's “approved” reply; [record](14-scope-workflow-approval.md).
- PKG-01 P2/P3 policy direction and A4 staged development: **approved**, exact evidence above. Actual operating configuration remains required before affected live activation and accepted operational closure. Other packages' unresolved policy decisions retain their existing timing; no universal legal/safety defaults are approved.
- PKG-01 v8 and Sol High: **approved; Designer has started the single Implementer**. Technical setup precedes code writes; no QA completion, integration or page acceptance is claimed.
