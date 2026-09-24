# PKG-02B Vehicle Profile — build summary for Main

23 September 2026. Written for the Main session by Claude (Claude Code), who took over the paused PKG-02B build from the ChatGPT/Codex Designer with Stephan's approval. This replaces the earlier "slice (a)" summary: Stephan rejected that delivery because it did not match the approved v13 mockup (the Calendar in particular), so the whole page was rebuilt view for view instead of in four slices. ChatGPT stays paused; its worktree was not touched. Main's uncommitted records in the main checkout were left alone.

Branch: `claude/vehicle-profile-designer-pkg02b-54af5e`, with the follow-ups below on `claude/pkg02b-v13-followups`. Both merged to `main` on 24 September 2026 with Stephan's approval.

## Follow-ups, 24 September 2026 (branch `claude/pkg02b-v13-followups`)

Stephan answered the open questions; each answer is built with a real backend and tests.

1. **A requirement can be marked not required, with a reason** ("not all vehicles have RUC"). Each row of Evidence & due dates has a visible "Not required for this vehicle" tick box; a readiness reason's link opens that row and focuses its box. Ticking asks for a reason and saves a new compliance version (earlier evidence is kept), audited as `fleet.vehicle.compliance.not_required`; unticking is audited as `required_again` and asks for evidence again. Nothing is inferred from the fuel type (I1 contract). A not-required, failed or unassessed version also clears the old register date for that requirement, so register badges, due alerts and Site calendar obligations stop flagging it.
2. **Fleet Managers see every vehicle** (`fleet.vehicles.viewAllSites`, granted to admin and the Fleet Manager role by `2026_09_24_000200`). The register, daily check, Compliance page and vehicle profile show every vehicle, and the vehicle's own records (evidence, readings, schedules, reminders, checks, documents and details) can be kept across Sites. Bookings (busy time only), trips, locations, driving insights, alerts, drivers, Finance, devices and PKG-01 Maintenance work keep their own Site rules, and each of those views says so. Placement and primary driver can't be changed from outside the vehicle's Site. **The Fleet Manager role was referenced by earlier grants but never seeded**, so the migration creates it with the seeder's grants when it's missing (an existing, possibly customised role only gains the new permission).
3. **A check can be released with a reason**: "No issue found — released for use", recorded by a Maintenance manager at the vehicle's Site through PKG-01's own assessment path (`2026_09_24_000300_pkg01_check_assessments`), with a required reason, audited. The check keeps its original outcome beside the decision.
4. **Control Room receives vehicle alerts out of the box**: `2026_09_24_000100` provisions the internal fleet safety signal source (idempotent). Deliveries recorded as "unroutable" before the deploy need a Retry.

### Calendar

Every right-click action in the approved mockup is offered with a real backend, by right-click, the entry's actions button, the keyboard and a long press, and destructive items offer Undo where a compensating action exists (a cancelled unavailable period is restored through `POST …/unavailable-periods/{id}/restore`; a changed period is put back through its own edit). New backend pieces: a record route for bookings and periods outside the summary's lists (`GET …/calendar/records/{kind}/{id}`), appointments planned from a due item reuse that item's open work, the vehicle's check due date gets its own obligation reminder (`vehicle_check`, lead `FLEET_CHECK_REMINDER_LEAD_DAYS`, default 7), a concern recorded at return opens linked Maintenance work once, and the restriction record opens its source check. The vehicle calendar now uses the shared Site Calendar parts (`pages/sites/calendar/_parts.tsx`: context menu, source pills, date anchor, Today rail, views) inside `PageLayout`, per `design_styles/CALENDAR_STYLE_GUIDE.md`. Two anti-patterns were added to `DESIGN.md` ("Module calendars that fork the shared calendar chrome", "Dropping approved context actions"), and ESLint now refuses new `@fullcalendar/*` imports outside the four legacy calendars.

### Review fixes

