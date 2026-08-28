<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AttendanceService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Database\Seeders\RbacSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->otherWorker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->worker->roles()->syncWithoutDetaching([$supportRole->id]);
        $this->otherWorker->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    $this->site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    foreach ([$this->worker, $this->otherWorker] as $worker) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
});

test('cross-user attendance commands conceal foreign objects without audit side effects', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $serviceContext = ServiceContext::factory()->create(['site_id' => $this->site->id]);
    $shift = Shift::query()->create([
        'client_id' => $client->id,
        'service_context_id' => $serviceContext->id,
        'site_id' => $this->site->id,
        'user_id' => $this->worker->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(7),
        'status' => 'in_progress',
        'actual_starts_at' => now()->subHour(),
        'started_by' => $this->worker->id,
        'created_by' => $this->worker->id,
    ]);

    $session = HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $this->worker->id,
        'shift_id' => $shift->id,
        'site_id' => $this->site->id,
        'clock_in_at' => now()->subHour(),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $this->worker->id,
    ]);
    $auditCount = AuditLog::query()->count();
    $missingSessionId = $session->id + 999999;

    foreach ([$session->id, $missingSessionId] as $concealedSessionId) {
        $this->actingAs($this->otherWorker)
            ->post('/attendance/clock-out', [
                'session_id' => $concealedSessionId,
                'break_minutes' => 0,
            ])
            ->assertNotFound();
    }

    $service = app(AttendanceService::class);

    $handoverService = Mockery::mock(ShiftHandoverService::class);
    $handoverService->shouldNotReceive('save');
    app()->instance(ShiftHandoverService::class, $handoverService);

    $craftedSession = $session->replicate();
    $craftedSession->forceFill([
        'id' => $session->id,
        'user_id' => $this->otherWorker->id,
    ]);
    $craftedSession->exists = true;
    try {
        $service->clockOut($this->otherWorker, $craftedSession, [
            'handover' => ['handover_notes' => 'Must remain concealed.'],
        ]);
        $this->fail('Clock-out accepted a stale model with mismatched canonical ownership.');
    } catch (HttpException $exception) {
        $this->assertSame(404, $exception->getStatusCode());
    }

    foreach (['clockOut', 'startBreak', 'endBreak'] as $command) {
        try {
            $service->{$command}($this->otherWorker, $session, []);
            $this->fail("{$command} accepted another worker's attendance session.");
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    $session->refresh();
    expect($session->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and($session->break_started_at)->toBeNull()
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->count())->toBe($auditCount);

    $this->assertDatabaseMissing('audit_logs', [
        'action' => 'attendance.clockOut.unauthorized',
        'auditable_id' => $session->id,
        'user_id' => $this->otherWorker->id,
    ]);
});

test('workers who can clock can open their attendance session list', function () {
    $this->actingAs($this->worker)
        ->get('/attendance')
        ->assertOk();
});
