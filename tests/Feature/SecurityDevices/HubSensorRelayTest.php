<?php

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Services\CollectorIngestService;
use App\Domain\SecurityDevices\Iot\Relay\Models\SensorGatewayLogin;
use App\Domain\SecurityDevices\Iot\Relay\Services\SensorGatewayLoginService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * S&D Phase B, sensor relay (D21): a house's hub relays its gateways' MQTT readings to Oblivion
 * through its encrypted, outbound-only upload, and readings held while its link was down arrive with
 * their real times. The Site always comes from the gateway's login, never from the message.
 */

beforeEach(function (): void {
    $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x61", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($configPair)));

    $this->site = Site::factory()->create(['name' => 'Rata House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x62", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Rata House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'relay-hub-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
    ]);
    ['login' => $this->login, 'password' => $this->password] = app(SensorGatewayLoginService::class)->create($this->site, 'Office UG65');
    // Phase E (D22): the hub relay is unencrypted MQTT, so it runs only once Setup allows that.
    \App\Domain\SecurityDevices\Setup\Models\SecurityDeviceSetting::query()->create(['key' => 'mqtt.allow_unencrypted', 'value' => true]);

    $this->sensor = Device::factory()->create([
        'name' => 'Rata House medication fridge', 'domain' => 'iot_healthcare', 'category' => 'environmental',
        'manufacturer' => 'Milesight', 'model' => 'EM300-TH', 'last_seen_at' => now()->subDay(),
        'config' => ['mqtt' => ['topic' => $this->login->topic_prefix.'fridge']],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->sensor->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subWeek(),
    ]);

    $this->relayed = function (array $overrides = [], ?CarbonImmutable $receivedAt = null): array {
        $receivedAt ??= CarbonImmutable::now('UTC')->subMinutes(8)->startOfSecond();
        $payload = array_replace([
            'item_type' => 'sensor_reading',
            'username' => $this->login->username,
            'topic' => $this->login->topic_prefix.'fridge',
            'payload_base64' => base64_encode(json_encode(['temperature' => 4.6, 'battery' => 81], JSON_THROW_ON_ERROR)),
            'received_at' => $receivedAt->format(DATE_ATOM),
        ], $overrides);

        return app(CollectorIngestService::class)->ingest($this->hub->fresh(), [[
            'id' => 'sensor-reading:'.Str::random(12),
            'source_sequence' => 1,
            'created_at' => $receivedAt->format(DATE_ATOM),
            'payload' => $payload,
        ]]);
    };
});

it('sends the hub only a hash of each gateway login, and a relay-only hub still gets its work list', function (): void {
    $envelope = app(CollectorConfigurationService::class)->signedEnvelope($this->hub->fresh(), 0);
    $configuration = json_decode(base64_decode(json_decode($envelope, true)['payload'], true), true);

    expect($configuration['sensor_relay']['listen_port'])->toBe(1883)
        ->and($configuration['sensor_relay']['logins'])->toBe([[
            'username' => $this->login->username,
            'password_hash' => SensorGatewayLogin::query()->sole()->password_hash,
            'site_id' => $this->site->id,
            'topic_prefix' => 'oblivion/'.$this->site->id.'/'.$this->login->username.'/',
        ]])
        ->and(password_verify($this->password, $configuration['sensor_relay']['logins'][0]['password_hash']))->toBeTrue()
        ->and($configuration['scope']['protocols'])->toContain('sensor_relay')
        ->and($configuration['checks'])->toBe([])
        ->and($envelope)->not->toContain($this->password);
});

it('sends no relay while unencrypted MQTT is off, or for a Site whose range another Site uses (D22)', function (): void {
    $config = fn (): array => json_decode(base64_decode(json_decode(app(CollectorConfigurationService::class)->signedEnvelope($this->hub->fresh(), 0), true)['payload'], true), true);

    \App\Domain\SecurityDevices\Setup\Models\SecurityDeviceSetting::query()->where('key', 'mqtt.allow_unencrypted')->update(['value' => json_encode(false)]);
    expect(fn () => $config())->toThrow(DomainException::class); // a relay-only hub then has no work at all

    \App\Domain\SecurityDevices\Setup\Models\SecurityDeviceSetting::query()->where('key', 'mqtt.allow_unencrypted')->update(['value' => json_encode(true)]);
    $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    \App\Domain\Monitoring\Discovery\Models\DiscoveryScope::factory()->create(['site_id' => $this->site->id, 'cidrs' => ['172.16.0.0/24']]);
    \App\Domain\Monitoring\Discovery\Models\DiscoveryScope::factory()->create(['site_id' => $other->id, 'cidrs' => ['172.16.0.0/24']]);
    expect(fn () => $config())->toThrow(DomainException::class);
});

