<?php

namespace App\Domain\Roadmap\Http\Controllers;

use App\Domain\Roadmap\Http\Controllers\Concerns\ProvidesRoadmapInertiaProps;
use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Domain\Roadmap\Services\RoadmapDashboardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RoadmapDashboardController extends Controller
{
    use ProvidesRoadmapInertiaProps;

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

        return Inertia::render('Roadmap/Dashboard', [
            'summary' => $payload['summary'],
            'triage' => $payload['triage'],
            'generatedAt' => $payload['generated_at'],
            'managers' => $this->roadmapManagerOptions($request),
            'can' => $this->roadmapCan($request),
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
