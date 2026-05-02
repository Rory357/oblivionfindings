<?php

namespace App\Domain\Roadmap\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait ProvidesRoadmapInertiaProps
{
    protected function roadmapCan(Request $request): array
    {
        $user = $request->user();

        return [
            'viewDashboard' => (bool) ($user?->canDo('roadmap.view') || $user?->canDo('governance.view')),
            'viewRoadmap' => (bool) $user?->canDo('roadmap.view'),
            'manageRoadmap' => (bool) $user?->canDo('roadmap.manage'),
            'approveRoadmap' => (bool) $user?->canDo('roadmap.approve'),
            'manageBudget' => (bool) $user?->canDo('roadmap.budget.manage'),
            'viewDecisions' => (bool) ($user?->canDo('roadmap.decisions.view') || $user?->canDo('governance.resolutions.view')),
            'manageDecisions' => (bool) ($user?->canDo('roadmap.decisions.manage') || $user?->canDo('governance.resolutions.manage')),
            'exportReports' => (bool) $user?->canDo('roadmap.reports.export'),
        ];
    }

    protected function roadmapManagerOptions(Request $request): Collection
    {
        if (! $request->user()?->canDo('roadmap.manage')) {
            return collect();
        }

        $managerRoleNames = [
            'admin',
            'provider_manager',
            'roadmap_manager',
            'it_manager',
            'facilities_manager',
            'maintenance_coordinator',
            'team_lead',
            'ceo',
            'coo',
            'cfo',
            'compliance_lead',
            'risk_lead',
            'board_chair',
        ];

        return User::query()
            ->staff()
            ->where(function ($query) use ($managerRoleNames) {
                $query->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', $managerRoleNames))
                    ->orWhereIn('role', $managerRoleNames);
            })
            ->with(['roles:id,name,label'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(function (User $manager) {
                $roleLabel = $manager->roles->first()?->label;

                if (! $roleLabel && is_string($manager->role) && $manager->role !== '') {
                    $roleLabel = Str::of($manager->role)->replace('_', ' ')->title()->value();
                }

                return [
                    'id' => $manager->id,
                    'name' => $manager->name,
                    'email' => $manager->email,
                    'role_label' => $roleLabel,
                ];
            })
            ->values();
    }

    protected function paginationPerPage(Request $request, int $default, int $max): int
    {
        return min(max((int) $request->integer('per_page', $default), 1), $max);
    }
}
