# PKG-02B vehicle profile: mockup fidelity audit

## Re-audit after the rebuild (23 September 2026, evening)

Every view was rebuilt to the approved v13 mockup and then walked side by side with it: the mockup (`docs/fleet-assets-audit/previews/PKG-02B/v13`, served locally) beside the build (branch `claude/vehicle-profile-designer-pkg02b-54af5e`, isolated `oblivion_findings_pkg02b_browser` database, synthetic Demo Admin), both at the same emulated desktop width. Where the synthetic vehicle had no data for a view (tracker samples, a trip, a checklist, a schedule), a record was created through the view's own wizard or, for tracker data, inserted into the isolated database, so each view was compared with content rather than as an empty state.

The first audit (below) found 1 matching, 3 close, 5 partial and 10 missing views. The build now follows the mockup section for section in every view listed here. The remaining differences are deliberate and listed in "Deliberate differences".

| Area | View | Verdict | Checked |
|---|---|---|---|
| Chrome | Header, rail (7 tabs + Find), tier tabs, 20px hero → tabs → content | Matches | Chip labels use the mockup's own logic: Not ready / Needs assessment / Ready. |
| Overview | Readiness | Matches | Readiness card with next action and three linked facts, Next service, Compliance at a glance, What happens next. Start check opens the in-page check. |
| Overview | Vehicle details | Matches | Two columns: identity card with the photo box and upload button, Vehicle details facts with Edit details, Vehicle documents shelf (count, Upload, Manage documents & renewals, Finance records), Lifecycle history. |
| Overview | Documents | Matches | Section header "Documents & renewal evidence" with List/Cards and Upload documents; Required documents table (file status, renewal/expiry, Find files, Upload, menu); search, Current/All history and Renewal reminders; Document library table (version/expiry with the renewal link, source & reference, file actions with Open source for files kept with another record). |
| Overview | Finance | Matches | Ownership, purchasing & costs; Link records and Request Finance review; three tiles; linked records and review requests tables; "Finance remains the owner". |
| Service & compliance | Evidence & due dates | Matches | |
| Service & compliance | Service schedules | Matches | Next due with km-remaining bar, interval & responsibility, Plan service and Upload evidence per row. |
| Service & compliance | Reminders | Matches | Three tiles, Vehicle follow-ups, Obligation reminders with delivery status and next action. |
| Service & compliance | Service history | Matches | Three tiles and the per-row Upload evidence. |
| Service & compliance | Mileage | Matches | Distance feed card with Cross-check & configure and Pause feed; current recorded odometer; next distance trigger; readings table; reading progression chart. |
| Checks & inspections | Recent checks | Matches | Next requirement card (Manage requirement, Start a retest); Checks & inspections table with Upload evidence and Add amendment. |
| Checks & inspections | Templates | Matches | Controlled template library with Create checklist, Preview, Customise, Use checklist and versioned publishing. |
| Maintenance | Open work | Matches | |
| Maintenance | Historical work | Matches | |
| Map | Location & geofences | Matches | Toolbar (Recenter, Historical trail, Geofences, Location actions, Expand map), reported state panel, geofence links with Manage, Select existing and Create. |
| Map | Vehicle telemetry | Matches | Tile grid (ignition, motion, vehicle voltage, tracker backup, connection, distance counter), sample picker, distance feed. |
| Map | Driving insights | Matches | Analytics, Reviews & coaching and Speed limits; period score with its basis and policy version; distance, overspeed, idle and events-per-100-km tiles; daily score chart; overspeed → Control Room card; insights by trip; events to review; "Make the score trustworthy" with Create coaching follow-up. Personal and consent-restricted trips are counted as withheld only. |
| Map | Alerts & Control Room | Matches | Response clock, source → correlate → owner → follow-up strip, response queue with filter, send-a-recorded-event card, draft response plan. The mockup's preview controls (synthetic events, advancing clock, simulated failures) are replaced by real recorded events and Control Room's own response targets. |
| Trip history | Vehicle trips | Matches | Filters with Export PDF / Excel, summary chips, trip cards with pager, recorded journey with playback, trip behaviour, speed through the trip, driver attribution, journey timeline, Act on the evidence. |
| Calendar | Vehicle calendar | Matches | Own header and meters; Month, Week, Day, Agenda and Timeline; filters; restriction banner; selected-day rail; right-click scheduling; Bookings & custody below. |

### Flows walked end to end in the browser

- Tracker cross-check wizard (3 steps) → reading kept, feed calibrated, automatic planning on; Pause feed with a reason.
- Create checklist (3 steps) → version 1 published; Start check from the header → CHK-1 recorded against version 1 ("Needs assessment" because the site has no approved check rule, as PKG-01 requires).
- Report a problem from the header → correctly refused with "This site needs an approved Coordinator and backup", draft kept.
- Set up service schedule (4 steps) → row with 1,738 km remaining against the recorded reading.
- Renewal reminders button → Service & compliance › Reminders, showing the insurance renewal.
- Edit details → the four-step Edit vehicle record wizard.
- Driving insights on a recorded business trip with an overspeed episode and a harsh-braking report: score, tiles, daily chart, trip insights and events all populated.
- Send a recorded overspeed to Control Room (with its confirmation) → the response appears in the queue. On the isolated database the delivery is held as "unroutable" because no fleet safety signal source is configured there; it's kept and retryable, as designed.
- A trip marked personal shows "Location withheld" on its card, start, end and map, with no route, events or score.

### Deliberate differences

1. **Placement & driver and Accessibility cards** sit as a third row under Vehicle details. The mockup has no place for the home site, primary driver or accessibility equipment, and these are live records people already maintain.
2. **Required documents count only the vehicle's own documents**, and the library adds files kept with other vehicle records (schedules, service history, readings, compliance, unavailable periods, checks) with an "Open source" link. Booking and Finance files are left out of the library because they follow their own access rules.
3. **Tracker figures are permissioned.** People without vehicle-technology access see no distance feed and plan from recorded readings.
4. **Demo-only controls** (scenario switcher, "synthetic sample" buttons, fixture notes) are not built.
5. **Wording**: "Responsible person" instead of "Responsible role" (the record holds a person); the telemetry title shows the tracker model only when a device is linked.

---

## First audit (23 September 2026, before the rebuild)

This audit compares every view of the approved v13 mockup with the built page on branch `claude/vehicle-profile-designer-pkg02b-54af5e`. The mockup was the v13 build served from the Codex worktree. The build ran on the isolated `oblivion_findings_pkg02b_browser` database. Both were viewed side by side at 1440 px desktop width.

**Verdict: the build did not match the approved design.** Of the 19 mockup views, 1 matched, 3 were close, 5 were partial, and 10 were missing and replaced by interim content carried over from the old page.

| Area | View | Verdict then |
|---|---|---|
| Chrome | Header / Rail | Close / Partial (8 tabs with a separate Technology tab) |
| Overview | Readiness / Vehicle details / Documents / Finance | Close / Partial / Close / Missing |
| Service & compliance | Evidence / Schedules / Reminders / History / Mileage | Matches / Close / Partial / Partial / Partial |
| Checks | Recent checks / Templates | Missing / Missing |
| Maintenance | Open work / Historical work | Partial / Missing |
| Map | Location / Telemetry / Driving insights / Alerts | Missing ×4 |
| Trip history | Vehicle trips | Missing |
| Calendar | Vehicle calendar | Missing (a plain bookings list) |
