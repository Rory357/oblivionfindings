<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\AttendanceService;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\User;
use App\Services\Operations\TimesheetReconciliationService;

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
});

test('normal attendance clock out reconciles the draft timesheet exactly once', function () {
    $client = Client::factory()->create();
    $serviceContext = ServiceContext::factory()->create();
    $shift = Shift::query()->create([
        'client_id' => $client->id,
        'service_context_id' => $serviceContext->id,
        'user_id' => $this->worker->id,
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->addHours(6),
        'status' => 'in_progress',
        'actual_starts_at' => now()->subHours(2),
        'started_by' => $this->worker->id,
        'created_by' => $this->worker->id,
    ]);

    ShiftTask::query()->create([
        'shift_id' => $shift->id,
        'label' => 'Complete log',
        'is_completed' => true,
        'completed_at' => now()->subMinutes(10),
        'completed_by' => $this->worker->id,
        'sort_order' => 1,
    ]);

    $session = HrAttendanceSession::query()->create([
        'tenant_id' => null,
        'user_id' => $this->worker->id,
        'shift_id' => $shift->id,
        'clock_in_at' => now()->subHours(2),
        'status' => 'open',
        'source' => 'manual',
        'created_by' => $this->worker->id,
    ]);

    $reconciler = Mockery::mock(TimesheetReconciliationService::class);
    $reconciler
        ->shouldReceive('reconcile')
        ->once()
        ->andReturn([
            'status' => TimesheetReconciliationService::STATUS_CLEAR,
            'severity' => TimesheetReconciliationService::SEVERITY_NONE,
            'detected_at' => now(),
            'summary' => 'No reconciliation anomalies detected.',
            'findings' => [],
        ]);

    $this->app->instance(TimesheetReconciliationService::class, $reconciler);

    app(AttendanceService::class)->clockOut($this->worker, $session, [
        'break_minutes' => 0,
        'handover' => [
            'meds_completed' => true,
            'shift_rating' => 'calm',
            'handover_notes' => 'Ready for the next shift.',
            'follow_up_needed' => false,
        ],
    ]);
});
