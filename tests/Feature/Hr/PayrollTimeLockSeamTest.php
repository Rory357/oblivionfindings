<?php

use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Seam S9 — Payroll ↔ Time/Leave, the TIME side. Time entries feed pay, so once
 * payroll has LOCKED/EXPORTED the period an entry's date falls in, the entry can
 * no longer be amended or voided — otherwise pay would silently desync from the
 * record. Enforced by TimeTrackingService::assertEntryNotPayrollLocked (added
 * for S9 data-integrity in Run 8, commit 85f42759).
 *
 * The LEAVE side of S9 (F-13, "by design") — HrLeaveRequest is the SoT and
 * StaffTimeOff is its synced roster projection (approve creates it, edit
 * re-syncs, a roster delete of an approved-leave projection is blocked) — is
 * already proven by tests/Feature/Hr/LeaveProjectionSyncTest.php.
 */
test('S9 seam: a time entry in a LOCKED payroll period cannot be voided (payroll integrity)', function () {
    Notification::fake();
    $staff = User::factory()->create();
    $actor = User::factory()->create();

    $entry = HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'entry_date' => '2026-06-15',
        'status' => 'submitted', // not 'approved' — so we reach the payroll-lock guard
    ]);

    HrPayrollRun::factory()->create([
        'status' => 'locked',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ]);

    expect(fn () => app(TimeTrackingService::class)->voidEntry($entry->fresh(), $actor, 'test'))
        ->toThrow(LogicException::class, 'locked payroll period');
});

test('S9 seam: a time entry OUTSIDE any locked payroll period can still be voided', function () {
    Notification::fake();
    $staff = User::factory()->create();
    $actor = User::factory()->create();

    $entry = HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'entry_date' => '2026-06-15',
        'status' => 'submitted',
    ]);

    // A locked run for a DIFFERENT period — this entry's date is not covered.
    HrPayrollRun::factory()->create([
        'status' => 'locked',
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);

    app(TimeTrackingService::class)->voidEntry($entry->fresh(), $actor, 'test');

    // The void ran to completion (no lock exception): its amendment record exists.
    expect(HrTimeEntryAmendment::query()
        ->where('hr_time_entry_id', $entry->id)
        ->where('new_value', 'voided')
        ->exists())->toBeTrue();
});
