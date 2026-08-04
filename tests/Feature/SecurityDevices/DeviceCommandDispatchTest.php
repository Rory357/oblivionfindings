<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandReconciliationOutcome;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Jobs\DispatchDeviceCommand;
use App\Domain\SecurityDevices\Management\Jobs\ReconcileDeviceCommand;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandReconciliationDelay;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandQueueService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ItChange;
use App\Models\ItTicketLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ContractDoorExecutionAdapter implements CommandExecutionAdapter
{
    /** @var list<CommandExecutionContext> */
    public array $executions = [];

    public function __construct(
        public ?CommandExecutionResult $result = null,
        public ?CommandObservedState $observation = null,
        public bool $throwDuringExecution = false,
        public bool $throwDuringObservation = false,
        public string $capability = 'access.door.unlock_timed',
    ) {}

    public function supports(Device $device, string $capability): bool
    {
        return $device->provider === 'contract-door'
            && $capability === $this->capability;
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        $this->executions[] = $context;
        if ($this->throwDuringExecution) {
            throw new RuntimeException('Raw provider timeout must not escape.');
        }

        return $this->result ?? new CommandExecutionResult(
            status: CommandAttemptStatus::Succeeded,
            safeSummary: ['provider_state' => 'accepted'],
            providerRequestReference: 'provider-request-123',
        );
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        if ($this->throwDuringObservation) {
            throw new RuntimeException('Raw observation failure must not escape.');
        }

        return $this->observation ?? new CommandObservedState(
            state: ['locked' => true],
            observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            observationReference: 'observation:door:123',
            safeEvidenceSummary: 'Door returned to its confirmed locked state.',
        );
    }
}

