<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

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
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $serviceContext = ServiceContext::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $staff->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now(config('app.worker_timezone', 'Pacific/Auckland'))->subMonth()->toDateString(),
        'end_date' => null,
    ]);
    $shift = Shift::query()->create([
        'site_id' => $site->id,
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

function attendanceHandoverWorkerAtSite(Site $site): User
{
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $supportRole = Role::query()->where('name', 'support_worker')->firstOrFail();
    $worker->roles()->syncWithoutDetaching([$supportRole->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now(config('app.worker_timezone', 'Pacific/Auckland'))->subMonth()->toDateString(),
        'end_date' => null,
    ]);

    return $worker;
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

test('inertia clock out blocker response redirects with blocker flash instead of raw json', function () {
    $session = openShiftSessionFor($this->staff);

    $response = $this->actingAs($this->staff)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ])
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
        ]);

    $response
        ->assertRedirect()
        ->assertSessionHasErrors(['clock_out'])
        ->assertSessionHas('clock_out_blockers');

    expect($session->fresh()->status)->toBe('open');
});

test('hr clock out surfaces catch domain blockers instead of raising a server error', function (string $endpoint) {
    $session = openShiftSessionFor($this->staff);
    $entry = HrTimeEntry::query()->create([
        'user_id' => $this->staff->id,
        'shift_id' => $session->shift_id,
        'attendance_session_id' => $session->id,
        'site_id' => $session->shift->site_id,
        'client_id' => $session->shift->client_id,
        'entry_date' => $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString(),
        'clock_in' => $session->clock_in_at,
        'entry_type' => 'clock',
        'status' => 'active',
        'source_type' => 'attendance',
        'source_id' => $session->id,
        'created_by' => $this->staff->id,
    ]);
    $permission = Permission::query()->where('key', 'timesheets.viewAny')->firstOrFail();
    $this->staff->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);

    $this->actingAs($this->staff)
        ->post($endpoint, ['break_minutes' => 0])
        ->assertRedirect()
        ->assertSessionHas('error')
        ->assertSessionHas('clock_out_blockers');

    expect($session->fresh()->status)->toBe('open')
        ->and($entry->fresh()->clock_out)->toBeNull();
})->with([
    '/hr/time/clock-out',
    '/hr/my/time/clock-out',
]);

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

test('forced clock out rolls back all writes when its strict audit write fails', function () {
    $session = openShiftSessionFor($this->staff);
    AuditLog::creating(function (AuditLog $log): void {
        if ($log->action === 'attendance.clockOut.forced') {
            throw new RuntimeException('Injected forced-clock-out audit failure.');
        }
    });
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
            'force' => true,
            'override_reason' => 'This forced close must roll back',
        ]))
        ->toThrow(RuntimeException::class, 'Injected forced-clock-out audit failure.');

    $session->refresh();
    $shift = $session->shift()->firstOrFail();
    expect($session->status)->toBe('open')
        ->and($session->clock_out_at)->toBeNull()
        ->and($shift->status)->toBe('in_progress')
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse();
});

