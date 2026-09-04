# HR Module — production-rebuild prompt (Rostering-parity + BambooHR-grade)

**Purpose:** paste the fenced block below into a **new Claude Code session** running the
highest reasoning mode ("ultra"/Opus). It runs an autonomous, looped **audit → plan →
build → verify → merge** until the HR module reaches BambooHR-grade feature completeness
*and* Rostering-grade UX, production-ready, one milestone at a time.

**How to run it**

1. New Claude Code session, ultra/Opus.
2. Paste the whole block below (everything between the ``` fences).
3. Let it run. It audits first, writes/updates `docs/hr-module-rebuild-plan.md`, then loops:
   build one milestone → pass every gate → **merge-commit to `main` and push** → continue.
4. If it pauses, reply **"continue with the next milestone."** If a gate fails it self-corrects
   before pushing — it must never push a red gate to the auto-deploying `main`.

**Companion docs it will read and keep updated:** `docs/hr-module-design.md`,
`docs/hr-module-checklist.md`, `docs/hr-module-audit-fix-plan.md`, `docs/hr-nz-statutory-notes.md`,
`design_styles/DESIGN_TOKENS.md`, `design_styles/GOVERNANCE_HERO_GUIDE.md`, `design_styles/POPUP_STYLE_GUIDE.md`.

**Reference implementations (the bar to match — read these first):**
`resources/js/pages/operations/rostering/index.tsx` (PageHero + TabStrip + per-tab Panes +
Dialog modals), `resources/js/components/rostering/tab-strip.tsx` (tab look/feel),
`resources/js/components/clients/add-client-dialog.tsx` + `resources/js/components/wizard/*`
(stepper-modal workflow), `resources/js/components/page/*` (`PageHero`/`PageTabs`, with an
existing `hr` category), `resources/js/pages/my-calendar.tsx` + `resources/js/pages/calendar/*`
(site calendar look/feel for leave).

> **Structural strategy = Conservative & non-breaking (chosen).** Keep every existing HR route
> working. Introduce Rostering-style hub-pages-with-tabs and modal workflows *additively*; where
> a standalone page is folded into a tab/modal, **redirect its old route to `hub#tab`** rather
> than deleting it. Never break a URL and never delete a working backend. (To go more aggressive
> later — collapsing to ~6–8 hubs and removing routes — say so and the agent will switch modes.)

---

```
GOAL (north star — do not lose sight of this across the loop)
Bring the HR module to BambooHR-grade feature completeness AND Rostering-grade UX, fully
production-ready for a NZ supported-living provider. "Done" = every HR area has a Rostering-style
hero + tabs + modal workflows, every half-built feature is finished end-to-end (no dead buttons,
no stubbed UI), payroll runs end-to-end and posts through to Finance, leave calendars look and
behave like the site calendar, peer recognition and employee onboarding ship as modal workflows,
duplicates are removed, and every quality gate is green. You will get there milestone by milestone,
merging each verified milestone to main.

You are running on the highest reasoning mode. Treat any prior session's claims as UNTRUSTED and
re-derive from the code. Use parallel subagents for the audit sweeps where it speeds you up.

────────────────────────────────────────────────────────────────────────
OPERATING MODE — the loop
────────────────────────────────────────────────────────────────────────
PHASE 0 — AUDIT (no code changes yet)
  - Read the reference implementations and companion docs listed in the prompt header.
  - Adversarially audit the whole HR module end to end: every page under resources/js/pages/hr/**,
    its controller in app/Http/Controllers/Hr/**, its services in app/Domain/Hr/Services/**, its
    models in app/Domain/Hr/Models/**, and its routes in routes/hr.php, routes/api-hr.php,
    routes/training.php. Compare feature-by-feature against BambooHR's surface area.
  - For each HR area produce: what exists, what's half-built (UI with no backend, backend with no
    UI, dead buttons, TODOs, empty states that should hold real data), what's missing for BambooHR
    parity, and what diverges from the Rostering hero/tabs/modal pattern.
  - Hunt for DUPLICATION (see the De-dup section) and for swallowed fatals (catch(\Throwable){return []}
    patterns that hide missing imports / type mismatches — these have bitten this repo before).
  - WRITE/UPDATE docs/hr-module-rebuild-plan.md: a prioritized, milestone-grouped fix plan. Every
    item gets Problem → Evidence(file:line) → Fix → Acceptance criteria. Sequence the milestones
    (suggested backbone below; finalise it in the doc). Then begin the loop.

LOOP (repeat until DEFINITION OF DONE is met)
  1. Pick the next milestone from docs/hr-module-rebuild-plan.md.
  2. Implement it FULL-STACK (frontend + backend + routes + permissions + seeders + tests). No
     stubs: if a control needs a backend, build the backend; if a backend can't be finished now,
     hide the control (house rule) and note it.
  3. Bring every page/area you touch to Rostering parity (hero + tabs + modals — see Design Parity).
  4. Run ALL verification gates (below). Fix anything red. Never proceed with a red gate.
  5. UI/UX parity check: open the touched HR pages and the equivalent Rostering surfaces side by
     side (browse oblivionfindings.com as demo admin) and confirm hero, tabs, and modal patterns
     match in look, feel, and completeness.
  6. Update docs/hr-module-rebuild-plan.md (tick acceptance criteria) and project memory.
  7. Merge-commit the milestone to main and push (cadence below). Then continue to the next.

────────────────────────────────────────────────────────────────────────
GIT / DEPLOY CADENCE  (main auto-deploys to the DEV server oblivionfindings.com — no prod, no clients)
────────────────────────────────────────────────────────────────────────
- Work on a short-lived branch per milestone (e.g. hr/<milestone-slug>).
- BEFORE merging: every gate green + UI/UX parity confirmed + plan doc updated.
- THEN merge to main with a real merge commit (--no-ff) and push. One milestone = one merge.
- Commit messages: "HR <area>: <what shipped> (milestone N)" + acceptance criteria touched.
- If a milestone is large, land it as a sequence of green sub-commits on the branch, but only the
  fully-verified milestone gets merged to main. A red gate must never reach main — not for client
  safety (there are none) but to keep the shared dev server working and the codebase production-ready.
- Because there's no real data, you can reset the dev DB (migrate:fresh --seed) as part of a milestone
  whenever schema changes warrant it — just re-seed demo data so the hubs stay populated.

────────────────────────────────────────────────────────────────────────
GROUND TRUTH — architecture primer (verify before relying on it)
────────────────────────────────────────────────────────────────────────
- ENVIRONMENT: development only. main auto-deploys to the DEV server oblivionfindings.com — there is
  NO production deployment and NO real clients or real data yet. So you may run migrate:fresh --seed
  freely, write destructive/irreversible migrations, rename tables/columns, and reshape schema without
  any data-preservation gymnastics. The strict gates below are for CODE QUALITY and to keep the shared
  dev server usable — not for client safety. Nothing here is client-facing.
- Stack: Laravel 11 + Inertia.js + React/TypeScript + Tailwind + Radix/shadcn UI. NZ context.
- HR domain models live in app/Domain/Hr/Models (NOT app/Models/Hr); services in
  app/Domain/Hr/Services; controllers in app/Http/Controllers/Hr; pages in resources/js/pages/hr;
  routes in routes/hr.php (name prefix hr.), routes/api-hr.php, routes/training.php.
- RBAC: User::canDo() (deny-override → allow-override → role permissions). No wildcard/admin bypass.
  EnsurePermission middleware treats permission:a|b as OR. Permissions are SEEDED, not migrated, and
  deploys skip seeders — any new permission key must be added to a seeder DatabaseSeeder calls, and
  the deploy runbook updated.
- Time & attendance pipeline ALREADY WORKS — don't re-plumb it: all clock surfaces flow through
  App\Domain\Hr\Services\AttendanceService → HrAttendanceSession + HrTimeEntry → on clock-out
  DraftTimesheetService::fromAttendanceSession creates a draft operations Timesheet → PayrollExportService
  reads APPROVED operations Timesheet rows only.
- Leave ALREADY integrates with rostering — don't re-plumb: LeaveService creates a StaffTimeOff on
  approval/deletes on cancel; AvailabilityRule blocks rostering against approved HrLeaveRequests.
- Payroll services that already exist: PayrollExportService, PayrollExportFormatService,
  NzPayrollCalculatorService, PayslipService (+ PayrollExportController, PayslipController). Build the
  Finance bridge ON TOP of these; don't reinvent pay calculation.
- Legacy App\Models\Staff is DEAD (no imports anywhere) — never build on it; rostering assigns
  Shift.user_id → User directly.
- Tenancy: HR models scope by tenant_id via forTenant() + ResolvesHrTenant. Keep HR queries
  tenant-scoped. (Operations models scope by site_id.)
- Endpoints called by BOTH Inertia and axios must content-negotiate (RespondsToInertiaOrJson trait).

────────────────────────────────────────────────────────────────────────
HOUSE RULES (non-negotiable)
────────────────────────────────────────────────────────────────────────
1. Tests: NEVER `php artisan test --parallel` (per-worker DBs aren't migrated → thousands of false
   failures). Run scoped: `php artisan test tests/Feature/Hr` plus any suite you touch. Use
   FEATURE_ROSTERING_PUBLISH=false when needed.
2. Timezones: store UTC; convert at the app.worker_timezone (Pacific/Auckland) boundary; call ->utc()
   before persisting tz-aware Carbons. Be careful Carbon\Carbon vs Illuminate\Support\Carbon hints.
3. Permissions seeded not migrated (see above) — wire seeder + deploy runbook for every new key.
4. Design tokens only — NEVER hardcode hex; every colour comes from semantic tokens (design_styles/DESIGN_TOKENS.md).
5. Hero contract: design_styles/GOVERNANCE_HERO_GUIDE.md. Modal/popup style: design_styles/POPUP_STYLE_GUIDE.md.
6. Full-width layout convention; no centered max-width caps on page bodies.
7. NZ locale & terminology everywhere: en-NZ, NZD, NZ statutory names (Holidays Act 2003, IRD/PAYE,
   KiwiSaver, Ngā Paerewa NZS 8134:2021, Privacy Act 2020). See docs/hr-nz-statutory-notes.md and
   treat a payroll provider as the source of truth for statutory termination/anniversary pay.
8. Don't stub UI for missing backends — either build the backend or hide the control.
9. Every mutation is audited (existing AuditableChanges trait / AuditLogger) and permission-gated.
10. Conservative structure: keep every route working; fold pages into tabs/modals by REDIRECTING the
    old route to hub#tab, not by deleting it. Don't break URLs; don't delete working backends.

────────────────────────────────────────────────────────────────────────
DESIGN PARITY — match Rostering exactly (this is the heart of the request)
────────────────────────────────────────────────────────────────────────
HERO BANNERS — every HR hub/page gets a PageHero (category="hr") matching the Rostering hero
(resources/js/pages/operations/rostering/index.tsx ~line 2196). Include the relevant subset of:
  - a live/status pill + a personalised, human title (the repo uses "Kia ora {firstName}, …");
  - a one-line description that summarises the page's real state (counts, what needs the user);
  - meta items (icon + label), badges, and 3–4 KPI stats sourced from real data;
  - primary actions (the page's main verbs) and optional icon quickActions.
  NO calendar/week-picker navigation controls in HR heroes (explicit requirement) — but DO populate
  each hero with relevant stats, filters, badges and actions so it's never an empty banner.

TABS — standardise ONE tab component across all of HR, matching Rostering's TabStrip look and feel
(resources/js/components/rostering/tab-strip.tsx): toned chips with icons, count badges, the active
underline-bar, keyboard nav. Either reuse TabStrip or align the shared PageTabs to the same visual
language — pick one and use it everywhere in HR so tabs are identical across modules. Sub-areas that
are currently separate pages become tabs on their hub (with old routes redirecting to hub#tab).

MODALS / WORKFLOWS — every create/edit/process workflow matches the Add-Client modal UX
(resources/js/components/clients/add-client-dialog.tsx): a full-height stepper modal = left stepper
rail + scroll-contained body + sticky footer, built from the shared wizard kit
(resources/js/components/wizard/primitives.tsx + shell.tsx — Field, FieldErr, Segmented, TilePicker,
ChipMulti, SelectInput, StepHead, SubHead, InfoCard, Ring, etc.). Do the work IN modals like
Rostering does (CreateShiftDialog, ReassignDialog, …) instead of navigating to standalone form pages.

LEAVE CALENDARS — make HR leave calendars look and behave like the SITE calendar
(resources/js/pages/my-calendar.tsx + resources/js/pages/calendar/*, FullCalendar-based): same
month/agenda views, same styling/tokens, same interactions (click a day/entry → modal). Reuse the
shared calendar components rather than a bespoke HR calendar.

EMPTY / LOADING / ERROR STATES, mobile responsiveness, and accessibility (WCAG 2.1 AA; the repo has
@axe-core/playwright) are part of "Rostering-grade" — bring touched pages up to that bar.

────────────────────────────────────────────────────────────────────────
FUNCTIONAL TARGETS — BambooHR parity (complete the half-built; add what's missing)
────────────────────────────────────────────────────────────────────────
Core People: employee directory + rich profiles, org chart, positions, departments, documents +
  templates + e-sign, custom fields, import/export. Profile = hub with tabs (personal, job, comp,
  documents, time, performance, compliance) + modal edits.
Recruitment / ATS: pipeline kanban, job postings, candidates, interviews, scorecards, offers
  (offer creation as a wizard modal) → on accept, flows into Onboarding.
Onboarding (modal workflow — explicit ask): new-employee onboarding as a stepper modal that
  provisions the profile, assigns the compliance-matrix requirements for the role, kicks off tasks,
  documents to sign, and welcome emails. Offboarding mirrors it (checklist + asset return + access).
Time & Leave: leave requests/approvals, balances, NZ public holidays + alternative holidays, leave
  calendars matching the site calendar (above). Don't re-plumb the existing leave↔rostering and
  attendance↔timesheet pipelines; complete the UX around them.
Payroll END-TO-END → FINANCE (headline): pay run lifecycle (draft → review → approve → export →
  posted) built on the existing payroll services; NZ payroll (PAYE, KiwiSaver, student loan, leave
  pay) surfaced and verifiable; payslips. THEN wire the run THROUGH to Finance: post the payroll
  journal to Finance journals (resources/js/pages/finance/journals/*) and/or create a Finance
  payment run (resources/js/pages/finance/payment-runs/*) via the Finance integration mapping
  (resources/js/pages/finance/Integrations/Mapping.tsx), with GL account mapping, and IRD/PAYE
  filing surfacing (resources/js/pages/finance/IrdFilings/*). Acceptance: an approved pay run
  produces a balanced, traceable journal in Finance and a payslip per employee — no manual re-keying.
Performance: reviews, supervisions, PIPs, competencies/skills matrix, goals, succession/9-box.
  Consolidate the many performance/* pages into a Performance hub with tabs + modal create/edit.
Peer Recognition (NEW + modal workflow — explicit ask): a kudos/shout-out recognition feed with a
  "Give recognition" stepper modal (recipient(s), value/badge, message, visibility), reactions, and a
  hero stat. Surface it on the HR feed/wellbeing hub and the employee profile. Full-stack: model +
  migration + controller + permissions + seeder + tests.
Compliance & Training: compliance matrix per role, vetting register, training catalog/courses,
  expiry tracking that gates rostering eligibility; evidence packs.
Compensation & Benefits: bands, comp reviews, bonuses, benefit plans.
Engagement: announcements, surveys/wellbeing, feedback.
Reports & Analytics: HR analytics, headcount, report builder, saved reports, automations/webhooks.
Self-service (My HR): the employee's own profile, leave, payslips, documents, goals, reviews,
  training, expenses — as a clean self-service hub.
Cross-cutting additions you should make (these "fit what's described"):
  - A unified Approvals inbox (leave, expenses, offers, timesheets, pay-run sign-off) — one hub.
  - Expiry/reminder notifications verified end-to-end.
  - Demo seed data so every HR hub renders populated on the live demo (not empty states).
  - RBAC + audit coverage for every new control; tests for every new behaviour.

────────────────────────────────────────────────────────────────────────
DE-DUPLICATION
────────────────────────────────────────────────────────────────────────
Find and unify duplicates created during the long build, e.g. parallel pages/components/services for
the same concept (candidate "create" vs "create-offer", cases "create" vs "create-disciplinary",
performance "reviews" vs "create-review"/"edit-review"/"show-review", leave "index"/"create"/"show",
docs "index"/"templates"/"create-template", etc.) and any near-identical hero/tab/table/card code.
Extract shared HR primitives (hero presets, an HR data table, status badges, a People picker, the
recognition modal) into reusable components. Removing a duplicate must keep its route alive via a
redirect to the surviving hub#tab. Record every merge in the plan doc.

────────────────────────────────────────────────────────────────────────
SUGGESTED MILESTONE BACKBONE (finalise in the plan doc after the audit)
────────────────────────────────────────────────────────────────────────
M0  Foundations: standardise the HR hero + tab + modal primitives; align ONE tab component to
    Rostering; create the HR hero presets and shared wizard wrappers. Land the design spine first.
M1  People hub: directory + employee profile hub (tabs + modal edits) + org chart + positions/depts.
M2  Recruitment/ATS hub + Offer wizard.
M3  Onboarding + Offboarding modal workflows.
M4  Time & Leave hub + leave calendar matching the site calendar.
M5  Payroll end-to-end → Finance bridge (journals/payment-run/GL mapping/IRD). [headline]
M6  Performance hub (reviews/supervisions/PIPs/competencies/goals/succession).
M7  Peer Recognition (feed + Give-recognition modal). [explicit ask]
M8  Compliance & Training hub; Compensation & Benefits; Engagement (announcements/surveys/feedback).
M9  Reports & Analytics; unified Approvals inbox; My-HR self-service hub.
M10 De-dup sweep, demo seeders, a11y + responsive polish, final parity pass.
(Order so the design spine M0 lands first and Payroll→Finance M5 isn't blocked by later work.)

────────────────────────────────────────────────────────────────────────
VERIFICATION GATES — run every one before merging a milestone to main
────────────────────────────────────────────────────────────────────────
- Types:  npm run types        → 0 TypeScript errors.
- Build:  npm run build        → clean.
- Lint:   npm run lint         → clean (on touched files at minimum).
- Tests:  php artisan test tests/Feature/Hr  (NON-parallel) + every suite you touched; ADD tests for
          new behaviour (controllers, services, payroll→finance posting, recognition, onboarding).
- Visual: playwright visual tests (playwright.config.ts) for touched HR pages; update snapshots only
          when the change is intended.
- a11y:   axe pass (no critical violations) on touched HR pages.
- Browser smoke on oblivionfindings.com (auto-deployed dev server) logged in as demo admin: click
          through the milestone's pages and EVERY modal; confirm no console errors, no dead buttons,
          real data renders, and the hero/tabs/modals visually match the Rostering equivalents.
  (Local Herd alt: oblivionfindings.test needs Herd Desktop on PHP 8.4; delete public/hot if pages
   render blank.)
- Then update docs/hr-module-rebuild-plan.md + memory, merge --no-ff to main, push, continue.

────────────────────────────────────────────────────────────────────────
DEFINITION OF DONE
────────────────────────────────────────────────────────────────────────
Every milestone in docs/hr-module-rebuild-plan.md is checked off; every HR hub has a Rostering-grade
hero + standardised tabs + modal workflows; no dead buttons or stubbed UI anywhere in HR; payroll
runs end-to-end and posts a balanced journal/payment run into Finance with payslips; HR leave
calendars match the site calendar; peer recognition and employee onboarding ship as modal workflows;
duplicates removed (routes preserved via redirects); demo data populates every hub; all gates green;
and the whole module has been merged to main milestone by milestone with no red gate ever pushed.

FIRST ACTION
Start PHASE 0 now: read the reference files + companion docs, run the audit (parallel subagents
welcome), and write docs/hr-module-rebuild-plan.md with the finalised milestone list and per-item
Problem→Evidence→Fix→Acceptance. Then begin M0. Do not ask me to confirm between milestones unless
you hit a genuine ambiguity or a destructive/irreversible decision — otherwise keep looping and
merging until the Definition of Done is met.
```

---

### Notes for you (not part of the paste)

- **Why audit-first-then-loop:** it matches how you already work (`docs/hr-module-audit-fix-plan.md`,
  `docs/my-day-fresh-audit-prompt.md`) and gives the agent a written plan to drive — and you a
  checklist to watch — instead of an opaque continuous loop.
- **Milestone merge cadence is built in** per your instruction (verify UI/UX parity + completeness →
  merge to `main` → continue). The gates are deliberately strict to keep the codebase production-ready
  and the shared dev server usable — but since this is dev-only with no production or clients, a slip
  is never client-facing, and the agent is told it can reset/reshape the database freely.
- **Conservative structure is encoded** (routes preserved via redirects, no backend deletion). If you
  later want the dramatic simplification (collapse to ~6–8 hubs, delete dead routes), tell the agent
  "switch to aggressive consolidation" and it will.
- **To drive it:** paste the block, let it run; if it stops, say *"continue with the next milestone."*
  Lower (non-ultra) modes are fine for the mechanical edit milestones; keep ultra/Opus for Phase 0
  and the Payroll→Finance milestone.
