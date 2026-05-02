<?php

namespace App\Domain\Roadmap\Http\Controllers;

use App\Domain\Roadmap\Http\Controllers\Concerns\ProvidesRoadmapInertiaProps;
use App\Domain\Roadmap\Http\Requests\StoreQuarterlyPlanRequest;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Domain\Roadmap\Services\QuarterlyRoadmapPlannerService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuarterlyPlanController extends Controller
{
    use ProvidesRoadmapInertiaProps;

    public function __construct(
        protected QuarterlyRoadmapPlannerService $plannerService,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $tenantId = $this->tenantId($request);

        $query = QuarterlyRoadmapPlan::query()
            ->forTenant($tenantId)
            ->withCount('items')
            ->when($request->filled('fiscal_year'), fn ($q) => $q->where('fiscal_year', $request->integer('fiscal_year')))
            ->when($request->filled('quarter'), fn ($q) => $q->where('quarter', $request->integer('quarter')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->orderByDesc('fiscal_year')
            ->orderByDesc('quarter')
            ->orderByDesc('revision_no');

        $items = $query
            ->paginate($this->paginationPerPage($request, 20, 100))
            ->withQueryString();

        if ($this->shouldReturnJson($request)) {
            return response()->json(['items' => $items]);
        }

        return Inertia::render('Roadmap/QuarterlyPlans/Index', [
            'items' => $items,
            'filters' => [
                'fiscal_year' => $request->input('fiscal_year'),
                'quarter' => $request->input('quarter'),
                'status' => $request->input('status'),
            ],
            'can' => $this->roadmapCan($request),
        ]);
    }

    public function generate(StoreQuarterlyPlanRequest $request)
    {
        $data = $request->validated();

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
        $item = $plan->load('items.initiative');

        if ($this->shouldReturnJson($request)) {
            return response()->json(['item' => $item]);
        }

        return Inertia::render('Roadmap/QuarterlyPlans/Show', [
            'item' => $item,
            'can' => $this->roadmapCan($request),
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

    protected function shouldReturnJson(Request $request): bool
    {
        return $request->expectsJson()
            || $request->wantsJson()
            || $request->ajax();
    }
}
