# PKG-02B — Vehicle profile and readiness Designer handoff

Owner: MAIN ASTRA. Revision 6. 22 September 2026. **Stephan approved v13 and instructed the build to start. Same task01a0c2bb-fcff-7cb1-8bab-882d84477c6c in worktree5b0a; current build release supersedes design-only restrictions below.**

## Current execution direction

Follow [PKG-02B-BUILD.md](PKG-02B-BUILD.md), Main's self-contained implementation release and coordination contract. Stephan explicitly requests no continuous Main/build back-and-forth. Designer owns routine contracts, implementation, corrections and QA in this task; only genuine boundary conflicts or the consolidated final review packet return to Main. Astra Extra High owns frontend and final fidelity; Sol may do backend only under the Designer's bounded brief and serial writer handoff. Readonly guides and frozen v1–v13 remain protected. No repeat user start approval or arbitrary internal-slice approval gate. Final technical review, authorised integration and operating acceptance remain separate.

## Current gate — preserve v13 and await exact design approval

[Main v13 review](../evidence/PKG-02B-main-v13-review.md) resolves B01–B03 for candidate56781fb5239a1b5a65fe69be015f782e15f4b665ea7a3c203bb00ca355090181, manifestC537D72D3617A05467C3A9FC91CA9A9E7B422FCF30E7D1C87A687D2145874C30. Main independently checked file/preservation identities, source and targeted regressions/rendered journeys. Stop at Stephan's exact mockup approval; genuine browser zoom is not yet verified, and native export/picker/production evidence limits remain explicit. No implementation/worker/integration/activation release. Broader v12 scope, ownership prerequisites and read-only current guides remain authoritative. The correction instructions below describe the prior v12 checkpoint and are not another request to edit frozen files.

## Current direction — verified expanded design and v12 corrections

