# HR "Performance & Development" Hub Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent (Claude design — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/performance` and every tab URL (`/hr/performance/reviews`, `/hr/goals`, `/hr/goals/development`, `/hr/performance/competencies`, `/hr/performance/skills`, `/hr/performance/skills/matrix`, `/hr/feedback`, `/hr/performance/pips`, `/hr/succession`) **and each modal**, then diff against the gold-standard pages/components before continuing. Start with the audit in §A, then build §B–§P. **Anything you discover that needs backend/data work goes into §O "Backend handoff for Claude Code" — append to it as you go, and mirror the final list into a new `PERFORMANCE_BACKEND_HANDOVER.md` at repo root so Chane has one clean hand-off for Claude Code.**

**Page (canonical):** `/hr/performance` — the **Performance & Development hub**: one golden hero + a shared tab strip across **Supervision · Reviews · Goals & OKRs · Development · Competencies & Skills · 360 Feedback · PIPs · Succession**.
**Frontend (this hub owns all of these):**
- `resources/js/pages/hr/performance/index.tsx` (Supervision overview), `reviews.tsx`, `create-review.tsx`, `edit-review.tsx`, `show-review.tsx`, `create-supervision.tsx`, `edit-supervision.tsx`, `show-supervision.tsx`
- `resources/js/pages/hr/performance/competencies/{index,assess,profile}.tsx`, `skills/{index,matrix}.tsx`, `pips/{index,create,show}.tsx`
- `resources/js/pages/hr/goals/` (goals index + `development.tsx` + `show.tsx`), `resources/js/pages/hr/feedback/` (`index`, `request`, `respond`, `summary`), `resources/js/pages/hr/succession/` (`index`, `create`, `show`)
- `resources/js/components/hr/performance-tabs.tsx` (the constellation strip), and the 5 dialogs in `resources/js/components/hr/performance/` (`review-wizard-dialog.tsx`, `supervision-dialog.tsx`, `goal-dialog.tsx`, `probation-dialog.tsx`, `succession-candidate-dialog.tsx`)
- Employee self-views: `resources/js/pages/hr/my/{reviews,goals}.tsx`
**Backend:** `app/Http/Controllers/Hr/PerformanceReviewController.php` (reviews + probation), `SupervisionController`, `GoalController`, `DevelopmentGoalController`, `CompetencyController`, `SkillsController`, `FeedbackController`, `PipController`, `SuccessionController` (under `app/Http/Controllers/Hr/`). Governance twin: `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php` + `app/Domain/Governance/Services/PerformanceReviewService.php`. Routes in `routes/hr.php` (performance / goals / feedback / succession blocks).
**Models:** HR side `app/Domain/Hr/Models/HrPerformanceReview.php`, `HrPerformanceImprovementPlan.php` (+ `HrProbationReview`, `HrSupervisionNote`, `HrGoal`, `HrKeyResult`, `HrGoalUpdate`, `HrDevelopmentGoal`, `HrCompetency`, `HrCompetencyAssessment`, `HrSkill`, `HrEmployeeSkill`, `HrFeedbackRequest`, `HrFeedbackResponse`, `HrFeedbackTemplate`, `HrPipMilestone`, `HrSuccessionPlan`, `HrSuccessionCandidate`, unused `HrTalentPool`). Governance side `app/Domain/Governance/Models/{PerformanceReview,PerformanceGoal,PerformanceKpi,PerformanceFeedback}.php`.
**Gold-standard modal to clone:** `resources/js/components/clients/add-client-dialog.tsx`. **Premium modal reference:** `resources/js/components/hr/leave-request-dialog.tsx` (the "New Request" flow). **Hero reference:** `resources/js/components/hr/my-hr-hero.tsx`.
**Read first (absorb prior decisions, don't re-litigate them):** `GOALS_REDESIGN_PROMPT.md` (the OKR-cycle engine + "fold Development in" decision — this prompt now supersedes it but must carry those decisions forward) and `HR_TRAINING_REDESIGN_PROMPT.md` §I (the **shared Claim Expense modal** you will reuse here, see §M).

---

## 0. Mission

Make the **Performance & Development hub** a **premium, end-to-end performance surface** that feels identical in quality to our gold-standard pages — **`/meds/today`**, **`/my-day`**, **`/health-safety`**, **`/hr/people`** — and reuses their exact components and tokens.

Today the hub is **functional but inconsistent and half-built**. The good news: the 5 dialogs in `components/hr/performance/` are **already full Add-Client-style `WizardShell` wizards** — they are the proven house template the rest of the hub must rise to. The bad news, everywhere else:

- **Three different ways to "create a thing"** coexist: polished wizard modals (reviews, supervision, goals, probation, succession candidate) **vs** off-pattern **full-page forms** (PIP create, competency assess, 360 request, succession plan create) **vs** **thin inline cards/dialogs** (new competency, new skill, matrix cell, add key-result). The user's core complaint — *"the tabs are old views"* — is exactly this.
- A generic flat `PageHero` on every tab (never the golden band), with **static, non-clickable stats**.
- **Zero** use of `StatusBadge` (10+ hand-rolled colour maps instead), **zero** `EmptyState`, **no** skeleton/loading/error states, **27 raw-hex** colours (incl. a hand-rolled SVG radar), and a stray raw `<input type=checkbox>` and a native `confirm()`.
- **No right-click anywhere**, no bulk-select, no export, no sort, no density toggle, no deep-linking, no detail sheets — the whole premium "command-center" layer is missing.
- A **silent two-model split**: the live UI writes the **anemic** `hr_performance_reviews` (goals stuffed in a JSON column, status set as free text, sign-off booleans no endpoint ever flips), while the **richer** governance `performance_reviews` (real child tables, a service, resolution sign-off) sits unused under `/governance/performance` — and is itself **stuck before "completed"** because its `approve()` transition is unrouted.
- **Whole lifecycles are unreachable:** 360 feedback can never be `declined` or `expired`; PIP milestones can't be added after creation; supervision notes can't be acknowledged by the employee; nothing has evidence upload, export, bulk, or reminders.

**Result:** every create/edit/assess/request/check-in/sign-off flow becomes a **full Add-Client-style wizard**, one **golden hero** fitted to performance (no clock), **eight real tabs** with right-click everywhere, **one source of truth** for reviews, the shared **Claim Expense modal** reused for development spend, and a real **9-box succession** view. Bring the whole hub to parity with the gold-standard pages.

---

## 1. Non-negotiables

1. **Keep the 8-tab constellation and make every tab real and uniform.** Supervision · Reviews · Goals & OKRs · Development · Competencies & Skills · 360 Feedback · PIPs · Succession. Use the shared tab kit; add the missing **Competencies / Skills / Matrix** sub-tab.
2. **Reuse the kit — never hand-roll a primitive we already have.** No new bespoke widgets, no raw hex (ESLint blocks it — colours come from design tokens in `resources/css/app.css`). Everything in §2 is the source of truth. The 5 existing wizard dialogs already do this — match them, don't regress.
3. **Information-gathering = modals.** Every create / edit / assess / request-feedback / record-check-in / sign-off / claim flow is a **full wizard dialog** cloned from `add-client-dialog.tsx` — **not** an inline form and **not** a full-page route. Reading detail can navigate to a profile page or open a sheet. **No thin modals** — each carries the full field set and a review step.
4. **Single source of truth.** Unify staff reviews onto **one** entity (§O #1) so reviews, goals, competency links and 360 all hang off one place. Port the governance lifecycle/sign-off semantics onto the live HR model; don't fork data another module owns; don't invent a third store. The **engagement-survey** tables are owned by the **Wellbeing** redesign — link, don't absorb.
5. **Web-only desktop app. No phone frames.** Design for mouse + keyboard: hover states, **right-click menus**, keyboard shortcuts. (Mobile app comes later.)
6. **Locale stays NZ.** NZD / `en-NZ` / `formatNzd`; KiwiSaver / Holidays-Act context where relevant. Do **not** switch to GBP/US.
7. **Verify each pass:** clean `npm run build`, `npm run types` (no TS errors), `npm run lint`; screenshot the changed surface and diff vs the reference pages. Don't move on with a broken pass. **No dead buttons** — every action hits a real route or is appended to §O.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero**
- Golden band: clone `resources/js/components/hr/my-hr-hero.tsx` — its `HERO_STYLE` (the `linear-gradient` over `--primary` + `boxShadow`; re-themes per tenant) and the injected amber accent `--hr-amber` / `--hr-amber-soft`. **Omit the clock** — on My HR the clock is a separate child `<MyHrClockCard>` (`resources/js/components/hr/my-hr-clock-card.tsx`); reuse means simply **not rendering that child**. Reuse `HeroStat` (label + big tabular value, **clickable / `href`** — deep-link into the matching tab/filter) and `QuickAction` (icon + label).
- If you extract a shared spine, add `resources/js/components/hr/hero-kit.tsx` so My HR, People, Training and Performance share one hero (the standardisation win) — confirm the shape before refactoring the others.
- Richer KPI cluster reference: `resources/js/pages/health-safety/components/hs-hero-kit.tsx` (`HeroShell`, `HeroMedallion`, `HeroCluster`/`HeroClusterTile`, `HeroSummaryStrip`, `HeroSegmented`, `HeroComplianceBadges`). Generic fallback only if truly needed: `PageHero` from `@/components/page/page-hero` with `category="hr"`.

**2.2 Modals / wizards**
- Clone `resources/js/components/clients/add-client-dialog.tsx`. Markers to match exactly: `Dialog`+`DialogContent` with `[&>button]:hidden`, `flex h-[min(92vh,860px)]`, a **left stepper rail** (`w-[248px]`, `bg-sidebar`) with per-step icon + blurb + check-on-complete, a **completeness meter** at the rail foot, header "Step X of N", a **top progress bar**, scroll-contained body, footer with Back / Cancel / **Save & add another** / Create.
- Engine: Inertia `useForm`; a `STEPS` array (`{key,label,icon,blurb}`); client-side `validateStep(key, data)`; `stepForError(field)` to jump to the step that owns a server error; `WizardSuccessPane` after create; `resetAll()` for "Save & add another"; `forceFormData: true` whenever a file (evidence / certificate / receipt) is involved.
- Built from `@/components/wizard/primitives` (`Field`, `FieldErr`, `StepHead`, `SubHead`, `InfoCard`, `SelectInput`, `Segmented`, `ChipMulti`, `TilePicker`, `Ring`, `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_TRACK_CLASS`, `WIZARD_PROGRESS_BAR_CLASS`, `WIZARD_FOOTER_CLASS`) and `@/components/wizard/shell` (`WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`) + the `useWizard(stepCount)` state machine. HR re-exports the whole kit via `@/components/hr/wizard.ts` — import from there to stay visually identical. **The 5 existing performance dialogs already use this — read `review-wizard-dialog.tsx` as your in-module template.**
- Premium idioms to copy from `leave-request-dialog.tsx`: a **live preview** side-panel pinned via `railExtra` fed by a debounced `/preview` fetch, per-type accent tinting, review-step warning banners, optional confetti + `toast` on self-service submit. People-picker: `@/components/hr/people-picker` (`PeoplePicker`, `PersonOption`).
- Base shadcn: `@/components/ui/` — `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`.

**2.3 Right-click menus + hover actions**
- `@/components/rostering/shift-context-menu` (`ShiftContextMenu`, `ShiftCtxItem`, `ShiftCtxState`) — portal-rendered, viewport-flipping, Esc/outside-click close, icon + label + `kbd` + tone. Cleanest reuse is to copy the hook shape in `resources/js/pages/operations/handovers/components/handover-context-menu.tsx` (`useHandoverContextMenu` → returns `{ openCtx, menu }`). Wire via `onContextMenu={(e) => openCtx(e, row)}`.
- Lightweight inline row hover: `resources/js/pages/my-day/components/hover-action.tsx` (`HoverAction`).

**2.4 Cards / tables / states / badges**
- `@/components/ui/status-badge` (`StatusBadge`) **everywhere** — do not hand-map status/rating/risk/readiness/outcome colours. (Hub currently has **0** uses and ~10 bespoke colour maps — replace them all.)
- `@/components/ui/card`, `table`, `@/components/ui/empty-state` (`EmptyState`, `EmptyList`, `EmptySearch`), `error-state`, `loading-state`, `skeleton-card`, `skeleton-table`, `@/components/ui/laravel-pagination`, `@/components/ui/checkbox` (replace the raw `<input type=checkbox>` in `supervision-dialog.tsx`).

**2.5 Tabs**
- `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab(defaultTab, { param, syncUrl })`) built on `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`: `role="tablist"`, arrow/Home/End keys, `onItemContextMenu`, `decorations`, `trailing`). `performance-tabs.tsx` already wraps `HrTabs` — keep it, but (a) wire `onItemContextMenu` (§N) and (b) keep the strip rendered on **sub-routes** too (it currently drops on assess/create/show pages).

