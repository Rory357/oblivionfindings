<?php

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointScript;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointCommandSigner;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointMutationGuard;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptAuthority;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptCommands;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptContract as Contract;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptOutputs;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScripts;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandQueueService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\EndpointScriptPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

function scriptCatalogueGrant(User $actor, string $key, bool $allow = true): void
{
    $permission = Permission::firstOrCreate(['key' => $key], ['name' => $key, 'module' => 'securityDevices']);
    $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allow]]);
}

function scriptCatalogueProof(User $actor): void
{
    $request = Request::create('/synthetic-script-review');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $actor);
    app()->instance('request', $request);
    app(EndpointScriptIdentity::class)->begin($request, $actor);
    $request->session()->put('auth.password_confirmed_at', now()->timestamp);
}

function scriptCatalogueInput(User $actor, ?int $version = null, ?string $content = null): array
{
    return ['expected_actor_id' => (int) $actor->id, 'request_key' => (string) Str::uuid(), 'reason' => 'Synthetic independent catalogue verification.',
        ...($version !== null ? ['expected_version' => $version] : []), ...($content !== null ? ['reviewed_content_hash' => $content] : [])];
}

function scriptCatalogueDeny(callable $operation, int $status): void
{
    try {
        $operation();
        test()->fail('Expected catalogue denial.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe($status);
    }
}

function scriptCatalogueUpload($test, ?array $input = null): array
{
    return app(EndpointScripts::class)->upload($test->scriptAuthor, $input ?? scriptCatalogueInput($test->scriptAuthor), Contract::encode($test->scriptMetadata), Contract::encode($test->scriptReview), $test->scriptBytes, $test->scriptRuntime);
}

function scriptCataloguePending($test): array
{
    $row = scriptCatalogueUpload($test)['script'];
    app(EndpointScripts::class)->transition($test->scriptAuthor, $row['uuid'], 'submit', scriptCatalogueInput($test->scriptAuthor, 1));

    return $row;
}

function scriptExecutionCandidateFixture($test, bool $unattended = false): array
{
    $test->scriptMetadata->parameter_schema = [(object) [
        'name' => 'IncludeDetails', 'type' => 'boolean', 'required' => true, 'constraints' => new stdClass,
    ]];
    $row = scriptCataloguePending($test);
    scriptCatalogueProof($test->scriptReviewer);
    app(EndpointScripts::class)->transition($test->scriptReviewer, $row['uuid'], 'review',
        scriptCatalogueInput($test->scriptReviewer, 2, $row['content_hash']));
    $runner = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
    ensureCanonicalHrStaffProfile($runner, $test->scriptSite);
    $runner->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    foreach (['it.view', 'it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view',
        EndpointScriptPolicy::RUN, EndpointScriptPolicy::MANAGE] as $key) {
        scriptCatalogueGrant($runner, $key);
    }
    if ($unattended) {
        scriptCatalogueGrant($runner, EndpointScriptPolicy::UNATTENDED);
    }
    $device = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active']);
    $assignment = DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => 'site',
        'assignable_id' => $test->scriptSite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
    scriptCatalogueProof($runner);
    app(EndpointScriptPolicy::class)->save($runner, $device, [
        'expected_actor_id' => (int) $runner->id, 'expected_version' => 0, 'request_key' => (string) Str::uuid(),
        'enabled' => true, 'consent_mode' => $unattended ? 'unattended' : 'attended', 'reason' => 'Synthetic candidate eligibility review.',
    ]);
    // Executing a published candidate does not grant catalogue author/reviewer rights.
    scriptCatalogueGrant($runner, EndpointScriptPolicy::MANAGE, false);

    return [$runner, $device, $assignment, $row];
}

function scriptCommandFixture($test, bool $syntheticRelease = true, bool $unattended = false): array
{
    [$runner, $device, $assignment, $row] = scriptExecutionCandidateFixture($test, $unattended);
    Queue::fake();
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.server_url', 'https://localhost');
    config()->set('endpoint_management.scripts.release_enabled', true);
    config()->set('monitoring.signing.active_key_id', 'isolated-script-command');
    config()->set('monitoring.signing.keys', ['isolated-script-command' => base64_encode(str_repeat('Q', SODIUM_CRYPTO_AUTH_KEYBYTES))]);
    foreach ([$runner, $test->scriptReviewer] as $actor) {
        foreach (['securityDevices.devices.view', 'securityDevices.commands.observe', 'securityDevices.commands.control',
            'securityDevices.commands.approve', EndpointScriptPolicy::RUN] as $key) {
            scriptCatalogueGrant($actor, $key);
        }
        if ($unattended) {
            scriptCatalogueGrant($actor, EndpointScriptPolicy::UNATTENDED);
        }
    }
    $device->update(['last_seen_at' => now()]);
    $agent = EndpointAgent::create(['uuid' => (string) Str::uuid(), 'device_id' => $device->id,
        'site_id' => $test->scriptSite->id, 'platform' => 'windows', 'created_by_user_id' => $runner->id,
        'assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($device),
        'credential_hash' => hash('sha256', 'synthetic-never-enrolled'), 'enrolled_at' => now(),
        'last_seen_at' => now(), 'observed_at' => now(), 'boot_id' => (string) Str::uuid(),
        'sequence' => 1, 'heartbeat_hash' => str_repeat('b', 64), 'inventory' => ['architecture' => 'amd64'],
        'script_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'reviewed_script']]);
    if ($syntheticRelease) {
        // Only this test replaces the closed release predicate. No executor exists.
        // All actual current policy/assignment reads still use the production owner.
        $policy = app(EndpointScriptPolicy::class);
        app()->instance(EndpointScriptPolicy::class, new class($policy)
        {
            public function __construct(private EndpointScriptPolicy $policy) {}

            public function available(): bool
            {
                return true;
            }

            public function current(Device $device): array
            {
                return $this->policy->current($device);
            }
        });
    }
    scriptCatalogueProof($runner);
    $input = new CommandRequestInput(Contract::CAPABILITY, [
        'script_uuid' => $row['uuid'], 'script_version' => 1, 'content_hash' => $row['content_hash'],
        'policy_version' => 1, 'inputs_json' => ' { "IncludeDetails": false } ',
    ], 'Synthetic request for independently reviewed inspection.', (string) Str::uuid(),
        CarbonImmutable::createFromTimestamp(app('request')->session()->get('auth.password_confirmed_at')),
        impactAcknowledged: true);

    return [$runner, $device, $agent, $input, $row];
}

function scriptPreparedTransportFixture($test, bool $unattended = false): array
{
    [$runner, $device, $agent, $input, $row] = scriptCommandFixture($test, true, $unattended);
    $credential = str_repeat('d', 64);
    expect(preg_match('/\A[a-f0-9]{64}\z/', $credential))->toBe(1);
    $agent->update(['credential_hash' => hash('sha256', $credential)]);
    config()->set('endpoint_management.command_signing_secret', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('T', 32)))));
    $command = app(DeviceCommandRequestService::class)->request($device, $runner, $input);
    scriptCatalogueProof($test->scriptReviewer);
    $command = app(DeviceCommandApprovalService::class)->decide($command, $test->scriptReviewer,
        new CommandDecisionInput(CommandApprovalDecision::Approved, 'Independent synthetic transport review.', $test->scriptReviewer->id));
    // Queue remains closed in production. Seed only this approved internal dispatch state
    // to verify prepared transport; no DispatchDeviceCommand job or native process runs.
    [$attempt, $delivery] = DB::transaction(function () use ($command): array {
        $command->forceFill(['status' => CommandStatus::Dispatching, 'dispatched_at' => now()])->save();
        $attempt = DeviceCommandAttempt::create(['device_command_request_id' => $command->id,
            'attempt_number' => 1, 'status' => CommandAttemptStatus::Dispatching, 'runtime' => 'endpoint']);
        $delivery = app(EndpointScriptCommands::class)->prepare($command, $attempt);

        return [$attempt, $delivery];
    });
    $claim = (object) ['command_uuid' => $command->command_uuid, 'payload_hash' => $delivery->payload_hash,
        'artifact_sha256' => $command->expected_state['artifact_sha256'],
        'runtime_manifest_sha256' => $command->expected_state['runtime_manifest_sha256'], 'boot_id' => $agent->boot_id,
        'consent' => (object) ['desktop_session' => (string) Str::uuid(), 'consent_nonce' => str_repeat('e', 64)]];
    if ($unattended) {
        unset($claim->consent);
    }

    return [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim, $row];
}

function scriptResultFixture(DeviceCommandRequest $command, EndpointDelivery $delivery, EndpointAgent $agent, array $changes = []): array
{
    return array_replace(['command_uuid' => $command->command_uuid, 'payload_hash' => $delivery->payload_hash,
        'status' => 'completed', 'mutation_started' => true, 'reason_code' => 'completed', 'exit_code' => 0,
        'boot_id' => $agent->boot_id, 'output_sha256' => null, 'output_bytes' => 0, 'output_truncated' => false], $changes);
}

function scriptCancellationInput(User $actor, ?DeviceCommandAttempt $attempt = null): array
{
    return ['expected_actor_id' => (int) $actor->id, 'request_key' => (string) Str::uuid(),
        'expected_attempt_uuid' => $attempt?->attempt_uuid, 'reason' => 'Synthetic cancellation verification.'];
}

