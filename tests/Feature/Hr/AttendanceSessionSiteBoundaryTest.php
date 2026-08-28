<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

function attendanceBoundaryUser(Site $site, array $permissions): User
{
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
    $user->permissionOverrides()->sync(
        $permissionIds->mapWithKeys(fn ($id) => [(int) $id => ['allowed' => true]])->all(),
    );

    return $user;
}

function attendanceBoundarySession(
    User $worker,
    Site $site,
    bool $withShift = true,
    bool $captureSite = true,
): HrAttendanceSession {
    $shift = null;
    if ($withShift) {
        $client = Client::factory()->create(['site_id' => $site->id]);
        $shift = Shift::factory()->create([
            'user_id' => $worker->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'status' => 'scheduled',
        ]);
    }

    return HrAttendanceSession::query()->create([
        'user_id' => $worker->id,
        'shift_id' => $shift?->id,
        'site_id' => $captureSite ? $site->id : null,
        'clock_in_at' => now()->subHours(2),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $worker->id,
    ]);
}

function attendanceBoundaryEntry(HrAttendanceSession $session): HrTimeEntry
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

test('a manager cannot disclose or end a foreign Site attendance session by direct id', function (): void {
    $localSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $manager = attendanceBoundaryUser($localSite, ['timesheets.manageAny']);
    $worker = attendanceBoundaryUser($foreignSite, []);
    $session = attendanceBoundarySession($worker, $foreignSite);

    $foreign = $this->actingAs($manager)->post(route('attendance.sessions.end', $session), [
        'reason' => 'Attempted foreign Site close.',
    ]);
    $missing = $this->actingAs($manager)->post(route('attendance.sessions.end', 999999), [
        'reason' => 'Missing session comparison.',
    ]);

    $foreign->assertNotFound();
    $missing->assertNotFound();
    $foreign->assertDontSee($worker->name);
    expect($session->refresh()->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and($session->closed_by)->toBeNull()
        ->and($session->shift->refresh()->status)->toBe('scheduled')
        ->and($session->timesheet()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'attendance.session.adminEnded')->exists())->toBeFalse();
});

test('an approve-only manager with global reporting scope cannot correct a direct report at an unapproved foreign Site', function (): void {
    $localSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $manager = attendanceBoundaryUser($localSite, ['timesheets.approve', 'shifts.update', 'reports.viewAny']);
    $worker = attendanceBoundaryUser($foreignSite, []);
    $worker->hrEmployeeProfile()->update(['manager_user_id' => $manager->id]);
    $session = attendanceBoundarySession($worker, $foreignSite);
    $entry = attendanceBoundaryEntry($session);
    $sessionBefore = $session->fresh()->getAttributes();
    $entryBefore = $entry->fresh()->getAttributes();
    $timesheetCount = Timesheet::query()->where('attendance_session_id', $session->id)->count();
    $auditCount = AuditLog::query()
        ->where('action', 'attendance.session.corrected')
        ->where('auditable_id', $session->id)
        ->count();
    $payload = [
        'clock_out_at' => now()->subMinute()->toIso8601String(),
        'break_minutes' => 0,
        'reason' => 'Attempted foreign direct-report correction.',
    ];

    expect($manager->canDo('timesheets.approve'))->toBeTrue()
        ->and($manager->canDo('timesheets.manageAny'))->toBeFalse()
        ->and($manager->canDo('reports.viewAny'))->toBeTrue()
        ->and($manager->canDo('shifts.update'))->toBeTrue();

    $foreign = $this->actingAs($manager)
        ->post(route('attendance.sessions.correct', $session), $payload);
    $missing = $this->actingAs($manager)
        ->post(route('attendance.sessions.correct', 999999), $payload);

    $foreign->assertNotFound();
    $missing->assertNotFound();
    expect(fn () => app(AttendanceService::class)->correctSession(
        $manager,
        $session,
        now()->subMinute(),
        0,
        'Attempted direct domain correction.',
    ))->toThrow(NotFoundHttpException::class);

    expect($session->fresh()->getAttributes())->toBe($sessionBefore)
        ->and($entry->fresh()->getAttributes())->toBe($entryBefore)
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->count())->toBe($timesheetCount)
        ->and(AuditLog::query()
            ->where('action', 'attendance.session.corrected')
            ->where('auditable_id', $session->id)
            ->count())->toBe($auditCount);
});

