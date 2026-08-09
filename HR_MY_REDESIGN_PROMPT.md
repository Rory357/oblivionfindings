# HR "My" Self‑Service Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent. Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/my`, and diff against the gold‑standard pages before continuing. Start with the audit in §A, then build §B–§K.

---

## 0. Mission

Redesign the employee self‑service area at **`/hr/my`** so frontline care staff *actually want to use it*. It must feel as polished, fast and "alive" as our three gold‑standard pages — **`/meds/today`**, **`/my-day`**, **`/health-safety`** — and reuse their exact components and tokens. Today `/hr/my` is a "wall of widgets" with 12 full‑page tabs, ad‑hoc tables, almost no modals, no right‑click actions, and a clock‑in buried in a faint hero tile. Bring it to parity and add the features below. Result should feel warm and a little fun (recognition, celebrations, live micro‑interactions) — not a grey form portal.

## 1. Non‑negotiables

1. **Keep the tab structure.** You may rename, reorder, merge (Time + Shifts) and add tabs, but the tabbed `MyHrTabs` model stays.
2. **Reuse the kit — never hand‑roll a primitive we already have.** This page must be *standardised with the rest of the app*. Every hero, modal, badge, status colour, context menu and toast comes from §2. No new bespoke widgets, no raw hex (ESLint blocks it).
3. **Web‑only desktop app.** No phone frames. Design for mouse + keyboard: hover states, right‑click menus, keyboard shortcuts. Responsive down to a small laptop is fine.
4. **Information‑gathering = modals.** Every create/edit/record/respond flow becomes a **wizard dialog** (§2.2), not an inline collapsible form and not a full‑page route. Reading long content (policy, payslip, 1:1 history) may use a dialog or sheet.
5. **Single source of truth.** Don't fork data owned by another module (esp. shifts — §G). Surface it read‑only.
6. **Locale is correct — keep it.** This is an **NZ‑only** product: keep **NZD / `en-NZ`** formatting (`formatNzd`) and KiwiSaver in benefits. Do **not** switch to GBP. Just apply NZD/`en-NZ` consistently across every tab (payslips, expenses, benefits).
7. **Verify each pass:** clean build, no TS errors, screenshot the changed surface, confirm it matches the reference page's hero/modal/menu.

---

## A. Audit & benchmark first (do this before building)

Study `/meds/today`, `/my-day`, `/health-safety` and interact with them — they are the parity bar.

