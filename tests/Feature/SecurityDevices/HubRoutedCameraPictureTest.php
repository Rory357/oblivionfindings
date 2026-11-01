<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Services\CollectorIngestService;
use App\Domain\SecurityDevices\Cctv\Models\CameraEndpointTrust;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * S&D Phase B, routing through hubs: "Capture snapshot" on a UniFi Protect camera at a Site with a
 * hub. The hub takes the picture (sending the API key only to the trusted console certificate), and
 * the picture is shown once to the person who asked.
 */

const HUB_CAMERA_PIN = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
const HUB_CAMERA_FINGERPRINT = 'AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'hub-camera');
    config()->set('monitoring.signing.keys', ['hub-camera' => base64_encode(str_repeat('K', SODIUM_CRYPTO_AUTH_KEYBYTES))]);
    $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x51", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($configPair)));

    $this->site = Site::factory()->create(['name' => 'Kowhai Villa', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.88.0.0/24'], 'protocols' => ['tcp', 'tls'],
        'port_bounds' => ['tcp' => [443]], 'exclusions' => [], 'status' => 'active',
    ]);
    $this->camera = Device::factory()->create([
        'name' => 'Kowhai Villa front door camera', 'domain' => 'security', 'category' => 'cctv', 'subcategory' => 'camera',
        'manufacturer' => 'Ubiquiti', 'model' => 'UVC-G5-Bullet', 'provider' => 'unifi_protect',
        'ip_address' => '10.88.0.31', 'mac_address' => 'AA:BB:CC:00:11:22', 'last_seen_at' => now(),
        'config' => ['protect' => ['console_ip' => '10.88.0.2']],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->camera->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    CredentialReference::query()->create([
        'reference_key' => 'database:unifi_protect/'.$this->site->id.'/api', 'site_id' => $this->site->id, 'provider' => 'unifi_protect',
        'purpose' => 'device_management', 'capabilities' => ['protect.snapshot'],
        'secret_manager_reference' => 'secret/data/sites/'.$this->site->id.'/protect',
        'secret_manager_reference_hash' => hash('sha256', 'hub-camera-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);
    $this->app->instance(CredentialLeaseProvider::class, new class implements CredentialLeaseProvider
    {
        public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
        {
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinute(), ['protect_api_key' => 'HUB-CAMERA-TEST-ONLY-API-KEY']);
        }
    });

    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x52", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Kowhai Villa', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'hub-camera-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
    ]);

    $this->viewer = function (): User {
        $user = User::factory()->create(['approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', 'it_manager')->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
        $permission = Permission::query()->where('key', 'securityDevices.cctv.media.view')->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $user->unsetRelations();

        return $user;
    };
    $this->it = ($this->viewer)();

    $this->hubJob = function (): array {
        $envelope = app(CollectorConfigurationService::class)->signedEnvelope($this->hub->fresh(), 0);
        $signed = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);

        return json_decode(base64_decode($signed['payload'], true), true, flags: JSON_THROW_ON_ERROR);
    };
    $this->ingest = function (array $job, array $overrides): void {
        $request = DeviceCommandRequest::query()->where('command_uuid', $job['command_uuid'])->sole();
        $attempt = DeviceCommandAttempt::query()->where('attempt_uuid', $job['attempt_uuid'])->sole();
        $at = CarbonImmutable::now('UTC')->startOfSecond();
        app(CollectorIngestService::class)->ingest($this->hub->fresh(), [[
            'id' => 'command-result:'.$attempt->attempt_uuid,
            'source_sequence' => 1,
            'created_at' => $at->format(DATE_ATOM),
            'payload' => array_replace([
                'item_type' => 'command_result',
                'command_uuid' => $request->command_uuid,
                'attempt_uuid' => $attempt->attempt_uuid,
                'attempt_number' => 1,
                'site_id' => $this->site->id,
                'device_id' => (string) $this->camera->id,
                'capability' => 'camera.snapshot.capture',
                'contract_hash' => $job['contract_hash'],
                'execution_status' => 'succeeded',
                'safe_result' => [],
                'provider_request_reference' => null,
                'safe_failure_reason' => null,
                'accepted_at' => $at->format(DATE_ATOM),
                'started_at' => $at->format(DATE_ATOM),
                'completed_at' => $at->format(DATE_ATOM),
                'reconciliation' => null,
            ], $overrides),
        ]]);
    };
});

