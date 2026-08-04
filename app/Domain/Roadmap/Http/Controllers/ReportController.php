<?php

namespace App\Domain\Roadmap\Http\Controllers;

use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Domain\Roadmap\Models\ReportSnapshot;
use App\Domain\Roadmap\Services\RoadmapReportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        protected RoadmapReportService $reportService,
    ) {}

    public function generate(Request $request, string $type)
    {
        $validated = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:roadmap_quarterly_plans,id'],
        ]);

        $plan = null;
        if (! empty($validated['plan_id'])) {
            $plan = QuarterlyRoadmapPlan::query()->findOrFail($validated['plan_id']);
        }

        $snapshot = $this->reportService->generate($type, $plan, $request->user()?->id);

        return response()->json([
            'item' => $snapshot,
        ], 201);
    }

    public function show(Request $request, ReportSnapshot $snapshot)
    {
        return response()->json([
            'item' => $snapshot,
        ]);
    }
}
