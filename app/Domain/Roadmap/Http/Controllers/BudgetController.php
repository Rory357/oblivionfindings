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
            'new_envelope' => ['nullable', 'numeric', 'min:0'],
            'use_governance_budget' => ['nullable', 'boolean'],
            'tenant_id' => ['nullable', 'integer'],
        ]);

        $envelope = (float) ($data['new_envelope'] ?? 0);

        if (! empty($data['use_governance_budget'])) {
            $govEnvelope = $this->budgetReplanService->getGovernanceBudgetEnvelope();
            if ($govEnvelope === null) {
                return response()->json(['message' => 'No approved governance budget found.'], 422);
            }
            $envelope = $govEnvelope;
        }

        if ($envelope <= 0 && empty($data['use_governance_budget'])) {
            return response()->json(['message' => 'A budget envelope amount is required.'], 422);
        }

        $result = $this->budgetReplanService->replanForBudgetCut(
            $envelope,
            $data['tenant_id'] ?? ($request->user()?->tenant_id ?? null),
        );

        return response()->json([
            'result' => $result,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function governanceBudget()
    {
        $envelope = $this->budgetReplanService->getGovernanceBudgetEnvelope();

        return response()->json([
            'envelope' => $envelope,
            'available' => $envelope !== null,
        ]);
    }
}
