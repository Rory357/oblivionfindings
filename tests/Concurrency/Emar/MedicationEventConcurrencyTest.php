<?php

namespace Tests\Concurrency\Emar;

use App\Models\MedicationEvent;
use App\Models\Site;
use App\Services\Medication\Audit\MedicationEventChain;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Must run under the shared heavy lock, after the focused Feature migration run. */
class MedicationEventConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || preg_match('/^oblivion_findings_codex_test_[a-zA-Z0-9_]+$/D', DB::getDatabaseName()) !== 1 || config('database.connections.mysql.host') !== '127.0.0.1') {
            throw new \RuntimeException('Use the isolated local test database for medication concurrency.');
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_four_connections_append_once_in_one_site_with_no_sequence_gap(): void
    {
        $site = Site::factory()->create(['name' => 'P09 synthetic concurrency']);
        try {
            $this->race(array_fill(0, 4, [0, 0, $site->id]));
            $events = MedicationEvent::query()->where('site_id', $site->id)->orderBy('sequence')->get();
            $this->assertSame([1, 2, 3, 4], $events->pluck('sequence')->all());
            $this->assertSame(4, $events->pluck('subject_id')->unique()->count());
            $this->assertTrue(DB::transaction(fn () => app(MedicationEventChain::class)->verify($site->id))['intact']);
        } finally {
            $this->removeSyntheticSites([$site->id]);
        }
    }

    public function test_real_innodb_deadlock_retries_the_whole_domain_write_and_appends_no_aborted_event(): void
    {
        $one = Site::factory()->create(['name' => 'P09 synthetic one']);
        $two = Site::factory()->create(['name' => 'P09 synthetic two']);
        try {
            $attempts = $this->race([[$one->id, $two->id, $one->id], [$two->id, $one->id, $one->id]]);
            $this->assertGreaterThan(1, max($attempts), 'The simultaneous opposite domain locks must cause a real retry.');
            $this->assertSame(1, substr_count($one->fresh()->name, ':command-'));
            $this->assertSame(1, substr_count($two->fresh()->name, ':command-'));
            $this->assertSame(2, MedicationEvent::where('site_id', $one->id)->count());
            $this->assertTrue(DB::transaction(fn () => app(MedicationEventChain::class)->verify($one->id))['intact']);
        } finally {
            $this->removeSyntheticSites([$one->id, $two->id]);
        }
    }

    public function test_recorder_rejects_a_write_without_an_outer_transaction(): void
    {
        $this->expectException(\LogicException::class);
        app(MedicationEventRecorder::class)->append(new MedicationEventData(1, 'test.invalid', 'command', 'synthetic', null, CarbonImmutable::now(), 'No transaction'));
    }

    public function test_evidence_migration_refuses_rollback_without_forgetting_its_receipt(): void
    {
        $migration = require base_path('database/migrations/2026_10_03_210000_create_medication_event_chain.php');
        try {
            $migration->down();
            $this->fail('An evidence rollback must be explicitly refused.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot be rolled back', $exception->getMessage());
        }
        $this->assertTrue(DB::table('migrations')->where('migration', '2026_10_03_210000_create_medication_event_chain')->exists());
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('medication_events'));
    }

    private function race(array $commands): array
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p09-'.Str::uuid();
        $processes = [];
        $gates = [];
        try {
            foreach ($commands as $i => [$first, $second, $site]) {
                $gate = $root.'-'.$i;
                $gates[] = $gate;
                $process = new Process([PHP_BINARY, base_path('tests/Support/medication-event-concurrency-worker.php'), (string) $first, (string) $second, (string) $site, 'command-'.$i, $gate], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::getDatabaseName(),
                    'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                    'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                ]);
                $process->setTimeout(40)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 25;
            while (count(array_filter($gates, fn ($gate) => is_file($gate.'.ready'))) !== count($gates)) {
                foreach ($processes as $process) {
                    $this->assertTrue($process->isRunning(), $process->getErrorOutput().$process->getOutput());
                }
                $this->assertLessThan($deadline, microtime(true), 'Workers did not reach the concurrency barrier.');
                usleep(10000);
            }
            foreach ($gates as $gate) {
                file_put_contents($gate.'.release', 'go');
            }
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
                $this->assertSame('committed', $process->getOutput());
            }

            return array_map(fn ($gate) => (int) file_get_contents($gate.'.attempt'), $gates);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($gates as $gate) {
                foreach (['.ready', '.release', '.attempt'] as $suffix) {
                    if (is_file($gate.$suffix)) {
                        unlink($gate.$suffix);
                    }
                }
            }
        }
    }

    private function removeSyntheticSites(array $ids): void
    {
        DB::table('medication_events')->whereIn('site_id', $ids)->delete();
        DB::table('medication_event_heads')->whereIn('site_id', $ids)->delete();
        Site::query()->whereIn('id', $ids)->delete();
    }
}
