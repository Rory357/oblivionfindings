<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Timesheet;
use App\Models\User;

/*
 * Session correction — the "fix a missed clock-out" wizard. Managers
 * (timesheets.manageAny) correct anyone's session; workers only their own.
 * The reason is mandatory and lands in the audit log; the linked timesheet is
 * recalculated, with submitted ones returning to draft for re-approval and
 * approved ones refusing the correction outright.
 */

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->worker->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    $this->manager = User::factory()->create([
        'role' => 'coordinator',
        'approved_at' => now(),
    ]);
    $overrides = collect([
        Permission::query()->where('key', 'timesheets.manageAny')->first(),
        Permission::query()->where('key', 'timesheets.viewAny')->first(),
    ])->filter()->mapWithKeys(fn (Permission $p) => [$p->id => ['allowed' => true]])->all();
    $this->manager->permissionOverrides()->syncWithoutDetaching($overrides);
});

function correctableSessionFor(User $user, array $attributes = []): HrAttendanceSession
{
    return HrAttendanceSession::query()->create(array_merge([
        'tenant_id' => null,
        'user_id' => $user->id,
        'clock_in_at' => now()->subHours(20),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $user->id,
    ], $attributes));
}

it('lets a worker fix their own missed clock-out with timesheet sync and audit trail', function () {
    $session = correctableSessionFor($this->worker);
    $clockOutAt = now()->subHours(12);

    $response = $this->actingAs($this->worker)->post("/attendance/sessions/{$session->id}/correct", [
        'clock_out_at' => $clockOutAt->toIso8601String(),
        'break_minutes' => 30,
        'reason' => 'Forgot to clock out after the sleepover shift',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $session->refresh();
    expect($session->status)->toBe('closed')
        ->and($session->clock_out_at->timestamp)->toBe($clockOutAt->timestamp)
        ->and($session->break_minutes)->toBe(30)
        ->and($session->closed_by)->toBe($this->worker->id)
        ->and($session->meta['corrected'])->toBeTrue()
        ->and($session->meta['correction_reason'])->toBe('Forgot to clock out after the sleepover shift');

    $timesheet = Timesheet::query()->where('attendance_session_id', $session->id)->first();
    expect($timesheet)->not->toBeNull()
        ->and($timesheet->status)->toBe('draft')
        ->and($timesheet->break_minutes)->toBe(30);

    $log = AuditLog::query()->where('action', 'attendance.session.corrected')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and((int) $log->auditable_id)->toBe($session->id)
        ->and($log->meta['reason'])->toBe('Forgot to clock out after the sleepover shift')
        ->and($log->meta['was_open'])->toBeTrue()
        ->and($log->user_id)->toBe($this->worker->id);
});

it('lets a manager rewrite an already-closed session and recalculate its timesheet', function () {
    $session = correctableSessionFor($this->worker, [
        'clock_in_at' => now()->subHours(10),
        'clock_out_at' => now()->subHours(2),
        'status' => 'closed',
        'closed_by' => $this->worker->id,
    ]);
    $newOut = now()->subHours(4);

    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/correct", [
            'clock_out_at' => $newOut->toIso8601String(),
            'break_minutes' => 15,
            'reason' => 'Left early — site confirmed',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $session->refresh();
    expect($session->clock_out_at->timestamp)->toBe($newOut->timestamp)
        ->and($session->break_minutes)->toBe(15);

    $timesheet = Timesheet::query()->where('attendance_session_id', $session->id)->first();
    expect($timesheet)->not->toBeNull()
        ->and($timesheet->ends_at->timestamp)->toBe($newOut->timestamp);
});

it('returns a submitted timesheet to draft so corrected hours re-enter approval', function () {
    $session = correctableSessionFor($this->worker);

    // First correction creates the draft timesheet…
    $this->actingAs($this->worker)->post("/attendance/sessions/{$session->id}/correct", [
        'clock_out_at' => now()->subHours(12)->toIso8601String(),
        'reason' => 'Missed clock-out',
    ])->assertSessionHas('success');

    $timesheet = Timesheet::query()->where('attendance_session_id', $session->id)->firstOrFail();
    $timesheet->forceFill(['status' => 'submitted'])->saveQuietly();

    // …the follow-up correction pulls it back to draft with the new hours.
    $newOut = now()->subHours(10);
    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/correct", [
            'clock_out_at' => $newOut->toIso8601String(),
            'reason' => 'Actual finish was later',
        ])
        ->assertSessionHas('success');

    $timesheet->refresh();
    expect($timesheet->status)->toBe('draft')
        ->and($timesheet->ends_at->timestamp)->toBe($newOut->timestamp);
});

it('refuses to correct a session whose timesheet is already approved', function () {
    $session = correctableSessionFor($this->worker);

    $this->actingAs($this->worker)->post("/attendance/sessions/{$session->id}/correct", [
        'clock_out_at' => now()->subHours(12)->toIso8601String(),
        'reason' => 'Missed clock-out',
    ])->assertSessionHas('success');

    $timesheet = Timesheet::query()->where('attendance_session_id', $session->id)->firstOrFail();
    $timesheet->forceFill(['status' => 'approved'])->saveQuietly();

    $originalOut = $session->fresh()->clock_out_at;

    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/correct", [
            'clock_out_at' => now()->subHours(6)->toIso8601String(),
            'reason' => 'Trying to change approved hours',
        ])
        ->assertSessionHasErrors('correct_session');

    expect($session->fresh()->clock_out_at->timestamp)->toBe($originalOut->timestamp);
});

it('refuses workers correcting someone else’s session', function () {
    $otherWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $otherWorker->roles()->syncWithoutDetaching([$supportRole->id]);
    }
    $session = correctableSessionFor($otherWorker);

    $this->actingAs($this->worker)
        ->post("/attendance/sessions/{$session->id}/correct", [
            'clock_out_at' => now()->subHours(2)->toIso8601String(),
            'reason' => 'Not mine to fix',
        ])
        ->assertForbidden();

    expect($session->fresh()->status)->toBe('open');
});

it('requires a reason and rejects a clock-out before clock-in', function () {
    $session = correctableSessionFor($this->worker);

    $this->actingAs($this->worker)
        ->post("/attendance/sessions/{$session->id}/correct", [
            'clock_out_at' => now()->subHours(2)->toIso8601String(),
        ])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->worker)
        ->post("/attendance/sessions/{$session->id}/correct", [
            'clock_out_at' => now()->subHours(30)->toIso8601String(),
            'reason' => 'Before clock-in',
        ])
        ->assertSessionHasErrors('correct_session');

    expect($session->fresh()->status)->toBe('open');
});
