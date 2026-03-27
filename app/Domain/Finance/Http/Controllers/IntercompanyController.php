<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinConsolidationGroup;
use App\Domain\Finance\Models\FinIntercompanyTransaction;
use App\Domain\Finance\Services\IntercompanyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IntercompanyController extends Controller
{
    public function __construct(
        private readonly IntercompanyService $intercompanyService,
    ) {}

    /**
     * List intercompany transactions for a group.
     */
    public function index(Request $request, FinConsolidationGroup $group)
    {
        $this->authorizeGroupAccess($request, $group);

        $group->load('entities');

        $transactions = FinIntercompanyTransaction::where('group_id', $group->id)
            ->with(['fromEntity', 'toEntity', 'createdBy:id,name'])
            ->orderByDesc('transaction_date')
            ->get()
            ->map(fn ($ict) => [
                'id' => $ict->id,
                'from_entity_id' => $ict->from_entity_id,
                'from_entity_name' => $ict->fromEntity?->entity_name,
                'to_entity_id' => $ict->to_entity_id,
                'to_entity_name' => $ict->toEntity?->entity_name,
                'transaction_date' => $ict->transaction_date->toDateString(),
                'description' => $ict->description,
                'amount' => $ict->amount,
                'status' => $ict->status,
                'created_by' => $ict->createdBy?->name,
                'created_at' => $ict->created_at->toDateTimeString(),
            ]);

        $entities = $group->entities->map(fn ($e) => [
            'id' => $e->id,
            'entity_name' => $e->entity_name,
            'is_active' => $e->is_active,
        ]);

        return Inertia::render('finance/Intercompany/Index', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
            ],
            'transactions' => $transactions,
            'entities' => $entities,
        ]);
    }

    /**
     * Create a new intercompany transaction.
     */
    public function store(Request $request, FinConsolidationGroup $group)
    {
        $this->authorizeGroupAccess($request, $group);

        $validated = $request->validate([
            'from_entity_id' => 'required|integer|exists:fin_consolidation_entities,id',
            'to_entity_id' => 'required|integer|exists:fin_consolidation_entities,id|different:from_entity_id',
            'transaction_date' => 'required|date',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $this->intercompanyService->createTransaction($group, $validated);

        return redirect()->route('finance.intercompany.index', $group)
            ->with('success', 'Intercompany transaction created.');
    }

    /**
     * Post an intercompany transaction (creates journals in both entities).
     */
    public function post(Request $request, FinConsolidationGroup $group, FinIntercompanyTransaction $transaction)
    {
        $this->authorizeGroupAccess($request, $group);

        if ($transaction->group_id !== $group->id) {
            abort(404);
        }

        try {
            $this->intercompanyService->postTransaction($transaction);

            return redirect()->route('finance.intercompany.index', $group)
                ->with('success', 'Intercompany transaction posted successfully.');
        } catch (\Throwable $e) {
            return back()->withErrors(['transaction' => 'Failed to post: ' . $e->getMessage()]);
        }
    }

    /**
     * Ensure the user's organization matches the group's parent organization.
     */
    private function authorizeGroupAccess(Request $request, FinConsolidationGroup $group): void
    {
        if ($group->parent_organization_id !== $request->user()->organization_id) {
            abort(403, 'You do not have access to this consolidation group.');
        }
    }
}
