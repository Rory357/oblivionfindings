<?php

namespace App\Domain\Roadmap\Http\Controllers;

use App\Domain\Roadmap\Http\Controllers\Concerns\ProvidesRoadmapInertiaProps;
use App\Domain\Roadmap\Models\Initiative;
use App\Domain\Roadmap\Models\InitiativeCategory;
use App\Domain\Roadmap\Http\Requests\StoreInitiativeRequest;
use App\Domain\Roadmap\Http\Requests\UpdateInitiativeRequest;
use App\Domain\Roadmap\Services\RoadmapChangeLogService;
use App\Domain\Roadmap\Services\RoadmapDecisionService;
use App\Domain\Roadmap\Services\RoadmapScoringService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InitiativeController extends Controller
{
    use ProvidesRoadmapInertiaProps;

    public function __construct(
        protected RoadmapScoringService $scoringService,
        protected RoadmapDecisionService $decisionService,
        protected RoadmapChangeLogService $changeLogService,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $tenantId = $this->tenantId($request);

        $query = Initiative::query()
            ->forTenant($tenantId)
            ->with(['category', 'owner', 'sponsor', 'budgets', 'riskLinks']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('stream')) {
            $query->where('stream', $request->string('stream')->value());
        }

        if ($request->filled('fiscal_year') && $request->filled('quarter')) {
            $query->forQuarter($request->integer('fiscal_year'), $request->integer('quarter'));
        }

        $items = $query
            ->orderByDesc('priority_score')
            ->orderBy('title')
            ->paginate($this->paginationPerPage($request, 30, 100))
            ->withQueryString();

        if ($this->shouldReturnJson($request)) {
            return response()->json(['items' => $items]);
        }

        return Inertia::render('Roadmap/Initiatives/Index', [
            'items' => $items,
            'filters' => [
                'status' => $request->input('status'),
                'stream' => $request->input('stream'),
                'fiscal_year' => $request->input('fiscal_year'),
                'quarter' => $request->input('quarter'),
            ],
            'can' => $this->roadmapCan($request),
        ]);
    }

    public function store(StoreInitiativeRequest $request)
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);

        $data = $request->validated();

        $categoryId = $data['category_id'] ?? null;
        if (! $categoryId && ! empty($data['category_key'])) {
            $category = InitiativeCategory::query()
                ->forTenant($tenantId)
                ->where('key', $data['category_key'])
                ->orderByRaw('tenant_id IS NULL')
                ->first();

            if (! $category) {
                $category = InitiativeCategory::create([
                    'tenant_id' => $tenantId,
                    'key' => $data['category_key'],
                    'name' => ucfirst(str_replace('_', ' ', $data['category_key'])),
                    'sort_order' => 500,
                    'is_active' => true,
                ]);
            }

            $categoryId = $category->id;
        }

        $initiative = Initiative::create([
            'tenant_id' => $tenantId,
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'category_id' => $categoryId,
            'stream' => $data['stream'] ?? 'operations',
            'status' => $data['status'] ?? Initiative::STATUS_DRAFT,
            'owner_user_id' => $data['owner_user_id'] ?? $user?->id,
            'sponsor_user_id' => $data['sponsor_user_id'] ?? null,
            'next_decision' => $data['next_decision'] ?? 'Define scope and approve',
            'decision_due_at' => $data['decision_due_at'] ?? now()->addDays(14)->toDateString(),
            'target_fiscal_year' => $data['target_fiscal_year'] ?? now()->year,
            'target_quarter' => $data['target_quarter'] ?? now()->quarter,
            'cost_estimate_low' => $data['cost_estimate_low'] ?? null,
            'cost_estimate_high' => $data['cost_estimate_high'] ?? null,
            'benefit_summary' => $data['benefit_summary'] ?? null,
            'risk_summary' => $data['risk_summary'] ?? null,
            'dependency_summary' => $data['dependency_summary'] ?? null,
            'impact_profile' => $data['impact_profile'] ?? null,
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);

        $this->scoringService->score($initiative, 'board_ceo', true);
        $this->decisionService->ensureDecisionRequestForInitiative($initiative, $user?->id);

        $this->changeLogService->log(
            $tenantId,
            Initiative::class,
            $initiative->id,
            'initiative.created',
            ['title' => $initiative->title],
            'Quick-add initiative created.',
            $user?->id,
        );

        return response()->json([
            'item' => $initiative->fresh(['category', 'owner', 'budgets']),
        ], 201);
    }

    public function show(Request $request, Initiative $initiative)
    {
        $this->assertTenant($request, $initiative->tenant_id);

        return response()->json([
            'item' => $initiative->load([
                'category',
                'owner',
                'sponsor',
                'budgets',
                'benefits',
                'riskLinks.risk',
                'qualityLinks',
                'dependencies.dependsOn',
                'milestones.tasks',
                'tasks',
                'assurancePlans',
                'contractRefs',
            ]),
        ]);
    }

    public function update(UpdateInitiativeRequest $request, Initiative $initiative)
    {
        $this->assertTenant($request, $initiative->tenant_id);

        $data = $request->validated();

        $before = $initiative->only(array_keys($data));

        if (! empty($data['status']) && ! $initiative->canTransitionTo($data['status']) && $data['status'] !== $initiative->status) {
            return response()->json(['message' => 'Invalid status transition.'], 422);
        }

        $data['updated_by'] = $request->user()?->id;

        $initiative->update($data);

        if (array_key_exists('impact_profile', $data) || array_key_exists('manual_priority_override', $data) === false) {
            $this->scoringService->score(
                $initiative,
                $initiative->score_profile ?: 'board_ceo',
                true,
            );
        }

        $this->decisionService->ensureDecisionRequestForInitiative($initiative, $request->user()?->id);

        $this->changeLogService->log(
            $initiative->tenant_id,
            Initiative::class,
            $initiative->id,
            'initiative.updated',
            [
                'before' => $before,
                'after' => $initiative->only(array_keys($data)),
            ],
            $request->input('change_reason'),
            $request->user()?->id,
        );

        return response()->json([
            'item' => $initiative->fresh(['category', 'owner', 'budgets']),
        ]);
    }

    public function score(Request $request, Initiative $initiative)
    {
        $this->assertTenant($request, $initiative->tenant_id);

        $preset = $request->validate([
            'preset' => ['nullable', 'string', 'max:32'],
        ])['preset'] ?? 'board_ceo';

        $breakdown = $this->scoringService->score($initiative, $preset, true);

        $this->changeLogService->log(
            $initiative->tenant_id,
            Initiative::class,
            $initiative->id,
            'initiative.scored',
            $breakdown,
            null,
            $request->user()?->id,
        );

        return response()->json([
            'item' => $initiative->fresh(),
            'score' => $breakdown,
        ]);
    }

    public function transition(Request $request, Initiative $initiative)
    {
        $this->assertTenant($request, $initiative->tenant_id);

        $status = $request->validate([
            'status' => ['required', 'string', 'max:32'],
        ])['status'];

        if (! $initiative->transitionTo($status)) {
            return response()->json(['message' => 'Invalid status transition.'], 422);
        }

        $this->changeLogService->log(
            $initiative->tenant_id,
            Initiative::class,
            $initiative->id,
            'initiative.transitioned',
            ['status' => $initiative->status],
            null,
            $request->user()?->id,
        );

        return response()->json(['item' => $initiative->fresh()]);
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
