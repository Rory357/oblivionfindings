<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domain\Governance\Models\Budget;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BudgetController extends Controller
{
    public function index(Request $request)
    {
        $budgets = Budget::query()
            ->orderBy('fiscal_year', 'desc')
            ->get();

        return Inertia::render('Governance/Budgets/Index', [
            'budgets' => $budgets,
        ]);
    }

    public function create()
    {
        return Inertia::render('Governance/Budgets/Create');
    }

    public function show(Request $request, Budget $budget)
    {
        $budget->load(['lineItems', 'varianceNotes', 'auditLogs']);

        return Inertia::render('Governance/Budgets/Show', [
            'budget' => $budget,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'title' => ['nullable', 'string', 'max:255'],
            'total_budget' => ['required', 'numeric', 'min:0'],
            'board_approved' => ['boolean'],
        ]);

        $data['status'] = $data['board_approved'] ?? false ? 'approved' : 'draft';

        $budget = Budget::create($data);

        return redirect()->route('governance.budgets.index')
            ->with('success', 'Budget created.');
    }

    public function update(Request $request, Budget $budget)
    {
        $data = $request->validate([
            'total_budget' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'in:draft,proposed,approved,adjusted'],
        ]);

        $budget->update($data);

        return redirect()->back()->with('success', 'Budget updated.');
    }

    public function propose(Request $request, Budget $budget)
    {
        $budget->update(['status' => 'proposed']);

        return redirect()->back()->with('success', 'Budget proposed to board.');
    }

    public function requestAdjustment(Request $request, Budget $budget)
    {
        $data = $request->validate([
            'reason' => ['required', 'string'],
            'requested_amount' => ['required', 'numeric'],
        ]);

        $budget->varianceNotes()->create([
            'type' => 'adjustment_request',
            'reason' => $data['reason'],
            'amount' => $data['requested_amount'],
            'requested_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Adjustment requested.');
    }
}
