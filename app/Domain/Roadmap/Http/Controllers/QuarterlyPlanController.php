<?php

namespace App\Domain\Roadmap\Http\Controllers;

use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Domain\Roadmap\Services\QuarterlyRoadmapPlannerService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class QuarterlyPlanController extends Controller
{
    public function __construct(
        protected QuarterlyRoadmapPlannerService $plannerService,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);

        $query = QuarterlyRoadmapPlan::query()
            ->forTenant($tenantId)
            ->withCount('items')
            ->orderByDesc('fiscal_year')
            ->orderByDesc('quarter')
            ->orderByDesc('revision_no');

        return response()->json([
            'items' => $query->paginate(20),
        ]);
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:3000'],
            'quarter' => ['required', 'integer', 'min:1', 'max:4'],
            'preset' => ['nullable', 'string', 'max:32'],
            'tenant_id' => ['nullable', 'integer'],
        ]);

        $plan = $this->plannerService->generateDraft(
            (int) $data['fiscal_year'],
            (int) $data['quarter'],
            $data['preset'] ?? 'board_ceo',
            $data['tenant_id'] ?? $this->tenantId($request),
            $request->user()?->id,
        );

        return response()->json(['item' => $plan], 201);
    }

    public function show(Request $request, QuarterlyRoadmapPlan $plan)
    {
        $this->assertTenant($request, $plan->tenant_id);

        return response()->json([
            'item' => $plan->load('items.initiative'),
        ]);
    }

    public function submitManagerReview(Request $request, QuarterlyRoadmapPlan $plan)
    {
        $this->assertTenant($request, $plan->tenant_id);

        try {
            return response()->json([
                'item' => $this->plannerService->submitForManagerReview($plan, $request->user()?->id),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function submitExecutiveReview(Request $request, QuarterlyRoadmapPlan $plan)
    {
        $this->assertTenant($request, $plan->tenant_id);

        try {
            return response()->json([
                'item' => $this->plannerService->submitForExecutiveReview($plan, $request->user()?->id),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approve(Request $request, QuarterlyRoadmapPlan $plan)
    {
        $this->assertTenant($request, $plan->tenant_id);

        try {
            return response()->json([
                'item' => $this->plannerService->approve($plan, $request->user()?->id),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function publish(Request $request, QuarterlyRoadmapPlan $plan)
    {
        $this->assertTenant($request, $plan->tenant_id);

        try {
            return response()->json([
                'item' => $this->plannerService->publish($plan, $request->user()?->id),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function revise(Request $request, QuarterlyRoadmapPlan $plan)
    {
        $this->assertTenant($request, $plan->tenant_id);

        $data = $request->validate([
            'change_summary' => ['nullable', 'string'],
        ]);

        try {
            return response()->json([
                'item' => $this->plannerService->createRevisionFromPublished(
                    $plan,
                    $request->user()?->id,
                    $data['change_summary'] ?? null,
                ),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