beforeEach(function () {
    // Register before blanket seeders to prove they do not silently grant scripts.
    $this->seed([EndpointScriptPermissionsSeeder::class, RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
    $this->scriptAuthor = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
    $this->scriptReviewer = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
    $this->scriptSite = ensureCanonicalHrStaffProfile($this->scriptAuthor);
    ensureCanonicalHrStaffProfile($this->scriptReviewer, $this->scriptSite);
    foreach ([$this->scriptAuthor, $this->scriptReviewer] as $actor) {
        $actor->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
        foreach (['it.view', 'it.manage', 'securityDevices.viewAny', EndpointScripts::UPLOAD, EndpointScripts::REVIEW] as $key) {
            scriptCatalogueGrant($actor, $key);
        }
    }
    $this->scriptBytes = "# Synthetic catalogue fixture. Never execute.\n";
    $this->scriptRuntime = Contract::encode((object) ['version' => 1, 'interpreter' => 'powershell7', 'interpreter_version' => '7.6.0', 'architecture' => 'amd64', 'entrypoint' => 'pwsh.exe', 'files' => [
        (object) ['path' => 'obli-script-wrapper.ps1', 'size_bytes' => 10, 'sha256' => str_repeat('b', 64)],
        (object) ['path' => 'pwsh.exe', 'size_bytes' => 20, 'sha256' => str_repeat('c', 64)],
    ]]);
    $this->scriptMetadata = (object) ['name' => 'Synthetic inspection', 'publisher_label' => 'Synthetic publisher', 'interpreter' => 'powershell7', 'interpreter_version' => '7.6.0',
        'wrapper_sha256' => str_repeat('b', 64), 'architecture' => 'amd64', 'parameter_schema' => [],
        'execution_policy' => (object) ['runtime_seconds' => 60, 'output_bytes' => 4096, 'output_retention_hours' => 24, 'network_mode' => 'reviewed_network', 'timeout_mode' => 'report_only', 'success_exit_codes' => [0], 'expected_observation_schema' => null]];
    $this->scriptReview = (object) ['effect' => 'read_only', 'child_processes' => [], 'network_destinations' => [], 'output_sensitivity' => 'operational', 'compensation' => 'No mutation approved.', 'restart_behavior' => 'none', 'no_detached_work' => true, 'no_dynamic_code' => true];
    $this->scriptApprovedRuntime = [hash('sha256', $this->scriptRuntime) => ['architecture' => 'amd64', 'interpreter_version' => '7.6.0', 'wrapper_sha256' => str_repeat('b', 64)]];
    config()->set('endpoint_management.scripts.reviewed_runtimes', $this->scriptApprovedRuntime);
});

it('binds script command intake to immutable content current enrolment and private typed inputs', function () {
    [$runner, $device, $agent, $input] = scriptCommandFixture($this);
    $service = app(DeviceCommandRequestService::class);
    $command = $service->request($device, $runner, $input);
    expect($command->status)->toBe(CommandStatus::AwaitingApproval)
        ->and($command->execution_route)->toBe('endpoint')
        ->and($command->expected_state['agent_uuid'])->toBe($agent->uuid)
        ->and($command->expected_state['policy_version'])->toBe(1)
        ->and($command->encrypted_parameters)->toBe($input->parameters)
        ->and($command->safe_parameter_summary)->toBe(['script_uuid' => $input->parameters['script_uuid'], 'script_version' => 1])
        ->and($command->getRawOriginal('encrypted_parameters'))->not->toContain('IncludeDetails')
        ->and($service->request($device, $runner, $input)->id)->toBe($command->id);
    $changed = new CommandRequestInput($input->capability, [...$input->parameters, 'inputs_json' => '{"IncludeDetails":true}'],
        $input->reason, $input->idempotencyKey, $input->stepUpConfirmedAt, impactAcknowledged: true);
    expect(fn () => $service->request($device, $runner, $changed))->toThrow(ValidationException::class);
    $agent->update(['uuid' => (string) Str::uuid()]);
    scriptCatalogueDeny(fn () => $service->request($device, $runner, $input), 409);
    expect(DeviceCommandRequest::count())->toBe(1)
        ->and(DB::table('device_command_attempts')->count())->toBe(0);
});

it('serves exact private prepared script bytes and a nonrenewable original signed HTTP claim', function () {
    $this->travelTo(CarbonImmutable::now()->startOfSecond());
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $this->withServerVariables(['HTTPS' => 'on'])->withToken($credential);
    $base = 'https://localhost/api/endpoints/agent/scripts';
    $poll = $this->get($base)->assertOk()->assertJsonPath('cancellation', null);
    expect($poll->json('command'))->toBe($delivery->encrypted_payload);
    $artifact = $this->get($base.'/'.$attempt->attempt_uuid.'/artifact')->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($artifact->getContent())->toBe($this->scriptBytes)
        ->and($artifact->headers->get('Cache-Control'))->toContain('no-store');
    $path = $base.'/'.$attempt->attempt_uuid.'/claim';
    $reply = $this->postJson($path, (array) $claim)->assertOk()->json();
    $decoded = Contract::decode(base64_decode($reply['payload'], true));
    expect($decoded->runtime_manifest_sha256)->toBe($claim->runtime_manifest_sha256)
        ->and($decoded->consent_nonce)->toBe($claim->consent->consent_nonce)
        ->and($command->fresh()->status)->toBe(CommandStatus::Accepted);
    if ($export = getenv('OBLI_SCRIPT_TRANSPORT_EXPORT')) {
        file_put_contents($export, Contract::encode((object) [
            'public_key' => app(EndpointCommandSigner::class)->publicKey(), 'agent_uuid' => $agent->uuid,
            'now' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'command' => (object) $poll->json('command'),
            'claim' => (object) $reply, 'claim_request' => $claim, 'artifact' => base64_encode($artifact->getContent()),
        ]));
    }
    scriptCatalogueGrant($runner, EndpointScriptPolicy::RUN, false);
    $this->postJson($path, (array) $claim)->assertConflict();
    scriptCatalogueGrant($runner, EndpointScriptPolicy::RUN);
    $this->travel(2)->seconds();
    expect($this->postJson($path, (array) $claim)->assertOk()->json())->toBe($reply);
    $this->get($base.'/'.$attempt->attempt_uuid.'/artifact')->assertConflict();
    $this->get($base)->assertOk()->assertJsonPath('command', null);
    $changed = Contract::decode(Contract::encode($claim));
    $changed->consent->consent_nonce = str_repeat('a', 64);
    $this->postJson($path, (array) $changed)->assertConflict();
    $this->travel(29)->seconds();
    $this->postJson($path, (array) $claim)->assertConflict();
    expect($delivery->fresh()->encrypted_claim)->toBe($reply)
        ->and($delivery->fresh()->claim_expires_at->format('Y-m-d\TH:i:s\Z'))->toBe($decoded->expires_at)
        ->and(DB::table('device_command_audit_events')->where('action', 'endpoint_script_claimed')->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('accepts immutable script result HTTP after authority withdrawal expiry and later boot without granting execution', function () {
    $this->travelTo(CarbonImmutable::now()->startOfSecond());
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim, $row] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $signedClaim = $service->claim($credential, $attempt->attempt_uuid, $claim);
    $input = scriptResultFixture($command, $delivery, $agent, ['output_sha256' => hash('sha256', 'private output'), 'output_bytes' => 14]);
    scriptCatalogueProof($this->scriptReviewer);
    app(EndpointScripts::class)->transition($this->scriptReviewer, $row['uuid'], 'withdraw', scriptCatalogueInput($this->scriptReviewer, 3));
    foreach ([$runner, $this->scriptAuthor, $this->scriptReviewer] as $actor) {
        $actor->forceFill(['two_factor_confirmed_at' => null])->save();
    }
    scriptCatalogueGrant($runner, EndpointScriptPolicy::RUN, false);
    config()->set('endpoint_management.scripts.reviewed_runtimes', []);
    app()->instance(EndpointScriptPolicy::class, new EndpointScriptPolicy);
    DB::table('endpoint_scripts')->where('uuid', $row['uuid'])->update(['artifact_ciphertext' => 'damaged']);
    $this->travel(2)->hours();
    $agent->update(['boot_id' => (string) Str::uuid()]);
    $this->withToken($credential);
    $path = 'https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/result';
    $response = $this->postJson($path, $input)->assertOk()->assertJsonPath('accepted', true);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $stored = $delivery->fresh();
    expect($attempt->fresh()->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($attempt->fresh()->started_at)->toBeNull()
        ->and($command->fresh()->status)->toBe(CommandStatus::Succeeded)
        ->and($attempt->fresh()->safe_result_summary['output_received'])->toBeFalse()
        ->and($stored->result['boot_id'])->toBe($claim->boot_id)
        ->and($stored->encrypted_claim)->toBe($signedClaim);
    $this->travel(1)->minutes();
    expect($this->postJson($path, array_reverse($input, true))->assertOk()->json())->toBe($response->json())
        ->and($delivery->fresh()->completed_at->equalTo($stored->completed_at))->toBeTrue();
    $this->postJson($path, [...$input, 'output_bytes' => 15])->assertConflict();
    expect(DB::table('device_command_audit_events')->where('action', 'endpoint_script_result_completed')->count())->toBe(1);
    // An exit receipt does not yet prove every owned child process is quiescent.
    scriptCatalogueDeny(fn () => DB::transaction(fn () => app(EndpointMutationGuard::class)
        ->assertAvailable($agent->fresh(), (new EndpointDelivery)->forceFill(['id' => PHP_INT_MAX]))), 409);
    if ($export = getenv('OBLI_SCRIPT_RESULT_EXPORT')) {
        file_put_contents($export, Contract::encode((object) [
            'public_key' => app(EndpointCommandSigner::class)->publicKey(), 'agent_uuid' => $agent->uuid,
            'command' => (object) $stored->encrypted_payload, 'claim' => (object) $signedClaim,
            'result' => (object) $input, 'ack' => (object) $response->json(),
        ]));
    }
    Queue::assertNothingPushed();
});

it('rejects malformed or unclaimed entered script results and preserves the first valid prelaunch receipt', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential] = scriptPreparedTransportFixture($this);
    $this->withToken($credential);
    $path = 'https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/result';
    $input = scriptResultFixture($command, $delivery, $agent);
    $this->postJson($path, $input)->assertConflict();
    foreach ([
        ['mutation_started' => 'true'], ['exit_code' => -1], ['output_bytes' => 4097],
        ['status' => 'cancelled'], ['raw_output' => 'must never be accepted'], ['payload_hash' => str_repeat('f', 64)],
    ] as $change) {
        $this->postJson($path, array_replace($input, $change))->assertUnprocessable();
    }
    $this->postJson($path.'?extra=1', $input)->assertStatus(400);
    $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(415);
    $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"status":"failed","status":"completed"}')->assertUnprocessable();
    expect($delivery->fresh()->result_hash)->toBeNull();
    $declined = array_replace($input, ['status' => 'declined', 'mutation_started' => false, 'reason_code' => 'declined', 'exit_code' => null]);
    $this->postJson($path, $declined)->assertOk();
    expect($attempt->fresh()->status)->toBe(CommandAttemptStatus::Failed);
    $this->postJson($path, $input)->assertConflict();
    expect(DB::transaction(fn () => app(EndpointMutationGuard::class)->assertAvailable($agent->fresh(),
        (new EndpointDelivery)->forceFill(['id' => PHP_INT_MAX]))))->toBeNull();
});

it('retains script exclusion for uncertain entry and known entered outcomes', function (array $change) {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $input = (object) scriptResultFixture($command, $delivery, $agent, $change);
    expect($service->result($credential, $attempt->attempt_uuid, $input)['accepted'])->toBeTrue();
    $this->travel(2)->hours();
    scriptCatalogueDeny(fn () => DB::transaction(fn () => app(EndpointMutationGuard::class)
        ->assertAvailable($agent->fresh(), (new EndpointDelivery)->forceFill(['id' => PHP_INT_MAX]))), 409);
    expect($delivery->fresh()->completed_at)->not->toBeNull();
})->with([
    'unknown entry' => [['status' => 'uncertain', 'mutation_started' => false, 'reason_code' => 'state_unavailable', 'exit_code' => null]],
    'entered interruption' => [['status' => 'uncertain', 'reason_code' => 'interrupted', 'exit_code' => null]],
    'entered timeout' => [['status' => 'uncertain', 'reason_code' => 'timed_out', 'exit_code' => null]],
    'cancel requested after entry' => [['status' => 'uncertain', 'reason_code' => 'cancellation_requested', 'exit_code' => null]],
    'known failed exit' => [['status' => 'failed', 'reason_code' => 'exit_failed', 'exit_code' => 1]],
]);

it('releases script exclusion only on a definite prelaunch receipt', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $input = (object) scriptResultFixture($command, $delivery, $agent, [
        'status' => 'cancelled', 'mutation_started' => false, 'reason_code' => 'cancelled', 'exit_code' => null,
    ]);
    $service->result($credential, $attempt->attempt_uuid, $input);
    expect($attempt->fresh()->status)->toBe(CommandAttemptStatus::Cancelled)
        ->and($command->fresh()->status)->toBe(CommandStatus::Cancelled)
        ->and(DB::transaction(fn () => app(EndpointMutationGuard::class)->assertAvailable($agent->fresh(),
            (new EndpointDelivery)->forceFill(['id' => PHP_INT_MAX]))))->toBeNull();
});

it('retains late script reports without rewriting terminal canonical outcomes', function (string $terminal) {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $attempt->forceFill(['status' => CommandAttemptStatus::from($terminal), 'completed_at' => now(),
        'safe_result_summary' => ['reason_code' => 'historical_'.$terminal]])->save();
    $command->forceFill(['status' => CommandStatus::from($terminal)])->save();
    $before = $attempt->fresh()->getRawOriginal();
    $service->result($credential, $attempt->attempt_uuid, (object) scriptResultFixture($command, $delivery, $agent));
    expect($attempt->fresh()->getRawOriginal())->toBe($before)
        ->and($command->fresh()->status->value)->toBe($terminal)
        ->and($delivery->fresh()->result['status'])->toBe('completed');
})->with(['uncertain', 'expired']);

it('rejects foreign boot revoked identity and retained script command substitution on result receipts', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $this->withToken($credential);
    $path = 'https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/result';
    $input = scriptResultFixture($command, $delivery, $agent);
    $this->postJson($path, [...$input, 'boot_id' => (string) Str::uuid()])->assertConflict();
    $agent->update(['revoked_at' => now()]);
    $this->postJson($path, $input)->assertUnauthorized();
    $agent->update(['revoked_at' => null]);
    $original = $delivery->encrypted_payload;
    $changed = Contract::decode(base64_decode($original['payload'], true));
    $changed->parameters->inputs->IncludeDetails = true;
    $signed = app(EndpointCommandSigner::class)->sign((array) $changed);
    $delivery->forceFill(['encrypted_payload' => $signed, 'payload_hash' => hash('sha256', base64_decode($signed['payload'], true))])->save();
    $this->postJson($path, [...$input, 'payload_hash' => $delivery->payload_hash])->assertConflict();
    $delivery->forceFill(['encrypted_payload' => $original, 'payload_hash' => $input['payload_hash']])->save();
    DB::table('device_command_requests')->where('id', $command->id)->update(['reason' => 'Tampered historical command reason.']);
    $this->postJson($path, $input)->assertConflict();
    expect($delivery->fresh()->result_hash)->toBeNull();
});

it('rejects a prelaunch cancellation that conflicts with the canonical running state', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    app(EndpointScriptCommands::class)->claim($credential, $attempt->attempt_uuid, $claim);
    $attempt->forceFill(['status' => CommandAttemptStatus::Running])->save();
    $command->refresh()->forceFill(['status' => CommandStatus::Running])->save();
    $this->withToken($credential);
    $path = 'https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/result';
    foreach (['cancelled' => 'cancelled', 'expired' => 'expired', 'declined' => 'declined', 'failed' => 'consent_lost'] as $status => $reason) {
        $this->postJson($path, scriptResultFixture($command, $delivery, $agent, [
            'status' => $status, 'mutation_started' => false, 'reason_code' => $reason, 'exit_code' => null,
        ]))->assertConflict();
    }
    expect($delivery->fresh()->result_hash)->toBeNull()
        ->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Running)
        ->and($command->fresh()->status)->toBe(CommandStatus::Running);
    $this->postJson($path, scriptResultFixture($command, $delivery, $agent))->assertOk();
});

it('binds unattended script HTTP claims to separate current authority and accepts delayed result recovery', function () {
    $this->travelTo(CarbonImmutable::now()->startOfSecond());
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this, true);
    $this->withToken($credential);
    $base = 'https://localhost/api/endpoints/agent/scripts';
    $this->get($base)->assertOk()->assertJsonPath('command', $delivery->encrypted_payload);
    $this->get($base.'/'.$attempt->attempt_uuid.'/artifact')->assertOk();
    $path = $base.'/'.$attempt->attempt_uuid.'/claim';
    scriptCatalogueGrant($runner, EndpointScriptPolicy::UNATTENDED, false);
    $this->get($base)->assertOk()->assertJsonPath('command', null);
    $this->postJson($path, (array) $claim)->assertConflict();
    scriptCatalogueGrant($runner, EndpointScriptPolicy::UNATTENDED);
    $this->postJson($path, [...(array) $claim, 'consent' => (object) [
        'desktop_session' => (string) Str::uuid(), 'consent_nonce' => str_repeat('e', 64),
    ]])->assertUnprocessable();
    $reply = $this->postJson($path, (array) $claim)->assertOk()->json();
    $decoded = Contract::decode(base64_decode($reply['payload'], true));
    expect($decoded->consent_mode)->toBe('unattended')
        ->and($decoded->desktop_session)->toBeNull()->and($decoded->consent_nonce)->toBeNull();
    scriptCatalogueGrant($runner, EndpointScriptPolicy::UNATTENDED, false);
    $this->postJson($path, (array) $claim)->assertConflict();
    $this->travel(2)->hours();
    $input = scriptResultFixture($command, $delivery, $agent);
    $ack = $this->postJson($base.'/'.$attempt->attempt_uuid.'/result', $input)->assertOk()->json();
    if ($export = getenv('OBLI_SCRIPT_UNATTENDED_EXPORT')) {
        file_put_contents($export, Contract::encode((object) [
            'public_key' => app(EndpointCommandSigner::class)->publicKey(), 'agent_uuid' => $agent->uuid,
            'command' => (object) $delivery->encrypted_payload, 'claim' => (object) $reply,
            'result' => (object) $input, 'ack' => (object) $ack,
        ]));
    }
    Queue::assertNothingPushed();
});

