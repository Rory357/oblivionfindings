# HR Module Reorganisation — Progress

Self-contained loop tracker. One slice per iteration: implement → verify all gates → commit → exit.
Backlog from §C of the loop prompt. Check a slice off **only** when every gate is green and the orphan
grep is clean (scope: `resources/ app/ tests/ routes/`).

## Workbench (this worktree)
- `node_modules` → **junction** to parent `C:\Users\steph\Herd\oblivionfindings\node_modules`
  (⚠️ delete the LINK before removing this worktree or the parent's node_modules gets wiped).
- `vendor`, `.env`, `resources/js/{routes,actions,wayfinder}` (all gitignored) → **copied** from parent.
- PHP memory: Herd CLI default 128M is too low for `wayfinder:generate`/`vite build` → run with a temp
  `PHP_INI_SCAN_DIR` containing `memory_limit=1024M` in the same shell invocation.
- ⚠️ **Do NOT run prettier on touched files.** The codebase is hand-formatted (the gate is eslint, not prettier;
  eslint does NOT enforce indentation). `prettier --write` (with `prettier-plugin-organize-imports`) reorganises +
  drops unrelated imports and rewraps JSX across the *whole* file → huge noisy diffs that diverge from sibling
  style and risk conflicts with concurrent loops (esp. the shared `app-sidebar.tsx`). Hand-format new code to match
  the nearest sibling (copy `compliance-tabs.tsx` for any `*-tabs.tsx`). [Learned the hard way in S2.]
- ⚠️ **vitest is flaky under CPU contention** — the catering `MealLogDialog`/`_dialogs` mock-hoist test
  intermittently fails when vitest runs concurrently with `vite build`. Run vitest ALONE for a stable count;
  the true baseline is 5 fails / 156 (the 2 files below). Don't trust a vitest count taken during a build.

## Gates (run all; must pass before commit)
`php artisan wayfinder:generate` (if routes changed) · `npm run types` · `npm run lint` · `npm run build`
· `npm run test` · `vendor/bin/pest tests/Feature/Hr tests/Unit/Hr`

## ⚠️ Known-red baseline (PRE-EXISTING on base `e97fa742`, NOT caused by this loop)
The base branch is already red on three gates, in modules unrelated to the HR reorg. A slice is "green" when
it adds **no new** failures beyond this baseline (and ideally clears any it legitimately touches). Re-confirmed
on a clean fresh-regenerated workbench (not a stale-TS artifact).
- **types:** clean (0).
- **lint:** 0–1 error (NON-DETERMINISTIC) + ~532 warnings. The lone error = `checklists/primitives.tsx:274:14`
  "Cannot create components during render" — it intermittently reads 0 even with that file UNMODIFIED (observed in
  S4), so treat the lint gate as "no NEW error in touched files + no unrelated file swept into the commit", not an
  exact count. Warnings = `no-restricted-syntax` style nags in sites pages.
- **vitest:** 5 failures in 2 files —
  - `app-sidebar.test.ts` › "moves billing navigation into Finance" (stale after the **Finance** hub rebuild —
    test still expects /finance/billing·invoices·price-books; actual nav is Dashboard·Calendar·…). ⚠️ S10 edits
    this file's *HR* section only — do **not** fix the Finance assertion as part of HR work.
  - `my-day/index-audit-fixes.test.tsx` › 4 my-day/attendance wiring tests.
- **pest (tests/Feature/Hr):** **2 failures** (as of 2026-06-16) —
  (1) `RecruitmentJobPostingSyncTest` › "hr can sync and unpublish external posting channels" (L67:
  `external_posting_status` stays `posted`) — the long-standing baseline fail.
  (2) `ShiftPayrollBackboneIntegrationTest` › "attendance generated shift timesheet flows into payroll" (L142: pay-run
  item null) — **DATE-DEPENDENT, appeared at the 2026-06-15→16 rollover**, NOT caused by this loop. PROVEN: it fails on
  the clean S7 HEAD tree with S8 stashed. The test schedules a shift at `now()`, `travel(3)->hours()`, then runs payroll
  for `[now()->subWeek(), now()->endOfDay()]` — a day-boundary desync. Unrelated to HR reorg; do NOT "fix" it.
  Pass count ~337–338 (varies; S7 added /hr/training/catalog, S8 added /hr/goals/development render cases).

## Slices

- [x] **S1 — Webhooks → Settings only** *(half-done before loop)* ✅
- [x] **S2 — Leave Reports → Leave & Rosters tab** ✅
- [x] **S3 — Benefits → Compensation & Benefits tab** ✅
- [x] **S4 — Expenses (admin) → Comp & Benefits tab** ✅
- [x] **S5 — Skills → Performance ▸ Competencies & Skills** ✅
- [x] **S6 — Policies → Documents & Policies hub** ✅
- [x] **S7 — Training hub (pull out of Compliance)** ✅
- [x] **S8 — Goals consolidation** ✅ *(safe re-home; deeper data-merge deferred — see log)*
- [x] **S9 — Calendar hub (merge three calendars)** ✅
- [ ] **S10 — Final regroup + group split** *(last)*

## Log

### S1 — DONE
- `reports-tabs.tsx` already had Webhooks + Automations tabs removed (prior commit 184131f1) — no change needed.
- Deleted the two `Route::redirect` lines (automations + webhooks) + their comment block at `routes/hr.php` ~L546-549.
- Removed the two now-dead cases from `tests/Feature/Hr/RetiredRoutesRedirectTest.php` (clean cut → no redirects,
  so the redirect-assertion test would otherwise fail). The remaining 8 retired-route cases still pass.
- **Files touched:** `routes/hr.php`, `tests/Feature/Hr/RetiredRoutesRedirectTest.php`, `HR_REORG_PROGRESS.md`.
- **Orphan grep (scope resources/ app/ tests/ routes/):** `reports/webhooks|reports.webhooks` → 0 matches;
  `reports/automations|reports.automations` → 0 matches. Clean.
- **Gates:** wayfinder regen clean (redirects were unnamed → no helper diff) · types 0 · build exit 0 ·
  `RetiredRoutesRedirectTest` passes · lint/vitest/pest at known-red baseline (no new failures).
- Note: `docs/hr-module-rebuild-plan.md:473` still mentions `reports/webhooks` as historical narrative — that is
  **out of the orphan-check scope** and is accurate history, so left untouched.

### S2 — DONE
- The Leave hub was un-standardised (bespoke Radix `Tabs` in `index.tsx`: Dashboard·Requests·Balances·Reports,
  where Balances/Reports were stub "Open X" link-outs and there was no Holidays tab). `/leave/balances`,
  `/leave/holidays`, `/leave/reports` are separate routes/pages, all behind the same `hr.leave.viewAny` gate.
- Followed the canonical multi-page hub pattern (mirrors `ComplianceTabs`): created
  `resources/js/components/hr/leave-tabs.tsx` → `LeaveTabs` (Requests·Balances·Holidays·Reports, `router.visit`
  per-tab URL, gated `hr.leave.viewAny`, active always shown); exported it from the `@/components/hr` barrel;
  rendered `<LeaveTabs active="…" />` on all four leave pages so every hub page carries the strip.
- `index.tsx`: removed the bespoke Radix `Tabs` (golden rule #4); stacked the Dashboard analytics (Overview) +
  the Requests management under `<LeaveTabs active="requests" />` — **all content preserved**, nothing dropped.
  Removed now-unused imports (`Tabs*`, `LayoutDashboard`, `List`).
- `app-sidebar.tsx`: removed the standalone "Leave Reports" sidebar item (Reports is now a hub tab). `FileText`
  import kept (23 other uses). The "Leave & Rosters" hub entry stays.
- **Permissions frozen:** all 4 tabs share the exact same `hr.leave.viewAny` gate the routes already enforce.
- **No routes removed → no orphan grep** (the `/hr/leave/reports` route stays; it's now a tab, not a sidebar item).
- **Decision (documented):** `reports.tsx` keeps its inner Absenteeism/Bradford/Utilisation Radix tabs as
  *content sub-views* (visually distinct from the hub strip). Rule #4 targets hub-navigation strips; stacking a
  second HrTabs chip-strip has no precedent in the codebase (grep: no hub page mixes `*Tabs active=` with
  `@/components/ui/tabs`) and would blur hub-vs-content hierarchy.
- **Files touched:** NEW `components/hr/leave-tabs.tsx`; `components/hr/index.ts`, `components/app-sidebar.tsx`,
  `pages/hr/leave/{index,balances,holidays,reports}.tsx`, `HR_REORG_PROGRESS.md`.
- **Gates:** types 0 · lint 1-err (baseline, no new in touched files) · build exit 0 · vitest 5-fail/156
  (baseline, clean run) · pest 1-fail (baseline RecruitmentJobPostingSyncTest), 336 pass — run once this slice;
  final hand-formatted version has **zero PHP diff** so pest is unchanged.
- **Hand-formatted, no prettier** (see Workbench note). `index.tsx`: the un-wrapped Overview+Requests content sits
  one indent level deep under the new `<div class="space-y-4">` — left as-is so the ~700 content lines stay
  **unchanged in the diff** (easy to verify nothing in them was altered); eslint doesn't enforce indentation.
  Net `index.tsx` diff ≈ 86 lines (deleted bespoke Tabs scaffold + import tweaks), `app-sidebar.tsx` = −5 lines.
- **visual:test deferred:** Playwright screenshot snapshots are platform-specific (Windows renders ≠ Linux/CI
  snapshots — same Win↔Linux divergence as the Vite-hash note) and there's no served app in this headless
  worktree; running it yields false diffs and updating would corrupt cross-platform snapshots. Live visual parity
  is the user's browser check per the loop DoD.

### S3 — DONE
- Benefits was a standalone mini-hub: `/hr/benefits` (enrollments) + `/hr/benefits/plans` + POST/PUT actions,
  gated `hr.benefits.view`/`manage`. Clean-cut **re-home** into the Compensation hub (no redirect).
- **Routes** (`routes/hr.php`): re-prefixed the group `benefits` → `compensation/benefits` and renamed
  `hr.benefits.*` → `hr.compensation.benefits.*`. **Kept the `permission:hr.benefits.view`/`manage` gates** as a
  SEPARATE group (not nested in the `hr.compensation.view` group) so permissions stay frozen (golden rule #3).
- **Pages**: `git mv` `pages/hr/benefits/{index,plans}.tsx` → `pages/hr/compensation/benefits/` (git detected
  renames R098/R097, history preserved). Updated their breadcrumbs + body URLs `/hr/benefits` →
  `/hr/compensation/benefits`, added `<CompensationTabs active="benefits" />` to each (Inertia resolves pages by
  glob, so no import to fix; `BenefitsController` render paths `'hr/benefits/…'` → `'hr/compensation/benefits/…'`).
- **CompensationTabs** rewritten to the per-tab-gated pattern (mirrors ComplianceTabs): bands/reviews/bonuses gated
  `hr.compensation.view`, the new **Benefits** tab gated `hr.benefits.view` (cross-gate, no 403-on-click), active
  always shown. Hub renamed "Compensation & Benefits".
- **Sidebar**: removed the standalone "Benefits" item; renamed "Compensation" → "Compensation & Benefits" with a
  **conditional href** (`compensation.view ? /…/bands : /…/benefits`) so a benefits-only user keeps a working hub
  entry (no access lost). `Shield` import still used (13 other uses).
- **Tests**: re-homed `/hr/benefits` → `/hr/compensation/benefits` in BenefitsEnrollmentTest + BenefitPlanLifecycleTest
  (Feature, in gate) + HrBenefitsTest (Browser). The 336 pest passes are UNCHANGED in count — same tests, new URLs.
- **Orphan grep (resources/ app/ tests/ routes/):** `hr/benefits` → 0, `benefits.index` → 0. The remaining
  `hr.benefits.` matches are exclusively the FROZEN permission key `hr.benefits.view`/`manage` (+ one comment).
- **Files touched:** `routes/hr.php`, `app/Http/Controllers/Hr/BenefitsController.php`, `components/hr/compensation-tabs.tsx`,
  `components/app-sidebar.tsx`, moved `pages/hr/compensation/benefits/{index,plans}.tsx`, 3 test files, tracker.
- **Gates:** wayfinder exit 0 · types 0 · lint 1-err (baseline) · build exit 0 · vitest 5/156 (baseline, run alone)
  · pest 1-fail (baseline RecruitmentJobPostingSyncTest), 336 pass (benefits tests pass at new URLs). Hand-formatted.
- **Discovered:** the Benefits *Plans* page had no UI link from the Enrollments page (reachable only by URL) — a
  pre-existing nav gap, preserved as-is (not adding sub-nav = no scope creep).

### S4 — DONE
- Admin Expenses was a mini-hub: `/hr/expenses` (index) + `/create` + `/{claim}` (show) + submit/approve/reject/pay,
  gated `hr.expenses.view`/`manage`/`approve`. **Re-homed** to `/hr/compensation/expenses` exactly like S3 (clean cut,
  no redirect), for a coherent Comp & Benefits hub (all tabs now under /hr/compensation/*).
- **Routes** (`routes/hr.php`): re-prefixed `expenses` → `compensation/expenses`, renamed `hr.expenses.*` →
  `hr.compensation.expenses.*`, KEEPING the `permission:hr.expenses.view`/`manage`/`approve` gates frozen.
- **Pages**: `git mv` `pages/hr/expenses/{index,create,show}.tsx` → `pages/hr/compensation/expenses/` (renames
  R097/R098). `<CompensationTabs active="expenses" />` added to **index only** (the tab landing); create/show are
  claim form/detail sub-pages with their own back-nav (URL re-homed, no strip — analogous to S3). Controller render
  paths + the post-store redirect re-homed (`hr/expense-receipts` storage path correctly NOT matched).
- **CompensationTabs**: added the **Expenses** tab gated `hr.expenses.view` (now 5 tabs; benefits+expenses cross-gate).
- **Notifications**: ExpenseApproved/SubmittedNotification action URLs `/hr/expenses/{id}` → `/hr/compensation/expenses/{id}`
  (the show page moved). [Discovered: those notify links point at the admin show page (hr.expenses.view) even for the
  worker claimant — pre-existing, preserved.]
- **Sidebar**: removed the standalone "Expenses" item; the "Compensation & Benefits" hub entry now shows for
  compensation OR benefits OR **expenses** view, with a nested-ternary href landing each user on their first
  accessible tab (no access lost for an expenses-only user).
- **Tests**: re-homed `/hr/expenses` → `/hr/compensation/expenses` in ExpensePaymentTest + ExpenseReceiptUploadTest
  (Feature) + HrExpensesTest (Browser). **`/hr/my/expenses` (MyHrController) untouched** (personal lens; not matched
  by the `hr/expenses` grep).
- **Orphan grep (resources/ app/ tests/ routes/):** `hr/expenses` → 0, `expenses.index` → 0; remaining `hr.expenses.`
  matches are exclusively the FROZEN permission keys (view/manage/approve).
- **Files touched:** routes/hr.php, ExpenseController.php, 2 notification classes, compensation-tabs.tsx, app-sidebar.tsx,
  moved pages/hr/compensation/expenses/{index,create,show}.tsx, 3 test files, tracker.
- **Gates:** wayfinder exit 0 · types 0 · lint 0-err (≤ baseline — the checklists/primitives.tsx baseline error read
  as 0 this run with that file UNMODIFIED, i.e. it's non-deterministic; no NEW errors in touched files, no unrelated
  files swept into the commit) · build exit 0 · vitest 5/156 (baseline, run alone) · pest 1-fail (baseline), 336 pass
  (expense tests pass at new URLs). Hand-formatted (no prettier).

### S5 — DONE
- Skills was a standalone mini-hub (`/hr/skills` index + `/hr/skills/matrix` + store/assess) gated
  `hr.performance.view`/`manage` at the ROUTE, but its sidebar item was gated `hr.skills.view` (a separate perm) and
  lived in the "records" group. Competencies (`/hr/performance/competencies`) is already a Performance-hub tab.
- **Re-homed** Skills under the Performance hub (clean cut, no redirect): routes group `skills` →
  `performance/skills`, names `skills.*` → `performance.skills.*` (gates kept). `git mv` pages →
  `pages/hr/performance/skills/` (renames R093/R098); SkillsController render paths re-homed; URLs re-homed in both
  pages + HrMiscTest (Browser).
- **Merged into the Competencies tab:** renamed the PerformanceTabs `competencies` tab label
  "Competencies" → "**Competencies & Skills**". Both skills pages now render `<PerformanceTabs active="competencies" />`
  so they sit inside the hub under that tab. Bridged the two page-sets with hero links: competencies index → "Skills";
  skills index → "Competencies" (+ its existing "Skills Matrix"). (Re: the active-tab is a no-op when you're already
  on a skills page — the hero links are the intra-section bridge; the strip is the outer Performance-hub nav.)
- **Sidebar:** removed the standalone "Skills" item (was gated `hr.skills.view` in the records group). No real access
  lost — the skills ROUTE already required `hr.performance.view`, so a skills.view-only user got 403 there before too;
  performance.view users reach Skills via the hub. `Target` import still used (6 other uses).
- **Permissions frozen:** route gate stays `hr.performance.view`/`manage`; SkillsController still accepts
  `hr.skills.*` OR `hr.performance.*` (untouched).
- **Orphan grep (resources/ app/ tests/ routes/):** `hr/skills` → 0, `skills.index|skills.matrix` → 0. Remaining
  `hr.skills.` matches are exclusively the FROZEN permission keys in SkillsController::canView/canManage.
- **Files touched:** routes/hr.php, SkillsController.php, performance-tabs.tsx, competencies/index.tsx, app-sidebar.tsx,
  moved pages/hr/performance/skills/{index,matrix}.tsx, HrMiscTest.php, tracker.
- **Gates:** wayfinder exit 0 · types 0 · lint 0-err (no new in touched files, no unrelated staged file) · build exit 0
  · vitest 5/156 (baseline, run alone) · pest 1-fail (baseline), 336 pass. NB: no skills-specific pest Feature test
  exists, so the re-homed route resolution is covered by types/build/wayfinder + the Browser HrMiscTest, not pest.
  Hand-formatted (no prettier).

### S6 — DONE
- Policies was a 5-page mini-hub (`/hr/policies` index + show/edit/create/attestations + store/version/attest/download),
  controllers PolicyController + PolicyAttestationController, gated `hr.policies.view`/`manage`/`attest`. **Re-homed**
  to `/hr/documents/policies` and folded into the Documents hub (clean cut, no redirect).
- ⚠️ **Kept the route NAME `policies.`** (only changed the prefix `policies` → `documents/policies`). Policies has
  `redirect()->route('hr.policies.index')` in the controller — keeping the name means those route() calls stay valid
  with ZERO controller-route edits; only the URL changes. (S3/S4/S5 renamed names because they had no route() calls.)
- **Pages**: git mv all 5 → `pages/hr/documents/policies/` (renames R095–R098); controllers' Inertia::render paths
  re-homed; URLs re-homed in the 5 pages + 2 tests + the **PolicyAttestationDueNotification** action URLs (a file the
  first orphan-grep CAUGHT — re-ran grep after fixing → clean).
- **DocumentsTabs**: added a per-tab-gated **Policies** tab (`hr.policies.view`, cross-gate vs documents.view); the
  policies index now carries `<DocumentsTabs active="policies" />`. Hub renamed "Documents & Policies".
- **Sidebar**: merged the separate "Documents" + "Policies" items into one "Documents & Policies" entry (shown for
  documents OR policies view, href lands a policies-only user on /hr/documents/policies). `FileText` import still used.
- **`/hr/my/policies` UNTOUCHED** (MyHrController personal lens). Its page's admin-download link
  `/hr/policies/{id}/download` WAS re-homed to `/hr/documents/policies/{id}/download` (that's an admin-route
  cross-reference, not the personal lens — `/hr/my/policies` itself never matched the `hr/policies` substring).
- **Orphan grep (resources/ app/ tests/ routes/):** `hr/policies` → 0. The only `hr.policies.` matches are the FROZEN
  permission keys (view/manage/attest) + the 2 VALID `route('hr.policies.index')` calls (kept name).
- **Files touched (13):** routes/hr.php, PolicyController.php, PolicyAttestationController.php,
  PolicyAttestationDueNotification.php, documents-tabs.tsx, app-sidebar.tsx, moved
  pages/hr/documents/policies/{index,show,edit,create,attestations}.tsx, my/policies.tsx, 2 test files, tracker.
- **Gates:** wayfinder exit 0 · types 0 · lint 0-err (no new in touched, no unrelated staged) · build exit 0 · vitest
  5/156 (baseline, run alone) · pest 1-fail (baseline), 336 pass (PolicyShowContentTest passes at new URL). Hand-formatted.
- **Discovered:** strip added to the policies INDEX only (show/edit/create/attestations are sub-pages with backHref) —
  matches the S4 pattern; revisit if every hub sub-page should carry the strip.

### S7 — DONE
- NOT a re-home — CREATED a standalone Training hub by pulling Training out of Compliance. Training was split: the
  **dashboard** at the odd URL `/hr/compliance/training` (TrainingDashboardController, name `hr.training.index`,
  StaffTrainingRecord data) + the **catalog** at `/hr/training/catalog` (TrainingController). Pages already lived under
  `pages/hr/training/` so NO file moves.
- **Route**: re-homed the dashboard URL `/hr/compliance/training` → `/hr/training` (kept name `training.index`, so the
  `redirect()->route('hr.training.index')` in routes/training.php stays valid). Catalog/course routes unchanged.
- **New `components/hr/training-tabs.tsx`** (TrainingTabs): **Dashboard** (`/hr/training`) + **Catalog**
  (`/hr/training/catalog`), gated `hr.training.view`. ⚠️ **HONEST 2 tabs, not §A's "Catalog·Assignments·Records"** —
  there is NO built "Assignments" page (enrollments are an action on the course-detail page, not a page); the dashboard
  IS the records view. Did not invent an Assignments tab. Exported from the barrel.
- training/index.tsx + catalog.tsx now render `<TrainingTabs active="dashboard|catalog">` instead of `<ComplianceTabs>`;
  their `/hr/compliance/training` URLs re-homed to `/hr/training`.
- **compliance-tabs.tsx**: removed the `training` + `catalog` tabs (+ their now-unused GraduationCap/BookOpen imports +
  the `training` HrCan field + the type-union members). Compliance hub now: Overview·Matrix·Calendar·Vetting·Drivers.
- **Sidebar**: added a new "Training" item (`/hr/training`, GraduationCap, gated `hr.training.view`) under the
  **Performance** group (→ "Performance & Development" per §A; S10 renames the group label). There was NO standalone
  Training sidebar item before (it was only a Compliance tab).
- **Tests**: re-homed `/hr/compliance/training` → `/hr/training` in HrComplianceTest + TrainingTest (Browser); the
  orphan-grep CAUGHT a missed Feature test HrHubTabsRenderTest (had `/hr/compliance/training` in its render list) →
  moved it to a new "Training hub" section with both `/hr/training` + `/hr/training/catalog` (so pest now renders both
  new pages → +1 pass, 337).
- **`/hr/my/training` (personal) UNTOUCHED.**
- **Orphan grep (resources/ app/ tests/ routes/):** `compliance/training` → 0. The `compliance.training` dotted
  matches are exclusively the UNRELATED governance metric `compliance.training_compliance` (PerformanceReviewService).
- **Files touched (10):** routes/hr.php, NEW training-tabs.tsx, components/hr/index.ts, compliance-tabs.tsx,
  app-sidebar.tsx, training/{index,catalog}.tsx, HrComplianceTest.php, TrainingTest.php, HrHubTabsRenderTest.php, tracker.
- **Gates:** wayfinder exit 0 · types 0 · lint 0-err (no new, no unrelated staged) · build exit 0 · vitest 5/156
  (baseline) · pest 1-fail (baseline), 337 pass. Hand-formatted (no prettier).

### S8 — DONE (safe re-home; deeper data-merge deferred)
- THREE goals surfaces: (1) `/hr/goals` (GoalController → **HrGoal**, full OKR system: objectives + key-results +
  parent goals + progress) = the canonical Performance ▸ Goals & OKRs tab; (2) `/hr/development/goals`
  (DevelopmentGoalController → **HrDevelopmentGoal**, employee development plans: competency_area, target/current_level,
  review_frequency, manager-assigned); (3) `/hr/my/goals` (personal — KEPT). Both admin gates = `hr.performance.view`/`manage`.
- ⚠️ **GoalController (HrGoal/OKR) and DevelopmentGoalController (HrDevelopmentGoal) are GENUINELY DIFFERENT data
  models.** A data-level merge would lose the dev-goal-specific fields and is unsafe for one slice. Per the loop's
  explicit guidance, did the SAFE part: **re-homed `/hr/development/goals` → `/hr/goals/development`** (under the Goals
  umbrella), kept both models + both controllers. **DEEPER MERGE DEFERRED** (see Discovered).
- **Routes**: dev group re-prefixed `development` → `goals/development` (inner routes `/goals`→`/`), kept name
  `development.`. The dev group registers at L499, BEFORE the GoalController `/{goal}` show route at L786, so GET
  `/hr/goals/development` matches the dev route (no conflict with `/hr/goals/{goal}`). DevelopmentGoalController render
  path re-homed.
- **Page**: git mv `pages/hr/development/goals.tsx` → `pages/hr/goals/development.tsx` (R098); added
  `<PerformanceTabs active="goals" />` + a "Goals & OKRs" breadcrumb parent so it sits under the Performance hub.
- **References re-homed**: DevelopmentGoalAssignedNotification URL, goals/show.tsx backHref, performance/index.tsx link,
  HrGoalsTest + DevelopmentGoalWorkflowTest. Added `/hr/goals/development` to HrHubTabsRenderTest (renders 200).
- **`/hr/my/goals` UNTOUCHED**; the canonical `/hr/goals` OKR system UNTOUCHED (allowed to remain per done-criterion).
- **Orphan grep:** `hr/development/goals` → 0 (incl. the wayfinder-generated TS after regen — RE-GREP after regen was
  essential, the stale generated files showed the old URL pre-regen). `hr/goals\b` matches only the canonical /hr/goals
  + the new /hr/goals/development (both the Goals umbrella) + /hr/my/goals isn't matched (the `my/` segment).
- **Files touched (9):** routes/hr.php, DevelopmentGoalController.php, DevelopmentGoalAssignedNotification.php, moved
  pages/hr/goals/development.tsx, goals/show.tsx, performance/index.tsx, HrGoalsTest.php, DevelopmentGoalWorkflowTest.php,
  HrHubTabsRenderTest.php, tracker.
- **Gates:** wayfinder exit 0 · types 0 · lint baseline (no new in touched, no unrelated staged) · build exit 0 · vitest
  5/156 (baseline) · pest 2-fail/337-pass — the 2nd fail (ShiftPayrollBackboneIntegrationTest) is the PROVEN-environmental
  date-rollover fail (stash-verified on clean HEAD), NOT an S8 regression; my new /hr/goals/development render case passes.

### S9 — DONE (frontend-only)
- THREE calendar surfaces: (1) `/hr/calendar` (CalendarController — the company "Schedule" event calendar, FullCalendar
  + event CRUD, gated hr.calendar.view); (2) `/hr/calendar/time-off` (TimeOffCalendarController — who's off, hand-rolled
  grid, AUTH-ONLY `abort_unless($user)`); (3) `/hr/compliance/calendar` (ComplianceCalendarController — cert/vetting
  RENEWAL dates, a tab in compliance-tabs gated hr.compliance.view).
- **NO route/controller changes** — purely a nav/tab reorganisation (so no wayfinder regen; pest unaffected = baseline).
- **New `components/hr/calendar-tabs.tsx`** (CalendarTabs): **Schedule** (/hr/calendar, gated hr.calendar.view) +
  **Time Off** (/hr/calendar/time-off, always shown — the controller is auth-only). Exported from the barrel.
  calendar/index.tsx renders `<CalendarTabs active="schedule">`; calendar/time-off.tsx renders `active="timeoff"`.
- **Compliance hub**: renamed the compliance-tabs `calendar` tab LABEL "Calendar" → "**Renewals**" (same id/URL
  /hr/compliance/calendar + same data — it's the cert/vetting renewal calendar; ComplianceCalendarController KEPT).
- **Sidebar consolidation**: removed the standalone "Time Off Calendar" item (Time & Leave) AND the separate "Calendar"
  item (was in the Payroll & Admin group); replaced with ONE "Calendar" entry **under Time & Leave** →
  `can.hr.calendar.view ? /hr/calendar : /hr/calendar/time-off` (cross-gate href so a leave-only viewer still lands on
  a page they can open). leave/index.tsx Quick-Link to /hr/calendar/time-off left as a valid cross-link.
- **"Orphan" interpretation:** `/hr/calendar/time-off` legitimately REMAINS (the route + the Time Off hub-tab target +
  the controller render path + the leave quick-link); what was removed is the standalone/duplicate NAV entry. Done.
- **Files touched (6, frontend-only):** NEW calendar-tabs.tsx, components/hr/index.ts, compliance-tabs.tsx,
  calendar/index.tsx, calendar/time-off.tsx, app-sidebar.tsx, tracker.
- **Gates:** types 0 · lint 0-err (no new, no unrelated staged) · build exit 0 · vitest 5/156 (baseline) · pest NOT
  re-run (ZERO PHP/route diff → provably at the 2-fail baseline). Hand-formatted (no prettier).

## Discovered (out of scope — do not fix here)
- **S8 DEEPER MERGE:** HrDevelopmentGoal (dev plans) and HrGoal (OKRs) are distinct models/features sharing the
  hr.performance.view gate. Truly unifying them (one model/UI for "Goals") is a product+migration decision that would
  drop dev-goal fields (competency_area, target/current_level, review_frequency) — needs design sign-off, not a reorg
  slice. Re-homed under /hr/goals/development; left as two surfaces under one umbrella.
- Leave page breadcrumbs are inconsistent (`Leave` vs `Leave Balances` vs `Leave & Rosters`); not touched.
- `reports.tsx` inner content tabs remain Radix (see S2 decision above) — revisit only if a global "content
  sub-tabs → HrTabs" convention is adopted.
- `RetiredRoutesRedirectTest.php` encodes the *previous* rebuild's "retire → redirect, never 404" philosophy,
  which is the opposite of this reorg's clean-cut rule. Only the slice-relevant cases are removed per slice.
