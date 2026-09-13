<?php

namespace App\Http\Controllers\Operations;

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
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        $scopedWorkflows = fn () => $this->workflowQueryFor(
            $auth,
            ['onboarding.viewAny'],
        );

        $workflows = $scopedWorkflows()
            ->with(['client:id,first_name,last_name'])
            ->withCount(['steps', 'steps as completed_steps_count' => fn ($q) => $q->where('status', 'completed')])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
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
                ->whereHas('workflow', fn ($workflowQuery) => $workflowQuery
                    ->where('status', 'in_progress')
                    ->whereHas('client', fn ($clientQuery) => $this->siteAccess->applyClientScope(
                        $clientQuery,
                        $auth,
                        ['onboarding.viewAny'],
                    )))
                ->where('status', 'pending')
                ->where('due_date', '<', now())
                ->count(),
            'avg_days' => (int) ($scopedWorkflows()
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->selectRaw('AVG(DATEDIFF(completed_at, started_at)) as avg_days')
                ->value('avg_days') ?? 0),
        ];

        return inertia('operations/onboarding/Index', [
            'workflows' => $workflows,
            'stats' => $stats,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
        ]);
    }

    public function show(Request $request, $workflow)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canViewWorkflows($auth), 403);

        $workflow = $this->workflowQueryFor($auth, ['onboarding.viewAny'])
            ->with(['client:id,first_name,last_name', 'steps' => fn ($q) => $q->orderBy('step_order')])
            ->findOrFail($workflow);

        return inertia('operations/onboarding/Show', [
            'workflow' => $workflow,
        ]);
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
            $workflowModel = $this->workflowQueryFor($auth, ['clients.update'])
                ->with('client')
                ->lockForUpdate()
                ->findOrFail($workflow);

            if ($workflowModel->steps()->where('is_required', true)->where('status', 'pending')->exists()) {
                return false;
            }

            $workflowModel->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $auth->id,
            ]);
            $workflowModel->client->update(['status' => 'active']);

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

            if ($lockedClient->onboardingWorkflows()->where('status', 'in_progress')->exists()) {
                return null;
            }

            return ClientOnboardingWorkflow::createForClient($lockedClient, $createdBy);
        });
    }
}
