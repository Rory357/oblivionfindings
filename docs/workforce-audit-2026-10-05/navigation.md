# Workforce navigation and function-preservation map

This is a proposed information architecture, not an implemented change or a visual mockup. It follows the current `DESIGN.md`, `design_styles/` contracts and the user's desktop-only scope. Findings use IDs from [audit.md](audit.md).

The later human decision updates the original six-entry proposal below: retain Rostering, Available shifts, Attendance, Timesheets, Handovers and Settings. Shift notes becomes a distinct discoverable workspace tab within Handovers, preserving its canonical route, independent access, documentation/export/review functions and direct links. The intermediate seven-entry reply was explicitly corrected as a mistake in the coordinator chat. The original sidebar table below is historical; its separate Shift notes row is replaced by Settings in the final contract.

Implementation checkpoint, 12 October: Handovers and Shift notes have the shared permission-aware workspace rail in source, with status choices/counts in their existing filter rows. Loaded week and Site are carried using each canonical route's own parameter names. These changes require source review, tests and browser verification. The shared sidebar reduction has not been adopted; Shifts, Conflict Queue, Qualifications and restricted Availability retain their current entries until equivalent lawful destinations are verified. Do not infer access grants or delete a function to reach the menu count.

## Recommended sidebar

Keep six clear destinations under **Workforce**, in this order, filtered by the user's actual capabilities:

| Destination | Main purpose | Existing entry point and treatment |
| --- | --- | --- |
| **Rostering** | Plan shifts, check support coverage, find cover and manage patterns | `/operations/rostering`; absorbs the complete manager Shifts list, Availability and Conflict Queue as in-page work |
| **Available shifts** | Staff find work; coordinators manage claims and replacements | `/operations/job-board`; presentation rename only; retain route and permissions |
| **Attendance** | Clock sessions, breaks and corrections | `/attendance`; retain canonical attendance workflow |
| **Timesheets** | Review worked time, submit, approve and correct pay records | `/operations/timesheets`; include discoverable Payroll adjustments for authorised reviewers |
| **Handovers** | Prepare, submit, read and acknowledge care handovers | `/operations/handovers`; retain dedicated review/deep-detail access |
| **Shift notes** | Record and review shift documentation | `/operations/shift-notes`; retain private-note and export capabilities with corrected boundaries |

My Day remains the worker's primary home outside this coordinator navigation. A worker sees their current care, tasks, handover and clock actions there. Navigation visibility must reflect exact permissions and ownership; it is not itself an access control.

**Control Room remains an independent top-level area.** Its Desk, queues, alerts, map, safety handovers, messaging, devices, playbooks, SLA, statistics, reports and settings are not moved into Workforce. Only its staffing/rostering entry is represented in Rostering, with a clear **Open Control Room** link. Keep a contextual staffing link from Control Room so existing operators can find it easily.

Do not remove `/operations/shifts`, `/operations/rostering/conflicts`, availability URLs, series URLs or legacy shift links before equivalent functionality and redirects are verified. A layout consolidation does not require a mass route rename. Preserve HTTP methods on legacy write redirects; do not convert an action into a GET.

## Rostering workspace

Use one PageHeader with title, selected period including year, approved house/site filters, staff/person filters, meaningful totals and the relevant primary action. Use the established connected rail for six sections:

| Section | What the coordinator finds | Current functions retained |
| --- | --- | --- |
| **Roster** | The working schedule by house or staff | Current Shifts/grid and Calendar; full shift list; week/day/month navigation; quick view; create/edit; publication access |
| **Cover & conflicts** | Required cover, unassigned work and specific problems to resolve | Open shifts, coverage windows, coverage acknowledgement/dismissal, conflict queue, candidates, replacements, broadcasts and claim review |
| **Availability & leave** | Who can work, preferences and approved/pending absences | Weekly availability, roster time-off blocks, HR leave visibility and permissioned leave actions |
| **Patterns** | Repeat known good schedules with exceptions | Templates, duplicate/apply/edit/delete, recurring series, occurrence details and cancel-future |
| **Control Room** | Operator staffing and its rostering entry | Existing duty/session links and future roster extension; exact Control Room permissions; operational detail remains in Control Room |
| **Reports** | Capacity, roster changes and operational analysis | Capacity heatmap, analytics, shift reports/export, publication history/diff and override reporting where permitted |

Section-specific choices belong in the shared header controls, not a second competing primary tab bar. For example, Roster offers **Week grid / List / Calendar** display choices; Patterns offers a labelled **Templates / Recurring schedules** view selector. A covering problem can open a detail panel without taking the user out of the selected roster.

The six sections must remain discoverable at normal desktop widths. If the shared rail overflows, preserve its supported More/Find behaviour and selected-section label; do not invent a second navigation system. Measure this at 1280, 1440 and 1920 widths and at desktop zoom during implementation.

