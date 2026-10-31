<?php

use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerRestoreProbe;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerSecretStore;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretReferenceRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretVersionRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretWriteRequest;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Credentials\Services\EncryptedDatabaseSecretLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Services\EncryptedDatabaseSecretStore;
use App\Domain\SecurityDevices\Credentials\Services\HashicorpVaultSecretStore;
use App\Domain\SecurityDevices\Credentials\Services\UnavailableSecretManagerSecretStore;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Logging\ConfigureSensitiveDataRedaction;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Integration\IntegrationSecretManager;
use App\Services\Integration\IntegrationSecretMaterialService;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
});

it('uses the encrypted database credential store by default while keeping Vault optional', function (): void {
    expect(config('monitoring.credentials.driver'))->toBe('database')
        ->and((string) file_get_contents(base_path('.env.example')))
        ->toContain('MONITORING_CREDENTIAL_DRIVER=database');

    app()->forgetInstance(SecretManagerSecretStore::class);
    app()->forgetInstance(SecretManagerLeaseIssuer::class);
    app()->forgetInstance(SecretManagerRestoreProbe::class);

    expect(app(SecretManagerSecretStore::class))->toBeInstanceOf(EncryptedDatabaseSecretStore::class)
        ->and(app(SecretManagerLeaseIssuer::class))->toBeInstanceOf(EncryptedDatabaseSecretLeaseIssuer::class)
        ->and(app(SecretManagerRestoreProbe::class)->healthy())->toBeTrue();

    config()->set('monitoring.credentials.driver', 'vault');
    app()->forgetInstance(SecretManagerSecretStore::class);
    expect(app(SecretManagerSecretStore::class))->toBeInstanceOf(HashicorpVaultSecretStore::class);

    config()->set('monitoring.credentials.driver', 'unavailable');
    app()->forgetInstance(SecretManagerSecretStore::class);
    expect(app(SecretManagerSecretStore::class))->toBeInstanceOf(UnavailableSecretManagerSecretStore::class);
});

it('boots credential store bindings lazily when app.key is absent during asset builds', function (): void {
    $previousKey = config('app.key');

    try {
        config()->set('app.key', '');
        app()->forgetInstance('encrypter');
        app()->forgetInstance(EncryptedDatabaseSecretStore::class);
        app()->forgetInstance(EncryptedDatabaseSecretLeaseIssuer::class);
        app()->forgetInstance(SecretManagerSecretStore::class);
        app()->forgetInstance(SecretManagerLeaseIssuer::class);
        app()->forgetInstance(SecretManagerRestoreProbe::class);

        $store = app(SecretManagerSecretStore::class);
        $issuer = app(SecretManagerLeaseIssuer::class);
        $probe = app(SecretManagerRestoreProbe::class);

        expect($store)->toBeInstanceOf(EncryptedDatabaseSecretStore::class)
            ->and($issuer)->toBeInstanceOf(EncryptedDatabaseSecretLeaseIssuer::class)
            ->and($probe->healthy())->toBeFalse();
    } finally {
        config()->set('app.key', $previousKey);
        app()->forgetInstance('encrypter');
    }
});

