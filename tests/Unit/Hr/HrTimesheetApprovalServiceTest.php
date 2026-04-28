<?php

use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimesheet;
use App\Domain\Hr\Services\HrNotificationService;
use App\Domain\Hr\Services\HrTimesheetApprovalService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-04-28 09:00:00'));
});

function hrWorkflowUser(string $role = 'support_worker'): User
{
    return User::factory()->create([
        'organization_id' => 1,
        'role' => $role,
        'approved_at' => now(),
    ]);
}

function hrWorkflowTimesheet(User $user, array $overrides = []): HrTimesheet
{
    return HrTimesheet::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'period_start' => '2026-04-20',
        'period_end' => '2026-04-26',
        'status' => 'draft',
        'total_hours' => 0,
        'created_by' => $user->id,
    ], $overrides));
}

function hrWorkflowEntry(User $user, array $overrides = []): HrTimeEntry
{
    return HrTimeEntry::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'entry_date' => '2026-04-21',
        'clock_in' => '2026-04-21 09:00:00',
        'clock_out' => '2026-04-21 17:00:00',
        'break_minutes' => 30,
        'total_hours' => 7.5,
        'entry_type' => 'clock',
        'status' => 'active',
        'created_by' => $user->id,
    ], $overrides));
}

test('submit accepts draft and returned hr timesheets and recalculates hours', function () {
    $staff = hrWorkflowUser();
    $timesheet = hrWorkflowTimesheet($staff, [
        'status' => 'returned',
        'returned_by' => hrWorkflowUser('hr')->id,
        'returned_at' => now()->subDay(),
        'returned_notes' => 'Please fix.',
    ]);
    hrWorkflowEntry($staff, ['total_hours' => 7.5, 'status' => 'active']);
    hrWorkflowEntry($staff, [
        'entry_date' => '2026-04-22',
        'clock_in' => '2026-04-22 09:00:00',
        'clock_out' => '2026-04-22 12:00:00',
        'total_hours' => 3,
        'status' => 'submitted',
    ]);

    $this->mock(HrNotificationService::class, function ($mock): void {
        $mock->shouldReceive('notifyTimesheetSubmitted')->once();
    });

    $result = app(HrTimesheetApprovalService::class)->submit($timesheet, $staff);

    expect($result->changed)->toBeTrue()
        ->and($result->timesheet->status)->toBe('submitted')
        ->and((float) $result->timesheet->total_hours)->toBe(10.5)
        ->and($result->timesheet->submitted_by)->toBe($staff->id)
        ->and($result->timesheet->returned_by)->toBeNull()
        ->and(HrTimeEntry::query()->where('user_id', $staff->id)->where('status', 'submitted')->count())->toBe(2);
});

test('approve is idempotent and updates related submitted entries once', function () {
    $staff = hrWorkflowUser();
    $approver = hrWorkflowUser('hr');
    $timesheet = hrWorkflowTimesheet($staff, ['status' => 'submitted']);
    hrWorkflowEntry($staff, ['status' => 'submitted']);

    $service = app(HrTimesheetApprovalService::class);

    $first = $service->approve($timesheet, $approver, 'Looks good.');
    $second = $service->approve($timesheet->fresh(), $approver, 'Duplicate click.');

    expect($first->changed)->toBeTrue()
        ->and($second->changed)->toBeFalse()
        ->and($second->timesheet->status)->toBe('approved')
        ->and($second->timesheet->decision_notes)->toBe('Looks good.')
        ->and(HrTimeEntry::query()->where('user_id', $staff->id)->where('status', 'approved')->count())->toBe(1);
});

test('return for changes uses returned status and keeps entries resubmittable', function () {
    $staff = hrWorkflowUser();
    $reviewer = hrWorkflowUser('hr');
    $timesheet = hrWorkflowTimesheet($staff, [
        'status' => 'submitted',
        'approved_by' => $reviewer->id,
        'approved_at' => now()->subHour(),
    ]);
    hrWorkflowEntry($staff, ['status' => 'submitted']);

    $result = app(HrTimesheetApprovalService::class)
        ->returnForChanges($timesheet, $reviewer, 'Correct the break.');

    expect($result->changed)->toBeTrue()
        ->and($result->timesheet->status)->toBe('returned')
        ->and($result->timesheet->returned_by)->toBe($reviewer->id)
        ->and($result->timesheet->returned_notes)->toBe('Correct the break.')
        ->and($result->timesheet->approved_by)->toBeNull()
        ->and(HrTimeEntry::query()->where('user_id', $staff->id)->first()->status)->toBe('active');
});

test('reject records reviewer notes and rejects related entries', function () {
    $staff = hrWorkflowUser();
    $reviewer = hrWorkflowUser('hr');
    $timesheet = hrWorkflowTimesheet($staff, ['status' => 'submitted']);
    hrWorkflowEntry($staff, ['status' => 'submitted']);

    $result = app(HrTimesheetApprovalService::class)
        ->reject($timesheet, $reviewer, 'Not a valid period.');

    expect($result->timesheet->status)->toBe('rejected')
        ->and($result->timesheet->approved_by)->toBe($reviewer->id)
        ->and($result->timesheet->decision_notes)->toBe('Not a valid period.')
        ->and($result->timesheet->rejection_reason)->toBe('Not a valid period.')
        ->and(HrTimeEntry::query()->where('user_id', $staff->id)->first()->status)->toBe('rejected');
});
