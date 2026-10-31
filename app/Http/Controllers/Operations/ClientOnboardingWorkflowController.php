<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Referrals\Services\ReferralActivationGuard;
use App\Domain\Referrals\Services\ReferralCareHandovers;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientOnboardingStep;
use App\Models\ClientOnboardingWorkflow;
use App\Models\User;
use App\Services\Clients\ClientOnboardingAccess;
use App\Services\Clients\ClientWorkerEligibility;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientOnboardingWorkflowController extends Controller
{
    public function __construct(
        private readonly ClientOnboardingAccess $access,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canViewWorkflows($auth), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:in_progress,completed,cancelled'],
            'overdue' => ['nullable', 'boolean'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        $scopedWorkflows = fn () => $this->workflowQueryFor(
            $auth,
            ['onboarding.viewAny'],
        );

        $workflows = $scopedWorkflows()
            ->with(['client:id,first_name,last_name', 'site:id,name', 'serviceContext:id,name', 'assignee:id,name'])
            ->withCount(['steps', 'steps as completed_steps_count' => fn ($q) => $q->where('status', 'completed'),
                'steps as overdue_steps' => fn ($q) => $q->where('status', 'pending')->where('due_date', '<', now('Pacific/Auckland')->toDateString()),
            ])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['overdue'] ?? false, fn ($q) => $q->where('status', 'in_progress')->whereHas('steps', fn ($steps) => $steps
                ->where('status', 'pending')->where('due_date', '<', now('Pacific/Auckland')->toDateString())))
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('client', function ($clientQuery) use ($search) {
                    $clientQuery->where(fn ($nameQuery) => $nameQuery
                        ->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%'));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'active' => $scopedWorkflows()->where('status', 'in_progress')->count(),
            'completed_this_month' => $scopedWorkflows()->where('status', 'completed')->where('completed_at', '>=', now()->startOfMonth())->count(),
            'overdue_steps' => ClientOnboardingStep::query()
                ->whereIn('workflow_id', $scopedWorkflows()->where('status', 'in_progress')->select('client_onboarding_workflows.id'))
                ->where('status', 'pending')
                ->where('due_date', '<', now('Pacific/Auckland')->toDateString())
                ->count(),
            'avg_days' => (int) ($scopedWorkflows()
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->selectRaw('AVG(DATEDIFF(completed_at, started_at)) as avg_days')
                ->value('avg_days') ?? 0),
        ];

        // Per-status counts over the whole scoped set — rail counters stay
        // honest whatever filter is active.
        $statusCounts = $scopedWorkflows()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return inertia('operations/onboarding/Index', [
            'workflows' => $workflows,
            'stats' => $stats,
            'status_counts' => $statusCounts,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'status' => $filters['status'] ?? null,
                'overdue' => (bool) ($filters['overdue'] ?? false),
            ],
        ]);
    }

    public function show(Request $request, $workflow)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canViewWorkflows($auth), 403);

        $workflow = $this->workflowQueryFor($auth, ['onboarding.viewAny'])
            ->with(['client:id,first_name,last_name,site_id', 'site:id,name', 'serviceContext:id,name', 'steps' => fn ($q) => $q->orderBy('step_order')])
            ->findOrFail($workflow);

        return inertia('operations/onboarding/Show', [
            'workflow' => $workflow,
            'capabilities' => [
                'complete' => $this->access->canManageWorkflows($auth),
                'open_person' => $workflow->client && $auth->can('view', $workflow->client),
            ],
            'requires_separate_service_start' => $workflow->source_referral_id !== null || ($workflow->client && app(ReferralActivationGuard::class)->governsActivation($workflow->client)),
            'care_handovers' => app(ReferralCareHandovers::class)->forWorkflow($auth, $workflow),
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canCreateWorkflows($auth), 403);

        $clients = $this->siteAccess->applyClientScope(
            Client::query(),
            $auth,
            ['clients.viewAny', 'clients.update'],
        )
            ->select('id', 'first_name', 'last_name')
            ->orderBy('last_name')
            ->get();

        return inertia('operations/onboarding/Create', [
            'clients' => $clients,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canCreateWorkflows($auth), 403);
        $accessibleSiteIds = $this->siteAccess->accessibleSiteIds(
            $auth,
            ['clients.viewAny', 'clients.update'],
        );

        $data = $request->validate([
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where(
                    fn ($query) => $query
                        ->whereNotNull('site_id')
                        ->whereIn('site_id', $accessibleSiteIds),
                ),
            ],
        ]);

        $client = $this->siteAccess->applyClientScope(
            Client::query(),
            $auth,
            ['clients.viewAny', 'clients.update'],
        )->findOrFail($data['client_id']);

        if (! $this->createWorkflowForClient($client, (int) $auth->id)) {
            return redirect()->back()->with('error', 'Client already has an active onboarding workflow.');
        }

        return redirect()->back()->with('success', 'Onboarding workflow created.');
    }

    public function storeStep(Request $request, $workflow)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canManageWorkflows($auth), 403);

        $workflowModel = $this->workflowQueryFor($auth, ['clients.update'])
            ->with('client')
            ->findOrFail($workflow);

        $data = $request->validate([
            'step_name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:60'],
            'assigned_to' => [
                'bail',
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($workflowModel): void {
                    if (! app(ClientWorkerEligibility::class)->contains($workflowModel->client, (int) $value)) {
                        $fail('Choose a current eligible assignee from this Client Site.');
                    }
                },
            ],
            'due_date' => ['nullable', 'date'],
            'is_required' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($auth, $data, $workflow): void {
            $lockedWorkflow = $this->workflowQueryFor($auth, ['clients.update'])
                ->lockForUpdate()
                ->findOrFail($workflow);

            $lockedWorkflow->steps()->create([
                ...$data,
                'step_order' => ((int) $lockedWorkflow->steps()->max('step_order')) + 1,
                'is_required' => $data['is_required'] ?? true,
                'status' => 'pending',
            ]);
        });

        return redirect()->back()->with('success', 'Onboarding step added.');
    }

    public function updateStep(Request $request, $workflow, $step)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canManageWorkflows($auth), 403);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:pending,completed,skipped'],
            'notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($auth, $data, $step, $workflow): void {
            $this->workflowQueryFor($auth, ['clients.update'])
                ->lockForUpdate()
                ->findOrFail($workflow);

            $stepModel = ClientOnboardingStep::query()
                ->where('workflow_id', $workflow)
                ->lockForUpdate()
                ->findOrFail($step);

            $isCompleted = $data['status'] === 'completed';
            $stepModel->update([
                'status' => $data['status'],
                'notes' => $data['notes'] ?? $stepModel->notes,
                'completed_at' => $isCompleted ? now() : null,
                'completed_by' => $isCompleted ? $auth->id : null,
            ]);
        });

        return redirect()->back()->with('success', 'Step updated.');
    }

    public function storeForClient(Request $request, Client $client)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canCreateWorkflows($auth), 403);
        $this->authorize('view', $client);

        if (! $this->createWorkflowForClient($client, (int) $auth->id)) {
            return redirect()->back()->with('error', 'Client already has an active onboarding workflow.');
        }

        return redirect()->back()->with('success', 'Onboarding workflow created successfully.');
    }

    public function complete(Request $request, $workflow)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canManageWorkflows($auth), 403);

        $completed = DB::transaction(function () use ($auth, $workflow): bool {
            $candidate = $this->workflowQueryFor($auth, ['clients.update'])->findOrFail($workflow);
            // Match the conversion lock order: person, then onboarding workflow.
            $client = Client::query()->lockForUpdate()->findOrFail($candidate->client_id);
            $workflowModel = $this->workflowQueryFor($auth, ['clients.update'])
                ->lockForUpdate()
                ->findOrFail($workflow);
            $workflowModel->setRelation('client', $client);

            if ($workflowModel->steps()->where('is_required', true)->where('status', 'pending')->exists()) {
                return false;
            }

            $workflowModel->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $auth->id,
            ]);
            // Referral-originated people (or everyone, when the organisation requires it) start
            // support only through the separate commencement review; others keep the established flow.
            if (! $workflowModel->source_referral_id && ! app(ReferralActivationGuard::class)->governsActivation($client)) {
                $client->update(['status' => 'active']);
            }

            return true;
        });

        if (! $completed) {
            return back()->withErrors([
                'workflow' => 'Complete or explicitly skip every required onboarding step first.',
            ]);
        }

        return redirect()->back()->with('success', 'Onboarding workflow completed.');
    }

    /**
     * @param  array<int, string>  $bypassPermissions
     * @return Builder<ClientOnboardingWorkflow>
     */
    private function workflowQueryFor(User $user, array $bypassPermissions = []): Builder
    {
        return ClientOnboardingWorkflow::query()
            ->where(function (Builder $scope) use ($user): void {
                $scope->whereNull('site_id')->orWhereIn('site_id', $this->siteAccess->accessibleSiteIds($user, ['clinical.accessAllSites', 'sites.viewAll']));
            })
            ->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
                $clientQuery,
                $user,
                $bypassPermissions,
            ));
    }

    private function createWorkflowForClient(Client $client, int $createdBy): ?ClientOnboardingWorkflow
    {
        return DB::transaction(function () use ($client, $createdBy): ?ClientOnboardingWorkflow {
            $lockedClient = Client::query()
                ->lockForUpdate()
                ->findOrFail($client->getKey());

            if ($lockedClient->onboardingWorkflows()->where('scope', 'person')->where('status', 'in_progress')->exists()) {
                return null;
            }

            return ClientOnboardingWorkflow::createForClient($lockedClient, $createdBy);
        });
    }
}
