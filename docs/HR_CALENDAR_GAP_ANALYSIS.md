# HR Calendar (Org) Redesign — Gap Analysis

Target: `/hr/calendar` — the organisation / manager lens (not the personal `/hr/my`
calendar). Measured against the handover package (`00_README` … `03_LOOP_CHECKLIST`).

Status legend: ✅ Present · 🟡 Partial · ⛔ Missing / deferred.

> The visual source-of-truth prototype (`HR Calendar.dc.html`) referenced by the
> handover was **not included** in the delivered zip — only the four markdown
> handover docs were. The build below follows the written spec + the parity bar
> (`/hr/people`, `/hr/leave`) and the existing gold-standard kit. A pixel diff
> against the prototype is still outstanding (it needs the HTML file).

---

## What shipped this pass

### Backend
- ✅ **`HrCalendarAggregator`** (`app/Domain/Hr/Services/HrCalendarAggregator.php`) —
  one tenant-scoped entry point returning a flat `CalendarLayerFeed[]` across six
  layers, **delegating** to the existing feeds rather than re-deriving them:
  events → `HrCalendarEvent`; leave + holidays → `LeaveService::calendarFeed()`
  (reuses the hub's redaction + roster-conflict context); shifts + coverage →
  `Shift` + `ShiftCoverageService`; compliance → cert/vetting/licence expiries;
  milestones → `HrEmployeeProfile` (birthday/anniversary/probation/contract-end).
- ✅ **`GET /hr/calendar/feed`** (`CalendarController::feed`) — validated range
  fetch, layer-gated, permission-gated (`canView`), tenant-scoped.
- ✅ **`index()` rebuilt** to bootstrap chrome only (sites, departments, teams,
  hero stats, "Up next", iCal URL, permissions); events are fetched client-side.
- ✅ **Tenant-scoping leak fixed** in `ICalController::feed` — calendar events are
  now `forTenant`-scoped (previously any valid token returned **all tenants'**
  events). [bug A1]
- ✅ Shared TS contract `resources/js/lib/calendar/layer-feed.ts`
  (`CalendarLayerFeed`, `CALENDAR_LAYERS`, `LAYER_META`).
- ✅ Feature test `tests/Feature/Hr/HrCalendarFeedTest.php` (feed shape, event
  layer, **cross-tenant isolation**, validation, bogus-layer fallback).

### Frontend
- ✅ **One layered calendar** on the shared `calendar-view.tsx` (not forked) — one
  client feed, grouped into layers; Month / Week / Day / **Agenda (listWeek)**.
- ✅ **Layer panel** (popover) + **persistent legend**, both with per-layer counts;
  persisted to `localStorage` + `?layers=`. `?view=` deep-linked.
- ✅ **Filter bar** — site / department / team + search, combined with layer
  toggles. Site filter is functional end-to-end; holidays stay org-wide.
- ✅ **Golden hero, no clock** (`calendar-hero.tsx`) on the people-hero pattern:
  medallion + context, four click-through stats (Events this week · On leave today
  · Coverage gaps today *(amber)* · Renewals ≤30d *(amber)*), quick actions
  (New event *(gated)* · Today · Subscribe), "needs attention" chips, and a
  fixed-width **"Up next"** right rail.
- ✅ **Event wizard** (`event-wizard-dialog.tsx`) — Add-Client-grade `WizardShell`
  clone: Basics → When → Who & where → Review, stepper rail, per-step validation,
  server-error→step mapping, **Save & add another**, `SuccessPane`. Single
  instance for create **and** edit; click-to-create (drag-select) escalates into
  it pre-filled. Delete via `alert-dialog` confirmation (never instant).
- ✅ **Read-only layers deep-link**: leave → `/hr/leave`, shifts/gaps →
  `/operations/rostering`, renewals → `/hr/compliance`. Never edited here.
- ✅ **Leave redaction honoured** — sensitive reasons hidden unless
  `hr.leave.manage`; redacted entries flagged in the feed + styled.
- ✅ **Renewals tab** — folds the compliance layer into a list (urgency-coloured,
  click-through to Compliance).
- ✅ **iCal Subscribe modal** (`ical-subscribe-dialog.tsx`) — surfaces the existing
  `ICalController` (copy URL, Add to Google/Apple/Outlook, regenerate token). This
  backend had **no UI** before.
- ✅ `holiday` is now a selectable category in the wizard. [bug B2, create side]

Gates: `tsc --noEmit` clean (calendar files), `eslint` clean (no raw hex —
colours are token references / `color-mix()`), `php -l` clean on all PHP.

---

## Checklist scorecard

| Area | Item | Status |
|---|---|---|
| Hero | Golden band, amber accents, no clock | ✅ |
| Hero | 4 click-through stats | ✅ |
| Hero | Quick actions (New gated · Today · Subscribe) | ✅ |
| Hero | "Manage layers" quick action | 🟡 toolbar Layers popover instead (not duplicated) |
| Hero | Needs-attention chips + "Up next" rail | ✅ |
| Calendar | Single `calendar-view`, token colours | ✅ |
| Calendar | Layer panel + counts + legend, `localStorage`+`?layers=` | ✅ |
| Calendar | Month/Week/Day/Agenda, `?view=` | ✅ |
| Calendar | Site/team/department filter + search, persisted | ✅ (search not persisted) |
| Calendar | One aggregator; editors untouched | ✅ |
| Layers | Events: CRUD via wizard | ✅ |
| Layers | Events: drag-move / resize | ⛔ deferred |
| Layers | Leave: read-only, pending styled, redaction, Open in Leave | ✅ |
| Layers | Shifts/coverage: gaps stand out, Open in Rostering | ✅ |
| Layers | Holidays: shaded bg, single source, excluded from counts | ✅ |
| Layers | Compliance renewals: urgency-coloured, Renewals view | ✅ |
| Layers | Milestones: subtle, off by default | ✅ |
| Interactions | Click/drag-to-create → wizard | ✅ (quick-add popover ⛔) |
| Interactions | Right-click (entries / cells / tab strip) | ⛔ deferred |
| Interactions | Detail popover + hover preview | ⛔ deferred (click deep-links / opens wizard) |
| Interactions | Recurring-edit scope prompt | ⛔ deferred (needs recurrence schema) |
| Interactions | Delete confirmation dialog | ✅ |
| Interactions | Year date-picker (month/day jump) | ⛔ deferred |
| Interactions | Keyboard shortcuts (`/ n t 1-4 ←→ Esc`) | ⛔ deferred |
| Interactions | Skeleton / empty / error states | 🟡 error + empty ✅; skeleton ⛔ |
| Wizard | Stepper + validation + error→step + Save&add + Success | ✅ |
| Wizard | Basics→When→Who→Details→Review (5-step w/ recurrence/attendees/attachments) | 🟡 4-step over the live schema |
| Backend | Tenant bugs (`ICalController`) | ✅ |
| Backend | `my-hr-calendar` unscoped query | ✅ n/a — no such query exists in current code |
| Backend | `holiday` selectable | ✅ (create); category table ⛔ |
| Backend | `department` → `HrDepartment` FK | ⛔ deferred (still free-text on events) |
| Backend | Categories / recurrence / attendees / reminders / attachments schema | ⛔ deferred |
| Backend | `HrCalendarAggregator` + `CalendarLayerFeed` + feed | ✅ |
| Backend | People-milestones deriver | ✅ |
| Backend | iCal surfaced | ✅ |
| Backend | Right-click write routes | ⛔ deferred (no right-click yet) |
| Cleanup | Time-Off tab + `time-off.tsx` + `TimeOffCalendarController` deleted | ⛔ deferred (see below) |

---

## Follow-up progress (shipped 2026-06-27)

The deferred backlog below has since been worked through and merged to `main`
(each with feature tests — the calendar suite is **11 tests / 44 assertions green**):

- ✅ **D1** Duplicate Time-Off calendar retired (controller/route/page/legacy tabs
  deleted; `canView` broadened so leave viewers land on the unified page).
- ✅ **D2** Events bound to `HrDepartment` (FK + backfill; event department filter live).
- ✅ **D3** `hr_calendar_event_categories` table + `category_id` FK; wizard tiles from DB.
- ✅ **D4** Recurrence (rrule expansion, override children, "this / this & following /
  all" edit-scope gated on `calendar.manage_recurring`).
- ✅ **D5** Attendees + RSVP (audience selector + people picker; RSVP endpoint).
- ✅ **D6** Reminders + every-minute `hr:dispatch-calendar-reminders` dispatch job.
- ✅ **D7** Attachments on the private disk (mime allowlist, hardened serving).
- ✅ **D8** Rich interactions — click → detail popover (deep-link / Edit-Dup-Delete);
  right-click context menu on entries + day cells; drag-move/resize for standalone
  events (optimistic PUT, revert); 12-mini-month year picker; keyboard shortcuts
  (`/ n t 1-4 ←→ Esc`); quick-add popover with "More options →" escalation; hover
  preview + loading skeleton + delete confirm. Build clean; browser-verified.
- ⛔ Pixel-diff vs `HR Calendar.dc.html` (still not provided).

**All deferred work (D1–D8) is now shipped to `main`.** Backend suite: 11 tests /
44 assertions green; frontend: clean `tsc`/`eslint`/`vite build`; `/hr/calendar`
browser-verified (page, feed/layers, hero, wizard, iCal, year picker).

## Deferred — recommended follow-up passes

These are genuinely large and/or need schema + browser verification. None block
shipping the redesigned page; the page is fully functional without them.

1. **Extended event schema + capabilities** (handover §B):
   `hr_calendar_event_categories`, recurrence columns (`rrule`,
   `recurrence_until`, `recurrence_parent_id`, `is_exception`),
   `hr_calendar_event_attendees`, `hr_calendar_event_reminders`,
   `hr_calendar_event_attachments` + the reminder dispatch job. Then extend the
   wizard to the full 5 steps (Who: audience/RSVP via `PeoplePicker`; Details:
   reminders + attachments via `forceFormData`) and add the recurring-edit scope
   prompt (this / this & following / all) wired to `calendar.manage_recurring`.
2. **`department` → `HrDepartment` FK** on `hr_calendar_events` (migrate + backfill
   from the free-text strings); then re-enable event department filtering in the
   aggregator (currently a no-op on events; already live for milestones).
3. **Rich interactions** (handover §4): `CalendarContextMenu` on entries / day
   cells / the tab strip (mould of `rostering/shift-context-menu.tsx`); detail
   popover + hover preview; the 12-mini-month **year picker**; drag-move / resize
   for the events layer (optimistic `PUT` + revert); keyboard shortcuts; the
   quick-add popover with a "More options →" escalation; calendar skeleton state.
4. **Duplicate-calendar removal** (handover §A4): delete `TimeOffCalendarController`
   + route `routes/hr.php` (`calendar.time-off`) + `pages/hr/calendar/time-off.tsx`
   + the legacy `components/hr/calendar-tabs.tsx`, now that the Leave layer covers
   them. **Left intact this pass to avoid breaking links while other loops run** —
   the new page already supersedes them; this is a clean isolated PR.
5. **Pixel-diff vs `HR Calendar.dc.html`** once that prototype file is provided.

---

## Verification status

- ✅ `tsc`, `eslint`, `php -l` — clean on all changed files.
- ⛔ **Browser test pending.** The worktree isn't served by Herd, and PHP feature
  tests in a junctioned-vendor worktree autoload the *parent* app, so the new
  backend can only be exercised after merging to the parent and running there
  (then browsing as the demo admin on `.test` / `.com`). The feature test and a
  full Month/Week/Day/Agenda × layer × modal screenshot sweep should run at that
  point.