test('clock out rejects a legacy missing projection that overlaps payable time before ancillary writes', function () {
    $session = openShiftSessionFor($this->staff);
    $shift = $session->shift()->firstOrFail();
    $task = $shift->tasks()->firstOrFail();
    HrTimeEntry::query()->create([
        'user_id' => $this->staff->id,
        'site_id' => $shift->site_id,
        'client_id' => $shift->client_id,
        'entry_date' => now()->toDateString(),
        'clock_in' => now()->subMinutes(30),
        'clock_out' => now()->addMinutes(30),
        'break_minutes' => 0,
        'total_hours' => 1,
        'entry_type' => 'manual',
        'status' => 'active',
        'source_type' => 'manual',
        'created_by' => $this->staff->id,
    ]);
    $sessionBefore = $session->fresh()->getRawOriginal();
    $shiftBefore = $shift->fresh()->getRawOriginal();
    $taskBefore = $task->fresh()->getRawOriginal();

    expect(fn () => app(AttendanceService::class)->clockOut($this->staff, $session, [
        'clock_out_at' => now(),
        'break_minutes' => 0,
        'force' => true,
        'override_reason' => 'Regression preflight',
        'task_updates' => [[
            'id' => $task->id,
            'is_completed' => true,
        ]],
    ]))->toThrow(LogicException::class, 'overlapping time entry');

    expect($session->fresh()->getRawOriginal())->toBe($sessionBefore)
        ->and($shift->fresh()->getRawOriginal())->toBe($shiftBefore)
        ->and($task->fresh()->getRawOriginal())->toBe($taskBefore)
        ->and(Timesheet::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse()
        ->and(HrTimeEntry::query()->where('attendance_session_id', $session->id)->exists())->toBeFalse()
        ->and(ShiftHandover::query()->where('outgoing_shift_id', $shift->id)->exists())->toBeFalse();
});

test('frontline forced clock out cannot bypass clinical blockers', function () {
    $session = openShiftSessionFor($this->staff);

    ClientIncident::factory()->create([
        'client_id' => $session->shift->client_id,
        'shift_id' => $session->shift_id,
        'reported_by' => $this->staff->id,
        'status' => 'draft',
        'submitted_at' => null,
    ]);

    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
            'force' => true,
            'override_reason' => 'I need to leave and will finish the incident later.',
        ])
        ->assertSessionHasErrors(['clock_out']);

    expect($session->fresh()->status)->toBe('open');
});

test('manager capability can force clock out through clinical blockers with a reason', function () {
    $manager = User::factory()->create([
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'shifts.manageAny'],
        ['description' => 'shifts.manageAny'],
    );
    $manager->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $session = openShiftSessionFor($manager);

    ClientIncident::factory()->create([
        'client_id' => $session->shift->client_id,
        'shift_id' => $session->shift_id,
        'reported_by' => $manager->id,
        'status' => 'draft',
        'submitted_at' => null,
    ]);

    $this->actingAs($manager)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 0,
            'force' => true,
            'override_reason' => 'Manager approved clinical blocker override.',
        ])
        ->assertSessionHas('success');

    expect($session->fresh()->status)->toBe('closed');
});

test('attendance handover remains a draft when no incoming shift was reviewed', function () {
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

    $handover = ShiftHandover::query()
        ->where('outgoing_shift_id', $session->shift_id)
        ->sole();

    expect($handover->status)->toBe(ShiftHandoverService::STATUS_DRAFT)
        ->and($handover->incoming_shift_id)->toBeNull()
        ->and($handover->incoming_staff_id)->toBeNull();
});

test('attendance handover conceals foreign shift ids before validating handover details', function () {
    $ownSession = openShiftSessionFor($this->staff);
    $foreignStaff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $supportRole = Role::query()->where('name', 'support_worker')->firstOrFail();
    $foreignStaff->roles()->syncWithoutDetaching([$supportRole->id]);
    $foreignSession = openShiftSessionFor($foreignStaff);

    $this->actingAs($this->staff)
        ->post('/attendance/handover', [
            'shift_id' => $foreignSession->shift_id,
        ])
        ->assertNotFound();

    $this->actingAs($this->staff)
        ->post('/attendance/handover', [
            'shift_id' => $ownSession->shift_id,
        ])
        ->assertSessionHasErrors(['meds_completed', 'follow_up_needed']);
});

test('unexpected attendance handover save failures bubble instead of becoming raw validation text', function () {
    $session = openShiftSessionFor($this->staff);
    $service = Mockery::mock(app(ShiftHandoverService::class))->makePartial();
    $service->shouldReceive('save')
        ->once()
        ->andThrow(new RuntimeException('Injected audit or database failure.'));
    $this->app->instance(ShiftHandoverService::class, $service);
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->staff)
        ->post('/attendance/handover', [
            'shift_id' => $session->shift_id,
            'meds_completed' => true,
            'shift_rating' => 'calm',
            'handover_notes' => 'This failure must escape the controller.',
            'follow_up_needed' => false,
        ]))
        ->toThrow(RuntimeException::class, 'Injected audit or database failure.');
});

