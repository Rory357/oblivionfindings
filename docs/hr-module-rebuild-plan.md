# HR Module — Rebuild Plan (Design Parity + BambooHR Completeness + Payroll→Finance)

**Created:** 2026-06-14 · **Author:** Claude (Opus 4.8, autonomous /loop)
**Audit basis:** 9 parallel adversarial code sweeps over `resources/js/pages/hr/**` (156 pages),
`app/Http/Controllers/Hr/**` (70 controllers), `app/Domain/Hr/Services/**` (46 services),
`app/Domain/Hr/Models/**` (~116 models), `routes/hr.php` (1070 lines), `routes/api-hr.php`,
`routes/training.php`, plus the Finance bridge surfaces.

This plan is **distinct** from `docs/hr-module-audit-fix-plan.md` (a 2026-06-10/11 *functional/correctness*
round — permissions, dead workflows, timezones, NZ statutory — mostly shipped). This round is about
**Rostering-grade UX** (hero + standardised tabs + modal workflows everywhere), **finishing the
half-built**, the **payroll→Finance bridge**, **peer recognition**, **onboarding modal**, and
**de-duplication** — re-derived from code, prior claims treated as untrusted.

Legend: each item is **Problem → Evidence → Fix → Acceptance**. `[ ]` open · `[x]` done.

---

## Design-spine reference (the bar — verified from code)

- **Hero:** `resources/js/components/page/page-hero.tsx` — `PageHero` supports `category="hr"` (themed
  gradient via `--category-hr`). Compact/inline variants ignore `category`. The Rostering hero
  (`resources/js/pages/operations/rostering/index.tsx`) = `variant="hero"` + title + real-state
  description + meta/badges + 3–4 KPI **stats from real data** + primary `actions`. No calendar/week nav.
