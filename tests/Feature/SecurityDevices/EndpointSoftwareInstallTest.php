<?php

use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointSoftwarePackage;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointActionPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointCommandSigner;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointHeartbeat;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointMutationGuard;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCommands;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareCommands;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwarePackages;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwarePolicy;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function softwareGrant(User $actor, string $key, bool $allowed = true): void
{
    $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'module' => 'securityDevices']);
    $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
    $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
}
function softwareProof(User $actor): array
{
    return ['endpoint_software_identity' => ['actor_id' => (int) $actor->id, 'fingerprint' => ObliStaffAccess::fingerprint($actor), 'started_at' => now()->timestamp], 'auth.password_confirmed_at' => now()->timestamp];
}
function softwareMetadata($test, ?string $key = null): array
{
    return ['expected_actor_id' => (int) $test->softwareActor->id, 'request_key' => $key ?? (string) Str::uuid(), 'name' => 'Reviewed machine software', 'publisher' => 'Reviewed publisher', 'product_code' => '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}', 'product_version' => '1.2.3', 'architecture' => 'amd64', 'signer_sha256' => str_repeat('a', 64), 'reason' => 'Upload this machine MSI for independent review.'];
}
function softwareUpload($test, array $input = []): array
{
    $test->actingAs($test->softwareActor);

    return $test->post('/security-devices/software', array_replace(softwareMetadata($test), $input) + ['file' => UploadedFile::fake()->createWithContent('reviewed.msi', $test->softwareArtifact)], ['Accept' => 'application/json'])->assertOk()->json();
}
function softwarePublish($test, array $input = []): EndpointSoftwarePackage
{
    $row = softwareUpload($test, $input)['package'];
    $path = '/security-devices/software/'.$row['uuid'];
    $test->postJson($path.'/submit', ['expected_actor_id' => (int) $test->softwareActor->id, 'expected_version' => 1, 'reason' => 'Submit the exact immutable machine MSI for review.'])->assertOk();
    $test->actingAs($test->softwareReviewer)->withSession(softwareProof($test->softwareReviewer));
    $test->postJson($path.'/review', ['expected_actor_id' => (int) $test->softwareReviewer->id, 'expected_version' => 2, 'reason' => 'Independently reviewed signer, metadata, machine scope and embedded files.'])->assertOk()->assertJsonPath('package.status', 'published');
    $test->actingAs($test->softwareActor);

    return EndpointSoftwarePackage::query()->where('uuid', $row['uuid'])->firstOrFail();
}
function softwareDispatch($test): array
{
    $input = new CommandRequestInput('endpoint.software.install', ['package_uuid' => $test->softwarePackage->uuid], 'Install the independently reviewed machine MSI on this computer.', (string) Str::uuid(), CarbonImmutable::now('UTC')->startOfSecond(), impactAcknowledged: true);
    $request = app(DeviceCommandRequestService::class)->request($test->softwareDevice, $test->softwareActor, $input);
    expect($request->status)->toBe(CommandStatus::AwaitingApproval);
    app(DeviceCommandApprovalService::class)->decide($request, $test->softwareReviewer, new CommandDecisionInput(CommandApprovalDecision::Approved, 'Package, computer, consent and impact independently reviewed.'));
    $attempt = app(GovernedCommandDispatchService::class)->dispatch($request->fresh(), $test->softwareActor);

    return [$request->fresh(), $attempt, EndpointDelivery::query()->where('device_command_attempt_id', $attempt->id)->firstOrFail()];
}
function softwareClaim($test, $request, $delivery): array
{
    return ['command_uuid' => $request->command_uuid, 'payload_hash' => $delivery->payload_hash, 'artifact_sha256' => $test->softwarePackage->sha256, 'boot_id' => $test->softwareAgent->boot_id, 'consent' => ['desktop_session' => '11111111-1111-4111-8111-111111111111', 'consent_nonce' => str_repeat('c', 64)]];
}
function softwareResult($test, $request, $delivery, int $exit = 0): array
{
    return ['command_uuid' => $request->command_uuid, 'payload_hash' => $delivery->payload_hash, 'status' => in_array($exit, [0, 3010], true) ? 'awaiting_observation' : ($exit === 1641 ? 'uncertain' : 'failed'), 'mutation_started' => true, 'reason_code' => match ($exit) {
        0 => 'installed',3010 => 'installed_restart_required',1641 => 'restart_initiated',1618 => 'installer_busy',default => 'installer_failed'
    }, 'exit_code' => $exit, 'reboot_required' => in_array($exit, [3010, 1641], true), 'boot_id' => $test->softwareAgent->boot_id];
}
function softwareHeartbeat($test, $request, $attempt, array $change = [], bool $release = true): array
{
    $boot = $change['boot_id'] ?? $test->softwareAgent->boot_id;

    return ['sequence' => $test->softwareAgent->fresh()->sequence + 1, 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'agent_version' => '1.0.0', 'boot_id' => $boot,
        ...($release ? ['software_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_msi']] : []),
        'software_observations' => [array_replace(['command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'product_code' => $test->softwarePackage->product_code, 'product_version' => $test->softwarePackage->product_version, 'state' => 'installed', 'boot_id' => $boot, 'transaction_quiescent' => true, 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')], $change)],
        'inventory' => ['hostname' => 'isolated-software-computer', 'os_version' => 'Windows 11', 'architecture' => 'amd64', 'memory_total_bytes' => 8192, 'memory_available_bytes' => 4096, 'uptime_seconds' => 120, 'cpu_percent' => null, 'disks' => [], 'software' => [], 'software_complete' => true, 'services' => [], 'services_complete' => true, 'probe_errors' => []]];
}
beforeEach(function () {
    $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
    Queue::fake();
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.software.release_enabled', true);
    config()->set('endpoint_management.actions.release_enabled', true);
    config()->set('endpoint_management.server_url', 'https://localhost');
    config()->set('endpoint_management.services', [['name' => 'Spooler', 'label' => 'Print service', 'operations' => ['start', 'stop', 'restart']]]);
    config()->set('endpoint_management.command_signing_secret', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('s', 32)))));
    config()->set('monitoring.signing.active_key_id', 'isolated-software-test');
    config()->set('monitoring.signing.keys', ['isolated-software-test' => base64_encode(str_repeat('S', SODIUM_CRYPTO_AUTH_KEYBYTES))]);
    $this->softwareDirectory = base_path('.endpoint-tools/software-test-'.getmypid().'-'.Str::uuid());
    config()->set('endpoint_management.software.package_directory', $this->softwareDirectory);
    $this->softwareArtifact = hex2bin('d0cf11e0a1b11ae1').'Isolated injected MSI test bytes; never execute.';
    $this->softwareActor = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
    $this->softwareReviewer = User::factory()->create(['approved_at' => now(), 'two_factor_confirmed_at' => now()]);
    $this->softwareSite = ensureCanonicalHrStaffProfile($this->softwareActor);
    ensureCanonicalHrStaffProfile($this->softwareReviewer, $this->softwareSite);
    foreach ([$this->softwareActor, $this->softwareReviewer] as $actor) {
        $actor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        foreach (['it.view', 'it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.commands.observe', 'securityDevices.commands.control', 'securityDevices.commands.approve', EndpointSoftwarePackages::UPLOAD, EndpointSoftwarePackages::REVIEW, EndpointSoftwarePolicy::SERVICE, EndpointSoftwarePolicy::MANAGE, EndpointActionPolicy::SERVICE] as $key) {
            softwareGrant($actor, $key);
        }
    }
    $this->softwareDevice = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active', 'last_seen_at' => now()]);
    DeviceAssignment::query()->create(['device_id' => $this->softwareDevice->id, 'assignable_type' => 'site', 'assignable_id' => $this->softwareSite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
    $this->softwareCredential = str_repeat('e', 64);
    $this->softwareAgent = EndpointAgent::query()->create(['uuid' => (string) Str::uuid(), 'device_id' => $this->softwareDevice->id, 'site_id' => $this->softwareSite->id, 'platform' => 'windows', 'created_by_user_id' => $this->softwareActor->id, 'assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($this->softwareDevice), 'credential_hash' => hash('sha256', $this->softwareCredential), 'enrolled_at' => now(), 'last_seen_at' => now(), 'observed_at' => now(), 'boot_id' => '22222222-2222-4222-8222-222222222222', 'sequence' => 1, 'heartbeat_hash' => str_repeat('b', 64), 'software_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_msi'], 'service_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_scm', 'operations' => ['start', 'stop', 'restart']], 'inventory' => ['architecture' => 'amd64', 'services' => [['name' => 'Spooler', 'state' => 'running']]]]);
    DB::table('endpoint_software_policies')->insert(['device_id' => $this->softwareDevice->id, 'version' => 1, 'enabled' => true, 'consent_mode' => 'attended', 'updated_by_user_id' => $this->softwareActor->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('endpoint_action_policies')->insert(['device_id' => $this->softwareDevice->id, 'version' => 1, 'enabled' => true, 'consent_mode' => 'attended', 'updated_by_user_id' => $this->softwareActor->id, 'created_at' => now(), 'updated_at' => now()]);
    URL::forceScheme('https');
    $this->withServerVariables(['HTTPS' => 'on']);
    $this->actingAs($this->softwareActor);
    $this->softwareCommands = app(EndpointSoftwareCommands::class);
});
afterEach(function () {
    CarbonImmutable::setTestNow();
    if (isset($this->softwareDirectory) && is_dir($this->softwareDirectory)) {
        // Unique test-owned directory, never the configured production package store.
        foreach (glob($this->softwareDirectory.'/*.msi') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->softwareDirectory);
    }
});

it('recovers actor private immutable upload receipts and rejects changed bytes metadata actor or arbitrary installer inputs', function () {
    $input = softwareMetadata($this);
    $first = softwareUpload($this, $input);
    $replay = softwareUpload($this, $input);
    expect($replay)->toBe($first)->and(EndpointSoftwarePackage::query()->count())->toBe(1)->and(glob($this->softwareDirectory.'/*.msi'))->toHaveCount(1);
    $href = '/security-devices/software/uploads/'.$input['request_key'].'?expected_actor_id='.$this->softwareActor->id;
    $this->getJson($href)->assertOk()->assertExactJson($first);
    $this->post('/security-devices/software', [...$input, 'file' => UploadedFile::fake()->createWithContent('changed.msi', $this->softwareArtifact.'changed')], ['Accept' => 'application/json'])->assertConflict();
    $this->post('/security-devices/software', [...$input, 'publisher' => 'Changed publisher', 'file' => UploadedFile::fake()->createWithContent('changed.msi', $this->softwareArtifact)], ['Accept' => 'application/json'])->assertConflict();
    $this->post('/security-devices/software', [...$input, 'url' => 'https://example.invalid/package.msi', 'file' => UploadedFile::fake()->createWithContent('unsafe.msi', $this->softwareArtifact)], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post('/security-devices/software', [...$input, 'file' => UploadedFile::fake()->createWithContent('unsafe.msi', 'not an MSI')], ['Accept' => 'application/json'])->assertUnprocessable();
    expect(EndpointSoftwarePackage::query()->count())->toBe(1)->and(glob($this->softwareDirectory.'/*.msi'))->toHaveCount(1);
    $this->actingAs($this->softwareReviewer)->getJson(str_replace('expected_actor_id='.$this->softwareActor->id, 'expected_actor_id='.$this->softwareReviewer->id, $href))->assertNotFound();
    $this->getJson($href)->assertConflict();
    if ($export = getenv('OBLI_SOFTWARE_EXPORT')) {
        file_put_contents($export.'.upload', json_encode($first, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
});

it('requires independent recent current reviewer identity for publication and withdrawal but permits draft upload and submission', function () {
    $row = softwareUpload($this)['package'];
    $path = '/security-devices/software/'.$row['uuid'];
    $this->postJson($path.'/submit', ['expected_actor_id' => (int) $this->softwareActor->id, 'expected_version' => 1, 'reason' => 'Submit exact draft for independent machine MSI review.'])->assertOk()->assertJsonPath('package.status', 'pending_review');
    $this->withSession(softwareProof($this->softwareActor))->postJson($path.'/review', ['expected_actor_id' => (int) $this->softwareActor->id, 'expected_version' => 2, 'reason' => 'Cannot publish the package that I uploaded.'])->assertForbidden();
    $this->actingAs($this->softwareReviewer)->withSession(['endpoint_software_identity' => null, 'auth.password_confirmed_at' => now()->timestamp]);
    $pending = $this->getJson('/security-devices/software?status=pending_review')->assertOk()->assertJsonPath('can_confirm_identity', true)->assertJsonPath('identity_confirmed', false)->assertJsonPath('packages.0.can_review', false)->json();
    $review = ['expected_actor_id' => (int) $this->softwareReviewer->id, 'expected_version' => 2, 'reason' => 'Independently reviewed this exact immutable machine installer.'];
    $this->postJson($path.'/review', $review)->assertForbidden();
    $this->get('/security-devices/software/confirm-identity?expected_actor_id='.$this->softwareReviewer->id)->assertRedirect(route('password.confirm'))->assertSessionMissing('auth.password_confirmed_at')->assertSessionHas('url.intended', '/security-devices/software?expected_actor_id='.$this->softwareReviewer->id.'&status=pending_review');
    $this->withSession(softwareProof($this->softwareReviewer));
    $published = $this->postJson($path.'/review', $review)->assertOk()->assertJsonPath('package.status', 'published')->assertJsonPath('package.status_version', 3)->json();
    $this->postJson($path.'/review', $review)->assertConflict();
    $this->withSession(['auth.password_confirmed_at' => now()->subSeconds(901)->timestamp])->postJson($path.'/withdraw', [...$review, 'expected_version' => 3])->assertForbidden();
    $this->withSession(softwareProof($this->softwareReviewer));
    $package = EndpointSoftwarePackage::query()->where('uuid', $row['uuid'])->firstOrFail();
    expect(fn () => $package->forceFill(['product_version' => '9.9.9'])->save())->toThrow(LogicException::class);
    $withdrawn = $this->postJson($path.'/withdraw', [...$review, 'expected_version' => 3])->assertOk()->assertJsonPath('package.status', 'withdrawn')->json();
    expect(DB::table('endpoint_software_package_events')->where('endpoint_software_package_id', $package->id)->count())->toBe(4);
    if ($export = getenv('OBLI_SOFTWARE_EXPORT')) {
        file_put_contents($export.'.catalogue', json_encode(['pending' => $pending, 'published' => $published, 'withdrawn' => $withdrawn], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
});

it('pages the current permitted catalogue before literal search and preserves canonical device denial and summary scope', function () {
    $this->softwarePackage = softwarePublish($this);
    $base = $this->softwarePackage->getAttributes();
    for ($i = 1; $i <= 24; $i++) {
        $row = $base;
        unset($row['id']);
        $row['uuid'] = (string) Str::uuid();
        $row['request_key'] = (string) Str::uuid();
        $row['storage_key'] = $row['uuid'].'.msi';
        $row['name'] = $i === 24 ? 'Literal %_! software' : 'Paged software '.$i;
        DB::table('endpoint_software_packages')->insert($row);
    }
    $first = $this->getJson('/security-devices/software?device_id='.$this->softwareDevice->id)->assertOk()->assertJsonPath('summary.published', 25)->assertJsonPath('pagination.shown', 20)->assertJsonPath('pagination.has_more', true)->json();
    $second = $this->getJson($first['pagination']['next_href'])->assertOk()->assertJsonPath('pagination.shown', 5)->assertJsonPath('pagination.has_more', false)->json();
    $newer = $this->getJson($second['pagination']['previous_href'])->assertOk()->json();
    expect(array_column($newer['packages'], 'uuid'))->toBe(array_column($first['packages'], 'uuid'));
    $search = $this->getJson('/security-devices/software?search='.rawurlencode('%_!'))->assertOk()->assertJsonCount(1, 'packages')->assertJsonPath('summary.published', 25)->json();
    $this->getJson('/security-devices/software?software_cursor[]=unsafe')->assertBadRequest();
    $cursor = (new Cursor(['id' => PHP_INT_MAX], true))->encode();
    $reset = $this->getJson('/security-devices/software?software_cursor='.$cursor)->assertOk()->assertJsonPath('pagination.position_reset', true)->assertJsonPath('pagination.shown', 20)->json();
    $site = Site::factory()->create();
    $hidden = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint']);
    DeviceAssignment::query()->create(['device_id' => $hidden->id, 'assignable_type' => 'site', 'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
    $this->getJson('/security-devices/software?device_id='.$hidden->id)->assertNotFound();
    $this->getJson('/security-devices/software?expected_actor_id='.$this->softwareReviewer->id)->assertConflict();
    if ($export = getenv('OBLI_SOFTWARE_EXPORT')) {
        file_put_contents($export.'.pages', json_encode(['first' => $first, 'second' => $second, 'search' => $search, 'reset' => $reset], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
});

it('binds policy saves to actor version and separately grants unattended execution without a paged catalogue digest', function () {
    $path = '/security-devices/devices/'.$this->softwareDevice->id.'/endpoint/software-policy';
    $before = $this->getJson($path.'?expected_actor_id='.$this->softwareActor->id)->assertOk()->assertJsonPath('policy.consent_mode', 'attended')->json();
    $save = ['expected_actor_id' => (int) $this->softwareActor->id, 'expected_version' => 1, 'enabled' => true, 'consent_mode' => 'unattended', 'reason' => 'Approve unattended installation policy separately.'];
    $this->putJson($path, $save)->assertForbidden();
    softwareGrant($this->softwareActor, EndpointSoftwarePolicy::UNATTENDED);
    $after = $this->putJson($path, $save)->assertOk()->assertJsonPath('policy.version', 2)->json();
    $this->putJson($path, $save)->assertConflict();
    $this->getJson($path.'?expected_actor_id='.$this->softwareReviewer->id)->assertConflict();
    if ($export = getenv('OBLI_SOFTWARE_EXPORT')) {
        file_put_contents($export.'.policy', json_encode(['get' => $before, 'put' => $after], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
});

it('signs the exact reviewed machine release and final claim then confirms only independent exact product evidence', function () {
    $this->softwarePackage = softwarePublish($this);
    [$request,$attempt,$delivery] = softwareDispatch($this);
    $path = '/api/endpoints/agent/software/'.$attempt->attempt_uuid;
    $command = $this->withToken($this->softwareCredential)->getJson('/api/endpoints/agent/software')->assertOk()->json('command');
    $raw = base64_decode($command['payload'], true);
    $payload = json_decode($raw, true);
    expect(count($payload))->toBe(13)->and(count($payload['parameters']))->toBe(10)->and($payload['parameters']['sha256'])->toBe(hash('sha256', $this->softwareArtifact))->and($payload['parameters']['product_version'])->toBe('1.2.3')->and($payload['parameters']['package_version'])->toBe(1)->and(sodium_crypto_sign_verify_detached(base64_decode($command['signature']), $raw, base64_decode(app(EndpointCommandSigner::class)->publicKey())))->toBeTrue();
    $download = $this->softwareCommands->artifact($this->softwareCredential, $attempt->attempt_uuid);
    expect(stream_get_contents($download['stream']))->toBe($this->softwareArtifact);
    fclose($download['stream']);
    $downloadReply = $this->get($path.'/artifact')->assertOk()->assertDownload('approved-package.msi')->assertHeader('Content-Type', 'application/octet-stream');
    expect($downloadReply->streamedContent())->toBe($this->softwareArtifact);
    $this->withToken(str_repeat('f', 64))->get($path.'/artifact')->assertUnauthorized();
    $this->withToken($this->softwareCredential)->get('/api/endpoints/agent/software/'.Str::uuid().'/artifact')->assertNotFound();
    $this->getJson($path.'/artifact?url=https://example.invalid')->assertUnprocessable();
    $management = new ReflectionMethod(DeviceProfilePresenter::class, 'management');
    $presenter = app(DeviceProfilePresenter::class);
    $before = $management->invoke($presenter, $this->softwareActor, $this->softwareDevice->fresh());
    $claimRequest = softwareClaim($this, $request, $delivery);
    $claim = $this->postJson($path.'/claim', $claimRequest)->assertOk()->json();
    $lease = json_decode(base64_decode($claim['payload']), true);
    expect(count($lease))->toBe(15)->and($lease['artifact_sha256'])->toBe($this->softwarePackage->sha256)->and($lease['boot_id'])->toBe($this->softwareAgent->boot_id)->and($this->softwareCommands->claim($this->softwareCredential, $attempt->attempt_uuid, $claimRequest))->toBe($claim);
    $this->postJson($path.'/claim', [...$claimRequest, 'artifact_sha256' => str_repeat('b', 64)])->assertConflict();
    $result = softwareResult($this, $request, $delivery);
    $resultAck = $this->postJson($path.'/result', $result)->assertOk()->json();
    expect($request->fresh()->status)->toBe(CommandStatus::Reconciling);
    $unconfirmed = app(DeviceCommandReconciliationService::class)->reconcile($request->fresh(), $this->softwareActor);
    expect($unconfirmed->outcome->value)->toBe('uncertain');
    $this->travel(3)->seconds();
    $heartbeat = softwareHeartbeat($this, $request, $attempt);
    $ack = app(EndpointHeartbeat::class)->record($this->softwareCredential, $heartbeat);
    expect($ack['software_observations_accepted'])->toHaveCount(1)->and($ack['software_observations_accepted'][0]['status'])->toBe('succeeded')->and(count($ack['software_observations_accepted'][0]))->toBe(9);
    $reconciliation = app(DeviceCommandReconciliationService::class)->reconcile($request->fresh(), $this->softwareActor);
    expect($reconciliation->outcome->value)->toBe('matched')->and($request->fresh()->status)->toBe(CommandStatus::Reconciled);
    expect($this->softwareCommands->result($this->softwareCredential, $attempt->attempt_uuid, $result))->toBe($resultAck);
    $this->postJson($path.'/result', [...$result, 'exit_code' => 3010, 'reason_code' => 'installed_restart_required', 'reboot_required' => true])->assertConflict();
    if ($export = getenv('OBLI_SOFTWARE_EXPORT')) {
        file_put_contents($export, json_encode(['command' => $command, 'claim' => $claim, 'public_key' => app(EndpointCommandSigner::class)->publicKey(), 'agent_uuid' => $this->softwareAgent->uuid, 'payload_hash' => $delivery->payload_hash, 'claim_request' => $claimRequest, 'result' => $result, 'result_ack' => $resultAck, 'heartbeat_payload' => $heartbeat, 'heartbeat_ack' => $ack, 'artifact_base64' => base64_encode($this->softwareArtifact), 'header' => (new ReflectionMethod(DeviceProfilePresenter::class, 'header'))->invoke($presenter, $this->softwareActor, $this->softwareDevice->fresh(), DeviceAssignment::query()->where('device_id', $this->softwareDevice->id)->whereNull('released_at')->first(), collect(), $this->softwareActor->canDo('securityDevices.events.view'), $this->softwareActor->canDo('securityDevices.maintenance.view')), 'management_before' => $before, 'management_confirmed' => $management->invoke($presenter, $this->softwareActor, $this->softwareDevice->fresh())], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
});

it('retains restart-needed state after3010 until a later accepted changed boot and accepts recovery after withdrawal', function () {
    $this->softwarePackage = softwarePublish($this);
    [$request,$attempt,$delivery] = softwareDispatch($this);
    $command = $this->softwareCommands->poll($this->softwareCredential)['command'];
    $claimRequest = softwareClaim($this, $request, $delivery);
    $claim = $this->softwareCommands->claim($this->softwareCredential, $attempt->attempt_uuid, $claimRequest);
    $receipt = softwareResult($this, $request, $delivery, 3010);
    $resultAck = $this->softwareCommands->result($this->softwareCredential, $attempt->attempt_uuid, $receipt);
    $this->travel(3)->seconds();
    $heartbeat = softwareHeartbeat($this, $request, $attempt);
    $ack = app(EndpointHeartbeat::class)->record($this->softwareCredential, $heartbeat);
    expect($ack['software_observations_accepted'][0]['status'])->toBe('succeeded');
    app(DeviceCommandReconciliationService::class)->reconcile($request->fresh(), $this->softwareActor);
    $projection = $this->softwareCommands->projection($request->fresh(), $this->softwareActor, $attempt->fresh());
    expect($projection['state'])->toBe('confirmed')->and($projection['rebootRequired'])->toBeTrue();
    $management = (new ReflectionMethod(DeviceProfilePresenter::class, 'management'))->invoke(app(DeviceProfilePresenter::class), $this->softwareActor, $this->softwareDevice->fresh());
    config()->set('endpoint_management.software.release_enabled', false);
    DB::table('endpoint_software_packages')->where('id', $this->softwarePackage->id)->update(['status' => 'withdrawn', 'status_version' => 4]);
    DB::table('endpoint_software_policies')->where('device_id', $this->softwareDevice->id)->update(['enabled' => false, 'version' => 2]);
    expect($this->softwareCommands->result($this->softwareCredential, $attempt->attempt_uuid, $receipt)['accepted'])->toBeTrue();
    $this->travel(3)->seconds();
    $after = softwareHeartbeat($this, $request, $attempt, ['boot_id' => '33333333-3333-4333-8333-333333333333'], false);
    $afterAck = app(EndpointHeartbeat::class)->record($this->softwareCredential, $after);
    expect($afterAck['software_observations_accepted'][0]['status'])->toBe('succeeded')->and($this->softwareAgent->fresh()->software_actions)->toBeNull()->and($this->softwareCommands->projection($request->fresh(), $this->softwareActor, $attempt->fresh())['rebootRequired'])->toBeFalse();
    if ($export = getenv('OBLI_SOFTWARE_EXPORT')) {
        file_put_contents($export.'.reboot', json_encode(['command' => $command, 'claim' => $claim, 'public_key' => app(EndpointCommandSigner::class)->publicKey(), 'agent_uuid' => $this->softwareAgent->uuid, 'payload_hash' => $delivery->payload_hash, 'claim_request' => $claimRequest, 'result_ack' => $resultAck, 'artifact_base64' => base64_encode($this->softwareArtifact), 'receipt' => $receipt, 'heartbeat_payload' => $heartbeat, 'heartbeat_ack' => $ack, 'management_reboot_needed' => $management, 'header' => (new ReflectionMethod(DeviceProfilePresenter::class, 'header'))->invoke(app(DeviceProfilePresenter::class), $this->softwareActor, $this->softwareDevice->fresh(), DeviceAssignment::query()->where('device_id', $this->softwareDevice->id)->whereNull('released_at')->first(), collect(), $this->softwareActor->canDo('securityDevices.events.view'), $this->softwareActor->canDo('securityDevices.maintenance.view')), 'projection' => $projection, 'changed_boot_payload' => $after, 'changed_boot_ack' => $afterAck], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
});

it('keeps1641 and crash attribution uncertain and never accepts unknown busy advertised or wrong product as completion', function () {
    $this->softwarePackage = softwarePublish($this);
    [$request,$attempt,$delivery] = softwareDispatch($this);
    $this->softwareCommands->claim($this->softwareCredential, $attempt->attempt_uuid, softwareClaim($this, $request, $delivery));
    $this->softwareCommands->result($this->softwareCredential, $attempt->attempt_uuid, softwareResult($this, $request, $delivery, 1641));
    $this->travel(3)->seconds();
    foreach ([['state' => 'unknown', 'product_version' => null], ['transaction_quiescent' => false], ['product_code' => '{BBBBBBBB-BBBB-CCCC-DDDD-EEEEEEEEEEEE}']] as $change) {
        $ack = app(EndpointHeartbeat::class)->record($this->softwareCredential, softwareHeartbeat($this, $request, $attempt, $change));
        expect($ack)->not->toHaveKey('software_observations_accepted');
        $this->travel(2)->seconds();
    }
    $ack = app(EndpointHeartbeat::class)->record($this->softwareCredential, softwareHeartbeat($this, $request, $attempt, ['boot_id' => '33333333-3333-4333-8333-333333333333']));
    expect($ack['software_observations_accepted'][0]['status'])->toBe('uncertain');
    $reconciliation = app(DeviceCommandReconciliationService::class)->reconcile($request->fresh(), $this->softwareActor);
    expect($reconciliation->outcome->value)->toBe('uncertain')->and($request->fresh()->status)->toBe(CommandStatus::Uncertain);
    expect($this->softwareCommands->projection($request->fresh(), $this->softwareActor, $attempt->fresh())['rebootRequired'])->toBeFalse();
});

it('mismatches advertised or wrong-version machine evidence despite an exit0 receipt', function () {
    $this->softwarePackage = softwarePublish($this);
    [$request,$attempt,$delivery] = softwareDispatch($this);
    $this->softwareCommands->claim($this->softwareCredential, $attempt->attempt_uuid, softwareClaim($this, $request, $delivery));
    $this->softwareCommands->result($this->softwareCredential, $attempt->attempt_uuid, softwareResult($this, $request, $delivery));
    $this->travel(3)->seconds();
    $ack = app(EndpointHeartbeat::class)->record($this->softwareCredential, softwareHeartbeat($this, $request, $attempt, ['state' => 'advertised', 'product_version' => '1.2.2']));
    expect($ack['software_observations_accepted'][0]['status'])->toBe('uncertain');
    expect(app(DeviceCommandReconciliationService::class)->reconcile($request->fresh(), $this->softwareActor)->outcome->value)->toBe('mismatch')->and($request->fresh()->status)->toBe(CommandStatus::Mismatch);
});

it('denies final claims after package policy reviewer grant MFA boot or assignment changes and never trusts artifact availability as execution approval', function () {
    $this->softwarePackage = softwarePublish($this);
    [$request,$attempt,$delivery] = softwareDispatch($this);
    $input = softwareClaim($this, $request, $delivery);
    $path = '/api/endpoints/agent/software/'.$attempt->attempt_uuid.'/claim';
    $this->withToken($this->softwareCredential);
    $download = $this->softwareCommands->artifact($this->softwareCredential, $attempt->attempt_uuid);
    fclose($download['stream']);
    $this->postJson($path, [...$input, 'boot_id' => '33333333-3333-4333-8333-333333333333'])->assertConflict();
    $this->postJson($path, array_diff_key($input, ['consent' => true]))->assertUnprocessable();
    DB::table('endpoint_software_packages')->where('id', $this->softwarePackage->id)->update(['status' => 'withdrawn']);
    $this->postJson($path, $input)->assertNotFound();
    DB::table('endpoint_software_packages')->where('id', $this->softwarePackage->id)->update(['status' => 'published']);
    DB::table('endpoint_software_policies')->where('device_id', $this->softwareDevice->id)->update(['version' => 2]);
    $this->postJson($path, $input)->assertConflict();
    DB::table('endpoint_software_policies')->where('device_id', $this->softwareDevice->id)->update(['version' => 1]);
    softwareGrant($this->softwareReviewer, 'securityDevices.commands.approve', false);
    $this->postJson($path, $input)->assertConflict();
    softwareGrant($this->softwareReviewer, 'securityDevices.commands.approve');
    $this->softwareReviewer->forceFill(['two_factor_confirmed_at' => null])->save();
    $this->postJson($path, $input)->assertConflict();
    $this->softwareReviewer->forceFill(['two_factor_confirmed_at' => now()])->save();
    $assignment = DeviceAssignment::query()->where('device_id', $this->softwareDevice->id)->whereNull('released_at')->firstOrFail();
    $assignment->forceFill(['released_at' => now()])->save();
    $this->postJson($path, $input)->assertUnauthorized();
    expect($delivery->fresh()->claimed_at)->toBeNull();
});

it('rejects unreviewed packages arbitrary parameters wrong architecture and stale stepup at the canonical request boundary', function () {
    $row = softwareUpload($this)['package'];
    $make = fn ($params, $time = null) => new CommandRequestInput('endpoint.software.install', $params, 'Install the reviewed machine software package.', (string) Str::uuid(), $time ?? CarbonImmutable::now('UTC')->startOfSecond(), impactAcknowledged: true);
    $service = app(DeviceCommandRequestService::class);
    expect(fn () => $service->request($this->softwareDevice, $this->softwareActor, $make(['package_uuid' => $row['uuid']])))->toThrow(ModelNotFoundException::class);
    $this->softwarePackage = softwarePublish($this, ['architecture' => 'arm64']);
    expect(fn () => $service->request($this->softwareDevice, $this->softwareActor, $make(['package_uuid' => $this->softwarePackage->uuid])))->toThrow(ValidationException::class);
    expect(fn () => $service->request($this->softwareDevice, $this->softwareActor, $make(['package_uuid' => $this->softwarePackage->uuid, 'url' => 'https://example.invalid'])))->toThrow(ValidationException::class);
    $this->softwarePackage = softwarePublish($this);
    $browser = ['expected_actor_id' => (int) $this->softwareReviewer->id, 'capability' => 'endpoint.software.install', 'parameters' => ['package_uuid' => $this->softwarePackage->uuid], 'reason' => 'Install this exact reviewed machine MSI.', 'idempotency_key' => (string) Str::uuid(), 'impact_acknowledged' => true];
    $browserPath = '/security-devices/devices/'.$this->softwareDevice->id.'/commands';
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson($browserPath, $browser)->assertConflict();
    $this->postJson($browserPath, array_diff_key($browser, ['expected_actor_id' => true]))->assertUnprocessable();
    $pending = $service->request($this->softwareDevice, $this->softwareActor, $make(['package_uuid' => $this->softwarePackage->uuid], CarbonImmutable::now('UTC')->subSeconds(901)));
    expect($pending->status)->toBe(CommandStatus::AwaitingStepUp);
    expect(fn () => app(GovernedCommandDispatchService::class)->dispatch($pending, $this->softwareActor))->toThrow(ValidationException::class);
    expect(DeviceCommandRequest::query()->count())->toBe(1);
});

it('holds all other native mutations until installer quiescence and does not relock a settled install after later reboot', function () {
    $this->softwarePackage = softwarePublish($this);
    [$request,$attempt,$delivery] = softwareDispatch($this);
    $serviceRequest = app(DeviceCommandRequestService::class)->request($this->softwareDevice, $this->softwareActor, new CommandRequestInput('endpoint.service.manage', ['service_name' => 'Spooler', 'operation' => 'restart'], 'Restart the independently reviewed print service.', (string) Str::uuid(), CarbonImmutable::now('UTC')->startOfSecond(), impactAcknowledged: true));
    app(DeviceCommandApprovalService::class)->decide($serviceRequest, $this->softwareReviewer, new CommandDecisionInput(CommandApprovalDecision::Approved, 'Independently reviewed the print service change.'));
    $serviceAttempt = app(GovernedCommandDispatchService::class)->dispatch($serviceRequest->fresh(), $this->softwareActor);
    $serviceDelivery = EndpointDelivery::query()->where('device_command_attempt_id', $serviceAttempt->id)->firstOrFail();
    $serviceInput = ['command_uuid' => $serviceRequest->command_uuid, 'payload_hash' => $serviceDelivery->payload_hash, 'consent' => ['desktop_session' => '11111111-1111-4111-8111-111111111111', 'consent_nonce' => str_repeat('c', 64)]];
    $this->softwareCommands->claim($this->softwareCredential, $attempt->attempt_uuid, softwareClaim($this, $request, $delivery));
    expect(fn () => app(EndpointServiceCommands::class)->claim($this->softwareCredential, $serviceAttempt->attempt_uuid, $serviceInput))->toThrow(HttpException::class);
    $this->softwareCommands->result($this->softwareCredential, $attempt->attempt_uuid, softwareResult($this, $request, $delivery));
    expect(fn () => app(EndpointServiceCommands::class)->claim($this->softwareCredential, $serviceAttempt->attempt_uuid, $serviceInput))->toThrow(HttpException::class);
    $this->travel(3)->seconds();
    app(EndpointHeartbeat::class)->record($this->softwareCredential, softwareHeartbeat($this, $request, $attempt));
    $this->softwareAgent->fresh()->forceFill(['boot_id' => '33333333-3333-4333-8333-333333333333', 'service_actions' => ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_scm', 'operations' => ['start', 'stop', 'restart']], 'inventory' => ['architecture' => 'amd64', 'services' => [['name' => 'Spooler', 'state' => 'running']]]])->save();
    expect(app(EndpointServiceCommands::class)->claim($this->softwareCredential, $serviceAttempt->attempt_uuid, $serviceInput))->toHaveKeys(['payload', 'signature']);
    [$nextRequest,$nextAttempt,$nextDelivery] = softwareDispatch($this);
    $nextInput = softwareClaim($this, $nextRequest, $nextDelivery);
    $nextInput['boot_id'] = '33333333-3333-4333-8333-333333333333';
    expect(fn () => $this->softwareCommands->claim($this->softwareCredential, $nextAttempt->attempt_uuid, $nextInput))->toThrow(HttpException::class);
});

it('accepts exact non-mutating native claim recovery receipts without authorizing execution or completion', function (string $reason, bool $serverClaimed) {
    $this->softwarePackage = softwarePublish($this);
    [$request,$attempt,$delivery] = softwareDispatch($this);
    $input = softwareClaim($this, $request, $delivery);
    $path = '/api/endpoints/agent/software/'.$attempt->attempt_uuid;
    $this->withToken($this->softwareCredential);
    if ($serverClaimed) {
        $this->postJson($path.'/claim', $input)->assertOk();
    }
    $receipt = ['command_uuid' => $request->command_uuid, 'payload_hash' => $delivery->payload_hash, 'status' => 'uncertain', 'mutation_started' => false, 'reason_code' => $reason, 'exit_code' => null, 'reboot_required' => false, 'boot_id' => $this->softwareAgent->boot_id];
    $this->postJson($path.'/result', [...$receipt, 'payload_hash' => str_repeat('f', 64)])->assertConflict();
    foreach ([['reason_code' => 'operation_failed'], ['reason_code' => 'restart_initiated', 'exit_code' => 1641, 'reboot_required' => true], ['exit_code' => 0], ['reboot_required' => true], ['mutation_started' => 0]] as $invalid) {
        $this->postJson($path.'/result', array_replace($receipt, $invalid))->assertUnprocessable();
    }
    $ack = $this->postJson($path.'/result', $receipt)->assertOk()->json();
    expect($ack)->toBe(['command_uuid' => $request->command_uuid, 'attempt_uuid' => $attempt->attempt_uuid, 'payload_hash' => $delivery->payload_hash, 'accepted' => true]);
    $this->postJson($path.'/result', $receipt)->assertOk()->assertExactJson($ack);
    $this->postJson($path.'/result', [...$receipt, 'reason_code' => $reason === 'interrupted' ? 'state_unavailable' : 'interrupted'])->assertConflict();
    $this->postJson($path.'/claim', $input)->assertConflict();
    // MySQL normalizes JSON object key order; retain exact keys, types and values.
    $storedReceipt = $delivery->fresh()->result;
    $expectedReceipt = $receipt;
    ksort($storedReceipt);
    ksort($expectedReceipt);
    expect($storedReceipt)->toBe($expectedReceipt)->and($delivery->fresh()->completed_at)->not->toBeNull()->and($request->fresh()->status)->toBe(CommandStatus::Uncertain)->and($attempt->fresh()->started_at)->toBeNull();
    $this->travel(3)->seconds();
    $heartbeat = app(EndpointHeartbeat::class)->record($this->softwareCredential, softwareHeartbeat($this, $request, $attempt));
    expect($heartbeat)->not->toHaveKey('software_observations_accepted')->and($delivery->fresh()->software_readback)->toBeNull()->and($this->softwareCommands->projection($request->fresh(), $this->softwareActor, $attempt->fresh())['state'])->toBe('uncertain');
})->with(['persisted claiming crash' => ['interrupted', false], 'lost final claim reply' => ['state_unavailable', true]]);
it('never refreshes an expired claimed attempt or automatically repeats an unknown installer boundary', function () {
    $this->softwarePackage = softwarePublish($this);
    [$request,$attempt,$delivery] = softwareDispatch($this);
    $input = softwareClaim($this, $request, $delivery);
    $this->softwareCommands->claim($this->softwareCredential, $attempt->attempt_uuid, $input);
    $this->travel(31)->seconds();
    $this->withToken($this->softwareCredential)->postJson('/api/endpoints/agent/software/'.$attempt->attempt_uuid.'/claim', $input)->assertConflict();
    $this->travel(1100)->seconds();
    expect($this->softwareCommands->expire())->toBe(1)->and($request->fresh()->status)->toBe(CommandStatus::Uncertain)->and($delivery->fresh()->completed_at)->toBeNull();
    expect(fn () => DB::transaction(fn () => app(EndpointMutationGuard::class)->assertAvailable($this->softwareAgent->fresh(), (new EndpointDelivery)->forceFill(['id' => 999999]))))->toThrow(HttpException::class);
});

it('detects package byte tampering before publication and before authenticated staging without changing reviewed identity', function () {
    $row = softwareUpload($this)['package'];
    $path = '/security-devices/software/'.$row['uuid'];
    $this->postJson($path.'/submit', ['expected_actor_id' => (int) $this->softwareActor->id, 'expected_version' => 1, 'reason' => 'Submit the exact immutable machine MSI for review.'])->assertOk();
    $package = EndpointSoftwarePackage::query()->where('uuid', $row['uuid'])->firstOrFail();
    file_put_contents($this->softwareDirectory.DIRECTORY_SEPARATOR.$package->storage_key, $this->softwareArtifact.'tampered');
    $this->actingAs($this->softwareReviewer)->withSession(softwareProof($this->softwareReviewer));
    $this->postJson($path.'/review', ['expected_actor_id' => (int) $this->softwareReviewer->id, 'expected_version' => 2, 'reason' => 'Review the exact immutable machine MSI and publisher.'])->assertStatus(503);
    expect($package->fresh()->status)->toBe('pending_review')->and($package->fresh()->reviewed_by_user_id)->toBeNull();
    file_put_contents($this->softwareDirectory.DIRECTORY_SEPARATOR.$package->storage_key, $this->softwareArtifact);
    $this->postJson($path.'/review', ['expected_actor_id' => (int) $this->softwareReviewer->id, 'expected_version' => 2, 'reason' => 'Review the exact restored immutable machine MSI bytes.'])->assertOk();
    $this->softwarePackage = $package->fresh();
    $this->actingAs($this->softwareActor);
    [$request,$attempt,$delivery] = softwareDispatch($this);
    file_put_contents($this->softwareDirectory.DIRECTORY_SEPARATOR.$package->storage_key, $this->softwareArtifact.'tampered again');
    expect(fn () => $this->softwareCommands->artifact($this->softwareCredential, $attempt->attempt_uuid))->toThrow(HttpException::class);
    expect($delivery->fresh()->claimed_at)->toBeNull();
});

it('accepts attended decline and known busy receipts without an automatic retry or false completion', function () {
    $this->softwarePackage = softwarePublish($this);
    [$request,$attempt,$delivery] = softwareDispatch($this);
    $declined = ['command_uuid' => $request->command_uuid, 'payload_hash' => $delivery->payload_hash, 'status' => 'declined', 'mutation_started' => false, 'reason_code' => 'declined', 'exit_code' => null, 'reboot_required' => false, 'boot_id' => $this->softwareAgent->boot_id];
    $this->softwareCommands->result($this->softwareCredential, $attempt->attempt_uuid, $declined);
    expect($request->fresh()->status)->toBe(CommandStatus::Failed)->and($delivery->fresh()->claimed_at)->toBeNull()->and($this->softwareCommands->projection($request->fresh(), $this->softwareActor, $attempt->fresh())['state'])->toBe('failed');
    [$busyRequest,$busyAttempt,$busyDelivery] = softwareDispatch($this);
    $this->softwareCommands->claim($this->softwareCredential, $busyAttempt->attempt_uuid, softwareClaim($this, $busyRequest, $busyDelivery));
    $this->softwareCommands->result($this->softwareCredential, $busyAttempt->attempt_uuid, softwareResult($this, $busyRequest, $busyDelivery, 1618));
    expect($busyRequest->fresh()->status)->toBe(CommandStatus::Failed)->and($busyDelivery->fresh()->result['reason_code'])->toBe('installer_busy');
    expect(fn () => DB::transaction(fn () => app(EndpointMutationGuard::class)->assertAvailable($this->softwareAgent->fresh(), (new EndpointDelivery)->forceFill(['id' => 999999]))))->toThrow(HttpException::class);
    $this->travel(3)->seconds();
    $ack = app(EndpointHeartbeat::class)->record($this->softwareCredential, softwareHeartbeat($this, $busyRequest, $busyAttempt, ['state' => 'absent', 'product_version' => null]));
    expect($ack['software_observations_accepted'][0]['status'])->toBe('uncertain')->and($busyRequest->fresh()->status)->toBe(CommandStatus::Failed);
    expect(DB::transaction(fn () => app(EndpointMutationGuard::class)->assertAvailable($this->softwareAgent->fresh(), (new EndpointDelivery)->forceFill(['id' => 999999]))))->toBeNull();
});

it('cannot convert attended consent to unattended without current separate policy and actor authorization', function () {
    $this->softwarePackage = softwarePublish($this);
    DB::table('endpoint_software_policies')->where('device_id', $this->softwareDevice->id)->update(['consent_mode' => 'unattended']);
    expect(fn () => softwareDispatch($this))->toThrow(HttpException::class);
    softwareGrant($this->softwareActor, EndpointSoftwarePolicy::UNATTENDED);
    [$request,$attempt,$delivery] = softwareDispatch($this);
    $input = softwareClaim($this, $request, $delivery);
    $this->withToken($this->softwareCredential)->postJson('/api/endpoints/agent/software/'.$attempt->attempt_uuid.'/claim', $input)->assertUnprocessable();
    $claim = $this->softwareCommands->claim($this->softwareCredential, $attempt->attempt_uuid, array_diff_key($input, ['consent' => true]));
    $payload = json_decode(base64_decode($claim['payload']), true);
    expect($payload['consent_mode'])->toBe('unattended')->and($payload['desktop_session'])->toBeNull()->and($payload['consent_nonce'])->toBeNull();
});
