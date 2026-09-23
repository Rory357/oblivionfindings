# PKG-02B Vehicle Profile — build summary for Main

23 September 2026. Written for the Main session by Claude (Claude Code), who took over the paused PKG-02B build from the ChatGPT/Codex Designer with Stephan's approval. This replaces the earlier "slice (a)" summary: Stephan rejected that delivery because it did not match the approved v13 mockup (the Calendar in particular), so the whole page was rebuilt view for view instead of in four slices. ChatGPT stays paused; its worktree was not touched. Main's uncommitted records in the main checkout were left alone.

Branch: `claude/vehicle-profile-designer-pkg02b-54af5e`. Not merged to `main` and not pushed at the time of writing.

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
9. **Vehicle lists and badges follow the vehicle profile's Site rule.** On `main` the vehicle register listed every vehicle, but opening one was already site-scoped, so a site-less manager saw vehicles they couldn't open. This branch (from the Codex I1 work) scopes the register and its badges to the vehicles a person may open, and the daily-check badges now use the same scope. Cross-site totals need `securityDevices.devices.viewAllSites`; `fleet.manage` alone is no longer a site bypass for vehicles. **Impact to decide:** the seeded `fleet_manager` role doesn't hold that permission, so a fleet manager will see only vehicles at their HR Sites in the register (on `main` they already couldn't open other Sites' vehicles). If central fleet managers should see every vehicle, grant `securityDevices.devices.viewAllSites` to `fleet_manager` with a grant migration, or make `fleet.manage` a vehicle Site bypass. Access was not widened here.

## Verification

- TypeScript: whole-app `tsc --noEmit` clean. ESLint and Prettier clean on the changed files.
- Vitest: 12 files / 75 tests pass (workspace model, record command, checks model/studio/dialogs, finance studio, map model, trip model, vehicle index and technology projection, Leaflet map, and the IT/security interaction audit).
- Pest: see "Test results" below.
- Browser: every view compared with the mockup on the isolated `oblivion_findings_pkg02b_browser` database as the synthetic Demo Admin; flows walked end to end are listed in `MOCKUP-FIDELITY-AUDIT.md`.

### Test results

(Filled in when the full fleet run completes.)

### Driving insights and alerts

- **Migrations** `2026_09_23_000900_pkg02b_driving_insights` (published score policies, event reviews, per-vehicle speed limits with evidence) and `…001000_pkg02b_vehicle_alert_response` (response plans and a vehicle alert action ledger). Additive; `down()` refuses while records exist.
- **Driving insights**: a vehicle period score with its basis and published policy version, daily scores, overspeed episodes, event reviews (dismiss/dispute/coach with a named owner), per-vehicle manual speed limits (propose, approve, retire) and a coaching follow-up. Personal and consent-restricted trips are only counted as withheld: never analysed, scored, listed, reviewed or sent. No invented defaults: policy version 1 is the fleet settings, and a driver score needs a published policy with minimum trips and distance.
- **Alerts & Control Room**: sends a recorded event through `FleetSignalService` and the outbox (one recorded event → one signal → one response; re-sending joins it as a duplicate report), acknowledge/triage/escalate/resolve through Control Room's own lifecycle service, retry failed deliveries, create or link Maintenance work through the PKG-01 report path, and a follow-up reminder. Resolving never releases the vehicle or closes the work.
- **Prerequisite:** Control Room only receives these when an active fleet safety signal source is configured; without one the delivery is kept as "unroutable" and can be retried (seen on the isolated database).
- **Known gaps (pre-existing or deferred):** the Fleet › Alerts page lists only the `fleet`, `asset`, `tracker` and `geofence` sources, so alerts from the standard fleet signal pipeline (`queclink_fleet`) appear in Control Room and on the vehicle but not there; there's no mapped road-speed provider ("Not connected"). Two gaps found in review were fixed: a trip's score in Trip history now follows the same human reviews as Driving insights (dismissed events stop deducting; a disputed event withholds it), and the panel-level Send and Evaluate actions no longer stay locked after a 409 (they load the latest records instead of silently doing nothing).