**2.6 Tokens & flourishes**
- Tokens only, from `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`, `--shadow-hero`/`--shadow-float`. Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`. **Charts (recharts) must read CSS variables, not hex** — replace the `RATING_COLORS`/`PIP_COLORS`/inline `stroke`/`fill` hex and the hand-rolled SVG radar in `competencies/profile.tsx`.
- Toasts: **sonner** (`<Toaster>` already mounted in `resources/js/app.tsx`) — `toast.success/error` on **every** action.
- Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards.

---

## A. Audit & benchmark first (do this before building)

Study `/meds/today`, `/my-day`, `/health-safety`, `/hr/people` and **interact** with them — they are the parity bar. Then open every tab listed in the header and fill in the checklist; paste the results back as your first pass.

**Checklist**
- [ ] Screenshot each current surface (all 8 tab landing pages + each sub-route + `/hr/my/reviews` + `/hr/my/goals`). Note every hand-rolled element that has a kit equivalent.
- [ ] Confirm the **two-model split**: which fields live on `hr_performance_reviews` (HR, live, tenanted; `goals`/`training_recommendations` JSON children; status `draft|in_progress|completed|signed_off`; `employee_signed_off(_at)`/`manager_signed_off(_at)`) vs governance `performance_reviews` (board-only; child tables `performance_goals`/`performance_kpis`/`performance_feedback`; status `drafting|self_review|peer_review|board_review|completed`; `approval_resolution_id`). Confirm **zero** code bridges them, and that the governance `approve()`/`submitSelfAssessment()` transitions are **unrouted**. Document it; this seeds §O #1.
- [ ] List every performance/goals/feedback/succession route that exists vs every action the new UI needs; the delta seeds §O.
- [ ] Confirm the **dead stubs**: `PerformanceReviewController@create`/`@edit`, `SupervisionController@create`/`@edit`, `GoalController@create` are redirect-only; `HrNotificationService::notifyPerformanceReviewDue()` is never invoked; `PerformanceReviewDueNotification.action_url` points at the wrong path (`/hr/performance-reviews/{id}` vs real `/hr/performance/reviews/{id}`).
- [ ] Map the **goal sprawl**: `hr_goals` (+`hr_key_results`+`hr_goal_updates`) vs `hr_development_goals` (Development tab + My-HR self-service) vs governance `performance_goals`/`strategic_goals`. Decide the unification with `GOALS_REDESIGN_PROMPT.md` (§F/§G).
- [ ] Confirm the **permission tangle**: `hr.performance.*` actually gates goals/feedback/succession too; `hr.goals.*`/`hr.skills.*` exist in a separate seeder and are largely dead with a route(`hr.performance.view`)-vs-controller(`hr.goals.*`) mismatch; `hr.feedback.*`/`hr.succession.*` don't exist; the whole `/hr/feedback` write group is gated on `.view` (create-on-view gap).

> **Known gaps the audit already surfaced** (confirm, then fix):
>
> **Cross-cutting (true of the whole hub):** generic `PageHero` everywhere (never the golden band) with static non-clickable stats; **0** `StatusBadge` / **0** `EmptyState`; no skeleton/loading/error states; **27 raw-hex** colours across `performance/index.tsx`, `reviews.tsx`, `pips/index.tsx` and a hand-rolled SVG radar in `competencies/profile.tsx`; **no right-click** anywhere; **no** bulk-select, export (CSV/PDF), sort, density toggle, deep-linking, or detail sheets; **no file/evidence upload** anywhere; **no** reminders/escalation/recurrence scheduling.
> - **Supervision (`performance/index.tsx`):** no status lifecycle on notes; employee can't acknowledge their own note; `next_session_date` is a free date with no recurrence/reminder; 1:1 SLA is read-only.
> - **Reviews (`reviews.tsx`):** status is a free-text field written by a generic `update()` — no guarded `submit`, no employee-acknowledge, no manager sign-off transition or notification, no lock after sign-off; detail opens a full page, not a sheet; create/edit are **redundant full-page routes** while `ReviewWizardDialog` already does the job. Probation lives inline (`storeProbation`/`updateProbation`) with no acknowledge.
> - **Goals & OKRs / Development:** three overlapping goal tables; no status-transition endpoints; check-in history written but not surfaced as a timeline; Development "create" is a **thin inline form**; "Add Key Result" is a **thin inline card**; one native `confirm()` (`goals/show.tsx:312`).
> - **Competencies & Skills:** "Competencies / Skills / Matrix" are three pages sharing one tab with **no sub-tab** (reachable only via hero buttons); Add Competency is a **thin inline card**, New Skill a **thin dialog**, matrix-cell assess a **thin dialog**; competency **assess** is a **full-page** form; assessments are snapshots with no sign-off (contrast the meds competency table's `assessor_declared_at`/`staff_acknowledged_at`); no `created_by` on `hr_competencies`.
> - **360 Feedback:** `request.tsx` is a **fake 4-step single page** (no back-nav, no per-step validation, no progress bar); statuses `declined`/`expired` are **unreachable** (no decline endpoint, no expiry job); no resend/remind; no bulk-request management; write-routes gated on `.view`.
> - **PIPs:** create is a **full-page** form; milestones **can't be added after creation** and have only a plain-TEXT evidence field; no edit/cancel PIP, no employee acknowledge; `pips/create` removes milestones with **no confirm**.
> - **Succession:** `index` is a **155-line flat list** — no **9-box grid / talent matrix**, no org context; create is a **full-page** form; no delete plan / remove candidate / nominate-convert; `HrTalentPool` model exists but is unused.

---

## B. Hero rethink — the golden band (NO clock, fitted to performance)

Replace the generic `PageHero` on every tab with **one** golden `MyHrHero`-style band (§2.1) spanning the hub; the active tab tunes the stats.

**Do:**
- Title: a warm, role-aware line (e.g. "Performance & development") + the org/site context line (date, your role, site) like the My HR hero meta row. **No clock card.**
- `HeroStat` cluster (clickable, deep-link into the matching tab/filter): **Reviews due**, **Overdue reviews** (amber via `--hr-amber`), **Supervisions due**, **Active goals / OKRs**, **Avg rating** (trend `delta`), **360 in-flight**, **Active PIPs** (amber when off-track), **Succession risk** (critical roles uncovered). Use `delta`/tone where you have a trend.
- `QuickAction`s on the hero, each opening the matching wizard (§L) — no dead actions: **New review**, **Log supervision**, **New goal/OKR**, **Request 360**, **Start PIP**, **Add succession plan**, **Export**.
- Compliance chips (`HeroComplianceBadges` style) where relevant (e.g. "NZ — Holidays Act / role requirements", "probation due"). Footer "Needs you" strip: overdue reviews, unacknowledged supervision notes, stalled PIP milestones, 360 requests awaiting your response.
- Re-theme via `--primary`; amber accent only for "needs attention" numbers.

---

## C. Tabs — the Performance hub shell (8 real, uniform tabs)

Keep `performance-tabs.tsx` on `HrTabs`/`TabStrip` (§2.5) but make every tab a real, kit-built surface, all gated on `auth.can.hr.performance.view` (and add proper per-domain abilities in §O):

1. **Supervision** (`/hr/performance`) — §D
2. **Reviews** (`/hr/performance/reviews`) — §E (Probation folds in here)
3. **Goals & OKRs** (`/hr/goals`) — §F
4. **Development** (`/hr/goals/development`) — §G
5. **Competencies & Skills** (`/hr/performance/competencies`) — §H (add **Competencies / Skills / Matrix** sub-tab)
6. **360 Feedback** (`/hr/feedback`) — §I
7. **PIPs** (`/hr/performance/pips`) — §J
8. **Succession** (`/hr/succession`) — §K

> Per tab: real loading (`skeleton-*`), empty (`EmptyState`/`EmptySearch`) and error (`error-state`) states; URL-synced filters (`?tab=`, `?search=`, `?status=`, etc.); a uniform **command bar** (search + sort + density toggle + filter chips + multi-select **bulk bar** + **Export**); right-click on rows **and** on the tab strip (§N). **Keep the strip rendered on sub-routes** (assess/create/show) so hub context never drops. Detail views become **sheets** where possible, or full pages reachable from any tab. Same status → same `StatusBadge` on every tab.

---

## D. Supervision tab (`performance/index.tsx`)

Premium 1:1/supervision surface. **Do:** keep the recharts trends but feed them **tokens not hex**; list supervision notes in a kit table/cards with **search, staff filter, sort, density, bulk-bar, export**; each row → **View** opens a detail **sheet**; right-click row: Open · Edit · **Acknowledge** (employee) · Schedule next · Export (§N). Wire the existing `SupervisionDialog` for create/edit (add Save-&-add-another + `WizardSuccessPane`, §L). Surface the **1:1 SLA / overdue** as a clickable hero stat. Backend adds: note status lifecycle, **employee-acknowledge endpoint**, recurrence + reminder (§O).

## E. Reviews & Probation tab (`reviews.tsx`)

**Do:** kit table + command bar (search, `?status=` filter, sort, density, bulk, export); detail opens a **sheet** (`show-review` content) with the assessment, linked goals, 360 summary, competency snapshot and a **sign-off panel**. Wire `ReviewWizardDialog` (create/edit) and `ProbationDialog` from here and the hero. Add the **lifecycle the model implies but the UI never exposes**: `Submit` (draft→in_progress), employee **Acknowledge / sign-off**, manager **Sign-off**, and **lock** after sign-off — each a guarded transition with a toast + notification (§O). Right-click row: Open · Edit · Submit · Sign off · Acknowledge · Start PIP from review · Export. Delete the **redundant** `create-review.tsx`/`edit-review.tsx` pages once the modal covers them (§L).

## F. Goals & OKRs tab (`/hr/goals`)

Carry forward `GOALS_REDESIGN_PROMPT.md`: golden hero, `HrTabs`, a **full OKR-cycle engine**, and **key-results-in-one-wizard** (no thin inline KR card). **Do:** board/list toggle, alignment (parent→child cascade) and analytics views; objective + KR + check-in are all wizard/modal flows; surface the **check-in history timeline** (data is written via the service but never shown); replace the native `confirm()` (`goals/show.tsx:312`) with `alert-dialog`. Right-click: Open · Edit · Check-in · Add KR · Cascade · Complete/Cancel · Export. Resolve the **three-table goal sprawl** toward one model with `GOALS_REDESIGN_PROMPT.md` (§O #4).

## G. Development tab (`/hr/goals/development`)

Fold in per the Goals decision. **Do:** replace the **thin inline create form** with a full **Development-goal wizard** (competency area, current→target level, milestones, linked course, due date); card grid → kit cards with status `StatusBadge`, progress ring, sort/filter/bulk/export; inline status/progress updates become a **check-in modal**. Add the **Claim development expense** action here (§M). Right-click: Open · Edit · Check-in · Link course · **Claim expense** · Complete.

## H. Competencies, Skills & Matrix tab (`/hr/performance/competencies`)

**Do:** add a **sub-tab strip** (Competencies · Skills · Matrix) so these three pages read as one tab instead of being reached via hero buttons. Promote **Add Competency** (inline→wizard), **New Skill** (thin→wizard) and **matrix-cell assess** (thin→wizard, or a polished inline `popover` for single-cell speed but with the full field set). Convert the **full-page competency `assess`** into the **Assess wizard** (employee → per-competency current/target/evidence → review). Replace the **hand-rolled SVG radar** in `profile.tsx` with a token-driven chart. Add **assessment sign-off** (assessor declared / staff acknowledged) mirroring the meds competency pattern, **evidence upload**, search/sort/bulk/export, and a `created_by` on competencies (§O). Right-click on matrix cells and rows: Assess · View profile · Export.

## I. 360 Feedback tab (`/hr/feedback`)

**Do:** rebuild `request.tsx` from a fake 4-step page into a real **Request-360 wizard** (`WizardShell`: Template → Subject & type → **Reviewers** via `PeoplePicker` (bulk) → Questions → Review), with live count preview. Make the unreachable lifecycle reachable: **decline**, **expire** (scheduled job), **resend/remind**, and **bulk request**. Kit cards/table with response-rate KPI, status filter, sort, export. Right-click: Open · Remind · Decline · Cancel · View summary. Move the feedback write-routes off `.view` onto a manage ability (§O).

## J. PIPs tab (`/hr/performance/pips`)

**Do:** convert the **full-page `pips/create`** into a **PIP wizard** (Plan details → Milestones (repeatable) → Support/owners → Review); feed the pie chart from tokens; on `show.tsx` make milestones **add / edit / reorder / delete** (today they're create-time only) with `alert-dialog` confirms and **evidence upload**; add **edit/cancel PIP**, **employee acknowledge**, and **export**. Right-click: Open · Edit · Add milestone · Record outcome · **Claim expense** (if the support plan funds training, §M) · Export.

## K. Succession tab (`/hr/succession`)

The thinnest surface — make it premium. **Do:** keep a plan list but add a real **9-box grid / talent matrix** (performance × potential) and a **readiness pipeline** (ready now / 1yr / 2yr / developing) per critical role, colour-coded by `risk_level` via `StatusBadge`. Convert the **full-page `succession/create`** into a **Succession-plan wizard**; keep `SuccessionCandidateDialog` for candidates (add Save-&-add-another). Wire the unused **`HrTalentPool`** (nominate / convert candidate → talent pool). Add delete plan / remove candidate (with `alert-dialog`), search/sort/bulk/export. Right-click on grid cells and rows: Open · Add candidate · Nominate to pool · Set readiness · Export.

---

## L. Modals = exact Add-Client wizard pattern (full, not thin)

Every flow clones `add-client-dialog.tsx` (§2.2): full-height bespoke shell, left stepper rail with completeness meter, top progress bar, per-step `validateStep`, `stepForError` jump, `WizardSuccessPane`, **Save & add another**, `forceFormData` for uploads, `toast` on success.

**Upgrade the 5 existing dialogs** (they're already `WizardShell` — bring them fully to the bar): add **Save & add another** + **`WizardSuccessPane`** to `review-wizard-dialog`, `supervision-dialog`, `goal-dialog`, `probation-dialog`, `succession-candidate-dialog`; swap the raw `<input type=checkbox>` in `supervision-dialog.tsx:355` for `@/components/ui/checkbox`; add **evidence upload** where relevant (`forceFormData`).

**Build / convert these (full wizards, retire the page/inline form after):**
1. **Development-goal wizard** (replaces the thin inline form, §G).
2. **Add Key Result wizard/step** (folds into the Goal wizard per `GOALS_REDESIGN_PROMPT.md`; kills the inline KR card).
3. **Goal / OKR check-in modal** (progress + comment + status transition; writes the check-in history timeline).
4. **Competency wizard** (replaces inline Add Competency) and **Skill wizard** (replaces thin New Skill).
5. **Competency Assess wizard** (replaces full-page `competencies/assess.tsx`): employee → per-competency current/target/evidence upload → sign-off → review.
6. **Request-360 wizard** (replaces fake-step `feedback/request.tsx`, §I) — bulk reviewers via `PeoplePicker`, live count preview.
7. **PIP wizard** (replaces full-page `pips/create.tsx`, §J) + **Add/Edit Milestone** modal with evidence upload.
8. **Succession-plan wizard** (replaces full-page `succession/create.tsx`, §K).
9. **Review sign-off modal** (Submit / Acknowledge / Sign-off transitions, §E) — confirm + optional comment, not a native dialog.
10. **Claim Expense modal** — shared component, §M.

> Wire each from its page/tab and from the hero `QuickAction`s exactly like Add-Client is wired from `index.tsx`. Destructive actions (delete goal/KR/plan/candidate, cancel PIP, expire 360, remove milestone) confirm via `alert-dialog`, **never** native `confirm()`. **Delete the redundant full-page routes** `create-review`, `edit-review`, `create-supervision`, `edit-supervision` (the modals already cover them) and redirect the stub `create`/`edit` routes to the list so old links don't 404.

---

## M. Expenses — reuse the shared "Claim Expense" modal (no parallel backend)

Per Chane: development and PIP-funded training spend should be claimable **without** building a new expense store. Reuse the **one** Claim Expense modal defined in `HR_TRAINING_REDESIGN_PROMPT.md` §I (the unified `resources/js/components/hr/claim-expense-modal.tsx` on the Add-Client shell, backed by the single `HrExpenseClaim`/`HrExpenseItem` lifecycle via `ExpenseService`/`ExpenseController`).

**Do:**
- Add a **"Claim development expense"** action on **Development goals** (§G) and a **"Claim expense"** action on **PIPs** whose support plan funds training (§J) — both open the **same** modal, pre-populated via its `prefill`/`source` prop: `category = 'training'` (or a new `development` category), `description = goal/PIP title`, hidden `source_type`/`source_id` → the `HrDevelopmentGoal` / `HrPerformanceImprovementPlan`.
- Do **not** duplicate the form body — import the shared modal. If `HR_TRAINING_REDESIGN_PROMPT.md` hasn't shipped it yet, build it there (it's the canonical owner) and consume it here.
- Surface a small "Expenses" strip on the Development goal / PIP detail showing linked claims and their `draft→submitted→approved→paid` status via `StatusBadge`.

**Backend deltas → §O:** ensure the self-service **submit** works (the known `HrExpenseClaimPolicy::create()=true` vs `hr.expenses.manage` mismatch — staff must create **and** submit their own claim); add the nullable `source_type`/`source_id` on `hr_expense_items`; add a `development` category if you don't reuse `training`. (These mostly overlap with the Training handoff — cross-reference, don't re-build.)

---

## N. Right-click everywhere (rows and tabs)

Chane explicitly wants right-click options "under tabs etc." Build a `PerformanceContextMenu` (mould of `ShiftContextMenu`, §2.3) and wire `onContextMenu` on every row and grid cell across all 8 tabs, plus the tab strip:
- **Review rows:** Open · Edit · Submit · Sign off · Acknowledge · Start PIP · Export.
- **Supervision rows:** Open · Edit · Acknowledge · Schedule next · Export.
- **Goal / KR rows:** Open · Edit · Check-in · Add KR · Cascade · Complete/Cancel · Claim expense (dev) · Export.
- **Competency / skill / matrix cells:** Assess · View profile · Sign off · Export.
- **360 rows:** Open · Remind · Decline · Cancel · View summary.
- **PIP rows & milestones:** Open · Edit · Add milestone · Record outcome · Acknowledge · Claim expense · Export.
- **Succession plan / 9-box cells:** Open · Add candidate · Nominate to pool · Set readiness · Export.
- **The tab strip itself:** right-click a tab → **Set as default view**, **Open**, **Pin**. Persist default-tab/pins to `localStorage` (allowed) so it survives reloads; render a `decorations` star/pin on the chosen tab.

Every menu action fires a toast and, where it writes, hits a real route (§O). **No dead items.** Destructive items confirm via `alert-dialog`. Show `kbd` hints.

---

## O. Backend handoff for Claude Code (append to this as you design)

> Claude design: as you build the UI and discover anything that needs server work, **add it here** with a short spec + migration sketch, so Chane has one clean list to hand to Claude Code — and copy the finished list into a new **`PERFORMANCE_BACKEND_HANDOVER.md`** at repo root. Gate manager actions on the right permission, respect `ResolvesHrTenant`, and **confirm any schema before building**. Seed list from the audit:

**Source-of-truth & data model (decision → confirm with Chane before migrating):**
1. **Reviews — unify the two stacks.** HR `hr_performance_reviews` is the live, tenanted source but anemic (goals in a JSON column, status as free text, sign-off booleans with no endpoint). Governance `performance_reviews` is richer (child tables, a service, resolution sign-off) but board-only, **un-tenanted**, and its `approve()`/`submitSelfAssessment()` transitions are unrouted. Decide: (a) make `hr_performance_reviews` canonical and **port** governance's lifecycle + real `review_goals`/`review_kpis` child tables onto it (recommended — keep governance as a tenant-scoped `review_type`), or (b) keep governance deliberately separate and **document the boundary** so nothing bridges them by accident. Provide migration + backfill + rollback sketch.
2. **Goals — collapse the sprawl.** Reconcile `hr_goals`(+`hr_key_results`+`hr_goal_updates`) vs `hr_development_goals` vs governance `performance_goals`/`strategic_goals` into the model `GOALS_REDESIGN_PROMPT.md` specifies. Confirm before migrating.

**Missing lifecycle / transition endpoints (spec → confirm → implement):**
3. **Reviews:** guarded `submit`, employee `acknowledge`, manager `sign-off` (flip the existing `*_signed_off(_at)` columns) + **lock** after sign-off; fix `PerformanceReviewDueNotification.action_url` and actually **invoke** `notifyPerformanceReviewDue()` from a scheduled command. Route the governance `approve()` so its review can reach `completed`.
4. **Supervision:** note status lifecycle, **employee-acknowledge** endpoint, **recurrence** (cadence) + `next_session_date` reminder job.
5. **Goals:** status-transition endpoints (activate/complete/cancel), a **check-in history read** endpoint (timeline), cascade/assign, due-date nudge job.
6. **360 Feedback:** `decline` endpoint, **expiry** scheduled job, **resend/remind**, **bulk request**; move write-routes off `hr.performance.view` onto a manage ability.
7. **PIPs:** `add/edit/reorder/delete milestone`, **edit/cancel PIP**, employee **acknowledge**, milestone **evidence upload**, review-date reminder job.
8. **Competencies/Skills:** competency `deactivate`/delete, `created_by` column, **assessment sign-off** columns (`assessor_declared_at`/`staff_acknowledged_at`), **evidence upload**, `update/delete skill`, bulk assess.
9. **Succession:** delete plan, remove candidate, **nominate/convert → `HrTalentPool`** (wire the unused model), readiness transitions.

**Cross-cutting (absent for the entire hub — build once, reuse):**
10. **File/evidence upload** infrastructure (a polymorphic attachment table or per-domain `*_path` columns) for reviews, goals, competency assessments, PIP milestones.
11. **Export** endpoints (CSV/PDF) for every list (reviews, supervision, goals, competencies, skills matrix, 360, PIPs, succession) — none exist.
12. **Bulk** endpoints behind the multi-select bars (bulk review-open, bulk 360-request, bulk assess, bulk reminder).
13. **Reminders/escalation scheduling** — register the performance jobs in `routes/console.php` (review due, supervision cadence, 360 due/expiry, PIP review date, goal due). Wire to the existing notification classes.
14. **Permissions cleanup:** reconcile `hr.performance.*` vs the dead `hr.goals.*`/`hr.skills.*`, add real `hr.feedback.*`/`hr.succession.*` abilities, fix the route-vs-controller gate mismatch, and add policies for goals/feedback/succession/PIP/competency/supervision (today only `HrPerformanceReviewPolicy` exists and is barely used). Add in-action `authorize()` to the governance POST actions.

**Expenses (back §M, mostly shared with Training handoff):**
15. Make self-service **submit** work (reconcile `HrExpenseClaimPolicy::create()=true` vs `hr.expenses.manage`); add nullable `source_type`/`source_id` to `hr_expense_items`; add a `development` category (or reuse `training`); wire the dev-goal/PIP prefill.

> For each item: short spec + migration (if any) and **confirm before building**. Don't silently invent schema. Retire the dead stubs (`create`/`edit` redirects) and the dead `notifyPerformanceReviewDue` only after the real flows replace them.

---

## P. Premium polish & delight

- Micro-interactions from the kit: `animate-in fade-in-0 zoom-in-95` on modals/menus, hover lifts (`--shadow-float`), skeletons on load, optimistic toasts. `motion-reduce` guards throughout.
- Optional confetti + celebratory `WizardSuccessPane` on a review signed off, a goal/OKR hitting 100%, or a PIP closed successfully (mirror the Leave self-service flourish) — tasteful, not noisy.
- Keyboard: `/` focuses search, `n` opens the tab's primary New action, arrow/Home/End on tabs, Esc closes menus/modals; surface `kbd` hints in menus.
- Live preview where it helps (Request-360 reviewer count, Goal cascade impact, Claim total) via the debounced `/preview` idiom.
- Everything re-themes via `--primary`; amber only for attention.

---

## Definition of done

- The Performance hub is **one golden hero (no clock)** + **eight real, uniform tabs** on `HrTabs`/`TabStrip`, matching the gold-standard pages, with the strip kept on sub-routes and a Competencies/Skills/Matrix sub-tab.
- Every tab has the same command layer: search, sort, density toggle, filter chips, multi-select **bulk bar**, **Export**, deep-linking, detail **sheets**, real loading/empty/error states, and **`StatusBadge`** for every status (no hand-rolled colour maps, no raw hex, no hand-rolled radar).
- Every create/edit/assess/request/check-in/sign-off/claim flow is a **full Add-Client-style wizard** (stepper rail, completeness meter, validation, review, Save & add another, `WizardSuccessPane`, uploads) — **no thin modals, no inline forms, no full-page create routes**. The 4 redundant review/supervision pages are gone; assess / pips-create / feedback-request / succession-create are now modals.
- **Reviews have a real lifecycle** (submit → acknowledge → sign-off → lock) with notifications; **360** can decline/expire/remind; **PIP milestones** are fully editable; **supervision** can be acknowledged and scheduled; **succession** has a 9-box and a wired talent pool.
- **One Claim Expense modal** is reused on Development + PIPs (source-linked); self-service submit works.
- **Right-click** works on every row/cell **and** the tab strip (Set as default / Pin); every action toasts and hits a real route. No dead items.
- **One reviews source of truth**; the goal sprawl collapsed per `GOALS_REDESIGN_PROMPT.md`; the governance review can reach `completed`.
- NZD / `en-NZ` retained; `ResolvesHrTenant` scoping and the cleaned-up `hr.*` gates respected; **no regressions** to `/hr/my`, `/governance/performance`, or finance GL posting.
- Clean `build`, `types`, `lint`; screenshots of each tab **and each modal** match the reference pages. **§O backend handoff list is filled in** and mirrored to `PERFORMANCE_BACKEND_HANDOVER.md` for Chane → Claude Code.
- **Signals to watch:** % reviews completed/signed-off on time, overdue-review queue size, supervision-cadence adherence, 360 response rate, PIP on-track rate, time-to-record a check-in, succession coverage of critical roles.

**Build order:** §A audit → §B hero → §C tab shell → §D Supervision → §E Reviews → §F Goals → §G Development → §H Competencies/Skills → §I 360 → §J PIPs → §K Succession → §L modals → §M expenses → §N right-click → §P delight pass. Verify each pass against the reference pages, and keep appending discovered backend work to **§O**.
