<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvRequestReceipt;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Models\HikvisionDiscoveryRequest;
use App\Domain\SecurityDevices\Cctv\Services\CctvAuthority;
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionDiscoveryAuthority;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionSourcePolicy;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
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

final class HikvisionSourceMappingTest extends TestCase
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
        config(['cctv.hikvision_mapping_enabled' => true, 'cctv.hikvision_supported_profiles' => [$this->support()]]);
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

    public function test_missing_and_unavailable_device_routes_share_the_same_private_denial(): void
    {
        $request = $this->commit();
        $body = $this->mapping($request);
        $missingId = (int) Device::query()->max('id') + 1;
        $missing = $this->postJson('/security-devices/devices/'.$missingId.'/live/hikvision/source', $body)->assertNotFound()->assertExactJson(['message' => 'unavailable']);
        $existingOtherDevice = Device::query()->findOrFail($this->collector->collector_device_id);
        $other = $this->postJson('/security-devices/devices/'.$existingOtherDevice->id.'/live/hikvision/source', $body)->assertNotFound()->assertExactJson(['message' => 'unavailable']);
        self::assertSame($other->json(), $missing->json());
        self::assertStringContainsString('no-store', $missing->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $other->headers->get('Cache-Control'));
        self::assertSame(0, CctvSource::query()->count());
        Http::assertNothingSent();
    }

    public function test_original_discovery_context_bytes_keep_the_legacy_actor_and_source_revision_binding(): void
    {
        $request = $this->commit();
        $expected = DB::transaction(function () use ($request): string {
            $actor = app(CctvAuthority::class)->actor((int) $this->actor->id, true);
            $device = $this->camera->fresh();
            $collector = $this->collector->fresh();
            $reference = $this->reference->fresh();
            $deviceIdentity = [];
            foreach (['id', 'domain', 'category', 'subcategory', 'provider', 'model', 'serial_number', 'mac_address', 'ip_address', 'status'] as $field) {
                $deviceIdentity[$field] = $device->getRawOriginal($field);
            }
            $credential = CctvContract::hash(['id' => (int) $reference->id, 'uuid' => $reference->reference_uuid, 'site_id' => (int) $reference->site_id, 'provider' => $reference->provider, 'purpose' => $reference->purpose, 'version' => (int) $reference->version, 'status' => $reference->status->value, 'rotation' => $reference->rotation_status->value, 'test' => $reference->test_status->value, 'capabilities' => $reference->capabilities, 'pointer' => $reference->getRawOriginal('secret_manager_reference')]);

            // This is the original discovery context schema, independently
            // reconstructed from canonical records rather than the refactored
            // authority helper's returned hash or newly added source binding.
            return CctvContract::hash(['actor_id' => (int) $actor->id, 'actor' => CctvAuthority::fingerprint($actor), 'device' => $deviceIdentity, 'site_id' => (int) $this->site->id, 'collector' => ['id' => (int) $collector->id, 'uuid' => $collector->collector_uuid, 'device_id' => (int) $collector->collector_device_id, 'site_id' => (int) $collector->site_id, 'key' => $collector->public_key, 'certificate' => $collector->client_certificate_fingerprint, 'enrolled_at' => $collector->getRawOriginal('enrolled_at')], 'scope' => $this->scope->fresh()->snapshot(), 'source_revision' => 0, 'source_id' => null, 'source_fingerprint' => null, 'configuration' => $request->configuration, 'credential' => $credential]);
        });
        self::assertSame($expected, $request->context_fingerprint);
        self::assertSame($expected, $request->result['context_sha256']);
        $this->saveMapping($this->mapping($request))->assertOk();
        $source = CctvSource::query()->firstOrFail();
        $this->getJson('/security-devices/cctv/hikvision-discoveries/'.$request->uuid)->assertOk()->assertJsonPath('current_context', false);
        self::assertSame($expected, $request->fresh()->context_fingerprint);
        self::assertNotSame($expected, $source->configuration['binding_sha256']);
    }

    public function test_original_signed_observation_creates_retained_inactive_direct_source_without_nvr_or_vendor_write(): void
    {
        $request = $this->commit();
        $review = $this->getJson($this->href().'/discoveries/'.$request->uuid)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        self::assertSame($request->result_hash, $review['result_sha256']);
        self::assertSame('H.264', $review['channels'][0]['codec']);
        self::assertNull($review['channels'][0]['audio_enabled']);
        self::assertTrue($review['channels'][0]['can_select']);
        self::assertFalse($review['live_available']);
        $body = $this->mapping($request);
        $dto = $this->saveMapping($body)->assertOk()->json();
        self::assertFalse($dto['live_available']);
        self::assertFalse($dto['source']['enabled']);
        self::assertNull($dto['source']['recorder_device_id']);
        self::assertSame('not_requested', $dto['source']['stream_state']);
        self::assertNull($dto['source']['stream_verified_at']);
        self::assertSame('H264', $dto['source']['video_codec']);
        self::assertFalse($dto['source']['audio_enabled']);
        self::assertNull($dto['source']['channel']['audio_enabled']);
        $source = CctvSource::query()->firstOrFail();
        self::assertSame((int) $request->id, (int) $source->discovery_request_id);
        self::assertSame('hikvision', $source->provider);
        self::assertSame($request->result['inventory']['channels'][0], $source->configuration['channel']);
        self::assertSame($request->result_hash, $request->fresh()->result_hash);
        self::assertSame($dto['source'], $this->getJson($this->href())->assertOk()->json('source'));
        self::assertStringNotContainsString('10.44.0.10', DB::table('cctv_sources')->value('configuration'));
        $public = CctvContract::json($dto);
        foreach (['10.44.0.10', 'tls_pin', 'synthetic-private-password', 'private-camera-location', 'credential_reference_id'] as $private) {
            self::assertStringNotContainsString($private, $public);
        }
        self::assertSame(1, $this->issued, 'Mapping must not retrieve a new credential.');
        self::assertSame(1, AuditLog::query()->where('action', 'cctv.hikvision.source_mapped')->count());
        $this->postJson('/security-devices/devices/'.$this->camera->id.'/live-sessions', ['expected_actor_id' => (int) $this->actor->id, 'request_key' => (string) Str::uuid(), 'source_revision' => 1, 'purpose' => 'View this newly reviewed camera.'])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_immutable_command_replay_and_recovery_do_not_extend_observation_or_depend_on_creation_gate(): void
    {
        $request = $this->commit();
        $body = $this->mapping($request);
        $first = $this->saveMapping($body)->assertOk()->json();
        $this->travel(301)->seconds();
        config(['cctv.hikvision_mapping_enabled' => false]);
        $retry = $this->saveMapping(array_reverse($body, true))->assertOk()->json();
        self::assertSame($first['source'], $retry['source']);
        self::assertFalse($retry['mapping_enabled']);
        self::assertSame($retry, $this->recovery($body)->assertOk()->json());
        $this->saveMapping(array_replace($body, ['purpose' => 'Different intent under an existing key.']))->assertStatus(409);
        self::assertSame(1, CctvSource::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', 'cctv.hikvision.source_mapped')->count());
    }

    public function test_replacement_mapping_retains_original_receipt_and_never_reactivates_or_withdraws_previous_source(): void
    {
        $firstRequest = $this->commit();
        $body = $this->mapping($firstRequest);
        $first = $this->saveMapping($body)->assertOk()->json('source');
        $old = CctvSource::query()->firstOrFail();
        $old->update(['enabled' => true, 'stream_state' => 'available']);
        $secondRequest = $this->commit(null, 1);
        $second = $this->saveMapping($this->mapping($secondRequest))->assertOk()->json('source');
        self::assertSame(2, $second['revision']);
        self::assertFalse($second['enabled']);
        $recovered = $this->recovery($body)->assertOk()->json('source');
        self::assertSame($first['id'], $recovered['id']);
        self::assertFalse($recovered['current_mapping']);
        self::assertTrue($old->fresh()->enabled, 'Selection alone must not withdraw an existing activation.');
        self::assertSame(2, CctvSource::query()->count());
    }

    public function test_current_authorized_manager_reads_canonical_mapping_but_cannot_read_another_actors_private_command(): void
    {
        $request = $this->commit();
        $body = $this->mapping($request);
        $mapped = $this->saveMapping($body)->assertOk()->json('source');
        $other = User::factory()->create();
        $other->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->actingAs($other);
        self::assertSame($mapped, $this->getJson($this->href())->assertOk()->json('source'));
        $this->getJson($this->href().'/discoveries/'.$request->uuid)->assertNotFound();
        $this->getJson($this->href().'/requests/'.$body['request_key'].'?expected_actor_id='.$other->id)->assertNotFound();
        $this->saveMapping(array_replace($body, ['expected_actor_id' => (int) $other->id]))->assertNotFound();
        $this->actor->roles()->detach();
        self::assertSame($mapped, $this->getJson($this->href())->assertOk()->json('source'), 'Canonical accepted source does not inherit the creator login.');
    }

    public static function rejectedSelections(): array
    {
        return array_map(fn ($v) => [$v], ['model', 'serial', 'H265', 'HK264', 'disabled', 'video_disabled', 'input', 'firmware', 'build', 'empty_support', 'expired', 'future_clock', 'revision', 'result_hash', 'missing_channel']);
    }

    #[DataProvider('rejectedSelections')]
    public function test_new_mapping_denies_unmatched_unsupported_expired_or_changed_observation(string $kind): void
    {
        $request = $this->commit(function (array $r) use ($kind): array {
            match ($kind) {
                'model' => $r['inventory']['identity']['model'] = 'another-model',
                'serial' => $r['inventory']['identity']['serial'] = 'another-serial',
                'H265' => $r['inventory']['channels'][0]['codec'] = 'H265',
                'HK264' => $r['inventory']['channels'][0]['codec'] = 'HK.264',
                'disabled' => $r['inventory']['channels'][0]['enabled'] = false,
                'video_disabled' => $r['inventory']['channels'][0]['video_enabled'] = false,
                'input' => $r['inventory']['channels'][0]['input_id'] = '2',
                'firmware' => $r['inventory']['identity']['firmware'] = 'another-version',
                'build' => $r['inventory']['identity']['firmware_build'] = 'different-build',
                default => null,
            };

            return $r;
        });
        if ($kind === 'expired') {
            $this->travel(300)->seconds();
        } elseif ($kind === 'future_clock') {
            $this->travel(-1)->seconds();
        } elseif ($kind === 'empty_support') {
            config(['cctv.hikvision_supported_profiles' => []]);
        }
        $body = $this->mapping($request, match ($kind) {
            'revision' => ['expected_revision' => 1],
            'result_hash' => ['expected_result_sha256' => str_repeat('d', 64)],
            'missing_channel' => ['channel_id' => '102'],
            default => [],
        });
        $this->saveMapping($body)->assertStatus($kind === 'future_clock' ? 404 : 409);
        self::assertSame(0, CctvSource::query()->count());
        self::assertSame(0, CctvRequestReceipt::query()->where('owner', 'hikvision_source:'.$this->actor->id.':'.$this->camera->id)->count());
        if (! in_array($kind, ['revision', 'result_hash', 'missing_channel', 'future_clock'])) {
            $this->getJson($this->href().'/discoveries/'.$request->uuid)->assertOk()->assertJsonPath('channels.0.can_select', false);
        }
    }

    public static function contextLosses(): array
    {
        return array_map(fn ($v) => [$v], ['ip', 'identity', 'retired', 'credential_version', 'credential_revoked', 'scope', 'collector_key', 'collector_device', 'site', 'actor', 'support']);
    }

    #[DataProvider('contextLosses')]
    public function test_saved_source_and_original_receipt_require_current_authority_and_support(string $kind): void
    {
        $request = $this->commit();
        $body = $this->mapping($request);
        $this->saveMapping($body)->assertOk();
        match ($kind) {
            'ip' => $this->camera->update(['ip_address' => '10.44.0.11']),
            'identity' => $this->camera->update(['serial_number' => 'changed-serial']),
            'retired' => $this->camera->update(['status' => 'decommissioned']),
            'credential_version' => $this->reference->update(['version' => 2]),
            'credential_revoked' => $this->reference->update(['status' => 'revoked']),
            'scope' => $this->scope->update(['cidrs' => ['10.45.0.0/24']]),
            'collector_key' => $this->collector->update(['public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]),
            'collector_device' => Device::query()->findOrFail($this->collector->collector_device_id)->update(['status' => 'decommissioned']),
            'site' => DeviceAssignment::query()->where('device_id', $this->camera->id)->update(['assignable_id' => Site::factory()->create()->id]),
            'actor' => $this->actor->roles()->detach(),
            'support' => config(['cctv.hikvision_supported_profiles' => []]),
        };
        foreach ([$this->getJson($this->href()), $this->recovery($body), $this->saveMapping($body)] as $response) {
            self::assertContains($response->status(), [403, 404, 409, 503]);
        }
        self::assertSame(1, CctvSource::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', 'cctv.hikvision.source_mapped')->count());
    }

    public function test_exact_support_configuration_failure_is_generic_and_cannot_save_a_source(): void
    {
        $request = $this->commit();
        config(['cctv.hikvision_supported_profiles' => [$this->support(), $this->support()]]);
        $this->saveMapping($this->mapping($request))->assertStatus(503)->assertExactJson(['message' => 'temporarily_unavailable']);
        $this->getJson($this->href().'/discoveries/'.$request->uuid)->assertStatus(503);
        self::assertSame(0, CctvSource::query()->count());
    }

    public function test_camera_observation_reclassified_as_nvr_cannot_establish_a_direct_camera_or_proxy_association(): void
    {
        $this->reference->update(['capabilities' => [HikvisionDiscoveryAuthority::CAPABILITY, 'cctv:hikvision:recorder:discovery:read_only']]);
        $request = $this->commit();
        $this->camera->update(['subcategory' => 'nvr']);
        $this->saveMapping($this->mapping($request))->assertStatus(409);
        $this->getJson($this->href())->assertNotFound();
        $this->getJson($this->href().'/discoveries/'.$request->uuid)->assertNotFound();
        self::assertSame(0, CctvSource::query()->count());
    }

    public function test_audit_failure_atomically_rolls_back_source_and_receipt_then_original_retry_commits_once(): void
    {
        $request = $this->commit();
        $body = $this->mapping($request);
        $enabled = true;
        AuditLog::creating(function (AuditLog $row) use (&$enabled): void {
            if ($enabled && $row->action === 'cctv.hikvision.source_mapped') {
                throw new \RuntimeException('Synthetic audit storage unavailable.');
            }
        });
        try {
            $this->saveMapping($body)->assertStatus(503)->assertExactJson(['message' => 'temporarily_unavailable']);
        } finally {
            $enabled = false;
        }
        self::assertSame(0, CctvSource::query()->count());
        self::assertSame(0, CctvRequestReceipt::query()->where('owner', 'hikvision_source:'.$this->actor->id.':'.$this->camera->id)->count());
        $this->recovery($body)->assertNotFound();
        $this->saveMapping($body)->assertOk();
        $this->recovery($body)->assertOk();
        self::assertSame(1, CctvSource::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', 'cctv.hikvision.source_mapped')->count());
    }

    public function test_original_mapping_is_immutable_retained_and_corrupt_origin_is_denied(): void
    {
        $request = $this->commit();
        $body = $this->mapping($request);
        $this->saveMapping($body)->assertOk();
        $source = CctvSource::query()->firstOrFail();
        foreach (['provider' => 'unifi', 'revision' => 2, 'fingerprint' => str_repeat('d', 64), 'actor_id' => 99999, 'configuration' => ['changed' => true], 'recorder_device_id' => (int) $this->camera->id, 'discovery_request_id' => null] as $key => $value) {
            try {
                $source->fresh()->update([$key => $value]);
                self::fail('Retained source identity mutated.');
            } catch (\LogicException) {
                self::assertTrue(true);
            }
        }
        try {
            $source->delete();
            self::fail('Retained source deleted.');
        } catch (\LogicException) {
            self::assertSame(1, CctvSource::query()->count());
        }
        DB::table('cctv_sources')->where('id', $source->id)->update(['discovery_request_id' => null]);
        $this->recovery($body)->assertStatus(409);
    }

    public function test_closed_body_and_query_contract_never_accepts_extra_authority_or_manual_source(): void
    {
        $request = $this->commit();
        $body = $this->mapping($request);
        foreach (['url' => 'https://arbitrary.test/live', 'collector_id' => 1, 'enabled' => true, 'recorder_device_id' => (int) $this->camera->id] as $key => $value) {
            $this->saveMapping($body + [$key => $value])->assertStatus(400);
        }
        $this->saveMapping(array_replace($body, ['expected_actor_id' => (string) $this->actor->id]))->assertStatus(400);
        $this->getJson($this->href().'?enabled=1')->assertStatus(400);
        $this->getJson($this->href().'/discoveries/'.$request->uuid.'?url=x')->assertStatus(400);
        self::assertSame(0, CctvSource::query()->count());
    }

    public function test_disabling_new_mappings_retains_review_but_denies_creation(): void
    {
        $request = $this->commit();
        config(['cctv.hikvision_mapping_enabled' => false]);
        $this->getJson($this->href().'/discoveries/'.$request->uuid)->assertOk()->assertJsonPath('mapping_enabled', false)->assertJsonPath('live_available', false);
        $this->saveMapping($this->mapping($request))->assertStatus(503);
        self::assertSame(0, CctvSource::query()->count());
    }
}
