<?php

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointActionPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointCommandSigner;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointHeartbeat;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCommands;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Exceptions\CommandDispatchPreconditionException;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function serviceActionGrant(User $actor, string $key, bool $allowed = true): void
{
    $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'module' => 'securityDevices']);
    $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
}
function serviceActionInput(string $key, string $operation = 'restart'): CommandRequestInput
{
    return new CommandRequestInput('endpoint.service.manage', ['service_name' => 'Spooler', 'operation' => $operation], 'Restore the reviewed print service for this computer.', $key, CarbonImmutable::now('UTC')->startOfSecond(), impactAcknowledged: true);
}
function serviceActionDispatch($test): array
{
    $input = isset($test->serviceExportPurpose) ? new CommandRequestInput('endpoint.service.manage', ['service_name' => 'Spooler', 'operation' => 'restart'], $test->serviceExportPurpose, (string) Str::uuid(), CarbonImmutable::now('UTC')->startOfSecond(), impactAcknowledged: true) : serviceActionInput((string) Str::uuid());
    $request = app(DeviceCommandRequestService::class)->request($test->serviceDevice, $test->serviceActor, $input);
    expect($request->status)->toBe(CommandStatus::AwaitingApproval);
    app(DeviceCommandApprovalService::class)->decide($request, $test->serviceReviewer, new CommandDecisionInput(CommandApprovalDecision::Approved, 'Service and impact independently reviewed.'));
    $attempt = app(GovernedCommandDispatchService::class)->dispatch($request->fresh(), $test->serviceActor);

    return [$request->fresh(), $attempt, EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->firstOrFail()];
}
function serviceActionClaim($test, $request, $attempt, $delivery): array
{
    return ['command_uuid' => $request->command_uuid, 'payload_hash' => $delivery->payload_hash, 'consent' => ['desktop_session' => '11111111-1111-4111-8111-111111111111', 'consent_nonce' => str_repeat('c', 64)]];
}
function serviceActionResult($request, $delivery): array
{
    return ['command_uuid' => $request->command_uuid, 'payload_hash' => $delivery->payload_hash, 'status' => 'succeeded', 'operation' => 'restart', 'service_name' => 'Spooler', 'before_state' => 'running', 'after_state' => 'running', 'mutation_started' => true, 'reason_code' => 'confirmed'];
}

function serviceActionObserve($test, $request, $attempt, string $state = 'running', bool $include = true): array
{
    $payload = [
        'sequence' => $test->serviceAgent->fresh()->sequence + 1, 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'agent_version' => '1.0.0', 'boot_id' => '22222222-2222-4222-8222-222222222222',
        'service_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_scm', 'operations' => ['start', 'stop', 'restart']],
        'service_action_observations' => $include ? [['command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'service_name' => 'Spooler', 'state' => $state, 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')]] : [],
        'inventory' => ['hostname' => 'isolated-computer', 'os_version' => 'Windows 11', 'architecture' => 'amd64', 'memory_total_bytes' => 8192, 'memory_available_bytes' => 4096, 'uptime_seconds' => 120, 'cpu_percent' => null, 'disks' => [], 'software' => [], 'software_complete' => true, 'services' => [], 'services_complete' => true, 'probe_errors' => []],
    ];
    $ack = app(EndpointHeartbeat::class)->record($test->serviceCredential, $payload);
    $test->serviceHeartbeatPayload = $payload;

    return $ack;
}

