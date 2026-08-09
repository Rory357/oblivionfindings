# HR "Leave" (Leave & Absence) Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent (Claude design — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/leave` (and `?tab=` for each tab + each modal open) **and** `/hr/my` (the request‑leave modal), and diff against the gold‑standard pages/components before continuing. Start with the audit in §A, then build §B–§L. **Anything you discover that needs backend/data work goes into §K "Backend handoff for Claude Code" — append to it as you go so Chane has one clean hand‑off list when the design is done.**

**Page:** `https://oblivionfindings.com/hr/leave` (manager/HR lens) · `https://oblivionfindings.com/hr/my` → request‑leave modal (employee lens)
**Frontend (admin):** `resources/js/pages/hr/leave/{index,balances,reports,holidays,create,show}.tsx` · **tabs:** `resources/js/components/hr/leave-tabs.tsx`
**Frontend (self‑service):** `resources/js/pages/hr/my/leave.tsx` · `resources/js/components/hr/my-hr-leave-wizard.tsx` · manager dialog `resources/js/components/hr/leave-request-dialog.tsx`
**Backend:** `app/Http/Controllers/Hr/LeaveController.php` · `LeaveReportController.php` · `PublicHolidayController.php` · `Hr/TimeOffCalendarController.php` · `MyHrController.php` (leave/submitLeave/cancelLeave) · routes in `routes/hr.php` (`:85‑87` my, `:297‑318` admin)
**Engine:** `app/Domain/Hr/Services/LeaveService.php` (the core) · `LeaveReportService.php` · `PublicHolidayCalendar.php` · `AlternativeHolidayService.php`
**Models:** `HrLeaveRequest`, `HrLeaveBalance`, `HrLeaveBalanceLedger`, `HrLeaveApprovalChain`, `HrPublicHoliday` (all `app/Domain/Hr/Models/`) · `app/Models/StaffTimeOff.php`
**Cross‑loop consumers:** `app/Services/ShiftStaffEligibilityService.php` (`checkTimeOff` ~`:211‑243`) · `app/Services/Eligibility/Rules/AvailabilityRule.php` (`checkApprovedLeave` ~`:127‑166`) · `app/Http/Controllers/RosteringController.php` · `app/Domain/Rostering/RosterPublishValidator.php` · `app/Domain/Rostering/AutoSchedule/Strategies/EligibilityScoringStrategy.php`
**Gold‑standard modal to clone:** `resources/js/components/clients/add-client-dialog.tsx` (built on `resources/js/components/wizard/primitives.tsx`)

---

## 0. Mission

Turn `/hr/leave` into a **premium, end‑to‑end Leave & Absence surface** that feels identical in quality to our gold‑standard pages — **`/hr/people`**, **`/meds/today`**, **`/my-day`**, **`/health-safety`** — and reuses their exact components and tokens. This is the **organisation‑wide HR + manager view** of leave: the request pipeline, **a real approvals inbox**, balances, a team **calendar**, public holidays and reports. The employee‑scoped request flow lives on **`/hr/my`** (the request‑leave modal) — don't duplicate the wall, but **do** fix that modal (§I) because it feeds this page.

Today `/hr/leave` is functional but dated: **four standalone "old view" pages** masquerading as tabs (Requests / Balances / Holidays / Reports), a **generic hero** (not the golden band), **approvals buried inside the Requests dashboard** (and the "Pending" card only reflects the current paginated page — so it is *not* a reliable inbox), a **legacy `show.tsx` detail page using native `confirm()/alert()`**, **no team calendar on the page**, **no balance‑adjustment UI**, **no export**, **no right‑click menus**, and — most importantly — a **split source of truth** that lets leave data drift (§H). Bring it to parity, give it the **golden HR hero band (no clock, fitted to leave)**, swap every create/edit flow to the **exact Add‑Client wizard pattern**, introduce **real premium tabs with right‑click menus (tabs + rows)**, stand up a **dedicated Approvals inbox**, make **`HrLeaveRequest` the single source of truth** with the roster reading a maintained projection, and fix the self‑service request modal. Result: leave that is **accurate, glanceable, and premium** — not four grey tables.

---

## 1. Non‑negotiables

