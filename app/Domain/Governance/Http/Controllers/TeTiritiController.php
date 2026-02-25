<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\TeTiritiObligation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TeTiritiController extends Controller
{
    public function index()
    {
        $obligations = TeTiritiObligation::query()
            ->with('owner')
            ->orderBy('principle')
            ->orderBy('id')
            ->get()
            ->groupBy('principle');

        return Inertia::render('Governance/TeTiriti/Index', [
            'obligationsByPrinciple' => $obligations,
            'principles' => [
                ['value' => 'partnership', 'label' => 'Partnership / Rangatiratanga'],
                ['value' => 'participation', 'label' => 'Participation / Mana Motuhake'],
                ['value' => 'protection', 'label' => 'Protection / Whakamarumarutanga'],
                ['value' => 'equity', 'label' => 'Equity / Taurite'],
                ['value' => 'options', 'label' => 'Options / Kowhiringa'],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'principle' => 'required|in:partnership,participation,protection,equity,options',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'status' => 'required|in:not_started,in_progress,achieved,ongoing',
            'evidence' => 'nullable|string',
            'actions_taken' => 'nullable|string',
            'target_date' => 'nullable|date',
            'progress_pct' => 'nullable|integer|min:0|max:100',
        ]);

        TeTiritiObligation::create([
            ...$validated,
            'owner_id' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Te Tiriti obligation added.');
    }

    public function update(Request $request, TeTiritiObligation $obligation)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'status' => 'sometimes|in:not_started,in_progress,achieved,ongoing',
            'evidence' => 'nullable|string',
            'actions_taken' => 'nullable|string',
            'target_date' => 'nullable|date',
            'progress_pct' => 'nullable|integer|min:0|max:100',
        ]);

        $obligation->update($validated);

        return redirect()->back()->with('success', 'Te Tiriti obligation updated.');
    }
}
