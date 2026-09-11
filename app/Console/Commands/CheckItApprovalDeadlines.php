<?php

namespace App\Console\Commands;

use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Domain\It\Services\ItTicketApprovalService;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use Illuminate\Console\Command;
use Throwable;

/** Bounded due-time processing on canonical approvals; external dispatch belongs to the outbox drain. */
class CheckItApprovalDeadlines extends Command
{
    public const AUTOMATION_KEY = 'it.check-approval-deadlines';

    protected $signature = 'it:check-approval-deadlines {--limit=100 : Maximum approvals per due-time queue (1–1000)}';

    protected $description = 'Expire overdue pending approvals and prepare explicitly scheduled reminders';

    public function handle(ItTicketApprovalService $approvals, ItAutomationRunRecorder $runs): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be an integer from 1 to 1000.');

            return self::INVALID;
        }
        $run = null;
        $started = hrtime(true);
        $counts = ['expired' => 0, 'reminder_prepared' => 0, 'deferred' => 0, 'skipped' => 0, 'failed' => 0];
        try {
            $run = $runs->begin(self::AUTOMATION_KEY);
            $approvals->requireEvidenceStorage();
            $expired = ItTicketApproval::query()->where('status', 'pending')->where('expires_at', '<=', now())
                ->orderBy('expires_at')->orderBy('id')->limit($limit)->pluck('id');
            $reminders = ItTicketApproval::query()->where('status', 'pending')->whereNull('reminder_prepared_at')
                ->where('remind_at', '<=', now())->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('ticket', fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES)->whereNull('merged_into_ticket_id'))
                ->orderBy('reminder_last_checked_at')->orderBy('remind_at')->orderBy('id')->limit($limit)->pluck('id');
            foreach ($expired->merge($reminders)->unique() as $id) {
                try {
                    $counts[$approvals->processTiming((int) $id)]++;
                } catch (Throwable) {
                    // The row transaction rolled back. Continue unrelated due work; do not report delivery or expose private error text.
                    $counts['failed']++;
                }
            }
            $status = $counts['failed'] === 0 && $counts['deferred'] === 0 ? 'succeeded' : 'failed';
            $runs->completeRun($run, $status, (int) ((hrtime(true) - $started) / 1_000_000),
                $status === 'failed' ? 'Some approvals need a retry or an eligible approver.' : null, $counts);
            $this->line(json_encode(['status' => $status, 'counts' => $counts, 'notification_delivery' => 'not_attempted'], JSON_THROW_ON_ERROR));

            return $status === 'succeeded' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            if ($run) {
                try {
                    $runs->completeRun($run, 'failed', (int) ((hrtime(true) - $started) / 1_000_000), 'Approval timing could not be fully recorded. Retry the bounded check.', $counts);
                } catch (Throwable) {
                    // Failure to finalize run evidence must not become success.
                }
            }
            $this->error('Approval timing could not be fully verified. Check setup and retry; prior committed outcomes are preserved.');

            return self::FAILURE;
        }
    }
}
