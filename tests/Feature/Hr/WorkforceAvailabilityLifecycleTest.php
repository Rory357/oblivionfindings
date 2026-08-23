<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Services\LeaveService;
use App\Domain\Hr\Services\OnboardingService;
use App\Domain\Hr\Services\WorkforceAvailabilityCoverageService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkforceAvailabilityCoverageAction;
use App\Services\Eligibility\Rules\AvailabilityRule;
use App\Services\ShiftStaffEligibilityService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\Process;

function wfAvailabilityGrant(User $user, array $permissionKeys): void
{
    foreach ($permissionKeys as $permissionKey) {
        $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }
}

function wfAvailabilityProfile(User $user, Site $site, array $attributes = []): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'employee_number' => 'WF-AVAIL-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ], $attributes));
}

beforeEach(function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);
    $this->seed(RbacSeeder::class);

    $this->site = Site::factory()->create(['name' => 'Workforce availability Site']);
    $this->client = Client::factory()->create(['site_id' => $this->site->id]);
    $this->serviceContext = ServiceContext::factory()->create();

    $this->manager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    wfAvailabilityGrant($this->manager, ['shifts.manageAny']);
    wfAvailabilityProfile($this->manager, $this->site, [
        'employee_number' => 'WF-AVAIL-MANAGER-'.$this->manager->id,
        'position_title' => 'HR Manager',
        'position_role' => 'hr',
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->staff->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);
    $this->profile = wfAvailabilityProfile($this->staff, $this->site);

    HrOnboardingTemplate::query()->create([
        'role' => 'offboarding:support_worker',
        'site_type' => 'all',
        'tasks' => [[
            'category' => 'hr',
            'title' => 'Confirm departure hand-off',
            'description' => 'Confirm the offboarding hand-off.',
            'is_required' => true,
            'sign_off_required' => false,
            'assigned_to_user_id' => $this->manager->id,
        ]],
        'is_active' => true,
        'created_by' => $this->manager->id,
    ]);

    $this->makeShift = function (array $attributes = []): Shift {
        return Shift::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(4),
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
        ], $attributes));
    };
});

