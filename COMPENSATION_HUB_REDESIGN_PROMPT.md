# HR "Compensation & Benefits" Hub Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent (Claude design — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot the surface you changed (`/hr/compensation/bands` and `?tab=` / each modal open) **and** the self-service surfaces it feeds (`/hr/my` expenses, `/my-day`), and diff against the gold-standard pages/components before continuing. Start with the audit in §A, then build §B–§L. **Anything you discover that needs backend/data work goes into §K "Backend handoff for Claude Code" — append to it as you go (and into `docs/compensation-hub-redesign/PROGRESS.md`) so Chane has one clean hand-off list when the design is done.**

**Page:** `https://oblivionfindings.com/hr/compensation/bands` (this is the hub entry; the tab strip is the spine of the whole job)
**Frontend (hub):** `resources/js/pages/hr/compensation/{bands,reviews,review-detail,bonuses,history}.tsx` · `resources/js/pages/hr/compensation/benefits/{index,plans}.tsx` · `resources/js/pages/hr/compensation/expenses/{index,create,show}.tsx` · **tabs:** `resources/js/components/hr/compensation-tabs.tsx`
**Frontend (self-service it feeds):** `resources/js/pages/hr/my/expenses.tsx` (thin inline claim form) · `resources/js/pages/my-day/*` (where a worker logs their day) · `resources/js/pages/hr/employees/show.tsx` (the People profile — must surface comp history)
**Backend:** `app/Http/Controllers/Hr/{CompensationController,BonusController,BenefitsController,ExpenseController}.php` · `app/Http/Controllers/Hr/MyHrController.php` (`expenses`/`submitExpense`) · `app/Http/Requests/Hr/StoreExpenseClaimRequest.php` · routes in `routes/hr.php` (`:89-90` self-service, `:654-711` hub)
**Engine:** `app/Domain/Hr/Services/{CompensationService,BenefitsService,ExpenseService}.php` · `app/Domain/Finance/Services/ExpenseJournalService.php` · `app/Domain/Finance/Jobs/PostExpenseJournalJob.php`
**Models:** `HrSalaryBand`, `HrCompensationReview`, `HrCompensationReviewItem`, `HrCompensationHistory`, `HrBonusPayment`, `HrBenefitPlan`, `HrBenefitEnrollment`, `HrExpenseClaim`, `HrExpenseItem` (all `app/Domain/Hr/Models/`)
**Config (the "fee place"):** `config/finance.php` — `mileage_rate_per_km` (`:304`, `0.95`, IRD Tier 1) and `event_accounts` GL map
**Permissions:** `hr.compensation.{view,manage}` · `hr.benefits.{view,manage}` · `hr.expenses.{view,manage,approve}` (`database/seeders/SeedHrPermissionsSeeder.php:28-60`)
**Gold-standard to clone:** create/edit wizard → `resources/js/components/clients/add-client-dialog.tsx` on `resources/js/components/wizard/{shell,primitives}.tsx` (re-exported via `resources/js/components/hr/wizard.ts`) · warm modal feel → `resources/js/components/hr/leave-request-dialog.tsx` · golden hero → `resources/js/components/hr/my-hr-hero.tsx` (drop its clock `my-hr-clock-card.tsx`) · right-click → `resources/js/components/hr/leave-context-menu.tsx`

---

## 0. Mission

Turn the **Compensation & Benefits hub** (entry: `/hr/compensation/bands`) into a **premium, end-to-end, standardised surface** that feels identical in quality to our gold-standard pages — **`/hr/people`**, **`/hr/leave`**, **`/meds/today`**, **`/my-day`** — and reuses their exact components and tokens. The hub is six surfaces today (Salary bands · Pay reviews · Bonuses · Benefits · Expenses · per-employee History) wired by a thin tab strip. They are inconsistent, mostly **read-only or thin-modal**, several are **orphaned**, and the data model can support far more than the UI exposes.

Today the hub is dated and uneven:
- **Generic `PageHero`** on bands (not the golden band), and **chrome drift** between tabs (bonuses uses a raw `<table>` + `PageShell`; everyone else uses the shared `<Table>` + `PageLayout`; filters are free-text vs `Select` vs pill-buttons depending on the tab).
- **Thin single-step `Dialog` forms** everywhere a real guided workflow belongs (bands create/edit, bonus record, benefit enroll/edit, plan create) — the opposite of the Add-Client wizard.
- **Edit-pencil-only rows** — no detail view, no delete, no duplicate, no right-click menus, no bulk actions, no export on any tab.
- **Hero stats that lie** — nearly every "Total / Active / Pending" counts `…data.length` (the current paginated page ≤20), not the true total.
- **Two orphaned surfaces**: Benefit **Plans** (`/hr/compensation/benefits/plans`) is reachable only by typing the URL, and per-employee **Compensation History** (`/hr/compensation/history/{profile}`) has **zero inbound links** anywhere in the app and isn't a tab.
- **Salary bands are decorative** — `CompensationService::getSalaryBandForRole()` has **zero callers**; bands are never used to validate a proposed salary, place an employee, or compute compa-ratio. The most important number in a comp tool (where does this person sit in their band) doesn't exist.
- **The expense claim flow is fragmented** across four parallel systems with three different per-km rates and three status vocabularies (§H), and the self-service path on `/hr/my` **dead-ends** (creates a draft, no submit).
- **Two real bugs**: applying a pay review writes the **annual** salary figure into the employee's **hourly_rate** (data corruption — §K-1), and approving an expense **double-posts to the GL** (§K, document-only).

Bring it to parity: the **golden HR hero band (no clock, fitted to compensation)**, a **standardised tab strip with right-click menus**, every create/edit/claim/enroll/adjust flow swapped to the **exact Add-Client wizard** with the **warm Leave-modal feel**, **right-click menus on tabs + rows**, a **de-orphaned History surfaced on the People profile**, salary bands wired into **real band-placement analytics**, and **one unified premium expense-claim modal** reused on Compensation, My HR and My Day — with a **mileage line type that reads the IRD rate from config** (the "fee place"). Result: a comp hub that is **accurate, glanceable, premium and joined-up** — not six grey tables.

