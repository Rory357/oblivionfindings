# Handoff: Timesheet "Paid" flows from the pay run + audit cleanup

**Status:** ready to implement. **Branch base:** `main`.
This doc is self-contained — a fresh context can execute it without re-investigating.

## Why this change

Audit of the timesheet → HR → payroll chain found the chain mostly correct, but with one user-facing
defect and several structural issues. The user's driving concern: **marking a timesheet "Paid" looks
manual, but it should flow up from payroll automatically.**

Reality is worse than manual — it isn't wired at all:
- `Timesheet.status` has a `'paid'` value the UI counts/filters (a "Paid" tab) and there are two
  "Mark as paid" buttons, but **nothing sets `status='paid'`**. The button's `handleAction()` switch
  has no `'pay'` case; there is no backend endpoint. The Paid tab is permanently empty.
- The canonical HR pay run already links to timesheets (`HrPayrollRunItem.timesheet_ids`) and has the
  right commit seam (`PayrollExportService::lockRun()`), but never reaches back to the timesheets.

### Decisions already made (do not re-litigate)
1. **Paid trigger = pay-run lock.** `lockRun()` is the committed, irreversible point (GL journal
   posts; it's once-only). Export is a downstream artifact. (Neither point proves money left the bank;
   there's no bank-confirmation step, so lock is the chosen proxy.)
2. **Remove the legacy Operations payroll export entirely.** The canonical HR pay run (GL posting,
   NZ PAYE/KiwiSaver via `NzPayrollCalculatorService`, cost allocation, webhooks) is the single
   payroll surface.
3. **Keep the `/hr/time` and `/hr/my/time` clock pages.** They are NOT broken duplicates — they route
   through the canonical `AttendanceService` and additionally record NZ break-compliance + an amendment
   audit trail via `HrTimeEntry` (load-bearing). Add a safeguard test only.

---

## Key constraint to respect (read before coding the cascade)

`app/Models/Timesheet.php` `booted()` `updating` hook (lines ~111–170) makes payroll-linked rows
immutable:
- `$wasApproved = getOriginal('status')==='approved'`
- `$wasPayrollLinked = !empty(getOriginal('payroll_reference')) || !empty(getOriginal('exported_to_payroll_at'))`
- If `($wasApproved || $wasPayrollLinked)` AND operational fields dirty → throws.
- If `$wasPayrollLinked` AND workflow fields (incl. **`status`**) dirty → throws
  "Payroll-linked timesheets cannot change workflow state after export confirmation."

**Implication:** move an `approved` row to `paid` by setting `status` + `exported_to_payroll_at` +
`payroll_reference` in **one** `update()`, while the original row is still `approved` and not yet
linked (so `$wasPayrollLinked` is false on that update). Touch no operational fields. A normal
`update()` records the `AuditableChanges` trail. `status` is a plain string column — **no migration**.
`'paid'` is terminal: there is no un-lock / reverse-run path anywhere (verified).

The `saving` hook (`ShiftSafetyInvariantService::assertTimesheet`) and `ensureWorkflowAllowedForShift`
are both benign for approved→paid (the latter early-returns for any status outside submitted/approved).

---

## Workstream 1 — Paid flows from the pay run (core)

### 1a. Cascade on lock — `app/Domain/Hr/Services/PayrollExportService.php`
`DB` is already imported (line 12). Add `use Illuminate\Support\Facades\Log;`.

Add this method (validated reference implementation):

```php
/**
 * Cascade: flip every still-approved timesheet linked to this run to 'paid'.
 * Idempotent and safe to call repeatedly. 'paid' is terminal — the system has
 * no reverse-run path, so this is a one-way transition. Returns count newly paid.
 */
public function markRunTimesheetsPaid(HrPayrollRun $run): int
{
    $reference = "hr-payroll-run:{$run->id}";

    $ids = $run->items()
        ->pluck('timesheet_ids')
        ->flatMap(fn ($arr) => collect($arr ?? []))
        ->filter(fn ($id) => is_numeric($id))
        ->map(fn ($id) => (int) $id)
        ->unique()
        ->values();

    if ($ids->isEmpty()) {
        return 0;
    }

    return DB::transaction(function () use ($ids, $reference, $run) {
        $marked = 0;

        $timesheets = Timesheet::query()
            ->whereIn('id', $ids->all())
            ->lockForUpdate()
            ->get();

        foreach ($timesheets as $timesheet) {
            if ($timesheet->status === 'paid') {
                continue; // idempotent skip
            }

            if ($timesheet->status !== 'approved') {
                Log::info('Skipping non-approved timesheet during payroll paid cascade.', [
                    'payroll_run_id' => $run->id,
                    'timesheet_id'   => $timesheet->id,
                    'status'         => $timesheet->status,
                ]);
                continue; // timesheet_ids array can be stale — never trust it blindly
            }

            $payload = [
                'status'                 => 'paid',
                'exported_to_payroll_at' => now(),
                'payroll_reference'      => $reference,
            ];

            $alreadyLinked = filled($timesheet->getOriginal('payroll_reference'))
                || filled($timesheet->getOriginal('exported_to_payroll_at'));

            if ($alreadyLinked) {
                // Pre-existing legacy-stamped row: a normal update() would hit the
                // workflow guard, so bypass it. (New data can't reach this state
                // once Workstream 2 removes the legacy export path.)
                $timesheet->forceFill($payload)->saveQuietly();
            } else {
                $timesheet->update($payload); // works WITH the guard
            }

            $marked++;
        }

        return $marked;
    });
}
```

Call it from `lockRun()` right after the lock `update()` (after line ~159, before `return $run->fresh();`):

```php
$run->update([
    'status'            => 'locked',
    'locked_at'         => now(),
    'locked_by'         => $lockedBy,
    'validation_errors' => [],
]);

$this->markRunTimesheetsPaid($run); // <-- new

return $run->fresh();
```

Wrap the lock `update()` + cascade call in a single `DB::transaction` so a cascade failure rolls back
the lock. `validateRunConsistency()` (already run earlier in `lockRun`, lines ~145–152) guarantees
every linked timesheet is `approved` before we get here.

> Note: there are TWO `PayrollExportService` classes. The canonical one to edit is
> `app/Domain/Hr/Services/PayrollExportService.php`. The other
> (`app/Services/Operations/PayrollExportService.php`) is being deleted in Workstream 2.

### 1b. Remove the dead manual "Mark as paid" buttons (frontend)
- `resources/js/pages/operations/timesheets/index.tsx`: in `menuItemsFor()` remove the
  `{ id: 'pay', label: 'Mark as paid', icon: Banknote, tone: 'success' }` item (~line 277) from the
  `approved` actions. Drop the `Banknote` import (~line 22) if unused afterward. (`handleAction()` has
  no `'pay'` case, so nothing else to change there.)
- `resources/js/components/timesheets/view-timesheet-dialog.tsx`: remove the dead footer button
  (~lines 514–518) — renders for approved timesheets but has no `onClick`.

### 1c. Surface payroll state read-only — `view-timesheet-dialog.tsx`
- Extend `ViewTimesheetRow` (~line 53) with `exported_to_payroll_at?: string | null` and
  `payroll_reference?: string | null`.
- Add a small read-only "Payroll" section in the right-hand `<aside>` (after the Linked-shift block,
  ~line 370), shown only when either field is present: "Exported {date}" and "Reference {ref}".

### 1d. Carry the fields in the index payload — `app/Http/Controllers/TimesheetController.php`
In `index()` ensure the per-row mapping (~lines 283–321) includes `exported_to_payroll_at` and
`payroll_reference`. Mirror `payrollAdjustmentsPending()` (~line 1226), which already selects both.

### 1e. Tests — extend `tests/Feature/Hr/PayrollRunIntegrityTest.php`
It already seeds the lean fixture (RBAC + hr user + support_worker + `HrEmployeeProfile` +
`HrPayRateRule`) and drives `createRun`/`lockRun` with directly-created approved timesheets. Add:
- (a) lock flips linked approved timesheets to `paid` with `payroll_reference="hr-payroll-run:{id}"`
  and non-null `exported_to_payroll_at`;
- (b) idempotency: re-calling `markRunTimesheetsPaid($run->fresh())` returns 0 and doesn't error
  (`lockRun` itself can't be re-called — it throws "already locked" by design);
- (c) a timesheet outside the run period (e.g. `work_date` last month) stays `approved`, null payroll
  fields;
- (d) guard doesn't block the cascade — incl. a pre-stamped approved row
  (`forceFill(['exported_to_payroll_at'=>now(),'payroll_reference'=>'operations-payroll-export:99'])->saveQuietly()`
  before building the run) that still lands on `paid` via the fallback without throwing.

---

## Workstream 2 — Remove the legacy Operations payroll export entirely

No other production code imports these (verified — only their own controller/service/routes/nav).

**Delete:**
- `app/Models/PayrollExport.php`
- `app/Http/Controllers/Operations/PayrollExportController.php`
- `app/Services/Operations/PayrollExportService.php`
- `resources/js/pages/operations/payroll-export/Index.tsx` and `Create.tsx`
- `resources/js/routes/operations/payroll_export/index.ts` (generated)
- `resources/js/actions/App/Http/Controllers/Operations/PayrollExportController.ts` (generated)
- `tests/Feature/Payroll/NzPayrollExportGoldenTest.php` + fixture
  `tests/fixtures/payroll/2026-04-baseline.csv` (golden test for the legacy CSV only — no HR/NZ-tax
  coverage lost; that lives in HR `NzPayrollCalculatorService` + `PayrollRunIntegrityTest`)
- `tests/Unit/Operations/PayrollExportSegmentationTest.php`

**Edit:**
- `routes/operations.php`: remove the `PayrollExportController` import (line 53) and the whole
  `permission:payroll.export` route group (lines ~1227–1233:
  `operations.payroll_export.index|create|generate|download|confirm`).
- `resources/js/components/app-sidebar.tsx`: remove the "Payroll Export" nav item (lines ~893–898,
  the `can?.payroll?.export || can?.payroll_exports?.viewAny` block, `href: '/operations/payroll-export'`).

**KEEP (shared — do NOT delete):**
- `app/Services/Operations/PayrollRateResolver.php` — used by canonical `DraftTimesheetService`,
  `BillingService`, `TimesheetHrSyncService`, `BackfillOperationalSnapshots`.
- `tests/Unit/Services/PayrollRateResolverSegmentationTest.php` — tests the resolver directly, not the
  export.
- HR system, different namespace, must still resolve: `app/Domain/Hr/Services/PayrollExportService.php`
  and `app/Http/Controllers/Hr/PayrollExportController.php`.

**Optional follow-up (NOT in this change):** drop the unused `payroll_exports` table via a new
migration; remove the now-vestigial `payroll_segments_exported` column + `is_payroll_segment_complete`
accessor on `Timesheet`. Deferred to avoid a destructive migration; a dormant nullable column + harmless
accessor are low-risk.

---

## Workstream 3 — No-shift time logging (already implemented; just commit)

Done in a prior session and verified green — include so it lands together:
- `app/Domain/Shifts/Timesheets/Drafts/DraftTimesheetService.php` — `fromAttendanceSession()` logs a
  draft timesheet for a clock-out with no rostered shift (`activity_type='other'`, nullable client,
  `site_id` from the session) instead of dropping the time.
- `tests/Feature/Timesheets/DraftTimesheetServiceTest.php` —
  `test_from_attendance_session_logs_time_when_worker_has_no_shift` (suite: 3 passing, 27 assertions).

No further code change.

---

## Workstream 4 — Clock-surface consistency safeguard (test only)

Add a feature test asserting the three clock entry points stay consistent (all funnel through
`AttendanceService`):
- `POST /attendance/clock-in|out`, `POST /hr/time/clock-in|out`, `POST /hr/my/time/clock-in|out`
- All three create exactly one `HrAttendanceSession` + the canonical draft `Timesheet`.
- The two HR surfaces additionally create an `HrTimeEntry`; the canonical one does not.

Locks in the "not a duplicate" finding so a future refactor can't silently diverge them.

---

## Workstream 5 — `return_notes` / `returned_notes` naming (no action)

`MyTasksController` exposes `return_notes` as an alias of the real `returned_notes` column; reads/writes
are correct. Renaming is cosmetic churn across model/controllers/frontend with no behavioural gain —
leave as-is. Recorded so it's a decision, not an oversight.

---

## Verification

Backend (PowerShell — Herd provides `php`):
- `php artisan test --filter=PayrollRunIntegrity` — paid cascade (1e)
- `php artisan test --filter=DraftTimesheetService` — no-shift logging (3)
- `php artisan test --filter=Attendance` + payroll backbone
  (`ShiftPayrollBackboneIntegrationTest`, `PayrollExportProfileWorkflowTest`,
  `PayrollJournalPostingTest`) — no regression from removal/cascade
- New clock-consistency test (4)
- After Workstream 2: full suite, then grep for `PayrollExport` (Operations namespace),
  `payroll-export`, `operations.payroll_export` to confirm nothing dangles. (HR
  `App\Domain\Hr\Services\PayrollExportService` + `App\Http\Controllers\Hr\PayrollExportController`
  must still resolve.)

Frontend:
- `npm run build` (or project typecheck/lint) — confirm removed pages/imports leave no broken refs and
  the new dialog type compiles.

Manual (remote test server per merge-to-main → verify-on-dev workflow):
- Lock a pay run with approved timesheets → they show "Paid", appear in the Paid tab, and the view
  dialog shows the Payroll section (`hr-payroll-run:{id}`).
- "Payroll Export" nav item gone; `/operations/payroll-export` 404s.
- Clock in/out on My Day with no rostered shift → a draft timesheet is logged for the worker.

## Suggested commit split
Each workstream as its own commit: (1) core paid cascade + frontend, (2) legacy export removal,
(3) no-shift logging (already staged), (4) clock-consistency test.