test('handover submit omits medication due evidence without both exact controlled capabilities', function () {
    $session = openShiftSessionFor($this->staff);
    $recordPermission = Permission::query()
        ->where('key', 'medications.controlled.record')
        ->firstOrFail();

    $this->staff->permissionOverrides()->syncWithoutDetaching([
        $recordPermission->id => ['allowed' => false],
    ]);

    expect($this->staff->canDo('medications.controlled.view'))->toBeTrue()
        ->and($this->staff->canDo('medications.controlled.record'))->toBeFalse();

    $this->actingAs($this->staff)
        ->post('/attendance/handover', [
            'shift_id' => $session->shift_id,
            'meds_completed' => false,
            'shift_rating' => 'mixed',
            'handover_notes' => '',
            'follow_up_needed' => true,
        ])
        ->assertSessionHas('success')
        ->assertSessionDoesntHaveErrors('handover');

    $handover = ShiftHandover::query()
        ->where('outgoing_shift_id', $session->shift_id)
        ->first();

    expect($handover)->not->toBeNull()
        ->and($handover?->status)->toBe(ShiftHandoverService::STATUS_DRAFT)
        ->and($handover?->medications_due)->toBeNull()
        ->and($handover?->handover_notes)
        ->toBe('Medications were not fully completed — please review on arrival.')
        ->and($handover?->follow_up_items)->toHaveCount(1)
        ->and($handover?->follow_up_items[0]['label'] ?? null)
        ->toBe('Follow-up flagged by outgoing worker');
});

test('handover submit includes medication due evidence with both exact controlled capabilities', function () {
    $session = openShiftSessionFor($this->staff);

    expect($this->staff->canDo('medications.controlled.view'))->toBeTrue()
        ->and($this->staff->canDo('medications.controlled.record'))->toBeTrue();

    $this->actingAs($this->staff)
        ->post('/attendance/handover', [
            'shift_id' => $session->shift_id,
            'meds_completed' => false,
            'shift_rating' => 'challenging',
            'handover_notes' => 'Medication follow-up is documented for the next worker.',
            'follow_up_needed' => false,
        ])
        ->assertSessionHas('success')
        ->assertSessionDoesntHaveErrors('handover');

    $handover = ShiftHandover::query()
        ->where('outgoing_shift_id', $session->shift_id)
        ->first();

    expect($handover)->not->toBeNull()
        ->and($handover?->status)->toBe(ShiftHandoverService::STATUS_DRAFT)
        ->and($handover?->handover_notes)
        ->toBe('Medication follow-up is documented for the next worker.')
        ->and($handover?->follow_up_items)->toBeNull()
        ->and($handover?->medications_due)->toHaveCount(1)
        ->and($handover?->medications_due[0]['label'] ?? null)
        ->toBe(ShiftHandoverService::OUTSTANDING_MEDICATION_DUE_LABEL)
        ->and($handover?->medications_due[0]['severity'] ?? null)->toBe('high');
});

