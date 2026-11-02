<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Services\CollectorIngestService;
use App\Domain\SecurityDevices\Cctv\Models\CameraPrivacyHours;
use App\Domain\SecurityDevices\Cctv\Services\CameraPrivacy;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CameraSnapshotCollectorDescriptor;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 * S&D rebuild Phase E: a camera's privacy hours. Inside them every picture endpoint refuses (the
 * latest picture, the hub picture camera.snapshot.capture and live viewing) and the camera wall
 * and page say "Privacy hours until …"; outside them pictures work as before. Another Site's
 * camera is 404, and changing privacy hours needs securityDevices.devices.update and is audited.
 */

const PRIVACY_EVERY_NIGHT = [['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '22:00', 'to' => '06:00']];
const PRIVACY_MONDAY_EARLY = [['days' => [1], 'from' => '01:00', 'to' => '02:00']];

beforeEach(function (): void {
    // Wednesday 4 Nov 2026, 11:30 pm in New Zealand (NZDT, UTC+13).
    $this->travelTo(CarbonImmutable::parse('2026-11-04 23:30:00', 'Pacific/Auckland')->utc());
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'privacy-camera');
    config()->set('monitoring.signing.keys', ['privacy-camera' => base64_encode(str_repeat('P', SODIUM_CRYPTO_AUTH_KEYBYTES))]);
    $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x61", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($configPair)));

    $this->site = Site::factory()->create(['name' => 'Synthetic Privacy House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->otherSite = Site::factory()->create(['name' => 'Synthetic Other House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.77.0.0/24'], 'protocols' => ['tcp', 'tls'],
        'port_bounds' => ['tcp' => [443]], 'exclusions' => [], 'status' => 'active',
    ]);
    $this->camera = Device::factory()->create([
        'name' => 'Synthetic hallway camera', 'domain' => 'security', 'category' => 'cctv', 'subcategory' => 'camera',
        'manufacturer' => 'Ubiquiti', 'model' => 'UVC-G5-Dome', 'provider' => 'unifi_protect',
        'ip_address' => '10.77.0.31', 'mac_address' => 'AA:BB:CC:00:77:31', 'last_seen_at' => now(),
        'config' => ['protect' => ['console_ip' => '10.77.0.2']],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->camera->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    $this->theirCamera = Device::factory()->create([
        'name' => 'Synthetic other camera', 'domain' => 'security', 'category' => 'cctv', 'subcategory' => 'camera',
        'manufacturer' => 'Ubiquiti', 'model' => 'UVC-G5-Dome', 'provider' => 'unifi_protect', 'ip_address' => '10.78.0.31',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->theirCamera->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->otherSite->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    CredentialReference::query()->create([
        'reference_key' => 'database:unifi_protect/'.$this->site->id.'/api', 'site_id' => $this->site->id, 'provider' => 'unifi_protect',
        'purpose' => 'device_management', 'capabilities' => ['protect.snapshot'],
        'secret_manager_reference' => 'secret/data/sites/'.$this->site->id.'/protect',
        'secret_manager_reference_hash' => hash('sha256', 'privacy-camera-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);
    $this->app->instance(CredentialLeaseProvider::class, new class implements CredentialLeaseProvider
    {
        public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
        {
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinute(), ['protect_api_key' => 'PRIVACY-CAMERA-TEST-ONLY-KEY']);
        }
    });
    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x62", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Synthetic Privacy House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'privacy-camera-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
    ]);

    // IT at this house: sees the pictures, may change the camera.
    $this->it = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now()]);
    $this->it->roles()->attach(Role::query()->where('name', 'it_manager')->firstOrFail());
    HrEmployeeProfile::factory()->create(['user_id' => $this->it->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
    foreach (['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.cctv.media.view', 'securityDevices.devices.update'] as $key) {
        $this->it->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', $key)->firstOrFail()->id => ['allowed' => true]]);
    }
    $this->it->unsetRelations();

    $this->staffWith = function (array $keys): User {
        $user = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user;
    };
    $this->privacy = function (array $ranges, bool $enabled = true): void {
        CameraPrivacyHours::query()->updateOrCreate(['device_id' => $this->camera->id], ['enabled' => $enabled, 'ranges' => $ranges]);
    };
    $this->hubJob = function (): array {
        $envelope = app(CollectorConfigurationService::class)->signedEnvelope($this->hub->fresh(), 0);
        $signed = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);

        return json_decode(base64_decode($signed['payload'], true), true, flags: JSON_THROW_ON_ERROR);
    };
    $this->ingestPicture = function (array $job, string $picture): void {
        $request = DeviceCommandRequest::query()->where('command_uuid', $job['command_uuid'])->sole();
        $attempt = DeviceCommandAttempt::query()->where('attempt_uuid', $job['attempt_uuid'])->sole();
        $at = CarbonImmutable::now('UTC')->startOfSecond();
        app(CollectorIngestService::class)->ingest($this->hub->fresh(), [[
            'id' => 'command-result:'.$attempt->attempt_uuid,
            'source_sequence' => 1,
            'created_at' => $at->format(DATE_ATOM),
            'payload' => [
                'item_type' => 'command_result',
                'command_uuid' => $request->command_uuid,
                'attempt_uuid' => $attempt->attempt_uuid,
                'attempt_number' => 1,
                'site_id' => $this->site->id,
                'device_id' => (string) $this->camera->id,
                'capability' => 'camera.snapshot.capture',
                'contract_hash' => $job['contract_hash'],
                'execution_status' => 'succeeded',
                'safe_result' => ['provider_state' => 'captured', 'mime' => 'image/jpeg', 'image_bytes' => strlen($picture), 'image_sha256' => hash('sha256', $picture)],
                'provider_request_reference' => 'camera-snapshot:'.$job['command_uuid'],
                'safe_failure_reason' => null,
                'accepted_at' => $at->format(DATE_ATOM),
                'started_at' => $at->format(DATE_ATOM),
                'completed_at' => $at->format(DATE_ATOM),
                'reconciliation' => null,
                'attachment' => ['kind' => 'image', 'encoding' => 'gzip+base64', 'bytes' => strlen($picture), 'sha256' => hash('sha256', $picture), 'data' => base64_encode(gzencode($picture))],
            ],
        ]]);
    };
});

it('works out privacy hours in NZ time, overnight and back to back', function (): void {
    $wednesdayNight = CarbonImmutable::parse('2026-11-04 23:30:00', 'Pacific/Auckland');

    expect(CameraPrivacy::activeUntil(PRIVACY_EVERY_NIGHT, $wednesdayNight)?->toIso8601String())->toBe('2026-11-04T17:00:00+00:00')
        ->and(CameraPrivacy::activeUntil(PRIVACY_EVERY_NIGHT, CameraPrivacy::activeUntil(PRIVACY_EVERY_NIGHT, $wednesdayNight)))->toBeNull()
        ->and(CameraPrivacy::activeUntil(PRIVACY_MONDAY_EARLY, $wednesdayNight))->toBeNull()
        // 6 pm – 10 pm then 10 pm – 6 am: privacy runs on to 6 am.
        ->and(CameraPrivacy::activeUntil([
            ['days' => [3], 'from' => '18:00', 'to' => '22:00'],
            ['days' => [3], 'from' => '22:00', 'to' => '06:00'],
        ], CarbonImmutable::parse('2026-11-04 19:00', 'Pacific/Auckland'))?->toIso8601String())->toBe('2026-11-04T17:00:00+00:00')
        // A Friday-night range is still on early on Saturday.
        ->and(CameraPrivacy::activeUntil([['days' => [5], 'from' => '22:00', 'to' => '06:00']], CarbonImmutable::parse('2026-11-07 03:00', 'Pacific/Auckland')))->not->toBeNull()
        ->and(CameraPrivacy::nextStart(PRIVACY_EVERY_NIGHT, CarbonImmutable::parse('2026-11-04 12:00', 'Pacific/Auckland'))?->toIso8601String())->toBe('2026-11-04T09:00:00+00:00');
});

it('refuses every picture inside privacy hours, and never asks the hub', function (): void {
    ($this->privacy)(PRIVACY_EVERY_NIGHT);

    $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot")
        ->assertStatus(409)
        ->assertJsonPath('reason_code', 'privacy_hours')
        ->assertJsonPath('privacy_until', '2026-11-04T17:00:00+00:00')
        ->assertJsonMissingPath('data_uri');
    expect(DeviceCommandRequest::query()->count())->toBe(0);

    // The hub capability itself refuses, so no other route can ask for a picture either.
    $route = app(CommandExecutionRouteResolver::class)->resolve($this->camera->fresh(), $this->site->id, CameraSnapshotCollectorDescriptor::CAPABILITY);
    expect($route->available)->toBeFalse();

    // Live viewing refuses before it looks at the camera source.
    $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/live-sessions", [
        'expected_actor_id' => (int) $this->it->id,
        'request_key' => (string) Str::uuid(),
        'source_revision' => 1,
        'purpose' => 'Safety concern: shouting heard outside',
    ])->assertStatus(409)->assertJsonPath('message', 'privacy_hours');

    $refused = AuditLog::query()->where('action', 'camera.onvif.snapshot')->latest('id')->first();
    expect($refused)->not->toBeNull();
});

it('throws away a hub picture that arrives once privacy hours have begun', function (): void {
    ($this->privacy)(PRIVACY_MONDAY_EARLY);
    $start = $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot");
    $start->assertStatus(202)->assertJsonPath('pending', true);
    $statusUrl = $start->json('status_url');
    $job = ($this->hubJob)()['commands'][0];

    ($this->privacy)(PRIVACY_EVERY_NIGHT);
    ($this->ingestPicture)($job, "\xFF\xD8\xFF\xE0".str_repeat("\x10", 64)."\xFF\xD9");

    expect(Cache::get(CameraSnapshotCollectorDescriptor::pictureCacheKey((string) $job['command_uuid'])))->toBeNull();
    $this->actingAs($this->it)->getJson($statusUrl)
        ->assertStatus(409)
        ->assertJsonPath('reason_code', 'privacy_hours')
        ->assertJsonMissingPath('data_uri');
});

it('gives pictures as before outside privacy hours, or when they are off', function (): void {
    ($this->privacy)(PRIVACY_MONDAY_EARLY);
    $start = $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot")->assertStatus(202);
    $job = ($this->hubJob)()['commands'][0];
    $picture = "\xFF\xD8\xFF\xE0".str_repeat("\x20", 64)."\xFF\xD9";
    ($this->ingestPicture)($job, $picture);
    $this->actingAs($this->it)->getJson($start->json('status_url'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data_uri', 'data:image/jpeg;base64,'.base64_encode($picture));

    // Turned off, the times are kept and pictures work.
    ($this->privacy)(PRIVACY_EVERY_NIGHT, false);
    $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/camera/snapshot")->assertStatus(202);

    // Live viewing gets past privacy hours (and stops at the next check: no camera source here).
    $this->actingAs($this->it)->postJson("/security-devices/devices/{$this->camera->id}/live-sessions", [
        'expected_actor_id' => (int) $this->it->id,
        'request_key' => (string) Str::uuid(),
        'source_revision' => 1,
        'purpose' => 'Safety concern: shouting heard outside',
    ])->assertJsonPath('message', fn (string $message): bool => $message !== 'privacy_hours');
});

it('says “Privacy hours until …” on the camera wall and the camera page', function (): void {
    ($this->privacy)(PRIVACY_EVERY_NIGHT);

    $this->actingAs($this->it)->get('/security-devices/devices?tab=cameras')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('cameras.items', fn ($items): bool => collect($items)->contains(
                fn (array $item): bool => $item['id'] === $this->camera->id && $item['privacy_until'] === '2026-11-04T17:00:00+00:00',
            )));

    $this->actingAs($this->it)->get("/security-devices/devices/{$this->camera->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('security-devices/devices/camera')
            ->where('device.kind', 'camera')
            ->where('tab', 'live')
            ->where('privacy.active', true)
            ->where('privacy.until', '2026-11-04T17:00:00+00:00')
            ->where('can.watch_live', false)
            ->where('live.reason', 'privacy_hours')
            ->where('meters.0.value', 'Off')
            ->where('tabs', fn ($tabs): bool => collect($tabs)->pluck('key')->doesntContain('history')));

    // Outside them the tile has no privacy line.
    ($this->privacy)(PRIVACY_MONDAY_EARLY);
    $this->actingAs($this->it)->get('/security-devices/devices?tab=cameras')
        ->assertInertia(fn ($page) => $page
            ->where('cameras.items', fn ($items): bool => collect($items)->firstWhere('id', $this->camera->id)['privacy_until'] === null));
});

it('keeps another Site’s camera out of reach', function (): void {
    $viewer = ($this->staffWith)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.update', 'securityDevices.cctv.media.view']);

    $this->actingAs($viewer)->get("/security-devices/devices/{$this->theirCamera->id}")->assertNotFound();
    $this->actingAs($viewer)->get("/security-devices/devices/{$this->theirCamera->id}?tab=settings")->assertNotFound();
    $this->actingAs($viewer)->putJson("/security-devices/devices/{$this->theirCamera->id}/camera/privacy-hours", ['enabled' => true, 'ranges' => PRIVACY_EVERY_NIGHT])
        ->assertNotFound();
    expect(CameraPrivacyHours::query()->where('device_id', $this->theirCamera->id)->exists())->toBeFalse();

    // Their camera's page is the camera page for someone at that Site.
    $this->actingAs($viewer)->get("/security-devices/devices/{$this->camera->id}?tab=settings")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('security-devices/devices/camera')->where('tab', 'settings')->has('settings.who', 2));
});

it('needs securityDevices.devices.update to change privacy hours, and audits every change', function (): void {
    $looker = ($this->staffWith)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.cctv.media.view']);
    $this->actingAs($looker)->putJson("/security-devices/devices/{$this->camera->id}/camera/privacy-hours", ['enabled' => true, 'ranges' => PRIVACY_EVERY_NIGHT])
        ->assertForbidden();
    $this->actingAs($looker)->get("/security-devices/devices/{$this->camera->id}?tab=settings")
        ->assertInertia(fn ($page) => $page->where('can.privacy', false));
    expect(CameraPrivacyHours::query()->count())->toBe(0);

    $this->actingAs($this->it)->putJson("/security-devices/devices/{$this->camera->id}/camera/privacy-hours", ['enabled' => true, 'ranges' => []])
        ->assertUnprocessable();
    $this->actingAs($this->it)->putJson("/security-devices/devices/{$this->camera->id}/camera/privacy-hours", ['enabled' => true, 'ranges' => [['days' => [8], 'from' => '22:00', 'to' => '06:00']]])
        ->assertUnprocessable();

    $this->actingAs($this->it)->from("/security-devices/devices/{$this->camera->id}?tab=settings")
        ->put("/security-devices/devices/{$this->camera->id}/camera/privacy-hours", ['enabled' => true, 'ranges' => PRIVACY_EVERY_NIGHT])
        ->assertRedirect("/security-devices/devices/{$this->camera->id}?tab=settings");
    $row = CameraPrivacyHours::query()->where('device_id', $this->camera->id)->sole();
    expect($row->enabled)->toBeTrue()
        ->and($row->ranges)->toEqual(PRIVACY_EVERY_NIGHT)
        ->and($row->updated_by_user_id)->toBe((int) $this->it->id);

    $this->actingAs($this->it)->put("/security-devices/devices/{$this->camera->id}/camera/privacy-hours", ['enabled' => false, 'ranges' => PRIVACY_EVERY_NIGHT])
        ->assertRedirect();

    $audits = AuditLog::query()->where('action', 'security_devices.camera.privacy_hours.changed')->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[0]->user_id)->toBe((int) $this->it->id)
        ->and(data_get($audits[0]->meta, 'device_id'))->toBe((int) $this->camera->id)
        ->and(data_get($audits[0]->meta, 'before.enabled'))->toBeFalse()
        ->and(data_get($audits[0]->meta, 'after.enabled'))->toBeTrue()
        ->and(data_get($audits[0]->meta, 'after.ranges'))->toEqual(PRIVACY_EVERY_NIGHT)
        ->and(data_get($audits[1]->meta, 'after.enabled'))->toBeFalse()
        ->and(CameraPrivacyHours::query()->sole()->ranges)->toEqual(PRIVACY_EVERY_NIGHT);
});
