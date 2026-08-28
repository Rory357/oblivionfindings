<?php

use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Hr\Services\AttendanceTimeEntryProjector;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Mockery\MockInterface;

/*
 * Manager force-close for stuck open sessions ("End session" on the
 * on-clock-now board). Gated by timesheets.manageAny — the same permission
 * that shows the board. Mirrors clockOut's close path (break event closed,
 * shift completed, draft timesheet synced) with closed_by + audit attribution.
 */

beforeEach(function () {
    $this->seed(RbacSeeder::class);

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

function adminEndEntryFor(HrAttendanceSession $session): HrTimeEntry
{
    return HrTimeEntry::query()->create([
        'user_id' => $session->user_id,
        'shift_id' => $session->shift_id,
        'attendance_session_id' => $session->id,
        'site_id' => $session->site_id,
        'client_id' => $session->shift?->client_id,
        'entry_date' => $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString(),
        'clock_in' => $session->clock_in_at,
        'entry_type' => 'clock',
        'status' => 'active',
        'source_type' => 'attendance',
        'source_id' => $session->id,
        'created_by' => $session->user_id,
    ]);
}

it('lets a manager end a stale shiftless session with attribution, timesheet and audit row', function () {
    $session = stuckSessionFor($this->worker);
    $entry = adminEndEntryFor($session);

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
    expect($entry->refresh()->status)->toBe('submitted')
        ->and($entry->clock_out?->timestamp)->toBe($session->clock_out_at?->timestamp)
        ->and((int) $entry->site_id)->toBe($this->site->id)
        ->and((float) $entry->total_hours)->toBeGreaterThan(0)
        ->and(HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->count())->toBe(1);

    $log = AuditLog::query()->where('action', 'attendance.session.adminEnded')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and((int) $log->auditable_id)->toBe($session->id)
        ->and($log->meta['reason'])->toBe('Missed clock-out — closing administratively')
        ->and($log->meta['was_stale'])->toBeTrue()
        ->and($log->user_id)->toBe($this->manager->id);
});

it('rolls back administrative close when its linked Timesheet is approved', function () {
    $session = stuckSessionFor($this->worker);
    $entry = adminEndEntryFor($session);
    $timesheet = Timesheet::query()->create([
        'user_id' => $this->worker->id,
        'attendance_session_id' => $session->id,
        'site_id' => $this->site->id,
        'activity_type' => 'other',
        'work_date' => $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString(),
        'starts_at' => $session->clock_in_at,
        'ends_at' => now(),
        'break_minutes' => 0,
        'status' => 'draft',
        'created_by' => $this->worker->id,
    ]);
    $timesheet->forceFill([
        'status' => 'approved',
        'approved_at' => now(),
        'approved_by' => $this->manager->id,
    ])->saveQuietly();
    $sessionBefore = $session->fresh()->getAttributes();
    $entryBefore = $entry->fresh()->getAttributes();
    $timesheetBefore = $timesheet->fresh()->getAttributes();

    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/end", [
            'reason' => 'Approved Timesheet follow-up contract.',
        ])
        ->assertSessionHasErrors(['end_session']);

    expect($session->fresh()->getAttributes())->toBe($sessionBefore)
        ->and($entry->fresh()->getAttributes())->toBe($entryBefore)
        ->and($timesheet->fresh()->getAttributes())->toBe($timesheetBefore)
        ->and(AuditLog::query()
            ->where('action', 'attendance.session.adminEnded')
            ->where('auditable_id', $session->id)
            ->exists())->toBeFalse();
});

it('rolls back administrative close when the attendance-backed time entry is approved', function () {
    $session = stuckSessionFor($this->worker);
    $entry = adminEndEntryFor($session);
    $entry->forceFill([
        'status' => 'approved',
        'approved_at' => now(),
        'approved_by' => $this->manager->id,
    ])->saveQuietly();
    $sessionBefore = $session->fresh()->getAttributes();
    $entryBefore = $entry->fresh()->getAttributes();

    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/end", [
            'reason' => 'Approved ledger evidence must remain immutable.',
        ])
        ->assertSessionHasErrors(['end_session']);

    expect($session->fresh()->getAttributes())->toBe($sessionBefore)
        ->and($entry->fresh()->getAttributes())->toBe($entryBefore)
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse()
        ->and(HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->exists())->toBeFalse()
        ->and(AuditLog::query()
            ->where('action', 'attendance.session.adminEnded')
            ->where('auditable_id', $session->id)
            ->exists())->toBeFalse();
});

