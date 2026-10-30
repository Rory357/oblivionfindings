<?php

namespace Tests\Feature\Settings;

use App\Domain\Monitoring\Models\MonitoringRuntimeHeartbeat;
use App\Jobs\System\RecordDefaultWorkerHeartbeatJob;
use App\Models\Role;
use App\Models\User;
use App\Services\System\ServerReadinessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ServerReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        config(['inertia.ssr.enabled' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_server_readiness_requires_settings_access_permission(): void
    {
        $this->get('/settings/server-readiness')->assertRedirect('/login');

        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());

        $this->actingAs($worker)
            ->get('/settings/server-readiness')
            ->assertForbidden();
    }

    public function test_unconfigured_server_reports_failures_with_plain_fixes_and_never_fakes_green(): void
    {
        Redis::shouldReceive('connection->command')
            ->with('ping')
            ->andThrow(new \RuntimeException('Redis connection refused'));

        Cache::forget(ServerReadinessService::SCHEDULER_CACHE_KEY);
        Cache::forget(RecordDefaultWorkerHeartbeatJob::CACHE_KEY);

        config([
            'queue.default' => 'sync',
            'monitoring.signing.active_key_id' => null,
            'monitoring.signing.keys' => [],
            'monitoring.collector.signing_secret_key' => null,
            'monitoring.collector.ca_certificate_path' => null,
            'monitoring.collector.ca_private_key_path' => null,
            'endpoint_management.release_public_key' => null,
            'monitoring.credentials.driver' => 'unavailable',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/settings/server-readiness')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/server-readiness')
                ->where('readiness.overall_status', 'fail')
                ->where('readiness.total_count', 7)
            );

        /** @var array<string, mixed> $readiness */
        $readiness = $response->viewData('page')['props']['readiness'];
        $checksByKey = collect($readiness['checks'])->keyBy('key');

        foreach (['redis', 'scheduler', 'worker_pools', 'collector_keys', 'release_key', 'credential_store'] as $failingKey) {
            $check = $checksByKey->get($failingKey);
            $this->assertNotNull($check, "Expected check {$failingKey} to exist");
            $this->assertSame('fail', $check['status'], "Expected check {$failingKey} to fail when unconfigured");
            $this->assertIsString($check['fix']);
            $this->assertNotSame('', trim($check['fix']));
        }

        $this->assertCount(9, $checksByKey->get('worker_pools')['subitems']);
    }

    public function test_configured_server_passes_all_checks_and_never_exposes_key_material(): void
    {
        Redis::shouldReceive('connection->command')
            ->with('ping')
            ->andReturn('PONG');

        $runtimeSigningKey = base64_encode(random_bytes(SODIUM_CRYPTO_AUTH_KEYBYTES));
        $collectorKeypair = sodium_crypto_sign_keypair();
        $collectorSecretKey = base64_encode(sodium_crypto_sign_secretkey($collectorKeypair));
        $releaseKeypair = sodium_crypto_sign_keypair();
        $releasePublicKey = base64_encode(sodium_crypto_sign_publickey($releaseKeypair));

        $caCertFile = tempnam(sys_get_temp_dir(), 'oblivion-ca-cert-');
        $caKeyFile = tempnam(sys_get_temp_dir(), 'oblivion-ca-key-');
        $this->assertIsString($caCertFile);
        $this->assertIsString($caKeyFile);
        $this->tempFiles[] = $caCertFile;
        $this->tempFiles[] = $caKeyFile;
        file_put_contents($caCertFile, 'TEST-CA-CERT-PLACEHOLDER');
        file_put_contents($caKeyFile, 'TEST-CA-PRIVATE-KEY-DO-NOT-LEAK');

        config([
            'queue.default' => 'database',
            'monitoring.signing.active_key_id' => 'k1',
            'monitoring.signing.keys' => ['k1' => $runtimeSigningKey],
            'monitoring.collector.signing_secret_key' => $collectorSecretKey,
            'monitoring.collector.ca_certificate_path' => $caCertFile,
            'monitoring.collector.ca_private_key_path' => $caKeyFile,
            'endpoint_management.release_public_key' => $releasePublicKey,
            'monitoring.credentials.driver' => 'database',
        ]);

        Artisan::call('system:scheduler-heartbeat');
        (new RecordDefaultWorkerHeartbeatJob)->handle();

        foreach (['events', 'checks', 'discovery', 'provider', 'topology', 'maintenance', 'orchestration', 'commands'] as $component) {
            MonitoringRuntimeHeartbeat::query()->updateOrCreate(
                ['component' => $component],
                [
                    'queue' => 'monitoring-'.$component,
                    'last_dispatched_token' => 'tok-'.$component,
                    'last_dispatched_at' => now(),
                    'last_consumed_token' => 'tok-'.$component,
                    'last_consumed_at' => now(),
                    'last_consumed_dispatch_at' => now(),
                ],
            );
        }

        $response = $this->actingAs($this->admin)
            ->get('/settings/server-readiness')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/server-readiness')
                ->where('readiness.overall_status', 'pass')
                ->where('readiness.passed_count', 7)
                ->where('readiness.failed_count', 0)
            );

        $serialized = json_encode($response->viewData('page'));
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString($runtimeSigningKey, $serialized);
        $this->assertStringNotContainsString($collectorSecretKey, $serialized);
        $this->assertStringNotContainsString('TEST-CA-PRIVATE-KEY-DO-NOT-LEAK', $serialized);
    }
}
