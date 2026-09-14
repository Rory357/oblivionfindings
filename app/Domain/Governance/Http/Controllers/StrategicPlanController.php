<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\StrategicPlan;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StrategicPlanController extends Controller
{
    /** Planning horizons the plan store/update rules accept. */
    private const HORIZONS = [
        '3_year' => '3-year plan',
        '5_year' => '5-year plan',
    ];

    /** Strategic pillars used by plan goals. */
    private const PILLARS = [
        'safety' => 'Safety',
        'quality' => 'Quality',
        'people' => 'People',
        'finance' => 'Finance',
        'compliance' => 'Compliance',
        'it_resilience' => 'IT resilience',
    ];

    /** Register status filters (the model maps legacy active/completed). */
    private const STATUS_FILTERS = [
        'draft' => ['draft', 'review', 'consultation'],
        'approved' => ['approved', 'active'],
        'superseded' => ['superseded'],
        'archived' => ['archived', 'completed'],
    ];

    /**
     * Legacy deep link: the new-plan wizard is a dialog on the register.
     * Authorise exactly as the retired page did, then open it there.
     */
    public function create()
    {
        $this->authorize('create', StrategicPlan::class);

        return redirect()->route('governance.strategy.index', ['create' => 1]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', StrategicPlan::class);

        $status = $request->string('status')->toString();
        $horizon = $request->string('horizon')->toString();
        $search = trim($request->string('search')->toString());

        // Header counts come from the whole register, before header filters.
        $summaryBase = StrategicPlan::query();
        $summary = [
            'total' => (clone $summaryBase)->count(),
            'draft' => (clone $summaryBase)->whereIn('status', self::STATUS_FILTERS['draft'])->count(),
            'approved' => (clone $summaryBase)->whereIn('status', self::STATUS_FILTERS['approved'])->count(),
            'superseded' => (clone $summaryBase)->whereIn('status', self::STATUS_FILTERS['superseded'])->count(),
            'archived' => (clone $summaryBase)->whereIn('status', self::STATUS_FILTERS['archived'])->count(),
        ];

        $plans = StrategicPlan::query()
            ->withCount('goals')
            ->withAvg('goals', 'progress_pct')
            ->when(isset(self::STATUS_FILTERS[$status]), fn ($query) => $query->whereIn('status', self::STATUS_FILTERS[$status]))
            ->when(isset(self::HORIZONS[$horizon]), fn ($query) => $query->where('planning_horizon', $horizon))
            ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.$search.'%'))
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (StrategicPlan $plan) {
                $plan->progress_pct = round((float) ($plan->goals_avg_progress_pct ?? 0), 1);

                return $plan;
            });

        $inEffect = StrategicPlan::query()
            ->whereIn('status', self::STATUS_FILTERS['approved'])
            ->withAvg('goals', 'progress_pct')
            ->orderByDesc('approved_by_board_at')
            ->orderByDesc('id')
            ->first();

        $canCreate = $request->user()->can('create', StrategicPlan::class);

        return Inertia::render('Governance/Strategy/Index', [
            'plans' => ['data' => $plans],
            'summary' => $summary,
            'inEffect' => $inEffect ? [
                'id' => (int) $inEffect->id,
                'title' => $inEffect->title,
                'progress_pct' => round((float) ($inEffect->goals_avg_progress_pct ?? 0), 1),
            ] : null,
            'filters' => [
                'status' => isset(self::STATUS_FILTERS[$status]) ? $status : null,
                'horizon' => isset(self::HORIZONS[$horizon]) ? $horizon : null,
                'search' => $search !== '' ? $search : null,
            ],
            'canCreate' => $canCreate,
            // New-plan wizard options, only for viewers who may create.
            'formOptions' => $canCreate ? $this->formOptions() : null,
        ]);
    }

    public function show(Request $request, StrategicPlan $plan)
    {
        $this->authorize('view', $plan);

        $plan->load([
            'goals' => fn ($q) => $q->orderBy('order'),
            'goals.initiatives.owner',
            'goals.leadExecutive',
            'goals.originGoal',
            'goals.roadmapInitiative',
            'approvalResolution.meeting',
            'supersedes',
            'creator',
        ]);

        $user = $request->user();
        $canApprove = $plan->isDraft() && $user->can('approve', $plan);

        // Only resolutions explicitly bound to this exact plan (and not yet
        // used) can approve it.
        $carriedResolutions = ! $canApprove ? collect() : Resolution::query()
            ->where('status', 'closed')
            ->where('outcome', 'carried')
            ->whereHas('authorityBindings', fn ($q) => $q
                ->where('subject_type', GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN)
                ->where('subject_id', $plan->id)
                ->whereNull('consumed_at'))
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->get(['id', 'resolution_reference', 'title', 'outcome', 'closed_at']);

        $canEdit = $user->can('update', $plan);

        return Inertia::render('Governance/Strategy/Show', [
            'plan' => $plan,
            'carriedResolutions' => $carriedResolutions,
            'canEdit' => $canEdit,
            'canAddGoal' => $user->can('addGoal', $plan),
            'canApprove' => $canApprove,
            'canCreateVersion' => $plan->isApproved() && $user->can('createVersion', $plan),
            // Edit wizard options, only for viewers who may edit.
            'formOptions' => $canEdit ? $this->formOptions() : null,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', StrategicPlan::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'planning_horizon' => ['required', 'in:3_year,5_year'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'vision_statement' => ['nullable', 'string'],
            'mission_statement' => ['nullable', 'string'],
            'values' => ['nullable', 'array'],
            ...$this->goalRules(),
        ]);

        $goals = $data['goals'] ?? [];

        DB::transaction(function () use ($request, $data, $goals): void {
            $plan = StrategicPlan::create([
                'title' => $data['title'],
                'planning_horizon' => $data['planning_horizon'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'vision_statement' => $data['vision_statement'] ?? $data['description'] ?? 'TBD',
                'mission_statement' => $data['mission_statement'] ?? 'TBD',
                'values' => $data['values'] ?? [],
                'created_by' => $request->user()->id,
            ]);

            if ($goals !== []) {
                $this->authorize('addGoal', $plan);
                $this->createGoals($request, $plan, $goals);
            }
        });

        return redirect()->route('governance.strategy.index')
            ->with('success', 'Strategic plan created.');
    }

    public function update(Request $request, StrategicPlan $plan)
    {
        $this->authorize('update', $plan);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'planning_horizon' => ['sometimes', 'in:3_year,5_year'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'vision_statement' => ['nullable', 'string'],
            'mission_statement' => ['nullable', 'string'],
            'values' => ['nullable', 'array'],
            'status' => ['sometimes', 'string', 'in:draft,review,approved,active,superseded,archived,completed'],
            ...$this->goalRules(),
        ]);

        $goals = $data['goals'] ?? [];
        if ($goals !== []) {
            $this->authorize('addGoal', $plan);
        }

        DB::transaction(function () use ($request, $plan, $data, $goals): void {
            $plan->update([
                'title' => $data['title'],
                'planning_horizon' => $data['planning_horizon'] ?? $plan->planning_horizon,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'vision_statement' => $data['vision_statement'] ?? $data['description'] ?? $plan->vision_statement,
                'mission_statement' => $data['mission_statement'] ?? $plan->mission_statement,
                'values' => $data['values'] ?? $plan->values,
                'status' => $data['status'] ?? $plan->status,
            ]);

            if ($goals !== []) {
                $this->createGoals($request, $plan, $goals);
            }
        });

        return redirect()->back()->with('success', 'Strategic plan updated.');
    }

    public function addGoal(Request $request, StrategicPlan $plan)
    {
        $this->authorize('addGoal', $plan);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'timeframe' => ['nullable', 'string', 'max:255'],
            'pillar' => ['nullable', 'string', 'max:255'],
            'lead_executive_id' => ['nullable', 'exists:users,id'],
            'key_results' => ['nullable', 'array'],
            'risks' => ['nullable', 'array'],
            'order' => ['integer', 'min:0'],
        ]);

        $data['timeframe'] ??= $plan->period_start?->toDateString().' - '.$plan->period_end?->toDateString();
        $data['pillar'] ??= 'quality';
        $data['lead_executive_id'] ??= $request->user()->id;

        $plan->goals()->create($data);

        return redirect()->back()->with('success', 'Goal added.');
    }

    public function approve(Request $request, StrategicPlan $plan)
    {
        $this->authorize('approve', $plan);

        $validated = $request->validate([
            'resolution_id' => 'required|exists:resolutions,id',
            'notes' => 'nullable|string',
        ]);

        $plan->approve((int) $validated['resolution_id'], $request->user()->id);

        return redirect()->back()->with('success', 'Strategic plan approved.');
    }

    /** Legacy deep link: the edit wizard is a dialog on the plan page. */
    public function edit(StrategicPlan $plan)
    {
        $this->authorize('update', $plan);

        return redirect()->route('governance.strategy.show', ['plan' => $plan->id, 'edit' => 1]);
    }

    public function createVersion(Request $request, StrategicPlan $plan)
    {
        $this->authorize('createVersion', $plan);

        $validated = $request->validate([
            'version_notes' => 'required|string|max:500',
        ]);

        $newPlan = $plan->createNewVersion($validated['version_notes'], auth()->id());

        return redirect()->route('governance.strategy.show', $newPlan)
            ->with('success', 'New version created (v'.$newPlan->version_number.').');
    }

    public function changes(StrategicPlan $plan)
    {
        $this->authorize('viewChanges', $plan);

        $changeData = $plan->getChangesSinceLastSnapshot();

        return Inertia::render('Governance/Strategy/Changes', [
            'plan' => $plan->load(['goals.leadExecutive', 'supersedes']),
            'changes' => $changeData,
        ]);
    }

    /** @return array{horizons: array<string, string>, pillars: array<string, string>} */
    private function formOptions(): array
    {
        return [
            'horizons' => self::HORIZONS,
            'pillars' => self::PILLARS,
        ];
    }

    /**
     * New goals authored in the plan wizard — the add-goal fields, with the
     * NOT NULL goal description required up front.
     *
     * @return array<string, array<int, string>>
     */
    private function goalRules(): array
    {
        return [
            'goals' => ['sometimes', 'array', 'max:50'],
            'goals.*.title' => ['required', 'string', 'max:255'],
            'goals.*.description' => ['required', 'string'],
            'goals.*.pillar' => ['required', 'string', 'in:'.implode(',', array_keys(self::PILLARS))],
            'goals.*.timeframe' => ['nullable', 'string', 'max:255'],
            'goals.*.key_results' => ['nullable', 'array', 'max:20'],
            'goals.*.key_results.*.result' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $goals
     */
    private function createGoals(Request $request, StrategicPlan $plan, array $goals): void
    {
        $order = (int) $plan->goals()->max('order');
        $defaultTimeframe = $plan->period_start?->toDateString().' - '.$plan->period_end?->toDateString();

        foreach ($goals as $goal) {
            $plan->goals()->create([
                'title' => $goal['title'],
                'description' => $goal['description'],
                'pillar' => $goal['pillar'],
                'timeframe' => $goal['timeframe'] ?? $defaultTimeframe,
                'key_results' => collect($goal['key_results'] ?? [])
                    ->map(fn (array $result) => ['result' => $result['result'], 'status' => 'not_started'])
                    ->values()
                    ->all(),
                'lead_executive_id' => $request->user()->id,
                'order' => ++$order,
            ]);
        }
    }
}