it('preserves observed script start evidence across immutable result and acknowledgement recovery', function (bool $uncertain) {
    $this->travelTo(CarbonImmutable::now()->startOfSecond());
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    app(EndpointScriptCommands::class)->claim($credential, $attempt->attempt_uuid, $claim);
    $claimedAt = $delivery->fresh()->claimed_at;
    $this->travel(7)->seconds();
    $startedAt = CarbonImmutable::now();
    $attempt->refresh()->forceFill(['status' => CommandAttemptStatus::Running, 'started_at' => $startedAt])->save();
    $command->refresh()->forceFill(['status' => CommandStatus::Running])->save();
    $this->travel(5)->seconds();
    $input = scriptResultFixture($command, $delivery, $agent, $uncertain ? [
        'status' => 'uncertain', 'mutation_started' => false, 'reason_code' => 'state_unavailable', 'exit_code' => null,
    ] : []);
    $this->withToken($credential);
    $path = 'https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/result';
    $ack = $this->postJson($path, $input)->assertOk()->json();
    $stored = $delivery->fresh();
    $expected = $input;
    ksort($expected);
    $reported = $stored->result;
    ksort($reported);
    expect($attempt->fresh()->started_at->equalTo($startedAt))->toBeTrue()
        ->and($attempt->fresh()->started_at->equalTo($claimedAt))->toBeFalse()
        ->and($reported)->toBe($expected)
        ->and($attempt->fresh()->status)->toBe($uncertain ? CommandAttemptStatus::Uncertain : CommandAttemptStatus::Succeeded);
    $this->travel(1)->minutes();
    expect($this->postJson($path, $input)->assertOk()->json())->toBe($ack)
        ->and($attempt->fresh()->started_at->equalTo($startedAt))->toBeTrue()
        ->and($delivery->fresh()->completed_at->equalTo($stored->completed_at))->toBeTrue();
    $different = array_replace($input, $uncertain ? ['reason_code' => 'interrupted']
        : ['status' => 'failed', 'reason_code' => 'exit_failed', 'exit_code' => 1]);
    $this->postJson($path, $different)->assertConflict();
    $reportedAfter = $delivery->fresh()->result;
    ksort($reportedAfter);
    expect($reportedAfter)->toBe($expected);
    scriptCatalogueDeny(fn () => DB::transaction(fn () => app(EndpointMutationGuard::class)
        ->assertAvailable($agent->fresh(), (new EndpointDelivery)->forceFill(['id' => PHP_INT_MAX]))), 409);
    Queue::assertNothingPushed();
})->with(['uncertain entry' => true, 'known completion' => false]);

it('records private immutable cancellation HTTP before an attempt and recovers the original receipt', function () {
    [$runner, $device, $agent, $input] = scriptCommandFixture($this);
    $command = app(DeviceCommandRequestService::class)->request($device, $runner, $input);
    $data = scriptCancellationInput($runner);
    $path = 'https://localhost/security-devices/devices/'.$device->id.'/commands/'.$command->id.'/script-cancellation';
    $this->actingAs($runner);
    $reply = $this->postJson($path, $data)->assertOk()->assertJsonPath('receipt.disposition', 'cancelled_before_claim');
    expect($reply->headers->get('Cache-Control'))->toContain('no-store');
    $receipt = $reply->json('receipt');
    expect($command->fresh()->status)->toBe(CommandStatus::Cancelled)
        ->and(DeviceCommandAttempt::count())->toBe(0)->and(EndpointDelivery::count())->toBe(0);
    $this->travel(1)->hours();
    expect($this->postJson($path, array_reverse($data, true))->assertOk()->json('receipt'))->toBe($receipt);
    $read = $path.'/requests/'.$data['request_key'].'?expected_actor_id='.$runner->id;
    expect($this->get($read)->assertOk()->json('receipt'))->toBe($receipt);
    $this->postJson($path, [...$data, 'reason' => 'Changed cancellation request intent.'])->assertConflict();
    $denial = $this->get($read.'&expected_actor_id='.$runner->id)->assertStatus(400);
    expect($denial->headers->get('Cache-Control'))->toContain('no-store');
    scriptCatalogueGrant($runner, EndpointScriptPolicy::RUN, false);
    $this->get($read)->assertForbidden();
    scriptCatalogueGrant($runner, EndpointScriptPolicy::RUN);
    expect(DB::table('endpoint_script_cancel_receipts')->count())->toBe(1)
        ->and(DB::table('endpoint_script_cancel_leases')->count())->toBe(0)
        ->and(DB::table('device_command_audit_events')->where('action', 'endpoint_script_cancel_requested')->count())->toBe(1);
    scriptCatalogueGrant($this->scriptReviewer, 'securityDevices.commands.admin');
    $this->actingAs($this->scriptReviewer)->get($path.'/requests/'.$data['request_key'].'?expected_actor_id='.$this->scriptReviewer->id)->assertNotFound();
    Queue::assertNothingPushed();
});

it('fences final claim when cancellation wins and retains a delivered prelaunch acknowledgement', function (bool $delivered) {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $agentBase = 'https://localhost/api/endpoints/agent/scripts';
    $this->withToken($credential);
    if ($delivered) {
        $this->get($agentBase)->assertOk();
    }
    $data = scriptCancellationInput($runner, $attempt);
    $path = 'https://localhost/security-devices/devices/'.$device->id.'/commands/'.$command->id.'/script-cancellation';
    $receipt = $this->actingAs($runner)->postJson($path, $data)->assertOk()->assertJsonPath('receipt.disposition', 'cancelled_before_claim')->json('receipt');
    $this->postJson($agentBase.'/'.$attempt->attempt_uuid.'/claim', (array) $claim)->assertConflict();
    expect($attempt->fresh()->status)->toBe(CommandAttemptStatus::Cancelled)
        ->and($delivery->fresh()->encrypted_claim)->toBeNull();
    $poll = $this->get($agentBase)->assertOk()->assertJsonPath('command', null);
    if ($delivered) {
        expect($poll->json('cancellation'))->toBeArray();
        // The local user may have declined before it learned of server cancellation.
        $result = scriptResultFixture($command, $delivery, $agent, [
            'status' => 'declined', 'mutation_started' => false, 'reason_code' => 'declined', 'exit_code' => null,
        ]);
        $this->postJson($agentBase.'/'.$attempt->attempt_uuid.'/result', $result)->assertOk();
        $this->get($agentBase)->assertOk()->assertJsonPath('cancellation', null);
        expect($this->postJson($path, $data)->assertOk()->json('receipt'))->toBe($receipt);
        $this->postJson($path, scriptCancellationInput($runner, $attempt))->assertOk();
        expect($delivery->fresh()->status)->toBe('declined')
            ->and($delivery->fresh()->result['status'])->toBe('declined');
    } else {
        $poll->assertJsonPath('cancellation', null);
    }
    expect($command->fresh()->status)->toBe(CommandStatus::Cancelled)
        ->and(DB::transaction(fn () => app(EndpointMutationGuard::class)->assertAvailable($agent->fresh(),
            (new EndpointDelivery)->forceFill(['id' => PHP_INT_MAX]))))->toBeNull();
    Queue::assertNothingPushed();
})->with(['not delivered' => false, 'delivered' => true]);

it('delivers historical cancellation after expiry and withdrawal with immutable renewable stop leases', function () {
    $this->travelTo(CarbonImmutable::now()->startOfSecond());
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim, $row] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $launchClaim = $service->claim($credential, $attempt->attempt_uuid, $claim);
    scriptCatalogueProof($this->scriptReviewer);
    app(EndpointScripts::class)->transition($this->scriptReviewer, $row['uuid'], 'withdraw', scriptCatalogueInput($this->scriptReviewer, 3));
    config()->set('endpoint_management.scripts.reviewed_runtimes', []);
    app()->instance(EndpointScriptPolicy::class, new EndpointScriptPolicy);
    DB::table('endpoint_scripts')->where('uuid', $row['uuid'])->update(['artifact_ciphertext' => 'damaged']);
    $this->scriptAuthor->forceFill(['two_factor_confirmed_at' => null])->save();
    scriptCatalogueGrant($this->scriptReviewer, EndpointScripts::REVIEW, false);
    $this->travel(2)->hours();
    $data = scriptCancellationInput($runner, $attempt);
    $path = 'https://localhost/security-devices/devices/'.$device->id.'/commands/'.$command->id.'/script-cancellation';
    $receipt = $this->actingAs($runner)->postJson($path, $data)->assertOk()->assertJsonPath('receipt.disposition', 'cancellation_requested')->json('receipt');
    $this->withToken($credential);
    $base = 'https://localhost/api/endpoints/agent/scripts';
    $first = $this->get($base)->assertOk()->assertJsonPath('command', null)->json('cancellation');
    $firstTime = now()->utc()->format('Y-m-d\TH:i:s\Z');
    $lease = DB::table('endpoint_script_cancel_leases')->where('endpoint_delivery_id', $delivery->id)->first();
    $this->travel(2)->seconds();
    expect($this->get($base)->assertOk()->json('cancellation'))->toBe($first)
        ->and($this->postJson($path, $data)->assertOk()->json('receipt'))->toBe($receipt)
        ->and($attempt->fresh()->status)->toBe(CommandAttemptStatus::Accepted)
        ->and($command->fresh()->status)->toBe(CommandStatus::Accepted)
        ->and($delivery->fresh()->encrypted_claim)->toBe($launchClaim);
    $this->postJson($base.'/'.$attempt->attempt_uuid.'/claim', (array) $claim)->assertConflict();
    $this->travel(1)->hours();
    $renewed = $this->get($base)->assertOk()->json('cancellation');
    expect($renewed)->not->toBe($first)
        ->and(DB::table('endpoint_script_cancel_leases')->count())->toBe(2)
        ->and(DB::table('endpoint_script_cancel_leases')->where('id', $lease->id)->first()->encrypted_envelope)->toBe($lease->encrypted_envelope)
        ->and(json_decode(Crypt::decryptString($lease->encrypted_envelope), true, 32, JSON_THROW_ON_ERROR))->toBe($first)
        ->and($this->postJson($path, $data)->assertOk()->json('receipt'))->toBe($receipt)
        ->and($delivery->fresh()->encrypted_claim)->toBe($launchClaim);
    if ($export = getenv('OBLI_SCRIPT_CANCEL_EXPORT')) {
        file_put_contents($export, Contract::encode((object) [
            'public_key' => app(EndpointCommandSigner::class)->publicKey(), 'agent_uuid' => $agent->uuid,
            'command' => (object) $delivery->encrypted_payload,
            'first' => (object) $first, 'first_time' => $firstTime,
            'renewed' => (object) $renewed, 'renewed_time' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'receipt' => (object) $receipt,
        ]));
    }
    $this->postJson($base.'/'.$attempt->attempt_uuid.'/result', scriptResultFixture($command, $delivery, $agent, [
        'status' => 'uncertain', 'mutation_started' => false, 'reason_code' => 'state_unavailable', 'exit_code' => null,
    ]))->assertOk();
    expect($this->get($base)->assertOk()->json('cancellation'))->toBe($renewed);
    scriptCatalogueDeny(fn () => DB::transaction(fn () => app(EndpointMutationGuard::class)
        ->assertAvailable($agent->fresh(), (new EndpointDelivery)->forceFill(['id' => PHP_INT_MAX]))), 409);
    expect(DB::table('device_command_audit_events')->where('action', 'endpoint_script_cancel_requested')->count())->toBe(1)
        ->and(DB::table('device_command_audit_events')->where('action', 'endpoint_script_cancel_delivery_issued')->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('preserves entered terminal script results when recording a cancellation intent', function (bool $uncertain) {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $service->result($credential, $attempt->attempt_uuid, (object) scriptResultFixture($command, $delivery, $agent,
        $uncertain ? ['status' => 'uncertain', 'mutation_started' => false, 'reason_code' => 'interrupted', 'exit_code' => null] : []));
    $attemptBefore = $attempt->fresh()->getRawOriginal();
    $resultBefore = $delivery->fresh()->result_hash;
    $reply = $service->cancel($runner, $device, $command, (object) scriptCancellationInput($runner, $attempt));
    expect($reply['receipt']->disposition)->toBe('cancellation_requested')
        ->and($attempt->fresh()->getRawOriginal())->toBe($attemptBefore)
        ->and($delivery->fresh()->result_hash)->toBe($resultBefore)
        ->and($service->poll($credential)['cancellation'])->toBeArray();
})->with(['uncertain' => true, 'completed process' => false]);

it('confirms nonentry after claim without rewriting the original pending cancellation receipt', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $input = (object) scriptCancellationInput($runner, $attempt);
    $first = $service->cancel($runner, $device, $command, $input);
    expect($first['receipt']->disposition)->toBe('cancellation_requested');
    $this->withToken($credential)->postJson('https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/result',
        scriptResultFixture($command, $delivery, $agent, [
            'status' => 'cancelled', 'mutation_started' => false, 'reason_code' => 'cancelled', 'exit_code' => null,
        ]))->assertOk();
    $again = $service->cancel($runner, $device, $command, $input);
    expect(Contract::encode($again['receipt']))->toBe(Contract::encode($first['receipt']))
        ->and($again['current']['result_status'])->toBe('cancelled')
        ->and($again['current']['command_status'])->toBe('cancelled')
        ->and($service->poll($credential)['cancellation'])->toBeNull()
        ->and(DB::transaction(fn () => app(EndpointMutationGuard::class)->assertAvailable($agent->fresh(),
            (new EndpointDelivery)->forceFill(['id' => PHP_INT_MAX]))))->toBeNull();
});

it('does not request cancellation after a definite prelaunch failure', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $service->result($credential, $attempt->attempt_uuid, (object) scriptResultFixture($command, $delivery, $agent, [
        'status' => 'failed', 'mutation_started' => false, 'reason_code' => 'policy_unavailable', 'exit_code' => null,
    ]));
    scriptCatalogueDeny(fn () => $service->cancel($runner, $device, $command, (object) scriptCancellationInput($runner, $attempt)), 409);
    expect(DB::table('endpoint_script_cancel_receipts')->count())->toBe(0)
        ->and($delivery->fresh()->cancel_requested_at)->toBeNull();
});