test('attendance acknowledgement conceals a handover not assigned to the actor', function () {
    $session = openShiftSessionFor($this->staff);
    $incomingStaff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $supportRole = Role::query()->where('name', 'support_worker')->firstOrFail();
    $incomingStaff->roles()->syncWithoutDetaching([$supportRole->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $incomingStaff->id,
        'primary_site_id' => $session->shift->site_id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now(config('app.worker_timezone', 'Pacific/Auckland'))->subMonth()->toDateString(),
        'end_date' => null,
    ]);
    $incomingShift = Shift::query()->create([
        'site_id' => $session->shift->site_id,
        'client_id' => $session->shift->client_id,
        'service_context_id' => $session->shift->service_context_id,
        'user_id' => $incomingStaff->id,
        'starts_at' => $session->shift->ends_at,
        'ends_at' => $session->shift->ends_at->copy()->addHours(8),
        'status' => 'scheduled',
        'created_by' => $this->staff->id,
    ]);
    $handover = ShiftHandover::factory()->create([
        'outgoing_shift_id' => $session->shift_id,
        'incoming_shift_id' => $incomingShift->id,
        'client_id' => $session->shift->client_id,
        'outgoing_staff_id' => $this->staff->id,
        'incoming_staff_id' => $incomingStaff->id,
        'status' => ShiftHandoverService::STATUS_SUBMITTED,
        'submitted_at' => now(),
        'submitted_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)
        ->patch("/attendance/handover/{$handover->id}/acknowledge")
        ->assertNotFound();
});

test('attendance acknowledgement follows the current incoming Shift assignee and retains submit-time provenance', function () {
    $session = openShiftSessionFor($this->staff);
    $site = $session->shift->site;
    $formerIncomingStaff = attendanceHandoverWorkerAtSite($site);
    $currentIncomingStaff = attendanceHandoverWorkerAtSite($site);
    $incomingShift = Shift::query()->create([
        'site_id' => $site->id,
        'client_id' => $session->shift->client_id,
        'service_context_id' => $session->shift->service_context_id,
        'user_id' => $currentIncomingStaff->id,
        'starts_at' => $session->shift->ends_at,
        'ends_at' => $session->shift->ends_at->copy()->addHours(8),
        'status' => 'scheduled',
        'created_by' => $this->staff->id,
    ]);
    $handover = ShiftHandover::factory()->create([
        'outgoing_shift_id' => $session->shift_id,
        'incoming_shift_id' => $incomingShift->id,
        'client_id' => $session->shift->client_id,
        'outgoing_staff_id' => $this->staff->id,
        'incoming_staff_id' => $formerIncomingStaff->id,
        'status' => ShiftHandoverService::STATUS_SUBMITTED,
        'submitted_at' => now(),
        'submitted_by' => $this->staff->id,
    ]);

    $this->actingAs($formerIncomingStaff)
        ->patch("/attendance/handover/{$handover->id}/acknowledge")
        ->assertNotFound();

    $this->actingAs($currentIncomingStaff)
        ->patch('/attendance/handover/999999999/acknowledge')
        ->assertNotFound();

    $this->actingAs($currentIncomingStaff)
        ->patch("/attendance/handover/{$handover->id}/acknowledge")
        ->assertSessionHas('success', 'Handover marked as read.');

    $handover->refresh();
    expect($handover->status)->toBe(ShiftHandoverService::STATUS_ACKNOWLEDGED)
        ->and($handover->incoming_staff_id)->toBe($formerIncomingStaff->id)
        ->and($handover->acknowledged_by)->toBe($currentIncomingStaff->id);

    $rebound = AuditLog::query()
        ->where('action', 'shift.handover.incomingAssignment.rebound')
        ->where('auditable_id', $handover->id)
        ->sole();
    expect($rebound->meta['submitted_incoming_staff_id'] ?? null)->toBe($formerIncomingStaff->id)
        ->and($rebound->meta['current_incoming_staff_id'] ?? null)->toBe($currentIncomingStaff->id);
});

test('attendance acknowledgement conceals foreign-Site and wrong-context handovers', function () {
    $session = openShiftSessionFor($this->staff);
    $currentIncomingStaff = attendanceHandoverWorkerAtSite($session->shift->site);
    $wrongContext = ServiceContext::factory()->create();
    $wrongContextShift = Shift::query()->create([
        'site_id' => $session->shift->site_id,
        'client_id' => $session->shift->client_id,
        'service_context_id' => $wrongContext->id,
        'user_id' => $currentIncomingStaff->id,
        'starts_at' => $session->shift->ends_at,
        'ends_at' => $session->shift->ends_at->copy()->addHours(8),
        'status' => 'scheduled',
        'created_by' => $this->staff->id,
    ]);
    $wrongContextHandover = ShiftHandover::factory()->create([
        'outgoing_shift_id' => $session->shift_id,
        'incoming_shift_id' => $wrongContextShift->id,
        'client_id' => $session->shift->client_id,
        'outgoing_staff_id' => $this->staff->id,
        'incoming_staff_id' => $currentIncomingStaff->id,
        'status' => ShiftHandoverService::STATUS_SUBMITTED,
        'submitted_at' => now(),
        'submitted_by' => $this->staff->id,
    ]);

    $this->actingAs($currentIncomingStaff)
        ->patch("/attendance/handover/{$wrongContextHandover->id}/acknowledge")
        ->assertNotFound();

    $foreignOutgoing = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $supportRole = Role::query()->where('name', 'support_worker')->firstOrFail();
    $foreignOutgoing->roles()->syncWithoutDetaching([$supportRole->id]);
    $foreignSession = openShiftSessionFor($foreignOutgoing);
    $foreignIncomingShift = Shift::query()->create([
        'site_id' => $foreignSession->shift->site_id,
        'client_id' => $foreignSession->shift->client_id,
        'service_context_id' => $foreignSession->shift->service_context_id,
        'user_id' => $currentIncomingStaff->id,
        'starts_at' => $foreignSession->shift->ends_at,
        'ends_at' => $foreignSession->shift->ends_at->copy()->addHours(8),
        'status' => 'scheduled',
        'created_by' => $foreignOutgoing->id,
    ]);
    $foreignHandover = ShiftHandover::factory()->create([
        'outgoing_shift_id' => $foreignSession->shift_id,
        'incoming_shift_id' => $foreignIncomingShift->id,
        'client_id' => $foreignSession->shift->client_id,
        'outgoing_staff_id' => $foreignOutgoing->id,
        'incoming_staff_id' => $currentIncomingStaff->id,
        'status' => ShiftHandoverService::STATUS_SUBMITTED,
        'submitted_at' => now(),
        'submitted_by' => $foreignOutgoing->id,
    ]);

    $this->actingAs($currentIncomingStaff)
        ->patch("/attendance/handover/{$foreignHandover->id}/acknowledge")
        ->assertNotFound();

    expect($wrongContextHandover->fresh()->status)->toBe(ShiftHandoverService::STATUS_SUBMITTED)
        ->and($foreignHandover->fresh()->status)->toBe(ShiftHandoverService::STATUS_SUBMITTED);
});

test('unexpected attendance acknowledgement failures bubble instead of exposing raw messages', function () {
    $session = openShiftSessionFor($this->staff);
    $incomingShift = Shift::query()->create([
        'site_id' => $session->shift->site_id,
        'client_id' => $session->shift->client_id,
        'service_context_id' => $session->shift->service_context_id,
        'user_id' => $this->staff->id,
        'starts_at' => $session->shift->ends_at,
        'ends_at' => $session->shift->ends_at->copy()->addHours(8),
        'status' => 'scheduled',
        'created_by' => $this->staff->id,
    ]);
    $handover = ShiftHandover::factory()->create([
        'outgoing_shift_id' => $session->shift_id,
        'incoming_shift_id' => $incomingShift->id,
        'client_id' => $session->shift->client_id,
        'outgoing_staff_id' => $this->staff->id,
        'incoming_staff_id' => $this->staff->id,
        'status' => ShiftHandoverService::STATUS_SUBMITTED,
        'submitted_at' => now(),
        'submitted_by' => $this->staff->id,
    ]);
    $service = Mockery::mock(app(ShiftHandoverService::class))->makePartial();
    $service->shouldReceive('acknowledge')
        ->once()
        ->andThrow(new RuntimeException('Injected acknowledgement audit failure.'));
    $this->app->instance(ShiftHandoverService::class, $service);
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->staff)
        ->patch("/attendance/handover/{$handover->id}/acknowledge"))
        ->toThrow(RuntimeException::class, 'Injected acknowledgement audit failure.');
});

