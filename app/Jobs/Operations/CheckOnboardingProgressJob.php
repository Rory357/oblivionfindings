<?php

namespace App\Jobs\Operations;

use App\Services\Operations\OnboardingService;
use App\Services\Operations\OpsNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckOnboardingProgressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $organizationId
    ) {}

    public function handle(OnboardingService $onboardingService, OpsNotificationService $notificationService): void
    {
        try {
            $overdueSteps = $onboardingService->getOverdueSteps($this->organizationId);
            $staleWorkflows = $onboardingService->getStaleWorkflows($this->organizationId);

            $adminIds = \App\Models\User::where('organization_id', $this->organizationId)
                ->whereIn('role', ['admin', 'manager', 'coordinator'])
                ->pluck('id')
                ->toArray();

            if ($overdueSteps->isNotEmpty()) {
                $notificationService->notifyBulk(
                    $adminIds,
                    $this->organizationId,
                    'Overdue Onboarding Steps',
                    sprintf('%d onboarding steps are overdue and need attention.', $overdueSteps->count()),
                    'onboarding.overdue_steps',
                    ['count' => $overdueSteps->count()]
                );
            }

            if ($staleWorkflows->isNotEmpty()) {
                $notificationService->notifyBulk(
                    $adminIds,
                    $this->organizationId,
                    'Stale Onboarding Workflows',
                    sprintf('%d onboarding workflows have not been updated in 14+ days.', $staleWorkflows->count()),
                    'onboarding.stale_workflows',
                    ['count' => $staleWorkflows->count()]
                );
            }

            Log::info("Checked onboarding for org {$this->organizationId}: {$overdueSteps->count()} overdue, {$staleWorkflows->count()} stale.");
        } catch (\Throwable $e) {
            \Log::error('CheckOnboardingProgressJob failed: ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }
}
