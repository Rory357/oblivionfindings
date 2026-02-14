<?php

namespace App\Domain\Roadmap\Http\Controllers;

use App\Domain\Roadmap\Services\RoadmapBudgetReplanService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function __construct(
        protected RoadmapBudgetReplanService $budgetReplanService,
    ) {}

    public function replan(Request $request)
    {
        $data = $request->validate([
            'new_envelope' => ['required', 'numeric', 'min:0'],
            'tenant_id' => ['nullable', 'integer'],
        ]);

        $result = $this->budgetReplanService->replanForBudgetCut(
            (float) $data['new_envelope'],
            $data['tenant_id'] ?? ($request->user()?->tenant_id ?? null),
        );

        return response()->json([
            'result' => $result,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