- **Tabs (DECISION):** standardise HR on **`TabStrip`** (`resources/js/components/rostering/tab-strip.tsx`)
  — toned chips + count badges + active underline-bar + keyboard nav, matching Rostering exactly.
  `PageTabs` (Radix underline) is NOT used for HR hubs. Today HR uses a chaotic mix: raw `ui/tabs`
  (employees/show, candidates/show, recruitment/analytics, goals/*, hr/time, hr/leave, leave/reports),
  bespoke button rows (feed, onboarding/emails), or no tabs. Neither `TabStrip` nor `PageTabs` is used
  anywhere in HR today.
- **Modals:** `resources/js/components/wizard/shell.tsx` (`WizardShell` + `WizardStepPane` +
  `WizardSuccessPane` + `ReviewCard`/`ReviewRow`) + `primitives.tsx` (`Field`, `Segmented`,
  `TilePicker`, `ChipMulti`, `SelectInput`, `StepHead`, `SubHead`, `InfoCard`, `Ring`). Reference UX:
  `resources/js/components/clients/add-client-dialog.tsx`. HR create/edit are overwhelmingly
  **standalone form pages**; **zero** HR pages use `WizardShell` today.
- **Leave calendar parity target:** `resources/js/pages/my-calendar.tsx` + `resources/js/pages/calendar/global.tsx`
  (FullCalendar, 4 views, async events, click→modal, drag-create/move, `--fc-*` token CSS). There is
  **no shared FullCalendar wrapper** — each page re-instantiates FullCalendar.
- **Shared HR components:** `resources/js/components/hr/` **does not exist** — no HR primitives today.

---

## Cross-cutting findings (apply across milestones)

- **Hero gap is near-universal:** essentially no HR `PageHero` passes `category="hr"`; a handful of pages
  have **no PageHero** at all (onboarding/show, offboarding/show, reports/show, cases/create-disciplinary).
- **Tab chaos:** see above — one component (`TabStrip`) to rule them all.
- **Standalone-page create/edit** instead of modals across People, Performance, Cases, Policies, Vetting,
  Documents, Recruitment, Comp, Assets, Expenses, Leave.
- **Misleading stats:** many index heroes compute counts from the current page (`data.length`) not true
  totals — positions, audit-log, exit-interviews, onboarding, policies, assets, compensation/bands.
- **Duplicated hero KPIs:** several pages render the same numbers in the hero AND a KpiCard strip
  (hr/time, hr/leave, my/time, payroll).
- **Swallowed fatals:** `HrNotificationService` (10 silent `catch`), `HrWebhookService:58`,
  `HrAutomationService:68`, `WebhookDispatchService:92,139`, `EmployeeProfileController:357` (empty catch),
  frontend `reports/builder.tsx:147`, `reports/saved.tsx:89`.
- **Cross-tenant leaks:** `AnalyticsDashboardController:25` and `HeadcountController:24` pass `tenantId=null`;
  `BonusController@store` hardcodes `tenant_id=null`; `/hr/feed` + `/hr/feed/kudos` are **ungated** (any tenant).
- **Same-colour badge bug:** `text-status-success bg-status-success` (invisible text) in drivers, documents,
  signatures — fix via a shared `StatusBadge`.

---

## Milestones

### M0 — Foundations: the HR design spine `[x] DONE — merged main 1cd01120, 2026-06-14`

Create shared HR primitives and standardise hero + tabs + modal language so M1–M10 build on one spine.
**Shipped:** `resources/js/components/hr/` (hr-hero, hr-tabs, people-picker, status-badge, wizard, index)
+ `category="hr"` sweep (154 PageHero tags / 152 pages) + 6 vitest specs. Gates: types/build/lint/vitest green.
Live-verify the HR gradient on oblivionfindings.com after deploy (next loop tick).

- **[x] M0-1 Standardise on `TabStrip` for HR.** *Problem:* no canonical HR tab component; pages use raw
  `ui/tabs`, button rows, or nothing. *Fix:* add `resources/js/components/hr/hr-tabs.tsx` re-exporting/
  wrapping `TabStrip` with HR tab tones + a `useHrTabs(?tab= sync)` helper. *Acceptance:* one component
  imported by every HR hub from M1 onward; visual match to Rostering tabs.
- **[x] M0-2 HR hero preset.** *Problem:* heroes omit `category="hr"`; structure varies. *Fix:* add
  `resources/js/components/hr/hr-hero.tsx` — a thin `PageHero` preset that defaults `category="hr"`,
  supports the "Kia ora {firstName}, …" personalisation + a live/status pill, and exposes typed
  `stats`/`actions`/`quickActions`. *Acceptance:* used by every rebuilt hub; renders the HR-themed gradient.
- **M0-3 Blanket `category="hr"` sweep.** *Problem:* ~140 existing HR `PageHero` call sites render the
  default `--primary` gradient. *Evidence:* grep — `category="hr"` count ≈ 0 in `resources/js/pages/hr`.
  *Fix:* codemod adding `category="hr"` to every `<PageHero` in `resources/js/pages/hr/**` lacking one
  (no-op for compact/inline variants). *Acceptance:* every HR page hero shows the HR gradient; types+build green.
  **[x] Done** — 154 tags / 152 files; build green.
- **[x] M0-4 Shared `PeoplePicker`.** *Problem:* multiple flows take a raw "Employee Profile ID" number input
  (benefits/index.tsx:409, bonuses, recognition). *Fix:* `resources/js/components/hr/people-picker.tsx`
  (searchable user/employee combobox backed by an existing directory/API endpoint). *Acceptance:* reused
  by benefits enrol, recognition modal, bonus create.
- **[x] M0-5 Shared `StatusBadge`.** *Problem:* same-colour text/bg badges (invisible text) in drivers/documents/
  signatures; ad-hoc colour maps everywhere. *Fix:* `resources/js/components/hr/status-badge.tsx` mapping
  status→token pair (`bg-status-*-bg text-status-*`). *Acceptance:* badges legible; reused across hubs.
- **[x] M0-6 HR wizard convention.** *Problem:* zero HR wizards; standalone pages instead. *Fix:* shipped
  `components/hr/wizard.ts` — re-exports the `WizardShell` kit from one HR entry point + a `useWizard()`
  step machine. The first *real* HR wizard ships in M1 (`AddEmployeeDialog`) using this wrapper.
  *Acceptance:* shared wrapper + hook in place, tested; adoption begins M1.

### M1 — People hub: directory + profile + org chart + positions/departments `[CORE SHIPPED → main cf6a4879, 2026-06-14]`

**Shipped** (worktree hr/m1-people → main, all gates green, 16 PHP tests):
- **[x] M1-1** People hub with 5 standardised `HrTabs` (People · Directory · Positions · Departments · Org chart);
  duplicate directory merged; positions/departments/orgchart folded into panes; old routes redirect to
  `/hr/people?tab=…`; orphan index pages deleted. Reusable panes in `components/hr/` (+ `people/org-chart-pane`).
- **[x] M1-2** Add-Employee wizard (`AddEmployeeDialog`) + `POST /hr/people`.
- **[x] M1-4** Org-chart permission fix (ResolvesHrTenant) + reassign dialog + `OrgChartService::wouldCreateCycle` guard.
- Bug fixes: positions/departments tenant scoping via `ResolvesHrTenant` (users carry no tenant_id); invisible
  status-badge bug fixed via shared `StatusBadge`; Position/Department modal create/edit.
- **[x] M1-5** Directory/profile photo upload (`PhotoUploadButton` on the profile hero → existing
  `directory.uploadPhoto`); hub stat counts confirmed server-side totals.

**M1 = DONE.** Deferred to **M10 parity pass** (the profile edit *page* works today — modal-izing it is
parity polish best batched with the a11y/responsive sweep): **M1-3** convert `employees/edit` → WizardShell
modal (also fix the pre-existing `department_id`/`pay_rate` field-name bugs vs `UpdateEmployeeProfileRequest`)
+ surface `HrCustomFieldDefinition`/`HrCustomFieldValue` on the profile (needs value-storage plumbing — a
self-contained sub-feature). Tracked in M10.

- **M1-1 Merge the two employee lists.** *Problem:* `DirectoryController`/`directory/*` and
  `EmployeeProfileController`/`employees/*` are parallel people lists with duplicated avatar helpers.
  *Evidence:* `directory/index.tsx:79-101` ≈ `employees/index.tsx:84-106`; two profile pages
  (`directory/show.tsx` vs `employees/show.tsx`). *Fix:* one People hub at `/hr/people` with `TabStrip`
  (Directory card-view · Table · Org chart · Positions · Departments); redirect `/hr/directory` →
  `/hr/people`. One profile page with role-gated tabs (already 12 good tabs in `employees/show.tsx`),
  absorbing the social/kudos view as a tab. *Acceptance:* one list, one profile; old routes redirect; no
  duplicate avatar helpers.
- **M1-2 Add-Employee wizard.** *Problem:* no create flow; employees only enter via onboarding/import
  (`employees/index.tsx:290-300` has only Export). *Fix:* `AddEmployeeDialog` (WizardShell) creating
  `User`+`HrEmployeeProfile` with role attach. *Acceptance:* new employee created from the hub; appears in pickers.
- **M1-3 Profile edit → modal; surface custom fields.** *Problem:* `employees/edit.tsx`, positions
  create/edit are standalone pages; custom fields defined but never shown on profile/edit. *Fix:* modal
  edits per profile tab; render `HrCustomFieldDefinition` values in a profile tab + edit modal.
  *Acceptance:* edits in modals; custom fields visible/editable.
- **M1-4 Org chart actions + permission fix.** *Problem:* org chart read-only (`orgchart.update` unused;
  `canManage` threaded but unused, `orgchart/index.tsx:299`); route gates `hr.employees.viewAny` but
  controller requires `hr.orgchart.view` (`OrgChartController:20`) → 403 mismatch. *Fix:* drag-to-reassign
  manager wired to `orgchart.update`; align route+controller permission; seed `hr.orgchart.*` in `RbacSeeder`.
  *Acceptance:* reassign persists; permitted user loads org chart (no 403).
- **M1-5 Directory photo upload + true totals.** *Problem:* `directory.uploadPhoto` route unused; positions/
  audit-log stats are page-scoped. *Fix:* wire photo upload; compute stats from server totals.
  *Acceptance:* photo upload works; hero stats show real totals.

### M2 — Recruitment / ATS hub + Offer wizard

- **M2-1 Collapse the two job systems.** *Problem:* `HrJobRequisition` (`/hr/recruitment/jobs`) and
  `HrJobPosting` (`/hr/job-postings`) are parallel; applications link `jobPosting` but `createApplication`
  writes `requisition_id`. *Evidence:* `CandidateController:195` vs `RecruitmentService:114`. *Fix:* make
  `HrJobPosting` canonical (richer: approval/screening/analytics/preview); fold requisition channels in;
  redirect `/hr/recruitment/jobs`. *Acceptance:* one job model end-to-end; applications consistent.
- **M2-2 Recruitment hub tabs.** *Problem:* `recruitment/index`, `candidates.index` (same controller),
  `kanban` are overlapping surfaces. *Fix:* one hub with `TabStrip` (Pipeline/Kanban · Candidates ·
  Jobs · Interviews · Scorecards · Analytics); retire duplicate `candidates.index` route via redirect.
  *Acceptance:* one recruitment hub; no duplicate candidate list route.
- **M2-3 Real kanban board.** *Problem:* `recruitment/kanban.tsx` has no DnD/persistence; Kanban toggle
  button disabled (`kanban.tsx:87-90`). *Fix:* drag-to-stage persisting via a new
  `applications.move-stage` endpoint (or generalise `applications.advance`). *Acceptance:* dragging a card
  changes its stage in the DB.
- **M2-4 Offer wizard (explicit deliverable).** *Problem:* offer creation is a standalone page
  (`candidates/create-offer.tsx`); decisions use `prompt()/confirm()` (`candidates/show.tsx:308,356`).
  *Fix:* `OfferWizardDialog` (WizardShell): role/comp → terms → letter (template/merge → PDF) → review/send.
  On accept → flows to Onboarding (M3). *Acceptance:* offer created/sent from a modal; templated letter
  generated; accept triggers onboarding.
- **M2-5 Interview scheduling captures interviewer; fix dead routes.** *Problem:* schedule form omits
  interviewer; `onboarding/emails/{id}/edit` link is a 404; `jobs.unpublish-posting` &
  `job-postings.reject-approval` have no UI. *Fix:* add interviewer field; wire or remove the dead
  routes/links (house rule: hide if no backend). *Acceptance:* interviewer captured; no 404 links; no dead buttons.

### M3 — Onboarding + Offboarding modal workflows (explicit deliverable) — ✅ DONE (main 13ab554d→ba2c953a, 2026-06-14)

**Shipped in 4 green sub-commits (≈19 Pest tests):** M3-2 email engine (13ab554d) → M3-1 OnboardingWizardDialog
(0ef97489) → M3-4 OffboardingWizardDialog + exit-interview link (f9561f07) → M3-3 offer→onboarding auto-flow
(ba2c953a). M3-1 compliance assignment uses `ComplianceMatrixService::evaluateStaff`; "docs-to-sign" step
folded into the template task preview (no separate e-sign request — deferred to M8 compliance). Onboarding/
offboarding `create.tsx` pages kept as no-JS fallback routes. Incidental fix: respondOffer `terms_accepted`
was validated `accepted` (rejected the dialog's default `false`) → relaxed to `boolean`.

- **M3-1 Onboarding wizard.** *Problem:* onboarding "create" is a one-field employee picker
  (`onboarding/create.tsx`); template editor is an always-open inline form on the index. *Fix:*
  `OnboardingWizardDialog` (WizardShell): pick person/role/site/start-date → choose template → preview
  tasks → assign compliance-matrix requirements for the role → docs-to-sign → welcome email → launch.
  *Acceptance:* onboarding launched from a modal; provisions profile + compliance reqs + tasks + docs + email.
- **M3-2 Onboarding email engine.** *Problem:* `HrOnboardingEmail`/`...Log` have **no sender** — no
  Mailable/Job/scheduler anywhere. *Fix:* Mailable + queued send on checklist/stage, honouring
  `send_days_before_start`; populate the Sent Log; create-template modal. *Acceptance:* welcome/sequence
  emails send and log; template CRUD in modals.
- **M3-3 Offer→Onboarding auto-flow.** *Problem:* accepting an offer only sets `offer_accepted`; onboarding
  needs a separate manual convert. *Fix:* on accept, auto-`convertToEmployee` (idempotent) → generate
  onboarding checklist. *Acceptance:* accepted offer auto-creates the hire + onboarding.
- **M3-4 Offboarding wizard + exit-interview link.** *Problem:* offboarding create is a 2-field page; the
  "Exit interview" task is a plain row not linked to an `HrExitInterview`. *Fix:* `OffboardingWizardDialog`
  (checklist + asset return + access revoke); exit-interview task creates/links the record. *Acceptance:*
  offboarding launched from a modal; exit-interview task opens a real exit interview.

### M4 — Time & Leave hub + leave calendar = site calendar — ✅ CORE DONE (main cb8d8ad8 + b3ffb973, 2026-06-14)

**Shipped (2 green sub-commits, 7 Pest tests):** M4-1 + M4-2 (cb8d8ad8) — shared `components/calendar/calendar-view.tsx`
wrapper (themed FullCalendar, hardcoded hex→`--primary`/`--status-critical` tokens, Monday-first) now used by the
HR company calendar; fixed the create **405** (was POSTing `/hr/calendar`, GET-only → now `/hr/calendar/events`),
added event-click→edit/**delete** modal (PUT/DELETE were unused), fixed a Radix `SelectItem value=""` crash on the
Site picker, and moved `CalendarController` to `ResolvesHrTenant` (+ tenant-access guards). M4-3 leave-request modal
(b3ffb973) — `components/hr/leave-request-dialog.tsx` (WizardShell) replaces the page-based `leave/create` (route
redirects to the hub); fixed the blank Balances columns (controller mapped raw `balance/used/pending` → expected
`entitlement/taken/remaining`).

**AUDIT CORRECTION:** only `my-calendar.tsx` is FullCalendar; `calendar/global.tsx` wraps the hand-rolled
`SiteCalendar.tsx` (different engine) — so "adopt the wrapper across site calendars" is N/A for the global site
calendar. **DEFERRED to M10 de-dup/parity sweep** (refactor risk on working, non-browser-verifiable pages): adopt
the wrapper inside `my-calendar.tsx` itself (de-dup its ~85-line inline FC CSS; keep its context-menu CSS); migrate
`leave/index` Radix Tabs → `HrTabs`; rebuild `hr/calendar/time-off.tsx` (hand-rolled `grid grid-cols-7`) on the
shared calendar or fold leave into the unified calendar; M4-4 (leave cancel button, .ics subscribe, replace
native confirm()/alert()).

- **M4-1 Shared FullCalendar wrapper.** *Problem:* no shared wrapper; `my-calendar.tsx`, `calendar/global.tsx`,
  `hr/calendar/index.tsx` each re-instantiate FullCalendar; `hr/calendar/time-off.tsx` is a hand-rolled
  `grid grid-cols-7` (not FullCalendar). *Fix:* extract `resources/js/components/calendar/calendar-view.tsx`
  (plugins, views, `--fc-*` tokens, eventClick→modal, drag) and adopt it in the site calendars + HR.
  *Acceptance:* HR leave calendar visually/behaviourally matches the site calendar; one wrapper, reused.
- **M4-2 Fix broken calendar create + wire edit/delete.** *Problem:* create dialog POSTs `/hr/calendar`
  (only `/hr/calendar/events` exists) → 405 (`calendar/index.tsx:174`); `PUT/DELETE events/{event}` unused.
  *Fix:* correct the endpoint; add event-click→detail/edit/delete modal. *Acceptance:* event create/edit/
  delete works from the calendar.
- **M4-3 Time & Leave hub tabs + leave-request modal.** *Problem:* `hr/time` & `hr/leave` use raw `ui/tabs`;
  leave/index Balances & Reports tabs are empty stubs linking to standalone pages; new-request is a
  standalone page/inline collapsible (3 duplicate request forms). *Fix:* `TabStrip` hub
  (Timesheets · Requests · Balances · Calendar · Holidays · Reports); `LeaveRequestDialog` (WizardShell)
  shared by admin + self-service; de-stub tabs to render real content. *Acceptance:* one request modal; no
  empty tabs; tabs standardised.
- **M4-4 Calendar housekeeping.** *Problem:* `DELETE /hr/my/leave/{leaveRequest}` (cancel) has no UI; iCal
  feed not surfaced; native `confirm()/alert()` in leave/show. *Fix:* add cancel button; "Subscribe/.ics"
  action; replace confirm() with dialogs. *Acceptance:* cancel works; .ics subscribe present; no native dialogs.

### M5 — Payroll end-to-end → Finance bridge `[headline]` — 🟡 HEADLINE DONE (main 76bb724e, 2026-06-14); net-pay run remaining

**Re-derived (audit CORRECTS the "~80%"):** the bridge was ~90% built AND already had a passing balanced-journal
test. The ONLY production gap was M5-1 (payslips never generated on lock). `PostPayrollJournalJob` *was* already
dispatched on lock (the stale FINANCE_READINESS_AUDIT "zero callers" claim is wrong). **M5-1 SHIPPED** (76bb724e):
`PayrollExportService::lockRun` now generates payslips idempotently → balanced journal posts → cost allocation
fires, all via the existing route. Also fixed a gross-parity hole (payslip recomputed gross from hours×rate,
ignoring the run item's rule-loaded `gross_pay`): added `grossOverride` to `NzPayrollCalculatorService` +
`PayslipService`; `generateBulkPayslips` passes each item's authoritative gross so Σpayslip gross == run
total_gross == GL wage expense. **M5-2 (net-pay payment run) is the remaining piece** before M5 closes.

- **M5-1 Generate payslips inside the lock flow (BLOCKER).** ✅ DONE (76bb724e). *Was:* `postPayrollJournal` reads
  `HrPayslip` rows but `lockRun` never generated them → "no payslips to post". *Fixed:* lockRun generates them
  idempotently; updated `PayrollJournalPostingTest` to drop the manual pre-generation (proving lock does it) +
  assert payslip count, net = gross+holiday−deductions, and gross parity. 10 payroll regression tests still green.
- **M5-2 Employee net-pay payment run.** ✅ DONE (main 1723eb19, 2026-06-14). *Was:* `PaymentRunService` only
  pays vendor `FinBill`s; net pay was computed but never disbursed (the 2300 Accrued Wages liability never
  cleared). *Fixed:* `PayrollJournalService::postNetPayPayment` posts a second BALANCED journal DR 2300 / CR bank
  for Σ net_pay, flips payslips→paid, stamps `net_paid_at`+`payment_journal_id` (idempotent); bank GL resolved
  from the org's primary `FinBankAccount`. Route `hr.payroll.runs.pay` + `payNet` action (gated, tenant-checked,
  blocks not-GL-posted/already-paid); "Pay net" button shows only on GL-posted, unpaid runs + a "Paid" badge.
  Test asserts the second journal balances == Σnet_pay, payslips paid, idempotent. M5 COMPLETE. *(orig:)*
  payroll-sourced payment run /
  NZ direct-credit bank file from payslip `net_pay` + employee bank account (DR 2300 Accrued Wages / CR
  Bank). *Acceptance:* an approved pay run produces a Finance payment run / bank file paying each employee's net.
- **M5-3 Pay-run lifecycle UI + modal process.** *Problem:* `payroll/index.tsx` is a list with inline
  buttons + a JSON-textarea mapping editor; no `category="hr"`, no tabs, no wizard; PAYE/net not shown on
  the run. *Fix:* `TabStrip` (Runs · Payslips · Export profiles · GL mapping) + a pay-run process modal
  (create→review (PAYE/KiwiSaver/net visible)→approve→post→export→pay); surface journal-post status +
  failures on the run. *Acceptance:* full lifecycle visible; NZ deductions verifiable in UI; post failures surfaced.
- **M5-4 Bridge hardening.** *Problem:* GL codes hardcoded (`PayrollJournalService:253`); requires open
  fiscal period + seeded accounts or the queued job throws unseen; run gross (rate-rule) can diverge from
  payslip gross (NZ calc) → reconciliation hole; payslip "PDF" is HTML (`PayslipService:147`);
  `generateBulkPayslips` silently skips profile-less employees. *Fix:* per-org role→GL config (reuse/extend
  the Integration mapping concept); preflight checks with surfaced errors; reconcile/align the two gross
  engines (single source); real PDF; warn on skipped employees. *Acceptance:* non-seeded org gets a clear
  error not a silent failure; run gross == journal gross == export gross; PDF is a real PDF.
- **M5-5 IRD/PAYE filing surfacing.** *Problem:* `IrdFilingController` covers GST only; no PAYE/IR348
  payday-filing feed from payroll. *Fix:* surface a payday-filing export/record from the posted run on the
  IRD filings screen. *Acceptance:* a posted run yields a payday-filing artefact visible under IRD filings.

### M6 — Performance hub — ✅ DONE (main c1b072b5→15b818b2, 2026-06-14)

**Shipped (6 green sub-commits, ~22 Pest tests):** ReviewWizardDialog (c1b072b5) · SupervisionDialog (86c41f33,
+topics_discussed NOT-NULL fix) · GoalDialog (de2e2a9a, wired dead goals.update) · ProbationDialog (3031c971,
wired dead probation.*) · SuccessionCandidateDialog (6f5855fe, wired dead candidates.* + blank-name fix) ·
Competency edit (15b818b2, wired dead competencies.update + fixed a 500ing create path: created_by column didn't
exist). Every page-based create/edit form → WizardShell modal with old routes redirected; all four flagged dead
backends now wired. **DEFERRED to M10:** the Performance hub-tabs shell (performance/index.tsx → PageHero+HrTabs
consolidation of Overview·Reviews·Supervision·Competencies·PIPs·Succession·Feedback) — alongside the other hub
consolidations (leave HrTabs migration, my-calendar wrapper de-dup, time-off grid). HrGoal vs HrDevelopmentGoal
confirmed NOT duplicates (kept separate).


**Audit CORRECTION:** HrGoal (OKRs: user_id, key-results, progress_percentage) and HrDevelopmentGoal (competency
growth: employee_user_id/manager_user_id, target_level/progress_percent) are **NOT duplicates** — distinct
schemas/controllers/consumers, both live. Do NOT merge (HIGH risk). Confirmed dead backends (no UI): probation.*,
goals.update, succession.candidates.store/update, competencies.update.
- **M6-R1 ReviewWizardDialog** ✅ DONE (c1b072b5): WizardShell modal (Details → Assessment → Review) replaces the
  page-based create-review + edit-review; hosted on the reviews list (hero + per-row Edit, wiring the previously
  caller-less reviews.edit); controller ships staff+reviewTypes, create/edit pages redirect to the hub. 4 Pest tests.
- **M6 REMAINING (next ticks):** (a) SupervisionDialog — replace create/edit-supervision page forms (mirror
  ReviewWizardDialog; note hr_supervision_notes.topics_discussed is text NOT NULL but validated nullable → make it
  required in the dialog + tighten the rule); (b) GoalDialog — create/edit OKR goal, wiring the dead goals.update;
  (c) Performance hub shell — convert performance/index.tsx to PageHero+HrTabs (Overview·Reviews·Supervision·
  Competencies·PIPs·Succession·Feedback) + extract panes + redirect /reviews,/competencies,/pips,/succession,
  /feedback → ?tab= (bigger re-composition; merge PerformanceReviewController@index data into SupervisionController@
  index; succession/goals controllers bypass ResolvesHrTenant — route through it when folding); (d) wire/hide the
  dead backends (probation record dialog, succession candidate dialog, competency edit).

- **M6-1 Performance hub tabs.** *Problem:* 9 sub-features across 24 pages, 3 tab primitives, 2 layout
  wrappers. *Fix:* one hub (`TabStrip`): Overview · Reviews (incl. Probation) · Supervision/1:1 ·
  Competencies · Skills · PIPs · Goals & OKRs · Succession · 360 Feedback. Standardise on `PageLayout`.
  *Acceptance:* one hub; old sub-routes redirect to `hub#tab`.
- **M6-2 Create/edit → modals.** *Problem:* create/edit are standalone pages, several ~95% duplicates
  (create-review/edit-review; create-supervision/edit-supervision). *Fix:* `ReviewWizardDialog`,
  `SupervisionDialog`, `CompetencyAssessDialog`, `PipWizardDialog`, `SuccessionPlanDialog` (+candidate
  sub-modal), `GoalWizardDialog`. *Acceptance:* create/edit in modals; duplicate pages removed (routes redirect).
- **M6-3 Wire dead write paths.** *Problem:* `probation.store/update`, `succession.update`/`candidates.*`,
  `goals.update`, `competencies.update` have no UI; competencies profile link 404
  (`competencies/index.tsx:280`); PIP outcome column bug (`PipController:36`); succession candidate mgmt
  unreachable; no 9-box. *Fix:* wire each via modals; fix the 404 binding + outcome mapping; add a 9-box
  grid to succession. *Acceptance:* every backend write path reachable; no 404; PIP outcome correct; 9-box renders.
- **M6-4 Unify goal/competency models.** *Problem:* two goal systems (`HrGoal` vs `HrDevelopmentGoal`,
  cross-linked by mistake `goals/show.tsx:380`); competencies vs skills vs development-goal "gaps" overlap.
  *Fix:* fold Development Goals into the Goals tab (or a Development sub-tab) on one model; present
  Competencies+Skills under one "Capabilities" tab; stop the cross-link. *Acceptance:* one goals surface;
  capabilities consolidated; no mis-link.
- **M6-5 Review cycles + self-assessment.** *Problem:* reviews are one-off; no cycle/campaign; no
  self-assessment though `employee_comments` is validated. *Fix:* a review-cycle object + bulk launch +
  employee self-assessment step. *Acceptance:* a cycle launches reviews in bulk; employee can self-assess.

### M7 — Peer Recognition (explicit deliverable) — ✅ DONE (main 6a8cc7af→e08c55c5, 2026-06-14)

**Shipped (3 green sub-commits, 7 Pest tests):** M7-S1 (6a8cc7af) — SECURITY: the /hr/feed routes were
permission-ungated (any auth user could post/kudos); gated feed.index→hr.recognition.view, feed.store+feed.kudos→
hr.recognition.give (new keys in SeedHrPermissionsSeeder, granted to all staff roles, reseed-on-deploy). Same commit
fixed a latent bug that made the feed WHOLLY non-functional: FeedController used $user->tenant_id (always null) vs a
NOT-NULL column → empty reads + every insert silently failed; routed via ResolvesHrTenant. M7-R2 (7aa86d9e) —
Give-recognition WizardShell modal (recipient PeoplePicker → category TilePicker → message → review) replacing the
flat Send-Kudos dialog. M7-R3 (e08c55c5) — HrDemoSeeder feed/kudos demo data (idempotent). NO reactions (no backend).

**Key finding:** partially built — `HrKudos` model + `FeedService::sendKudos` + `FeedController::sendKudos`
+ 2 flat dialogs + profile stats + leaderboard. This is "finish/upgrade", not "build".

- **M7-1 Give-recognition wizard.** *Problem:* both kudos flows are flat Radix dialogs; multi-recipient +
  visibility not exposed (`is_public` hardcoded true, `FeedService:71`). *Fix:* `GiveRecognitionDialog`
  (WizardShell): recipient(s) (PeoplePicker, multi) → value/badge (TilePicker) → message → visibility →
  review. *Acceptance:* recognition sent from a stepper modal with multi-recipient + visibility.
- **M7-2 Reactions.** *Problem:* none — no table/model/endpoint/UI. *Fix:* `hr_feed_reactions` table +
  model + endpoint + UI on feed posts/kudos. *Acceptance:* users can react; counts persist.
- **M7-3 Permissions + security (BLOCKER).** *Problem:* `/hr/feed`, `POST /hr/feed`, `POST /hr/feed/kudos`
  are **ungated** (any user, any tenant); nav gated but route not. *Fix:* add `hr.feed.view`/`hr.recognition.*`
  permissions + seeder + route gating; tenant-scope. *Acceptance:* unauthorised user 403s; tenant isolation enforced.
- **M7-4 Hero stat + seeder + tests.** *Problem:* no "recognition this month" hero KPI; no
  factory/seeder (leaderboard empty on fresh DB); zero tests. *Fix:* hero KPI; `HrKudos`/`HrFeedPost`
  factories + demo seeder; feature tests. *Acceptance:* hero shows kudos KPI; demo populated; tests green.
- **M7-5 Persist milestone posts (or remove the filter).** *Problem:* "milestone" post_type filter always
  empty (never written). *Fix:* persist milestone posts or remove the dead filter. *Acceptance:* no dead filter.

### M8 — Compliance & Training; Comp & Benefits; Engagement

- **M8-1 Compliance/Training/Cases/Policies/Vetting/Docs hubs + modals.** *Problem:* compliance/matrix/
  calendar/training/vetting/drivers are separate routes (no hub); create/edit standalone pages (cases ×4,
  policies ×2, vetting ×2, documents ×3). *Fix:* hubs with `TabStrip`; convert create/edit to modals
  (disciplinary as a multi-step wizard). *Acceptance:* hubs + modal CRUD; routes redirect.
- **M8-2 Wire dead compliance backends.** *Problem:* driver register read-only
  (`drivers.store/update/approve/suspend` dead); e-signature `signatures.request` dead (no UI); document
  `documents.generate` dead (no UI); vetting `consent`/`captureConsent` dead. Signature page signs blind
  (no doc preview, `ESignatureController:55`). *Fix:* add driver add/approve/suspend UI; "Send for
  signature" + "Generate from template" modals; consent capture; document preview in `sign.tsx`.
  *Acceptance:* each backend reachable; signer sees the document.
  - ✅ **DONE — drivers (829cfb6b):** drivers/index.tsx was fully read-only (didn't even consume `can`); wired
    Add-Driver dialog (staff dropdown + licence fields, endorsements comma→array) + per-row Approve + Suspend
    dialog (required reason) on the existing store/approve/suspend endpoints; controller index() ships an
    `employees` prop; fixed invisible status badges. 5 tests (DriverEligibilityTest). NOTE: hr.driver.view/manage
    are granted to provider_manager (RbacSeeder), NOT the hr role. Audit verified all 4 compliance controllers
    correctly use ResolvesHrTenant (no null-tenant bug). REMAINING M8-2: signatures.request "Send for signature"
    modal (+ doc preview in sign.tsx — currently signs blind); documents.generate "Generate from template" modal
    (index "Generate from Template" link is a dead-end to the templates list); vetting.captureConsent "Record
    Consent" button on vetting/show.tsx (page already renders the consent badge/card, just nothing writes it).
  - ✅ **DONE — vetting consent (cf77f871):** added a "Record Consent" button + dialog (affirmative-consent
    checkbox matching the `accepted` rule + optional notes) to vetting/show.tsx Actions card → POSTs the existing
    captureConsent endpoint (appends to notes; non-tenant-aware table; no migration). 3 tests (VettingConsentTest,
    acting as provider_manager).
  - ✅ **DONE — e-signature (6bebb4b8):** signatures.request was UI-less → added a "Send for Signature" dialog
    (per-row on documents/index.tsx, checkbox list of staff by name → POST signatures.request; index ships
    employees.user_id). Fixed BLIND signing: sign.tsx now has a "View document" link backed by a NEW signer-scoped
    download route hr.signatures.document (authorises on signer_user_id, not hr.documents.view). 4 tests
    (ESignatureRequestTest).
  - ✅ **DONE — documents.generate (11b99d68) → M8-2 COMPLETE:** documents/index "Generate from Template" hero
    button was a dead-end Link to the templates LIST → now opens a "Generate from Template" dialog (template Select +
    employee Select + optional title → POST documents.generate; HrDocumentMergeService writes an HTML doc to the
    private disk). HrDocumentController@index ships an active `templates` prop; "Manage templates" link retained.
    3 tests (DocumentGenerateTest, Storage::fake private). **All 4 dead compliance backends now reachable.**
- **M8-3 Policies fixes.** *Problem:* `policies/show.tsx` reads `content`/`change_summary` the controller
  never persists (renders empty) + XSS via `dangerouslySetInnerHTML`; stats page-scoped. *Fix:* map real
  fields; sanitise/remove raw HTML; server-side totals. *Acceptance:* content renders; no XSS; correct totals.
  - ✅ **DONE (b4dfe753):** root cause was deeper — show.tsx read `policy.currentVersion` (camelCase) but the
    relation serialises `current_version` (snake_case, as index.tsx reads), so the WHOLE current-version section
    (version badge, View/Download doc, summary) was dead. Plus the field is `content_summary` (not content/
    change_summary), and it was piped through `dangerouslySetInnerHTML` (XSS on a plain-text textarea field).
    Fixed all three: current_version + content_summary throughout; render summary as plain text (no HTML); card
    shown only when a summary exists. Pure frontend (data was always persisted). 3 tests (PolicyShowContentTest:
    payload ships content_summary, non-view 403, source guard that show.tsx has no dangerouslySetInnerHTML).
    PolicyController already tenant-correct (ResolvesHrTenant). Stats deferred (low value).
- **M8-4 Compensation hub + finish flows.** *Problem:* 5 disconnected comp pages, 1 nav entry; bonus create
  unreachable + `tenant_id=null`; `storeReview` redirects to a non-existent route name → exception; comp
  review approval flow missing (applyReview no-op). *Fix:* Compensation hub (`TabStrip`: Bands · Reviews ·
  Bonuses); bonus create modal + tenant scope; fix the route-name; add review-item approve + status
  transition. *Acceptance:* bonus created from UI (tenant-scoped); review create works; review can be
  approved + applied.
  - ✅ **DONE (41a26edc):** `storeReview`/`storeBand` null-tenant fixed (was dead on MySQL — `tenant_id`
    NOT NULL) via `ResolvesHrTenant`; `storeReview` dead redirect (`hr.compensation.reviews.index` →
    `hr.compensation.reviews`) fixed. 3 tests (CompensationReviewCreationTest).
  - ✅ **DONE (551ee277):** bonus create UI — `bonuses.tsx` ignored the `employees` prop + had no create
    control (the live `bonuses.store` was UI-unreachable); added a "Record Bonus" dialog (employee dropdown
    by name, type/amount/date/reason). `BonusController::store` wrote `tenant_id=null` (nullable col but
    escapes `scopeForTenant` + the `['tenant_id','status']` index) → `ResolvesHrTenant`; dropped orphaned
    `show()`; fixed invisible status badges (`bg-status-*` → `bg-status-*-bg`). 3 tests (BonusCreationTest).
    REMAINING: Compensation hub TabStrip; review-item approve + status transition.
- **M8-5 Benefits + Assets + Expenses.** *Problem:* benefits enrol uses raw profile-ID input; no plan
  edit/lifecycle; assets create standalone + no retire/maintenance; expenses "Mark Paid" 404
  (`markPaid` unrouted), no receipt upload, **expense→Finance bridge orphaned** (`PostExpenseJournalJob`
  dispatched nowhere; `ExpenseService::approveClaim` never posts GL). *Fix:* Benefits hub + PeoplePicker
  enrol + plan lifecycle; asset modals + retire/maintenance; route+wire `markPaid`; receipt upload;
  dispatch `PostExpenseJournalJob` on approve. *Acceptance:* benefits enrol via picker; asset lifecycle;
  expense paid + posts to Finance GL.
  - ✅ **DONE (6e8e52c9):** expense→Finance GL bridge wired — `ExpenseService::approveClaim` now dispatches
    `PostExpenseJournalJob` (idempotent via `journal_id` guard); `HrExpenseClaim` `gl_posted_at` made
    fillable+cast (was silently dropped); `ExpenseController` approve/reject/show permission keys aligned to
    `hr.expenses.approve`. 3 tests (ExpenseJournalPostingTest — balanced journal DR 6100/7010 CR 2000=300,
    double-post guard, non-approver 403).
  - ✅ **DONE (a1efcc75):** Benefits enrollment edit UI — `benefits/index.tsx` table was read-only; added a
    per-row Edit dialog (status/contribution rates/notes → PUT `enrollments.update`) + replaced the raw
    "Employee Profile ID" input with an employee name dropdown. Fixed 3 null-tenant bugs (all `$user->tenant_id`):
    index/plans/summary used `forTenant(null)`→`whereNull` so real enrollments NEVER showed (list always empty);
    `storePlan` wrote `tenant_id=null` into a NOT-NULL col (plan create dead on MySQL); added cross-tenant guard
    on update. Routed via `ResolvesHrTenant` + ships `employees` prop. 4 tests (BenefitsEnrollmentTest).
  - ✅ **DONE (aff27454):** Expenses finishing — wired `markPaid` (was unrouted; show.tsx Mark-Paid button POSTed
    to a nonexistent `/mark-paid` = dead button) → new route `hr.expenses.pay` + `ExpenseController::pay` (guards
    `gl_posted_at` set, mirrors payroll pay-net gate) + server `can.pay` flag. Added "Posted to GL" badge (show
    payload now ships `journal_id`/`gl_posted_at`). Fixed the always-empty index (`forTenant(null)`→`whereNull`)
    via `ResolvesHrTenant`. Fixed invisible status badges. 5 tests (ExpensePaymentTest). REMAINING: expenses
    create page→modal + receipt upload; benefits plan edit/lifecycle; asset modals/retire.
- **M8-6 Engagement consolidation.** *Problem:* two survey systems (`HrSurvey` vs `HrEngagementSurvey`),
  two announcement paths (`HrAnnouncement` vs feed post_type), `HrCheckIn` orphan model. *Fix:* consolidate
  on the richer Engagement survey system (retire `/hr/surveys` via redirect); one announcement model;
  announcements create → modal; wire or drop `HrCheckIn`. *Acceptance:* one survey system; one announcement
  path; no orphan model.
  - AUDIT (agent a1edcf43, re-derived): canonical survey system = HrEngagementSurvey (/hr/wellbeing) — richer
    (anonymity, per-response scoring, eNPS, action plans + SLA reminder job SendEngagementActionPlanRemindersJob,
    5 tests); HrSurvey (/hr/surveys) is the thin dup (+cross-tenant bug). Announcements (HrAnnouncement) vs feed
    are COMPLEMENTARY, not dups (acknowledge fully wired, no null-tenant bug) — only gap = create.tsx is a page not
    a modal. HrCheckIn = TRUE ORPHAN (referenced nowhere but model/migration; drop = M10, needs migration).
  - ✅ **DONE — retire HrSurvey (a22ddb85):** SurveyController index/create/show/respond → redirect to
    hr.wellbeing.index (routes+names preserved; store/submitResponse left gated-but-unreachable for M10 removal);
    sidebar dropped "Surveys", renamed "Wellbeing"→"Surveys & Wellbeing" + broadened gate (wellbeing||analytics||
    surveys .view; old item was mis-gated analytics.view only). 4 tests (SurveySystemRetiredTest).
  - ✅ **DONE — announcements create→modal (36197574) → M8-6 COMPLETE:** "New Announcement" hero button now opens an
    in-page dialog on announcements/index (title/content/priority/audience/target-value/publish+expiry/pin/ack);
    AnnouncementController@index ships PRIORITIES/AUDIENCES consts; @create redirects to index (route preserved);
    deleted dead create.tsx. 4 tests (AnnouncementCreateModalTest). HrCheckIn orphan drop → M10.

### M9 — Reports/Analytics; unified Approvals inbox; My-HR self-service

- **M9-1 Fix the broken reports/analytics.** *Problem:* headcount page crashes (prop `currentHeadcount` vs
  `current`, shape mismatch); saved-report Run/Export 404 (path mismatch); `exportToExcel` writes CSV bytes
  to `.xlsx` (corrupt); analytics/headcount cross-tenant (`tenantId=null`). *Fix:* align props/shape; fix
  the run/export routes; real XLSX (or honest CSV); tenant-scope. *Acceptance:* headcount loads; saved
  reports run/export; XLSX opens; metrics tenant-scoped.
  - ✅ **DONE — headcount crash + tenant (37f2233b):** controller shipped prop `currentHeadcount` but page reads
    `current` → Object.entries(undefined) crash; +2 shape mismatches (page read `total_fte` [service ships
    `fte_total`] + treated `by_department` [array of {department,count}] as a Record). Fixed: controller render key
    → `current` + ResolvesHrTenant (was `$tenantId=null`); page type aligned to service shape, maps the array, reads
    fte_total. 2 tests (HeadcountDashboardTest).
  - ✅ **DONE — saved-report 404s + corrupt xlsx (e8496951):** reports/saved.tsx hit /hr/reports/{id}/run|export +
    deleted /hr/reports/{id}, but routes are /hr/reports/**saved**/{id}/... → run/export/delete all 404'd (export also
    POSTed a GET route). Fixed all three page paths (run POST, export GET, delete DELETE). exportToExcel wrote CSV
    bytes into a .xlsx (no xlsx-writer dep) → corrupt; made export emit an honest .csv (text/csv), removed the
    misleading "Excel" button + the dead exportToExcel service method. 4 tests (SavedReportActionsTest).
    REMAINING M9-1: AnalyticsDashboardController `$tenantId=null` cross-tenant (same null-tenant pattern → ResolvesHrTenant).
