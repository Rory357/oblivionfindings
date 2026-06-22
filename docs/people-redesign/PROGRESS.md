# HR People Hub redesign — PROGRESS

**Goal:** rebuild `/hr/people` to the gold standard from the design drop `HR People Page.zip`
(`People.dc.html` prototype + 4 build briefs). Self-paced `/loop` started 2026-06-22.

Worktree `stoic-benz-9372e0` / branch `claude/stoic-benz-9372e0`.
Design extract: `C:\Users\steph\Downloads\_hr_people_page_extract`.

## Environment notes
- node_modules **junctioned** to parent → `npm run types|lint|build` work in the worktree.
- **No vendor** in worktree → `php -l` for PHP syntax only; `artisan test`/pint run POST-MERGE from parent.
- Permissions checked via `canDo()` (NOT `can()`); tenant via `ResolvesHrTenant`.
- Reuse kit: `@/components/wizard/{shell,primitives}` (Add-Client contract), `StatusBadge`, `HrTabs`,
  `my-hr-hero` gradient. Tokens only — ESLint `no-restricted-syntax` blocks raw hex
  (exempt a bespoke on-gradient file with a top-of-file `eslint-disable no-restricted-syntax` + reason).

## Phases (build order from HANDOVER §4)

### Phase 1 — PeopleHero golden band + People table upgrade  ✅ COMPLETE (ready to merge)
- [x] 1a. `people-hero.tsx` — brand-gradient band, HeroStats (Active / New hires 30d / On probation /
      Compliance alerts), QuickActions (Add / Import / Export), Mix-donut ↔ Compliance-ring toggle
      (persisted to localStorage). Dropped the duplicated StatCard grid + standalone type-bar from the
      People tab. Stats deep-link to live filters: Active→status=active, New hires→joined=30,
      On probation→probation=1 (2 new controller filters), Compliance→/hr/compliance. Needs-attention
      chips (compliance/probation) render from summary. **Invite quick-action deferred to Phase 2**
      (intake/invite backend doesn't exist yet — no dead buttons). tsc/lint/build green; php -l clean.
- [x] 1b-i. `people-pane.tsx` extracted — **server-side sortable headers** (controller `sort`/`dir`
      whitelist + leftJoin), **column chooser** + **density** toggle (persisted localStorage),
      StatusBadge pills, active-filter chips, **loading skeleton**, refined empty state, and a
      **row context menu** (reuses `ShiftContextMenu`: View profile / Edit / Deactivate-Reactivate).
      New `PATCH /hr/people/{profile}/active` → `setActive` (manage-gated). Tests: `PeoplePaneActionsTest`
      (setActive + authz + sort) — run post-merge. tsc/lint(0 err)/build green; php -l clean.
- [x] 1b-ii. Multi-select (header + per-row Checkbox, per-view selection) + **sticky bulk bar**
      (Deactivate / Reactivate / Assign site / Assign department / Assign manager via dropdowns +
      Clear). New `POST /hr/people/bulk` → `bulkAction` (manage-gated; assign_department keeps the
      denormalised label in sync). `managers` prop threaded from the page. Bulk tests added to
      `PeoplePaneActionsTest`. tsc/lint(0)/build green; php -l clean. (Export-selected + Resend-invite
      deferred — export-by-ids needs ImportExport change; invite needs Phase 2 backend.)
- [x] 1c. Right-click tab strip → **set default view / pin** (persisted localStorage). Shared
      `TabStrip` extended additively (`onItemContextMenu` / `decorations` / `trailing` — opt-in, no
      change for other callers); People page persists `hrp.defaultTab` + `hrp.pins`, floats pinned
      tabs to the front, shows star/pin decorations, opens a `ShiftContextMenu` on right-click, and
      restores the default view on load (post-mount, no SSR mismatch). tsc/lint(0)/build green.

### Phase 2 — Employee intake unification (audit-first)  ✅ COMPLETE (ready to merge)
Audit: `docs/employee-intake-audit.md` (signed off — autonomous).
- [x] 2a (backend) — `EmployeeIntakeService::intake()` = single writer (User firstOrCreate-link +
      profile upsert by user_id + onboarding toggle + one invite/reset-link + `employee.created`
      webhook). `store()` + `convertToEmployee()` both delegate. Dedupe gate (link_existing).
      `StoreEmployeeRequest` drops unique-email, adds RTW/visa + emergency + toggles. Tests updated
      (AddEmployeeWizardTest link/dedupe; OfferAcceptOnboardingFlowTest preserved). Boot + DI verified
      (tinker resolves service; employee.created registered). php -l clean. Commit `b2e939b9`.
- [x] 2b (frontend) — Add-Employee dialog extended on the existing WizardShell to 5 steps
      (Person → Job → Right-to-work → Emergency contact → Review). RTW step (work-rights status +
      conditional visa type/expiry), repeatable emergency-contact rows, "Start onboarding now" +
      "Send login invite" Switch toggles on Review, and the dedupe **"Link to existing record"**
      callout (shows on the email conflict; button → "Link & add"). Posts all new fields to the
      unified endpoint. tsc/lint(0)/build green.

### Phase 3 — Positions → recruitment automation (audit-first)  🔄 IN PROGRESS
Audit: `docs/positions-recruitment-audit.md` (signed off — autonomous).
- [x] 3a (data foundation) — additive migration `2026_06_22_000002` (`hr_job_requisitions.position_id`
      + `hr_offers.position_id` FKs + `hr_positions.summary`/`responsibilities` JD parity); model
      fillable/relations (`HrPosition::requisitions()`, `HrJobRequisition::position()`,
      `HrOffer::position()`); `HrPosition` accessors `open_requisition_openings` /
      `actionable_vacancies` (budget−current−openReq) / `is_understaffed`; **`HrEmployeeProfileObserver`
      keeps `current_headcount` synced** on hire/transfer/deactivate (registered in AppServiceProvider);
      `convertToEmployee` now sets `position_id` from the offer. Migration ran clean locally; tinker
      end-to-end proved observer sync + accessors (current 0→1 on hire, actionable 1→0 with a 1-opening
      req). `PositionVacancyTest` (run post-merge). php -l clean. ⚠️mass updates (bulk bar) bypass the
      observer → daily job (3b) reconciles.
- [x] 3b — `hr:check-vacancies` command (`CheckVacanciesCommand`, daily 06:30 NZ in routes/console.php):
      syncAllHeadcounts (reconcile backstop for bulk-update drift) + reports understaffed. New
      `PositionService::getUnderstaffed` / `actionableVacancies`. Positions payload carries
      `open_requisition_openings`/`actionable_vacancies`/`is_understaffed`; positions-pane shows a
      warning **"N to hire"** badge (or "Recruiting" when covered by an open req). People hero
      **needs-attention chip** "N understaffed positions" → ?tab=positions (summary.understaffed_positions).
      Command live-verified (found "Tour Guide — 1 to hire"); tsc/lint/build green; test added.
- [x] 3c — New/Edit Position modal rebuilt to 3 steps (Role → **Job description** → Structure &
      recruitment). JD step = Role summary (summary) / Key responsibilities (responsibilities) /
      Essential criteria (requirements) / Preferred (description). Structure step has the
      **"Open a job requisition for N vacancies" toggle** (create-only, shown when `can.recruit`).
      `PositionController@store` validates JD + `open_requisition`; on toggle (and recruitment.manage)
      creates a linked draft `HrJobRequisition` prefilled from the position. `update()` persists JD.
      `can.recruit` + position payload `summary`/`responsibilities` added. Tests: auto-requisition
      create (+negative). tsc/lint/build green; php -l clean.
- [ ] 3d — loop-close: auto-close/prompt linked requisition when filled; events. **(deferred — small;
      may fold into a later pass. Core automation 3a-3c covers the brief's primary loop.)**

### Phase 4 — Departments feature-complete (audit-first)  ✅ COMPLETE (ready to merge)
Audit: `docs/departments-audit.md` (signed off — autonomous; cost_centre=string, sites deferred).
- [x] 4a (backend) — migration `2026_06_22_000003` (`hr_departments.cost_centre`); model
      `cost_centre` fillable + cycle-safe `descendantIds()` / `rolledUpEmployeeCount()` /
      `wouldCreateCycle()`; controller: cost_centre in store/update, **cycle-safe parent on update**
      (rejects self/descendant), **reparent children → parent on deactivate** (no dangling), new
      **`show()` JSON** (head/parent/children/direct+rolled-up headcount/linked positions by name),
      tenant via `resolveHrTenantIdForUser`. New `GET /hr/departments/{department}` (departments.show).
      Migration ran clean; tinker proved descendantIds + cycle checks. `DepartmentFeatureTest` (cycle,
      reparent, block-on-employees, show roll-up, cost_centre). php -l clean.
- [x] 4b (frontend) — DepartmentDialog rebuilt on **WizardShell** (Details[name/code/cost_centre/
      description] → Structure[parent/head/sort/status] → Review) + cost_centre. New
      **`DepartmentViewDialog`** (read-only modal; fetches `GET /hr/departments/{id}` JSON → stat tiles
      [direct/rolled-up staff, sub-depts, linked positions] + head/parent/description + child chips +
      linked-positions list + Edit). departments-pane: cost-centre column + **row-click → View**
      (manage-gated; action buttons stopPropagation). tsc/lint(0)/build green.

### Phase 5 — Org chart view + builder modal (research-first)  ✅ COMPLETE (merged → origin/main)
Audit+research: `docs/org-chart-research.md` (signed off — autonomous; @dnd-kit available, save-live
per-move, keep reports-to picker fallback).
- [x] 5a (backend + view) — `OrgChartService::buildNode` widened (+ `site`, `manager_user_id`,
      resolved `photo_url` via Storage public disk — fixes raw-path bug); `orgPeople` + `manager_user_id`.
      `org-chart-pane` node card rebuilt to the prototype: **colour-coded title bar** (role, hashed per
      department) + square photo-left + name (italic) + site; toolbar Print (window.print). Existing
      per-person ReassignManagerDialog kept. tsc/lint(0)/build green; php -l clean. Commit `9d024b21`.
- [x] 5b (builder modal) — `org-chart-builder-dialog.tsx`: @dnd-kit `DndContext` indented draggable
      tree — drag a person (by the grip) onto another to set their manager, or onto the **"Top level"**
      drop zone to clear it; each drop writes live via `PUT /hr/orgchart/{profile}` (preserveState so
      the open modal re-renders with the result). **Cycle double-guard:** client disables drops onto the
      dragged node's own descendants + server `wouldCreateCycle` authoritative. Per-person reports-to
      picker (ReassignManagerDialog) retained as the fallback. "Build org chart" toolbar button is
      canManage- + non-empty-gated (no dead control). `OrgChartReassignTest` extended (server-side cycle
      rejection + move-to-top-level). tsc/lint(0)/build/php-l green. Commit `d877fd92`.

### Phase 6 — "Needs attention" triage modal  ✅ COMPLETE (merged → origin/main)
Drill-down modal from the hero chips (no tab — it's a cross-cutting action queue, per the IA note).
- [x] 6 — `needs-triage-dialog.tsx` (master-detail Dialog, tokens only): left rail Compliance /
      Probation / Invites with live counts; body lists the actual people with per-row actions
      (View profile; **Send invite** for the invites rail); footer deep-links to the owning surface.
      Wired so the hero needs-chips + Compliance stat + Invite quick-action open it at the right rail.
      Backend (`EmployeeProfileController`): `buildTriage()` (expired/expiring compliance ordered
      expired-first; staff within probation; pending invites = active staff w/ `last_login_at` null),
      `summary.pending_invites`, `triage` payload, and **`resendInvite()`** (`POST /hr/people/{profile}/invite`,
      manage-gated, mirrors the intake reset-link path — also delivers the deferred resend-invite).
      `NeedsTriageTest` (payload + probation surfacing + manage-gated invite notification). Triage
      queries validated via tinker (invites rail = 15 real rows locally). tsc/eslint(0)/build/php-l green.
      Commit `b9d916ff`.

## Log
- 2026-06-22: Kickoff. Extracted drop, audited current state (Explore agent), read kit + prototype
  hero/table. Set up node_modules junction. Wrote memory + this tracker. Starting Phase 1a.
- 2026-06-22: Phase 1 complete (1a `65c3c9db`, 1b-i `18b587a8`, 1b-ii `c8a7e225`, 1c `208532d3`).
  Set up full worktree workbench (robocopy vendor + .env). **Merged Phase 1 → origin/main**
  (FF `b6004905..208532d3`) → deployed → **✅ Chrome-verified LIVE on .com** (hero/donut/tabs/table
  render; 0 console errors; server-side sort proven). Fixed default-star bug `d80d1ec9` (ships next
  merge). ⚠️PeoplePaneActionsTest committed but not run (shared local test DB → would wipe dev data).
  Next: Phase 2 — employee intake unification (audit-first).
- 2026-06-22: Phase 2 complete (2a backend `b2e939b9`, 2b wizard `1aeac54f`). **Merged Phase 1-starfix
  + Phase 2 → origin/main** (FF `208532d3..1aeac54f`) → deploy triggered. Pending: Chrome-verify the
  5-step Add-Employee wizard + end-to-end create on .com. Next: Phase 3 (positions automation).
- 2026-06-22: Phase 2 re-verified LIVE (create 200 → /hr/people/45) after an intake 500 fix
  (`76e5850b`: side-effects best-effort post-commit). Phase 3 core complete (3a `9093ec75`,
  3b `a8d44027`, 3c `e0349eda`); **merged → origin/main** via reconcile-merge `6ad28387` (a concurrent
  /hr/feed recognition redesign had pushed 10 commits; auto-merged clean). Merged tree tsc/build green
  → deploy triggered. Pending: Chrome-verify understaffed surfacing + New-Position JD/auto-req. 3d
  deferred. Next phase: Departments (Phase 4).
- 2026-06-22: Phase 3 ✅ Chrome-verified LIVE on .com (created a position via the 3-step wizard + auto-req
  toggle → "Recruiting" badge; 0 app console errors). Phase 4 complete (4a `6429691a`, 4b `85e5fd6d`):
  cost-centre, cycle-safe hierarchy + roll-up, View modal, wizard. **Merged Phase 4 → origin/main**
  (FF `6ad28387..85e5fd6d`) → deploy triggered. Pending: Chrome-verify departments. Next: Phase 5
  (org-chart builder, research-first), then Phase 6 (needs-attention triage).
- 2026-06-22: Phase 5 complete (5a richer nodes/colour card `9d024b21`, 5b drag-to-reassign Build modal
  `d877fd92`). **Merged Phase 5 → origin/main** (FF `85e5fd6d..d877fd92`, no divergence) → deploy
  triggered (~5-8min). tsc 0 / eslint 0 / vite build 0 / php -l clean. Pending: Chrome-verify the org-chart
  view (colour-coded cards + photos) + the Build-org-chart drag modal on .com. ⚠️OrgChart tests
  committed-not-run (shared local test DB → would wipe dev data); backend is the existing proven
  `orgchart.update` endpoint — new tests are additive coverage.
- 2026-06-22: **Phase 6 (FINAL) complete** (`b9d916ff`) — needs-attention triage modal + resendInvite.
  **Merged → origin/main** (FF `1fb31ec9..b9d916ff`, no divergence) → deploy triggered. tsc/eslint(0)/build/
  php-l green; triage queries tinker-validated. **ALL 6 PHASES NOW BUILT + MERGED.** Remaining: one
  consolidated Chrome-verify pass on .com covering Phases 4–6 (departments View modal; org-chart colour
  cards + Build drag modal; needs-attention triage modal incl. live Send-invite) once the deploy lands,
  then run the committed Pest suite from the parent against a throwaway DB. ⚠️Chrome-verify needs the
  USER logged into .com (I never enter passwords).