---

## 1. Non-negotiables

1. **One standardised tab spine.** Keep the six surfaces as tabs in `CompensationTabs` (`resources/js/components/hr/compensation-tabs.tsx`) but bring **History** and **Benefit Plans** into the model so nothing is orphaned (§C). Reflect the active tab in a `?tab=` deep-link, per-tab count badges, and a **right-click tab menu** (set default, open, pin) — same tab language as `/hr/people`. **Propose the final tab set + ordering to Chane in the §A audit and get sign-off before building.**
2. **Reuse the kit — never hand-roll a primitive we already have** (§2). Every hero, modal, badge, status colour, context menu, empty state and toast comes from the shared kit. **No new bespoke widgets, no raw hex** (ESLint blocks it — colours come from design tokens in `resources/css/app.css`).
3. **Information-gathering = full wizard modals, never thin dialogs.** Every create / edit / record / enroll / adjust / claim / approve-with-notes flow becomes a **multi-step wizard dialog cloning the Add-Client shell** (`WizardShell` + `useWizard`, §2.2) with the **warm Leave-modal treatment** (identity tiles, live preview/summary, review step, success pane — §2.3). **No single-step `Dialog` forms. No inline forms. No "thin" workflows.** Reading a record's detail/history may use a dialog/sheet.
4. **The golden hero, no clock.** Build a `CompensationHero` on the `my-hr-hero.tsx` gradient + `HeroStat` + `QuickAction` language, **fitted to compensation content** and **with the clock (`my-hr-clock-card.tsx`) and the te-reo greeting/needs-you self-service furniture removed** — this is a manager/admin lens, not self-service (§B).
5. **Salary bands must become load-bearing.** Wire `getSalaryBandForRole` into pay reviews, bonuses and the People profile so every proposed/current salary shows its **band placement (compa-ratio, position in range, in/under/over band)**. A comp hub whose bands aren't referenced anywhere is the core gap to close (§D, §E).
6. **One expense-claim modal, reused everywhere, with the mileage "fee".** Build a single premium `ExpenseClaimDialog` wizard and mount it on **Compensation Expenses**, **My HR** (`/hr/my`) and **My Day** — replacing the full-page `create.tsx` and the thin inline `hr/my/expenses.tsx` form. Add a **mileage line type** that captures `distance_km × rate` with the **rate auto-filled from `config('finance.mileage_rate_per_km')`** (0.95, IRD Tier 1) — the same UX as `fleet-assets/mileage/create.tsx`'s calculation card. **Do not** touch the Operations or Fleet mileage systems or the GL posting in this pass (those are flagged for finance in §K) — just stop the HR expense flow from hardcoding rates and give it the fee picker (§H).
7. **Web-only desktop app.** No phone frames, **no clock** in the hero. Design for mouse + keyboard: hover states, **right-click menus**, keyboard shortcuts. Responsive down to a small laptop is fine. (A dedicated mobile app comes later — not now.)
8. **Locale & statute stay NZ.** NZD / `en-NZ` formatting and dates. KiwiSaver employee rates (3/4/6/8/10%) and employer minimum drive the benefits flow; mileage uses the **IRD** rate from config; comp is hourly **and** annual (this is a support-worker workforce — most are waged). Do **not** switch to GBP/US.
9. **Respect scoping & permissions.** Everything tenant-scoped via `ResolvesHrTenant`. Gate every surface and every action by its existing permission (`hr.compensation.*`, `hr.benefits.*`, `hr.expenses.*`); hide manager-only UI when the user lacks the gate (the tab strip already filters by `auth.can.hr.*` — keep that). Money fields stay encrypted where they already are.
10. **End-to-end or it doesn't ship.** Every visible action has a wired route + toast; no dead buttons, no orphan routes, no "current-page-only" stats. If a button needs backend that doesn't exist, **build the route/controller/service** (it's in scope) or, if it's risky/cross-domain, **log it in §K** and disable the control with a tooltip — never leave it dead.
11. **Verify each pass:** clean `npm run build`, `npm run types` (no TS errors), `npm run lint`; screenshot the changed surface; confirm it matches the reference page's hero/modal/menu. Don't move on with a broken pass.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero** — copy the gradient treatment from `resources/js/components/hr/my-hr-hero.tsx`: `HERO_STYLE` (amber CSS vars `--hr-amber`/`--hr-amber-soft` + the 3-stop `linear-gradient` over `--primary` + deep `boxShadow`), `HeroStat` (label + big tabular value, clickable / `href`), `QuickAction` (icon + label). Build `CompensationHero` in `resources/js/components/hr/compensation/compensation-hero.tsx`. **Strip the self-service furniture**: do not import `MyHrClockCard` (`my-hr-clock-card.tsx`), the te-reo greeting, the avatar account popover, or the "needs you" employee strip — those are `/hr/my` concerns. Generic fallback `PageHero`/`PageHeroStats`/`PageHeroQuickActions` live in `@/components/page` — fallback only. Tokens: `--primary`, `--primary-foreground`, `--category-hr`, `--hr-amber`.

**2.2 Modals / wizards (the spine)** — `@/components/wizard`:
- `shell.tsx`: `WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`, type `WizardStep` (`{key,label,blurb,icon}`).
- `primitives.tsx`: `Field`, `FieldErr`, `SubHead`, `StepHead`, `InfoCard` (toned `info|warn|crit`), `SelectInput`, `Segmented`, `ChipMulti`, `TilePicker`, `Ring`, and chrome class constants `WIZARD_RAIL_CLASS` / `WIZARD_PROGRESS_TRACK_CLASS` / `WIZARD_FOOTER_CLASS`.
- HR re-exports the kit + `useWizard(stepCount)` via `@/components/hr` (`resources/js/components/hr/wizard.ts`) — import from there.
- **Reference to clone: `resources/js/components/clients/add-client-dialog.tsx`.** Markers to match: `Dialog`+`DialogContent` with `[&>button]:hidden` and an inline `maxWidth`/`maxHeight`; a **248px stepper rail** (`<aside>` on `bg-sidebar`); a sticky header ("Step X of Y · {label}" + custom close); a **3px progress strip**; a scrollable body; a **muted footer band**; a `STEPS` array; per-step `validateStep()`; server-error→step routing (`STEP_FOR_PREFIX` + `stepForError()` on `onError`); a **completeness meter** pinned to the rail foot (`Ring` on the review step); **"Save & add another"** on the review step in create mode (calls `resetAll()`); and a `WizardSuccessPane` on success. Submit via Inertia **`useForm`** with `forceFormData` when files are involved. For employee pickers reuse `@/components/hr/people-picker` (`PeoplePicker`, `PersonOption`).

**2.3 The "warm" feel (Leave modal)** — `resources/js/components/hr/leave-request-dialog.tsx` is the premium bar built **on top of** the same `WizardShell`. Lift these touches into the comp modals where they fit:
- **Identity tiles instead of dropdowns** (`TILE_META` → `{icon, accent, sub}`, selected tile gets a `color-mix` tint + ring glow) — perfect for **bonus type**, **leave/expense category**, **benefit plan**, **review cycle**.
- **A live summary/preview card** that updates as the user fills the form (Leave shows hours + working-days; for comp show **band placement / compa-ratio**, **change %**, **claim total**, **KiwiSaver cost**). Debounce any server preview ~280ms.
- **A persistent rail extra** (Leave's `BalanceCard`) — use the rail to show context: the employee's current comp + band, the plan's rates, the running claim total.
- **A review step that's a hero summary card** (gradient header, the type's icon in a shadowed tile, key facts as `HeroRow`s, an inline "Edit" pencil that jumps back) + an approver line ("Goes to {approver} · usually approved by {date}").
- **Premium footer buttons** with the colored glow `boxShadow` + `ArrowRight`; **success micro-interaction** (`toast.success(...)` + optional `fireConfetti()` from `@/lib/confetti` for employee-facing submits) then a `WizardSuccessPane`.

**2.4 Tabs + right-click** — `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab(defaultTab,{param,syncUrl})`) wrapping `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`: `role="tablist"`, arrow/Home/End roving, **`onItemContextMenu`**, toned chips `primary|warning|success|info|violet|critical`, count badges). `compensation-tabs.tsx` already uses `HrTabs` — extend it for counts, `?tab=` deep-link and the right-click tab menu. **There is no Radix `ContextMenu` primitive in the repo** — right-click menus are a bespoke portal `FloatingMenu`. Clone **`resources/js/components/hr/leave-context-menu.tsx`** (`useLeaveContextMenu` → `{ open(items)=>(e)=>void, element }`, fixed at cursor, viewport-nudged, closes on outside-click/Esc/scroll/resize, arrow-key roving, item shape `divider | {label,icon,onSelect,tone,kbd}`) into a `useCompensationContextMenu`. Wire `onContextMenu={(e)=>open(itemsFor(row))(e)}` on every row.