An independent security, privacy and idempotency review of the whole package found 15 issues. Fixed on this branch:

- **Fleet-wide settings need central authority** (new `fleet.settings.manage`, granted to admin and the Fleet Manager role by `2026_09_24_000400`): publishing a checklist used beyond one vehicle, and publishing the driving score policy. Site managers publish checklists for their own vehicle only.
- **Maintenance keeps PKG-01's Site rule everywhere on the profile**: completed and cancelled work in service history, work names on reminders and work linked from checks are shown only to people with Maintenance access at the vehicle's Site.
- **Compliance page**: follows the register's scope (it was empty for Fleet Managers), and vehicles that readiness blocks now read "Not ready" instead of "Expiring soon" (and count against the fleet posture).
- **Speed limits** can only be approved on evidence the approver can open (scanned and available).
- **Finance review requests**: the person who asked can't decide their own request.
- **Personal and no-consent periods in telemetry**: a withheld sample keeps its row and device-health events (heartbeat, power, battery) but no driving event, ignition or motion, on the telemetry tiles, the sample picker and the location panel.
- **Trip history is bounded**: "All recorded dates" covers the 90 days up to the latest trip, a range is capped at a year, and at most 500 trips are analysed per request; the page says when earlier trips were left out. Exports keep their own limits.
- **Trip evidence is kept**: driver confirmations, driving event reviews, speed limits and their approval events now refuse deletion instead of cascading (the unreleased PKG-02B migrations were changed in place). Deleting, changing or closing a trip follows the playback page's Site rule (404 elsewhere), and a trip with a confirmation or review can't be deleted (422 with the reason).
- **Calendar privacy**: for a vehicle outside the viewer's Sites, bookings and unavailable periods are busy time only, everywhere (feed, Today rail, Bookings & custody, the record route). An appointment's hold shows as "Unavailable · Maintenance" without the work reference or provider to people who can't read that Site's Maintenance.
- **Retries**: creating a service schedule now takes a request key (`2026_09_24_000500`); a booking edit is a retry only when the same key and the same full payload come back (anything else on an old version is a 409, not a silently lost edit); reusing a key for a different reminder acknowledgement or a different appointment plan is a 409.

### Needs Stephan's decision

1. **Releasing your own check.** A Maintenance manager at the vehicle's Site can record "No issue found — released for use" on a check they recorded themselves (a reason is required). PKG-01's full release needs a second, independently authorised reviewer. If two people should be needed every time, it's a one-line change in `MaintenanceTransitionService::checkAssessmentRefusal`.
2. **Finance review requests.** The seeded Finance role has no Fleet access, so today only people with both Fleet and Finance access (for example admin) can open the vehicle's Finance view and decide a request. Either give Finance users `fleet.viewAny`, or add a Finance-side inbox.
3. **Confirming your own booking.** "Approval not required" lets an approver confirm their own booking with a written justification; the old code always refused self-approval. Please confirm this is intended.
4. **Fleet-wide settings.** Only admin and the Fleet Manager role hold `fleet.settings.manage`, so Site coordinators now publish checklists for their own vehicle only, and only those two roles can change an all-vehicle checklist or the driving score policy.
5. **Design sign-off.** The "No issue found" dialog is new UI (not in the v13 mockup), built from the existing record-dialog pattern.

### Known gaps, not fixed on this branch

- The old asset-document routes (download, delete) still bypass PKG-02B's file rules; the "Close legacy asset-document bypass" session (`claude/sweet-mclean-6c912c`) owns that fix.
- Maintenance › Checklists (`ChecklistController`) can still change a shared checklist with `fleet.maintenance.manage`. That page is outside the vehicle profile and is being changed by the daily-check session.
- Maintenance › Open work still gives work from a check later released as "no issue found" the check's warning tone (`VehicleWorkspacePresenter::workSources`, which the daily-check session is rewriting).
- PKG-01: the work order page offers report-linked checks as hold sources, but the server only accepts checks recorded on that work (404); and a check that blocks the vehicle creates no booking impacts, so approved bookings are stopped only at checkout.
- Reminder snooze has no Undo (the reminder endpoint refuses a past time; a dedicated revert action is needed). An appointment reschedule can be planned again but not reverted.
- `FleetTimelineService` is no longer used anywhere.