beforeEach(function () {
    $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
    Queue::fake();
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.actions.release_enabled', true);
    config()->set('endpoint_management.server_url', 'https://localhost');
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Print service', 'operations' => ['start', 'stop', 'restart']]]);
    config()->set('endpoint_management.command_signing_secret', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('s', 32)))));
    config()->set('monitoring.signing.active_key_id', 'isolated-service-test');
    config()->set('monitoring.signing.keys', ['isolated-service-test' => base64_encode(str_repeat('S', SODIUM_CRYPTO_AUTH_KEYBYTES))]);
    $this->serviceActor = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
    $this->serviceReviewer = User::factory()->create(['approved_at' => now()]);
    $this->serviceSite = ensureCanonicalHrStaffProfile($this->serviceActor);
    ensureCanonicalHrStaffProfile($this->serviceReviewer, $this->serviceSite);
    foreach ([$this->serviceActor, $this->serviceReviewer] as $actor) {
        $actor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        foreach (['it.view', 'it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.commands.observe', 'securityDevices.commands.control', 'securityDevices.commands.approve', EndpointActionPolicy::SERVICE, EndpointActionPolicy::MANAGE] as $key) {
            serviceActionGrant($actor, $key);
        }
    }
    $this->serviceDevice = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active', 'last_seen_at' => now()]);
    DeviceAssignment::query()->create(['device_id' => $this->serviceDevice->id, 'assignable_type' => 'site', 'assignable_id' => $this->serviceSite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
    $this->serviceCredential = str_repeat('e', 64);
    $this->serviceAgent = EndpointAgent::query()->create(['uuid' => (string) Str::uuid(), 'device_id' => $this->serviceDevice->id, 'site_id' => $this->serviceSite->id, 'platform' => 'windows', 'created_by_user_id' => $this->serviceActor->id,
        'assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($this->serviceDevice), 'credential_hash' => hash('sha256', $this->serviceCredential), 'enrolled_at' => now(), 'last_seen_at' => now(), 'observed_at' => now(), 'sequence' => 1, 'heartbeat_hash' => str_repeat('b', 64),
        'service_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_scm', 'operations' => ['start', 'stop', 'restart']], 'inventory' => ['services' => [['name' => 'Spooler', 'state' => 'running']]]]);
    DB::table('endpoint_action_policies')->insert(['device_id' => $this->serviceDevice->id, 'version' => 1, 'enabled' => true, 'consent_mode' => 'attended', 'updated_by_user_id' => $this->serviceActor->id, 'created_at' => now(), 'updated_at' => now()]);
    URL::forceScheme('https');
    $this->withServerVariables(['HTTPS' => 'on']);
    $this->actingAs($this->serviceActor);
    $this->serviceCommands = app(EndpointServiceCommands::class);
});

