<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandReconciliationOutcome;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Jobs\ReconcileDeviceCommand;
use App\Domain\SecurityDevices\Management\Models\DeviceConfigurationProfile;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\FleetTelemetryEvent;
use App\Models\ItChange;
use App\Models\ItTicketLink;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Queclink\GovernedCommandLifecycleService;
use App\Services\Queclink\Listener\ConnectionState;
use App\Services\Queclink\Listener\FrameRouter;
use App\Services\Queclink\QueclinkConfigurationProfileService;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuthoritativeConsentFixture;

uses(RefreshDatabase::class);

final class QueclinkCommandFixtureLeaseProvider implements CredentialLeaseProvider
{
    /** @var list<array{site_id: int, reference: string, capabilities: list<string>}> */
    public array $calls = [];

    public function __construct(public string $password = 'QueclinkSecret42') {}

    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
    {
        $this->calls[] = [
            'site_id' => $siteId,
            'reference' => $reference,
            'capabilities' => array_values($capabilities),
        ];

        return new CredentialLease(
            'queclink-command-lease-'.count($this->calls),
            CarbonImmutable::now('UTC')->addMinute(),
            ['command_password' => $this->password],
        );
    }
}

final class QueclinkCommandFixtureSecretIssuer implements SecretManagerLeaseIssuer
{
    /** @var list<string> */
    public array $revoked = [];

    public function issue(SecretLeaseRequest $request): CredentialLease
    {
        throw new RuntimeException('The Queclink adapter must acquire through the governed lease provider.');
    }

    public function revoke(string $leaseId): void
    {
        $this->revoked[] = $leaseId;
    }
}