test('clock out persists the same canonical medication-due marker as a draft', function () {
    $session = openShiftSessionFor($this->staff);
    ShiftTask::query()
        ->where('shift_id', $session->shift_id)
        ->update([
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $this->staff->id,
        ]);

    $this->travel(2)->hours();

    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 15,
            'handover' => [
                'meds_completed' => false,
                'shift_rating' => 'calm',
                'handover_notes' => 'Quiet shift. Dinner prepared for the next worker.',
                'follow_up_needed' => false,
            ],
        ])
        ->assertSessionHas('success');

    expect($session->fresh()->status)->toBe('closed');

    $handover = ShiftHandover::query()
        ->where('outgoing_shift_id', $session->shift_id)
        ->first();

    expect($handover)->not->toBeNull()
        ->and($handover?->status)->toBe(ShiftHandoverService::STATUS_DRAFT)
        ->and($handover?->handover_notes)->toBe('Quiet shift. Dinner prepared for the next worker.')
        ->and($handover?->medications_due[0]['label'] ?? null)
        ->toBe(ShiftHandoverService::OUTSTANDING_MEDICATION_DUE_LABEL);
});

test('clock out refreshes the current workers existing draft instead of blocking the session close', function () {
    $session = openShiftSessionFor($this->staff);
    ShiftTask::query()
        ->where('shift_id', $session->shift_id)
        ->update([
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $this->staff->id,
        ]);
    $draft = ShiftHandover::factory()->create([
        'outgoing_shift_id' => $session->shift_id,
        'incoming_shift_id' => null,
        'client_id' => $session->shift->client_id,
        'outgoing_staff_id' => $this->staff->id,
        'incoming_staff_id' => null,
        'handover_notes' => 'Earlier draft before the clock-out review.',
        'status' => ShiftHandoverService::STATUS_DRAFT,
        'version' => 3,
    ]);
    $this->travel(2)->hours();

    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 10,
            'handover' => [
                'meds_completed' => true,
                'shift_rating' => 'calm',
                'handover_notes' => 'Final clock-out review preserved in the owned draft.',
                'follow_up_needed' => false,
            ],
        ])
        ->assertSessionHas('success')
        ->assertSessionDoesntHaveErrors('clock_out');

    $draft = $draft->fresh();
    expect($session->fresh()->status)->toBe('closed')
        ->and($draft->status)->toBe(ShiftHandoverService::STATUS_DRAFT)
        ->and($draft->handover_notes)->toBe('Final clock-out review preserved in the owned draft.')
        ->and($draft->version)->toBe(4)
        ->and(ShiftHandover::query()->where('outgoing_shift_id', $session->shift_id)->count())->toBe(1);
});

