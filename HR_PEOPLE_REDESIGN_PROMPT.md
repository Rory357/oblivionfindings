# HR "People" Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent (Claude design — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/people` (and `?tab=` for each tab), and diff against the gold‑standard pages/components before continuing. Start with the audit in §A, then build §B–§J.

**Page:** `https://oblivionfindings.com/hr/people?tab=people`
**Frontend:** `resources/js/pages/hr/employees/index.tsx` (Inertia + React + Tailwind v4)
**Backend:** `app/Http/Controllers/Hr/EmployeeProfileController.php` · routes in `routes/hr.php`
**Panes:** `directory-pane.tsx`, `positions-pane.tsx`, `departments-pane.tsx`, `people/org-chart-pane.tsx`, `add-employee-dialog.tsx` (all under `resources/js/components/hr/`)

---

## 0. Mission

Make `/hr/people` a **premium, end‑to‑end workforce‑admin surface** that feels identical in quality to our gold‑standard pages — **`/meds/today`**, **`/my-day`**, **`/health-safety`** — and reuses their exact components and tokens. Today the page is functional but dated: a **generic flat hero**, **duplicated stat cards**, **three hand‑rolled `<table>`s**, the **old 3‑step Add‑Employee dialog**, **no right‑click menus anywhere**, **no bulk actions**, and **no column sorting**. Bring it to parity, give it the **golden HR hero band**, swap the create flow for the **exact Add‑Client wizard pattern**, add **right‑click menus on rows and tabs**, and close the **backend gaps** so every action is wired end‑to‑end.

## 1. Non‑negotiables

1. **Keep the tab model.** The `HrTabs` tabbed shell stays. You may rename/reorder/merge tabs and add a "Saved views" affordance, but don't replace the tab system. Current tabs: **People · Directory · Positions · Departments · Org chart**.
2. **Reuse the kit — never hand‑roll a primitive we already have** (§2). This page must be *standardised with the rest of the app*: every hero, modal, badge, status colour, context menu, empty state and toast comes from the shared kit. No new bespoke widgets, **no raw hex** (ESLint blocks it — colours come from design tokens).
3. **Web‑only desktop app.** No phone frames. Design for mouse + keyboard: hover states, **right‑click menus**, keyboard shortcuts, multi‑select. Responsive down to a small laptop is fine.
4. **Information‑gathering = modals.** Every create/edit/record flow is a **wizard dialog** (§2.2 / §D), not an inline form and not a full‑page route. Reading detail can navigate to the profile (`/hr/people/{id}`) or open a sheet.
5. **Single source of truth.** Don't fork data another module owns. Positions/Departments/Org chart already have their own controllers — **call the existing named routes**, don't duplicate writes. People list is the canonical staff list (`User::staff()` + `HrEmployeeProfile`).
6. **Locale stays NZ.** NZD / `en-NZ` formatting; dates already use `toLocaleDateString('en-NZ', …)`. Keep it. Do **not** switch to GBP/US.
7. **Verify each pass:** clean `npm run build` (or `vite`), `npm run types` (no TS errors), `npm run lint`, screenshot the changed surface, confirm it matches the reference page's hero/modal/menu. Don't move on with a broken pass.

---

## A. Audit & benchmark first (do this before building)

Study `/meds/today`, `/my-day`, `/health-safety` and **interact** with them — they are the parity bar. Then study the two patterns you must clone:

- **Golden hero** → `resources/js/components/hr/my-hr-hero.tsx` (brand‑gradient band, `HERO_STYLE`, `HeroStat`, `QuickAction`, te‑reo greeting). This is the look Chane wants — **but the People page must drop the clock** and re‑purpose the right column (§B).
- **Gold‑standard modal** → `resources/js/components/clients/add-client-dialog.tsx` (full‑height bespoke shell: **stepper rail + completeness meter + per‑step validation + server‑error→step mapping + Save & add another + SuccessPane**), built on `@/components/wizard/primitives`. This is the modal to replicate for Add Employee (§D).

Then audit `/hr/people` against this **best‑in‑class people/workforce‑admin checklist** (mark each **Present / Partial / Missing**, then close gaps in §B–§J). Benchmarks: **BambooHR** (employee directory w/ photo grid + list toggle, quick filters, bulk actions), **HiBob / Workday** (people table w/ saved views, column chooser, sort, multi‑select bulk edit, org chart), **Personio / Deel** (headcount stats, employment‑type mix, compliance flags, import/export, invite/seat management).

**Checklist (fill this in as the first pass and paste back the results):**

