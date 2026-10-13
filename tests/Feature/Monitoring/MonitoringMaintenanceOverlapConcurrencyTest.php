<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringMaintenanceCommandReceipt;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Separate process owns the autocommitted synthetic fixtures and registered schema cleanup. */
final class MonitoringMaintenanceOverlapConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('MAINTENANCE_OVERLAP_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Run this committed-fixture concurrency witness in its dedicated isolated process.');
        }
        $base = self::$testDatabaseBaseName ?? 'oblivion_findings_codex_test_mreceipt_race_'.substr(hash('sha256', getmypid().microtime(true)), 0, 8);
        foreach (['DB_DATABASE' => $base, 'DB_CONNECTION' => 'mysql', 'CACHE_STORE' => 'array'] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        file_put_contents(dirname(__DIR__, 3).'/.endpoint-tools/sd01-maintenance-overlap-20261012/concurrency-runtime-owner.json', json_encode(['pid' => getmypid(), 'database_base' => $base, 'phase' => 'preparing', 'synthetic' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        parent::setUp();
        file_put_contents(dirname(__DIR__, 3).'/.endpoint-tools/sd01-maintenance-overlap-20261012/concurrency-runtime-owner.json', json_encode(['pid' => getmypid(), 'database_base' => $base, 'database' => DB::connection()->getDatabaseName(), 'phase' => 'ready', 'synthetic' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_concurrent_distinct_intents_cannot_commit_overlapping_same_scope_windows(): void
    {
        self::assertSame(0, DB::connection()->transactionLevel(), 'Concurrency fixtures must be committed before workers start.');
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $device = Device::factory()->itInfrastructure()->create();
        DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()]);
        $monitor = Monitor::factory()->create(['device_id' => $device->id]);
        $uuid = (string) Str::uuid();
        $directory = base_path('.endpoint-tools/sd01-maintenance-overlap-20261012/concurrency/'.str_replace('-', '', $uuid));
        mkdir($directory, 0700, true);
        $input = ['request_uuid' => $uuid, 'expected_actor_id' => (int) $actor->id, 'site_id' => (int) $site->id, 'monitor_id' => (int) $monitor->id, 'device_id' => null, 'name' => 'Synthetic concurrent maintenance', 'reason' => 'Preserve the same reviewed intent during an overlapping retry.', 'starts_at' => '2026-09-26T09:00:00+12:00', 'ends_at' => '2026-09-26T10:00:00+12:00', 'timezone' => 'Pacific/Auckland', 'dst_overlap' => 'reject', 'dst_gap' => 'reject', 'recurrence' => null, 'recurrence_until' => null];
        $secondUuid = (string) Str::uuid();
        $fixture = ['database' => DB::connection()->getDatabaseName(), 'actor_id' => (int) $actor->id, 'input' => $input, 'ready' => $directory.'/ready', 'release' => $directory.'/release'];
        $processes = [];
        try {
            foreach (['first', 'second'] as $label) {
                $path = $directory.'/'.$label.'.json';
                file_put_contents($path, json_encode([...$fixture, 'input' => $label === 'second' ? [...$input, 'request_uuid' => $secondUuid] : $input, 'started' => $directory.'/'.$label.'.started'], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/maintenance_receipt_race_worker.php'), $path, $label], base_path());
                $process->setTimeout(45);
                $process->start();
                $processes[$label] = $process;
                if ($label === 'first') {
                    $this->waitForFixtureFile($fixture['ready'], $process);
                } else {
                    $this->waitForFixtureFile($directory.'/second.started', $process);
                }
            }
            // First holds the Site mutex and an uncommitted window/receipt. Second is live.
            self::assertTrue($processes['first']->isRunning());
            self::assertTrue($processes['second']->isRunning());
            file_put_contents($directory.'/owner.json', json_encode(['owner_pid' => getmypid(), 'database' => $fixture['database'], 'worker_pids' => array_map(fn (Process $p) => $p->getPid(), $processes), 'synthetic' => true, 'hardware_acceptance' => false], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            file_put_contents($fixture['release'], 'release');
            $results = [];
            foreach ($processes as $label => $process) {
                $process->wait();
                file_put_contents($directory.'/'.$label.'.stdout', $process->getOutput());
                file_put_contents($directory.'/'.$label.'.stderr', $process->getErrorOutput());
                self::assertSame($label === 'first' ? 0 : 1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[$label] = json_decode(trim($process->getOutput()), true, 32, JSON_THROW_ON_ERROR);
            }
            self::assertArrayHasKey('reply', $results['first']);
            self::assertSame(ValidationException::class, $results['second']['error']);
            self::assertStringContainsString('overlaps this schedule', $results['second']['message']);
            self::assertFalse(MonitoringMaintenanceCommandReceipt::query()->where('request_uuid', $secondUuid)->exists());
            self::assertSame(1, MonitoringMaintenanceWindow::query()->count());
            self::assertSame(1, MonitoringMaintenanceCommandReceipt::query()->where('request_uuid', $uuid)->count());
            self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.created')->count());
            self::assertSame(1, AuditLog::query()->where('action', 'monitoring.maintenance.command_committed')->count());
            file_put_contents($directory.'/outcome.json', json_encode(['passed' => true, 'request_uuid' => $uuid, 'denied_request_uuid' => $secondUuid, 'database' => $fixture['database'], 'results' => $results, 'synthetic' => true, 'hardware_acceptance' => false], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } finally {
            if (! is_file($fixture['release'])) {
                file_put_contents($fixture['release'], 'release');
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    private function waitForFixtureFile(string $path, Process $process): void
    {
        $until = microtime(true) + 15;
        while (! is_file($path)) {
            if (! $process->isRunning() || microtime(true) > $until) {
                self::fail('Owned synthetic worker did not reach its barrier: '.$process->getOutput().$process->getErrorOutput());
            }
            usleep(10000);
        }
    }
}
