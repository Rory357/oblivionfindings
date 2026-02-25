<?php

use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->setAttribute('tenant_id', 1);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->staff->setAttribute('tenant_id', 1);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }
});

function ensureLeaveBalance(User $staff, User $actor, string $leaveType): void
{
    HrLeaveBalance::query()->firstOrCreate([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'leave_type' => $leaveType,
        'year' => now()->year,
    ], [
        'balance_hours' => 200,
        'accrued_hours' => 200,
        'used_hours' => 0,
        'pending_hours' => 16,
        'source' => 'system',
        'last_synced_at' => now(),
        'updated_by' => $actor->id,
    ]);
}

test('hr approver can bulk approve and bulk decline pending leave requests', function () {
    ensureLeaveBalance($this->staff, $this->hr, 'annual');
    ensureLeaveBalance($this->staff, $this->hr, 'sick');

    $approveA = HrLeaveRequest::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'starts_at' => now()->addDays(5)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'hours_requested' => 8,
        'status' => 'pending',
        'submitted_at' => now()->subDay(),
        'approval_due_at' => now()->addHours(20),
        'escalation_level' => 1,
    ]);

    $approveB = HrLeaveRequest::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'starts_at' => now()->addDays(8)->toDateString(),
        'ends_at' => now()->addDays(9)->toDateString(),
        'hours_requested' => 16,
        'status' => 'pending',
        'submitted_at' => now()->subDay(),
        'approval_due_at' => now()->addHours(18),
        'escalation_level' => 1,
    ]);

    $declineA = HrLeaveRequest::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'leave_type' => 'sick',
        'starts_at' => now()->addDays(11)->toDateString(),
        'ends_at' => now()->addDays(11)->toDateString(),
        'hours_requested' => 8,
        'status' => 'pending',
        'submitted_at' => now()->subDay(),
        'approval_due_at' => now()->addHours(16),
        'escalation_level' => 1,
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/leave/bulk-approve', [
            'request_ids' => [$approveA->id, $approveB->id],
            'review_notes' => 'Bulk approved by manager.',
        ])
        ->assertSessionHas('success');

    $approveA->refresh();
    $approveB->refresh();
    expect($approveA->status)->toBe('approved');
    expect($approveB->status)->toBe('approved');
    expect($approveA->reviewed_by)->toBe($this->hr->id);
    expect($approveB->approval_due_at)->toBeNull();

    $this->actingAs($this->hr)
        ->post('/hr/leave/bulk-decline', [
            'request_ids' => [$declineA->id],
            'review_notes' => 'Insufficient details supplied.',
        ])
        ->assertSessionHas('success');

    $declineA->refresh();
    expect($declineA->status)->toBe('declined');
    expect($declineA->reviewed_by)->toBe($this->hr->id);
});

test('hr approver can adjust sla due and trigger immediate escalation', function () {
    $request = HrLeaveRequest::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'starts_at' => now()->addDays(6)->toDateString(),
        'ends_at' => now()->addDays(6)->toDateString(),
        'hours_requested' => 8,
        'status' => 'pending',
        'submitted_at' => now()->subDay(),
        'approval_due_at' => now()->subHours(2),
        'escalation_level' => 1,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/leave/{$request->id}/sla-due", [
            'hours' => 24,
        ])
        ->assertSessionHas('success');

    $request->refresh();
    expect($request->approval_due_at)->not->toBeNull();
    expect($request->approval_due_at?->greaterThan(now()->addHours(23)))->toBeTrue();

    $overdue = HrLeaveRequest::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'leave_type' => 'annual',
        'starts_at' => now()->addDays(10)->toDateString(),
        'ends_at' => now()->addDays(10)->toDateString(),
        'hours_requested' => 8,
        'status' => 'pending',
        'submitted_at' => now()->subDay(),
        'approval_due_at' => now()->subHours(1),
        'escalation_level' => 1,
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/leave/escalate-now')
        ->assertSessionHas('success');

    $overdue->refresh();
    expect($overdue->escalation_level)->toBeGreaterThan(1);
    expect($overdue->escalated_to)->not->toBeNull();
});