it('rechecks cancellation actor MFA command ownership incarnation and exact attempt before accepting intent', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $input = scriptCancellationInput($runner, $attempt);
    scriptCatalogueDeny(fn () => $service->cancel($runner, $device, $command, (object) [...$input, 'expected_attempt_uuid' => (string) Str::uuid()]), 409);
    scriptCatalogueDeny(fn () => $service->cancel($runner, $device, $command, (object) [...$input, 'expected_actor_id' => $this->scriptReviewer->id]), 409);
    scriptCatalogueGrant($runner, EndpointScriptPolicy::RUN, false);
    scriptCatalogueDeny(fn () => $service->cancel($runner, $device, $command, (object) $input), 403);
    scriptCatalogueGrant($runner, EndpointScriptPolicy::RUN);
    $runner->forceFill(['two_factor_confirmed_at' => null])->save();
    scriptCatalogueDeny(fn () => $service->cancel($runner, $device, $command, (object) $input), 403);
    $runner->forceFill(['two_factor_confirmed_at' => now()])->save();
    scriptCatalogueDeny(fn () => $service->cancel($this->scriptReviewer, $device, $command,
        (object) scriptCancellationInput($this->scriptReviewer, $attempt)), 403);
    $originalUuid = $agent->uuid;
    $agent->update(['uuid' => (string) Str::uuid()]);
    scriptCatalogueDeny(fn () => $service->cancel($runner, $device, $command, (object) $input), 404);
    $agent->update(['uuid' => $originalUuid]);
    DB::table('device_command_requests')->where('id', $command->id)->update(['reason' => 'Tampered cancellation target identity.']);
    scriptCatalogueDeny(fn () => $service->cancel($runner, $device, $command, (object) $input), 409);
    DB::table('device_command_requests')->where('id', $command->id)->update(['reason' => $command->reason]);
    expect(DB::table('endpoint_script_cancel_receipts')->count())->toBe(0);
    $agent->update(['revoked_at' => now()]);
    expect($service->cancel($runner, $device, $command, (object) $input)['receipt']->disposition)->toBe('cancellation_requested');
    scriptCatalogueDeny(fn () => $service->poll($credential), 401);
    expect($attempt->fresh()->status)->toBe(CommandAttemptStatus::Accepted);
});

it('rejects ambiguous cancellation HTTP without persisting or exposing an intent', function () {
    [$runner, $device, $agent, $command, $attempt] = scriptPreparedTransportFixture($this);
    $data = scriptCancellationInput($runner, $attempt);
    $path = 'https://localhost/security-devices/devices/'.$device->id.'/commands/'.$command->id.'/script-cancellation';
    $this->actingAs($runner);
    $this->postJson($path.'?extra=1', $data)->assertStatus(400);
    $this->postJson($path, [...$data, 'unexpected' => true])->assertUnprocessable();
    $this->postJson($path, [...$data, 'expected_actor_id' => (string) $runner->id])->assertUnprocessable();
    $this->postJson($path, [...$data, 'expected_attempt_uuid' => null])->assertConflict();
    $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'],
        '{"request_key":"a","request_key":"b"}')->assertUnprocessable();
    $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(415);
    $this->postJson(str_replace('https://', 'http://', $path), $data)->assertStatus(400);
    $this->postJson(str_replace('localhost', 'foreign.invalid', $path), $data)->assertStatus(400);
    expect(DB::table('endpoint_script_cancel_receipts')->count())->toBe(0)
        ->and(DB::table('endpoint_script_cancel_leases')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects altered cancellation lease history instead of minting a replacement', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $service->cancel($runner, $device, $command, (object) scriptCancellationInput($runner, $attempt));
    DB::table('endpoint_script_cancel_leases')->where('endpoint_delivery_id', $delivery->id)->update(['encrypted_envelope' => 'damaged']);
    $this->travel(2)->hours();
    scriptCatalogueDeny(fn () => $service->poll($credential), 409);
    expect(DB::table('endpoint_script_cancel_leases')->count())->toBe(1)
        ->and($delivery->fresh()->cancel_requested_at)->not->toBeNull();
});

it('never rebinds a cancellation request key to another canonical command', function () {
    [$runner, $device, $agent, $input] = scriptCommandFixture($this);
    $requests = app(DeviceCommandRequestService::class);
    $commands = app(EndpointScriptCommands::class);
    $first = $requests->request($device, $runner, $input);
    $cancel = scriptCancellationInput($runner);
    $commands->cancel($runner, $device, $first, (object) $cancel);
    scriptCatalogueProof($runner);
    $second = $requests->request($device, $runner, new CommandRequestInput(Contract::CAPABILITY,
        $first->encrypted_parameters, 'Second isolated cancellation target.', (string) Str::uuid(),
        CarbonImmutable::createFromTimestamp(app('request')->session()->get('auth.password_confirmed_at')), impactAcknowledged: true));
    scriptCatalogueDeny(fn () => $commands->cancel($runner, $device, $second, (object) $cancel), 409);
    expect(DB::table('endpoint_script_cancel_receipts')->count())->toBe(1)
        ->and($second->fresh()->status)->toBe(CommandStatus::AwaitingApproval)
        ->and($commands->cancellation($runner, $device, $first, $cancel['request_key'], (int) $runner->id)['receipt']->command_uuid)->toBe($first->command_uuid);
});

it('conceals cancellation targets outside the current approved Site and rejects a different device path', function () {
    [$runner, $device, $agent, $command, $attempt] = scriptPreparedTransportFixture($this);
    $foreign = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
    $site = ensureCanonicalHrStaffProfile($foreign);
    expect($site->id)->not->toBe($this->scriptSite->id);
    $foreign->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    foreach (['it.view', 'it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view',
        'securityDevices.commands.admin', EndpointScriptPolicy::RUN] as $key) {
        scriptCatalogueGrant($foreign, $key);
    }
    $service = app(EndpointScriptCommands::class);
    scriptCatalogueDeny(fn () => $service->cancel($foreign, $device, $command, (object) scriptCancellationInput($foreign, $attempt)), 404);
    $other = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint']);
    $this->actingAs($runner)->postJson('https://localhost/security-devices/devices/'.$other->id.'/commands/'.$command->id.'/script-cancellation',
        scriptCancellationInput($runner, $attempt))->assertNotFound();
    expect(DB::table('endpoint_script_cancel_receipts')->count())->toBe(0);
});

it('can deliver a stop request despite damaged catalogue metadata without granting execution', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim, $row] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    DB::table('endpoint_scripts')->where('uuid', $row['uuid'])->update(['manifest_json' => '{}']);
    expect($service->cancel($runner, $device, $command, (object) scriptCancellationInput($runner, $attempt))['receipt']->disposition)
        ->toBe('cancellation_requested');
    $poll = $service->poll($credential);
    expect($poll['command'])->toBeNull()->and($poll['cancellation'])->toBeArray();
    scriptCatalogueDeny(fn () => $service->claim($credential, $attempt->attempt_uuid, $claim), 409);
    expect($delivery->fresh()->result_hash)->toBeNull();
});

it('rechecks prepared script requester reviewer release and boot at final claim', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $this->withServerVariables(['HTTPS' => 'on'])->withToken($credential);
    $path = 'https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/claim';
    foreach ([[$runner, EndpointScriptPolicy::RUN], [$this->scriptReviewer, 'securityDevices.commands.approve'],
        [$this->scriptReviewer, EndpointScripts::REVIEW], [$this->scriptAuthor, EndpointScripts::UPLOAD]] as [$subject, $permission]) {
        scriptCatalogueGrant($subject, $permission, false);
        $this->get('https://localhost/api/endpoints/agent/scripts')->assertOk()->assertJsonPath('command', null);
        $this->postJson($path, (array) $claim)->assertConflict();
        scriptCatalogueGrant($subject, $permission);
    }
    $this->scriptReviewer->forceFill(['two_factor_confirmed_at' => null])->save();
    $this->postJson($path, (array) $claim)->assertConflict();
    $this->scriptReviewer->forceFill(['two_factor_confirmed_at' => now()])->save();
    $policy = app(EndpointScriptPolicy::class);
    app()->instance(EndpointScriptPolicy::class, new EndpointScriptPolicy);
    $this->postJson($path, (array) $claim)->assertConflict();
    app()->instance(EndpointScriptPolicy::class, $policy);
    $agent->update(['boot_id' => (string) Str::uuid()]);
    $this->postJson($path, (array) $claim)->assertConflict();
    expect($delivery->fresh()->claimed_at)->toBeNull();
    $agent->update(['boot_id' => $claim->boot_id]);
    $this->postJson($path, (array) $claim)->assertOk();
});

it('rejects ambiguous script claim HTTP and foreign credentials without a stored claim', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $this->withServerVariables(['HTTPS' => 'on'])->withToken($credential);
    $path = 'https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/claim';
    $raw = Contract::encode($claim);
    $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'],
        '{"boot_id":"'.$claim->boot_id.'",'.substr($raw, 1))->assertUnprocessable();
    $this->postJson($path.'?extra=1', (array) $claim)->assertBadRequest();
    $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $raw)->assertStatus(415);
    $bad = Contract::decode($raw);
    $bad->runtime_manifest_sha256 = str_repeat('f', 64);
    $this->postJson($path, (array) $bad)->assertUnprocessable();
    $this->get('https://localhost/api/endpoints/agent/scripts?extra=1')->assertBadRequest();
    $this->withToken(str_repeat('f', 64))->postJson($path, (array) $claim)->assertUnauthorized();
    $other = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active']);
    DeviceAssignment::create(['device_id' => $other->id, 'assignable_type' => 'site', 'assignable_id' => $this->scriptSite->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()]);
    EndpointAgent::create(['uuid' => (string) Str::uuid(), 'device_id' => $other->id, 'site_id' => $this->scriptSite->id,
        'platform' => 'windows', 'created_by_user_id' => $runner->id, 'enrolled_at' => now(),
        'assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($other),
        'credential_hash' => hash('sha256', str_repeat('f', 64))]);
    $this->postJson($path, (array) $claim)->assertNotFound();
    $agent->update(['revoked_at' => now()]);
    $this->withToken($credential)->postJson($path, (array) $claim)->assertUnauthorized();
    expect($delivery->fresh()->encrypted_claim)->toBeNull();
});

it('rejects signed script payload substitution and preserves exact prepare retries', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $original = $delivery->encrypted_payload;
    $again = DB::transaction(fn () => app(EndpointScriptCommands::class)->prepare($command, $attempt));
    expect($again->id)->toBe($delivery->id)->and($again->encrypted_payload)->toBe($original)
        ->and(EndpointDelivery::count())->toBe(1);
    $payload = Contract::decode(base64_decode($original['payload'], true));
    $payload->parameters->inputs->IncludeDetails = true;
    $substitute = app(EndpointCommandSigner::class)->sign((array) $payload);
    $delivery->forceFill(['encrypted_payload' => $substitute,
        'payload_hash' => hash('sha256', base64_decode($substitute['payload'], true))])->save();
    $this->withServerVariables(['HTTPS' => 'on'])->withToken($credential);
    $this->get('https://localhost/api/endpoints/agent/scripts')->assertOk()->assertJsonPath('command', null);
    $this->get('https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/artifact')->assertConflict();
    $this->postJson('https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/claim', (array) $claim)->assertConflict();
    expect($delivery->fresh()->claimed_at)->toBeNull();
});

