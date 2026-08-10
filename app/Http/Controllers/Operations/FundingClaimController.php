<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FundingClaim;
use App\Models\FundingClaimItem;
use App\Models\ServiceAgreement;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FundingClaimController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $this->authorizeAny($request, ['funding.viewAny', 'funding_claims.viewAny']);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,submitted,approved,rejected,paid'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        if (! empty($data['client_id'])) {
            $this->siteAccess->assertCanAccessClientId(
                $auth,
                (int) $data['client_id'],
                ['reports.viewAny'],
            );
        }

        $claims = $this->accessibleClaims($auth)
            ->with([
                'client:id,first_name,last_name',
                'serviceAgreement:id,title,reference_number',
                'submitter:id,name',
            ])
            ->withCount('items')
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $clients = $this->siteAccess->applyClientScope(
            Client::query(),
            $auth,
            ['reports.viewAny'],
        )
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
        $auth = $this->authorizeAny($request, ['funding.claims.create', 'funding_claims.create']);

        $agreements = $this->accessibleAgreements($auth)
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
        $auth = $this->authorizeAny($request, ['funding.claims.create', 'funding_claims.create']);

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
            'items.*.funding_contract_reference' => ['nullable', 'string', 'max:50'],
        ]);

        $this->siteAccess->assertCanAccessClientId(
            $auth,
            (int) $data['client_id'],
            ['reports.viewAny'],
        );

        $agreement = $this->accessibleAgreements($auth)
            ->with('lineItems:id,service_agreement_id')
            ->findOrFail($data['service_agreement_id']);

        if ((int) $agreement->client_id !== (int) $data['client_id']) {
            throw ValidationException::withMessages([
                'client_id' => 'The Funding Claim Client must match its Service Agreement.',
            ]);
        }

        $this->assertItemOwnership($agreement, $data['items']);

        $claim = DB::transaction(function () use ($data, $agreement) {
            $totalAmount = collect($data['items'])->sum(fn ($item) => $item['quantity'] * $item['unit_price']);

            $claim = FundingClaim::create([
                'service_agreement_id' => $agreement->id,
                'client_id' => $agreement->client_id,
                'claim_reference' => $data['claim_reference'] ?? null,
                'status' => 'draft',
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'total_amount' => $totalAmount,
            ]);

            foreach ($data['items'] as $itemData) {
                FundingClaimItem::create([
                    'funding_claim_id' => $claim->id,
                    'service_agreement_line_item_id' => $itemData['service_agreement_line_item_id'] ?? null,
                    'shift_id' => $itemData['shift_id'] ?? null,
                    'timesheet_id' => $itemData['timesheet_id'] ?? null,
                    'description' => $itemData['description'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'total_amount' => $itemData['quantity'] * $itemData['unit_price'],
                    'service_date' => $itemData['service_date'],
                    'funding_contract_reference' => $itemData['funding_contract_reference'] ?? null,
                ]);
            }

            return $claim;
        });

        return redirect()->route('operations.funding.claims.show', $claim)
            ->with('success', 'Funding claim created.');
    }

    public function show(Request $request, $claim)
    {
        $auth = $this->authorizeAny($request, ['funding.viewAny', 'funding_claims.view']);

        $claim = $this->accessibleClaims($auth)
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
        $auth = $this->authorizeAny($request, ['funding.claims.submit', 'funding_claims.submit']);

        $claim = $this->accessibleClaims($auth)
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
        $auth = $this->authorizeAny($request, ['funding.claims.approve', 'funding_claims.approve']);

        $claim = $this->accessibleClaims($auth)
            ->where('status', 'submitted')
            ->findOrFail($claim);

        $claim->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Claim approved.');
    }

    private function authorizeAny(Request $request, array $permissions)
    {
        $user = $request->user();
        abort_unless(
            $user && collect($permissions)->contains(fn (string $permission) => $user->canDo($permission)),
            403,
        );

        return $user;
    }

    private function accessibleClaims(User $user): Builder
    {
        return FundingClaim::query()
            ->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
                $clientQuery,
                $user,
                ['reports.viewAny'],
            ))
            ->whereHas('serviceAgreement', fn (Builder $agreementQuery) => $agreementQuery
                ->whereColumn('service_agreements.client_id', 'funding_claims.client_id'));
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

    /** @param array<int, array<string, mixed>> $items */
    private function assertItemOwnership(ServiceAgreement $agreement, array $items): void
    {
        $lineItemIds = collect($items)
            ->pluck('service_agreement_line_item_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();
        if ($agreement->lineItems->whereIn('id', $lineItemIds)->count() !== $lineItemIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'Every Funding Claim line item must belong to the selected Service Agreement.',
            ]);
        }

        $shiftIds = collect($items)->pluck('shift_id')->filter()->map(fn ($id) => (int) $id)->unique();
        if (Shift::query()->whereIn('id', $shiftIds)->where('client_id', $agreement->client_id)->count() !== $shiftIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'Every linked Shift must belong to the Funding Claim Client.',
            ]);
        }

        $timesheetIds = collect($items)->pluck('timesheet_id')->filter()->map(fn ($id) => (int) $id)->unique();
        if (Timesheet::query()->whereIn('id', $timesheetIds)->where('client_id', $agreement->client_id)->count() !== $timesheetIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'Every linked Timesheet must belong to the Funding Claim Client.',
            ]);
        }
    }
}
