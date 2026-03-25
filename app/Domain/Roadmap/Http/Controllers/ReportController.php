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
        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:roadmap_quarterly_plans,id'],
        ]);

        $plan = null;
        if (! empty($validated['plan_id'])) {
            $plan = QuarterlyRoadmapPlan::query()->findOrFail($validated['plan_id']);
            $this->assertTenant($request, $plan->tenant_id);
        }

        $snapshot = $this->reportService->generate($type, $plan, $tenantId, $request->user()?->id);

        return response()->json([
            'item' => $snapshot,
        ], 201);
    }

    public function show(Request $request, ReportSnapshot $snapshot)
    {
        $this->assertTenant($request, $snapshot->tenant_id);

        return response()->json([
            'item' => $snapshot,
        ]);
    }

    protected function tenantId(Request $request): ?int
    {
        if ($request->filled('tenant_id')) {
            return (int) $request->integer('tenant_id');
        }

        return $request->user()?->tenant_id ?? null;
    }

    protected function assertTenant(Request $request, ?int $resourceTenantId): void
    {
        $tenantId = $this->tenantId($request);

        if ($tenantId !== null && $resourceTenantId !== null && $tenantId !== $resourceTenantId) {
            abort(403, 'Tenant scope mismatch.');
        }
    }
}
