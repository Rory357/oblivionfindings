# HR "Goals & OKRs + Development" Hub Redesign — PROMPT

> Paste this whole brief to **Claude design**. It redesigns `/hr/goals` (the OKR hub), its **objective detail** page, the **`/hr/goals/development`** plans view, and **every modal** into one premium, end-to-end hub that matches the gold-standard pages (`/meds/today`, `/my-day`, `/health-safety`, `/hr/people`, `/hr/leave`). Two product decisions are already made by Chane: **(1) fold Development into the Goals hub as a tab** (one golden hero, one shell), and **(2) introduce a real OKR Cycles/periods engine** (Q1 / H1 / FY26). Everything else: audit first, reuse the shared kit, no thin modals, no dead actions, confirm schema before migrating.

---

## 0. Mission

Turn `/hr/goals` from a generic 3-tab list into a **premium OKR & development hub**: a golden hero (no clock), a real tab shell (**Objectives · Alignment · Development · Analytics**), full Add-Client-style wizard modals (objective **with** key results in one flow, check-ins with confidence, development plans), a true alignment tree, right-click everywhere, and the backend to make it end-to-end. Standardise the UI with the rest of HR and **expand the workflows** — each modal is a complete workflow, not a thin form.

---

## 1. Non-negotiables

- **Reuse the shared kit (§2).** No hand-rolled heroes, tabs, modals, menus, badges or tables when a kit component exists. Visual parity with the gold pages is the bar.
- **Web app only.** Desktop web; no phone frames / mobile-app chrome (a dedicated mobile app comes later).
- **NZ-only.** NZD, `en-NZ`, NZ date formatting. Don't "fix" to GBP/USD.
- **Modals are full workflows.** Every create/edit/check-in/assign flow clones the **Add-Client wizard** (stepper rail + completeness meter + per-step validation + review + **Save & add another**). **No thin shadcn dialogs, no inline page forms, no full-page create routes.**
- **Tenancy & permissions.** Respect `ResolvesHrTenant`; gate manager actions on `hr.performance.manage` (and reconcile the `hr.goals.*` vs `hr.performance.*` split — §A/§K). View on `hr.performance.view`.
- **Tokens only** (no hex). `StatusBadge` everywhere. `toast` on every action. `motion-reduce` guards on all animation.
- **No regressions** to `/hr/my` goals, the `PerformanceTabs` constellation (Supervision · Reviews · **Goals** · **Development** · Competencies · 360 · PIPs · Succession), or the cascade/analytics maths.
- **Confirm schema before migrating.** Seed the backend list in §K and mirror it to a handover doc — don't silently invent columns.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero**
- Golden band: clone `resources/js/components/hr/my-hr-hero.tsx` — its `HERO_STYLE` (the `linear-gradient` over `--primary` + `boxShadow`; re-themes per tenant) and the injected amber accent `--hr-amber` / `--hr-amber-soft`. **Omit the clock** — on My HR the clock is a separate child `<MyHrClockCard>` (`resources/js/components/hr/my-hr-clock-card.tsx`); reuse means simply **not rendering that child**. Reuse `HeroStat` (label + big tabular value, clickable / deep-link) and `QuickAction` (icon + label).
- If a shared spine exists/emerges, prefer `resources/js/components/hr/hero-kit.tsx` so My HR, People, Training and Goals share one hero (the standardisation win) — confirm the shape before refactoring the others.
- Richer KPI cluster reference: `resources/js/pages/health-safety/components/hs-hero-kit.tsx` (`HeroShell`, `HeroMedallion`, `HeroCluster`/`HeroClusterTile`, `HeroSummaryStrip`, `HeroSegmented`, `HeroComplianceBadges`). Generic fallback only: `PageHero` from `@/components/page/page-hero` with `category="hr"` (this is what the page wrongly uses today).

