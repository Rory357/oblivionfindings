<?php

use App\Domain\Monitoring\Contracts\CollectorCertificateIssuer;
use App\Domain\Monitoring\Data\CollectorCertificateBundle;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Testing\TestResponse;

// S&D Phase B, Windows installer: removing a hub tells Oblivion (windows tab E), an admin can
// ask one hub to check for its update now (D3), and a hub PC also gets Obli (D4).

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-01T09:00:00Z');
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('endpoint_management.server_url', 'https://oblivion.test');
    config()->set('endpoint_management.organisation_label', 'Oblivion Test');
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.command_signing_secret', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('e', 32)))));
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
    $this->site = Site::factory()->create(['name' => 'Tōtara House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->admin = hubUninstallAdmin();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function hubUninstallAdmin(): User
{
    $user = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now(), 'role' => 'admin', 'external_clinical_account' => false]);
    $user->roles()->sync([Role::query()->where('name', 'admin')->value('id')]);

    return $user->fresh();
}

function hubUninstallCode(object $test): string
{
    return (string) $test->actingAs($test->admin)->postJson(route('security-devices.hubs.setup-codes.issue'), [
        'site_id' => $test->site->id,
        'platform' => 'windows',
        'name' => 'Tōtara House hub',
    ])->assertCreated()->json('code');
}

/** Redeem and enrol, as the installer does. @return array{collector: MonitoringCollector, secret: string} */
function hubUninstallInstall(object $test, string $code): array
{
    auth()->logout();
    $profile = $test->postJson('/api/monitoring/collectors/setup/redeem', ['code' => $code])->assertOk()->json('profile');
    $pair = sodium_crypto_sign_keypair();
    $test->withToken($profile['enrolment_token'])->postJson('/api/monitoring/collectors/enrol', [
        'collector_id' => $profile['collector_uuid'],
        'collector_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
    ])->assertCreated();

    return [
        'collector' => MonitoringCollector::query()->where('collector_uuid', $profile['collector_uuid'])->sole(),
        'secret' => sodium_crypto_sign_secretkey($pair),
    ];
}

function hubUninstallSignedPost(object $test, string $path, array $payload, MonitoringCollector $collector, string $secret, string $nonce): TestResponse
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

function hubUninstallHeartbeat(): array
{
    return ['status' => [
        'reported_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
        'state' => 'writable', 'spool_items' => 0, 'spool_bytes' => 0, 'oldest_spool_item_at' => null,
        'corrupted_frames' => 0, 'acknowledged_source_sequence' => 0, 'highest_seen_source_sequence' => 0,
        'runtime' => ['checks_executed' => 0, 'version' => '1.3.1', 'platform' => 'windows'],
    ]];
}

it('removes a hub that is being uninstalled, even one still waiting for approval, and stops trusting it', function () {
    $hub = hubUninstallInstall($this, hubUninstallCode($this));
    expect($hub['collector']->approved_at)->toBeNull();

    hubUninstallSignedPost($this, '/api/monitoring/collectors/deregister', [], $hub['collector'], $hub['secret'], 'uninstall-0000001')
        ->assertOk()->assertExactJson(['state' => 'removed']);

    $removed = $hub['collector']->fresh();
    expect($removed->revoked_at)->not->toBeNull()
        ->and($removed->status)->toBe('revoked')
        ->and($removed->getRawOriginal('vault_key_ciphertext'))->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.hub.uninstalled']);

    // Asked again (an uninstall retried): the hub is already gone, which the hub treats as done.
    hubUninstallSignedPost($this, '/api/monitoring/collectors/deregister', [], $hub['collector'], $hub['secret'], 'uninstall-0000002')
        ->assertStatus(410)->assertJsonPath('state', 'removed');
});

