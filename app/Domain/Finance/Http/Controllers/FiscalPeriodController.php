<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FiscalPeriodController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $periods = FinFiscalPeriod::forOrganization($orgId)
            ->with('closedBy:id,name')
            ->orderByDesc('start_date')
            ->get()
            ->map(fn ($period) => [
                'id' => $period->id,
                'name' => $period->name,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
                'status' => $period->status,
                'closed_at' => $period->closed_at?->toDateTimeString(),
                'closed_by' => $period->closedBy?->name,
            ]);

        return Inertia::render('finance/fiscal-periods/Index', [
            'periods' => $periods,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        FinFiscalPeriod::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $validated['name'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => 'open',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('finance.fiscal-periods.index')
            ->with('success', 'Fiscal period created successfully.');
    }

    public function update(Request $request, FinFiscalPeriod $period)
    {
        if ($period->status !== 'open') {
            return back()->withErrors(['period' => 'Only open periods can be edited.']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        $period->update($validated);

        return redirect()->route('finance.fiscal-periods.index')
            ->with('success', 'Fiscal period updated successfully.');
    }

    public function close(Request $request, FinFiscalPeriod $period)
    {
        if ($period->status !== 'open') {
            return back()->withErrors(['period' => 'This period is already closed.']);
        }

        // Check for unposted draft journals in this period
        $draftCount = FinJournal::forOrganization($request->user()->organization_id)
            ->where('fiscal_period_id', $period->id)
            ->where('status', 'draft')
            ->count();

        if ($draftCount > 0) {
            return back()->withErrors([
                'period' => "Cannot close period: {$draftCount} draft journal(s) still unposted.",
            ]);
        }

        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $request->user()->id,
        ]);

        return redirect()->route('finance.fiscal-periods.index')
            ->with('success', 'Fiscal period closed successfully.');
    }
}