**2.2 Modals / wizards**
- Clone `resources/js/components/clients/add-client-dialog.tsx`. Markers to match exactly: `Dialog`+`DialogContent` with `[&>button]:hidden`, `flex h-[min(92vh,860px)]`, a **left stepper rail** (`w-[248px]`, `bg-sidebar`) with per-step icon + blurb + check-on-complete, a **completeness meter** at the rail foot, header "Step X of N", a **top progress bar**, scroll-contained body, footer with Back / Cancel / **Save & add another** / Create.
- Engine: Inertia `useForm`; a `STEPS` array (`{key,label,icon,blurb}`); client-side `validateStep(key,data)`; `stepForError(field)` to jump to the step that owns a server error; `WizardSuccessPane` after create; `resetAll()` for "Save & add another"; `forceFormData: true` whenever a file is involved.
- Built from `@/components/wizard/primitives` (`Field`, `FieldErr`, `StepHead`, `SubHead`, `InfoCard`, `SelectInput`, `Segmented`, `ChipMulti`, `TilePicker`, `Ring`, `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_TRACK_CLASS`, `WIZARD_PROGRESS_BAR_CLASS`, `WIZARD_FOOTER_CLASS`) and `@/components/wizard/shell` (`WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`) + the `useWizard(stepCount)` state machine. HR re-exports the whole kit via `@/components/hr/wizard.ts` — **import from there** to stay visually identical.
- **The existing goal modal already uses this kit** — `resources/js/components/hr/performance/goal-dialog.tsx` (3 steps: Objective · Target · Review). Good bones; it's just **thin** (no key results, no preview, no Save & add another). **Extend it**, don't rebuild from scratch.
- Premium idioms to copy from `resources/js/components/hr/leave-request-dialog.tsx`: a **live preview** side-panel pinned via `railExtra` fed by a debounced `/preview` fetch (use it for the **roll-up % and alignment** preview), per-type accent tinting, review-step warning banners, confetti + `toast` on completion. People-picker: `@/components/hr/people-picker` (`PeoplePicker`, `PersonOption`).
- Base shadcn: `@/components/ui/` — `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`.

**2.3 Right-click menus + hover actions**
- Closest HR reuse: `resources/js/components/hr/leave-context-menu.tsx` (`useLeaveContextMenu()` → `{ open, element }`; portal-rendered, viewport-flipping, Esc/outside-click/scroll close, arrow-key rove, icon + label + `kbd` + `tone`). Mould a `useGoalContextMenu` on it. Equivalents: `@/components/rostering/shift-context-menu` (`ShiftContextMenu`, `ShiftCtxItem`, `ShiftCtxState`) and `resources/js/pages/operations/handovers/components/handover-context-menu.tsx` (`useHandoverContextMenu`). Wire via `onContextMenu={(e) => open(items)(e)}`.
- Lightweight inline row hover: `resources/js/pages/my-day/components/hover-action.tsx` (`HoverAction`).

**2.4 Cards / tables / states / badges**
- `@/components/ui/status-badge` (`StatusBadge`) **everywhere** — do not hand-map status/priority/confidence colours (the page currently hand-maps `typeBadge`/`statusBadge`/`priorityBadge` dictionaries — delete them).
- `@/components/ui/card`, `table`, `@/components/ui/empty-state` (`EmptyState`, `EmptyList`, `EmptySearch`), `error-state`, `loading-state`, `skeleton-card`, `skeleton-table`, `@/components/ui/laravel-pagination`, `@/components/ui/checkbox` (for multi-select).

**2.5 Tabs**
- `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab(defaultTab, { param, syncUrl })`) built on `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`: `role="tablist"`, arrow/Home/End keys, `onItemContextMenu`, `decorations`, `trailing`). **Replace the plain shadcn `<Tabs>`** on both the hub and the detail page with this.
- Section-level constellation strip stays `resources/js/components/hr/performance-tabs.tsx` (`PerformanceTabs`) — see §C for how Development folds in without breaking it.

