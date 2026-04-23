<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientFund;
use App\Models\ClientFundTransaction;
use Illuminate\Http\Request;

class ClientFundController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canViewFunds($auth), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'fund_type' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        $funds = ClientFund::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name'])
            ->withCount('transactions')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->when($filters['fund_type'] ?? null, fn ($q, $fundType) => $q->where('fund_type', $fundType))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->through(fn (ClientFund $fund) => [
                'id' => $fund->id,
                'name' => $fund->name,
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
                'total' => ClientFund::query()
                    ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
                    ->count(),
                'total_balance' => (float) ClientFund::query()
                    ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
                    ->sum('balance'),
                'low_balance_alerts' => 0,
            ],
        ]);
    }

    public function show(Request $request, $fund)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canViewFunds($auth), 403);

        $fund = ClientFund::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
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

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
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
            'total_budget' => ['required', 'numeric', 'min:0'],
            'balance' => ['nullable', 'numeric'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        ClientFund::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'name' => $data['name'],
            'funding_source' => $data['funding_source'] ?? null,
            'total_budget' => $data['total_budget'],
            'balance' => $data['balance'] ?? $data['total_budget'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Client fund created.');
    }

    public function update(Request $request, $fund)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canManageFunds($auth), 403);

        $fund = ClientFund::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($fund);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'funding_source' => ['nullable', 'string', 'max:255'],
            'total_budget' => ['sometimes', 'required', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $fund->update($data);

        return redirect()->back()->with('success', 'Client fund updated.');
    }

    public function addTransaction(Request $request, $fund)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canManageFunds($auth), 403);

        $fund = ClientFund::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($fund);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $newBalance = $data['type'] === 'credit'
            ? $fund->balance + $data['amount']
            : $fund->balance - $data['amount'];

        $fund->transactions()->create([
            'type' => $data['type'],
            'amount' => $data['amount'],
            'description' => $data['description'],
            'reference' => $data['reference'] ?? null,
            'running_balance' => $newBalance,
            'created_by' => $auth->id,
        ]);

        $fund->update(['balance' => $newBalance]);

        return redirect()->back()->with('success', 'Transaction recorded.');
    }

    private function canViewFunds($auth): bool
    {
        return $auth->canDo('client_funds.viewAny')
            || $auth->canDo('client_funds.view')
            || $auth->canDo('clients.viewAny');
    }

    private function canManageFunds($auth): bool
    {
        return $auth->canDo('client_funds.create')
            || $auth->canDo('client_funds.edit')
            || $auth->canDo('client_funds.manage');
    }
}
