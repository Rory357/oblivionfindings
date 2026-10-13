<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Models\HikvisionDiscoveryRequest;
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionRecorderDiscoveryContract;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Composer\Autoload\ClassLoader;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Oblivion\Collector\Media\HikvisionRecorderDiscoveryExchange;
use Oblivion\Collector\Media\PrivateFrames;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class HikvisionRecorderCanonicalInteropTest extends TestCase
{
    use RefreshDatabase;

    private ClassLoader $collectorAutoloader;

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
        $binary = getenv('OBLI_RECORDER_CANONICAL_NATIVE_BINARY');
        if (! is_string($binary) || ! is_file($binary)) {
            $this->markTestSkipped('Explicit original canonical native fixture binary required.');
        }
        parent::setUp();
        // Load only the collector namespace. Registering its separate Composer
        // runtime would replace Laravel's inferred root on the next test case.
        $this->collectorAutoloader = new ClassLoader;
        $this->collectorAutoloader->addPsr4('Oblivion\\Collector\\', base_path('collector/src'));
        $this->collectorAutoloader->register();
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

            return new CredentialLease('synthetic-hk-lease-'.$this->issued, CarbonImmutable::now('UTC')->addSeconds(60), ['username' => 'fixture-isapi-user', 'password' => 'fixture-isapi-secret']);
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

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (isset($this->collectorAutoloader)) {
                $this->collectorAutoloader->unregister();
            }
        }
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

    public static function originalWireCases(): array
    {
        return ['original result and lost acknowledgement' => [false], 'permission changed before original result' => [true]];
    }

    #[DataProvider('originalWireCases')]
    public function test_original_canonical_request_native_observation_and_current_commitment(bool $withdraw): void
    {
        $binary = realpath((string) getenv('OBLI_RECORDER_CANONICAL_NATIVE_BINARY'));
        $tools = realpath(base_path('.endpoint-tools'));
        self::assertIsString($binary);
        self::assertIsString($tools);
        self::assertStringStartsWith(strtolower($tools.DIRECTORY_SEPARATOR), strtolower($binary));
        $evidence = dirname($binary);
        $owned = $evidence.DIRECTORY_SEPARATOR.'live-'.Str::uuid();
        self::assertTrue(mkdir($owned, 0700));
        $process = new Process([$binary, '-test.run=^TestHikvisionRecorderCanonicalApplicationInteropFixture$', '-test.v'], base_path('endpoint-agent'), ['OBLI_RECORDER_CANONICAL_FIXTURE_ROOT' => $owned], null, 20);
        $files = ['metadata.json', 'configure.bin', 'request.bin', 'result.bin', 'request-replay.bin', 'result-replay.bin', 'proof.json'];
        $pid = null;
        try {
            $process->start();
            $pid = $process->getPid();
            $metadata = json_decode($this->awaitOwned($owned, 'metadata.json', $process), true, 16, JSON_THROW_ON_ERROR);
            self::assertIsInt($metadata['process_id']);
            self::assertGreaterThan(0, $metadata['process_id']);
            // Symfony's Windows process handle may own cmd.exe, with the native
            // fixture as its direct child. Bind either actual ownership form.
            self::assertContains($pid, [$metadata['process_id'], $metadata['parent_process_id']]);
            self::assertTrue($metadata['synthetic']);
            self::assertFalse($metadata['hardware_acceptance']);
            self::assertSame('127.0.0.1', $metadata['allowed_ip']);
            self::assertIsInt($metadata['allowed_port']);
            self::assertGreaterThan(0, $metadata['allowed_port']);
            self::assertSame(1, preg_match('/\A[0-9a-f]{64}\z/', $metadata['tls_pin']));
            $this->recorder->update(['ip_address' => $metadata['allowed_ip']]);
            $this->scope->update(['cidrs' => ['127.0.0.1/32'], 'port_bounds' => ['tcp' => [$metadata['allowed_port']]]]);
            $configuration = array_intersect_key($metadata, array_flip(['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin']));
            $this->actualClock();
            $created = $this->create($this->input($configuration))->assertOk()->json();
            $this->actualClock();
            $workBody = $this->body();
            $dispatched = $this->postCollector('work', $workBody)->assertOk()->json();
            self::assertCount(1, $dispatched['operations']);
            $operation = $dispatched['operations'][0];
            $row = HikvisionDiscoveryRequest::query()->firstOrFail();
            self::assertSame(CctvContract::hash($operation), $row->operation_hash);
            self::assertSame($dispatched, $this->postCollector('work', $workBody)->assertOk()->json());
            $grantRaw = base64_decode($operation['grant']['payload'], true);
            self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($operation['grant']['signature'], true), $grantRaw, sodium_crypto_sign_publickey_from_secretkey($this->serverKey)));
            $grant = json_decode($grantRaw, true, 32, JSON_THROW_ON_ERROR);
            self::assertSame((int) $this->recorder->id, $grant['device_id']);
            self::assertSame((int) $this->scope->id, $grant['discovery_scope_id']);
            self::assertSame(HikvisionRecorderDiscoveryContract::CAPABILITY, $grant['capability']);
            $identity = ['collector_id' => $this->collector->collector_uuid, 'site_id' => (int) $this->site->id];
            $adapter = new HikvisionRecorderDiscoveryExchange(new CredentialLeaseDecryptor($this->collectorKey), new EnvelopeVerifier(sodium_crypto_sign_publickey_from_secretkey($this->serverKey)));
            $configure = json_encode(['version' => 1, 'operation' => 'configure', 'collector_uuid' => $identity['collector_id'], 'site_id' => $identity['site_id'], 'server_public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->serverKey))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $wire = $adapter->frame($operation, $identity, $this->realNow());
            $requestFrame = $this->framed($wire);
            $this->publishOwned($owned, 'configure.bin', $this->framed($configure));
            $this->publishOwned($owned, 'request.bin', $requestFrame);
            $resultFrame = $this->awaitOwned($owned, 'result.bin', $process);
            $input = fopen('php://memory', 'w+b');
            $output = fopen('php://memory', 'w+b');
            try {
                self::assertSame(strlen($resultFrame), fwrite($input, $resultFrame));
                rewind($input);
                $result = $adapter->exchange($operation, $identity, $input, $output, $this->realNow());
                self::assertTrue(feof($input) || ftell($input) === strlen($resultFrame));
                rewind($output);
                self::assertSame($requestFrame, stream_get_contents($output));
            } finally {
                fclose($input);
                fclose($output);
            }
            $this->publishOwned($owned, 'request-replay.bin', $requestFrame);
            self::assertSame($resultFrame, $this->awaitOwned($owned, 'result-replay.bin', $process));
            $proof = json_decode($this->awaitOwned($owned, 'proof.json', $process), true, 16, JSON_THROW_ON_ERROR);
            self::assertSame(0, $process->wait(), 'Owned native fixture failed: '.$process->getOutput().$process->getErrorOutput());
            self::assertSame(hash('sha256', $configure), $proof['configure_sha256']);
            self::assertSame(hash('sha256', $wire), $proof['request_sha256']);
            self::assertSame(hash('sha256', $requestFrame), $proof['request_frame_sha256']);
            self::assertSame(hash('sha256', substr($resultFrame, 4)), $proof['reply_sha256']);
            self::assertSame(hash('sha256', $resultFrame), $proof['reply_frame_sha256']);
            self::assertSame($proof['reply_frame_sha256'], $proof['replay_frame_sha256']);
            self::assertSame($result, HikvisionRecorderDiscoveryContract::result($result));
            self::assertSame($metadata['response_sha256'], $result['inventory']['response_sha256']);
            self::assertSame($proof['original_observed_at'], $result['observed_at']);
            self::assertSame('fixture-recorder-serial', $result['inventory']['identity']['serial']);
            self::assertNull($result['inventory']['inputs'][0]['camera_identity']);
            self::assertSame('unverified', $result['inventory']['inputs'][0]['association']);
            foreach (['/ISAPI/System/deviceInfo', '/ISAPI/ContentMgmt/StreamingProxy/channels', '/ISAPI/ContentMgmt/InputProxy/channels/status'] as $resource) {
                self::assertSame(2, $proof['resources'][$resource]);
                self::assertSame(1, $proof['authorized_resources'][$resource]);
            }
            self::assertCount(3, $proof['resources']);
            $this->actualClock();
            $body = ['collector_id' => $this->collector->collector_uuid, 'request_key' => (string) Str::uuid(), 'results' => [$result]];
            if ($withdraw) {
                $this->reference->update(['version' => 2]);
                $this->postCollector('results', $body)->assertStatus(409);
                self::assertNull($row->fresh()->result_hash);
                self::assertSame('dispatched', $row->fresh()->status);
                self::assertSame(0, AuditLog::query()->where('action', 'cctv.hikvision.discovery.result_recorded')->count());
                self::assertSame([], $this->postCollector('work', $this->body())->assertOk()->json('operations'));
                self::assertSame('revoked', $row->fresh()->status);
                self::assertSame('released', CredentialLeaseGrant::query()->firstOrFail()->status);
                $reader = $this->getJson($created['reader_href'])->assertOk()->json();
                self::assertFalse($reader['current_context']);
                self::assertNull($reader['inventory']);
                $receipt = null;
            } else {
                $receipt = $this->postCollector('results', $body)->assertOk()->json();
                self::assertSame(['accepted' => [['request_uuid' => $row->uuid, 'result_sha256' => CctvContract::hash($result)]]], $receipt);
                self::assertSame($receipt, $this->postCollector('results', $body)->assertOk()->json());
                $body['request_key'] = (string) Str::uuid();
                self::assertSame($receipt, $this->postCollector('results', $body)->assertOk()->json());
                self::assertSame($result, $row->fresh()->result);
                self::assertSame(CctvContract::hash($result), $row->fresh()->result_hash);
                self::assertSame('observed', $row->fresh()->status);
                self::assertSame($result['inventory'], $this->getJson($created['reader_href'])->assertOk()->json('inventory'));
                self::assertSame(1, AuditLog::query()->where('action', 'cctv.hikvision.discovery.result_recorded')->count());
                self::assertSame('released', CredentialLeaseGrant::query()->firstOrFail()->status);
                $body['request_key'] = (string) Str::uuid();
                $body['results'][0]['inventory']['inputs'][0]['online'] = false;
                $this->postCollector('results', $body)->assertStatus(409);
                self::assertSame($result, $row->fresh()->result);
                $body['request_key'] = (string) Str::uuid();
                $body['results'] = [$result];
                $body['results'][0]['inventory']['inputs'][0]['camera_identity'] = ['serial' => 'unproven-camera'];
                $this->postCollector('results', $body)->assertStatus(400);
            }
            self::assertNull($row->fresh()->operation);
            self::assertSame(CctvContract::hash($operation), $row->fresh()->operation_hash);
            self::assertSame(1, $this->issued);
            self::assertContains('synthetic-hk-lease-1', $this->revoked);
            self::assertSame('original-canonical-recorder-serial', $this->recorder->fresh()->serial_number);
            self::assertSame(0, CctvSource::query()->count());
            Http::assertNothingSent();
            $record = ['version' => 1, 'synthetic' => true, 'hardware_acceptance' => false, 'original_canonical_wire' => true, 'permission_withdrawn_before_commitment' => $withdraw, 'transport_process_id' => $pid, 'native_process_id' => $metadata['process_id'], 'native_exit_code' => $process->getExitCode(), 'request_uuid' => $row->uuid, 'canonical_operation_sha256' => $row->fresh()->operation_hash, 'canonical_result_sha256' => $row->fresh()->result_hash, 'final_status' => $row->fresh()->status, 'accepted_receipt' => $receipt, 'proof' => $proof];
            file_put_contents($evidence.DIRECTORY_SEPARATOR.($withdraw ? 'withdrawn' : 'accepted').'-evidence.json', json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            // Only exact files in the fresh UUID-owned directory are removed.
            foreach ($files as $file) {
                foreach ([$file, $file.'.tmp'] as $name) {
                    $target = $owned.DIRECTORY_SEPARATOR.$name;
                    if (is_file($target)) {
                        self::assertTrue(unlink($target));
                    }
                }
            }
            self::assertTrue(rmdir($owned));
            self::assertFalse($process->isRunning());
            file_put_contents($evidence.DIRECTORY_SEPARATOR.($withdraw ? 'withdrawn' : 'accepted').'-cleanup.json', json_encode(['native_process_id' => $pid, 'native_exit_code' => $process->getExitCode(), 'native_running' => false, 'owned_directory_removed' => ! is_dir($owned)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
        }
    }

    private function realNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function actualClock(): void
    {
        $this->travelTo(CarbonImmutable::instance($this->realNow())->startOfSecond());
    }

    private function awaitOwned(string $root, string $name, Process $process): string
    {
        $deadline = microtime(true) + 8;
        do {
            $path = $root.DIRECTORY_SEPARATOR.$name;
            if (is_file($path)) {
                $value = file_get_contents($path);
                self::assertIsString($value);
                self::assertLessThanOrEqual(65540, strlen($value));

                return $value;
            }
            self::assertTrue($process->isRunning(), 'Owned native fixture ended before its output: '.$process->getOutput().$process->getErrorOutput());
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Owned native fixture output deadline.');
    }

    private function publishOwned(string $root, string $name, string $bytes): void
    {
        $path = $root.DIRECTORY_SEPARATOR.$name;
        self::assertSame(strlen($bytes), file_put_contents($path.'.tmp', $bytes));
        self::assertTrue(rename($path.'.tmp', $path));
    }

    private function framed(string $json): string
    {
        $stream = fopen('php://memory', 'w+b');
        try {
            PrivateFrames::write($stream, $json);
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}
