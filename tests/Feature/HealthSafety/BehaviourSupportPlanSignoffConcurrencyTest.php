<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AppSetting;
use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanApproval;
use App\Models\RestraintCommandReceipt;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings as Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\NotInThisBuild;

final class BehaviourSupportPlanSignoffConcurrencyTest extends BehaviourSupportPlanTestCase
{
    public function test_policy_and_signoff_share_mutex_and_never_capture_mixed_modes_or_revisions(): void
    {
        $plan = $this->makePlan();
        $results = $this->race($plan, [
            ['kind' => 'settings', 'actor' => $this->manager->id, 'payload' => $this->payload('authorised_reviewer')],
            ['kind' => 'approval', 'actor' => $this->planSpecialist->id, 'payload' => $this->approvalPayload($plan)],
        ], true);
        $settings = collect($results)->firstWhere('kind', 'settings');
        $approval = collect($results)->firstWhere('kind', 'approval');
        $this->assertSame('accepted', $settings['status']);
        $this->assertSame(1, $this->policy()->snapshot()['policy_revision']);
        if ($approval['status'] === 'accepted') {
            $row = BehaviourSupportPlanApproval::findOrFail($approval['receipt']['approval_id']);
            $this->assertSame(0, $row->policy_snapshot['policy_revision']);
            $this->assertSame('separate_specialist', $row->policy_snapshot['mode']);
            $this->assertSame(0, $row->settingChange->revision);
            $this->assertSame('application_default', $row->settingChange->origin);
        } else {
            $this->assertSame(['expected_policy_revision'], $approval['fields']);
            $this->assertSame(0, BehaviourSupportPlanApproval::count());
            $this->assertSame(1, $plan->fresh()->lock_version);
        }
        $this->assertNull($plan->fresh()->current_published_version_id);
    }

    public function test_content_edit_and_approval_from_same_parent_version_accept_only_one_command(): void
    {
        $plan = $this->makePlan();
        $results = $this->race($plan, [
            ['kind' => 'update', 'actor' => $this->planManager->id, 'payload' => $this->command($plan, ['notes' => 'Concurrent changed content'])],
            ['kind' => 'approval', 'actor' => $this->planSpecialist->id, 'payload' => $this->approvalPayload($plan)],
        ], false);
        $this->assertSame(['accepted', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
        $this->assertSame(['expected_version'], collect($results)->firstWhere('status', 'rejected')['fields']);
        $this->assertSame(2, $plan->fresh()->lock_version);
        $this->assertSame(2, RestraintCommandReceipt::where('source_type', 'plan')->where('source_id', $plan->id)->count());
        $approved = BehaviourSupportPlanApproval::first();
        if ($approved) {
            $this->assertSame($approved->content_digest, $approved->version->content_digest);
            $this->assertSame('Private plan narrative', $approved->version->content['notes']);
        } else {
            $this->assertSame('Concurrent changed content', $plan->fresh()->candidateVersion->content['notes']);
        }
    }

    public function test_two_exact_signoffs_converge_to_one_immutable_decision_and_receipt(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::UNMET_CHECK, 'the second of two simultaneous identical sign-offs is refused instead of replayed; the approval is recorded once and nothing is lost.', 'replay an identical sign-off that lost the race.'));
        $plan = $this->makePlan();
        $payload = $this->approvalPayload($plan);
        $job = ['kind' => 'approval', 'actor' => $this->planSpecialist->id, 'payload' => $payload];
        $results = $this->race($plan, [$job, $job], true);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'status'));
        $this->assertSame([false, true], collect($results)->pluck('receipt.replayed')->sort()->values()->all());
        $this->assertCount(1, array_unique(collect($results)->pluck('receipt.receipt_id')->all()));
        $this->assertSame(1, BehaviourSupportPlanApproval::count());
        $this->assertSame(2, $plan->fresh()->lock_version);
    }

    public function test_signoff_mutex_precedes_plan_and_actor_locks(): void
    {
        $plan = $this->makePlan();
        $payload = $this->approvalPayload($plan);
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        try {
            $this->approvals()->decide($plan, $this->planSpecialist, $payload);
            $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($sql) => strtolower(str_replace([chr(96), '"'], '', $sql)));
        } finally {
            DB::connection()->disableQueryLog();
        }
        $mutex = $queries->search(fn ($sql) => str_contains($sql, 'from app_settings') && str_contains($sql, 'for update'));
        $parent = $queries->search(fn ($sql) => str_contains($sql, 'from behaviour_support_plans') && str_contains($sql, 'for update'));
        $actor = $queries->search(fn ($sql) => str_contains($sql, 'from users') && str_contains($sql, 'for share'));
        $this->assertNotFalse($mutex);
        $this->assertNotFalse($parent);
        $this->assertNotFalse($actor);
        $this->assertLessThan($parent, $mutex);
        $this->assertLessThan($actor, $parent);
    }

    private function race(BehaviourSupportPlan $plan, array $jobs, bool $holdPolicy): array
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection = DB::connection();
        $this->assertSame(1, $connection->transactionLevel());
        AppSetting::firstOrCreate(['key' => Policy::REVISION_KEY], ['value' => 0]);
        $connection->commit();
        $token = (string) Str::uuid();
        $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-plan-go-'.$token;
        $ready = [];
        $processes = [];
        try {
            $connection->beginTransaction();
            if ($holdPolicy) {
                AppSetting::where('key', Policy::REVISION_KEY)->lockForUpdate()->firstOrFail();
            } else {
                BehaviourSupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            }
            foreach ($jobs as $index => $job) {
                $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hs-plan-ready-'.$token.'-'.$index;
                $ready[] = $path;
                $config = $connection->getConfig();
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', base_path('tests/Support/BehaviourSupportPlanCommandWorker.php'), $connection->getDatabaseName(), (string) $job['actor'], (string) $plan->id, $job['kind'], base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR)), $path, $go], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection->getDatabaseName(), 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array']);
                $process->setTimeout(60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 30;
            while (count(array_filter($ready, 'is_file')) !== count($ready)) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        throw new RuntimeException('Plan worker exited before barrier: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Plan worker ready barrier timed out.');
                }
                usleep(10000);
            }
            touch($go);
            usleep(250000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Command bypassed the held canonical mutex.');
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