### Verification (24 September 2026)

- **Pest, one process** (`phpunit.pkg02b.xml`): every PKG-02B suite (FleetSignalSourceProvisioning, FleetVehiclesAllSites, MileageFeed, ObligationReminders, VehicleCalendar, VehicleChecks, VehicleDrivingAlerts, VehicleFinance, VehicleMap, VehicleReadiness, VehicleTripHistory, VehicleWorkspace, WorkspaceRollback) plus Pkg01CheckAssessmentTest and Pkg01MaintenanceProtectedSliceTest: **151 passed, 3,302 assertions**.
- **Regressions, one process**: VehiclePageContract, VehicleBookingSitePrivacy, FleetMaintenanceWiring, DashboardHeroContract, FleetControlRoomAlertHeroScope, FleetAvailabilityRecovery, FleetTelemetryIngest, FleetIncident, FleetManagement and FleetTripPlaybackTelemetryAudit pass. FleetHeroRolloutContractTest fails only its two cases that already fail on `main` (overdue filter, status transitions; owned by the "Fix 16 fleet/security tests" task). FleetVehicleTechnologyProjectionTest needs a Vite build manifest for its Inertia version header; it passes (2/2) with one present, and failed only in this worktree, which has no `public/build`.
- **Frontend**: whole-app `tsc --noEmit` clean; vitest 8 files / 90 tests (workspace model, calendar actions, checks studio and dialogs, map and trip models, compliance tick box, finance studio); ESLint and Prettier clean on changed files; `@fullcalendar/*` guardrail proven on a probe file.
- **Browser** (isolated database): decision 1 recorded and audited on vehicle 7; decision 2 walked as a Fleet Manager at another Site (register shows all 5 vehicles across 3 Sites; Maintenance, Map, Trips, Finance and history notices; Compliance page lists all vehicles; calendar shows only busy time with no work reference or provider); the check release dialog and the calendar menus checked as the Demo Admin.

### Deploying

- Migrations: `2026_09_23_000100`–`001000` (PKG-02B) and `2026_09_24_000100`–`000500`. Deploys don't run seeders; the grant migrations add the new permissions and create the Fleet Manager role.
- After deploy every vehicle reads "Needs assessment" until its requirements are recorded or marked not required, and Control Room deliveries recorded as "unroutable" before the deploy need a Retry.

## What ChatGPT (Codex Designer) completed before the pause

- **Design**: 13 frozen mockup rounds (`previews/PKG-02B/v1`–`v13`; v13 approved by Stephan). Guides: `DESIGN.md`, `design_styles/POPUP_STYLE_GUIDE.md`, `design_styles/MAP_GEOFENCING_STYLE_GUIDE.md`.
- **Contracts and notes**: `I1-CONTRACT.md`, `I2A-CONTRACT.md`, `I2-DESIGN-NOTES.md`, `I3-I4-DESIGN-NOTES.md`, `BROWSER-QA-PLAN.md`, `SOURCE-MAPPING.md`, Main context copies, and an isolated test baseline (`0744e5ffd`, `622836e0d`).
- **I1 backend checkpoint `71dfa1a90`** and uncommitted frontend foundations, committed unchanged by Claude as `e9432132d`.

## What Claude completed

### Every v13 view, built to the mockup

`pages/fleet-assets/vehicles/show.tsx` is the v13 vehicle workspace; its components live in `components/fleet-assets/vehicle-workspace/`. The mockup's own stylesheets are ported by `scripts/port-pkg02b-mockup-css.cjs` into `mockup-port.css` (scoped to `.vehicle-studio`, colours mapped to semantic tokens), so every view uses the mockup's structure and class names. Each view was then compared side by side with the mockup: see `MOCKUP-FIDELITY-AUDIT.md`.