test('clock out handover omits medication due evidence without both exact capabilities', function () {
    $session = openShiftSessionFor($this->staff);
    ShiftTask::query()
        ->where('shift_id', $session->shift_id)
        ->update([
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $this->staff->id,
        ]);
    $recordPermission = Permission::query()
        ->where('key', 'medications.controlled.record')
        ->firstOrFail();
    $this->staff->permissionOverrides()->syncWithoutDetaching([
        $recordPermission->id => ['allowed' => false],
    ]);
    $this->staff->unsetRelation('permissionOverrides')->unsetRelation('roles');
    $this->travel(2)->hours();

    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'break_minutes' => 15,
            'handover' => [
                'meds_completed' => false,
                'shift_rating' => 'mixed',
                'handover_notes' => 'Medication follow-up remains in the ordinary narrative.',
                'follow_up_needed' => true,
            ],
        ])
        ->assertSessionHas('success');

    $handover = ShiftHandover::query()
        ->where('outgoing_shift_id', $session->shift_id)
        ->sole();

    expect($session->fresh()->status)->toBe('closed')
        ->and($handover->status)->toBe(ShiftHandoverService::STATUS_DRAFT)
        ->and($handover->medications_due)->toBeNull()
        ->and($handover->handover_notes)->toBe('Medication follow-up remains in the ordinary narrative.')
        ->and($handover->follow_up_items[0]['label'] ?? null)
        ->toBe('Follow-up flagged by outgoing worker');
});

