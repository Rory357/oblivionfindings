<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinCostCentre;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CostCentreController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $costCentres = FinCostCentre::forOrganization($orgId)
            ->orderBy('code')
            ->get()
            ->map(fn ($cc) => [
                'id' => $cc->id,
                'code' => $cc->code,
                'name' => $cc->name,
                'type' => $cc->type,
                'site_id' => $cc->site_id,
                'parent_id' => $cc->parent_id,
                'is_active' => $cc->is_active,
            ]);

        return Inertia::render('finance/cost-centres/Index', [
            'costCentres' => $costCentres,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:50',
            'site_id' => 'nullable|integer',
            'parent_id' => 'nullable|exists:fin_cost_centres,id',
            'is_active' => 'boolean',
        ]);

        $orgId = $request->user()->organization_id;

        // Check code uniqueness per org
        $exists = FinCostCentre::forOrganization($orgId)
            ->where('code', $validated['code'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['code' => 'A cost centre with this code already exists.']);
        }

        FinCostCentre::create(array_merge($validated, [
            'organization_id' => $orgId,
            'created_by' => $request->user()->id,
        ]));

        return redirect()->route('finance.cost-centres.index')
            ->with('success', 'Cost centre created successfully.');
    }

    public function update(Request $request, FinCostCentre $costCentre)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:50',
            'site_id' => 'nullable|integer',
            'parent_id' => 'nullable|exists:fin_cost_centres,id',
            'is_active' => 'boolean',
        ]);

        // Check code uniqueness per org (excluding self)
        $exists = FinCostCentre::forOrganization($costCentre->organization_id)
            ->where('code', $validated['code'])
            ->where('id', '!=', $costCentre->id)
            ->exists();

        if ($exists) {
            return back()->withErrors(['code' => 'A cost centre with this code already exists.']);
        }

        $costCentre->update($validated);

        return redirect()->route('finance.cost-centres.index')
            ->with('success', 'Cost centre updated successfully.');
    }

    public function destroy(Request $request, FinCostCentre $costCentre)
    {
        $costCentre->delete();

        return redirect()->route('finance.cost-centres.index')
            ->with('success', 'Cost centre deleted successfully.');
    }
}
