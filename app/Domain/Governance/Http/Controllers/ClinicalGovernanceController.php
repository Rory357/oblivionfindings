<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\ClinicalGovernanceIndicator;
use App\Domain\Governance\Models\ClinicalGovernanceSnapshot;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClinicalGovernanceController extends Controller
{
    public function dashboard()
    {
        $indicators = ClinicalGovernanceIndicator::where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $latestSnapshot = ClinicalGovernanceSnapshot::latest()->first();

        return Inertia::render('Governance/Clinical/Dashboard', [
            'indicators' => $indicators,
            'latestSnapshot' => $latestSnapshot,
        ]);
    }

    public function storeIndicator(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:medication_safety,incident_rates,restraint_usage,infection_control,falls,client_outcomes,other',
            'description' => 'nullable|string',
            'target_value' => 'required|numeric',
            'target_direction' => 'required|in:above,below,equal',
            'unit' => 'required|string|max:50',
            'reporting_frequency' => 'required|in:weekly,monthly,quarterly',
        ]);

        ClinicalGovernanceIndicator::create([
            ...$validated,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Clinical indicator added.');
    }

    public function recordSnapshot(Request $request)
    {
        $validated = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'indicator_values' => 'required|array',
            'indicator_values.*.indicator_id' => 'required|exists:clinical_governance_indicators,id',
            'indicator_values.*.value' => 'required|numeric',
            'narrative' => 'nullable|string',
        ]);

        $snapshot = ClinicalGovernanceSnapshot::create([
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'indicator_values' => $validated['indicator_values'],
            'narrative' => $validated['narrative'] ?? null,
            'recorded_by' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Clinical governance snapshot recorded.');
    }

    public function trends()
    {
        $snapshots = ClinicalGovernanceSnapshot::orderByDesc('period_end')
            ->limit(12)
            ->get();

        $indicators = ClinicalGovernanceIndicator::where('is_active', true)->get();

        return Inertia::render('Governance/Clinical/Trends', [
            'snapshots' => $snapshots,
            'indicators' => $indicators,
        ]);
    }
}
