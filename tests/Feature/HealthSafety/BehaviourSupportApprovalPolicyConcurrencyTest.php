<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\BehaviourSupportPlan;
use App\Models\HealthSafetySettingChange;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings as Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;

final class BehaviourSupportApprovalPolicyConcurrencyTest extends BehaviourSupportPolicyTestCase
{
    public function test_two_first_saves_with_exact_command_converge_to_one_revision_and_audit(): void
    {
        $payload = $this->payload('authorised_reviewer');
        $results = $this->race([$payload, $payload]);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status'));
        $this->assertSame([false, true], collect($results)->pluck('receipt.replayed')->sort()->values()->all());
        $this->assertSame(1, $this->policy()->snapshot()['policy_revision']);
        $this->assertSame(2, HealthSafetySettingChange::count());
        $this->assertSame(1, AuditLog::where('action', 'like', 'health_safety.settings.restraint_plan_approval.%')->count());
        $this->assertCount(1, array_unique(array_column(array_column($results, 'receipt'), 'setting_change_id')));
    }

    public function test_two_distinct_commands_from_same_revision_accept_one_and_preserve_the_stale_draft(): void
    {
        $results = $this->race([$this->payload('authorised_reviewer'), $this->payload(Policy::DEFAULT_MODE)]);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $rejected = collect($results)->firstWhere('status', 'rejected');
        $this->assertSame(['expected_policy_revision'], $rejected['fields']);
        $accepted = collect($results)->firstWhere('status', 'accepted')['receipt'];
        $this->assertSame($accepted['mode'], $this->policy()->snapshot()['mode']);
        $this->assertSame(1, $this->policy()->snapshot()['policy_revision']);
        $this->assertSame(1, AuditLog::where('action', 'like', 'health_safety.settings.restraint_plan_approval.%')->count());
    }

    public function test_policy_mutex_precedes_caller_owned_plan_lock_and_actor_authority_reads(): void
    {
        $plan = BehaviourSupportPlan::factory()->draft()->create();
        DB::connection()->enableQueryLog();
        try {
            $this->policy()->withLockedPolicy(function (array $snapshot) use ($plan): void {
                BehaviourSupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                $this->policy()->assertUsable($snapshot, 0);
            });
            $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($sql) => strtolower(str_replace(['`', '"'], '', $sql)));
        } finally {
            DB::connection()->disableQueryLog();
        }
        $mutexLock = $queries->search(fn ($sql) => str_contains($sql, 'from app_settings') && str_contains($sql, 'for update'));
        $planLock = $queries->search(fn ($sql) => str_contains($sql, 'from behaviour_support_plans') && str_contains($sql, 'for update'));
        $this->assertNotFalse($mutexLock);
        $this->assertNotFalse($planLock);
        $this->assertLessThan($planLock, $mutexLock);
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        try {
            $this->policy()->save($this->manager, $this->payload());
            $saveQueries = collect(DB::getQueryLog())->pluck('query')->map(fn ($sql) => strtolower(str_replace(['`', '"'], '', $sql)));
        } finally {
            DB::connection()->disableQueryLog();
        }
        $policyMutex = $saveQueries->search(fn ($sql) => str_contains($sql, 'from app_settings') && str_contains($sql, 'for update'));
        $actorLock = $saveQueries->search(fn ($sql) => str_contains($sql, 'from users') && str_contains($sql, 'for share'));
        $this->assertNotFalse($actorLock);
        $this->assertLessThan($actorLock, $policyMutex);
    }

    /** Real MySQL sessions, with the existing committed-fixture cleanup contract. */
    private function race(array $payloads): array
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection = DB::connection();
        $this->assertSame(1, $connection->transactionLevel());
        AppSetting::firstOrCreate(['key' => Policy::REVISION_KEY], ['value' => 0]);
        $connection->commit();
        $token = (string) Str::uuid();
        $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-policy-go-'.$token;
        $ready = [];
        $processes = [];
        try {
            $connection->beginTransaction();
            AppSetting::where('key', Policy::REVISION_KEY)->lockForUpdate()->firstOrFail();
            foreach ($payloads as $index => $payload) {
                $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-policy-ready-'.$token.'-'.$index;
                $ready[] = $path;
                $config = $connection->getConfig();
                $process = new Process([
                    PHP_BINARY, base_path('tests/Support/BehaviourSupportPolicySettingsWorker.php'),
                    $connection->getDatabaseName(), (string) $this->manager->id,
                    base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $path, $go,
                ], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(),
                    'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                    'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                ]);
                $process->setTimeout(60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($ready, 'is_file')) !== count($ready)) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        throw new RuntimeException('A policy worker exited before its barrier: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Policy workers did not become ready.');
                }
                usleep(10000);
            }
            touch($go);
            usleep(250000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'The policy save bypassed the shared organisational mutex.');
            }
            $connection->commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$ready, $go] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $connection->beginTransaction();
        }
    }
}
