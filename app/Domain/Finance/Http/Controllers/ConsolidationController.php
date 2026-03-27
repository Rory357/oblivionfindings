<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccountMapping;
use App\Domain\Finance\Models\FinConsolidationEntity;
use App\Domain\Finance\Models\FinConsolidationGroup;
use App\Domain\Finance\Models\FinConsolidationRun;
use App\Domain\Finance\Services\ConsolidationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConsolidationController extends Controller
{
    public function __construct(
        private readonly ConsolidationService $consolidationService,
    ) {}

    /**
     * List all consolidation groups.
     */
    public function index(Request $request)
    {
        $groups = FinConsolidationGroup::query()
            ->where('parent_organization_id', $request->user()->organization_id)
            ->with('createdBy:id,name')
            ->withCount('entities')
            ->withCount('runs')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'base_currency_code' => $group->base_currency_code,
                'is_active' => $group->is_active,
                'entities_count' => $group->entities_count,
                'runs_count' => $group->runs_count,
                'created_by' => $group->createdBy?->name,
                'created_at' => $group->created_at->toDateTimeString(),
            ]);

        return Inertia::render('finance/Consolidation/Index', [
            'groups' => $groups,
        ]);
    }

    /**
     * Create a new consolidation group.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'base_currency_code' => 'required|string|size:3',
        ]);

        FinConsolidationGroup::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'parent_organization_id' => $request->user()->organization_id,
            'base_currency_code' => $validated['base_currency_code'],
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('finance.consolidation.index')
            ->with('success', 'Consolidation group created successfully.');
    }

    /**
     * Show a consolidation group with its entities.
     */
    public function show(Request $request, FinConsolidationGroup $group)
    {
        $this->authorizeGroupAccess($request, $group);

        $group->load([
            'entities',
            'createdBy:id,name',
        ]);

        $entities = $group->entities->map(fn ($entity) => [
            'id' => $entity->id,
            'organization_id' => $entity->organization_id,
            'entity_name' => $entity->entity_name,
            'ownership_percentage' => $entity->ownership_percentage,
            'consolidation_method' => $entity->consolidation_method,
            'currency_code' => $entity->currency_code,
            'is_active' => $entity->is_active,
        ]);

        $recentRuns = FinConsolidationRun::where('group_id', $group->id)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'period_from' => $run->period_from->toDateString(),
                'period_to' => $run->period_to->toDateString(),
                'status' => $run->status,
                'total_revenue' => $run->total_revenue,
                'total_expenses' => $run->total_expenses,
                'eliminations_count' => $run->eliminations_count,
                'created_by' => $run->createdBy?->name,
                'created_at' => $run->created_at->toDateTimeString(),
            ]);

        return Inertia::render('finance/Consolidation/Show', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'base_currency_code' => $group->base_currency_code,
                'is_active' => $group->is_active,
                'created_by' => $group->createdBy?->name,
            ],
            'entities' => $entities,
            'recentRuns' => $recentRuns,
        ]);
    }

    /**
     * Add an entity to a consolidation group.
     */
    public function addEntity(Request $request, FinConsolidationGroup $group)
    {
        $this->authorizeGroupAccess($request, $group);

        $validated = $request->validate([
            'organization_id' => 'required|integer',
            'entity_name' => 'required|string|max:255',
            'ownership_percentage' => 'required|numeric|min:0|max:100',
            'consolidation_method' => 'required|in:full,proportional,equity',
            'currency_code' => 'required|string|size:3',
        ]);

        FinConsolidationEntity::create([
            'group_id' => $group->id,
            'organization_id' => $validated['organization_id'],
            'entity_name' => $validated['entity_name'],
            'ownership_percentage' => $validated['ownership_percentage'],
            'consolidation_method' => $validated['consolidation_method'],
            'currency_code' => $validated['currency_code'],
            'is_active' => true,
        ]);

        return redirect()->route('finance.consolidation.show', $group)
            ->with('success', 'Entity added to consolidation group.');
    }

    /**
     * Remove an entity from a consolidation group.
     */
    public function removeEntity(Request $request, FinConsolidationGroup $group, FinConsolidationEntity $entity)
    {
        $this->authorizeGroupAccess($request, $group);

        if ($entity->group_id !== $group->id) {
            abort(404);
        }

        $entity->delete();

        return redirect()->route('finance.consolidation.show', $group)
            ->with('success', 'Entity removed from consolidation group.');
    }

    /**
     * List consolidation runs for a group.
     */
    public function runs(Request $request, FinConsolidationGroup $group)
    {
        $this->authorizeGroupAccess($request, $group);

        $runs = FinConsolidationRun::where('group_id', $group->id)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'period_from' => $run->period_from->toDateString(),
                'period_to' => $run->period_to->toDateString(),
                'status' => $run->status,
                'total_revenue' => $run->total_revenue,
                'total_expenses' => $run->total_expenses,
                'total_assets' => $run->total_assets,
                'total_liabilities' => $run->total_liabilities,
                'total_equity' => $run->total_equity,
                'eliminations_count' => $run->eliminations_count,
                'eliminations_amount' => $run->eliminations_amount,
                'created_by' => $run->createdBy?->name,
                'created_at' => $run->created_at->toDateTimeString(),
            ]);

        return Inertia::render('finance/Consolidation/Show', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'base_currency_code' => $group->base_currency_code,
                'is_active' => $group->is_active,
            ],
            'entities' => $group->entities->map(fn ($e) => [
                'id' => $e->id,
                'organization_id' => $e->organization_id,
                'entity_name' => $e->entity_name,
                'ownership_percentage' => $e->ownership_percentage,
                'consolidation_method' => $e->consolidation_method,
                'currency_code' => $e->currency_code,
                'is_active' => $e->is_active,
            ]),
            'recentRuns' => $runs,
        ]);
    }

    /**
     * Trigger a consolidation run.
     */
    public function runConsolidation(Request $request, FinConsolidationGroup $group)
    {
        $this->authorizeGroupAccess($request, $group);

        $validated = $request->validate([
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
        ]);

        try {
            $run = $this->consolidationService->runConsolidation(
                $group,
                $validated['period_from'],
                $validated['period_to'],
            );

            return redirect()->route('finance.consolidation.show-run', [$group, $run])
                ->with('success', 'Consolidation run completed successfully.');
        } catch (\Throwable $e) {
            return back()->withErrors(['consolidation' => 'Consolidation failed: ' . $e->getMessage()]);
        }
    }

    /**
     * View consolidation run results.
     */
    public function showRun(Request $request, FinConsolidationGroup $group, FinConsolidationRun $run)
    {
        $this->authorizeGroupAccess($request, $group);

        if ($run->group_id !== $group->id) {
            abort(404);
        }

        $run->load('createdBy:id,name');

        return Inertia::render('finance/Consolidation/RunResults', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'base_currency_code' => $group->base_currency_code,
            ],
            'run' => [
                'id' => $run->id,
                'period_from' => $run->period_from->toDateString(),
                'period_to' => $run->period_to->toDateString(),
                'status' => $run->status,
                'total_revenue' => $run->total_revenue,
                'total_expenses' => $run->total_expenses,
                'total_assets' => $run->total_assets,
                'total_liabilities' => $run->total_liabilities,
                'total_equity' => $run->total_equity,
                'eliminations_count' => $run->eliminations_count,
                'eliminations_amount' => $run->eliminations_amount,
                'report_data' => $run->report_data,
                'notes' => $run->notes,
                'created_by' => $run->createdBy?->name,
                'created_at' => $run->created_at->toDateTimeString(),
            ],
        ]);
    }

    /**
     * View account mappings for a group.
     */
    public function mapping(Request $request, FinConsolidationGroup $group)
    {
        $this->authorizeGroupAccess($request, $group);

        $group->load(['entities', 'accountMappings.sourceAccount', 'accountMappings.entity']);

        $mappings = $group->accountMappings->map(fn ($m) => [
            'id' => $m->id,
            'entity_id' => $m->entity_id,
            'entity_name' => $m->entity?->entity_name,
            'source_account_id' => $m->source_account_id,
            'source_account_code' => $m->sourceAccount?->code,
            'source_account_name' => $m->sourceAccount?->name,
            'consolidated_account_code' => $m->consolidated_account_code,
            'consolidated_account_name' => $m->consolidated_account_name,
            'is_elimination_account' => $m->is_elimination_account,
        ]);

        return Inertia::render('finance/Consolidation/Show', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'base_currency_code' => $group->base_currency_code,
                'is_active' => $group->is_active,
            ],
            'entities' => $group->entities->map(fn ($e) => [
                'id' => $e->id,
                'organization_id' => $e->organization_id,
                'entity_name' => $e->entity_name,
                'ownership_percentage' => $e->ownership_percentage,
                'consolidation_method' => $e->consolidation_method,
                'currency_code' => $e->currency_code,
                'is_active' => $e->is_active,
            ]),
            'recentRuns' => [],
            'mappings' => $mappings,
        ]);
    }

    /**
     * Update account mappings for a group.
     */
    public function updateMapping(Request $request, FinConsolidationGroup $group)
    {
        $this->authorizeGroupAccess($request, $group);

        $validated = $request->validate([
            'mappings' => 'required|array',
            'mappings.*.entity_id' => 'required|integer|exists:fin_consolidation_entities,id',
            'mappings.*.source_account_id' => 'required|integer|exists:fin_accounts,id',
            'mappings.*.consolidated_account_code' => 'required|string|max:50',
            'mappings.*.consolidated_account_name' => 'required|string|max:255',
            'mappings.*.is_elimination_account' => 'boolean',
        ]);

        foreach ($validated['mappings'] as $mappingData) {
            FinAccountMapping::updateOrCreate(
                [
                    'group_id' => $group->id,
                    'entity_id' => $mappingData['entity_id'],
                    'source_account_id' => $mappingData['source_account_id'],
                ],
                [
                    'consolidated_account_code' => $mappingData['consolidated_account_code'],
                    'consolidated_account_name' => $mappingData['consolidated_account_name'],
                    'is_elimination_account' => $mappingData['is_elimination_account'] ?? false,
                ],
            );
        }

        return redirect()->route('finance.consolidation.mapping', $group)
            ->with('success', 'Account mappings updated successfully.');
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