it('rejects an overlapping legacy session before closing breaks or creating payable evidence', function () {
    $session = stuckSessionFor($this->worker, [
        'clock_in_at' => now()->subHours(2),
        'break_started_at' => now()->subMinutes(20),
    ]);
    $break = HrAttendanceBreakEvent::query()->create([
        'session_id' => $session->id,
        'started_at' => $session->break_started_at,
        'created_by' => $this->worker->id,
    ]);
    HrTimeEntry::query()->create([
        'user_id' => $this->worker->id,
        'site_id' => $this->site->id,
        'entry_date' => now()->toDateString(),
        'clock_in' => now()->subHour(),
        'clock_out' => now()->addHour(),
        'break_minutes' => 0,
        'total_hours' => 2,
        'entry_type' => 'manual',
        'status' => 'active',
        'source_type' => 'manual',
        'created_by' => $this->worker->id,
    ]);
    $sessionBefore = $session->fresh()->getRawOriginal();
    $breakBefore = $break->fresh()->getRawOriginal();

    expect(fn () => app(AttendanceService::class)->adminEndSession(
        $this->manager,
        $session,
        'Regression preflight',
        now(),
    ))->toThrow(LogicException::class, 'overlapping time entry');

    expect($session->fresh()->getRawOriginal())->toBe($sessionBefore)
        ->and($break->fresh()->getRawOriginal())->toBe($breakBefore)
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse()
        ->and(HrTimeEntry::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse()
        ->and(HrTimeEntryAmendment::query()->exists())->toBeFalse()
        ->and(AuditLog::query()
            ->where('action', 'attendance.session.adminEnded')
            ->where('auditable_id', $session->id)
            ->exists())->toBeFalse();
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

it('rolls back the administrative close and timesheet when its strict audit write fails', function () {
    $session = stuckSessionFor($this->worker);
    $entry = adminEndEntryFor($session);
    AuditLog::creating(function (AuditLog $log): void {
        if ($log->action === 'attendance.session.adminEnded') {
            throw new RuntimeException('Injected admin-end audit failure.');
        }
    });
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/end", [
            'reason' => 'This administrative close must roll back',
        ]))
        ->toThrow(RuntimeException::class, 'Injected admin-end audit failure.');

    $session->refresh();
    $entry->refresh();
    expect($session->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse()
        ->and($entry->status)->toBe('active')
        ->and($entry->clock_out)->toBeNull()
        ->and(HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->exists())->toBeFalse();
});

it('rolls back attendance shift and timesheet when the atomic time projection fails', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $this->site->id,
        'user_id' => $this->worker->id,
        'starts_at' => now()->subHours(4),
        'ends_at' => now()->subHour(),
        'actual_starts_at' => now()->subHours(4),
        'status' => 'in_progress',
    ]);
    $session = stuckSessionFor($this->worker, [
        'shift_id' => $shift->id,
        'clock_in_at' => now()->subHours(4),
    ]);
    $entry = adminEndEntryFor($session);

    $this->partialMock(AttendanceTimeEntryProjector::class, function (MockInterface $mock): void {
        $mock->shouldReceive('project')
            ->once()
            ->andThrow(new RuntimeException('Injected attendance projection failure.'));
    });
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/end", [
            'reason' => 'Projection rollback proof',
        ]))
        ->toThrow(RuntimeException::class, 'Injected attendance projection failure.');

    $session->refresh();
    $shift->refresh();
    $entry->refresh();
    expect($session->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and($shift->status)->toBe('in_progress')
        ->and($shift->actual_ends_at)->toBeNull()
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse()
        ->and($entry->status)->toBe('active')
        ->and($entry->clock_out)->toBeNull()
        ->and(AuditLog::query()->where('action', 'attendance.session.adminEnded')->exists())->toBeFalse();
});
