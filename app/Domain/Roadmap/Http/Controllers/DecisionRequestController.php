<?php

namespace App\Domain\Roadmap\Http\Controllers;

use App\Domain\Roadmap\Models\DecisionRequest;
use App\Domain\Roadmap\Services\RoadmapDecisionService;
use App\Domain\Roadmap\Http\Requests\UpdateDecisionRequestRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DecisionRequestController extends Controller
{
    public function __construct(
        protected RoadmapDecisionService $decisionService,
    ) {}

    public function index(Request $request): JsonResponse|RedirectResponse
    {
        if (! $this->shouldReturnJson($request)) {
            return redirect()->route('roadmap.dashboard');
        }

        $tenantId = $this->tenantId($request);

        $query = DecisionRequest::query();
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        return response()->json([
            'items' => $query->orderBy('due_date')->paginate(30),
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
