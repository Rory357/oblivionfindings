<?php

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointActionPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointCommandSigner;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointHeartbeat;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointMutationGuard;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartCommands;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCommands;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Exceptions\CommandDispatchPreconditionException;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandQueueService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Presenters\DeviceProfilePresenter;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function restartActionGrant(User $actor, string $key, bool $allowed = true): void
{
    $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'module' => 'securityDevices']);
    $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
}
function restartInput(string $key, ?string $execute = null): CommandRequestInput
{
    return new CommandRequestInput('endpoint.computer.restart', ['execute_at' => $execute ?? now()->utc()->addSeconds(120)->format('Y-m-d\TH:i:s\Z'), 'warning_seconds' => 60], 'Restart this reviewed computer after the visible warning.', $key, CarbonImmutable::now('UTC')->startOfSecond(), impactAcknowledged: true);
}
function restartDispatch($test, ?string $execute = null): array
{
    $request = app(DeviceCommandRequestService::class)->request($test->restartDevice, $test->restartActor, restartInput((string) Str::uuid(), $execute));
    expect($request->status)->toBe(CommandStatus::AwaitingApproval);
    app(DeviceCommandApprovalService::class)->decide($request, $test->restartReviewer, new CommandDecisionInput(CommandApprovalDecision::Approved, 'Restart deadline and impact independently reviewed.'));
    $attempt = app(GovernedCommandDispatchService::class)->dispatch($request->fresh(), $test->restartActor);

    return [$request->fresh(), $attempt, EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->firstOrFail()];
}
function restartClaimInput($request, $delivery): array
{
    $payload = json_decode(base64_decode($delivery->encrypted_payload['payload']), true);

    return ['command_uuid' => $request->command_uuid, 'payload_hash' => $delivery->payload_hash, 'boot_id' => '22222222-2222-4222-8222-222222222222', 'warning_started_at' => $payload['issued_at'], 'consent' => ['desktop_session' => '11111111-1111-4111-8111-111111111111', 'consent_nonce' => str_repeat('c', 64)]];
}
function restartReceipt($request, $delivery, string $status = 'awaiting_boot', string $reason = 'restart_requested', bool $started = true): array
{
    return ['command_uuid' => $request->command_uuid, 'payload_hash' => $delivery->payload_hash, 'status' => $status, 'mutation_started' => $started, 'reason_code' => $reason, 'boot_id' => '22222222-2222-4222-8222-222222222222'];
}
function restartJump($test, $request, int $offset = 0): void
{
    CarbonImmutable::setTestNow(now()->setTimestamp(CarbonImmutable::parse($request->encrypted_parameters['execute_at'])->timestamp + $offset));
    $test->restartAgent->forceFill(['last_seen_at' => now(), 'observed_at' => now()])->save();
    $test->restartDevice->forceFill(['last_seen_at' => now()])->save();
}
function restartHeartbeat($test, $request, $attempt, string $boot = '33333333-3333-4333-8333-333333333333'): array
{
    return ['sequence' => $test->restartAgent->fresh()->sequence + 1, 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'agent_version' => '1.0.0', 'boot_id' => $boot,
        'restart_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_restart'],
        'restart_observations' => [['command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'before_boot_id' => '22222222-2222-4222-8222-222222222222', 'boot_id' => $boot, 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')]],
        'inventory' => ['hostname' => 'isolated-restart-computer', 'os_version' => 'Windows 11', 'architecture' => 'amd64', 'memory_total_bytes' => 8192, 'memory_available_bytes' => 4096, 'uptime_seconds' => 1, 'cpu_percent' => null, 'disks' => [], 'software' => [], 'software_complete' => true, 'services' => [], 'services_complete' => true, 'probe_errors' => []]];
}
beforeEach(function () {
    $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
    Queue::fake();
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.restart.release_enabled', true);
    config()->set('endpoint_management.server_url', 'https://localhost');
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Print service']]);
    config()->set('endpoint_management.command_signing_secret', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('s', 32)))));
    config()->set('monitoring.signing.active_key_id', 'isolated-service-test');
    config()->set('monitoring.signing.keys', ['isolated-service-test' => base64_encode(str_repeat('S', SODIUM_CRYPTO_AUTH_KEYBYTES))]);
    $this->restartActor = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
    $this->restartReviewer = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
    $this->restartSite = ensureCanonicalHrStaffProfile($this->restartActor);
    ensureCanonicalHrStaffProfile($this->restartReviewer, $this->restartSite);
    foreach ([$this->restartActor, $this->restartReviewer] as $actor) {
        $actor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        foreach (['it.view', 'it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.commands.observe', 'securityDevices.commands.control', 'securityDevices.commands.approve', EndpointRestartPolicy::SERVICE, EndpointRestartPolicy::MANAGE] as $key) {
            restartActionGrant($actor, $key);
        }
    }
    $this->restartDevice = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active', 'last_seen_at' => now()]);
    DeviceAssignment::query()->create(['device_id' => $this->restartDevice->id, 'assignable_type' => 'site', 'assignable_id' => $this->restartSite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
    $this->restartCredential = str_repeat('e', 64);
    $this->restartAgent = EndpointAgent::query()->create(['uuid' => (string) Str::uuid(), 'device_id' => $this->restartDevice->id, 'site_id' => $this->restartSite->id, 'platform' => 'windows', 'created_by_user_id' => $this->restartActor->id,
        'assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($this->restartDevice), 'credential_hash' => hash('sha256', $this->restartCredential), 'enrolled_at' => now(), 'last_seen_at' => now(), 'observed_at' => now(), 'boot_id' => '22222222-2222-4222-8222-222222222222', 'sequence' => 1, 'heartbeat_hash' => str_repeat('b', 64),
        'restart_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_restart'], 'inventory' => ['services' => [['name' => 'Spooler', 'state' => 'running']]]]);
    DB::table('endpoint_restart_policies')->insert(['device_id' => $this->restartDevice->id, 'version' => 1, 'enabled' => true, 'consent_mode' => 'attended', 'minimum_warning_seconds' => 60, 'updated_by_user_id' => $this->restartActor->id, 'created_at' => now(), 'updated_at' => now()]);
    URL::forceScheme('https');
    $this->withServerVariables(['HTTPS' => 'on']);
    $this->actingAs($this->restartActor);
    $this->restartCommands = app(EndpointRestartCommands::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('keeps restart policy separate disabled by release and version checked with current actor and unattended grants', function () {
    config()->set('endpoint_management.restart.release_enabled', false);
    $path = '/security-devices/devices/'.$this->restartDevice->id.'/endpoint/restart-policy';
    $policy = $this->getJson($path.'?expected_actor_id='.$this->restartActor->id)->assertOk()->assertJsonPath('available', false)->assertJsonPath('can_manage', true)->json();
    $save = ['expected_actor_id' => $this->restartActor->id, 'expected_version' => 1, 'enabled' => false, 'consent_mode' => 'attended', 'minimum_warning_seconds' => 120, 'reason' => 'Pause restart scheduling while policy is reviewed.'];
    $updated = $this->putJson($path, $save)->assertOk()->assertJsonPath('policy.version', 2)->assertJsonPath('policy.minimum_warning_seconds', 120)->json();
    $this->putJson($path, $save)->assertConflict();
    $this->putJson($path, [...$save, 'expected_version' => 2, 'enabled' => true, 'consent_mode' => 'unattended'])->assertForbidden();
    $this->getJson($path.'?expected_actor_id='.$this->restartReviewer->id)->assertConflict();
    $this->putJson($path, [...$save, 'minimum_warning_seconds' => '120'])->assertUnprocessable();
    expect(app(DeclaredDeviceCommandCapabilities::class)->forDevice($this->restartDevice))->not->toContain('endpoint.computer.restart');
    if ($export = getenv('OBLI_RESTART_EXPORT')) {
        file_put_contents($export.'.policy', json_encode(['get' => $policy, 'put' => $updated, 'capability' => app(CommandCapabilityRegistry::class)->definition('endpoint.computer.restart')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
});

it('uses one canonical request approval attempt and exact signed schedule final claim and independent boot confirmation', function () {
    [$request,$attempt,$delivery] = restartDispatch($this);
    $command = $this->withToken($this->restartCredential)->getJson('/api/endpoints/agent/restarts')->assertOk()->assertJsonPath('cancellation', null)->json('command');
    $raw = base64_decode($command['payload'], true);
    $schedule = json_decode($raw, true);
    expect(array_keys($schedule))->toBe(['version', 'type', 'agent_uuid', 'command_uuid', 'attempt_uuid', 'assignment_fingerprint', 'capability', 'parameters', 'policy', 'technician', 'purpose', 'issued_at', 'expires_at'])
        ->and($schedule['parameters']['force_close'])->toBeFalse()->and($schedule['type'])->toBe('endpoint_restart_schedule')
        ->and(sodium_crypto_sign_verify_detached(base64_decode($command['signature']), $raw, base64_decode(app(EndpointCommandSigner::class)->publicKey())))->toBeTrue();
    $managementMethod = new ReflectionMethod(DeviceProfilePresenter::class, 'management');
    $managementBefore = $managementMethod->invoke(app(DeviceProfilePresenter::class), $this->restartActor, $this->restartDevice->fresh());
    $claimRequest = restartClaimInput($request, $delivery);
    $path = '/api/endpoints/agent/restarts/'.$attempt->attempt_uuid;
    $this->postJson($path.'/claim', $claimRequest)->assertConflict();
    restartJump($this, $request);
    $claim = $this->postJson($path.'/claim', $claimRequest)->assertOk()->json();
    expect($this->restartCommands->claim($this->restartCredential, $attempt->attempt_uuid, $claimRequest))->toBe($claim);
    $lease = json_decode(base64_decode($claim['payload']), true);
    expect(count($lease))->toBe(16)->and($lease['boot_id'])->toBe($this->restartAgent->boot_id)->and($lease['expires_at'])->toBe($schedule['expires_at']);
    config()->set('endpoint_management.restart.release_enabled', false);
    $result = restartReceipt($request, $delivery);
    $this->postJson($path.'/result', $result)->assertOk();
    $this->postJson($path.'/result', $result)->assertOk();
    expect($request->fresh()->status)->toBe(CommandStatus::Reconciling)->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Running);
    CarbonImmutable::setTestNow(now()->addSecond());
    $sameBoot = restartHeartbeat($this, $request, $attempt, '22222222-2222-4222-8222-222222222222');
    $this->restartCommands->result($this->restartCredential, $attempt->attempt_uuid, $result);
    $sameAck = app(EndpointHeartbeat::class)->record($this->restartCredential, $sameBoot);
    expect($sameAck)->not->toHaveKey('restart_observations_accepted');
    $heartbeat = restartHeartbeat($this, $request, $attempt);
    $ack = app(EndpointHeartbeat::class)->record($this->restartCredential, $heartbeat);
    expect($ack['restart_observations_accepted'][0]['status'])->toBe('succeeded');
    $reconciliation = app(DeviceCommandReconciliationService::class)->reconcile($request->fresh());
    expect($reconciliation->outcome->value)->toBe('matched')->and($request->fresh()->status)->toBe(CommandStatus::Reconciled);
    if ($export = getenv('OBLI_RESTART_EXPORT')) {
        file_put_contents($export, json_encode(['command' => $command, 'claim' => $claim, 'public_key' => app(EndpointCommandSigner::class)->publicKey(), 'agent_uuid' => $this->restartAgent->uuid, 'payload_hash' => $delivery->payload_hash, 'claim_request' => $claimRequest, 'result' => $result, 'heartbeat_payload' => $heartbeat, 'heartbeat_ack' => $ack, 'projection' => $this->restartCommands->projection($request->fresh(), $this->restartActor, $attempt->fresh()), 'header' => (new ReflectionMethod(DeviceProfilePresenter::class, 'header'))->invoke(app(DeviceProfilePresenter::class), $this->restartActor, $this->restartDevice->fresh(), DeviceAssignment::query()->where('device_id', $this->restartDevice->id)->whereNull('released_at')->first(), collect(), $this->restartActor->canDo('securityDevices.events.view'), $this->restartActor->canDo('securityDevices.maintenance.view')), 'management_before' => $managementBefore, 'management_confirmed' => $managementMethod->invoke(app(DeviceProfilePresenter::class), $this->restartActor, $this->restartDevice->fresh())], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
});

it('retains step up only for an already dispatched signed future restart and still rechecks present approval and identity', function () {
    [$request,$attempt,$delivery] = restartDispatch($this, now()->utc()->addHours(2)->format('Y-m-d\TH:i:s\Z'));
    $input = restartClaimInput($request, $delivery);
    restartJump($this, $request);
    expect($this->restartCommands->poll($this->restartCredential)['command'])->not->toBeNull();
    $claim = $this->restartCommands->claim($this->restartCredential, $attempt->attempt_uuid, $input);
    expect($claim)->toHaveKeys(['payload', 'signature']);
    $this->restartReviewer->forceFill(['two_factor_confirmed_at' => null])->save();
    expect(fn () => $this->restartCommands->claim($this->restartCredential, $attempt->attempt_uuid, $input))->toThrow(CommandDispatchPreconditionException::class);
});

it('does not extend recent step up at approval queue or dispatch and rejects altered unsigned future schedule', function () {
    $request = app(DeviceCommandRequestService::class)->request($this->restartDevice, $this->restartActor, restartInput((string) Str::uuid(), now()->utc()->addHours(2)->format('Y-m-d\TH:i:s\Z')));
    app(DeviceCommandApprovalService::class)->decide($request, $this->restartReviewer, new CommandDecisionInput(CommandApprovalDecision::Approved, 'Restart deadline independently reviewed.'));
    CarbonImmutable::setTestNow(now()->addMinutes(16));
    $this->restartAgent->forceFill(['last_seen_at' => now(), 'observed_at' => now()])->save();
    $this->restartDevice->forceFill(['last_seen_at' => now()])->save();
    expect(fn () => app(DeviceCommandQueueService::class)->queue($request->fresh(), $this->restartActor))->toThrow(ValidationException::class);
    expect($request->fresh()->status)->toBe(CommandStatus::Blocked);
});

it('leaves a delivered cancellation pending until exact durable no API acknowledgement and denies the final claim', function () {
    [$request,$attempt,$delivery] = restartDispatch($this);
    $command = $this->restartCommands->poll($this->restartCredential)['command'];
    $this->restartCommands->cancel($this->restartActor, $this->restartDevice, $request, ['expected_actor_id' => $this->restartActor->id, 'reason' => 'Postpone this restart until active work is saved.']);
    expect($request->fresh()->status)->toBe(CommandStatus::Dispatching)->and($this->restartCommands->projection($request->fresh(), $this->restartActor, $attempt)['state'])->toBe('cancel_requested');
    $poll = $this->restartCommands->poll($this->restartCredential);
    expect($poll['command'])->toBeNull()->and(json_decode(base64_decode($poll['cancellation']['payload']), true)['type'])->toBe('endpoint_restart_cancel');
    restartJump($this, $request);
    expect(fn () => $this->restartCommands->claim($this->restartCredential, $attempt->attempt_uuid, restartClaimInput($request, $delivery)))->toThrow(HttpException::class);
    $result = restartReceipt($request, $delivery, 'cancelled', 'cancelled', false);
    $resultAck = $this->restartCommands->result($this->restartCredential, $attempt->attempt_uuid, $result);
    expect($request->fresh()->status)->toBe(CommandStatus::Cancelled)->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Cancelled);
    if ($export = getenv('OBLI_RESTART_EXPORT')) {
        file_put_contents($export.'.cancel', json_encode(['command' => $command, 'public_key' => app(EndpointCommandSigner::class)->publicKey(), 'agent_uuid' => $this->restartAgent->uuid, 'payload_hash' => $delivery->payload_hash, 'boot_id' => $this->restartAgent->boot_id, 'result' => $result, 'result_ack' => $resultAck, 'cancellation' => $poll['cancellation'], 'projection' => $this->restartCommands->projection($request->fresh(), $this->restartActor, $attempt->fresh())], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
});

it('cancels before delivery without native acknowledgement and rejects false cancellation after API entry', function () {
    [$request,$attempt,$delivery] = restartDispatch($this);
    $this->restartCommands->cancel($this->restartActor, $this->restartDevice, $request, ['expected_actor_id' => $this->restartActor->id, 'reason' => 'Cancel the restart before it has reached this computer.']);
    expect($request->fresh()->status)->toBe(CommandStatus::Cancelled)->and($this->restartCommands->poll($this->restartCredential)['command'])->toBeNull();
    [$request,$attempt,$delivery] = restartDispatch($this);
    restartJump($this, $request);
    $this->restartCommands->claim($this->restartCredential, $attempt->attempt_uuid, restartClaimInput($request, $delivery));
    $this->restartCommands->result($this->restartCredential, $attempt->attempt_uuid, restartReceipt($request, $delivery));
    expect(fn () => $this->restartCommands->cancel($this->restartActor, $this->restartDevice, $request, ['expected_actor_id' => $this->restartActor->id, 'reason' => 'Attempt cancellation after the Windows API was entered.']))->toThrow(HttpException::class);
    expect(fn () => $this->restartCommands->result($this->restartCredential, $attempt->attempt_uuid, restartReceipt($request, $delivery, 'cancelled', 'cancelled', false)))->toThrow(HttpException::class);
});

it('never upgrades crash uncertainty or an unrelated changed boot into restart success', function () {
    [$request,$attempt,$delivery] = restartDispatch($this);
    restartJump($this, $request);
    $this->restartCommands->claim($this->restartCredential, $attempt->attempt_uuid, restartClaimInput($request, $delivery));
    $this->restartCommands->result($this->restartCredential, $attempt->attempt_uuid, restartReceipt($request, $delivery, 'uncertain', 'interrupted', true));
    CarbonImmutable::setTestNow(now()->addSecond());
    $ack = app(EndpointHeartbeat::class)->record($this->restartCredential, restartHeartbeat($this, $request, $attempt));
    expect($ack['restart_observations_accepted'][0]['status'])->toBe('uncertain');
    expect(app(DeviceCommandReconciliationService::class)->reconcile($request->fresh())->outcome->value)->toBe('uncertain')->and($request->fresh()->status)->toBe(CommandStatus::Uncertain);
    expect(fn () => app(GovernedCommandDispatchService::class)->dispatch($request->fresh(), $this->restartActor))->toThrow(ValidationException::class);
});

it('expires unclaimed offline schedules at the absolute deadline and leaves claimed or unobserved boot attempts uncertain', function () {
    [$request,$attempt,$delivery] = restartDispatch($this);
    restartJump($this, $request, 6);
    expect($this->restartCommands->expire())->toBe(1)->and($request->fresh()->status)->toBe(CommandStatus::Expired);
    expect(fn () => $this->restartCommands->claim($this->restartCredential, $attempt->attempt_uuid, restartClaimInput($request, $delivery)))->toThrow(HttpException::class);
    [$request,$attempt,$delivery] = restartDispatch($this);
    restartJump($this, $request);
    $this->restartCommands->claim($this->restartCredential, $attempt->attempt_uuid, restartClaimInput($request, $delivery));
    CarbonImmutable::setTestNow(now()->addSeconds(6));
    expect($this->restartCommands->expire())->toBe(0);
    CarbonImmutable::setTestNow(now()->addSeconds(120));
    expect($this->restartCommands->expire())->toBe(1)->and($request->fresh()->status)->toBe(CommandStatus::Uncertain);
});

it('rejects changed policy authority assignment incarnation warning and replay context before execution', function () {
    [$request,$attempt,$delivery] = restartDispatch($this);
    $input = restartClaimInput($request, $delivery);
    restartJump($this, $request);
    $path = '/api/endpoints/agent/restarts/'.$attempt->attempt_uuid.'/claim';
    $this->withToken($this->restartCredential)->postJson($path, [...$input, 'boot_id' => (string) Str::uuid()])->assertConflict();
    $this->postJson($path, [...$input, 'warning_started_at' => now()->utc()->subSeconds(10)->format('Y-m-d\TH:i:s\Z')])->assertConflict();
    $this->postJson($path, array_diff_key($input, ['consent' => true]))->assertUnprocessable();
    DB::table('endpoint_restart_policies')->where('device_id', $this->restartDevice->id)->update(['version' => 2]);
    $this->postJson($path, $input)->assertConflict();
    DB::table('endpoint_restart_policies')->where('device_id', $this->restartDevice->id)->update(['version' => 1]);
    restartActionGrant($this->restartActor, EndpointRestartPolicy::SERVICE, false);
    $this->postJson($path, $input)->assertConflict();
    restartActionGrant($this->restartActor, EndpointRestartPolicy::SERVICE);
    $this->postJson($path, $input)->assertOk();
    $this->postJson($path, [...$input, 'consent' => [...$input['consent'], 'consent_nonce' => str_repeat('d', 64)]])->assertConflict();
    CarbonImmutable::setTestNow(now()->addSeconds(6));
    $this->postJson($path, $input)->assertConflict();
});

it('requires separate unattended permission and forbids silently converting attended consent', function () {
    DB::table('endpoint_restart_policies')->where('device_id', $this->restartDevice->id)->update(['consent_mode' => 'unattended']);
    expect(fn () => restartDispatch($this))->toThrow(HttpException::class);
    restartActionGrant($this->restartActor, EndpointRestartPolicy::UNATTENDED);
    [$request,$attempt,$delivery] = restartDispatch($this);
    restartJump($this, $request);
    $input = restartClaimInput($request, $delivery);
    expect(fn () => $this->restartCommands->claim($this->restartCredential, $attempt->attempt_uuid, $input))->toThrow(HttpException::class);
    $input = array_diff_key([...$input, 'warning_started_at' => null], ['consent' => true]);
    $claim = $this->restartCommands->claim($this->restartCredential, $attempt->attempt_uuid, $input);
    $payload = json_decode(base64_decode($claim['payload']), true);
    expect($payload['consent_mode'])->toBe('unattended')->and($payload['desktop_session'])->toBeNull()->and($payload['warning_started_at'])->toBeNull();
});

it('conceals policy and cancellation for a moved device and never accepts forged canonical identifiers', function () {
    [$request,$attempt,$delivery] = restartDispatch($this);
    $other = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint']);
    $cancel = ['expected_actor_id' => $this->restartActor->id, 'reason' => 'Cancel this scheduled restart before active work is interrupted.'];
    $this->postJson('/security-devices/devices/'.$other->id.'/commands/'.$request->id.'/cancel', $cancel)->assertNotFound();
    $this->postJson('/security-devices/devices/'.$this->restartDevice->id.'/commands/'.$request->id.'/cancel', [...$cancel, 'expected_actor_id' => $this->restartReviewer->id])->assertConflict();
    $hiddenSite = Site::factory()->create();
    DeviceAssignment::query()->where('device_id', $this->restartDevice->id)->update(['released_at' => now()]);
    DeviceAssignment::query()->create(['device_id' => $this->restartDevice->id, 'assignable_type' => 'site', 'assignable_id' => $hiddenSite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
    $this->getJson('/security-devices/devices/'.$this->restartDevice->id.'/endpoint/restart-policy?expected_actor_id='.$this->restartActor->id)->assertNotFound();
    $this->postJson('/security-devices/devices/'.$this->restartDevice->id.'/commands/'.$request->id.'/cancel', $cancel)->assertNotFound();
    // The enrolled transport loses authentication after its canonical assignment changes.
    $this->withToken($this->restartCredential)->getJson('/api/endpoints/agent/restarts')->assertUnauthorized();
});

it('mutually excludes restart and service final claims while preserving exact own claim replay', function () {
    config()->set('endpoint_management.actions.release_enabled', true);
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Print service', 'operations' => ['restart']]]);
    $this->restartAgent->forceFill(['service_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_scm', 'operations' => ['restart']]])->save();
    DB::table('endpoint_action_policies')->insert(['device_id' => $this->restartDevice->id, 'version' => 1, 'enabled' => true, 'consent_mode' => 'attended', 'updated_by_user_id' => $this->restartActor->id, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([$this->restartActor, $this->restartReviewer] as $actor) {
        restartActionGrant($actor, EndpointActionPolicy::SERVICE);
    }
    $prepareService = function () {
        $request = app(DeviceCommandRequestService::class)->request($this->restartDevice, $this->restartActor, new CommandRequestInput('endpoint.service.manage', ['service_name' => 'Spooler', 'operation' => 'restart'], 'Restore this independently reviewed print service.', (string) Str::uuid(), CarbonImmutable::now('UTC')->startOfSecond(), impactAcknowledged: true));
        app(DeviceCommandApprovalService::class)->decide($request, $this->restartReviewer, new CommandDecisionInput(CommandApprovalDecision::Approved, 'Service impact independently reviewed.'));
        $attempt = app(GovernedCommandDispatchService::class)->dispatch($request->fresh(), $this->restartActor);
        $delivery = EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->firstOrFail();

        return [$request->fresh(), $attempt, $delivery];
    };
    [$restart,$restartAttempt,$restartDelivery] = restartDispatch($this);
    [$service,$serviceAttempt,$serviceDelivery] = $prepareService();
    $serviceInput = ['command_uuid' => $service->command_uuid, 'payload_hash' => $serviceDelivery->payload_hash, 'consent' => ['desktop_session' => '11111111-1111-4111-8111-111111111111', 'consent_nonce' => str_repeat('f', 64)]];
    $services = app(EndpointServiceCommands::class);
    $own = $services->claim($this->restartCredential, $serviceAttempt->attempt_uuid, $serviceInput);
    restartJump($this, $restart);
    expect(fn () => $this->restartCommands->claim($this->restartCredential, $restartAttempt->attempt_uuid, restartClaimInput($restart, $restartDelivery)))->toThrow(HttpException::class);
    // An expired service claim is unsettled; the restart must not silently treat it as complete.
    expect($serviceDelivery->fresh()->claim_expires_at->isPast())->toBeTrue();
    $services->result($this->restartCredential, $serviceAttempt->attempt_uuid, ['command_uuid' => $service->command_uuid, 'payload_hash' => $serviceDelivery->payload_hash, 'status' => 'uncertain', 'operation' => 'restart', 'service_name' => 'Spooler', 'before_state' => 'running', 'after_state' => 'unknown', 'mutation_started' => true, 'reason_code' => 'interrupted']);
    $restartInput = restartClaimInput($restart, $restartDelivery);
    $claim = $this->restartCommands->claim($this->restartCredential, $restartAttempt->attempt_uuid, $restartInput);
    [$otherService,$otherAttempt,$otherDelivery] = $prepareService();
    expect(fn () => $services->claim($this->restartCredential, $otherAttempt->attempt_uuid, [...$serviceInput, 'command_uuid' => $otherService->command_uuid, 'payload_hash' => $otherDelivery->payload_hash]))->toThrow(HttpException::class);
    expect($this->restartCommands->claim($this->restartCredential, $restartAttempt->attempt_uuid, $restartInput))->toBe($claim);
});

it('retains the mutation guard after API acceptance until changed boot and rejects overlapping scheduled restart queues', function () {
    [$request,$attempt,$delivery] = restartDispatch($this);
    expect(fn () => restartDispatch($this))->toThrow(ValidationException::class);
    restartJump($this, $request);
    $this->restartCommands->claim($this->restartCredential, $attempt->attempt_uuid, restartClaimInput($request, $delivery));
    $this->restartCommands->result($this->restartCredential, $attempt->attempt_uuid, restartReceipt($request, $delivery));
    $other = (new EndpointDelivery)->forceFill(['id' => PHP_INT_MAX]);
    $guard = app(EndpointMutationGuard::class);
    expect(fn () => $guard->assertAvailable($this->restartAgent->fresh(), $other))->toThrow(HttpException::class);
    CarbonImmutable::setTestNow(now()->addSecond());
    app(EndpointHeartbeat::class)->record($this->restartCredential, restartHeartbeat($this, $request, $attempt));
    $guard->assertAvailable($this->restartAgent->fresh(), $other);
    expect(true)->toBeTrue();
});

it('uses current locking mutual claim evidence despite an older repeatable read snapshot', function () {
    $manager = DB::getFacadeRoot();
    $default = $manager->getDefaultConnection();
    $configuration = config('database.connections.'.$default);
    expect($configuration['driver'])->toBe('mysql')->and($configuration['database'])->toContain('_codex_test_');
    $prefix = 'restart_rr_'.strtolower(Str::random(8)).'_';
    $configuration['prefix'] = $prefix;
    $configuration['isolation_level'] = 'REPEATABLE READ';
    config()->set('database.connections.restart_guard_a', $configuration);
    config()->set('database.connections.restart_guard_b', $configuration);
    $a = DB::connection('restart_guard_a');
    $b = DB::connection('restart_guard_b');
    $schema = $b->getSchemaBuilder();
    try {
        $schema->create('device_command_requests', function (Blueprint $t) {
            $t->id();
            $t->string('capability');
        });
        $schema->create('device_command_attempts', function (Blueprint $t) {
            $t->id();
            $t->integer('device_command_request_id');
        });
        $schema->create('endpoint_deliveries', function (Blueprint $t) {
            $t->id();
            $t->integer('endpoint_agent_id');
            $t->string('agent_uuid');
            $t->integer('device_command_attempt_id');
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->json('result')->nullable();
            $t->json('restart_readback')->nullable();
            $t->json('software_readback')->nullable();
            $t->timestamp('readback_received_at')->nullable();
        });
        $b->table('device_command_requests')->insert(['id' => 1, 'capability' => 'endpoint.computer.restart']);
        $b->table('device_command_attempts')->insert(['id' => 1, 'device_command_request_id' => 1]);
        $b->table('endpoint_deliveries')->insert(['id' => 1, 'endpoint_agent_id' => 7, 'agent_uuid' => 'agent-current', 'device_command_attempt_id' => 1]);
        $a->beginTransaction();
        expect($a->table('endpoint_deliveries')->whereNotNull('claimed_at')->count())->toBe(0);
        $b->table('endpoint_deliveries')->where('id', 1)->update(['claimed_at' => now()]);
        expect($a->table('endpoint_deliveries')->whereNotNull('claimed_at')->count())->toBe(0);
        $manager->setDefaultConnection('restart_guard_a');
        $agent = (new EndpointAgent)->forceFill(['id' => 7, 'uuid' => 'agent-current', 'boot_id' => 'boot-current']);
        $own = (new EndpointDelivery)->forceFill(['id' => 99]);
        expect(fn () => app(EndpointMutationGuard::class)->assertAvailable($agent, $own))->toThrow(HttpException::class);
        $a->rollBack();
        $b->table('endpoint_deliveries')->where('id', 1)->update(['completed_at' => now(), 'result' => json_encode(['status' => 'awaiting_boot', 'mutation_started' => true]), 'restart_readback' => json_encode(['boot_id' => 'boot-current'])]);
        $a->beginTransaction();
        expect($a->table('endpoint_deliveries')->value('restart_readback'))->toContain('boot-current');
        $b->table('endpoint_deliveries')->where('id', 1)->update(['restart_readback' => null]);
        expect($a->table('endpoint_deliveries')->value('restart_readback'))->toContain('boot-current');
        expect(fn () => app(EndpointMutationGuard::class)->assertAvailable($agent, $own))->toThrow(HttpException::class);
        $a->rollBack();
        $b->table('device_command_requests')->where('id', 1)->update(['capability' => 'endpoint.software.install']);
        $b->table('endpoint_deliveries')->where('id', 1)->update(['result' => json_encode(['status' => 'awaiting_observation', 'mutation_started' => true]), 'software_readback' => json_encode(['boot_id' => 'older-settled-boot', 'transaction_quiescent' => true]), 'readback_received_at' => now()]);
        // Finish the successful current locking read before the second writer.
        // Otherwise even filtered-out scanned rows can remain locked in this fixture.
        expect($a->transaction(fn () => app(EndpointMutationGuard::class)->assertAvailable($agent, $own)))->toBeNull();
        $a->beginTransaction();
        expect($a->table('endpoint_deliveries')->value('software_readback'))->toContain('true');
        $b->table('endpoint_deliveries')->where('id', 1)->update(['software_readback' => null, 'readback_received_at' => null]);
        expect($a->table('endpoint_deliveries')->value('software_readback'))->toContain('true');
        expect(fn () => app(EndpointMutationGuard::class)->assertAvailable($agent, $own))->toThrow(HttpException::class);
    } finally {
        if ($a->transactionLevel() > 0) {
            $a->rollBack();
        }$manager->setDefaultConnection($default);
        foreach (['endpoint_deliveries', 'device_command_attempts', 'device_command_requests'] as $table) {
            $schema->dropIfExists($table);
        }
        DB::purge('restart_guard_a');
        DB::purge('restart_guard_b');
    }
});
