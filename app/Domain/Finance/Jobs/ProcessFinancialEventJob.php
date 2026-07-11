<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinFinancialEvent;
use App\Domain\Finance\Services\FinancialEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that processes a single financial event through the GL posting pipeline.
 *
 * Observers dispatch this job instead of calling FinancialEventService directly.
 * This ensures:
 * 1. Observers never block the HTTP request / model save
 * 2. Transient GL failures (missing fiscal period, etc.) are retried automatically
 * 3. Permanently failed events are logged and visible for admin review
 */
class ProcessFinancialEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Laravel queue retry — up to 3 attempts with backoff.
     */
    public int $tries = 3;

    public array $backoff = [10, 60, 300]; // 10s, 1min, 5min

    /**
     * @param  array  $eventData  The full data payload for FinancialEventService::record()
     */
    public function __construct(
        public readonly array $eventData,
    ) {}

    public function handle(FinancialEventService $service): void
    {
        $eventType = $this->eventData['event_type'] ?? 'unknown';
        $sourceType = $this->eventData['source_type'] ?? 'unknown';
        $sourceId = $this->eventData['source_id'] ?? 0;

        try {
            $event = $service->record($this->eventData);

            Log::info("ProcessFinancialEventJob: Posted [{$eventType}] for {$sourceType}#{$sourceId} → journal #{$event->journal_id}");
        } catch (\Throwable $e) {
            Log::error("ProcessFinancialEventJob: Failed [{$eventType}] for {$sourceType}#{$sourceId} (attempt {$this->attempts()}): {$e->getMessage()}");

            // If we've exhausted retries, mark the event as permanently failed
            if ($this->attempts() >= $this->tries) {
                $this->markPermanentlyFailed($e);

                return; // Don't rethrow — we've handled the final failure
            }

            throw $e; // Rethrow so Laravel retries the job
        }
    }

    /**
     * Mark the financial event as permanently failed after all retries exhausted.
     */
    private function markPermanentlyFailed(\Throwable $e): void
    {
        $sourceType = $this->eventData['source_type'] ?? '';
        $sourceId = $this->eventData['source_id'] ?? 0;
        $eventType = $this->eventData['event_type'] ?? '';
        $amount = (string) ($this->eventData['amount'] ?? '0');

        // Find the event if it was already created in a prior attempt
        $idempotencyKey = FinFinancialEvent::buildIdempotencyKey(
            $sourceType,
            $sourceId,
            $eventType,
            $amount,
            $this->eventData['source_updated_at'] ?? null,
        );

        $event = FinFinancialEvent::where('idempotency_key', $idempotencyKey)->first();

        if ($event && $event->status !== 'posted') {
            $event->update([
                'status' => 'failed',
                'failure_reason' => "Permanently failed after {$this->tries} attempts: {$e->getMessage()}",
                'retry_count' => $this->tries,
            ]);
        }

        Log::critical("ProcessFinancialEventJob: PERMANENTLY FAILED [{$eventType}] for {$sourceType}#{$sourceId}: {$e->getMessage()}");
    }

    /**
     * Handle a job that failed completely (called by Laravel after all retries).
     */
    public function failed(\Throwable $e): void
    {
        $this->markPermanentlyFailed($e);
    }

    /*
     * NOTE: do NOT add a `queue()` METHOD to route this job to a named queue.
     *
     * Laravel's Bus\Dispatcher::dispatchToQueue() treats a `queue()` method as a
     * custom "enqueue yourself" hook: when the method exists it calls
     * $command->queue($queueInstance, $command) and expects THAT method to push the
     * job onto the queue. A method that merely returns a queue name (as this class
     * previously did — `queue(): string { return 'finance'; }`) silently swallows the
     * dispatch — the job is never pushed, so under the `sync` connection handle()
     * never runs and no GL journal posts. This broke every observer-dispatched GL
     * capture (house-ledger, fuel, maintenance).
     *
     * To route to a specific queue, set the `$queue` PROPERTY (public string $queue =
     * 'finance';) or call ->onQueue('finance') at dispatch — and make sure a worker
     * actually consumes that queue. Today the app runs `sync` with no dedicated queue
     * worker, so this job executes inline on the default queue.
     */
}