| Tab | Views |
|---|---|
| Overview | Readiness · Vehicle details · Documents · Finance |
| Service & compliance | Evidence & due dates · Service schedules · Reminders · Service history · Mileage |
| Checks & inspections | Recent checks · Templates |
| Maintenance | Open work · Historical work |
| Map | Location & geofences · Vehicle telemetry · Driving insights · Alerts & Control Room |
| Trip history | Vehicle trips |
| Calendar | Month, Week, Day, Agenda and Timeline, plus Bookings & custody |

Header Start check, Report a problem and Calendar open in-page flows; the header, rail (7 tabs + Find), tier-two tabs and the 20px hero → tabs → content rhythm match the Sites profile.

### Backends added (all real, no stubs; actions without a backend are hidden)

- **Readiness (I1)**: one assessment per vehicle (B01/B02 plus the RUC lower bound), odometer rules, compliance validation, booking rules, the legacy evidence guard. Readiness never uses tracker distance.
- **Workspace records** (`2026_09_23_000100`): private versioned document sets with renewal reminders, profile photo, catalogue choices, service schedules and completions, reminders.
- **Calendar & bookings** (`…000200`): calendar feed and summary, booking request/decision/change/cancel, unavailable periods with evidence, appointment planning with manage mode (plan, cancel, overrun with a reason), custody evidence.
- **Finance** (`…000300`): linked Finance records, Finance review requests with files, and an All Tasks provider (`FleetFinanceReviewProvider`). Finance files never enter the vehicle document library.
- **Trip history** (`…000400`): trips with driver attribution and confirmation, behaviour analysis, recorded journey playback, PDF/Excel export.
- **Obligation reminders** (`…000500`): due-point reminders for service schedules and compliance records, delivered in-app daily at 07:00 Pacific/Auckland (`fleet:deliver-obligation-reminders`), with retry, acknowledge and history.
- **Tracker distance feed** (`…000600`): cross-check the dashboard against a tracker sample (the dashboard figure is kept as a recorded reading), optional automatic planning from the calibrated tracker distance, pause with a reason. Planning only; readiness still uses recorded readings. Tracker figures are shown only to people with vehicle-technology access.
- **Location, geofences and telemetry** (`…000700`): current location and trail under the trip Site rule, geofence assignments that never start monitoring on their own, telemetry tiles and sample picker.
- **Checks** (`…000800`): versioned checklist library (publish, customise, preview), check requirement, checks recorded against an exact version, amendments, evidence, and Report a problem through the PKG-01 maintenance report path.
- **Driving insights and Alerts & Control Room**: see "Driving insights and alerts" below.

Every write uses an idempotency key with a fingerprint (a replay returns the same record; a changed payload gets 409), optimistic versions where records can be edited, `AuditLogger::logOrFail`, and the site-scoped vehicle resolver (404 for a foreign vehicle).

### Gaps found in the final review and fixed

- The Documents library now also lists files kept with other vehicle records (schedules, service history, readings, compliance, unavailable periods, checks), each with "Open source"; booking and Finance files stay out.
- Vehicle details rebuilt to the mockup's two-column layout (it had been stacked cards).
- Service planning for people without vehicle-technology access now uses recorded readings only (tracker figures are separately permissioned).
- The mileage progression chart printed "—" for its dates (a timestamp passed to a date-only formatter).
- "1 months" / "1 days" in the choice pickers.
- The distance-feed card names the tracker model when the sample identifies its device.
- "Confirm report" is disabled while the site's Maintenance routing isn't approved (the server refuses, and the notice says why).
- The page no longer loads the old interim-tab data (trips, fuel logs, driver sessions, work orders, bookings, incidents, service forecast, timeline) on every visit; `interim-tabs.tsx` and the redirect-based Start check are removed.
- Two migrations shared the `000500` timestamp; the geofence and checks migrations are now `000700` and `000800`.

