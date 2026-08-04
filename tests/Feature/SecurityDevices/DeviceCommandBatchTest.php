<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Jobs\DispatchDeviceCommand;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandIntakeAudit;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ItChange;
use App\Models\ItTicketLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

final class BulkCommandContractAdapter implements CommandExecutionAdapter
{
    /** @param list<string> $capabilities */
    public function __construct(
        private readonly array $capabilities = ['access.door.unlock_timed'],
        private readonly string $provider = 'batch-contract',
    ) {}

    public function supports(Device $device, string $capability): bool
    {
        return $device->provider === $this->provider
            && in_array($capability, $this->capabilities, true);
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        return new CommandExecutionResult(
            status: CommandAttemptStatus::Succeeded,
            safeSummary: ['provider_state' => 'accepted'],
            providerRequestReference: 'batch-contract:'.$context->attemptUuid,
        );
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        return new CommandObservedState(
            state: ['locked' => true],
            observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            observationReference: 'batch-contract:'.$context->attemptUuid,
            safeEvidenceSummary: 'The expected Device state was freshly confirmed.',
        );
    }
}

function bulkCommandActor(string $role = 'it_manager', ?Site $site = null): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $actor->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
    if ($site) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
    }

    return $actor;
}

function bulkCommandGrant(User $actor, string $key): void
{
    $permission = Permission::query()->where('key', $key)->firstOrFail();
    $actor->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
}

function bulkCommandDevice(Site $site, array $attributes = []): Device
{
    $device = Device::factory()->security()->create(array_replace([
        'category' => 'access_control',
        'subcategory' => 'door_controller',
        'provider' => 'batch-contract',
        'last_seen_at' => now(),
        'config' => [
            'management' => [
                'capabilities' => ['access.door.unlock_timed'],
            ],
        ],
    ], $attributes));
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);

    return $device;
}

function bulkCommandNetworkDevice(Site $site, array $attributes = []): Device
{
    $device = Device::factory()->itInfrastructure()->create(array_replace([
        'category' => 'networking',
        'subcategory' => 'gateway',
        'provider' => 'batch-contract',
        'last_seen_at' => now(),
        'config' => [
            'management' => [
                'capabilities' => ['device.reboot'],
            ],
        ],
    ], $attributes));
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);

    return $device;
}

function bulkCommandChange(Site $site, Device $device, User $actor): ItChange
{
    $change = ItChange::factory()->standard()->create([
        'created_by_user_id' => $actor->id,
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
        'created_by_user_id' => $actor->id,
    ]);

    return $change->refresh()->load('ticket');
}

function bindBulkCommandAdapter(array $capabilities = ['access.door.unlock_timed']): void
{
    app()->instance(
        CommandExecutionAdapterRegistry::class,
        new CommandExecutionAdapterRegistry([new BulkCommandContractAdapter($capabilities)]),
    );
}

