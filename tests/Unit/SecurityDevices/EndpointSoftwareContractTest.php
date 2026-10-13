<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointSoftwarePackage;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointHeartbeat;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareCommands;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareContract;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwarePackages;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwarePolicy;
use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EndpointSoftwareContractTest extends TestCase
{
    private $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Facade::getFacadeApplication();
        $app = new Application;
        $app->instance('config', new Repository);
        $app->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $app));
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Facade::setFacadeApplication($this->previous);
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe software contract accepted.');
        } catch (ValidationException $error) {
            self::assertNotEmpty($error->errors());
        } catch (HttpException $error) {
            self::assertSame(422, $error->getStatusCode());
        }
    }

    public function test_only_reviewed_uuid_and_canonical_machine_metadata_are_browser_inputs(): void
    {
        $uuid = '11111111-1111-4111-8111-111111111111';
        self::assertSame(['package_uuid' => $uuid], EndpointSoftwareContract::parameters(['package_uuid' => $uuid]));
        self::assertSame(['version' => 0, 'enabled' => false, 'consent_mode' => 'attended'], EndpointSoftwarePolicy::defaults());
        $metadata = ['name' => 'Reviewed print software', 'publisher' => 'Reviewed publisher', 'product_code' => '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}', 'product_version' => '255.255.65535', 'architecture' => 'arm64', 'signer_sha256' => str_repeat('a', 64)];
        self::assertSame($metadata, EndpointSoftwareContract::metadata($metadata));
        foreach ([[], ['package_uuid' => []], ['package_uuid' => $uuid, 'url' => 'https://example.invalid'], ['package_uuid' => $uuid, 'arguments' => '/force'], ['package_uuid' => $uuid, 'properties' => ['ALLUSERS' => 1]], ['package_uuid' => $uuid, 'path' => 'C:\\untrusted.msi']] as $invalid) {
            $this->rejected(fn () => EndpointSoftwareContract::parameters($invalid));
        }
        foreach ([['product_code' => '{aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee}'], ['product_code' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE'], ['product_version' => '256.0.0'], ['product_version' => '0.256.0'], ['product_version' => '0.0.65536'], ['product_version' => '01.2.3'], ['product_version' => '1.2'], ['product_version' => '1.2.3.4'], ['architecture' => 'x86'], ['signer_sha256' => str_repeat('A', 64)], ['publisher' => "Unsafe\nPublisher"], ['name' => str_repeat('😀', 201)], ['publisher' => "Invisible\u{202E}"], ['transform' => 'unsafe.mst']] as $change) {
            $this->rejected(fn () => EndpointSoftwareContract::metadata(array_replace($metadata, $change)));
        }
    }

    public function test_installer_exit_receipt_never_claims_completion_and_reboot_codes_remain_distinct(): void
    {
        $valid = [
            ['awaiting_observation', 'installed', true, 0, false], ['awaiting_observation', 'installed_restart_required', true, 3010, true], ['uncertain', 'restart_initiated', true, 1641, true], ['failed', 'installer_busy', true, 1618, false], ['failed', 'installer_failed', true, 1603, false], ['uncertain', 'interrupted', true, null, false], ['uncertain', 'state_unavailable', true, null, false], ['uncertain', 'interrupted', false, null, false], ['uncertain', 'state_unavailable', false, null, false], ['failed', 'artifact_invalid', false, null, false], ['declined', 'declined', false, null, false], ['expired', 'expired', false, null, false],
        ];
        foreach ($valid as [$status,$reason,$started,$exit,$reboot]) {
            EndpointSoftwareContract::validateResult(['status' => $status, 'reason_code' => $reason, 'mutation_started' => $started, 'exit_code' => $exit, 'reboot_required' => $reboot]);
            self::assertTrue(true);
        }
        $invalid = [['succeeded', 'installed', true, 0, false], ['awaiting_observation', 'installed', false, 0, false], ['awaiting_observation', 'installed', true, 3010, false], ['awaiting_observation', 'installed_restart_required', true, 3010, false], ['failed', 'installer_failed', true, 3010, true], ['failed', 'installer_failed', true, 1618, false], ['failed', 'installer_busy', false, 1618, false], ['uncertain', 'restart_initiated', true, 1641, false], ['uncertain', 'restart_initiated', false, 1641, true], ['uncertain', 'interrupted', false, 0, false], ['uncertain', 'state_unavailable', false, null, true], ['uncertain', 'operation_failed', false, null, false], ['uncertain', 'interrupted', 0, null, false], ['failed', 'artifact_invalid', true, null, false], ['declined', 'declined', true, null, false]];
        foreach ($invalid as [$status,$reason,$started,$exit,$reboot]) {
            $this->rejected(fn () => EndpointSoftwareContract::validateResult(['status' => $status, 'reason_code' => $reason, 'mutation_started' => $started, 'exit_code' => $exit, 'reboot_required' => $reboot]));
        }
    }

    public function test_optional_capability_preserves_legacy_agents_and_exact_recovery_observations(): void
    {
        $heartbeat = (new \ReflectionClass(EndpointHeartbeat::class))->newInstanceWithoutConstructor();
        $validate = new \ReflectionMethod($heartbeat, 'validate');
        $payload = ['sequence' => 1, 'observed_at' => '2026-10-08T10:00:00Z', 'agent_version' => '1.0.0', 'boot_id' => '22222222-2222-4222-8222-222222222222', 'inventory' => ['hostname' => 'isolated', 'os_version' => 'Windows 11', 'architecture' => 'amd64', 'memory_total_bytes' => 1, 'memory_available_bytes' => 1, 'uptime_seconds' => 1, 'cpu_percent' => null, 'disks' => [], 'software' => [], 'software_complete' => true, 'services' => [], 'services_complete' => true, 'probe_errors' => []]];
        self::assertSame($payload, $validate->invoke($heartbeat, $payload));
        $entry = ['command_uuid' => '11111111-1111-4111-8111-111111111111', 'attempt_uuid' => '44444444-4444-4444-8444-444444444444', 'product_code' => '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}', 'product_version' => '1.2.3', 'state' => 'installed', 'boot_id' => $payload['boot_id'], 'transaction_quiescent' => true, 'observed_at' => $payload['observed_at']];
        self::assertSame([$entry], $validate->invoke($heartbeat, $payload + ['software_observations' => [$entry]])['software_observations']);
        $capability = ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_msi'];
        self::assertSame($capability, $validate->invoke($heartbeat, $payload + ['software_actions' => $capability])['software_actions']);
        foreach ([array_diff_key($capability, ['installed_service' => true]), [...$capability, 'installed_service' => false], [...$capability, 'release_enabled' => 1], [...$capability, 'version' => '1'], [...$capability, 'executor' => 'windows_scm'], [...$capability, 'unexpected' => true]] as $invalid) {
            try {
                $validate->invoke($heartbeat, $payload + ['software_actions' => $invalid]);
                self::fail('Invalid capability accepted.');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
        foreach ([[...$entry, 'transaction_quiescent' => 1], [...$entry, 'path' => 'unsafe'], [...$entry, 'product_version' => '01.2.3']] as $invalid) {
            try {
                $validate->invoke($heartbeat, $payload + ['software_observations' => [$invalid]]);
                self::fail('Invalid observation accepted.');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
    }

    public function test_fresh_review_proof_is_bound_to_actor_current_identity_and_actual_password_confirmation(): void
    {
        $actor = (new User)->setRawAttributes(['id' => 7, 'email' => 'reviewer@example.invalid', 'password' => 'isolated-hash', 'two_factor_confirmed_at' => '2026-10-08 10:00:00'], true);
        $helper = new EndpointSoftwareIdentity;
        $matches = new \ReflectionMethod($helper, 'matches');
        $proof = ['actor_id' => 7, 'fingerprint' => ObliStaffAccess::fingerprint($actor), 'started_at' => 1000];
        self::assertTrue($matches->invoke($helper, $actor, $proof, 1001, 1901));
        foreach ([[$proof, 1001, 1902], [$proof, 999, 1001], [$proof, 1002, 1001], [[...$proof, 'actor_id' => 8], 1001, 1001], [[...$proof, 'fingerprint' => str_repeat('a', 64)], 1001, 1001], [$proof, '1001', 1001], [null, 1001, 1001]] as [$value,$confirmed,$now]) {
            self::assertFalse($matches->invoke($helper, $actor, $value, $confirmed, $now));
        }
        $actor->setRawAttributes([...$actor->getAttributes(), 'password' => 'changed-hash'], true);
        self::assertFalse($matches->invoke($helper, $actor, $proof, 1001, 1001));
    }

    public function test_exact_ack_matches_json_object_permutations_only(): void
    {
        $commands = (new \ReflectionClass(EndpointSoftwareCommands::class))->newInstanceWithoutConstructor();
        $same = new \ReflectionMethod($commands, 'sameObservation');
        $row = ['state' => 'installed', 'transaction_quiescent' => true, 'product_version' => '1.2.3'];
        self::assertTrue($same->invoke($commands, $row, array_reverse($row, true)));
        foreach ([array_diff_key($row, ['state' => 1]), $row + ['extra' => true], [...$row, 'transaction_quiescent' => 1], [...$row, 'product_version' => null]] as $invalid) {
            self::assertFalse($same->invoke($commands, $row, $invalid));
        }
    }

    public function test_cursor_types_are_validated_before_framework_coercion_and_links_are_canonical(): void
    {
        $packages = new EndpointSoftwarePackages;
        $cursor = new \ReflectionMethod($packages, 'cursor');
        $valid = (new Cursor(['id' => 20], false))->encode();
        self::assertSame(['id' => 20, '_pointsToNextItems' => false], $cursor->invoke($packages, $valid));
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        foreach ([null, [], '', str_repeat('A', 129), $encode(['id' => 20, '_pointsToNextItems' => []]), $encode(['id' => '20', '_pointsToNextItems' => true]), $encode(['id' => 0, '_pointsToNextItems' => true]), $encode(['id' => 20, '_pointsToNextItems' => 1]), $encode(['id' => 20, '_pointsToNextItems' => true, 'extra' => 1]), $encode(['_pointsToNextItems' => true, 'id' => 20])] as $invalid) {
            try {
                $cursor->invoke($packages, $invalid);
                self::fail('Malformed cursor accepted.');
            } catch (HttpException $error) {
                self::assertSame(400, $error->getStatusCode());
            }
        }
    }

    public function test_authenticated_artifact_snapshot_checks_exact_bytes_and_cannot_follow_private_path_escape(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'obli-software-unit-'.bin2hex(random_bytes(12));
        self::assertTrue(mkdir($root, 0700));
        $key = '11111111-1111-4111-8111-111111111111.msi';
        $path = $root.DIRECTORY_SEPARATOR.$key;
        $bytes = hex2bin('d0cf11e0a1b11ae1').'Isolated immutable bytes';
        file_put_contents($path, $bytes);
        config()->set('endpoint_management.software.package_directory', $root);
        $package = (new EndpointSoftwarePackage)->forceFill(['storage_key' => $key, 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]);
        $packages = new EndpointSoftwarePackages;
        try {
            $stream = $packages->copy($package);
            file_put_contents($path, $bytes.'tampered');
            self::assertSame($bytes, stream_get_contents($stream));
            fclose($stream);
            foreach ([$package, (clone $package)->forceFill(['storage_key' => '../outside.msi']), (clone $package)->forceFill(['storage_key' => $root.DIRECTORY_SEPARATOR.$key])] as $invalid) {
                try {
                    $packages->copy($invalid);
                    self::fail('Unverified artifact accepted.');
                } catch (HttpException $error) {
                    self::assertSame(503, $error->getStatusCode());
                }
            }
        } finally {
            unlink($path);
            rmdir($root);
        }
    }
}
