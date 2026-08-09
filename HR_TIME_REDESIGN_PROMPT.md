# HR "Timekeeping" (`/hr/time`) Redesign — PROMPT

> For **Claude Design** (UI build) with a **backend handoff list for Claude Code** (§K).
> Page: `resources/js/pages/hr/time/index.tsx` · Controller: `app/Http/Controllers/Hr/TimeTrackingController.php`.
> Read this whole prompt before touching code. Where it says **confirm with Chane**, stop and ask — don't silently invent schema or scope.

---

## 0. Mission

Turn `/hr/time` from a single 1,600-line dashboard with two thin dialogs into a **premium manager/admin time-oversight surface** that is visually and behaviourally on par with `/hr/people`, `/hr/leave`, `/meds/today` and `/my-day`. Same golden HR hero spine, the same standardised tab shell, the **exact Add-Client wizard pattern** for every create/edit/amend flow, **right-click menus on rows and tabs**, and **no dead buttons** — every visible action hits a real route and toasts.

**Positioning (Chane's decision): this page is now a pure oversight surface — there is NO personal clock-in/out here.** Individuals clock on **`/hr/my`** and **`/my-day`** (the unified `AttendanceService` clock). `/hr/time` is where managers and admins **review** team time, **add/correct/amend** entries (incl. on behalf), watch **exceptions** (missed clock-outs, break-compliance fails, overtime, public-holiday/sleepover loadings), and **deep-link** into the Operations timesheet/payroll flow that already owns approval and pay.

---

## 1. Non-negotiables

- **Web-only, desktop-first.** No phone frames / mobile-app chrome. (Oblivion is a desktop web app; a native mobile app comes later.)
- **NZ-only.** NZD, `en-NZ`, NZ statute (Holidays Act 2003 + the Employment Leave Bill direction), KiwiSaver, NZ public holidays incl. Matariki & regional anniversaries. Never "fix" to GBP/US.
- **Reuse the shared kit (§2). Do not invent new heroes, modal shells, tab strips, badges or context menus.** Standardisation across HR is the point.
- **One clock engine.** `AttendanceService` (writing `HrAttendanceSession`) is the canonical clock. **Do not fork a second clock.** All HR time writes go through `TimeTrackingService`.
- **Don't rebuild what rostering/operations owns.** Timesheet submit/approve/reject/return/bulk and payroll are owned by the Operations `Timesheet` flow — **link, don't rebuild** (§H).
- **Tenancy + permissions.** Respect `ResolvesHrTenant` and the `timesheets.*` gates (`viewAny`, `approve`, `manageAny`) on every action.
- **No regressions** to `/hr/my`, `/my-day`, the roster, `AttendanceService`, `TimeTrackingService`, `DraftTimesheetService`, or the Operations timesheet/payroll flow.
- **Clean `build`, `types`, `lint`.** Tokens only (no raw hex). Sonner toast on every write. `motion-reduce` guards on all animation.

---

## A. Audit & benchmark first (do this before building)

Study `/hr/people`, `/hr/leave`, `/meds/today`, `/my-day` and **interact** with them — they are the parity bar. Then study the three patterns you must clone:

- **Golden hero** → `resources/js/components/hr/my-hr-hero.tsx` (the `HERO_STYLE` brand-gradient band with the amber accent `--hr-amber: oklch(0.86 0.13 90)`, clickable `HeroStat`s, `QuickAction`s, on-gradient "needs you" chips) and `resources/js/components/hr/people-hero.tsx` (the **manager/admin lens, no clock**, right-rail toggle persisted to `localStorage`). **Timekeeping should follow the People hero shape (manager lens, no clock).** If a shared `resources/js/components/hr/hero-kit.tsx` exists (from the Feed/People/Leave work), build `TimeHero` on it; otherwise lift `HERO_STYLE` / `HeroStat` / `QuickAction` out of `my-hr-hero.tsx` into that shared kit so My HR, People, Feed, Leave **and** Timekeeping share one hero spine.
- **Gold-standard modal** → `resources/js/components/clients/add-client-dialog.tsx`, built on `@/components/wizard/shell` (`WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`) + `@/components/wizard/primitives`. Markers to match: full-height bespoke shell (`Dialog`+`DialogContent` with `[&>button]:hidden`, `flex h-[min(92vh,860px)]`), a left **stepper rail** (`w-[248px] bg-sidebar`, per-step icon + blurb + check-on-complete), a **completeness meter** at the rail foot, "Step X of N" header, **top progress bar**, scroll-contained body, footer with Back / Cancel / **Save & add another** / primary, plus the **engine** (`validateStep()`, `stepForError()` server-error→step jump, `SuccessPane`, `resetAll()`). The **warm reference already built on this shell** is `resources/js/components/hr/leave-request-dialog.tsx` ("New request") — copy its premium idioms (accent tiles via `color-mix`, the live duration/summary card, the review hero-summary card with gradient wash + Edit jump-back, footer CTA glow). This is the modal to replicate for **every** create/edit/amend flow (§F).
- **Tab strip + right-click** → `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`: `role="tablist"`, arrow/Home/End keys, `onItemContextMenu`, per-tab `decorations`, `trailing` slot) wrapped by `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab(defaultTab,{param,syncUrl})`). For the right-click menu, the canonical idiom is `resources/js/components/checklists/context-menu.tsx` (`FloatingMenu` + a `use…ContextMenu()` hook — portal-rendered, viewport-flipping, Esc/outside-click/scroll close, arrow-key roving). Live examples: `resources/js/components/hr/leave-context-menu.tsx` (used at `resources/js/pages/hr/leave/index.tsx:212` and `:587`) and `resources/js/components/rostering/shift-context-menu.tsx`.