- **M9-2 Reports hub + consolidate webhooks/scheduling.** *Problem:* 4 fragmented report pages; **three**
  webhook systems (`HrWebhookController`/`reports/webhooks` vs `WebhookController`/`settings/webhooks` vs
  automation actions); two scheduling systems (`HrReportSubscription` vs `HrSavedReport.is_scheduled`).
  *Fix:* Reports hub (`TabStrip`: Reports · Builder · Saved · Scheduled · Automations · Webhooks); collapse
  to one webhook model + one scheduling concept (redirect the loser). *Acceptance:* one reports hub; one
  webhook system; one scheduling system.
- **M9-3 Unified Approvals inbox.** *Problem:* `ApprovalWorkflowService` is a generic engine but
  `initiateApproval()` is **never called** (approvals/pending always empty), `pending()` not scoped to the
  approver, chains create-only, no offer/pay-run process types. *Fix:* wire `initiateApproval` into leave/
  expense/timesheet/offer/pay-run submit; scope `pending()` to `getCurrentApprover()`; add process-type
  tabs + chain edit/toggle; offers + pay-run sign-off as process types. *Acceptance:* one inbox shows the
  user's real pending items across types; chains manageable.
  - ✅ **PARTIAL — tenant-correctness (fc64fa59):** ApprovalController chains/storeChain/pending used $user->tenant_id
    (null) → ResolvesHrTenant + a cross-tenant guard on action(); 3 tests (ApprovalChainTenantTest). The inbox now
    loads honestly (empty) + chains are tenant-scoped/usable.
  - ⚠️ **DEFERRED to M10 (design decision, NOT a safe loop wire):** initiateApproval has ZERO callers; the generic
    approval-chain engine is a PARALLEL system to each feature's own approve flow (leave/expense/etc. approve
    directly). Wiring initiateApproval into those flows would create a duplicate, competing approval state — a product
    call on which mechanism is authoritative (adopt the generic engine everywhere vs remove it as speculative). Not
    done here to avoid dual-approval bugs. pending()-scope-to-approver + process-type tabs also depend on that call.