/** @return array{0: DeviceCommandRequest, 1: User, 2: User} */
function readyDoorCommand(): array
{
    $site = Site::factory()->create();
    $requester = User::factory()->create(['approved_at' => now()]);
    $approver = User::factory()->create(['approved_at' => now()]);
    $itManager = Role::query()->where('name', 'it_manager')->firstOrFail();
    $requester->roles()->attach($itManager);
    $approver->roles()->attach($itManager);
    $device = Device::factory()->security()->create([
        'category' => 'access_control',
        'subcategory' => 'door_controller',
        'provider' => 'contract-door',
        'config' => ['management' => ['capabilities' => ['access.door.unlock_timed']]],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);
    $command = app(DeviceCommandRequestService::class)->request($device, $requester, new CommandRequestInput(
        capability: 'access.door.unlock_timed',
        parameters: ['duration_seconds' => 15],
        reason: 'Let the approved technician through the service entrance.',
        idempotencyKey: 'dispatch-door-'.$device->id.'-'.$requester->id,
        stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
        impactAcknowledged: true,
    ));
    app(DeviceCommandApprovalService::class)->decide($command, $approver, new CommandDecisionInput(
        decision: CommandApprovalDecision::Approved,
        comment: 'Technician identity and attendance window confirmed.',
    ));

    return [$command->fresh(), $requester, $approver];
}

/** @return array{0: DeviceCommandRequest, 1: User, 2: ItChange} */
function readyChangeCommandForDispatch(): array
{
    $site = Site::factory()->create();
    $requester = User::factory()->create(['approved_at' => now()]);
    $requester->roles()->attach(Role::query()->where('name', 'it_manager')->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $requester->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $approver = User::factory()->create(['approved_at' => now()]);
    $approver->roles()->attach(Role::query()->where('name', 'it_manager')->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $approver->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $device = Device::factory()->itInfrastructure()->create([
        'category' => 'networking',
        'subcategory' => 'gateway',
        'provider' => 'contract-door',
        'config' => ['management' => ['capabilities' => ['device.reboot']]],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);
    $change = ItChange::factory()->standard()->create([
        'maintenance_starts_at' => now()->subMinute(),
        'maintenance_ends_at' => now()->addMinutes(10),
    ]);
    $change->ticket()->update([
        'site_id' => $site->id,
        'is_organisation_wide' => false,
        'work_type' => 'change',
        'workflow_state' => 'scheduled',
    ]);
    ItTicketLink::query()->create([
        'ticket_id' => $change->ticket_id,
        'relationship' => 'affected_device',
        'linkable_type' => $device->getMorphClass(),
        'linkable_id' => $device->id,
        'created_by_user_id' => $change->created_by_user_id,
    ]);
    $command = app(DeviceCommandRequestService::class)->request($device, $requester, new CommandRequestInput(
        capability: 'device.reboot',
        parameters: [],
        reason: 'Restart the gateway during the approved maintenance window.',
        idempotencyKey: 'dispatch-change-'.$device->id.'-'.$requester->id,
        stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
        itChangeId: $change->id,
        impactAcknowledged: true,
    ));
    app(DeviceCommandApprovalService::class)->decide($command, $approver, new CommandDecisionInput(
        decision: CommandApprovalDecision::Approved,
        comment: 'The outage impact and approved maintenance window were independently confirmed.',
    ));

    return [$command->fresh(), $requester, $change->refresh()];
}

function bindDoorAdapter(ContractDoorExecutionAdapter $adapter): void
{
    app()->instance(CommandExecutionAdapterRegistry::class, new CommandExecutionAdapterRegistry([$adapter]));
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'command-dispatch-test');
    config()->set('monitoring.signing.keys', [
        'command-dispatch-test' => base64_encode(str_repeat('D', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
});

it('fails closed when no approved provider adapter is registered', function () {
    [$command, $requester] = readyDoorCommand();

    expect(fn () => app(CommandDispatchPort::class)->dispatch($command, $requester))
        ->toThrow(ValidationException::class, 'No approved execution adapter is currently available')
        ->and($command->attempts()->count())->toBe(0)
        ->and($command->fresh()->status)->toBe(CommandStatus::Blocked)
        ->and($command->fresh()->blocked_reason_code)->toBe('provider_adapter_withdrawn');
});

it('revalidates the approved IT Change window immediately before dispatch', function () {
    [$command, $requester, $change] = readyChangeCommandForDispatch();
    $adapter = new ContractDoorExecutionAdapter(capability: 'device.reboot');
    bindDoorAdapter($adapter);

    $change->update([
        'maintenance_starts_at' => now()->subMinutes(10),
        'maintenance_ends_at' => now()->subMinute(),
    ]);

    expect(fn () => app(CommandDispatchPort::class)->dispatch($command->fresh(), $requester))
        ->toThrow(ValidationException::class, 'approved IT Change or maintenance window is no longer current')
        ->and($command->attempts()->count())->toBe(0)
        ->and($adapter->executions)->toHaveCount(0)
        ->and($command->fresh()->status)->toBe(CommandStatus::Blocked)
        ->and($command->fresh()->blocked_reason_code)->toBe('change_window_closed');
});

it('blocks a queued command when its observation becomes stale and never invokes the adapter', function () {
    Queue::fake();
    [$command, $requester] = readyDoorCommand();
    $adapter = new ContractDoorExecutionAdapter;
    bindDoorAdapter($adapter);
    $command->device()->update(['last_seen_at' => now()->subHour()]);

    expect(fn () => app(DeviceCommandQueueService::class)->queue($command->fresh(), $requester))
        ->toThrow(ValidationException::class, 'observation became stale')
        ->and($command->fresh()->status)->toBe(CommandStatus::Blocked)
        ->and($command->fresh()->blocked_reason_code)->toBe('observation_stale')
        ->and($command->fresh()->blocked_at)->not->toBeNull()
        ->and($command->attempts()->count())->toBe(0)
        ->and($command->auditEvents()->where('action', 'dispatch_blocked')->count())->toBe(1)
        ->and($adapter->executions)->toBe([]);
    Queue::assertNothingPushed();
});

it('binds dispatch to the exact approved assignment even when the Site stays the same', function () {
    [$command, $requester] = readyDoorCommand();
    $adapter = new ContractDoorExecutionAdapter;
    bindDoorAdapter($adapter);
    $assignment = DeviceAssignment::query()->where('device_id', $command->device_id)->sole();
    $assignment->update(['released_at' => now()]);
    DeviceAssignment::query()->create([
        'device_id' => $command->device_id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $command->site_id,
        'assigned_at' => now(),
    ]);

    expect(fn () => app(CommandDispatchPort::class)->dispatch($command->fresh(), $requester))
        ->toThrow(ValidationException::class, 'assignment or ownership changed')
        ->and($command->fresh()->status)->toBe(CommandStatus::Blocked)
        ->and($command->fresh()->blocked_reason_code)->toBe('device_assignment_changed')
        ->and($command->attempts()->count())->toBe(0)
        ->and($adapter->executions)->toBe([]);
});

it('revalidates the parameter schema immediately before dispatch', function () {
    [$command, $requester] = readyDoorCommand();
    $adapter = new ContractDoorExecutionAdapter;
    bindDoorAdapter($adapter);
    $definitions = config('security_devices.command_capabilities');
    $definitions['access.door.unlock_timed']['parameters']['duration_seconds']['max'] = 10;
    config()->set('security_devices.command_capabilities', $definitions);

    expect(fn () => app(CommandDispatchPort::class)->dispatch($command->fresh(), $requester))
        ->toThrow(ValidationException::class, 'parameter policy changed')
        ->and($command->fresh()->status)->toBe(CommandStatus::Blocked)
        ->and($command->fresh()->blocked_reason_code)->toBe('parameter_policy_changed')
        ->and($command->attempts()->count())->toBe(0)
        ->and($adapter->executions)->toBe([]);
});

it('blocks dispatch when the risk confirmation policy changes after approval', function () {
    [$command, $requester] = readyDoorCommand();
    $adapter = new ContractDoorExecutionAdapter;
    bindDoorAdapter($adapter);
    $definitions = config('security_devices.command_capabilities');
    $definitions['access.door.unlock_timed']['confirmation_mode'] = 'type_device_name';
    config()->set('security_devices.command_capabilities', $definitions);

    expect(fn () => app(CommandDispatchPort::class)->dispatch($command->fresh(), $requester))
        ->toThrow(ValidationException::class, 'risk safeguards changed')
        ->and($command->fresh()->status)->toBe(CommandStatus::Blocked)
        ->and($command->fresh()->blocked_reason_code)->toBe('risk_policy_changed')
        ->and($command->attempts()->count())->toBe(0)
        ->and($adapter->executions)->toBe([]);
});

it('dispatches only a ready signed command and reconciles fresh actual state before success', function () {
    [$command, $requester] = readyDoorCommand();
    $adapter = new ContractDoorExecutionAdapter;
    bindDoorAdapter($adapter);

    $attempt = app(CommandDispatchPort::class)->dispatch($command, $requester);
    $command->refresh();
    expect($attempt->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($command->status)->toBe(CommandStatus::Reconciling)
        ->and($command->reconciled_at)->toBeNull()
        ->and($adapter->executions)->toHaveCount(1)
        ->and($adapter->executions[0]->parameters)->toBe(['duration_seconds' => 15]);

    $reconciliation = app(DeviceCommandReconciliationService::class)->reconcile($command, $requester);
    $event = DeviceEvent::query()
        ->where('event_type', 'management_command_reconciled')
        ->sole();
    expect($reconciliation->outcome)->toBe(CommandReconciliationOutcome::Matched)
        ->and($command->fresh()->status)->toBe(CommandStatus::Reconciled)
        ->and($command->fresh()->reconciled_at)->not->toBeNull()
        ->and($command->auditEvents()->where('action', 'reconciliation_matched')->count())->toBe(1)
        ->and($event->device_id)->toBe($command->device_id)
        ->and($event->source)->toBe('oblivion_command_plane')
        ->and($event->payload)->toMatchArray([
            'command_uuid' => $command->command_uuid,
            'capability' => 'access.door.unlock_timed',
            'site_id' => $command->site_id,
            'attempt_number' => 1,
            'outcome' => 'matched',
        ])
        ->and(json_encode($event->payload))->not->toContain($command->reason);
});

it('preserves an asynchronous provider acceptance without claiming execution completed', function () {
    [$command, $requester] = readyDoorCommand();
    $adapter = new ContractDoorExecutionAdapter(result: new CommandExecutionResult(
        status: CommandAttemptStatus::Accepted,
        safeSummary: ['delivery_state' => 'queued_for_device'],
        providerRequestReference: 'provider:accepted:1',
    ));
    bindDoorAdapter($adapter);

    $attempt = app(CommandDispatchPort::class)->dispatch($command, $requester);
    $command->refresh();

    expect($attempt->status)->toBe(CommandAttemptStatus::Accepted)
        ->and($attempt->accepted_at)->not->toBeNull()
        ->and($attempt->started_at)->toBeNull()
        ->and($attempt->completed_at)->toBeNull()
        ->and($attempt->provider_request_reference)->toBe('provider:accepted:1')
        ->and($command->status)->toBe(CommandStatus::Accepted)
        ->and($command->execution_completed_at)->toBeNull()
        ->and($command->safe_result_summary)->toContain('queued_for_device');
});

it('records a fresh-state mismatch instead of reporting blanket success', function () {
    [$command, $requester] = readyDoorCommand();
    $adapter = new ContractDoorExecutionAdapter(
        observation: new CommandObservedState(
            state: ['locked' => false],
            observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            observationReference: 'observation:door:mismatch',
            safeEvidenceSummary: 'Door remained unlocked after the expected relock window.',
        ),
    );
    bindDoorAdapter($adapter);

    app(CommandDispatchPort::class)->dispatch($command, $requester);
    $reconciliation = app(DeviceCommandReconciliationService::class)->reconcile($command->fresh(), $requester);

    expect($reconciliation->outcome)->toBe(CommandReconciliationOutcome::Mismatch)
        ->and($command->fresh()->status)->toBe(CommandStatus::Mismatch)
        ->and($command->fresh()->reconciled_at)->toBeNull();
});

it('turns an execution timeout into uncertain state and never retries blindly', function () {
    [$command, $requester] = readyDoorCommand();
    $adapter = new ContractDoorExecutionAdapter(throwDuringExecution: true);
    bindDoorAdapter($adapter);

    $attempt = app(CommandDispatchPort::class)->dispatch($command, $requester);
    expect($attempt->status)->toBe(CommandAttemptStatus::Uncertain)
        ->and($attempt->safe_failure_reason)->toContain('Reconcile actual state')
        ->and($command->fresh()->status)->toBe(CommandStatus::Uncertain)
        ->and($adapter->executions)->toHaveCount(1);

    expect(fn () => app(CommandDispatchPort::class)->dispatch($command->fresh(), $requester))
        ->toThrow(ValidationException::class, 'Only a ready or queued command can be dispatched.')
        ->and($adapter->executions)->toHaveCount(1);

    $reconciliation = app(DeviceCommandReconciliationService::class)->reconcile($command->fresh(), $requester);
    expect($reconciliation->outcome)->toBe(CommandReconciliationOutcome::Matched)
        ->and($command->fresh()->status)->toBe(CommandStatus::Reconciled);
});

it('keeps reconciliation uncertain when fresh state cannot be observed', function () {
    [$command, $requester] = readyDoorCommand();
    $adapter = new ContractDoorExecutionAdapter(throwDuringObservation: true);
    bindDoorAdapter($adapter);

    app(CommandDispatchPort::class)->dispatch($command, $requester);
    $reconciliation = app(DeviceCommandReconciliationService::class)->reconcile($command->fresh(), $requester);

    expect($reconciliation->outcome)->toBe(CommandReconciliationOutcome::Uncertain)
        ->and($reconciliation->observed_state)->toBeNull()
        ->and($reconciliation->safe_evidence_summary)->toContain('Do not retry')
        ->and($command->fresh()->status)->toBe(CommandStatus::Uncertain);
});

it('rejects a tampered signature before creating an execution attempt', function () {
    [$command, $requester] = readyDoorCommand();
    bindDoorAdapter(new ContractDoorExecutionAdapter);
    DB::table('device_command_requests')->where('id', $command->id)->update([
        'signature' => base64_encode(str_repeat('X', SODIUM_CRYPTO_AUTH_BYTES)),
    ]);

    expect(fn () => app(CommandDispatchPort::class)->dispatch($command->fresh(), $requester))
        ->toThrow(ValidationException::class, 'The signed command contract could not be verified.')
        ->and($command->attempts()->count())->toBe(0);
});

it('redacts an adapter result that attempts to return sensitive fields', function () {
    [$command, $requester] = readyDoorCommand();
    $adapter = new ContractDoorExecutionAdapter(result: new CommandExecutionResult(
        status: CommandAttemptStatus::Succeeded,
        safeSummary: ['token' => 'must-not-persist'],
    ));
    bindDoorAdapter($adapter);

    $attempt = app(CommandDispatchPort::class)->dispatch($command, $requester);

    expect($attempt->status)->toBe(CommandAttemptStatus::Uncertain)
        ->and($attempt->safe_result_summary)->toBe([])
        ->and($attempt->safe_failure_reason)->not->toContain('must-not-persist')
        ->and(json_encode($command->fresh()->toArray()))->not->toContain('must-not-persist');
});

it('allows only the original requester or a command administrator to trigger dispatch', function () {
    [$command, $requester] = readyDoorCommand();
    bindDoorAdapter(new ContractDoorExecutionAdapter);
    $otherOperator = User::factory()->create(['approved_at' => now()]);
    $otherOperator->roles()->attach(Role::query()->where('name', 'coordinator')->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $otherOperator->id,
        'primary_site_id' => $command->site_id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $control = Permission::query()->where('key', 'securityDevices.commands.control')->firstOrFail();
    $otherOperator->permissionOverrides()->attach($control->id, ['allowed' => true]);

    expect(fn () => app(CommandDispatchPort::class)->dispatch($command, $otherOperator))
        ->toThrow(HttpException::class)
        ->and($command->attempts()->count())->toBe(0);

    app(CommandDispatchPort::class)->dispatch($command, $requester);
    expect($command->attempts()->count())->toBe(1);
});

it('queues execution on the isolated command queue and follows with reconciliation', function () {
    Queue::fake();
    [$command, $requester] = readyDoorCommand();
    bindDoorAdapter(new ContractDoorExecutionAdapter);

    app(DeviceCommandQueueService::class)->queue($command, $requester);
    expect($command->fresh()->status)->toBe(CommandStatus::Queued);
    Queue::assertPushed(DispatchDeviceCommand::class, fn (DispatchDeviceCommand $job): bool => $job->commandId === $command->id
        && $job->triggeredByUserId === $requester->id
        && $job->queue === 'monitoring-commands');

    $dispatchJob = Queue::pushed(DispatchDeviceCommand::class)->first();
    $dispatchJob->handle(app(CommandDispatchPort::class), app(CommandReconciliationDelay::class));
    Queue::assertPushed(ReconcileDeviceCommand::class, fn (ReconcileDeviceCommand $job): bool => $job->commandId === $command->id && $job->queue === 'monitoring-commands');

    $reconcileJob = Queue::pushed(ReconcileDeviceCommand::class)->first();
    expect($reconcileJob->delay)->not->toBeNull();
    $reconcileJob->handle(app(DeviceCommandReconciliationService::class));
    expect($command->fresh()->status)->toBe(CommandStatus::Reconciled)
        ->and($command->attempts()->count())->toBe(1)
        ->and($command->reconciliations()->count())->toBe(1);
});

it('conceals the execution route from an operator outside the command Site', function () {
    Queue::fake();
    [$command] = readyDoorCommand();
    $otherSite = Site::factory()->create();
    $outsideOperator = User::factory()->create(['approved_at' => now()]);
    $outsideOperator->roles()->attach(Role::query()->where('name', 'coordinator')->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $outsideOperator->id,
        'primary_site_id' => $otherSite->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $control = Permission::query()->where('key', 'securityDevices.commands.control')->firstOrFail();
    $outsideOperator->permissionOverrides()->attach($control->id, ['allowed' => true]);

    $this->actingAs($outsideOperator)
        ->post("/security-devices/commands/{$command->id}/dispatch")
        ->assertNotFound();

    expect($command->fresh()->status)->toBe(CommandStatus::Ready)
        ->and($command->attempts()->count())->toBe(0);
    Queue::assertNothingPushed();
});