test('routine clock out persists its draft but defers Shift completion for an exact incoming shift', function () {
    $session = openShiftSessionFor($this->staff);
    ShiftTask::query()
        ->where('shift_id', $session->shift_id)
        ->update([
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $this->staff->id,
        ]);

    $incomingStaff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $supportRole = Role::query()->where('name', 'support_worker')->firstOrFail();
    $incomingStaff->roles()->syncWithoutDetaching([$supportRole->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $incomingStaff->id,
        'primary_site_id' => $session->shift->site_id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now(config('app.worker_timezone', 'Pacific/Auckland'))->subMonth()->toDateString(),
        'end_date' => null,
    ]);
    Shift::query()->create([
        'site_id' => $session->shift->site_id,
        'client_id' => $session->shift->client_id,
        'service_context_id' => $session->shift->service_context_id,
        'user_id' => $incomingStaff->id,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(9),
        'status' => 'scheduled',
        'created_by' => $this->staff->id,
    ]);

    $this->actingAs($this->staff)
        ->post('/attendance/clock-out', [
            'session_id' => $session->id,
            'clock_out_at' => now()->toIso8601String(),
            'break_minutes' => 0,
            'handover' => [
                'meds_completed' => true,
                'shift_rating' => 'calm',
                'handover_notes' => 'Quiet shift. This draft still needs exact incoming assignment.',
                'follow_up_needed' => false,
            ],
        ])
        ->assertSessionHas('success');

    $handover = ShiftHandover::query()
        ->where('outgoing_shift_id', $session->shift_id)
        ->sole();
    $outgoingShift = $session->shift->fresh();

    expect($session->fresh()->status)->toBe('closed')
        ->and($handover->status)->toBe(ShiftHandoverService::STATUS_DRAFT)
        ->and($handover->incoming_shift_id)->toBeNull()
        ->and($outgoingShift->status)->toBe('in_progress')
        ->and($outgoingShift->handover_waiver_reason)->toBeNull()
        ->and($outgoingShift->handover_waived_at)->toBeNull()
        ->and($outgoingShift->handover_waived_by)->toBeNull()
        ->and(AuditLog::query()
            ->where('action', 'shift.handover.waived')
            ->where('auditable_id', $outgoingShift->id)
            ->exists())->toBeFalse();
});

test('handover is rolled back when a later clock out write fails', function () {
    $session = openShiftSessionFor($this->staff);
    ShiftTask::query()
        ->where('shift_id', $session->shift_id)
        ->update([
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $this->staff->id,
        ]);

    $this->travel(2)->hours();

    $draftTimesheets = Mockery::mock(DraftTimesheetService::class);
    $draftTimesheets
        ->shouldReceive('fromAttendanceSession')
        ->once()
        ->andThrow(new RuntimeException('Draft sync failed after handover save.'));

    $this->app->instance(DraftTimesheetService::class, $draftTimesheets);

    expect(fn () => app(AttendanceService::class)->clockOut($this->staff, $session, [
        'break_minutes' => 15,
        'handover' => [
            'meds_completed' => true,
            'shift_rating' => 'mixed',
            'handover_notes' => 'This should roll back.',
            'follow_up_needed' => true,
        ],
    ]))->toThrow(RuntimeException::class);

    expect($session->fresh()->status)->toBe('open');
    expect(ShiftHandover::query()->where('outgoing_shift_id', $session->shift_id)->exists())->toBeFalse();
});