Then audit `/hr/time` against this **best-in-class time & attendance + workforce-oversight checklist** (mark each **Present / Partial / Missing**, paste the results back, then close gaps in §B–§L). Benchmarks: **Deputy / When I Work / Homebase** (live "who's on now", auto break rules, missed-clock-out exceptions), **Tanda / Humanforce / Quinyx / RosterLab** (award/agreement interpretation, fatigue & consecutive-hours, variance scheduled-vs-actual), **Employment Hero / PayHero / Droppah / Smartly** (NZ payroll+roster+leave live data sharing, timesheet→pay), **ShiftCare / Birdie / CarePlanner** (aged-care & NDIS: sleepovers, travel/mileage, client-linked shifts), **Clockify / Harvest / Hubstaff** (manual entry ergonomics, audit trail, export).

**Checklist (fill in as the first pass and paste back):**

- **Hero:** golden brand band • oversight stats that matter (clocked-in now, team hours this week, awaiting approval, exceptions) • quick actions (Add entry / Clock on behalf / Review shift timesheets / Export) • live alert chips (missed clock-outs, break-compliance fails, overtime over 40h, sleepovers/PH today) with drill-down • **no clock**.
- **Tabs:** real `TimeTabs` (not a hand-rolled `<Tabs>`) • per-tab counts • **right-click tab menu** (set default, open, pin) • `?tab=` deep-link.
- **Dashboard / live oversight:** "who's clocked in right now" (live, with elapsed time) • exceptions board (open >12h / missed clock-out, break-compliance fail, >40h overtime, unlinked-to-shift) • team weekly hours • recent activity • **no personal clock card**.
- **Time Entries register:** scope (mine / team / all) • filters status / pay-type / **site / team / date-range** + search • **Add entry** (manual) + **Clock on behalf** • per-row **amendment history** drill-in • right-click menu + hover actions • real empty/skeleton/error states • export.
- **Amendments:** the per-field audit trail (`HrTimeEntryAmendment`) is **visible** (who / field / old→new / reason / when), not just a static "(amended)" marker.
- **Shift Timesheets (read-only):** premium restyle of the Operations `Timesheet` window, honest framing, deep-link to `/operations/timesheets`; **approval stays in Operations**.
- **Exceptions & compliance:** NZ break rule (≥4h→30m, ≥2h→10m) surfaced as real signal not noise; overtime; sleepover/on-call/public-holiday loadings; mileage totals.
- **End-to-end:** every visible action has a wired route + toast; no dead buttons; manual entry, clock-on-behalf, edit/amend, amendment history, export all work; the KPIs and the Time Entries list **agree** (see §I).