1. **Introduce a real tab model.** The current four **separate Inertia pages** wired by `leave-tabs.tsx` are the "old views" Chane means — replace them with a proper in‑page **`LeaveTabs`** shell (same tab language as `/hr/people`'s `HrTabs`), reflected in a `?tab=` query param, per‑tab counts as badges. **You propose the tab set during the §A audit and get Chane's sign‑off before building** (recommended starting point in §C). The page gets a **standardised tab system**, not four route hops.
2. **Stand up a dedicated Approvals inbox** (Chane's top complaint: *"I don't see where approvals are being done"*). Approvals must be a **first‑class tabbed surface**, not buried in the Requests table. Routing is **decided** (§G): assigned approver = the employee's **line/site manager**, with a **role‑based site‑scoped fallback** and the existing **SLA auto‑escalation**.
3. **`HrLeaveRequest` is the single source of truth** (Chane's decision). `StaffTimeOff` becomes an **auto‑maintained projection** the roster reads. Roster‑entered *leave* must flow back into HR (record + balance); ad‑hoc *unavailable/training* stays roster‑only. Never let the two stores drift again (§H). **Don't fork the leave engine** — everything routes through `LeaveService`.
4. **Reuse the kit — never hand‑roll a primitive we already have** (§2). Every hero, modal, badge, status colour, context menu, empty state, calendar and toast comes from the shared kit. **No new bespoke widgets, no raw hex** (ESLint blocks it — colours come from design tokens).
5. **Web‑only desktop app.** No phone frames, **no clock** in the hero. Design for mouse + keyboard: hover states, **right‑click menus**, keyboard shortcuts. Responsive down to a small laptop is fine. (A dedicated mobile app comes later — not now.)
6. **Information‑gathering = modals.** Every request/edit/adjust/approve‑with‑notes/leave‑type‑admin flow becomes a **wizard dialog** cloning the Add‑Client shell (§2.2 / §F), **not** an inline form and **not** a full‑page route. Reading a request's detail/history may use a dialog/sheet.
7. **Locale & statute stay NZ.** NZD / `en-NZ` formatting and `en-NZ` dates. Leave types follow the **NZ Holidays Act 2003** and are forward‑compatible with the **Employment Leave Bill** reform (hours‑based accrual from day one; part‑day bereavement & family‑violence). Keep the existing **hours‑based** model — it is *aligned* with the reform direction (§J). Do **not** switch to GBP/US or weeks‑only.
8. **Respect scoping & permissions.** Everything tenant‑scoped via `ResolvesHrTenant`. View gated by `hr.leave.viewAny`, request via `/hr/my`, manage by `hr.leave.manage`, approve by `hr.leave.approve`. **Fix the gate mismatch** (§H‑bugs) and hide manager‑only UI when the user lacks the gate.
9. **Verify each pass:** clean `npm run build`, `npm run types` (no TS errors), `npm run lint`; screenshot the changed surface; confirm it matches the reference page's hero/modal/menu. Don't move on with a broken pass.

---

## A. Audit & benchmark first (do this before building)

Study `/hr/people`, `/meds/today`, `/my-day`, `/health-safety` and **interact** with them — they are the parity bar. Then study the three patterns you must clone:

- **Golden hero** → `resources/js/components/hr/people-hero.tsx` (admin/manager lens, **no clock**, `HERO_STYLE` brand‑gradient band, clickable `HeroStat`s, right‑rail toggle persisted to `localStorage`) and `my-hr-hero.tsx` (the gradient + `HeroStat` + `QuickAction` + te‑reo greeting). **Leave should follow the People hero shape (manager lens, no clock).** If a shared `resources/js/components/hr/hero-kit.tsx` exists (from the Feed/People work), build `LeaveHero` on top of it; otherwise lift `HERO_STYLE`/`HeroStat`/`QuickAction` from `my-hr-hero.tsx` into that shared kit so My HR, People, Feed and Leave share one hero spine (the standardisation win).
- **Gold‑standard modal** → `resources/js/components/clients/add-client-dialog.tsx` (full‑height bespoke shell: **stepper rail + completeness meter + per‑step validation + server‑error→step mapping + Save & add another + `SuccessPane`**), built on `@/components/wizard/primitives`. Markers to match: `Dialog`+`DialogContent` with `[&>button]:hidden`, `flex h-[min(92vh,860px)]`, a `STEPS` array (`{key,label,icon,blurb}`), `validateStep()`, `stepForError()`, completeness meter in the rail foot, "Save & add another", `SuccessPane`, `forceFormData` for uploads. This is the modal to replicate for **every** create/edit flow (§F).
- **Tab strip + right‑click** → `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`: `role="tablist"`, keyboard arrows/Home/End, `onItemContextMenu`, `decorations` per tab, `trailing` slot) wrapped by `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab(defaultTab,{param,syncUrl})`). `leave-tabs.tsx` already uses this — extend it for the new tab set, right‑click menu, and `localStorage` default‑tab/pin.

Then audit `/hr/leave` **and** the `/hr/my` request modal against this **best‑in‑class leave/absence + manager checklist** (mark each **Present / Partial / Missing**, then close gaps in §B–§L). Benchmarks: **BambooHR** (calendar sync Outlook/Google/iCal, who's‑out view), **Rippling** (jurisdiction‑specific leave rules, accrual caps, immutable audit logs), **Deputy** (shift‑based: leave blocks the roster), **Employment Hero / PayHero / Droppah** (NZ payroll+roster+leave live data sharing), **Humanforce / ShiftCare / RosterLab / Tanda** (aged‑care & NDIS rostering: fatigue, coverage/backfill, consecutive‑hours limits), **Vacation Tracker / LeaveWizard / Factorial** (team calendar, accrual, carryover).

**Checklist (fill this in as the first pass and paste back the results):**

- **Hero:** golden brand band • leave stats that matter (pending approvals, on leave today, upcoming this week, absence rate / coverage at risk) • quick actions (Request leave / Review approvals / Open calendar / Export) • live alert badges (awaiting **my** decision, overdue past SLA, roster conflicts) with drill‑down • **no clock**.
- **Tabs:** real `LeaveTabs` (not four route pages) • per‑tab counts • **right‑click tab menu** (set default, open, pin) • `?tab=` deep‑link.
- **Approvals inbox (NEW):** a true cross‑page queue (not "current page only") • segments (Awaiting my decision / Escalated to me / All pending / Recently decided) • single + **bulk** approve/decline • **decline requires a reason** • SLA/overdue badges + escalation visible • **roster‑conflict & insufficient‑balance warnings inline on each request** • right‑click row menu • approve/decline via a review modal (no native `confirm()`).
- **Requests list:** filter by status/type/site/team/date‑range + search • saved/default view • bulk actions • export • per‑row history (ledger + escalation) • real empty/skeleton states.
- **Calendar (NEW on page):** month/week team time‑off grid (who's off, overlaps, public holidays shaded, coverage gaps) • filter by site/team • click a day → drill‑in. Today this lives only at a separate route (`/hr/calendar/time-off` via `TimeOffCalendarController`) — surface it **on** the leave hub.
- **Balances:** per‑person entitlement / taken / pending / remaining • drill into the **ledger** (every reserve/approve/release/cancel/accrual) • **manual adjustment + opening‑balance** action (gated) • accrual visibility • low‑balance flags • export.
- **Leave types:** all **10** types requestable end‑to‑end (annual, sick, bereavement, family_violence, parental, public_holiday, alternative/lieu, unpaid, toil, other) — today self‑service exposes only 4.
- **Public holidays:** NZ holidays (incl. Matariki, regional anniversaries) shading the calendar and **excluded from leave‑day counts**; alternative/lieu‑day accrual visible.
- **Reports:** absenteeism, Bradford Factor, utilisation • date filters • **export (CSV/Excel/PDF)** • drill‑through.
- **End‑to‑end:** every visible action has a wired route + toast; no dead buttons; the request modal and approvals both reflect **accurate** balances and **roster conflicts**; the roster and HR never disagree about who's off.

> **Known gaps the audit already surfaced** (confirm, then fix):
> - **No approvals inbox.** Approve/decline/bulk/escalate/SLA endpoints exist (`LeaveController` + `routes/hr.php:311‑318`) but the UI is scattered across the Requests dashboard (`index.tsx` "Pending Approval" card) and a **legacy `show.tsx`** using native `confirm()/alert()`. The pending card is computed **client‑side from the current paginated page only** — a manager with >20 mixed requests misses pending items not on page 1. **Not a real inbox.**
> - **Split source of truth.** `HrLeaveRequest.time_off_id` → `staff_time_offs.id` is **not a real FK** and there's **no back‑link/observer**. Approval hand‑writes a `StaffTimeOff` row in `LeaveService::approveRequest`; cancel deletes it. But roster‑side writes (`StaffTimeOffController`) **never touch HR** — Direction B is invisible to balances. The roster reads `StaffTimeOff`; the HR calendar reads `HrLeaveRequest`. They can drift (manager edits/deletes a `StaffTimeOff`; an approved request edited out of band; pending leave that doesn't block the roster). `StaffTimeOff` has **no `tenant_id`** (cross‑tenant risk).
> - **Balances ledger + accrual are invisible.** `HrLeaveBalanceLedger` records every movement but **no UI reads it**. `ProcessLeaveBalanceAccrualJob` exists but is **not scheduled** in `routes/console.php` — accrual likely never fires. No manual‑adjustment / opening‑balance path.
> - **No team calendar on the page**, no export anywhere, no date‑range/site/team filter on the requests list, no per‑request roster‑conflict badge (the backend computes `rosterImpact` + `needsEscalation` but the UI never shows the approver "this overlaps shift X").
> - **Half‑day is schema‑only** — a `period` column exists but `LeaveService` never reads it; hours are whole‑business‑days.
> - **Gate mismatch:** route allows only `hr.leave.approve` while controller methods also allow `hr.leave.manage`; `HrLeaveRequestPolicy` is **dead code** (never invoked). Legacy `GET /hr/leave/create` now just redirects.
> - **Self‑service request modal weak** (`my-hr-leave-wizard.tsx`): only **4 of 10** leave types; **no supporting‑doc upload** (the backend + manager dialog support it — matters most for sick/med certs); **wrong hours** (hardcodes `days×8` instead of the contracted‑hours calc in `LeaveService`); **no insufficient‑balance guard**; **no shift‑conflict warning**; half‑day only on the last day.
> - **Stray root files** likely committed by accident: `./HrLeaveRequest.php`, `./HrRosteringContract.php`, `./ComplianceMatrixService.php` (the real ones live under `app/`). `HrRosteringContract` is a **dead interface** (no implementation, no callers). Flag for deletion in §K.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero** — copy the gradient treatment from `resources/js/components/hr/people-hero.tsx` / `my-hr-hero.tsx`: `HERO_STYLE` (the `linear-gradient` over `--primary` + `boxShadow`; re‑themes per tenant), `HeroStat` (label + big tabular value, clickable / `href`), `QuickAction` (icon + label), and the on‑gradient "needs you" chip pattern. Build `LeaveHero` on the shared `hero-kit.tsx` if present (else refactor it out of `my-hr-hero.tsx` first). Generic fallbacks live in `@/components/page` (`PageHero`, `PageHeroStats`, `PageHeroQuickActions`) — fallback only. Tokens: `--primary`, `--primary-foreground`, `--category-hr`, `--hr-amber`.

**2.2 Modals / wizards** — `@/components/wizard/primitives`: `Field`, `FieldErr`, `Segmented`, `ChipMulti`, `SelectInput`, `StepHead`, `SubHead`, `InfoCard`, `TilePicker`, `Ring`, `IconType`, `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_TRACK_CLASS`, `WIZARD_PROGRESS_BAR_CLASS`, `WIZARD_FOOTER_CLASS`. **Reference to clone: `resources/js/components/clients/add-client-dialog.tsx`.** For the employee picker reuse `@/components/hr/people-picker` (`PeoplePicker`, `PersonOption`). Base shadcn in `@/components/ui/`: `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`.

**2.3 Right‑click menus + hover actions** — reuse the existing pattern, don't invent one. Closest references: `@/components/rostering/shift-context-menu` (`ShiftContextMenu`, `ShiftCtxItem`, `ShiftCtxState` — portal‑rendered, viewport‑flipping, Esc/outside‑click close, icon+label+`kbd`+tone) and `@/components/emar/mar/dose-context-menu`. Build a `LeaveContextMenu` in the same mould; wire `onContextMenu={(e) => onCtx(e, row)}`.

**2.4 Cards / states / badges / calendar** — **`@/components/ui/status-badge` (`StatusBadge`) everywhere** for leave status (Pending / Approved / Declined / Cancelled / Escalated) and type chips — do not hand‑map colours. Also `@/components/ui/card`, `avatar`, `badge`, `empty-state` (`EmptyState`, `EmptyList`, `EmptySearch`), `error-state`, `loading-state`, `skeleton-card`, `@/components/ui/laravel-pagination`. **For the calendar, reuse the shared `@/components/calendar/calendar-view.tsx`** (the FullCalendar wrapper whose `CALENDAR_STYLES` were "extracted verbatim so every FullCalendar surface renders identically") — it already powers **`/my-calendar`**, the **HR company calendar `/hr/calendar` (`resources/js/pages/hr/calendar/index.tsx`)** and **Finance** (`resources/js/pages/finance/Calendar.tsx`). Match that look exactly for consistency. **Do NOT** replicate the older hand‑rolled `grid-cols-7` month grid in `resources/js/pages/hr/calendar/time-off.tsx` (with its bespoke `leaveTypeColors` map) — that's the outlier; fold time‑off onto the shared component instead. No new calendar library.

**2.5 Tokens & flourishes** — tokens only in `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`, `--shadow-hero`/`--shadow-float`. Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`. **Toasts: sonner** (`<Toaster>` mounted in `resources/js/app.tsx`) — `toast.success/error` on **every** action. Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards.

---

## B. Hero rethink — the golden band (NO clock, fitted to leave)

**Current:** the Requests page uses a `PageHero`/`KpiCard` arrangement; Balances/Reports/Holidays use plainer `PageHero`+table. Not the golden band, inconsistent across the four pages.

**Do:** build a **`LeaveHero`** (in `resources/js/components/hr/leave/leave-hero.tsx`) using the **same gradient + `HeroStat` + `QuickAction` language as `people-hero.tsx`**, sized to this page's content. **No clock.** Compose:

- **Left column:** title **"Leave & Absence"** + one‑line context ("Plan cover, approve fast and keep {tenant} balances accurate"). Small icon medallion (`CalendarDays` / `Plane`).
- **Glanceable `HeroStat`s** (manager‑relevant, each click‑filters or deep‑links a tab): **Awaiting approval** (`--hr-amber` if >0, → Approvals tab) • **On leave today** (→ Calendar today) • **Upcoming (next 7 days)** (→ Calendar week) • **Absence rate / coverage at risk** (→ Reports). Use tabular figures.
- **`QuickAction`s:** **Request leave** (opens the request wizard, §F‑1 — also usable by managers on behalf of staff) • **Review approvals** (jumps to Approvals tab) • **Open calendar** • **Export** (gated `hr.leave.manage`).
- **Live alert badges** (drill‑down popover, like `people-hero`/`my-hr-hero` chips): "{n} awaiting **your** decision", "{n} overdue past SLA ⏰", "{n} roster conflicts ⚠️". Reuse the chip + `NeedsDot` pattern.
- **Right column (where My HR puts the clock):** since there's **no clock**, fill it with a page‑appropriate cluster — a compact **"This week" coverage mini‑view** (who's off vs. rostered, a small avatar stack of people on leave) **or** an absence/utilisation `Ring`. Persist any toggle to `localStorage` (`hrLeave.heroRight`) like People does. Keeps the band balanced without a clock.

---

## C. Tabs — replace the four pages with a real `LeaveTabs` shell

Replace the four standalone pages with a standardised in‑page tab strip (mould of `HrTabs`), `?tab=` deep‑linked, per‑tab counts as badges, **right‑click menu on the tab strip** (§E). **Propose the final set to Chane in the §A audit and get sign‑off before building.** Recommended starting set:

1. **Overview / Requests** (default) — the request pipeline: filter toolbar (status / type / site / team / date‑range / search), bulk actions, export, real `EmptyState` + `skeleton-card`. Each row: employee avatar, type chip via `StatusBadge`, dates, hours, status, SLA/overdue badge, **roster‑conflict & balance flags**, **right‑click menu**. (Retire the old `index.tsx` dashboard‑as‑queue behaviour — the queue moves to Approvals.)
2. **Approvals** (NEW, §G) — the dedicated inbox. Segments: *Awaiting my decision · Escalated to me · All pending · Recently decided*. Single + bulk approve/decline (decline needs a reason), SLA/escalation visible, inline conflict/balance warnings, approve/decline via review modal. This is the headline new surface.
3. **Calendar** (NEW on page, §D) — month/week team time‑off grid; public holidays shaded; coverage gaps highlighted; filter by site/team; click‑through to a day/person. Surface the existing `TimeOffCalendarController` data **here**, not at a separate route.
4. **Balances** — per‑person entitlement / taken / pending / remaining with **ledger drill‑in** and a gated **Adjust / Set opening balance** action (§F‑3); low‑balance flags; accrual visibility; export.
5. **Reports** — absenteeism, Bradford Factor, utilisation (keep `LeaveReportService`); add date filters + **export** + drill‑through.
6. **Public holidays** — keep the CRUD, but **move it to a settings/secondary position** (a sub‑tab under a "⋯ More" affordance or the page's settings) rather than a primary tab, since it's admin config not daily flow. Confirm placement with Chane.

> Per tab: shared list/card + `StatusBadge` chips; real **empty state** (icon + line + CTA) and **skeleton**; every create/edit flow is a **modal** (§F); every row has a **right‑click menu** (§E) + hover actions; **toast** every result.

---

## D. Calendar tab — the team time‑off view (bring it onto the page)

A team calendar is table stakes for leave (BambooHR/Deputy/Vacation Tracker all lead with it). The data + controller already exist (`Hr/TimeOffCalendarController`, with dept/team/site filters and public‑holiday overlay) — it's just not on the leave hub.

- **Month + week views** of approved (and, distinctly styled, **pending**) leave across the team, swimlaned by person or grouped by site/team.
- **Public holidays shaded** (from `HrPublicHoliday` / `PublicHolidayCalendar`), including regional anniversaries and Matariki.
- **Coverage signal:** highlight days where too many of a site/team are off (a simple count vs. headcount threshold) so managers see cover risk — echoing aged‑care "coverage/backfill" tooling.
- **Filters:** site / team / leave type. **Click a day or a person** → drill into the requests/approvals for that slice.
- **Use the shared `@/components/calendar/calendar-view.tsx` (FullCalendar)** so the tab is visually identical to `/my-calendar`, `/hr/calendar` (company) and Finance — month (`dayGridMonth`) default with **week** (`timeGridWeek`) and **list/agenda** (`listWeek`) toggles, leave rendered as the standard colourful pastel event blocks, today pill in `--primary`, all design‑token‑themed (no raw hex, no new library). This is the consistency Chane asked for; the existing `hr/calendar/time-off.tsx` hand‑rolled grid should be retired onto this same component. Feed it from the single source of truth (§H): approved leave = `HrLeaveRequest` (projected to `StaffTimeOff` for the roster), pending = `HrLeaveRequest` pending (styled distinctly).

---

## E. Right‑click everywhere (rows **and** tabs)

Chane explicitly wants right‑click options "under tabs etc." Build a `LeaveContextMenu` (mould of `ShiftContextMenu`) and wire `onContextMenu` on:

- **Request / approval rows:** **Approve** · **Decline…** (opens review modal, reason required) · **Open detail** · **+24h SLA** · **Escalate now** · **View balance** · **View on calendar** · **Copy link** · (manager) **Edit dates/type** · **Cancel request** (confirm via `alert-dialog`). Gate destructive/manager items; show `kbd` hints.
- **Calendar entries:** **Open request** · **Approve/Decline** (if pending) · **View profile** · **View coverage that day**.
- **Balance rows:** **Adjust balance…** · **Set opening balance…** · **View ledger** · **Request leave for…**.
- **The tab strip itself:** right‑click a tab → **Set as default view**, **Open**, **Pin**. Persist "default tab" + pins to `localStorage` (`hrLeave.defaultTab`, allowed) so it survives reloads; render a `decorations` star/pin on the chosen tab.

Every menu action fires a toast and, where it writes, hits a real route (§K). No dead items.

---

## F. Modals = exact Add‑Client wizard pattern

Every create/edit/adjust flow clones `resources/js/components/clients/add-client-dialog.tsx`: same **full‑height bespoke shell** (`Dialog` + `DialogContent [&>button]:hidden`, `flex h-[min(92vh,860px)]`, left **stepper rail** `w-[248px] bg-sidebar` with per‑step icons + blurbs + check‑on‑complete, a **completeness meter** at the rail foot, header "Step X of N", **top progress bar**, scroll‑contained body, footer with Back / Cancel / **Save & add another** / primary), same **engine** (Inertia `useForm`, client‑side `validateStep`, `stepForError` to jump to the offending step, `SuccessPane`, `resetAll()` for Save & add another), from `@/components/wizard/primitives`.

1. **Request leave** — **rebuild `my-hr-leave-wizard.tsx` onto the Add‑Client shell** (today it uses a lighter pattern and is the employee's main flow). Steps: **Type** (`TilePicker` — **all 10 leave types**, with helper copy per type incl. tangihanga/bereavement and family‑violence) → **Dates** (start/end, **per‑day or half‑day** handling, **public‑holiday‑aware day count**, live "X working days · Yh" using the **contracted‑hours** calc, not ×8) → **Details** (reason, **supporting‑doc upload** via `forceFormData` — show especially for sick/med‑cert) → **Review & submit** (show **current balance**, **projected remaining**, an **insufficient‑balance warning**, and a **roster‑conflict warning** if the dates overlap a rostered shift). Managers can open the same wizard **on behalf of** a staff member (recipient picker via `PeoplePicker`). Posts to the self‑service route (`/hr/my/leave`) or the manage route (`/hr/leave`) as appropriate. Confetti + sonner on success.
2. **Approve / Decline (review modal)** — replace the native `confirm()/alert()` on `show.tsx`. A focused dialog: request summary, **balance impact**, **roster conflict** (if any), notes field (**required on decline**), Approve / Decline. Supports the **bulk** path too (one modal, N requests, shared note). Posts to the existing approve/decline/bulk endpoints.
3. **Adjust balance / Set opening balance** (gated `hr.leave.manage`, NEW backend §K) — Steps: **Person & type** → **Adjustment** (credit/debit hours or set opening balance, reason) → **Review**. Writes a manual `HrLeaveBalanceLedger` entry so the audit trail stays complete.
4. **Leave‑type / policy admin** (optional, gated) — if Chane wants entitlements/accrual editable in‑app rather than `config/hr.php`, a small wizard over a settings store. **Confirm before building** (could stay config‑driven for now).

> Wire each modal from the page like today (`open` state + `<LeaveRequestDialog … />`), opened from the hero `QuickAction`s, tab CTAs and row/context menus.

---

## G. The Approvals inbox — the headline new surface

This is what resolves *"I don't see where approvals are being done."* Build a real inbox (not the current page‑bound "Pending" card).

- **Segments** (tabs‑within‑tab or a `Segmented`): **Awaiting my decision · Escalated to me · All pending · Recently decided**. Counts as badges.
- **A true cross‑page queue** — server‑driven, ordered by SLA urgency (overdue first, then due‑within‑24h, then oldest). **Not** a client filter of the current page. Reuse `LeaveService::approvalSlaSummary` + `pendingAging` for the urgency data.
- **Each request card/row shows, inline:** employee + avatar, type chip, dates/hours, **balance impact** (remaining → projected), **roster conflict** badge if the dates overlap a rostered shift (surface the backend's `rosterImpact`/conflict — today it's computed but hidden), SLA/overdue/escalation state, and **Approve / Decline / +24h / Escalate** actions.
- **Single + bulk** approve/decline through the review modal (§F‑2); **decline requires a reason**. Escalation history visible (who it's escalated to, level).
- **Routing (decided):** each request's **assigned approver = the employee's line/site manager** when that relationship exists, surfaced under *Awaiting my decision*; where no manager is set, fall back to a **role‑based shared queue scoped to the approver's site(s)**; **auto‑escalation** to a backup/HR after the SLA window via the existing `EscalateLeaveApprovalsJob` + `HrLeaveApprovalChain`. This gives accountable, auditable approvals (important for a care provider) while staying workable for small teams. The tabbed inbox is the **surface**; this is the **routing** underneath it.
- **Fix the gate** so `hr.leave.manage` users who are meant to approve aren't blocked at the route while the controller would allow them (§K‑bugs).

---

## H. Source of truth & the rostering cross‑loop (Chane's decision: HR Leave is the truth)

Make **`HrLeaveRequest` (+ `HrLeaveBalance` + `HrLeaveBalanceLedger`) the single system of record.** `StaffTimeOff` becomes a **read‑projection** the roster consumes. The cross‑loop must be reliable in **both** directions and never silently drift.

**Direction A (HR → roster) — already mostly works, harden it:** approval writes a `StaffTimeOff` row in `LeaveService::approveRequest`; cancel deletes it. Extend `LeaveService` (or an `HrLeaveRequest` observer) to **also re‑sync on edit** (date/type changes to an approved request must update the projection) and to set a **real link**. The roster already blocks via `ShiftStaffEligibilityService::checkTimeOff` (`StaffTimeOff`) **and** `AvailabilityRule::checkApprovedLeave` (`HrLeaveRequest` approved); auto‑schedule (`EligibilityScoringStrategy`) and publish (`RosterPublishValidator`) inherit those blocks. Once the projection is guaranteed, **collapse the duplicate read** to one rule to avoid contradictory sub‑verdicts.

**Direction B (roster → HR) — the gap to close:** `StaffTimeOffController::store` currently writes a bare `StaffTimeOff` with **no** HR record and **no** balance impact. Per Chane's decision:
- Roster‑entered **leave‑type** time‑off must **create a matching `HrLeaveRequest`** (auto‑approved, attributed to the manager) **and deduct the balance** via `LeaveService` — so HR balances and the calendar stay correct.
- Roster‑entered **ad‑hoc `unavailable` / `training`** stays roster‑only (no balance impact) — it's not leave.
- **Guard `StaffTimeOffController::destroy`/`store`:** refuse to delete a projection row that belongs to an approved `HrLeaveRequest` (send the user to the HR cancel flow); restrict the roster‑side free‑form `type` to non‑leave unavailability, or route leave through the request path.

**Pending leave should also warn the roster:** between submit and approve there's no projection, so someone can be rostered over their pending leave. Surface pending `HrLeaveRequest` as a **soft warning** on the roster grid and in the request/approval UI (the roster index already has a pending‑leave overlay to build on).

**Public holidays in the loop (§J):** count leave **excluding** public holidays (don't charge a stat day to the balance), and shade stat days on the calendar/roster. Today PH only affects pay, not balances or the roster grid.

> The detailed schema/back‑end changes for all of the above go in **§K** — write the migration specs there and **confirm before building**.

---

## I. The self‑service request modal (`/hr/my`) — fix it (it feeds this page)

`resources/js/components/hr/my-hr-leave-wizard.tsx` is the employee's main request flow and is the weakest link. Rebuild it on the Add‑Client shell (§F‑1) and fix:

- **All 10 leave types** (today only annual/sick/bereavement/parental) with clear per‑type helper copy.
- **Supporting‑doc upload** (today missing here, though the backend + manager dialog support it) — surface it, especially for sick/med‑cert and family‑violence (handle the latter sensitively/privately).
- **Correct hours** — use the backend contracted‑hours calc, not `days×8`; support **half‑day / part‑day** (NZ reform allows part‑day bereavement & family violence).
- **Public‑holiday‑aware** day count (don't count a stat day inside the range).
- **Insufficient‑balance guard** — show current balance + projected remaining and warn (don't hard‑block unless Chane wants to; the engine flags it as `needsEscalation`).
- **Shift‑conflict warning** — the data exists server‑side (`hasRosterConflict`); tell the user "you're rostered on shift X during these dates" before they submit.
- Show the **assigned approver** and the **expected‑decision SLA**, not just "Your manager".

---

## J. NZ statute — Holidays Act 2003 → Employment Leave Bill (forward‑compatible)

Keep NZ correctness and design for the reform now in select committee (introduced March 2026; ~24‑month transition):

- **Hours‑based model is right.** The reform moves annual + sick leave to **hours‑based accrual from day one** (annual ≈ 0.0769 h per contracted hour; sick ≈ 0.0385 h, carryover cap 160h). Our engine is already hours‑based — **keep it**, and make sure the **ledger + accrual** can express per‑hour accrual (not only monthly twelfths). **Schedule `ProcessLeaveBalanceAccrualJob`** (it isn't, today) so balances actually accrue.
- **Day‑one entitlements & part‑days:** bereavement & family‑violence apply from day one and allow **part‑days** — make these requestable as part‑days end‑to‑end.
- **Alternative (lieu) days:** accrue an alt day per public holiday worked (the `AlternativeHolidayService` exists on the pay side) — surface accrued alt days in Balances.
- **Records:** the reform tightens leave records / pay statements — our immutable **ledger** is an asset; surface it (audit trail) and keep it complete (manual adjustments write ledger rows).
- Don't over‑build to an unpassed bill — just don't design anything that blocks it. Flag any statute‑sensitive default (entitlement hours, carryover caps) as config in §K.

---

## K. Backend handoff for Claude Code (append to this as you design)

> Claude design: as you build the UI and discover anything that needs server work, **add it here** with a short spec + migration sketch, so Chane has one clean list to hand to Claude Code. Gate manager actions on the right permission, respect `ResolvesHrTenant`, and **confirm any schema before building**. Seed list from the audit:

**Bugs / scoping to fix:**
1. **Approval gate mismatch** — `routes/hr.php:311` allows only `hr.leave.approve`, but `LeaveController@approve/decline` also accept `hr.leave.manage`. Align the route group (and either invoke `HrLeaveRequestPolicy` or delete it — it's currently dead code).
2. **Schedule accrual** — register `ProcessLeaveBalanceAccrualJob` in `routes/console.php` (only `EscalateLeaveApprovalsJob` is scheduled). Without it, accrual never runs.
3. **Delete stray root files** — `./HrLeaveRequest.php`, `./HrRosteringContract.php`, `./ComplianceMatrixService.php` (duplicates of the real `app/...` classes). `HrRosteringContract` interface has **no implementation/callers** — remove or implement deliberately.
4. **`StaffTimeOff` tenancy** — add `tenant_id` (+ backfill + scope) so the roster's time‑off reads are tenant‑isolated like HR.

**Source‑of‑truth reconciliation (Chane: HR Leave is the truth):**
5. **Real link + projection** — add `staff_time_offs.hr_leave_request_id` (nullable FK) + convert `hr_leave_requests.time_off_id` to a real constrained FK (`nullOnDelete`); backfill from existing `time_off_id`.
6. **Two‑way sync** — `HrLeaveRequest` observer (or centralise in `LeaveService`) to create/update/delete the `StaffTimeOff` projection on approve/edit/cancel. Guard `StaffTimeOffController` so roster‑entered **leave** creates an `HrLeaveRequest` + balance movement (auto‑approved, manager‑attributed) while **unavailable/training** stays roster‑only; block deleting a projection that belongs to an approved request.
7. **Collapse duplicate eligibility read** — once the projection is guaranteed, keep a single leave check (`checkTimeOff`) and retire the redundant `AvailabilityRule::checkApprovedLeave` query (or vice‑versa) to avoid contradictory verdicts.
8. **Pending‑leave roster warning** — surface pending `HrLeaveRequest` as a soft (non‑blocking) overlay on the roster grid + conflict counter.

**Missing endpoints to build (spec → confirm → implement):**
9. **Approvals inbox query** — a server‑driven, cross‑page, SLA‑ordered pending queue with segments (awaiting‑me / escalated‑to‑me / all‑pending / recently‑decided), scoped by site/manager. Back the routing decided in §G.
10. **Per‑request conflict + balance payload** — return `rosterConflict` (overlapping shift detail) and `balanceImpact` (remaining → projected) per request so the inbox/cards can warn inline.
11. **Balance adjustment / opening balance** — `POST /hr/leave/balances/adjust` (gated `hr.leave.manage`) writing a manual `HrLeaveBalanceLedger` row; expose the ledger read for the drill‑in.
12. **Export** — CSV/Excel/PDF for requests, balances and reports (use the `xlsx`/`pdf` skills' server equivalents or a streamed export controller).
13. **Calendar feed on the hub** — feed the Calendar tab from `TimeOffCalendarController` data; optionally a subscribable `.ics`.
14. **Half‑day / part‑day end‑to‑end** — wire the existing `period` column through `LeaveService` (hours calc, projection, calendar) for part‑day bereavement/family‑violence.
15. **Public‑holiday‑aware day count** — `LeaveService::calculateRequestedHours` should skip `HrPublicHoliday` days; shade stat days on calendar/roster.
16. **Self‑service parity** — accept supporting‑doc upload on `/hr/my/leave`, use the contracted‑hours calc, expand to all 10 types, return balance + conflict to the modal.
17. **(Confirm) Approval‑chain admin** — UI/endpoints to manage `HrLeaveApprovalChain` if Chane wants configurable multi‑level routing (else role + escalation fallback stays).

> For each item: short spec + migration (if any) and **confirm before building**. Don't silently invent schema.

---

## L. Premium polish & delight

- **Avatars** with real photos (fall back to coloured initials — reuse the existing `getAvatarColor` helper).
- **Toasts with personality** on every request/approve/decline/adjust/export (sonner). Tasteful **confetti** on a submitted request / cleared approval queue (`motion-reduce`‑safe).
- **Micro‑interactions** — approve/decline row transitions, `animate-in` on new requests, hover lift on cards, progress `Ring` in the hero — all guarded by `motion-reduce:*`.
- **Keyboard:** `/` focuses search, `r` opens Request leave, `a` approves the focused request, `Esc` closes menus/dialogs; arrow/Enter on rows.
- **Loading/empty/error:** every tab gets a `skeleton-card` while loading and a friendly `EmptyState` (icon + line + primary CTA) — no bare "No requests." line. Special empty state for a **cleared approvals inbox** ("You're all caught up ✅").
- **Consistency sweep:** all status/type chips via `StatusBadge`; delete any hand‑rolled colour maps; replace native `confirm()/alert()` on `show.tsx`; no raw hex anywhere.

---

## Definition of done

- `/hr/leave` hero is the **golden HR band** (gradient, `HeroStat`s, `QuickAction`s, live alert badges, coverage/absence right‑column) — **no clock** — visually on par with `people-hero`; built on the shared `hero-kit.tsx`.
- The four standalone pages are gone, replaced by a real **`LeaveTabs`** shell (`?tab=`, per‑tab counts) with the Chane‑approved set (recommended: **Overview · Approvals · Calendar · Balances · Reports**, Public Holidays in settings).
- A **dedicated Approvals inbox** exists: cross‑page SLA‑ordered queue, segments, single + bulk approve/decline (reason on decline), inline **roster‑conflict + balance** warnings, escalation visible — no more "current‑page‑only" pseudo‑queue, no native `confirm()`.
- A **Calendar** tab shows team time‑off with public holidays and coverage signals, on the page.
- **Balances** drill into the ledger and support **manual adjustment / opening balance**; accrual is scheduled and visible.
- Every create/edit/approve/adjust flow is an **Add‑Client‑grade wizard** (stepper rail + completeness + per‑step validation + server‑error→step + **Save & add another** + `SuccessPane`); the self‑service request modal is rebuilt with **all 10 types, doc upload, correct hours, balance + conflict warnings**.
- **Right‑click menus** on rows **and** the tab strip; default‑tab/pin persisted; every item wired + toasted; `kbd` hints shown.
- **Source of truth = `HrLeaveRequest`**: the roster reads a maintained projection; roster‑entered leave flows back to HR + balances; the two stores can no longer drift; pending leave warns the roster.
- **End‑to‑end:** approvals, calendar, balances, adjustments, export, request, cancel all hit real routes; balances and conflicts are **accurate**; **no dead buttons**.
- NZD / `en-NZ` retained; Holidays‑Act / Employment‑Leave‑Bill‑compatible (hours‑based, part‑day bereavement/FV, alt days); `ResolvesHrTenant` scoping and `hr.leave.*` gates respected; **no regressions** to `/hr/my`, the roster, or `LeaveService`.
- Clean `build`, `types`, `lint`; screenshots of each tab + each modal match the reference pages. **§K backend handoff list is filled in** for Chane → Claude Code.
- **Signals to watch:** time‑to‑approve, % approved within SLA, overdue queue size, balance‑accuracy (HR vs roster agreement), self‑service request completion rate, roster conflicts caught before publish.

**Build order:** §A audit + propose tab set (paste results, get sign‑off) → **`hero-kit.tsx` + `LeaveHero`** (golden band, no clock) → **`LeaveTabs`** shell (retire the four pages) → **Approvals inbox** (§G) with review modal (kill native `confirm()`) → **Calendar** tab (§D) → **Requests** list polish (filters/export/right‑click) → **Balances** ledger + adjust (§F‑3) → rebuild **self‑service request modal** (§I) → **source‑of‑truth sync + cross‑loop** (§H/§K, confirm schema) → delight pass (§L). Verify each pass against the reference pages, and keep appending discovered backend work to **§K**.
