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

### Phase 2 — Employee intake unification (audit-first)  ⬜ NOT STARTED
`docs/employee-intake-audit.md` → `EmployeeIntakeService::createOrConvert`; dedupe/link; onboarding
toggle; one send-invite path; persist RTW/visa + emergency-contact wizard steps.

### Phase 3 — Positions → recruitment automation (audit-first)  ⬜ NOT STARTED
`docs/positions-recruitment-audit.md` → `HrJobRequisition.position_id` FK; vacancy =
budget−current−open_req_openings; Job Description fields; scheduled understaffed check + alerts;
New-Position auto-open-requisition toggle; close-loop headcount sync.

### Phase 4 — Departments feature-complete (audit-first)  ⬜ NOT STARTED
`docs/departments-audit.md` → cost_centre (+ site link?); cycle-safe parent (store+update);
New/Edit/View Department wizard; headcount roll-up incl children; deactivate/delete semantics.

### Phase 5 — Org chart view + builder modal (research-first)  ⬜ NOT STARTED
`docs/org-chart-research.md` → rebuild `org-chart-pane.tsx` to connected top-down tree (colour-coded
title bars, photo cards); "Build org chart" drag-to-reassign modal (cycle-safe) → `orgchart.update`.

### Phase 6 — "Needs attention" triage modal  ⬜ NOT STARTED
Add-Client shell modal from hero chips: Compliance / Probation / Invites rails w/ live counts +
per-row actions; footer deep-links.

## Log
- 2026-06-22: Kickoff. Extracted drop, audited current state (Explore agent), read kit + prototype
  hero/table. Set up node_modules junction. Wrote memory + this tracker. Starting Phase 1a.
