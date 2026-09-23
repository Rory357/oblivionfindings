# Vehicle mockup v2 — complete tab and workflow audit

**Verdict: incomplete. Do not treat v2 as approval-ready for the complete vehicle profile.** The previous pass verified visual components and selected interactions. It did not establish complete servicing, reminder, compliance or booking journeys. That distinction was not made clearly enough in the earlier hand-back.

Audited the running v2 at `http://127.0.0.1:4337/PKG-02B/v2/`, all six top-level tabs, all ten profile subsections and all five calendar views, plus linked dialogs and selected view-only, overdue, stale and first-use scenarios. Findings concern this synthetic mockup, not a claim that the entire production application lacks these capabilities. No application or preview source code was changed during this audit. No update was sent to Main.

Basis: master v10 sections 4, 4A, 4C–4D, 7, 9 and 11; the local source contract; existing implementation sources; rendered browser evidence. The master expressly requires the complete due-service-to-next-schedule and per-vehicle booking journeys. Earlier package notes deferred booking to PKG-04 and bounded Maintenance to a destination preview. Those deferrals explain omissions; they do not establish product completeness. No external research was needed to establish these UI gaps. Legal applicability and operational thresholds remain subject to their approved sources.

## Highest-priority missing journeys

### W1 — Servicing stops at a displayed due value

**Observed:** Next service shows `24 Sep 2026 / 85,000 km`; its detail dialog repeats a source reference and closes. Service history shows one completed job. The open service job says “Confirm the service provider and appointment” but provides no action to do so.

**Missing:** create/edit a vehicle's service schedules; multiple service types; interval and trigger explanation; current reading provenance; remaining distance/time; explicit overdue reason; responsible person; and a clear Plan service action. Engine-hour schedules should only appear for applicable vehicles after confirming their data source.

**Required mockup journey:** choose a due schedule → plan provider and appointment → distinguish internal plan from recorded provider confirmation → show any actual unavailable period and affected bookings → record work outcome, actual completion date/odometer and evidence → review the following due date/distance → reflect the updated schedule in the profile, history, reminders and calendar. A failed outcome branches to repair/retest. Work completion and authorised release stay separate.

**Concrete UI:** a Service schedules subsection with Next service summary, schedule rows, Plan service and Manage schedule; a shared wizard for schedule setup and completion review. The first-use state needs Set up service schedule. Do not invent service intervals or silently advance a schedule merely because an appointment ended.

### W2 — Reminder handling is absent

**Observed:** there is a registration calendar reminder and due-soon badges. Notifications opens a “Contextual destination” placeholder. No reminder controls or delivery history exist in the vehicle profile.

**Missing:** which obligations generate reminders; the next reminder date; recipient/owner and backup; approved lead-time or distance thresholds; delivery channel and outcome; acknowledgement; failed delivery; overdue follow-up; and the action that resolves the obligation. A badge or calendar reminder is not evidence that anyone has been notified.

**Concrete UI:** Reminders within Service & compliance, showing obligation, due basis, next reminder, responsible role, last delivery/acknowledgement and next action. Show configuration through existing policy/settings ownership, with example values labelled as examples. Reuse existing tasks/notifications/Control Room escalation rather than introducing another alert engine. Reminder acknowledgement must not mark a service complete or clear a restriction.

### W3 — Compliance is a list of facts without the resolution workflow

**Observed:** WoF, registration, RUC and CoF open informational dialogs. Unknown/applicability states are visible, which should be retained.

**Missing:** record/update evidence; record the basis for applicability; plan an appointment; distinguish provider confirmation; record pass/failure and supporting documents; repair/retest; authorise release where required; update the next due date; and show the responsible next actor. The RUC licence range in the Ready fixture has no renewal/evidence journey or source-backed remaining-distance presentation.

**Concrete UI:** contextual actions on each obligation, using the same Maintenance appointment, outcome and evidence components as servicing. Preserve date-only versus timed information and do not assume every vehicle needs both WoF and CoF.

### W4 — Maintenance contains dead-end actions

**Observed:** report/create-or-link and evidence-staging demonstrations exist. Open work and historical work are present. Complete work opens a warning explaining that the transition is not implemented. Progress and cancelled-work details also terminate in explanatory text.

**Missing:** actionable assessment/owner/target changes, provider selection, appointment confirmation/reschedule/cancellation, estimates and approval references, parts/labour and Finance links, completion evidence review, unresolved-defect/retest handling, authorised release and feedback to the reporter. The service work order also displays the condition job's notes/evidence context rather than a convincing service-specific record.

**Concrete UI:** carry the selected vehicle and source into the existing Maintenance workflow. Provide real mockup steps and resulting states for Plan appointment, Record outcome and Review release. Source links must open the corresponding synthetic record. An informational “continue elsewhere” dialog is not the demonstrated journey requested by the master.

### W5 — Calendar display is present; vehicle booking is absent

