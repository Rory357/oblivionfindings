<?php

namespace App\Jobs\Operations;

use App\Services\Operations\FundingService;
use App\Services\Operations\OpsNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckExpiringAgreementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const APPLICATION_STORAGE_CONTEXT_ID = 1;

    public function handle(FundingService $fundingService, OpsNotificationService $notificationService): void
    {
        $expiring = $fundingService->getExpiringAgreements();
        $budgetAlerts = $fundingService->getBudgetAlerts();

        foreach ($expiring as $agreement) {
            if ($agreement->created_by) {
                $notificationService->notifySpecific(
                    $agreement->created_by,
                    self::APPLICATION_STORAGE_CONTEXT_ID,
                    'Service Agreement Expiring',
                    sprintf(
                        'Service agreement "%s" for %s expires on %s.',
                        $agreement->title,
                        $agreement->client?->full_name ?? 'Unknown',
                        $agreement->ends_at->format('d M Y')
                    ),
                    'service_agreement.expiring',
                    ['agreement_id' => $agreement->id, 'client_id' => $agreement->client_id]
                );
            }
        }

        foreach ($budgetAlerts as $agreement) {
            $percent = $agreement->total_budget > 0
                ? round(($agreement->budget_used / $agreement->total_budget) * 100, 1)
                : 0;

            if ($agreement->created_by) {
                $notificationService->notifySpecific(
                    $agreement->created_by,
                    self::APPLICATION_STORAGE_CONTEXT_ID,
                    'Budget Alert',
                    sprintf(
                        'Service agreement "%s" has used %s%% of its budget ($%s of $%s).',
                        $agreement->title,
                        $percent,
                        number_format($agreement->budget_used, 2),
                        number_format($agreement->total_budget, 2)
                    ),
                    'service_agreement.budget_alert',
                    ['agreement_id' => $agreement->id, 'utilisation_percent' => $percent]
                );
            }
        }

        Log::info("Checked application service agreements: {$expiring->count()} expiring, {$budgetAlerts->count()} budget alerts.");
    }
}