it('retains the shared mutation lock after an unacknowledged script claim expires', function () {
    $this->travelTo(CarbonImmutable::now()->startOfSecond());
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    app(EndpointScriptCommands::class)->claim($credential, $attempt->attempt_uuid, $claim);
    scriptCatalogueProof($runner);
    $second = app(DeviceCommandRequestService::class)->request($device, $runner,
        new CommandRequestInput(Contract::CAPABILITY, $command->encrypted_parameters, $command->reason, (string) Str::uuid(),
            CarbonImmutable::createFromTimestamp(app('request')->session()->get('auth.password_confirmed_at')), impactAcknowledged: true));
    scriptCatalogueProof($this->scriptReviewer);
    $second = app(DeviceCommandApprovalService::class)->decide($second, $this->scriptReviewer,
        new CommandDecisionInput(CommandApprovalDecision::Approved, 'Second synthetic reviewed transport request.', $this->scriptReviewer->id));
    [$otherAttempt, $otherDelivery] = DB::transaction(function () use ($second): array {
        $second->forceFill(['status' => CommandStatus::Dispatching, 'dispatched_at' => now()])->save();
        $attempt = DeviceCommandAttempt::create(['device_command_request_id' => $second->id,
            'attempt_number' => 1, 'status' => CommandAttemptStatus::Dispatching, 'runtime' => 'endpoint']);

        return [$attempt, app(EndpointScriptCommands::class)->prepare($second, $attempt)];
    });
    $other = Contract::decode(Contract::encode($claim));
    $other->command_uuid = $second->command_uuid;
    $other->payload_hash = $otherDelivery->payload_hash;
    scriptCatalogueDeny(fn () => app(EndpointScriptCommands::class)->claim($credential, $otherAttempt->attempt_uuid, $other), 409);
    $this->travel(31)->seconds();
    scriptCatalogueDeny(fn () => app(EndpointScriptCommands::class)->claim($credential, $otherAttempt->attempt_uuid, $other), 409);
    expect($delivery->fresh()->claimed_at)->not->toBeNull()->and($otherDelivery->fresh()->claimed_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('revalidates script command authority and fresh identity before binding', function () {
    [$runner, $device, $agent, $input] = scriptCommandFixture($this);
    $service = app(DeviceCommandRequestService::class);
    scriptCatalogueGrant($runner, 'securityDevices.commands.control', false);
    scriptCatalogueDeny(fn () => $service->request($device, $runner, $input), 403);
    scriptCatalogueGrant($runner, 'securityDevices.commands.control');
    app('request')->session()->forget('endpoint_script_identity');
    scriptCatalogueDeny(fn () => $service->request($device, $runner, $input), 403);
    scriptCatalogueProof($runner);
    $agent->update(['inventory' => ['architecture' => 'arm64']]);
    scriptCatalogueDeny(fn () => $service->request($device, $runner, $input), 409);
    $agent->update(['inventory' => ['architecture' => 'amd64'], 'observed_at' => now()->subMinutes(3)]);
    scriptCatalogueDeny(fn () => $service->request($device, $runner, $input), 409);
    $agent->update(['observed_at' => now()]);
    scriptCatalogueGrant($this->scriptReviewer, EndpointScripts::REVIEW, false);
    scriptCatalogueDeny(fn () => $service->request($device, $runner, $input), 403);
    expect(DeviceCommandRequest::count())->toBe(0);
});

it('requires independent current script approval and never queues the unfinished executor', function () {
    [$runner, $device, $agent, $input] = scriptCommandFixture($this);
    $command = app(DeviceCommandRequestService::class)->request($device, $runner, $input);
    $approval = app(DeviceCommandApprovalService::class);
    $decide = fn (int $id) => new CommandDecisionInput(CommandApprovalDecision::Approved, 'Independently reviewed this exact synthetic request.', $id);
    scriptCatalogueDeny(fn () => $approval->decide($command, $this->scriptReviewer, $decide($runner->id)), 409);
    scriptCatalogueDeny(fn () => $approval->decide($command, $this->scriptReviewer, $decide($this->scriptReviewer->id)), 403);
    expect(fn () => $approval->decide($command, $runner, $decide($runner->id)))->toThrow(ValidationException::class);
    scriptCatalogueProof($this->scriptReviewer);
    $this->scriptReviewer->forceFill(['two_factor_confirmed_at' => null])->save();
    expect($this->scriptReviewer->fresh()->two_factor_confirmed_at)->toBeNull();
    scriptCatalogueDeny(fn () => $approval->decide($command, $this->scriptReviewer, $decide($this->scriptReviewer->id)), 403);
    $this->scriptReviewer->forceFill(['two_factor_confirmed_at' => now()])->save();
    scriptCatalogueProof($this->scriptReviewer);
    $command = $approval->decide($command, $this->scriptReviewer, $decide($this->scriptReviewer->id));
    expect($command->status)->toBe(CommandStatus::Ready)
        ->and($command->approved_by_user_id)->toBe($this->scriptReviewer->id);
    expect(fn () => app(DeviceCommandQueueService::class)->queue($command, $runner))->toThrow(ValidationException::class);
    expect($command->fresh()->blocked_reason_code)->toBe('script_executor_unavailable')
        ->and(DB::table('device_command_attempts')->count())->toBe(0)
        ->and(DB::table('endpoint_deliveries')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('denies script approval after binding drift while allowing an independent rejection after withdrawal', function () {
    [$runner, $device, $agent, $input, $row] = scriptCommandFixture($this);
    $command = app(DeviceCommandRequestService::class)->request($device, $runner, $input);
    scriptCatalogueProof($this->scriptReviewer);
    $agent->update(['uuid' => (string) Str::uuid()]);
    $approval = app(DeviceCommandApprovalService::class);
    scriptCatalogueDeny(fn () => $approval->decide($command, $this->scriptReviewer,
        new CommandDecisionInput(CommandApprovalDecision::Approved, 'Review of superseded computer enrolment.', $this->scriptReviewer->id)), 409);
    app(EndpointScripts::class)->transition($this->scriptReviewer, $row['uuid'], 'withdraw', scriptCatalogueInput($this->scriptReviewer, 3));
    $rejected = $approval->decide($command, $this->scriptReviewer,
        new CommandDecisionInput(CommandApprovalDecision::Rejected, 'Withdrawn script must not run on this computer.', $this->scriptReviewer->id));
    expect($rejected->status)->toBe(CommandStatus::Rejected)
        ->and(DB::table('device_command_approvals')->count())->toBe(1);
});

it('keeps script command production release closed despite synthetic readiness and a release flag', function () {
    [$runner, $device, $agent, $input] = scriptCommandFixture($this, false);
    expect(app(EndpointScriptPolicy::class)->available())->toBeFalse()
        ->and(app(EndpointScriptAuthority::class)->supports($device))->toBeFalse();
    expect(fn () => app(DeviceCommandRequestService::class)->request($device, $runner, $input))->toThrow(ValidationException::class);
    app(EndpointScriptPolicy::class)->beginIdentity(app('request'), $runner, $device, (int) $runner->id);
    expect(app('request')->session()->has('endpoint_script_identity'))->toBeTrue()
        ->and(DeviceCommandRequest::count())->toBe(0);
});

it('validates original script command HTTP bodies and account bound decisions', function () {
    [$runner, $device, $agent, $input] = scriptCommandFixture($this);
    $this->withServerVariables(['HTTPS' => 'on'])->actingAs($runner);
    $this->withSession(app('request')->session()->all());
    $path = "/security-devices/devices/{$device->id}/commands";
    $body = ['expected_actor_id' => (int) $runner->id, 'capability' => $input->capability,
        'parameters' => $input->parameters, 'reason' => $input->reason,
        'idempotency_key' => $input->idempotencyKey, 'impact_acknowledged' => true];
    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $send = fn (string $url, string $json) => $this->call('POST', $url, [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $json);
    $send($path, '{"expected_actor_id":0,'.substr($raw, 1))->assertUnprocessable();
    $send($path.'?unexpected=1', $raw)->assertBadRequest();
    $this->postJson($path, [...$body, 'expected_actor_id' => $this->scriptReviewer->id])->assertConflict();
    $send($path, $raw)->assertRedirect();
    $command = DeviceCommandRequest::sole();
    expect($command->encrypted_parameters['inputs_json'])->toBe($input->parameters['inputs_json']);
    $send($path, $raw)->assertRedirect();
    expect(DeviceCommandRequest::count())->toBe(1);
    scriptCatalogueProof($this->scriptReviewer);
    $this->actingAs($this->scriptReviewer)->withSession(app('request')->session()->all());
    $decisionPath = "/security-devices/commands/{$command->id}/decision";
    $decision = json_encode(['expected_actor_id' => $this->scriptReviewer->id, 'decision' => 'approved',
        'comment' => 'Independent review of the exact synthetic request.'], JSON_THROW_ON_ERROR);
    $send($decisionPath, '{"decision":"rejected",'.substr($decision, 1))->assertUnprocessable();
    $this->postJson($decisionPath, ['decision' => 'approved', 'comment' => 'Missing account binding must be denied.'])->assertUnprocessable();
    $send($decisionPath, $decision)->assertRedirect();
    expect($command->fresh()->status)->toBe(CommandStatus::Ready)
        ->and(DB::table('device_command_approvals')->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('keeps exact encrypted immutable bytes and independent retryable catalogue receipts', function () {
    $service = app(EndpointScripts::class);
    $input = scriptCatalogueInput($this->scriptAuthor);
    $uploaded = scriptCatalogueUpload($this, $input);
    $row = $uploaded['script'];
    $model = EndpointScript::where('uuid', $row['uuid'])->firstOrFail();
    expect(Crypt::decryptString($model->artifact_ciphertext))->toBe($this->scriptBytes)
        ->and($model->artifact_ciphertext)->not->toContain('Synthetic catalogue fixture')
        ->and($model->toArray())->not->toHaveKeys(['artifact_ciphertext', 'runtime_ciphertext', 'manifest_json']);
    expect(scriptCatalogueUpload($this, $input))->toEqual($uploaded);
    scriptCatalogueDeny(fn () => scriptCatalogueUpload($this, [...$input, 'reason' => 'Different intent for the same request key.']), 409);
    expect(EndpointScript::count())->toBe(1);
    expect(fn () => $model->forceFill(['manifest_json' => '{}'])->save())->toThrow(LogicException::class);
    expect(fn () => $model->delete())->toThrow(LogicException::class);
    $submit = scriptCatalogueInput($this->scriptAuthor, 1);
    $pending = $service->transition($this->scriptAuthor, $row['uuid'], 'submit', $submit);
    expect($pending['script']['status'])->toBe('pending_review')->and($service->transition($this->scriptAuthor, $row['uuid'], 'submit', $submit))->toEqual($pending);
    scriptCatalogueProof($this->scriptAuthor);
    scriptCatalogueDeny(fn () => $service->transition($this->scriptAuthor, $row['uuid'], 'review', scriptCatalogueInput($this->scriptAuthor, 2, $row['content_hash'])), 403);
    app('request')->session()->forget('endpoint_script_identity');
    $review = scriptCatalogueInput($this->scriptReviewer, 2, $row['content_hash']);
    scriptCatalogueDeny(fn () => $service->transition($this->scriptReviewer, $row['uuid'], 'review', $review), 403);
    scriptCatalogueProof($this->scriptReviewer);
    $material = $service->material($this->scriptReviewer, $row['uuid'], (int) $this->scriptReviewer->id);
    expect($material['artifact'])->toBe($this->scriptBytes)->and($material['runtime_manifest'])->toBe($this->scriptRuntime)
        ->and(DB::table('endpoint_script_material_reads')->count())->toBe(1);
    $published = $service->transition($this->scriptReviewer, $row['uuid'], 'review', $review);
    expect($published['script']['status'])->toBe('published')->and($service->transition($this->scriptReviewer, $row['uuid'], 'review', $review))->toEqual($published);
    $withdraw = scriptCatalogueInput($this->scriptReviewer, 3);
    $withdrawn = $service->transition($this->scriptReviewer, $row['uuid'], 'withdraw', $withdraw);
    expect($withdrawn['script']['status'])->toBe('withdrawn')->and($service->transition($this->scriptReviewer, $row['uuid'], 'withdraw', $withdraw))->toEqual($withdrawn);
    $oldReceipt = $service->transition($this->scriptReviewer, $row['uuid'], 'review', $review);
    expect($oldReceipt['receipt']['status'])->toBe('published')->and($oldReceipt['script']['status'])->toBe('withdrawn');
    expect($service->recoverUpload($this->scriptAuthor, $input['request_key'], (int) $this->scriptAuthor->id)['script']['status'])->toBe('withdrawn');
    expect(DB::table('endpoint_script_events')->count())->toBe(4)->and(config('endpoint_management.scripts.release_enabled'))->toBeFalse();
});

it('rechecks cached account grants and author eligibility before independent publication', function () {
    $service = app(EndpointScripts::class);
    $row = scriptCataloguePending($this);
    scriptCatalogueProof($this->scriptReviewer);
    expect($this->scriptAuthor->canDo(EndpointScripts::UPLOAD))->toBeTrue();
    scriptCatalogueGrant($this->scriptAuthor, EndpointScripts::UPLOAD, false);
    scriptCatalogueDeny(fn () => $service->transition($this->scriptReviewer, $row['uuid'], 'review', scriptCatalogueInput($this->scriptReviewer, 2, $row['content_hash'])), 403);
    scriptCatalogueGrant($this->scriptAuthor, EndpointScripts::UPLOAD);
    $published = $service->transition($this->scriptReviewer, $row['uuid'], 'review', scriptCatalogueInput($this->scriptReviewer, 2, $row['content_hash']));
    expect($published['script']['status'])->toBe('published');
    scriptCatalogueGrant($this->scriptReviewer, EndpointScripts::REVIEW, false);
    scriptCatalogueDeny(fn () => $service->transition($this->scriptReviewer, $row['uuid'], 'withdraw', scriptCatalogueInput($this->scriptReviewer, 3)), 403);
    scriptCatalogueGrant($this->scriptReviewer, EndpointScripts::REVIEW);
    $this->scriptAuthor->forceFill(['approved_at' => null])->save();
    scriptCatalogueDeny(fn () => scriptCatalogueUpload($this), 403);
    // An ineligible author must not prevent an eligible reviewer withdrawing.
    expect($service->transition($this->scriptReviewer, $row['uuid'], 'withdraw', scriptCatalogueInput($this->scriptReviewer, 3))['script']['status'])->toBe('withdrawn');
});

it('denies unavailable runtimes and unimplemented independent observations', function () {
    $service = app(EndpointScripts::class);
    $row = scriptCataloguePending($this);
    scriptCatalogueProof($this->scriptReviewer);
    $review = scriptCatalogueInput($this->scriptReviewer, 2, $row['content_hash']);
    config()->set('endpoint_management.scripts.reviewed_runtimes', []);
    scriptCatalogueDeny(fn () => $service->transition($this->scriptReviewer, $row['uuid'], 'review', $review), 503);
    config()->set('endpoint_management.scripts.reviewed_runtimes', $this->scriptApprovedRuntime);
    expect($service->transition($this->scriptReviewer, $row['uuid'], 'review', $review)['script']['status'])->toBe('published');
    $this->scriptReview->effect = 'mutating';
    $this->scriptMetadata->execution_policy->expected_observation_schema = str_repeat('d', 64);
    $mutating = scriptCataloguePending($this);
    scriptCatalogueDeny(fn () => $service->transition($this->scriptReviewer, $mutating['uuid'], 'review', scriptCatalogueInput($this->scriptReviewer, 2, $mutating['content_hash'])), 503);
    expect(EndpointScript::where('uuid', $mutating['uuid'])->value('status'))->toBe('pending_review');
});

it('refuses damaged bytes and stale content while preserving withdrawal', function () {
    $service = app(EndpointScripts::class);
    $row = scriptCataloguePending($this);
    scriptCatalogueProof($this->scriptReviewer);
    scriptCatalogueDeny(fn () => $service->transition($this->scriptReviewer, $row['uuid'], 'review', scriptCatalogueInput($this->scriptReviewer, 2, str_repeat('f', 64))), 409);
    DB::table('endpoint_scripts')->where('uuid', $row['uuid'])->update(['artifact_ciphertext' => 'damaged']);
    scriptCatalogueDeny(fn () => $service->material($this->scriptReviewer, $row['uuid'], (int) $this->scriptReviewer->id), 409);
    scriptCatalogueDeny(fn () => $service->transition($this->scriptReviewer, $row['uuid'], 'review', scriptCatalogueInput($this->scriptReviewer, 2, $row['content_hash'])), 409);
    expect(DB::table('endpoint_script_material_reads')->count())->toBe(0);
    config()->set('endpoint_management.scripts.reviewed_runtimes', []);
    expect($service->transition($this->scriptReviewer, $row['uuid'], 'withdraw', scriptCatalogueInput($this->scriptReviewer, 2))['script']['status'])->toBe('withdrawn');
    DB::table('endpoint_scripts')->where('uuid', $row['uuid'])->update(['manifest_json' => '{}']);
    scriptCatalogueDeny(fn () => $service->show($this->scriptReviewer, $row['uuid'], (int) $this->scriptReviewer->id), 409);
});

it('rejects malformed metadata and review intent before persisting anything', function () {
    $service = app(EndpointScripts::class);
    $meta = Contract::encode($this->scriptMetadata);
    foreach ([str_replace('"parameter_schema":[]', '"parameter_schema":true', $meta), str_replace('"name":', '"name":"duplicate","name":', $meta), str_replace('"architecture":"amd64"', '"architecture":"x86"', $meta)] as $bad) {
        scriptCatalogueDeny(fn () => $service->upload($this->scriptAuthor, scriptCatalogueInput($this->scriptAuthor), $bad, Contract::encode($this->scriptReview), $this->scriptBytes, $this->scriptRuntime), 422);
    }
    $this->scriptReview->no_dynamic_code = false;
    scriptCatalogueDeny(fn () => scriptCatalogueUpload($this), 422);
    expect(EndpointScript::count())->toBe(0)->and(DB::table('endpoint_script_events')->count())->toBe(0);
});

it('keeps direct reads owner scoped and rejects changed actor or Site evidence', function () {
    $service = app(EndpointScripts::class);
    $row = scriptCatalogueUpload($this)['script'];
    scriptCatalogueGrant($this->scriptReviewer, EndpointScripts::REVIEW, false);
    expect(fn () => $service->show($this->scriptReviewer, $row['uuid'], (int) $this->scriptReviewer->id))->toThrow(ModelNotFoundException::class);
    scriptCatalogueGrant($this->scriptReviewer, EndpointScripts::REVIEW);
    expect($service->show($this->scriptReviewer, $row['uuid'], (int) $this->scriptReviewer->id)['script']['uuid'])->toBe($row['uuid']);
    scriptCatalogueDeny(fn () => $service->show($this->scriptReviewer, $row['uuid'], (int) $this->scriptAuthor->id), 409);
    $this->scriptSite->forceFill(['is_active' => false])->save();
    scriptCatalogueDeny(fn () => $service->show($this->scriptReviewer, $row['uuid'], (int) $this->scriptReviewer->id), 403);
    $this->scriptSite->forceFill(['is_active' => true])->save();
    expect($service->show($this->scriptReviewer, $row['uuid'], (int) $this->scriptReviewer->id)['script']['uuid'])->toBe($row['uuid']);
});

it('requires script-specific fresh identity evidence and explicit role grants', function () {
    foreach (['admin', 'it_manager'] as $role) {
        expect(Role::where('name', $role)->firstOrFail()->permissions()->whereIn('key', [EndpointScripts::UPLOAD, EndpointScripts::REVIEW])->count())->toBe(0);
    }
    $service = app(EndpointScripts::class);
    $row = scriptCataloguePending($this);
    scriptCatalogueProof($this->scriptReviewer);
    $request = app('request');
    $proof = $request->session()->get('endpoint_script_identity');
    $request->session()->forget('endpoint_script_identity');
    $request->session()->put('endpoint_software_identity', $proof);
    scriptCatalogueDeny(fn () => $service->material($this->scriptReviewer, $row['uuid'], (int) $this->scriptReviewer->id), 403);
    $request->session()->put('endpoint_script_identity', $proof);
    $request->session()->put('auth.password_confirmed_at', now()->subSeconds(901)->timestamp);
    scriptCatalogueDeny(fn () => $service->material($this->scriptReviewer, $row['uuid'], (int) $this->scriptReviewer->id), 403);
    scriptCatalogueProof($this->scriptReviewer);
    expect($service->material($this->scriptReviewer, $row['uuid'], (int) $this->scriptReviewer->id)['artifact'])->toBe($this->scriptBytes);
    $this->scriptReviewer->forceFill(['password' => 'changed-test-password'])->save();
    scriptCatalogueDeny(fn () => $service->material($this->scriptReviewer, $row['uuid'], (int) $this->scriptReviewer->id), 403);
});

it('serves the real private HTTP lifecycle and immutable action recovery', function () {
    $base = 'https://localhost/security-devices/scripts';
    $this->actingAs($this->scriptAuthor);
    $input = scriptCatalogueInput($this->scriptAuthor);
    $body = [...$input, 'metadata' => $this->scriptMetadata, 'review' => $this->scriptReview,
        'artifact_base64' => base64_encode($this->scriptBytes), 'runtime_manifest_base64' => base64_encode($this->scriptRuntime)];
    $uploaded = $this->postJson($base, $body)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->json();
    $uuid = $uploaded['script']['uuid'];
    $ownerQuery = '?expected_actor_id='.$this->scriptAuthor->id;
    $readerQuery = '?expected_actor_id='.$this->scriptReviewer->id;
    $read = $this->get($base.'/'.$uuid.$ownerQuery, ['Accept' => 'application/json'])->assertOk();
    expect($read->headers->get('Cache-Control'))->toContain('no-store')->and($read->json('script'))->toEqual($uploaded['script']);
    $this->get($base.'/uploads/'.$input['request_key'].$ownerQuery, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('receipt.request_key', $input['request_key']);
    $pending = $this->postJson($base.'/'.$uuid.'/submit', scriptCatalogueInput($this->scriptAuthor, 1))->assertOk()->json();
    $this->actingAs($this->scriptReviewer);
    $this->get($base.'/'.$uuid.'/material'.$readerQuery, ['Accept' => 'application/json'])->assertForbidden();
    $this->get($base.'/confirm-identity'.$readerQuery)->assertRedirect(route('password.confirm'))->assertSessionHas('endpoint_script_identity')->assertSessionMissing('auth.password_confirmed_at');
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $material = $this->get($base.'/'.$uuid.'/material'.$readerQuery, ['Accept' => 'application/json'])->assertOk()->json();
    expect($material['artifact'])->toBe($this->scriptBytes);
    $pendingIndex = $this->get($base.$readerQuery, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('scripts.0.can_review', true)->json();
    $review = scriptCatalogueInput($this->scriptReviewer, 2, $uploaded['script']['content_hash']);
    $published = $this->postJson($base.'/'.$uuid.'/review', $review)->assertOk()->assertJsonPath('script.status', 'published')->json();
    $this->get($base.'/'.$uuid.'/requests/'.$review['request_key'].$readerQuery, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('receipt.status', 'published');
    $publishedIndex = $this->get($base.$readerQuery, ['Accept' => 'application/json'])->assertOk()->json();
    $withdrawn = $this->postJson($base.'/'.$uuid.'/withdraw', scriptCatalogueInput($this->scriptReviewer, 3))->assertOk()->assertJsonPath('script.status', 'withdrawn')->json();
    $this->get($base.'/'.$uuid.'/requests/'.$review['request_key'].$readerQuery, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('receipt.status', 'published')->assertJsonPath('script.status', 'withdrawn');
    $withdrawnIndex = $this->get($base.$readerQuery, ['Accept' => 'application/json'])->assertOk()->json();
    if ($path = getenv('OBLI_SCRIPT_CATALOGUE_EXPORT')) {
        file_put_contents($path, json_encode(['uploaded' => $uploaded, 'pending' => $pending, 'material' => $material, 'published' => $published, 'withdrawn' => $withdrawn,
            'index_pending' => $pendingIndex, 'index_published' => $publishedIndex, 'index_withdrawn' => $withdrawnIndex], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
});

it('rejects ambiguous HTTP queries bodies and insecure private reads', function () {
    $base = 'https://localhost/security-devices/scripts';
    $this->actingAs($this->scriptAuthor);
    $uuid = scriptCatalogueUpload($this)['script']['uuid'];
    $id = $this->scriptAuthor->id;
    foreach ([
        'expected_actor_id='.$id.'&expected_actor_id='.$id,
        'expected_actor_id='.$id.'&%65xpected_actor_id='.$id,
        'expected_actor_id[]='.$id, 'expected_actor_id='.$id.'&extra=1',
        'expected_actor_id='.$id.'&', 'expected_actor_id=0', 'expected_actor_id=%ZZ',
    ] as $query) {
        $this->get($base.'/'.$uuid.'?'.$query, ['Accept' => 'application/json'])->assertStatus(400);
    }
    $this->get($base.'/'.$uuid, ['Accept' => 'application/json'])->assertStatus(400);
    $this->getJson($base.'/'.$uuid.'?expected_actor_id='.$id)->assertStatus(400); // getJson injects [] as a GET body.
    $this->get('http://localhost/security-devices/scripts/'.$uuid.'?expected_actor_id='.$id, ['Accept' => 'application/json'])->assertStatus(400);
    $this->get($base.'/'.$uuid.'?expected_actor_id='.$this->scriptReviewer->id, ['Accept' => 'application/json'])->assertConflict();
    $input = scriptCatalogueInput($this->scriptAuthor, 1);
    $raw = json_encode($input, JSON_THROW_ON_ERROR);
    $duplicate = str_replace('"expected_actor_id":', '"expected_actor_id":999,"expected_actor_id":', $raw);
    $this->call('POST', $base.'/'.$uuid.'/submit', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $duplicate)->assertStatus(422);
    $this->postJson($base.'/'.$uuid.'/submit?expected_actor_id='.$id, $input)->assertStatus(400);
    $this->post($base.'/'.$uuid.'/submit', $input, ['Accept' => 'application/json'])->assertStatus(415);
    expect(EndpointScript::where('uuid', $uuid)->value('status'))->toBe('uploaded');
});

it('paginates current visible records and treats search wildcards literally', function () {
    for ($i = 0; $i < 21; $i++) {
        $this->scriptMetadata->name = match ($i) {
            0 => '100% complete', 1 => '100X complete', 2 => 'under_score', 3 => 'underXscore', default => 'Inspection '.$i,
        };
        scriptCatalogueUpload($this);
    }
    $this->actingAs($this->scriptReviewer);
    $base = 'https://localhost/security-devices/scripts?expected_actor_id='.$this->scriptReviewer->id;
    $first = $this->get($base, ['Accept' => 'application/json'])->assertOk()->assertJsonCount(20, 'scripts')->json();
    // Generated links include search=; global middleware converts that parsed
    // value to null. The raw closed query must retain the original empty text.
    expect($first['pagination']['next_href'])->toContain('search=&cursor=');
    $second = $this->get('https://localhost'.$first['pagination']['next_href'], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(1, 'scripts')->json();
    $back = $this->get('https://localhost'.$second['pagination']['previous_href'], ['Accept' => 'application/json'])->assertOk()->json();
    expect(array_column($first['scripts'], 'uuid'))->toBe(array_column($back['scripts'], 'uuid'));
    $this->get($base.'&search='.rawurlencode('100%'), ['Accept' => 'application/json'])->assertOk()->assertJsonCount(1, 'scripts')->assertJsonPath('scripts.0.manifest.name', '100% complete');
    $this->get($base.'&search='.rawurlencode('under_'), ['Accept' => 'application/json'])->assertOk()->assertJsonCount(1, 'scripts')->assertJsonPath('scripts.0.manifest.name', 'under_score');
    $this->get($base.'&cursor=invalid', ['Accept' => 'application/json'])->assertStatus(400);
    $this->get($base.'&status=unknown', ['Accept' => 'application/json'])->assertStatus(400);
    $cursor = rtrim(strtr(base64_encode('{"id":999999999,"forward":true}'), '+/', '-_'), '=');
    $this->get($base.'&cursor='.$cursor, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('pagination.position_reset', true)->assertJsonCount(20, 'scripts');
    scriptCatalogueGrant($this->scriptReviewer, EndpointScripts::REVIEW, false);
    $this->get($base, ['Accept' => 'application/json'])->assertOk()->assertJsonCount(0, 'scripts')->assertJsonPath('summary.uploaded', 0)->assertJsonPath('pagination.next_href', null);
});

it('does not resolve another actor receipt or admit noncanonical binary uploads', function () {
    $this->actingAs($this->scriptAuthor);
    $base = 'https://localhost/security-devices/scripts';
    $input = scriptCatalogueInput($this->scriptAuthor);
    $body = [...$input, 'metadata' => $this->scriptMetadata, 'review' => $this->scriptReview,
        'artifact_base64' => base64_encode($this->scriptBytes), 'runtime_manifest_base64' => base64_encode($this->scriptRuntime)];
    $this->postJson($base, [...$body, 'artifact_base64' => $body['artifact_base64']."\n"])->assertStatus(422);
    $this->postJson($base, [...$body, 'expected_actor_id' => (string) $this->scriptAuthor->id])->assertStatus(422);
    $uploaded = $this->postJson($base, $body)->assertOk()->json('script');
    $this->actingAs($this->scriptReviewer);
    $query = '?expected_actor_id='.$this->scriptReviewer->id;
    $this->get($base.'/uploads/'.$input['request_key'].$query, ['Accept' => 'application/json'])->assertNotFound();
    $this->get($base.'/'.$uploaded['uuid'].'/requests/'.$input['request_key'].$query, ['Accept' => 'application/json'])->assertNotFound();
    $this->get($base.'/'.$uploaded['uuid'].$query, ['Accept' => 'application/json'])->assertOk();
    scriptCatalogueGrant($this->scriptReviewer, EndpointScripts::REVIEW, false);
    scriptCatalogueGrant($this->scriptReviewer, EndpointScripts::UPLOAD, false);
    $this->get($base.'/'.$uploaded['uuid'].$query, ['Accept' => 'application/json'])->assertForbidden();
    expect(EndpointScript::count())->toBe(1);
});

it('derives script navigation from current account grants and approved Site evidence', function () {
    $service = app(EndpointScripts::class);
    $actor = $this->scriptAuthor;
    expect($service->canOpen($actor))->toBeTrue();
    scriptCatalogueGrant($actor, 'securityDevices.devices.view');
    $this->actingAs($actor)->get('https://localhost/it/remote-support')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('it/remote-support/index')
            ->where('can_view_script_catalogue', true));
    // Existing cached actor instances do not retain a removed grant.
    scriptCatalogueGrant($actor, EndpointScripts::UPLOAD, false);
    scriptCatalogueGrant($actor, EndpointScripts::REVIEW, false);
    expect($service->canOpen($actor))->toBeFalse();
    $this->get('https://localhost/it/remote-support')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can_view_script_catalogue', false));
    scriptCatalogueGrant($actor, EndpointScripts::REVIEW);
    expect($service->canOpen($actor))->toBeTrue();
    User::whereKey($actor->id)->update(['two_factor_confirmed_at' => null]);
    expect($service->canOpen($actor))->toBeFalse();
    User::whereKey($actor->id)->update(['two_factor_confirmed_at' => now()]);
    $this->scriptSite->forceFill(['is_active' => false])->save();
    expect($service->canOpen($actor))->toBeFalse();
});

it('revalidates candidate requester author reviewer and current computer policy without granting execution', function () {
    [$runner, $device, $assignment, $row] = scriptExecutionCandidateFixture($this);
    $service = app(EndpointScripts::class);
    $calls = 0;
    $read = function () use ($runner, $device, $row, $service, &$calls): array {
        return $service->withExecutionCandidate($runner, $device, (int) $runner->id, $row['uuid'], $row['content_hash'], 1,
            (object) ['IncludeDetails' => false],
            function (User $current, Device $locked, array $candidate) use ($runner, $device, &$calls): array {
                expect(DB::transactionLevel())->toBeGreaterThan(0)
                    ->and($current->id)->toBe($runner->id)->and($locked->id)->toBe($device->id);
                $calls++;

                return $candidate;
            });
    };
    $candidate = $read();
    expect($candidate['parameters']->script_uuid)->toBe($row['uuid'])
        ->and($candidate['parameters']->inputs->IncludeDetails)->toBeFalse()
        ->and($candidate['policy'])->toBe(['version' => 1, 'enabled' => true, 'consent_mode' => 'attended'])
        ->and($candidate['content_hash'])->toBe($row['content_hash'])
        ->and(strlen($candidate['assignment_fingerprint']))->toBe(64)
        ->and(Contract::encode((object) $candidate))->not->toContain('Synthetic catalogue fixture');
    foreach ([[$runner, EndpointScriptPolicy::RUN], [$this->scriptAuthor, EndpointScripts::UPLOAD],
        [$this->scriptReviewer, EndpointScripts::REVIEW]] as [$subject, $permission]) {
        scriptCatalogueGrant($subject, $permission, false);
        scriptCatalogueDeny($read, 403);
        scriptCatalogueGrant($subject, $permission);
        User::whereKey($subject->id)->update(['two_factor_confirmed_at' => null]);
        scriptCatalogueDeny($read, 403);
        User::whereKey($subject->id)->update(['two_factor_confirmed_at' => now()]);
    }
    $this->scriptSite->forceFill(['is_active' => false])->save();
    scriptCatalogueDeny($read, 403);
    $this->scriptSite->forceFill(['is_active' => true])->save();
    $assignment->forceFill(['assigned_at' => now()->subSeconds(30)])->save();
    scriptCatalogueDeny($read, 409);
    $assignment->forceFill(['assigned_at' => now()->subMinute()])->save();
    // A stale actor object and explicit actor mismatch never reach the callback.
    scriptCatalogueDeny(fn () => $service->withExecutionCandidate($runner, $device, (int) $this->scriptAuthor->id,
        $row['uuid'], $row['content_hash'], 1, (object) ['IncludeDetails' => false], fn () => throw new LogicException), 409);
    expect($calls)->toBe(1)
        ->and(app(EndpointScriptPolicy::class)->available())->toBeFalse()
        ->and(DB::table('device_command_requests')->count())->toBe(0)
        ->and(DB::table('endpoint_deliveries')->count())->toBe(0);
});

it('rejects altered or withdrawn candidate material runtime inputs and unattended authority', function () {
    [$runner, $device, $assignment, $row] = scriptExecutionCandidateFixture($this);
    $service = app(EndpointScripts::class);
    $calls = 0;
    $read = function (int $version = 1, ?stdClass $inputs = null, ?string $hash = null) use ($runner, $device, $row, $service, &$calls): array {
        return $service->withExecutionCandidate($runner, $device, (int) $runner->id, $row['uuid'], $hash ?? $row['content_hash'],
            $version, $inputs ?? (object) ['IncludeDetails' => false],
            function (User $actor, Device $computer, array $candidate) use (&$calls): array {
                $calls++;

                return $candidate;
            });
    };
    scriptCatalogueDeny(fn () => $read(2), 409);
    scriptCatalogueDeny(fn () => $read(hash: str_repeat('f', 64)), 409);
    scriptCatalogueDeny(fn () => $read(inputs: (object) ['IncludeDetails' => 'false']), 422);
    scriptCatalogueDeny(fn () => $read(inputs: (object) ['IncludeDetails' => false, 'extra' => true]), 422);
    scriptCatalogueDeny(fn () => $read(inputs: new stdClass), 422);
    config()->set('endpoint_management.scripts.reviewed_runtimes', []);
    scriptCatalogueDeny(fn () => $read(), 503);
    config()->set('endpoint_management.scripts.reviewed_runtimes', $this->scriptApprovedRuntime);
    $model = EndpointScript::where('uuid', $row['uuid'])->firstOrFail();
    $original = $model->artifact_ciphertext;
    DB::table('endpoint_scripts')->where('id', $model->id)->update(['artifact_ciphertext' => Crypt::encryptString('altered')]);
    scriptCatalogueDeny(fn () => $read(), 409);
    DB::table('endpoint_scripts')->where('id', $model->id)->update(['artifact_ciphertext' => $original]);
    DB::table('endpoint_script_events')->where('endpoint_script_id', $model->id)->where('status_version', 3)->update(['actor_user_id' => $runner->id]);
    scriptCatalogueDeny(fn () => $read(), 409);
    DB::table('endpoint_script_events')->where('endpoint_script_id', $model->id)->where('status_version', 3)->update(['actor_user_id' => $this->scriptReviewer->id]);
    scriptCatalogueGrant($runner, EndpointScriptPolicy::MANAGE);
    scriptCatalogueGrant($runner, EndpointScriptPolicy::UNATTENDED);
    $policy = app(EndpointScriptPolicy::class);
    $policy->save($runner, $device, ['expected_actor_id' => (int) $runner->id, 'expected_version' => 1,
        'request_key' => (string) Str::uuid(), 'enabled' => true, 'consent_mode' => 'unattended', 'reason' => 'Synthetic unattended candidate configuration.']);
    scriptCatalogueGrant($runner, EndpointScriptPolicy::UNATTENDED, false);
    scriptCatalogueDeny(fn () => $read(2), 403);
    scriptCatalogueGrant($runner, EndpointScriptPolicy::UNATTENDED);
    expect($read(2)['policy']['consent_mode'])->toBe('unattended');
    $policy->save($runner, $device, ['expected_actor_id' => (int) $runner->id, 'expected_version' => 2,
        'request_key' => (string) Str::uuid(), 'enabled' => false, 'consent_mode' => 'attended', 'reason' => 'Synthetic candidate disabled configuration.']);
    scriptCatalogueDeny(fn () => $read(3), 409);
    scriptCatalogueProof($this->scriptReviewer);
    $service->transition($this->scriptReviewer, $row['uuid'], 'withdraw', scriptCatalogueInput($this->scriptReviewer, 3));
    scriptCatalogueDeny(fn () => $read(3), 409);
    expect($calls)->toBe(1)->and(DB::table('device_command_requests')->count())->toBe(0);
});

it('selects current script poll candidates despite an older repeatable read snapshot', function (bool $cancellation, array $change) {
    $manager = DB::getFacadeRoot();
    $default = $manager->getDefaultConnection();
    $configuration = config('database.connections.'.$default);
    expect($configuration['driver'])->toBe('mysql')->and($configuration['database'])->toContain('_codex_test_');
    $configuration['prefix'] = 'script_poll_rr_'.strtolower(Str::random(8)).'_';
    $configuration['isolation_level'] = 'REPEATABLE READ';
    config()->set('database.connections.script_poll_a', $configuration);
    config()->set('database.connections.script_poll_b', $configuration);
    $a = DB::connection('script_poll_a');
    $b = DB::connection('script_poll_b');
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
            foreach (['claimed_at', 'completed_at', 'delivered_at', 'cancel_requested_at', 'expires_at'] as $column) {
                $t->timestamp($column)->nullable();
            }
            $t->string('result_hash')->nullable();
            $t->json('result')->nullable();
        });
        $b->table('device_command_requests')->insert(['id' => 1, 'capability' => Contract::CAPABILITY]);
        $b->table('device_command_attempts')->insert(['id' => 1, 'device_command_request_id' => 1]);
        $b->table('endpoint_deliveries')->insert(['id' => 1, 'endpoint_agent_id' => 7, 'agent_uuid' => 'agent-current',
            'device_command_attempt_id' => 1, 'expires_at' => now()->addHour(),
            'claimed_at' => $cancellation ? now() : null, 'cancel_requested_at' => $cancellation ? now() : null]);
        $manager->setDefaultConnection('script_poll_a');
        $agent = (new EndpointAgent)->forceFill(['id' => 7, 'uuid' => 'agent-current']);
        $service = app(EndpointScriptCommands::class);
        $select = new ReflectionMethod($service, 'pollCandidates');
        // A positive current read first, with its locks released before the writer.
        expect($a->transaction(fn () => $select->invoke($service, $agent, $cancellation)->modelKeys()))->toBe([1]);
        $a->beginTransaction();
        $snapshot = (array) $a->table('endpoint_deliveries')->first();
        $b->table('endpoint_deliveries')->where('id', 1)->update(array_map(fn ($value) => $value === '@now' ? now() : $value, $change));
        expect((array) $a->table('endpoint_deliveries')->first())->toBe($snapshot);
        // The actual selector used by poll must bypass the older consistent snapshot.
        expect($select->invoke($service, $agent, $cancellation)->modelKeys())->toBe([]);
    } finally {
        if ($a->transactionLevel() > 0) {
            $a->rollBack();
        }
        $manager->setDefaultConnection($default);
        foreach (['endpoint_deliveries', 'device_command_attempts', 'device_command_requests'] as $table) {
            $schema->dropIfExists($table);
        }
        DB::purge('script_poll_a');
        DB::purge('script_poll_b');
    }
})->with([
    'new cancellation excludes launch' => [false, ['cancel_requested_at' => '@now']],
    'new claim excludes launch' => [false, ['claimed_at' => '@now']],
    'new completion excludes launch' => [false, ['completed_at' => '@now']],
    'settled nonentry excludes stop' => [true, ['result_hash' => str_repeat('a', 64), 'result' => '{"status":"cancelled","mutation_started":false}']],
]);

function scriptOutputHeader(DeviceCommandRequest $command, EndpointDelivery $delivery, string $bytes, bool $truncated = false): object
{
    return (object) ['command_uuid' => $command->command_uuid, 'payload_hash' => $delivery->payload_hash,
        'sha256' => $bytes === '' ? null : hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'truncated' => $truncated];
}

function scriptOutputPost($test, string $path, string $credential, object $header, string $bytes, array $server = [])
{
    return $test->call('POST', $path, [], [], [], array_replace([
        'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$credential,
        'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_ENDPOINT_SCRIPT_OUTPUT' => base64_encode(Contract::encode($header)),
    ], $server), $bytes);
}

it('stores original native output privately with immutable HTTP recovery and audited separate read authority', function () {
    $this->travelTo(CarbonImmutable::now()->startOfSecond());
    // Written by the Go test TestScriptOutputNormalizationAndBudget with
    // OBLI_SCRIPT_OUTPUT_EXPORT; set OBLI_SCRIPT_NATIVE_OUTPUT to use a fresh one.
    $path = getenv('OBLI_SCRIPT_NATIVE_OUTPUT') ?: base_path('tests/fixtures/endpoint-script-native-output.json');
    $native = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    $witnesses = [];
    foreach ($native as $case) {
        scriptCatalogueProof($this->scriptAuthor);
        app()->instance(EndpointScriptPolicy::class, new EndpointScriptPolicy);
        $this->scriptMetadata->execution_policy->output_bytes = $case['limit'];
        [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
        $credential = hash('sha256', $attempt->attempt_uuid);
        $agent->update(['credential_hash' => hash('sha256', $credential)]);
        $service = app(EndpointScriptCommands::class);
        $service->claim($credential, $attempt->attempt_uuid, $claim);
        $bytes = base64_decode($case['body_base64'], true);
        $header = scriptOutputHeader($command, $delivery, $bytes, $case['truncated']);
        $report = scriptResultFixture($command, $delivery, $agent, ['output_sha256' => $case['sha256'], 'output_bytes' => $case['size_bytes'], 'output_truncated' => $case['truncated']]);
        $service->result($credential, $attempt->attempt_uuid, (object) $report);
        $http = 'https://localhost/api/endpoints/agent/scripts/'.strtoupper($attempt->attempt_uuid).'/output';
        $receipt = scriptOutputPost($this, $http, $credential, $header, $bytes)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->json();
        $stored = DB::table('endpoint_script_outputs')->where('endpoint_delivery_id', $delivery->id)->first();
        expect(Crypt::decryptString($stored->encrypted_content))->toBe($bytes)
            ->and($receipt['attempt_uuid'])->toBe($attempt->attempt_uuid)
            ->and($receipt['size_bytes'])->toBe($case['size_bytes'])
            ->and($attempt->fresh()->safe_result_summary['output_received'])->toBeFalse();
        $this->travel(1)->seconds();
        expect(scriptOutputPost($this, strtolower($http), $credential, (object) array_reverse(get_object_vars($header), true), $bytes)->assertOk()->json())->toBe($receipt)
            ->and(DB::table('endpoint_script_outputs')->where('id', $stored->id)->first()->encrypted_content)->toBe($stored->encrypted_content);
        $read = 'https://localhost/security-devices/devices/'.$device->id.'/commands/'.$command->id.'/script-output/'.strtoupper($attempt->attempt_uuid);
        $this->actingAs($runner)->get($read.'?expected_actor_id='.$runner->id)->assertForbidden();
        scriptCatalogueGrant($runner, EndpointScriptOutputs::VIEW);
        $meta = $this->get($read.'?expected_actor_id='.$runner->id)->assertOk()->assertJsonPath('content_available', true)->json();
        expect($meta)->not->toHaveKey('bytes');
        $download = $this->get($read.'/content?expected_actor_id='.$runner->id)->assertOk();
        expect($download->getContent())->toBe($bytes)->and($download->headers->get('Cache-Control'))->toContain('no-store')
            ->and($download->headers->get('Content-Disposition'))->toContain('attachment');
        $witnesses[] = ['name' => $case['name'], 'public_key' => app(EndpointCommandSigner::class)->publicKey(), 'agent_uuid' => $agent->uuid,
            'command' => $delivery->encrypted_payload, 'header' => $header, 'result' => $report, 'body_base64' => base64_encode($download->getContent()), 'receipt' => $receipt];
    }
    expect(count($witnesses))->toBe(10)
        ->and(DB::table('device_command_audit_events')->where('action', 'endpoint_script_output_received')->count())->toBe(10)
        ->and(DB::table('device_command_audit_events')->where('action', 'endpoint_script_output_read')->count())->toBe(10);
    $outputs = app(EndpointScriptOutputs::class);
    expect($outputs->purge(3))->toBe(0);
    $this->travel(25)->hours();
    expect($outputs->purge(3))->toBe(3)
        ->and(DB::table('endpoint_script_outputs')->whereNotNull('encrypted_content')->count())->toBe(7)
        ->and($outputs->purge(500))->toBe(7)
        ->and(DB::table('endpoint_script_outputs')->count())->toBe(10);
    if ($export = getenv('OBLI_SCRIPT_OUTPUT_HTTP_EXPORT')) {
        file_put_contents($export, Contract::encode($witnesses));
    }
    Queue::assertNothingPushed();
});

it('erases expired script content while preserving exact receipts and preventing late restoration', function () {
    $this->travelTo(CarbonImmutable::now()->startOfSecond());
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $bytes = "Sensitive synthetic transcript.\n";
    $header = scriptOutputHeader($command, $delivery, $bytes);
    $service->result($credential, $attempt->attempt_uuid, (object) scriptResultFixture($command, $delivery, $agent, ['output_sha256' => $header->sha256, 'output_bytes' => $header->size_bytes]));
    $received = $delivery->fresh()->completed_at;
    $this->travel(23)->hours();
    $receipt = $service->output($credential, $attempt->attempt_uuid, $header, $bytes);
    expect($receipt['expires_at'])->toBe($received->addHours(24)->format('Y-m-d\TH:i:s\Z'));
    $this->travel(1)->hours();
    scriptCatalogueGrant($runner, EndpointScriptOutputs::VIEW);
    $read = 'https://localhost/security-devices/devices/'.$device->id.'/commands/'.$command->id.'/script-output/'.$attempt->attempt_uuid;
    $this->actingAs($runner)->get($read.'/content?expected_actor_id='.$runner->id)->assertStatus(410);
    $stored = DB::table('endpoint_script_outputs')->first();
    expect($stored->encrypted_content)->toBeNull()->and($stored->purged_at)->not->toBeNull()
        ->and($service->output($credential, $attempt->attempt_uuid, $header, $bytes))->toBe($receipt)
        ->and(DB::table('endpoint_script_outputs')->first()->encrypted_content)->toBeNull();
    $this->get($read.'?expected_actor_id='.$runner->id)->assertOk()->assertJsonPath('content_available', false)->assertJsonPath('receipt.accepted', true);
    expect(DB::table('device_command_audit_events')->where('action', 'endpoint_script_output_read')->count())->toBe(0);
    $this->artisan('endpoints:purge-script-outputs', ['--batch' => '10'])->assertExitCode(0);
    $this->artisan('endpoints:purge-script-outputs', ['--batch' => '0'])->assertExitCode(2);
    expect(DB::table('endpoint_script_outputs')->first()->receipt_json)->toBe($stored->receipt_json);
});

it('purges output without agent or launch authority and rejects first arrival after its fixed deadline', function () {
    $this->travelTo(CarbonImmutable::now()->startOfSecond());
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $header = scriptOutputHeader($command, $delivery, 'abc');
    $service->result($credential, $attempt->attempt_uuid, (object) scriptResultFixture($command, $delivery, $agent, ['output_sha256' => $header->sha256, 'output_bytes' => 3]));
    $this->travel(24)->hours();
    scriptCatalogueDeny(fn () => $service->output($credential, $attempt->attempt_uuid, $header, 'abc'), 410);
    expect(DB::table('endpoint_script_outputs')->count())->toBe(0);
    $this->travelBack();
    $service->output($credential, $attempt->attempt_uuid, $header, 'abc');
    $this->travel(25)->hours();
    $agent->update(['revoked_at' => now()]);
    config()->set('endpoint_management.enabled', false);
    $this->artisan('endpoints:purge-script-outputs')->assertExitCode(0);
    expect(DB::table('endpoint_script_outputs')->first()->encrypted_content)->toBeNull()
        ->and(DB::table('endpoint_script_outputs')->first()->purged_at)->not->toBeNull()
        ->and(app(EndpointScriptOutputs::class)->purge())->toBe(0);
});

it('rejects malformed output HTTP and unbound bytes without storing content or exposing it', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $bytes = 'SYNTHETIC-PRIVATE-OUTPUT';
    $header = scriptOutputHeader($command, $delivery, $bytes);
    $http = 'https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/output';
    scriptOutputPost($this, $http, $credential, $header, $bytes)->assertConflict();
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $service->result($credential, $attempt->attempt_uuid, (object) scriptResultFixture($command, $delivery, $agent, ['output_sha256' => $header->sha256, 'output_bytes' => $header->size_bytes]));
    $cases = [
        [$http.'?unexpected=1', $credential, $header, $bytes, [], 400],
        [str_replace('https:', 'http:', $http), $credential, $header, $bytes, [], 400],
        [str_replace('localhost', 'elsewhere.invalid', $http), $credential, $header, $bytes, [], 400],
        [$http, str_repeat('f', 64), $header, $bytes, [], 401],
        [$http, $credential, $header, str_repeat('x', 65537), [], 413],
        [$http, $credential, $header, $bytes, ['CONTENT_TYPE' => 'application/json'], 415],
        [$http, $credential, $header, $bytes, ['HTTP_X_ENDPOINT_SCRIPT_OUTPUT' => 'not base64'], 422],
        [$http, $credential, $header, $bytes, ['HTTP_X_ENDPOINT_SCRIPT_OUTPUT' => base64_encode('{"command_uuid":"x","command_uuid":"y"}')], 422],
        [$http, $credential, (object) [...get_object_vars($header), 'extra' => true], $bytes, [], 422],
        [$http, $credential, (object) [...get_object_vars($header), 'size_bytes' => (string) strlen($bytes)], $bytes, [], 422],
        [$http, $credential, (object) [...get_object_vars($header), 'payload_hash' => str_repeat('f', 64)], $bytes, [], 422],
        [$http, $credential, (object) [...get_object_vars($header), 'command_uuid' => (string) Str::uuid()], $bytes, [], 422],
        [$http, $credential, (object) [...get_object_vars($header), 'truncated' => true], $bytes, [], 422],
        [$http, $credential, $header, $bytes.'changed', [], 422],
        [$http, $credential, $header, "\xff", [], 422],
    ];
    foreach ($cases as [$url, $token, $head, $body, $server, $status]) {
        $response = scriptOutputPost($this, $url, $token, $head, $body, $server)->assertStatus($status);
        expect($response->getContent())->not->toContain($bytes)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    }
    expect(DB::table('endpoint_script_outputs')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('recovers delayed output after launch withdrawal but rejects foreign or revoked agent identity', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim, $row] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $header = scriptOutputHeader($command, $delivery, 'abc');
    $service->result($credential, $attempt->attempt_uuid, (object) scriptResultFixture($command, $delivery, $agent, ['output_sha256' => $header->sha256, 'output_bytes' => 3]));
    scriptCatalogueProof($this->scriptReviewer);
    app(EndpointScripts::class)->transition($this->scriptReviewer, $row['uuid'], 'withdraw', scriptCatalogueInput($this->scriptReviewer, 3));
    $runner->forceFill(['two_factor_confirmed_at' => null])->save();
    scriptCatalogueGrant($runner, EndpointScriptPolicy::RUN, false);
    config()->set('endpoint_management.scripts.reviewed_runtimes', []);
    app()->instance(EndpointScriptPolicy::class, new EndpointScriptPolicy);
    DB::table('endpoint_scripts')->where('uuid', $row['uuid'])->update(['artifact_ciphertext' => 'damaged']);
    $this->travel(2)->hours();
    $agent->update(['boot_id' => (string) Str::uuid()]);
    $receipt = $service->output($credential, $attempt->attempt_uuid, $header, 'abc');
    expect($receipt['accepted'])->toBeTrue()->and($service->output($credential, $attempt->attempt_uuid, $header, 'abc'))->toBe($receipt);
    $agent->update(['revoked_at' => now()]);
    scriptCatalogueDeny(fn () => $service->output($credential, $attempt->attempt_uuid, $header, 'abc'), 401);
    $agent->update(['revoked_at' => null, 'uuid' => (string) Str::uuid()]);
    $http = 'https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/output';
    scriptOutputPost($this, $http, $credential, $header, 'abc')->assertNotFound();
    expect(DB::table('endpoint_script_outputs')->count())->toBe(1);
});

it('rechecks sensitive output actor grant MFA ownership and approved Site on every read', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $header = scriptOutputHeader($command, $delivery, 'abc');
    $service->result($credential, $attempt->attempt_uuid, (object) scriptResultFixture($command, $delivery, $agent, ['output_sha256' => $header->sha256, 'output_bytes' => 3]));
    $service->output($credential, $attempt->attempt_uuid, $header, 'abc');
    $permission = EndpointScriptOutputs::VIEW;
    $read = 'https://localhost/security-devices/devices/'.$device->id.'/commands/'.$command->id.'/script-output/'.$attempt->attempt_uuid.'/content';
    $this->actingAs($runner)->get($read.'?expected_actor_id='.$runner->id)->assertForbidden();
    scriptCatalogueGrant($runner, $permission);
    $this->get($read.'?expected_actor_id='.$runner->id.'&expected_actor_id='.$runner->id)->assertBadRequest();
    $this->get($read.'?expected_actor_id='.$this->scriptReviewer->id)->assertConflict();
    $this->get($read.'?expected_actor_id='.$runner->id)->assertOk();
    scriptCatalogueGrant($runner, $permission, false);
    $this->get($read.'?expected_actor_id='.$runner->id)->assertForbidden();
    scriptCatalogueGrant($runner, $permission);
    $runner->forceFill(['two_factor_confirmed_at' => null])->save();
    $this->get($read.'?expected_actor_id='.$runner->id)->assertForbidden();
    $runner->forceFill(['two_factor_confirmed_at' => now()])->save();
    scriptCatalogueGrant($this->scriptReviewer, $permission);
    $this->actingAs($this->scriptReviewer)->get($read.'?expected_actor_id='.$this->scriptReviewer->id)->assertForbidden();
    scriptCatalogueGrant($this->scriptReviewer, 'securityDevices.commands.admin');
    $this->get($read.'?expected_actor_id='.$this->scriptReviewer->id)->assertOk();
    DeviceAssignment::where('device_id', $device->id)->update(['released_at' => now()]);
    $foreign = Site::factory()->create();
    DeviceAssignment::create(['device_id' => $device->id, 'assignable_type' => 'site', 'assignable_id' => $foreign->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()]);
    $this->get($read.'?expected_actor_id='.$this->scriptReviewer->id)->assertNotFound();
    expect(DB::table('device_command_audit_events')->where('action', 'endpoint_script_output_read')->count())->toBe(2);
});

it('rejects damaged private content and result binding without overwriting the retained receipt', function () {
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $service->claim($credential, $attempt->attempt_uuid, $claim);
    $header = scriptOutputHeader($command, $delivery, 'abc');
    $service->result($credential, $attempt->attempt_uuid, (object) scriptResultFixture($command, $delivery, $agent, ['output_sha256' => $header->sha256, 'output_bytes' => 3]));
    $service->output($credential, $attempt->attempt_uuid, $header, 'abc');
    scriptCatalogueGrant($runner, EndpointScriptOutputs::VIEW);
    $read = 'https://localhost/security-devices/devices/'.$device->id.'/commands/'.$command->id.'/script-output/'.$attempt->attempt_uuid.'/content?expected_actor_id='.$runner->id;
    $stored = DB::table('endpoint_script_outputs')->first();
    foreach (['damaged', Crypt::encryptString('xyz')] as $cipher) {
        DB::table('endpoint_script_outputs')->where('id', $stored->id)->update(['encrypted_content' => $cipher]);
        $this->actingAs($runner)->get($read)->assertConflict();
    }
    DB::table('endpoint_script_outputs')->where('id', $stored->id)->update(['encrypted_content' => $stored->encrypted_content, 'receipt_hash' => str_repeat('0', 64)]);
    $this->get($read)->assertConflict();
    DB::table('endpoint_script_outputs')->where('id', $stored->id)->update(['receipt_hash' => $stored->receipt_hash]);
    DB::table('endpoint_deliveries')->where('id', $delivery->id)->update(['result_hash' => str_repeat('f', 64)]);
    scriptCatalogueDeny(fn () => $service->output($credential, $attempt->attempt_uuid, $header, 'abc'), 409);
    $this->get($read)->assertConflict();
    expect(DB::table('endpoint_script_outputs')->first()->receipt_json)->toBe($stored->receipt_json)
        ->and(DB::table('device_command_audit_events')->where('action', 'endpoint_script_output_read')->count())->toBe(0);
});

it('accepts the full reviewed output budget and rejects output for definite nonentry', function (bool $entered) {
    $this->scriptMetadata->execution_policy->output_bytes = 65536;
    [$runner, $device, $agent, $command, $attempt, $delivery, $credential, $claim] = scriptPreparedTransportFixture($this);
    $service = app(EndpointScriptCommands::class);
    $bytes = $entered ? str_repeat('x', 65536) : '';
    $header = scriptOutputHeader($command, $delivery, $bytes);
    if ($entered) {
        $service->claim($credential, $attempt->attempt_uuid, $claim);
    }
    $service->result($credential, $attempt->attempt_uuid, (object) scriptResultFixture($command, $delivery, $agent, $entered
        ? ['output_sha256' => $header->sha256, 'output_bytes' => $header->size_bytes]
        : ['status' => 'declined', 'reason_code' => 'declined', 'mutation_started' => false, 'exit_code' => null]));
    $http = 'https://localhost/api/endpoints/agent/scripts/'.$attempt->attempt_uuid.'/output';
    $reply = scriptOutputPost($this, $http, $credential, $header, $bytes);
    if (! $entered) {
        $reply->assertUnprocessable();
        expect(DB::table('endpoint_script_outputs')->count())->toBe(0);

        return;
    }
    $reply->assertOk()->assertJsonPath('size_bytes', 65536);
    scriptCatalogueGrant($runner, EndpointScriptOutputs::VIEW);
    $read = 'https://localhost/security-devices/devices/'.$device->id.'/commands/'.$command->id.'/script-output/'.$attempt->attempt_uuid.'/content?expected_actor_id='.$runner->id;
    expect($this->actingAs($runner)->get($read)->assertOk()->getContent())->toBe($bytes)
        ->and(Crypt::decryptString(DB::table('endpoint_script_outputs')->first()->encrypted_content))->toBe($bytes);
})->with(['full output budget' => true, 'definite nonentry' => false]);
