<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FundingClaim;
use App\Models\ServiceAgreement;
use App\Models\User;
use App\Services\Operations\FundingClaimService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class FundingClaimController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly FundingClaimService $claims,
    ) {}

    public function index(Request $request)
    {
        $auth = $this->authorizeAny($request, ['funding.viewAny']);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,submitted,approved,rejected,paid'],
            'client_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! empty($data['client_id'])) {
            abort_unless(
                $this->siteAccess->applyClientScope(
                    Client::query()->whereKey((int) $data['client_id']),
                    $auth,
                    ['funding.viewAllSites'],
                )->exists(),
                404,
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
            ['funding.viewAllSites'],
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
        $auth = $this->authorizeAny($request, ['funding.claims.create']);

        $agreements = $this->accessibleAgreements($auth)
            ->active()
            ->with(['client:id,first_name,last_name', 'lineItems'])
            ->orderBy('title')
            ->get(['id', 'client_id', 'title', 'reference_number', 'total_budget', 'budget_used']);

        return inertia('operations/funding/claims/Create', [
            'agreements' => $agreements,
            'deliveries' => $this->claims->eligibleDeliveries($auth)
                ->map(fn ($entry): array => [
                    'id' => (int) $entry->id,
                    'service_agreement_id' => (int) $entry->service_agreement_id,
                    'service_agreement_line_item_id' => (int) $entry->line_item_id,
                    'shift_id' => (int) $entry->shift_id,
                    'timesheet_id' => (int) $entry->timesheet_id,
                    'description' => (string) $entry->lineItem?->description,
                    'quantity' => (string) $entry->hours,
                    'unit_price' => (string) $entry->rate,
                    'total_amount' => (string) $entry->amount,
                    'service_date' => $entry->service_date?->toDateString(),
                    'funding_contract_reference' => $entry->lineItem?->funding_contract_reference,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $auth = $this->authorizeAny($request, ['funding.claims.create']);

        $data = $request->validate([
            'service_agreement_id' => ['required', 'integer', 'min:1'],
            'client_id' => ['required', 'integer', 'min:1'],
            'claim_reference' => ['nullable', 'string', 'max:100'],
            'client_request_uuid' => ['nullable', 'uuid'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.billing_entry_id' => ['required', 'integer', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.service_date' => ['required', 'date'],
            'items.*.service_agreement_line_item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.shift_id' => ['nullable', 'integer', 'min:1'],
            'items.*.timesheet_id' => ['nullable', 'integer', 'min:1'],
            'items.*.funding_contract_reference' => ['nullable', 'string', 'max:50'],
        ]);

        $result = $this->claims->createDraft($auth, $data);
        $claim = $result['claim'];

        return redirect()->route('operations.funding.claims.show', $claim)
            ->with('success', $result['replayed'] ? 'Funding claim already created.' : 'Funding claim created.');
    }

    public function show(Request $request, $claim)
    {
        $auth = $this->authorizeAny($request, ['funding.viewAny']);

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
            'can_retry_posting' => $auth->canDo('funding.claims.retryPosting'),
        ]);
    }

    public function submit(Request $request, $claim)
    {
        $auth = $this->authorizeAny($request, ['funding.claims.submit']);

        $result = $this->claims->submit($auth, (int) $claim);

        return redirect()->back()->with(
            $result['posting_failed'] ? 'error' : 'success',
            $result['posting_failed']
                ? 'Claim submitted, but its General Ledger posting could not be queued. An authorised user can retry it.'
                : ($result['replayed'] ? 'Claim already submitted.' : 'Claim submitted.'),
        );
    }

    public function approve(Request $request, $claim)
    {
        $auth = $this->authorizeAny($request, ['funding.claims.approve']);

        $this->claims->approve($auth, (int) $claim);

        return redirect()->back()->with('success', 'Claim approved.');
    }

    public function retryPosting(Request $request, $claim)
    {
        $auth = $this->authorizeAny($request, ['funding.claims.retryPosting']);
        $result = $this->claims->retryPosting($auth, (int) $claim);

        return redirect()->back()->with(
            $result['posting_failed'] ? 'error' : 'success',
            $result['posting_failed']
                ? 'General Ledger posting could not be queued. Try again after the finance service has recovered.'
                : 'General Ledger posting queued.',
        );
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
                ['funding.viewAllSites'],
            ))
            ->whereHas('serviceAgreement', fn (Builder $agreementQuery) => $agreementQuery
                ->whereColumn('service_agreements.client_id', 'funding_claims.client_id'))
            ->where(function (Builder $scope): void {
                $scope->whereNull('funding_claims.site_id')
                    ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery
                        ->whereColumn('clients.site_id', 'funding_claims.site_id'));
            });
    }

    private function accessibleAgreements(User $user): Builder
    {
        return ServiceAgreement::query()
            ->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
                $clientQuery,
                $user,
                ['funding.viewAllSites'],
            ));
    }
}
