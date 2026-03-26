<?php

namespace App\Jobs\Operations;

use App\Services\Operations\CarePlanService;
use App\Services\Operations\OpsNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckCarePlanReviewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $organizationId
    ) {}

    public function handle(CarePlanService $carePlanService, OpsNotificationService $notificationService): void
    {
        $dueForReview = $carePlanService->getReviewsDue($this->organizationId, 14);

        foreach ($dueForReview as $plan) {
            if ($plan->created_by) {
                $notificationService->notifySpecific(
                    $plan->created_by,
                    $this->organizationId,
                    'Care Plan Review Due',
                    sprintf(
                        'Care plan "%s" for %s is due for review by %s.',
                        $plan->title,
                        $plan->client?->full_name ?? 'Unknown',
                        $plan->next_review_at->format('d M Y')
                    ),
                    'care_plan.review_due',
                    ['care_plan_id' => $plan->id, 'client_id' => $plan->client_id]
                );
            }
        }

        Log::info("Checked care plan reviews for org {$this->organizationId}: {$dueForReview->count()} due.");
    }
}