test('approved leave creates one owned native coverage action for draft published and in-progress shifts', function () {
    $draft = ($this->makeShift)([
        'status' => 'draft',
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addHours(4),
    ]);
    $published = ($this->makeShift)([
        'status' => 'scheduled',
        'published_at' => now(),
        'starts_at' => now()->addHours(5),
        'ends_at' => now()->addHours(7),
    ]);
    $inProgress = ($this->makeShift)([
        'status' => 'in_progress',
        'actual_starts_at' => now()->subHour(),
        'started_by' => $this->staff->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    $leave = app(LeaveService::class)->submitRequest($this->staff, [
        'leave_type' => 'annual',
        'starts_at' => today()->subDay()->toDateString(),
        'ends_at' => today()->addDay()->toDateString(),
        'hours_requested' => 24,
        'created_by' => $this->staff->id,
    ]);
    $approved = app(LeaveService::class)->approveRequest($leave, $this->manager);

    $actions = WorkforceAvailabilityCoverageAction::query()
        ->where('source_type', WorkforceAvailabilityCoverageAction::SOURCE_LEAVE)
        ->where('source_id', $approved->id)
        ->orderBy('shift_id')
        ->get();

    expect($actions)->toHaveCount(3)
        ->and($actions->pluck('shift_id')->all())->toEqualCanonicalizing([
            $draft->id,
            $published->id,
            $inProgress->id,
        ])
        ->and($actions->where('action_kind', WorkforceAvailabilityCoverageAction::KIND_HANDOVER)->pluck('shift_id')->all())
        ->toBe([$inProgress->id])
        ->and($actions->pluck('owner_user_id')->unique()->all())->toBe([$this->manager->id]);

    expect(ShiftReplacementRequest::query()->whereIn('shift_id', $actions->pluck('shift_id'))->count())->toBe(3)
        ->and(ShiftOpenPosition::query()->whereIn('shift_id', $actions->pluck('shift_id'))->count())->toBe(3);

    app(WorkforceAvailabilityCoverageService::class)->syncApprovedLeave(
        $approved->fresh(),
        $this->manager,
    );

    expect(WorkforceAvailabilityCoverageAction::query()
        ->where('source_type', WorkforceAvailabilityCoverageAction::SOURCE_LEAVE)
        ->where('source_id', $approved->id)
        ->count())->toBe(3)
        ->and(ShiftReplacementRequest::query()->whereIn('shift_id', $actions->pluck('shift_id'))->count())->toBe(3);

    $candidateIds = app(ShiftStaffEligibilityService::class)
        ->candidatesFor($published)
        ->pluck('id');
    expect($candidateIds)->not->toContain($this->staff->id);

    app(LeaveService::class)->cancelRequest($approved->fresh(), $this->manager);

    expect(WorkforceAvailabilityCoverageAction::query()
        ->where('source_type', WorkforceAvailabilityCoverageAction::SOURCE_LEAVE)
        ->where('source_id', $approved->id)
        ->where('status', WorkforceAvailabilityCoverageAction::STATUS_CANCELLED)
        ->count())->toBe(3)
        ->and(ShiftReplacementRequest::query()
            ->whereIn('shift_id', $actions->pluck('shift_id'))
            ->where('status', 'cancelled')
            ->count())->toBe(3)
        ->and($approved->fresh()->status)->toBe('cancelled')
        ->and($approved->fresh()->timeOff()->exists())->toBeFalse();
});

test('offboarding persists the effective end date and reconciles coverage when delayed cancelled and resumed', function () {
    $effectiveEndDate = today()->subDay()->toDateString();
    $draft = ($this->makeShift)(['status' => 'draft']);
    $published = ($this->makeShift)([
        'published_at' => now(),
        'starts_at' => now()->addHours(5),
        'ends_at' => now()->addHours(7),
    ]);
    $inProgress = ($this->makeShift)([
        'status' => 'in_progress',
        'actual_starts_at' => now()->subHour(),
        'started_by' => $this->staff->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);
    $afterDelayedEnd = ($this->makeShift)([
        'starts_at' => now()->addDays(15),
        'ends_at' => now()->addDays(15)->addHours(4),
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/offboarding', [
            'employee_profile_id' => $this->profile->id,
            'end_date' => $effectiveEndDate,
        ])
        ->assertSessionHas('success');

    $checklist = HrOffboardingChecklist::query()
        ->where('employee_profile_id', $this->profile->id)
        ->firstOrFail();

    expect($this->profile->fresh()->end_date?->toDateString())->toBe($effectiveEndDate)
        ->and($checklist->previous_employee_end_date)->toBeNull()
        ->and(WorkforceAvailabilityCoverageAction::query()
            ->where('source_type', WorkforceAvailabilityCoverageAction::SOURCE_OFFBOARDING)
            ->where('source_id', $checklist->id)
            ->where('status', WorkforceAvailabilityCoverageAction::STATUS_OPEN)
            ->count())->toBe(4);

    $employmentRule = collect(app(AvailabilityRule::class)->evaluateAll($published, $this->staff->fresh()))
        ->firstWhere('rule', 'availability_employment');
    expect($employmentRule)->toMatchArray([
        'passed' => false,
        'severity' => 'block',
        'overrideable' => false,
    ]);
    expect(app(ShiftStaffEligibilityService::class)->candidatesFor($published)->pluck('id'))
        ->not->toContain($this->staff->id);

    $delayedEndDate = today()->addDays(10)->toDateString();
    $this->actingAs($this->manager)
        ->post("/hr/offboarding/{$checklist->id}/status", [
            'status' => 'in_progress',
            'end_date' => $delayedEndDate,
        ])
        ->assertSessionHas('success');

    expect($this->profile->fresh()->end_date?->toDateString())->toBe($delayedEndDate)
        ->and($checklist->fresh()->due_date?->toDateString())->toBe($delayedEndDate)
        ->and(WorkforceAvailabilityCoverageAction::query()
            ->where('source_type', WorkforceAvailabilityCoverageAction::SOURCE_OFFBOARDING)
            ->where('source_id', $checklist->id)
            ->where('status', WorkforceAvailabilityCoverageAction::STATUS_CANCELLED)
            ->whereIn('shift_id', [$draft->id, $published->id, $inProgress->id])
            ->count())->toBe(3)
        ->and(WorkforceAvailabilityCoverageAction::query()
            ->where('source_type', WorkforceAvailabilityCoverageAction::SOURCE_OFFBOARDING)
            ->where('source_id', $checklist->id)
            ->where('shift_id', $afterDelayedEnd->id)
            ->where('status', WorkforceAvailabilityCoverageAction::STATUS_OPEN)
            ->exists())->toBeTrue();

    $this->actingAs($this->manager)
        ->post("/hr/offboarding/{$checklist->id}/status", ['status' => 'cancelled'])
        ->assertSessionHas('success');

    expect($this->profile->fresh()->end_date)->toBeNull()
        ->and(WorkforceAvailabilityCoverageAction::query()
            ->where('source_type', WorkforceAvailabilityCoverageAction::SOURCE_OFFBOARDING)
            ->where('source_id', $checklist->id)
            ->where('status', WorkforceAvailabilityCoverageAction::STATUS_OPEN)
            ->exists())->toBeFalse();

    $this->actingAs($this->manager)
        ->post("/hr/offboarding/{$checklist->id}/status", ['status' => 'in_progress'])
        ->assertSessionHas('success');

    expect($this->profile->fresh()->end_date?->toDateString())->toBe($delayedEndDate)
        ->and(WorkforceAvailabilityCoverageAction::query()
            ->where('source_type', WorkforceAvailabilityCoverageAction::SOURCE_OFFBOARDING)
            ->where('source_id', $checklist->id)
            ->where('status', WorkforceAvailabilityCoverageAction::STATUS_OPEN)
            ->pluck('shift_id'))
        ->toContain($afterDelayedEnd->id);
});

test('rehire cancels stale offboarding coverage and applies the new employment window', function () {
    $shift = ($this->makeShift)([
        'starts_at' => now()->addDays(8),
        'ends_at' => now()->addDays(8)->addHours(4),
        'published_at' => now(),
    ]);

    $checklist = app(OnboardingService::class)->generateOffboardingChecklist(
        $this->profile,
        $this->manager->id,
        ['end_date' => today()->addDays(2)->toDateString()],
    );
    $action = WorkforceAvailabilityCoverageAction::query()
        ->where('source_type', WorkforceAvailabilityCoverageAction::SOURCE_OFFBOARDING)
        ->where('source_id', $checklist->id)
        ->where('shift_id', $shift->id)
        ->firstOrFail();

    $checklist->update([
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    $this->profile->update(['is_active' => false]);
    $this->staff->forceFill(['approved_at' => null])->save();
    $newStart = today()->addDays(5)->toDateString();

    $this->actingAs($this->manager)
        ->post("/hr/people/{$this->profile->id}/rehire", [
            'start_date' => $newStart,
            'send_invite' => false,
            'start_onboarding' => false,
        ])
        ->assertSessionHas('success');

    expect($this->profile->fresh()->is_active)->toBeTrue()
        ->and($this->profile->fresh()->end_date)->toBeNull()
        ->and($this->profile->fresh()->start_date?->toDateString())->toBe($newStart)
        ->and($checklist->fresh()->status)->toBe('completed')
        ->and($action->fresh()->status)->toBe(WorkforceAvailabilityCoverageAction::STATUS_CANCELLED)
        ->and($action->replacementRequest?->fresh()->status)->toBe('cancelled');

    $employmentRule = collect(app(AvailabilityRule::class)->evaluateAll($shift, $this->staff->fresh()))
        ->firstWhere('rule', 'availability_employment');
    expect($employmentRule)->toMatchArray(['passed' => true]);
});

test('outer rollbacks leave leave and offboarding coverage and notifications unapplied', function () {
    ($this->makeShift)();
    $leave = app(LeaveService::class)->submitRequest($this->staff, [
        'leave_type' => 'annual',
        'starts_at' => today()->toDateString(),
        'ends_at' => today()->toDateString(),
        'hours_requested' => 8,
        'created_by' => $this->staff->id,
    ]);
    Notification::fake();

    try {
        DB::transaction(function () use ($leave): void {
            app(LeaveService::class)->approveRequest($leave, $this->manager);
            throw new RuntimeException('force outer rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('force outer rollback');
    }

    expect($leave->fresh()->status)->toBe('pending')
        ->and(WorkforceAvailabilityCoverageAction::query()->count())->toBe(0)
        ->and(ShiftReplacementRequest::query()->count())->toBe(0);
    $this->assertDatabaseMissing('staff_time_offs', ['hr_leave_request_id' => $leave->id]);

    try {
        DB::transaction(function (): void {
            app(OnboardingService::class)->generateOffboardingChecklist(
                $this->profile,
                $this->manager->id,
                ['end_date' => today()->addDay()->toDateString()],
            );
            throw new RuntimeException('force offboarding outer rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('force offboarding outer rollback');
    }

    expect($this->profile->fresh()->end_date)->toBeNull()
        ->and(HrOffboardingChecklist::query()->count())->toBe(0)
        ->and(WorkforceAvailabilityCoverageAction::query()->count())->toBe(0)
        ->and(ShiftReplacementRequest::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('foreign site offboarding is concealed and cannot mutate employment or coverage', function () {
    $scopedActor = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    wfAvailabilityProfile($scopedActor, $this->site, [
        'employee_number' => 'WF-AVAIL-SCOPED-'.$scopedActor->id,
    ]);
    wfAvailabilityGrant($scopedActor, [
        'hr.onboarding.view',
        'hr.onboarding.manage',
        'hr.employees.viewAny',
    ]);

    $foreignSite = Site::factory()->create(['name' => 'Foreign workforce Site']);
    $foreignStaff = User::factory()->create(['approved_at' => now()]);
    $foreignProfile = wfAvailabilityProfile($foreignStaff, $foreignSite, [
        'employee_number' => 'WF-AVAIL-FOREIGN-'.$foreignStaff->id,
    ]);

    $this->actingAs($scopedActor)
        ->post('/hr/offboarding', [
            'employee_profile_id' => $foreignProfile->id,
            'end_date' => today()->addWeek()->toDateString(),
        ])
        ->assertNotFound();

    expect($foreignProfile->fresh()->end_date)->toBeNull()
        ->and(HrOffboardingChecklist::query()
            ->where('employee_profile_id', $foreignProfile->id)
            ->exists())->toBeFalse()
        ->and(WorkforceAvailabilityCoverageAction::query()->count())->toBe(0);

    $foreignChecklist = HrOffboardingChecklist::query()->create([
        'employee_profile_id' => $foreignProfile->id,
        'template_key' => 'offboarding:support_worker',
        'status' => 'pending',
        'started_at' => now(),
        'due_date' => today()->addWeek()->toDateString(),
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($scopedActor)
        ->post("/hr/offboarding/{$foreignChecklist->id}/status", ['status' => 'cancelled'])
        ->assertNotFound();

    expect($foreignChecklist->fresh()->status)->toBe('pending')
        ->and($foreignProfile->fresh()->end_date)->toBeNull()
        ->and(WorkforceAvailabilityCoverageAction::query()->count())->toBe(0);
});

test('offboarding status needs action authority separately from Site visibility', function () {
    $checklist = app(OnboardingService::class)->generateOffboardingChecklist(
        $this->profile,
        $this->manager->id,
        ['end_date' => today()->addWeek()->toDateString()],
    );
    $viewOnly = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    wfAvailabilityProfile($viewOnly, $this->site, [
        'employee_number' => 'WF-AVAIL-VIEW-'.$viewOnly->id,
    ]);
    wfAvailabilityGrant($viewOnly, ['hr.onboarding.view']);

    $this->actingAs($viewOnly)
        ->post("/hr/offboarding/{$checklist->id}/status", ['status' => 'cancelled'])
        ->assertForbidden();

    expect($checklist->fresh()->status)->toBe('pending')
        ->and($this->profile->fresh()->end_date?->toDateString())->toBe($checklist->due_date?->toDateString());
});

test('completed offboarding cannot be cancelled or resumed into contradictory employment state', function () {
    $checklist = app(OnboardingService::class)->generateOffboardingChecklist(
        $this->profile,
        $this->manager->id,
        ['end_date' => today()->toDateString()],
    );
    $task = $checklist->tasks()->firstOrFail();

    $this->actingAs($this->manager)
        ->post("/hr/offboarding/tasks/{$task->id}/complete")
        ->assertSessionHas('success');

    expect($checklist->fresh()->status)->toBe('completed')
        ->and($this->profile->fresh()->is_active)->toBeFalse()
        ->and($this->profile->fresh()->end_date?->toDateString())->toBe($checklist->due_date?->toDateString());

    foreach (['cancelled', 'in_progress', 'archived'] as $status) {
        $this->actingAs($this->manager)
            ->post("/hr/offboarding/{$checklist->id}/status", ['status' => $status])
            ->assertSessionHasErrors('status');
    }

    expect($checklist->fresh()->status)->toBe('completed')
        ->and($this->profile->fresh()->is_active)->toBeFalse()
        ->and($this->profile->fresh()->end_date?->toDateString())->toBe($checklist->due_date?->toDateString());
});

test('availability activation and cancellation serialize on one shift across two MySQL workers', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    $shift = ($this->makeShift)();
    $leave = app(LeaveService::class)->submitRequest($this->staff, [
        'leave_type' => 'annual',
        'starts_at' => today()->subDay()->toDateString(),
        'ends_at' => today()->addDay()->toDateString(),
        'hours_requested' => 24,
        'created_by' => $this->staff->id,
    ]);
    $approved = app(LeaveService::class)->approveRequest($leave, $this->manager);
    $checklist = app(OnboardingService::class)->generateOffboardingChecklist(
        $this->profile,
        $this->manager->id,
        ['end_date' => today()->subDay()->toDateString()],
    );

    $coverage = app(WorkforceAvailabilityCoverageService::class);
    $coverage->cancelLeave($approved->fresh(), $this->manager);
    $coverage->cancelOffboarding($checklist->fresh(), $this->manager);

    $database = DB::connection()->getDatabaseName();
    $actions = [
        [
            'action' => 'sync_leave',
            'source_id' => $approved->id,
            'actor_id' => $this->manager->id,
        ],
        [
            'action' => 'sync_offboarding',
            'source_id' => $checklist->id,
            'actor_id' => $this->manager->id,
        ],
    ];
    DB::connection()->commit();

    $syncResults = wfAvailabilityRunBlockedWorkers($database, $shift->id, $actions);
    expect($syncResults)->toBe(['ok', 'ok']);

    $openActions = WorkforceAvailabilityCoverageAction::query()
        ->whereIn('source_type', [
            WorkforceAvailabilityCoverageAction::SOURCE_LEAVE,
            WorkforceAvailabilityCoverageAction::SOURCE_OFFBOARDING,
        ])
        ->whereIn('source_id', [$approved->id, $checklist->id])
        ->where('shift_id', $shift->id)
        ->where('status', WorkforceAvailabilityCoverageAction::STATUS_OPEN)
        ->get();
    expect($openActions)->toHaveCount(2)
        ->and($openActions->pluck('replacement_request_id')->unique())->toHaveCount(1)
        ->and(ShiftReplacementRequest::query()
            ->where('shift_id', $shift->id)
            ->whereIn('status', ['requested', 'claimed'])
            ->count())->toBe(1);

    $cancelResults = wfAvailabilityRunBlockedWorkers($database, $shift->id, [
        array_merge($actions[0], ['action' => 'cancel_leave']),
        array_merge($actions[1], ['action' => 'cancel_offboarding']),
    ]);
    expect($cancelResults)->toBe(['ok', 'ok'])
        ->and(WorkforceAvailabilityCoverageAction::query()
            ->whereIn('id', $openActions->pluck('id'))
            ->where('status', WorkforceAvailabilityCoverageAction::STATUS_CANCELLED)
            ->count())->toBe(2)
        ->and(ShiftReplacementRequest::query()
            ->whereKey($openActions->first()->replacement_request_id)
            ->where('status', 'cancelled')
            ->exists())->toBeTrue()
        ->and(ShiftOpenPosition::query()
            ->where('replacement_request_id', $openActions->first()->replacement_request_id)
            ->whereIn('status', ['open', 'claimed'])
            ->exists())->toBeFalse();
});

/**
 * @param  array<int, array{action: string, source_id: int, actor_id: int}>  $actions
 * @return list<string>
 */
function wfAvailabilityRunBlockedWorkers(string $database, int $shiftId, array $actions): array
{
    $connection = DB::connection();
    $connection->beginTransaction();
    $connection->table('shifts')->where('id', $shiftId)->lockForUpdate()->first();
    $workers = [];
    $readyPaths = [];

    try {
        foreach ($actions as $index => $action) {
            $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR
                .'wf-availability-'.bin2hex(random_bytes(12))."-{$index}.ready";
            $readyPaths[] = $readyPath;
            $workers[] = wfAvailabilityStartWorker($database, $action, $readyPath);
        }

        foreach ($readyPaths as $readyPath) {
            wfAvailabilityWaitForWorker($readyPath);
        }

        usleep(300_000);
        foreach ($workers as $worker) {
            expect($worker->isRunning())->toBeTrue();
        }

        $connection->commit();

        return collect($workers)
            ->map(function (Process $worker): string {
                $worker->wait();
                expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

                return (string) data_get(
                    json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR),
                    'status',
                );
            })
            ->sort()
            ->values()
            ->all();
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        foreach ($readyPaths as $readyPath) {
            if (is_file($readyPath)) {
                unlink($readyPath);
            }
        }
    }
}

/** @param array{action: string, source_id: int, actor_id: int} $action */
function wfAvailabilityStartWorker(string $database, array $action, string $readyPath): Process
{
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$actor = App\Models\User::query()->findOrFail((int) $payload['actor_id']);
file_put_contents($argv[3], 'ready');
switch ($payload['action']) {
    case 'sync_leave':
        $source = App\Domain\Hr\Models\HrLeaveRequest::query()->findOrFail((int) $payload['source_id']);
        $app->make(App\Domain\Hr\Services\WorkforceAvailabilityCoverageService::class)
            ->syncApprovedLeave($source, $actor);
        break;
    case 'cancel_leave':
        $source = App\Domain\Hr\Models\HrLeaveRequest::query()->findOrFail((int) $payload['source_id']);
        $app->make(App\Domain\Hr\Services\WorkforceAvailabilityCoverageService::class)
            ->cancelLeave($source, $actor);
        break;
    case 'sync_offboarding':
        $source = App\Domain\Hr\Models\HrOffboardingChecklist::query()->findOrFail((int) $payload['source_id']);
        $app->make(App\Domain\Hr\Services\WorkforceAvailabilityCoverageService::class)
            ->syncOffboarding($source, $actor);
        break;
    case 'cancel_offboarding':
        $source = App\Domain\Hr\Models\HrOffboardingChecklist::query()->findOrFail((int) $payload['source_id']);
        $app->make(App\Domain\Hr\Services\WorkforceAvailabilityCoverageService::class)
            ->cancelOffboarding($source, $actor);
        break;
    default:
        throw new RuntimeException('Unknown workforce availability action.');
}
echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
PHP;

    $process = new Process([
        PHP_BINARY,
        '-r',
        $worker,
        base_path(),
        base64_encode(json_encode($action, JSON_THROW_ON_ERROR)),
        $readyPath,
    ], base_path(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => $database,
    ]);
    $process->setTimeout(30);
    $process->start();

    return $process;
}

function wfAvailabilityWaitForWorker(string $readyPath): void
{
    $deadline = microtime(true) + 15;
    while (! is_file($readyPath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the workforce availability worker.');
        }

        usleep(10_000);
    }
}
