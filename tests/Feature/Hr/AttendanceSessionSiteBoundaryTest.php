<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
): HrAttendanceSession
{
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