function bulkDoorPayload(Device $first, Device $second, array $overrides = []): array
{
    return array_replace_recursive([
        'workspace' => 'security',
        'device_ids' => [$first->id, $second->id],
        'capability' => 'access.door.unlock_timed',
        'parameters' => ['duration_seconds' => 15],
        'reason' => 'Allow the approved engineering team through both service doors.',
        'idempotency_key' => 'bulk-door-'.$first->id.'-'.$second->id,
        'impact_acknowledged' => true,
        'confirmation_text' => 'BULK 2 DEVICES',
    ], $overrides);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'command-batch-test');
    config()->set('monitoring.signing.keys', [
        'command-batch-test' => base64_encode(str_repeat('B', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
    bindBulkCommandAdapter();
});

it('creates one immutable parent and one signed governed child per included Device', function () {
    $site = Site::factory()->create();
    $requester = bulkCommandActor();
    $first = bulkCommandDevice($site, ['name' => '=North service door']);
    $second = bulkCommandDevice($site, ['name' => 'South service door']);

    $this->actingAs($requester)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', bulkDoorPayload($first, $second))
        ->assertRedirect()
        ->assertSessionHas('success');

    $batch = DeviceCommandBatch::query()->with('targets.command')->sole();
    expect($batch->target_count)->toBe(2)
        ->and($batch->included_count)->toBe(2)
        ->and($batch->excluded_count)->toBe(0)
        ->and($batch->site_count)->toBe(1)
        ->and($batch->targets)->toHaveCount(2)
        ->and($batch->targets->pluck('device_command_request_id')->filter()->unique())->toHaveCount(2)
        ->and($batch->targets->pluck('command.status')->unique()->all())->toBe([CommandStatus::AwaitingApproval])
        ->and(DeviceCommandRequest::query()->count())->toBe(2)
        ->and(DeviceCommandIntakeAudit::query()->where('outcome', 'allowed')->count())->toBe(2);

    $this->actingAs($requester)
        ->get("/security-devices/command-batches/{$batch->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('security-devices/command-batches/show')
            ->where('batch.summary.selected', 2)
            ->where('batch.summary.included', 2)
            ->where('batch.summary.awaitingApproval', 2)
            ->has('batch.targets', 2));

    $this->actingAs($requester)
        ->get('/security-devices/security?tab=management')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('security-devices/category')
            ->where('workspace.activeTab', 'management')
            ->where('bulkManagement.workspace', 'security')
            ->where('bulkManagement.recentBatches.0.id', $batch->id)
            ->has('bulkManagement.devices', 2));

    $export = $this->actingAs($requester)
        ->get("/security-devices/command-batches/{$batch->id}/export")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($export->streamedContent())
        ->toContain('Expected state')
        ->toContain("'=North service door")
        ->not->toContain('encrypted_parameters')
        ->not->toContain('contract_hash');

    expect(fn () => $batch->update(['reason' => 'Changed after request']))
        ->toThrow(UnexpectedValueException::class, 'immutable');
});

it('retains unavailable visible targets as explicit exclusions without blanket success', function () {
    $site = Site::factory()->create();
    $requester = bulkCommandActor();
    $included = bulkCommandDevice($site, ['name' => 'Supported door']);
    $excluded = bulkCommandDevice($site, [
        'name' => 'Provider pending door',
        'provider' => 'provider-without-adapter',
    ]);

    $this->actingAs($requester)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', bulkDoorPayload($included, $excluded))
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $batch = DeviceCommandBatch::query()->with('targets.command')->sole();
    $excludedTarget = $batch->targets->firstWhere('inclusion_status', 'excluded');
    expect($batch->included_count)->toBe(1)
        ->and($batch->excluded_count)->toBe(1)
        ->and(DeviceCommandRequest::query()->count())->toBe(1)
        ->and($excludedTarget->device_id)->toBe($excluded->id)
        ->and($excludedTarget->safe_exclusion_code)->toBe('provider_adapter_required')
        ->and($excludedTarget->safe_exclusion_reason)->toContain('approved execution')
        ->and($excludedTarget->device_command_request_id)->toBeNull();
});

it('conceals the complete batch when any submitted target is outside current Site access', function () {
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $requester = bulkCommandActor('coordinator', $allowedSite);
    bulkCommandGrant($requester, 'securityDevices.commands.control');
    $allowed = bulkCommandDevice($allowedSite);
    $hidden = bulkCommandDevice($hiddenSite);

    $this->actingAs($requester)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', bulkDoorPayload($allowed, $hidden))
        ->assertNotFound();

    $denied = DeviceCommandIntakeAudit::query()->where('outcome', 'denied')->sole();
    expect(DeviceCommandBatch::query()->count())->toBe(0)
        ->and(DeviceCommandRequest::query()->count())->toBe(0)
        ->and($denied->safe_reason_code)->toBe('target_not_found')
        ->and($denied->capability)->toBeNull()
        ->and($denied->target_fingerprint)->toMatch('/^[a-f0-9]{64}$/')
        ->and(array_keys($denied->toArray()))->not->toContain('device_id', 'device_ids');
});

it('requires fresh identity, exact target-count confirmation, impact acknowledgement and MFA for critical bulk actions', function () {
    bindBulkCommandAdapter(['camera.privacy.disable']);
    $site = Site::factory()->create();
    $requester = bulkCommandActor();
    $requester->forceFill(['two_factor_confirmed_at' => null])->save();
    $first = bulkCommandDevice($site, [
        'category' => 'cctv',
        'subcategory' => 'camera',
        'config' => ['management' => ['capabilities' => ['camera.privacy.disable']]],
    ]);
    $second = bulkCommandDevice($site, [
        'category' => 'cctv',
        'subcategory' => 'camera',
        'config' => ['management' => ['capabilities' => ['camera.privacy.disable']]],
    ]);
    $payload = [
        'workspace' => 'security',
        'device_ids' => [$first->id, $second->id],
        'capability' => 'camera.privacy.disable',
        'parameters' => [],
        'reason' => 'Resume approved observation on both cameras after privacy review.',
        'idempotency_key' => 'bulk-critical-'.$first->id.'-'.$second->id,
        'impact_acknowledged' => true,
        'confirmation_text' => 'BULK 2 DEVICES',
    ];

    $this->actingAs($requester)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', $payload)
        ->assertSessionHasErrors('confirmation_text');

    $requester->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->actingAs($requester->fresh())
        ->withSession(['auth.password_confirmed_at' => now()->subHour()->timestamp])
        ->post('/security-devices/command-batches', $payload)
        ->assertSessionHasErrors('confirmation_text');

    $this->actingAs($requester->fresh())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', array_replace($payload, [
            'confirmation_text' => 'BULK 1 DEVICES',
        ]))
        ->assertSessionHasErrors('confirmation_text');

    $this->actingAs($requester->fresh())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', $payload)
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(DeviceCommandBatch::query()->count())->toBe(1)
        ->and(DeviceCommandRequest::query()->count())->toBe(2)
        ->and(DeviceCommandRequest::query()->whereNull('impact_acknowledged_at')->count())->toBe(0);
});

