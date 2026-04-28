<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }
});

function openShiftSessionFor(User $staff): HrAttendanceSession
{
    $client = Client::factory()->create();
    $serviceContext = ServiceContext::factory()->create();
    $shift = Shift::query()->create([
        'client_id' => $client->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $staff->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(7),
        'status' => 'in_progress',
        'actual_starts_at' => now()->subHour(),
        'started_by' => $staff->id,
        'created_by' => $staff->id,
    ]);

    ShiftTask::query()->create([
        'shift_id' => $shift->id,
        'label' => 'Lock up',
        'is_completed' => false,
        'sort_order' => 1,
    ]);

    return HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $staff->id,
        'shift_id' => $shift->id,
        'clock_in_at' => now()->subHour(),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $staff->id,
    ]);
}

test('clock out is blocked when end of shift checklist items are outstanding', function () {
    $session = openShiftSessionFor($this->staff);

    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
        ])
        ->assertSessionHasErrors(['clock_out']);

    expect($session->fresh()->status)->toBe('open');
});

test('forced clock out succeeds with an override reason', function () {
    $session = openShiftSessionFor($this->staff);

    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
            'force' => true,
            'override_reason' => 'Medication record is being corrected by the senior.',
        ])
        ->assertSessionHas('success');

    expect($session->fresh()->status)->toBe('closed');
});

test('handover submit does not crash when no incoming shift exists', function () {
    // Regression: ShiftHandoverService::save called
    // resolveExpectedIncomingShift(...)->get('matched_shift') but the resolver
    // returns a plain array. End-of-shift dialog hit
    // "Call to a member function get() on array" whenever a worker submitted
    // a handover for a shift with no follow-on shift to match.
    $session = openShiftSessionFor($this->staff);

    $this->actingAs($this->staff)
        ->post('/attendance/handover', [
            'shift_id' => $session->shift_id,
            'meds_completed' => true,
            'shift_rating' => 'calm',
            'handover_notes' => 'Regression coverage for handover submit fatal.',
            'follow_up_needed' => false,
        ])
        ->assertSessionHas('success');
});
