<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\TimesheetHrSyncService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->worker = User::factory()->create(['approved_at' => now()]);
    $this->approver = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->worker->id,
        'hourly_rate' => 32.50,
        'is_active' => true,
    ]);
});

function identityTimesheet(User $worker, User $approver, array $overrides = []): Timesheet
{
    $shift = Shift::factory()->create(['user_id' => $worker->id]);

    return Timesheet::factory()->approved()->create(array_merge([
        'shift_id' => $shift->id,
        'client_id' => $shift->client_id,
        'shift_site_id' => $shift->site_id,
        'shift_service_context_id' => $shift->service_context_id,
        'user_id' => $worker->id,
        'approved_by' => $approver->id,
        'created_by' => $worker->id,
    ], $overrides));
}

function identityEntry(User $worker, User $approver, array $overrides = []): HrTimeEntry
{
    return HrTimeEntry::factory()->create(array_merge([
        'user_id' => $worker->id,
        'status' => 'submitted',
        'approved_by' => null,
        'approved_at' => null,
        'created_by' => $approver->id,
    ], $overrides));
}

test('approval updates the linked hr entry in place and repeated sync is idempotent', function () {
    $entry = identityEntry($this->worker, $this->approver);
    $timesheet = identityTimesheet($this->worker, $this->approver, [
        'hr_time_entry_id' => $entry->id,
    ]);

    app(TimesheetHrSyncService::class)->syncToHr($timesheet);
    app(TimesheetHrSyncService::class)->syncToHr($timesheet->fresh());

    expect($timesheet->fresh()->hr_time_entry_id)->toBe($entry->id)
        ->and($entry->fresh()->status)->toBe('approved')
        ->and($entry->fresh()->source_type)->toBe('timesheet')
        ->and($entry->fresh()->source_id)->toBe($timesheet->id)
        ->and(HrTimeEntry::query()->where('user_id', $this->worker->id)->count())->toBe(1);
});

test('an existing canonical source row is reused when the direct link is absent', function () {
    $timesheet = identityTimesheet($this->worker, $this->approver);
    $entry = identityEntry($this->worker, $this->approver, [
        'source_type' => 'timesheet',
        'source_id' => $timesheet->id,
    ]);

    app(TimesheetHrSyncService::class)->syncToHr($timesheet);

    expect($timesheet->fresh()->hr_time_entry_id)->toBe($entry->id)
        ->and($entry->fresh()->status)->toBe('approved')
        ->and(HrTimeEntry::query()->where('source_type', 'timesheet')->where('source_id', $timesheet->id)->count())->toBe(1);
});

test('a conflicting direct link and canonical source row fails without rewriting either identity', function () {
    $timesheet = identityTimesheet($this->worker, $this->approver);
    $linked = identityEntry($this->worker, $this->approver);
    $canonical = identityEntry($this->worker, $this->approver, [
        'source_type' => 'timesheet',
        'source_id' => $timesheet->id,
    ]);
    $timesheet->forceFill(['hr_time_entry_id' => $linked->id])->saveQuietly();

    expect(fn () => app(TimesheetHrSyncService::class)->syncToHr($timesheet->fresh()))
        ->toThrow(ValidationException::class);

    expect($timesheet->fresh()->hr_time_entry_id)->toBe($linked->id)
        ->and($linked->fresh()->source_type)->toBeNull()
        ->and($canonical->fresh()->source_id)->toBe($timesheet->id);
});

test('a linked entry for another worker is rejected before mutation', function () {
    $otherWorker = User::factory()->create(['approved_at' => now()]);
    $otherEntry = identityEntry($otherWorker, $this->approver, [
        'status' => 'submitted',
    ]);
    $timesheet = identityTimesheet($this->worker, $this->approver, [
        'hr_time_entry_id' => $otherEntry->id,
    ]);

    expect(fn () => app(TimesheetHrSyncService::class)->syncToHr($timesheet))
        ->toThrow(ValidationException::class);

    expect($otherEntry->fresh()->status)->toBe('submitted')
        ->and($otherEntry->fresh()->source_type)->toBeNull();
});
