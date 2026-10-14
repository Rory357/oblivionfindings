<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvLiveSession;
use App\Domain\SecurityDevices\Cctv\Models\CctvRequestReceipt;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Models\HikvisionDiscoveryRequest;
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionDiscoveryAuthority;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionLiveContract;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionSourcePolicy;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CredentialLeaseLifecycleService;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceRules;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Oblivion\Collector\Media\HikvisionLiveExchange;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\SecurityDevices\CctvContractTest;

final class HikvisionLiveSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Site $site;

    private Device $camera;

    private MonitoringCollector $collector;

    private DiscoveryScope $scope;

    private CredentialReference $reference;

    private string $serverKey;

    private string $collectorKey;

    private int $issued = 0;

    private array $revoked = [];

    private ?\Closure $duringIssue = null;

    private bool $revokeUnavailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::now('UTC')->startOfSecond());
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create();
        $this->camera = Device::factory()->security()->create(['category' => 'cctv', 'subcategory' => 'dome_camera', 'provider' => 'hikvision', 'ip_address' => '10.44.0.10', 'serial_number' => 'observed-camera-serial', 'model' => 'synthetic-model']);
        $computer = Device::factory()->itInfrastructure()->create();
        foreach ([$this->camera, $computer] as $device) {
            DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
        }
        $this->serverKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->collectorKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->collector = MonitoringCollector::factory()->create(['site_id' => $this->site->id, 'collector_device_id' => $computer->id, 'public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->collectorKey)), 'client_certificate_fingerprint' => str_repeat('a', 64), 'enrolled_at' => now()]);
        $this->scope = DiscoveryScope::factory()->create(['site_id' => $this->site->id, 'collector_id' => $this->collector->id, 'cidrs' => ['10.44.0.0/24'], 'port_bounds' => ['tcp' => [443]], 'protocols' => ['tls']]);
        $this->reference = CredentialReference::query()->create(['reference_key' => 'vault:hikvision/discovery', 'site_id' => $this->site->id, 'provider' => 'hikvision', 'purpose' => 'cctv_read_only', 'capabilities' => [HikvisionDiscoveryAuthority::CAPABILITY, HikvisionLiveContract::CAPABILITY], 'secret_manager_reference' => 'private-camera-location', 'secret_manager_reference_hash' => str_repeat('b', 64), 'status' => 'active', 'rotation_status' => 'current', 'test_status' => 'passed', 'version' => 1]);
        config(['cctv.hikvision_discovery_enabled' => true, 'monitoring.collector.signing_secret_key' => base64_encode($this->serverKey), 'monitoring.collector.trusted_proxy_cidrs' => ['127.0.0.1/32'], 'monitoring.collector.allow_proxy_fingerprint_header' => true, 'monitoring.collector.replay_store' => 'array', 'monitoring.collector.allow_local_replay_store_for_tests' => true]);
        // Keep the real governed broker and lifecycle; substitute only the external
        // secret manager, so catalogue checks, lease records and audits run normally.
        $issue = function (SecretLeaseRequest $request): CredentialLease {
            $this->issued++;
            self::assertSame(0, DB::transactionLevel() - 1, 'External retrieval must run outside domain transactions (the test owns one rollback transaction).');
            self::assertSame((int) $this->site->id, $request->siteId);
            self::assertContains($request->capabilities, [[HikvisionDiscoveryAuthority::CAPABILITY], [HikvisionLiveContract::CAPABILITY]]);
            if ($this->duringIssue) {
                ($this->duringIssue)();
            }

            return new CredentialLease('synthetic-hk-lease-'.$this->issued, CarbonImmutable::now('UTC')->addSeconds(60), ['username' => 'synthetic-read-only-user', 'password' => 'synthetic-private-password']);
        };
        $revoke = function (string $id): void {
            $this->revoked[] = $id;
            if ($this->revokeUnavailable) {
                throw new \RuntimeException('Synthetic provider revoke unavailable.');
            }
        };
        $this->app->instance(SecretManagerLeaseIssuer::class, new class($issue, $revoke) implements SecretManagerLeaseIssuer
        {
            public function __construct(private \Closure $issueCallback, private \Closure $revokeCallback) {}

            public function issue(SecretLeaseRequest $request): CredentialLease
            {
                return ($this->issueCallback)($request);
            }

            public function revoke(#[\SensitiveParameter] string $leaseId): void
            {
                ($this->revokeCallback)($leaseId);
            }
        });
        config(['cctv.hikvision_mapping_enabled' => true, 'cctv.hikvision_supported_profiles' => [$this->support()]]);
        Http::preventStrayRequests();
        $this->actingAs($this->actor);
        config(['cctv.enabled' => true, 'cctv.hikvision_live_enabled' => true, 'cctv.relay_urls' => ['turn:relay.example.test:3478?transport=udp'], 'cctv.relay_secret' => str_repeat('s', 40), 'cctv.relay_cidrs' => ['192.0.2.0/24'], 'cctv.relay_port_min' => 32768, 'cctv.relay_port_max' => 65535]);
        $this->withSession([]);
        $this->withCookie(config('session.cookie'), $this->app['session']->getId())->withCredentials();
    }

    private function input(array $changes = []): array
    {
        return array_replace(['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'expected_source_revision' => 0, 'collector_id' => (int) $this->collector->id, 'discovery_scope_id' => (int) $this->scope->id, 'credential_reference_id' => (int) $this->reference->id, 'allowed_ip' => '10.44.0.10', 'allowed_port' => 443, 'tls_server_name' => 'camera.example.test', 'tls_pin' => str_repeat('c', 64), 'purpose' => 'Check the reported camera identity and channels.'], $changes);
    }

    private function create(?array $input = null): TestResponse
    {
        return $this->postJson('/security-devices/devices/'.$this->camera->id.'/hikvision-discoveries', $input ?? $this->input());
    }

    private function body(?string $key = null, bool $available = true): array
    {
        return ['collector_id' => $this->collector->collector_uuid, 'request_key' => $key ?? (string) Str::uuid(), 'capability' => ['version' => 1, 'available' => $available, 'profile' => 'hikvision_isapi_https_v1']];
    }

    private function postCollector(string $operation, array $body): TestResponse
    {
        $path = '/api/monitoring/collectors/media/discovery/'.$operation;
        $json = CctvContract::json($body);
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $nonce = (string) Str::uuid();
        $signature = base64_encode(sodium_crypto_sign_detached("POST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $json), $this->collectorKey));

        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_OBLIVION_COLLECTOR_TIMESTAMP' => $timestamp, 'HTTP_X_OBLIVION_COLLECTOR_NONCE' => $nonce, 'HTTP_X_OBLIVION_COLLECTOR_SIGNATURE' => $signature, 'HTTP_X_OBLIVION_CLIENT_CERTIFICATE_FINGERPRINT' => str_repeat('a', 64)], $json);
    }

    private function dispatched(): array
    {
        $this->create()->assertOk();

        return $this->postCollector('work', $this->body())->assertOk()->json('operations.0');
    }

    private function observation(array $operation): array
    {
        $grant = json_decode(base64_decode($operation['grant']['payload'], true), true, 32, JSON_THROW_ON_ERROR);

        return ['version' => 1, 'operation' => 'discover_hikvision', 'request_uuid' => $operation['request_uuid'], 'device_id' => (int) $this->camera->id, 'context_sha256' => $grant['context_sha256'], 'credential_binding_sha256' => $grant['credential_binding_sha256'], 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'status' => 'observed', 'inventory' => ['identity' => ['model' => 'synthetic-model', 'serial' => 'observed-camera-serial', 'device_id' => 'synthetic-vendor-id', 'firmware' => 'synthetic-version', 'firmware_build' => ''], 'channels' => [['id' => '101', 'input_id' => '1', 'name' => 'Reception', 'codec' => 'H.264', 'enabled' => true, 'video_enabled' => true, 'width' => 1920, 'height' => 1080, 'fps100' => 2500, 'audio_enabled' => null]]], 'reason_code' => null];
    }

    private function resultBody(array $result, ?string $key = null): array
    {
        return ['collector_id' => $this->collector->collector_uuid, 'request_key' => $key ?? (string) Str::uuid(), 'results' => [$result]];
    }

    private function support(): array
    {
        return ['model' => 'synthetic-model', 'firmware' => 'synthetic-version', 'firmware_build' => '', 'transport' => HikvisionSourcePolicy::PROFILE, 'input_ids' => ['1'], 'witness_id' => 'synthetic-only-not-hardware-acceptance'];
    }

    private function commit(?\Closure $change = null, int $revision = 0): HikvisionDiscoveryRequest
    {
        $this->create($this->input(['expected_source_revision' => $revision]))->assertOk();
        $op = $this->postCollector('work', $this->body())->assertOk()->json('operations.0');
        $result = $this->observation($op);
        if ($change) {
            $result = $change($result);
        }
        $this->postCollector('results', $this->resultBody($result))->assertOk();

        return HikvisionDiscoveryRequest::query()->where('uuid', $op['request_uuid'])->firstOrFail();
    }

    private function mapping(HikvisionDiscoveryRequest $request, array $change = []): array
    {
        return array_replace(['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'expected_revision' => (int) $request->source_revision, 'discovery_uuid' => $request->uuid, 'expected_result_sha256' => $request->result_hash, 'channel_id' => '101', 'transport_profile' => HikvisionSourcePolicy::PROFILE, 'purpose' => 'Review this exact direct camera channel.'], $change);
    }

    private function href(): string
    {
        return '/security-devices/devices/'.$this->camera->id.'/live/hikvision/source';
    }

    private function saveMapping(array $body): TestResponse
    {
        return $this->postJson($this->href(), $body);
    }

    private function recovery(array $body): TestResponse
    {
        return $this->getJson($this->href().'/requests/'.$body['request_key'].'?expected_actor_id='.$body['expected_actor_id']);
    }

    private function liveCollector(string $operation, array $body): TestResponse
    {
        $path = '/api/monitoring/collectors/media/'.$operation;
        $json = CctvContract::json($body);
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $nonce = (string) Str::uuid();
        $signature = base64_encode(sodium_crypto_sign_detached("POST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $json), $this->collectorKey));

        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_OBLIVION_COLLECTOR_TIMESTAMP' => $timestamp, 'HTTP_X_OBLIVION_COLLECTOR_NONCE' => $nonce, 'HTTP_X_OBLIVION_COLLECTOR_SIGNATURE' => $signature, 'HTTP_X_OBLIVION_CLIENT_CERTIFICATE_FINGERPRINT' => str_repeat('a', 64)], $json);
    }

    private function liveWork(?string $key = null, ?string $profile = HikvisionSourcePolicy::PROFILE): TestResponse
    {
        return $this->liveCollector('work', ['collector_id' => $this->collector->collector_uuid, 'request_key' => $key ?? (string) Str::uuid(), 'media_capability' => ['version' => 1, 'available' => true, 'video_codec' => 'H264', 'audio' => false, 'hikvision_profile' => $profile]]);
    }

    private function actionInput(): array
    {
        return ['expected_actor_id' => (int) $this->actor->id, 'expected_revision' => 1, 'request_key' => (string) Str::uuid(), 'purpose' => 'Allow the approved camera to be viewed.'];
    }

    private function mapped(bool $activate = true): CctvSource
    {
        $this->saveMapping($this->mapping($this->commit()))->assertOk();
        $this->liveWork()->assertOk()->assertJsonPath('operations', []);
        if ($activate) {
            $this->postJson($this->href().'/enable', $this->actionInput())->assertOk()->assertJsonPath('source.stream_state', 'not_requested')->assertJsonPath('source.stream_verified_at', null);
        }

        return CctvSource::query()->firstOrFail();
    }

    private function liveInput(): array
    {
        return ['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'source_revision' => 1, 'purpose' => 'View the approved camera for this investigation.'];
    }

    private function startLive(?array $input = null): TestResponse
    {
        return $this->postJson('/security-devices/devices/'.$this->camera->id.'/live-sessions', $input ?? $this->liveInput());
    }

    private function offerLive(array $started): array
    {
        return $this->postJson($started['session']['offer_href'], ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1, 'negotiation_uuid' => (string) Str::uuid(), 'sdp' => CctvContractTest::offer()])->assertOk()->json();
    }

    private function reply(array $operation): array
    {
        $grant = json_decode(base64_decode($operation['grant']['payload'], true), true, 32, JSON_THROW_ON_ERROR);

        return ['version' => 1, 'request_uuid' => $operation['request_uuid'], 'session_uuid' => $grant['session_uuid'], 'generation' => $grant['generation'], 'status' => 'ready', 'answer' => ['type' => 'answer', 'sdp' => str_replace('a=recvonly', 'a=sendonly', CctvContractTest::offer())], 'reason_code' => null];
    }

    public function test_manager_setup_projects_fresh_readiness_and_permits_withdrawal_when_binding_is_lost(): void
    {
        $source = $this->mapped(false);
        $base = '/security-devices/devices/'.$this->camera->id.'/live/hikvision/setup';
        $dto = $this->getJson($base)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('provider', 'hikvision')->assertJsonPath('readiness.can_enable', true)->assertJsonPath('readiness.can_view', false)->json();
        foreach (['private-camera-location', 'synthetic-private-password', 'tls_pin', 'secret_manager_reference'] as $private) {
            self::assertStringNotContainsString($private, json_encode($dto));
        }
        $this->postJson($this->href().'/enable', $this->actionInput())->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('readiness.can_disable', true)->assertJsonPath('readiness.can_view', true)->assertJsonPath('source.stream_state', 'not_requested');
        $this->reference->update(['version' => 2]);
        config(['cctv.enabled' => false, 'cctv.hikvision_live_enabled' => false]);
        $this->getJson($base)->assertOk()->assertJsonPath('source.id', (int) $source->id)->assertJsonPath('readiness.can_disable', true)->assertJsonPath('readiness.can_view', false)->assertJsonPath('readiness.can_enable', false)->assertJsonPath('readiness.reason_code', 'connection_unavailable');
        $this->postJson($this->href().'/disable', $this->actionInput())->assertOk()->assertJsonPath('source.enabled', false);
        $this->getJson('/security-devices/devices/'.$this->camera->id.'/live/source')->assertOk()->assertJsonPath('provider', 'hikvision')->assertJsonPath('readiness.can_disable', false);
    }

    public function test_setup_retains_old_enabled_connection_until_explicit_withdrawal_then_allows_new_activation(): void
    {
        $original = $this->mapped();
        $request = $this->commit(null, 1);
        $this->saveMapping($this->mapping($request))->assertOk()->assertJsonPath('source.revision', 2)->assertJsonPath('source.enabled', false);
        $base = '/security-devices/devices/'.$this->camera->id.'/live/hikvision/setup';
        $this->getJson($base)->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('source.revision', 2)->assertJsonPath('active_source.id', (int) $original->id)->assertJsonPath('readiness.can_enable', false)->assertJsonPath('readiness.can_disable', true)->assertJsonPath('readiness.reason_code', 'active_connection_exists');
        $this->postJson($this->href().'/disable', $this->actionInput())->assertOk();
        $this->liveWork()->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('active_source', null)->assertJsonPath('readiness.can_enable', true);
        $next = $this->actionInput();
        $next['expected_revision'] = 2;
        $this->postJson($this->href().'/enable', $next)->assertOk()->assertJsonPath('source.enabled', true)->assertJsonPath('source.stream_state', 'not_requested');
        $this->getJson($base)->assertOk()->assertJsonPath('active_source.revision', 2)->assertJsonPath('readiness.can_view', true);
    }

    public function test_setup_directory_is_bounded_searchable_and_cursor_cannot_cross_revision_or_query(): void
    {
        for ($i = 0; $i < 23; $i++) {
            CredentialReference::query()->create(['reference_key' => 'approved-camera-'.sprintf('%02d', $i), 'site_id' => $this->site->id, 'provider' => 'hikvision', 'purpose' => 'cctv_read_only', 'capabilities' => [HikvisionDiscoveryAuthority::CAPABILITY], 'secret_manager_reference' => 'do-not-expose-'.$i, 'secret_manager_reference_hash' => hash('sha256', 'location-'.$i), 'status' => 'active', 'rotation_status' => 'current', 'test_status' => 'passed', 'version' => 1]);
        }
        $base = '/security-devices/devices/'.$this->camera->id.'/live/hikvision/setup';
        $first = $this->getJson($base.'?choice=credentials&q=approved-camera')->assertOk()->json();
        self::assertCount(20, $first['choices']['credentials']['items']);
        self::assertNotNull($first['choices']['credentials']['next_href']);
        $this->getJson($first['choices']['credentials']['next_href'])->assertOk()->assertJsonCount(3, 'choices.credentials.items');
        $this->getJson(str_replace('q=approved-camera', 'q=changed', $first['choices']['credentials']['next_href']))->assertBadRequest();
        $this->getJson($base.'?choice=credentials&q=approved-camera-22')->assertOk()->assertJsonCount(1, 'choices.credentials.items');
        $this->getJson($base.'?choice=credentials&q=%25')->assertOk()->assertJsonCount(0, 'choices.credentials.items');
        $this->getJson($base.'?choice=credentials&collector_id='.$this->collector->id)->assertBadRequest();
        $this->getJson($base.'?choice=credentials&unexpected=private')->assertBadRequest()->assertExactJson(['message' => 'invalid_request']);
        $this->mapped(false);
        $this->getJson($first['choices']['credentials']['next_href'])->assertBadRequest();
    }

    public function test_setup_directory_rechecks_collector_ownership_protocol_and_credential_rotation(): void
    {
        $base = '/security-devices/devices/'.$this->camera->id.'/live/hikvision/setup';
        $this->getJson($base.'?choice=scopes&collector_id='.$this->collector->id)->assertOk()->assertJsonPath('choices.scopes.items.0.id', (int) $this->scope->id);
        $this->reference->update(['rotation_status' => 'overdue']);
        $this->scope->update(['protocols' => ['icmp']]);
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'choices.credentials.items')->assertJsonCount(0, 'choices.scopes.items');
        $computer = Device::query()->findOrFail($this->collector->collector_device_id);
        foreach (['quarantined', 'lost'] as $status) {
            $computer->update(['status' => $status]);
            $this->getJson($base)->assertOk()->assertJsonCount(0, 'choices.collectors.items');
            $this->getJson($base.'?choice=scopes&collector_id='.$this->collector->id)->assertNotFound()->assertExactJson(['message' => 'unavailable']);
        }
        $computer->update(['status' => 'active']);
        DeviceAssignment::query()->where('device_id', $this->collector->collector_device_id)->update(['released_at' => now()]);
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'choices.collectors.items');
        $this->getJson($base.'?choice=scopes&collector_id='.$this->collector->id)->assertNotFound()->assertExactJson(['message' => 'unavailable']);
    }

    public function test_approved_site_media_viewer_can_view_and_stop_hikvision_without_management_permission(): void
    {
        $this->mapped();
        $viewer = User::factory()->create();
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $this->site->id, 'is_active' => true]);
        $role = Role::query()->create(['name' => 'synthetic_hik_media_viewer', 'label' => 'Camera viewer', 'level' => 20, 'type' => 'custom']);
        $role->permissions()->sync(Permission::query()->whereIn('key', ['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.cctv.media.view'])->pluck('id')->all());
        $viewer->roles()->attach($role);
        $this->actor = $viewer;
        $this->actingAs($viewer);
        $this->getJson('/security-devices/devices/'.$this->camera->id.'/live/hikvision/setup')->assertNotFound()->assertExactJson(['message' => 'unavailable']);
        $this->postJson($this->href().'/disable', $this->actionInput())->assertNotFound();
        $started = $this->startLive()->assertOk()->json();
        $offered = $this->offerLive($started);
        $operation = $this->liveWork()->assertOk()->json('operations.0');
        $this->liveCollector('results', $this->resultBody($this->reply($operation)))->assertOk();
        $this->getJson($offered['session']['status_href'].'?expected_actor_id='.$viewer->id)->assertOk()->assertJsonPath('session.status', 'ready');
        $this->postJson($offered['session']['stop_href'], ['expected_actor_id' => (int) $viewer->id, 'expected_generation' => 1])->assertOk()->assertJsonPath('session.status', 'stopped');
        self::assertFalse($viewer->fresh()->canDo('securityDevices.integrations.manage'));
    }

    public function test_real_governed_lease_sealed_dispatch_current_result_and_exact_renewal_recovery(): void
    {
        $source = $this->mapped();
        $input = $this->liveInput();
        $started = $this->startLive($input)->assertOk()->json();
        self::assertSame(2, $this->issued, 'One discovery lease and one distinct live lease.');
        $row = CctvLiveSession::query()->firstOrFail();
        self::assertSame('hikvision', $row->provider);
        $lease = CredentialLeaseGrant::query()->findOrFail($row->credential_lease_grant_id);
        self::assertSame([HikvisionLiveContract::CAPABILITY], $lease->capabilities);
        self::assertTrue($row->session_expires_at->lte($lease->expires_at));
        self::assertTrue($row->session_expires_at->equalTo($row->ice_expires_at));
        self::assertStringNotContainsString('synthetic-private-password', CctvContract::json($started));
        self::assertStringNotContainsString('synthetic-private-password', $row->getRawOriginal('material'));
        self::assertArrayNotHasKey('source_context', $row->toArray());
        $this->travel(2)->seconds();
        $replayed = $this->startLive($input)->assertOk()->json('session');
        self::assertSame($started['session']['uuid'], $replayed['uuid']);
        self::assertSame($started['session']['authority_until'], $replayed['authority_until']);
        self::assertSame(2, $this->issued);
        $offered = $this->offerLive($started);
        $key = (string) Str::uuid();
        $work = $this->liveWork($key)->assertOk()->json();
        self::assertSame($work, $this->liveWork($key)->assertOk()->json());
        $operation = $work['operations'][0];
        $grantBytes = base64_decode($operation['grant']['payload'], true);
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($operation['grant']['signature'], true), $grantBytes, sodium_crypto_sign_publickey_from_secretkey($this->serverKey)));
        $grant = json_decode($grantBytes, true, 32, JSON_THROW_ON_ERROR);
        CctvContract::exact($grant, HikvisionLiveContract::KEYS);
        self::assertSame(HikvisionLiveContract::TYPE, $grant['type']);
        self::assertSame(HikvisionLiveContract::CAPABILITY, $grant['capability']);
        self::assertNull($grant['recorder_device_id']);
        self::assertSame($source->configuration['identity'], $grant['identity']);
        self::assertSame($source->configuration['channel'], $grant['channel']);
        $box = sodium_crypto_box_keypair_from_secretkey_and_publickey(sodium_crypto_sign_ed25519_sk_to_curve25519($this->collectorKey), sodium_crypto_sign_ed25519_pk_to_curve25519(sodium_crypto_sign_publickey_from_secretkey($this->collectorKey)));
        $sealed = json_decode(sodium_crypto_box_seal_open(base64_decode($operation['credential_lease']['sealed_material'], true), $box), true, 32, JSON_THROW_ON_ERROR);
        // The central seal's original nested material is checked by the real adapter.
        // A second Composer vendor loader changes Laravel's inferred base path.
        // Load original collector classes without replacing that application path.
        foreach (['Security/CredentialLeaseDecryptor.php', 'Security/EnvelopeVerifier.php', 'Media/HikvisionLiveExchange.php'] as $file) {
            require_once base_path('collector/src/'.$file);
        }
        $adapter = new HikvisionLiveExchange(new CredentialLeaseDecryptor($this->collectorKey), new EnvelopeVerifier(sodium_crypto_sign_publickey_from_secretkey($this->serverKey)));
        $frame = $adapter->frame($operation, ['collector_id' => $this->collector->collector_uuid, 'site_id' => (int) $this->site->id], now()->toDateTimeImmutable());
        $private = json_decode($frame, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('synthetic-private-password', $private['source']['password']);
        self::assertSame(CctvContractTest::offer(), $private['offer']['sdp']);
        self::assertArrayNotHasKey('url', $private['source']);
        self::assertSame(hash('sha256', $row->fresh()->material['source_json']), $grant['source_sha256']);
        self::assertSame(hash('sha256', $row->fresh()->material['turn_json']), $grant['turn_sha256']);
        self::assertSame('not_requested', $source->fresh()->stream_state);
        $reply = $this->reply($operation);
        $this->liveCollector('results', $this->resultBody($reply))->assertOk()->assertJsonCount(1, 'accepted');
        self::assertSame('ready', $row->fresh()->status);
        self::assertSame('verified', $source->fresh()->stream_state);
        $verified = $source->fresh()->stream_verified_at;
        $this->liveCollector('results', $this->resultBody($reply))->assertOk()->assertJsonCount(1, 'accepted');
        self::assertTrue($verified->equalTo($source->fresh()->stream_verified_at));
        $changed = array_replace($reply, ['status' => 'failed', 'answer' => null, 'reason_code' => 'transport_unavailable']);
        $this->liveCollector('results', $this->resultBody($changed))->assertStatus(409);
        self::assertSame('ready', $row->fresh()->status);
        $renewInput = ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1];
        $renewed = $this->postJson($started['session']['renew_href'], $renewInput)->assertOk()->json('session');
        $this->travel(1)->seconds();
        $retry = $this->postJson($started['session']['renew_href'], $renewInput)->assertOk()->json('session');
        self::assertSame(2, $retry['generation']);
        self::assertSame($renewed['authority_until'], $retry['authority_until']);
        self::assertSame($started['session']['session_expires_at'], $retry['session_expires_at']);
        self::assertSame($started['session']['ice_expires_at'], $retry['ice_expires_at']);
        self::assertSame(2, $this->issued);
        $renewOp = $this->liveWork()->assertOk()->json('operations.0');
        self::assertSame('renew', $renewOp['operation']);
        self::assertArrayNotHasKey('credential_lease', $renewOp);
        $new = json_decode(base64_decode($renewOp['grant']['payload'], true), true, 32, JSON_THROW_ON_ERROR);
        foreach (['source_sha256', 'turn_sha256', 'offer_sha256', 'session_expires_at', 'identity', 'channel'] as $field) {
            self::assertSame($grant[$field], $new[$field]);
        }
        self::assertCount(1, AuditLog::query()->where('action', 'cctv.live.renewed')->get());
    }

    public function test_enable_replay_cannot_reactivate_after_explicit_withdrawal_and_stop_cannot_reopen(): void
    {
        $source = $this->mapped(false);
        $enable = $this->actionInput();
        $this->postJson($this->href().'/enable', $enable)->assertOk();
        $input = $this->liveInput();
        $started = $this->startLive($input)->assertOk()->json('session');
        config(['cctv.hikvision_live_enabled' => false, 'cctv.enabled' => false]);
        $this->postJson($this->href().'/disable', $this->actionInput())->assertOk()->assertJsonPath('source.enabled', false);
        self::assertSame('revoked', CctvLiveSession::query()->firstOrFail()->status);
        self::assertNull(CctvLiveSession::query()->firstOrFail()->material);
        $this->postJson($this->href().'/enable', $enable)->assertOk()->assertJsonPath('source.enabled', false);
        config(['cctv.hikvision_live_enabled' => true, 'cctv.enabled' => true]);
        $this->startLive($input)->assertOk()->assertJsonPath('session.uuid', $started['uuid'])->assertJsonPath('session.status', 'revoked');
        self::assertSame(2, $this->issued);
        self::assertFalse($source->fresh()->enabled);
    }

    public function test_inactive_source_and_native_profile_do_not_allow_viewing_and_old_media_capability_remains_accepted(): void
    {
        $source = $this->mapped(false);
        $this->startLive()->assertStatus(409);
        $this->liveWork(null, null)->assertOk();
        $this->postJson($this->href().'/enable', $this->actionInput())->assertStatus(503);
        $this->liveWork()->assertOk();
        $this->postJson($this->href().'/enable', $this->actionInput())->assertOk();
        $started = $this->startLive()->assertOk()->json();
        $this->offerLive($started);
        $old = ['collector_id' => $this->collector->collector_uuid, 'request_key' => (string) Str::uuid(), 'media_capability' => ['version' => 1, 'available' => true, 'video_codec' => 'H264', 'audio' => false]];
        $this->liveCollector('work', $old)->assertOk()->assertJsonPath('operations.0.operation', 'close');
        self::assertSame('failed', CctvLiveSession::query()->firstOrFail()->status);
        self::assertSame('worker_unavailable', CctvLiveSession::query()->firstOrFail()->reason_code);
        self::assertNull(CctvLiveSession::query()->firstOrFail()->material);
        self::assertSame('not_requested', $source->fresh()->stream_state);
    }

    public function test_uncertain_credential_preparation_is_a_retained_failure_and_is_not_reissued(): void
    {
        $this->mapped();
        $this->duringIssue = fn () => throw new \RuntimeException('Synthetic provider unavailable.');
        $input = $this->liveInput();
        $this->startLive($input)->assertStatus(503);
        $row = CctvLiveSession::query()->firstOrFail();
        self::assertSame('failed', $row->status);
        self::assertSame('credential_unavailable', $row->reason_code);
        $this->duringIssue = null;
        $this->startLive($input)->assertOk()->assertJsonPath('session.status', 'failed');
        self::assertSame(2, $this->issued);
    }

    public function test_authority_change_during_external_lease_releases_lease_and_retains_failure(): void
    {
        $this->mapped();
        $this->duringIssue = function (): void {
            $this->camera->update(['serial_number' => 'replacement-camera']);
        };
        $input = $this->liveInput();
        $this->startLive($input)->assertStatus(409);
        self::assertSame('failed', CctvLiveSession::query()->firstOrFail()->status);
        self::assertNull(CctvLiveSession::query()->firstOrFail()->credential_lease_grant_id);
        self::assertContains('synthetic-hk-lease-2', $this->revoked);
        self::assertSame(CredentialLeaseGrant::STATUS_RELEASED, CredentialLeaseGrant::query()->where('lease_fingerprint', app(CredentialReferenceRules::class)->fingerprint('synthetic-hk-lease-2'))->firstOrFail()->status);
    }

    #[DataProvider('lostAuthority')]
    public function test_active_session_is_revoked_when_current_authority_changes(string $change): void
    {
        $this->mapped();
        $started = $this->startLive()->assertOk()->json();
        $this->offerLive($started);
        switch ($change) {
            case 'role': $this->actor->roles()->detach();
                break;
            case 'model': $this->camera->update(['model' => 'replaced-model']);
                break;
            case 'ip': $this->camera->update(['ip_address' => '10.44.0.11']);
                break;
            case 'collector-key': $this->collector->update(['public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);
                break;
            case 'scope': $this->scope->update(['cidrs' => ['10.55.0.0/24']]);
                break;
            case 'reference-version': $this->reference->update(['version' => 2]);
                break;
            case 'live-capability': $this->reference->update(['capabilities' => [HikvisionDiscoveryAuthority::CAPABILITY]]);
                break;
            case 'support': config(['cctv.hikvision_supported_profiles' => []]);
                break;
            case 'gate': config(['cctv.hikvision_live_enabled' => false]);
                break;
            case 'governed-grant': CredentialLeaseGrant::query()->findOrFail(CctvLiveSession::query()->firstOrFail()->credential_lease_grant_id)->update(['status' => CredentialLeaseGrant::STATUS_RELEASED]);
                break;
        }
        if ($change === 'collector-key') {
            // The new enrolled transport is authenticated, but the source keeps
            // the original collector incarnation and cannot silently rebind.
            $this->collectorKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
            $this->collector->update(['public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->collectorKey))]);
        }
        $this->liveWork()->assertOk()->assertJsonPath('operations.0.operation', 'close');
        $row = CctvLiveSession::query()->firstOrFail();
        self::assertSame('revoked', $row->status);
        self::assertSame('authority_lost', $row->reason_code);
        self::assertNull($row->material);
        self::assertNull($row->worker_grant);
    }

    public static function lostAuthority(): array
    {
        return array_map(fn ($s) => [$s], ['role', 'model', 'ip', 'collector-key', 'scope', 'reference-version', 'live-capability', 'support', 'gate', 'governed-grant']);
    }

    public function test_absolute_credential_expiry_cannot_be_extended_and_late_result_is_ignored(): void
    {
        $this->mapped();
        $started = $this->startLive()->assertOk()->json();
        $this->offerLive($started);
        $op = $this->liveWork()->assertOk()->json('operations.0');
        $this->travel(61)->seconds();
        $this->liveCollector('results', $this->resultBody($this->reply($op)))->assertOk()->assertJsonPath('accepted', []);
        $row = CctvLiveSession::query()->firstOrFail();
        self::assertSame('expired', $row->status);
        self::assertSame('session_expired', $row->reason_code);
        self::assertNull($row->material);
        self::assertTrue($row->session_expires_at->equalTo($row->ice_expires_at));
        self::assertTrue($row->authority_until->lte($row->session_expires_at));
        self::assertTrue($row->authority_until->lte(CarbonImmutable::parse($started['session']['authority_until'])));
        $terminal = $this->getJson($started['session']['status_href'].'?expected_actor_id='.$this->actor->id)
            ->assertOk()->assertJsonPath('session.status', 'expired')->assertJsonPath('session.reason_code', 'session_expired')
            ->assertJsonPath('session.answer', null)->assertJsonPath('session.ice_expires_at', null)->assertJsonCount(0, 'session.ice_servers')->json();
        self::assertSame($started['session']['session_expires_at'], $terminal['session']['session_expires_at']);
        $export = getenv('OBLI_CCTV_TERMINAL_EXPORT');
        if (is_string($export) && $export !== '') {
            file_put_contents($export.'.hikvision', json_encode($terminal, JSON_THROW_ON_ERROR));
        }
        self::assertSame(2, $this->issued);
    }

    public function test_live_model_freezes_original_authority_lifetime_and_denies_destructive_rollback(): void
    {
        $this->mapped();
        $this->startLive()->assertOk();
        $row = CctvLiveSession::query()->firstOrFail();
        foreach (['camera_id' => (int) $row->camera_id + 1, 'source_context' => ['version' => 0], 'credential_lease_grant_id' => (int) $row->credential_lease_grant_id + 1, 'session_expires_at' => $row->session_expires_at->addMinute(), 'ice_expires_at' => $row->ice_expires_at->addMinute()] as $field => $value) {
            try {
                $row->fresh()->update([$field => $value]);
                self::fail('Immutable authority accepted '.$field);
            } catch (\LogicException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        $migration = require base_path('database/migrations/2026_10_12_010000_add_hikvision_live_authority.php');
        try {
            $migration->down();
            self::fail('Retained live authority was destructively rolled back.');
        } catch (\RuntimeException $e) {
            self::assertSame('Retained live session authority prevents rollback.', $e->getMessage());
        }
        self::assertTrue(Schema::hasColumn('cctv_live_sessions', 'provider'));
        self::assertTrue(Schema::hasColumn('cctv_collector_presence', 'hikvision_profile'));
    }

    public function test_stop_has_durable_correlated_close_recovery_even_with_live_gates_off(): void
    {
        $this->mapped();
        $input = $this->liveInput();
        $started = $this->startLive($input)->assertOk()->json();
        $this->offerLive($started);
        $open = $this->liveWork()->assertOk()->json('operations.0');
        $this->liveCollector('results', $this->resultBody($this->reply($open)))->assertOk();
        config(['cctv.enabled' => false, 'cctv.hikvision_live_enabled' => false]);
        $stop = ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1];
        $this->postJson($started['session']['stop_href'], $stop)->assertOk()->assertJsonPath('session.status', 'stopped');
        $row = CctvLiveSession::query()->firstOrFail();
        self::assertNull($row->material);
        self::assertNull($row->worker_grant);
        $close = $this->liveWork(null, null)->assertOk()->json('operations.0');
        CctvContract::exact($close, ['operation', 'request_uuid', 'session_uuid', 'generation']);
        self::assertSame('close', $close['operation']);
        self::assertSame($row->uuid, $close['request_uuid']);
        self::assertSame($close, $this->liveWork(null, null)->assertOk()->json('operations.0'));
        $reply = ['version' => 1, 'request_uuid' => $close['request_uuid'], 'session_uuid' => $close['session_uuid'], 'generation' => $close['generation'], 'status' => 'stopped', 'answer' => null, 'reason_code' => 'stopped'];
        $this->liveCollector('results', $this->resultBody(array_replace($reply, ['generation' => 2])))->assertOk()->assertJsonPath('accepted', []);
        $this->liveWork(null, null)->assertOk()->assertJsonCount(1, 'operations');
        $this->liveCollector('results', $this->resultBody(array_replace($reply, ['status' => 'failed', 'reason_code' => 'worker_unavailable'])))->assertOk()->assertJsonPath('accepted', []);
        $this->liveWork(null, null)->assertOk()->assertJsonCount(1, 'operations');
        $this->liveCollector('results', $this->resultBody($reply))->assertOk()->assertJsonCount(1, 'accepted');
        $this->liveCollector('results', $this->resultBody($reply))->assertOk()->assertJsonCount(1, 'accepted');
        $this->liveCollector('results', $this->resultBody(array_replace($reply, ['status' => 'expired', 'reason_code' => 'lease_expired'])))->assertStatus(409);
        $this->liveWork(null, null)->assertOk()->assertJsonPath('operations', []);
        $this->startLive($input)->assertOk()->assertJsonPath('session.status', 'stopped')->assertJsonPath('session.uuid', $row->uuid);
        self::assertSame(2, $this->issued);
        self::assertCount(1, AuditLog::query()->where('action', 'cctv.hikvision.live_closed')->get());
        self::assertContains('synthetic-hk-lease-2', $this->revoked);
    }

    public function test_pending_credential_preparation_cannot_be_reissued_by_concurrent_recovery(): void
    {
        $this->mapped();
        $input = $this->liveInput();
        $this->duringIssue = function () use ($input): void {
            $this->startLive($input)->assertStatus(503);
            $this->liveWork()->assertOk()->assertJsonPath('operations', []);
            self::assertSame(2, $this->issued);
        };
        $this->startLive($input)->assertOk();
        self::assertSame(2, $this->issued);
        self::assertSame(1, CctvLiveSession::query()->count());
    }

    public function test_another_browser_cannot_recover_start_or_consume_an_owned_session(): void
    {
        $this->mapped();
        $input = $this->liveInput();
        $started = $this->startLive($input)->assertOk()->json('session');
        $this->withCookie(config('session.cookie'), str_repeat('z', 40))->withCredentials();
        $this->startLive($input)->assertNotFound()->assertExactJson(['message' => 'unavailable']);
        $this->getJson($started['status_href'].'?expected_actor_id='.$this->actor->id)->assertNotFound();
        self::assertSame(2, $this->issued);
        self::assertSame(1, CctvLiveSession::query()->count());
    }

    public function test_transient_negotiation_can_finish_and_rejected_answer_does_not_poison_renewal_receipt(): void
    {
        $this->mapped();
        $started = $this->startLive()->assertOk()->json();
        $this->offerLive($started);
        $op = $this->liveWork()->assertOk()->json('operations.0');
        $ready = $this->reply($op);
        $negotiating = array_replace($ready, ['status' => 'negotiating', 'answer' => null]);
        $this->liveCollector('results', $this->resultBody($negotiating))->assertOk()->assertJsonCount(1, 'accepted');
        $this->liveCollector('results', $this->resultBody($ready))->assertOk()->assertJsonCount(1, 'accepted');
        $this->liveCollector('results', $this->resultBody($negotiating))->assertOk();
        self::assertSame('ready', CctvLiveSession::query()->firstOrFail()->status);
        $this->postJson($started['session']['renew_href'], ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1])->assertOk();
        $renew = $this->liveWork()->assertOk()->json('operations.0');
        $good = $this->reply($renew);
        $bad = $good;
        $bad['answer']['sdp'] = str_replace('o=- 1 1', 'o=- 2 2', $bad['answer']['sdp']);
        $this->liveCollector('results', $this->resultBody($bad))->assertOk()->assertJsonPath('accepted', []);
        self::assertSame(0, CctvRequestReceipt::query()->where('owner', 'hikvision_live_result:'.$good['session_uuid'].':final')->where('request_key', $good['request_uuid'])->count());
        $this->liveCollector('results', $this->resultBody($good))->assertOk()->assertJsonCount(1, 'accepted');
    }

    public function test_result_audit_failure_rolls_back_verification_and_receipt_then_exact_retry_commits(): void
    {
        $source = $this->mapped();
        $started = $this->startLive()->assertOk()->json();
        $this->offerLive($started);
        $reply = $this->reply($this->liveWork()->assertOk()->json('operations.0'));
        $input = $this->resultBody($reply);
        $fail = true;
        AuditLog::creating(function (AuditLog $row) use (&$fail): void {
            if ($fail && $row->action === 'cctv.hikvision.live_result') {
                throw new \RuntimeException('Synthetic audit storage unavailable.');
            }
        });
        try {
            $this->liveCollector('results', $input)->assertStatus(503);
        } finally {
            $fail = false;
        }
        self::assertSame('not_requested', $source->fresh()->stream_state);
        self::assertNull(CctvLiveSession::query()->firstOrFail()->material['answer']);
        self::assertSame(0, CctvRequestReceipt::query()->where('owner', 'hikvision_live_result:'.$reply['session_uuid'].':final')->count());
        $this->liveCollector('results', $input)->assertOk()->assertJsonCount(1, 'accepted');
        self::assertSame('verified', $source->fresh()->stream_state);
    }

    public function test_credential_preparation_audit_failure_releases_lease_and_keeps_a_durable_failed_session(): void
    {
        $this->mapped();
        $fail = true;
        AuditLog::creating(function (AuditLog $row) use (&$fail): void {
            if ($fail && $row->action === 'cctv.live.credential_prepared') {
                throw new \RuntimeException('Synthetic audit storage unavailable.');
            }
        });
        $input = $this->liveInput();
        try {
            $this->startLive($input)->assertStatus(503);
        } finally {
            $fail = false;
        }
        self::assertSame('failed', CctvLiveSession::query()->firstOrFail()->status);
        self::assertNull(CctvLiveSession::query()->firstOrFail()->material);
        self::assertNull(CctvLiveSession::query()->firstOrFail()->credential_lease_grant_id);
        self::assertContains('synthetic-hk-lease-2', $this->revoked);
        $this->startLive($input)->assertOk()->assertJsonPath('session.status', 'failed');
        self::assertSame(2, $this->issued);
    }

    public function test_activation_audit_failure_and_active_source_conflict_require_explicit_reviewed_recovery(): void
    {
        $first = $this->mapped(false);
        $input = $this->actionInput();
        $fail = true;
        AuditLog::creating(function (AuditLog $row) use (&$fail): void {
            if ($fail && $row->action === 'cctv.hikvision.source_enabled') {
                throw new \RuntimeException('Synthetic audit storage unavailable.');
            }
        });
        try {
            $this->postJson($this->href().'/enable', $input)->assertStatus(503);
        } finally {
            $fail = false;
        }
        self::assertFalse($first->fresh()->enabled);
        $this->postJson($this->href().'/enable', $input)->assertOk();
        $request = $this->commit(null, 1);
        $this->saveMapping($this->mapping($request))->assertOk();
        $second = array_replace($this->actionInput(), ['expected_revision' => 2]);
        $this->postJson($this->href().'/enable', $second)->assertStatus(409)->assertJsonPath('message', 'active_source_exists');
        self::assertTrue($first->fresh()->enabled);
        $this->postJson($this->href().'/disable', $this->actionInput())->assertOk();
        $this->postJson($this->href().'/enable', $second)->assertOk()->assertJsonPath('source.revision', 2)->assertJsonPath('source.enabled', true);
        $this->postJson($this->href().'/enable', $input)->assertOk()->assertJsonPath('source.enabled', false);
        self::assertSame(1, CctvSource::query()->where('enabled', true)->count());
    }

    public function test_shared_capacity_denies_another_lease_until_stop_and_failed_revoke_recovers(): void
    {
        $this->mapped();
        $first = $this->startLive()->assertOk()->json('session');
        $this->startLive()->assertOk();
        $this->startLive()->assertStatus(429);
        self::assertSame(3, $this->issued);
        $this->revokeUnavailable = true;
        $this->postJson($first['stop_href'], ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1])->assertOk()->assertJsonPath('session.status', 'stopped');
        $row = CctvLiveSession::query()->where('uuid', $first['uuid'])->firstOrFail();
        $grant = CredentialLeaseGrant::query()->findOrFail($row->credential_lease_grant_id);
        self::assertSame(CredentialLeaseGrant::STATUS_REVOKE_PENDING, $grant->status);
        self::assertNull($row->material);
        $this->revokeUnavailable = false;
        app(CredentialLeaseLifecycleService::class)->reconcile();
        self::assertSame(CredentialLeaseGrant::STATUS_REVOKE_PENDING, $grant->fresh()->status, 'Containment retries retain their 15-second backoff.');
        $this->travel(16)->seconds();
        app(CredentialLeaseLifecycleService::class)->reconcile();
        self::assertSame(CredentialLeaseGrant::STATUS_CONTAINED, $grant->fresh()->status);
        $this->liveWork()->assertOk();
        $this->startLive()->assertOk();
        self::assertSame(4, $this->issued);
    }

    public function test_duplicate_start_and_renew_json_fields_do_not_consume_or_extend_authority(): void
    {
        $this->mapped();
        $input = $this->liveInput();
        $raw = substr(CctvContract::json($input), 0, -1).',"source_revision":1}';
        $path = '/security-devices/devices/'.$this->camera->id.'/live-sessions';
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertStatus(400)->assertExactJson(['message' => 'invalid_request']);
        self::assertSame(1, $this->issued);
        self::assertSame(0, CctvLiveSession::query()->count());
        $started = $this->startLive($input)->assertOk()->json('session');
        $raw = CctvContract::json(['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1]);
        $raw = substr($raw, 0, -1).',"expected_generation":1}';
        $this->call('POST', $started['renew_href'], [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertStatus(400);
        self::assertSame(1, CctvLiveSession::query()->firstOrFail()->generation);
        self::assertSame($started['authority_until'], CctvLiveSession::query()->firstOrFail()->authority_until->utc()->format('Y-m-d\TH:i:s\Z'));
        self::assertSame(2, $this->issued);
    }

    public function test_failed_close_audit_retains_pending_shutdown_until_exact_acknowledgement_commits(): void
    {
        $this->mapped();
        $started = $this->startLive()->assertOk()->json('session');
        $this->postJson($started['stop_href'], ['expected_actor_id' => (int) $this->actor->id, 'expected_generation' => 1])->assertOk();
        $close = $this->liveWork()->assertOk()->json('operations.0');
        $reply = ['version' => 1, 'request_uuid' => $close['request_uuid'], 'session_uuid' => $close['session_uuid'], 'generation' => $close['generation'], 'status' => 'stopped', 'answer' => null, 'reason_code' => 'stopped'];
        $input = $this->resultBody($reply);
        $fail = true;
        AuditLog::creating(function (AuditLog $row) use (&$fail): void {
            if ($fail && $row->action === 'cctv.hikvision.live_closed') {
                throw new \RuntimeException('Synthetic audit storage unavailable.');
            }
        });
        try {
            $this->liveCollector('results', $input)->assertStatus(503)->assertExactJson(['message' => 'temporarily_unavailable']);
        } finally {
            $fail = false;
        }
        self::assertSame($close, $this->liveWork()->assertOk()->json('operations.0'));
        self::assertNull(CctvRequestReceipt::query()->where('owner', 'hikvision_live_close:'.$this->collector->id)->where('request_key', $close['session_uuid'])->firstOrFail()->response);
        $this->liveCollector('results', $input)->assertOk()->assertJsonCount(1, 'accepted');
        $this->liveWork()->assertOk()->assertJsonPath('operations', []);
        self::assertSame('stopped', CctvLiveSession::query()->firstOrFail()->status);
        self::assertNull(CctvLiveSession::query()->firstOrFail()->material);
    }
}
