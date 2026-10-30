<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvLiveSession;
use App\Domain\SecurityDevices\Cctv\Models\CctvRecordingReference;
use App\Domain\SecurityDevices\Cctv\Models\CctvRequestReceipt;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\CctvRecordingReferences;
use App\Domain\SecurityDevices\Cctv\Services\CctvSessions;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Unit\SecurityDevices\CctvContractTest;

final class CctvLiveSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Site $site;

    private Device $camera;

    private Device $recorder;

    private MonitoringCollector $collector;

    private DiscoveryScope $scope;

    private IntegrationProviderConnection $connection;

    private string $serverKey;

    private string $collectorKey;

    private array $providerReplies = [];

    private ?\Closure $afterStream = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::now('UTC')->startOfSecond());
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create(['name' => 'Approved camera Site']);
        $this->camera = $this->device('dome_camera', 'console-one', 'aa:bb:cc:dd:ee:01');
        $this->recorder = $this->device('nvr', 'console-one', 'aa:bb:cc:dd:ee:02');
        $computer = Device::factory()->itInfrastructure()->create();
        $this->assign($computer, $this->site);
        $this->serverKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->collectorKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->collector = MonitoringCollector::factory()->create(['site_id' => $this->site->id, 'collector_device_id' => $computer->id, 'public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->collectorKey)), 'client_certificate_fingerprint' => str_repeat('a', 64), 'enrolled_at' => now()]);
        $this->scope = DiscoveryScope::factory()->create(['site_id' => $this->site->id, 'collector_id' => $this->collector->id, 'cidrs' => ['10.44.0.0/24'], 'port_bounds' => ['tcp' => [7441]]]);
        $this->connection = IntegrationProviderConnection::query()->create(['provider' => 'unifi', 'status' => 'connected', 'secret_encrypted' => Crypt::encryptString('isolated-unifi-key'), 'secret_last4' => '-key']);
        IntegrationSiteConfig::query()->create(['provider' => 'unifi', 'site_id' => $this->site->id, 'is_active' => true, 'status' => 'hybrid', 'mapped_external_site_id' => 'site-cloud', 'overrides' => ['protect_host_id' => 'different-import-preference']]);
        config(['cctv.enabled' => true, 'cctv.max_session_seconds' => 300, 'cctv.relay_urls' => ['turns:relay.example.test:5349?transport=tcp'], 'cctv.relay_secret' => str_repeat('r', 32), 'cctv.relay_cidrs' => ['192.0.2.0/24'], 'cctv.relay_port_min' => 39000, 'cctv.relay_port_max' => 41000, 'monitoring.collector.signing_secret_key' => base64_encode($this->serverKey), 'monitoring.collector.trusted_proxy_cidrs' => ['127.0.0.1/32'], 'monitoring.collector.allow_proxy_fingerprint_header' => true, 'monitoring.collector.replay_store' => 'array', 'monitoring.collector.allow_local_replay_store_for_tests' => true]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_ends_with($path, '/rtsps-stream') && $this->afterStream !== null) {
                ($this->afterStream)();
            }
            if (isset($this->providerReplies[$path])) {
                return Http::response($this->providerReplies[$path], 200);
            }
            if (str_ends_with($path, '/meta/info')) {
                return Http::response(['applicationVersion' => '7.3.53'], 200);
            }
            if (str_ends_with($path, '/cameras/protect-camera-one')) {
                return Http::response(['id' => 'protect-camera-one', 'mac' => 'aa:bb:cc:dd:ee:01', 'state' => 'CONNECTED'], 200);
            }
            if (str_ends_with($path, '/nvrs')) {
                return Http::response(['id' => 'nvr-verified-one', 'mac' => 'aa:bb:cc:dd:ee:02'], 200);
            }
            if (str_ends_with($path, '/rtsps-stream')) {
                return Http::response(['low' => 'rtsps://nvr.example.test:7441/opaque-path?enableSrtp'], 200);
            }

            return Http::response([], 404);
        });
        $this->actingAs($this->actor);
        // JSON requests otherwise omit cookies in Laravel's test harness. Keep the
        // real encrypted browser session cookie; do not bypass the ownership check.
        $this->withSession([]);
        $this->withCookie(config('session.cookie'), $this->app['session']->getId())->withCredentials();
    }

    private function device(string $subcategory, string $console, string $mac): Device
    {
        $device = Device::factory()->security()->create(['subcategory' => $subcategory, 'provider' => 'unifi', 'mac_address' => $mac, 'external_ref' => ['host_id' => $console, 'provider_entity_id' => 'inventory-'.$mac]]);
        $this->assign($device, $this->site);

        return $device;
    }

    private function assign(Device $device, Site $site): void
    {
        DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
    }

    private function sourceInput(array $change = []): array
    {
        return array_replace(['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'expected_revision' => 0, 'recorder_device_id' => (int) $this->recorder->id, 'collector_id' => (int) $this->collector->id, 'discovery_scope_id' => (int) $this->scope->id, 'console_id' => 'console-one', 'provider_camera_id' => 'protect-camera-one', 'stream_address' => '10.44.0.10', 'stream_port' => 7441, 'tls_server_name' => 'nvr.example.test', 'tls_pin' => str_repeat('b', 64), 'purpose' => 'Verify this camera and its approved recorder.'], $change);
    }

    private function source(): array
    {
        return $this->postJson('/security-devices/devices/'.$this->camera->id.'/live/source', $this->sourceInput())->assertOk()->json();
    }

    private function work(?string $key = null, bool $available = true): array
    {
        return $this->collectorPost('work', ['collector_id' => $this->collector->collector_uuid, 'request_key' => $key ?? (string) Str::uuid(), 'media_capability' => ['version' => 1, 'available' => $available, 'video_codec' => 'H264', 'audio' => false]])->assertOk()->json();
    }

    private function collectorPost(string $operation, array $body): TestResponse
    {
        $path = '/api/monitoring/collectors/media/'.$operation;
        $json = CctvContract::json($body);
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $nonce = (string) Str::uuid();
        $signature = base64_encode(sodium_crypto_sign_detached("POST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $json), $this->collectorKey));

        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_OBLIVION_COLLECTOR_TIMESTAMP' => $timestamp, 'HTTP_X_OBLIVION_COLLECTOR_NONCE' => $nonce, 'HTTP_X_OBLIVION_COLLECTOR_SIGNATURE' => $signature, 'HTTP_X_OBLIVION_CLIENT_CERTIFICATE_FINGERPRINT' => str_repeat('a', 64)], $json);
    }

    private function start(array $change = []): array
    {
        $this->work();

        return $this->postJson('/security-devices/devices/'.$this->camera->id.'/live-sessions', array_replace(['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'source_revision' => 1, 'purpose' => 'View the approved reception camera now.'], $change))->assertOk()->json();
    }

    private function offer(array $started): array
    {
        $session = $started['session'];

        return $this->postJson($session['offer_href'], ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => $session['generation'], 'negotiation_uuid' => (string) Str::uuid(), 'sdp' => CctvContractTest::offer()])->assertOk()->json();
    }

    public function test_governed_setup_and_browser_collector_negotiation_preserve_original_signed_authority(): void
    {
        $setup = $this->source();
        $started = $this->start();
        $page = $this->get('/security-devices/devices/'.$this->camera->id.'/live')->assertOk();
        $validFingerprint = 'a=fingerprint:sha-256 '.implode(':', array_fill(0, 32, 'AA'));
        $badOffer = str_replace($validFingerprint, 'a=fingerprint:sha-256 AA:BB', CctvContractTest::offer());
        $this->postJson($started['session']['offer_href'], ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1, 'negotiation_uuid' => (string) Str::uuid(), 'sdp' => $badOffer])->assertStatus(400);
        $this->assertNull(CctvLiveSession::query()->first()->negotiation_uuid);
        $offered = $this->offer($started);
        $key = (string) Str::uuid();
        $work = $this->work($key);
        $replay = $this->work($key);
        $this->assertSame($work, $replay);
        $operation = $work['operations'][0];
        $bytes = base64_decode($operation['grant']['payload'], true);
        $grant = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($operation['grant']['signature'], true), $bytes, sodium_crypto_sign_publickey_from_secretkey($this->serverKey)));
        $this->assertSame($offered['session']['uuid'], $grant['session_uuid']);
        $this->assertSame((int) $this->recorder->id, $grant['recorder_device_id']);
        $this->assertSame(1, $grant['generation']);
        $secretBoxKey = sodium_crypto_sign_ed25519_sk_to_curve25519($this->collectorKey);
        $publicBoxKey = sodium_crypto_sign_ed25519_pk_to_curve25519(sodium_crypto_sign_publickey_from_secretkey($this->collectorKey));
        $sealed = json_decode(sodium_crypto_box_seal_open(base64_decode($operation['credential_lease']['sealed_material'], true), sodium_crypto_box_keypair_from_secretkey_and_publickey($secretBoxKey, $publicBoxKey)), true, 32, JSON_THROW_ON_ERROR);
        foreach (['source_json' => 'source_sha256', 'turn_json' => 'turn_sha256', 'offer_sdp' => 'offer_sha256'] as $key => $hash) {
            $this->assertSame(hash('sha256', $sealed['material'][$key]), $grant[$hash]);
        }
        $this->assertSame(CctvContractTest::offer(), $sealed['material']['offer_sdp']);
        $answer = ['type' => 'answer', 'sdp' => str_replace(['a=recvonly', 'a=setup:actpass'], ['a=sendonly', 'a=setup:passive'], CctvContractTest::offer())];
        $result = ['version' => 1, 'request_uuid' => $operation['request_uuid'], 'session_uuid' => $grant['session_uuid'], 'generation' => 1, 'status' => 'ready', 'answer' => $answer, 'reason_code' => null];
        $resultInput = ['collector_id' => $this->collector->collector_uuid, 'request_key' => (string) Str::uuid(), 'results' => [$result]];
        $badResult = $resultInput;
        $badResult['request_key'] = (string) Str::uuid();
        $badResult['results'][0]['answer']['sdp'] = str_replace($validFingerprint, 'a=fingerprint:sha-256 AA:BB', $answer['sdp']);
        $this->collectorPost('results', $badResult)->assertStatus(400);
        $this->assertSame('negotiating', CctvLiveSession::query()->first()->status);
        $ack = $this->collectorPost('results', $resultInput)->assertOk()->json();
        $this->assertSame($ack, $this->collectorPost('results', $resultInput)->assertOk()->json());
        $ready = $this->getJson($started['session']['status_href'].'?expected_actor_id='.$this->actor->id)->assertOk()->assertJsonPath('session.status', 'ready')->json();
        $this->assertSame($started['session']['authority_until'], $ready['session']['authority_until']);
        $this->assertSame($answer, $ready['session']['answer']);
        $this->assertStringNotContainsString('opaque-path', json_encode($ready));
        $this->assertStringNotContainsString('isolated-unifi-key', json_encode($ready));
        $this->assertSame('open', $this->work()['operations'][0]['operation']);
        $path = getenv('OBLI_CCTV_EXPORT');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['page' => $page->viewData('page')['props'] ?? null, 'setup' => $this->getJson('/security-devices/devices/'.$this->camera->id.'/live/source')->assertOk()->json(), 'source_write' => $setup, 'started' => $started, 'offered' => $offered, 'ready' => $ready, 'operation' => $operation, 'server_public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->serverKey)), 'collector_uuid' => $this->collector->collector_uuid, 'source_json' => $sealed['material']['source_json'], 'turn_json' => $sealed['material']['turn_json'], 'offer_sdp' => $sealed['material']['offer_sdp'], 'result_input' => $resultInput, 'result_ack' => $ack], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }

    public function test_start_offer_and_renew_replays_do_not_extend_or_replace_a_negotiation(): void
    {
        $this->source();
        $key = (string) Str::uuid();
        $started = $this->start(['request_key' => $key]);
        $again = $this->start(['request_key' => $key]);
        $this->assertSame($started['session']['uuid'], $again['session']['uuid']);
        $this->assertSame($started['session']['authority_until'], $again['session']['authority_until']);
        $offered = $this->offer($started);
        $body = ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1, 'negotiation_uuid' => $offered['session']['negotiation_uuid'], 'sdp' => CctvContractTest::offer()];
        $this->postJson($started['session']['offer_href'], $body)->assertOk();
        $this->postJson($started['session']['offer_href'], array_replace($body, ['negotiation_uuid' => (string) Str::uuid()]))->assertStatus(409);
        $renew = ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1];
        $this->postJson($started['session']['renew_href'], $renew)->assertOk()->assertJsonPath('session.generation', 2);
        $this->postJson($started['session']['renew_href'], $renew)->assertStatus(409);
        $grant = json_decode(base64_decode($this->work()['operations'][0]['grant']['payload']), true);
        $this->assertSame(2, $grant['generation']);
        $this->assertSame($started['session']['session_expires_at'], $grant['session_expires_at']);
        $this->postJson($started['session']['stop_href'], $renew)->assertOk()->assertJsonPath('session.status', 'stopped');
        $this->postJson($started['session']['stop_href'], $renew)->assertOk();
        $this->assertNull(CctvLiveSession::query()->first()->material);
        $this->assertSame([], $this->work()['operations']);
    }

    public function test_source_idempotency_and_lost_enable_reply_do_not_repeat_vendor_mutations(): void
    {
        $input = $this->sourceInput();
        $first = $this->postJson('/security-devices/devices/'.$this->camera->id.'/live/source', $input)->assertOk()->json();
        $this->assertSame($first, $this->postJson('/security-devices/devices/'.$this->camera->id.'/live/source', $input)->assertOk()->json());
        $this->postJson('/security-devices/devices/'.$this->camera->id.'/live/source', array_replace($input, ['tls_pin' => str_repeat('c', 64)]))->assertStatus(409);
        $this->assertSame(1, CctvSource::query()->count());
        $action = ['expected_actor_id' => (int) $this->actor->id, 'expected_revision' => 1, 'request_key' => (string) Str::uuid(), 'purpose' => 'Enable reviewed low-quality camera streaming.'];
        $path = '/security-devices/devices/'.$this->camera->id.'/live/source/enable-stream';
        $one = $this->postJson($path, $action)->assertOk()->json();
        $this->assertSame($one, $this->postJson($path, $action)->assertOk()->json());
        Http::assertSentCount(6); // Identity GETs, existing-stream GET, explicit POST and verified GET.
        $body = array_replace($action, ['request_key' => (string) Str::uuid()]);
        $streamPath = '/v1/connector/consoles/console-one/proxy/protect/integration/v1/cameras/protect-camera-one/rtsps-stream';
        $this->afterStream = function () use ($streamPath): void {
            $this->providerReplies[$streamPath] = ['low' => null];
        };
        $this->postJson($path, $body)->assertStatus(503);
        $receipt = CctvRequestReceipt::query()->where('request_key', $body['request_key'])->firstOrFail();
        $this->assertNull($receipt->response);
        $beforeRecovery = Http::recorded()->count();
        $posts = Http::recorded(fn ($request) => $request->method() === 'POST')->count();
        $this->afterStream = null;
        unset($this->providerReplies[$streamPath]);
        $recovered = $this->postJson($path, $body)->assertOk()->assertJsonPath('source.stream_state', 'verified')->json();
        $this->assertSame($beforeRecovery + 1, Http::recorded()->count());
        $this->assertSame($posts, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
        $this->assertSame($recovered, $this->postJson($path, $body)->assertOk()->json());
        $this->assertNotNull($receipt->fresh()->response);
    }

    public function test_current_actor_mapping_credentials_and_source_seal_are_rechecked_on_replay(): void
    {
        $this->source();
        $key = (string) Str::uuid();
        $started = $this->start(['request_key' => $key]);
        $source = CctvSource::query()->first();
        $config = $source->configuration;
        $config['tls_pin'] = str_repeat('c', 64);
        $source->configuration = $config;
        $source->save();
        $this->postJson('/security-devices/devices/'.$this->camera->id.'/live-sessions', ['expected_actor_id' => (int) $this->actor->id, 'request_key' => $key, 'source_revision' => 1, 'purpose' => 'View the approved reception camera now.'])->assertStatus(409);
        $this->getJson($started['session']['status_href'].'?expected_actor_id='.$this->actor->id)->assertStatus(409);
        $work = $this->work();
        $this->assertSame('close', $work['operations'][0]['operation']);
        $this->assertNull(CctvLiveSession::query()->first()->material);
    }

    public function test_expiry_erases_transient_material_and_never_resurrects_a_closed_session(): void
    {
        $this->source();
        $started = $this->start();
        $this->offer($started);
        $this->work();
        $this->travel(16)->seconds();
        $this->getJson($started['session']['status_href'].'?expected_actor_id='.$this->actor->id)->assertOk()->assertJsonPath('session.status', 'expired')->assertJsonPath('session.answer', null)->assertJsonCount(0, 'session.ice_servers');
        $this->postJson($started['session']['renew_href'], ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1])->assertOk()->assertJsonPath('session.status', 'expired');
        $this->assertNull(CctvLiveSession::query()->first()->material);
    }

    public function test_late_absolute_expiry_retains_bounds_and_readable_terminal_status(): void
    {
        $this->source();
        $started = $this->start();
        $this->offer($started);
        $this->travel(301)->seconds();
        $terminal = $this->getJson($started['session']['status_href'].'?expected_actor_id='.$this->actor->id)
            ->assertOk()->assertJsonPath('session.status', 'expired')->assertJsonPath('session.reason_code', 'session_expired')
            ->assertJsonPath('session.answer', null)->assertJsonPath('session.ice_expires_at', null)->assertJsonCount(0, 'session.ice_servers')->json();
        $row = CctvLiveSession::query()->firstOrFail();
        self::assertNull($row->material);
        self::assertNull($row->worker_grant);
        self::assertTrue($row->authority_until->lte($row->session_expires_at));
        self::assertTrue($row->authority_until->lte(CarbonImmutable::parse($started['session']['authority_until'])));
        self::assertSame($started['session']['session_expires_at'], $terminal['session']['session_expires_at']);
        $export = getenv('OBLI_CCTV_TERMINAL_EXPORT');
        if (is_string($export) && $export !== '') {
            file_put_contents($export.'.unifi', json_encode($terminal, JSON_THROW_ON_ERROR));
        }
    }

    public function test_forged_relay_candidates_cannot_contact_other_addresses_or_ports(): void
    {
        $this->source();
        $started = $this->start();
        $base = ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1, 'negotiation_uuid' => (string) Str::uuid()];
        foreach ([str_replace('192.0.2.10', '127.0.0.1', CctvContractTest::offer()), str_replace('40000 typ relay', '22 typ relay', CctvContractTest::offer())] as $sdp) {
            $this->postJson($started['session']['offer_href'], $base + ['sdp' => $sdp])->assertStatus(400);
        }
        $this->assertNull(CctvLiveSession::query()->first()->negotiation_uuid);
    }

    public function test_hidden_camera_moved_recorder_and_revoked_media_grant_deny_direct_objects(): void
    {
        $this->source();
        $started = $this->start();
        $permission = Permission::query()->where('key', 'securityDevices.cctv.media.view')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        $this->getJson($started['session']['status_href'].'?expected_actor_id='.$this->actor->id)->assertNotFound();
        $work = $this->work();
        $this->assertSame('close', $work['operations'][0]['operation']);
        $this->actor->permissionOverrides()->detach($permission->id);
        $this->actor->unsetRelations();
        $this->recorder->assignments()->active()->update(['released_at' => now()]);
        $this->assign($this->recorder, Site::factory()->create());
        $this->postJson('/security-devices/devices/'.$this->camera->id.'/live-sessions', ['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'source_revision' => 1, 'purpose' => 'View the approved reception camera now.'])->assertNotFound();
        $viewer = User::factory()->create();
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => Site::factory()->create()->id, 'is_active' => true]);
        foreach (['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.cctv.media.view'] as $key) {
            $permission = Permission::query()->where('key', $key)->firstOrFail();
            $viewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        $viewer->unsetRelations();
        $this->actingAs($viewer)->get('/security-devices/devices/'.$this->camera->id.'/live')->assertNotFound();
    }

    public function test_credential_rotation_and_current_mapping_drift_end_worker_authority(): void
    {
        $this->source();
        $started = $this->start();
        $this->offer($started);
        $this->work();
        $this->connection->secret_encrypted = Crypt::encryptString('changed-key');
        $this->connection->save();
        $this->getJson($started['session']['status_href'].'?expected_actor_id='.$this->actor->id)->assertStatus(409);
        $this->assertSame('close', $this->work()['operations'][0]['operation']);
        $this->assertSame('revoked', CctvLiveSession::query()->first()->status);
    }

    public function test_provider_mismatch_and_unknown_version_never_create_an_approved_source(): void
    {
        $prefix = '/v1/connector/consoles/console-one/proxy/protect/integration/v1';
        $this->providerReplies[$prefix.'/meta/info'] = ['applicationVersion' => '99.0.0'];
        $this->postJson('/security-devices/devices/'.$this->camera->id.'/live/source', $this->sourceInput())->assertStatus(503);
        $this->assertSame(0, CctvSource::query()->count());
        unset($this->providerReplies[$prefix.'/meta/info']);
        $this->providerReplies[$prefix.'/cameras/protect-camera-one'] = ['id' => 'wrong-camera', 'mac' => 'aa:bb:cc:dd:ee:01'];
        $this->postJson('/security-devices/devices/'.$this->camera->id.'/live/source', $this->sourceInput())->assertStatus(409);
        $this->assertSame(0, CctvSource::query()->count());
    }

    public function test_minute_collector_inventory_status_does_not_fake_live_worker_readiness(): void
    {
        $this->source();
        $this->collector->runtime_status = ['cctv.live' => true];
        $this->collector->save();
        $this->get('/security-devices/devices/'.$this->camera->id.'/live')->assertOk()->assertInertia(fn ($page) => $page->where('live.setup_ready', false)->where('live.streaming', false));
        $this->work(null, false);
        $this->get('/security-devices/devices/'.$this->camera->id.'/live')->assertOk()->assertInertia(fn ($page) => $page->where('live.can_start', false));
        $this->work();
        $this->get('/security-devices/devices/'.$this->camera->id.'/live')->assertOk()->assertInertia(fn ($page) => $page->where('live.setup_ready', true)->where('live.streaming', false));
    }

    public function test_source_pickers_search_full_current_scope_and_bind_seek_cursor_to_literal_query(): void
    {
        for ($i = 0; $i < 24; $i++) {
            $this->device('nvr', 'console-one', sprintf('bb:00:00:00:00:%02x', $i))->update(['name' => 'Additional recorder '.$i]);
        }
        $last = $this->device('nvr', 'console-one', 'cc:00:00:00:00:01');
        $last->update(['name' => 'Literal %_! recorder beyond first page']);
        $path = '/security-devices/devices/'.$this->camera->id.'/live/source';
        $first = $this->getJson($path.'?choice=recorders')->assertOk()->json();
        $this->assertCount(20, $first['choices']['recorders']['items']);
        $next = $first['choices']['recorders']['next_href'];
        $this->assertNotNull($next);
        $second = $this->getJson($next)->assertOk()->json();
        $this->assertNotEmpty($second['choices']['recorders']['items']);
        $back = $this->getJson($second['choices']['recorders']['previous_href'])->assertOk()->json();
        $this->assertSame($first['choices']['recorders']['items'], $back['choices']['recorders']['items']);
        $literal = $this->getJson($path.'?choice=recorders&q='.rawurlencode('%_!'))->assertOk()->json();
        $this->assertSame([$last->id], array_column($literal['choices']['recorders']['items'], 'id'));
        $this->getJson($next.'&q='.rawurlencode('%_!'))->assertStatus(400);
        $this->getJson($path.'?choice=recorders&q[]=%25')->assertStatus(400);
        $this->getJson($path.'?choice=recorders&q='.rawurlencode(str_repeat('x', 201)))->assertStatus(400);
    }

    public function test_owned_session_is_bound_to_actor_fingerprint_and_browser_session(): void
    {
        $this->source();
        $started = $this->start();
        $uuid = $started['session']['uuid'];
        $status = $started['session']['status_href'].'?expected_actor_id='.$this->actor->id;
        $this->getJson($status)->assertOk()->assertJsonPath('session.uuid', $uuid);
        $browserCookie = $this->app['session']->getId();
        $this->withCookie(config('session.cookie'), str_repeat('b', 40))->getJson($status)->assertNotFound();
        $this->withCookie(config('session.cookie'), $browserCookie);
        $this->getJson($status)->assertOk()->assertJsonPath('session.uuid', $uuid);
        $request = Request::create('/security-devices/cctv/live-sessions/'.$uuid, 'GET');
        $request->setUserResolver(fn () => $this->actor);
        $store = new Store('other', new ArraySessionHandler(120), str_repeat('b', 40));
        $request->setLaravelSession($store);
        try {
            app(CctvSessions::class)->control($request, $uuid, 'status', ['expected_actor_id' => (int) $this->actor->id]);
            $this->fail('Foreign browser session was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->actor->password = 'changed-identity-fingerprint';
        $this->actor->save();
        $this->getJson($started['session']['status_href'].'?expected_actor_id='.$this->actor->id)->assertNotFound();
        $this->assertSame('close', $this->work()['operations'][0]['operation']);
    }

    public function test_source_withdrawal_preserves_private_receipt_and_denies_new_authority(): void
    {
        $this->source();
        $started = $this->start();
        $body = ['expected_actor_id' => (int) $this->actor->id, 'expected_revision' => 1, 'request_key' => (string) Str::uuid(), 'purpose' => 'Withdraw this source from live viewing.'];
        $path = '/security-devices/devices/'.$this->camera->id.'/live/source/disable';
        $response = $this->postJson($path, $body)->assertOk()->assertJsonPath('source.enabled', false)->json();
        $this->assertSame($response, $this->postJson($path, $body)->assertOk()->json());
        $this->getJson($started['session']['status_href'].'?expected_actor_id='.$this->actor->id)->assertOk()->assertJsonPath('session.status', 'revoked')->assertJsonCount(0, 'session.ice_servers');
        $this->assertNull(CctvLiveSession::query()->first()->material);
        $this->assertSame([], $this->work()['operations']);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE' || $request->method() === 'POST');
    }

    public function test_multiple_recorders_on_one_site_have_explicit_independent_camera_bindings(): void
    {
        $this->source();
        $camera = $this->device('dome_camera', 'console-two', 'dd:00:00:00:00:01');
        $recorder = $this->device('nvr', 'console-two', 'dd:00:00:00:00:02');
        $prefix = '/v1/connector/consoles/console-two/proxy/protect/integration/v1';
        $this->providerReplies[$prefix.'/cameras/protect-camera-two'] = ['id' => 'protect-camera-two', 'mac' => $camera->mac_address];
        $this->providerReplies[$prefix.'/nvrs'] = ['id' => 'nvr-verified-two', 'mac' => $recorder->mac_address];
        $input = $this->sourceInput(['request_key' => (string) Str::uuid(), 'recorder_device_id' => (int) $recorder->id, 'console_id' => 'console-two', 'provider_camera_id' => 'protect-camera-two', 'stream_address' => '10.44.0.20']);
        $this->postJson('/security-devices/devices/'.$camera->id.'/live/source', $input)->assertOk()->assertJsonPath('source.recorder_device_id', (int) $recorder->id);
        $this->assertSame(2, CctvSource::query()->where('site_id', $this->site->id)->where('enabled', true)->count());
    }

    public function test_mapping_change_during_provider_io_is_rechecked_before_start_returns_credentials(): void
    {
        $this->source();
        $this->work();
        $this->afterStream = function (): void {
            $this->recorder->external_ref = ['host_id' => 'other-console'];
            $this->recorder->save();
        };
        $this->postJson('/security-devices/devices/'.$this->camera->id.'/live-sessions', ['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'source_revision' => 1, 'purpose' => 'View the approved reception camera now.'])->assertStatus(409);
        $this->assertSame(0, CctvLiveSession::query()->count());
    }

    public function test_duration_is_captured_from_bounded_policy_and_actor_capacity_is_not_a_page_count(): void
    {
        $this->source();
        config(['cctv.max_session_seconds' => 3601]);
        $first = $this->start();
        $second = $this->start();
        $this->assertNotSame($first['session']['uuid'], $second['session']['uuid']);
        $this->assertSame(3600, (int) CarbonImmutable::parse($first['session']['server_now'])->diffInSeconds(CarbonImmutable::parse($first['session']['session_expires_at'])));
        $this->postJson('/security-devices/devices/'.$this->camera->id.'/live-sessions', ['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'source_revision' => 1, 'purpose' => 'View the approved reception camera now.'])->assertStatus(429);
        $this->assertSame(2, CctvLiveSession::query()->count());
        $this->postJson($first['session']['stop_href'], ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1])->assertOk();
        $third = $this->start();
        $this->assertNotSame($first['session']['uuid'], $third['session']['uuid']);
    }

    public function test_stream_readiness_is_verified_separately_from_application_enablement_and_labels_are_current(): void
    {
        $streamPath = '/v1/connector/consoles/console-one/proxy/protect/integration/v1/cameras/protect-camera-one/rtsps-stream';
        $this->providerReplies[$streamPath] = ['low' => null];
        $source = $this->source();
        $this->assertTrue($source['source']['enabled']);
        $this->assertSame('unavailable', $source['source']['stream_state']);
        $this->assertNull($source['source']['stream_verified_at']);
        $this->work();
        $this->get('/security-devices/devices/'.$this->camera->id.'/live')->assertOk()->assertInertia(fn ($page) => $page->where('live.can_start', false));
        $this->recorder->update(['name' => 'Current recorder label']);
        $this->collector->update(['name' => 'Current collector label']);
        $this->scope->update(['name' => 'Current network label']);
        $this->getJson('/security-devices/devices/'.$this->camera->id.'/live/source')->assertOk()->assertJsonPath('source.recorder_name', 'Current recorder label')->assertJsonPath('source.collector_name', 'Current collector label')->assertJsonPath('source.discovery_scope_name', 'Current network label');
        unset($this->providerReplies[$streamPath]);
        $body = ['expected_actor_id' => (int) $this->actor->id, 'expected_revision' => 1, 'request_key' => (string) Str::uuid(), 'purpose' => 'Enable the separately reviewed low stream.'];
        $this->postJson('/security-devices/devices/'.$this->camera->id.'/live/source/enable-stream', $body)->assertOk()->assertJsonPath('source.stream_state', 'verified');
        $this->get('/security-devices/devices/'.$this->camera->id.'/live')->assertOk()->assertInertia(fn ($page) => $page->where('live.can_start', true)->where('live.streaming', false));
    }

    public function test_scope_picker_filters_on_current_selected_collector_before_paging_and_binds_cursor(): void
    {
        $computer = Device::factory()->itInfrastructure()->create();
        $this->assign($computer, $this->site);
        $other = MonitoringCollector::factory()->create(['site_id' => $this->site->id, 'collector_device_id' => $computer->id, 'enrolled_at' => now()]);
        $otherScope = DiscoveryScope::factory()->create(['site_id' => $this->site->id, 'collector_id' => $other->id, 'name' => 'Other collector network']);
        for ($i = 0; $i < 23; $i++) {
            DiscoveryScope::factory()->create(['site_id' => $this->site->id, 'collector_id' => $this->collector->id]);
        }
        $path = '/security-devices/devices/'.$this->camera->id.'/live/source';
        $first = $this->getJson($path.'?choice=scopes&collector_id='.$this->collector->id)->assertOk()->assertJsonPath('collector_id', (int) $this->collector->id)->json();
        $this->assertCount(20, $first['choices']['scopes']['items']);
        $this->assertNotContains($otherScope->id, array_column($first['choices']['scopes']['items'], 'id'));
        $next = $first['choices']['scopes']['next_href'];
        $this->assertStringContainsString('collector_id='.$this->collector->id, $next);
        $this->getJson($next)->assertOk();
        $this->getJson(str_replace('collector_id='.$this->collector->id, 'collector_id='.$other->id, $next))->assertStatus(400);
        $this->getJson($path.'?choice=recorders&collector_id='.$this->collector->id)->assertStatus(400);
        $this->getJson($path.'?choice=scopes&collector_id[]=1')->assertStatus(400);
        $this->getJson($path.'?choice=scopes&collector_id=01')->assertStatus(400);
        $other->revoked_at = now();
        $other->save();
        $this->getJson($path.'?choice=scopes&collector_id='.$other->id)->assertNotFound();
    }

    public function test_enable_recovery_rechecks_mapping_after_read_and_never_repeats_the_pending_mutation(): void
    {
        $this->source();
        $body = ['expected_actor_id' => (int) $this->actor->id, 'expected_revision' => 1, 'request_key' => (string) Str::uuid(), 'purpose' => 'Enable reviewed camera stream with recovery.'];
        $path = '/security-devices/devices/'.$this->camera->id.'/live/source/enable-stream';
        $streamPath = '/v1/connector/consoles/console-one/proxy/protect/integration/v1/cameras/protect-camera-one/rtsps-stream';
        $this->afterStream = function () use ($streamPath): void {
            $this->providerReplies[$streamPath] = ['low' => null];
        };
        $this->postJson($path, $body)->assertStatus(503);
        unset($this->providerReplies[$streamPath]);
        $posts = Http::recorded(fn ($request) => $request->method() === 'POST')->count();
        $this->afterStream = function (): void {
            $this->recorder->external_ref = ['host_id' => 'changed-console'];
            $this->recorder->save();
        };
        $this->postJson($path, $body)->assertStatus(409);
        $this->assertSame($posts, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
        $this->assertNull(CctvRequestReceipt::query()->where('request_key', $body['request_key'])->firstOrFail()->response);
    }

    public function test_camera_navigation_is_server_owned_and_gated_by_current_media_and_management_permissions(): void
    {
        $base = '/security-devices/devices/'.$this->camera->id.'/live';
        $this->get('/security-devices/devices/'.$this->camera->id)->assertOk()->assertInertia(fn ($page) => $page->where('profile.cctv.live_href', $base)->where('profile.cctv.setup_href', $base.'/source'));
        $this->get('/security-devices/security?tab=cctv')->assertOk()->assertInertia(function ($page) use ($base): void {
            $rows = collect($page->toArray()['props']['securityWorkspace']['activeTab']['devices'])->keyBy('id');
            $this->assertSame($base, $rows[$this->camera->id]['live_href']);
            $this->assertSame($base.'/source', $rows[$this->camera->id]['setup_href']);
            $this->assertNull($rows[$this->recorder->id]['live_href']);
            $this->assertNull($rows[$this->recorder->id]['setup_href']);
        });
        foreach (['securityDevices.cctv.media.view', 'securityDevices.integrations.manage'] as $key) {
            $permission = Permission::query()->where('key', $key)->firstOrFail();
            $this->actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        }
        $this->actor->unsetRelations();
        $this->get('/security-devices/devices/'.$this->camera->id)->assertOk()->assertInertia(fn ($page) => $page->where('profile.cctv.live_href', null)->where('profile.cctv.setup_href', null));
        $this->get('/security-devices/security?tab=cctv')->assertOk()->assertInertia(function ($page): void {
            $row = collect($page->toArray()['props']['securityWorkspace']['activeTab']['devices'])->firstWhere('id', $this->camera->id);
            $this->assertNull($row['live_href']);
            $this->assertNull($row['setup_href']);
            $this->assertArrayNotHasKey('href', $row['media']);
        });
    }

    #[DataProvider('liveDecommissionCases')]
    public function test_cctv_operational_decommission_revokes_live_authority_without_resurrection(string $target): void
    {
        $this->source();
        $started = $this->start();
        $this->offer($started);
        $this->assertSame('open', $this->work()['operations'][0]['operation']);
        $device = $target === 'camera' ? $this->camera : $this->recorder;
        $device->update(['status' => 'decommissioned']);
        $this->getJson($started['session']['status_href'].'?expected_actor_id='.$this->actor->id)->assertNotFound();
        $this->postJson('/security-devices/devices/'.$this->camera->id.'/live-sessions', ['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'source_revision' => 1, 'purpose' => 'A decommissioned source cannot receive live authority.'])->assertNotFound();
        $this->assertSame('close', $this->work()['operations'][0]['operation']);
        $session = CctvLiveSession::query()->firstOrFail();
        $this->assertSame('revoked', $session->status);
        $this->assertNull($session->material);
        $this->assertNull($session->worker_grant);
        $device->update(['status' => 'active']);
        $this->getJson($started['session']['status_href'].'?expected_actor_id='.$this->actor->id)->assertOk()->assertJsonPath('session.status', 'revoked')->assertJsonPath('session.answer', null)->assertJsonPath('session.ice_servers', []);
        $this->assertNotContains('open', array_column($this->work()['operations'], 'operation'));
    }

    public static function liveDecommissionCases(): array
    {
        return [['camera'], ['recorder']];
    }

    private function referenceInput(array $change = []): array
    {
        return array_replace(['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'expected_source_revision' => 1, 'requested_start_at' => now()->utc()->subMinute()->format('Y-m-d\TH:i:s\Z'), 'requested_end_at' => now()->utc()->addMinutes(14)->format('Y-m-d\TH:i:s\Z'), 'purpose' => 'Record the selected incident reconstruction window.'], $change);
    }

    private function referencePath(): string
    {
        return '/security-devices/devices/'.$this->camera->id.'/recording-references';
    }

    public function test_recording_reference_is_immutable_metadata_with_in_progress_window_and_actor_private_recovery(): void
    {
        $empty = $this->getJson($this->referencePath())->assertOk()->assertJsonPath('source', null)->assertJsonPath('current_source.state', 'unavailable')->assertJsonPath('permissions.can_create', false)->assertJsonPath('references.items', [])->json();
        $this->source();
        // Historical reference creation is independent of live release/worker/TURN readiness.
        config(['cctv.enabled' => false, 'cctv.relay_urls' => [], 'cctv.relay_secret' => null]);
        $this->collector->update(['revoked_at' => now(), 'status' => 'revoked']);
        $page = $this->getJson($this->referencePath())->assertOk()->json();
        $this->assertTrue($page['permissions']['can_create']);
        $this->assertFalse($page['window_limits']['operational_policy_applied']);
        $input = $this->referenceInput();
        $created = $this->postJson($this->referencePath(), $input)->assertOk()->json();
        $reference = $created['reference'];
        $this->assertSame('reference_only', $reference['state']);
        $this->assertFalse($reference['preserved']);
        $this->assertNull($reference['recording_verified_at']);
        $this->assertNull($reference['vendor_retention_until']);
        $this->assertSame('reviewed_source_revision', $reference['binding_basis']);
        $this->assertSame('nvr-verified-one', $reference['provider_recorder_id']);
        $this->assertSame('not_verified', $reference['window_coverage']);
        $this->assertSame($input['requested_end_at'], $reference['requested_end_at']);
        $this->get('/security-devices/devices/'.$this->camera->id.'/live')->assertOk()->assertInertia(fn ($p) => $p->where('reference_href', $this->referencePath()));
        $this->get('/security-devices/devices/'.$this->camera->id)->assertOk()->assertInertia(fn ($p) => $p->where('profile.cctv.reference_href', $this->referencePath()));
        $this->assertGreaterThan($created['server_now'], $reference['requested_end_at']);
        $this->assertStringNotContainsString('rtsps:', json_encode($created));
        $this->assertStringNotContainsString('isolated-unifi-key', json_encode($created));
        $this->assertStringNotContainsString('opaque-path', json_encode($created));
        $detail = $this->getJson($reference['reader_href'])->assertOk()->json();
        $recovered = $this->getJson($page['request_recovery_base_href'].'/'.$input['request_key'].'?expected_actor_id='.$this->actor->id)->assertOk()->json();
        $this->assertSame($created, $detail);
        $this->assertSame($created, $recovered);
        $this->assertSame($created, $this->postJson($this->referencePath(), array_reverse($input, true))->assertOk()->json());
        $this->postJson($this->referencePath(), array_replace($input, ['purpose' => 'A different immutable purpose for this window.']))->assertStatus(409);
        $other = User::factory()->create();
        $other->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->actingAs($other)->getJson($page['request_recovery_base_href'].'/'.$input['request_key'].'?expected_actor_id='.$other->id)->assertNotFound();
        $this->getJson($page['request_recovery_base_href'].'/'.$input['request_key'].'?expected_actor_id='.$this->actor->id)->assertNotFound();
        $this->getJson($reference['reader_href'])->assertOk()->assertJsonPath('reference.created_by.id', (int) $this->actor->id)->assertJsonPath('actor_id', (int) $other->id);
        $this->actingAs($this->actor);
        $listed = $this->getJson($this->referencePath())->assertOk()->json();
        $this->assertSame($reference['reference_uuid'], $listed['references']['items'][0]['reference_uuid']);
        $this->get($this->referencePath())->assertOk()->assertInertia(fn ($p) => $p->component('security-devices/cctv/recording-references')->where('camera.id', (int) $this->camera->id));
        $this->get($reference['reader_href'])->assertOk()->assertInertia(fn ($p) => $p->component('security-devices/cctv/recording-reference')->where('reference.reference_uuid', $reference['reference_uuid']));
        if ($export = getenv('OBLI_CCTV_REFERENCE_EXPORT')) {
            file_put_contents($export, json_encode(['empty' => $empty, 'page' => $page, 'created' => $created, 'detail' => $detail, 'recovered' => $recovered, 'listed' => $listed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }

    public function test_recording_reference_permissions_are_distinct_from_live_media_and_creation(): void
    {
        $this->source();
        $admin = Role::query()->where('name', 'admin')->firstOrFail();
        $view = Permission::where('key', 'securityDevices.cctv.recordingReferences.view')->firstOrFail();
        $create = Permission::where('key', 'securityDevices.cctv.recordingReferences.create')->firstOrFail();
        $admin->permissions()->detach($view);
        $this->assertTrue($this->actor->fresh()->canDo('securityDevices.cctv.media.view'));
        $this->getJson($this->referencePath())->assertNotFound();
        $this->postJson($this->referencePath(), $this->referenceInput())->assertNotFound();
        $this->get('/security-devices/devices/'.$this->camera->id.'/live')->assertOk()->assertInertia(fn ($p) => $p->where('reference_href', null));
        $this->get('/security-devices/devices/'.$this->camera->id)->assertOk()->assertInertia(fn ($p) => $p->where('profile.cctv.reference_href', null));
        $admin->permissions()->attach($view);
        $admin->permissions()->detach($create);
        $this->getJson($this->referencePath())->assertOk()->assertJsonPath('permissions.can_create', false)->assertJsonPath('create_href', null);
        $this->postJson($this->referencePath(), $this->referenceInput())->assertNotFound();
        $this->assertFalse(Role::query()->where('name', 'it_manager')->firstOrFail()->permissions()->whereIn('key', ['securityDevices.cctv.recordingReferences.view', 'securityDevices.cctv.recordingReferences.create'])->exists());
    }

    public function test_recording_reference_replay_survives_source_withdrawal_without_recreating_or_verifying_footage(): void
    {
        $this->source();
        $input = $this->referenceInput();
        $created = $this->postJson($this->referencePath(), $input)->assertOk()->json();
        CctvSource::query()->firstOrFail()->update(['enabled' => false]);
        $detail = $this->getJson($created['reference']['reader_href'])->assertOk()->assertJsonPath('current_source.state', 'withdrawn')->assertJsonPath('permissions.can_create', false)->json();
        $this->assertSame($created['reference'], $detail['reference']);
        $this->postJson($this->referencePath(), $input)->assertOk()->assertJsonPath('reference.reference_uuid', $created['reference']['reference_uuid'])->assertJsonPath('current_source.state', 'withdrawn');
        $this->getJson('/security-devices/cctv/recording-references/requests/'.$input['request_key'].'?expected_actor_id='.$this->actor->id)->assertOk()->assertJsonPath('current_source.state', 'withdrawn');
        $this->postJson($this->referencePath(), $this->referenceInput())->assertStatus(409);
        $this->assertSame(1, CctvRecordingReference::query()->count());
        if ($export = getenv('OBLI_CCTV_REFERENCE_EXPORT')) {
            file_put_contents($export.'.withdrawn', json_encode(['page' => $this->getJson($this->referencePath())->assertOk()->json(), 'detail' => $detail], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }

    public function test_recording_reference_capture_is_not_rewritten_by_a_new_reviewed_source_revision(): void
    {
        $this->source();
        $input = $this->referenceInput();
        $created = $this->postJson($this->referencePath(), $input)->assertOk()->json();
        $this->postJson('/security-devices/devices/'.$this->camera->id.'/live/source', $this->sourceInput(['expected_revision' => 1]))->assertOk();
        $this->getJson($created['reference']['reader_href'])->assertOk()->assertJsonPath('current_source.state', 'changed')->assertJsonPath('reference.source_revision', 1)->assertJsonPath('current_source.revision', 2);
        $this->postJson($this->referencePath(), $input)->assertOk()->assertJsonPath('reference', $created['reference']);
        $this->postJson($this->referencePath(), $this->referenceInput())->assertStatus(409);
        $this->postJson($this->referencePath(), $this->referenceInput(['expected_source_revision' => 2]))->assertOk()->assertJsonPath('reference.source_revision', 2);
    }

    public function test_recording_reference_reads_keep_metadata_when_provider_is_unavailable_and_deny_tampered_new_mapping(): void
    {
        $this->source();
        $created = $this->postJson($this->referencePath(), $this->referenceInput())->assertOk()->json();
        $this->connection->update(['status' => 'disconnected']);
        $this->getJson($created['reference']['reader_href'])->assertOk()->assertJsonPath('current_source.state', 'unavailable')->assertJsonPath('reference', $created['reference']);
        $this->postJson($this->referencePath(), $this->referenceInput())->assertStatus(503);
        $source = CctvSource::query()->firstOrFail();
        $configuration = $source->configuration;
        $configuration['console_id'] = 'unreviewed-console';
        $source->update(['configuration' => $configuration]);
        $this->getJson($created['reference']['reader_href'])->assertOk()->assertJsonPath('current_source.state', 'changed');
        $this->postJson($this->referencePath(), $this->referenceInput())->assertStatus(409);
    }

    #[DataProvider('recordingReferenceLossCases')]
    public function test_recording_reference_current_and_captured_site_authority_denies_lost_source(string $loss): void
    {
        $this->source();
        $input = $this->referenceInput();
        $created = $this->postJson($this->referencePath(), $input)->assertOk()->json();
        if ($loss === 'view_permission') {
            Role::query()->where('name', 'admin')->firstOrFail()->permissions()->detach(Permission::where('key', 'securityDevices.cctv.recordingReferences.view')->firstOrFail());
        } elseif ($loss === 'site_grant') {
            $this->actor->roles()->detach();
            $this->actor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
            $profile = HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id, 'is_active' => true]);
            foreach (['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.cctv.recordingReferences.view', 'securityDevices.cctv.recordingReferences.create'] as $key) {
                $permission = Permission::query()->where('key', $key)->firstOrFail();
                $this->actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
            }
            $this->actor->unsetRelations();
            $this->getJson($created['reference']['reader_href'])->assertOk();
            $profile->update(['primary_site_id' => Site::factory()->create()->id]);
        } elseif ($loss === 'ambiguous_recorder') {
            $other = Site::factory()->create();
            $asset = Asset::factory()->create(['site_id' => $other->id, 'home_site_id' => $other->id, 'client_id' => null, 'category' => 'IT Equipment', 'status' => 'active']);
            DeviceAssetLink::query()->create(['device_id' => $this->recorder->id, 'asset_id' => $asset->id, 'link_type' => 'installed_in', 'linked_at' => now()]);
        } elseif (in_array($loss, ['decommissioned_camera', 'decommissioned_recorder', 'soft_deleted'], true)) {
            $device = $loss === 'decommissioned_camera' ? $this->camera : $this->recorder;
            $device->update(['status' => 'decommissioned']);
            if ($loss === 'soft_deleted') {
                $device->delete();
            }
        } else {
            $device = $loss === 'camera_move' ? $this->camera : $this->recorder;
            $device->assignments()->active()->update(['released_at' => now()]);
            $this->assign($device, Site::factory()->create());
        }
        $this->getJson($created['reference']['reader_href'])->assertNotFound();
        $this->getJson('/security-devices/cctv/recording-references/requests/'.$input['request_key'].'?expected_actor_id='.$this->actor->id)->assertNotFound();
        if (in_array($loss, ['camera_move', 'recorder_move', 'decommissioned_recorder', 'soft_deleted', 'ambiguous_recorder'], true)) {
            $page = $this->getJson($this->referencePath())->assertOk()->assertJsonPath('references.items', [])->assertJsonPath('permissions.can_create', false);
            if ($loss === 'camera_move') {
                // This actor can view the camera at its new approved Site, but no original reference or source binding.
                $page->assertJsonPath('source', null)->assertJsonPath('current_source.state', 'unavailable');
                $this->assertNotSame($created['reference']['site_id'], $page->json('camera.site.id'));
            }
        } else {
            $this->getJson($this->referencePath())->assertNotFound();
        }
        $this->postJson($this->referencePath(), $input)->assertNotFound();
        $this->assertSame(1, CctvRecordingReference::query()->count());
    }

    public static function recordingReferenceLossCases(): array
    {
        return [['camera_move'], ['recorder_move'], ['decommissioned_camera'], ['decommissioned_recorder'], ['soft_deleted'], ['view_permission'], ['site_grant'], ['ambiguous_recorder']];
    }

    public function test_recording_reference_pagination_is_bounded_and_cursors_cannot_cross_camera_or_actor(): void
    {
        $this->source();
        for ($i = 0; $i < 23; $i++) {
            $request = Request::create($this->referencePath(), 'POST');
            $request->setUserResolver(fn () => $this->actor);
            app(CctvRecordingReferences::class)->create($request, (int) $this->camera->id, $this->referenceInput());
        }
        $first = $this->getJson($this->referencePath())->assertOk()->json();
        $this->assertCount(20, $first['references']['items']);
        $second = $this->getJson($first['references']['next_href'])->assertOk()->json();
        $this->assertCount(3, $second['references']['items']);
        $this->assertNull($second['references']['next_href']);
        $this->assertSame($first['references']['items'], $this->getJson($second['references']['previous_href'])->assertOk()->json('references.items'));
        $otherCamera = $this->device('dome_camera', 'console-one', 'aa:bb:cc:dd:ee:03');
        $query = parse_url($first['references']['next_href'], PHP_URL_QUERY);
        $this->getJson('/security-devices/devices/'.$otherCamera->id.'/recording-references?'.$query)->assertStatus(400);
        $other = User::factory()->create();
        $other->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->actingAs($other)->getJson($first['references']['next_href'])->assertStatus(400);
        $this->actingAs($this->actor);
        $this->getJson($this->referencePath().'?cursor[]=invalid')->assertStatus(400);
        $this->getJson($this->referencePath().'?return=https://other.example')->assertStatus(400);
        if ($export = getenv('OBLI_CCTV_REFERENCE_EXPORT')) {
            file_put_contents($export.'.pages', json_encode(['first' => $first, 'second' => $second], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }

    public function test_recording_reference_immutable_row_and_metadata_integrity_cannot_be_overwritten(): void
    {
        $this->source();
        $created = $this->postJson($this->referencePath(), $this->referenceInput())->assertOk()->json();
        $row = CctvRecordingReference::query()->firstOrFail();
        foreach (['save', 'delete'] as $operation) {
            try {
                $row->setAttribute('metadata_hash', str_repeat('0', 64));
                $row->$operation();
                $this->fail('Immutable reference was changed.');
            } catch (\DomainException $exception) {
                $this->assertSame('Recording references are immutable.', $exception->getMessage());
            }
        }
        try {
            $migration = require base_path('database/migrations/2026_10_08_230000_add_cctv_recording_references.php');
            $migration->down();
            $this->fail('Rollback erased retained references.');
        } catch (\DomainException $exception) {
            $this->assertSame('Retained recording references cannot be rolled back.', $exception->getMessage());
        }
        DB::table('cctv_recording_references')->where('id', $row->id)->update(['metadata_hash' => str_repeat('0', 64)]);
        $this->getJson($created['reference']['reader_href'])->assertNotFound();
    }

    public function test_recording_reference_http_rejects_forged_fields_future_start_and_window_overflow(): void
    {
        $this->source();
        foreach ([['preserved' => true], ['requested_start_at' => now()->utc()->addSecond()->format('Y-m-d\TH:i:s\Z')], ['requested_end_at' => now()->utc()->addHour()->format('Y-m-d\TH:i:s\Z')], ['requested_start_at' => '2026-02-30T00:00:00Z'], ['expected_source_revision' => '1'], ['requested_end_at' => now()->utc()->format('Y-m-d\TH:i:sP')], ['requested_end_at' => ['raw' => 'time']]] as $change) {
            $this->postJson($this->referencePath(), $this->referenceInput($change))->assertStatus(400);
        }
        $this->assertSame(0, CctvRecordingReference::query()->count());
    }

    public function test_collector_transport_rejects_unsigned_work_and_mismatched_or_extra_keys(): void
    {
        $this->postJson('/api/monitoring/collectors/media/work', ['collector_id' => $this->collector->collector_uuid, 'request_key' => (string) Str::uuid()])->assertUnauthorized();
        $body = ['collector_id' => $this->collector->collector_uuid, 'request_key' => (string) Str::uuid(), 'media_capability' => ['version' => 1, 'available' => true, 'video_codec' => 'H264', 'audio' => false], 'url' => 'rtsps://other'];
        $this->collectorPost('work', $body)->assertStatus(400);
        $body['collector_id'] = (string) Str::uuid();
        $this->collectorPost('work', $body)->assertUnauthorized();
    }
}
