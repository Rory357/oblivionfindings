<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ServiceAgreement;
use App\Models\ServiceAgreementLineItem;
use App\Models\ServiceAgreementRate;
use App\Models\ServiceAgreementStatusChange;
use App\Models\User;
use App\Services\Operations\OpsNotificationService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ServiceAgreementController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.viewAny'), 403);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'status' => ['nullable', 'string', 'in:draft,pending_approval,active,under_review,renewed,expired,terminated,suspended'],
            'agreement_type' => ['nullable', 'string'],
            'funding_type' => ['nullable', 'string'],
        ]);

        $baseQuery = $this->accessibleAgreements($auth);

        if (! empty($data['client_id'])) {
            $this->siteAccess->assertCanAccessClientId(
                $auth,
                (int) $data['client_id'],
                ['reports.viewAny'],
            );
        }

        // Compute stats from the Site-scoped base (before user filters).
        $stats = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->where('status', 'active')->count(),
            'pending_approval' => (clone $baseQuery)->where('status', 'pending_approval')->count(),
            'expiring_soon' => (clone $baseQuery)->where('status', 'active')
                ->where('ends_at', '<=', now()->addDays(30))
                ->where('ends_at', '>=', now())
                ->count(),
            'total_budget' => (float) (clone $baseQuery)->sum('total_budget'),
            'total_used' => (float) ServiceAgreementLineItem::query()
                ->whereIn('service_agreement_id', (clone $baseQuery)->select('id'))
                ->sum('budget_used'),
            'draft_count' => (clone $baseQuery)->where('status', 'draft')->count(),
        ];

        $agreements = (clone $baseQuery)
            ->with(['client:id,first_name,last_name', 'creator:id,name'])
            ->withCount(['lineItems', 'fundingClaims'])
            ->when(! empty($data['q']), function ($q) use ($data) {
                $search = $data['q'];
                $q->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', "%{$search}%")
                        ->orWhere('reference_number', 'like', "%{$search}%")
                        ->orWhere('funding_body', 'like', "%{$search}%")
                        ->orWhere('funding_reference', 'like', "%{$search}%");
                });
            })
            ->when(! empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['agreement_type']), fn ($q) => $q->where('agreement_type', $data['agreement_type']))
            ->when(! empty($data['funding_type']), fn ($q) => $q->where('funding_type', $data['funding_type']))
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        // Append the budget_utilisation_percent accessor to each item
        $agreements->getCollection()->transform(function ($agreement) {
            $agreement->append(['budget_utilisation_percent', 'budget_remaining']);

            return $agreement;
        });

        $clients = $this->accessibleClients($auth);

        return inertia('operations/service-agreements/Index', [
            'agreements' => $agreements,
            'clients' => $clients,
            'stats' => $stats,
            'filters' => $request->only(['q', 'client_id', 'status', 'agreement_type', 'funding_type']),
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.create'), 403);

        $clients = $this->accessibleClients($auth);

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
            'nasc_assessment_date' => ['nullable', 'date'],
            'funding_approved_date' => ['nullable', 'date'],
            'signed_date' => ['nullable', 'date'],
            'first_service_date' => ['nullable', 'date'],
            'review_due_date' => ['nullable', 'date'],
            'renewal_date' => ['nullable', 'date'],
            'total_budget' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
            'terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:draft,active,expired,cancelled'],
            // NZ Funding Details
            'funding_type' => ['nullable', 'string', 'max:100'],
            'service_level' => ['nullable', 'string', 'max:100'],
            'allocated_hours_per_week' => ['nullable', 'numeric', 'min:0'],
            'carer_support_days_allocated' => ['nullable', 'integer', 'min:0', 'max:366'],
            'carer_support_days_used' => ['nullable', 'integer', 'min:0', 'max:999'],
            'carer_support_entitlement_year' => ['nullable', 'string', 'max:9'],
            'total_hours' => ['nullable', 'numeric', 'min:0'],
            'gst_inclusive' => ['nullable', 'boolean'],
            'whaikaha_reference' => ['nullable', 'string', 'max:255'],
            'support_needs_level' => ['nullable', 'string', 'max:100'],
            // NASC Details
            'nasc_assessor_name' => ['nullable', 'string', 'max:255'],
            'nasc_support_package_ref' => ['nullable', 'string', 'max:255'],
            // Signatories & Contacts
            'client_signatory' => ['nullable', 'string', 'max:255'],
            'provider_signatory' => ['nullable', 'string', 'max:255'],
            'funder_contact_name' => ['nullable', 'string', 'max:255'],
            'funder_contact_email' => ['nullable', 'email', 'max:255'],
            'funder_contact_phone' => ['nullable', 'string', 'max:50'],
        ]);

        $this->siteAccess->assertCanAccessClientId(
            $auth,
            (int) $data['client_id'],
            ['reports.viewAny'],
        );

        $agreement = ServiceAgreement::create([
            'client_id' => $data['client_id'],
            'title' => $data['title'],
            'agreement_type' => $data['agreement_type'],
            'reference_number' => $data['reference_number'] ?? null,
            'funding_body' => $data['funding_body'] ?? null,
            'funding_reference' => $data['funding_reference'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'nasc_assessment_date' => $data['nasc_assessment_date'] ?? null,
            'funding_approved_date' => $data['funding_approved_date'] ?? null,
            'signed_date' => $data['signed_date'] ?? null,
            'first_service_date' => $data['first_service_date'] ?? null,
            'review_due_date' => $data['review_due_date'] ?? null,
            'renewal_date' => $data['renewal_date'] ?? null,
            'total_budget' => $data['total_budget'] ?? 0,
            'budget_used' => 0,
            'hourly_rate' => $data['hourly_rate'] ?? null,
            'daily_rate' => $data['daily_rate'] ?? null,
            'terms' => $data['terms'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'created_by' => $auth->id,
            // NZ Funding Details
            'funding_type' => $data['funding_type'] ?? null,
            'service_level' => $data['service_level'] ?? null,
            'allocated_hours_per_week' => $data['allocated_hours_per_week'] ?? null,
            'carer_support_days_allocated' => $data['carer_support_days_allocated'] ?? null,
            'carer_support_days_used' => $data['carer_support_days_used'] ?? 0,
            'carer_support_entitlement_year' => $data['carer_support_entitlement_year'] ?? null,
            'total_hours' => $data['total_hours'] ?? null,
            'gst_inclusive' => $data['gst_inclusive'] ?? true,
            'whaikaha_reference' => $data['whaikaha_reference'] ?? null,
            'support_needs_level' => $data['support_needs_level'] ?? null,
            // NASC Details
            'nasc_assessor_name' => $data['nasc_assessor_name'] ?? null,
            'nasc_support_package_ref' => $data['nasc_support_package_ref'] ?? null,
            // Signatories & Contacts
            'client_signatory' => $data['client_signatory'] ?? null,
            'provider_signatory' => $data['provider_signatory'] ?? null,
            'funder_contact_name' => $data['funder_contact_name'] ?? null,
            'funder_contact_email' => $data['funder_contact_email'] ?? null,
            'funder_contact_phone' => $data['funder_contact_phone'] ?? null,
        ]);

        app(OpsNotificationService::class)->notifyCrud($auth, 'created', 'service agreement', $agreement, Client::find($data['client_id']));

        return redirect()->route('operations.service_agreements.show', $agreement)
            ->with('success', 'Service agreement created.');
    }

    public function show(Request $request, $agreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.viewAny'), 403);

        $agreement = $this->accessibleAgreements($auth)
            ->with([
                'client:id,first_name,last_name',
                'creator:id,name',
                'lineItems',
                'rates',
                'fundingClaims' => fn ($q) => $q->orderByDesc('created_at'),
                'fundingClaims.submitter:id,name',
                'statusChanges' => fn ($q) => $q->orderByDesc('created_at'),
                'statusChanges.user:id,name',
            ])
            ->withCount('fundingClaims')
            ->findOrFail($agreement);

        $agreement->append([
            'budget_utilisation_percent',
            'budget_remaining',
            'carer_support_days_remaining',
            'carer_support_utilisation_percent',
        ]);

        // Calculate actual budget from line items
        $budgetFromItems = $agreement->lineItems->sum('budget_used');
        $allocatedFromItems = $agreement->lineItems->sum('budget_allocated');
        $effectiveUsed = $budgetFromItems > 0 ? $budgetFromItems : (float) $agreement->budget_used;

        // Hours utilisation
        $totalHours = (float) ($agreement->total_hours ?? 0);
        $hoursUsed = (float) ($agreement->hours_used ?? 0);
        $hoursRemaining = $totalHours > 0 ? round($totalHours - $hoursUsed, 1) : null;
        $hoursUtilisationPercent = $totalHours > 0 ? round(($hoursUsed / $totalHours) * 100, 1) : null;

        $agreement->hours_remaining = $hoursRemaining;
        $agreement->hours_utilisation_percent = $hoursUtilisationPercent;

        // Funding claims summary by status
        $claimsByStatus = $agreement->fundingClaims->groupBy('status');
        $fundingClaimsSummary = [
            'draft' => ($claimsByStatus->get('draft') ?? collect())->count(),
            'submitted' => ($claimsByStatus->get('submitted') ?? collect())->count(),
            'approved' => ($claimsByStatus->get('approved') ?? collect())->count(),
            'total_claimed' => round((float) $agreement->fundingClaims->sum('amount'), 2),
        ];

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
            'funding_claims_summary' => $fundingClaimsSummary,
        ]);
    }

    public function edit(Request $request, $agreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)
            ->with(['client:id,first_name,last_name', 'lineItems'])
            ->findOrFail($agreement);

        $clients = $this->accessibleClients($auth);

        return inertia('operations/service-agreements/Edit', [
            'agreement' => $agreement,
            'clients' => $clients,
        ]);
    }

    public function update(Request $request, $agreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)->findOrFail($agreement);

        $data = $request->validate([
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'agreement_type' => ['sometimes', 'required', 'string', 'max:100'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'funding_body' => ['nullable', 'string', 'max:255'],
            'funding_reference' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'nasc_assessment_date' => ['nullable', 'date'],
            'funding_approved_date' => ['nullable', 'date'],
            'signed_date' => ['nullable', 'date'],
            'first_service_date' => ['nullable', 'date'],
            'review_due_date' => ['nullable', 'date'],
            'renewal_date' => ['nullable', 'date'],
            'total_budget' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
            'terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:draft,active,expired,cancelled'],
            // NZ Funding Details
            'funding_type' => ['nullable', 'string', 'max:100'],
            'service_level' => ['nullable', 'string', 'max:100'],
            'allocated_hours_per_week' => ['nullable', 'numeric', 'min:0'],
            'carer_support_days_allocated' => ['nullable', 'integer', 'min:0', 'max:366'],
            'carer_support_days_used' => ['nullable', 'integer', 'min:0', 'max:999'],
            'carer_support_entitlement_year' => ['nullable', 'string', 'max:9'],
            'total_hours' => ['nullable', 'numeric', 'min:0'],
            'gst_inclusive' => ['nullable', 'boolean'],
            'whaikaha_reference' => ['nullable', 'string', 'max:255'],
            'support_needs_level' => ['nullable', 'string', 'max:100'],
            // NASC Details
            'nasc_assessor_name' => ['nullable', 'string', 'max:255'],
            'nasc_support_package_ref' => ['nullable', 'string', 'max:255'],
            // Signatories & Contacts
            'client_signatory' => ['nullable', 'string', 'max:255'],
            'provider_signatory' => ['nullable', 'string', 'max:255'],
            'funder_contact_name' => ['nullable', 'string', 'max:255'],
            'funder_contact_email' => ['nullable', 'email', 'max:255'],
            'funder_contact_phone' => ['nullable', 'string', 'max:50'],
        ]);

        if (array_key_exists('client_id', $data)) {
            $this->siteAccess->assertCanAccessClientId(
                $auth,
                (int) $data['client_id'],
                ['reports.viewAny'],
            );
        }

        $agreement->update($data);

        return redirect()->route('operations.service_agreements.show', $agreement)
            ->with('success', 'Service agreement updated.');
    }

    public function transition(Request $request, $serviceAgreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)->findOrFail($serviceAgreement);

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

        app(OpsNotificationService::class)->notifyCrud($auth, $data['status'], 'service agreement', $agreement);

        return redirect()->back()->with('success', "Agreement status changed to {$data['status']}.");
    }

    public function submitForApproval(Request $request, $serviceAgreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)->findOrFail($serviceAgreement);

        abort_unless($agreement->status === 'draft', 422, 'Only draft agreements can be submitted for approval.');

        $fromStatus = $agreement->status;

        ServiceAgreementStatusChange::create([
            'service_agreement_id' => $agreement->id,
            'from_status' => $fromStatus,
            'to_status' => 'pending_approval',
            'changed_by' => $auth->id,
            'reason' => 'Submitted for approval',
            'notes' => null,
        ]);

        $agreement->update([
            'status' => 'pending_approval',
            'submitted_for_approval_at' => now(),
            'submitted_for_approval_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Agreement submitted for approval.');
    }

    public function approve(Request $request, $serviceAgreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)->findOrFail($serviceAgreement);

        abort_unless($agreement->status === 'pending_approval', 422, 'Only agreements pending approval can be approved.');

        $fromStatus = $agreement->status;

        ServiceAgreementStatusChange::create([
            'service_agreement_id' => $agreement->id,
            'from_status' => $fromStatus,
            'to_status' => 'active',
            'changed_by' => $auth->id,
            'reason' => 'Approved',
            'notes' => $request->input('notes'),
        ]);

        $agreement->update([
            'status' => 'active',
            'approved_at' => now(),
            'approved_by' => $auth->id,
        ]);

        app(OpsNotificationService::class)->notifyCrud($auth, 'approved', 'service agreement', $agreement);

        return redirect()->back()->with('success', 'Agreement approved and now active.');
    }

    public function reject(Request $request, $serviceAgreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)->findOrFail($serviceAgreement);

        abort_unless($agreement->status === 'pending_approval', 422, 'Only agreements pending approval can be rejected.');

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $fromStatus = $agreement->status;

        ServiceAgreementStatusChange::create([
            'service_agreement_id' => $agreement->id,
            'from_status' => $fromStatus,
            'to_status' => 'draft',
            'changed_by' => $auth->id,
            'reason' => $data['reason'] ?? 'Returned to draft',
            'notes' => null,
        ]);

        $agreement->update([
            'status' => 'draft',
            'submitted_for_approval_at' => null,
            'submitted_for_approval_by' => null,
        ]);

        return redirect()->back()->with('success', 'Agreement returned to draft.');
    }

    public function destroy(Request $request, $agreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)->findOrFail($agreement);

        $agreement->delete();

        return redirect()->route('operations.service_agreements.index')
            ->with('success', 'Service agreement deleted.');
    }

    // -------------------------------------------------------------------------
    // Line Item CRUD
    // -------------------------------------------------------------------------

    public function storeLineItem(Request $request, $serviceAgreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)->findOrFail($serviceAgreement);

        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'string', 'in:hour,night,day,km,trip,flat'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'budget_allocated' => ['nullable', 'numeric', 'min:0'],
            'funding_contract_reference' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:255'],
        ]);

        $agreement->lineItems()->create([
            'description' => $data['description'],
            'unit_price' => $data['unit_price'],
            'unit' => $data['unit'],
            'quantity' => $data['quantity'] ?? null,
            'budget_allocated' => $data['budget_allocated'] ?? ($data['unit_price'] * ($data['quantity'] ?? 0)),
            'funding_contract_reference' => $data['funding_contract_reference'] ?? null,
            'category' => $data['category'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Line item added.');
    }

    public function updateLineItem(Request $request, $serviceAgreement, $lineItem)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)->findOrFail($serviceAgreement);

        $item = ServiceAgreementLineItem::where('service_agreement_id', $agreement->id)
            ->findOrFail($lineItem);

        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'string', 'in:hour,night,day,km,trip,flat'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'budget_allocated' => ['nullable', 'numeric', 'min:0'],
            'funding_contract_reference' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:255'],
        ]);

        $item->update([
            'description' => $data['description'],
            'unit_price' => $data['unit_price'],
            'unit' => $data['unit'],
            'quantity' => $data['quantity'] ?? null,
            'budget_allocated' => $data['budget_allocated'] ?? ($data['unit_price'] * ($data['quantity'] ?? 0)),
            'funding_contract_reference' => $data['funding_contract_reference'] ?? null,
            'category' => $data['category'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Line item updated.');
    }

    public function destroyLineItem(Request $request, $serviceAgreement, $lineItem)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)->findOrFail($serviceAgreement);

        $item = ServiceAgreementLineItem::where('service_agreement_id', $agreement->id)
            ->findOrFail($lineItem);

        $item->delete();

        return redirect()->back()->with('success', 'Line item deleted.');
    }

    // -------------------------------------------------------------------------
    // Rate CRUD
    // -------------------------------------------------------------------------

    public function storeRate(Request $request, $serviceAgreement)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)->findOrFail($serviceAgreement);

        $data = $request->validate([
            'rate_type' => ['required', 'string', 'in:weekday,evening,weekend,public_holiday,sleepover,active_night,overtime,travel,mileage'],
            'rate' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'string', 'in:hour,night,km,trip,flat'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $agreement->rates()->create([
            'rate_type' => $data['rate_type'],
            'rate' => $data['rate'],
            'unit' => $data['unit'],
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Rate added.');
    }

    public function destroyRate(Request $request, $serviceAgreement, $rate)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('service_agreements.update'), 403);

        $agreement = $this->accessibleAgreements($auth)->findOrFail($serviceAgreement);

        $rateModel = ServiceAgreementRate::where('service_agreement_id', $agreement->id)
            ->findOrFail($rate);

        $rateModel->delete();

        return redirect()->back()->with('success', 'Rate deleted.');
    }

    private function accessibleAgreements(User $user): Builder
    {
        return ServiceAgreement::query()
            ->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
                $clientQuery,
                $user,
                ['reports.viewAny'],
            ));
    }

    private function accessibleClients(User $user)
    {
        return $this->siteAccess->applyClientScope(
            Client::query(),
            $user,
            ['reports.viewAny'],
        )
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);
    }
}
