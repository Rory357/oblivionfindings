<?php

use App\Domain\Monitoring\Contracts\CollectorCertificateIssuer;
use App\Domain\Monitoring\Data\CollectorCertificateBundle;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Models\SetupCode;
use App\Domain\SecurityDevices\Hubs\Services\HubUpdatePolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Testing\TestResponse;

// S&D Phase B (plan v10, "Hub security"; D2, D3): setup codes in the installer's file name,
// approval before any work or saved login, the login-store key, removal wipes, update hold.

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-01T09:00:00Z');
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('endpoint_management.server_url', 'https://oblivion.test');
    config()->set('endpoint_management.organisation_label', 'Oblivion Test');
    config()->set('monitoring.collector.installation.tls_public_key_pin', 'sha256//'.base64_encode(str_repeat("\x11", 32)));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat("\x72", 32)))));
    config()->set('monitoring.collector.trusted_proxy_cidrs', ['10.20.30.0/24']);
    config()->set('monitoring.collector.replay_store', 'array');
    config()->set('monitoring.collector.allow_local_replay_store_for_tests', true);
    config()->set('monitoring.collector.allow_proxy_fingerprint_header', true);
    app()->instance(CollectorCertificateIssuer::class, new class implements CollectorCertificateIssuer
    {
        public function issue(string $collectorUuid): CollectorCertificateBundle
        {
            return new CollectorCertificateBundle(
                certificatePem: "-----BEGIN CERTIFICATE-----\nfixture-{$collectorUuid}\n-----END CERTIFICATE-----\n",
                privateKeyPem: "-----BEGIN PRIVATE KEY-----\nfixture\n-----END PRIVATE KEY-----\n",
                fingerprint: str_repeat('a', 64),
                expiresAt: CarbonImmutable::now('UTC')->addYear(),
            );
        }
    });
    $this->site = Site::factory()->create(['name' => 'Kauri House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->admin = sdHubAdmin();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function sdHubAdmin(): User
{
    $user = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now(), 'role' => 'admin', 'external_clinical_account' => false]);
    $user->roles()->sync([Role::query()->where('name', 'admin')->value('id')]);

    return $user->fresh();
}

/** @return array{response: TestResponse, code: string, id: int} */
function sdHubIssueCode(object $test, ?Site $site = null): array
{
    $response = $test->actingAs($test->admin)->postJson(route('security-devices.hubs.setup-codes.issue'), [
        'site_id' => ($site ?? $test->site)->id,
        'platform' => 'windows',
        'name' => 'Kauri House hub',
    ])->assertCreated();

    return ['response' => $response, 'code' => (string) $response->json('code'), 'id' => (int) $response->json('setup_code_id')];
}

/** Redeem, then enrol with the profile, as the installer does. @return array{collector: MonitoringCollector, secret: string} */
function sdHubInstall(object $test, string $code): array
{
    $profile = $test->postJson('/api/monitoring/collectors/setup/redeem', ['code' => $code])->assertOk()->json('profile');
    $pair = sodium_crypto_sign_keypair();
    $test->withToken($profile['enrolment_token'])->postJson('/api/monitoring/collectors/enrol', [
        'collector_id' => $profile['collector_uuid'],
        'collector_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
    ])->assertCreated();

    return [
        'collector' => MonitoringCollector::query()->where('collector_uuid', $profile['collector_uuid'])->sole(),
        'secret' => sodium_crypto_sign_secretkey($pair),
        'public' => sodium_crypto_sign_publickey($pair),
    ];
}

