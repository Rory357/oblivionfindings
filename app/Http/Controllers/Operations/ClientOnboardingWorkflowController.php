<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientOnboardingStep;
use App\Models\ClientOnboardingWorkflow;
use Illuminate\Http\Request;

class ClientOnboardingWorkflowController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('onboarding.viewAny'), 403);

        $workflows = ClientOnboardingWorkflow::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name'])
            ->withCount(['steps', 'steps as completed_steps_count' => fn ($q) => $q->where('status', 'completed')])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/onboarding/Index', [
            'workflows' => $workflows,
        ]);
    }

    public function show(Request $request, $workflow)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('onboarding.view'), 403);

        $workflow = ClientOnboardingWorkflow::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name', 'steps' => fn ($q) => $q->orderBy('order')])
            ->findOrFail($workflow);

        return inertia('operations/onboarding/Show', [
            'workflow' => $workflow,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('onboarding.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
        ]);

        $workflow = ClientOnboardingWorkflow::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'status' => 'in_progress',
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
                'name' => $stepName,
                'order' => $order + 1,
                'status' => 'pending',
            ]);
        }

        return redirect()->back()->with('success', 'Onboarding workflow created.');
    }

    public function updateStep(Request $request, $workflow, $step)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('onboarding.edit'), 403);

        ClientOnboardingWorkflow::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($workflow);

        $step = ClientOnboardingStep::where('client_onboarding_workflow_id', $workflow)->findOrFail($step);

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

    public function complete(Request $request, $workflow)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('onboarding.edit'), 403);

        $workflow = ClientOnboardingWorkflow::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($workflow);

        $workflow->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Onboarding workflow completed.');
    }
}
