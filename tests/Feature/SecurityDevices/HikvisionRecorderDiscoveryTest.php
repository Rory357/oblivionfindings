<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Models\HikvisionDiscoveryRequest;
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\CctvNavigation;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionDiscoveryRequests;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionRecorderDiscoveryContract;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CredentialLeaseLifecycleService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HikvisionRecorderDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Site $site;

    private Device $recorder;

    private MonitoringCollector $collector;

    private DiscoveryScope $scope;

    private CredentialReference $reference;

    private string $serverKey;

    private string $collectorKey;

    private int $issued = 0;

    private array $leaseCapabilities = [HikvisionRecorderDiscoveryContract::CAPABILITY];

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
        $this->recorder = Device::factory()->security()->create(['category' => 'cctv', 'subcategory' => 'nvr', 'provider' => 'hikvision', 'ip_address' => '10.44.0.10', 'serial_number' => 'original-canonical-recorder-serial']);
        $computer = Device::factory()->itInfrastructure()->create();
        foreach ([$this->recorder, $computer] as $device) {
            DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
        }
        $this->serverKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->collectorKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->collector = MonitoringCollector::factory()->create(['site_id' => $this->site->id, 'collector_device_id' => $computer->id, 'public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->collectorKey)), 'client_certificate_fingerprint' => str_repeat('a', 64), 'enrolled_at' => now()]);
        $this->scope = DiscoveryScope::factory()->create(['site_id' => $this->site->id, 'collector_id' => $this->collector->id, 'cidrs' => ['10.44.0.0/24'], 'port_bounds' => ['tcp' => [443]], 'protocols' => ['tls']]);
        $this->reference = CredentialReference::query()->create(['reference_key' => 'vault:hikvision/discovery', 'site_id' => $this->site->id, 'provider' => 'hikvision', 'purpose' => 'cctv_read_only', 'capabilities' => [HikvisionRecorderDiscoveryContract::CAPABILITY], 'secret_manager_reference' => 'private-camera-location', 'secret_manager_reference_hash' => str_repeat('b', 64), 'status' => 'active', 'rotation_status' => 'current', 'test_status' => 'passed', 'version' => 1]);
        config(['cctv.hikvision_discovery_enabled' => true, 'monitoring.collector.signing_secret_key' => base64_encode($this->serverKey), 'monitoring.collector.trusted_proxy_cidrs' => ['127.0.0.1/32'], 'monitoring.collector.allow_proxy_fingerprint_header' => true, 'monitoring.collector.replay_store' => 'array', 'monitoring.collector.allow_local_replay_store_for_tests' => true]);
        // Keep the real governed broker and lifecycle; substitute only the external
        // secret manager, so catalogue checks, lease records and audits run normally.
        $issue = function (SecretLeaseRequest $request): CredentialLease {
            $this->issued++;
            self::assertSame(0, DB::transactionLevel() - 1, 'External retrieval must run outside domain transactions (the test owns one rollback transaction).');
            self::assertSame((int) $this->site->id, $request->siteId);
            self::assertSame($this->leaseCapabilities, $request->capabilities);
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
        Http::preventStrayRequests();
        $this->actingAs($this->actor);
    }

    private function input(array $changes = []): array
    {
        return array_replace(['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'expected_source_revision' => 0, 'collector_id' => (int) $this->collector->id, 'discovery_scope_id' => (int) $this->scope->id, 'credential_reference_id' => (int) $this->reference->id, 'allowed_ip' => '10.44.0.10', 'allowed_port' => 443, 'tls_server_name' => 'recorder.example.test', 'tls_pin' => str_repeat('c', 64), 'purpose' => 'Check the reported camera identity and channels.'], $changes);
    }

    private function create(?array $input = null): TestResponse
    {
        return $this->postJson('/security-devices/devices/'.$this->recorder->id.'/hikvision-discoveries', $input ?? $this->input());
    }

    private function body(?string $key = null, bool $available = true): array
    {
        return ['collector_id' => $this->collector->collector_uuid, 'request_key' => $key ?? (string) Str::uuid(), 'capability' => ['version' => 1, 'available' => $available, 'profile' => 'hikvision_isapi_https_v1', 'recorder_profile' => HikvisionRecorderDiscoveryContract::PROFILE]];
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

        return ['version' => 2, 'operation' => HikvisionRecorderDiscoveryContract::OPERATION, 'request_uuid' => $operation['request_uuid'], 'device_id' => (int) $this->recorder->id, 'context_sha256' => $grant['context_sha256'], 'credential_binding_sha256' => $grant['credential_binding_sha256'], 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'status' => 'observed', 'reason_code' => null, 'inventory' => [
            'identity' => ['model' => 'DS-7608NXI-K1/8P', 'serial' => 'fixture-recorder-serial', 'device_id' => 'fixture-recorder-id', 'firmware' => 'fixture-version', 'firmware_build' => 'fixture-build'],
            'channels' => [['id' => '1301', 'input_id' => '13', 'input_kind' => 'digital_proxy', 'name' => 'Synthetic input', 'codec' => 'H.265', 'enabled' => true, 'video_enabled' => true, 'width' => 1920, 'height' => 1080, 'fps100' => 0, 'audio_enabled' => null]],
            'inputs' => [['id' => '13', 'proxy_protocol' => 'HIKVISION', 'addressing_type' => 'ipaddress', 'address' => '192.0.2.20', 'management_port' => 8000, 'source_input_id' => '1', 'online' => true, 'camera_identity' => null, 'association' => 'unverified']],
            'response_sha256' => ['device_info' => str_repeat('a', 64), 'streaming_proxy' => str_repeat('b', 64), 'input_status' => str_repeat('c', 64)],
        ]];
    }

    private function resultBody(array $result, ?string $key = null): array
    {
        return ['collector_id' => $this->collector->collector_uuid, 'request_key' => $key ?? (string) Str::uuid(), 'results' => [$result]];
    }

    public function test_recorder_has_distinct_original_signed_dispatch_and_immutable_observation_without_camera_activation(): void
    {
        $created = $this->create()->assertOk()->json();
        $work = $this->body();
        $first = $this->postCollector('work', $work)->assertOk()->json();
        self::assertSame($first, $this->postCollector('work', $work)->assertOk()->json());
        $op = $first['operations'][0];
        self::assertSame(HikvisionRecorderDiscoveryContract::OPERATION, $op['operation']);
        $bytes = base64_decode($op['grant']['payload'], true);
        $grant = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($op['grant']['signature'], true), $bytes, sodium_crypto_sign_publickey_from_secretkey($this->serverKey)));
        self::assertSame(2, $grant['version']);
        self::assertSame(HikvisionRecorderDiscoveryContract::CAPABILITY, $grant['capability']);
        self::assertSame('recorder', $grant['source_kind']);
        self::assertSame(HikvisionRecorderDiscoveryContract::RESOURCE_SET, $grant['resource_set']);
        self::assertSame((int) $this->recorder->id, $grant['device_id']);
        self::assertSame(HikvisionRecorderDiscoveryContract::PROTOCOL, $op['credential_lease']['protocol']);
        self::assertSame([HikvisionRecorderDiscoveryContract::CAPABILITY], CredentialLeaseGrant::query()->firstOrFail()->capabilities);
        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey(sodium_crypto_sign_ed25519_sk_to_curve25519($this->collectorKey), sodium_crypto_sign_ed25519_pk_to_curve25519(sodium_crypto_sign_publickey_from_secretkey($this->collectorKey)));
        $sealed = json_decode(sodium_crypto_box_seal_open(base64_decode($op['credential_lease']['sealed_material'], true), $pair), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($grant['source_sha256'], hash('sha256', $sealed['material']['source_json']));
        $result = $this->observation($op);
        $body = $this->resultBody($result);
        $accepted = $this->postCollector('results', $body)->assertOk()->json();
        self::assertSame($accepted, $this->postCollector('results', $body)->assertOk()->json());
        self::assertSame($accepted, $this->postCollector('results', $this->resultBody($result))->assertOk()->json());
        $row = HikvisionDiscoveryRequest::query()->firstOrFail();
        self::assertSame($result, $row->result);
        self::assertSame(CctvContract::hash($result), $row->result_hash);
        self::assertSame($result['inventory'], $this->getJson($created['reader_href'])->assertOk()->json('inventory'));
        self::assertNull($result['inventory']['inputs'][0]['camera_identity']);
        self::assertSame('unverified', $result['inventory']['inputs'][0]['association']);
        self::assertTrue($result['inventory']['inputs'][0]['online']);
        self::assertSame('original-canonical-recorder-serial', $this->recorder->fresh()->serial_number);
        self::assertSame(0, CctvSource::query()->count());
        $this->getJson('/security-devices/devices/'.$this->recorder->id.'/live/hikvision/source')->assertNotFound();
        $this->getJson('/security-devices/devices/'.$this->recorder->id.'/live/hikvision/source/discoveries/'.$row->uuid)->assertNotFound();
        self::assertNull($row->operation);
        self::assertSame('released', CredentialLeaseGrant::query()->firstOrFail()->status);
        self::assertSame(1, AuditLog::query()->where('action', 'cctv.hikvision.discovery.result_recorded')->count());
        self::assertSame(1, $this->issued);
        $changed = $result;
        $changed['inventory']['inputs'][0]['online'] = false;
        $this->postCollector('results', $this->resultBody($changed))->assertStatus(409);
        self::assertSame($result, $row->fresh()->result);
        Http::assertNothingSent();
    }

    public function test_camera_only_collectors_skip_recorder_work_and_do_not_starve_camera_work(): void
    {
        $legacy = $this->body();
        unset($legacy['capability']['recorder_profile']);
        for ($i = 0; $i < 17; $i++) {
            $this->create()->assertOk();
            // Respect the unchanged ten-per-minute manager admission limit.
            $this->travel(7)->seconds();
        }
        self::assertSame([], $this->postCollector('work', $legacy)->assertOk()->json('operations'));
        self::assertSame(0, $this->issued);
        $camera = Device::factory()->security()->create(['category' => 'cctv', 'subcategory' => 'dome_camera', 'provider' => 'hikvision', 'ip_address' => '10.44.0.10']);
        DeviceAssignment::query()->create(['device_id' => $camera->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
        $this->reference->update(['capabilities' => [HikvisionRecorderDiscoveryContract::CAPABILITY, 'cctv:hikvision:discovery:read_only']]);
        $this->postJson('/security-devices/devices/'.$camera->id.'/hikvision-discoveries', $this->input())->assertOk();
        $legacy['request_key'] = (string) Str::uuid();
        $this->leaseCapabilities = ['cctv:hikvision:discovery:read_only'];
        $op = $this->postCollector('work', $legacy)->assertOk()->json('operations.0');
        self::assertSame('discover_hikvision', $op['operation']);
        self::assertSame(1, $this->issued);
        self::assertSame(17, HikvisionDiscoveryRequest::query()->where('device_id', $this->recorder->id)->where('status', 'queued')->count());
    }

    public function test_recorder_permission_cannot_be_replaced_by_camera_permission_or_browser_kind(): void
    {
        $this->reference->update(['capabilities' => ['cctv:hikvision:discovery:read_only']]);
        $this->create()->assertStatus(503);
        self::assertSame(0, $this->issued);
        self::assertSame(0, HikvisionDiscoveryRequest::query()->count());
        $this->reference->update(['capabilities' => [HikvisionRecorderDiscoveryContract::CAPABILITY]]);
        $this->create($this->input(['source_kind' => 'direct_camera']))->assertStatus(400);
        $this->create($this->input(['expected_source_revision' => 1]))->assertStatus(409);
        $this->create()->assertOk();
        $wrong = $this->body();
        $wrong['capability']['recorder_profile'] = 'hikvision_isapi_https_v1';
        $this->postCollector('work', $wrong)->assertStatus(400);
        $wrong['capability']['recorder_profile'] = null;
        self::assertSame([], $this->postCollector('work', $wrong)->assertOk()->json('operations'));
        self::assertSame(0, $this->issued);
    }

    public function test_camera_result_cannot_be_committed_as_recorder_observation(): void
    {
        $op = $this->dispatched();
        $result = $this->observation($op);
        $result['version'] = 1;
        $result['operation'] = 'discover_hikvision';
        unset($result['inventory']['inputs'], $result['inventory']['response_sha256'], $result['inventory']['channels'][0]['input_kind']);
        $result['inventory']['channels'][0]['fps100'] = 2500;
        $this->postCollector('results', $this->resultBody($result))->assertStatus(409);
        self::assertNull(HikvisionDiscoveryRequest::query()->firstOrFail()->result_hash);
        self::assertSame('issued', CredentialLeaseGrant::query()->firstOrFail()->status);
        $this->postCollector('results', $this->resultBody($this->observation($op)))->assertOk();
    }

    public static function changes(): array
    {
        return array_map(fn ($v) => [$v], ['kind', 'recorder_site', 'network', 'credential_version', 'credential_capability', 'actor', 'collector_revoked']);
    }

    private function change(string $kind): void
    {
        match ($kind) {
            'kind' => $this->recorder->update(['subcategory' => 'dome_camera']),
            'recorder_site' => DeviceAssignment::query()->where('device_id', $this->recorder->id)->update(['released_at' => now()]),
            'network' => $this->scope->update(['cidrs' => ['10.55.0.0/24']]),
            'credential_version' => $this->reference->update(['version' => 2]),
            'credential_capability' => $this->reference->update(['capabilities' => ['cctv:hikvision:discovery:read_only']]),
            'actor' => $this->actor->roles()->detach(),
            'collector_revoked' => $this->collector->update(['revoked_at' => now(), 'status' => 'revoked']),
        };
    }

    #[DataProvider('changes')]
    public function test_changed_authority_during_external_retrieval_never_publishes_recorder_work(string $kind): void
    {
        $this->create()->assertOk();
        $this->duringIssue = fn () => $this->change($kind);
        $response = $this->postCollector('work', $this->body());
        self::assertContains($response->status(), [200, 404, 503]);
        if ($response->status() === 200) {
            self::assertSame([], $response->json('operations'));
        }
        $row = HikvisionDiscoveryRequest::query()->firstOrFail();
        self::assertContains($row->status, ['revoked', 'failed']);
        self::assertNull($row->operation);
        self::assertSame(1, $this->issued);
        self::assertContains('synthetic-hk-lease-1', $this->revoked);
    }

    #[DataProvider('changes')]
    public function test_changed_authority_denies_result_and_lost_acknowledgement_replay(string $kind): void
    {
        $op = $this->dispatched();
        $body = $this->resultBody($this->observation($op));
        $this->postCollector('results', $body)->assertOk();
        $row = HikvisionDiscoveryRequest::query()->firstOrFail();
        $this->change($kind);
        self::assertContains($this->postCollector('results', $body)->status(), [401, 404, 409, 503]);
        self::assertSame($row->result_hash, $row->fresh()->result_hash);
        self::assertSame(1, AuditLog::query()->where('action', 'cctv.hikvision.discovery.result_recorded')->count());
    }

    public function test_recorder_expiry_retains_identity_and_erases_sealed_material(): void
    {
        $op = $this->dispatched();
        $row = HikvisionDiscoveryRequest::query()->firstOrFail();
        $hash = $row->operation_hash;
        $this->travelTo($row->authority_until);
        self::assertSame(1, app(HikvisionDiscoveryRequests::class)->expirePending());
        self::assertSame('expired', $row->fresh()->status);
        self::assertNull($row->fresh()->operation);
        self::assertSame($hash, $row->fresh()->operation_hash);
        self::assertSame('released', CredentialLeaseGrant::query()->firstOrFail()->status);
        self::assertSame([], $this->postCollector('work', $this->body())->assertOk()->json('operations'));
        self::assertSame(1, $this->issued);
    }

    public function test_input_status_cannot_publish_camera_identity_and_unknown_facts_remain_null(): void
    {
        $op = $this->dispatched();
        $result = $this->observation($op);
        $bad = $result;
        $bad['inventory']['inputs'][0]['camera_identity'] = ['serial' => 'inferred-camera'];
        $this->postCollector('results', $this->resultBody($bad))->assertStatus(400);
        self::assertNull(HikvisionDiscoveryRequest::query()->firstOrFail()->result_hash);
        foreach (['address', 'management_port', 'source_input_id', 'online'] as $field) {
            $result['inventory']['inputs'][0][$field] = null;
        }
        $this->postCollector('results', $this->resultBody($result))->assertOk();
        self::assertSame($result, HikvisionDiscoveryRequest::query()->firstOrFail()->result);
        self::assertSame(0, CctvSource::query()->count());
    }

    private function failAudit(string $action, callable $operation): mixed
    {
        // Keep every model's original listeners; disable only this injected failure.
        $enabled = true;
        AuditLog::creating(function (AuditLog $row) use ($action, &$enabled): void {
            if ($enabled && $row->action === $action) {
                throw new \RuntimeException('Synthetic audit storage unavailable.');
            }
        });
        try {
            return $operation();
        } finally {
            $enabled = false;
        }
    }

    public function test_audit_failure_rolls_back_creation_and_result_then_exact_retry_commits_once(): void
    {
        $response = $this->failAudit('cctv.hikvision.discovery.requested', fn () => $this->create());
        $response->assertStatus(503);
        self::assertSame(['message' => 'temporarily_unavailable'], $response->json());
        self::assertSame(0, HikvisionDiscoveryRequest::query()->count());
        $op = $this->dispatched();
        $body = $this->resultBody($this->observation($op));
        $this->failAudit('cctv.hikvision.discovery.result_recorded', fn () => $this->postCollector('results', $body))->assertStatus(503);
        $row = HikvisionDiscoveryRequest::query()->first();
        self::assertNull($row->result_hash);
        self::assertSame('dispatched', $row->status);
        self::assertSame($op, $row->operation);
        self::assertSame('issued', CredentialLeaseGrant::query()->first()->status);
        $accepted = $this->postCollector('results', $body)->assertOk()->json();
        self::assertSame($accepted, $this->postCollector('results', $body)->assertOk()->json());
        self::assertSame(1, AuditLog::query()->where('action', 'cctv.hikvision.discovery.result_recorded')->count());
        self::assertSame('released', CredentialLeaseGrant::query()->first()->status);
    }

    public function test_provider_release_failure_preserves_committed_observation_and_existing_containment_can_retry(): void
    {
        $op = $this->dispatched();
        $body = $this->resultBody($this->observation($op));
        $this->revokeUnavailable = true;
        $accepted = $this->postCollector('results', $body)->assertOk()->json();
        $grant = CredentialLeaseGrant::query()->first();
        self::assertSame('revoke_pending', $grant->status);
        self::assertNotNull($grant->lease_id);
        self::assertSame($accepted, $this->postCollector('results', $body)->assertOk()->json());
        self::assertSame(1, $grant->fresh()->revoke_attempts);
        $this->revokeUnavailable = false;
        app(CredentialLeaseLifecycleService::class)->releaseGrant((int) $grant->id);
        self::assertSame('released', $grant->fresh()->status);
        self::assertNull($grant->fresh()->lease_id);
        self::assertSame('observed', HikvisionDiscoveryRequest::query()->first()->status);
    }

    #[DataProvider('changes')]
    public function test_changed_authority_denies_uncommitted_recorder_result(string $kind): void
    {
        $op = $this->dispatched();
        $body = $this->resultBody($this->observation($op));
        $this->change($kind);
        self::assertContains($this->postCollector('results', $body)->status(), [401, 404, 409, 503]);
        self::assertNull(HikvisionDiscoveryRequest::query()->firstOrFail()->result_hash);
        self::assertSame('issued', CredentialLeaseGrant::query()->firstOrFail()->status);
        self::assertSame(0, AuditLog::query()->where('action', 'cctv.hikvision.discovery.result_recorded')->count());
    }

    public function test_recorder_management_projection_has_distinct_capability_choices_without_live_or_reference_authority(): void
    {
        $href = '/security-devices/devices/'.$this->recorder->id.'/live/source';
        $api = '/security-devices/devices/'.$this->recorder->id.'/live/hikvision/setup';
        $cameraReference = $this->reference->replicate(['reference_uuid']);
        $cameraReference->reference_key = 'camera-only-reference';
        $cameraReference->secret_manager_reference = 'vault://synthetic/camera-management-reference';
        $cameraReference->secret_manager_reference_hash = hash('sha256', $cameraReference->secret_manager_reference);
        $cameraReference->capabilities = ['cctv:hikvision:discovery:read_only'];
        $cameraReference->save();
        $response = $this->getJson($href)->assertOk();
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        $body = $response->json();
        self::assertSame('hikvision_recorder', $body['provider']);
        self::assertSame((int) $this->recorder->id, $body['recorder_id']);
        self::assertSame(0, $body['revision']);
        self::assertSame(['can_discover' => true], $body['readiness']);
        self::assertSame([], $body['recent_requests']);
        self::assertSame([['id' => (int) $this->reference->id, 'name' => $this->reference->reference_key]], $body['choices']['credentials']['items']);
        self::assertSame($body, $this->getJson($api)->assertOk()->json());
        $pageHeaders = $this->inertiaPartialHeaders('security-devices/cctv/source', 'provider,recorder_id');
        unset($pageHeaders['X-Inertia-Partial-Component'], $pageHeaders['X-Inertia-Partial-Data']);
        $this->get($href, $pageHeaders)->assertOk()->assertJsonPath('component', 'security-devices/cctv/source')->assertJsonPath('props.provider', 'hikvision_recorder')->assertJsonPath('props.recorder_id', (int) $this->recorder->id);
        self::assertArrayNotHasKey('source', $body);
        self::assertArrayNotHasKey('active_source', $body);
        self::assertStringNotContainsString('secret_manager', json_encode($body));
        self::assertStringNotContainsString('sealed_material', json_encode($body));
        $navigation = app(CctvNavigation::class)->forDevice($this->actor, $this->recorder);
        self::assertSame(['live_href' => null, 'setup_href' => $href, 'reference_href' => null, 'setup_kind' => 'recorder'], $navigation);
        config(['cctv.hikvision_discovery_enabled' => false]);
        self::assertFalse($this->getJson($api)->assertOk()->json('readiness.can_discover'));
        self::assertSame(0, $this->issued);
        self::assertSame(0, CctvSource::query()->count());
    }

    public function test_recorder_management_history_is_bounded_actor_private_and_original_reader_owned(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->create()->assertOk();
            $this->travel(7)->seconds();
        }
        $other = User::factory()->create();
        $other->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $foreign = $this->actingAs($other)->create($this->input(['expected_actor_id' => (int) $other->id]))->assertOk()->json();
        $this->actingAs($this->actor);
        $body = $this->getJson('/security-devices/devices/'.$this->recorder->id.'/live/hikvision/setup')->assertOk()->json();
        self::assertCount(10, $body['recent_requests']);
        self::assertNotContains($foreign['request_uuid'], array_column($body['recent_requests'], 'request_uuid'));
        self::assertSame(HikvisionDiscoveryRequest::query()->where('actor_id', $this->actor->id)->orderByDesc('id')->first()->uuid, $body['recent_requests'][0]['request_uuid']);
        foreach ($body['recent_requests'] as $row) {
            self::assertSame(['request_uuid', 'status', 'created_at', 'reader_href'], array_keys($row));
            self::assertSame($row['request_uuid'], $this->getJson($row['reader_href'])->assertOk()->json('request_uuid'));
        }
        $this->getJson($foreign['reader_href'])->assertNotFound();
        self::assertSame(0, $this->issued);
    }

    public function test_recorder_management_directory_cursor_cannot_cross_camera_schema_or_changed_collector(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $reference = $this->reference->replicate(['reference_uuid']);
            $reference->reference_key = 'recorder-reference-'.$i;
            $reference->secret_manager_reference = 'vault://synthetic/recorder-management-reference-'.$i;
            $reference->secret_manager_reference_hash = hash('sha256', $reference->secret_manager_reference);
            $reference->save();
        }
        $href = '/security-devices/devices/'.$this->recorder->id.'/live/hikvision/setup?choice=credentials';
        $body = $this->getJson($href)->assertOk()->json();
        self::assertCount(20, $body['choices']['credentials']['items']);
        $next = $body['choices']['credentials']['next_href'];
        self::assertIsString($next);
        self::assertCount(2, $this->getJson($next)->assertOk()->json('choices.credentials.items'));
        $this->recorder->update(['subcategory' => 'dome_camera']);
        $this->getJson($next)->assertStatus(400);
        $this->recorder->update(['subcategory' => 'nvr']);
        $this->collector->update(['revoked_at' => now(), 'status' => 'revoked']);
        $body = $this->getJson($href)->assertOk()->json();
        self::assertSame([], $body['choices']['collectors']['items']);
        self::assertSame([], $body['choices']['scopes']['items']);
        $this->getJson('/security-devices/devices/'.$this->recorder->id.'/live/hikvision/setup?choice=scopes&collector_id='.$this->collector->id)->assertNotFound();
    }

    public function test_recorder_management_has_current_permission_site_and_provider_denial(): void
    {
        $href = '/security-devices/devices/'.$this->recorder->id.'/live/source';
        $this->recorder->update(['provider' => 'unifi']);
        $this->getJson($href)->assertNotFound();
        $this->recorder->update(['provider' => 'hikvision']);
        $this->actor->roles()->detach();
        self::assertContains($this->getJson($href)->status(), [403, 404]);
        self::assertNull(app(CctvNavigation::class)->forDevice($this->actor, $this->recorder)['setup_href']);
        $this->actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        DeviceAssignment::query()->where('device_id', $this->recorder->id)->update(['released_at' => now()]);
        $this->getJson($href)->assertNotFound();
        self::assertSame(0, $this->issued);
    }
}
