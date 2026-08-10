<?php

namespace Tests\Performance\Monitoring;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Management\Services\CollectorCommandRecoveryService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use App\Support\LegacyStorageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class MonitoringLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_runtime_workload_is_supervised_separately_with_bounded_workers(): void
    {
        $path = base_path('ops/supervisor/oblivion-monitoring-workers.conf');

        $this->assertFileExists($path);
        $config = (string) file_get_contents($path);
        $expected = [
            'monitoring-events' => ['processes' => 4, 'timeout' => 60],
            'monitoring-checks' => ['processes' => 8, 'timeout' => 45],
            'monitoring-discovery' => ['processes' => 2, 'timeout' => 300],
            'monitoring-provider' => ['processes' => 3, 'timeout' => 180],
            'monitoring-topology' => ['processes' => 2, 'timeout' => 300],
            'monitoring-maintenance' => ['processes' => 1, 'timeout' => 300],
            'monitoring-orchestration' => ['queue' => 'monitoring', 'processes' => 2, 'timeout' => 120],
            'monitoring-commands' => ['queue' => 'monitoring-commands', 'processes' => 2, 'timeout' => 150],
        ];

        foreach ($expected as $program => $bounds) {
            $queue = $bounds['queue'] ?? $program;
            $section = $this->supervisorSection($config, "oblivion-{$program}");

            $this->assertStringContainsString('queue:work redis', $section);
            $this->assertStringContainsString("--queue={$queue}", $section);
            $this->assertStringContainsString(
                $queue === 'monitoring-commands' ? '--tries=1' : '--tries=5',
                $section,
            );
            if ($queue !== 'monitoring-commands') {
                $this->assertStringContainsString('--backoff=5,30,120', $section);
            }
            $this->assertStringContainsString("--timeout={$bounds['timeout']}", $section);
            $this->assertStringContainsString('--memory=256', $section);
            $this->assertStringContainsString('--max-time=3600', $section);
            $this->assertSame($bounds['processes'], $this->integerSetting($section, 'numprocs'));
            $this->assertGreaterThan($bounds['timeout'], $this->integerSetting($section, 'stopwaitsecs'));
        }

        $this->assertStringNotContainsString('--queue=default', $config);
        $this->assertStringNotContainsString('--queue=monitoring,monitoring-events', $config);
        $this->assertDoesNotMatchRegularExpression('/--queue=[^\s]*,/', $config);
    }

    public function test_udp_listeners_are_distinct_restartable_processes(): void
    {
        $path = base_path('ops/supervisor/oblivion-monitoring-listeners.conf');

        $this->assertFileExists($path);
        $config = (string) file_get_contents($path);
        $commands = [
            'snmp-traps' => 'monitoring:listen-snmp-traps',
            'syslog' => 'monitoring:listen-syslog',
            'flow' => 'monitoring:listen-flow',
        ];

        foreach ($commands as $name => $command) {
            $section = $this->supervisorSection($config, "oblivion-monitoring-{$name}");

            $this->assertStringContainsString("artisan {$command}", $section);
            $this->assertStringContainsString('numprocs=1', $section);
            $this->assertStringContainsString('autostart=true', $section);
            $this->assertStringContainsString('autorestart=true', $section);
            $this->assertStringContainsString("monitoring-{$name}.log", $section);
        }
    }

    public function test_runtime_health_and_collector_deployment_boundaries_are_operationally_explicit(): void
    {
        $route = Route::getRoutes()->getByName('security-devices.runtime-health');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());

        $collectorReadme = (string) file_get_contents(base_path('collector/README.md'));
        $this->assertStringNotContainsString('DB_HOST', $collectorReadme);
        $this->assertStringNotContainsString('DB_DATABASE', $collectorReadme);
        $this->assertStringNotContainsString('DB_PASSWORD', $collectorReadme);
        $this->assertStringContainsString('database-free', strtolower($collectorReadme));
    }

    public function test_reproducible_runtime_scale_preserves_identity_site_scope_and_configured_bounds(): void
    {
        $writeEvidence = env('MONITORING_WRITE_EVIDENCE') === '1';
        $scale = $writeEvidence
            ? ['sites' => 100, 'devices' => 10_000, 'monitors' => 50_000, 'collectors' => 500]
            : ['sites' => 5, 'devices' => 200, 'monitors' => 1_000, 'collectors' => 10];
        $seedStarted = hrtime(true);
        $siteIds = Site::factory()->count($scale['sites'])->create()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $deviceIds = $this->seedDevicesAndAssignments($siteIds, $scale['devices']);
        $profile = MonitoringProfile::factory()->create([
            'name' => 'Native monitoring load profile',
            'interval_seconds' => 60,
        ]);
        $this->seedMonitors($deviceIds, (int) $profile->id, $scale['monitors']);
        $this->seedCollectors($siteIds, $scale['collectors']);
        $seedMs = $this->elapsedMilliseconds($seedStarted);

        $restricted = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $restricted->id,
            'primary_site_id' => $siteIds[0],
            'secondary_site_ids' => [],
        ]);
        $expectedVisible = DB::table('device_assignments')
            ->where('assignable_type', DeviceAssignment::TARGET_SITE)
            ->where('assignable_id', $siteIds[0])
            ->whereNull('released_at')
            ->count();
        $actualVisible = app(SecurityDevicesAccessService::class)->visibleDevices($restricted)->count();
        $this->assertSame($expectedVisible, $actualVisible, 'Restricted Site access leaked canonical Devices.');

        $scheduleKeys = [];
        $phaseTimings = [];
        $phaseTimings['dispatch'] = $this->measureBatches($scale['monitors'], 1_000, function (int $start, int $end) use (&$scheduleKeys): void {
            for ($index = $start; $index < $end; $index++) {
                $scheduleKeys["monitor:{$index}:2026-07-23T12:00Z"] = true;
            }
        });
        $this->assertCount($scale['monitors'], $scheduleKeys);

        $inboxEffects = [];
        $phaseTimings['ingest'] = $this->measureBatches($scale['monitors'] * 2, 2_000, function (int $start, int $end) use (&$inboxEffects, $scale): void {
            for ($delivery = $start; $delivery < $end; $delivery++) {
                $identity = $delivery % $scale['monitors'];
                $message = "monitor:{$identity}:2026-07-23T12:00Z";
                $signature = hash_hmac('sha256', $message, 'synthetic-load-fixture-key');
                $inboxEffects[$message] = $signature;
            }
        });
        $this->assertCount($scale['monitors'], $inboxEffects, 'Duplicate delivery created duplicate inbox effects.');

        $correlations = [];
        $phaseTimings['correlation'] = $this->measureBatches($scale['monitors'], 1_000, function (int $start, int $end) use (&$correlations): void {
            for ($index = $start; $index < $end; $index++) {
                $root = intdiv($index, 5);
                $correlations["root:{$root}"] = true;
            }
        });
        $this->assertCount((int) ceil($scale['monitors'] / 5), $correlations);

        $projection = [];
        $phaseTimings['projection'] = $this->measureBatches($scale['monitors'], 1_000, function (int $start, int $end) use (&$projection): void {
            for ($index = $start; $index < $end; $index++) {
                $projection[$index] = ($index % 1000) / 10;
            }
        });
        sort($projection, SORT_NUMERIC);

        $queueAges = [];
        $phaseTimings['queue_lag'] = $this->measureBatches($scale['monitors'], 1_000, function (int $start, int $end) use (&$queueAges): void {
            for ($index = $start; $index < $end; $index++) {
                $queueAges[] = $index % 120;
            }
        });

        $topologyEdges = [];
        $phaseTimings['topology'] = $this->measureBatches($scale['devices'], 1_000, function (int $start, int $end) use (&$topologyEdges): void {
            for ($index = max(1, $start); $index < $end; $index++) {
                $topologyEdges['device:'.($index - 1).":device:{$index}"] = true;
            }
        });

        $hourly = [];
        $phaseTimings['downsample'] = $this->measureBatches($scale['monitors'], 1_000, function (int $start, int $end) use (&$hourly): void {
            for ($index = $start; $index < $end; $index++) {
                $bucket = intdiv($index, 60);
                $hourly[$bucket] = ($hourly[$bucket] ?? 0) + 1;
            }
        });

        $thresholds = (array) config('monitoring.performance');
        foreach ($phaseTimings as $phase => $timings) {
            $limit = (float) $thresholds["{$phase}_batch_p95_ms"];
            $this->assertLessThanOrEqual($limit, $timings['p95_ms'], "{$phase} p95 exceeded its configured threshold.");
        }
        $peakMemoryMb = memory_get_peak_usage(true) / 1_048_576;
        $this->assertLessThanOrEqual((float) $thresholds['peak_memory_mb'], $peakMemoryMb);
        $this->assertCount(max(0, $scale['devices'] - 1), $topologyEdges);
        $this->assertSame($scale['monitors'], array_sum($hourly));

        $evidence = [
            'scale_profile' => $writeEvidence ? 'full_scale' : 'smoke',
            'scale' => $scale,
            'seed_ms' => $seedMs,
            'phases' => $phaseTimings,
            'peak_memory_mb' => round($peakMemoryMb, 2),
            'schedule_keys' => count($scheduleKeys),
            'duplicate_deliveries' => $scale['monitors'] * 2,
            'inbox_effects' => count($inboxEffects),
            'correlations' => count($correlations),
            'visible_devices_for_restricted_site' => $actualVisible,
        ];
        if ($writeEvidence) {
            $this->writeLocalSyntheticEvidence('native-monitoring-load', $evidence);
        }
    }

    public function test_collector_command_recovery_drains_a_backlog_in_bounded_idempotent_batches(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $user = User::factory()->create(['approved_at' => now()]);
        $device = Device::factory()->security()->create();
        $collector = MonitoringCollector::factory()->create(['site_id' => $site->id]);
        $now = now();
        $total = 1_100;
        $requests = [];
        foreach (range(1, $total) as $index) {
            $requests[] = [
                'command_uuid' => sprintf('20000000-0000-4000-8000-%012d', $index),
                'device_id' => $device->id,
                'site_id' => $site->id,
                'requested_by_user_id' => $user->id,
                'collector_id' => $collector->id,
                'capability' => 'access.door.unlock_timed',
                'capability_version' => 1,
                'management_level' => 'control',
                'risk' => 'high',
                'confirmation_mode' => 'acknowledge_impact',
                'status' => 'dispatching',
                'safe_parameter_summary' => json_encode(['duration_seconds' => 15], JSON_THROW_ON_ERROR),
                'reason' => 'Command recovery load fixture.',
                'expected_state' => json_encode(['locked' => true], JSON_THROW_ON_ERROR),
                'reconciliation_rule' => 'fresh_state_matches',
                'idempotency_key' => 'collector-recovery-load-'.$index,
                'execution_route' => 'collector',
                'provider' => 'unifi',
                'is_break_glass' => false,
                'dispatched_at' => $now->copy()->subMinutes(2),
                'expires_at' => $now->copy()->subMinute(),
                'created_at' => $now->copy()->subMinutes(2),
                'updated_at' => $now->copy()->subMinutes(2),
            ];
        }
        foreach (array_chunk($requests, 250) as $chunk) {
            DB::table('device_command_requests')->insert($chunk);
        }

        $requestIds = DB::table('device_command_requests')
            ->where('command_uuid', 'like', '20000000-%')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $attempts = [];
        foreach ($requestIds as $offset => $requestId) {
            $index = $offset + 1;
            $attempts[] = [
                'device_command_request_id' => $requestId,
                'attempt_uuid' => sprintf('30000000-0000-4000-8000-%012d', $index),
                'attempt_number' => 1,
                'status' => 'dispatching',
                'runtime' => 'collector',
                'provider_request_reference' => $index % 2 === 0
                    ? 'collector:'.$collector->collector_uuid.':config:'.$index
                    : null,
                'created_at' => $now->copy()->subMinutes(2),
                'updated_at' => $now->copy()->subMinutes(2),
            ];
        }
        foreach (array_chunk($attempts, 250) as $chunk) {
            DB::table('device_command_attempts')->insert($chunk);
        }

        $started = hrtime(true);
        $recovery = app(CollectorCommandRecoveryService::class);
        $first = $recovery->recover($now->toImmutable());
        $second = $recovery->recover($now->toImmutable());
        $third = $recovery->recover($now->toImmutable());
        $elapsedMs = $this->elapsedMilliseconds($started);

        $this->assertSame(['expired_before_delivery' => 500, 'uncertain_after_delivery' => 500], $first);
        $this->assertSame(['expired_before_delivery' => 50, 'uncertain_after_delivery' => 50], $second);
        $this->assertSame(['expired_before_delivery' => 0, 'uncertain_after_delivery' => 0], $third);
        $this->assertSame(550, DB::table('device_command_requests')->where('status', 'expired')->count());
        $this->assertSame(550, DB::table('device_command_requests')->where('status', 'uncertain')->count());
        $this->assertSame(0, DB::table('device_command_attempts')->whereIn('status', [
            'dispatching', 'accepted', 'running',
        ])->count());
        $this->assertSame($total, DB::table('device_command_audit_events')->count());
        $this->assertLessThanOrEqual(
            (float) config('monitoring.performance.command_recovery_backlog_ms', 120_000),
            $elapsedMs,
            'Collector command recovery backlog exceeded its configured operational bound.',
        );

        if (env('MONITORING_WRITE_EVIDENCE') === '1') {
            $this->writeLocalSyntheticEvidence('device-command-recovery', [
                'records' => $total,
                'batch_limit' => 1_000,
                'first_pass' => $first,
                'second_pass' => $second,
                'idempotent_final_pass' => $third,
                'elapsed_ms' => round($elapsedMs, 3),
                'issued_commands_repeated' => 0,
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeLocalSyntheticEvidence(string $prefix, array $payload): void
    {
        $directory = base_path('output/monitoring/load');
        File::ensureDirectoryExists($directory);

        $artifactId = (string) Str::orderedUuid();
        $checkedAt = now()->utc();
        $evidence = array_merge($payload, [
            'evidence_contract' => 'monitoring-local-synthetic-v1',
            'artifact_id' => $artifactId,
            'evidence_classification' => 'local_synthetic_fixture',
            'execution_scope' => 'test_process_only',
            'network_io_performed' => false,
            'deployed_runtime_observed' => false,
            'soak_duration_proven' => false,
            'v09_release_evidence' => false,
            'checked_at' => $checkedAt->toIso8601String(),
        ]);
        $contents = json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        $path = sprintf(
            '%s/%s-%s-%s.json',
            $directory,
            $prefix,
            $checkedAt->format('Ymd\THis.u\Z'),
            $artifactId,
        );
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Unable to create a unique local synthetic monitoring evidence artifact.');
        }

        $offset = 0;
        try {
            while ($offset < strlen($contents)) {
                $written = fwrite($stream, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to write the complete local synthetic monitoring evidence artifact.');
                }
                $offset += $written;
            }
            if (! fflush($stream)) {
                throw new RuntimeException('Unable to flush the local synthetic monitoring evidence artifact.');
            }
        } catch (RuntimeException $exception) {
            fclose($stream);
            unlink($path);

            throw $exception;
        }
        fclose($stream);
    }

    private function supervisorSection(string $config, string $program): string
    {
        preg_match(
            '/\[program:'.preg_quote($program, '/').'\](.*?)(?=\R\[program:|\z)/s',
            $config,
            $matches,
        );

        $this->assertArrayHasKey(1, $matches, "Missing Supervisor program {$program}.");

        return $matches[1];
    }

    private function integerSetting(string $section, string $name): int
    {
        preg_match('/^'.preg_quote($name, '/').'=(\d+)$/m', $section, $matches);
        $this->assertArrayHasKey(1, $matches, "Missing {$name} setting.");

        return (int) $matches[1];
    }

    /** @param list<int> $siteIds @return list<int> */
    private function seedDevicesAndAssignments(array $siteIds, int $count): array
    {
        $template = Device::factory()->itInfrastructure()->make()->getAttributes();
        $now = now();
        $rows = [];
        foreach (range(0, $count - 1) as $offset) {
            $row = $template;
            $row['device_uid'] = sprintf('LOAD-DEVICE-%05d', $offset);
            $row['name'] = sprintf('Load Device %05d', $offset);
            $row['serial_number'] = sprintf('LOAD-SERIAL-%05d', $offset);
            $row['mac_address'] = sprintf('02:4f:%02x:%02x:%02x:%02x', ($offset >> 24) & 255, ($offset >> 16) & 255, ($offset >> 8) & 255, $offset & 255);
            $row['ip_address'] = sprintf('10.%d.%d.%d', 64 + (intdiv($offset, 65_536) % 32), intdiv($offset, 256) % 256, $offset % 256);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
            if (count($rows) === 1_000) {
                DB::table('devices')->insert($rows);
                $rows = [];
            }
        }
        if (($rows ?? []) !== []) {
            DB::table('devices')->insert($rows);
        }

        $deviceIds = DB::table('devices')->where('device_uid', 'like', 'LOAD-DEVICE-%')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $assignments = [];
        foreach ($deviceIds as $offset => $deviceId) {
            $assignments[] = [
                'device_id' => $deviceId,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $siteIds[$offset % count($siteIds)],
                'assignment_type' => 'permanent',
                'assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($assignments) === 1_000) {
                DB::table('device_assignments')->insert($assignments);
                $assignments = [];
            }
        }
        if ($assignments !== []) {
            DB::table('device_assignments')->insert($assignments);
        }

        return $deviceIds;
    }

    /** @param list<int> $deviceIds */
    private function seedMonitors(array $deviceIds, int $profileId, int $count): void
    {
        $template = array_merge(
            LegacyStorageContext::attributes(),
            Monitor::factory()->make(['device_id' => $deviceIds[0], 'profile_id' => $profileId])->getAttributes(),
        );
        $now = now();
        $rows = [];
        foreach (range(0, $count - 1) as $offset) {
            $row = $template;
            $row['device_id'] = $deviceIds[$offset % count($deviceIds)];
            $row['profile_id'] = $profileId;
            $row['name'] = sprintf('Load monitor %05d', $offset);
            $row['target'] = sprintf('10.%d.%d.%d', 64 + (intdiv($offset, 65_536) % 32), intdiv($offset, 256) % 256, $offset % 256);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
            if (count($rows) === 1_000) {
                DB::table('monitors')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('monitors')->insert($rows);
        }
    }

    /** @param list<int> $siteIds */
    private function seedCollectors(array $siteIds, int $count): void
    {
        $template = array_merge(
            LegacyStorageContext::attributes(),
            MonitoringCollector::factory()->make()->getAttributes(),
        );
        $now = now();
        $rows = [];
        foreach (range(0, $count - 1) as $offset) {
            $row = $template;
            $row['collector_uuid'] = (string) Str::uuid();
            $row['name'] = sprintf('Load Collector %04d', $offset);
            $row['site_id'] = $siteIds[$offset % count($siteIds)];
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
        }
        foreach (array_chunk($rows, 1_000) as $chunk) {
            DB::table('monitoring_collectors')->insert($chunk);
        }
    }

    /** @return array{p50_ms: float, p95_ms: float, maximum_ms: float, batches: int} */
    private function measureBatches(int $total, int $batchSize, callable $operation): array
    {
        $timings = [];
        for ($start = 0; $start < $total; $start += $batchSize) {
            $started = hrtime(true);
            $operation($start, min($total, $start + $batchSize));
            $timings[] = $this->elapsedMilliseconds($started);
        }
        sort($timings, SORT_NUMERIC);

        return [
            'p50_ms' => round($this->percentile($timings, 0.50), 3),
            'p95_ms' => round($this->percentile($timings, 0.95), 3),
            'maximum_ms' => round((float) end($timings), 3),
            'batches' => count($timings),
        ];
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $quantile): float
    {
        return $values[(int) floor((count($values) - 1) * $quantile)] ?? 0.0;
    }

    private function elapsedMilliseconds(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}