**2.5 Cards / states / badges** — **`@/components/ui/status-badge` (`StatusBadge`) everywhere** for statuses (draft/submitted/approved/paid/rejected; planning/approved/applied; pending/approved; active/opted_out) and type chips — do not hand-map colours (kill the bonuses page's hard-coded `$` and bespoke status map, the expenses pages' three separate `statusConfig` objects). Also `@/components/ui/card`, `avatar`, `badge`, `empty-state` (`EmptyState`, `EmptyList`, `EmptySearch`), `error-state`, `loading-state`, `skeleton-card`, `@/components/ui/laravel-pagination`, `@/components/ui/table` (retire the raw `<table>` in bonuses).

**2.6 Tokens & flourishes** — tokens only in `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`, `--shadow-hero`/`--shadow-float`. Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`. **Toasts: sonner** — `toast.success/error` on **every** action. Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`) with `motion-reduce:*` guards. Currency via `Intl.NumberFormat('en-NZ',{style:'currency',currency:'NZD'})`; dates `en-NZ`.

---

## A. Audit & benchmark first (do this before building)

Study `/hr/people`, `/hr/leave`, `/meds/today`, `/my-day` and **interact** with them — they are the parity bar. Then study the three patterns you must clone (§2.2–2.4). Then audit the hub **and** the self-service expense surfaces against this **best-in-class compensation/benefits/expenses checklist** (mark each **Present / Partial / Missing**, then close gaps in §B–§L).

**Benchmarks:** comp & bands — **Pave, CompTeam, Payscale, Mercer, Carta** (band ranges, compa-ratio, range penetration, band overlap, market position, merit-matrix). NZ payroll/benefits — **Employment Hero, PayHero, Gusto, Deel** (KiwiSaver, leave+comp+payroll sharing live data). Expenses — **Expensify, SAP Concur, Ramp, Payhawk, Pleo** (one claim flow, mileage at a configured rate, receipt OCR, approval inbox, policy caps, reimbursement export).

**Checklist (fill this in as the first pass and paste back the results):**