### Calendar and grid

Reuse **SiteCalendar** for the conventional calendar views, adapting shift records and permitted actions to the shared contract. Its header controls must integrate with the Rostering header, so the user sees one date and one source/filter context. If the shared component needs a small supported header integration extension, add that extension with other consumers protected.

Retain the resource week grid: looking across staff or houses simultaneously is a real scheduling requirement that a generic month calendar does not replace. Preserve staff/site grouping, density and list alternatives. It should share interval logic, selected dates, filters, actions and statuses with the calendar, rather than becoming another schedule authority.

Show the viewed date and year prominently. An overnight entry spans both days with “continues from Sunday” / “ends Monday 07:00” as appropriate. Editing either segment opens one shift. Keep exact-minute entry, visible work timezone, Today, previous/next, keyboard interaction and non-drag action equivalents. Show cancelled/completed/read-only shifts accurately and prevent editing that the server would reject.

### Supported-living language

Use **house** where the site really is a house; retain **facility/site** where appropriate. Use the organisation's established person terminology consistently without renaming models. Preserve person-centred care while showing shared house coverage.

| Current ambiguity | Clear presentation |
| --- | --- |
| Coverage 100% from assigned rows | “Shifts assigned: 8 of 8”; separate “Required cover: one gap” |
| Two staff rostered at once | “Planned two-person support” when supported by requirements; otherwise “Check support requirement” |
| Unqualified conflict count | Distinguish “Staff double-booked”, “Not enough cover”, “Leave conflict” and “Review needed” |
| No data in availability | “Availability not supplied”; never assume unavailable |
| Resolved today from local state | Saved server count or accurately labelled session-only presentation, without claiming a saved decision |
| All caught up with no notes | “No notes awaiting review”; separate expected documentation gaps only where policy supports them |
| Pending timesheets | “Draft”, “Submitted for approval”, “Needs changes” and “Approved”, each counted separately |
| Live/refreshed just now | Actual updated time and a visible refresh/error state |
| Claim/replacement ambiguity | “Express interest”, “Awaiting coordinator”, “Assigned to you” and “Current worker remains assigned” as the true state requires |

## Function-preservation register

This is the minimum parity register for implementation. A row is not signed off merely because its new button exists. The action, permissions, error behaviour, downstream effect and deep links must also match or improve on the existing workflow.

