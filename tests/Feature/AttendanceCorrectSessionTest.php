<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;

/*
 * Session correction — the "fix a missed clock-out" wizard. Managers
 * (timesheets.manageAny) correct anyone's session; workers only their own.
 * The reason is mandatory and lands in the audit log; the linked timesheet is
 * recalculated, with submitted ones returning to draft for re-approval and
 * approved ones refusing the correction outright.
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

function correctableSessionFor(User $user, array $attributes = []): HrAttendanceSession
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

function correctableEntryFor(HrAttendanceSession $session, array $attributes = []): HrTimeEntry
{
    return HrTimeEntry::query()->create(array_merge([
        'user_id' => $session->user_id,
        'shift_id' => $session->shift_id,
        'attendance_session_id' => $session->id,
        'site_id' => $session->site_id,
        'client_id' => $session->shift?->client_id,
        'entry_date' => $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString(),
        'clock_in' => $session->clock_in_at,
        'clock_out' => $session->clock_out_at,
        'break_minutes' => (int) ($session->break_minutes ?? 0),
        'entry_type' => 'clock',
        'status' => $session->clock_out_at ? 'submitted' : 'active',
        'source_type' => 'attendance',
        'source_id' => $session->id,
        'created_by' => $session->user_id,
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
    $entry = correctableEntryFor($session, [
        'break_minutes' => 45,
        'total_hours' => 7.25,
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

    expect($entry->refresh()->clock_out?->timestamp)->toBe($newOut->timestamp)
        ->and($entry->break_minutes)->toBe(15)
        ->and((float) $entry->total_hours)->toBe(5.75)
        ->and($entry->status)->toBe('submitted')
        ->and(HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->count())->toBe(2);
});

it('backfills canonical Site before correcting legacy shiftless payroll projections', function () {
    $session = correctableSessionFor($this->worker, ['site_id' => null]);
    $entry = correctableEntryFor($session, ['site_id' => null]);
    $clockOutAt = now()->subHours(12);

    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/correct", [
            'clock_out_at' => $clockOutAt->toIso8601String(),
            'break_minutes' => 30,
            'reason' => 'Backfill the retained Site before correcting payroll projections.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $timesheet = Timesheet::query()->where('attendance_session_id', $session->id)->sole();
    expect((int) $session->refresh()->site_id)->toBe($this->site->id)
        ->and((int) $timesheet->site_id)->toBe($this->site->id)
        ->and((int) $entry->refresh()->site_id)->toBe($this->site->id)
        ->and($entry->clock_out?->timestamp)->toBe($clockOutAt->timestamp);
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

    $entry = HrTimeEntry::query()->where('attendance_session_id', $session->id)->sole();
    $originalSession = $session->fresh()->getAttributes();
    $originalTimesheet = $timesheet->fresh()->getAttributes();
    $originalEntry = $entry->getAttributes();
    $auditCount = AuditLog::query()->where('action', 'attendance.session.corrected')->count();
    $amendmentCount = HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->count();

    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/correct", [
            'clock_out_at' => now()->subHours(6)->toIso8601String(),
            'reason' => 'Trying to change approved hours',
        ])
        ->assertSessionHasErrors('correct_session');

    expect($session->fresh()->getAttributes())->toBe($originalSession)
        ->and($timesheet->fresh()->getAttributes())->toBe($originalTimesheet)
        ->and($entry->fresh()->getAttributes())->toBe($originalEntry)
        ->and(AuditLog::query()->where('action', 'attendance.session.corrected')->count())->toBe($auditCount)
        ->and(HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->count())->toBe($amendmentCount);
});

it('conceals another worker session from a self-service correction', function () {
    $otherWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $otherWorker->roles()->syncWithoutDetaching([$supportRole->id]);
    }
    HrEmployeeProfile::factory()->create([
        'user_id' => $otherWorker->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
    $session = correctableSessionFor($otherWorker);

    $this->actingAs($this->worker)
        ->post("/attendance/sessions/{$session->id}/correct", [
            'clock_out_at' => now()->subHours(2)->toIso8601String(),
            'reason' => 'Not mine to fix',
        ])
        ->assertNotFound();

    expect($session->fresh()->status)->toBe('open');
});

it('requires manage-any plus canonical Site scope and conceals missing ids', function () {
    $foreignSite = Site::factory()->create();
    $foreignWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $foreignWorker->id,
        'primary_site_id' => $foreignSite->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
    $reportsBypass = Permission::query()->where('key', 'reports.viewAny')->firstOrFail();
    $this->manager->permissionOverrides()->syncWithoutDetaching([
        $reportsBypass->id => ['allowed' => false],
    ]);
    $session = correctableSessionFor($foreignWorker);
    $payload = [
        'clock_out_at' => now()->subHours(2)->toIso8601String(),
        'reason' => 'Foreign Site must stay concealed',
    ];

    $this->actingAs($this->manager)
        ->post("/attendance/sessions/{$session->id}/correct", $payload)
        ->assertNotFound();

    $this->actingAs($this->manager)
        ->post('/attendance/sessions/999999999/correct', $payload)
        ->assertNotFound();

    expect($session->fresh()->status)->toBe('open');
});

it('rolls back the correction and timesheet when its strict audit write fails', function () {
    $session = correctableSessionFor($this->worker);
    AuditLog::creating(function (AuditLog $log): void {
        if ($log->action === 'attendance.session.corrected') {
            throw new RuntimeException('Injected correction audit failure.');
        }
    });
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->worker)
        ->post("/attendance/sessions/{$session->id}/correct", [
            'clock_out_at' => now()->subHours(2)->toIso8601String(),
            'break_minutes' => 15,
            'reason' => 'This correction must roll back',
        ]))
        ->toThrow(RuntimeException::class, 'Injected correction audit failure.');

    $session->refresh();
    expect($session->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse();
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
