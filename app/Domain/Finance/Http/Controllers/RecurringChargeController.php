<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RecurringCharge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class RecurringChargeController extends Controller
{
    public function index(Request $request)
    {
        $auth = $this->authorizeFinance($request, 'finance.ar.view');

        $filters = $request->validate([
            'q' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $charges = RecurringCharge::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name'])
            ->when(
                !empty($filters['q']),
                fn ($query) => $query->where(function ($innerQuery) use ($filters) {
                    $search = '%' . $filters['q'] . '%';

                    $innerQuery->where('name', 'like', $search)
                        ->orWhere('description', 'like', $search)
                        ->orWhereHas('client', function ($clientQuery) use ($search) {
                            $clientQuery->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search);
                        });
                }),
            )
            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) => $query->where('is_active', true),
            )
            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) => $query->where('is_active', false),
            )
            ->orderBy('next_charge_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (RecurringCharge $charge) => [
                'id' => $charge->id,
                'name' => $charge->name ?: $charge->description,
                'amount' => (float) $charge->amount,
                'frequency' => $charge->frequency,
                'is_active' => (bool) $charge->is_active,
                'next_charge_date' => $charge->next_charge_at?->toDateString(),
                'client' => $charge->client ? [
                    'id' => $charge->client->id,
                    'first_name' => $charge->client->first_name,
                    'last_name' => $charge->client->last_name,
                ] : null,
            ]);

        return inertia('finance/recurring-charges/Index', [
            'charges' => $charges,
            'filters' => $filters,
            'stats' => [
                'active' => RecurringCharge::query()
                    ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
                    ->where('is_active', true)
                    ->count(),
                'monthly_total' => (float) RecurringCharge::query()
                    ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
                    ->where('is_active', true)
                    ->sum('amount'),
                'next_due' => RecurringCharge::query()
                    ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
                    ->where('is_active', true)
                    ->whereDate('next_charge_at', '<=', now()->addDays(7))
                    ->count(),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $auth = $this->authorizeFinance($request, 'finance.ar.manage');
        $clients = $this->clientOptions($auth->organization_id);

        return inertia('finance/recurring-charges/Create', [
            'clients' => $clients,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $this->authorizeFinance($request, 'finance.ar.manage');

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'string', 'in:weekly,fortnightly,monthly,quarterly,annually'],
            'next_charge_date' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:next_charge_date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        RecurringCharge::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'name' => $data['description'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'frequency' => $data['frequency'],
            'next_charge_at' => $data['next_charge_date'],
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $auth->id,
        ]);

        return redirect()->route('finance.recurring_charges.index')->with('success', 'Recurring charge created.');
    }

    public function edit(Request $request, $charge)
    {
        $auth = $this->authorizeFinance($request, 'finance.ar.manage');

        $charge = RecurringCharge::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name'])
            ->findOrFail($charge);

        $clients = $this->clientOptions($auth->organization_id);

        return inertia('finance/recurring-charges/Edit', [
            'charge' => [
                'id' => $charge->id,
                'client_id' => $charge->client_id,
                'description' => $charge->description,
                'amount' => (string) $charge->amount,
                'frequency' => $charge->frequency,
                'next_charge_date' => $charge->next_charge_at?->toDateString(),
                'is_active' => (bool) $charge->is_active,
            ],
            'clients' => $clients,
        ]);
    }

    public function update(Request $request, $charge)
    {
        $auth = $this->authorizeFinance($request, 'finance.ar.manage');

        $charge = RecurringCharge::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($charge);

        $data = $request->validate([
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'frequency' => ['sometimes', 'required', 'string', 'in:weekly,fortnightly,monthly,quarterly,annually'],
            'next_charge_date' => ['sometimes', 'required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('description', $data)) {
            $data['name'] = $data['description'];
        }

        if (array_key_exists('next_charge_date', $data)) {
            $data['next_charge_at'] = $data['next_charge_date'];
            unset($data['next_charge_date']);
        }

        $charge->update($data);

        return redirect()->route('finance.recurring_charges.index')->with('success', 'Recurring charge updated.');
    }

    public function destroy(Request $request, $charge)
    {
        $auth = $this->authorizeFinance($request, 'finance.ar.manage');

        $charge = RecurringCharge::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($charge);

        $charge->delete();

        return redirect()->back()->with('success', 'Recurring charge deleted.');
    }

    private function authorizeFinance(Request $request, string $permission)
    {
        $user = $request->user();

        abort_unless($user && $user->canDo($permission), 403);

        return $user;
    }

    private function clientOptions(?int $orgId)
    {
        return Client::query()
            ->when(
                $orgId && Schema::hasColumn('clients', 'organization_id'),
                fn ($query) => $query->where('organization_id', $orgId),
            )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);
    }
}