**Observed:** Month, Week, Day, Agenda and Timeline all render. Filters, navigation, source labels and the persistent restriction banner work. The booking example is correctly busy-only. All five views have zero Book this vehicle / Request booking actions. Entries are explicitly read-only.

**Missing:** booking from a free slot and an accessible form alternative; prefilled vehicle/site/times; permitted requester/driver and pickup/return context; pending approval; conflict and alternative handling; permission-aware event detail/edit/reschedule/cancel; checkout/return and unreturned states; manual blocks; maintenance overrun and affected bookings. Service-due and inspection reminders are also not represented alongside the one registration reminder.

**Concrete UI:** Book this vehicle or Request booking on the calendar; a focused booking wizard; pending/conflict/success states; an authorised booking detail with lifecycle actions. Calendar absence of an event must never be presented as proof of readiness.

## Every-tab coverage

1. **Overview → Readiness:** reviewed restriction, evidence, latest check, upcoming events and context. Missing clear action/owner for each unresolved obligation, a connected service action and booking/custody context. Preserve the distinction between profile readiness and readiness for a particular booking.
2. **Overview → Vehicle details:** reviewed all ten displayed fields. VIN is present but explicitly unrecorded. Missing an edit/correct journey, responsible person/driver, vehicle category detail, photos/documents, insurance/warranty/lease context and lifecycle history/actions. The lift needs a real linked component record with its own service/check evidence, not only a notice.
3. **Service & compliance → Evidence & due dates:** reviewed all six evidence rows and next-service detail. Read-only facts with no service setup, reminder or compliance resolution flow; W1–W3.
4. **Service & compliance → Service history:** reviewed completed service and next scheduled service. Missing multiple/filterable service records, provider/work performed/cost/evidence detail and the review that establishes the next schedule.
5. **Service & compliance → Mileage:** reviewed three readings and their source actions. Missing manual reading entry, attributable correction, complete history and the connection to service/RUC thresholds. Previous-reading source is a placeholder. Trackerless vehicles must remain manageable.
6. **Checks & inspections → Recent checks:** reviewed run display and all detail sections. Submission and source preservation are partly demonstrated. Missing current requirement/due date/owner, attributable amendment, connected retest and the existing work link; see B5.
7. **Checks & inspections → Templates:** reviewed both template actions. Templates are appropriately labelled fictional, but selecting Return opens the default vehicle-condition template; see B2. Operational template approval/configuration is not demonstrated and must not be implied.
8. **Maintenance → Open work:** reviewed the condition and service work destinations. Report/link is present; service planning, progress and completion/release are incomplete; W4.
9. **Maintenance → Historical work:** reviewed completed service and cancelled visit. Cancellation reason is said to be retained but its action opens a placeholder. Missing useful outcome, provider, cancellation, costs and evidence history.
10. **Map → Map & observations:** reviewed map, recorded observation and mileage destination. A real basemap and synthetic markers are now present. Missing selectable observation/trip history, time filters, tracker assignment/health context and linked permitted geofence/event history. These should reuse existing destinations and privacy controls. Advanced map setup can remain centrally owned; it needs an honest contextual link rather than another settings system.
11. **Calendar → Month:** inspected populated September grid and source context. Display works; no free-slot booking or availability interaction.
12. **Calendar → Week:** inspected timed service/busy events and all-day estimates/restriction. No appointment/booking editing or conflict path.
13. **Calendar → Day:** inspected the selected-day display. No create/request action or permitted lifecycle action.
14. **Calendar → Agenda:** inspected source/status wording and event actions. It provides a list for viewing, but not the required accessible booking/form alternative.
15. **Calendar → Timeline:** inspected source lanes and entries. No connected allocation/booking or maintenance-change workflow. The same omissions apply across all calendar views; they are not five separate backend requirements.

## Confirmed interaction and data defects

### B1 — View-only scenario can submit a new check — high

Reproduced: choose View-only role → Checks & inspections → Templates → View Return condition record → fill both answers → Review → Submit check. Result: `CHK-DEMO-0183 · Passed` and “Check recorded in this preview.” This is a mockup role-boundary defect, not a demonstrated production authorisation exploit. Main action buttons being disabled does not protect the alternate entry point. Evidence: `01-view-only-editable-check.png`, `02-view-only-check-saved.png` and the browser evidence JSON. Source: `main.tsx:1856`, `main.tsx:2063`.

### B2 — Return template selects the wrong template — medium

Selecting View Return condition record opens Vehicle condition record / DEMO-3. The requested return template is DEMO-2. The caller supplies no selected template and CheckFlow always starts with `condition`. Source: `main.tsx:1856`, `flows.tsx:126`.

### B3 — Next-service detail contradicts the current scenario — high