Then audit `/hr/my` against this **best‑in‑class employee self‑service checklist** (mark each Present / Partial / Missing, and close the gaps in §B–§K). Benchmarks: **BambooHR** (widget home, celebrations, who's‑out, request‑leave‑from‑home, in‑app e‑sign, payslips + YTD, benefits), **Workleap/Lattice** (1:1s with shared agenda, talking points, action items w/ owners + carry‑forward, shared vs private notes, history; goals; feedback; praise tied to values), **HiBob** (social branded home, kudos/shoutouts w/ reactions, org chart, clubs), **Deel/Personio** (document vault, pending‑task dashboard).

Checklist:
- **Time & Attendance:** prominent clock in/out + live timer; timesheets; hours/overtime.
- **Leave:** request from home; balances; who's‑out/team calendar; approval status.
- **Pay & Benefits:** payslips (history + YTD, PDF); tax/bank self‑edit; own benefits/total‑reward visibility.
- **Performance & Growth:** recurring 1:1s (shared agenda, action items w/ owners, shared+private notes, history, carry‑forward); goals w/ progress; feedback; reviews/self‑assessment.
- **Documents & Compliance:** document vault (folders, expiry); in‑app e‑sign; policy acknowledgements; onboarding tasks.
- **People & Social:** kudos/recognition feed w/ reactions; celebrations; org chart / your team; announcements; directory.
- **Personal/Profile:** edit personal/emergency/bank details; completeness; pending tasks + announcements on landing.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero** — `PageHero` from `@/components/page/page-hero`, pass **`category="hr"`** (auto HR gradient via `--category-hr`). Reference caller to copy: `resources/js/pages/my-day/components/my-day-hero.tsx`. Data‑driven props: `title`, `description`, `meta[]`, `badges[]` (`PageHeroBadge`, supports hover/click drill‑down popover), `stats[]` (`PageHeroStat`), `quickActions[]`, `actions`, `avatar`/`avatarStack`, `footer`. Sub‑components in `@/components/page/`. Richer cluster look optional: H&S kit `resources/js/pages/health-safety/components/hs-hero-kit.tsx` (`HeroShell`, `HeroStatusPill`, `HeroMedallion`, `HeroCluster`/`HeroClusterTile`, `HeroSummaryStrip`, `HeroSegmented`).

**2.2 Modals / wizards** — `MedsWizardDialog` from `@/components/meds/wizard-shell` (+ `SummaryRow`, `MedsWizardStep`): stepper rail + progress bar + scroll‑contained body + review/sign footer. Field primitives from `@/components/wizard/primitives`: `Field`, `FieldErr`, `Segmented`, `ChipMulti`, `SelectInput`, `StepHead`, `SubHead`, `InfoCard`, `WIZARD_*_CLASS`. Reference usages: `resources/js/pages/meds/today/components/record-dose-wizard.tsx`, `prn-wizard.tsx`. Base shadcn in `@/components/ui/`: `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`.

**2.3 Right‑click menus + hover actions** — `ShiftContextMenu` from `@/components/rostering/shift-context-menu` (`ShiftCtxItem`, `ShiftCtxState`): portal‑rendered, viewport‑flipping, Esc/outside‑click close, icon+label+`kbd`+tone. Pattern ref: `@/components/emar/mar/dose-context-menu` (`DoseContextMenu`), wired in `resources/js/pages/meds/today/index.tsx` via `onContextMenu={(e) => onCtx(e, row)}`. Lightweight inline row buttons: `HoverAction` (`resources/js/pages/my-day/components/hover-action.tsx`).

**2.4 Cards / states / badges** — `PageHeroStat`; `@/components/ui/badge` (`Badge`); **`@/components/ui/status-badge` (`StatusBadge`) — use everywhere instead of re‑mapping status colours by hand**; `@/components/ui/card`, `table`, `empty-state`, `error-state`, `loading-state`, `skeleton-card`, `skeleton-table`.

**2.5 Tokens & flourishes** — tokens only in `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary` (purple), `--shadow-hero`/`--shadow-float`, `--live` (teal). Use Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`. Toasts: **sonner** (`<Toaster>` mounted in `resources/js/app.tsx`) — `toast.success/error/info` on every action. Optimistic/offline writes: `@/lib/offline-queue` + idempotent `client_request_uuid` (apply to clock‑in/out and action‑item checkboxes). Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-top-1`, `animate-ping`) with `motion-reduce:*` guards.

---

## B. Hero rethink (and clock‑in)

**Current:** `PageHero category="hr"` with avatar + greeting + 3 stat pills, and the **clock‑in widget inside a low‑contrast translucent tile** (`bg-primary-foreground/10 … backdrop-blur`) — the most important daily action is the least visible.

**Do:** keep `PageHero`, rebuild contents to be useful at a glance — time‑aware greeting + date; **stats that matter today** (hours this week + mini‑sparkline, next shift, open action items, kudos this month); `badges[]` for live alerts ("2 documents to sign", "attestation due") each opening its drill‑down popover; `quickActions[]` (Request leave / Submit expense / Prep 1:1).

