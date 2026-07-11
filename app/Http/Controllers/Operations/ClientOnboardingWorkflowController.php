<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientOnboardingStep;
use App\Models\ClientOnboardingWorkflow;
use App\Services\Clients\ClientOnboardingAccess;
use App\Services\Clients\ClientWorkerEligibility;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientOnboardingWorkflowController extends Controller
{
    public function __construct(
        private readonly ClientOnboardingAccess $access,
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

        $workflows = ClientOnboardingWorkflow::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name'])
            ->withCount(['steps', 'steps as completed_steps_count' => fn ($q) => $q->where('status', 'completed')])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('client', function ($clientQuery) use ($search) {
                    $clientQuery
                        ->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'active' => ClientOnboardingWorkflow::where('organization_id', $auth->organization_id)->where('status', 'in_progress')->count(),
            'completed_this_month' => ClientOnboardingWorkflow::where('organization_id', $auth->organization_id)->where('status', 'completed')->where('completed_at', '>=', now()->startOfMonth())->count(),
            'overdue_steps' => ClientOnboardingStep::whereHas('workflow', fn ($q) => $q->where('organization_id', $auth->organization_id)->where('status', 'in_progress'))->where('status', 'pending')->where('due_date', '<', now())->count(),
            'avg_days' => (int) (ClientOnboardingWorkflow::where('organization_id', $auth->organization_id)->where('status', 'completed')->whereNotNull('completed_at')->selectRaw('AVG(DATEDIFF(completed_at, started_at)) as avg_days')->value('avg_days') ?? 0),
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

        $workflow = ClientOnboardingWorkflow::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
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

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
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

        $data = $request->validate([
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where(
                    fn ($query) => $auth->organization_id !== null
                        ? $query->where('organization_id', $auth->organization_id)
                        : $query,
                ),
            ],
        ]);

        $workflow = ClientOnboardingWorkflow::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'status' => 'in_progress',
            'started_at' => now(),
            'created_by' => $auth->id,
        ]);

        $defaultSteps = [
            'Referral Received',
            'Needs Assessment',
            'Consent Forms',
            'Care Plan Created',
            'Service Agreement Signed',
            'Staff Assigned',
            'Orientation Complete',
        ];

        foreach ($defaultSteps as $order => $stepName) {
            $workflow->steps()->create([
                'step_name' => $stepName,
                'step_order' => $order + 1,
                'status' => 'pending',
            ]);
        }

        return redirect()->back()->with('success', 'Onboarding workflow created.');
    }

    public function storeStep(Request $request, $workflow)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canManageWorkflows($auth), 403);

        $workflowModel = ClientOnboardingWorkflow::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
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
                        $fail('Choose an eligible assignee from this organisation.');
                    }
                },
            ],
            'due_date' => ['nullable', 'date'],
            'is_required' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $workflowModel->steps()->create([
            ...$data,
            'organization_id' => $workflowModel->organization_id,
            'step_order' => ((int) $workflowModel->steps()->max('step_order')) + 1,
            'is_required' => $data['is_required'] ?? true,
            'status' => 'pending',
        ]);

        return redirect()->back()->with('success', 'Onboarding step added.');
    }

    public function updateStep(Request $request, $workflow, $step)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canManageWorkflows($auth), 403);

        ClientOnboardingWorkflow::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($workflow);

        $step = ClientOnboardingStep::where('workflow_id', $workflow)->findOrFail($step);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:pending,completed,skipped'],
            'notes' => ['nullable', 'string'],
        ]);

        $step->update([
            'status' => $data['status'],
            'notes' => $data['notes'] ?? $step->notes,
            'completed_at' => $data['status'] === 'completed' ? now() : $step->completed_at,
            'completed_by' => $data['status'] === 'completed' ? $auth->id : $step->completed_by,
        ]);

        return redirect()->back()->with('success', 'Step updated.');
    }

    public function storeForClient(Request $request, Client $client)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canCreateWorkflows($auth), 403);
        $this->authorize('view', $client);
        abort_if(
            $auth->organization_id !== null
                && $client->organization_id !== null
                && (int) $auth->organization_id !== (int) $client->organization_id,
            403,
        );

        // Check if client already has an active workflow
        $existing = $client->onboardingWorkflows()
            ->where('status', 'in_progress')
            ->exists();

        if ($existing) {
            return redirect()->back()->with('error', 'Client already has an active onboarding workflow.');
        }

        ClientOnboardingWorkflow::createForClient($client, $auth->id);

        return redirect()->back()->with('success', 'Onboarding workflow created successfully.');
    }

    public function complete(Request $request, $workflow)
    {
        $auth = $request->user();
        abort_unless($auth && $this->access->canManageWorkflows($auth), 403);

        $workflow = ClientOnboardingWorkflow::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($workflow);

        if ($workflow->steps()->where('is_required', true)->where('status', 'pending')->exists()) {
            return back()->withErrors([
                'workflow' => 'Complete or explicitly skip every required onboarding step first.',
            ]);
        }

        $workflow->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $workflow->client->update(['status' => 'active']);

        return redirect()->back()->with('success', 'Onboarding workflow completed.');
    }
}
