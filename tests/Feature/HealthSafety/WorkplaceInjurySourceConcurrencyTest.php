<?php

namespace Tests\Feature\HealthSafety;

use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryCommandReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;

/** Prepared real parallel MySQL commands. Sole runner admission is required; never run against a production schema. */
final class WorkplaceInjurySourceConcurrencyTest extends WorkplaceInjurySourceTestCase
{
    public function test_parallel_same_uuid_converges_to_one_audit_version_and_receipt(): void
    {
        $injury = $this->injury();
        $job = ['actor' => $this->manager->id, 'payload' => $this->command($injury, ['description' => 'Parallel factual correction'])];
        $results = $this->race($injury, [$job, $job]);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status'));
        $this->assertSame([false, true], collect($results)->pluck('receipt.replayed')->sort()->values()->all());
        $this->assertCount(1, array_unique(collect($results)->pluck('receipt.receipt_id')->all()));
        $this->assertSame(2, $injury->fresh()->source_revision);
        $this->assertSame(2, WorkplaceInjuryCommandReceipt::where('workplace_injury_id', $injury->id)->count());
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'health_safety.injury.update')->count());
    }

    public function test_parallel_distinct_corrections_from_same_revision_accept_exactly_one(): void
    {
        $injury = $this->injury();
        $results = $this->race($injury, [
            ['actor' => $this->manager->id, 'payload' => $this->command($injury, ['description' => 'First proposed factual correction'])],
            ['actor' => $this->factual->id, 'payload' => $this->command($injury, ['description' => 'Second proposed factual correction'])],
        ]);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['expected_version'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(2, $injury->fresh()->source_revision);
        $this->assertContains($injury->fresh()->description, ['First proposed factual correction', 'Second proposed factual correction']);
        $this->assertSame(2, WorkplaceInjuryCommandReceipt::where('workplace_injury_id', $injury->id)->count());
    }

    private function race(WorkplaceInjury $injury, array $jobs): array
    {
        $connection = DB::connection();
        if (app()->environment() !== 'testing' || $connection->getDriverName() !== 'mysql'
            || ! str_contains(strtolower($connection->getDatabaseName()), 'test')) {
            throw new RuntimeException('Parallel source tests require the admitted MySQL test schema.');
        }
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $this->assertSame(1, $connection->transactionLevel());
        $connection->commit();
        $token = (string) Str::uuid();
        $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-injury-go-'.$token;
        $ready = [];
        $processes = [];
        try {
            $connection->beginTransaction();
            WorkplaceInjury::whereKey($injury->id)->lockForUpdate()->firstOrFail();
            foreach ($jobs as $index => $job) {
                $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-injury-ready-'.$token.'-'.$index;
                $ready[] = $path;
                $config = $connection->getConfig();
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/WorkplaceInjurySourceWorker.php'),
                    $connection->getDatabaseName(), (string) $job['actor'], (string) $injury->id,
                    base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR)), $path, $go], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(),
                        'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'],
                        'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array']);
                $process->setTimeout(60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($ready, 'is_file')) !== count($ready)) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) { throw new RuntimeException('Source worker exited before ready barrier.'); }
                }
                if (microtime(true) >= $deadline) { throw new RuntimeException('Source worker ready barrier timed out.'); }
                usleep(10000);
            }
            touch($go);
            usleep(250000);
            foreach ($processes as $process) { $this->assertTrue($process->isRunning(), 'Source command bypassed its held parent lock.'); }
            $connection->commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            while ($connection->transactionLevel() > 0) { $connection->rollBack(); }
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            foreach ([...$ready, $go] as $path) { if (is_file($path)) { unlink($path); } }
            $connection->beginTransaction();
        }
    }
}