| Existing surface/function | Destination after consolidation | Parity requirement |
| --- | --- | --- |
| Shifts index search, status/site/staff/person/date filters | Roster → List | Retain all filters, totals, status views, density, quick view, paging/limit disclosure and authorised scope |
| Shifts index Calendar | Roster → Calendar | Preserve reading/editing rights and current entry links; use common date contract |
| Rostering staff/site week grid | Roster → Week grid | Retain staff and site groupings, day/week/list access and assignment actions |
| Shift quick-view panel | Any Roster view | Same ID, dates, person, house, assignee, status, Edit, Start where authorised, Open full view |
| Shift full detail | Existing detail route, linked from Roster/My Day | Keep Tasks, Notes, Audit history, Incidents, Medications, Assignment, Observations, Forms, Transport and Replacement |
| Add shift / add to day | Roster header and day action | Selected date/site/person defaults must be explicit and correct |
| Shift type | Shared create/edit wizard | Standard, sleepover, on-call and other supported values retain their semantics |
| Who and where | Shared create/edit wizard | Person, canonical site/service context, staff and qualification requirements survive unchanged |
| Shift schedule | Shared create/edit wizard | Start/end, overnight continuation, break minutes, exact times and configured timezone |
| Repeat weekly | Shared create/edit wizard / Patterns | Repeat end, recurrence options and resulting occurrence identities; no accidental duplicate creation |
| Tasks and notes | Shared create/edit wizard and shift detail | Task text/times, notes and existing care links retained; do not flatten into a generic event title |
| Review/validation/eligibility preview | Shared create/edit wizard | Retain existing per-step checks; server remains authoritative; blockers/warnings/override reason stay visible |
| Edit / duplicate / promote to series | Shift action menu and Patterns | Preserve canonical services, copied-field rules, expected version and payroll locks |
| Assign / unassign / candidates / auto-fill | Cover & conflicts and shift detail | Same eligibility decisions and reservations; explain excluded candidates |
| Start / complete | My Day for workers; authorised manager detail action | Preserve lifecycle, attendance evidence, tasks, actual times and downstream timesheet behaviour |
| Cancel / reopen occurrence | Shift action menu | Retain reason, audit, notification, replacement/open-position cleanup and approved-pay protection |
| Publish single shift | Existing authorised action in Roster/detail | Keep its distinct permissions and status semantics |
| Open shifts list | Cover & conflicts | Retain quick assignment, eligibility details, cover requests and link to Available shifts |
| Post open position / broadcast needs cover | Cover & conflicts / Available shifts | Explicit audience, permissions, safe detail payload and duplicate prevention |
| Request/cancel replacement | Cover & conflicts and shift detail | Preserve reason, current worker responsibility and request → claimed → approved/cancelled history |
| Coverage windows/roles/gaps | Cover & conflicts | Required counts/time windows/roles, reservation behaviour, acknowledgement/dismissal/clear and audit retained |
| Staff/client/leave/turnaround conflicts | Cover & conflicts queue | Correct complete set; clear hard block vs review warning; durable actions and canonical IDs |
| Time off | Availability & leave | Retain roster blocks and their add/remove permissions; clearly distinguish them from HR leave |
| HR leave review | Availability & leave contextual link/action | Keep canonical HR approval/decline and return context; do not create a second leave ledger |
| Self availability | Permission-appropriate self-service route | A worker can manage self without coordinator access; preserve selected person and overnight support |
| Coordinator availability | Availability & leave | Approved-site staff list; scoped time-off visibility; exact edit-any capability |
| Capacity heatmap / capacity rail | Reports and contextual Roster summary | Correct hours, clear scheduled/paid definitions and direct link to contributing shifts |
| Analytics / trend / distribution | Reports | Preserve period, authorised population and underlying detail links; do not infer policy from charts |
| Templates list/detail | Patterns → Templates | Reusable library, notes/rows, authorised house/person/staff references and explicit scope |
| Template create/update/duplicate/delete | Patterns → Templates | Exact capability per action; dirty-close guard; existing row data preserved |
| Template apply | Patterns → Templates | Date/range selection, preview, conflicts, atomic behaviour, overrides and duplicate protection |
| Series index/detail / occurrence statuses | Patterns → Recurring schedules | Preserve series identity, exceptions, active/open/replacement counts, next occurrence and deep links |
| Cancel future series occurrences | Patterns → Recurring schedules | Correct authority, fresh state, lock handling, reason and all cascades; current/past history retained |
| Publish review / confirm | Roster primary action when enabled | Same site/period, eligibility results, real blockers/warnings and durable audit |
| Diff / republish / unpublish | Roster publication history and Reports | Preserve before/after changes, publication state, notification semantics and payroll restrictions |
| Auto-schedule suggestion run | Cover & conflicts action when enabled | Retain queued/progress/failure states; human accept/dismiss/apply/apply-accepted; no silent automatic assignment |
| Job Board For you / all open | Available shifts | Eligibility reasons, privacy-limited shift details, date filters, alerts preference and refresh state |
| My claims / pending approval | Available shifts | Claim/approve retained; add withdrawal/rejection/reopen as real workflows, not cosmetic dismissals |
| Job Board replacements | Available shifts and Cover & conflicts | Same canonical request and assignment; no duplicate cover workflow |
| Attendance sessions / on clock / handovers | Attendance | Self and coordinator views, missed clock-out visibility, correct day/week scope |
| Clock in/out / break start/end | My Day and existing Attendance entry | One session authority; no new clock system; prevent accidental double action |
| Clock-out checklist/tasks/handover | Existing shared flow | Preserve completion blockers, drafts, actual time, reasons and draft-timesheet creation |
| End another session / correct session | Attendance reviewer action | Permission, reason, audit, payroll lock and reconciliation retained |
| Timesheets list / quick view / create/edit | Timesheets | Week/status/person/site, allocations, worked time, notes, pay fields and canonical shift links |
| Submit / return / resubmit / approve / reject | Timesheets and authorised My Day action | Explicit states, reasons, notifications and immutable approval semantics |
| Bulk approve / return / reject | Timesheets | Row eligibility, selected scope, partial-failure reporting, audit and no hidden action on filtered-out rows |
| Archive / restore | Timesheets | Preserve visibility and authority; never imply payroll deletion |
| Amendments / payroll adjustments | Timesheets → Payroll adjustments | Request/review history, locked original, external-processing state and explicit discoverable queue |
| Payroll/HR time-entry integration | Existing services and Finance/HR links | Stable IDs, idempotent sync, snapshots, segment/rate rules and no double export/payment |
| Handovers Cards/List/Board and filters | Handovers | Draft/submitted/acknowledged and outstanding views; same care scope |
| Handover create/edit/save draft/submit | Existing shared handover flow | Structured fields, worker notes, incoming worker/person/site and interruption recovery |
| Read/acknowledge/follow-up | My Day and Handovers | Correct recipient/site, durable acknowledgement and linked follow-up task |
| Shift Notes Cards/List/status/day/filter | Shift notes | Same note types, flags, review, person/staff filters and honest missing-note presentation |
| Shift note create/edit/privacy/review/export | Shift notes | Preserve fields and audit; correct privacy and export filtering before wider exposure |
| Control Room current/recent duty sessions | Independent Control Room; staffing link in Rostering | Preserve distinct operator-duty IDs, lead/team, live status and history |
| Control Room prepared/accepted handover | Independent Control Room | Versions, stale checks, incoming acceptance, operator notes and overrides preserved |
| Future Control Room staffing | Rostering → Control Room, later bounded addition | Explicit planned interval/lead/team and link to actual duty session; no fake care client |
| My Day tasks/medicines/people/shift/handover | My Day | Canonical frontline home, assignment/site scope and medication permissions; no dependency on coordinator access |
| Notifications, reports and legacy links | Existing owners, linked contextually | Saved targets still resolve; correct recipients; current permission checks on arrival |

