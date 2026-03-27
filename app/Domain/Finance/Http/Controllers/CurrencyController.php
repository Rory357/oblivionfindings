<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinCurrency;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CurrencyController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $currencies = FinCurrency::forOrganization($orgId)
            ->orderByDesc('is_base')
            ->orderBy('code')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'symbol' => $c->symbol,
                'decimal_places' => $c->decimal_places,
                'exchange_rate' => $c->exchange_rate,
                'rate_updated_at' => $c->rate_updated_at?->toIso8601String(),
                'is_base' => $c->is_base,
                'is_active' => $c->is_active,
            ]);

        return Inertia::render('finance/currencies/Index', [
            'currencies' => $currencies,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|size:3|alpha',
            'name' => 'required|string|max:255',
            'symbol' => 'required|string|max:10',
            'decimal_places' => 'integer|min:0|max:6',
            'exchange_rate' => 'required|numeric|min:0.000001',
            'is_base' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $orgId = $request->user()->organization_id;
        $validated['code'] = strtoupper($validated['code']);

        // Check code uniqueness per org
        $exists = FinCurrency::forOrganization($orgId)
            ->where('code', $validated['code'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['code' => 'A currency with this code already exists.']);
        }

        // If marking as base, unset any existing base currency
        if (! empty($validated['is_base'])) {
            FinCurrency::forOrganization($orgId)
                ->where('is_base', true)
                ->update(['is_base' => false]);
        }

        FinCurrency::create(array_merge($validated, [
            'organization_id' => $orgId,
            'rate_updated_at' => now(),
            'created_by' => $request->user()->id,
        ]));

        return redirect()->route('finance.currencies.index')
            ->with('success', 'Currency created successfully.');
    }

    public function update(Request $request, FinCurrency $currency)
    {
        $validated = $request->validate([
            'code' => 'required|string|size:3|alpha',
            'name' => 'required|string|max:255',
            'symbol' => 'required|string|max:10',
            'decimal_places' => 'integer|min:0|max:6',
            'exchange_rate' => 'required|numeric|min:0.000001',
            'is_base' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $validated['code'] = strtoupper($validated['code']);

        // Check code uniqueness per org (excluding self)
        $exists = FinCurrency::forOrganization($currency->organization_id)
            ->where('code', $validated['code'])
            ->where('id', '!=', $currency->id)
            ->exists();

        if ($exists) {
            return back()->withErrors(['code' => 'A currency with this code already exists.']);
        }

        // If marking as base, unset any existing base currency
        if (! empty($validated['is_base'])) {
            FinCurrency::forOrganization($currency->organization_id)
                ->where('is_base', true)
                ->where('id', '!=', $currency->id)
                ->update(['is_base' => false]);
        }

        // Track rate change
        $rateChanged = (float) $currency->exchange_rate !== (float) $validated['exchange_rate'];

        $currency->update(array_merge($validated, [
            'rate_updated_at' => $rateChanged ? now() : $currency->rate_updated_at,
        ]));

        return redirect()->route('finance.currencies.index')
            ->with('success', 'Currency updated successfully.');
    }

    public function destroy(Request $request, FinCurrency $currency)
    {
        if ($currency->is_base) {
            return back()->withErrors(['code' => 'The base currency cannot be deleted.']);
        }

        $currency->delete();

        return redirect()->route('finance.currencies.index')
            ->with('success', 'Currency deleted successfully.');
    }
}
