<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FundingClaim;
use App\Models\FundingClaimItem;
use App\Models\ServiceAgreement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FundingClaimController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('funding_claims.viewAny'), 403);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,submitted,approved,rejected,paid'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        $claims = FundingClaim::query()
            ->where('organization_id', $auth->organization_id)
            ->with([
                'client:id,first_name,last_name',
                'serviceAgreement:id,title,reference_number',
                'submitter:id,name',
            ])
            ->withCount('items')
            ->when(!empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(!empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $clients = Client::query()
            ->where('organization_id', $auth->organization_id)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/funding/claims/Index', [
            'claims' => $claims,
            'clients' => $clients,
            'filters' => $request->only(['status', 'client_id']),
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('funding_claims.create'), 403);

        $agreements = ServiceAgreement::query()
            ->where('organization_id', $auth->organization_id)
            ->active()
            ->with(['client:id,first_name,last_name', 'lineItems'])
            ->orderBy('title')
            ->get(['id', 'client_id', 'title', 'reference_number', 'total_budget', 'budget_used']);

        return inertia('operations/funding/claims/Create', [
            'agreements' => $agreements,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('funding_claims.create'), 403);

        $data = $request->validate([
            'service_agreement_id' => ['required', 'integer', 'exists:service_agreements,id'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'claim_reference' => ['nullable', 'string', 'max:100'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.service_date' => ['required', 'date'],
            'items.*.service_agreement_line_item_id' => ['nullable', 'integer', 'exists:service_agreement_line_items,id'],
            'items.*.shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'items.*.timesheet_id' => ['nullable', 'integer', 'exists:timesheets,id'],
            'items.*.ndis_line_item_code' => ['nullable', 'string', 'max:50'],
        ]);

        $claim = DB::transaction(function () use ($data, $auth) {
            $totalAmount = collect($data['items'])->sum(fn ($item) => $item['quantity'] * $item['unit_price']);

            $claim = FundingClaim::create([
                'organization_id' => $auth->organization_id,
                'service_agreement_id' => $data['service_agreement_id'],
                'client_id' => $data['client_id'],
                'claim_reference' => $data['claim_reference'] ?? null,
                'status' => 'draft',
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'total_amount' => $totalAmount,
            ]);

            foreach ($data['items'] as $itemData) {
                FundingClaimItem::create([
                    'organization_id' => $auth->organization_id,
                    'funding_claim_id' => $claim->id,
                    'service_agreement_line_item_id' => $itemData['service_agreement_line_item_id'] ?? null,
                    'shift_id' => $itemData['shift_id'] ?? null,
                    'timesheet_id' => $itemData['timesheet_id'] ?? null,
                    'description' => $itemData['description'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'total_amount' => $itemData['quantity'] * $itemData['unit_price'],
                    'service_date' => $itemData['service_date'],
                    'ndis_line_item_code' => $itemData['ndis_line_item_code'] ?? null,
                ]);
            }

            return $claim;
        });

        return redirect()->route('operations.funding.claims.show', $claim)
            ->with('success', 'Funding claim created.');
    }

    public function show(Request $request, $claim)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('funding_claims.view'), 403);

        $claim = FundingClaim::query()
            ->where('organization_id', $auth->organization_id)
            ->with([
                'client:id,first_name,last_name',
                'serviceAgreement:id,title,reference_number,total_budget,budget_used',
                'submitter:id,name',
                'approver:id,name',
                'items' => fn ($q) => $q->orderBy('service_date'),
                'items.lineItem',
            ])
            ->findOrFail($claim);

        return inertia('operations/funding/claims/Show', [
            'claim' => $claim,
        ]);
    }

    public function submit(Request $request, $claim)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('funding_claims.submit'), 403);

        $claim = FundingClaim::query()
            ->where('organization_id', $auth->organization_id)
            ->where('status', 'draft')
            ->findOrFail($claim);

        $claim->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Claim submitted.');
    }

    public function approve(Request $request, $claim)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('funding_claims.approve'), 403);

        $claim = FundingClaim::query()
            ->where('organization_id', $auth->organization_id)
            ->where('status', 'submitted')
            ->findOrFail($claim);

        $claim->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Claim approved.');
    }
}
