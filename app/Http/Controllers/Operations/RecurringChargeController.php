<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RecurringCharge;
use Illuminate\Http\Request;

class RecurringChargeController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('recurring_charges.viewAny'), 403);

        $charges = RecurringCharge::query()
            ->where('organization_id', $auth->organization_id)
            ->with(['client:id,first_name,last_name'])
            ->where('is_active', true)
            ->orderBy('next_charge_date')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/recurring-charges/Index', [
            'charges' => $charges,
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('recurring_charges.create'), 403);

        $clients = Client::query()
            ->where('organization_id', $auth->organization_id)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/recurring-charges/Create', [
            'clients' => $clients,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('recurring_charges.create'), 403);

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
            'description' => $data['description'],
            'amount' => $data['amount'],
            'frequency' => $data['frequency'],
            'next_charge_date' => $data['next_charge_date'],
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Recurring charge created.');
    }

    public function edit(Request $request, $charge)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('recurring_charges.edit'), 403);

        $charge = RecurringCharge::query()
            ->where('organization_id', $auth->organization_id)
            ->with(['client:id,first_name,last_name'])
            ->findOrFail($charge);

        $clients = Client::query()
            ->where('organization_id', $auth->organization_id)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/recurring-charges/Edit', [
            'charge' => $charge,
            'clients' => $clients,
        ]);
    }

    public function update(Request $request, $charge)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('recurring_charges.edit'), 403);

        $charge = RecurringCharge::query()
            ->where('organization_id', $auth->organization_id)
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

        $charge->update($data);

        return redirect()->back()->with('success', 'Recurring charge updated.');
    }

    public function destroy(Request $request, $charge)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('recurring_charges.delete'), 403);

        $charge = RecurringCharge::query()
            ->where('organization_id', $auth->organization_id)
            ->findOrFail($charge);

        $charge->delete();

        return redirect()->back()->with('success', 'Recurring charge deleted.');
    }
}