test('an approve-only manager can correct a direct report at their approved Site', function (): void {
    $site = Site::factory()->create();
    $manager = attendanceBoundaryUser($site, ['timesheets.approve', 'shifts.update']);
    $worker = attendanceBoundaryUser($site, []);
    $worker->hrEmployeeProfile()->update(['manager_user_id' => $manager->id]);
    $session = attendanceBoundarySession($worker, $site);
    $entry = attendanceBoundaryEntry($session);
    $clockOutAt = now()->subMinute();

    $this->actingAs($manager)
        ->post(route('attendance.sessions.correct', $session), [
            'clock_out_at' => $clockOutAt->toIso8601String(),
            'break_minutes' => 0,
            'reason' => 'Confirmed direct-report finish time.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($session->refresh()->status)->toBe('closed')
        ->and($session->clock_out_at?->timestamp)->toBe($clockOutAt->timestamp)
        ->and($session->closed_by)->toBe($manager->id)
        ->and($entry->refresh()->clock_out?->timestamp)->toBe($clockOutAt->timestamp)
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('action', 'attendance.session.corrected')
            ->where('auditable_id', $session->id)
            ->where('user_id', $manager->id)
            ->exists())->toBeTrue();
});

test('self clock in surfaces conceal missing and foreign Shifts before payload validation', function (): void {
    $site = Site::factory()->create();
    $worker = attendanceBoundaryUser($site, ['timesheets.create', 'timesheets.viewAny']);
    $otherWorker = attendanceBoundaryUser($site, []);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $ownShift = Shift::factory()->create([
        'user_id' => $worker->id,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'status' => 'scheduled',
    ]);
    $foreignShift = Shift::factory()->create([
        'user_id' => $otherWorker->id,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'status' => 'scheduled',
    ]);

    foreach (['/attendance/clock-in', '/hr/time/clock-in', '/hr/my/time/clock-in'] as $endpoint) {
        foreach ([$foreignShift->id, 999999] as $concealedShiftId) {
            $this->actingAs($worker)
                ->post($endpoint, [
                    'shift_id' => $concealedShiftId,
                    'notes' => str_repeat('x', 2101),
                ])
                ->assertNotFound();
        }

        $this->actingAs($worker)
            ->post($endpoint, [
                'shift_id' => $ownShift->id,
                'notes' => str_repeat('x', 2101),
            ])
            ->assertSessionHasErrors('notes');
    }

    expect(HrAttendanceSession::query()->where('user_id', $worker->id)->exists())->toBeFalse()
        ->and(HrTimeEntry::query()->where('user_id', $worker->id)->exists())->toBeFalse();
});

test('self clock out conceals session identity first and binds Client and task IDs to its locked Shift', function (): void {
    $site = Site::factory()->create();
    $worker = attendanceBoundaryUser($site, ['timesheets.create']);
    $otherWorker = attendanceBoundaryUser($site, []);
    $session = attendanceBoundarySession($worker, $site);
    $foreignSession = attendanceBoundarySession($otherWorker, $site);
    $ownTask = $session->shift->tasks()->create([
        'label' => 'Own task',
        'is_completed' => false,
        'sort_order' => 1,
    ]);
    $foreignTask = $foreignSession->shift->tasks()->create([
        'label' => 'Foreign task',
        'is_completed' => false,
        'sort_order' => 1,
    ]);
    $foreignClient = Client::factory()->create(['site_id' => $site->id]);

    foreach ([$foreignSession->id, 999999] as $concealedSessionId) {
        $this->actingAs($worker)
            ->post(route('attendance.clockOut'), [
                'session_id' => $concealedSessionId,
                'break_minutes' => 999,
                'client_id' => 999999,
                'task_updates' => [['id' => 999999]],
            ])
            ->assertNotFound();
    }

    foreach ([$foreignClient->id, 999999] as $concealedClientId) {
        $this->actingAs($worker)
            ->post(route('attendance.clockOut'), [
                'session_id' => $session->id,
                'break_minutes' => 0,
                'client_id' => $concealedClientId,
            ])
            ->assertNotFound();
    }

    foreach ([$foreignTask->id, 999999] as $concealedTaskId) {
        $this->actingAs($worker)
            ->post(route('attendance.clockOut'), [
                'session_id' => $session->id,
                'break_minutes' => 0,
                'client_id' => $session->shift->client_id,
                'task_updates' => [[
                    'id' => $concealedTaskId,
                    'is_completed' => true,
                ]],
            ])
            ->assertNotFound();
    }

    expect($session->fresh()->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and($ownTask->fresh()->is_completed)->toBeFalse()
        ->and($foreignTask->fresh()->is_completed)->toBeFalse()
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse()
        ->and(HrTimeEntry::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse();
});

test('the manager board and staff filter conceal foreign Site attendance', function (): void {
    $localSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $manager = attendanceBoundaryUser($localSite, ['timesheets.manageAny', 'timesheets.viewAny']);
    $localWorker = attendanceBoundaryUser($localSite, []);
    $foreignWorker = attendanceBoundaryUser($foreignSite, []);
    $localSession = attendanceBoundarySession($localWorker, $localSite);
    $foreignSession = attendanceBoundarySession($foreignWorker, $foreignSite);

    $response = $this->actingAs($manager)->get('/attendance');
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    $staffIds = collect($props['staff'])->pluck('id');
    expect(collect($props['onClockNow'])->pluck('id')->all())->toBe([$localSession->id])
        ->and($staffIds->contains($localWorker->id))->toBeTrue()
        ->and($staffIds->contains($foreignWorker->id))->toBeFalse()
        ->and(HrAttendanceSession::query()->whereKey($foreignSession->id)->exists())->toBeTrue();

    $this->actingAs($manager)
        ->get('/attendance?user_id='.$foreignWorker->id)
        ->assertNotFound();
});

test('the manager board and end command share the linked Shift Site after a worker profile moves', function (): void {
    $shiftSite = Site::factory()->create();
    $currentProfileSite = Site::factory()->create();
    $manager = attendanceBoundaryUser($shiftSite, ['timesheets.manageAny', 'timesheets.viewAny']);
    $worker = attendanceBoundaryUser($currentProfileSite, []);
    $session = attendanceBoundarySession($worker, $shiftSite);

    $page = $this->actingAs($manager)->get('/attendance')->assertOk();
    $props = $page->viewData('page')['props'];

    expect(collect($props['onClockNow'])->pluck('id'))
        ->toContain($session->id)
        ->and(collect($props['staff'])->pluck('id'))
        ->not->toContain($worker->id);

    $this->actingAs($manager)
        ->post(route('attendance.sessions.end', $session), [
            'reason' => 'Confirmed against the captured Shift Site.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($session->refresh()->status)->toBe('closed')
        ->and($session->closed_by)->toBe($manager->id)
        ->and($session->timesheet?->shift_site_id)->toBe($shiftSite->id)
        ->and(AuditLog::query()
            ->where('action', 'attendance.session.adminEnded')
            ->where('auditable_id', $session->id)
            ->count())->toBe(1);
});

test('a shiftless session falls back to the workers current primary Site', function (): void {
    $localSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $manager = attendanceBoundaryUser($localSite, ['timesheets.manageAny']);
    $worker = attendanceBoundaryUser($foreignSite, []);
    $session = attendanceBoundarySession($worker, $foreignSite, false, false);

    $this->actingAs($manager)
        ->post(route('attendance.sessions.end', $session), ['reason' => 'Foreign fallback attempt.'])
        ->assertNotFound();

    expect($session->refresh()->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull();

    $localManager = attendanceBoundaryUser($foreignSite, ['timesheets.manageAny']);
    $this->actingAs($localManager)
        ->post(route('attendance.sessions.end', $session), ['reason' => 'Confirmed legacy Site fallback.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($session->refresh()->status)->toBe('closed')
        ->and($session->closed_by)->toBe($localManager->id)
        ->and($session->site_id)->toBe($foreignSite->id)
        ->and($session->timesheet?->site_id)->toBe($foreignSite->id);
});

test('a shiftless session keeps its captured Site when the worker profile later changes', function (): void {
    $capturedSite = Site::factory()->create();
    $currentProfileSite = Site::factory()->create();
    $manager = attendanceBoundaryUser($capturedSite, ['timesheets.manageAny']);
    $worker = attendanceBoundaryUser($currentProfileSite, []);
    $session = attendanceBoundarySession($worker, $capturedSite, false);

    $this->actingAs($manager)
        ->post(route('attendance.sessions.end', $session), ['reason' => 'Close against captured Site.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($session->refresh()->status)->toBe('closed')
        ->and($session->closed_by)->toBe($manager->id);
});

test('worker clock out backfills canonical Site across legacy shiftless attendance payroll projections', function (): void {
    $site = Site::factory()->create();
    $worker = attendanceBoundaryUser($site, ['timesheets.create']);
    $session = attendanceBoundarySession($worker, $site, false, false);

    $this->actingAs($worker)
        ->post(route('attendance.clockOut'), [
            'session_id' => $session->id,
            'break_minutes' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $timesheet = Timesheet::query()->where('attendance_session_id', $session->id)->sole();
    $entry = HrTimeEntry::query()->where('attendance_session_id', $session->id)->sole();

    expect($session->refresh()->status)->toBe('closed')
        ->and((int) $session->site_id)->toBe($site->id)
        ->and((int) $timesheet->site_id)->toBe($site->id)
        ->and((int) $entry->site_id)->toBe($site->id)
        ->and($entry->clock_out)->not->toBeNull();
});

test('conflicting session and Shift Site provenance is concealed without mutation', function (): void {
    $shiftSite = Site::factory()->create();
    $conflictingSite = Site::factory()->create();
    $manager = attendanceBoundaryUser($conflictingSite, ['timesheets.manageAny', 'reports.viewAny']);
    $worker = attendanceBoundaryUser($shiftSite, []);
    $session = attendanceBoundarySession($worker, $shiftSite);
    DB::table('hr_attendance_sessions')->where('id', $session->id)->update([
        'site_id' => $conflictingSite->id,
    ]);

    $this->actingAs($manager)
        ->post(route('attendance.sessions.end', $session), ['reason' => 'Conflicting provenance attempt.'])
        ->assertNotFound();

    expect($session->refresh()->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and($session->closed_by)->toBeNull()
        ->and($session->shift->refresh()->status)->toBe('scheduled')
        ->and($session->timesheet()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'attendance.session.adminEnded')->exists())->toBeFalse();
});

test('worker clock out conceals a Shift reassigned after attendance began without mutation', function (): void {
    $site = Site::factory()->create();
    $worker = attendanceBoundaryUser($site, ['timesheets.create']);
    $replacement = attendanceBoundaryUser($site, []);
    $session = attendanceBoundarySession($worker, $site);
    $shift = $session->shift;
    $shift->update([
        'user_id' => $replacement->id,
        'status' => 'in_progress',
        'actual_starts_at' => $session->clock_in_at,
        'started_by' => $worker->id,
    ]);

    $this->actingAs($worker)
        ->post(route('attendance.clockOut'), [
            'session_id' => $session->id,
            'break_minutes' => 0,
            'force' => true,
            'override_reason' => 'Must not complete a replacement workers Shift.',
        ])
        ->assertNotFound();

    expect($session->refresh()->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and($session->closed_by)->toBeNull()
        ->and($session->timesheet()->exists())->toBeFalse()
        ->and($shift->refresh()->status)->toBe('in_progress')
        ->and($shift->user_id)->toBe($replacement->id)
        ->and($shift->actual_ends_at)->toBeNull()
        ->and($shift->completed_by)->toBeNull()
        ->and(AuditLog::query()->where('action', 'attendance.clockOut.forced')->exists())->toBeFalse();
});

test('worker clock out conceals conflicting captured and Shift Sites without mutation', function (): void {
    $shiftSite = Site::factory()->create();
    $conflictingSite = Site::factory()->create();
    $worker = attendanceBoundaryUser($shiftSite, ['timesheets.create']);
    $session = attendanceBoundarySession($worker, $shiftSite);
    $shift = $session->shift;
    $shift->update([
        'status' => 'in_progress',
        'actual_starts_at' => $session->clock_in_at,
        'started_by' => $worker->id,
    ]);
    DB::table('hr_attendance_sessions')->where('id', $session->id)->update([
        'site_id' => $conflictingSite->id,
    ]);

    $this->actingAs($worker)
        ->post(route('attendance.clockOut'), [
            'session_id' => $session->id,
            'break_minutes' => 0,
            'force' => true,
            'override_reason' => 'Conflicting Site provenance must fail closed.',
        ])
        ->assertNotFound();

    expect($session->refresh()->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and($session->closed_by)->toBeNull()
        ->and($session->timesheet()->exists())->toBeFalse()
        ->and($shift->refresh()->status)->toBe('in_progress')
        ->and($shift->actual_ends_at)->toBeNull()
        ->and($shift->completed_by)->toBeNull()
        ->and(AuditLog::query()->where('action', 'attendance.clockOut.forced')->exists())->toBeFalse();
});

test('worker can close attendance linked to a cancelled Shift without changing terminal state', function (): void {
    $site = Site::factory()->create();
    $worker = attendanceBoundaryUser($site, ['timesheets.create']);
    $session = attendanceBoundarySession($worker, $site);
    $shift = $session->shift;
    $shift->update([
        'status' => 'cancelled',
        'actual_starts_at' => null,
        'actual_ends_at' => null,
        'completed_by' => null,
    ]);

    $this->actingAs($worker)
        ->post(route('attendance.clockOut'), [
            'session_id' => $session->id,
            'break_minutes' => 0,
            'force' => true,
            'override_reason' => 'Close retained attendance after Shift cancellation.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($session->refresh()->status)->toBe('closed')
        ->and($session->clock_out_at)->not->toBeNull()
        ->and($session->closed_by)->toBe($worker->id)
        ->and($session->timesheet()->exists())->toBeFalse()
        ->and($shift->refresh()->status)->toBe('cancelled')
        ->and($shift->actual_starts_at)->toBeNull()
        ->and($shift->actual_ends_at)->toBeNull()
        ->and($shift->completed_by)->toBeNull();
});

test('manager can end reassigned attendance without completing the replacement workers Shift', function (): void {
    $site = Site::factory()->create();
    $manager = attendanceBoundaryUser($site, ['timesheets.manageAny']);
    $worker = attendanceBoundaryUser($site, []);
    $replacement = attendanceBoundaryUser($site, []);
    $session = attendanceBoundarySession($worker, $site);
    $shift = $session->shift;
    $shift->update([
        'user_id' => $replacement->id,
        'status' => 'in_progress',
        'actual_starts_at' => $session->clock_in_at,
        'started_by' => $worker->id,
    ]);

    $this->actingAs($manager)
        ->post(route('attendance.sessions.end', $session), [
            'reason' => 'Recover retained attendance after reassignment.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($session->refresh()->status)->toBe('closed')
        ->and($session->closed_by)->toBe($manager->id)
        ->and($session->timesheet()->exists())->toBeFalse()
        ->and($shift->refresh()->status)->toBe('in_progress')
        ->and($shift->user_id)->toBe($replacement->id)
        ->and($shift->actual_ends_at)->toBeNull()
        ->and($shift->completed_by)->toBeNull();
});

test('manager can correct reassigned attendance without completing the replacement workers Shift', function (): void {
    $site = Site::factory()->create();
    $manager = attendanceBoundaryUser($site, ['timesheets.manageAny']);
    $worker = attendanceBoundaryUser($site, []);
    $replacement = attendanceBoundaryUser($site, []);
    $session = attendanceBoundarySession($worker, $site);
    $shift = $session->shift;
    $shift->update([
        'user_id' => $replacement->id,
        'status' => 'in_progress',
        'actual_starts_at' => $session->clock_in_at,
        'started_by' => $worker->id,
    ]);

    $this->actingAs($manager)
        ->post(route('attendance.sessions.correct', $session), [
            'clock_out_at' => now()->subMinute()->toIso8601String(),
            'break_minutes' => 0,
            'reason' => 'Correct retained attendance after reassignment.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($session->refresh()->status)->toBe('closed')
        ->and($session->closed_by)->toBe($manager->id)
        ->and($session->timesheet()->exists())->toBeFalse()
        ->and($shift->refresh()->status)->toBe('in_progress')
        ->and($shift->user_id)->toBe($replacement->id)
        ->and($shift->actual_ends_at)->toBeNull()
        ->and($shift->completed_by)->toBeNull();
});

test('manager correction of reassigned attendance preserves submitted payroll evidence and updates the original workers time entry', function (): void {
    $site = Site::factory()->create();
    $manager = attendanceBoundaryUser($site, ['timesheets.manageAny']);
    $worker = attendanceBoundaryUser($site, []);
    $replacement = attendanceBoundaryUser($site, []);
    $session = attendanceBoundarySession($worker, $site);
    $shift = $session->shift;
    $entry = attendanceBoundaryEntry($session);
    $timesheet = Timesheet::factory()->create([
        'shift_id' => $shift->id,
        'attendance_session_id' => $session->id,
        'user_id' => $worker->id,
        'client_id' => $shift->client_id,
        'shift_site_id' => $site->id,
        'work_date' => $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString(),
        'starts_at' => $session->clock_in_at,
        'ends_at' => now(),
        'break_minutes' => 0,
        'status' => 'draft',
        'created_by' => $worker->id,
    ]);
    $timesheet->forceFill([
        'status' => 'submitted',
        'submitted_at' => now(),
        'submitted_by' => $worker->id,
    ])->saveQuietly();
    $timesheetBefore = $timesheet->fresh()->getAttributes();

    $shift->update([
        'user_id' => $replacement->id,
        'status' => 'in_progress',
        'actual_starts_at' => $session->clock_in_at,
        'started_by' => $worker->id,
    ]);
    $correctedOut = now()->subMinute();

    $this->actingAs($manager)
        ->post(route('attendance.sessions.correct', $session), [
            'clock_out_at' => $correctedOut->toIso8601String(),
            'break_minutes' => 0,
            'reason' => 'Correct retained attendance without rewriting payroll evidence.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Payroll follow-up'));

    expect($session->refresh()->status)->toBe('closed')
        ->and($session->clock_out_at?->timestamp)->toBe($correctedOut->timestamp)
        ->and($shift->refresh()->status)->toBe('in_progress')
        ->and($shift->user_id)->toBe($replacement->id)
        ->and($shift->actual_ends_at)->toBeNull()
        ->and($timesheet->fresh()->getAttributes())->toBe($timesheetBefore)
        ->and($entry->refresh()->user_id)->toBe($worker->id)
        ->and($entry->clock_out?->timestamp)->toBe($correctedOut->timestamp)
        ->and($entry->status)->toBe('submitted')
        ->and(HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('action', 'attendance.session.corrected')
            ->where('auditable_id', $session->id)
            ->where('meta->timesheet_sync_outcome', 'skipped_follow_up')
            ->exists())->toBeTrue();
});

test('conflicting per-row attendance and time-entry links fail without side effects', function (): void {
    $site = Site::factory()->create();
    $manager = attendanceBoundaryUser($site, ['timesheets.manageAny']);
    $worker = attendanceBoundaryUser($site, []);
    $session = attendanceBoundarySession($worker, $site, false);
    $canonicalEntry = attendanceBoundaryEntry($session);
    $otherEntry = HrTimeEntry::factory()->create([
        'user_id' => $worker->id,
        'attendance_session_id' => null,
        'shift_id' => null,
        'site_id' => $site->id,
        'entry_date' => today(),
        'clock_in' => now()->subHours(3),
        'clock_out' => now()->subHours(2),
        'status' => 'submitted',
        'created_by' => $worker->id,
    ]);
    $timesheet = Timesheet::query()->create([
        'user_id' => $worker->id,
        'attendance_session_id' => $session->id,
        'hr_time_entry_id' => $otherEntry->id,
        'site_id' => $site->id,
        'activity_type' => 'other',
        'work_date' => today(),
        'starts_at' => $session->clock_in_at,
        'ends_at' => now(),
        'break_minutes' => 0,
        'status' => 'draft',
        'created_by' => $worker->id,
    ]);
    $sessionBefore = $session->fresh()->getAttributes();
    $canonicalBefore = $canonicalEntry->fresh()->getAttributes();
    $otherBefore = $otherEntry->fresh()->getAttributes();
    $timesheetBefore = $timesheet->fresh()->getAttributes();

    $this->actingAs($manager)
        ->post(route('attendance.sessions.end', $session), [
            'reason' => 'Contradictory link convergence regression.',
        ])
        ->assertSessionHasErrors(['end_session']);

    expect($session->fresh()->getAttributes())->toBe($sessionBefore)
        ->and($canonicalEntry->fresh()->getAttributes())->toBe($canonicalBefore)
        ->and($otherEntry->fresh()->getAttributes())->toBe($otherBefore)
        ->and($timesheet->fresh()->getAttributes())->toBe($timesheetBefore)
        ->and(AuditLog::query()->where('action', 'attendance.session.adminEnded')->exists())->toBeFalse();
});

test('the domain command reasserts Site scope when invoked without the controller', function (): void {
    $localSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $manager = attendanceBoundaryUser($localSite, ['timesheets.manageAny']);
    $worker = attendanceBoundaryUser($foreignSite, []);
    $session = attendanceBoundarySession($worker, $foreignSite);

    expect(fn () => app(AttendanceService::class)->adminEndSession(
        $manager,
        $session,
        'Attempted direct domain close.',
    ))->toThrow(NotFoundHttpException::class);

    expect($session->refresh()->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and($session->closed_by)->toBeNull()
        ->and($session->timesheet()->exists())->toBeFalse();
});

test('end and correction commands reject whitespace-only reasons without mutation', function (): void {
    $site = Site::factory()->create();
    $manager = attendanceBoundaryUser($site, ['timesheets.manageAny']);
    $endSession = attendanceBoundarySession(attendanceBoundaryUser($site, []), $site);
    $correctionSession = attendanceBoundarySession(attendanceBoundaryUser($site, []), $site);

    $this->actingAs($manager)
        ->post(route('attendance.sessions.end', $endSession), ['reason' => " \t \n "])
        ->assertSessionHasErrors(['reason']);
    $this->actingAs($manager)
        ->post(route('attendance.sessions.correct', $correctionSession), [
            'clock_out_at' => now()->subMinute()->toIso8601String(),
            'break_minutes' => 0,
            'reason' => " \r\n\t ",
        ])
        ->assertSessionHasErrors(['reason']);

    $endSession->refresh();
    $correctionSession->refresh();
    expect($endSession->status)->toBe('open')
        ->and($endSession->clock_out_at)->toBeNull()
        ->and($correctionSession->status)->toBe('open')
        ->and($correctionSession->clock_out_at)->toBeNull()
        ->and(AuditLog::query()
            ->whereIn('action', ['attendance.session.adminEnded', 'attendance.session.corrected'])
            ->exists())->toBeFalse();

    expect(fn () => app(AttendanceService::class)->adminEndSession($manager, $endSession, '   '))
        ->toThrow(ValidationException::class);
});

test('global Site scope broadens a payroll managers action without replacing it', function (): void {
    $localSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $worker = attendanceBoundaryUser($foreignSite, []);
    $session = attendanceBoundarySession($worker, $foreignSite);
    $scopeOnly = attendanceBoundaryUser($localSite, ['reports.viewAny']);
    $globalManager = attendanceBoundaryUser($localSite, ['timesheets.manageAny', 'timesheets.viewAny', 'reports.viewAny']);

    $this->actingAs($scopeOnly)
        ->post(route('attendance.sessions.end', $session), ['reason' => 'No action capability.'])
        ->assertForbidden();
    expect($session->refresh()->status)->toBe('open');

    try {
        app(AttendanceService::class)->adminEndSession(
            $scopeOnly,
            $session,
            'Direct command without the action capability.',
        );
        $this->fail('Expected direct command denial.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    expect($session->refresh()->status)->toBe('open');

    $globalPage = $this->actingAs($globalManager)->get('/attendance');
    $globalPage->assertOk();
    expect(collect($globalPage->viewData('page')['props']['onClockNow'])->pluck('id'))
        ->toContain($session->id);

    $this->actingAs($globalManager)
        ->post(route('attendance.sessions.end', $session), ['reason' => 'Confirmed missed clock-out.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($globalManager)
        ->post(route('attendance.sessions.end', $session), ['reason' => 'Duplicate close attempt.'])
        ->assertRedirect()
        ->assertSessionHas('info');

    expect($session->refresh()->status)->toBe('closed')
        ->and($session->clock_out_at)->not->toBeNull()
        ->and($session->closed_by)->toBe($globalManager->id)
        ->and($session->timesheet()->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('action', 'attendance.session.adminEnded')
            ->where('auditable_id', $session->id)
            ->count())->toBe(1);
});

test('global Site scope broadens a payroll managers correction action', function (): void {
    $localSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $worker = attendanceBoundaryUser($foreignSite, []);
    $manager = attendanceBoundaryUser($localSite, ['timesheets.manageAny', 'reports.viewAny']);
    $session = attendanceBoundarySession($worker, $foreignSite);
    $entry = attendanceBoundaryEntry($session);
    $clockOutAt = now()->subMinute();

    $this->actingAs($manager)
        ->post(route('attendance.sessions.correct', $session), [
            'clock_out_at' => $clockOutAt->toIso8601String(),
            'break_minutes' => 0,
            'reason' => 'Confirmed foreign Site clock-out under explicit global scope.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($session->refresh()->status)->toBe('closed')
        ->and($session->clock_out_at?->timestamp)->toBe($clockOutAt->timestamp)
        ->and($session->closed_by)->toBe($manager->id)
        ->and($entry->refresh()->clock_out?->timestamp)->toBe($clockOutAt->timestamp)
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('action', 'attendance.session.corrected')
            ->where('auditable_id', $session->id)
            ->where('user_id', $manager->id)
            ->exists())->toBeTrue();
});

test('a downstream failure rolls the entire authorised end-session command back', function (): void {
    $site = Site::factory()->create();
    $manager = attendanceBoundaryUser($site, ['timesheets.manageAny']);
    $worker = attendanceBoundaryUser($site, []);
    $session = attendanceBoundarySession($worker, $site, true, false);
    $session->shift->update([
        'status' => 'in_progress',
        'actual_starts_at' => $session->clock_in_at,
        'started_by' => $worker->id,
    ]);

    $this->mock(DraftTimesheetService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('fromAttendanceSession')
            ->once()
            ->andThrow(new RuntimeException('Simulated draft-timesheet failure.'));
    });

    try {
        $this->withoutExceptionHandling()
            ->actingAs($manager)
            ->post(route('attendance.sessions.end', $session), [
                'reason' => 'Rollback proof.',
            ]);
        $this->fail('The simulated downstream failure was not raised.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated draft-timesheet failure.');
    }

    expect($session->refresh()->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and($session->closed_by)->toBeNull()
        ->and($session->site_id)->toBeNull()
        ->and($session->shift->refresh()->status)->toBe('in_progress')
        ->and($session->shift->actual_ends_at)->toBeNull()
        ->and($session->shift->completed_by)->toBeNull()
        ->and($session->timesheet()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'attendance.session.adminEnded')->exists())->toBeFalse();
});
