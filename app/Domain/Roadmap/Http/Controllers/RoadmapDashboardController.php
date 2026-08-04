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
        $summary = $this->dashboardService->governanceWidget();
        $triageOverload = InitiativeSuggestion::query()
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
}