it('reviews policy without promising release readiness and rejects stale catalogue actor and unattended saves', function () {
    config()->set('endpoint_management.actions.release_enabled', false);
    $path = '/it/remote-support/devices/'.$this->serviceDevice->id.'/action-policy';
    $before = $this->getJson($path.'?expected_actor_id='.$this->serviceActor->id)->assertOk()->assertJsonPath('available', false)->json();
    $save = ['expected_actor_id' => $this->serviceActor->id, 'expected_version' => 1, 'expected_catalogue_digest' => $before['catalogue_digest'], 'enabled' => false, 'consent_mode' => 'attended', 'reason' => 'Pause service operations while reviewed.'];
    $after = $this->putJson($path, $save)->assertOk()->assertJsonPath('policy.version', 2)->json();
    $this->putJson($path, $save)->assertConflict();
    $this->putJson($path, [...$save, 'expected_version' => 2, 'expected_catalogue_digest' => str_repeat('a', 64)])->assertConflict();
    $this->putJson($path, [...$save, 'expected_version' => 2, 'enabled' => true, 'consent_mode' => 'unattended'])->assertForbidden();
    $this->putJson($path, [...$save, 'expected_version' => 2, 'consent_mode' => 'unattended'])->assertOk();
    $this->getJson($path.'?expected_actor_id='.$this->serviceReviewer->id)->assertConflict();
    if ($export = getenv('OBLI_ACTION_EXPORT')) {
        file_put_contents($export.'.policy', json_encode(['get' => $before, 'put' => $after], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
});

it('uses the canonical request approval attempt and exact signed short claim with immutable retries', function () {
    $this->serviceExportPurpose = str_repeat('😀', 498).'<> ';
    $this->serviceExportPurpose = trim($this->serviceExportPurpose);
    $this->serviceActor->forceFill(['name' => str_repeat('🛠', 200)])->save();
    [$request, $attempt, $delivery] = serviceActionDispatch($this);
    expect($attempt->runtime)->toBe('endpoint')->and($attempt->status)->toBe(CommandAttemptStatus::Dispatching);
    $poll = $this->withToken($this->serviceCredential)->getJson('/api/endpoints/agent/commands')->assertOk()->json();
    $envelope = $poll['command'];
    $raw = base64_decode($envelope['payload'], true);
    $command = json_decode($raw, true);
    expect(sodium_crypto_sign_verify_detached(base64_decode($envelope['signature']), $raw, base64_decode(app(EndpointCommandSigner::class)->publicKey())))->toBeTrue();
    expect(strlen($raw))->toBeLessThanOrEqual(16384);
    expect($command['purpose'])->toBe($this->serviceExportPurpose)->and(mb_strlen($command['technician']))->toBe(200);
    expect($command['type'])->toBe('endpoint_service_command')->and($command['policy']['catalogue_digest'])->toBe(app(EndpointServiceCatalogue::class)->digest())->and(hash('sha256', $raw))->toBe($delivery->payload_hash);
    $input = serviceActionClaim($this, $request, $attempt, $delivery);
    $claim = $this->withToken($this->serviceCredential)->postJson('/api/endpoints/agent/commands/'.$attempt->attempt_uuid.'/claim', $input)->assertOk()->json();
    expect($this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, $input))->toBe($claim)->and(EndpointDelivery::query()->count())->toBe(1);
    $lease = json_decode(base64_decode($claim['payload']), true);
    expect(CarbonImmutable::parse($lease['issued_at'])->diffInSeconds(CarbonImmutable::parse($lease['expires_at'])))->toBeLessThanOrEqual(30);
    if ($export = getenv('OBLI_ACTION_EXPORT')) {
        file_put_contents($export, json_encode(['policy_reply' => app(EndpointActionPolicy::class)->show($this->serviceActor, $this->serviceDevice, ['expected_actor_id' => $this->serviceActor->id]), 'command' => $envelope, 'claim' => $claim, 'public_key' => app(EndpointCommandSigner::class)->publicKey(), 'agent_uuid' => $this->serviceAgent->uuid, 'assignment_fingerprint' => $this->serviceAgent->assignment_fingerprint, 'payload_hash' => $delivery->payload_hash, 'claim_request' => $input, 'result' => serviceActionResult($request, $delivery)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
    $result = serviceActionResult($request, $delivery);
    $this->serviceCommands->result($this->serviceCredential, $attempt->attempt_uuid, $result);
    $this->travel(3)->seconds();
    $heartbeatAck = serviceActionObserve($this, $request, $attempt);
    expect($heartbeatAck['service_action_observations_accepted'])->toHaveCount(1);
    if ($export = getenv('OBLI_ACTION_EXPORT')) {
        $fixture = json_decode(file_get_contents($export), true, 512, JSON_THROW_ON_ERROR);
        $fixture['heartbeat_payload'] = $this->serviceHeartbeatPayload;
        $fixture['heartbeat_ack'] = $heartbeatAck;
        file_put_contents($export, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
    $this->travel(31)->seconds();
    expect(fn () => $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, $input))->toThrow(HttpException::class);
});

it('never creates a second canonical request for immutable replay and rejects changed intent or revoked authority', function () {
    $input = serviceActionInput((string) Str::uuid());
    $service = app(DeviceCommandRequestService::class);
    $first = $service->request($this->serviceDevice, $this->serviceActor, $input);
    expect($service->request($this->serviceDevice, $this->serviceActor, $input)->id)->toBe($first->id);
    expect(fn () => $service->request($this->serviceDevice, $this->serviceActor, serviceActionInput($input->idempotencyKey, 'stop')))->toThrow(ValidationException::class);
    serviceActionGrant($this->serviceActor, EndpointActionPolicy::SERVICE, false);
    expect(fn () => $service->request($this->serviceDevice, $this->serviceActor->fresh(), $input))->toThrow(HttpException::class);
    expect(DeviceCommandRequest::query()->count())->toBe(1);
});

it('denies claims after policy catalogue installed capability reviewer or incarnation withdrawal', function () {
    [$request, $attempt, $delivery] = serviceActionDispatch($this);
    $input = serviceActionClaim($this, $request, $attempt, $delivery);
    DB::table('endpoint_action_policies')->where('device_id', $this->serviceDevice->id)->update(['version' => 2]);
    expect(fn () => $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, $input))->toThrow(CommandDispatchPreconditionException::class);
    DB::table('endpoint_action_policies')->where('device_id', $this->serviceDevice->id)->update(['version' => 1]);
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Changed review', 'operations' => ['start', 'stop', 'restart']]]);
    expect(fn () => $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, $input))->toThrow(CommandDispatchPreconditionException::class);
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Print service', 'operations' => ['start', 'stop', 'restart']]]);
    $this->serviceAgent->forceFill(['service_actions' => null])->save();
    expect(fn () => $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, $input))->toThrow(CommandDispatchPreconditionException::class);
    $this->serviceAgent->forceFill(['service_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_scm', 'operations' => ['start', 'stop', 'restart']]])->save();
    serviceActionGrant($this->serviceReviewer, 'securityDevices.commands.approve', false);
    expect(fn () => $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, $input))->toThrow(CommandDispatchPreconditionException::class);
    expect($delivery->fresh()->claimed_at)->toBeNull();
});

it('accepts immutable late results without refreshing claims and verifies fresh state independently', function () {
    [$request, $attempt, $delivery] = serviceActionDispatch($this);
    $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, serviceActionClaim($this, $request, $attempt, $delivery));
    $this->travel(301)->seconds();
    $result = serviceActionResult($request, $delivery);
    $ack = $this->serviceCommands->result($this->serviceCredential, $attempt->attempt_uuid, $result);
    expect($ack['accepted'])->toBeTrue()->and($this->serviceCommands->result($this->serviceCredential, $attempt->attempt_uuid, $result))->toBe($ack)->and($request->fresh()->status)->toBe(CommandStatus::Reconciling);
    expect(fn () => $this->serviceCommands->result($this->serviceCredential, $attempt->attempt_uuid, [...$result, 'before_state' => 'stopped']))->toThrow(HttpException::class);
    app(DeviceCommandReconciliationService::class)->reconcile($request->fresh());
    expect($request->fresh()->status)->toBe(CommandStatus::Uncertain);
    $this->travel(1)->seconds();
    $unknown = serviceActionObserve($this, $request, $attempt, 'unknown');
    expect($unknown['service_action_observations_accepted'])->toBe([]);
    $this->travel(1)->seconds();
    $known = serviceActionObserve($this, $request, $attempt);
    expect($known['service_action_observations_accepted'])->toHaveCount(1);
    $this->travel(1)->seconds();
    serviceActionObserve($this, $request, $attempt, 'running', false);
    expect($this->serviceAgent->fresh()->service_action_observations)->toBe([])->and($delivery->fresh()->service_readback['state'])->toBe('running');
    app(DeviceCommandReconciliationService::class)->reconcile($request->fresh());
    expect($request->fresh()->status)->toBe(CommandStatus::Reconciled);
});

it('records unclaimed local declines but never accepts unclaimed mutation or stale identity', function () {
    [$request, $attempt, $delivery] = serviceActionDispatch($this);
    $result = [...serviceActionResult($request, $delivery), 'status' => 'declined', 'mutation_started' => false, 'reason_code' => 'declined'];
    expect(fn () => $this->serviceCommands->result($this->serviceCredential, $attempt->attempt_uuid, serviceActionResult($request, $delivery)))->toThrow(HttpException::class);
    expect($this->serviceCommands->result($this->serviceCredential, $attempt->attempt_uuid, $result)['accepted'])->toBeTrue()->and($request->fresh()->status)->toBe(CommandStatus::Failed);
    $this->serviceAgent->forceFill(['uuid' => (string) Str::uuid()])->save();
    expect(fn () => $this->serviceCommands->result($this->serviceCredential, $attempt->attempt_uuid, $result))->toThrow(ModelNotFoundException::class);
});

it('expires unclaimed attempts in bounded batches and preserves claimed timeout evidence for late result recovery', function () {
    [$request, $attempt, $delivery] = serviceActionDispatch($this);
    $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, serviceActionClaim($this, $request, $attempt, $delivery));
    $this->travel(151)->seconds();
    expect($this->serviceCommands->expire(1))->toBe(1)->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Uncertain)->and($this->serviceCommands->expire(1))->toBe(0);
    $this->serviceCommands->result($this->serviceCredential, $attempt->attempt_uuid, serviceActionResult($request, $delivery));
    expect($attempt->fresh()->status)->toBe(CommandAttemptStatus::Uncertain)->and($delivery->fresh()->result['status'])->toBe('succeeded');
    $this->travel(1)->seconds();
    $unknown = serviceActionObserve($this, $request, $attempt, 'unknown');
    expect($unknown['service_action_observations_accepted'])->toBe([]);
    $this->travel(1)->seconds();
    $known = serviceActionObserve($this, $request, $attempt);
    expect($known['service_action_observations_accepted'])->toHaveCount(1);
    $this->travel(1)->seconds();
    serviceActionObserve($this, $request, $attempt, 'running', false);
    expect($this->serviceAgent->fresh()->service_action_observations)->toBe([])->and($delivery->fresh()->service_readback['state'])->toBe('running');
    app(DeviceCommandReconciliationService::class)->reconcile($request->fresh());
    expect($request->fresh()->status)->toBe(CommandStatus::Reconciled);
});