Overdue scenario: the dialog reports `18 Sep 2026 / 82,000 km` and then says the same schedule records `24 Sep 2026 / 85,000 km`. First-use scenario: it reports “Not scheduled / No schedule available” and then describes SCH-DEMO-07 with completed and due values. Reproduced both. This can mislead the person deciding what to do next. Evidence: `03-overdue-service-contradiction.png`. Source: `main.tsx:242`, `main.tsx:2325`.

### B4 — Stale odometer history has unexplained reversed chronology — medium

The “Latest selected reading” is 82,460 km on 2 September; the “Previous recorded reading” is 82,418 km on 20 September. A selected authoritative reading can differ from the newest one, but this UI provides no rejected/corrected/conflicting-source explanation. Both labels and readings need coherent provenance. Source: `main.tsx:1729`.

### B5 — Original failed check loses its existing work relationship — high

Open work says WO-0264 originated from CHK-0182. Opening CHK-0182 → Follow-up says “No follow-up link recorded in this preview” and offers Create or link maintenance. The existing relationship must be seeded and displayed consistently, otherwise the mockup encourages duplicate reporting. Source: `main.tsx:2083`, `flows.tsx:680`.

### B6 — Named records terminate in generic placeholders — medium

Reproduced Previous recorded reading → Mileage source, Cancelled provider visit → Cancelled work, and Notifications. Each opens generic contextual text rather than the referenced record or action. Complete work is explicitly a non-transition. These can be labelled out of preview scope, but cannot count as completed journeys.

### B7 — Search cannot find the displayed schedule reference — medium

Find in this vehicle → `SCH-DEMO-07` returns “No matching section or reference,” despite that schedule appearing throughout the profile. Search covers a small hardcoded subset. Include the permitted schedule, service, evidence and other references actually shown; do not expose restricted booking data.

## Existing application foundations to reuse

These are source findings, not end-to-end production verification:

- [FleetServiceSchedule model](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/app/Models/FleetServiceSchedule.php) already stores intervals, last completion and next due date/km. [ServiceScheduleController](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/app/Http/Controllers/FleetAssets/ServiceScheduleController.php:182) has create/update/mark-complete operations. The [existing schedule page](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/resources/js/pages/fleet-assets/maintenance/schedules/index.tsx:348) has a creation wizard. Do not create a parallel schedule registry.
- [VehicleController](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/app/Http/Controllers/FleetAssets/VehicleController.php:437) contains a distance/trip-based service prediction. The mockup should evaluate and preserve useful existing functionality, with source/freshness explained, rather than replace it with one static date.
- [FleetServiceScheduleObligationProvider](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/app/Services/Sites/Calendar/Providers/FleetServiceScheduleObligationProvider.php:47) projects date-based service obligations into the shared calendar. [FleetMaintenanceProvider](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/app/Services/Tasks/Providers/FleetMaintenanceProvider.php:149) exposes service schedule tasks.
- [FleetAutoAlertJob](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/app/Jobs/FleetAutoAlertJob.php:165) has compliance/asset-maintenance notification logic and delivery deduplication. This does not prove complete reminders for every FleetServiceSchedule or distance threshold; those connections need verification. Its current thresholds are implementation facts, not approved new policy or legal advice.
- Existing Maintenance services already own report/link, appointments/transitions, private evidence, restrictions, Finance integration and release. Their permissions and next-due semantics still need the appropriate implementation review. The current legacy schedule completion method alone is not evidence of the complete guarded lifecycle.

## What the next mockup must demonstrate

1. First-use vehicle → create a service schedule → see next due date/km and reminder ownership.
2. Due service → plan/confirm appointment → relevant unavailable period → completion evidence → review next service → updated history/calendar/reminders.
3. Failed service/WoF/check → linked existing work → repair/retest → authorised release, with booking impacts visible.
4. Reminder due → responsible person's task/notification → action/acknowledgement/failure recovery, without falsely completing the obligation.
5. Available calendar slot → request/book exact vehicle → pending/conflict/confirmed detail → permitted changes and checkout/return.
6. Repeat key journeys in view-only, report-only, empty, stale and overdue states. Facts and references must agree across tabs; no active action should end in an unlabelled dead end.

Complete these connected mockup journeys before another visual-completion claim or any update to Main. Keep the existing visual primitives, source identities, manual/no-tracker support and evidence/release distinctions. This is a design-completeness audit, not permission to alter production services or policy.

Evidence: [browser observations](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/docs/fleet-assets-audit/evidence/PKG-02B/v2/workflow-audit/browser-evidence.json). Current v2 source: [main.tsx](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/docs/fleet-assets-audit/previews/PKG-02B/v2/main.tsx), [flows.tsx](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/docs/fleet-assets-audit/previews/PKG-02B/v2/flows.tsx), [vehicle-calendar.tsx](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/docs/fleet-assets-audit/previews/PKG-02B/v2/vehicle-calendar.tsx), [vehicle-map.tsx](C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings/docs/fleet-assets-audit/previews/PKG-02B/v2/vehicle-map.tsx).