- **Hero:** golden brand band, **no clock** • comp stats that matter (people in band / out of band, bands active, reviews in flight, pending approvals across bonuses+expenses+benefits, total monthly reimbursements) • quick actions (New band / Start pay review / Record bonus / New claim / Export) • live alert badges (awaiting **my** approval, out-of-band employees, claims overdue) with drill-down.
- **Tabs:** standardised `CompensationTabs` with per-tab counts, **right-click tab menu**, `?tab=` deep-link, History + Plans no longer orphaned.
- **Salary bands:** band list with **range bar visualisation** (min–mid–max), **# employees in each band**, **compa-ratio / range-penetration**, **band-overlap detection**, effective-dating with **supersede** (not just edit), duplicate, archive, detail drawer, **right-click row menu**, filter (role LIKE not exact), export. The create/edit becomes a **wizard** with min ≤ mid ≤ max validation and hourly⇄salary sanity.
- **Pay reviews:** the one genuinely guided surface today — keep the multi-line builder but make it a **wizard**; show **band placement per line** (current vs proposed vs band), **budget vs sum-of-proposed** running tally with over-budget warning, **per-item approve/reject**, **reject-review** path, edit-after-create, and a link from each line to that employee's **History**.
- **Bonuses:** full lifecycle (pending → approved → **paid**/**cancelled** — both currently unreachable), record via **wizard** showing the employee's current comp + band, optional **link to a pay review**, **mark-paid / cancel**, confirm dialogs, shared `Table` + `StatusBadge`, true totals.
- **Benefits:** **Plans becomes a real tab** (or a clearly-linked sub-view), **plan edit** (today only `is_active` is mutable — all other fields are write-once), enroll/edit via **wizard** with **KiwiSaver rate presets + employer-minimum validation + cost preview**, **opt-out** as a guided state change capturing date + reason (the model has `opt_out_date`; the UI omits it), surface the **per-plan average contribution rates** the service already computes, enrollee drill-down, export.
- **Expenses:** **one premium claim wizard** (multi-item, per-item receipt, categories, **mileage line type at the configured IRD rate**) reused on **Compensation + My HR + My Day**; an **approvals inbox** (not "filter the list"); **receipt view/download** (today only an "Attached" badge, no link); **edit/withdraw a draft**, **add item to a draft**; **manager files on behalf of an employee**; true totals; export. Self-service must **submit**, not dead-end.
- **History:** **de-orphan it** — link from the People profile (`hr/employees/show.tsx`) and from each pay-review line; add a gated **"Record change"** wizard (promotion / adjustment / correction / initial — all dead enum values today) so history isn't only ever written by applying a review.
- **End-to-end:** every action wired + toasted; no dead buttons (the bonuses "Approve" with no confirm, the benefits activate toggle with no confirm, the expense "Mark Paid" gated on GL posting); stats are **true totals**, not page slices.

> **Known gaps the audit already surfaced** (confirm, then fix or hand off):
> - **Bands are decorative.** `CompensationService::getSalaryBandForRole()` (`:46-52`) has **zero callers**; `HrSalaryBand` is referenced only by its own controller. No compa-ratio, no "employees in band", no overlap. Money fields are **encrypted** (`HrSalaryBand` casts) so band analytics must be computed in **PHP**, not SQL.
> - **`updateBand` is weaker than `storeBand`:** store requires `effective_to` `after:effective_from` (`CompensationController.php:70`); update only checks `date` (`:100`) — update can set an end date before the start. **Fix (§K-3).**
> - **Pay-review apply corrupts the hourly rate.** `CompensationService::applyCompensationReview()` (`:131-134`) maps `proposed_salary` into **both** `new_hourly_rate` **and** `new_annual_salary`; the review UI only ever collects an **annual** "Proposed Salary" (`review-detail.tsx:416-432`). So every applied review writes the annual figure into the employee's hourly_rate, and every History row's hourly column is wrong. **Fix (§K-1) — highest priority.**
> - **Dead statuses/enums** (wire up or remove): review `in_progress`; review-item `rejected`; bonus `paid`/`cancelled`; history `initial`/`promotion`/`adjustment`/`correction`. Bonus `payroll_run_id` + `payrollRun()` relation are never set (the bonus→payroll→paid bridge is unbuilt).
> - **Two orphans:** Benefit **Plans** (`benefits/plans.tsx`) is not a tab and has **no inbound link**; **History** (`history.tsx`) has **zero inbound links** app-wide and the People profile (`hr/employees/show.tsx`) has **no comp section at all**.
> - **Hero stats lie:** bands "Bands", reviews "Reviews", benefits "Active", expenses "Submitted/Approved/Value" all count `…data.length` (page ≤20). Only bonuses "Total" and benefits "total enrolled" are true. **Fix by passing real aggregates from the controllers (§K).**
> - **Under-surfaced backend:** `BenefitsService::getEnrollmentSummary` computes `avg_employee_rate`/`avg_employer_rate` (`:65-70`) — UI shows only `total_enrolled`. `ExpenseService::addItem` exists but has **no route**. `BenefitsService::updateKiwiSaverRate` is dead. `updatePlan` accepts only `is_active`.
> - **Expense fragmentation + double GL post** — see §H and §K (document-only): on approval, both `HrExpenseClaimObserver` (DR 6500) and `ExpenseService::approveClaim → PostExpenseJournalJob` (per-category 6100/7010/…) fire, posting the same claim twice to different accounts. **Do not fix in this pass — log for Finance.**
> - **Chrome drift:** bonuses uses `PageShell` + a raw `<table>` + hard-coded `$`; everyone else uses `PageLayout` + `<Table>`. Filters drift (free-text role vs `Select` vs pills). Standardise.

---

## B. Hero rethink — the golden band (NO clock, fitted to compensation)

**Current:** `bands.tsx` uses `PageHero category="hr" icon={DollarSign}` with a single page-only "Bands" stat; reviews/bonuses/benefits/expenses each have their own slightly different hero. Not the golden band, not consistent.

**Do:** build **`CompensationHero`** (`resources/js/components/hr/compensation/compensation-hero.tsx`) on the `my-hr-hero.tsx` gradient + `HeroStat` + `QuickAction` language, sized to this hub. **No clock, no greeting, no needs-you employee strip.** Render it through `PageLayout hero={…}` above the tab strip on **every** hub page so the band is identical across tabs (only the stats/actions change per tab, or keep one hub-level hero with tab-aware stats).

- **Left column:** title **"Compensation & Benefits"** + one-line context ("Keep {tenant} pay fair, on-band and paid on time"). Icon medallion (`DollarSign` / `Layers`).
- **Glanceable `HeroStat`s** (each click-filters or deep-links a tab; tabular figures; amber `--hr-amber` when an attention number is non-zero): **People out of band** (→ Bands, computed via band placement) • **Reviews in flight** (planning+approved → Reviews) • **Awaiting my approval** (bonuses + expenses + benefit changes needing a decision → relevant tab) • **Reimbursements this month** (paid+approved claim value → Expenses).
- **`QuickAction`s** (gated): **New band** • **Start pay review** • **Record bonus** • **New claim** (opens the unified `ExpenseClaimDialog`) • **Export**.
- **Live alert badges** (drill-down popover, like `my-hr-hero` chips): "{n} awaiting **your** approval", "{n} employees over/under band ⚠️", "{n} claims overdue". Reuse the chip + dot pattern (without the self-service framing).
- **Right cluster (where My HR puts the clock):** since there's **no clock**, fill it with a page-appropriate cluster — a compact **band-health `Ring`** (% of employees within band) or a **mini bar** of headcount by band. Persist any toggle to `localStorage` (`hrComp.heroRight`).

---

## C. Tabs — standardise the strip, de-orphan the surfaces

Keep the strip in `compensation-tabs.tsx` (it already gates by `auth.can.hr.*` — keep that), but upgrade it to the full `HrTabs` treatment: **per-tab count badges**, **`?tab=` deep-link** (via `useHrTab`), **right-click tab menu** (set default / open / pin, persisted to `localStorage`), and bring the orphans into the model. **Propose the final set to Chane in §A and get sign-off.** Recommended set:

1. **Salary bands** (default, §D) — band ranges + placement analytics.
2. **Pay reviews** (§E) — review cycles + the line builder.
3. **Bonuses** (§F).
4. **Benefits** (§G) — with **Plans** as a segmented sub-view or a second-level tab inside Benefits (not a hidden URL). Surface the Plans ⇄ Enrollments link both ways.
5. **Expenses** (§H) — list + **approvals inbox** segment.
6. **History** (§I) — as a hub tab it's a **company-wide comp-change log** (every applied review / bonus / manual change), with the per-employee view reachable from a row or from the People profile. (Confirm with Chane whether History is a primary tab or lives behind a "⋯ More" affordance, since it's more audit than daily flow.)

> Per tab: shared list/card + `StatusBadge` chips; real **empty state** (icon + line + CTA) and **skeleton**; every create/edit/claim flow is a **wizard modal** (§2.2–2.3); every row has a **right-click menu** (§2.4) + hover actions; **toast** every result; true-total stats.

---

## D. Salary Bands — the lead surface (make bands load-bearing)

**Current (`bands.tsx`, 433 lines):** `PageHero` + a Filters card (exact-match role + active-only) + a `<Table>` (Role, Band, Salary Range, Hourly Range, Effective, edit pencil) + a thin 10-field create/edit `Dialog` (`:289-430`). No detail, delete, duplicate, supersede, analytics, or right-click. `storeBand`/`updateBand` only.

**Build:**

1. **Band list, upgraded.** Replace the flat range cells with a **range-bar visual** per row (min — mid — max as a horizontal bar with the mid marked), the **band name + role**, **# employees in this band**, and a **placement summary** (e.g. "6 in band · 1 under · 0 over"). Filter by role as **LIKE** (not exact — `CompensationController::bands` `:33` currently `where('position_role',$role)`), plus active-only and effective-as-of-date. Add **bulk select**, **export (CSV)**, real empty/skeleton states.
2. **Band detail drawer/sheet** (new) — open on row click: the range bar, every employee currently mapped to the band with their **compa-ratio** and an in/under/over flag, effective-dating history (superseded bands), and actions (Edit · Duplicate · Supersede · Archive).
3. **The create/edit becomes a wizard** (`WizardShell`, clone Add-Client). Steps: **(1) Role & band** (position_role via a role picker/autocomplete from existing roles, band_name, currency as a `Segmented` NZD default) → **(2) Ranges** (min/mid/max salary + min/max hourly, with **live validation min ≤ mid ≤ max**, an **hourly⇄annual sanity check** at ~2080 hrs, and an **overlap warning** if the new range overlaps an existing active band for a related role — `InfoCard warn`) → **(3) Effective dating** (effective_from, optional effective_to with the **fixed** `after:effective_from` rule; offer **"Supersede the current band for this role"** which closes the previous band's `effective_to` the day before) → **(4) Review** (range bar preview + "employees who will fall in/out of band" count) → success pane. Keep **"Save & add another"**. Submit via Inertia `useForm`.
4. **Right-click row menu** (`useCompensationContextMenu`): Edit · Duplicate · Supersede · View employees in band · Archive · Copy band range.
5. **Backend to add (§K):** `showBand` (detail payload incl. employees-in-band + compa-ratio, computed in PHP since money is encrypted), `destroyBand`/archive, duplicate, **wire `getSalaryBandForRole`** into a reusable **`bandPlacement(profile)`** helper on `CompensationService` (returns compa-ratio + in/under/over) used here, in reviews (§E) and on the People profile (§I); pass **true totals** to the hero (count of bands, count of out-of-band employees). Tighten `storeBand`/`updateBand` validation to enforce min ≤ mid ≤ max and the date rule.

---

## E. Pay Reviews — keep the builder, make it a wizard, add band + budget intelligence

**Current:** `reviews.tsx` (list, 256 lines) + `review-detail.tsx` (dual-mode create/view, 647 lines — the **one** genuinely guided surface). Create mode builds review header + per-employee adjustment lines (employee select, auto-filled current salary, proposed, auto change %, justification). View mode = status badge + Approve (planning/in_progress) + Apply (approved) + read-only items. **`in_progress` is a dead status; item `rejected` is dead; no edit-after-create, no reject-review, no per-item decision UI, no band context, no budget tally.**

**Build:**

1. **List** → shared `Table` + `StatusBadge`, true total in the hero, status filter as today, **right-click row menu** (Open · Duplicate cycle · Approve · Apply · Export), row click opens the detail.
2. **Convert create into the wizard** (it's already multi-step in spirit): **(1) Cycle** (review_cycle as identity tiles Annual/Mid-year/Ad-hoc, title, effective_date, budget_amount) → **(2) Employees & adjustments** (the line builder — reuse `PeoplePicker`; per line show **current comp + band placement** and flag if the **proposed** salary lands **outside the band** via `bandPlacement`) → **(3) Review** (a **budget vs sum-of-proposed** running tally with an **over-budget `InfoCard crit`**, count of lines pushing employees out of band) → success.
3. **Detail mode upgrades:** keep Approve/Apply but add **per-item approve/reject** (wire the dead item `rejected` status + an endpoint), a **reject-review** path, **edit-after-create** (add/remove/adjust lines while `planning`), and a **link from each line to that employee's History** (§I). Show each line's **band placement** read-only.
4. **Fix the apply bug (§K-1):** apply must write **annual → annual** and **derive hourly** correctly (hourly = annual ÷ contracted annual hours, or carry the employee's existing hourly if the review is annual-only) — never copy the annual figure into hourly. This also corrects History.
5. **Backend (§K):** `updateReview`, `destroyReview`, `rejectReview`, per-item approve/reject endpoints; reconcile the `in_progress` status (use it for "approvals underway" or remove it).

---

## F. Bonuses — full lifecycle, shared chrome, band context

**Current (`bonuses.tsx`, 385 lines):** `PageShell` + a **raw `<table>`** + hard-coded `$`, a single-step "Record Bonus Payment" `Dialog`, an inline **Approve with no confirm**. Backend `BonusController` only does index/store/approve; **`paid` and `cancelled` are unreachable**; `payroll_run_id` never set; no `BonusService`; `amount` is `decimal:2` (not encrypted — inconsistent).

**Build:**

1. **Re-chrome to match:** `PageLayout` + shared `Table` + `StatusBadge` + `Intl` NZD. True totals (Total / Pending / Approved / Paid) from the controller, not page slices.
2. **Record bonus = wizard** (clone Add-Client, warm feel): **(1) Who & what** (`PeoplePicker`; bonus_type as identity tiles performance/signing/retention/spot/holiday/other; showing the employee's **current comp + band**) → **(2) Amount & timing** (amount, payment_date, optional **link to a pay review**, reason) → **(3) Review** (employee, type, amount, approver line) → success.
3. **Lifecycle:** add **Approve** (with a confirm/review modal, not a bare button), **Mark paid** and **Cancel** transitions so `paid`/`cancelled` become reachable; **right-click row menu** (Approve · Mark paid · Cancel · View employee · Copy). Decide with Chane whether "Mark paid" is manual now or deferred to the unbuilt payroll bridge (if deferred, disable with a tooltip and log the `payroll_run_id` bridge in §K).
4. **Backend (§K):** `BonusController@update/destroy/cancel/markPaid` (+ a thin `BonusService` to hold the transitions and an optional review link); pass real aggregates to the hero.

---

## G. Benefits — de-orphan Plans, make plans editable, KiwiSaver-aware enroll

**Current:** `benefits/index.tsx` (enrollments, 700 lines) + `benefits/plans.tsx` (365 lines, **orphan** — not a tab, no inbound link). Enroll + Edit-enrollment are thin `Dialog`s; **Edit omits `opt_out_date`** (controller accepts it `:164`). **`updatePlan` accepts only `is_active`** so plan name/provider/rate/description are write-once; there's **no plan edit modal**. `getEnrollmentSummary` computes per-plan averages that the UI never shows. `updateKiwiSaverRate` is dead.

**Build:**

1. **Bring Plans into Benefits** as a segmented sub-view or a second-level tab; link **Enrollments ⇄ Plans** both ways (enrollee count on a plan → drill to those enrollments; an enrollment's plan → the plan). Surface the **per-plan average employee/employer contribution** the service already returns.
2. **Plan create/edit = wizard** with a **real edit** path (extend `updatePlan` to accept name/type/provider/employer rate/description, not just `is_active`): **(1) Plan basics** (name, type tiles, provider) → **(2) Cost** (employer rate, description, eligibility note) → **(3) Review** → success. Add **plan archive** (keep `is_active` toggle but behind a confirm).
3. **Enroll = wizard, KiwiSaver-aware:** **(1) Employee & plan** (`PeoplePicker` + plan tiles) → **(2) Contributions** (employee rate as **KiwiSaver presets 3/4/6/8/10%** when the plan is KiwiSaver, employer rate defaulting from the plan and **validated against the employer minimum**, enrolled date; a **live cost preview** in the rail) → **(3) Review** → success.
4. **Opt-out as a guided state change** (not a hidden field): a small wizard/confirm capturing **`opt_out_date` + reason**, setting status `opted_out`. Edit-enrollment wizard must expose `opt_out_date`. Add enrollment **detail** + **right-click menu** (Edit · Opt out · View plan · History).
5. **Backend (§K):** extend `updatePlan` fields; add `destroyPlan`/archive and `destroyEnrollment`/proper opt-out; surface summary averages; remove or wire `updateKiwiSaverRate`.

---

## H. Expenses — ONE premium claim modal, reused everywhere, with the mileage "fee"

This is the headline cross-surface job. **Decision (Chane): unify the claim modal + add a mileage rate line; do NOT consolidate the Fleet/Operations mileage systems or touch GL in this pass** (those are logged for Finance in §K).

**Current fragmentation:**
- **HR claims** (`expenses/{index,create,show}.tsx`) — the most complete: full-page `create.tsx` (multi-item, per-item receipt upload, categories from `ExpenseService::CATEGORIES`), lifecycle draft → submitted → approved (→ GL via `PostExpenseJournalJob`) → paid / rejected; approve/reject/pay on the **show** page (inline reject form). Gaps: create requires `hr.expenses.manage` so ordinary staff can't reach it; **no employee picker** (self only); **no receipt view/download** (just an "Attached" badge); **no edit/withdraw/add-item** after draft; `addItem` service method has **no route**.
- **My HR self-service** (`hr/my/expenses.tsx`) — a **thin inline collapsible** form, **4 fields only** (description, category, amount, date), **no receipt/tax/notes**, creates a **draft and dead-ends** (no submit button, no tracking, no link into the HR flow). Same model + `StoreExpenseClaimRequest`, divergent fields.
- **Mileage lives in three other places** (Operations `MileageClaim` at rate **0.97** free-text, Fleet `FleetPersonalTrip` at **0.95** IRD read-only, Timesheet `mileage_km` at `config` rate) — **out of scope to merge here**, but they prove the **canonical rate is `config('finance.mileage_rate_per_km')` = 0.95** (`config/finance.php:304`). The HR expense "mileage" category currently has **no rate** — the amount is typed by hand. **That is the "fee place" to fix.**

**Build one `ExpenseClaimDialog` wizard** (`resources/js/components/hr/expenses/expense-claim-dialog.tsx`, clone Add-Client + warm feel) and **mount it on Compensation Expenses, My HR (`/hr/my`) and My Day**, replacing the full-page `create.tsx` and the thin `hr/my/expenses.tsx` inline form with the **same component** (a `mode`/`onBehalf` prop toggles the employee picker for managers vs. self for staff):

- **Step 1 — Claim basics:** title, optional notes; for managers, an `onBehalf` `PeoplePicker` (self-locked for staff).
- **Step 2 — Items (repeatable, with a line-type switch):** each line is either
  - a **standard expense** (description, **category** as identity tiles travel/meals/accommodation/supplies/other, amount, expense_date, optional tax_amount, **receipt upload** — per-item, private disk, the `StoreExpenseClaimRequest` mime/size rules), **or**
  - a **mileage line** (`distance_km` × **rate auto-filled from `config('finance.mileage_rate_per_km')`**, read-only rate shown like `fleet-assets/mileage/create.tsx`'s "Reimbursement Calculation" card: `IRD rate $0.95/km × {km} = ${amount}`; the amount computes automatically and is not hand-typed). The rate must come from a controller prop sourced from config — **never hardcode 0.95/0.97 in React**.
  - A **live running total** across all lines (sum of standard + mileage), like the current `create.tsx` footer.
- **Step 3 — Review:** the hero summary card (claim title, employee, line list, total, the approver line "Goes to {approver}"), with inline Edit. Submit via Inertia `useForm` + `forceFormData` (receipts). Success pane + `toast` (+ `fireConfetti()` for staff self-submits).

**Then make the flow end-to-end on every surface:**
- **Self-service can actually finish:** after creating, staff get a **Submit** action (and see status), or the wizard submits straight to `submitted` for staff — no more dead-end draft. Surface the employee's own claims with status on `/hr/my` and `/my-day` (read using their own `hr.expenses.view`).
- **Approvals inbox** (Expenses tab segment, not "filter the list"): *Awaiting my decision · All pending · Recently decided*; single + **bulk** approve; **reject requires a reason** (keep the nice inline reject pattern from `show.tsx` or move it into a small modal); **Mark paid** stays gated on GL-posting (`gl_posted_at`); **right-click row menu**; **receipt view/download** (add a gated download route — today there's only a badge).
- **Drafts:** add **edit / add-item / withdraw** (wire `ExpenseService::addItem` to a route; add an update/withdraw endpoint).
- **Backend (§K):** receipt download route; `addItem`/update/withdraw routes; `onBehalf` creation; relax the create gate so staff self-file (keep approve/pay gated); true-total hero aggregates. **Log the GL double-post for Finance — do not fix here.**

---

## I. Compensation History — de-orphan and make it first-class

**Current (`history.tsx`, 264 lines):** a clean read-only timeline (change_type badge, +/-% trend, before→after hourly & annual, reason, approver) — but it has **zero inbound links** anywhere, isn't a tab, the People profile (`hr/employees/show.tsx`) has **no comp section**, and the only writer is `applyCompensationReview` (so `initial`/`promotion`/`adjustment`/`correction` change types are dead). It also faithfully displays the **wrong hourly rate** caused by the §K-1 bug.

**Build:**

1. **Surface it from the People profile:** add a **Compensation** card/section to `hr/employees/show.tsx` (current salary + hourly + **band placement** via `bandPlacement`, and a "View history" link to `history/{profile}`). This is the single most valuable cross-link in the hub.
2. **Link from pay-review lines** (§E-3) and from the History hub tab (§C-6) rows to the per-employee view.
3. **Add a gated "Record change" wizard** (`hr.compensation.manage`) so history can capture **promotion / adjustment / correction / initial** changes directly (not only via a review): **(1) Employee & type** (`PeoplePicker` + change_type tiles) → **(2) New comp** (new annual + new hourly with band-placement preview, effective_date, reason) → **(3) Review** → success. Wire a `recordChange` route to the existing `CompensationService::recordCompensationChange`.
4. Once §K-1 lands, the hourly column reads correctly.

---

## J. Right-click + hover everywhere (the standard)

Per non-negotiable #3/#7: **tabs and rows get right-click menus** via `useCompensationContextMenu` (clone `useLeaveContextMenu`). Tab menu: *Set as default · Open · Pin*. Row menus per tab as listed in §D–§I (Edit · Duplicate · Supersede · Approve · Mark paid · Opt out · View employee · Copy · Archive). Every row also gets **hover actions** (the primary 1–2 actions as ghost buttons) so the menu is a power-user accelerator, not the only path. Destructive items use the `crit` tone + an `alert-dialog` confirm. No native `confirm()/alert()` anywhere.

---

## K. Backend handoff for Claude Code (append as you go)

Create `docs/compensation-hub-redesign/PROGRESS.md` (mirror `docs/health-safety-events-redesign/PROGRESS.md`) and a `COMPENSATION_HUB_GAP_ANALYSIS.md`; append every backend/data item you hit. Seed it with:

**Fix in this work (safe):**
1. **[P0 data-corruption] Pay-review apply writes annual into hourly.** `CompensationService::applyCompensationReview()` (`:131-134`) sets both `new_hourly_rate` and `new_annual_salary` to `proposed_salary`. Fix: write annual→annual; derive hourly = annual ÷ contracted annual hours (or preserve existing hourly for annual-only reviews). Backfill/repair already-corrupted `hr_compensation_history` + `hr_employee_profiles.hourly_rate` where a review was applied (write a one-off command; document it). Cover with a test (extend `tests/Feature/Hr/CompensationReviewApprovalTest.php`).
2. **[P1] True-total hero stats.** Bands/reviews/benefits/expenses heroes count `…data.length` (page ≤20). Pass real aggregates (counts, sums, out-of-band counts) from each controller. Note money fields on bands/reviews/history are **encrypted** → compute sums/compa-ratio in PHP.
3. **[P1] `updateBand` validation.** Enforce `effective_to after:effective_from` and `min ≤ mid ≤ max` in both `storeBand` and `updateBand` (`CompensationController.php:55-106`).
4. **[P1] Wire bands into placement.** Add `CompensationService::bandPlacement(HrEmployeeProfile): {compaRatio, position, inBand|under|over}` built on the dead `getSalaryBandForRole` (`:46`); call it from Bands detail, Pay-review lines, and the People profile.
5. **[P2] Missing CRUD / routes (build):** Bands `showBand`/`destroy`(archive)/`duplicate`. Reviews `updateReview`/`destroyReview`/`rejectReview` + per-item approve/reject. Bonuses `update`/`destroy`/`cancel`/`markPaid` (+ thin `BonusService`). Benefits extend `updatePlan` to all fields + `destroyPlan`/`destroyEnrollment` + proper opt-out; surface `getEnrollmentSummary` averages. Expenses receipt **download** route, `addItem`/update/withdraw routes, `onBehalf` creation, relax create gate to let staff self-file. Add `CompensationController@recordChange` for manual history.
6. **[P2] Dead enums:** decide per Chane — wire or drop review `in_progress`, item `rejected`, bonus `paid`/`cancelled`, history `initial`/`promotion`/`adjustment`/`correction`, and the bonus `payroll_run_id` bridge.
7. **[P3] Cleanups:** remove dead `BenefitsService::updateKiwiSaverRate`; reconcile bonus `amount` encryption inconsistency with the other money fields (decide encrypt vs not, document).

**Document only — DO NOT fix in this pass (hand to Finance):**
8. **[P0 finance] Expense approval double-posts to the GL.** On `status→approved`, both `HrExpenseClaimObserver` (DR **6500** / CR 2310) and `ExpenseService::approveClaim → PostExpenseJournalJob → ExpenseJournalService::postExpenseClaimJournal` (per-category DR **6100/7010/6000/6300** / CR **2000**) fire for the same claim — two journals, different accounts. Also `ExpenseJournalService::CATEGORY_ACCOUNT_MAP` contradicts `config/finance.php` `event_accounts` (expense_claim→6500, mileage→6520). Write this up with the exact accounts; flag the likely correct single path; **leave behaviour unchanged** until Finance signs off.
9. **[note] Mileage system fragmentation** (Operations `MileageClaim` rate 0.97 user-editable + dead show/edit links + no pay; Fleet `FleetPersonalTrip` rate 0.95; Timesheet `mileage_km` at config). Out of scope here; record as a future consolidation onto `config('finance.mileage_rate_per_km')` and one claim object.

---

## L. Verification & acceptance (the loop)

Work in small passes (hero → tabs → bands → reviews → bonuses → benefits → expenses modal → expenses inbox → history). After **each** pass:
- `npm run build`, `npm run types` (zero TS errors), `npm run lint` (zero new violations; raw hex will be blocked).
- Screenshot the changed surface incl. **each modal open** and **each `?tab=`**, plus the self-service surfaces the expense modal feeds (`/hr/my`, `/my-day`); diff against the gold-standard page (`/hr/people`, `/hr/leave` request modal, Add-Client wizard).
- Update `docs/compensation-hub-redesign/PROGRESS.md` and the §K handoff.
- Run/extend the relevant tests (`tests/Feature/Hr/CompensationReview*`, `BonusCreationTest`, `BenefitsEnrollmentTest`, `ExpensePaymentTest`, `ExpenseReceiptUploadTest`; browser `HrExpensesTest`, `HrBenefitsTest`).

**Acceptance — the hub is done when:**
- Every tab shares the **golden hero (no clock)**, the standardised **tab strip with counts + right-click**, shared `Table` + `StatusBadge`, real empty/skeleton states, and **true-total** stats.
- **Every** create/edit/record/enroll/claim/adjust flow is a **full wizard modal** in the Add-Client idiom with the warm Leave feel — **zero thin single-step dialogs, zero inline forms, zero full-page create routes** left in the hub.
- **Bands are load-bearing:** band placement / compa-ratio shows on bands, pay-review lines and the People profile.
- **One expense modal** is used on Compensation, My HR and My Day; it has a **mileage line at the configured IRD rate**; self-service can **submit and track**; there's a real **approvals inbox** with bulk + reason-on-reject + receipt download.
- **Nothing is orphaned:** Plans and History are reachable through the UI; History is on the People profile; the §K-1 hourly bug is fixed and back-filled.
- Every action is wired + toasted; no dead buttons; permissions respected; NZD/en-NZ/KiwiSaver/IRD intact.

> Build order suggestion: **§B hero → §C tabs → §D bands (incl. band-placement backend, since §E/§I depend on it) → §E reviews (+ §K-1 fix) → §F bonuses → §G benefits → §H expense modal + inbox → §I history.** Get Chane's sign-off on the tab set (§C) and the bonus "mark paid" question (§F) before building those.
