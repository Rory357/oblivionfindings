<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\StrategicPlan;
use App\Domain\Governance\Services\GovernanceResolutionAuthorityService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StrategicPlanController extends Controller
{
    /** Plan lengths the plan store/update rules accept. */
    private const HORIZONS = ['3_year', '5_year'];

    /** Themes (stored as "pillar") used by plan goals. */
    private const PILLARS = ['safety', 'quality', 'people', 'finance', 'compliance', 'it_resilience'];

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
            ->when(in_array($horizon, self::HORIZONS, true), fn ($query) => $query->where('planning_horizon', $horizon))
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
            ->withCount('goals')
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
                'goals_count' => (int) $inEffect->goals_count,
                'progress_pct' => round((float) ($inEffect->goals_avg_progress_pct ?? 0), 1),
            ] : null,
            'filters' => [
                'status' => isset(self::STATUS_FILTERS[$status]) ? $status : null,
                'horizon' => in_array($horizon, self::HORIZONS, true) ? $horizon : null,
                'search' => $search !== '' ? $search : null,
            ],
            'horizons' => $this->horizonOptions(),
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
            'goals.initiatives.owner:id,name',
            'goals.leadExecutive:id,name',
            'goals.roadmapInitiative',
            'supersedes:id,title,version_number',
            'creator:id,name',
        ]);

        $user = $request->user();
        $isDraft = $plan->isDraft();

        // Only passed resolutions linked (and not yet used) to this exact
        // plan can approve it — listed only when the viewer can open them.
        $carriedResolutions = ! $isDraft || ! $user->can('approve', $plan)
            ? collect()
            : $this->linkedResolutions($plan, $user)
                ->filter(fn (Resolution $resolution) => $this->resolutionPassed($resolution))
                ->values()
                ->map(fn (Resolution $resolution) => [
                    'id' => (int) $resolution->id,
                    'resolution_reference' => $resolution->resolution_reference,
                    'title' => $resolution->title,
                    'outcome' => $resolution->outcome,
                    'closed_at' => $resolution->closed_at?->toIso8601String(),
                ]);

        $canEdit = $isDraft && $user->can('update', $plan);

        return Inertia::render('Governance/Strategy/Show', [
            'plan' => [
                ...$plan->only([
                    'id', 'title', 'planning_horizon', 'period_start', 'period_end', 'values', 'status',
                    'version_number', 'version_notes', 'approved_by_board_at', 'supersedes_plan_id',
                ]),
                // Legacy plans stored "TBD" for statements nobody wrote.
                'vision_statement' => StrategicPlan::statement($plan->vision_statement),
                'mission_statement' => StrategicPlan::statement($plan->mission_statement),
                'goals' => $plan->goals,
                'supersedes' => $plan->supersedes,
                'creator' => $plan->creator,
            ],
            'approval' => $this->approvalSummary($plan, $user),
            'carriedResolutions' => $carriedResolutions,
            'canEdit' => $canEdit,
            'canAddGoal' => $isDraft && $user->can('addGoal', $plan),
            'canApprove' => $carriedResolutions->isNotEmpty(),
            'canCreateVersion' => $plan->isApproved() && $user->can('createVersion', $plan),
            'canViewResolutions' => $user->can('viewAny', Resolution::class),
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
        ], $this->planMessages());

        $goals = $data['goals'] ?? [];

        DB::transaction(function () use ($request, $data, $goals): void {
            $plan = StrategicPlan::create([
                'title' => $data['title'],
                'planning_horizon' => $data['planning_horizon'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                // A statement nobody has written stays empty ("not written
                // yet") — never a placeholder the board reads as the plan.
                'vision_statement' => StrategicPlan::statement($data['vision_statement'] ?? $data['description'] ?? null),
                'mission_statement' => StrategicPlan::statement($data['mission_statement'] ?? null),
                'values' => $data['values'] ?? [],
                'created_by' => $request->user()->id,
            ]);

            if ($goals !== []) {
                $this->authorize('addGoal', $plan);
                $this->createGoals($request, $plan, $goals);
            }
        });

        return redirect()->route('governance.strategy.index')
            ->with('success', 'Strategic plan saved as a draft.');
    }

    public function update(Request $request, StrategicPlan $plan)
    {
        $this->authorize('update', $plan);

        if (! $plan->isDraft()) {
            return redirect()->back()->with('error', $this->readOnlyMessage($plan));
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'planning_horizon' => ['sometimes', 'in:3_year,5_year'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'vision_statement' => ['nullable', 'string'],
            'mission_statement' => ['nullable', 'string'],
            'values' => ['nullable', 'array'],
            // Approval only ever comes from a passed resolution, never an edit.
            'status' => ['sometimes', 'string', 'in:draft,review'],
            ...$this->goalRules(),
        ], [
            ...$this->planMessages(),
            'status.in' => 'A plan is approved by recording the board’s approval, not by editing it.',
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
                'vision_statement' => array_key_exists('vision_statement', $data) || array_key_exists('description', $data)
                    ? StrategicPlan::statement($data['vision_statement'] ?? $data['description'] ?? null)
                    : $plan->vision_statement,
                'mission_statement' => array_key_exists('mission_statement', $data)
                    ? StrategicPlan::statement($data['mission_statement'])
                    : $plan->mission_statement,
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

        if (! $plan->isDraft()) {
            return redirect()->back()->with('error', $this->readOnlyMessage($plan));
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'timeframe' => ['nullable', 'string', 'max:255'],
            'pillar' => ['nullable', 'string', 'max:255'],
            'lead_executive_id' => ['nullable', 'exists:users,id'],
            'key_results' => ['nullable', 'array'],
            'risks' => ['nullable', 'array'],
            'order' => ['integer', 'min:0'],
        ], [
            'title.required' => 'Give the goal a title.',
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
        ], [
            'resolution_id.required' => 'Choose the resolution the board passed.',
            'resolution_id.exists' => 'That resolution no longer exists.',
        ]);

        $plan->approve((int) $validated['resolution_id'], $request->user()->id);

        return redirect()->back()->with('success', 'Board approval recorded. This version is now the approved strategic plan.');
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
        ], [
            'version_notes.required' => 'Say why a new version is needed.',
            'version_notes.max' => 'Keep the reason to 500 characters.',
        ]);

        $newPlan = $plan->createNewVersion($validated['version_notes'], auth()->id());

        return redirect()->route('governance.strategy.show', $newPlan)
            ->with('success', "Version {$newPlan->version_number} created. Update it, then put it to the board.");
    }

    public function changes(StrategicPlan $plan)
    {
        $this->authorize('viewChanges', $plan);

        $changeData = $plan->getChangesSinceLastSnapshot();

        return Inertia::render('Governance/Strategy/Changes', [
            'plan' => $plan->load(['supersedes:id,title,version_number'])->only([
                'id', 'title', 'planning_horizon', 'period_start', 'period_end', 'version_number', 'status', 'supersedes',
            ]),
            'changes' => $changeData,
        ]);
    }

    /** @return array{horizons: array<string, string>, pillars: array<string, string>} */
    private function formOptions(): array
    {
        return [
            'horizons' => $this->horizonOptions(),
            'pillars' => collect(self::PILLARS)
                ->mapWithKeys(fn (string $key) => [$key => GovernanceLabels::label('theme', $key)])
                ->all(),
        ];
    }

    /** @return array<string, string> */
    private function horizonOptions(): array
    {
        return collect(self::HORIZONS)
            ->mapWithKeys(fn (string $key) => [$key => GovernanceLabels::label('plan_length', $key)])
            ->all();
    }

    /** @return array<string, string> */
    private function planMessages(): array
    {
        return [
            'title.required' => 'Give the plan a title.',
            'planning_horizon.required' => 'Choose how many years the plan covers.',
            'planning_horizon.in' => 'Choose a 3-year or 5-year plan.',
            'period_start.required' => 'Choose the date the plan starts.',
            'period_end.required' => 'Choose the date the plan ends.',
            'period_end.after' => 'The plan must end after it starts.',
            'goals.*.title.required' => 'Give the goal a title.',
            'goals.*.description.required' => 'Describe what the goal achieves.',
            'goals.*.pillar.required' => 'Choose the theme this goal belongs to.',
            'goals.*.pillar.in' => 'Choose one of the listed themes.',
            'goals.*.key_results.*.result.required' => 'Write the measure of success, or remove it.',
        ];
    }

    private function readOnlyMessage(StrategicPlan $plan): string
    {
        return $plan->isApproved()
            ? "This plan has been approved by the board, so it can't be edited. Create a new version to change it."
            : "This version is no longer a draft, so it can't be edited.";
    }

    /**
     * Resolutions linked to this exact plan (any outcome) that the viewer
     * may open, newest first.
     *
     * @return Collection<int, Resolution>
     */
    private function linkedResolutions(StrategicPlan $plan, User $user): Collection
    {
        return Resolution::query()
            ->whereHas('authorityBindings', fn ($q) => $q
                ->where('subject_type', GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN)
                ->where('subject_id', $plan->id)
                ->whereNull('consumed_at'))
            ->with('meeting:id,title,scheduled_at')
            ->orderByDesc('id')
            ->get(['id', 'resolution_reference', 'title', 'status', 'outcome', 'closed_at', 'governance_meeting_id'])
            ->filter(fn (Resolution $resolution) => $user->can('view', $resolution))
            ->values();
    }

    private function resolutionPassed(Resolution $resolution): bool
    {
        return in_array($resolution->status, StrategicPlan::PASSED_RESOLUTION_STATUSES, true)
            && $resolution->outcome === 'carried';
    }

    /**
     * Where the board's approval of this version stands, in plain words.
     *
     * @return array<string, mixed>
     */
    private function approvalSummary(StrategicPlan $plan, User $user): array
    {
        $summary = fn (string $key, string $label, string $detail, ?Resolution $resolution = null) => [
            'key' => $key,
            'label' => $label,
            'detail' => $detail,
            'resolution' => $resolution ? [
                'id' => (int) $resolution->id,
                'title' => $resolution->title,
                'reference' => $resolution->resolution_reference,
            ] : null,
        ];

        if ($plan->isApproved() || $plan->isSuperseded() || $plan->isArchived()) {
            $approvedBy = $plan->approval_resolution_id ? Resolution::query()->find($plan->approval_resolution_id) : null;
            $approvedBy = $approvedBy && $user->can('view', $approvedBy) ? $approvedBy : null;
            $date = $plan->approved_by_board_at ? GovernanceLabels::date($plan->approved_by_board_at) : null;

            return match (true) {
                $plan->isSuperseded() => $summary('superseded', 'Replaced by a newer version', 'The board approved a newer version of this plan.', $approvedBy),
                $plan->isArchived() => $summary('archived', 'Archived', 'This plan is no longer in use.', $approvedBy),
                default => $summary('approved', 'Approved by the board', $date
                    ? "The board's approval was recorded on {$date}."
                    : "The board's approval has been recorded.", $approvedBy),
            };
        }

        $resolutions = $this->linkedResolutions($plan, $user);
        $authority = app(GovernanceResolutionAuthorityService::class);
        $currentFingerprint = GovernanceResolutionAuthorityService::fingerprint($authority->strategicPlanTerms($plan));

        $matching = $resolutions->filter(function (Resolution $resolution) use ($plan, $currentFingerprint) {
            $binding = GovernanceResolutionBinding::query()
                ->where('resolution_id', $resolution->id)
                ->where('subject_type', GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN)
                ->where('subject_id', $plan->id)
                ->first();

            return $binding && hash_equals((string) $binding->subject_fingerprint, $currentFingerprint);
        });

        $passed = $matching->first(fn (Resolution $resolution) => $this->resolutionPassed($resolution));
        if ($passed) {
            return $summary('passed', 'Passed — ready to record approval', 'The board passed a resolution approving this version. Record the board’s approval to make it the approved plan.', $passed);
        }

        $open = $resolutions->first(fn (Resolution $resolution) => $resolution->status === 'open');
        if ($open) {
            return $summary('voting_open', 'Voting open', 'The board is voting on this version now.', $open);
        }

        $drafted = $resolutions->first(fn (Resolution $resolution) => in_array($resolution->status, ['draft', 'proposed'], true));
        if ($drafted) {
            if (! $matching->contains(fn (Resolution $resolution) => $resolution->is($drafted))) {
                return $summary('stale', 'Resolution out of date', 'This plan was edited after its resolution was prepared. The secretary links the resolution to this version again before the vote.', $drafted);
            }

            return $drafted->meeting
                ? $summary('on_agenda', "On the agenda for {$drafted->meeting->title}", sprintf('The board votes on this version at the meeting on %s.', GovernanceLabels::date($drafted->meeting->scheduled_at)), $drafted)
                : $summary('drafted', 'Resolution drafted — not yet on a meeting agenda', 'The secretary adds the resolution to a meeting agenda, and the board votes on it there.', $drafted);
        }

        $notPassed = $resolutions->first(fn (Resolution $resolution) => $resolution->status !== 'draft' && $resolution->outcome && $resolution->outcome !== 'carried');
        if ($notPassed) {
            return $summary('not_passed', 'Not passed', 'The board did not pass the resolution for this version. Update the plan and prepare a new resolution.', $notPassed);
        }

        if ($resolutions->contains(fn (Resolution $resolution) => $this->resolutionPassed($resolution))) {
            return $summary('stale', 'Passed — but the plan has changed', "The board passed a resolution, but this version was edited afterwards, so it can't be approved as it is now. Prepare a new resolution for this version.");
        }

        return $summary('waiting', 'Waiting for a resolution', 'Approved by the board when a resolution naming this version passes. The secretary prepares one from Resolutions.');
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
            'goals.*.pillar' => ['required', 'string', 'in:'.implode(',', self::PILLARS)],
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
