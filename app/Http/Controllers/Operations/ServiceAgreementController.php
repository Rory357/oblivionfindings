<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ServiceAgreement;
use App\Models\ServiceAgreementStatusChange;
use Illuminate\Http\Request;

class ServiceAgreementController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.viewAny'), 403);

        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'status' => ['nullable', 'string', 'in:draft,active,expired,cancelled'],
            'agreement_type' => ['nullable', 'string'],
            'expiring_soon' => ['nullable', 'boolean'],
        ]);

        $agreements = ServiceAgreement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name', 'creator:id,name'])
            ->withCount(['lineItems', 'fundingClaims'])
            ->when(!empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->when(!empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(!empty($data['agreement_type']), fn ($q) => $q->where('agreement_type', $data['agreement_type']))
            ->when(!empty($data['expiring_soon']), fn ($q) => $q->expiringSoon())
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        // Append the budget_utilisation_percent accessor to each item
        $agreements->getCollection()->transform(function ($agreement) {
            $agreement->append(['budget_utilisation_percent', 'budget_remaining']);
            return $agreement;
        });

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/service-agreements/Index', [
            'agreements' => $agreements,
            'clients' => $clients,
            'filters' => $request->only(['client_id', 'status', 'agreement_type', 'expiring_soon']),
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.create'), 403);

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/service-agreements/Create', [
            'clients' => $clients,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:255'],
            'agreement_type' => ['required', 'string', 'max:100'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'funding_body' => ['nullable', 'string', 'max:255'],
            'funding_reference' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'total_budget' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
            'terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:draft,active,expired,cancelled'],
        ]);

        $agreement = ServiceAgreement::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'title' => $data['title'],
            'agreement_type' => $data['agreement_type'],
            'reference_number' => $data['reference_number'] ?? null,
            'funding_body' => $data['funding_body'] ?? null,
            'funding_reference' => $data['funding_reference'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'total_budget' => $data['total_budget'] ?? 0,
            'budget_used' => 0,
            'hourly_rate' => $data['hourly_rate'] ?? null,
            'daily_rate' => $data['daily_rate'] ?? null,
            'terms' => $data['terms'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'created_by' => $auth->id,
        ]);

        return redirect()->route('operations.service_agreements.show', $agreement)
            ->with('success', 'Service agreement created.');
    }

    public function show(Request $request, $agreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.viewAny'), 403);

        $agreement = ServiceAgreement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'client:id,first_name,last_name',
                'creator:id,name',
                'lineItems',
                'fundingClaims' => fn ($q) => $q->orderByDesc('created_at'),
                'fundingClaims.submitter:id,name',
                'statusChanges' => fn ($q) => $q->orderByDesc('created_at'),
                'statusChanges.user:id,name',
            ])
            ->withCount('fundingClaims')
            ->findOrFail($agreement);

        $agreement->append(['budget_utilisation_percent', 'budget_remaining']);

        // Calculate actual budget from line items
        $budgetFromItems = $agreement->lineItems->sum('budget_used');
        $allocatedFromItems = $agreement->lineItems->sum('budget_allocated');
        $effectiveUsed = $budgetFromItems > 0 ? $budgetFromItems : (float) $agreement->budget_used;

        return inertia('operations/service-agreements/Show', [
            'agreement' => $agreement,
            'budget_summary' => [
                'total_budget' => (float) $agreement->total_budget,
                'budget_used' => round($effectiveUsed, 2),
                'budget_allocated' => round($allocatedFromItems, 2),
                'budget_remaining' => round((float) $agreement->total_budget - $effectiveUsed, 2),
                'utilisation_percent' => $agreement->total_budget > 0
                    ? round(($effectiveUsed / (float) $agreement->total_budget) * 100, 1)
                    : 0,
            ],
        ]);
    }

    public function edit(Request $request, $agreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = ServiceAgreement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name', 'lineItems'])
            ->findOrFail($agreement);

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/service-agreements/Edit', [
            'agreement' => $agreement,
            'clients' => $clients,
        ]);
    }

    public function update(Request $request, $agreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = ServiceAgreement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($agreement);

        $data = $request->validate([
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'agreement_type' => ['sometimes', 'required', 'string', 'max:100'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'funding_body' => ['nullable', 'string', 'max:255'],
            'funding_reference' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'total_budget' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
            'terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:draft,active,expired,cancelled'],
        ]);

        $agreement->update($data);

        return redirect()->route('operations.service_agreements.show', $agreement)
            ->with('success', 'Service agreement updated.');
    }

    public function transition(Request $request, $serviceAgreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = ServiceAgreement::findOrFail($serviceAgreement);

        $data = $request->validate([
            'status' => ['required', 'in:draft,pending_approval,active,under_review,renewed,expired,terminated,suspended'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $fromStatus = $agreement->status;

        // Record status change
        ServiceAgreementStatusChange::create([
            'service_agreement_id' => $agreement->id,
            'from_status' => $fromStatus,
            'to_status' => $data['status'],
            'changed_by' => $auth->id,
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        // Update agreement
        $updates = ['status' => $data['status']];
        if ($data['status'] === 'terminated') {
            $updates['terminated_at'] = now();
            $updates['terminated_reason'] = $data['reason'] ?? null;
        }
        if ($data['status'] === 'suspended') {
            $updates['suspended_at'] = now();
            $updates['suspended_reason'] = $data['reason'] ?? null;
        }
        if ($data['status'] === 'active' && $fromStatus === 'suspended') {
            $updates['resumed_at'] = now();
        }

        $agreement->update($updates);

        return redirect()->back()->with('success', "Agreement status changed to {$data['status']}.");
    }

    public function destroy(Request $request, $agreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = ServiceAgreement::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($agreement);

        $agreement->delete();

        return redirect()->route('operations.service_agreements.index')
            ->with('success', 'Service agreement deleted.');
    }
}
