<?php

use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
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
beforeEach(function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);
    Notification::fake();
    $this->payrollSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->payrollStaff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->payrollActor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($this->payrollActor, $this->payrollSite, ['start_date' => '2026-01-01']);
    ensureCanonicalHrStaffProfile($this->payrollStaff, $this->payrollSite, [
        'start_date' => '2026-01-01',
        'manager_user_id' => $this->payrollActor->id,
    ]);
    $permission = Permission::firstOrCreate(
        ['key' => 'timesheets.manageAny'],
        ['description' => 'Manage staff time entries'],
    );
    $this->payrollActor->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    expect($this->payrollActor->canDo('timesheets.manageAny'))->toBeTrue();
});

test('S9 seam: a time entry in a LOCKED payroll period cannot be voided (payroll integrity)', function () {
    $staff = $this->payrollStaff;
    $actor = $this->payrollActor;

    $entry = HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'site_id' => $this->payrollSite->id,
        'entry_date' => '2026-06-15',
        'clock_in' => Carbon::parse('2026-06-15 09:00:00', 'Pacific/Auckland')->utc(),
        'clock_out' => Carbon::parse('2026-06-15 17:00:00', 'Pacific/Auckland')->utc(),
        'created_by' => $staff->id,
        'status' => 'submitted', // not 'approved' — so we reach the payroll-lock guard
    ]);

    HrPayrollRun::factory()->create([
        'status' => 'locked',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ]);

    $original = $entry->refresh()->getRawOriginal();
    expect(fn () => app(TimeTrackingService::class)->voidEntry($entry->fresh(), $actor, 'test'))
        ->toThrow(LogicException::class, 'locked payroll period');
    expect($entry->fresh()->getRawOriginal())->toBe($original)
        ->and(HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->count())->toBe(0);
    Notification::assertNothingSent();
});

test('S9 seam: a time entry OUTSIDE any locked payroll period can still be voided', function () {
    $staff = $this->payrollStaff;
    $actor = $this->payrollActor;

    $entry = HrTimeEntry::factory()->create([
        'user_id' => $staff->id,
        'site_id' => $this->payrollSite->id,
        'entry_date' => '2026-06-15',
        'clock_in' => Carbon::parse('2026-06-15 09:00:00', 'Pacific/Auckland')->utc(),
        'clock_out' => Carbon::parse('2026-06-15 17:00:00', 'Pacific/Auckland')->utc(),
        'created_by' => $staff->id,
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
    $amendment = HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->sole();
    $voidedEntry = HrTimeEntry::withTrashed()->findOrFail($entry->id);
    expect($voidedEntry->status)->toBe('voided')
        ->and($voidedEntry->trashed())->toBeTrue()
        ->and($amendment->amended_by)->toBe($actor->id)
        ->and($amendment->reason)->toBe('test')
        ->and($amendment->new_value)->toBe('voided');
});
