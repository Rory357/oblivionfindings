<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\SubstanceExposureRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Prepared real two-worker contracts; no worker/test execution admitted. Uses the existing guarded source worker unchanged. */
final class ChemicalExposureReportedFactsConcurrencyTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;
    private function old(): SubstanceExposureRecord
    {
        return SubstanceExposureRecord::factory()->create(['hazardous_substance_id' => $this->catalogue()->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->manager->id,
            'related_incident_id' => null, 'original_context' => null, 'source_schema_version' => 0, 'source_revision' => 0,
            'medical_attention_sought' => false, 'incident_reported' => false]);
    }
    private function job(SubstanceExposureRecord $root, int $actor, array $input): array
    {
        return ['actor' => $actor, 'source' => $root->id, 'operation' => 'correct', 'payload' => $input];
    }

    public function test_two_current_actors_confirming_same_legacy_value_have_one_provenance_winner_and_stale_draft(): void
    {
        $root = $this->old(); $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $first = $this->privateCommand($root, ['reason' => 'First explicit reported No', 'incident_reported' => false]);
        $second = $this->privateCommand($root, ['reason' => 'Second explicit reported No', 'incident_reported' => false]);
        $results = $this->race([$this->job($root, $this->manager->id, $first), $this->job($root, $other->id, $second)], false);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['expected_version'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $winner = collect($results)->firstWhere('status', 'accepted')['receipt'];
        $this->assertTrue($winner['changed']); $this->assertSame(1, $root->fresh()->source_revision);
        $version = ChemicalExposureSourceVersion::findOrFail($winner['source_version_id']);
        $this->assertSame($winner['actor_id'], $version->content['facts']['reported_facts']['incident_reported']['reported_in']['actor_id']);
        $this->assertSame(1, ChemicalExposureCommandReceipt::whereIn('idempotency_key', [$first['idempotency_key'], $second['idempotency_key']])->count());
    }

    public function test_parallel_exact_legacy_confirmation_uuid_converges_to_one_version_audit_and_receipt(): void
    {
        $root = $this->old(); $input = $this->privateCommand($root, ['reason' => 'One exact retained confirmation', 'medical_attention_sought' => false]);
        $job = $this->job($root, $this->manager->id, $input); $results = $this->race([$job, $job], false);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status')); $this->assertSame($results[0]['receipt'], $results[1]['receipt']);
        $this->assertSame(1, $root->fresh()->source_revision); $this->assertSame(1, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
        $this->assertSame(1, AuditLog::where('meta->idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, ChemicalExposureCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
    }

    public function test_absent_shared_uuid_unknown_and_no_on_different_roots_cannot_claim_two_reported_intents(): void
    {
        $first = $this->old(); $second = $this->old(); $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']); $key = (string) Str::uuid();
        $a = $this->privateCommand($first, ['reason' => 'Original Unknown intent', 'incident_reported' => null], $key);
        $b = $this->privateCommand($second, ['reason' => 'Different No intent', 'incident_reported' => false], $key);
        $results = $this->race([$this->job($first, $this->manager->id, $a), $this->job($second, $other->id, $b)], true);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['idempotency_key'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(1, ChemicalCommandIntent::where('idempotency_key', $key)->count());
        $this->assertSame(1, ChemicalExposureCommandReceipt::where('idempotency_key', $key)->count());
        $this->assertSame(1, $first->fresh()->source_revision + $second->fresh()->source_revision);
    }

    public function test_two_real_readers_hold_shared_source_locks_without_command_update_or_upgrade(): void
    {
        $root = $this->exposure(['medical_attention_sought' => true]);
        $first = $this->staff(['hazards.view', 'hr.wellbeing.view']); $second = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        foreach ([$first, $second] as $reader) { $this->allow($reader, 'hazards.manage', false); $this->allow($reader, 'hazards.create', false); }
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || ! str_contains(strtolower($connection->getDatabaseName()), 'test') || $connection->transactionLevel() !== 0) {
            throw new \RuntimeException('Two-reader source proof requires the admitted physical MySQL test schema.');
        }
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-reported-fact-readers-'.Str::uuid(); $go = $prefix.'-go';
        $ready = []; $processes = []; $versions = ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count();
        $receipts = ChemicalExposureCommandReceipt::where('exposure_id', $root->id)->count();
        try {
            foreach ([$first, $second] as $index => $reader) {
                $ready[] = $prefix.'-ready-'.$index; $config = $connection->getConfig();
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/ChemicalExposureReportedFactsReadWorker.php'),
                    $connection->getDatabaseName(), (string) $reader->id, (string) $root->id, $ready[$index], $go], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(),
                        'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'],
                        'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array']);
                $process->setTimeout(60); $process->start(); $processes[] = $process;
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($ready, 'is_file')) !== 2) {
                foreach ($processes as $process) { if (! $process->isRunning()) { throw new \RuntimeException('Reader could not concurrently hold shared source: '.$process->getErrorOutput()); } }
                if (microtime(true) >= $deadline) { throw new \RuntimeException('Concurrent shared-reader readiness timed out.'); } usleep(10000);
            }
            foreach ($ready as $path) { $this->assertSame('held-source-shared-lock', file_get_contents($path)); }
            touch($go);
            foreach ($processes as $process) {
                $process->wait(); $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('read', $result['status']); $this->assertTrue($result['attention']);
                $this->assertFalse($result['source_for_update']); $this->assertGreaterThan(0, $result['source_selects']);
            }
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            foreach ([...$ready, $go] as $path) { if (is_file($path)) { unlink($path); } }
        }
        $this->assertSame(1, $root->fresh()->source_revision);
        $this->assertSame($versions, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
        $this->assertSame($receipts, ChemicalExposureCommandReceipt::where('exposure_id', $root->id)->count());
    }
    private function race(array $jobs, bool $firstClaim): array
    {
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || ! str_contains(strtolower($connection->getDatabaseName()), 'test') || $connection->transactionLevel() !== 0) {
            throw new \RuntimeException('Reported-fact workers require the admitted physical MySQL test schema.');
        }
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-private-chemical-race-'.Str::uuid(); $go = $prefix.'-go'; $bytes = $prefix.'-bytes';
        $ready = []; $processes = [];
        try {
            foreach ($jobs as $index => $job) {
                $ready[] = $prefix.'-ready-'.$index; $config = $connection->getConfig();
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/ChemicalExposureCommandWorker.php'),
                    $connection->getDatabaseName(), (string) $job['actor'], (string) $job['source'], $job['operation'],
                    base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR)), $ready[$index], $go], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(),
                        'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                        'EXPOSURE_TEST_STORAGE_ROOT' => $bytes, 'EXPOSURE_TEST_BARRIER_MODE' => $firstClaim ? 'first_claim' : 'start']);
                $process->setTimeout(60); $process->start(); $processes[] = $process;
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($ready, 'is_file')) !== count($jobs)) {
                foreach ($processes as $process) { if (! $process->isRunning()) { throw new \RuntimeException('Reported-fact worker exited before barrier: '.$process->getErrorOutput()); } }
                if (microtime(true) >= $deadline) { throw new \RuntimeException('Reported-fact worker readiness timed out.'); } usleep(10000);
            }
            foreach ($jobs as $job) {
                $this->assertSame(0, ChemicalExposureCommandReceipt::where('idempotency_key', $job['payload']['idempotency_key'])->count());
                $this->assertSame(0, ChemicalCommandIntent::where('idempotency_key', $job['payload']['idempotency_key'])->count());
            }
            touch($go); $results = [];
            foreach ($processes as $process) { $process->wait(); $this->assertTrue($process->isSuccessful(), $process->getErrorOutput()); $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR); }
            return $results;
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            foreach ([...$ready, $go] as $path) { if (is_file($path)) { unlink($path); } }
            if (dirname($bytes) !== sys_get_temp_dir() || ! str_starts_with($bytes, $prefix) || ! str_ends_with($bytes, '-bytes')) {
                throw new \RuntimeException('Reported-fact cleanup escaped the exact test temporary root.');
            }
            if (is_dir($bytes)) { File::deleteDirectory($bytes); }
        }
    }
}