Read [Main's v12 scope and review](../evidence/PKG-02B-main-v12-review.md). It records the actual user-message authority for the full vehicle calendar/trips/telemetry/Control Room, universal geofence/checklist, catalogue and document/Finance interfaces. That direction supersedes the historical compact-calendar-only design limit below. It does not release those modules' implementation or waive shared ownership, policy, privacy or exact approval gates.

Correct B01 unresolved compliance permitting Ready/Confirmed, verify and correct B02 inconsistent RUC-range gates, and correct B03 past-date validation routing/default. Same verified gpt-6-astra/xhigh Designer, no worker or application writer. Preserve v1–v12 and return a newly frozen candidate with targeted evidence. Main has not approved v12; Stephan's “ok let main know” is handoff permission only. Do not request implementation approval on the defective candidate.

The user's explicit 21 September 20:52 UTC guide-addition request and later catalogue/document follow-up narrowly authorise the already inspected additive DESIGN/POPUP/new MAP_GEOFENCING references. Their exact hashes are in Main's review. Treat them as read-only now; no further guide editing is included in these corrections. Canonical Revision10+A1–A6 is unchanged. Genuine desktop zoom and native export/picker evidence limits remain explicit; supplemental narrow-window checks do not expand the master into mobile work.

The remaining sections preserve the initial setup and reuse contract; read the current scope/review first where later verified directions supersede them.

Main independently verified PKG-02A remote publication, all125 source blobs and219 documentation entries at5307692ec59be84f3503c06354419b7da95be805. See the current Main-local evidence/PKG-02A-main-publication-verification.md revision1; hosting CI is in progress and older packages remain open. Main also independently verified this Designer's clean matching baseline and actual gpt-6-astra/xhigh turn metadata; evidence/PKG-02B-main-setup-release.md revision1. The setup gate is satisfied. Proceed with the bounded mockup and stop at exact mockup approval; no repeat user permission is needed.

## Authority, model and baseline

Stephan explicitly requested merging/pushing the finished Client Location work, then starting the next Designer. This releases one next mockup after publication, without inferring earlier packages' operational acceptance. It follows Main's earlier vehicle profile/readiness recommendation. Use GPT-6 Astra Extra High (gpt-6-astra / xhigh), explicitly selected and verified before design writes, including every continuation. Do not launch Sol or any Implementer during this assignment. Future frontend implementation belongs to Astra Extra High and requires the exact mockup/implementation gate.

Create one isolated worktree from verified remote main after Client Location publication. Read the canonical Revision 10 master at C:/Users/steph/Downloads/oblivion-findings-fleet-assets-complete-astra-prompt-v10.md, root AGENTS.md, docs/architecture/single-tenant-application.md, current programme register/dependency map/workflows, this handoff and the Client Location publication record. Preserve every remaining requirement and gate. One operating organisation: roles, permissions, sites, ownership and privacy; no new tenant product boundary.

Rory's current DESIGN.md and approved design_styles references are authoritative and read-only. Inspect current shared components and the approved Maintenance v8 design/implemented profile-work links. Use the current Rory patterns, premium evidence-upload interaction, searchable modal selectors and date/range picker conventions. Do not rewrite design guides or copy retired PageHero advice.

Verified starting references on the current publication baseline: canonical routes/fleet-assets.php and resources/js/pages/fleet-assets/vehicles/show.tsx; FleetAssets/VehicleController, ChecklistController, DailyCheckController, InspectionController, ServiceScheduleController, MileageController and ComplianceController; FleetChecklistTemplate/FleetChecklistRun, FleetServiceSchedule, FleetVehicleStateSnapshot and FleetVehicleBooking; the existing MaintenanceCheckService, MaintenanceReportService, MaintenanceRestrictionService and maintenance profile projection. routes/fleet.php is largely legacy redirects, not a new navigation source. FleetServiceSchedule already holds separate day/km intervals, last-completed date/km and next-due date/km. Confirm these references against the newly published baseline rather than assuming missing functionality.

## Bounded desktop mockup

Build one coherent Vehicle profile and readiness experience using the existing vehicle/asset identity and navigation. This is the next bounded truthful-status/compliance surface from PKG-02, incorporating the profile context already required by WF-02 and R1; it does not release the complete PKG-04 calendar/booking implementation.

- Show a clear readiness summary with reasons and next actions. Unknown, overdue, due soon, active restriction and ready are distinct; an online tracker or completed work order cannot imply the vehicle is safe or available.
- Make mileage/odometer, service history and next service, WoF, registration and applicable RUC evidence discoverable. Show source and observation/effective dates; unknown applicability/evidence must remain explicit. Reuse canonical sources and avoid inventing legal thresholds, operating policy or units.
- Give vehicle checklists/inspections an obvious home. Demonstrate start/review/completed evidence with exact template version and original answers. Failed checks can create or link the existing canonical Maintenance work according to approved rules; show review, duplicate/link, failure/retry and return paths. Do not invent safety procedures or automatic release rules.
- Include discoverable open maintenance/issues and historical work with original references, evidence, notes and lifecycle states. Services are a kind of planned maintenance, with source-linked service schedules/appointments; repair completion, safety release, custody and financial approval remain separate.
- Include a compact upcoming calendar/context list showing source-owned service appointments, estimated maintenance windows, actual restrictions, bookings and compliance reminders. An estimate or due reminder is not a confirmed booking or automatic whole-day hold. Provide a contextual calendar destination preview and return path only; the full shared vehicle calendar is a later mockup.
- Show role/site denial, busy-only privacy, missing/stale evidence, no tracker, overdue service/check, active hold, awaiting release, file upload/search failures and empty history. Keep unavailable actions explained rather than presenting invented success.

Use clearly synthetic desktop data. No application/backend writes, migrations, operational database access or tracker/notification actions. Build an isolated versioned interactive preview using repo-native HTML/React/CSS and shared visual references, not a static screenshot standing in for interactions. Preserve each frozen version and its evidence. Desktop keyboard, focus, supported window widths and zoom matter; phone/tablet work is outside the current master scope.

## Contract and approval gate

First inspect the existing profile/checklist/service/compliance/maintenance sources and record the exact reuse/navigation contract plus any unresolved operating decisions. Correct any tenant language to the repository boundary before proceeding. Main owns shared contracts and programme changes; do not expand adjacent packages or invent policy to make a mockup work.

Deliver a versioned preview URL, screenshots, source/file hashes, scenario and state coverage, concise source-to-design mapping and a list of actual unresolved decisions. Send the exact candidate to Main for design review and present it to Stephan. **Stop at exact mockup approval.** No implementation worker, application changes, merge or publication from mockup approval alone. Earlier packages stay honestly open where operating setup/acceptance remains outstanding.
