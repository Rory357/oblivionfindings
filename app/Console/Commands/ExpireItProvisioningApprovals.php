<?php

namespace App\Console\Commands;

use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Domain\It\Services\ItProvisioningReadinessService;
use App\Domain\It\Services\ItProvisioningRequestLifecycleService;
use App\Models\ItProvisioningRequest;
use Illuminate\Console\Command;
use Throwable;

/** Bounded expiry of overdue provisioning approvals; each row is its own transaction. */
class ExpireItProvisioningApprovals extends Command
{
    public const AUTOMATION_KEY = 'it.expire-provisioning-approvals';

    protected $signature = 'it:expire-provisioning-approvals {--limit=200 : Maximum approvals per run (1–1000)}';

    protected $description = 'Mark overdue pending provisioning approvals expired so a fresh review is requested explicitly';

    public function handle(ItProvisioningRequestLifecycleService $requests, ItProvisioningReadinessService $readiness, ItAutomationRunRecorder $runs): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be an integer from 1 to 1000.');

            return self::INVALID;
        }
        $run = null;
        $started = hrtime(true);
        $counts = ['expired' => 0, 'skipped' => 0, 'failed' => 0];
        try {
            $run = $runs->begin(self::AUTOMATION_KEY);
            if (! $readiness->storageReady()) {
                $runs->completeRun($run, 'failed', (int) ((hrtime(true) - $started) / 1_000_000),
                    'Provisioning history setup is incomplete; approvals were not expired.', $counts);
                $this->error('Complete the reviewed provisioning history database update before expiring approvals.');

                return self::FAILURE;
            }
            $due = ItProvisioningRequest::query()->where('approval_required', true)->where('approval_status', 'pending')
                ->whereNotNull('approval_requested_at')->where('approval_expires_at', '<=', now())
                ->whereNotIn('status', ['done', 'cancelled'])
                ->orderBy('approval_expires_at')->orderBy('id')->limit($limit)->pluck('id');
            foreach ($due as $id) {
                try {
                    $counts[$requests->expireApproval((int) $id)]++;
                } catch (Throwable) {
                    // The row transaction rolled back. Continue unrelated due work; never expose private error text.
                    $counts['failed']++;
                }
            }
            $status = $counts['failed'] === 0 ? 'succeeded' : 'failed';
            $runs->completeRun($run, $status, (int) ((hrtime(true) - $started) / 1_000_000),
                $status === 'failed' ? 'Some provisioning approvals could not be expired. Retry the bounded check.' : null, $counts);
            $this->line(json_encode(['status' => $status, 'counts' => $counts], JSON_THROW_ON_ERROR));

            return $status === 'succeeded' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            if ($run) {
                try {
                    $runs->completeRun($run, 'failed', (int) ((hrtime(true) - $started) / 1_000_000), 'Provisioning approval expiry could not be fully recorded. Retry the bounded check.', $counts);
                } catch (Throwable) {
                    // Failure to finalize run evidence must not become success.
                }
            }
            $this->error('Provisioning approval expiry could not be fully verified. Prior committed outcomes are preserved.');

            return self::FAILURE;
        }
    }
}
