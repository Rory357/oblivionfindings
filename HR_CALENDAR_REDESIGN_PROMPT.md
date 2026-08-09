# HR "Calendar" (Org Calendar) Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent (Claude design — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/calendar` (every view, every layer toggled, every modal open) and diff against the gold-standard pages/components before continuing. Start with the audit in §A, then build §B–§L. **Anything you discover that needs backend/data work goes into §K "Backend handoff for Claude Code" — append to it as you go so Chane has one clean hand-off list when the design is done.**

**Page:** `https://oblivionfindings.com/hr/calendar` (organisation / manager lens). The **personal** calendar already lives in the `/hr/my` hero popover (`MyHrCalendar`) — **do not duplicate it**; this page is the org-wide view (Chane's decision: org-wide scope).
**Frontend:** `resources/js/pages/hr/calendar/{index,time-off}.tsx` · **tabs:** `resources/js/components/hr/calendar-tabs.tsx` · **shared engine:** `resources/js/components/calendar/calendar-view.tsx` (FullCalendar wrapper) · **gold-standard interaction reference:** `resources/js/components/hr/my-hr-calendar.tsx` (+ `my-hr-types.ts`)
**Backend:** `app/Http/Controllers/Hr/CalendarController.php` (index + events store/update/destroy) · `TimeOffCalendarController.php` · `ComplianceCalendarController.php` · `ICalController.php` · `MyHrController::calendar` (JSON feed) · routes in `routes/hr.php` (`:84` my-feed, `:235` compliance.calendar, `:718` calendar.time-off, `:905-910` calendar group + events, `:1096-1097` ical)
**Feeds/Engines to reuse (don't re-query):** `app/Domain/Hr/Services/LeaveService.php::calendarFeed()` (leave + holidays) · `app/Http/Controllers/Hr/Concerns/BuildsMyHrShell.php::myHrCalendarFeed` (shifts + leave + holidays) · root `app/Http/Controllers/CalendarController.php::events()` (roster shifts, route `operations.php:870`)
**Models:** `HrCalendarEvent` (`app/Domain/Hr/Models/`) + migration `database/migrations/2026_03_22_100030_create_hr_calendar_events_table.php` · `HrLeaveRequest`, `HrPublicHoliday`, `HrEmployeeProfile`, `HrDepartment` · `app/Models/Shift.php` · compliance models (`HrStaffComplianceStatus`, `StaffBackgroundCheck`, `HrDriverEligibility`, `HrCourseEnrollment`)
**Recurrence lib (reuse, don't reinvent):** `resources/js/lib/calendar/recur.ts` (RRULE/ICS/conflict helpers — today only used by `pages/sites/calendar/*`)
**Gold-standard modal to clone:** `resources/js/components/clients/add-client-dialog.tsx` on `resources/js/components/wizard/shell.tsx` (`WizardShell`) + `resources/js/components/wizard/primitives.tsx`, via `resources/js/components/hr/wizard.ts` (`useWizard`). Warm reference already shipped: `resources/js/components/hr/leave-request-dialog.tsx`.
**Gold-standard hero:** `resources/js/components/hr/my-hr-hero.tsx` (`HERO_STYLE` golden band + `--hr-amber`) and `people-hero.tsx` (manager lens, **no clock** — the right shape). **Exclude** `my-hr-clock-card.tsx`.

---

## 0. Mission

Turn `/hr/calendar` into a **premium, end-to-end organisation calendar** that feels identical in quality to our gold-standard surfaces — **`/hr/people`**, **`/hr/leave`** (warm redesign), **`/meds/today`**, **`/health-safety`** — and reuses their exact components and tokens. This is the **single org-wide "what's happening" view**: HR/company events you create here, plus read-only **leave**, **roster shifts/coverage**, **compliance renewals** and **people milestones** as toggleable layers — all on **one** FullCalendar engine.

Today `/hr/calendar` is **two mismatched tabs masquerading as a page**: a **"Schedule"** tab (FullCalendar via the shared `calendar-view.tsx`, shows HR events + grey approved-leave blocks) and a **"Time Off"** tab (`time-off.tsx` — a **hand-rolled `grid-cols-7` month grid**, the "old view" Chane means, shows who's-off + NZ holidays). A third related view, **Compliance "Renewals"**, sits under the Compliance hub as a **grouped list** (no calendar grid at all). The result: **four calendars** across the app (Schedule, Time-Off, the Leave-hub swimlane, the Rostering planner) re-render overlapping leave/shift/holiday data, **three of them bespoke**; the same approved-leave query is re-implemented **4×** and NZ public holidays **3×**. The two clearest redundancies sit *inside this one hub* — "Schedule" and "Time Off" answer the same "who's off this month" question one tab apart, with two different engines.

Bring it to parity: give it the **golden HR hero band (no clock, fitted to the calendar)**, collapse the redundant tabs into **one unified, layered calendar** on the shared `calendar-view.tsx`, **delete `/hr/calendar/time-off`**, fold compliance renewals in as a layer + an agenda view, make every event flow an **Add-Client-grade wizard** (full workflow — recurrence, reminders, attendees/RSVP, attachments), add **right-click menus** (entries, day cells, tabs) and **drag-to-create / move / resize**, and stand up **one aggregator** so leave/shift/holiday data is fetched in a single place and the roster & leave hub never disagree with the calendar. Result: a calendar that is **accurate, glanceable, premium and non-duplicative** — not two grey grids plus a list.

---

## 1. Non-negotiables

1. **One calendar engine, layered — kill the duplication.** Replace the Schedule + Time-Off tabs with a **single unified calendar** rendered by the shared `resources/js/components/calendar/calendar-view.tsx` (FullCalendar). **Delete `resources/js/pages/hr/calendar/time-off.tsx` and `TimeOffCalendarController`** — its job ("who's off + holidays, filter by dept/site") becomes the **Leave layer** here. **Do NOT** create a third bespoke grid; the `grid-cols-7` month grid is the outlier to remove, exactly as `LEAVE_REDESIGN_PROMPT.md §2.4` already mandates.
2. **Aggregate, never re-query.** Build **one** `HrCalendarAggregator` (server) + **one** shared feed type `CalendarLayerFeed` (TS, in `resources/js/lib/calendar/`). The aggregator **delegates** to the existing `LeaveService::calendarFeed` (leave + holidays), the rostering `CalendarController::events` (shifts), and the compliance models — so the leave/holiday/shift SQL lives in **one** place, not copy-pasted across controllers (§C/§K). The bespoke **editors** (rostering planner, leave swimlane) keep their own richer feeds — we don't touch them.
3. **Read-only layers deep-link; they don't get edited here.** Leave, shifts and renewals are **view-only overlays**. Clicking one opens a detail popover with **"Open in Rostering" / "Open in Leave" / "Open in Compliance"** — never an editor on this page (mirror how `pages/hr/calendar/index.tsx:175-178` already blocks editing `leave-*` ids). Only **HR/company events** are CRUD-able here.
4. **Information-gathering = modals, full workflows.** Every create/edit flow is a **wizard dialog** cloning the Add-Client shell (§F) — **not** the current single-step `Dialog`, not an inline form, not a route. "Full workflow" means multi-step with recurrence, attendees, reminders and attachments (Chane's decision), per-step validation, `Save & add another`, and a `SuccessPane`.
5. **Golden hero, NO clock.** Build a `CalendarHero` on the shared golden band (`HERO_STYLE` + `--hr-amber` from `my-hr-hero.tsx`, manager-lens shape from `people-hero.tsx`). **No clock** — the right column carries calendar-appropriate content (§B).
6. **Right-click everywhere.** Chane explicitly wants right-click "under tabs etc." Context menus on **calendar entries**, **empty day cells**, and the **tab strip** (§E), in the mould of the gold-standard `my-hr-calendar.tsx` (which already has right-click + kebab + hover-preview) and `rostering/shift-context-menu.tsx`.
7. **Reuse the kit — never hand-roll a primitive we already have** (§2). Hero, modal, badges, status colours, context menu, empty/skeleton states, calendar engine and toasts all come from the shared kit. **No new bespoke widgets, no raw hex** (ESLint blocks it — colours come from `resources/css/app.css` tokens).
8. **Web-only desktop app.** No phone frames, **no clock** in the hero. Design for mouse + keyboard: hover states, **right-click menus**, drag, keyboard shortcuts. Responsive down to a small laptop is fine. (A dedicated mobile app comes later.)
9. **Locale & scope stay NZ + org-wide.** `en-NZ`, Monday-first, NZ public holidays (Matariki, regional anniversaries). **Org-wide scope** (Chane's decision) — no personal "Mine" view here (that's `/hr/my`); a **site/team/department filter** is the segmentation. Everything tenant-scoped via the same pattern `CalendarController` uses (`forTenant`) — **and fix the feeds that currently don't** (§K-bugs).
10. **Verify each pass:** clean `npm run build`, `npm run types` (no TS errors), `npm run lint`; screenshot the changed surface with each layer toggled and each modal open; confirm it matches the reference hero/modal/menu. Don't move on with a broken pass.

---

## A. Audit & benchmark first (do this before building)

Study `/hr/people`, `/hr/leave`, `/meds/today`, `/health-safety` and **interact** with them — they are the parity bar. Then study the patterns you must clone:

- **Golden hero** → `resources/js/components/hr/my-hr-hero.tsx` (`HERO_STYLE` brand-gradient band + `--hr-amber` + `HeroStat` + `QuickAction`) and `people-hero.tsx` (manager lens, **no clock**, clickable `HeroStat`s, right-rail toggle persisted to `localStorage`). If a shared `resources/js/components/hr/hero-kit.tsx` now exists (from the Feed/People/Leave work), build `CalendarHero` on it; otherwise lift `HERO_STYLE`/`HeroStat`/`QuickAction` into that shared kit so My HR, People, Leave, Feed **and** Calendar share one hero spine (the standardisation win). **Drop `MyHrClockCard`.**
- **Gold-standard modal** → `resources/js/components/clients/add-client-dialog.tsx` (full-height bespoke shell: **stepper rail + completeness meter + per-step validation + server-error→step mapping + Save & add another + `SuccessPane`**) built on `WizardShell` (`resources/js/components/wizard/shell.tsx`) + `@/components/wizard/primitives`; warm, shipped example of the same shell = `resources/js/components/hr/leave-request-dialog.tsx` (uses `useWizard`, `railExtra`, glow buttons, token tints). This is the modal to replicate for **every** event flow (§F).
- **Calendar engine** → `resources/js/components/calendar/calendar-view.tsx` — the themed FullCalendar wrapper whose `CALENDAR_STYLES` were "extracted verbatim so every FullCalendar surface renders identically." It already powers `/my-calendar`, `/hr/calendar` (Schedule), Finance and the client profile. **Everything renders through this.** No new calendar library; FullCalendar plugins (`daygrid`, `timegrid`, `list`, `interaction`) are already installed.
- **Gold-standard interactions** → `resources/js/components/hr/my-hr-calendar.tsx` — the richest interaction model in the app: **right-click context menu + kebab menus** ("Request leave on this day", "View in roster", "Add to calendar"), **hover preview**, **month/year picker**, lazy month paging. Pull this interaction quality onto `/hr/calendar`.
- **Right-click pattern** → `resources/js/components/rostering/shift-context-menu.tsx` (`ShiftContextMenu`, portal-rendered, viewport-flipping, Esc/outside-click close, icon+label+`kbd`+tone). Build a `CalendarContextMenu` in the same mould.

Then audit `/hr/calendar` against this **best-in-class shared-calendar checklist** (mark each **Present / Partial / Missing**, then close gaps in §B–§L). Benchmarks: **Teamup** (the reference for toggleable colour-coded *layers/sub-calendars* on one grid), **Google Calendar / Outlook 365** (multi-calendar overlay, drag-create/move/resize, recurrence (RRULE), reminders, guests/RSVP, attachments, agenda view, .ics subscribe), **BambooHR** (who's-out + company events + sync), **Deputy / When I Work / Sling** (shift coverage on a calendar), **Notion Calendar / Resource Guru** (clean layered scheduling).

**Checklist (fill this in as the first pass and paste back the results):**

- **Hero:** golden brand band • calendar stats that matter (events this week, on leave today, coverage gaps today, renewals due ≤30d) • quick actions (New event / Today / Subscribe (iCal) / Manage layers) • live alert badges (coverage gaps today, renewals overdue) with drill-down • **no clock**.
- **One layered calendar:** a single `calendar-view.tsx` grid with **toggleable layers** (HR events / Leave / Shifts & coverage / Holidays / Compliance renewals / People milestones), colour-coded with a persistent **layer panel + legend**; **Month / Week / Day / Agenda(list)** views; site/team/department filter; `?view=`, `?date=`, `?layers=` deep-link.
- **No duplication:** Time-Off tab and its bespoke grid **gone**; Renewals folded in; leave/shift/holiday data comes from **one aggregator**; the Leave-hub calendar and Rostering planner are **untouched** (we deep-link to them).
- **Interactions:** click-to-create, **drag-to-create**, **drag-to-move**, **resize** (HR events only); event click → detail popover; **right-click** on entries, day cells and tabs; hover preview; keyboard (`/`, `n`, `t`, arrows, `Esc`).
- **Event modal (full workflow):** wizard (Basics → When → Who → Details → Review) with **recurrence (RRULE)**, **reminders/notifications**, **attendees/RSVP** (audience = whole org / site / team / dept / picked people), **attachments**, **Save & add another**, completeness, `SuccessPane`.
- **Layers wired end-to-end:** each layer is a real feed; read-only ones deep-link out; HR events are full CRUD; counts/colours consistent via `StatusBadge`/tokens.
- **End-to-end:** every visible action has a wired route + toast; **iCal subscribe surfaced** (backend exists, no UI today); no dead buttons; real empty/skeleton/error states.

> **Known gaps the audit already surfaced** (confirm, then fix):
> - **Two engines, one hub.** "Schedule" = FullCalendar; "Time Off" = hand-rolled `grid-cols-7` in `time-off.tsx` with its own `leaveTypeColors`. Different day cells, today-highlight, chips. **Collapse to one.**
> - **Non-overlapping feeds.** Schedule shows `HrCalendarEvent` + approved leave; Time-Off shows leave + holidays; **neither shows shifts**; the My-HR popover shows shifts+leave+holidays but **not** `HrCalendarEvent`. So a company-wide event created here **never appears in an employee's calendar**, and the org calendar **can't show coverage**. Converge via the aggregator (§C).
> - **Thin event model.** `hr_calendar_events` = `title, description, event_type(string), starts_at, ends_at, is_all_day, location, department(free-text), site_id, created_by`. **No** recurrence, reminders, attendees, attachments, categories. `event_type` is a free string with a **hardcoded enum** (`company,team,training,social,holiday`); `holiday` is **coloured/labelled in the UI but missing from the create dropdown** — a dead option. `department` is free text, not bound to `HrDepartment` (the Time-Off filter *does* use real departments — inconsistent).
> - **Thin modal.** One single-step shadcn `Dialog` (`index.tsx:316-531`); server-side validation only; surfaces only `errors.title`; no stepper, no `Save & add another`, no recurrence/attendee/reminder/attachment fields. Not the Add-Client gold standard.
> - **No drag/resize/select** on the Schedule tab (`editable`/`eventDrop`/`eventResize`/`selectable` unset). **No right-click** anywhere on this page (the gold-standard right-click lives only in the off-page `my-hr-calendar.tsx`). Only Month+Week exposed (no Day, no Agenda/list) although the shared wrapper supports them.
> - **iCal built but unreachable.** `ICalController` + `/hr/ical/{token}` + `generateToken` exist (a working token-auth `.ics`) but there is **no "Subscribe / Add to Google/Outlook" button** anywhere on the page.
> - **Scoping bug.** `ICalController::feed` and the `my-hr-calendar` `HrCalendarEvent` query **don't `forTenant`-scope** like `CalendarController` does (`ICalController.php:37`) — cross-tenant leak risk. Fix in §K.
> - **Renewals stranded.** `/hr/compliance/calendar` (`ComplianceCalendarController`) is a month-grouped **list**, off this hub. Fold its data in as the Compliance layer + the Agenda/Renewals view.
> - **`calendar.manage_recurring` permission exists** (seeded, accepted by `canView`/`canManage`) but **no recurrence feature is wired to it** — build the feature behind it.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero** — copy the gradient treatment from `resources/js/components/hr/my-hr-hero.tsx` / `people-hero.tsx`: `HERO_STYLE` (the `linear-gradient` over `--primary` + `boxShadow`; re-themes per tenant), the local `--hr-amber` accent for standout stats/alerts, `HeroStat` (label + big tabular value, clickable / `href`), `QuickAction` (icon + label). Build `CalendarHero` on the shared `hero-kit.tsx` if present (else refactor it out first). **Do not render `MyHrClockCard`.** Generic fallbacks live in `@/components/page` (`PageHero`, `PageHeroStats`, `PageHeroQuickActions`) — fallback only. Tokens: `--primary`, `--primary-foreground`, `--category-hr`, `--hr-amber`, `--shadow-hero`.

**2.2 Calendar** — `resources/js/components/calendar/calendar-view.tsx` is the **only** calendar renderer. Drive layers via FullCalendar `eventSources` (one source per layer, each its own colour), enable `selectable`, `editable` (HR-event sources only — gate per source), `dayMaxEvents`, and the full `headerToolbar` (`dayGridMonth,timeGridWeek,timeGridDay,listWeek`). Keep `firstDay={1}`, `en-NZ`. Reuse `resources/js/lib/calendar/recur.ts` for recurrence expansion/ICS — **do not** write a new RRULE impl.

**2.3 Modals / wizards** — `WizardShell` (`resources/js/components/wizard/shell.tsx`: `WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`) + `@/components/wizard/primitives` (`Field`, `FieldErr`, `Segmented`, `ChipMulti`, `SelectInput`, `TilePicker`, `StepHead`, `SubHead`, `InfoCard`, `Ring`, `IconType`, `WIZARD_*_CLASS`) + `useWizard` from `@/components/hr/wizard`. **Reference to clone: `add-client-dialog.tsx`; warm shipped example: `leave-request-dialog.tsx`.** Recipient/attendee picking → `@/components/hr/people-picker` (`PeoplePicker`, `PersonOption`). Base shadcn in `@/components/ui/`: `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`.

**2.4 Right-click menus + hover** — reuse the pattern, don't invent one. References: `@/components/rostering/shift-context-menu` and the right-click/kebab/hover in `@/components/hr/my-hr-calendar.tsx`. Build a `CalendarContextMenu` (portal-rendered, viewport-flipping, Esc/outside-click close, icon+label+`kbd`+tone); wire `onContextMenu`/`eventDidMount` + `dayCellDidMount`.

**2.5 Cards / states / badges** — **`@/components/ui/status-badge` (`StatusBadge`) everywhere** for event type/layer chips — do not hand-map colours (kill the `eventTypeColors`/`leaveTypeColors` maps). Also `@/components/ui/card`, `avatar`, `badge`, `empty-state` (`EmptyState`, `EmptyList`, `EmptySearch`), `error-state`, `loading-state`, `skeleton-card`. Avatars via the existing `getAvatarColor` fallback.

**2.6 Tokens & flourishes** — tokens only in `resources/css/app.css` (Tailwind v4 `@theme`/`:root`, OKLCH; see `docs/DESIGN_TOKENS.md`): `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`, `--live`, `--shadow-hero`/`--shadow-float`. `cn()` from `@/lib/utils`. **Toasts: sonner** (`<Toaster>` in `app.tsx`) on **every** write. Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards. Per-layer accent tints via `color-mix()` on tokens (as `leave-request-dialog.tsx` does) — never raw hex.

---

## B. Hero rethink — the golden band (NO clock, fitted to the calendar)

**Current:** the Schedule page uses `PageShell` + `PageHero category="hr"`; Time-Off uses `PageLayout` + a bespoke month nav. Not the golden band, inconsistent across tabs.

**Do:** build a **`CalendarHero`** (`resources/js/components/hr/calendar/calendar-hero.tsx`) using the **same gradient + `HeroStat` + `QuickAction` language as `people-hero.tsx`/`my-hr-hero.tsx`**, sized to this page. **No clock.** Compose:

- **Left column:** title **"Calendar"** (or "Team Calendar") + one-line context ("Everything happening across {tenant} — events, leave, shifts and renewals in one view"). Small icon medallion (`CalendarDays`).
- **Glanceable `HeroStat`s** (each click-filters / deep-links): **Events this week** (→ week view) • **On leave today** (→ Leave layer, today) • **Coverage gaps today** (`--hr-amber` if >0 → Shifts layer) • **Renewals due ≤30d** (`--hr-amber` if >0 → Compliance layer / Agenda). Tabular figures.
- **`QuickAction`s:** **New event** (opens the event wizard, §F-1) • **Today** (jump) • **Subscribe** (opens the iCal/subscribe modal, §F-4 — surfaces the existing `ICalController`) • **Manage layers** (opens the layer panel). Gate New event on `canManage`.
- **Live alert badges** (drill-down popover, like `people-hero`/`my-hr-hero` chips): "{n} coverage gaps today ⚠️", "{n} renewals overdue ⏰". Reuse the chip + `NeedsDot` pattern.
- **Right column (where My HR puts the clock):** since there's **no clock**, fill it with a page-appropriate cluster — a compact **"Up next" agenda** (next 4–5 events across active layers, click-through) **or** a **mini month picker** for fast navigation. Persist the choice to `localStorage` (`hrCalendar.heroRight`) like People does. Keeps the band balanced without a clock.

---

## C. Architecture — one unified, layered calendar (the de-duplication)

This is the core change and the answer to *"these feel duplicated."* **Confirmed approach: a unified layered calendar; delete the Time-Off tab; read-only layers deep-link into Rostering & Leave; one aggregator centralises the feeds.**

**Frontend:**
- Rebuild `resources/js/pages/hr/calendar/index.tsx` as the single calendar surface on `calendar-view.tsx`, with **one FullCalendar `eventSource` per layer** (§D), each a distinct token colour.
- A persistent **Layer panel** (left rail or a "Manage layers" popover): checkbox per layer + colour swatch + count; state persisted to `localStorage` (`hrCalendar.layers`) and reflected in `?layers=`. A compact **legend** stays visible on the grid.
- **Delete `resources/js/pages/hr/calendar/time-off.tsx`** and remove the "Time Off" tab from `calendar-tabs.tsx`.
- **Tabs (standardise, confirm set with Chane):** repurpose `calendar-tabs.tsx` into a clean strip — recommended: **Calendar** (the unified grid, default) · **Agenda** (chronological cross-layer list, filterable — great for scanning + the new home for renewals/upcoming) · **Renewals** (the compliance expiry list, folded from `/hr/compliance/calendar`, also available as a grid layer). `?tab=` deep-linked, per-tab counts as badges, **right-click tab menu** (§E). No Schedule/Time-Off split.

**Backend — one aggregator, not four queries:**
- Build `app/Domain/Hr/Services/HrCalendarAggregator.php` (tenant-scoped) that returns a **single typed payload** keyed by layer. It **delegates**:
  - **Events** → `HrCalendarEvent` (expanded for recurrence via the new fields, §K) — the only editable layer.
  - **Leave** → `LeaveService::calendarFeed()` (reuse — don't re-query `HrLeaveRequest`/`HrPublicHoliday`).
  - **Shifts & coverage** → the rostering `CalendarController::events()` feed (reuse — read-only).
  - **Holidays** → from `LeaveService`/`HrPublicHoliday` (single source; stop the 3× copy).
  - **Compliance renewals** → the `ComplianceCalendarController` derivation (certs/vetting/licences/training expiries).
  - **People milestones** → derived (birthdays, work anniversaries, probation end, contract end, review due) from `HrEmployeeProfile` (§K).
- Shared TS type `CalendarLayerFeed` in `resources/js/lib/calendar/layer-feed.ts` — discriminated union `{ layer: 'event'|'leave'|'shift'|'holiday'|'compliance'|'milestone', id, title, start, end, allDay, color, editable, deepLink?, extendedProps }`. The page consumes this; the bespoke editors keep their richer feeds.
- Range-fetch on `datesSet` (one request, all active layers) via partial Inertia reload, like the current `index.tsx:208-221`.

> **Do not** unify the **editors**: the Rostering planner (`components/rostering/calendar-pane.tsx`) and Leave swimlane (`components/hr/leave-calendar-pane.tsx`) are resource-timeline editing surfaces — leave them as-is and **deep-link** to them. The win is one *viewer* + one *feed*, not one component everywhere.

---

## D. The layers (all four enabled — Chane's decision)

Each is a FullCalendar `eventSource` with its own token colour, a legend entry, and a layer-panel toggle. Read-only layers are **non-editable** and open a **detail popover with a deep-link** on click.

1. **HR / company events** (editable; `--category-hr` / per-type tint) — meetings, training days, social, town-halls. Full CRUD via the wizard (§F-1), drag/resize, right-click. Types come from the new **event-category** table (§K), not the hardcoded string enum (and **fix the dead `holiday` option**).
2. **Leave / who's-off** (read-only; grey/`--status-neutral`, pending distinctly styled) — approved + pending leave from `LeaveService::calendarFeed`. **This replaces the deleted Time-Off tab.** Click → popover with person, type, dates → **"Open in Leave"**. Honour the leave hub's sensitive-reason redaction (recent commits) — never show a redacted reason here.
3. **Roster shifts & coverage gaps** (read-only; `--live` for shifts, `--status-critical` background for gaps) — from the rostering feed. Lets the org calendar show coverage at a glance. Click a shift → **"Open in Rostering"**; click a gap → Rostering on that day. **No shift editing here.**
4. **NZ public holidays** (shaded background, not blocks) — Matariki, regional anniversaries; excluded from any day-count math; single source via the aggregator.
5. **Compliance & cert renewals** (read-only; urgency-coloured `--status-warning`/`--status-critical`) — expiring certs/vetting/licences/training. Click → **"Open in Compliance"**. Also the **Renewals** agenda view (§C).
6. **People milestones** (read-only; subtle `--status-info`) — birthdays, work anniversaries, probation end, contract end, review due (§K-derived). Tasteful, toggled off by default if noisy.

> Layer visibility is per-user (`localStorage` + `?layers=`). Default-on: events, leave, holidays. Default-available: shifts, compliance, milestones.

---

## E. Right-click everywhere (entries, day cells **and** tabs)

Chane explicitly wants right-click options "under tabs etc." Build a `CalendarContextMenu` (mould of `shift-context-menu.tsx` / the menus in `my-hr-calendar.tsx`) and wire it on:

- **HR event entries:** **Open** · **Edit…** (wizard) · **Duplicate** · **Move to…** (date) · **Change type** · **Manage attendees…** · **Add reminder…** · **Copy link** · **Export .ics** · **Delete** (confirm via `alert-dialog`). Gate edit/delete on `canManage`; show `kbd` hints.
- **Read-only layer entries** (leave/shift/renewal/milestone): **Open detail** · **Open in {Leave|Rostering|Compliance}** · **View person profile** · **View coverage that day** · **Copy link**. No edit/delete.
- **Empty day cells:** **New event here** (pre-fills the date) · **View this day** (Day view) · **Show who's off** (Leave layer focus) · **Show coverage** (Shifts layer focus).
- **The tab strip itself:** right-click a tab → **Set as default view**, **Open**, **Pin**. Persist default tab + pins to `localStorage` (`hrCalendar.defaultTab`); render a `decorations` star/pin on the chosen tab.

Every menu action fires a toast and, where it writes, hits a real route (§K). No dead items.

---

## F. Modals = exact Add-Client wizard pattern (full workflows)

Every create/edit flow clones `add-client-dialog.tsx` on `WizardShell`: same **full-height shell** (`Dialog` + `DialogContent [&>button]:hidden`, `flex h-[min(92vh,860px)]`, left **stepper rail** `w-[248px] bg-sidebar` with per-step icons + blurbs + check-on-complete, **completeness meter** at the rail foot, header "Step X of N", **top progress bar**, scroll-contained body, footer with Back / Cancel / **Save & add another** / primary), same **engine** (Inertia `useForm`, client-side `validateStep`, `stepForError` to jump to the offending step, `SuccessPane`, `resetAll()` for Save & add another, `forceFormData` for uploads), from `WizardShell` + `@/components/wizard/primitives`. Use `useWizard`.

1. **Create / edit event** (the headline modal — **full workflow**, gated `canManage`). Steps:
   - **Basics** — title; **category** (`TilePicker` from the new category table, with icon + colour + helper copy; includes the fixed `holiday`/company/team/training/social and any custom); short description.
   - **When** — start/end (`datetime-local`), **All day** toggle, **timezone-safe** `en-NZ`; **Recurrence** builder (none / daily / weekly(by-day) / monthly / yearly / custom RRULE + end-after/until) using `lib/calendar/recur.ts`; **public-holiday-aware** hints. Live "Occurs: …" summary.
   - **Who** — **audience**: whole org / by site / by team / by department (bound to **`HrDepartment`**, not free text) / **specific people** (`PeoplePicker`); attendees become **RSVP** invitees (§K). Show estimated reach count.
   - **Details** — location (free text or site picker), notes, **attachments** (`forceFormData`), **reminders** (e.g. 1 day / 1 hour / 15 min before → notification, §K).
   - **Review & submit** — summary card (gradient hero like `leave-request-dialog.tsx`), recurrence preview, audience/reach, attachments, reminders. **Save & add another** (non-edit only). Edit mode reuses the same wizard and adds **Delete**; for recurring events, ask **"this / this & future / all"**.
   - Posts to `POST/PUT /hr/calendar/events` (extended payload, §K). Sonner toast on success; tasteful confetti optional.
2. **Event detail popover** (read for any layer) — on `eventClick`: summary, attendees + RSVP state, attachments, reminders, recurrence; actions (Edit/Duplicate/Delete for HR events; deep-link "Open in …" for read-only layers). Replaces silent/no-op clicks. Use a `popover`/`sheet`, not a full route.
3. **Quick add** (lightweight) — `dateClick`/drag-select opens a **mini** create popover (title + when + category) with **"More options →"** that escalates into the full wizard (§F-1). Keeps fast creation fast without losing the full workflow.
4. **Subscribe / Sync** (surfaces the existing `ICalController`) — a small dialog: copy the personal **iCal URL**, **Add to Google / Outlook / Apple** buttons, regenerate-token action (`/hr/ical/token`), and a note on what's included. This is **backend-complete today but has no UI** — just build the modal + wire it.

> Wire each modal from the page like today (`open` state + `<EventWizardDialog … />`), opened from the hero `QuickAction`s, the tab CTAs, day cells and row/context menus.

---

## G. Interactions & views — make it feel like Google/Outlook

- **Views:** Month / Week / Day / **Agenda (listWeek)** via the FullCalendar toolbar (the shared wrapper already supports all four; today only Month+Week are exposed). `?view=` deep-linked.
- **Create:** click-to-create (quick-add popover), **drag-select to create** across a range, **"New event" everywhere** (hero, day-cell menu, `n` key).
- **Edit (HR events only):** **drag-to-move**, **resize** (`editable`, `eventDrop`, `eventResize` → optimistic update + `PUT` + toast; revert on failure). Read-only layers are `editable:false`.
- **Filter bar:** site / team / department (bound to `HrDepartment`) + search; combine with layer toggles; persisted.
- **Hover preview** (like `my-hr-calendar.tsx`): title, time, layer, attendees count — without opening.
- **Keyboard:** `/` focus search, `n` new event, `t` today, `1/2/3/4` switch views, arrows move period, `Esc` close menus/dialogs.
- **States:** `skeleton-card`/calendar skeleton while a range loads; friendly `EmptyState` per view ("No events this week — create one" / "You're all clear ✅"); `error-state` on feed failure with retry.

---

## H. Rostering & Leave cross-loop (no duplicated work)

The point of the unified calendar is to **show** rostering and leave without **re-owning** them.

- **Shifts layer is read-only and sourced from the rostering feed** (`CalendarController::events`) — the calendar never writes shifts. Coverage gaps render as background highlights; clicking deep-links into the **Rostering planner** for that day. The Rostering planner (`calendar-pane.tsx`) stays the editor.
- **Leave layer is read-only and sourced from `LeaveService::calendarFeed`** — the same feed the Leave hub uses (the recent `LeaveCalendarFeed` work). Clicking deep-links into **`/hr/leave`**. Respect the leave hub's **sensitive-reason redaction** (recent commits extended redaction to rostering/API/email — extend it here too; never leak a reason the leave hub hides).
- **Holidays come from one place** (`HrPublicHoliday` via the aggregator) — retire the per-controller copies (§K).
- **Coordinate with `LEAVE_REDESIGN_PROMPT.md`:** that prompt adds a **leave-specific** Calendar tab (swimlane, coverage-at-risk) on `/hr/leave`. That is the **leave manager's planning** surface; **this** page is the **org overview**. They must share the **same feed + the same `calendar-view.tsx` engine** so they never diverge — not two implementations. If the leave Calendar tab ships first, reuse its feed type here; if this ships first, expose `CalendarLayerFeed` for it to consume. Flag the shared contract in §K so Claude Code keeps them on one source.

> Net: keep the two bespoke **editors**; this page is the single read-only **overlay** + the home for genuine HR events. No third grid, no re-queried data.

---

## I. People milestones layer (new, derived)

A light, opt-in layer that makes the calendar feel alive and useful for managers:
- **Birthdays** & **work anniversaries** (from `HrEmployeeProfile` DOB / start date) — all-day, subtle.
- **Probation end**, **contract end**, **review due** — actionable for managers (click → person profile / start the relevant flow).
- Derived server-side in the aggregator (§K-derive), tenant-scoped, respecting privacy (don't surface DOB year; handle opt-out if Chane wants). Off by default if noisy; toggle in the layer panel.

---

## J. NZ correctness

- `en-NZ`, **Monday-first**, NZD where money appears.
- **NZ public holidays** (national + regional anniversaries + Matariki) shaded; single source via the aggregator; excluded from any day-count.
- Keep everything **tenant-scoped** the way `CalendarController` does — and **fix the feeds that don't** (`ICalController`, the `my-hr-calendar` event query) so nothing leaks across tenants (§K).
- Don't introduce GBP/US locale or non-NZ holiday sets.

---

## K. Backend handoff for Claude Code (append to this as you design)

> Claude design: as you build the UI and discover anything that needs server work, **add it here** with a short spec + migration sketch, so Chane has one clean list to hand to Claude Code. Gate manager actions on the right permission (`hr.calendar.manage` / `calendar.create` / `calendar.manage_recurring`), respect tenant scoping, and **confirm any schema before building**. Seed list from the audit:

**Bugs / scoping to fix:**
1. **Tenant scoping** — `ICalController::feed` (`:37`) and the `my-hr-calendar` `HrCalendarEvent` query don't `forTenant`-scope like `CalendarController`. Add scoping (cross-tenant leak risk).
2. **Dead `holiday` event type** — it's coloured/labelled but not offered in the create dropdown; resolve when moving to the category table (item 4).
3. **`department` free-text** — bind events to `HrDepartment` (the Time-Off filter already does); migrate the column to a nullable FK + backfill.
4. **Remove the duplicate calendars** — delete `TimeOffCalendarController` + route `routes/hr.php:718` + page `time-off.tsx` once the Leave layer covers it; fold `ComplianceCalendarController` data into the aggregator (keep its derivation logic).

**New event capabilities (spec → confirm → implement):**
5. **Event categories table** — `hr_calendar_event_categories` (`id, tenant_id, key, label, icon, color_token, is_system, sort`); seed the existing five (+ `holiday`); FK `hr_calendar_events.category_id` replacing the free `event_type` string (keep a back-compat map). Powers the `TilePicker`.
6. **Recurrence** — add `rrule` (string), `recurrence_until`, `recurrence_parent_id` (self FK), `is_exception` to `hr_calendar_events`; expand server-side (or hydrate via `lib/calendar/recur.ts`); support edit "this / this & future / all". Wire to the existing `calendar.manage_recurring` permission.
7. **Attendees / RSVP** — `hr_calendar_event_attendees` (`event_id, user_id|audience_type, audience_ref, rsvp_status, responded_at`); audience = org/site/team/department/people; endpoints to invite + RSVP. Return attendee summary in the feed.
8. **Reminders / notifications** — `hr_calendar_event_reminders` (`event_id, offset_minutes, channel`); a scheduled job to dispatch (reuse the app's notification stack); ensure it's registered in `routes/console.php`.
9. **Attachments** — `hr_calendar_event_attachments` (or reuse the app's media/attachment pattern); accept uploads via `forceFormData` on store/update; tenant-scoped storage.

**Aggregation & feeds:**
10. **`HrCalendarAggregator` service** — one tenant-scoped entry returning `CalendarLayerFeed[]` per active layer for a date range, **delegating** to `LeaveService::calendarFeed` (leave+holidays), the rostering `CalendarController::events` (shifts/coverage), the compliance derivation, and a milestones deriver — so leave/holiday/shift SQL lives in **one** place. Endpoint: `GET /hr/calendar/feed?from&to&layers`.
11. **People-milestones deriver** — birthdays/anniversaries/probation-end/contract-end/review-due from `HrEmployeeProfile`, tenant-scoped, privacy-aware (no DOB year; opt-out flag if Chane wants).
12. **Shared feed contract with `/hr/leave`** — expose/consume one feed type so the leave Calendar tab and this page never diverge (coordinate with `LEAVE_REDESIGN_PROMPT.md`). Reuse the leave hub's **sensitive-reason redaction** on the leave layer here.
13. **Surface iCal** — no backend change (works); just confirm the token flow and that the feed is tenant-safe (item 1). Optionally add an org-wide (not just personal) subscribe option.
14. **Right-click write routes** — duplicate-event, move-event(date), change-category, manage-attendees, add-reminder all map to the extended `events` update endpoint or thin sub-endpoints; no dead menu items.

> For each item: short spec + migration (if any) and **confirm before building**. Don't silently invent schema.

---

## L. Premium polish & delight

- **Layer legend + colours** are consistent, token-driven, and colour-blind-safe (pair colour with icon/label, not colour alone).
- **Toasts with personality** on every create/move/resize/delete/subscribe (sonner). Tasteful **confetti** on creating the first event of an empty week (`motion-reduce`-safe).
- **Micro-interactions** — `animate-in` on newly created events, hover lift on the agenda cards, smooth drag ghosts, a soft pulse on "today" — all guarded by `motion-reduce:*`.
- **Hover previews** and **keyboard shortcuts** (§G) make it feel like a desktop calendar app.
- **Loading/empty/error:** calendar skeleton while a range loads; friendly `EmptyState` per view; `error-state` with retry on feed failure. Special empty state for a clear week ("Nothing on — enjoy the quiet ☕").
- **Consistency sweep:** all type/layer chips via `StatusBadge`; delete `eventTypeColors`/`leaveTypeColors` hand-maps; replace the single-step event `Dialog`; no raw hex anywhere; no `console`/dead handlers.

---

## Definition of done

- `/hr/calendar` hero is the **golden HR band** (gradient, `--hr-amber`, `HeroStat`s, `QuickAction`s, live alert badges, "Up next"/mini-month right column) — **no clock** — visually on par with `people-hero`/`my-hr-hero`; built on the shared `hero-kit.tsx`.
- The Schedule + Time-Off tabs are **gone**, replaced by **one unified, layered calendar** on the shared `calendar-view.tsx`, with a persistent **layer panel + legend** and **Month / Week / Day / Agenda** views; `time-off.tsx` + `TimeOffCalendarController` **deleted**; Renewals folded in.
- **All four layer groups** work end-to-end: HR events (full CRUD here), Leave, Shifts & coverage, Holidays, Compliance renewals, People milestones — read-only layers **deep-link** into Leave / Rostering / Compliance and are never edited here.
- **No duplication:** leave/shift/holiday data comes from **one `HrCalendarAggregator`** (delegating to `LeaveService` + the rostering feed + compliance); the Rostering planner and Leave swimlane are **untouched**; holidays queried **once**.
- Every event flow is an **Add-Client-grade wizard** (stepper rail + completeness + per-step validation + server-error→step + **Save & add another** + `SuccessPane`) with the **full workflow**: **recurrence (RRULE), reminders, attendees/RSVP, attachments, categories**. Quick-add escalates into it.
- **Drag-to-create / move / resize** (HR events), **right-click menus** on entries, day cells **and** the tab strip (default-tab/pin persisted), hover previews, and keyboard shortcuts — every item wired + toasted; `kbd` hints shown.
- **iCal subscribe** is surfaced (backend already exists); the `holiday` type and free-text `department` bugs are fixed; tenant-scoping holes (`ICalController`, `my-hr-calendar` query) closed.
- **End-to-end:** create/edit/move/resize/delete/duplicate/subscribe all hit real routes; feeds are **accurate**; the org calendar, the roster and the leave hub never disagree; **no dead buttons**.
- `en-NZ` / Monday-first / NZ holidays retained; tenant scoping + `hr.calendar.*` / `calendar.*` gates respected; **no regressions** to `/hr/my` (`MyHrCalendar`), the Rostering planner, the Leave hub, or `calendar-view.tsx`'s other consumers (Finance, client profile, `/my-calendar`).
- Clean `build`, `types`, `lint`; screenshots of every view + every layer toggle + every modal match the reference pages. **§K backend handoff list is filled in** for Chane → Claude Code.
- **Signals to watch:** events created via the new wizard, layer-toggle usage, coverage-gaps surfaced/resolved, renewals actioned from the calendar, duplicate-calendar code removed (LoC), and zero feed-divergence between calendar / roster / leave.