it('does not prove an uncertain restart from running state and rejects observations for another attempt or service', function () {
    [$request, $attempt, $delivery] = serviceActionDispatch($this);
    $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, serviceActionClaim($this, $request, $attempt, $delivery));
    $this->serviceCommands->result($this->serviceCredential, $attempt->attempt_uuid, [...serviceActionResult($request, $delivery), 'status' => 'uncertain', 'reason_code' => 'interrupted']);
    $this->travel(1)->seconds();
    serviceActionObserve($this, $request, $attempt);
    app(DeviceCommandReconciliationService::class)->reconcile($request->fresh());
    expect($request->fresh()->status)->toBe(CommandStatus::Uncertain);
    expect(fn () => $this->serviceCommands->observations($this->serviceAgent->fresh(), [['command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'service_name' => 'DifferentService', 'state' => 'running', 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')]], CarbonImmutable::now()))->toThrow(HttpException::class);
});

it('requires installed capability and separate release policy even when a device forges its configured declaration', function () {
    $this->serviceDevice->forceFill(['config' => ['management' => ['capabilities' => ['endpoint.service.manage']]]])->save();
    $this->serviceAgent->forceFill(['service_actions' => null])->save();
    expect(app(DeclaredDeviceCommandCapabilities::class)->supports($this->serviceDevice, 'endpoint.service.manage'))->toBeFalse();
    expect(fn () => app(DeviceCommandRequestService::class)->request($this->serviceDevice, $this->serviceActor, serviceActionInput((string) Str::uuid())))->toThrow(ValidationException::class);
    config()->set('endpoint_management.actions.release_enabled', false);
    expect(app(EndpointServiceAuthority::class)->supports($this->serviceDevice))->toBeFalse();
});