## Roles and boundaries

| User context | Expected experience and boundary |
| --- | --- |
| Support worker | My Day first; own roster, permitted open shifts, own availability, clock, handover and timesheets; current assigned care only |
| House/team coordinator | Plan and manage approved houses; safe cover, leave impact and authorised reviews; no unrelated-house data through IDs, counts or pickers |
| Roster viewer | Read permitted schedules; no implicit series cancellation or assignment rights |
| Availability/template specialist | Reach the permitted function without acquiring unrelated roster or shift-management access |
| Payroll reviewer | Authorised timesheets/amendments; no implied payroll execution or access to unrelated care notes |
| Control Room operator/lead | Independent operations and permitted operator staffing; no automatic access to care rosters or care-plan editing |
| Administrator with broad approved authority | Broad function set with the same record correctness, locks and audit; not a substitute for testing limited roles |
| Family/portal user or unapproved/inactive staff | No accidental exposure through moved navigation, exports, calendars, notes or direct object routes |

A possible implementation route is `/operations/rostering/control-room` rendered in the same Rostering shell with a Control Room-specific permission and payload. This is an in-page section, not a new sidebar category. Do not add `controlRoom.viewAny` as a blanket alternative that grants access to the entire care Rostering controller. Existing `/control-room/shifts` links must continue to reach the correct duty function or a compatible clearly labelled destination.

## Forms, states and desktop interaction

Use the approved shared shell for entity/multi-section forms and the appropriate small reason/confirmation component for compact actions. Keep the user's input after validation/network failure; make success reflect a committed record. Preserve current validation and typed field semantics rather than rewriting a form's business logic to match its appearance.

| State | Required user experience |
| --- | --- |
| Loading | Stable layout and honest loading label; do not show a temporary zero as a true metric |
| Empty selected range | State the range and active filters; offer a relevant create/clear-filter action if permitted |
| Missing configuration | Explain the missing coverage/availability/payroll setting and link to its authorised owner |
| Permission denied | Clear boundary and safe route back; no leaked record detail; avoid rendering an actionable control without capability |
| Save validation failure | Keep entered data; focus/identify the relevant field or review error; preserve wizard step |
| Stale record | Explain that someone changed the record, show fresh values and offer a deliberate retry/review |
| Unsaved close | Guard destructive closing of a dirty form; respect draft semantics where supported |
| Network/partial load error | Keep context, label stale content, offer Retry; do not imply no shifts or safe coverage |
| Completed/locked record | Show reason and available correction/amendment route; preserve read-only history |
| Bulk action | Show selected count/scope, failures and saved outcomes; no invented blanket success |
| Keyboard/zoom | Visible focus, named controls, logical tab order, no trapped panels and no drag-only workflow |

## Entry points outside Workforce

The [complete sidebar register](module-status.md) preserves every visible module entry. The [cross-module architecture](cross-module-architecture.md) specifies the destination context and return receipt for each workflow. These are part of the navigation work, not an optional later integration:

- House → People → Shift Coverage / Staff Requirements → exact house, requirement and local-period roster view.
- HR employee → work schedule / availability / eligibility; Leave and Offboarding → complete affected-shifts and cover-progress receipt.
- Person supported → support schedule / care team; approved care or behaviour plan → staffing impact for its effective dates.
- H&S training or authorised work restriction → affected duties, source-limited reason and responsible coordinator.
- Respite stay / Fleet transport plan / committed IT booking → staffing impact with source ownership and return context retained.
- Timesheet / HR time / Payroll → canonical original shift and authorised adjustment history.
- Control Room duty → operator staffing plan; return to the independent live-duty and accepted-handover workflow.

Links retain canonical IDs, approved site, relevant person/staff, selected period and safe return location. They recheck authority on arrival. Where the viewer cannot open the source, show only a permitted summary and the responsible team rather than a dead link or private details. HR Calendar and Meal Planner's visible-but-forbidden preview entries require effective permission alignment; do not grant new privileges merely to make navigation work.

Navigation work is complete only when this register is checked against the old experience, existing route tests, the outside-module entry points and the supported-living scenarios in [verification.md](verification.md).
