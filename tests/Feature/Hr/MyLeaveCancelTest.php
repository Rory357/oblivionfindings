<?php

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\User;

beforeEach(function () {
    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->other = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->site = ensureCanonicalHrStaffProfile($this->worker);
    ensureCanonicalHrStaffProfile($this->other, $this->site);
});

function makeLeaveRequest(int $userId, string $status): HrLeaveRequest
{
    return HrLeaveRequest::query()->create([
        'user_id' => $userId,
        'leave_type' => 'annual',
        'period' => 'full_day',
        'starts_at' => now()->addWeeks(2),
        'ends_at' => now()->addWeeks(2)->addDay(),
        'hours_requested' => 8,
        'reason' => 'Personal day',
        'status' => $status,
        'submitted_at' => now(),
        'created_by' => $userId,
    ]);
}

test('an employee can cancel their own pending leave request', function () {
    $leave = makeLeaveRequest($this->worker->id, 'pending');

    $this->actingAs($this->worker)
        ->delete("/hr/my/leave/{$leave->id}")
        ->assertSessionHas('success');

    expect($leave->fresh()->status)->toBe('cancelled');
});

test('an employee cannot cancel another persons leave request', function () {
    $leave = makeLeaveRequest($this->other->id, 'pending');

    $this->actingAs($this->worker)
        ->delete("/hr/my/leave/{$leave->id}")
        ->assertForbidden();

    expect($leave->fresh()->status)->toBe('pending');
});

test('a non-pending/approved leave request cannot be cancelled', function () {
    $leave = makeLeaveRequest($this->worker->id, 'rejected');

    $this->actingAs($this->worker)
        ->delete("/hr/my/leave/{$leave->id}")
        ->assertStatus(422);

    expect($leave->fresh()->status)->toBe('rejected');
});