### Fixed after an independent code review of the branch

- **Personal and consent-restricted trips** no longer expose places in trip history: no start/end address or coordinates, no route or events, no driving behaviour, and their places can't be found through search or the overspeed filter. They're still listed, as the Map already withholds their positions.
- **Booking privacy**: booking evidence files open only for people who can open that booking, and a trip's covering booking (reference, times, readings) is shown only to them.
- **Booking evidence uploads** now work for the requester or a booking approver of that booking (the wizard offered them the upload, but it needed document-management permission and failed after the booking was saved).
- **Retries**: a retried appointment cancel, overrun or reschedule returns the same result instead of 422 or a second history entry; a retried trip-driver confirmation and tracker reconciliation read the latest committed rows (no silent overwrite, no duplicate-key 500 on two first reconciles).
- **Tracker feed permission**: reconciling or pausing now also needs vehicle-technology access, like seeing the feed.
- **Tests brought up to date with this branch**: the dashboard and daily-check insurance counts (the new `insurance_expires_at` column makes them real numbers, not null), and the PKG-02B rollback test now rolls back every package migration in reverse.

## Decisions and deviations Main should review

1. **Checks without approved site rules block bookings.** Under the existing PKG-01 rule, a check recorded where the site has no approved check rule is "Needs assessment" and blocks bookings until Maintenance releases the vehicle. The check wizard says so. Sites need approved check rules before routine checks go live.
2. **Report a problem needs approved site routing** (Coordinator and backup), as PKG-01 requires.
3. **The legacy Daily check page is not yet in the versioned model**: its checks don't appear under Recent checks, and it still overwrites the same day's check. Raised as a separate task because routing it through the versioned model would make daily checks block bookings at sites without approved rules.
4. **After deploy, every vehicle shows "Needs assessment"** until Registration, WoF, CoF and RUC are recorded (or marked not applicable with a basis).
5. **Uploads need a configured virus scanner** (`IT_INBOUND_MALWARE_SCANNER_BINARY`); without one, files stay private and "Waiting for virus check".
6. **Placement & driver and Accessibility** stay as a third row under Vehicle details; the mockup has no place for them but they hold live records.
7. **Vehicle voltage shows "—"**: no tracker report decodes supply voltage today. GV500CG capability notes appear only when the device model is exactly GV500CG.
8. **Legacy files stay downloadable** with a "Not virus-checked" marker and a "Check now" action.
9. **Vehicle lists and badges follow the vehicle profile's Site rule.** On `main` the vehicle register listed every vehicle, but opening one was already site-scoped, so a site-less manager saw vehicles they couldn't open. This branch (from the Codex I1 work) scopes the register and its badges to the vehicles a person may open, and the daily-check badges now use the same scope. Cross-site totals need `securityDevices.devices.viewAllSites`; `fleet.manage` alone is no longer a site bypass for vehicles. **Decided 24 September 2026:** Stephan asked for Fleet Managers to see every vehicle without the other Sites; built as `fleet.vehicles.viewAllSites` (see "Follow-ups" above).

## Verification

- TypeScript: whole-app `tsc --noEmit` clean. ESLint and Prettier clean on the changed files.
- Vitest: 12 files / 75 tests pass (workspace model, record command, checks model/studio/dialogs, finance studio, map model, trip model, vehicle index and technology projection, Leaflet map, and the IT/security interaction audit).
- Pest: see "Test results" below.
- Browser: every view compared with the mockup on the isolated `oblivion_findings_pkg02b_browser` database as the synthetic Demo Admin; flows walked end to end are listed in `MOCKUP-FIDELITY-AUDIT.md`.

### Test results

Run on the isolated `phpunit.pkg02b.xml` configuration (per-process MySQL schema), 24 September 2026:

