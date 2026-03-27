<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinFxRevaluation;
use App\Domain\Finance\Services\FxRevaluationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FxRevaluationController extends Controller
{
    public function __construct(
        protected FxRevaluationService $fxRevaluationService,
    ) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $revaluations = FinFxRevaluation::forOrganization($orgId)
            ->with('journal:id,journal_number', 'createdBy:id,name')
            ->orderByDesc('revaluation_date')
            ->paginate(20)
            ->through(fn ($r) => [
                'id' => $r->id,
                'revaluation_date' => $r->revaluation_date->toDateString(),
                'total_gain_loss' => $r->total_gain_loss,
                'status' => $r->status,
                'journal_number' => $r->journal?->journal_number,
                'notes' => $r->notes,
                'created_by_name' => $r->createdBy?->name,
                'created_at' => $r->created_at->toIso8601String(),
            ]);

        return Inertia::render('finance/fx-revaluations/Index', [
            'revaluations' => $revaluations,
        ]);
    }

    public function create(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $date = $request->get('date', now()->toDateString());

        $preview = $this->fxRevaluationService->calculateUnrealisedGainLoss($orgId, $date);

        return Inertia::render('finance/fx-revaluations/Create', [
            'preview' => $preview,
            'date' => $date,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $orgId = $request->user()->organization_id;
        $reval = $this->fxRevaluationService->createRevaluation($orgId, $validated['date']);

        if (! empty($validated['notes'])) {
            $reval->update(['notes' => $validated['notes']]);
        }

        return redirect()->route('finance.fx-revaluations.index')
            ->with('success', 'FX Revaluation created as draft.');
    }

    public function post(Request $request, FinFxRevaluation $revaluation)
    {
        try {
            $this->fxRevaluationService->postRevaluation($revaluation);

            return redirect()->route('finance.fx-revaluations.index')
                ->with('success', 'FX Revaluation posted to General Ledger.');
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['post' => $e->getMessage()]);
        }
    }
}