it('records nonmutating uncertainty before claim and expires a dispatched unclaimed attempt without retry', function () {
    [$request, $attempt, $delivery] = serviceActionDispatch($this);
    $this->serviceCommands->result($this->serviceCredential, $attempt->attempt_uuid, [...serviceActionResult($request, $delivery), 'status' => 'uncertain', 'mutation_started' => false, 'reason_code' => 'interrupted']);
    expect($request->fresh()->status)->toBe(CommandStatus::Uncertain)->and($delivery->fresh()->claimed_at)->toBeNull();
    [$second, $secondAttempt] = serviceActionDispatch($this);
    $this->travel(301)->seconds();
    expect($this->serviceCommands->expire(1))->toBe(1)->and($secondAttempt->fresh()->status)->toBe(CommandAttemptStatus::Expired)->and($second->fresh()->status)->toBe(CommandStatus::Expired)->and($this->serviceCommands->expire(1))->toBe(0);
});
it('rejects oversized consent purpose and requires actual local consent evidence only in attended mode', function () {
    $oversized = new CommandRequestInput('endpoint.service.manage', ['service_name' => 'Spooler', 'operation' => 'restart'], str_repeat('x', 501), (string) Str::uuid(), CarbonImmutable::now(), impactAcknowledged: true);
    expect(fn () => app(DeviceCommandRequestService::class)->request($this->serviceDevice, $this->serviceActor, $oversized))->toThrow(ValidationException::class);
    [$request, $attempt, $delivery] = serviceActionDispatch($this);
    $base = ['command_uuid' => $request->command_uuid, 'payload_hash' => $delivery->payload_hash];
    expect(fn () => $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, $base))->toThrow(HttpException::class);
    expect(fn () => $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, [...$base, 'consent' => ['desktop_session' => '1', 'consent_nonce' => str_repeat('c', 64)]]))->toThrow(ValidationException::class);
    serviceActionGrant($this->serviceActor, EndpointActionPolicy::UNATTENDED);
    DB::table('endpoint_action_policies')->where('device_id', $this->serviceDevice->id)->update(['version' => 2, 'consent_mode' => 'unattended']);
    [$unattended, $secondAttempt, $secondDelivery] = serviceActionDispatch($this);
    $claim = $this->serviceCommands->claim($this->serviceCredential, $secondAttempt->attempt_uuid, ['command_uuid' => $unattended->command_uuid, 'payload_hash' => $secondDelivery->payload_hash]);
    $payload = json_decode(base64_decode($claim['payload']), true);
    expect($payload['desktop_session'])->toBeNull()->and($payload['consent_nonce'])->toBeNull()->and($payload['consent_mode'])->toBe('unattended');
});