it('puts a relayed reading into the sensor intake with the time the hub received it', function (): void {
    $receivedAt = CarbonImmutable::now('UTC')->subMinutes(8)->startOfSecond();

    $acknowledgement = ($this->relayed)([], $receivedAt);

    $sensor = $this->sensor->fresh();
    expect($acknowledgement['acknowledged_ids'])->toHaveCount(1)
        ->and($sensor->battery_level)->toBe(81)
        ->and(data_get($sensor->meta, 'observed.sensor_readings.temperature.value'))->toBe(4.6)
        ->and(CarbonImmutable::parse(data_get($sensor->meta, 'observed.sensor_readings.temperature.observed_at'))->equalTo($receivedAt))->toBeTrue()
        ->and($this->login->fresh()->last_reading_at->equalTo($receivedAt))->toBeTrue();
});

it('refuses another gateway’s topic, a revoked login, and a Site the hub does not cover', function (): void {
    ($this->relayed)(['topic' => 'oblivion/'.$this->site->id.'/gw-otherdevice1/fridge']);
    expect($this->sensor->fresh()->battery_level)->not->toBe(81);

    $elsewhere = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    ['login' => $foreign] = app(SensorGatewayLoginService::class)->create($elsewhere, 'Another house');
    ($this->relayed)(['username' => $foreign->username, 'topic' => $foreign->topic_prefix.'fridge']);
    expect($this->sensor->fresh()->battery_level)->not->toBe(81);

    app(SensorGatewayLoginService::class)->revoke($this->login);
    ($this->relayed)([], CarbonImmutable::now('UTC')->startOfSecond());
    expect($this->sensor->fresh()->battery_level)->not->toBe(81);

    // A reading the hub received before the login was revoked still counts.
    ($this->relayed)([], CarbonImmutable::now('UTC')->subHour()->startOfSecond());
    expect($this->sensor->fresh()->battery_level)->toBe(81);
});

it('stops sending a revoked login to the hub', function (): void {
    app(SensorGatewayLoginService::class)->revoke($this->login);

    expect(fn () => app(CollectorConfigurationService::class)->signedEnvelope($this->hub->fresh(), 0))
        ->toThrow(DomainException::class, 'Collector has no executable work');
});

it('records the daily public-port check and alerts when Oblivion answers MQTT from the internet (D21)', function (): void {
    $hubDevice = Device::factory()->create(['name' => 'Rata House hub PC']);
    $this->hub->forceFill(['collector_device_id' => $hubDevice->id])->save();
    $send = fn (array $ports): array => app(CollectorIngestService::class)->ingest($this->hub->fresh(), [[
        'id' => 'broker-exposure:'.Str::random(8),
        'source_sequence' => 1,
        'created_at' => now()->toIso8601String(),
        'payload' => ['item_type' => 'broker_exposure_check', 'host' => 'oblivion.example', 'ports' => $ports, 'checked_at' => now()->toIso8601String()],
    ]]);

    $send(['1883' => 'answered', '8883' => 'refused']);
    expect(\App\Domain\SecurityDevices\Iot\Relay\Models\BrokerExposureCheck::query()->sole()->passed)->toBeFalse();
    $alert = \App\Domain\SecurityDevices\Alerts\Models\DeviceAlert::query()->sole();
    expect($alert->kind)->toBe('mqtt_exposed')->and($alert->severity)->toBe('critical')
        ->and($alert->title)->toBe('Oblivion’s public address answers on port 1883');

    $send(['1883' => 'refused', '8883' => 'refused']);
    expect($alert->fresh()->state)->toBe('done');
});

it('turns 5 wrong logins at the relay into an alert on the hub for IT', function (): void {
    $hubDevice = Device::factory()->create(['name' => 'Rata House hub PC']);
    $this->hub->forceFill(['collector_device_id' => $hubDevice->id])->save();

    app(CollectorIngestService::class)->ingest($this->hub->fresh(), [[
        'id' => 'sensor-reading:'.Str::random(8),
        'source_sequence' => 1,
        'created_at' => now()->toIso8601String(),
        'payload' => ['item_type' => 'relay_login_failures', 'address' => '10.20.0.36', 'count' => 5, 'received_at' => now()->toIso8601String()],
    ]]);

    $alert = \App\Domain\SecurityDevices\Alerts\Models\DeviceAlert::query()->sole();
    expect($alert->kind)->toBe('mqtt_logins')
        ->and($alert->title)->toBe('Rata House hub: 5 wrong sensor logins from 10.20.0.36');
});