/** @return array{actor: User, site: Site, client: Client, asset: Asset, tracker: AssetTracker, device: Device, providerDevice: QueclinkDevice} */
function governedQueclinkTrackingFixture(string $imei = '864696060004173'): array
{
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $actor = User::factory()->create(['approved_at' => now()]);
    $actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $type = ConsentType::query()->create([
        'name' => 'Governed personal location '.$imei,
        'category' => 'operational',
        'description' => 'Personal safety location refresh',
        'purpose' => 'Client personal safety tracking',
        'legal_basis' => 'consent',
        'allows_withdrawal' => true,
        'active' => true,
    ]);
    $version = ConsentTypeVersion::query()->create([
        'consent_type_id' => $type->id,
        'version' => 1,
        'description' => 'Governed location refresh v1',
        'purpose' => 'Client personal safety tracking',
        'legal_basis' => 'consent',
        'effective_from' => now()->subDay(),
    ]);
    $consent = AuthoritativeConsentFixture::manualSelf($client, $type, $actor, [
        'status' => 'given',
        'given_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);
    $asset = Asset::factory()->create([
        'category' => 'personal_tracker',
        'site_id' => $site->id,
        'client_id' => $client->id,
    ]);
    $tracker = AssetTracker::query()->create([
        'asset_id' => $asset->id,
        'vendor' => 'queclink',
        'device_uid' => $imei,
        'imei' => $imei,
        'status' => 'paired',
        'paired_at' => now(),
        'consent_id' => $consent->id,
    ]);
    $device = Device::factory()->tracking()->create([
        'provider' => 'queclink',
        'category' => 'personal_tracker',
        'imei' => $imei,
        'device_uid' => $imei,
        'legacy_asset_tracker_id' => $tracker->id,
        'config' => [],
        'last_seen_at' => now(),
    ]);
    DeviceAssetLink::query()->create([
        'device_id' => $device->id,
        'asset_id' => $asset->id,
        'link_type' => LinkType::InstalledIn,
        'linked_at' => now(),
        'linked_by_user_id' => $actor->id,
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
        'consent_id' => $consent->id,
    ]);
    $providerDevice = QueclinkDevice::query()->create([
        'imei' => $imei,
        'device_id' => $device->id,
        'status' => QueclinkDevice::STATUS_PAIRED,
        'model_hint' => 'GV500CG',
    ]);

    return compact('actor', 'site', 'client', 'asset', 'tracker', 'device', 'providerDevice');
}

/** @return array{leases: QueclinkCommandFixtureLeaseProvider, issuer: QueclinkCommandFixtureSecretIssuer} */
function bindGovernedQueclinkCommandCredential(array $fixture, string $password = 'QueclinkSecret42'): array
{
    CredentialReference::query()->create([
        'reference_key' => 'vault:queclink/'.$fixture['site']->id.'/device-management',
        'site_id' => $fixture['site']->id,
        'provider' => 'queclink',
        'purpose' => 'device_management',
        'capabilities' => [
            'command:tracking.location_refresh',
            'command:configuration.refresh',
            'command:configuration.apply',
            'command:device.reboot',
        ],
        'secret_manager_reference' => 'secret/data/sites/'.$fixture['site']->id.'/queclink',
        'secret_manager_reference_hash' => hash('sha256', 'queclink-'.$fixture['site']->id),
        'status' => CredentialReferenceStatus::Active,
        'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed,
        'version' => 1,
        'last_tested_at' => now(),
    ]);
    $leases = new QueclinkCommandFixtureLeaseProvider($password);
    $issuer = new QueclinkCommandFixtureSecretIssuer;
    app()->instance(CredentialLeaseProvider::class, $leases);
    app()->instance(SecretManagerLeaseIssuer::class, $issuer);

    return compact('leases', 'issuer');
}

function requestGovernedLocationRefresh(array $fixture)
{
    return app(DeviceCommandRequestService::class)->request(
        $fixture['device'],
        $fixture['actor'],
        new CommandRequestInput(
            capability: 'tracking.location_refresh',
            parameters: [],
            reason: 'Refresh the authorised safety location after the welfare escalation.',
            idempotencyKey: 'queclink-location-'.$fixture['device']->id.'-'.$fixture['actor']->id,
            stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
        ),
    );
}

function requestGovernedConfigurationRefresh(array $fixture, string $section = 'SRI')
{
    return app(DeviceCommandRequestService::class)->request(
        $fixture['device'],
        $fixture['actor'],
        new CommandRequestInput(
            capability: 'configuration.refresh',
            parameters: ['section' => $section],
            reason: 'Refresh the protected tracker configuration for the approved support review.',
            idempotencyKey: 'queclink-configuration-'.$fixture['device']->id.'-'.$fixture['actor']->id,
            stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
        ),
    );
}

/** @return array{request: mixed, approver: User, change: ItChange} */
function requestGovernedReboot(array $fixture): array
{
    $approver = User::factory()->create(['approved_at' => now()]);
    $approver->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $approver->id,
        'primary_site_id' => $fixture['site']->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $change = ItChange::factory()->standard()->create([
        'created_by_user_id' => $fixture['actor']->id,
        'maintenance_starts_at' => now()->subMinute(),
        'maintenance_ends_at' => now()->addMinutes(10),
    ]);
    $change->ticket()->update([
        'site_id' => $fixture['site']->id,
        'is_organisation_wide' => false,
        'work_type' => 'change',
        'workflow_state' => 'scheduled',
    ]);
    ItTicketLink::query()->create([
        'ticket_id' => $change->ticket_id,
        'relationship' => 'affected_device',
        'linkable_type' => $fixture['device']->getMorphClass(),
        'linkable_id' => $fixture['device']->id,
        'created_by_user_id' => $fixture['actor']->id,
    ]);
    $request = app(DeviceCommandRequestService::class)->request(
        $fixture['device'],
        $fixture['actor'],
        new CommandRequestInput(
            capability: 'device.reboot',
            parameters: [],
            reason: 'Restart the paired tracker during the approved maintenance window.',
            idempotencyKey: 'queclink-reboot-'.$fixture['device']->id.'-'.$fixture['actor']->id,
            stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            itChangeId: $change->id,
            impactAcknowledged: true,
        ),
    );
    app(DeviceCommandApprovalService::class)->decide(
        $request,
        $approver,
        new CommandDecisionInput(
            decision: CommandApprovalDecision::Approved,
            comment: 'The tracker outage and approved maintenance window were independently confirmed.',
        ),
    );

    return compact('request', 'approver', 'change');
}

/** @return array{request: mixed, profile: DeviceConfigurationProfile, approver: User, change: ItChange} */
function requestGovernedConfigurationApply(array $fixture): array
{
    $profile = app(QueclinkConfigurationProfileService::class)->createProfile(
        profileKey: 'queclink:device-'.$fixture['device']->id.':draft:test',
        name: 'Approved safety tracking profile',
        description: 'Two-section desired state for governed lifecycle verification.',
        targetCategory: 'personal_tracker',
        sections: [
            'server' => [
                'main_host' => 'tracking.example.test',
                'main_port' => 8090,
            ],
            'tracking' => [
                'continuous_send_interval_seconds' => 60,
                'battery_low_percentage' => 20,
            ],
        ],
        isSystem: false,
        createdByUserId: $fixture['actor']->id,
    );
    $approver = User::factory()->create(['approved_at' => now()]);
    $approver->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $approver->id,
        'primary_site_id' => $fixture['site']->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $change = ItChange::factory()->standard()->create([
        'created_by_user_id' => $fixture['actor']->id,
        'maintenance_starts_at' => now()->subMinute(),
        'maintenance_ends_at' => now()->addMinutes(10),
    ]);
    $change->ticket()->update([
        'site_id' => $fixture['site']->id,
        'is_organisation_wide' => false,
        'work_type' => 'change',
        'workflow_state' => 'scheduled',
    ]);
    ItTicketLink::query()->create([
        'ticket_id' => $change->ticket_id,
        'relationship' => 'affected_device',
        'linkable_type' => $fixture['device']->getMorphClass(),
        'linkable_id' => $fixture['device']->id,
        'created_by_user_id' => $fixture['actor']->id,
    ]);
    $request = app(DeviceCommandRequestService::class)->request(
        $fixture['device'],
        $fixture['actor'],
        new CommandRequestInput(
            capability: 'configuration.apply',
            parameters: ['configuration_profile_id' => $profile->id],
            reason: 'Apply the approved tracker desired state during its linked maintenance window.',
            idempotencyKey: 'queclink-configuration-apply-'.$fixture['device']->id,
            stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            itChangeId: $change->id,
            impactAcknowledged: true,
        ),
    );
    app(DeviceCommandApprovalService::class)->decide(
        $request,
        $approver,
        new CommandDecisionInput(
            decision: CommandApprovalDecision::Approved,
            comment: 'The desired-state profile and maintenance window were independently checked.',
        ),
    );

    return compact('request', 'profile', 'approver', 'change');
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'queclink-command-test');
    config()->set('monitoring.signing.keys', [
        'queclink-command-test' => base64_encode(str_repeat('Q', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
});

it('runs native Queclink location refresh through accepted running telemetry and reconciled states', function () {
    Queue::fake();
    $fixture = governedQueclinkTrackingFixture();
    $credential = bindGovernedQueclinkCommandCredential($fixture);
    $request = requestGovernedLocationRefresh($fixture);

    expect($request->status)->toBe(CommandStatus::Ready);
    $attempt = app(CommandDispatchPort::class)->dispatch($request, $fixture['actor']);
    $pending = QueclinkPendingCommand::query()->sole();
    expect($attempt->status)->toBe(CommandAttemptStatus::Accepted)
        ->and($attempt->started_at)->toBeNull()
        ->and($attempt->completed_at)->toBeNull()
        ->and($request->fresh()->status)->toBe(CommandStatus::Accepted)
        ->and($pending->device_command_request_id)->toBe($request->id)
        ->and($pending->device_command_attempt_id)->toBe($attempt->id)
        ->and($pending->command_word)->toBe('GTRTO')
        ->and($pending->raw_command)->toStartWith('AT+GTRTO=QueclinkSecret42,1,')
        ->and($pending->toArray())->not->toHaveKeys(['raw_command', 'raw_command_encrypted'])
        ->and((string) DB::table('queclink_pending_commands')->value('raw_command'))
        ->not->toContain('QueclinkSecret42')
        ->not->toStartWith('AT+GT')
        ->and((string) DB::table('queclink_pending_commands')->value('raw_command_encrypted'))
        ->not->toContain('QueclinkSecret42')
        ->not->toStartWith('AT+GT')
        ->and($credential['leases']->calls)->toHaveCount(1)
        ->and($credential['leases']->calls[0]['site_id'])->toBe($fixture['site']->id)
        ->and($credential['leases']->calls[0]['capabilities'])->toBe(['command:tracking.location_refresh'])
        ->and($credential['issuer']->revoked)->toBe(['queclink-command-lease-1']);

    $router = app(FrameRouter::class);
    $state = new ConnectionState('192.0.2.44:54321');
    $heartbeatResponses = $router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20260802010000,09CF$',
        $state,
    );
    expect($heartbeatResponses)->toHaveCount(2)
        ->and($heartbeatResponses[1])->toStartWith('AT+GTRTO=QueclinkSecret42,1,')
        ->and($pending->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_SENT)
        ->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Running)
        ->and($request->fresh()->status)->toBe(CommandStatus::Running);
    $storedOutbound = DB::table('queclink_raw_frames')
        ->where('direction', 'outbound')
        ->where('frame_type', 'AT')
        ->latest('id')
        ->first();
    expect($storedOutbound)->not->toBeNull()
        ->and((string) $storedOutbound->raw_frame)->not->toContain('QueclinkSecret42')
        ->not->toStartWith('AT+GT')
        ->and((string) $storedOutbound->encrypted_raw_frame)->not->toContain('QueclinkSecret42')
        ->not->toStartWith('AT+GT');
    $pending = $pending->fresh();

    $this->travel(1)->seconds();
    $router->handleInbound(
        '+RESP:GTFRI,8020090100,864696060004173,GV500CG,11985,10,1,1,2.5,180,118.5,174.7633,-36.8485,20260802010001,0460,0001,DF5C,02A90902,01,15,0.0,20260802010001,0120$',
        $state,
    );

    $event = FleetTelemetryEvent::query()
        ->where('device_id', $fixture['device']->id)
        ->where('vendor', 'queclink')
        ->where('received_at', '>=', $pending->sent_at)
        ->latest('id')
        ->firstOrFail();
    expect($event->consent_blocked)->toBeFalse()
        ->and($pending->fresh()->fulfilled_telemetry_event_id)->toBe($event->id)
        ->and($pending->fresh()->fulfilled_at)->not->toBeNull()
        ->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($request->fresh()->status)->toBe(CommandStatus::Reconciling);
    Queue::assertPushed(ReconcileDeviceCommand::class, fn (ReconcileDeviceCommand $job): bool => $job->commandId === $request->id);

    $job = Queue::pushed(ReconcileDeviceCommand::class)->first();
    $job->handle(app(DeviceCommandReconciliationService::class));
    $reconciliation = $request->reconciliations()->sole();
    expect($reconciliation->outcome)->toBe(CommandReconciliationOutcome::Matched)
        ->and($reconciliation->observed_state)->toBe(['action_completed' => true])
        ->and($reconciliation->observation_reference)->toBe('fleet-telemetry:'.$event->id)
        ->and($request->fresh()->status)->toBe(CommandStatus::Reconciled)
        ->and($request->auditEvents()->where('action', 'provider_delivery_started')->count())->toBe(1)
        ->and($request->auditEvents()->where('action', 'provider_observation_received')->count())->toBe(1)
        ->and(json_encode($request->fresh()->toArray()))->not->toContain($pending->raw_command);
});

it('does not treat privacy-blocked telemetry as command evidence and expires without retrying', function () {
    Queue::fake();
    $fixture = governedQueclinkTrackingFixture('864696060004174');
    bindGovernedQueclinkCommandCredential($fixture);
    $request = requestGovernedLocationRefresh($fixture);
    $attempt = app(CommandDispatchPort::class)->dispatch($request, $fixture['actor']);
    $pending = app(GovernedCommandLifecycleService::class)->markSent(QueclinkPendingCommand::query()->sole());
    $this->travel(1)->seconds();
    $event = FleetTelemetryEvent::query()->create([
        'asset_id' => $fixture['asset']->id,
        'asset_tracker_id' => $fixture['tracker']->id,
        'device_id' => $fixture['device']->id,
        'vendor' => 'queclink',
        'occurred_at' => now(),
        'received_at' => now(),
        'latitude' => null,
        'longitude' => null,
        'event_type' => 'location_report',
        'idempotency_key' => hash('sha256', 'privacy-blocked-'.$request->id),
        'consent_blocked' => true,
    ]);

    expect(app(GovernedCommandLifecycleService::class)->fulfilFromTelemetry(
        $fixture['providerDevice'],
        $event->id,
    ))->toBe(0)
        ->and($pending->fresh()->fulfilled_at)->toBeNull()
        ->and($request->fresh()->status)->toBe(CommandStatus::Running);
    Queue::assertNothingPushed();

    $this->travel(4)->minutes();
    expect(app(GovernedCommandLifecycleService::class)->expireStale())->toBe(1)
        ->and($pending->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_EXPIRED)
        ->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Expired)
        ->and($request->fresh()->status)->toBe(CommandStatus::Expired)
        ->and(QueclinkPendingCommand::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('runs a protected Queclink configuration refresh through the common governed lifecycle', function () {
    Queue::fake();
    $fixture = governedQueclinkTrackingFixture('867963069916998');
    $fixture['providerDevice']->forceFill(['model_hint' => 'GL30MEU'])->save();
    bindGovernedQueclinkCommandCredential($fixture);
    $request = requestGovernedConfigurationRefresh($fixture);

    $attempt = app(CommandDispatchPort::class)->dispatch($request, $fixture['actor']);
    $pending = QueclinkPendingCommand::query()->sole();
    expect($attempt->status)->toBe(CommandAttemptStatus::Accepted)
        ->and($pending->raw_command)->toMatch('/^AT\+GTRTO=QueclinkSecret42,2,SRI,,,,,[0-9A-F]{4}\$$/');

    $router = app(FrameRouter::class);
    $state = new ConnectionState('192.0.2.45:54321');
    $responses = $router->handleInbound(
        '+RESP:GTHBD,970204,867963069916998,GL30MEU,20260802020000,09D0$',
        $state,
    );
    expect($responses)->toHaveCount(2)
        ->and($request->fresh()->status)->toBe(CommandStatus::Running);

    $this->travel(1)->seconds();
    $router->handleInbound(
        '+RESP:GTALM,970204,867963069916998,GL30MEU,1,1,SRI,3,0,1,tracking.example.test,8090,backup.example.test,8090,,5,1,0,30,0,20260802020001,0A11$',
        $state,
    );

    $pending = $pending->fresh();
    expect($pending->fulfilled_raw_frame_id)->not->toBeNull()
        ->and($pending->fulfilled_at)->not->toBeNull()
        ->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($request->fresh()->status)->toBe(CommandStatus::Reconciling);
    Queue::assertPushed(ReconcileDeviceCommand::class, fn (ReconcileDeviceCommand $job): bool => $job->commandId === $request->id);

    Queue::pushed(ReconcileDeviceCommand::class)->first()->handle(app(DeviceCommandReconciliationService::class));
    $reconciliation = $request->reconciliations()->sole();
    expect($reconciliation->outcome)->toBe(CommandReconciliationOutcome::Matched)
        ->and($reconciliation->observed_state)->toBe(['action_completed' => true])
        ->and($reconciliation->observation_reference)->toBe('queclink-frame:'.$pending->fulfilled_raw_frame_id)
        ->and($request->fresh()->status)->toBe(CommandStatus::Reconciled)
        ->and((string) DB::table('queclink_raw_frames')
            ->where('id', $pending->fulfilled_raw_frame_id)
            ->value('parsed_payload'))->not->toContain('tracking.example.test');
});

it('reconciles a governed tracker reboot only after a new listener session', function () {
    Queue::fake();
    $fixture = governedQueclinkTrackingFixture('864696060004178');
    bindGovernedQueclinkCommandCredential($fixture);
    $governed = requestGovernedReboot($fixture);
    $request = $governed['request'];

    expect($request->fresh()->status)->toBe(CommandStatus::Ready);
    $attempt = app(CommandDispatchPort::class)->dispatch($request->fresh(), $fixture['actor']);
    $pending = QueclinkPendingCommand::query()->sole();
    expect($attempt->status)->toBe(CommandAttemptStatus::Accepted)
        ->and($pending->raw_command)->toMatch('/^AT\+GTRTO=QueclinkSecret42,3,,,,,,[0-9A-F]{4}\$$/');

    $router = app(FrameRouter::class);
    $originalSession = new ConnectionState('192.0.2.46:54321');
    $responses = $router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004178,GV500CG,20260802030000,09D1$',
        $originalSession,
    );
    $pending = $pending->fresh();
    expect($responses)->toHaveCount(2)
        ->and($responses[1])->toStartWith('AT+GTRTO=QueclinkSecret42,3,')
        ->and($pending->sent_session_id)->toBe($originalSession->sessionId)
        ->and($request->fresh()->status)->toBe(CommandStatus::Running);

    $this->travel(1)->seconds();
    $router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004178,GV500CG,20260802030001,09D2$',
        $originalSession,
    );
    expect($pending->fresh()->fulfilled_at)->toBeNull()
        ->and($request->fresh()->status)->toBe(CommandStatus::Running);

    $router->handleDisconnect($originalSession);
    $this->travel(1)->seconds();
    $newSession = new ConnectionState('192.0.2.46:54322');
    expect($newSession->sessionId)->not->toBe($originalSession->sessionId);
    $router->handleInbound(
        '+RESP:GTHBD,8020090100,864696060004178,GV500CG,20260802030002,09D3$',
        $newSession,
    );

    $pending = $pending->fresh();
    expect($pending->fulfilled_raw_frame_id)->not->toBeNull()
        ->and($pending->fulfilled_at)->not->toBeNull()
        ->and($pending->toArray())->not->toHaveKey('sent_session_id')
        ->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($request->fresh()->status)->toBe(CommandStatus::Reconciling);
    Queue::assertPushed(ReconcileDeviceCommand::class, fn (ReconcileDeviceCommand $job): bool => $job->commandId === $request->id);

    Queue::pushed(ReconcileDeviceCommand::class)->first()->handle(app(DeviceCommandReconciliationService::class));
    $reconciliation = $request->reconciliations()->sole();
    expect($reconciliation->outcome)->toBe(CommandReconciliationOutcome::Matched)
        ->and($reconciliation->observed_state)->toBe(['availability' => 'online'])
        ->and($reconciliation->observation_reference)->toBe('queclink-frame:'.$pending->fulfilled_raw_frame_id)
        ->and($request->fresh()->status)->toBe(CommandStatus::Reconciled);
});

it('applies an immutable Queclink profile sequentially and verifies protected desired state', function () {
    Queue::fake();
    $fixture = governedQueclinkTrackingFixture('867963069916997');
    $fixture['providerDevice']->forceFill(['model_hint' => 'GL30MEU'])->save();
    bindGovernedQueclinkCommandCredential($fixture);
    $governed = requestGovernedConfigurationApply($fixture);
    $request = $governed['request'];
    $profile = $governed['profile'];

    expect($request->expected_state)->toBe([
        'configuration_profile_uuid' => $profile->uuid,
        'configuration_payload_hash' => $profile->payload_hash,
    ])->and($request->safe_parameter_summary)->toBe([]);
    $attempt = app(CommandDispatchPort::class)->dispatch($request, $fixture['actor']);
    $pending = QueclinkPendingCommand::query()
        ->where('device_command_attempt_id', $attempt->id)
        ->orderBy('governed_sequence')
        ->get();
    expect($attempt->status)->toBe(CommandAttemptStatus::Accepted)
        ->and($pending)->toHaveCount(3)
        ->and($pending->pluck('governed_sequence')->all())->toBe([1, 2, 3])
        ->and($pending->pluck('governed_role')->all())->toBe([
            'configuration_write', 'configuration_write', 'verification',
        ])
        ->and($pending->pluck('command_word')->all())->toBe(['GTSRI', 'GTCFG', 'GTRTO']);

    $router = app(FrameRouter::class);
    $state = new ConnectionState('192.0.2.47:54321');
    $responses = $router->handleInbound(
        '+RESP:GTHBD,970204,867963069916997,GL30MEU,20260802040000,09D4$',
        $state,
    );
    expect($responses)->toHaveCount(2)
        ->and($responses[1])->toStartWith('AT+GTSRI=QueclinkSecret42,')
        ->and($pending[1]->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_QUEUED);

    $responses = $router->handleInbound(
        '+ACK:GTSRI,970204,867963069916997,GL30MEU,GPS,'.$pending[0]->serial_number.',20260802040001,0A12$',
        $state,
    );
    expect($responses)->toHaveCount(2)
        ->and($responses[1])->toStartWith('AT+GTCFG=QueclinkSecret42,')
        ->and($pending[0]->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_ACKED)
        ->and($pending[2]->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_QUEUED);

    $responses = $router->handleInbound(
        '+ACK:GTCFG,970204,867963069916997,GL30MEU,GPS,'.$pending[1]->serial_number.',20260802040002,0A13$',
        $state,
    );
    expect($responses)->toHaveCount(2)
        ->and($responses[1])->toStartWith('AT+GTRTO=QueclinkSecret42,2,,')
        ->and($pending[1]->fresh()->status)->toBe(QueclinkPendingCommand::STATUS_ACKED);

    $router->handleInbound(
        '+ACK:GTRTO,970204,867963069916997,GL30MEU,GPS,'.$pending[2]->serial_number.',20260802040003,0A14$',
        $state,
    );
    $this->travel(1)->seconds();
    $router->handleInbound(
        '+RESP:GTALM,970204,867963069916997,GL30MEU,1,1,SRI,3,0,1,tracking.example.test,8090,,0,,5,1,0,30,0,CFG,,GL30MEU,150,08E3,006F,1,60,,0,1200,,1,,,,1,1,0000,,,20,1,,1,2,1,0,20260802040004,0A15$',
        $state,
    );

    $verification = $pending[2]->fresh();
    expect($verification->fulfilled_raw_frame_id)->not->toBeNull()
        ->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($request->fresh()->status)->toBe(CommandStatus::Reconciling);
    Queue::assertPushed(ReconcileDeviceCommand::class, fn (ReconcileDeviceCommand $job): bool => $job->commandId === $request->id);

    Queue::pushed(ReconcileDeviceCommand::class)->first()->handle(app(DeviceCommandReconciliationService::class));
    $reconciliation = $request->reconciliations()->sole();
    expect($reconciliation->outcome)->toBe(CommandReconciliationOutcome::Matched)
        ->and($reconciliation->observed_state)->toHaveCount(2)
        ->and($reconciliation->observed_state)->toMatchArray($request->expected_state)
        ->and($request->fresh()->status)->toBe(CommandStatus::Reconciled)
        ->and((string) DB::table('device_configuration_profiles')->where('id', $profile->id)->value('encrypted_payload'))
        ->not->toContain('tracking.example.test')
        ->and((string) DB::table('queclink_pending_commands')->where('id', $pending[0]->id)->value('raw_command'))
        ->not->toContain('tracking.example.test');
});

it('blocks an approved configuration request when its immutable profile is retired before dispatch', function () {
    $fixture = governedQueclinkTrackingFixture('867963069916996');
    $fixture['providerDevice']->forceFill(['model_hint' => 'GL30MEU'])->save();
    bindGovernedQueclinkCommandCredential($fixture);
    $governed = requestGovernedConfigurationApply($fixture);
    $request = $governed['request'];
    $governed['profile']->retire();

    expect(fn () => app(CommandDispatchPort::class)->dispatch($request->fresh(), $fixture['actor']))
        ->toThrow(ValidationException::class, 'parameter policy changed')
        ->and($request->fresh()->status)->toBe(CommandStatus::Blocked)
        ->and($request->fresh()->blocked_reason_code)->toBe('parameter_policy_changed')
        ->and($request->attempts()->count())->toBe(0)
        ->and(QueclinkPendingCommand::query()->count())->toBe(0);
});

it('serialises native tracker commands so one observation cannot fulfil competing requests', function () {
    $fixture = governedQueclinkTrackingFixture('864696060004177');
    bindGovernedQueclinkCommandCredential($fixture);

    $location = requestGovernedLocationRefresh($fixture);
    $locationAttempt = app(CommandDispatchPort::class)->dispatch($location, $fixture['actor']);
    $configuration = requestGovernedConfigurationRefresh($fixture);
    $configurationAttempt = app(CommandDispatchPort::class)->dispatch($configuration, $fixture['actor']);

    expect($locationAttempt->status)->toBe(CommandAttemptStatus::Accepted)
        ->and($configurationAttempt->status)->toBe(CommandAttemptStatus::Failed)
        ->and($configurationAttempt->safe_failure_reason)
        ->toBe('Another tracker command is still active. Wait for its governed result before trying again.')
        ->and($configuration->fresh()->status)->toBe(CommandStatus::Failed)
        ->and(QueclinkPendingCommand::query()->count())->toBe(1)
        ->and(QueclinkPendingCommand::query()->sole()->device_command_request_id)->toBe($location->id);
});

it('fails closed when the paired Queclink identity does not match the canonical Device', function () {
    $fixture = governedQueclinkTrackingFixture('864696060004175');
    bindGovernedQueclinkCommandCredential($fixture);
    $fixture['device']->forceFill(['imei' => '864696060009999', 'device_uid' => '864696060009999'])->save();

    expect(fn () => requestGovernedLocationRefresh($fixture))
        ->toThrow(ValidationException::class, 'does not declare support')
        ->and(QueclinkPendingCommand::query()->count())->toBe(0);
});

it('does not advertise native Queclink execution without a current governed command credential', function () {
    $fixture = governedQueclinkTrackingFixture('864696060004176');

    expect(app(CommandExecutionAdapterRegistry::class)
        ->supports($fixture['device'], 'tracking.location_refresh'))->toBeFalse()
        ->and(CredentialReference::query()->count())->toBe(0)
        ->and(QueclinkPendingCommand::query()->count())->toBe(0);
});