it('conceals loss of approved Site evidence before final claim and rejects old incarnation observations', function () {
    [$request, $attempt, $delivery] = serviceActionDispatch($this);
    $input = serviceActionClaim($this, $request, $attempt, $delivery);
    $this->serviceActor->hrEmployeeProfile()->update(['is_active' => false]);
    expect(fn () => $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, $input))->toThrow(CommandDispatchPreconditionException::class);
    expect($delivery->fresh()->claimed_at)->toBeNull();
    $this->serviceActor->hrEmployeeProfile()->update(['is_active' => true]);
    $this->serviceCommands->claim($this->serviceCredential, $attempt->attempt_uuid, $input);
    $this->serviceCommands->result($this->serviceCredential, $attempt->attempt_uuid, serviceActionResult($request, $delivery));
    $this->travel(1)->seconds();
    serviceActionObserve($this, $request, $attempt);
    $this->serviceAgent->forceFill(['uuid' => (string) Str::uuid()])->save();
    app(DeviceCommandReconciliationService::class)->reconcile($request->fresh());
    expect($request->fresh()->status)->toBe(CommandStatus::Uncertain);
});

it('binds the browser native request to the exact reviewed actor before create or replay', function () {
    $payload = ['expected_actor_id' => $this->serviceReviewer->id, 'capability' => 'endpoint.service.manage', 'parameters' => ['service_name' => 'Spooler', 'operation' => 'restart'], 'reason' => 'Restore the reviewed print service.', 'idempotency_key' => (string) Str::uuid(), 'impact_acknowledged' => true];
    $path = '/security-devices/devices/'.$this->serviceDevice->id.'/commands';
    $this->withSession(['auth.password_confirmed_at' => time()])->postJson($path, $payload)->assertConflict();
    unset($payload['expected_actor_id']);
    $this->postJson($path, $payload)->assertUnprocessable();
    expect(DeviceCommandRequest::query()->count())->toBe(0);
});
