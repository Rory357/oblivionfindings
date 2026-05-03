# Dusk Operations Test Coverage Inventory

Last updated: 2026-05-03

This inventory covers `tests/Browser/Operations/OperationsTest.php`. The file is a broad admin route-smoke suite: each test logs in as `admin@test.com`, visits one operations URL, waits for a page-specific word, then asserts either that text or the final path. It is not being deleted in this workstream.

Canonical e2e coverage for new operations work is Playwright. Dusk rows marked "partial" still need either a focused Playwright spec or an explicitly named PHP feature/routing test before the Dusk row can be retired.

| Dusk test | URL | Dusk assertion | Current parity |
|---|---|---|---|
| operations index page loads | `/operations` | Waits/sees `Operation` | Partial: route/page render smoke only; keep Dusk row. |
| operations clients index page loads | `/operations/clients` | Waits/sees `Client` | Partial: `tests/e2e/operations-clients-care.spec.ts` covers client care workflow, not this index smoke. |
| operations clients create page loads | `/operations/clients/create` | Waits/sees `Client` | Partial: no direct Playwright parity confirmed. |
| operations shifts page loads | `/operations/shifts` | Waits/sees `Shift` | Covered by `tests/e2e/operations-shifts-detail.spec.ts` for list-to-detail workflow; routing also covered by `tests/Feature/Routing/ShiftLegacyRedirectTest.php`. |
| operations shifts create page loads | `/operations/shifts/create` | Waits/sees `Shift` | Partial: legacy create redirects are covered by `tests/Feature/Routing/ShiftLegacyRedirectTest.php`; create form interaction remains Dusk-only. |
| operations timesheets page loads | `/operations/timesheets` | Waits/sees `Timesheet` | Partial: frontline timesheet flows are covered by `tests/e2e/my-day-returned-timesheet.spec.ts`; manager timesheet index smoke remains Dusk-only. |
| operations care plans page loads | `/operations/care-plans` | Waits/sees `Care Plan` | Partial: client care workflow has Playwright coverage through `tests/e2e/operations-clients-care.spec.ts`; standalone care-plan route smoke remains Dusk-only. |
| operations care plans create page loads | `/operations/care-plans/create` | Waits/sees `Care Plan` | Partial: no direct Playwright parity confirmed. |
| operations handovers page loads | `/operations/handovers` | Waits/sees `Handover` | Partial: frontline handover-adjacent lifecycle is covered by My Day specs; manager handover route smoke remains Dusk-only. |
| operations forms page loads | `/operations/forms` | Waits/sees `Form` | Partial: no direct Playwright parity confirmed. |
| operations forms create page loads | `/operations/forms/create` | Waits/sees `Form` | Partial: no direct Playwright parity confirmed. |
| operations service agreements page loads | `/operations/service-agreements` | Waits/sees `Service Agreement` | Partial: no direct Playwright parity confirmed. |
| operations service agreements create page loads | `/operations/service-agreements/create` | Waits/sees `Service Agreement` | Partial: no direct Playwright parity confirmed. |
| operations rostering page loads | `/operations/rostering` | Waits/sees `Roster` | Covered by `tests/e2e/operations-rostering-publish.spec.ts`, `operations-rostering-a11y.spec.ts`, and `operations-rostering-performance.spec.ts`. |
| operations rostering templates page loads | `/operations/rostering/templates` | Waits/sees `Template` | Covered by `tests/e2e/template-apply-conflict.spec.ts` for template apply workflow. |
| operations rostering templates create page loads | `/operations/rostering/templates/create` | Waits/sees `Template` | Partial: template apply is covered by Playwright; template creation remains Dusk-only. |
| operations progress notes page loads | `/operations/progress-notes` | Waits/sees `Progress Note` | Partial: no direct Playwright parity confirmed. |
| operations shift notes page loads | `/operations/shift-notes` | Waits/sees `Shift Note` | Partial: shift-detail Notes tab is covered by `tests/e2e/operations-shifts-detail.spec.ts`; standalone shift-notes route smoke remains Dusk-only. |
| operations billing page loads | `/operations/billing` | Waits/sees `Billing` | Partial: no direct Playwright parity confirmed. |
| operations funding page loads | `/operations/funding` | Waits/sees `Funding` | Partial: no direct Playwright parity confirmed. |
| operations funding claims page loads | `/operations/funding/claims` | Waits/sees `Claim` | Partial: no direct Playwright parity confirmed. |
| operations invoices page loads | `/operations/invoices` | Waits/sees `Invoice` | Partial: no direct Playwright parity confirmed. |
| operations quotes page loads | `/operations/quotes` | Waits/sees `Quote` | Partial: no direct Playwright parity confirmed. |
| operations price books page loads | `/operations/price-books` | Waits/sees `Price Book` | Partial: no direct Playwright parity confirmed. |
| operations recurring charges page loads | `/operations/recurring-charges` | Waits/sees `Recurring` | Partial: no direct Playwright parity confirmed. |
| operations job board page loads | `/operations/job-board` | Waits/sees `Job` | Partial: `tests/e2e/job-board-readiness.spec.ts` covers job board readiness, but this exact route smoke is not a retirement signal by itself. |
| operations messages page loads | `/operations/messages` | Waits/sees `Message` | Partial: no direct Playwright parity confirmed. |
| operations notifications page loads | `/operations/notifications` | Waits/sees `Notification` | Partial: no direct Playwright parity confirmed. |
| operations onboarding page loads | `/operations/onboarding` | Waits/sees `Onboarding` | Partial: no direct Playwright parity confirmed. |
| operations mileage page loads | `/operations/mileage` | Waits/sees `Mileage` | Partial: no direct Playwright parity confirmed. |
| operations note templates page loads | `/operations/note-templates` | Waits/sees `Note Template` | Partial: no direct Playwright parity confirmed. |
| operations summaries page loads | `/operations/summaries` | Waits/sees `Summar` | Partial: no direct Playwright parity confirmed. |
| operations timeline page loads | `/operations/timeline` | Waits/sees `Timeline` | Partial: no direct Playwright parity confirmed. |
| operations availability page loads | `/operations/availability` | Waits/sees `Availability` | Partial: no direct Playwright parity confirmed. |
| operations calendar sync page loads | `/operations/calendar-sync` | Waits/sees `Calendar` | Partial: no direct Playwright parity confirmed. |
| operations evv page loads | `/operations/evv` | Waits/sees `EVV` | Partial: attendance readiness covers worker clock flows, not this admin EVV smoke. |
| operations family portal page loads | `/operations/family-portal` | Waits/sees `Family` | Partial: no direct Playwright parity confirmed. |
| operations geofences page loads | `/operations/geofences` | Waits/sees `Geofence` | Partial: no direct Playwright parity confirmed. |
| operations payroll export page loads | `/operations/payroll-export` | Waits/sees `Payroll` | Partial: no direct Playwright parity confirmed. |
| operations qualifications page loads | `/operations/qualifications` | Waits/sees `Qualification` | Partial: no direct Playwright parity confirmed. |
| operations activity page loads | `/operations/activity` | Waits `Activit`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations billing entries page loads | `/operations/billing/entries` | Waits `Entr`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations calendar sync create page loads | `/operations/calendar-sync/create` | Waits `Calendar`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations client funds page loads | `/operations/client-funds` | Waits `Fund`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations client funds create page loads | `/operations/client-funds/create` | Waits `Fund`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations geofences create page loads | `/operations/geofences/create` | Waits `Geofence`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations invoices create page loads | `/operations/invoices/create` | Waits `Invoice`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations mileage create page loads | `/operations/mileage/create` | Waits `Mileage`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations note templates create page loads | `/operations/note-templates/create` | Waits `Note Template`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations onboarding create page loads | `/operations/onboarding/create` | Waits `Onboarding`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations payroll export create page loads | `/operations/payroll-export/create` | Waits `Payroll`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations price books create page loads | `/operations/price-books/create` | Waits `Price Book`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations quotes create page loads | `/operations/quotes/create` | Waits `Quote`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations recurring charges create page loads | `/operations/recurring-charges/create` | Waits `Recurring`, asserts path | Partial: no direct Playwright parity confirmed. |
| operations reports page loads | `/operations/reports` | Waits `Report`, asserts path | Covered by `tests/e2e/operations-reports.spec.ts` for reports navigation. |
| operations rostering conflicts page loads | `/operations/rostering/conflicts` | Waits `Conflict`, asserts path | Covered by `tests/e2e/operations-rostering-conflicts.spec.ts`. |
| operations timesheets approvals page loads | `/operations/timesheets/approvals` | Waits `Approval`, asserts path | Partial: approval behavior is backend-covered; manager approval route smoke remains Dusk-only. |
| operations timesheets create page loads | `/operations/timesheets/create` | Waits `Timesheet`, asserts path | Partial: frontline returned-timesheet behavior has Playwright coverage; manager create route smoke remains Dusk-only. |

## Retirement Rule

Do not delete a row from `OperationsTest.php` until its parity row above is changed from "partial" to a specific green Playwright spec or PHP feature/routing test, and that parity has accumulated the agreed CI history from the rostering readiness plan.