it('lets an admin ask one hub to check for its update now, until the hub reports back', function () {
    $hub = hubUninstallInstall($this, hubUninstallCode($this));
    $this->actingAs($this->admin)->postJson(route('security-devices.hubs.approve', $hub['collector']))->assertOk();

    hubUninstallSignedPost($this, '/api/monitoring/collectors/heartbeat', hubUninstallHeartbeat(), $hub['collector'], $hub['secret'], 'checknow-hb-00001')
        ->assertOk()->assertJsonPath('update.check_now', false);

    // Needs securityDevices.hubs.approve.
    $manager = hubUninstallAdmin();
    $manager->permissionOverrides()->attach(Permission::query()->where('key', 'securityDevices.hubs.approve')->value('id'), ['allowed' => false]);
    $this->actingAs($manager->fresh())->postJson(route('security-devices.hubs.check-update', $hub['collector']))->assertForbidden();
    expect($hub['collector']->fresh()->update_check_requested_at)->toBeNull();

    $this->actingAs($this->admin)->postJson(route('security-devices.hubs.check-update', $hub['collector']))
        ->assertOk()->assertJsonPath('hub.update_check_requested_at', now()->toIso8601String());

    hubUninstallSignedPost($this, '/api/monitoring/collectors/heartbeat', hubUninstallHeartbeat(), $hub['collector'], $hub['secret'], 'checknow-hb-00002')
        ->assertOk()->assertJsonPath('update.check_now', true);

    // Any report from the hub answers it.
    hubUninstallSignedPost($this, '/api/monitoring/collectors/update/report', ['report' => ['outcome' => 'installed', 'from_version' => '1.3.0', 'to_version' => '1.3.1']], $hub['collector'], $hub['secret'], 'checknow-report-01')
        ->assertOk()->assertJsonPath('update.check_now', false);
    expect($hub['collector']->fresh()->update_check_requested_at)->toBeNull();

    // A request the hub never answered stops counting after a day.
    $this->actingAs($this->admin)->postJson(route('security-devices.hubs.check-update', $hub['collector']))->assertOk();
    CarbonImmutable::setTestNow(now()->addHours(25));
    hubUninstallSignedPost($this, '/api/monitoring/collectors/heartbeat', hubUninstallHeartbeat(), $hub['collector'], $hub['secret'], 'checknow-hb-00003')
        ->assertOk()->assertJsonPath('update.check_now', false);
});

it('gives a Windows hub PC its own Obli profile too, and a second redeem of the same code still works', function () {
    $code = hubUninstallCode($this);
    auth()->logout();

    $first = $this->postJson('/api/monitoring/collectors/setup/redeem', ['code' => $code, 'computer_name' => 'totara-hub'])
        ->assertOk()->assertJsonPath('role', 'hub')->assertJsonPath('profile.site_id', $this->site->id)
        ->assertJsonPath('computer_profile.device_label', 'TOTARA-HUB')
        ->assertJsonPath('computer_profile.server_url', 'https://oblivion.test');
    $firstAgent = (string) $first->json('computer_profile.agent_uuid');
    expect($first->json('computer_profile.enrolment_token'))->toMatch('/\A[a-f0-9]{64}\z/');

    // The installer ran again before the hub enrolled: a fresh Obli profile replaces the first.
    $second = $this->postJson('/api/monitoring/collectors/setup/redeem', ['code' => $code, 'computer_name' => 'TOTARA-HUB'])
        ->assertOk()->assertJsonPath('computer_profile.device_label', 'TOTARA-HUB');
    expect($second->json('computer_profile.agent_uuid'))->not->toBe($firstAgent)
        ->and($second->json('computer_profile.enrolment_token'))->not->toBe($first->json('computer_profile.enrolment_token'))
        ->and(EndpointAgent::query()->whereNull('revoked_at')->count())->toBe(1);

    // A copied download can't give Obli to another computer: one computer per hub code.
    $this->postJson('/api/monitoring/collectors/setup/redeem', ['code' => $code, 'computer_name' => 'SOMEONE-ELSE'])
        ->assertOk()->assertJsonPath('role', 'hub')->assertJsonPath('computer_profile', null);
    expect(EndpointAgent::query()->whereNull('revoked_at')->count())->toBe(1);

    // Without a computer name the hub still redeems, without Obli.
    $this->postJson('/api/monitoring/collectors/setup/redeem', ['code' => $code])
        ->assertOk()->assertJsonPath('computer_profile', null);

    // With computer agents switched off, the hub still installs; it just gets no Obli profile.
    config()->set('endpoint_management.enabled', false);
    $this->postJson('/api/monitoring/collectors/setup/redeem', ['code' => $code, 'computer_name' => 'TOTARA-HUB'])
        ->assertOk()->assertJsonPath('role', 'hub')->assertJsonPath('computer_profile', null);
});
