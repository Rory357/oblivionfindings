<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvRequestReceipt;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Models\HikvisionDiscoveryRequest;
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionDiscoveryAuthority;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionDiscoveryRequests;
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

final class HikvisionDiscoveryTest extends TestCase
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
        $this->camera = Device::factory()->security()->create(['category' => 'cctv', 'subcategory' => 'dome_camera', 'provider' => 'hikvision', 'ip_address' => '10.44.0.10', 'serial_number' => 'original-canonical-serial']);
        $computer = Device::factory()->itInfrastructure()->create();
        foreach ([$this->camera, $computer] as $device) {
            DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
        }
        $this->serverKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->collectorKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->collector = MonitoringCollector::factory()->create(['site_id' => $this->site->id, 'collector_device_id' => $computer->id, 'public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->collectorKey)), 'client_certificate_fingerprint' => str_repeat('a', 64), 'enrolled_at' => now()]);
        $this->scope = DiscoveryScope::factory()->create(['site_id' => $this->site->id, 'collector_id' => $this->collector->id, 'cidrs' => ['10.44.0.0/24'], 'port_bounds' => ['tcp' => [443]], 'protocols' => ['tls']]);
        $this->reference = CredentialReference::query()->create(['reference_key' => 'vault:hikvision/discovery', 'site_id' => $this->site->id, 'provider' => 'hikvision', 'purpose' => 'cctv_read_only', 'capabilities' => [HikvisionDiscoveryAuthority::CAPABILITY], 'secret_manager_reference' => 'private-camera-location', 'secret_manager_reference_hash' => str_repeat('b', 64), 'status' => 'active', 'rotation_status' => 'current', 'test_status' => 'passed', 'version' => 1]);
        config(['cctv.hikvision_discovery_enabled' => true, 'monitoring.collector.signing_secret_key' => base64_encode($this->serverKey), 'monitoring.collector.trusted_proxy_cidrs' => ['127.0.0.1/32'], 'monitoring.collector.allow_proxy_fingerprint_header' => true, 'monitoring.collector.replay_store' => 'array', 'monitoring.collector.allow_local_replay_store_for_tests' => true]);
        // Keep the real governed broker and lifecycle; substitute only the external
        // secret manager, so catalogue checks, lease records and audits run normally.
        $issue = function (SecretLeaseRequest $request): CredentialLease {
            $this->issued++;
            self::assertSame(0, DB::transactionLevel() - 1, 'External retrieval must run outside domain transactions (the test owns one rollback transaction).');
            self::assertSame((int) $this->site->id, $request->siteId);
            self::assertSame([HikvisionDiscoveryAuthority::CAPABILITY], $request->capabilities);
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

        return ['version' => 1, 'operation' => 'discover_hikvision', 'request_uuid' => $operation['request_uuid'], 'device_id' => (int) $this->camera->id, 'context_sha256' => $grant['context_sha256'], 'credential_binding_sha256' => $grant['credential_binding_sha256'], 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'status' => 'observed', 'inventory' => ['identity' => ['model' => 'synthetic-model', 'serial' => 'observed-camera-serial', 'device_id' => 'synthetic-vendor-id', 'firmware' => 'synthetic-version', 'firmware_build' => ''], 'channels' => [['id' => '101', 'input_id' => '1', 'name' => 'Reception', 'codec' => 'H265', 'enabled' => true, 'video_enabled' => true, 'width' => 1920, 'height' => 1080, 'fps100' => 2500, 'audio_enabled' => null]]], 'reason_code' => null];
    }

    private function resultBody(array $result, ?string $key = null): array
    {
        return ['collector_id' => $this->collector->collector_uuid, 'request_key' => $key ?? (string) Str::uuid(), 'results' => [$result]];
    }

    public function test_original_signed_dispatch_immutable_result_and_lost_response_recovery(): void
    {
        $input = $this->input();
        $created = $this->create($input)->assertOk()->json();
        self::assertSame('queued', $created['status']);
        self::assertTrue($created['current_context']);
        self::assertSame($created, $this->create(array_reverse($input, true))->assertOk()->json());
        self::assertSame($created, $this->getJson('/security-devices/cctv/hikvision-discoveries/requests/'.$input['request_key'].'?expected_actor_id='.$this->actor->id)->assertOk()->json());
        $this->create(array_replace($input, ['purpose' => 'Changed request under the same key.']))->assertStatus(409);
        $workInput = $this->body();
        $work = $this->postCollector('work', $workInput)->assertOk()->json();
        self::assertSame($work, $this->postCollector('work', $workInput)->assertOk()->json());
        self::assertSame($work['operations'], $this->postCollector('work', $this->body())->assertOk()->json('operations'));
        self::assertSame(1, $this->issued);
        self::assertCount(1, CredentialLeaseGrant::query()->get());
        $op = $work['operations'][0];
        $raw = base64_decode($op['grant']['payload'], true);
        $grant = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($op['grant']['signature'], true), $raw, sodium_crypto_sign_publickey_from_secretkey($this->serverKey)));
        $boxPair = sodium_crypto_box_keypair_from_secretkey_and_publickey(sodium_crypto_sign_ed25519_sk_to_curve25519($this->collectorKey), sodium_crypto_sign_ed25519_pk_to_curve25519(sodium_crypto_sign_publickey_from_secretkey($this->collectorKey)));
        $sealed = json_decode(sodium_crypto_box_seal_open(base64_decode($op['credential_lease']['sealed_material'], true), $boxPair), true, 32, JSON_THROW_ON_ERROR);
        $source = $sealed['material']['source_json'];
        self::assertSame(hash('sha256', $source), $grant['source_sha256']);
        self::assertSame('synthetic-private-password', json_decode($source, true, 8, JSON_THROW_ON_ERROR)['password']);
        $stored = DB::table('cctv_hikvision_discovery_requests')->first();
        self::assertStringNotContainsString('synthetic-private-password', (string) $stored->operation);
        self::assertStringNotContainsString('10.44.0.10', (string) $stored->configuration);
        self::assertSame('issued', CredentialLeaseGrant::query()->first()->status);
        $result = $this->observation($op);
        $upload = $this->resultBody($result);
        $accepted = $this->postCollector('results', $upload)->assertOk()->json();
        self::assertSame([['request_uuid' => $created['request_uuid'], 'result_sha256' => CctvContract::hash($result)]], $accepted['accepted']);
        self::assertSame('released', CredentialLeaseGrant::query()->first()->status);
        self::assertNull(CredentialLeaseGrant::query()->first()->lease_id);
        $audit = AuditLog::query()->where('action', 'cctv.hikvision.discovery.result_recorded')->firstOrFail();
        self::assertNull($audit->ip_address);
        self::assertNull($audit->user_agent);
        self::assertSame(['status' => 'observed'], $audit->meta['after']);
        $this->travel(20)->seconds();
        self::assertSame($accepted, $this->postCollector('results', $upload)->assertOk()->json());
        self::assertSame($accepted, $this->postCollector('results', $this->resultBody($result))->assertOk()->json());
        $dto = $this->getJson($created['reader_href'])->assertOk()->json();
        self::assertSame('observed', $dto['status']);
        self::assertNull($dto['inventory']['channels'][0]['audio_enabled']);
        self::assertSame('H265', $dto['inventory']['channels'][0]['codec']);
        self::assertSame('original-canonical-serial', $this->camera->fresh()->serial_number);
        self::assertSame(0, CctvSource::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', 'cctv.hikvision.discovery.result_recorded')->count());
        self::assertNull(HikvisionDiscoveryRequest::query()->first()->operation);
        $changed = $result;
        $changed['inventory']['identity']['model'] = 'different-model';
        $this->postCollector('results', $this->resultBody($changed))->assertStatus(409);
        $this->postCollector('work', $workInput)->assertStatus(409);
        Http::assertNothingSent();
    }

    public static function invalidAuthority(): array
    {
        return array_map(fn ($v) => [$v], ['camera_provider', 'camera_retired', 'camera_ip', 'network', 'exclusion', 'scope_site', 'scope_collector', 'scope_protocol', 'collector_site', 'collector_revoked', 'collector_device_retired', 'credential_site', 'credential_provider', 'credential_purpose', 'credential_status', 'credential_test', 'credential_rotation', 'credential_capability', 'source_revision']);
    }

    private function change(string $kind): void
    {
        match ($kind) {
            'camera_provider' => $this->camera->update(['provider' => 'unifi']),
            'camera_retired' => $this->camera->update(['status' => 'decommissioned']),
            'camera_ip' => $this->camera->update(['ip_address' => '10.44.0.11']),
            'network' => $this->scope->update(['cidrs' => ['10.55.0.0/24']]),
            'exclusion' => $this->scope->update(['exclusions' => ['10.44.0.10/32']]),
            'scope_site' => $this->scope->update(['site_id' => Site::factory()->create()->id]),
            'scope_collector' => $this->scope->update(['collector_id' => null]),
            'scope_protocol' => $this->scope->update(['protocols' => ['tcp']]),
            'collector_device_retired' => Device::query()->findOrFail($this->collector->collector_device_id)->update(['status' => 'lost']),
            'collector_site' => $this->collector->update(['site_id' => Site::factory()->create()->id]),
            'collector_revoked' => $this->collector->update(['revoked_at' => now(), 'status' => 'revoked']),
            'collector_certificate' => $this->collector->update(['client_certificate_fingerprint' => str_repeat('d', 64)]),
            'collector_key' => $this->collector->update(['public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]),
            'credential_site' => $this->reference->update(['site_id' => Site::factory()->create()->id]),
            'credential_provider' => $this->reference->update(['provider' => 'unifi']),
            'credential_purpose' => $this->reference->update(['purpose' => 'management']),
            'credential_status' => $this->reference->update(['status' => 'suspended']),
            'credential_test' => $this->reference->update(['test_status' => 'untested']),
            'credential_rotation' => $this->reference->update(['rotation_status' => 'overdue']),
            'credential_capability' => $this->reference->update(['capabilities' => ['snmp:read']]),
            'credential_version' => $this->reference->update(['version' => 2]),
            'actor' => $this->actor->roles()->detach(),
            'camera_site' => $this->moveCamera(),
            'source_revision' => null,
        };
    }

    private function moveCamera(): void
    {
        DeviceAssignment::query()->where('device_id', $this->camera->id)->update(['released_at' => now()]);
        DeviceAssignment::query()->create(['device_id' => $this->camera->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => Site::factory()->create()->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
    }

    #[DataProvider('invalidAuthority')]
    public function test_current_canonical_authority_required_before_request_and_secret_retrieval(string $kind): void
    {
        $this->change($kind);
        $input = $this->input($kind === 'source_revision' ? ['expected_source_revision' => 1] : []);
        $response = $this->create($input);
        self::assertContains($response->status(), [404, 409, 503]);
        self::assertSame(0, $this->issued);
        self::assertSame(0, HikvisionDiscoveryRequest::query()->count());
    }

    public static function revocations(): array
    {
        return array_map(fn ($v) => [$v], ['network', 'credential_version', 'actor', 'camera_site', 'collector_certificate', 'collector_key', 'collector_device_retired']);
    }

    #[DataProvider('revocations')]
    public function test_context_changes_during_external_retrieval_never_publish_signed_work(string $kind): void
    {
        $this->create()->assertOk();
        $this->duringIssue = fn () => $this->change($kind);
        $response = $this->postCollector('work', $this->body());
        self::assertContains($response->status(), [200, 404, 503]);
        if ($response->status() === 200) {
            self::assertSame([], $response->json('operations'));
        }
        $row = HikvisionDiscoveryRequest::query()->first();
        self::assertContains($row->status, ['revoked', 'failed']);
        self::assertNull($row->operation);
        self::assertSame(1, $this->issued);
        self::assertContains('synthetic-hk-lease-1', $this->revoked);
    }

    #[DataProvider('revocations')]
    public function test_changed_context_denies_existing_work_and_result_commitment(string $kind): void
    {
        $work = $this->body();
        $this->create()->assertOk();
        $operation = $this->postCollector('work', $work)->assertOk()->json('operations.0');
        $result = $this->observation($operation);
        $this->change($kind);
        self::assertContains($this->postCollector('work', $work)->status(), [401, 404, 409, 503]);
        self::assertContains($this->postCollector('results', $this->resultBody($result))->status(), [401, 404, 409, 503]);
        self::assertNull(HikvisionDiscoveryRequest::query()->first()->result_hash);
        self::assertSame(1, $this->issued);
    }

    public function test_pending_preparation_is_single_use_and_lost_preparation_expires(): void
    {
        $this->create()->assertOk();
        $body = $this->body();
        $this->duringIssue = function () use ($body): void {
            self::assertSame(503, $this->postCollector('work', $body)->status());
            self::assertSame([], $this->postCollector('work', $this->body())->assertOk()->json('operations'));
        };
        $this->postCollector('work', $body)->assertOk();
        self::assertSame(1, $this->issued);
        $this->create()->assertOk();
        $row = HikvisionDiscoveryRequest::query()->where('status', 'queued')->first();
        $row->status = 'preparing';
        $row->preparation_key = (string) Str::uuid();
        $row->preparation_until = now()->subSecond();
        $row->save();
        app(HikvisionDiscoveryRequests::class)->expirePending();
        self::assertSame('preparation_lost', $row->fresh()->reason_code);
    }

    public function test_expiry_disable_unavailable_and_closed_capability_do_not_issue(): void
    {
        $created = $this->create()->assertOk()->json();
        self::assertSame([], $this->postCollector('work', $this->body(available: false))->assertOk()->json('operations'));
        $body = $this->body();
        $body['capability']['profile'] = 'rtsp';
        $this->postCollector('work', $body)->assertStatus(400);
        $body = $this->body();
        $body['tenant_id'] = 1;
        $this->postCollector('work', $body)->assertStatus(400);
        config(['cctv.hikvision_discovery_enabled' => false]);
        self::assertSame([], $this->postCollector('work', $this->body())->assertOk()->json('operations'));
        $this->create()->assertStatus(503);
        $this->travel(6)->minutes();
        self::assertSame('expired', $this->getJson($created['reader_href'])->assertOk()->json('status'));
        self::assertSame(0, $this->issued);
    }

    public function test_expired_dispatch_erases_sealed_operation_and_retains_request_identity(): void
    {
        $operation = $this->dispatched();
        $result = $this->observation($operation);
        $this->travel(16)->seconds();
        $this->postCollector('results', $this->resultBody($result))->assertStatus(409);
        $this->artisan('cctv:expire-live-sessions')->assertExitCode(0);
        $row = HikvisionDiscoveryRequest::query()->first();
        self::assertSame('expired', $row->status);
        self::assertNull($row->operation);
        self::assertNotNull($row->operation_hash);
        self::assertSame(1, CctvRequestReceipt::query()->where('owner', 'hikvision_work:'.$this->collector->id)->count());
        self::assertNull(CctvRequestReceipt::query()->where('owner', 'hikvision_work:'.$this->collector->id)->first()->response);
    }

    public function test_reader_and_recovery_are_same_actor_and_current_site_only(): void
    {
        $input = $this->input();
        $created = $this->create($input)->assertOk()->json();
        $other = User::factory()->create();
        $other->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->actingAs($other);
        $this->getJson($created['reader_href'])->assertNotFound();
        $this->getJson('/security-devices/cctv/hikvision-discoveries/requests/'.$input['request_key'].'?expected_actor_id='.$other->id)->assertNotFound();
        $this->actingAs($this->actor);
        $this->moveCamera();
        $this->getJson($created['reader_href'])->assertNotFound();
        $this->create($input)->assertNotFound();
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

    public function test_credential_retrieval_failure_is_durable_and_never_returns_exception_details_or_reissues(): void
    {
        $this->create()->assertOk();
        $body = $this->body();
        $this->duringIssue = function (): void {
            throw new \RuntimeException('Private credential manager detail that must not be disclosed.');
        };
        $reply = $this->postCollector('work', $body)->assertStatus(503);
        self::assertStringNotContainsString('Private credential manager', $reply->getContent());
        self::assertSame('credential_unavailable', HikvisionDiscoveryRequest::query()->first()->reason_code);
        self::assertNull(HikvisionDiscoveryRequest::query()->first()->operation);
        self::assertSame([], $this->postCollector('work', $body)->assertOk()->json('operations'));
        self::assertSame(1, $this->issued);
    }

    public function test_result_time_partial_unknown_fields_and_correlation_are_denied_before_commitment(): void
    {
        $op = $this->dispatched();
        $valid = $this->observation($op);
        foreach ([now()->utc()->subSecond()->format('Y-m-d\TH:i:s\Z'), now()->utc()->addSecond()->format('Y-m-d\TH:i:s\Z'), now()->utc()->addSeconds(15)->format('Y-m-d\TH:i:s\Z')] as $time) {
            $bad = $valid;
            $bad['observed_at'] = $time;
            $this->postCollector('results', $this->resultBody($bad))->assertStatus(409);
        }
        $bad = $valid;
        $bad['inventory'] = null;
        $this->postCollector('results', $this->resultBody($bad))->assertStatus(400);
        $bad = $valid;
        $bad['provider_password'] = 'unapproved';
        $this->postCollector('results', $this->resultBody($bad))->assertStatus(400);
        $bad = $valid;
        $bad['context_sha256'] = str_repeat('f', 64);
        $this->postCollector('results', $this->resultBody($bad))->assertStatus(409);
        $body = $this->resultBody($valid);
        $body['results'][] = $valid;
        $this->postCollector('results', $body)->assertStatus(400);
        self::assertNull(HikvisionDiscoveryRequest::query()->first()->result_hash);
        self::assertSame(0, AuditLog::query()->where('action', 'cctv.hikvision.discovery.result_recorded')->count());
        $this->postCollector('results', $this->resultBody($valid))->assertOk();
    }

    #[DataProvider('revocations')]
    public function test_committed_result_replay_requires_current_context_even_after_a_lost_acknowledgement(string $kind): void
    {
        $op = $this->dispatched();
        $body = $this->resultBody($this->observation($op));
        $this->postCollector('results', $body)->assertOk();
        $hash = HikvisionDiscoveryRequest::query()->first()->result_hash;
        $this->change($kind);
        self::assertContains($this->postCollector('results', $body)->status(), [401, 404, 409, 503]);
        self::assertSame($hash, HikvisionDiscoveryRequest::query()->first()->result_hash);
        self::assertSame(1, AuditLog::query()->where('action', 'cctv.hikvision.discovery.result_recorded')->count());
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
}
