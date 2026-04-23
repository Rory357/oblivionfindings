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
            ->groupBy('principle')
            ->map(fn ($group) => $group->map(fn (TeTiritiObligation $obligation) => [
                'id' => $obligation->id,
                'principle' => $obligation->principle,
                'title' => $obligation->title,
                'description' => $obligation->description,
                'implementation_status' => $this->presentStatus($obligation->status),
                'evidence_notes' => $obligation->evidence,
                'target_date' => $obligation->target_date?->toDateString(),
                'order' => $obligation->id,
            ]));

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
            'status' => 'nullable|in:not_started,in_progress,achieved,ongoing,implemented,embedded',
            'implementation_status' => 'nullable|in:not_started,in_progress,implemented,embedded',
            'evidence' => 'nullable|string',
            'evidence_notes' => 'nullable|string',
            'actions_taken' => 'nullable|string',
            'target_date' => 'nullable|date',
            'progress_pct' => 'nullable|integer|min:0|max:100',
        ]);

        TeTiritiObligation::create([
            'principle' => $validated['principle'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'status' => $this->normalizeStatus($validated['status'] ?? $validated['implementation_status'] ?? 'not_started'),
            'evidence' => $validated['evidence'] ?? $validated['evidence_notes'] ?? null,
            'actions_taken' => $validated['actions_taken'] ?? null,
            'target_date' => $validated['target_date'] ?? null,
            'progress_pct' => $validated['progress_pct'] ?? 0,
            'owner_id' => auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Te Tiriti obligation added.');
    }

    public function update(Request $request, TeTiritiObligation $obligation)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'status' => 'nullable|in:not_started,in_progress,achieved,ongoing,implemented,embedded',
            'implementation_status' => 'nullable|in:not_started,in_progress,implemented,embedded',
            'evidence' => 'nullable|string',
            'evidence_notes' => 'nullable|string',
            'actions_taken' => 'nullable|string',
            'target_date' => 'nullable|date',
            'progress_pct' => 'nullable|integer|min:0|max:100',
        ]);

        $payload = [];

        foreach (['title', 'description', 'actions_taken', 'target_date', 'progress_pct'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('status', $validated) || array_key_exists('implementation_status', $validated)) {
            $payload['status'] = $this->normalizeStatus($validated['status'] ?? $validated['implementation_status']);
        }

        if (array_key_exists('evidence', $validated) || array_key_exists('evidence_notes', $validated)) {
            $payload['evidence'] = $validated['evidence'] ?? $validated['evidence_notes'] ?? null;
        }

        $obligation->update($payload);

        return redirect()->back()->with('success', 'Te Tiriti obligation updated.');
    }

    protected function normalizeStatus(?string $status): string
    {
        return match ($status) {
            'implemented' => 'achieved',
            'embedded' => 'ongoing',
            default => $status ?? 'not_started',
        };
    }

    protected function presentStatus(?string $status): string
    {
        return match ($status) {
            'achieved' => 'implemented',
            'ongoing' => 'embedded',
            default => $status ?? 'not_started',
        };
    }
}
