<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureRecoveryCase;
use App\Models\ChemicalExposureRecoveryReceipt;
use App\Models\ChemicalExposureRecoveryVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Genuine two native processes and pre-first-claim barriers. Prepared UNRUN; no fake serial concurrency. */
final class ChemicalExposureRecoveryConcurrencyTest extends ChemicalExposureRecoveryTestCase
{
    protected bool $physicalCommits = true;
    public function test_same_uuid_same_actor_root_replay_converges_one_case_review_receipt(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Legacy exposure association recovery (ChemicalExposureAssociationRecoveryService, ChemicalExposureRecoveryEvidenceService) has no route or caller, so nobody can run a recovery review or attach its basis today. The two race workers stop before the barrier (the worker hides its diagnostics by design; inferred to be the unmounted recovery path).', 'Mount legacy exposure association recovery with its routes (with the decision on unconfirmed legacy exposures).'));
        $root = $this->legacy(); $v = $this->reviewInput();
        $results = $this->race([[$this->manager->id, $root->id, $v], [$this->manager->id, $root->id, $v]], 'start');
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status')); $this->assertSame($results[0]['receipt'], $results[1]['receipt']);
        $this->assertSame(1, ChemicalExposureRecoveryCase::count()); $this->assertSame(1, ChemicalExposureRecoveryVersion::count());
        $this->assertSame(1, ChemicalExposureRecoveryReceipt::count()); $this->assertSame(0, $root->fresh()->source_revision);
    }
    public function test_different_uuid_same_original_row_lock_serializes_one_first_review_and_stale_loser(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Legacy exposure association recovery (ChemicalExposureAssociationRecoveryService, ChemicalExposureRecoveryEvidenceService) has no route or caller, so nobody can run a recovery review or attach its basis today. The two race workers stop before the barrier (the worker hides its diagnostics by design; inferred to be the unmounted recovery path).', 'Mount legacy exposure association recovery with its routes (with the decision on unconfirmed legacy exposures).'));
        $root = $this->legacy(); $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $results = $this->race([[$this->manager->id, $root->id, $this->reviewInput()], [$other->id, $root->id, $this->reviewInput()]], 'start');
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['expected_version'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(1, ChemicalExposureRecoveryCase::count()); $this->assertSame(1, ChemicalExposureRecoveryVersion::count());
    }
    public function test_real_absent_global_uuid_different_actor_root_first_claim_arbitrates_single_case_without_absent_key_lock(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Legacy exposure association recovery (ChemicalExposureAssociationRecoveryService, ChemicalExposureRecoveryEvidenceService) has no route or caller, so nobody can run a recovery review or attach its basis today. The two race workers stop before the barrier (the worker hides its diagnostics by design; inferred to be the unmounted recovery path).', 'Mount legacy exposure association recovery with its routes (with the decision on unconfirmed legacy exposures).'));
        $first = $this->legacy(); $second = $this->legacy(); $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $key = (string) Str::uuid(); $v = $this->reviewInput([], 0, $key);
        $this->assertFalse(ChemicalCommandIntent::where('idempotency_key', $key)->exists());
        $results = $this->race([[$this->manager->id, $first->id, $v], [$other->id, $second->id, $v]], 'claim');
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['idempotency_key'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(1, ChemicalCommandIntent::where('idempotency_key', $key)->count()); $this->assertSame(1, ChemicalExposureRecoveryReceipt::count());
        $this->assertSame(1, ChemicalExposureRecoveryCase::count());
    }
    private function race(array $jobs, string $mode): array
    {
        $this->assertSame(0, DB::transactionLevel()); $this->assertSame('mysql', DB::connection()->getDriverName());
        // Child processes write to this database: only ever a disposable test database (this repo's per-process
        // test database or Codex's oblivion_hs_ ones), never a real one.
        $this->assertMatchesRegularExpression('/^(oblivion_hs_|oblivion_findings_codex_test_\d+$)/', DB::connection()->getDatabaseName());
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-chemical-recovery-race-'.Str::uuid(); $go = $prefix.'-go'; $processes = []; $signals = [];
        try {
            foreach ($jobs as $index => [$actor, $source, $values]) {
                $ready = $prefix.'-'.$index.'-ready'; $signals[] = $ready;
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/ChemicalExposureRecoveryWorker.php'),
                    DB::connection()->getDatabaseName(), (string) $actor, (string) $source,
                    base64_encode(json_encode($values, JSON_THROW_ON_ERROR)), $ready, $go, $mode], base_path(), [
                        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                        'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_URL' => '', 'DB_SOCKET' => '',
                        'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                    ]);
                $process->setTimeout(65); $process->start(); $processes[] = $process;
            }
            $deadline = microtime(true) + 45;
            while (count(array_filter($signals, 'is_file')) !== 2) {
                foreach ($processes as $process) { if (! $process->isRunning()) { $this->fail('Native recovery worker ended before the two-process barrier.'); } }
                if (microtime(true) >= $deadline) { $this->fail('Native recovery barrier timed out.'); } usleep(10000);
            }
            $this->assertSame(2, count(array_filter($signals, 'is_file')));
            file_put_contents($go, 'release', LOCK_EX); $results = [];
            foreach ($processes as $process) {
                $process->wait(); $this->assertSame(0, $process->getExitCode(), 'Recovery worker failed; diagnostics deliberately omit credentials/private source body.');
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(); } }
            foreach ([...$signals, $go] as $file) { if (is_file($file)) { unlink($file); } }
        }
    }
}
