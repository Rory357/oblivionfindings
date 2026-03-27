<?php

namespace App\Services\Operations;

use App\Models\ClientOnboardingWorkflow;
use App\Models\ClientOnboardingStep;

class OnboardingService
{
    public function getOverdueSteps(int $organizationId): \Illuminate\Database\Eloquent\Collection
    {
        return ClientOnboardingStep::whereHas('workflow', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId)
                  ->where('status', 'in_progress');
            })
            ->where('status', 'pending')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->with(['workflow.client:id,first_name,last_name'])
            ->get();
    }

    public function checkWorkflowCompletion(ClientOnboardingWorkflow $workflow): bool
    {
        $totalSteps = $workflow->steps()->count();
        $completedSteps = $workflow->steps()->where('status', 'completed')->count();

        if ($totalSteps > 0 && $totalSteps === $completedSteps) {
            $workflow->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            return true;
        }

        return false;
    }

    public function getStaleWorkflows(int $organizationId, int $staleDays = 14): \Illuminate\Database\Eloquent\Collection
    {
        return ClientOnboardingWorkflow::where('organization_id', $organizationId)
            ->where('status', 'in_progress')
            ->where('updated_at', '<', now()->subDays($staleDays))
            ->with(['client:id,first_name,last_name'])
            ->get();
    }
}