it('asks the Site hub for the picture and gives it, once, to the person who asked', function (): void {
    $start = $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot");
    $start->assertStatus(202)
        ->assertJsonPath('pending', true)
        ->assertJsonPath('message', 'Checking through Kowhai Villa hub…');
    $statusUrl = $start->json('status_url');

    $configuration = ($this->hubJob)();
    $job = $configuration['commands'][0];
    expect($job['adapter'])->toBe('camera_snapshot_v1')
        ->and($job['target'])->toBe('10.88.0.2')
        ->and($job['endpoint']['trusted_pin'])->toBeNull()
        ->and($job['endpoint']['mac'])->toBe('AABBCC001122')
        ->and(json_encode($configuration, JSON_THROW_ON_ERROR))->not->toContain('HUB-CAMERA-TEST-ONLY-API-KEY');

    $this->actingAs($this->it)->getJson($statusUrl)->assertOk()->assertJsonPath('pending', true);

    $picture = "\xFF\xD8\xFF\xE0".str_repeat("\x10", 64)."\xFF\xD9";
    ($this->ingest)($job, [
        'safe_result' => ['provider_state' => 'captured', 'mime' => 'image/jpeg', 'image_bytes' => strlen($picture), 'image_sha256' => hash('sha256', $picture)],
        'provider_request_reference' => 'camera-snapshot:'.$job['command_uuid'],
        'attachment' => ['kind' => 'image', 'encoding' => 'gzip+base64', 'bytes' => strlen($picture), 'sha256' => hash('sha256', $picture), 'data' => base64_encode(gzencode($picture))],
    ]);

    expect(DeviceCommandRequest::query()->sole()->status)->toBe(CommandStatus::Reconciled);
    $this->actingAs(($this->viewer)())->getJson($statusUrl)->assertNotFound();
    $this->actingAs($this->it)->getJson($statusUrl)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data_uri', 'data:image/jpeg;base64,'.base64_encode($picture))
        ->assertJsonPath('source', 'UniFi Protect, through Kowhai Villa hub');
    $this->actingAs($this->it)->getJson($statusUrl)
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'This picture has already been shown or has expired. Check the camera again for a new one.');
});

it('records the certificate the console offered to the hub and asks to trust it', function (): void {
    $statusUrl = $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot")->json('status_url');
    $job = ($this->hubJob)()['commands'][0];

    ($this->ingest)($job, [
        'execution_status' => 'failed',
        'safe_result' => [
            'reason_code' => 'camera_certificate_untrusted', 'offered_pin' => HUB_CAMERA_PIN,
            'offered_fingerprint' => HUB_CAMERA_FINGERPRINT, 'offered_subject' => 'UDM-Pro', 'offered_valid_to' => now()->addYear()->timestamp,
        ],
        'safe_failure_reason' => 'The Protect console’s certificate isn’t trusted yet, so no API key was sent.',
    ]);

    $record = CameraEndpointTrust::query()->where('site_id', $this->site->id)->where('host', '10.88.0.2')->where('port', 443)->sole();
    expect($record->offered_pin)->toBe(HUB_CAMERA_PIN)
        ->and($record->offered_fingerprint)->toBe(HUB_CAMERA_FINGERPRINT)
        ->and($record->trusted_pin)->toBeNull();

    $this->actingAs($this->it)->getJson($statusUrl)
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('tls_trust.offered.fingerprint', HUB_CAMERA_FINGERPRINT)
        ->assertJsonPath('tls_trust.host', '10.88.0.2');
});

it('sends the trusted pin to the hub once the console is trusted', function (): void {
    CameraEndpointTrust::query()->create([
        'site_id' => $this->site->id, 'host' => '10.88.0.2', 'port' => 443,
        'trusted_pin' => HUB_CAMERA_PIN, 'trusted_fingerprint' => HUB_CAMERA_FINGERPRINT, 'trusted_at' => now(),
    ]);
    $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot")->assertStatus(202);

    expect(($this->hubJob)()['commands'][0]['endpoint']['trusted_pin'])->toBe(HUB_CAMERA_PIN);
});

it('says plainly that other camera actions and alarm commands do not run through a hub yet, and never goes direct', function (): void {
    $onvifCamera = Device::factory()->create([
        'name' => 'Kowhai Villa garage camera', 'domain' => 'security', 'category' => 'cctv', 'subcategory' => 'camera',
        'manufacturer' => 'Hikvision', 'model' => 'DS-2CD2143G2', 'provider' => 'onvif', 'ip_address' => '10.88.0.40', 'last_seen_at' => now(),
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $onvifCamera->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);

    $this->actingAs($this->it)->postJson("/security-devices/devices/{$onvifCamera->id}/camera/snapshot")
        ->assertStatus(409)
        ->assertJsonPath('reason_code', 'hub_route_not_built');
    $this->actingAs($this->it)->getJson("/security-devices/devices/{$this->camera->id}/camera/streams")
        ->assertStatus(409)
        ->assertJsonPath('reason_code', 'hub_route_not_built');

    $route = app(\App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver::class)
        ->resolve($this->camera->fresh(), $this->site->id, 'alarm.disarm');
    expect($route->available)->toBeFalse()
        ->and($route->reason)->toContain('alarm commands through a hub aren’t built yet');
});

it('never reaches the camera from the server when the covering hub is offline', function (): void {
    $this->hub->forceFill(['status' => 'offline'])->save();

    $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot")
        ->assertStatus(409)
        ->assertJsonPath('reason_code', 'hub_unavailable')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Kowhai Villa hub isn’t connected'));

    expect(DeviceCommandRequest::query()->count())->toBe(0);
});
