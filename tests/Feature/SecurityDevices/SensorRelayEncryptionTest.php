<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Services\CollectorIngestService;
use App\Domain\SecurityDevices\Iot\Relay\Models\HubRelayCertificate;
use App\Domain\SecurityDevices\Iot\Relay\Models\SensorCertificateAuthority;
use App\Domain\SecurityDevices\Iot\Relay\Services\SensorGatewayLoginService;
use App\Domain\SecurityDevices\Iot\Relay\Services\SensorsCertificateAuthority;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * S&D Phase E (E5): encrypted sensor relay. A hub makes its own key and sends only a certificate
 * request; Oblivion's sensors authority signs it for the hub's own house-network addresses; the hub
 * then takes gateway readings over TLS on 8883 by default, with no switch to turn on.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x71", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($configPair)));

    $this->site = Site::factory()->create(['name' => 'Kauri House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create(['site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.70.0.0/24'], 'status' => 'active']);
    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x72", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Kauri House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'tls-relay-hub-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
        'runtime_status' => ['version' => '2026.11.1', 'command_adapters' => 'camera_snapshot_v1,sensor_relay_tls_v1'],
    ]);

    $this->opensslConfig = function (): string {
        $path = tempnam(sys_get_temp_dir(), 'sd-tls-test-');
        file_put_contents($path, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\nCN = x\n");

        return $path;
    };
    /** A certificate request the way a hub makes one. */
    $this->request = function (?string $commonName = null, array $key = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']): string {
        $config = ($this->opensslConfig)();
        $private = openssl_pkey_new([...$key, 'config' => $config]);
        $csr = openssl_csr_new(['commonName' => $commonName ?? SensorsCertificateAuthority::commonName($this->hub)], $private, ['digest_alg' => 'sha256', 'config' => $config]);
        openssl_csr_export($csr, $pem);
        @unlink($config);

        return $pem;
    };
    $this->send = function (string $csr, array $addresses): void {
        app(CollectorIngestService::class)->ingest($this->hub->fresh(), [[
            'id' => 'relay-certificate-request:'.Str::random(8),
            'source_sequence' => 1,
            'created_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
            'payload' => ['item_type' => 'relay_certificate_request', 'csr_pem' => $csr, 'ip_addresses' => $addresses, 'requested_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM)],
        ]]);
    };
    $this->config = fn (): array => json_decode(base64_decode(json_decode(app(CollectorConfigurationService::class)->signedEnvelope($this->hub->fresh(), 0), true)['payload'], true), true);
});

it('signs the hub’s own key for its house-network addresses only, and keeps the authority key encrypted', function (): void {
    $csr = ($this->request)();
    ($this->send)($csr, ['10.70.0.5', '172.17.0.1', '8.8.8.8']);

    $certificate = HubRelayCertificate::query()->sole();
    $authority = SensorCertificateAuthority::query()->sole();
    $parsed = openssl_x509_parse($certificate->certificate_pem);
    expect($certificate->ip_addresses)->toBe(['10.70.0.5'])
        ->and($parsed['extensions']['subjectAltName'])->toBe('IP Address:10.70.0.5')
        ->and($parsed['extensions']['extendedKeyUsage'])->toBe('TLS Web Server Authentication')
        ->and(openssl_x509_verify($certificate->certificate_pem, openssl_pkey_get_public($authority->certificate_pem)))->toBe(1)
        ->and((string) DB::table('security_device_certificate_authorities')->value('private_key_encrypted'))->not->toContain('PRIVATE KEY');
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.sensor_relay.certificate_issued']);

    // The same key and addresses again: the same certificate, not a new one each day.
    ($this->send)($csr, ['10.70.0.5']);
    expect(HubRelayCertificate::query()->count())->toBe(1);
});

it('signs nothing for another hub’s name, a weak key or addresses outside the Site', function (): void {
    ($this->send)(($this->request)('oblivion-relay-someone-else'), ['10.70.0.5']);
    ($this->send)(($this->request)(null, ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]), ['10.70.0.5']);
    ($this->send)(($this->request)(), ['192.168.50.4']);
    ($this->send)('-----BEGIN CERTIFICATE REQUEST-----'."\nnot a request\n", ['10.70.0.5']);

    expect(HubRelayCertificate::query()->count())->toBe(0);
});

it('sends the encrypted relay with the hub’s certificate by default, and only to hubs that run it', function (): void {
    ['login' => $login, 'password' => $password] = app(SensorGatewayLoginService::class)->create($this->site, 'Lounge UG65');
    ($this->send)(($this->request)(), ['10.70.0.5']);

    $configuration = ($this->config)();
    expect($configuration)->not->toHaveKey('sensor_relay') // unencrypted stays off
        ->and($configuration['sensor_relay_tls']['listen_port'])->toBe(8883)
        ->and($configuration['sensor_relay_tls']['logins'][0]['username'])->toBe($login->username)
        ->and($configuration['sensor_relay_tls']['certificate'])->toBe(HubRelayCertificate::query()->sole()->certificate_pem)
        ->and($configuration['sensor_relay_tls']['ca_certificate'])->toBe(SensorCertificateAuthority::query()->sole()->certificate_pem)
        ->and($configuration['scope']['protocols'])->toContain('sensor_relay_tls')
        ->and(json_encode($configuration))->not->toContain($password)
        ->and(json_encode($configuration))->not->toContain('PRIVATE KEY');

    // A hub whose software doesn't list the encrypted relay never gets the section.
    $this->hub->forceFill(['runtime_status' => ['version' => '2026.10.1', 'command_adapters' => 'camera_snapshot_v1']])->save();
    expect(fn () => ($this->config)())->toThrow(DomainException::class); // a relay-only hub then has no work
});

it('gives a new gateway port 8883 with the sensors certificate once the hub is ready', function (): void {
    $keeper = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create(['user_id' => $keeper->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
    foreach (['securityDevices.viewAny', 'securityDevices.integrations.view', 'securityDevices.integrations.manage', 'securityDevices.savedLogins.manage'] as $key) {
        $keeper->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
    }
    $make = fn () => $this->actingAs($keeper->fresh())->postJson('/security-devices/setup/broker/gateways', [
        'site_id' => $this->site->id, 'model' => 'Milesight UG65', 'name' => 'Kauri House gateway',
    ]);

    // No certificate yet and unencrypted off: refused in words.
    $make()->assertUnprocessable()->assertJsonPath('errors.site_id.0', 'The Site’s hub hasn’t got its certificate for encrypted readings yet. It asks within a day of its update; try again then.');

    ($this->send)(($this->request)(), ['10.70.0.5']);
    $made = $make()->assertCreated()->assertJsonPath('port', 8883);
    expect($made->json('tls.download_url'))->toBe('/security-devices/setup/broker/sensors-certificate')
        ->and($made->json('tls.fingerprint'))->toBeString();

    $download = $this->actingAs($keeper->fresh())->get('/security-devices/setup/broker/sensors-certificate')->assertOk();
    expect($download->getContent())->toBe(SensorCertificateAuthority::query()->sole()->certificate_pem)
        ->and($download->getContent())->not->toContain('PRIVATE KEY');

    $this->actingAs($keeper->fresh())->get('/security-devices/setup/hubs?tab=broker')->assertOk()->assertInertia(fn ($page) => $page
        ->where('broker.rows.0.connection.text', 'Encrypted, port 8883')
        ->where('broker.certificate.download_url', '/security-devices/setup/broker/sensors-certificate'));
});
