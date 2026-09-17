<?php

namespace App\Domain\It\Services;

use App\Models\ItChange;
use App\Models\ItMajorIncident;
use App\Models\ItProblem;
use App\Models\User;

final class ItSpecialistWorkspaceSummary
{
    public function forWorkspace(string $workspace, User $user): array
    {
        [$model, $states] = match ($workspace) {
            'problems' => [ItProblem::class, ['investigating' => 'Investigating', 'known_error' => 'Known errors', 'resolved' => 'Resolved']],
            'changes' => [ItChange::class, ['approval_pending' => 'Awaiting approval', 'scheduled' => 'Scheduled', 'failed' => 'Failed']],
            'major-incidents' => [ItMajorIncident::class, ['declared' => 'Declared', 'responding' => 'Responding', 'monitoring' => 'Monitoring']],
        };
        $query = $model::query()->whereHas('ticket', fn ($tickets) => app(ItWorkAccessService::class)->applyViewScope($tickets, $user));
        $meters = [['label' => 'All records', 'value' => (clone $query)->count(), 'href' => '/it/'.$workspace]];
        foreach ($states as $state => $label) {
            $meters[] = [
                'label' => $label,
                'value' => (clone $query)->whereHas('ticket', fn ($tickets) => $tickets->where('workflow_state', $state))->count(),
                'href' => '/it/'.$workspace.'?state='.$state,
            ];
        }

        return $meters;
    }
}
