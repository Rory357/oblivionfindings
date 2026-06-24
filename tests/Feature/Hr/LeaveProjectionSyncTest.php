<?php

use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\LeaveService;
use App\Models\Permission;
use App\Models\StaffTimeOff;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->manager->setAttribute('tenant_id', 1);
    grantPerms($this->manager, ['hr.leave.viewAny', 'hr.leave.approve', 'hr.leave.manage', 'staff.availability.updateAny']);

    $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->staff->setAttribute('tenant_id', 1);
});

function grantPerms(User $user, array $keys): void
{
    foreach ($keys as $key) {
        $perm = Permission::where('key', $key)->first();
        if ($perm) {
            $user->permissionOverrides()->syncWithoutDetaching([$perm->id => ['allowed' => true]]);
        }
    }
}

function pendingRequest(User $staff, array $overrides = []): HrLeaveRequest
{
    HrLeaveBalance::query()->firstOrCreate([
        'tenant_id' => 1, 'user_id' => $staff->id, 'leave_type' => $overrides['leave_type'] ?? 'annual', 'year' => now()->year,
    ], [
        'balance_hours' => 200, 'accrued_hours' => 200, 'used_hours' => 0, 'pending_hours' => 8,
        'source' => 'system', 'last_synced_at' => now(), 'updated_by' => $staff->id,
    ]);

    return HrLeaveRequest::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'leave_type' => 'annual',
        'period' => 'full_day',
        'starts_at' => now()->addDays(5)->startOfDay(),
        'ends_at' => now()->addDays(5)->endOfDay(),
        'hours_requested' => 8,
        'status' => 'pending',
        'submitted_at' => now()->subHours(2),
        'approval_due_at' => now()->addHours(20),
        'escalation_level' => 1,
    ], $overrides));
}

test('approving a request creates a tenant-stamped, back-linked projection carrying period', function () {
    $request = pendingRequest($this->staff, ['period' => 'half_day_am']);

    app(LeaveService::class)->approveRequest($request->fresh(), $this->manager, 'ok');

    $projection = StaffTimeOff::query()->where('hr_leave_request_id', $request->id)->first();
    expect($projection)->not->toBeNull();
    expect($projection->tenant_id)->toBe(1);
    expect($projection->type)->toBe('annual');
    expect($projection->period)->toBe('half_day_am');
    expect($request->fresh()->time_off_id)->toBe($projection->id);
});

test('editing an approved request re-syncs the projection via the observer', function () {
    $request = pendingRequest($this->staff);
    app(LeaveService::class)->approveRequest($request->fresh(), $this->manager, 'ok');

    $newEnd = now()->addDays(8)->endOfDay();
    $request->fresh()->update(['ends_at' => $newEnd]);

    $projection = StaffTimeOff::query()->where('hr_leave_request_id', $request->id)->first();
    expect($projection->ends_at->toDateString())->toBe($newEnd->toDateString());
});

test('roster-entered leave routes through the engine: creates an approved request, ledger and linked projection', function () {
    // Anchor on weekdays so the window always has >0 business hours (a
    // weekend-only window is correctly rejected as zero-hours leave).
    $start = now()->addDays(3);
    while ($start->isWeekend()) {
        $start->addDay();
    }
    $end = $start->copy()->addDay();
    while ($end->isWeekend()) {
        $end->addDay();
    }

    $this->actingAs($this->manager)
        ->post(route('operations.rostering.time_off.store'), [
            'user_id' => $this->staff->id,
            'starts_at' => $start->toDateString(),
            'ends_at' => $end->toDateString(),
            'type' => 'leave',
            'leave_type' => 'annual',
            'label' => 'Covered by roster manager',
        ])
        ->assertSessionHas('success');

    $request = HrLeaveRequest::query()->where('user_id', $this->staff->id)->first();
    expect($request)->not->toBeNull();
    expect($request->status)->toBe('approved');

    $projection = StaffTimeOff::query()->where('hr_leave_request_id', $request->id)->first();
    expect($projection)->not->toBeNull();
    expect($projection->type)->toBe('annual');

    expect(HrLeaveBalanceLedger::query()->where('user_id', $this->staff->id)->where('entry_type', 'approved')->exists())->toBeTrue();
});

test('roster-entered unavailable stays roster-only but is tenant-stamped', function () {
    $this->actingAs($this->manager)
        ->post(route('operations.rostering.time_off.store'), [
            'user_id' => $this->staff->id,
            'starts_at' => now()->addDays(3)->toDateString(),
            'ends_at' => now()->addDays(4)->toDateString(),
            'type' => 'unavailable',
        ])
        ->assertSessionHas('success');

    $row = StaffTimeOff::query()->where('user_id', $this->staff->id)->where('type', 'unavailable')->first();
    expect($row)->not->toBeNull();
    expect($row->tenant_id)->not->toBeNull();
    expect($row->hr_leave_request_id)->toBeNull();
    expect(HrLeaveRequest::query()->where('user_id', $this->staff->id)->exists())->toBeFalse();
});

test('a roster delete of an approved leave projection is blocked to protect the balance', function () {
    $request = pendingRequest($this->staff);
    app(LeaveService::class)->approveRequest($request->fresh(), $this->manager, 'ok');
    $projection = StaffTimeOff::query()->where('hr_leave_request_id', $request->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->delete(route('operations.rostering.time_off.destroy', $projection), [])
        ->assertSessionHas('error');

    expect(StaffTimeOff::query()->whereKey($projection->id)->exists())->toBeTrue();
});

test('a manage-only user (no approve permission) can open a leave request — gate fix', function () {
    $manageOnly = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $manageOnly->setAttribute('tenant_id', 1);
    grantPerms($manageOnly, ['hr.leave.viewAny', 'hr.leave.manage']); // deliberately NOT approve

    $request = pendingRequest($this->staff);

    $this->actingAs($manageOnly)
        ->get(route('hr.leave.show', $request))
        ->assertOk();
});
