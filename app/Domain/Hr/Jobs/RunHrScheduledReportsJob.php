<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrReportExport;
use App\Domain\Hr\Models\HrReportSubscription;
use App\Domain\Hr\Notifications\HrScheduledReportReadyNotification;
use App\Domain\Hr\Services\HrReportingService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunHrScheduledReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(HrReportingService $reportingService): void
    {
        $query = HrReportSubscription::query()
            ->due()
            ->orderBy('next_run_at');

        $processed = 0;

        $query->chunkById(100, function ($subscriptions) use ($reportingService, &$processed) {
            foreach ($subscriptions as $subscription) {
                try {
                    $export = $reportingService->createExport(
                        reportType: $subscription->report_type,
                        filters: (array) ($subscription->filters ?? []),
                        generatedBy: null,
                        subscription: $subscription,
                    );

                    $subscription->update([
                        'last_run_at' => now(),
                        'next_run_at' => $reportingService->calculateNextRunAt($subscription, now()),
                        'last_status' => 'success',
                        'last_error' => null,
                    ]);

                    $this->notifyRecipients($subscription, $export);
                    $processed++;
                } catch (\Throwable $exception) {
                    $subscription->update([
                        'last_run_at' => now(),
                        'last_status' => 'failed',
                        'last_error' => 'Report generation failed. Review the application logs.',
                        'next_run_at' => $reportingService->calculateNextRunAt($subscription, now()),
                    ]);

                    Log::warning('Scheduled HR report run failed', [
                        'subscription_id' => $subscription->id,
                        'report_type' => $subscription->report_type,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        });

        Log::info('Scheduled HR report processing completed.', [
            'processed' => $processed,
        ]);
    }

    protected function notifyRecipients(HrReportSubscription $subscription, HrReportExport $export): void
    {
        $recipientIds = collect($subscription->recipient_user_ids ?? [])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        User::query()
            ->whereIn('id', $recipientIds->all())
            ->whereNotNull('approved_at')
            ->with(['roles.permissions', 'permissionOverrides'])
            ->chunkById(100, function ($users) use ($export) {
                foreach ($users as $user) {
                    if (! $user->canDo('hr.reports.view')) {
                        continue;
                    }

                    try {
                        $user->notify(new HrScheduledReportReadyNotification($export));
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to notify HR report recipient', [
                            'user_id' => $user->id,
                            'export_id' => $export->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });
    }
}