> **Known gaps the audit already surfaced** (confirmed against code — verify, then fix):
> - **No manual "Add time entry" UI.** `TimeTrackingController@store` → `TimeTrackingService::createManualEntry()` and route `hr.time.entries.store` **exist**, but the page has **no button/modal**. (Backend is also under-validated — see §K-2.)
> - **Amendment audit trail is invisible.** `entryAmendments()` returns a rich JSON history and route `hr.time.entries.amendments` exists, but the UI only renders a static "(amended)" word (`index.tsx:914`). No history drawer/tooltip.
> - **Clock-on-behalf silently drops data.** The dialog collects a **required** `reason`, but `ClockOnBehalfRequest` has **no `reason` rule** and `TimeTrackingService::clockOnBehalf()` **never persists it** — the reason is discarded. The dialog also omits `is_sleepover` / `is_on_call` / `is_public_holiday` / `mileage_km` / `shift_id` / `client_id`, which the service would accept.
> - **`is_public_holiday` can't be amended.** `UpdateTimeEntryRequest` + the service's `editableFields` both omit it, so the PH loading flag is unsettable after creation.
> - **`cost_centre` / `project_code`** are fillable and supported on create but never editable or displayed.
> - **Break-compliance false-fails.** The legacy personal clock-out posted an **empty body** (no break captured), so any 2h+ shift recorded `break_compliance_met = false` and rendered a warning the user couldn't prevent. (In the new oversight framing the personal clock-out leaves this page — but breaks must be captured on the `/my-day` & `/hr/my` clock-out paths; §I/§K.)
> - **The page under-reports hours.** `/my-day` and `AttendanceController` clock-outs create the session + Operations `Timesheet` but **no `HrTimeEntry`** — so the "Time Entries" tab and every `HrTimeEntry`-based KPI (Hours This Week / Active / Overtime / Avg) **exclude staff who clock via `/my-day`**, while "Pending Timesheets" (from the Operations `Timesheet`) includes them — an internal inconsistency on one dashboard. Meanwhile `/hr/my` (`MyHrController`) holds a **forked copy** of the `HrTimeEntry` write + break-compliance math instead of calling `TimeTrackingService`. **Chane has approved bundling both fixes** — specs in §I/§K.
> - **Stale architecture docs will mislead a rebuild.** `docs/architecture/shifts-module-map.md` and `docs/architecture/shifts-frontend-routes.md` describe an `HrTimesheet` period-aggregate model, an `HrTimesheetApprovalService`, and `hr.time.timesheets.{submit,approve,reject,return,bulk-*}` routes that **do not exist**. The "Period Timesheets/Approvals" data is per-shift Operations `Timesheet` rows bucketed Mon–Sun **for display only**. Don't build to the stale contract (§H + §K-13).

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero** — copy the gradient treatment from `resources/js/components/hr/my-hr-hero.tsx` / `people-hero.tsx`: `HERO_STYLE` (the `linear-gradient` over `--primary` + `boxShadow`; re-themes per tenant), the amber accent token `--hr-amber: oklch(0.86 0.13 90)` for "needs you" / exception emphasis, `HeroStat` (label + big tabular value, clickable / `href`), `QuickAction` (icon + label), and the on-gradient chip + `NeedsDot` pattern. Build `TimeHero` on the shared `hero-kit.tsx` if present (else refactor it out of `my-hr-hero.tsx` first — that's the standardisation win). Generic fallback only: `@/components/page` (`PageHero`, `PageHeroStats`, `PageHeroQuickActions`). Tokens: `--primary`, `--primary-foreground`, `--category-hr`, `--hr-amber`.

**2.2 Modals / wizards** — `@/components/wizard/shell` (`WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`) + `@/components/wizard/primitives` (`Field`, `FieldErr`, `Segmented`, `ChipMulti`, `SelectInput`, `StepHead`, `SubHead`, `InfoCard`, `TilePicker`, `Ring`, `IconType`, `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_TRACK_CLASS`, `WIZARD_PROGRESS_BAR_CLASS`, `WIZARD_FOOTER_CLASS`). HR re-exports these via `@/components/hr/wizard`. **References to clone: `resources/js/components/clients/add-client-dialog.tsx` (the contract) and `resources/js/components/hr/leave-request-dialog.tsx` (the warm HR application of it).** For the staff picker reuse `@/components/hr/people-picker` (`PeoplePicker`, `PersonOption`). Base shadcn in `@/components/ui/`: `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`.

**2.3 Right-click menus + hover actions** — reuse the existing idiom, don't invent one. Canonical: `resources/js/components/checklists/context-menu.tsx` (`FloatingMenu` + `use…ContextMenu()`); closest HR/rostering examples `resources/js/components/hr/leave-context-menu.tsx` and `resources/js/components/rostering/shift-context-menu.tsx` (portal-rendered, viewport-flipping, Esc/outside-click close, icon + label + `kbd` + tone). Build a `TimeContextMenu` (rows) and reuse the tab-strip's `onItemContextMenu`. **There is no shared `components/ui/context-menu.tsx` today** — if you find yourself copying the menu a third time, **promote `checklists/context-menu.tsx`'s `FloatingMenu` + hook into `@/components/ui/context-menu.tsx`** and have the domain menus pass only their `items` arrays (note it in §K for Chane's sign-off).

**2.4 Cards / states / badges** — **`@/components/ui/status-badge` (`StatusBadge`) everywhere** for entry status (Active / Submitted / Approved / Rejected) and pay-type chips — do not hand-map colours (the current `statusConfig`/`payTypeConfig` maps in `index.tsx` should be retired onto `StatusBadge`). Also `@/components/ui/card`, `avatar`, `badge`, `empty-state` (`EmptyState`, `EmptyList`, `EmptySearch`), `error-state`, `loading-state`, `skeleton-card`, `@/components/ui/laravel-pagination`, and the `KpiCard` already used (`@/components/recruitment/kpi-card`) — keep it but feed it trustworthy numbers (§I).

