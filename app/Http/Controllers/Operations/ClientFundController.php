<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Finance\Services\ClientFundTransactionService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientFund;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientFundController extends Controller
{
    public function __construct(
        private readonly ClientFundTransactionService $fundTransactions,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canViewFunds($auth), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'fund_type' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        $baseQuery = $this->accessibleFunds($auth);

        $funds = (clone $baseQuery)
            ->with(['client:id,first_name,last_name'])
            ->withCount('transactions')
            ->when($search !== '', fn ($q) => $q->where('fund_name', 'like', '%'.$search.'%'))
            ->when($filters['fund_type'] ?? null, fn ($q, $fundType) => $q->where('fund_type', $fundType))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->through(fn (ClientFund $fund) => [
                'id' => $fund->id,
                'name' => $fund->fund_name,
                'fund_type' => $fund->fund_type ?? 'trust',
                'balance' => (float) ($fund->balance ?? 0),
                'low_balance_threshold' => $fund->low_balance_threshold,
                'transaction_count' => (int) ($fund->transactions_count ?? 0),
                'client' => $fund->client ? [
                    'id' => $fund->client->id,
                    'first_name' => $fund->client->first_name,
                    'last_name' => $fund->client->last_name,
                ] : null,
            ])
            ->withQueryString();

        return inertia('operations/client-funds/Index', [
            'funds' => $funds,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'fund_type' => $filters['fund_type'] ?? null,
            ],
            'stats' => [
                'total' => (clone $baseQuery)->count(),
                'total_balance' => (float) (clone $baseQuery)
                    ->sum('balance'),
                'low_balance_alerts' => (clone $baseQuery)
                    ->whereNotNull('low_balance_threshold')
                    ->whereColumn('balance', '<=', 'low_balance_threshold')
                    ->count(),
            ],
        ]);
    }

    public function show(Request $request, $fund)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canViewFunds($auth), 403);

        $fund = $this->accessibleFunds($auth)
            ->with(['client:id,first_name,last_name', 'transactions' => fn ($q) => $q->orderByDesc('created_at')])
            ->findOrFail($fund);

        return inertia('operations/client-funds/Show', [
            'fund' => $fund,
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canManageFunds($auth), 403);

        $clients = $this->siteAccess->applyClientScope(
            Client::query(),
            $auth,
            ['reports.viewAny'],
        )
            ->select('id', 'first_name', 'last_name')
            ->orderBy('last_name')
            ->get();

        return inertia('operations/client-funds/Create', [
            'clients' => $clients,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canManageFunds($auth), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'funding_source' => ['nullable', 'string', 'max:255'],
            'fund_type' => ['nullable', 'string', 'max:100'],
            'total_budget' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'balance' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'low_balance_threshold' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->siteAccess->assertCanAccessClientId(
            $auth,
            (int) $data['client_id'],
            ['reports.viewAny'],
        );

        DB::transaction(function () use ($auth, $data): void {
            $openingBalance = bcadd(
                (string) ($data['balance'] ?? $data['total_budget']),
                '0',
                2,
            );
            $fund = ClientFund::query()->create([
                'client_id' => $data['client_id'],
                'fund_name' => $data['name'],
                'fund_type' => $data['fund_type'] ?? 'general',
                'balance' => '0.00',
                'low_balance_threshold' => $data['low_balance_threshold'] ?? null,
                'notes' => trim(collect([$data['funding_source'] ?? null, $data['notes'] ?? null])->filter()->join("\n\n")) ?: null,
            ]);

            if (bccomp($openingBalance, '0.00', 2) > 0) {
                $this->fundTransactions->record($fund, $auth, [
                    'type' => 'credit',
                    'amount' => $openingBalance,
                    'description' => 'Opening balance',
                    'reference' => null,
                    'idempotency_key' => Str::uuid()->toString(),
                ]);
            }
        }, 3);

        return redirect()->back()->with('success', 'Client fund created.');
    }

    public function update(Request $request, $fund)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canManageFunds($auth), 403);

        $fund = $this->accessibleFunds($auth)->findOrFail($fund);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'funding_source' => ['nullable', 'string', 'max:255'],
            'fund_type' => ['nullable', 'string', 'max:100'],
            'low_balance_threshold' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $fund->update(array_filter([
            'fund_name' => $data['name'] ?? null,
            'fund_type' => $data['fund_type'] ?? null,
            'low_balance_threshold' => $data['low_balance_threshold'] ?? null,
            'notes' => trim(collect([$data['funding_source'] ?? null, $data['notes'] ?? null])->filter()->join("\n\n")) ?: null,
        ], fn ($value) => $value !== null));

        return redirect()->back()->with('success', 'Client fund updated.');
    }

    public function addTransaction(Request $request, $fund)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canManageFunds($auth), 403);

        $fund = $this->accessibleFunds($auth)->findOrFail($fund);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $this->fundTransactions->record($fund, $auth, $data);

        return redirect()->back()->with('success', 'Transaction recorded.');
    }

    private function canViewFunds($auth): bool
    {
        return $auth->canDo('client_funds.manage');
    }

    private function canManageFunds($auth): bool
    {
        return $auth->canDo('client_funds.manage');
    }

    private function accessibleFunds(User $user): Builder
    {
        return ClientFund::query()
            ->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
                $clientQuery,
                $user,
                ['reports.viewAny'],
            ));
    }
}
