<?php

namespace App\Domain\Roadmap\Http\Controllers;

use App\Domain\Roadmap\Http\Controllers\Concerns\ProvidesRoadmapInertiaProps;
use App\Domain\Roadmap\Http\Requests\UpdateDecisionRequestRequest;
use App\Domain\Roadmap\Models\DecisionRequest;
use App\Domain\Roadmap\Services\RoadmapDecisionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DecisionRequestController extends Controller
{
    use ProvidesRoadmapInertiaProps;

    public function __construct(
        protected RoadmapDecisionService $decisionService,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $tenantId = $this->tenantId($request);
        $status = $request->filled('status')
            ? $request->string('status')->value()
            : ($this->shouldReturnJson($request) ? null : 'pending');

        $query = DecisionRequest::query()->with(['requester:id,name']);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $items = $query
            ->orderBy('due_date')
            ->paginate($this->paginationPerPage($request, 30, 100))
            ->withQueryString();

        if ($this->shouldReturnJson($request)) {
            return response()->json(['items' => $items]);
        }

        return Inertia::render('Roadmap/Decisions/Index', [
            'items' => $items,
            'filters' => [
                'status' => $status,
            ],
            'can' => $this->roadmapCan($request),
        ]);
    }

    public function resolve(UpdateDecisionRequestRequest $request, DecisionRequest $decisionRequest)
    {
        $this->assertTenant($request, $decisionRequest->tenant_id);

        $data = $request->validated();

        $this->decisionService->resolveRequest(
            $decisionRequest,
            $data['status'],
            $request->user()?->id,
            $data['notes'] ?? null,
        );

        return response()->json(['item' => $decisionRequest->fresh()]);
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
