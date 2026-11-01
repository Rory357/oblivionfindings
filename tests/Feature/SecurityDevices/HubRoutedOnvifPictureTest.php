<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Services\CollectorIngestService;
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
 * S&D Phase E (R5): "Capture snapshot" on a standard ONVIF camera at a Site with a hub. The hub asks
 * the camera itself (digest logins only) and the picture is shown once to the person who asked.
 * A hub too old to list the ONVIF job is never sent one.
 */

const HUB_ONVIF_ADAPTERS = 'unifi_access_timed_unlock_v1,ssh_config_backup_v1,camera_snapshot_v1,camera_snapshot_onvif_v1,alarm_area_control_v1';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'hub-onvif');
    config()->set('monitoring.signing.keys', ['hub-onvif' => base64_encode(str_repeat('O', SODIUM_CRYPTO_AUTH_KEYBYTES))]);
    $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x61", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($configPair)));

    $this->site = Site::factory()->create(['name' => 'Rata Cottage', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.89.0.0/24'], 'protocols' => ['tcp'],
        'port_bounds' => ['tcp' => [80]], 'exclusions' => [], 'status' => 'active',
    ]);
    $this->camera = Device::factory()->create([
        'name' => 'Rata Cottage driveway camera', 'domain' => 'security', 'category' => 'cctv', 'subcategory' => 'camera',
        'manufacturer' => 'Hikvision', 'model' => 'DS-2CD2143G2', 'provider' => 'onvif', 'ip_address' => '10.89.0.40', 'last_seen_at' => now(),
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->camera->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    CredentialReference::query()->create([
        'reference_key' => 'database:hikvision/'.$this->site->id.'/onvif', 'site_id' => $this->site->id, 'provider' => 'hikvision',
        'purpose' => 'device_management', 'capabilities' => ['camera.snapshot'],
        'secret_manager_reference' => 'secret/data/sites/'.$this->site->id.'/hikvision',
        'secret_manager_reference_hash' => hash('sha256', 'hub-onvif-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);
    $this->app->instance(CredentialLeaseProvider::class, new class implements CredentialLeaseProvider
    {
        public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
        {
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinute(), ['username' => 'viewer', 'password' => 'HUB-ONVIF-TEST-ONLY-PASSWORD']);
        }
    });

    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x62", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Rata Cottage', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'hub-onvif-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
        'runtime_status' => ['version' => '2026.11.1', 'command_adapters' => HUB_ONVIF_ADAPTERS],
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

it('sends the ONVIF picture job to the Site hub and shows the picture once to the person who asked', function (): void {
    $start = $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot");
    $start->assertStatus(202)
        ->assertJsonPath('pending', true)
        ->assertJsonPath('message', 'Checking through Rata Cottage hub…');
    $statusUrl = $start->json('status_url');

    $configuration = ($this->hubJob)();
    $job = $configuration['commands'][0];
    expect($job['adapter'])->toBe('camera_snapshot_onvif_v1')
        ->and($job['protocol'])->toBe('command.camera_onvif')
        ->and($job['provider'])->toBe('onvif')
        ->and($job['target'])->toBe('10.89.0.40')
        ->and($job['parameters'])->toBe(['method' => 'onvif_media_v1'])
        ->and($job['endpoint'])->toBe([
            'scheme' => 'http', 'address' => '10.89.0.40', 'port' => 80, 'trusted_pin' => null,
            'media_path' => '/onvif/media_service', 'media2_path' => '/onvif/media2_service',
            'connect_timeout_seconds' => 5, 'response_timeout_seconds' => 10, 'max_image_bytes' => 1_048_576,
        ])
        ->and($configuration['scope']['protocols'])->toContain('command.camera_onvif')
        ->and(json_encode($configuration, JSON_THROW_ON_ERROR))->not->toContain('HUB-ONVIF-TEST-ONLY-PASSWORD');

    $picture = "\xFF\xD8\xFF\xE0".str_repeat("\x31", 64)."\xFF\xD9";
    ($this->ingest)($job, [
        'safe_result' => ['provider_state' => 'captured', 'mime' => 'image/jpeg', 'image_bytes' => strlen($picture), 'image_sha256' => hash('sha256', $picture)],
        'provider_request_reference' => 'camera-snapshot:'.$job['command_uuid'],
        'attachment' => ['kind' => 'image', 'encoding' => 'gzip+base64', 'bytes' => strlen($picture), 'sha256' => hash('sha256', $picture), 'data' => base64_encode(gzencode($picture))],
    ]);

    expect(DeviceCommandRequest::query()->sole()->status)->toBe(CommandStatus::Reconciled);
    $this->actingAs($this->it)->getJson($statusUrl)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('source', 'ONVIF, through Rata Cottage hub');
});

it('says in words when the camera asked for a plain password and the hub didn’t send it', function (): void {
    $statusUrl = $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot")->json('status_url');
    $job = ($this->hubJob)()['commands'][0];

    ($this->ingest)($job, [
        'execution_status' => 'failed',
        'safe_result' => ['reason_code' => 'basic_auth_refused'],
        'safe_failure_reason' => 'The camera asks for a plain (Basic) password, so the hub didn’t send it.',
    ]);

    $this->actingAs($this->it)->getJson($statusUrl)
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Turn on Digest authentication'));
});

it('refuses a failure code only the Protect job gives', function (): void {
    $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot");
    $job = ($this->hubJob)()['commands'][0];

    ($this->ingest)($job, ['execution_status' => 'failed', 'safe_result' => ['reason_code' => 'camera_not_mapped'], 'safe_failure_reason' => 'x']);

    expect(DeviceCommandRequest::query()->sole()->status)->not->toBe(CommandStatus::Failed);
});

it('never sends the ONVIF job to a hub too old to know it, and says why', function (): void {
    $this->hub->forceFill(['runtime_status' => ['version' => '2026.10.1', 'command_adapters' => 'unifi_access_timed_unlock_v1,ssh_config_backup_v1,camera_snapshot_v1']])->save();

    $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot")
        ->assertStatus(409)
        ->assertJsonPath('reason_code', 'hub_unavailable')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Rata Cottage hub needs its software update'));

    expect(DeviceCommandRequest::query()->count())->toBe(0);
});
