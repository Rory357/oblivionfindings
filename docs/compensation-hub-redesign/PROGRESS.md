# Compensation & Benefits Hub — Redesign Progress

> Entry: `/hr/compensation/bands` · Design reference: **`Compensation Hub.dc.html`** (hi-fi clickable mockup, built on the real repo tokens + the Add-Client wizard idiom).
> This file tracks the redesign loop. The **design** is done as a mockup; items below marked _backend_ are for Claude Code in the live Laravel/Inertia repo.

---

## How to use this doc
- The mockup (`Compensation Hub.dc.html`) is the **visual + interaction spec**. Build the real `.tsx` to match it.
- Work in small passes (hero → tabs → bands → reviews → bonuses → benefits → expenses modal → expenses inbox → history → settings). After each pass: `npm run build`, `npm run types`, `npm run lint`, screenshot, diff against the gold-standard page.
- Append every backend/data discovery to **§ Backend handoff** below and to `COMPENSATION_HUB_GAP_ANALYSIS.md`.

---

## Design status (mockup) — DONE

| Surface | Mocked | Notes |
|---|---|---|
| CompensationHero (golden band, **no clock**) | ✅ | amber `--hr-amber` attention stats, alert chips, band-health ring in the clock slot. Stats: people out of band / reviews in flight / awaiting my approval / reimbursed this month. |
| Standardised tab strip | ✅ | 6 tabs, count badges, active tones, **right-click tab menu** (set default / open / pin). History is a tab. |
| Salary Bands | ✅ | Range-bar viz with employees plotted by **compa-ratio**, target zone, mid marker, in/under/over counts, detail drawer, hover + right-click row menu, filter/export toolbar, **empty state**. |
| New Band wizard (4 steps) | ✅ | Add-Client clone: role & band → ranges (min≤mid≤max + overlap warn) → effective dating (supersede toggle) → review. Live preview range bar + completeness ring. |
| Pay Reviews + builder wizard (3 steps) | ✅ | Cycle (identity tiles) → employees & adjustment lines with **per-line band placement** → review with **budget-vs-committed tally + over-budget crit + out-of-band warn**. |
| Bonuses + record wizard (3 steps) | ✅ | Who & what (type tiles + recipient band context) → amount & timing (+ optional review link) → review with approver line. |
| Benefits + enroll wizard (3 steps) | ✅ | Employee & plan tiles → contributions (**KiwiSaver presets 3/4/6/8/10%**, employer-min validation, live cost preview) → review. |
| Unified Expense Claim wizard (3 steps) | ✅ | Basics (+ on-behalf picker) → items (standard + **mileage line at config IRD rate**, receipts, running total) → review (warm gradient card + approver line). Reused on Compensation / My HR / My Day. |
| Expenses approvals inbox | ✅ | Segments (awaiting / all / decided), **bulk select + approve**, **reject-with-reason** (required), **receipt viewer + download**, hover approve/reject only on pending rows. |
| History | ✅ | Company-wide change-log table with change-type pills + trend. |
| Settings (rate + GL) | ✅ | **Mileage rate + effective date** (the "fee place"), other-systems consolidation flags, GL account map (read-only) + double-post warning. |
| Right-click menus + kbd hints | ✅ | `useCompensationContextMenu` analog; menu items carry kbd accelerators. |
| Toasts | ✅ | Every action fires a sonner-style toast. |

Tweakables exposed: `brand` (re-theme), `mileageRate`, `showRing`.

---

## Backend handoff (for Claude Code) — TODO