**2.6 Tokens & flourishes**
- Tokens only, from `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`, `--shadow-hero`/`--shadow-float`. Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`.
- Toasts: **sonner** (`<Toaster>` already mounted in `resources/js/app.tsx`) — `toast.success/error` on **every** action.
- Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards.

---

## A. Audit & benchmark first (do this before building)

Study `/meds/today`, `/my-day`, `/health-safety`, `/hr/people` and **`/hr/leave`** and **interact** with them — they are the parity bar. Then open `/hr/goals`, an objective detail (`/hr/goals/{id}`), `/hr/goals/development` and `/hr/my` (the self-service goals strip) and fill in the checklist; paste the results back as your first pass.

**Checklist**
- [ ] Screenshot each current surface (hub: All Objectives / Alignment / Analytics; objective detail: Key results / Child goals / Dev plans / History; Development; My-HR goals). Note every hand-rolled element that has a kit equivalent.
- [ ] Confirm the **data model**: `HrGoal` (owner, type individual/team/company, parent_goal_id self-cascade, target/current/unit, progress, status draft/active/completed/cancelled, priority, start/due, `performance_review_id`), `HrKeyResult` (target/current/unit/status/owner — **no baseline, no type, no weight**), `HrGoalUpdate` (the check-in log — value + % + comment, **no confidence**), `HrDevelopmentGoal` (employee/manager, optional `hr_goal_id` roll-up, competency_area free-text, target/current level 1–5, status incl. **blocked**, `review_frequency`, `review_notes`). Document field-by-field.
- [ ] Trace **progress maths** in `app/Domain/Hr/Services/GoalService.php`: `updateProgress()` (manual %, logs `HrGoalUpdate`, cascades to parent) **vs** `recalculateGoalProgress()` (averages KRs **and** child goals). Confirm the **clobber risk**: a goal with KRs can have a manual % overwritten on the next recalc, and a goal without KRs relies on manual. Define the single rule (§K).
- [ ] List every goals/dev/my-goals route in `routes/hr.php` vs every action the new UI needs; the delta seeds §K.
- [ ] Confirm the **permission split**: `GoalController::canView/canManage` accept `hr.goals.*` **or** `hr.performance.*`, but routes gate purely on `hr.performance.*`. Does `hr.goals.*` exist anywhere? Unify (§K).
- [ ] Note the **adjacent goal models that are NOT in scope** but must not collide: `app/Domain/Governance/Models/PerformanceGoal.php` + `StrategicGoal.php` (governance OKRs) and `app/Models/CarePlanGoal.php` (+ `CarePlanGoalController`, client care-plan goals). Flag any naming/route overlap; note a possible future cross-link (don't build it).

> **Known gaps the audit already surfaced** (confirm, then fix):
> - **Hero:** generic `PageHero category="hr"` — not the golden band. No cycle context, no clickable stats, no "needs you" strip.
> - **Hub tabs:** plain shadcn `<Tabs>` (All Objectives / Alignment / Analytics) — **not** `HrTabs`/`TabStrip`: no right-click, no URL-sync, no per-user default tab.
> - **All Objectives:** four loose `Select` filters; **no text search, no sort, no table/density toggle, no multi-select/bulk**. `ObjectiveRow` expand renders a **dead placeholder** ("View & manage key results →") instead of the actual KRs. No per-row action menu, no right-click.
> - **Alignment:** read-only company→team→individual cascade; no create-in-context, no re-parent, no roll-up tree/org view.
> - **Analytics:** static KPI cards + 2 charts; numbers **don't deep-link** to filtered lists; no cycle filter, no owner/team breakdown, no at-risk list.
> - **Objective detail:** KR add/edit is an **inline toggle form** (`showKrForm`); **progress update is a thin shadcn `Dialog sm:max-w-md`** (slider + comment, **no confidence, no per-KR check-in**); add-child reuses the thin GoalDialog; dev-plans tab read-only; **no right-click**, no alignment breadcrumb.
> - **Development:** a **full-page inline "Create Development Goal" Card form** (+ inline edit) — **not a modal**. Status filter only; no right-click/bulk; `competency_area` is free text (doesn't link the Competencies module); `review_frequency` stored but **nothing acts on it**.
> - **Create modal (`goal-dialog.tsx`):** on the wizard kit (good) but **thin** — objective only; KRs are added one-by-one **afterward** on the detail page; no completeness ring, no live roll-up/alignment preview, no Save & add another, no SuccessPane, minimal validation.
> - **Backend:** **no cycles/periods**; **no confidence/RAG** (`on_track` is derived from `due_date` only); KR has **no baseline/start value** (progress = current÷target, can't model "reduce 50→10"), **no `kr_type`**, **no weight** (goal % is a plain average); progress-source ambiguity (manual vs recalc); **no check-in cadence/reminders** (`HrGoalUpdate` exists but nothing schedules; dev `review_frequency` inert); **only dev-goal assignment notifies** (`DevelopmentGoalAssignedNotification` / `GoalCompletedNotification` exist; no OKR assign / at-risk / overdue / KR-due notifications); **no comments/discussion**; no tags/templates; **no bulk endpoints**; **no export**; index **search/sort is exact-match only** (status/type/priority/user — no free text, no sort param); status mismatch (dev has `blocked`, OKR doesn't).

---

## B. Hero rethink — the golden band (NO clock, fitted to Goals)

Replace the generic `PageHero` with the golden `MyHrHero`-style band (§2.1). **One hero spans the hub**; the active tab and the selected cycle tune the stats.

**Do:**
- Title: a warm line ("Goals & development") + the org/site context meta row (date, your role, site) like the My HR hero. **No clock card.**
- **Cycle selector in the hero** (the new spine): a segmented/`Select` for the OKR cycle (e.g. *FY26 · Q3* / *H1* / *All*), defaulting to the current cycle. Every stat and every tab respects it.
- `HeroStat` cluster (clickable → deep-link into the matching tab + filter): **Active**, **On track**, **At risk** (amber via `--hr-amber`), **Overdue** (critical), **Completion %**, **Avg progress**. Use `delta`/tone where you have a trend vs last cycle.
- `QuickAction`s (each opens the matching wizard in §H — no dead actions): **New objective**, **Log check-in**, **New development plan**, **Export**.
- Footer "Needs you" strip: **check-ins due** (cadence), **at-risk objectives**, **overdue KRs**, **unacknowledged assignments**. Re-theme via `--primary`; amber only for "needs attention" numbers.

---

## C. Tabs — fold Development in; one hub shell

Build the hub on `HrTabs` + `useHrTab` (§2.5), replacing the plain shadcn `<Tabs>`. **Four tabs**, all behind `hr.performance.view`:

1. **Objectives** (`/hr/goals?tab=objectives`) — the OKR list/board (§D).
2. **Alignment** (`?tab=alignment`) — the cascade tree (§F).
3. **Development** (`?tab=development`) — **folded in** from `/hr/goals/development` (§G).
4. **Analytics** (`?tab=analytics`) — the roll-up (§H-Analytics / see §D-Analytics).

> **Folding Development without breaking the constellation:** keep the `PerformanceTabs` strip (Supervision · Reviews · Goals · **Development** · …) working — the `development` item should **deep-link to `/hr/goals?tab=development`** (update its `TAB_URLS` entry in `performance-tabs.tsx`), and the old `/hr/goals/development` route should **redirect** there so bookmarks and `route()` helpers still resolve. Net effect: Development is reachable both from the constellation strip and as a hub tab, but renders inside the one golden shell. Confirm this routing with Chane if the redirect is contentious.

> Per tab: real loading (`skeleton-*`), empty (`EmptyState`/`EmptySearch`) and error (`error-state`) states; URL-synced filters (`?tab=`, `?cycle=`, `?search=`, `?status=`, `?owner=`, …); right-click on rows **and** on the tab strip (§J). Objective detail (`/hr/goals/{id}`) stays a full page reachable from any tab.

---

## D. Objectives tab redesign

**Current:** a `Card` of `ObjectiveRow`s with four loose `Select`s and a dead expand. **Do:**
- Keep a polished **list** but add a **table/density toggle** (`@/components/ui/table`) and a **board view** option (by status or by owner), a **sort control** (progress, due date, priority, owner, confidence, last check-in), and a **real text search** (title + description + owner + category — backend, §K).
- Filters (URL-synced): **cycle**, status, type (company/team/individual), priority, owner, **confidence (RAG)**, parent. Replace the four bare `Select`s with a tidy filter bar.
- Each objective row/card shows: title, type + priority (`StatusBadge`), owner avatar, **cycle**, parent (`↳`), **confidence chip**, KR count, **weighted** progress bar + %, due date, last-check-in age, status. Kill the hand-mapped badge dictionaries → `StatusBadge`.
- **Expand actually works:** expanding a row reveals its **real key results** (title, current→target, KR progress, owner, confidence) — not the placeholder link. Fetch lazily or hydrate from the index payload.
- Row/card actions (buttons + right-click, §J): **Open**, **Log check-in**, **Edit**, **Add KR**, **Add child objective**, **Duplicate** (into current cycle), **Complete**, **Archive/Cancel**, **Export**.
- **Multi-select + bulk bar** (`@/components/ui/checkbox`): bulk re-cycle, bulk owner reassign, bulk archive, export selected, bulk "request check-in".
- Empty/filtered states via `EmptySearch`/`EmptyList`. Pagination stays `laravel-pagination`.

---

## E. Objective detail redesign (`/hr/goals/{id}`)

Make `show.tsx` a premium OKR profile on `HrTabs`, not plain shadcn tabs:
- **Header:** title, owner, cycle, status + **confidence** (`StatusBadge`), **weighted** progress ring; an **alignment breadcrumb** (parent ↑) and a children roll-up (↓). Actions: **Log check-in**, **Edit**, **Add KR**, **Add child**, **Complete**, **Archive** (destructive → `alert-dialog`, never native `confirm()`), **Export**, right-click everywhere.
- **Key results panel:** each KR shows **baseline → current → target** with unit, `kr_type` (number / percent / currency / milestone / boolean), **weight**, owner, due, confidence, progress. Add/Edit/Delete via the **KR modal** (§H) — retire the inline toggle form. Per-KR **check-in** inline-or-modal updates current value + confidence and recomputes the roll-up live.
- **Check-in history (timeline):** the `HrGoalUpdate` log rendered as a timeline with **confidence trend** and who/when/comment — not a bare list.
- **Child objectives:** roll-up cards with progress; create-in-context (prefilled parent) via the Objective wizard.
- **Linked development plans:** the `developmentGoals` roll-up — show plan, employee, level current→target, status; open the Dev Plan modal (§H).
- **Linked performance review:** surface `performance_review_id` (link to the review) and allow linking/unlinking; keep the `GoalService::linkToPerformanceReview` path.

---

## F. Alignment tab redesign

Keep the company→team→individual idea but make it a **real alignment tree**, not three read-only panels:
- A proper tree / org-style view with **roll-up %** at each node (company shows weighted progress of its teams; team of its individuals), confidence colour, owner.
- **Create-in-context:** "Add child objective" under any selected node opens the Objective wizard with `parent_goal_id` prefilled.
- **Re-parent:** drag-to-reparent (or a right-click "Move under…") writing `parent_goal_id`; recompute roll-ups.
- Deep-link any node to its detail; right-click nodes for the same actions as §D. Keep `GoalService::getCompanyGoalTree` as the data source (extend for cycle + weighting).

---

## G. Development tab (folded in — replace the inline form)

Replace the full-page inline "Create Development Goal" Card with a managed, kit-built surface inside the hub:
- A managed **list** of development plans: person, manager, **competency area**, level **current→target** (1–5, e.g. a small level meter), category (growth/performance/leadership/compliance/capability), status (`StatusBadge`: not_started / in_progress / **blocked** / completed / cancelled), review cadence, **linked OKR objective**, due/overdue.
- Filters (cycle where relevant, status, category, person, manager); multi-select + bulk (reassign manager, set cadence, archive); right-click rows (§J).
- Create/Edit via the **Development Plan wizard** (§H) — no inline form. **Check-in** updates current level + status + notes and (if linked) reflects into the OKR roll-up.
- Surface the same plans read-only-to-the-owner on **`/hr/my`** goals so an employee sees and check-ins their own plan (shared component — §I). Wire `competency_area` to the **Competencies module** where one exists instead of free text (confirm — §K).

---

## H. Modals = exact Add-Client wizard pattern (full, not thin)

Every flow clones `add-client-dialog.tsx` (§2.2): full-height bespoke shell, left stepper rail with completeness meter, top progress bar, per-step `validateStep`, `stepForError` jump, `WizardSuccessPane`, **Save & add another**, `forceFormData` for any upload, `toast` on success. Build these:

1. **Objective wizard** (extends `goal-dialog.tsx`; create **and** edit like Add-Client's id toggle). Steps:
   1. **Objective** — owner (`PeoplePicker`)*, title*, description, type* (`Segmented`: company / team / individual), priority*, category (typeahead from existing), parent objective, **cycle*** (from the new cycles list).
   2. **Key results** — **repeatable KRs in the same flow** (kill the "add them later" detour): per KR → title*, `kr_type` (`Segmented`: number / percent / currency / milestone / boolean), **baseline → target** (+ unit), owner (`PeoplePicker`), **weight**, due. Live roll-up preview of how the KRs compute the objective %.
   3. **Timing** — start*, due*, confidence starting point; validate `due ≥ start` and within-cycle.
   4. **Review & create** — `ReviewCard`/`ReviewRow` summary + the roll-up; **Save & add another**; `WizardSuccessPane` ("Objective created — KRs attached").
2. **Check-in wizard** (replaces the thin Progress dialog) — per objective: step through **each KR** (new current value + confidence), then an **overall** step (auto-computed weighted % shown, optional manual override with reason, comment), with a **live preview** (debounced `/preview` idiom from Leave) of the new roll-up + cascade to the parent. Writes `HrGoalUpdate` + KR updates + confidence. Confetti + celebratory `WizardSuccessPane` when an objective hits 100% / completes.
3. **Key Result modal** (standalone add/edit) — for the detail panel and right-click "Add KR": the same KR fields as step 2 of the Objective wizard, single-KR.
4. **Development Plan wizard** (replaces the inline form) — steps: **Person** (employee* + manager via `PeoplePicker`, link OKR objective), **Focus** (competency area*, category*, level current→target, description/plan), **Cadence & review** (`review_frequency`, first review date, notes), **Review & create** + Save & add another. Fires `DevelopmentGoalAssignedNotification` (already exists).
5. **Confirmations** — Complete / Archive / Cancel / Delete / Re-parent confirm via `alert-dialog` with reason where it matters; never native `confirm()`.

> Wire each from its page/tab/row and from the hero `QuickAction`s exactly like Add-Client is wired from `index.tsx`. Reuse `@/components/hr/wizard.ts` so all of these are visually identical to Leave's New Request.

**Analytics (the fourth tab):** rebuild the KPI cards + charts so **every number deep-links** into a filtered Objectives/Development view; add a **cycle filter**, an **owner/team breakdown**, an **at-risk list**, and a **confidence distribution**. Keep `recharts`; no hand-rolled tables.

---

## I. Cross-loops & source of truth

- **OKR ↔ Development** (`hr_development_goals.hr_goal_id`): surface both ways — dev plans on the objective detail (§E) and the linked objective on the plan (§G). A plan check-in optionally nudges the linked objective.
- **OKR ↔ Performance review** (`hr_goals.performance_review_id`): surface on detail; let a review pull/attach its goals (keep `linkToPerformanceReview`).
- **OKR ↔ `/hr/my` goals:** the employee self-service strip (`MyHrController@goals`, `resources/js/pages/hr/my/goals.tsx`, `PUT /hr/my/goals/{goal}`) must use the **same check-in component** (§H-2) — don't fork a second progress form.
- **Progress-source rule (decide + enforce, §K):** if an objective **has KRs → progress is derived (weighted)** and the manual slider is hidden; **no KRs → manual** check-in %. Stop `recalculateGoalProgress` and `updateProgress` from clobbering each other.
- **Out of scope but keep distinct:** governance `PerformanceGoal`/`StrategicGoal` and `CarePlanGoal` are separate engines — don't merge; just avoid route/name collisions and note any future cross-link for Chane.

---

## J. Right-click everywhere (rows and tabs)

Chane explicitly wants right-click options "under tabs etc." Build a `useGoalContextMenu` (mould of `useLeaveContextMenu`, §2.3) and wire `onContextMenu` on:
- **Objective rows/cards:** Open · Log check-in · Edit · Add KR · Add child · Duplicate (into cycle) · Complete · Archive/Cancel · Export. Gate edit/complete/archive by `can.manage`; show `kbd` hints.
- **Key-result rows:** Check in · Edit · Reassign owner · Delete.
- **Child-objective rows & alignment nodes:** Open · Add child · **Move under…** (re-parent) · Log check-in.
- **Development rows:** Open person · Check in (level/status) · Set cadence · Reassign manager · Link/unlink objective · Archive.
- **The tab strip itself:** right-click a tab → **Set as default view**, **Open**, **Pin**. Persist default-tab/pins to `localStorage` (allowed) so it survives reloads; render a `decorations` star/pin on the chosen tab.

Every menu action fires a toast and, where it writes, hits a real route (§K). **No dead items.** Destructive items confirm via `alert-dialog`.

---

## K. Backend handoff for Claude Code (append to this as you design)

> Claude design: as you build the UI and discover anything that needs server work, **add it here** with a short spec + migration sketch, so Chane has one clean list to hand to Claude Code — and copy the finished list into a new **`GOALS_OKR_BACKEND_HANDOVER.md`** at repo root. Gate manager actions on `hr.performance.manage` (and reconcile `hr.goals.*`), respect `ResolvesHrTenant`, and **confirm any schema before building**. Seed list from the audit:

**Cycles / periods (the new spine — confirm before migrating):**
1. New `hr_goal_cycles` table (tenant_id, name e.g. "FY26 Q3", type quarter/half/year/custom, starts_at, ends_at, status upcoming/active/closed, optional parent for FY→Q nesting) + a nullable `cycle_id` FK on `hr_goals`. Endpoints: list/create/edit/close, **clone/roll-over** (copy selected objectives into the next cycle), and a `current` resolver for the hero default. Backfill existing goals into a derived cycle from their dates.

**OKR depth (confirm → implement):**
2. **Confidence/RAG:** add `confidence` (on_track / at_risk / off_track) to `hr_goals` **and** `hr_key_results`, and capture it on each `HrGoalUpdate` (so the timeline shows a trend). Stop deriving "on track" purely from `due_date`.
3. **Key results:** add `start_value` (baseline), `kr_type` (number/percent/currency/milestone/boolean) and `weight` to `hr_key_results`; update `recalculateProgress()` to honour baseline (`(current−start)/(target−start)`) and `GoalService::recalculateGoalProgress()` to compute a **weighted** roll-up.
4. **Progress-source rule:** enforce "has KRs → derived (weighted); none → manual"; make `updateProgress` and `recalculateGoalProgress` mutually consistent (no clobber).
5. **Status alignment:** decide whether OKR goals need `on_hold`/`blocked` to match dev goals; reconcile the two status sets.

**Check-ins, cadence & notifications:**
6. **Check-in cadence:** a `checkin_frequency` on `hr_goals` (or per cycle) + a scheduled reminder (mirror how dev `review_frequency` *should* work) — a "check-in due" notification + the hero "Needs you" feed.
7. **Notifications:** OKR **assignment** notification (today only dev goals notify), plus **at-risk**, **overdue**, and **KR-due** nudges, and a weekly **digest**. (`GoalCompletedNotification` already exists — reuse.)
8. **KR-level check-in log** (optional) so KR history is first-class, not only goal-level `HrGoalUpdate`.

**List power & data plumbing:**
9. Widen index **search** to title + description + owner + category; add **sort** params; add **cycle/confidence/owner** filters to `GoalController@index` and `getGoalAnalytics`/`getCompanyGoalTree` (cycle-aware, weighted).
10. **Bulk** endpoints: re-cycle, reassign owner, archive, request check-in (back the multi-select bars).
11. **Export** endpoint(s) (CSV/Excel) for objectives, KRs and development plans — none exist.
12. **Duplicate / clone** objective endpoint (into a chosen cycle, with/without KRs).
13. **Re-parent** endpoint (write `parent_goal_id`, recompute roll-ups) for drag/"Move under…".

**Hygiene:**
14. **Permissions:** confirm whether `hr.goals.view/manage` exist; either wire them or collapse `GoalController::canView/canManage` onto `hr.performance.*` so routes and controller agree.
15. **Development:** act on `review_frequency` (reminders); link `competency_area` to the Competencies module if one exists (FK instead of free text); keep `blocked`.
16. **Tags & templates** (optional, nice-to-have): `hr_goal_tags` + a "create from template" path for common objectives.

> For each item: short spec + migration (if any) and **confirm before building**. Don't silently invent schema. Mirror the finished list to `GOALS_OKR_BACKEND_HANDOVER.md`.

---

## L. Premium polish & delight

- Micro-interactions from the kit: `animate-in fade-in-0 zoom-in-95` on modals/menus, hover lifts (`--shadow-float`), skeletons on load, optimistic toasts. `motion-reduce` guards throughout.
- Confetti + celebratory `WizardSuccessPane` when a check-in completes an objective or a cycle hits 100% (mirror the Leave self-service flourish) — tasteful, not noisy.
- Keyboard: `/` focuses search, `n` opens New objective, `c` opens Log check-in, arrow/Home/End on tabs, Esc closes menus/modals; surface `kbd` hints in menus.
- Live preview where it helps: the KR roll-up in the Objective wizard, the new % + cascade in the Check-in wizard (debounced `/preview` idiom).
- Everything re-themes via `--primary`; amber (`--hr-amber`) only for attention.

---

## Definition of done

- The hub is **one golden hero (no clock) with a cycle selector** + **four real tabs** (Objectives · Alignment · Development · Analytics) on `HrTabs`/`TabStrip`, matching the gold-standard pages. Development is **folded in**; the old `/hr/goals/development` route redirects and the `PerformanceTabs` strip still resolves.
- Objectives has a table/board/density toggle, real sort + text search, multi-select + bulk bar, working KR expand (no dead placeholder), and `StatusBadge` throughout. Alignment is a real roll-up tree with create-in-context + re-parent. Analytics deep-links every number and shows at-risk + confidence.
- Objective detail is a premium profile: weighted progress, confidence, alignment breadcrumb, KR panel (baseline→target, type, weight), check-in **timeline**, linked dev plans + review.
- **Every** create / edit / check-in / KR / development flow is a **full Add-Client-style wizard** (stepper rail, completeness meter, validation, review, Save & add another) — **no thin dialogs, no inline forms, no full-page create routes**. The Objective wizard creates **KRs in the same flow**.
- **One check-in component** is reused on the detail page **and** `/hr/my` goals; the thin progress dialog and inline KR/dev forms are gone.
- **Right-click** works on objective / KR / child / alignment-node / development rows **and** the tab strip (Set as default / Pin); every action toasts and hits a real route. No dead items.
- A real **OKR cycle** spine exists; **confidence/RAG**, **weighted KRs with baselines**, and **check-in cadence/notifications** work end-to-end; the **progress-source rule** is enforced.
- NZD / `en-NZ` retained; `ResolvesHrTenant` scoping and `hr.*` gates respected; **no regressions** to `/hr/my`, the `PerformanceTabs` constellation, or the cascade/analytics maths.
- Clean `build`, `types`, `lint`; screenshots of each tab **and each modal** match the reference pages. **§K backend handoff list is filled in** and mirrored to `GOALS_OKR_BACKEND_HANDOVER.md` for Chane → Claude Code.
- **Signals to watch:** % objectives with a check-in this cycle, at-risk queue size, overdue-KR count, time-to-log a check-in, objectives created per cycle, development plans with an active cadence.

**Build order:** §A audit → §B hero → §C tab shell (fold Development) → §D Objectives → §E detail → §F Alignment → §G Development → §H modals (Objective+KRs → Check-in → KR → Dev plan) → §I cross-loops → §J right-click → delight pass (§L). Verify each pass against the reference pages, and keep appending discovered backend work to **§K**.