it('enforces compare-and-set versioning, soft deletion, restoration, and permanent destruction', function (): void {
    $store = app(EncryptedDatabaseSecretStore::class);
    $issuer = app(EncryptedDatabaseSecretLeaseIssuer::class);
    $ref = 'oblivion/sites/1/hikvision/camera-01';

    $v1 = $store->put(new SecretWriteRequest($ref, ['password' => 'first-secret-value'], 0));
    expect($v1->version)->toBe(1)
        ->and($store->metadata(new SecretReferenceRequest($ref))->version)->toBe(1);

    expect(fn () => $store->put(new SecretWriteRequest($ref, ['password' => 'stale-write'], 0)))
        ->toThrow(RuntimeException::class, 'Static secret compare and set failed.');

    $v2 = $store->put(new SecretWriteRequest($ref, ['password' => 'rotated-secret-value'], 1));
    expect($v2->version)->toBe(2);

    $leaseRequest = new SecretLeaseRequest(
        referenceUuid: '019f7b90-a3cc-7c6b-8428-766011e76999',
        siteId: 1,
        provider: 'hikvision',
        purpose: 'device_management',
        capabilities: ['command:device.reboot'],
        externalReference: $ref,
        expiresAt: CarbonImmutable::now('UTC')->addMinute(),
    );

    expect($issuer->issue($leaseRequest)->material()['password'])->toBe('rotated-secret-value');

    $store->softDelete(new SecretVersionRequest($ref, 2));
    // Version 1 is still active when version 2 is soft-deleted unless version 2 is requested explicitly.
    expect(fn () => $issuer->issue(new SecretLeaseRequest(
        referenceUuid: '019f7b90-a3cc-7c6b-8428-766011e76999',
        siteId: 1,
        provider: 'hikvision',
        purpose: 'device_management',
        capabilities: ['command:device.reboot'],
        externalReference: $ref,
        expiresAt: CarbonImmutable::now('UTC')->addMinute(),
        secretVersion: 2,
    )))->toThrow(RuntimeException::class, 'Credential lease is unavailable.');

    $store->restore(new SecretVersionRequest($ref, 2));
    expect($issuer->issue($leaseRequest)->material()['password'])->toBe('rotated-secret-value');

    $store->destroy(new SecretVersionRequest($ref, 2));
    expect(DB::table('monitoring_encrypted_secrets')->where('version', 2)->value('encrypted_payload'))->toBeNull();
});

it('stores provider integration API keys encrypted in the database without Vault', function (): void {
    $sentinel = 'UNIFI-DB-API-KEY-SENTINEL-77412';
    $connection = IntegrationProviderConnection::query()->create([
        'provider' => 'unifi',
        'name' => 'UniFi Cloud Connection',
        'connection_mode' => 'cloud_api',
        'status' => 'configured',
    ]);

    $manager = app(IntegrationSecretManager::class);
    $manager->storeApplication($connection, IntegrationSecretManager::PURPOSE_PRIMARY, [
        'api_key' => $sentinel,
    ]);

    $rawRow = (array) DB::table('monitoring_encrypted_secrets')->first();
    $materialService = app(IntegrationSecretMaterialService::class);
    expect(json_encode($rawRow, JSON_THROW_ON_ERROR))->not->toContain($sentinel)
        ->and($materialService->application($connection, IntegrationSecretManager::PURPOSE_PRIMARY, 'api_key'))->toBe($sentinel);
});

