<?php

namespace App\Console\Commands;

use App\Domain\It\Services\ItAttachmentCleanupReadService;
use App\Domain\It\Services\ItAttachmentStorageService;
use App\Domain\It\Services\ItAutomationRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

/** Self-recorded bounded recovery, also scheduled by the canonical IT catalogue. */
class RetryItAttachmentCleanup extends Command
{
    public const AUTOMATION_KEY = 'it.retry-attachment-cleanup';

    protected $signature = 'it:retry-attachment-cleanup {--limit=100 : Maximum explicitly pending intentions (1–1000)}';

    protected $description = 'Retry a bounded batch of confirmed IT attachment rollback and accepted email-copy cleanup';

    private const COUNT_KEYS = ['requested', 'deleted', 'failed', 'deferred', 'reconciliation_required'];

    public function handle(ItAutomationRunRecorder $runs): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->emit(['status' => 'not_run', 'outcome' => 'invalid_limit', 'error_code' => 'invalid_limit',
                'message' => 'Limit must be an integer from 1 to 1000.', 'limit' => null, 'counts' => null, 'remaining' => null]);

            return self::INVALID;
        }

        $run = null;
        $started = hrtime(true);
        $counts = null;
        $remaining = null;
        $failureCode = 'readiness_unavailable';
        $report = null;
        try {
            if (! Schema::hasTable('it_automation_runs') || ! Schema::hasColumns('it_automation_runs', [
                'automation_key', 'schedule_expression', 'status', 'started_at', 'finished_at', 'runtime_ms', 'error_summary', 'result_summary',
            ])) {
                $report = $this->failure('automation_storage_unavailable', 'IT automation recording is not ready. No cleanup was attempted.');
            } else {
                $failureCode = 'run_recording_unavailable';
                // The catalogue owns cadence; this command can also be invoked manually.
                $run = $runs->begin(self::AUTOMATION_KEY);
                $failureCode = 'readiness_unavailable';
                if (! Schema::hasTable('it_attachment_storage_intents') || ! Schema::hasColumns('it_attachment_storage_intents', [
                    'intent_uuid', 'path', 'actor_user_id', 'parent_type', 'parent_id', 'content_sha256', 'original_name_sha256', 'size',
                    'state', 'attachment_id', 'revision', 'cleanup_attempts', 'cleanup_requested_at', 'last_cleanup_attempt_at', 'deleted_at', 'cleanup_error_code',
                ])) {
                    $report = $this->failure('attachment_storage_unavailable', 'IT attachment storage setup is incomplete. Apply the reviewed 000010 migration before retrying.');
                } else {
                    $failureCode = 'cleanup_retry_unavailable';
                    $result = app(ItAttachmentStorageService::class)->retryPending($limit);
                    $failureCode = 'cleanup_result_invalid';
                    $counts = $this->validatedCounts($result, $limit);
                    $failureCode = 'cleanup_evidence_unavailable';
                    $remaining = $this->remainingEvidence();
                    $report = $this->outcome($counts, $remaining);
                }
            }
        } catch (Throwable) {
            $report = $this->failure($failureCode, 'This cleanup batch could not be fully verified. Retained intentions can be checked and retried safely.');
        }

        $report += ['limit' => $limit, 'counts' => $counts, 'remaining' => $remaining];
        if ($run !== null) {
            try {
                $runs->completeRun($run, $report['status'], (int) ((hrtime(true) - $started) / 1_000_000),
                    $report['error_code'] === null ? null : $report['message'], $report);
            } catch (Throwable) {
                // Keep observed storage counts. A failed terminal record is not a
                // successful automation, even if its physical deletion succeeded.
                $report = [...$report, ...$this->failure('run_recording_unavailable', 'Cleanup evidence could not be finalized. The known counts below do not confirm a completed automation run.')];
            }
        }
        $this->emit($report);

        return $report['status'] === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    private function validatedCounts(array $result, int $limit): array
    {
        if (count($result) !== count(self::COUNT_KEYS)) {
            throw new LogicException('Unexpected cleanup result shape.');
        }
        foreach (self::COUNT_KEYS as $key) {
            if (! isset($result[$key]) || ! is_int($result[$key]) || $result[$key] < 0 || $result[$key] > $limit) {
                throw new LogicException('Unverified cleanup count.');
            }
        }
        if ($result['requested'] !== $result['deleted'] + $result['failed'] + $result['deferred'] + $result['reconciliation_required']) {
            throw new LogicException('Cleanup outcomes do not reconcile.');
        }

        return array_intersect_key($result, array_flip(self::COUNT_KEYS));
    }

    private function remainingEvidence(): array
    {
        return app(ItAttachmentCleanupReadService::class)->currentCounts();
    }

    private function outcome(array $counts, array $remaining): array
    {
        if ($counts['failed'] > 0 || $counts['deferred'] > 0) {
            return ['status' => 'failed', 'outcome' => $counts['deleted'] > 0 ? 'partial' : 'failed',
                'error_code' => 'cleanup_incomplete', 'message' => 'Some cleanup remains unconfirmed. Successful deletions are retained; failed intentions remain retryable.'];
        }
        if ($counts['reconciliation_required'] > 0 || $remaining['reconciliation_required'] > 0) {
            return ['status' => 'failed', 'outcome' => 'reconciliation_required', 'error_code' => 'reconciliation_required',
                'message' => 'Some storage intentions require canonical record reconciliation. No removal authority is inferred.'];
        }

        return ['status' => 'succeeded', 'outcome' => $counts['requested'] === 0 ? 'no_pending' : 'batch_complete', 'error_code' => null,
            'message' => $remaining['cleanup_pending'] > 0
                ? 'This bounded batch finished. More explicit cleanup intentions remain for a later pass.'
                : 'This bounded batch finished. No explicit pending cleanup was observed; reserved intentions remain unclassified.'];
    }

    private function failure(string $code, string $message): array
    {
        return ['status' => 'failed', 'outcome' => $code, 'error_code' => $code, 'message' => $message];
    }

    private function emit(array $report): void
    {
        $this->line(json_encode(['automation_key' => self::AUTOMATION_KEY,
            'checked_at' => CarbonImmutable::now('UTC')->toIso8601String(), ...$report], JSON_THROW_ON_ERROR));
    }
}
