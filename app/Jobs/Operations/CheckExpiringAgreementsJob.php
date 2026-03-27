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

    public function __construct(
        public int $organizationId
    ) {}

    public function handle(FundingService $fundingService, OpsNotificationService $notificationService): void
    {
        $expiring = $fundingService->getExpiringAgreements($this->organizationId);
        $budgetAlerts = $fundingService->getBudgetAlerts($this->organizationId);

        foreach ($expiring as $agreement) {
            if ($agreement->created_by) {
                $notificationService->notifySpecific(
                    $agreement->created_by,
                    $this->organizationId,
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
                    $this->organizationId,
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

        Log::info("Checked expiring agreements for org {$this->organizationId}: {$expiring->count()} expiring, {$budgetAlerts->count()} budget alerts.");
    }
}