- **M9-4 My-HR self-service hub.** *Problem:* `/hr/my` is 11 separate full pages; my/training shows only
  compliance (no LMS); my/policies un-attestable without a current version. *Fix:* one ESS hub (`TabStrip`:
  Overview · Profile · Leave · Time · Pay · Expenses · Documents · Training · Policies · Reviews · Goals ·
  Surveys); fix my/training to include enrolments; fix attest. *Acceptance:* one tabbed ESS hub; training
  shows courses; policies attestable.
  - ✅ **DONE (audit + 341533bd) → M9 CLOSED.** Audit (agent a5a327c8) verified the ESS hub is essentially sound:
    all 12 my/* pages + 23 /hr/my routes resolve to real controller methods, self-scoped by user_id (no null-tenant
    bug); policies-attest (uses currentVersion, post-M8-3) + payslip-list flows confirmed working; the stale plan
    claims were false. ONE real dead item fixed: /hr/my/payslips "View/Download" buttons hit hr.payslips.view-gated
    routes → 403 for staff; added ungated owner-authorised self-service routes (my.payslips.show/download) +
    PayslipController@show owner-allowance. 3 tests (MyPayslipsSelfServiceTest). NOTE: the 11-page→TabStrip ESS-hub
    consolidation + my/training LMS link + a my/leave cancel button (backend works) are UX-shell/feature gaps →
    DEFERRED to M10 (consistent with the other hub-shell deferrals).

### M10 — De-dup sweep, demo seeders, a11y + responsive, final parity

- **M10-1 De-dup sweep close-out.** Verify every merge in M1–M9 kept old routes alive via redirect; extract
  any remaining near-identical hero/table/card code into shared HR primitives. *Acceptance:* no duplicate
  concept pages remain; all old routes 301/redirect; dup map in this doc updated.
  - ✅ **DONE — redirects verified (2720731d):** RetiredRoutesRedirectTest asserts all 8 retired routes still
    redirect (no 404): /hr/{directory,positions,orgchart,departments}→/hr/people; /hr/job-postings→/hr/recruitment/jobs
    (hr.jobs.index); /hr/surveys + /hr/surveys/create→/hr/wellbeing; /hr/announcements/create→/hr/announcements.
    16 assertions. Shared-primitive extraction not needed (hubs already reuse components/hr spine + wizard kit).
  - ✅ **DONE — ESS my/leave cancel button (cefa24a2):** wired the existing cancelLeave (owner-only, pending/
    approved, restores balance) — my/leave.tsx now has a per-row Cancel control. 3 tests (MyLeaveCancelTest).
  - ✅ **DONE — drop orphan HrCheckIn (d93766f1):** reversible drop migration + deleted the never-referenced model.
    2 tests (HrCheckInDroppedTest).
  - ✅ **DONE — my/training LMS catalog link (203dd983):** permission-gated "Browse training courses" action on
    my/training.tsx (can.viewCatalog = hr.training.view||training.viewAny). 2 tests (MyTrainingCatalogLinkTest).
  - ✅ **DONE — HrSurvey controller/service/pages removal (S25):** the standalone HrSurvey system was retired in
    S11 (GET methods already redirected to /hr/wellbeing). Finished the cleanup: the `surveys.` route group now
    uses `Route::redirect('/hr/surveys{,/create,/{survey}/respond,/{survey}}', '/hr/wellbeing')` (route NAMES
    preserved: hr.surveys.index/create/respond/show; permission middleware dropped so bookmarks redirect even
    without the perm). Deleted `SurveyController` + `SurveyService` (used ONLY by that controller) + the 4
    `pages/hr/surveys/*.tsx`. Dropped the unreferenced POST routes (hr.surveys.store/respond.store — grep found
    zero route()/UI refs; only `hr.wellbeing.surveys.show` exists, a different namespace). KEPT: HrSurvey* models
    (orphaned now — dropping their tables is a separate migration like HrCheckIn, future tick) and the
    `hr.surveys.view` permission exposure in HandleInertiaRequests (the sidebar still reads `can.hr.surveys.view`
    as one OR-branch to show the "Surveys & Wellbeing" → /hr/wellbeing nav item). Wayfinder regenerated (pruned the
    SurveyController action TS; generated TS is gitignored). Gates: types + build + RetiredRoutesRedirectTest +
    SurveySystemRetiredTest (5 tests, incl. route-names-still-resolve) green.
- **M10-2 Demo seeders for every hub.** *Problem:* many hubs empty in demo. *Fix:* extend `HrDemoSeeder`
  so every hub renders populated (recognition, payroll run, performance, recruitment pipeline, etc.).
  *Acceptance:* fresh `migrate:fresh --seed` → no empty hubs on the dev server.
  - Already seeded (pre-M10): leave, recruitment, cases, time, expenses, payroll, documents, performance, assets,
    training, announcements+surveys, recognition feed.
  - ✅ **DONE — Comp & Benefits (5dea5418):** seedCompensationAndBenefits — 3 salary bands + FY2026 review + 2 bonuses
    + 2 benefit plans + 3 enrollments (idempotent). 1 test (CompensationBenefitsDemoSeederTest).
  - ✅ **DONE — drivers/vetting/approvals/reports (ed9b1036) → M10-2 COMPLETE:** seedComplianceExtras — 2 driver
    records + 2 background checks + 2 approval chains (w/ steps) + 2 saved reports (idempotent). Every HR hub now
    renders populated under migrate:fresh --seed. Tests in CompensationBenefitsDemoSeederTest (2 tests, run seeder
    twice). NOTE: saved reports seeded tenant_id=null to match ReportBuilderController's current whereNull scope.
- **M10-6 Payslip true PDF (finish half-built feature).** *Problem:* `PayslipService::generatePayslipPdf`
  rendered the `hr.payslip-pdf` Blade view to disk as raw HTML named `.html` and `PayslipController@download`
  served it `text/html` — an honest stub, not a payslip document. *Fix:*
  - ✅ **DONE — render via dompdf (S24, a23b31e1):** `barryvdh/laravel-dompdf ^3.1` is already installed (same
    lib Finance's `InvoicePdfService` uses), so route the view through `Pdf::loadView(...)->setPaper('a4')->output()`,
    store a `.pdf`, persist `pdf_path`. download() serves `application/pdf` and regenerates when the artefact is
    missing OR a stale pre-PDF `.html` path (existing rows self-upgrade on next download). dompdf has no
    flexbox/grid → converted the flex header to a 2-col table + dropped the unused `.info-grid` grid CSS.
    3 tests (PayslipPdfTest: %PDF magic bytes under `.pdf`, download Content-Type `application/pdf`, `.html`→PDF
    upgrade). Self-service download test still green.
- **M10-3 Swallowed-fatal sweep.** Fix the silent catches listed in cross-cutting (notifications, webhooks,
  automation, EmployeeProfileController, frontend). *Acceptance:* failures log/surface; no silent `return []`.
- **M10-4 a11y + responsive + empty/loading/error states.** Axe pass (no criticals) + mobile pass on every
  HR hub; consistent empty/loading/error states. *Acceptance:* axe clean; responsive; states present.
  - ✅ **DONE — icon-only buttons get accessible names (S26):** static a11y sweep of pages/hr + components/hr.
    Token/contrast side already clean (no same-token `bg-status-X text-status-X` killers — the `-bg`-suffixed
    pairings are correct; `status-badge.tsx` + `hr-primitives.test.tsx` already guard the bug; no inline hex /
    inline styles). HIGH-SIGNAL cluster = 12 icon-only `<Button size="icon">` with no accessible name across 11
    files → added `aria-label`: time-off prev/next month, compensation/bands edit, recruitment/index clear-filter,
    recruitment/scorecard + feedback/respond + exit-interviews/create star ratings (dynamic `Rate N star(s)`),
    my/training clear-filter, documents/{create,edit}-template remove-merge-field, job-postings/create remove-email
    (dynamic), org-chart-pane expand/collapse (dynamic). Already-labelled (skipped): employees/documents (4×
    aria-label), succession/show (aria-label), compliance/matrix + directory-pane (title attr); skills/matrix has
    visible proficiency-code text. Gates: types + eslint(touched) + build green. NOTE: live axe/mobile pass is
    browser-blocked → deferred to USER. Remaining a11y static candidates: skills/matrix cell label, fill-amber-400
    raw-palette star fills (non-token, cosmetic).
- **M10-5 Final parity pass.** Side-by-side every HR hub vs Rostering on oblivionfindings.com. *Acceptance:*
  hero/tabs/modals visually match; no dead buttons; DoD met.

---

## Verification gates (every milestone, before merge to main)

`npm run types` (0 errors) · `npm run build` (clean) · `npm run lint` (clean on touched) ·
`php artisan test tests/Feature/Hr` (non-parallel) + touched suites + new tests · playwright visual on
touched HR pages · axe (no criticals) · browser smoke on oblivionfindings.com as demo admin (every modal,
no console errors, real data, parity). Then update this doc + memory, merge `--no-ff`, push.

## Deploy runbook deltas (per milestone introducing permissions/seeders)
New permission keys → add to a seeder `DatabaseSeeder` calls + run `db:seed --class=… --force` on deploy.
New demo data → `HrDemoSeeder`. Schema changes OK (dev only): `migrate:fresh --seed` then reseed demo.
