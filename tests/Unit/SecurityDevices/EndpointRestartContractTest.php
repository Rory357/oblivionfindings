<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointHeartbeat;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartCommands;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartContract;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EndpointRestartContractTest extends TestCase
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
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Facade::setFacadeApplication($this->previous);
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_absolute_schedule_is_bounded_and_never_accepts_force_or_untyped_values(): void
    {
        self::assertSame(['version' => 0, 'enabled' => false, 'consent_mode' => 'attended', 'minimum_warning_seconds' => 60], EndpointRestartPolicy::defaults());
        $base = ['execute_at' => '2026-10-08T10:01:00Z', 'warning_seconds' => 60];
        self::assertSame($base, EndpointRestartContract::parameters($base));
        self::assertSame('2026-10-08T10:01:05Z', EndpointRestartContract::requestedExpiry($base)->format('Y-m-d\TH:i:s\Z'));
        self::assertSame('2026-10-09T10:00:05Z', EndpointRestartContract::requestedExpiry([...$base, 'execute_at' => '2026-10-09T10:00:00Z'])->format('Y-m-d\TH:i:s\Z'));
        foreach ([[], [...$base, 'force_close' => true], [...$base, 'warning_seconds' => '60'], [...$base, 'warning_seconds' => true], [...$base, 'warning_seconds' => 59], [...$base, 'warning_seconds' => 3601], [...$base, 'execute_at' => '2026-10-08T10:00:59Z'], [...$base, 'execute_at' => '2026-10-09T10:00:01Z'], [...$base, 'execute_at' => '2026-02-30T10:01:00Z'], [...$base, 'execute_at' => '2026-10-08T10:01:00+00:00'], [...$base, 'execute_at' => '2026-10-08T10:01:00.000Z'], [...$base, 'execute_at' => '2026-10-08T10:01:60Z'], [...$base, 'execute_at' => []]] as $invalid) {
            try {
                EndpointRestartContract::requestedExpiry($invalid);
                self::fail('Unsafe schedule accepted.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('parameters', $e->errors());
            }
        }
    }

    public function test_only_an_api_acceptance_receipt_can_await_boot_and_no_result_claims_success(): void
    {
        $valid = [['awaiting_boot', 'restart_requested', true], ['failed', 'restart_refused', true], ['failed', 'operation_failed', false], ['uncertain', 'interrupted', true], ['expired', 'expired', false], ['declined', 'declined', false], ['cancelled', 'cancelled', false]];
        foreach ($valid as [$status,$reason,$started]) {
            EndpointRestartContract::validateResult(['status' => $status, 'reason_code' => $reason, 'mutation_started' => $started]);
            self::assertTrue(true);
        }
        foreach ([['awaiting_boot', 'restart_requested', false], ['awaiting_boot', 'interrupted', true], ['failed', 'operation_failed', true], ['cancelled', 'cancelled', true], ['declined', 'declined', true], ['expired', 'expired', true], ['uncertain', 'restart_requested', true], ['failed', 'restart_requested', false]] as [$status,$reason,$started]) {
            try {
                EndpointRestartContract::validateResult(['status' => $status, 'reason_code' => $reason, 'mutation_started' => $started]);
                self::fail('Contradictory receipt accepted.');
            } catch (HttpException $e) {
                self::assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_heartbeat_preserves_agents_without_restart_capability_and_recovery_only_observations(): void
    {
        $heartbeat = (new \ReflectionClass(EndpointHeartbeat::class))->newInstanceWithoutConstructor();
        $validate = new \ReflectionMethod($heartbeat, 'validate');
        $payload = ['sequence' => 1, 'observed_at' => '2026-10-08T10:00:00Z', 'agent_version' => '1.0.0', 'boot_id' => '22222222-2222-4222-8222-222222222222', 'inventory' => ['hostname' => 'isolated', 'os_version' => 'Windows 11', 'architecture' => 'amd64', 'memory_total_bytes' => 1, 'memory_available_bytes' => 1, 'uptime_seconds' => 1, 'cpu_percent' => null, 'disks' => [], 'software' => [], 'software_complete' => true, 'services' => [], 'services_complete' => true, 'probe_errors' => []]];
        self::assertSame($payload, $validate->invoke($heartbeat, $payload));
        $recovery = $payload + ['restart_observations' => [['command_uuid' => '11111111-1111-4111-8111-111111111111', 'attempt_uuid' => '44444444-4444-4444-8444-444444444444', 'before_boot_id' => '55555555-5555-4555-8555-555555555555', 'boot_id' => $payload['boot_id'], 'observed_at' => $payload['observed_at']]]];
        self::assertSame($recovery['restart_observations'], $validate->invoke($heartbeat, $recovery)['restart_observations']);
        $capability = ['version' => 1, 'installed_service' => true, 'release_enabled' => true, 'executor' => 'windows_restart'];
        self::assertSame($capability, $validate->invoke($heartbeat, $payload + ['restart_actions' => $capability])['restart_actions']);
        foreach ([array_diff_key($capability, ['installed_service' => true]), [...$capability, 'installed_service' => false], [...$capability, 'release_enabled' => false], [...$capability, 'release_enabled' => 1], [...$capability, 'version' => '1'], [...$capability, 'executor' => 'windows_scm'], [...$capability, 'unexpected' => true]] as $invalid) {
            try {
                $validate->invoke($heartbeat, $payload + ['restart_actions' => $invalid]);
                self::fail('Invalid execution capability accepted.');
            } catch (ValidationException $error) {
                self::assertNotEmpty($error->errors());
            }
        }
    }

    public function test_exact_observation_matching_survives_json_object_key_order_without_relaxing_fields_or_types(): void
    {
        $commands = (new \ReflectionClass(EndpointRestartCommands::class))->newInstanceWithoutConstructor();
        $compare = new \ReflectionMethod($commands, 'sameObservation');
        $reported = ['command_uuid' => 'command', 'attempt_uuid' => 'attempt', 'before_boot_id' => 'before', 'boot_id' => 'after', 'observed_at' => '2026-10-08T10:00:00Z'];
        $stored = array_reverse($reported, true);
        self::assertNotSame($reported, $stored); // Reproduces the previous ordered-array comparison failure.
        self::assertTrue($compare->invoke($commands, $stored, $reported));
        foreach ([array_diff_key($stored, ['observed_at' => true]), [...$stored, 'extra' => 'field'], [...$stored, 'boot_id' => 'another'], [...$stored, 'boot_id' => 1], [...$stored, 'observed_at' => null]] as $invalid) {
            self::assertFalse($compare->invoke($commands, $invalid, $reported));
        }
    }
}