- **Hero:** branded band • workforce stats that matter • quick actions (Add / Import / Export / Invite) • live alert badges (compliance/probation) w/ drill‑down • **no clock**.
- **People table:** column **sort** • **multi‑select + bulk actions** • column chooser/density • **right‑click row menu** • photo avatars • status & type via `StatusBadge` • real empty state + skeleton.
- **Directory:** grid/list toggle • search • card actions • single source w/ People.
- **Positions:** sortable list • vacancies/headcount • create/edit via wizard • right‑click • empty state.
- **Departments:** sortable list • manager/parent • create/edit via wizard • right‑click • empty state.
- **Org chart:** zoom/expand‑collapse • reassign manager (already `PUT /hr/orgchart/{profile}`) • export/print.
- **Create flow:** Add Employee = client‑grade wizard (stepper, completeness, validation, Save & add another).
- **Bulk/admin:** deactivate/reactivate • assign site/department/manager • export selected • resend invite.
- **Tabs:** right‑click tab menu (set default, open, pin) • per‑tab counts (already present as badges).
- **End‑to‑end:** every visible action has a wired route + toast; no dead buttons.

> **Known gaps the audit already surfaced** (confirm, then fix): generic `PageHero` instead of golden band; **stats duplicated** (hero stats **and** four `StatCard`s below — collapse to one source); **raw `<table>`** in People + Positions + Departments panes (no sort, no selection); **old `AddEmployeeDialog`** (simple 3‑step `WizardShell`, not the client wizard); **zero context menus**; **no bulk actions**; export uses a fragile hand‑built `<form>` POST to `/hr/import-export/export` (no toast/feedback); **no server‑side sort**; **no resend‑invite** path (store() creates the account but there's no re‑invite route).

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero** — copy the gradient treatment from `resources/js/components/hr/my-hr-hero.tsx`: `HERO_STYLE` (the `linear-gradient` + `--primary` token + `boxShadow`), `HeroStat` (label + big tabular value, clickable), `QuickAction` (icon + label). **Refactor these three into a tiny shared `resources/js/components/hr/hero-kit.tsx`** so My HR and People share one hero spine (the standardisation win), then build `PeopleHero` on top (§B). Tokens: `--primary`, `--primary-foreground`, `--category-hr`. The generic `PageHero` from `@/components/page` stays available for fallback, but People gets the **golden band**.

**2.2 Modals / wizards** — `@/components/wizard/primitives`: `Field`, `FieldErr`, `Segmented`, `ChipMulti`, `SelectInput`, `StepHead`, `SubHead`, `InfoCard`, `TilePicker`, `Ring`, `IconType`, `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_TRACK_CLASS`, `WIZARD_PROGRESS_BAR_CLASS`, `WIZARD_FOOTER_CLASS`. **Reference implementation to clone: `resources/js/components/clients/add-client-dialog.tsx`** (shell shape, stepper rail w/ completeness, `validateStep`, `stepForError`, Save & add another, `SuccessPane`). Base shadcn in `@/components/ui/`: `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`.

**2.3 Right‑click menus + hover actions** — the app already ships **five** context‑menu implementations; reuse the pattern, don't invent one. Closest references: `@/components/rostering/shift-context-menu` (`ShiftContextMenu`, `ShiftCtxItem`, `ShiftCtxState` — portal‑rendered, viewport‑flipping, Esc/outside‑click close, icon+label+`kbd`+tone) and `@/components/emar/mar/dose-context-menu` (`DoseContextMenu`, wired via `onContextMenu={(e) => onCtx(e, row)}`). Also: `@/components/operations/dashboard/context-menu`, `@/components/checklists/context-menu`. Build a `PeopleContextMenu` in the same mould.

**2.4 Tables / cards / states / badges** — **`@/components/ui/status-badge` (`StatusBadge`) everywhere** instead of re‑mapping status colours by hand (the page currently hand‑maps `TYPE_STYLES`/`STAT_COLORS` — replace). `@/components/ui/card`, `table`, `empty-state` (`EmptyState`), `error-state`, `loading-state`, `skeleton-table`, `@/components/ui/laravel-pagination` (already used), `@/components/ui/checkbox` (row select). If a shared sortable DataTable exists, use it; otherwise upgrade the existing `<table>` with sortable `<th>` buttons + a selection column (see §F).

**2.5 Tokens & flourishes** — tokens only in `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--shadow-hero`/`--shadow-float`. Use Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`. **Toasts: sonner** (`<Toaster>` is mounted in `resources/js/app.tsx`) — `toast.success/error` on **every** action. Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards.

---

## B. Hero rethink — the golden band (NO clock)

**Current:** generic `PageHero category="hr"` with icon + title + description + 4 stat pills + Add/Export buttons. Flat; doesn't match the My HR golden band; and its 4 stats are **duplicated** by the four `StatCard`s rendered again below the tabs.

**Do:** build a **`PeopleHero`** (in `resources/js/components/hr/people/people-hero.tsx`) using the **same gradient + `HeroStat` + `QuickAction` language as `my-hr-hero.tsx`**, sized to fit this page's content. **No clock card** — this is an admin/manager list, not self‑service. Compose:

- **Left column:** title **"People"** + one‑line context ("Manage your workforce — {total} people across {site count} sites"). Optional small icon medallion (`Users`).
- **Glanceable `HeroStat`s** (workforce, not personal): **Active**, **New hires (30d)**, **On probation**, **Compliance alerts** — each click‑filters the People table or deep‑links (Compliance alerts → `/hr/compliance`, as today). Use `--hr-amber` for the alerting stat like the My HR hero does for open actions.
- **`QuickAction`s:** **Add employee** (opens the new wizard, §D), **Import**, **Export**, **Invite** (resend logins). Gate by `can.manage`.
- **Live alert badges** (drill‑down popover, like `my-hr-hero` "needs you" chips): "{n} compliance items expiring", "{n} on probation ending this month", "{n} pending invites". Reuse the chip pattern from `my-hr-hero.tsx`.
- **Right column (where My HR puts the clock):** since there's **no clock**, fill it with a page‑appropriate cluster — a **headcount/employment‑type mini‑donut** (reuse `summary.type_counts`) or a **compliance ring** (`Ring` from wizard primitives). This keeps the band balanced without a clock.

**Then delete the duplicated `StatCard` grid** below the tabs (the hero now owns those numbers), and keep the employment‑type breakdown as a slim strip only if it adds value beyond the donut.

---

## C. Tab‑by‑tab (apply the global pattern to each)

> Per tab: replace ad‑hoc `<table>` with the shared table/list + **sortable headers** + `StatusBadge`; add a **real empty state** (icon + line + CTA) and a **skeleton**; move every create/edit into a **wizard dialog** (§2.2); add a **right‑click menu** (§2.3) on each row; **toast** every result.

1. **People** — the main table. Add **column sort** (name, employee #, position, department, type, site, start date, status), **multi‑select + bulk‑action bar** (§F), **right‑click row menu** (§E), photo avatars (use `profile_photo_path`, fall back to initials), status + type via `StatusBadge`. Keep filters but move them into a clean toolbar (search debounced, not Enter‑only; chips show active filters with one‑click remove). Empty state via `EmptyState`.
2. **Directory** — `directory-pane.tsx` already has grid/list toggle and avatars; restyle to match, add search within directory, add the same right‑click actions, and confirm it reads the **same** `profiles.data` source (it does). Add an empty state.
3. **Positions** — `positions-pane.tsx` uses a raw `<table>`; upgrade to sortable + `StatusBadge` (active/inactive), show vacancies/headcount clearly, right‑click → Edit / Duplicate / Deactivate. Create/Edit already opens `PositionDialog` → **restyle `PositionDialog` to the wizard‑primitives shell** so it matches. Writes go to the existing `positions.*` routes (`routes/hr.php:583`).
4. **Departments** — `departments-pane.tsx` (raw `<table>`) → sortable + `StatusBadge`, manager/parent columns, employee counts, right‑click → Edit / Deactivate / Delete. Create/Edit via `DepartmentDialog` → restyle to wizard shell. Writes go to existing `departments.*` routes (`routes/hr.php:999‑1002`).
5. **Org chart** — `people/org-chart-pane.tsx`: add expand/collapse all, zoom, search‑to‑highlight, and **reassign manager** inline (the route exists: `PUT /hr/orgchart/{profile}`, `orgchart.update`). Add print/export. Right‑click a node → View profile / Reassign manager / Add direct report.

---

## D. The Add‑Employee modal = exact Add‑Client wizard pattern

**Replace** the current `add-employee-dialog.tsx` (simple `WizardShell`, 3 steps Person→Job→Review) with a modal that **clones `resources/js/components/clients/add-client-dialog.tsx`**:

- Same **full‑height bespoke shell**: `Dialog` with `[&>button]:hidden`, `flex h-[min(92vh,860px)]`, **left stepper rail** (`w-[248px]`, `bg-sidebar`) with per‑step icons + blurbs + check‑on‑complete, a **profile‑completeness meter** at the rail's foot, a header "Step X of N", a **top progress bar**, scroll‑contained body, and a footer with Back / Cancel / **Save & add another** / Create.
- Same **engine**: Inertia `useForm`; client‑side `validateStep(key, data)` per step; `stepForError(field)` to jump to the step that owns a server error; `SuccessPane` after create; `resetAll()` for Save & add another.
- Built from **`@/components/wizard/primitives`** (`Field`, `Segmented`, `ChipMulti`, `SelectInput`, `TilePicker`, `StepHead`, `SubHead`, `InfoCard`, `Ring`). Use `TilePicker` for employment type, `SelectInput`/`PeoplePicker` for manager, `Segmented` for status, etc.

**Proposed steps (intake‑grade, not just quick‑add):**

1. **Person** — photo, full name, preferred name, work email, work phone. (Maps to existing `name`, `preferred_name`, `email`, `work_phone`.)
2. **Job** — access role (`SelectInput`), position (`SelectInput`), employment type (`TilePicker`), department, primary site, start date, reports‑to (`PeoplePicker`).
3. **Right‑to‑work & compliance (optional)** — work‑rights status, visa type/expiry (these fields exist on `HrEmployeeProfile` and on the edit page already). Helpful for NZ supported‑living onboarding.
4. **Emergency contact (optional)** — name, relationship, phone (fields exist on the profile/show).
5. **Review & create** — `ReviewCard`s per section with edit‑jump; **Save & add another** + **Create**.

**Backend to match (see §G):** `store()` + `StoreEmployeeRequest` currently accept only the step‑1/2 fields. If steps 3–4 collect data, **extend `StoreEmployeeRequest` rules and `store()`** to persist them (all nullable/optional so quick‑add still works). Keep the wizard's `forceFormData` for the photo upload, mirroring the client modal.

> Wire it from `index.tsx` exactly like today (`formData ? <AddEmployeeDialog … />`), and open it from the hero **Add employee** quick action.

---

## E. Right‑click everywhere (rows **and** tabs)

Chane explicitly wants right‑click options "under tabs etc." Build a `PeopleContextMenu` (mould of `ShiftContextMenu`) and wire `onContextMenu` on:

- **People rows:** View profile · Edit · Message/email · Start onboarding · Manage compliance · **Deactivate/Reactivate** · Copy email · Open in profile. Gate destructive items by `can.manage`; show `kbd` hints.
- **Positions / Departments / Org‑chart rows/nodes:** context‑appropriate Edit / Duplicate / Deactivate / Delete / Reassign.
- **The tab strip itself:** right‑click a tab → **Set as default view**, **Open**, **Pin**, (and once §F lands) **Save current view**. Persist "default tab"/pins to `localStorage` (allowed) so it survives reloads.

Every menu action fires a toast and, where it writes, hits a real route (§G). No dead items.

---

## F. Table upgrade + bulk actions

Turn the People table into a proper admin grid (reuse the shared table primitives; don't hand‑roll a grid lib):

- **Selection column** (`@/components/ui/checkbox`) with header select‑all (page‑scoped) and a "select all N matching filters" affordance.
- **Sortable headers** — clicking a `<th>` toggles sort; reflect via `sort` + `dir` query params (server‑side, §G) so it works across pagination.
- **Bulk‑action bar** (appears when ≥1 selected, sticky above the table): **Deactivate / Reactivate**, **Assign site**, **Assign department**, **Assign manager**, **Export selected**, **Resend invite**. Each posts to the bulk endpoint (§G), shows a confirm via `alert-dialog` for destructive ops, then toasts the count.
- **Column chooser + density toggle** (dropdown‑menu), persisted to `localStorage`.
- Replace the **fragile export `<form>`** with a proper download (respect active filters, and "selected only" when rows are checked) + a toast.

---

## G. Backend work summary (end‑to‑end check)

**Exists & wired — keep using:**

- People list/show/edit/update/store, `GET/POST/PUT /hr/people…` (`routes/hr.php:204‑216`); `StoreEmployeeRequest` / `UpdateEmployeeProfileRequest`.
- Positions CRUD: `positions.*` route group (`routes/hr.php:583`).
- Departments CRUD: `departments.*` (`routes/hr.php:999‑1002`).
- Org chart: `GET /hr/orgchart`, **`PUT /hr/orgchart/{profile}`** (`orgchart.update`, `:602‑603`).
- Export module: `/hr/import-export/export` (currently hit via raw form).

**Missing — build (spec → confirm → implement, gated `hr.employees.manage`, respect `ResolvesHrTenant`):**

1. **Server‑side sorting** on `index()` — accept `sort` (whitelist: name, employee_number, position_title, department, employment_type, start_date, is_active) + `dir` (asc/desc); default `name asc`. Feeds §F.
2. **Bulk endpoint** — `POST /hr/people/bulk` `{ action, ids[], payload }` where `action ∈ {deactivate, reactivate, assign_site, assign_department, assign_manager, export}`. Precedent: leave already has `bulk-approve` / `bulk-decline` (`routes/hr.php:309‑310`) — mirror its shape/validation. Return a count for the toast.
3. **Resend invite / send login** — `POST /hr/people/{profile}/invite`. `store()` creates the user with a random password + `approved_at`; there is **no** re‑invite path today. Add one (and surface "pending invite" status if the user has never signed in).
4. **Per‑person quick status** — `POST /hr/people/{profile}/deactivate` + `/reactivate` (or fold into the bulk endpoint with a single id) so the row right‑click and bulk bar share one code path.
5. **Expanded `store()`** — *only if* the wizard collects steps 3–4 (§D): add nullable rules to `StoreEmployeeRequest` (`work_rights_status`, `visa_type`, `visa_expires_at`, `emergency_contact_*`) and persist in `store()`. Keep all optional so quick‑add still works.
6. **(Phase 2, confirm first) Saved views** — persist named filter/sort/column segments per user (small table or JSON on the user). Drives the tab right‑click "Save current view".

> For each missing item: write a short spec + migration (if any) and **confirm before building**. Don't silently invent schema.

---

## H. Premium polish & delight

- **Avatars** with real photos (`profile_photo_path`) and coloured initials fallback (already have `getAvatarColor`).
- **Toasts with personality** on every create/bulk/status action (sonner).
- **Hover lift** on rows/cards, subtle `animate-in` on tab/pane switches, progress `Ring` in the hero — all `motion-reduce`‑safe.
- **Keyboard:** `/` focuses search, `n` opens Add Employee, arrow/Enter on rows, Esc closes menus/dialogs.
- **Loading/empty/error**: every tab gets `skeleton-table` while loading and a friendly `EmptyState` (icon + line + primary CTA) when empty — no bare "No employees found" row.
- **Consistency sweep:** all status/type pills via `StatusBadge`; remove hand‑rolled `TYPE_STYLES` / `STAT_COLORS` / `AVATAR_COLORS` colour maps in favour of tokens/`StatusBadge`.

---

## I. Definition of done

- `/hr/people` hero is the **golden HR band** (gradient, `HeroStat`s, `QuickAction`s, live alert badges) — **no clock** — visually on par with `my-hr-hero`; the duplicated `StatCard` grid is gone.
- Every tab uses shared table/list + **sortable** headers + `StatusBadge` + **real empty state** + **skeleton**; **no raw colour maps**, no raw hex.
- **Add Employee** is the client‑grade wizard (stepper rail + completeness + per‑step validation + server‑error→step + **Save & add another** + SuccessPane), built on `@/components/wizard/primitives`. `PositionDialog` and `DepartmentDialog` restyled to the same shell.
- **Right‑click menus** on People/Positions/Departments/Org‑chart rows **and** on the tab strip; every item wired + toasted; `kbd` hints shown.
- **Multi‑select + bulk‑action bar** (deactivate/reactivate, assign site/department/manager, export selected, resend invite), backed by the new bulk endpoint; destructive ops confirm via `alert-dialog`.
- **End‑to‑end:** sorting, bulk, invite, and quick‑status all hit real routes; export is a clean download with a toast (not a hand‑built form); **no dead buttons**.
- NZD / `en-NZ` retained; `ResolvesHrTenant` scoping and `hr.*` gates respected; **no regressions** to `/hr/people/{id}` profile, the Positions/Departments/Org‑chart controllers, or the import‑export module.
- Clean `build`, `types`, `lint`; screenshots of each tab match the reference pages.

**Build order:** §A audit (paste results) → **PeopleHero** (golden band, drop duplicate stats) → table upgrade + **right‑click** + `StatusBadge`/empty/skeleton across tabs (§C/§E/§F frontend) → **Add‑Employee wizard** (§D) → restyle Position/Department dialogs → **backend** sort + bulk + invite + quick‑status (§G) → wire bulk bar to endpoints → delight pass (§H). Verify each pass against the reference pages.
