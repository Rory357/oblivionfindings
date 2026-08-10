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
        $status = $request->filled('status')
            ? $request->string('status')->value()
            : ($this->shouldReturnJson($request) ? null : 'pending');

        $query = DecisionRequest::query()->with(['requester:id,name']);

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
        $data = $request->validated();

        $this->decisionService->resolveRequest(
            $decisionRequest,
            $data['status'],
            $request->user()?->id,
            $data['notes'] ?? null,
        );

        return response()->json(['item' => $decisionRequest->fresh()]);
    }

    protected function shouldReturnJson(Request $request): bool
    {
        return $request->expectsJson()
            || $request->wantsJson()
            || $request->ajax();
    }
}
