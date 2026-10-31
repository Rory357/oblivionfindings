<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointAgentIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptReadiness;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.command_signing_secret', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('e', 32)))));
    URL::forceScheme('https');
    $this->endpointSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->endpointOperator = User::factory()->create(['approved_at' => now()]);
    $this->endpointOperator->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    $keys = ['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.update', 'securityDevices.commands.admin'];
    $this->endpointOperator->permissionOverrides()->syncWithoutDetaching(
        Permission::query()->whereIn('key', $keys)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
    );
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->endpointOperator->id, 'primary_site_id' => $this->endpointSite->id,
        'secondary_site_ids' => [], 'is_active' => true,
    ]);
    $this->endpointDevice = endpointDeviceAt($this->endpointSite);
});

function endpointDeviceAt(Site $site): Device
{
    $device = Device::factory()->create([
        'domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id, 'assignable_type' => 'site', 'assignable_id' => $site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute(),
    ]);

    return $device;
}

function endpointHeartbeatFixture(int $sequence = 1): array
{
    return [
        'sequence' => $sequence, 'observed_at' => now()->utc()->format('Y-m-d\\TH:i:s\\Z'),
        'agent_version' => '0.1.0', 'boot_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'inventory' => [
            'hostname' => 'PILOT-PC', 'os_version' => 'Windows test fixture', 'architecture' => 'amd64',
            'memory_total_bytes' => 8192, 'memory_available_bytes' => 4096, 'uptime_seconds' => 120,
            'cpu_percent' => null, 'disks' => [], 'software' => [], 'software_complete' => false,
            'services' => [], 'services_complete' => false, 'probe_errors' => ['cpu', 'software', 'services'],
        ],
    ];
}

function endpointEnrolledFixture($test): array
{
    $issue = app(EndpointAgentIdentity::class)->issue($test->endpointOperator, $test->endpointDevice, 'windows');
    $credential = bin2hex(random_bytes(32));
    app(EndpointAgentIdentity::class)->enrol($issue['enrolment_token'], $credential, 'windows');

    return [$issue, $credential];
}

function endpointScriptReadinessWire(string $name): string
{
    $path = getenv('OBLI_SCRIPT_READINESS_CORPUS') ?: base_path('tests/fixtures/endpoint-script-readiness-go.json');
    $corpus = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    foreach ($corpus['documents'] as $row) {
        if ($row['name'] === $name) {
            return $row['request_json'];
        }
    }
    throw new LogicException('Original Go heartbeat fixture is missing.');
}

/**
 * The committed Go heartbeat corpus carries fixed observed_at times, so these
 * tests run at the corpus time. beforeEach has already assigned the device to
 * its Site at the real "now", which would then lie in the future and leave the
 * device with no current Site, so the assignment is re-dated to the test clock.
 */
function endpointScriptReadinessClock($test): void
{
    $test->travelTo(CarbonImmutable::parse('2026-10-21T00:00:00Z'));
    DeviceAssignment::query()->where('device_id', $test->endpointDevice->id)
        ->update(['assigned_at' => now()->subMinute()]);
}

function endpointScriptReadinessPost($test, string $credential, string $raw)
{
    return $test->call('POST', 'https://localhost/api/endpoints/agent/heartbeat', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$credential,
    ], $raw);
}