- **Every PKG-02B suite passes**: MileageFeed, ObligationReminders, VehicleCalendar, VehicleChecks, VehicleFinance, VehicleMap, VehicleReadiness, VehicleTripHistory, VehicleWorkspace, WorkspaceRollback (now all nine package migrations in reverse) and VehicleDrivingAlerts.
- **Regressions pass**: Pkg01MaintenanceProtectedSliceTest (21 of 21, run after the Control Room report source was added to the maintenance report service), VehiclePageContractTest, VehicleBookingSitePrivacyTest, FleetVehicleTechnologyProjectionTest, FleetMaintenanceWiringTest, DashboardHeroContractTest, FleetControlRoomAlertHeroScopeTest, FleetAvailabilityRecoveryTest, and the daily-check case of FleetHeroRolloutContractTest.
- **Frontend**: whole-app `tsc --noEmit` clean; vitest 14 files / 117 tests pass (workspace, checks, finance, map, trip and driving/alerts models, vehicle pages, Leaflet map, IT/security interaction audit); ESLint and Prettier clean on changed files; new PHP files Pint-clean.
- **Failing on a clean `main` too (not caused by this branch; raised as a separate task)**: 16 tests in FleetBoundedOptionsTest, FleetDashboardResidentSiteIsolationTest, FleetHeroRolloutContractTest (overdue filter, status transitions), FleetPermissionBoundaryTest, FleetWorkOrderSiteScopeTest, TrackingWorkspaceTest and AssetTrackerRetirementTest.
- **Environment-guarded**: Pkg01MaintenanceRollbackTest only runs on the `pkg01_2375_test` or `codex_test` schemas.
- **Order-dependent**: FleetAvailabilityRecoveryTest and TrackingWorkspaceTest pass on their own but fail after Pkg01MaintenanceProtectedSliceTest in the same process (its worker processes commit data outside the per-test transaction); included in the separate task.

### Driving insights and alerts

- **Migrations** `2026_09_23_000900_pkg02b_driving_insights` (published score policies, event reviews, per-vehicle speed limits with evidence) and `…001000_pkg02b_vehicle_alert_response` (response plans and a vehicle alert action ledger). Additive; `down()` refuses while records exist.
- **Driving insights**: a vehicle period score with its basis and published policy version, daily scores, overspeed episodes, event reviews (dismiss/dispute/coach with a named owner), per-vehicle manual speed limits (propose, approve, retire) and a coaching follow-up. Personal and consent-restricted trips are only counted as withheld: never analysed, scored, listed, reviewed or sent. No invented defaults: policy version 1 is the fleet settings, and a driver score needs a published policy with minimum trips and distance.
- **Alerts & Control Room**: sends a recorded event through `FleetSignalService` and the outbox (one recorded event → one signal → one response; re-sending joins it as a duplicate report), acknowledge/triage/escalate/resolve through Control Room's own lifecycle service, retry failed deliveries, create or link Maintenance work through the PKG-01 report path, and a follow-up reminder. Resolving never releases the vehicle or closes the work.
- **Prerequisite (now provisioned):** Control Room only receives these when an active fleet safety signal source is configured; without one the delivery is kept as "unroutable" and can be retried. `2026_09_24_000100` now provisions the source on deploy, so only deliveries recorded before it need a Retry.
- **Known gaps (pre-existing or deferred):** the Fleet › Alerts page lists only the `fleet`, `asset`, `tracker` and `geofence` sources, so alerts from the standard fleet signal pipeline (`queclink_fleet`) appear in Control Room and on the vehicle but not there; there's no mapped road-speed provider ("Not connected"). Two gaps found in review were fixed: a trip's score in Trip history now follows the same human reviews as Driving insights (dismissed events stop deducting; a disputed event withholds it), and the panel-level Send and Evaluate actions no longer stay locked after a 409 (they load the latest records instead of silently doing nothing).