**Clock‑in: keep it in the hero but promote it.** Replace the translucent pill with a **solid, high‑contrast "Clock" card** — own surface, live elapsed timer, today's total, big primary Clock In/Out button, break toggle; `--live` teal + `animate-ping` dot while active; reads as the hero's primary action. **It already shares one engine with `/my-day` — keep it that way.** `/hr/my` clock‑in (`/hr/time/clock-in` → `MyHrController::clockIn`) already calls the canonical **`AttendanceService`**, which writes one `HrAttendanceSession` (the source of truth), generates the `Timesheet` draft `/my-day` shows (via `DraftTimesheetService`), and links a `HrTimeEntry` for the HR time view. So clocking in/out on either page stays in sync — one engine, two surfaces. The redesigned hero clock must **keep calling `AttendanceService`** (don't fork a new clock path; note `MyDayActionsController` explicitly warns against re‑adding quick‑clock endpoints). Treat this as a visual promotion of the existing flow, not a new system.

---

## C. Tab‑by‑tab (apply the global pattern to each)

> Per tab: replace ad‑hoc `<table>` with the shared table/list + `StatusBadge`; add real empty states (icon + line + CTA); add skeletons; move every create/edit into a wizard dialog (§2.2); add a right‑click menu (§2.3) on each row; toast every result.

1. **Overview** — kill the "wall of widgets". Action‑first home: the Clock card, a "Needs your attention" rail (leave approvals, docs to sign, attestations due, open 1:1 actions, expiring compliance), next shift + this‑week hours, and the **delight strip** (§H). Remove the redundant 11‑icon Quick Links grid.
2. **Leave** — keep balance gauges; convert request to a **Request Leave wizard** (type → dates → notes → review); add **Who's‑out team calendar**; right‑click → Cancel / Duplicate / View.
3. **Expenses** — **Submit Expense wizard** (category → amount+date → receipt upload → review); row right‑click → View / Withdraw; `StatusBadge`.
4. **Training & Compliance** — requirement + expiry + clear status pill; surface "expiring soon"; deep‑link LMS; right‑click → Start / View certificate.
5. **Policies** — keep read‑and‑attest but restyle to the wizard shell; badge attested vs outstanding; one‑click Attest + toast.
6. **Profile** — edit per section in a sheet/dialog (Personal, Emergency, Bank/Tax) instead of one long inline form; show completeness.
7. **Reviews** — render review in a dialog with sign‑off; `StatusBadge` for stage; history.
8. **Goals** — card list with progress; edit in dialog; link goals to 1:1s (§F).
9. **Surveys** — friendly cards; confirm via standard `alert-dialog`.
10. **Time & Shifts** — see §G.
11. **Documents** — see §I.
12. **Payslips** — open in a dialog (not inline accordion); NZD (`en-NZ`); download PDF; YTD summary.
13. **1:1** — NEW, see §F.

---

## F. NEW "1:1" tab (Workleap/Lattice style)

**Backend reality (audited):** a 1:1 backend **already exists**, currently manager‑only. `app/Domain/Hr/Models/HrSupervisionNote.php` already has `session_type` (incl. `'one_to_one'`), `topics_discussed`, **`actions_agreed` (JSON = action items)**, `next_session_date`, **`employee_comments`**, **`employee_acknowledged` (+`_at`)**, **`is_visible_to_employee`**. Manager controller `app/Http/Controllers/Hr/SupervisionController.php` (gated `hr.performance.view|manage`). Migration `database/migrations/2026_02_12_100006_create_hr_performance_tables.php`.

**Phase 1 — surface what exists (ship first):** employee‑scoped methods on `MyHrController` + routes under `/hr/my/one-to-ones`; list notes where `employee_user_id = me` AND `is_visible_to_employee`. Render each: date, who with, topics, and **`actions_agreed` as a checkable list**; let employee add `employee_comments` and acknowledge (reuse `update()`, employee‑scoped). Add a **"My open actions" rail** aggregating unchecked actions across all 1:1s (owner + source meeting) — also show it on Overview. History view by date.

**Phase 2 — net‑new backend (write a short spec + migration, confirm before building):** a first‑class **pre‑meeting shared agenda / talking‑points** table (employee + manager add before the meeting, reorder, comment, check off); a first‑class **action‑item table** (owner, due date, status, source 1:1) that **carries forward**; **shared vs private notes**; recurring cadence with the next 1:1 pre‑created + reminder.

---

## G. Shifts (decision)

**Surface the employee's own week inside `/hr/my` read‑only; keep scheduling in Workforce; don't duplicate data.** `app/Models/Shift.php` has `belongsTo(user_id)` + `scopeVisibleToFrontline()`, and `MyHrController::time()` already queries the next 3 days — extend that exact query to a **week range** (`whereBetween('starts_at', [startOfWeek, endOfWeek])`). Merge into a single **"Time & Shifts"** tab: Clock card + today's entries + weekly hours **and** this‑week rota (day columns, shift cards w/ site/role/time); right‑click a shift → View / Add to calendar (reuse `HrICalToken`/`ICalController`). Promote to its own tab later only if shift features grow (swaps, open‑shift pickup).

---

## H. Delight layer (the "make it fun" ask)

Embed our **existing** social backend (currently only on separate pages): **Kudos** (`HrKudos` + `FeedController`/`FeedService`, `/hr/feed`) — latest kudos received, a send‑kudos wizard (person → value/category → message), small leaderboard/milestones peek; **Celebrations** (birthdays, work anniversaries, new starters from profiles/start dates) with one‑tap "Congratulate" that posts kudos; **Who's‑out today/this week** from leave; **Announcements** (`HrAnnouncement` + `AnnouncementController`, already support `acknowledge`) embedded with inline acknowledge (today only a count). Micro‑interactions: sonner toasts with personality, subtle confetti/`animate-ping` on clearing all action items or end‑of‑day clock‑out, progress rings, hover lift — tasteful, `motion-reduce`‑safe.

---

## I. Premium Documents tab

`HrDocument` already has `folder`, `category`, **`expires_at`**, and full **e‑signature** fields; a complete employee **e‑sign flow already exists** (`app/Http/Controllers/Hr/ESignatureController.php` → `pending`, `show`/sign, keyed on `signer_user_id`, pages `hr/signatures/pending` + `sign`) but is **not linked from `/hr/my`**. Rebuild Documents to: group by **folder/category** with counts + search/filter; document cards with type icon, date, **expiry badge**; a pinned **"Awaiting your signature"** section dropping straight into the existing e‑sign flow (toast + state update on signing); dialog preview + download. Phase 2 (confirm): employee document **upload/request** (no employee upload path today).

---

## J. Backend work summary

**Exists — just surface it (little/no schema change):** 1:1 employee read + ack/comment + open‑actions rail (`HrSupervisionNote`/`SupervisionController`, §F‑P1); this‑week shifts (extend `MyHrController::time()`, §G); kudos/feed/announcements embed (`HrKudos`, `FeedController`, `HrAnnouncement`, §H); my benefits self‑view (`HrBenefitEnrollment`, currently manager‑gated); documents folders + expiry + e‑sign link (`HrDocument`, `ESignatureController`, §I); quick wins — my assets (`HrAssetAssignment`), my onboarding/offboarding tasks (`HrOnboardingTask`/`HrOffboardingTask`), personal calendar/iCal (`HrCalendarEvent`, `ICalController`).

**Missing — build (spec → confirm → implement):** pre‑meeting shared 1:1 agenda + first‑class action‑item table w/ carry‑forward (§F‑P2); shared vs private 1:1 notes; employee document upload/request (§I). *(Attendance is already unified via `AttendanceService` — keep the hero clock on that path, §B; nothing to rebuild.)*

---

## K. Definition of done

- `/hr/my` hero, modals, badges, context menus, toasts and tokens are visually indistinguishable in quality from `/meds/today`, `/my-day`, `/health-safety`, and reuse their components (no new bespoke primitives, no raw hex).
- Every create/edit/record/respond flow is a wizard dialog; every list row has a right‑click menu + hover actions; every list has a real empty state + skeleton.
- Clock‑in is a prominent, live, high‑contrast hero action backed by a single attendance service.
- New **1:1 tab** (Phase 1): 1:1s with checkable action items, employee comments + acknowledge, and an aggregated "my open actions" rail.
- **Time & Shifts** shows this‑week rota from the Operations source (not duplicated).
- **Documents** groups by folder, shows expiry, links the existing e‑sign flow.
- Overview surfaces the delight strip (kudos, celebrations, who's‑out, announcements with inline acknowledge).
- Currency/date stays **NZD / `en-NZ`** (NZ‑only product), applied consistently across payslips/expenses/benefits.
- Accessibility/consistency: fix the faint‑clock contrast; keyboard + right‑click parity; status colours via `StatusBadge`.
- **Adoption signals to move:** weekly active employees on `/hr/my`, % clock‑ins via the page, kudos sent/week, 1:1 action‑item completion rate, docs signed in‑app, time‑to‑request‑leave.
- No regressions to `/hr/*` manager pages or the Operations shift module; respect `ResolvesHrTenant` scoping and `hr.*` gates (employee views self‑scoped to `user_id = me`).

**Build order:** §A audit → hero + clock card → roll out the modal + context‑menu + StatusBadge pattern across tabs → per‑tab (§C) → 1:1 (§F) → Time & Shifts (§G) → Documents (§I) → delight (§H). Verify each pass against the reference pages.