it('binds idempotency to the exact parent contract', function () {
    $site = Site::factory()->create();
    $requester = bulkCommandActor();
    $first = bulkCommandDevice($site);
    $second = bulkCommandDevice($site);
    $payload = bulkDoorPayload($first, $second);

    $this->actingAs($requester)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', $payload)
        ->assertRedirect();
    $this->actingAs($requester)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', $payload)
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(DeviceCommandBatch::query()->count())->toBe(1)
        ->and(DeviceCommandRequest::query()->count())->toBe(2);

    $this->actingAs($requester)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', array_replace_recursive($payload, [
            'parameters' => ['duration_seconds' => 20],
        ]))
        ->assertSessionHasErrors('idempotency_key');
});

it('uses an independently eligible IT Change for every Device across Sites', function () {
    bindBulkCommandAdapter(['device.reboot']);
    $firstSite = Site::factory()->create();
    $secondSite = Site::factory()->create();
    $requester = bulkCommandActor();
    HrEmployeeProfile::factory()->create([
        'user_id' => $requester->id,
        'primary_site_id' => $firstSite->id,
        'secondary_site_ids' => [$secondSite->id],
        'is_active' => true,
    ]);
    $first = bulkCommandNetworkDevice($firstSite);
    $second = bulkCommandNetworkDevice($secondSite);
    $firstChange = bulkCommandChange($firstSite, $first, $requester);
    $secondChange = bulkCommandChange($secondSite, $second, $requester);

    $this->actingAs($requester)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', [
            'workspace' => 'network-it',
            'device_ids' => [$first->id, $second->id],
            'capability' => 'device.reboot',
            'parameters' => [],
            'reason' => 'Restart both gateways under their own approved Site maintenance windows.',
            'idempotency_key' => 'bulk-restart-'.$first->id.'-'.$second->id,
            'it_change_ids' => [
                $first->id => $firstChange->id,
                $second->id => $secondChange->id,
            ],
            'impact_acknowledged' => true,
            'confirmation_text' => 'BULK 2 DEVICES',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $batch = DeviceCommandBatch::query()->sole();
    expect($batch->site_count)->toBe(2)
        ->and(DeviceCommandRequest::query()->where('device_id', $first->id)->sole()->it_change_id)->toBe($firstChange->id)
        ->and(DeviceCommandRequest::query()->where('device_id', $second->id)->sole()->it_change_id)->toBe($secondChange->id);
});

it('applies independent approval and dispatch decisions to every currently eligible child', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $requester = bulkCommandActor();
    $approver = bulkCommandActor();
    $first = bulkCommandDevice($site);
    $second = bulkCommandDevice($site);
    $payload = bulkDoorPayload($first, $second);

    $this->actingAs($requester)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', $payload)
        ->assertRedirect();
    $batch = DeviceCommandBatch::query()->sole();

    $this->actingAs($approver)
        ->post("/security-devices/command-batches/{$batch->id}/decision", [
            'decision' => 'approved',
            'comment' => 'Both Devices, the Site, attendance and expected lock state were confirmed.',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(DeviceCommandRequest::query()->where('status', CommandStatus::Ready->value)->count())->toBe(2);

    $this->actingAs($requester)
        ->post("/security-devices/command-batches/{$batch->id}/dispatch")
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(DeviceCommandRequest::query()->where('status', CommandStatus::Queued->value)->count())->toBe(2);
    Queue::assertPushed(DispatchDeviceCommand::class, 2);
});

it('conceals a retained batch and its export when the viewer loses any target Site', function () {
    $firstSite = Site::factory()->create();
    $secondSite = Site::factory()->create();
    $requester = bulkCommandActor();
    $first = bulkCommandDevice($firstSite);
    $second = bulkCommandDevice($secondSite);
    $payload = bulkDoorPayload($first, $second);

    $this->actingAs($requester)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/security-devices/command-batches', $payload)
        ->assertRedirect();
    $batch = DeviceCommandBatch::query()->sole();

    $restrictedViewer = bulkCommandActor('coordinator', $firstSite);
    bulkCommandGrant($restrictedViewer, 'securityDevices.commands.observe');
    $this->actingAs($restrictedViewer)
        ->get("/security-devices/command-batches/{$batch->id}")
        ->assertNotFound();
    $this->actingAs($restrictedViewer)
        ->get("/security-devices/command-batches/{$batch->id}/export")
        ->assertNotFound();
});