function sdHubSignedPost(object $test, string $path, array $payload, MonitoringCollector $collector, string $secret, string $nonce): TestResponse
{
    $body = json_encode(['collector_id' => $collector->collector_uuid, ...$payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $timestamp = CarbonImmutable::now('UTC')->format(DATE_ATOM);
    $signature = sodium_crypto_sign_detached("POST\n{$path}\n{$timestamp}\n{$nonce}\n".hash('sha256', $body), $secret);

    return $test->call('POST', $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OBLIVION_COLLECTOR_TIMESTAMP' => $timestamp,
        'HTTP_X_OBLIVION_COLLECTOR_NONCE' => $nonce,
        'HTTP_X_OBLIVION_COLLECTOR_SIGNATURE' => base64_encode($signature),
        'HTTP_X_OBLIVION_CLIENT_CERTIFICATE_FINGERPRINT' => str_repeat('a', 64),
        'REMOTE_ADDR' => '10.20.30.40',
    ], $body);
}

function sdHubHeartbeat(): array
{
    return ['status' => [
        'reported_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
        'state' => 'writable', 'spool_items' => 0, 'spool_bytes' => 0, 'oldest_spool_item_at' => null,
        'corrupted_frames' => 0, 'acknowledged_source_sequence' => 0, 'highest_seen_source_sequence' => 0,
        'runtime' => ['checks_executed' => 0, 'version' => '1.3.1', 'platform' => 'windows'],
    ]];
}

it('makes a 24-hour setup code that rides in the installer file name and works on one computer only', function () {
    $issued = sdHubIssueCode($this);

    expect($issued['code'])->toMatch('/\A[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}\z/')
        ->and($issued['response']->json('file_name'))->toBe("Oblivion-Setup (Kauri House) [oblivion.test {$issued['code']}].exe")
        ->and(CarbonImmutable::parse($issued['response']->json('expires_at'))->diffInMinutes(now()->addHours(24), true))->toBeLessThan(1.0)
        ->and(SetupCode::query()->sole()->getRawOriginal('code_hash'))->not->toContain(str_replace('-', '', $issued['code']));

    // Typed by hand from a renamed file: lower case and no dash still work.
    $this->postJson('/api/monitoring/collectors/setup/redeem', ['code' => strtolower(str_replace('-', '', $issued['code']))])
        ->assertOk()->assertJsonPath('profile.site_id', $this->site->id);

    sdHubInstall($this, $issued['code']);

    $this->postJson('/api/monitoring/collectors/setup/redeem', ['code' => $issued['code']])
        ->assertStatus(410)->assertJsonPath('state', 'used')
        ->assertJsonPath('message', 'This installer was already used. Download a new one from Oblivion.');
    $code = SetupCode::query()->sole();
    expect($code->consumed_at)->not->toBeNull()
        ->and($code->getRawOriginal('profile_ciphertext'))->toBeNull()
        ->and($code->getRawOriginal('code_ciphertext'))->toBeNull();
});

it('refuses a cancelled or expired code and keeps none of its secrets', function () {
    $cancelled = sdHubIssueCode($this);
    $this->actingAs($this->admin)->deleteJson(route('security-devices.hubs.setup-codes.cancel', $cancelled['id']))
        ->assertOk()->assertJsonPath('code_state', 'cancelled');
    $this->postJson('/api/monitoring/collectors/setup/redeem', ['code' => $cancelled['code']])
        ->assertStatus(410)->assertJsonPath('state', 'cancelled');

    $expired = sdHubIssueCode($this);
    CarbonImmutable::setTestNow(now()->addHours(24)->addMinute());
    $this->postJson('/api/monitoring/collectors/setup/redeem', ['code' => $expired['code']])
        ->assertStatus(410)->assertJsonPath('state', 'expired');

    expect(SetupCode::query()->whereNotNull('profile_ciphertext')->count())->toBe(0);
    $this->postJson('/api/monitoring/collectors/setup/redeem', ['code' => 'ZZZZ-ZZZZ'])->assertStatus(422)->assertJsonPath('state', 'unknown');
});

it('keeps a new hub waiting: it may say it is alive, but gets no work, uploads or logins until approved', function () {
    $hub = sdHubInstall($this, sdHubIssueCode($this)['code']);

    sdHubSignedPost($this, '/api/monitoring/collectors/heartbeat', sdHubHeartbeat(), $hub['collector'], $hub['secret'], 'wait-heartbeat-0001')
        ->assertOk()->assertJsonPath('approval', 'waiting')->assertJsonPath('update.target', null);
    sdHubSignedPost($this, '/api/monitoring/collectors/configuration', ['after_sequence' => 0], $hub['collector'], $hub['secret'], 'wait-config-00001')
        ->assertStatus(409)->assertJsonPath('state', 'waiting_for_approval');
    sdHubSignedPost($this, '/api/monitoring/collectors/observations', ['items' => []], $hub['collector'], $hub['secret'], 'wait-observe-0001')
        ->assertStatus(409)->assertJsonPath('state', 'waiting_for_approval');
    sdHubSignedPost($this, '/api/monitoring/collectors/vault-key', [], $hub['collector'], $hub['secret'], 'wait-vaultkey-001')
        ->assertStatus(409);

    $this->actingAs($this->admin)->postJson(route('security-devices.hubs.approve', $hub['collector']))
        ->assertOk()->assertJsonPath('hub.state', fn ($state) => $state !== 'waiting_for_approval');

    sdHubSignedPost($this, '/api/monitoring/collectors/heartbeat', sdHubHeartbeat(), $hub['collector'], $hub['secret'], 'ok-heartbeat-00001')
        ->assertOk()->assertJsonPath('approval', 'approved');
    $sealed = sdHubSignedPost($this, '/api/monitoring/collectors/vault-key', [], $hub['collector'], $hub['secret'], 'ok-vaultkey-00001')
        ->assertOk()->json('sealed_key');
    $box = sodium_crypto_box_keypair_from_secretkey_and_publickey(
        sodium_crypto_sign_ed25519_sk_to_curve25519($hub['secret']),
        sodium_crypto_sign_ed25519_pk_to_curve25519($hub['public']),
    );
    expect(strlen((string) sodium_crypto_box_seal_open(base64_decode($sealed), $box)))->toBe(32);
});

it('wipes a removed hub: its login-store key is destroyed and it is told to wipe', function () {
    $hub = sdHubInstall($this, sdHubIssueCode($this)['code']);
    $this->actingAs($this->admin)->postJson(route('security-devices.hubs.approve', $hub['collector']))->assertOk();
    expect($hub['collector']->fresh()->getRawOriginal('vault_key_ciphertext'))->not->toBeNull();

    $this->actingAs($this->admin)->postJson(route('security-devices.hubs.remove', $hub['collector']))
        ->assertOk()->assertJsonPath('hub.state', 'removed');

    expect($hub['collector']->fresh()->getRawOriginal('vault_key_ciphertext'))->toBeNull();
    sdHubSignedPost($this, '/api/monitoring/collectors/heartbeat', sdHubHeartbeat(), $hub['collector'], $hub['secret'], 'gone-heartbeat-001')
        ->assertStatus(410)->assertJsonPath('state', 'removed');
    sdHubSignedPost($this, '/api/monitoring/collectors/vault-key', [], $hub['collector'], $hub['secret'], 'gone-vaultkey-0001')
        ->assertStatus(410);
});

it('lets only people with hubs.approve approve or remove a hub', function () {
    $hub = sdHubInstall($this, sdHubIssueCode($this)['code']);
    $manager = sdHubAdmin();
    $manager->permissionOverrides()->attach(Permission::query()->where('key', 'securityDevices.hubs.approve')->value('id'), ['allowed' => false]);

    $this->actingAs($manager->fresh())->postJson(route('security-devices.hubs.approve', $hub['collector']))->assertForbidden();
    $this->actingAs($manager->fresh())->postJson(route('security-devices.hubs.remove', $hub['collector']))->assertForbidden();
    expect($hub['collector']->fresh()->approved_at)->toBeNull();
});

it('holds updates for every hub and tells each hub its ring and night window', function () {
    $hub = MonitoringCollector::factory()->create(['site_id' => $this->site->id, 'update_ring' => 'early', 'update_window_start' => '01:30', 'update_window_end' => '04:00']);

    $this->actingAs($this->admin)->putJson(route('security-devices.hubs.updates.hold'), ['held' => true])
        ->assertOk()->assertJsonPath('held', true);

    $instruction = app(HubUpdatePolicy::class)->instructionFor($hub);
    expect($instruction['held'])->toBeTrue()
        ->and($instruction['target'])->toBeNull()
        ->and($instruction['ring'])->toBe('early')
        ->and($instruction['window'])->toMatchArray(['start' => '01:30', 'end' => '04:00'])
        ->and($instruction['rollback_after_minutes'])->toBe(5);
});
