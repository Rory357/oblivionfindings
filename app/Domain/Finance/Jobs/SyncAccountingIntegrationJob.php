<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinAccountingIntegration;
use App\Domain\Finance\Services\GlSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAccountingIntegrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    public function __construct(
        public int $integrationId,
    ) {}

    public function handle(GlSyncService $syncService): void
    {
        $integration = FinAccountingIntegration::find($this->integrationId);

        if (! $integration) {
            Log::warning('SyncAccountingIntegrationJob: integration not found', [
                'integration_id' => $this->integrationId,
            ]);

            return;
        }

        if (! $integration->is_active) {
            Log::info('SyncAccountingIntegrationJob: integration is inactive, skipping', [
                'integration_id' => $this->integrationId,
            ]);

            return;
        }

        Log::info('SyncAccountingIntegrationJob: starting full sync', [
            'integration_id' => $integration->id,
            'provider' => $integration->provider,
            'organization_id' => $integration->organization_id,
        ]);

        try {
            $integration->update([
                'last_sync_status' => 'pending',
            ]);

            $logs = $syncService->fullSync($integration);

            $totalErrors = 0;
            $totalSuccess = 0;
            foreach ($logs as $log) {
                $totalErrors += $log->error_count;
                $totalSuccess += $log->success_count;
            }

            Log::info('SyncAccountingIntegrationJob: full sync completed', [
                'integration_id' => $integration->id,
                'total_success' => $totalSuccess,
                'total_errors' => $totalErrors,
                'sync_logs' => count($logs),
            ]);
        } catch (\Throwable $e) {
            Log::error('SyncAccountingIntegrationJob: sync failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $integration->update([
                'last_sync_at' => now(),
                'last_sync_status' => 'failed',
                'last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncAccountingIntegrationJob: job failed permanently', [
            'integration_id' => $this->integrationId,
            'error' => $exception->getMessage(),
        ]);

        $integration = FinAccountingIntegration::find($this->integrationId);
        if ($integration) {
            $integration->update([
                'last_sync_at' => now(),
                'last_sync_status' => 'failed',
                'last_error' => 'Sync job failed after all retries: ' . $exception->getMessage(),
            ]);
        }
    }
}