it('never leaks encrypted-database secrets to the browser, session flash, logs, or audit rows', function (): void {
    $site = Site::factory()->create(['name' => 'Rimu House']);
    $admin = User::factory()->create(['approved_at' => now()]);
    $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

    $device = Device::factory()->itInfrastructure()->create([
        'name' => 'Rimu Gateway',
        'provider' => 'mikrotik',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);

    $passwordSentinel = 'SENTINEL-DB-DEVICE-PASSWORD-99421';
    $rotatedSentinel = 'SENTINEL-DB-ROTATED-TOKEN-88103';
    $logPath = tempnam(sys_get_temp_dir(), 'oblivion-db-cred-log-');

    try {
        config()->set('logging.channels.db_cred_audit_test', [
            'driver' => 'single',
            'path' => $logPath,
            'level' => 'debug',
            'tap' => [ConfigureSensitiveDataRedaction::class],
        ]);
        Log::forgetChannel('db_cred_audit_test');

        // 1. Validation error must never flash credential_material back into session.
        $this->actingAs($admin)
            ->post('/security-devices/settings/credential-references', [
                'site_id' => $site->id,
                'reference_key' => 'invalid key with spaces',
                'provider' => 'mikrotik',
                'purpose' => 'device_management',
                'capabilities' => ['command:network.port.bounce'],
                'credential_material' => [
                    'username' => 'admin',
                    'password' => $passwordSentinel,
                ],
            ])
            ->assertSessionHasErrors('reference_key')
            ->assertSessionMissing('_old_input.credential_material');

        // 2. Register with credential_material stored encrypted in Oblivion DB.
        $this->actingAs($admin)
            ->post('/security-devices/settings/credential-references', [
                'site_id' => $site->id,
                'reference_key' => 'database:mikrotik/'.$site->id.'/rimu-gateway',
                'provider' => 'mikrotik',
                'purpose' => 'device_management',
                'capabilities' => ['command:network.port.bounce'],
                'credential_material' => [
                    'username' => 'admin',
                    'password' => $passwordSentinel,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $reference = CredentialReference::query()->sole();

        // 3. Test reference and acquire a runtime command lease.
        $this->actingAs($admin)
            ->post("/security-devices/settings/credential-references/{$reference->reference_uuid}/test")
            ->assertRedirect()
            ->assertSessionHas('success');

        expect($reference->fresh()->status)->toBe(CredentialReferenceStatus::Active);

        $context = new CommandExecutionContext(
            commandUuid: '019f7b90-a3cc-7c6b-8428-766011e76111',
            attemptUuid: '019f7b90-a3cc-7c6b-8428-766011e76112',
            attemptNumber: 1,
            device: $device,
            siteId: $site->id,
            capability: 'network.port.bounce',
            parameters: ['interface' => 'ether2'],
            expectedState: ['interface' => 'ether2', 'admin_state' => 'enabled'],
            idempotencyKey: 'db-cred-command-lease-1',
            expiresAt: CarbonImmutable::now('UTC')->addMinute(),
        );
        $lease = app(CommandCredentialLeaseService::class)->acquire($context);
        expect($lease->material()['password'])->toBe($passwordSentinel);
        app(CommandCredentialLeaseService::class)->release($lease);

        // 4. Rotate with new credential_material and test again.
        $this->actingAs($admin)
            ->post("/security-devices/settings/credential-references/{$reference->reference_uuid}/rotate", [
                'credential_material' => [
                    'username' => 'admin',
                    'api_token' => $rotatedSentinel,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post("/security-devices/settings/credential-references/{$reference->reference_uuid}/test")
            ->assertRedirect()
            ->assertSessionHas('success');

        // 5. Log through redacted channel to verify log tap protection.
        Log::channel('db_cred_audit_test')->warning('Credential diagnostic check', [
            'password' => $passwordSentinel,
            'api_token' => $rotatedSentinel,
            'site_id' => $site->id,
        ]);

        // 6. Verify browser/Inertia responses never contain either sentinel.
        foreach ([
            '/security-devices/settings',
            '/security-devices/devices',
            "/security-devices/devices/{$device->id}",
        ] as $url) {
            $body = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            expect($body)->not->toContain($passwordSentinel)
                ->not->toContain($rotatedSentinel);
        }

        // 7. Verify raw DB secret table is encrypted and audit tables + logs contain zero plaintext.
        $encryptedDump = json_encode(DB::table('monitoring_encrypted_secrets')->get()->all(), JSON_THROW_ON_ERROR);
        $auditLogsDump = json_encode(DB::table('audit_logs')->get()->all(), JSON_THROW_ON_ERROR);
        $refAuditsDump = json_encode(DB::table('security_device_credential_reference_audits')->get()->all(), JSON_THROW_ON_ERROR);
        $leaseAuditsDump = json_encode(DB::table('security_device_credential_lease_audits')->get()->all(), JSON_THROW_ON_ERROR);
        $logContents = (string) file_get_contents($logPath);

        foreach ([$encryptedDump, $auditLogsDump, $refAuditsDump, $leaseAuditsDump, $logContents] as $surface) {
            expect($surface)->not->toContain($passwordSentinel)
                ->not->toContain($rotatedSentinel);
        }
    } finally {
        Log::forgetChannel('db_cred_audit_test');
        config()->set('logging.channels.db_cred_audit_test', null);
        if (is_string($logPath) && is_file($logPath)) {
            unlink($logPath);
        }
    }
});