**2.5 Tokens & flourishes** — tokens only in `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`, `--shadow-hero`/`--shadow-float`. Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`. **Toasts: sonner** (`<Toaster>` in `resources/js/app.tsx`) — `toast.success/error` on **every** action. Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards. Avatars via the existing `getAvatarColor` helper (initials fallback).

---

## B. Hero rethink — the golden band (NO clock, fitted to timekeeping)

**Current:** a generic `<PageHero category="hr">` (purple category gradient, slot-based) with four stats and three buttons. Not the golden band, not the manager lens.

**Do:** build a **`TimeHero`** (in `resources/js/components/hr/time/time-hero.tsx`) using the **same gradient + `HeroStat` + `QuickAction` language as `people-hero.tsx`**, sized to this page. **No clock** (the clock lives on `/hr/my` + `/my-day`). Compose:

- **Left column:** title **"Timekeeping"** + one-line manager context ("Review team time, fix exceptions and keep {tenant} payroll-ready"). Small icon medallion (`Clock` / `Timer`).
- **Glanceable `HeroStat`s** (each click-filters or deep-links a tab):
  - **Clocked in now** (live count → Dashboard "who's on now").
  - **Team hours this week** (→ Time Entries, this week).
  - **Awaiting approval** (Operations submitted count; `--hr-amber` if >0 → Shift Timesheets, submitted).
  - **Exceptions** (missed clock-outs + break-compliance fails + overtime, combined; `--hr-amber`/critical → Dashboard exceptions). Use tabular figures.
- **`QuickAction`s:** **Add time entry** (opens the manual wizard, §F-1) • **Clock on behalf** (§F-2) • **Review shift timesheets** (deep-link `/operations/timesheets`) • **Export** (gated, §K-12).
- **Live alert chips** (drill-down popover, like `people-hero`/`my-hr-hero`): "{n} missed clock-outs ⏰", "{n} break-compliance fails", "{n} over 40h this week", "{n} sleepovers/PH today". Reuse the chip + `NeedsDot` pattern (amber).
- **Right column (where My HR puts the clock):** since there's **no clock**, fill it with a page-appropriate cluster — a compact **"on now" avatar stack** (who's currently clocked in, with elapsed pills) **or** a small **weekly team-hours `Ring`/sparkbar**. Persist any toggle to `localStorage` (`hrTime.heroRight`) like People does.

> For a user **without** manager scope who lands here (route gate is `timesheets.viewAny`), show a slimmer band: their own hours + a friendly pointer **"Clock in/out on My Day"** (deep-link) — this page doesn't clock.

---

## C. Tabs — standardise into a real `TimeTabs` shell

Replace the hand-rolled `<Tabs>` in `index.tsx` with the standardised strip (mould of `HrTabs` over `TabStrip`), `?tab=` deep-linked, per-tab counts as badges, **right-click menu on the tab strip** (§E). **Propose the final set to Chane in the §A audit and get sign-off before building.** Recommended starting set:

1. **Overview** (default, §D) — the live oversight dashboard: "who's on now", exceptions board, team weekly hours, recent activity. **No personal clock card.**
2. **Time Entries** (§F-1/§F-3/§G) — the `HrTimeEntry` register with scope + filters + search, **Add entry** & **Clock on behalf**, per-row right-click + **amendment history** drill-in, export, real empty/skeleton/error states.
3. **Shift Timesheets** (§H) — premium **read-only** restyle of the Operations `Timesheet` window (today's "Period Timesheets"), honest framing, deep-link to `/operations/timesheets`. **Confirm with Chane** whether to fold today's separate **"Period Approvals"** view into this tab as an **"Awaiting approval" segment** (recommended — one read-only tab with `Segmented`: *All · Awaiting approval · Recently approved*) or keep it as its own tab. Either way it stays **read-only + deep-link** (gated `canApproveAny`); **approval is owned by Operations**.

> Per tab: shared list/card + `StatusBadge` chips; real **empty state** (icon + line + CTA) and **skeleton**; every create/edit/amend flow is a **modal** (§F); every row has a **right-click menu** (§E) + hover actions; **toast** every result. (Optional, **confirm with Chane**: a light **Reports/Export** surface — hours by staff/site, overtime, break-compliance, mileage — but payroll export stays owned by the HR pay run, §H.)

---

## D. Overview tab — the live oversight view (who's on now, exceptions)

This replaces today's personal "Clock Status / This Week" cards (those belong on `/hr/my`). Build a **manager command view**:

- **On now (live):** a panel of everyone currently clocked in (avatar, name, since-time + elapsed, site/client/shift if linked, pay-type chip). Soft-refresh (poll or periodic Inertia `reload`, `motion-reduce`-safe). Right-click a person → **Correct/close clock-out…** (§F-5), **View entry**, **View profile**, **View on roster**.
- **Exceptions board** — the headline of this tab. Cards/rows for: **Missed clock-out** (active entry open beyond a threshold, e.g. >12h), **Break-compliance fail** (`break_compliance_met = false`), **Overtime** (>40h this week, per staff), **Unlinked to shift** (clocked time with no `shift_id`/variance vs roster), **Sleepover / on-call / public-holiday today** (loadings to verify). Each exception row deep-links or opens the right modal (correct, amend, review on roster).
- **Team weekly hours** — restyle the existing weekly bar into a team roll-up (Mon–Sun, total + per-day), driven by `getWeeklySummary`/KPI queries once §I makes them complete.
- **Recent activity** — keep, restyle (clock-in/out events, on-behalf flagged), right-click → open entry / view profile.

---

## E. Right-click everywhere (rows **and** tabs)

Chane explicitly wants right-click options "under tabs etc." Build a `TimeContextMenu` (mould of `ShiftContextMenu`) and wire `onContextMenu` (and a `⋯` hover button) on:

- **Time-entry rows:** **Edit / amend…** (opens §F-3, reason required) · **View amendment history** (§G) · **Correct / close clock-out…** (if still active, §F-5) · **Add note** · **View on roster** (if `shift_id`) · **View staff profile** · **Copy link** · (gated) **Void entry…** (soft-delete with reason → amendment, §K-10). Gate destructive/manager items; show `kbd` hints.
- **"On now" rows (Overview):** **Correct/close clock-out…** · **View entry** · **View profile** · **View on roster**.
- **Shift-timesheet rows (read-only):** **Open in Operations** · **Review** (if submitted) · **View shift** · **Copy link** — all deep-link out, no inline approve.
- **The tab strip itself:** right-click a tab → **Set as default view** · **Open** · **Pin**. Persist default-tab + pins to `localStorage` (`hrTime.defaultTab`, `hrTime.pinnedTabs`); render a `decorations` star/pin on the chosen tab.

Every menu action fires a toast and, where it writes, hits a real route (§K). No dead items. No native `confirm()/alert()` — use `alert-dialog` for destructive confirms.

---

## F. Modals = exact Add-Client wizard pattern

Every create / edit / amend / correct flow clones `resources/js/components/clients/add-client-dialog.tsx` (warm idioms from `leave-request-dialog.tsx`): same **full-height bespoke shell** (`Dialog` + `DialogContent [&>button]:hidden`, `flex h-[min(92vh,860px)]`, left **stepper rail** `w-[248px] bg-sidebar` with per-step icons + blurbs + check-on-complete, **completeness meter** at the rail foot, "Step X of N" header, **top progress bar**, scroll-contained body, footer with Back / Cancel / **Save & add another** / primary), same **engine** (Inertia `useForm`, client-side `validateStep`, `stepForError` jump-to-offending-step, `WizardSuccessPane`, `resetAll()` for Save & add another), from `@/components/wizard/*`. **No more thin single-screen dialogs** — the current Edit and Clock-on-behalf dialogs get rebuilt as full wizards.

1. **Add time entry (manual)** — the missing create flow (`hr.time.entries.store` exists; extend its validation, §K-2). Steps:
   - **Staff & date** (`PeoplePicker` — required for managers; `TilePicker`/date for entry date).
   - **Times & break** (clock-in, clock-out, break minutes with the **NZ break hint** ≥4h→30m / ≥2h→10m shown live, mileage km).
   - **Pay & context** (`pay_type` via accent `TilePicker`; `is_sleepover` / `is_on_call` / `is_public_holiday` toggles; link to **shift** / **site** / **client**; `cost_centre` / `project_code`).
   - **Review** (live summary card à la Leave: hours computed, loadings, compliance, "Edit" jump-back). **Save & add another** for batch entry.
2. **Clock on behalf** — rebuild the existing dialog onto the wizard shell and **fix the dropped data**: persist the **required `reason`**, and add the loading flags `is_sleepover` / `is_on_call` / `is_public_holiday`, plus `mileage_km`, `client_id` and a **shift** picker (all need `ClockOnBehalfRequest` extended — **except `shift_id`, which it already accepts**; see §K-1). Steps: **Staff** → **Times & break** → **Pay & context** → **Reason & review** (clock-out optional — "leave blank if still on shift"). Keep the live Entry Preview.
3. **Edit / amend entry** — rebuild the thin Edit dialog. Add `is_public_holiday` (§K-3), `mileage_km`, `is_sleepover`/`is_on_call`, `cost_centre`/`project_code`. Keep the **mandatory amendment reason**. Show a **live "what changed" diff** (old→new per field) before save, and a **section/tab embedding the amendment history** (§G). Block editing **approved** entries (matches the service guard) with a clear explanation.
4. **Amendment history** (read) — see §G. A `Sheet`/drawer, not a wizard.
5. **Correct / close missed clock-out** — focused modal to close an entry left open (set clock-out time + break + reason). Back it with `AttendanceService::correctSession` (it already exists and returns the linked submitted timesheet to draft so the loop stays consistent — **confirm the route is exposed**, §K-11). This is how the Overview "missed clock-out" exception gets actioned.

> Wire each modal from the page like Leave does (`open` state + `<AddTimeEntryDialog … />` etc.), opened from the hero `QuickAction`s, tab CTAs and row/context menus. Confetti is **not** appropriate here — keep it to a clean `WizardSuccessPane` + sonner toast.

---

## G. Amendment audit trail + exceptions — surface the hidden data

The backend already captures a **complete per-field amendment audit** (`HrTimeEntryAmendment`: `field_name`, `old_value`, `new_value`, `reason`, who, when) and exposes it at `GET /hr/time/entries/{entry}/amendments` (`hr.time.entries.amendments`) — but the UI shows nothing beyond a static "(amended)" word. Build the surface:

- **Amendment indicator** on any amended row (count badge), click / right-click → **View amendment history**.
- **History drawer** (`Sheet`): a timeline of each change — *{editor} changed {field} from {old} → {new} · "{reason}" · {when}*. Use `StatusBadge`/tone for adds vs edits. This is load-bearing for a care provider's audit posture, so make it first-class.
- **Exceptions** (Overview, §D) read from the same `HrTimeEntry` flags (`break_compliance_met`, active-too-long, `total_hours`>40/week, `is_sleepover`/`is_on_call`/`is_public_holiday`, missing `shift_id`). Make break-compliance a **real** signal — once §I/§K ensure breaks are actually captured at clock-out, the warning stops being noise.

---

## H. Source of truth & the rostering cross-loop (link, don't rebuild)

There is **one timesheet of record**: `App\Models\Timesheet` (Operations/shift timesheets). What `/hr/time` *owns* is `HrTimeEntry` (clock + manual entries with NZ break-compliance and the amendment trail). The relationship (verified in code):

- **HR → Operations:** every `HrTimeEntry` with a clock-out **spawns/updates an Operations `Timesheet` draft** via `DraftTimesheetService` (`fromAttendanceSession` / `fromManualEntry`). **Do not bypass this bridge.**
- **Operations → HR:** Operations approval (`TimesheetApprovalService::approve` → `TimesheetHrSyncService::syncToHr`) **mirrors back** onto the `HrTimeEntry` (status `approved`). Approval also snapshots pay, locks the row, generates billing, and feeds the HR pay run.
- **Clock engine:** `AttendanceService` (writing `HrAttendanceSession`) is shared by `/hr/my`, `/my-day` and the shift board. **Keep routing HR time writes through `TimeTrackingService`; don't fork a second clock.**

**Owned by rostering/operations — LINK, don't rebuild (no inline writes on `/hr/time`):**
- Timesheet **submit / approve / reject / return / resubmit / bulk** — only `TimesheetApprovalService` may write these (`operations.timesheets.*`). The "Shift Timesheets" tab keeps **deep-linking** ("Review" → `module_url`).
- Operations `Timesheet` **create/edit** (`DraftTimesheetService` + `TimesheetController`; approved/payroll-linked rows are immutable by model guard).
- **Shift lifecycle / rostering / eligibility / publish** (`ShiftLifecycleService`, `RosteringController`).
- **Variance / reconciliation** (`TimesheetReconciliationService` — scheduled-vs-actual). Surface its result **read-only** if useful; don't recompute.
- **Payroll export & "Paid"** (`PayrollExportService` + `HrPayrollRun` over the approved-`Timesheet` pool; `NzPayrollCalculatorService` for PAYE/ACC/KiwiSaver/Holidays-Act). `/hr/time` must not mark paid.

**Safe to build on `/hr/time` (genuinely owned here, no fork):** clock-on-behalf, manual `HrTimeEntry` CRUD, edit/amend + amendment trail, correct missed clock-out, KPI/dashboard reads, and **read-only** deep-linked timesheet/approval views.

**Do not build to the stale docs.** `shifts-module-map.md` / `shifts-frontend-routes.md` reference an `HrTimesheet` model, an `HrTimesheetApprovalService`, and `hr.time.timesheets.{approve,reject,…}` routes that **do not exist**. The "Period" framing is just a Mon–Sun **display bucket** over per-shift Operations `Timesheet` rows — there is **no** period-aggregate write model. Rename honestly ("Shift Timesheets") and **don't invent** one. (Flag the docs for correction in §K-13.)

---

## I. The `/my-day` & `/hr/my` clock paths — unify the writer (it feeds this page)

Chane has approved fixing the two data-integrity issues that make this page's numbers untrustworthy. These are **backend** changes (specs in §K) but they're in-scope for the redesign because the UI is meaningless without them:

- **`/my-day` & `AttendanceController` clock-outs create NO `HrTimeEntry`.** So `/hr/time`'s "Time Entries" tab and its `HrTimeEntry`-based KPIs (Hours This Week / Active / Overtime / Avg) **silently exclude** anyone who clocks via `/my-day`, while "Pending Timesheets" (Operations `Timesheet`) includes them. **Fix:** route those clock paths through `TimeTrackingService` (or have `AttendanceService::clockOut` write/maintain the `HrTimeEntry`) so **every** clock source produces a consistent `HrTimeEntry`. (§K-5)
- **`/hr/my` (`MyHrController`) holds a forked copy** of the `HrTimeEntry` create payload + the NZ break-compliance formula (≈ lines 1097–1215) instead of delegating. **Fix:** delegate to `TimeTrackingService` so the rule lives in exactly one place. (§K-6)
- Add a **unique guard on `hr_time_entries.attendance_session_id`** to prevent the duplicate-open-entry race the current code self-heals around. (§K-7)

After these land, **recompute the KPIs** so the hero/Overview numbers and the Time Entries list agree (§K-14).

---

## J. NZ statute & care-sector correctness

- **Break compliance** — keep the NZ rule (`worked ≥4h → 30m`, `≥2h → 10m`) but make breaks **actually captured** at clock-out (on `/my-day` + `/hr/my`) and **editable/amendable** here, so `break_compliance_met` is a real exception, not a false fail. Surface it in the exceptions board (§D) and on the entry.
- **Sleepovers** (core to NZ supported living) — `is_sleepover` drives a different pay basis (minimum-wage top-up; wake-ups during a sleepover are active hours). **Confirm with Chane** whether to add a lightweight **sleepover disturbance log** (wake-up start/end during a sleepover) so disturbed time is paid correctly — capture in the manual/edit wizard if wanted, else just surface the `is_sleepover` flag clearly.
- **On-call** — `is_on_call` loading; surface and make settable/amendable.
- **Public holidays** — `is_public_holiday` drives PH loading + alternative (lieu) day accrual (`AlternativeHolidayService` on the pay side). Make it **settable on create AND amendable** (§K-3); shade PH (incl. Matariki, regional anniversaries) in any date context.
- **Overtime** — keep the >40h/week watch; surface per-staff in exceptions. (Don't hard-code award rules here — pay interpretation is the pay run's job.)
- **Mileage** — `mileage_km` (NZ IRD reimbursement) is captured by the backend but never shown; surface mileage on entries and in exports.
- NZD / `en-NZ` throughout. Don't over-build to the unpassed Employment Leave Bill — just don't design anything that blocks the hours-based direction.

---

## K. Backend handoff for Claude Code (append to this as you design)

> Claude Design: as you build the UI and discover anything that needs server work, **add it here** with a short spec + migration sketch, so Chane has one clean list to hand to Claude Code. Gate manager actions on the right `timesheets.*` permission, respect `ResolvesHrTenant`, and **confirm any schema before building**. Seed list from the audit:

**Bugs / correctness:**
1. **Clock-on-behalf drops data** — `ClockOnBehalfRequest` has no `reason` rule and `TimeTrackingService::clockOnBehalf` never persists it, so the UI's **required** reason is silently dropped: add the rule and **persist it** (as entry `notes` and/or an initial `HrTimeEntryAmendment`/audit row). Also add rules for `is_sleepover` / `is_on_call` / `is_public_holiday` / `mileage_km` / `client_id` so the rebuilt wizard (§F-2) can set loadings & client (`shift_id` is already accepted by the request).
2. **`StoreTimesheetRequest` is under-validated** for the manual-entry UI — add rules for `pay_type`, `is_sleepover`, `is_on_call`, `is_public_holiday`, `mileage_km`, `site_id`, `client_id`, `shift_id`, `cost_centre` (the service `createManualEntry` already supports them). Also reconsider its `manageAny`-only gate vs. allowing `approve` for managers creating team entries — **confirm with Chane**.
3. **`is_public_holiday` can't be amended** — add it to `UpdateTimeEntryRequest` rules **and** the service's `editableFields` so the Edit/amend wizard can set it.
4. **Break capture at clock-out** — ensure the `/my-day` + `/hr/my` clock-out modals send `break_minutes` / `mileage_km` / `notes` so `break_compliance_met` is computed from real input (the legacy `/hr/time` personal clock-out posted an empty body → false fails). Tied to §I.

**Data integrity (Chane approved — §I):**
5. **`/my-day` + `AttendanceController` clock-out must create/maintain an `HrTimeEntry`** — route through `TimeTrackingService` (or have `AttendanceService::clockOut` own the write) so the Time Entries tab + KPIs are complete across all clock sources.
6. **De-fork `MyHrController`** (≈ L1097–1215) — delegate clock-in/out to `TimeTrackingService`; remove the duplicated `HrTimeEntry` payload + break-compliance math so the rule lives once.
7. **Unique index/guard on `hr_time_entries.attendance_session_id`** — prevent duplicate open entries (the enhance migration added the column without a unique constraint; current code self-heals around the race).

**Missing endpoints / wiring (mostly already exist — confirm + extend):**
8. **Manual "Add entry"** → `hr.time.entries.store` exists; just needs the validation in §K-2, then wire the §F-1 modal.
9. **Amendment history** → `hr.time.entries.amendments` exists; wire the §G drawer (no new endpoint).
10. **Void / soft-delete an entry** (if added to the row menu, §E) — a gated endpoint that soft-deletes with a **required reason** written to the amendment trail. **Confirm with Chane** before building.
11. **Correct missed clock-out** (§F-5) — confirm/expose a route onto `AttendanceService::correctSession` (force-close a left-open session with audit; it already returns the linked submitted timesheet to draft).
12. **Export** — CSV / Excel / PDF for time entries + an hours report (by staff / site / pay-type / date-range), incl. overtime, break-compliance, mileage. Use the streamed export pattern (or the `xlsx`/`pdf` server equivalents). Payroll export stays the HR pay run.
13. **Correct/delete the stale architecture docs** — `docs/architecture/shifts-module-map.md` (HR Time Route Inventory; Timesheet Approval Trace) and `docs/architecture/shifts-frontend-routes.md` reference a non-existent `HrTimesheet` model, `HrTimesheetApprovalService`, and `hr.time.timesheets.{submit,approve,reject,return,bulk-*}` routes. Fix them to reflect the real `HrTimeEntry` + Operations `Timesheet` model so future work isn't misled.
14. **Recompute KPIs** once §K-5 lands so `HrTimeEntry`-based stats (Hours/Active/Overtime/Avg) and the Operations-`Timesheet`-based "Pending" stat agree on the same population.

> For each item: short spec + migration (if any) and **confirm before building**. Don't silently invent schema.

---

## L. Premium polish & delight

- **Avatars** with real photos (coloured-initials fallback via `getAvatarColor`); "on now" rows get a soft live pulse (`motion-reduce`-safe).
- **Toasts** on every add / amend / correct / void / export (sonner). Clean `WizardSuccessPane` on create (no confetti here — it's an oversight tool).
- **Micro-interactions** — row hover lift, `animate-in` on new entries, exception cards that gently pulse amber, the hero `Ring`/sparkbar — all guarded by `motion-reduce:*`.
- **Keyboard:** `/` focuses search, `n` opens Add entry, `b` opens Clock on behalf, `Esc` closes menus/dialogs; arrow/Enter on rows; arrow/Home/End on the tab strip.
- **Loading / empty / error:** every tab gets a `skeleton-card` while loading and a friendly `EmptyState` (icon + line + primary CTA) — no bare "No time entries found." Special empty state for a clean exceptions board ("No exceptions — everyone's accounted for ✅").
- **Consistency sweep:** all status/pay-type chips via `StatusBadge`; delete the hand-rolled `statusConfig`/`payTypeConfig` colour maps; no native `confirm()/alert()`; no raw hex anywhere; the page split into components (`time-hero.tsx`, `time-tabs.tsx`, `overview/*`, `entries/*`, dialogs) — retire the single 1,600-line `index.tsx`.

---

## Definition of done

- `/hr/time` hero is the **golden HR band** (gradient, `HeroStat`s, `QuickAction`s, live exception chips, "on now" right-column) — **no clock** — visually on par with `people-hero`; built on the shared `hero-kit.tsx`.
- The hand-rolled tabs are replaced by a real **`TimeTabs`** shell (`?tab=`, per-tab counts) with the Chane-approved set (recommended: **Overview · Time Entries · Shift Timesheets**).
- **Overview** is a live oversight view (who's on now + exceptions board + team weekly hours + recent activity) with **no personal clock card**; self-clocking is pointed to `/hr/my` + `/my-day`.
- **Manual "Add time entry"** and a rebuilt **Clock on behalf** exist as **Add-Client-grade wizards** (stepper rail + completeness + per-step validation + server-error→step + **Save & add another** + `SuccessPane`); the **Edit/amend** flow is a full wizard with a live diff; **Correct missed clock-out** works.
- The **amendment audit trail** is visible (history drawer reading the existing endpoint); the dropped **clock-on-behalf reason** is persisted; **`is_public_holiday`**, **mileage**, **sleepover/on-call**, **cost-centre/project** are all settable & amendable.
- **Shift Timesheets** is a premium **read-only** deep-linked window onto the Operations flow; **approval/reject/return/bulk/payroll stay in Operations** — nothing forked.
- **Right-click menus** on rows **and** the tab strip; default-tab/pin persisted; every item wired + toasted; `kbd` hints shown.
- **The numbers are trustworthy:** `/my-day` + `/hr/my` clocks write a consistent `HrTimeEntry` (single writer via `TimeTrackingService`), and the KPIs and Time Entries list **agree**.
- **End-to-end:** add / amend / correct / void / export / on-behalf all hit real routes with toasts; **no dead buttons**; break-compliance is a real signal, not a false fail.
- NZD / `en-NZ` retained; breaks, sleepovers, on-call, public holidays (incl. Matariki/regional) and mileage handled correctly; `ResolvesHrTenant` + `timesheets.*` gates respected; **no regressions** to `/hr/my`, `/my-day`, the roster, `AttendanceService`, `TimeTrackingService`, `DraftTimesheetService`, or the Operations timesheet/payroll flow.
- Clean `build`, `types`, `lint`; screenshots of each tab + each modal match the reference pages. **§K backend handoff list is filled in** for Chane → Claude Code.
- **Signals to watch:** missed-clock-out count, break-compliance pass rate, time-to-correct an exception, % of clocked time linked to a shift, KPI agreement (HR vs Operations population), manual-entry/on-behalf usage.
