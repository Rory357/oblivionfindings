<?php

namespace App\Domain\Roadmap\Http\Controllers;

use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Domain\Roadmap\Services\RoadmapDashboardService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class RoadmapDashboardController extends Controller
{
    public function __construct(
        protected RoadmapDashboardService $dashboardService,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $user = $request->user();

        $summary = $this->dashboardService->governanceWidget($tenantId);
        $triageOverload = InitiativeSuggestion::query()
            ->forTenant($tenantId)
            ->where('status', InitiativeSuggestion::STATUS_TRIAGE_PENDING)
            ->count();

        $payload = [
            'summary' => $summary,
            'triage' => [
                'pending' => $triageOverload,
                'overload' => $triageOverload > 100,
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($payload);
        }

        $managers = collect();
        if ($user?->canDo('roadmap.manage')) {
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

            $managers = User::query()
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

        return Inertia::render('Roadmap/Dashboard', [
            'summary' => $payload['summary'],
            'triage' => $payload['triage'],
            'generatedAt' => $payload['generated_at'],
            'managers' => $managers,
            'can' => [
                'viewDashboard' => (bool) ($user?->canDo('roadmap.view') || $user?->canDo('governance.view')),
                'viewRoadmap' => (bool) $user?->canDo('roadmap.view'),
                'manageRoadmap' => (bool) $user?->canDo('roadmap.manage'),
                'approveRoadmap' => (bool) $user?->canDo('roadmap.approve'),
                'manageBudget' => (bool) $user?->canDo('roadmap.budget.manage'),
                'viewDecisions' => (bool) ($user?->canDo('roadmap.decisions.view') || $user?->canDo('governance.resolutions.view')),
                'manageDecisions' => (bool) ($user?->canDo('roadmap.decisions.manage') || $user?->canDo('governance.resolutions.manage')),
                'exportReports' => (bool) $user?->canDo('roadmap.reports.export'),
            ],
        ]);
    }

    protected function tenantId(Request $request): ?int
    {
        $user = $request->user();

        if ($request->filled('tenant_id')) {
            return (int) $request->integer('tenant_id');
        }

        return $user?->tenant_id ?? null;
    }
}
