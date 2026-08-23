<?php

use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;

/*
 * Manager force-close for stuck open sessions ("End session" on the
 * on-clock-now board). Gated by timesheets.manageAny — the same permission
 * that shows the board. Mirrors clockOut's close path (break event closed,
 * shift completed, draft timesheet synced) with closed_by + audit attribution.
 */

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->site = Site::factory()->create();

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

    foreach ([$this->worker, $this->manager] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }
});

function stuckSessionFor(User $user, array $attributes = []): HrAttendanceSession
{
    return HrAttendanceSession::query()->create(array_merge([
        'tenant_id' => null,
        'user_id' => $user->id,
        'site_id' => $user->hrEmployeeProfile()->value('primary_site_id'),
        'clock_in_at' => now()->subHours(20),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $user->id,
    ], $attributes));
}

it('lets a manager end a stale shiftless session with attribution, timesheet and audit row', function () {
    $session = stuckSessionFor($this->worker);

    $response = $this->actingAs($this->manager)->post("/attendance/sessions/{$session->id}/end", [
        'reason' => 'Missed clock-out — closing administratively',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $session->refresh();
    expect($session->status)->toBe('closed')
        ->and($session->clock_out_at)->not->toBeNull()
        ->and($session->closed_by)->toBe($this->manager->id)
        ->and($session->meta['admin_ended'])->toBeTrue()
        ->and($session->meta['admin_end_reason'])->toBe('Missed clock-out — closing administratively');

    expect(Timesheet::query()->where('attendance_session_id', $session->id)->exists())->toBeTrue();

    $log = AuditLog::query()->where('action', 'attendance.session.adminEnded')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and((int) $log->auditable_id)->toBe($session->id)
        ->and($log->meta['reason'])->toBe('Missed clock-out — closing administratively')
        ->and($log->meta['was_stale'])->toBeTrue()
        ->and($log->user_id)->toBe($this->manager->id);
});

it('closes a shift-linked session at the rostered end and completes the shift', function () {
    $client = Client::factory()->create([
        'site_id' => $this->site->id,
        'status' => 'active',
    ]);
    $shift = Shift::factory()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $this->site->id,
        'user_id' => $this->worker->id,
        'starts_at' => now()->subHours(20),
        'ends_at' => now()->subHours(12),
        'status' => 'in_progress',
    ]);
    $session = stuckSessionFor($this->worker, ['shift_id' => $shift->id]);

    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/end", ['reason' => 'Stale session'])
        ->assertRedirect();

    $session->refresh();
    expect($session->clock_out_at->timestamp)->toBe($shift->fresh()->ends_at->timestamp)
        ->and($shift->fresh()->status)->toBe('completed');
});

it('clamps a days-old open break below the elapsed time instead of failing', function () {
    $session = stuckSessionFor($this->worker, [
        'clock_in_at' => now()->subHours(40),
        'break_started_at' => now()->subHours(39),
    ]);
    HrAttendanceBreakEvent::query()->create([
        'session_id' => $session->id,
        'started_at' => now()->subHours(39),
    ]);

    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/end", ['reason' => 'Stuck with open break'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $session->refresh();
    $elapsed = (int) $session->clock_in_at->diffInMinutes($session->clock_out_at);
    expect($session->status)->toBe('closed')
        ->and($session->break_started_at)->toBeNull()
        ->and($session->break_minutes)->toBeLessThan($elapsed);

    expect(HrAttendanceBreakEvent::query()->where('session_id', $session->id)->whereNull('ended_at')->exists())
        ->toBeFalse();
});

it('refuses users without timesheets.manageAny', function () {
    $session = stuckSessionFor($this->worker);
    $otherWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);

    $this->actingAs($otherWorker)
        ->post("/attendance/sessions/{$session->id}/end", ['reason' => 'Nope'])
        ->assertForbidden();

    expect($session->fresh()->status)->toBe('open');
});

it('is a friendly no-op on an already-closed session', function () {
    $session = stuckSessionFor($this->worker, [
        'clock_in_at' => now()->subHours(5),
        'clock_out_at' => now()->subHours(1),
        'status' => 'closed',
        'closed_by' => $this->worker->id,
    ]);

    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/end", ['reason' => 'Double click'])
        ->assertRedirect()
        ->assertSessionHas('info');

    expect($session->fresh()->closed_by)->toBe($this->worker->id);
});

it('requires a reason', function () {
    $session = stuckSessionFor($this->worker);

    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/end", [])
        ->assertSessionHasErrors('reason');

    expect($session->fresh()->status)->toBe('open');
});