function endpointScriptReadinessDeny(callable $action, int $status): void
{
    try {
        $action();
        test()->fail('Expected script readiness denial.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe($status);
    }
}

it('stores original Go script readiness without enabling routes and clears it on a newer omitted report', function () {
    endpointScriptReadinessClock($this);
    [, $credential] = endpointEnrolledFixture($this);
    $ready = endpointScriptReadinessWire('ready');
    $absent = endpointScriptReadinessWire('absent');
    endpointScriptReadinessPost($this, $credential, $ready)->assertOk()->assertJsonPath('acknowledged_sequence', 1);
    $read = fn () => DB::transaction(fn () => app(EndpointScriptReadiness::class)->current($this->endpointDevice));
    $agent = $read();
    expect($agent->script_actions)->toEqual(['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'reviewed_script']);
    $seen = $agent->last_seen_at->toISOString();
    $this->travel(10)->seconds();
    endpointScriptReadinessPost($this, $credential, $ready)->assertOk();
    expect($read()->last_seen_at->toISOString())->toBe($seen);
    config()->set('endpoint_management.scripts.release_enabled', true);
    $this->endpointDevice->forceFill(['config' => ['management' => ['capabilities' => ['endpoint.script.run']]]])->save();
    expect(app(EndpointScriptPolicy::class)->available())->toBeFalse()
        ->and(app(DeclaredDeviceCommandCapabilities::class)->supports($this->endpointDevice, 'endpoint.script.run'))->toBeFalse()
        ->and(app(CommandExecutionRouteResolver::class)->resolve($this->endpointDevice, (int) $this->endpointSite->id, 'endpoint.script.run')->available)->toBeFalse();
    endpointScriptReadinessPost($this, $credential, $absent)->assertOk()->assertJsonPath('acknowledged_sequence', 2);
    expect(EndpointAgent::query()->sole()->script_actions)->toBeNull();
    endpointScriptReadinessDeny($read, 409);
    endpointScriptReadinessPost($this, $credential, $ready)->assertConflict();
    expect(EndpointAgent::query()->sole()->script_actions)->toBeNull()
        ->and(DB::table('device_command_requests')->count())->toBe(0)
        ->and(DB::table('endpoint_deliveries')->count())->toBe(0);
});

it('rejects malformed and ambiguous script readiness before accepting a heartbeat sequence', function () {
    endpointScriptReadinessClock($this);
    [, $credential] = endpointEnrolledFixture($this);
    $raw = endpointScriptReadinessWire('ready');
    $base = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $valid = $base['script_actions'];
    foreach ([
        null, [], 'reviewed_script', [...$valid, 'version' => '1'], [...$valid, 'version' => 1.5],
        [...$valid, 'installed_service' => 1], [...$valid, 'installed_service' => false],
        [...$valid, 'release_enabled' => 'true'], [...$valid, 'release_enabled' => false],
        [...$valid, 'executor' => 'windows_msi'], [...$valid, 'extra' => true],
        array_diff_key($valid, ['installed_service' => true]),
    ] as $invalid) {
        endpointScriptReadinessPost($this, $credential, json_encode([...$base, 'script_actions' => $invalid], JSON_THROW_ON_ERROR))->assertUnprocessable();
    }
    foreach ([
        str_replace('"version":1', '"version":0,"version":1', $raw),
        str_replace('"version":1', '"vers\\u0069on":0,"version":1', $raw),
        str_replace('"version":1', '"version":1.0', $raw),
        substr($raw, 0, -1).',"script_actions":'.json_encode($valid, JSON_THROW_ON_ERROR).'}',
        $raw.'{}', '[]', '{"script_actions":',
        str_replace('SYNTHETIC-SCRIPT-PC', "\xFF", $raw),
    ] as $invalid) {
        endpointScriptReadinessPost($this, $credential, $invalid)->assertUnprocessable();
    }
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat?extra=1', $base)->assertStatus(400);
    $this->withToken($credential)->post('/api/endpoints/agent/heartbeat', $base, ['Accept' => 'application/json'])->assertStatus(415);
    expect(EndpointAgent::query()->sole()->sequence)->toBe(0)
        ->and(EndpointAgent::query()->sole()->script_actions)->toBeNull();
});

it('rechecks script readiness freshness architecture assignment revocation and replacement', function () {
    endpointScriptReadinessClock($this);
    [$issue, $credential] = endpointEnrolledFixture($this);
    $raw = endpointScriptReadinessWire('ready');
    endpointScriptReadinessPost($this, $credential, $raw)->assertOk();
    $read = fn () => DB::transaction(fn () => app(EndpointScriptReadiness::class)->current($this->endpointDevice));
    $agent = $read();
    $original = $agent->getRawOriginal();
    foreach ([
        ['last_seen_at' => now()->subSeconds(180)], ['last_seen_at' => now()->addSecond()],
        ['observed_at' => now()->subSeconds(120)], ['observed_at' => now()->addSeconds(31)],
        ['inventory' => json_encode([...$agent->inventory, 'architecture' => '386'], JSON_THROW_ON_ERROR)],
        ['boot_id' => 'invalid'], ['heartbeat_hash' => null], ['sequence' => 0],
        ['script_actions' => json_encode([...$agent->script_actions, 'installed_service' => false], JSON_THROW_ON_ERROR)],
    ] as $changed) {
        DB::table('endpoint_agents')->where('id', $agent->id)->update($changed);
        endpointScriptReadinessDeny($read, 409);
        DB::table('endpoint_agents')->where('id', $agent->id)->update(array_intersect_key($original, $changed));
    }
    $assignment = $this->endpointDevice->assignments()->firstOrFail();
    $assignment->update(['released_at' => now()]);
    DeviceAssignment::create(['device_id' => $this->endpointDevice->id, 'assignable_type' => 'site',
        'assignable_id' => $this->endpointSite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
    endpointScriptReadinessDeny($read, 401);
    app(EndpointAgentIdentity::class)->revoke($this->endpointOperator, $this->endpointDevice, 'Synthetic changed computer assignment.', $issue['agent_uuid']);
    endpointScriptReadinessDeny($read, 409);
    $replacement = app(EndpointAgentIdentity::class)->issue($this->endpointOperator, $this->endpointDevice, 'windows');
    expect($replacement['agent_uuid'])->not->toBe($issue['agent_uuid'])
        ->and(EndpointAgent::query()->sole()->script_actions)->toBeNull();
    endpointScriptReadinessPost($this, $credential, $raw)->assertUnauthorized();
    endpointScriptReadinessDeny($read, 409);
});

it('issues site-scoped enrolment with no plaintext credentials in persistent records', function () {
    $response = $this->actingAs($this->endpointOperator)->postJson(
        "/security-devices/devices/{$this->endpointDevice->id}/endpoint/enrolments", ['platform' => 'windows'],
    )->assertCreated()->assertHeader('Pragma', 'no-cache');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $token = $response->json('enrolment_token');
    $agent = EndpointAgent::query()->sole();
    expect($agent->site_id)->toBe($this->endpointSite->id)
        ->and($agent->enrolment_hash)->toBe(hash('sha256', $token))
        ->and(json_encode($agent->toArray()))->not->toContain($token)
        ->and(DeviceEvent::query()->first()->payload)->toHaveKey('actor_user_id')
        ->and(json_encode(DeviceEvent::query()->get()->toArray()))->not->toContain($token);
});

it('conceals another site and denies a viewer even if they know the device id', function () {
    $hidden = endpointDeviceAt(Site::factory()->create(['is_active' => true, 'archived' => false]));
    $this->actingAs($this->endpointOperator)->postJson("/security-devices/devices/{$hidden->id}/endpoint/enrolments", ['platform' => 'windows'])->assertNotFound();
    $permission = Permission::query()->where('key', 'securityDevices.commands.admin')->firstOrFail();
    $this->endpointOperator->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    $this->actingAs($this->endpointOperator->fresh())->postJson(
        "/security-devices/devices/{$this->endpointDevice->id}/endpoint/enrolments", ['platform' => 'windows'],
    )->assertForbidden();
    expect(EndpointAgent::query()->count())->toBe(0);
});

it('allows lost enrolment replies to retry only with the identical client credential', function () {
    $issue = app(EndpointAgentIdentity::class)->issue($this->endpointOperator, $this->endpointDevice, 'windows');
    $credential = str_repeat('a', 64);
    $this->withToken($issue['enrolment_token'])->postJson('/api/endpoints/agent/enrol', ['credential' => $credential, 'platform' => 'windows'])->assertCreated();
    $this->withToken($issue['enrolment_token'])->postJson('/api/endpoints/agent/enrol', ['credential' => $credential, 'platform' => 'windows'])->assertCreated();
    $this->withToken($issue['enrolment_token'])->postJson('/api/endpoints/agent/enrol', ['credential' => str_repeat('c', 64), 'platform' => 'windows'])->assertUnauthorized();
    expect(EndpointAgent::query()->sole()->credential_hash)->toBe(hash('sha256', $credential))
        ->and(DeviceEvent::query()->where('event_type', 'endpoint_enrolled')->count())->toBe(1);
});

it('rejects expired and wrong-platform enrolments and rechecks issuer permissions', function () {
    $issue = app(EndpointAgentIdentity::class)->issue($this->endpointOperator, $this->endpointDevice, 'windows');
    $this->withToken($issue['enrolment_token'])->postJson('/api/endpoints/agent/enrol', ['credential' => str_repeat('a', 64), 'platform' => 'linux'])->assertUnprocessable();
    $this->endpointOperator->update(['approved_at' => null]);
    $this->withToken($issue['enrolment_token'])->postJson('/api/endpoints/agent/enrol', ['credential' => str_repeat('a', 64), 'platform' => 'windows'])->assertForbidden();
    $this->endpointOperator->update(['approved_at' => now()]);
    EndpointAgent::query()->update(['enrolment_expires_at' => now()->subSecond()]);
    $this->withToken($issue['enrolment_token'])->postJson('/api/endpoints/agent/enrol', ['credential' => str_repeat('a', 64), 'platform' => 'windows'])->assertUnauthorized();
    expect(EndpointAgent::query()->sole()->enrolled_at)->toBeNull();
});

it('accepts health reports without rewriting canonical device identity or lifecycle', function () {
    [, $credential] = endpointEnrolledFixture($this);
    $before = $this->endpointDevice->fresh()->getRawOriginal();
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', endpointHeartbeatFixture())->assertOk()->assertJsonPath('acknowledged_sequence', 1);
    $after = $this->endpointDevice->fresh()->getRawOriginal();
    unset($before['last_seen_at'], $before['updated_at'], $after['last_seen_at'], $after['updated_at']);
    expect($after)->toBe($before)
        ->and(EndpointAgent::query()->sole()->inventory['hostname'])->toBe('PILOT-PC')
        ->and(EndpointAgent::query()->sole()->inventory['software_complete'])->toBeFalse();
});

it('acknowledges identical retries without refreshing freshness and rejects changed or older sequences', function () {
    [, $credential] = endpointEnrolledFixture($this);
    $payload = endpointHeartbeatFixture(2);
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', $payload)->assertOk();
    $seen = EndpointAgent::query()->sole()->last_seen_at->toISOString();
    $this->travel(5)->seconds();
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', $payload)->assertOk();
    expect(EndpointAgent::query()->sole()->last_seen_at->toISOString())->toBe($seen);
    $payload['inventory']['hostname'] = 'Changed replay';
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', $payload)->assertConflict();
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', endpointHeartbeatFixture(1))->assertConflict();
});

it('rejects stale clocks, unknown private inventory fields and oversized requests', function () {
    [, $credential] = endpointEnrolledFixture($this);
    $stale = endpointHeartbeatFixture();
    $stale['observed_at'] = now()->subMinutes(5)->utc()->format('Y-m-d\\TH:i:s\\Z');
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', $stale)->assertUnprocessable();
    $private = endpointHeartbeatFixture();
    $private['inventory']['logged_in_user'] = 'Should never be stored';
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', $private)->assertUnprocessable();
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', ['oversized' => str_repeat('a', 1048577)])->assertStatus(413);
    expect(EndpointAgent::query()->sole()->sequence)->toBe(0);
});

it('requires a reason for revocation and immediately rejects old credentials and tokens', function () {
    [$issue, $credential] = endpointEnrolledFixture($this);
    $path = "/security-devices/devices/{$this->endpointDevice->id}/endpoint/revoke";
    $this->actingAs($this->endpointOperator)->postJson($path, ['reason' => 'short'])->assertUnprocessable();
    $this->actingAs($this->endpointOperator)->postJson($path, ['reason' => 'Host replaced after a hardware failure.'])->assertOk();
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', endpointHeartbeatFixture())->assertUnauthorized();
    $this->withToken($issue['enrolment_token'])->postJson('/api/endpoints/agent/enrol', ['credential' => $credential, 'platform' => 'windows'])->assertUnauthorized();
    expect(EndpointAgent::query()->sole()->credential_hash)->toBeNull();
    $replacement = app(EndpointAgentIdentity::class)->issue($this->endpointOperator, $this->endpointDevice, 'windows');
    expect($replacement['agent_uuid'])->not->toBe($issue['agent_uuid']);
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', endpointHeartbeatFixture())->assertUnauthorized();
});

it('rejects a changed assignment even within the same site', function () {
    [, $credential] = endpointEnrolledFixture($this);
    $assignment = $this->endpointDevice->assignments()->firstOrFail();
    $assignment->update(['released_at' => now()]);
    DeviceAssignment::query()->create([
        'device_id' => $this->endpointDevice->id, 'assignable_type' => 'site', 'assignable_id' => $this->endpointSite->id,
        'assignment_type' => 'permanent', 'assigned_at' => now(),
    ]);
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', endpointHeartbeatFixture())->assertUnauthorized();
});

it('rejects disabled sites and retired computers without resurrecting them', function () {
    [, $credential] = endpointEnrolledFixture($this);
    $this->endpointSite->update(['is_active' => false]);
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', endpointHeartbeatFixture())->assertUnauthorized();
    $this->endpointSite->update(['is_active' => true]);
    $this->endpointDevice->update(['status' => 'decommissioned']);
    $this->withToken($credential)->postJson('/api/endpoints/agent/heartbeat', endpointHeartbeatFixture())->assertUnauthorized();
    expect($this->endpointDevice->fresh()->status->value)->toBe('decommissioned');
});

it('requires HTTPS and closes the transport when native management is disabled', function () {
    $this->postJson('http://localhost/api/endpoints/agent/heartbeat', endpointHeartbeatFixture())->assertStatus(400);
    config()->set('endpoint_management.enabled', false);
    $this->withServerVariables(['HTTPS' => 'on'])->postJson('/api/endpoints/agent/heartbeat', endpointHeartbeatFixture())->assertNotFound();
});

it('downloads a deployment-bound installation profile with a one-time scoped secret', function () {
    config()->set('endpoint_management.server_url', 'https://care.example.nz/');
    config()->set('endpoint_management.organisation_label', 'Pilot Care');
    $path = "/security-devices/devices/{$this->endpointDevice->id}/endpoint/bootstrap";
    $response = $this->actingAs($this->endpointOperator)->postJson($path)
        ->assertCreated()->assertJsonPath('version', 1)->assertJsonPath('platform', 'windows')
        ->assertJsonPath('server_url', 'https://care.example.nz')
        ->assertJsonPath('organisation', 'Pilot Care')
        ->assertJsonPath('device_uid', $this->endpointDevice->device_uid)
        ->assertHeader('Content-Disposition', 'attachment; filename="oblivion-computer-'.$this->endpointDevice->id.'.json"')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $agent = EndpointAgent::query()->sole();
    $token = $response->json('enrolment_token');
    expect($response->json('agent_uuid'))->toBe($agent->uuid)
        ->and($agent->enrolment_hash)->toBe(hash('sha256', $token))
        ->and($agent->site_id)->toBe($this->endpointSite->id)
        ->and(json_encode(DeviceEvent::query()->get()->toArray()))->not->toContain($token);
    $this->actingAs($this->endpointOperator)->postJson($path)->assertUnprocessable();
    expect(EndpointAgent::query()->sole()->uuid)->toBe($agent->uuid);
    $this->withToken($token)->postJson('/api/endpoints/agent/enrol', [
        'credential' => str_repeat('d', 64), 'platform' => 'windows',
    ])->assertCreated()->assertJsonPath('agent_uuid', $agent->uuid);
});

it('protects installation profile downloads by current site and administrator permissions', function () {
    config()->set('endpoint_management.server_url', 'https://care.example.nz');
    config()->set('endpoint_management.organisation_label', 'Pilot Care');
    $hidden = endpointDeviceAt(Site::factory()->create(['is_active' => true, 'archived' => false]));
    $this->actingAs($this->endpointOperator)->postJson("/security-devices/devices/{$hidden->id}/endpoint/bootstrap")->assertNotFound();
    $permission = Permission::query()->where('key', 'securityDevices.commands.admin')->firstOrFail();
    $this->endpointOperator->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    $this->actingAs($this->endpointOperator->fresh())->postJson(
        "/security-devices/devices/{$this->endpointDevice->id}/endpoint/bootstrap",
    )->assertForbidden();
    expect(EndpointAgent::query()->count())->toBe(0);
});

it('rejects Linux computer enrolment at HTTP and service boundaries', function () {
    $this->actingAs($this->endpointOperator)->postJson(
        "/security-devices/devices/{$this->endpointDevice->id}/endpoint/enrolments", ['platform' => 'linux'],
    )->assertUnprocessable();
    expect(fn () => app(EndpointAgentIdentity::class)->issue($this->endpointOperator, $this->endpointDevice, 'linux'))
        ->toThrow(ValidationException::class);
    expect(EndpointAgent::query()->count())->toBe(0);
});

it('previews installation without issuing secrets and binds creation to the reviewed destination', function () {
    config()->set('endpoint_management.server_url', 'https://care.example.nz');
    config()->set('endpoint_management.organisation_label', 'Pilot Care');
    $base = "https://localhost/security-devices/devices/{$this->endpointDevice->id}/endpoint";
    $preview = $this->actingAs($this->endpointOperator)->getJson($base.'/bootstrap-options')->assertOk()
        ->assertJsonPath('actor_id', $this->endpointOperator->id)
        ->assertJsonPath('device_id', $this->endpointDevice->id)
        ->assertJsonPath('site.id', $this->endpointSite->id)
        ->assertJsonPath('server_url', 'https://care.example.nz')
        ->assertJsonPath('configured', true)->assertJsonPath('enrolment.state', 'none');
    expect(EndpointAgent::query()->count())->toBe(0)
        ->and($preview->json())->not->toHaveKeys(['enrolment_token', 'assignment_fingerprint', 'server_public_key'])
        ->and($preview->headers->get('Cache-Control'))->toContain('no-store');
    config()->set('endpoint_management.server_url', 'https://changed.example.nz');
    $this->postJson($base.'/bootstrap', ['expected_context' => $preview->json('context_hash')])->assertConflict();
    expect(EndpointAgent::query()->count())->toBe(0);
    $fresh = $this->getJson($base.'/bootstrap-options')->assertOk();
    $this->postJson($base.'/bootstrap', ['expected_context' => $fresh->json('context_hash')])->assertCreated()
        ->assertJsonPath('server_url', 'https://changed.example.nz');
    $this->getJson($base.'/bootstrap-options')->assertOk()->assertJsonPath('enrolment.state', 'pending');
});

it('refuses reviewed metadata that changed before profile creation', function () {
    config()->set('endpoint_management.server_url', 'https://care.example.nz');
    $base = "https://localhost/security-devices/devices/{$this->endpointDevice->id}/endpoint";
    $this->actingAs($this->endpointOperator);
    $context = $this->getJson($base.'/bootstrap-options')->assertOk()->json('context_hash');
    $this->endpointDevice->update(['name' => 'Renamed computer']);
    $this->postJson($base.'/bootstrap', ['expected_context' => $context])->assertConflict();
    expect(EndpointAgent::query()->count())->toBe(0);
    $context = $this->getJson($base.'/bootstrap-options')->assertOk()->json('context_hash');
    $assignment = $this->endpointDevice->assignments()->firstOrFail();
    $assignment->update(['released_at' => now()]);
    DeviceAssignment::query()->create(['device_id' => $this->endpointDevice->id, 'assignable_type' => 'site',
        'assignable_id' => $this->endpointSite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
    $this->postJson($base.'/bootstrap', ['expected_context' => $context])->assertConflict();
});

it('makes configuration gaps explicit without disclosing secrets or preventing specific revocation', function () {
    $issue = app(EndpointAgentIdentity::class)->issue($this->endpointOperator, $this->endpointDevice, 'windows');
    config()->set('endpoint_management.server_url', '');
    $base = "https://localhost/security-devices/devices/{$this->endpointDevice->id}/endpoint";
    $this->actingAs($this->endpointOperator)->getJson($base.'/bootstrap-options')->assertOk()
        ->assertJsonPath('configured', false)->assertJsonPath('server_url', null)->assertJsonPath('enrolment.state', 'pending')
        ->assertDontSee($issue['enrolment_token']);
    $this->postJson($base.'/revoke', ['reason' => 'Installation profile was lost before setup.', 'expected_agent_uuid' => $issue['agent_uuid']])->assertOk();
    $this->getJson($base.'/bootstrap-options')->assertOk()->assertJsonPath('enrolment.state', 'revoked');
});

it('never revokes a replacement enrolment from a stale review', function () {
    $identity = app(EndpointAgentIdentity::class);
    $first = $identity->issue($this->endpointOperator, $this->endpointDevice, 'windows');
    $identity->revoke($this->endpointOperator, $this->endpointDevice, 'Replace the expired installation profile.');
    $replacement = $identity->issue($this->endpointOperator, $this->endpointDevice, 'windows');
    $this->actingAs($this->endpointOperator)->postJson(
        "https://localhost/security-devices/devices/{$this->endpointDevice->id}/endpoint/revoke",
        ['reason' => 'An earlier profile was lost during download.', 'expected_agent_uuid' => $first['agent_uuid']],
    )->assertConflict();
    expect(EndpointAgent::query()->sole()->uuid)->toBe($replacement['agent_uuid'])
        ->and(EndpointAgent::query()->sole()->revoked_at)->toBeNull();
});

it('conceals hidden installation previews and uses current verified internal actor evidence', function () {
    config()->set('endpoint_management.server_url', 'https://care.example.nz');
    $hidden = endpointDeviceAt(Site::factory()->create(['is_active' => true, 'archived' => false]));
    $this->actingAs($this->endpointOperator)->getJson("https://localhost/security-devices/devices/{$hidden->id}/endpoint/bootstrap-options")->assertNotFound();
    $base = "https://localhost/security-devices/devices/{$this->endpointDevice->id}/endpoint";
    User::query()->whereKey($this->endpointOperator->id)->update(['email_verified_at' => null]);
    $this->getJson($base.'/bootstrap-options')->assertForbidden();
    $this->postJson($base.'/bootstrap')->assertForbidden();
    expect(EndpointAgent::query()->count())->toBe(0);
});

it('exposes setup only for enabled computer administrators in the real device page', function () {
    $this->endpointDevice->update(['name' => 'Reception PC', 'device_uid' => 'RECEPTION-01']);
    $this->endpointSite->update(['name' => 'Main office']);
    $this->endpointOperator->update(['name' => 'Development reviewer']);
    $path = '/security-devices/devices/'.$this->endpointDevice->id;
    $response = $this->actingAs($this->endpointOperator)->get($path)->assertOk()
        ->assertInertia(fn ($page) => $page->component('security-devices/devices/show')
            ->where('endpoint_setup.actor_id', $this->endpointOperator->id));
    if (getenv('OBLI_PROFILE_PREVIEW') === '1') {
        // Optional visual fixture: generated test data from this isolated test database.
        file_put_contents(base_path('.endpoint-tools/profile-device-page.json'), json_encode($response->viewData('page'), JSON_THROW_ON_ERROR));
    }
    config()->set('endpoint_management.enabled', false);
    $this->get($path)->assertOk()->assertInertia(fn ($page) => $page->where('endpoint_setup', null));
    config()->set('endpoint_management.enabled', true);
    $this->endpointDevice->update(['category' => 'switch']);
    $this->get($path)->assertOk()->assertInertia(fn ($page) => $page->where('endpoint_setup', null));
    $this->endpointDevice->update(['category' => 'endpoint']);
    $permission = Permission::query()->where('key', 'securityDevices.commands.admin')->sole();
    $this->endpointOperator->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    $this->actingAs($this->endpointOperator->fresh())->get($path)->assertOk()
        ->assertInertia(fn ($page) => $page->where('endpoint_setup', null));
});
