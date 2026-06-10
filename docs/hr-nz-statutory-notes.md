# HR Module — NZ Statutory Notes

**Updated:** 2026-06-11 (gap round following docs/hr-module-audit-fix-plan.md §5A)

This page records how the HR module maps onto NZ employment law, and — just as
importantly — where it deliberately simplifies. Read this before treating any
balance or accrual as statutory truth.

## Leave (Holidays Act 2003)

**Supported leave types** (`LeaveService::LEAVE_TYPES` / `config/hr.php`):
annual, sick, bereavement, **family_violence**, parental, public_holiday,
**alternative**, toil, unpaid, other.

**Simplifications (G-3):**

- The engine is **hours-based with monthly-twelfth accrual** (`ProcessLeaveBalanceAccrualJob`).
  The Holidays Act expresses annual leave as **4 weeks after 12 months**
  (s16) and sick / family-violence leave as **lump-sum 10-day entitlements
  after 6 months** (s63, s72D). The monthly model approximates continuous
  entitlement and is clearly adequate for planning/visibility, but a payroll
  provider (or manual calculation) remains the source of truth for statutory
  termination pay, average-weekly-earnings vs ordinary-weekly-pay, and
  anniversary-based entitlements.
- Family violence leave (Domestic Violence—Victims' Protection Act 2018):
  10 days/yr after 6 months' employment. Seeded/accrued like sick leave;
  eligibility timing (6-month qualification) is not enforced by the engine.
- **TOIL ≠ alternative holidays.** TOIL is contractual time-in-lieu; the
  `alternative` type is the statutory s56 entitlement and is accrued
  automatically (below).

## Public holidays & alternative holidays (G-2)

- `hr_public_holidays` is seeded with national days (incl. Matariki) and
  regional anniversary days (`HrPublicHolidaysSeeder`); manageable at
  `/hr/leave/holidays`.
- Draft timesheets are **auto-flagged `public_holiday`** when the worker-local
  work date matches a national holiday, or a regional holiday whose region
  matches the site's `region` (`DraftTimesheetService::isPublicHolidayFor`).
- Pay: `HrPayRateRule.applies_on_public_holiday` + `public_holiday_multiplier`
  (e.g. 1.5×) price the flagged hours into payroll runs; the export carries
  `public_holiday_hours` and the multiplier.
- **Alternative holiday accrual** (`AlternativeHolidayService`): approving a
  public-holiday timesheet credits one day (contracted weekly hours ÷ 5,
  default 8h, clamped 4–10h) to the worker's `alternative` balance, once per
  timesheet (ledger `source_type=timesheet` dedupe). **Simplifications:**
  casual/contractor staff are excluded (approximating the "otherwise working
  day" test, s12); a true OWD determination needs roster-pattern analysis.

## Right to work (G-4)

- `hr_employee_profiles`: `work_rights_status` (citizen / permanent_resident /
  resident_visa / work_visa / student_visa / other), `visa_type`,
  `visa_expires_at`. Editable on the People edit page; surfaced with
  expiry warnings on the profile.
- `SendExpiryRemindersJob` (daily 08:00) notifies the worker **and their
  manager** at 90/60/30/14/7 days before `visa_expires_at`.
- Vetting register includes a `right_to_work` check type for evidence capture.

## Safety checks (Children's Act 2014) (G-5)

The vetting register's check types cover the safety-check components: police
check, identity verification, qualification verification, Children's Act
safety check (stored value `vulnerable_children_act` for data continuity),
right to work, plus reference checks in recruitment. Three-yearly re-vet
cadence is handled by vetting expiry + renewal flows.

## Pay equity (G-6)

`HrPayEquityBandsSeeder` creates default salary bands per the Care and
Support Workers (Pay Equity) Settlement qualification structure (L0–L4).
The **structure** is durable; the **rates are placeholders** — update band
rates in `/hr/compensation/bands` (or the seeder) when funded rates change.

## Payroll boundary: PAYE / KiwiSaver / ESCT (G-7)

The module is **export-to-provider**, not a payroll engine. Runs export
hours, rates, multipliers and gross per worker (`PayrollExportService`
columns include `public_holiday_hours`, `gross_pay`). PAYE, KiwiSaver
deductions/contributions, **ESCT**, and student loan are calculated by the
payroll provider. Payslip records can store the provider's results
(`hr_payslips` has `paye`, `kiwisaver_employee`, `kiwisaver_employer`,
`student_loan`). IRD numbers and KiwiSaver rates live encrypted on the
profile and are deliberately **not** included in CSV exports.

## Record retention (G-8)

- Wages & time / holiday & leave records: keep ≥ 6 years (Employment
  Relations Act 2000 s130; Holidays Act 2003 s81).
- Enforced in schema: deleting a `users` row is blocked while
  `hr_leave_requests`, `hr_leave_balances`, `hr_payroll_run_items`,
  `hr_payslips`, `hr_cases`, `hr_disciplinary_actions` or `hr_time_entries`
  reference it (restrict FKs — migrations `2026_06_10_000001`,
  `2026_06_11_000002`). Deactivation (`is_active=false`) is the supported
  termination path.
- Configurable retention windows live in `config/hr.php` `retention`.