### Fix in this work (safe)
- [ ] **[P0 data-corruption] Pay-review apply writes annual into hourly.** `CompensationService::applyCompensationReview()` (`:131-134`) sets both `new_hourly_rate` and `new_annual_salary` to `proposed_salary`. Fix: annual→annual; hourly = annual ÷ contracted annual hours (or preserve existing hourly for annual-only reviews). Back-fill corrupted `hr_compensation_history` + `hr_employee_profiles.hourly_rate`. Extend `tests/Feature/Hr/CompensationReviewApprovalTest.php`.
- [ ] **[P1] True-total hero stats.** Bands/reviews/benefits/expenses heroes count `…data.length` (page ≤20). Pass real aggregates from controllers. Money on bands/reviews/history is **encrypted** → compute sums/compa-ratio in PHP, not SQL.
- [ ] **[P1] `updateBand` validation.** Enforce `effective_to after:effective_from` and `min ≤ mid ≤ max` in both `storeBand` and `updateBand` (`CompensationController.php:55-106`).
- [ ] **[P1] Band placement service.** Add `CompensationService::bandPlacement(HrEmployeeProfile): {compaRatio, position, inBand|under|over}` on the dead `getSalaryBandForRole()` (`:46`). Call from Bands detail, Pay-review lines, People profile.
- [ ] **[P2] Missing CRUD / routes.** Bands `showBand`/`destroy`(archive)/`duplicate`. Reviews `updateReview`/`destroyReview`/`rejectReview` + per-item approve/reject. Bonuses `update`/`destroy`/`cancel`/`markPaid` (+ thin `BonusService`). Benefits extend `updatePlan` to all fields + `destroyPlan`/`destroyEnrollment` + proper opt-out (capture `opt_out_date` + reason); surface `getEnrollmentSummary` averages. Expenses receipt **download** route, `addItem`/update/withdraw routes, `onBehalf` creation, relax create gate so staff self-file. `CompensationController@recordChange` for manual history.
- [ ] **[P2] Dead enums** — decide per Chane: review `in_progress`, item `rejected`, bonus `paid`/`cancelled`, history `initial`/`promotion`/`adjustment`/`correction`, bonus `payroll_run_id` bridge.
- [ ] **[P3] Cleanups** — remove dead `BenefitsService::updateKiwiSaverRate`; reconcile bonus `amount` encryption inconsistency.

### Document only — DO NOT fix in this pass (hand to Finance)
- [ ] **[P0 finance] Expense approval double-posts to the GL.** On `status→approved`, both `HrExpenseClaimObserver` (DR **6500** / CR 2310) and `ExpenseService::approveClaim → PostExpenseJournalJob → ExpenseJournalService::postExpenseClaimJournal` (per-category DR **6100/7010/6000/6300** / CR **2000**) fire — two journals, different accounts. `CATEGORY_ACCOUNT_MAP` contradicts `config/finance.php` `event_accounts` (expense_claim→6500, mileage→6520). Write up exact accounts; flag the likely-correct single path; leave behaviour unchanged until Finance signs off.
- [ ] **[note] Mileage system fragmentation** — Operations `MileageClaim` 0.97 (user-editable), Fleet `FleetPersonalTrip` 0.95 (IRD), Timesheet `mileage_km` (config). Out of scope; future consolidation onto `config('finance.mileage_rate_per_km')` and one claim object. The Settings surface in the mockup visualises the target end-state (single editable rate every surface reads).

---

## Open questions for Chane (get sign-off before building)
- [ ] **Tab set + ordering** (§C): proposed Bands · Reviews · Bonuses · Benefits(+Plans sub-view) · Expenses(+inbox) · History. Is History a primary tab or behind "⋯ More"?
- [ ] **Bonus "Mark paid"** (§F): manual transition now, or deferred to the unbuilt payroll bridge (`payroll_run_id`)? If deferred, disable with tooltip.
- [ ] **Mileage rate editing** — keep in `config/finance.php` (code/deploy) or surface the Settings field as a DB-backed, effective-dated setting (mockup shows the latter)?

---

## Verification checklist (per pass)
- [ ] `npm run build` clean
- [ ] `npm run types` — zero TS errors
- [ ] `npm run lint` — zero new violations (raw hex blocked)
- [ ] Screenshot changed surface incl. each modal + each `?tab=`, plus `/hr/my` and `/my-day` for the expense modal
- [ ] Diff against gold-standard (`/hr/people`, `/hr/leave` request modal, Add-Client wizard)
- [ ] Tests: `CompensationReview*`, `BonusCreationTest`, `BenefitsEnrollmentTest`, `ExpensePaymentTest`, `ExpenseReceiptUploadTest`; browser `HrExpensesTest`, `HrBenefitsTest`
